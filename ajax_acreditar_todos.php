<?php
/**
 * ajax_acreditar_todos.php - Acciones masivas para calificación de contenidos con soporte para filtros
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Soporte para filtros de subgrupos y manejo mejorado de errores
 */

header('Content-Type: application/json');

// Función para enviar respuesta JSON y terminar
function enviarRespuesta($data) {
    echo json_encode($data);
    exit;
}

// Función para manejar errores
function manejarError($mensaje, $excepcion = null) {
    $respuesta = ['success' => false, 'message' => $mensaje];
    
    if ($excepcion) {
        error_log("Error en ajax_acreditar_todos.php: " . $excepcion->getMessage());
        $respuesta['debug_info'] = $excepcion->getMessage();
    }
    
    enviarRespuesta($respuesta);
}

require_once 'config.php';

// Verificar sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'profesor') {
    enviarRespuesta(['success' => false, 'message' => 'No tiene permisos para esta acción']);
}

try {
    $db = Database::getInstance();
    $profesorId = $_SESSION['user_id'];
    
    // Obtener parámetros
    $contenidoId = intval($_POST['contenido_id'] ?? 0);
    $aplicarFiltro = isset($_POST['aplicar_filtro']) && $_POST['aplicar_filtro'] === '1';
    $estudiantesFiltrados = [];
    
    if ($aplicarFiltro && isset($_POST['estudiantes_filtrados'])) {
        $estudiantesFiltrados = json_decode($_POST['estudiantes_filtrados'], true);
        if (!is_array($estudiantesFiltrados)) {
            $estudiantesFiltrados = [];
        }
    }
    
    if (!$contenidoId) {
        enviarRespuesta(['success' => false, 'message' => 'ID de contenido no especificado']);
    }
    
    // Verificar que el contenido existe y el profesor tiene permisos
    $contenido = $db->fetchOne(
        "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, mp.curso_id
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
        [$contenidoId, $profesorId, $profesorId, $profesorId]
    );
    
    if (!$contenido) {
        enviarRespuesta(['success' => false, 'message' => 'Contenido no encontrado o sin permisos']);
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Función para obtener estudiantes
    function obtenerEstudiantesParaContenido($db, $materiaCursoId, $cicloLectivoId, $cursoId) {
        // Verificar si la materia requiere subgrupos
        $materiaInfo = $db->fetchOne(
            "SELECT COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos
             FROM materias_por_curso mp
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        $estudiantes = [];
        
        if ($materiaInfo && $materiaInfo['requiere_subgrupos']) {
            // Estudiantes por subgrupos
            $estudiantes = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        ep.subgrupo as subgrupo_nombre,
                        'subgrupo' as tipo_matricula
                 FROM estudiantes_por_materia ep
                 JOIN usuarios u ON ep.estudiante_id = u.id
                 WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                 ORDER BY ep.subgrupo, u.apellido, u.nombre",
                [$materiaCursoId, $cicloLectivoId]
            );
        } else {
            // Estudiantes regulares del curso
            $estudiantesRegulares = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        NULL as subgrupo_nombre,
                        'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 ORDER BY u.apellido, u.nombre",
                [$cursoId]
            );
            
            // Estudiantes recursando esta materia específica
            $estudiantesRecursando = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        NULL as subgrupo_nombre,
                        'recursando' as tipo_matricula
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'
                 ORDER BY u.apellido, u.nombre",
                [$materiaCursoId, $cicloLectivoId]
            );
            
            $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
        }
        
        return $estudiantes;
    }
    
    // Obtener estudiantes
    $todosLosEstudiantes = obtenerEstudiantesParaContenido($db, $contenido['materia_curso_id'], $cicloLectivoId, $contenido['curso_id']);
    
    // Filtrar estudiantes si hay filtro aplicado
    $estudiantesAActualizar = $todosLosEstudiantes;
    
    if ($aplicarFiltro && !empty($estudiantesFiltrados)) {
        error_log("FILTRO APLICADO - IDs recibidos: " . implode(', ', $estudiantesFiltrados));
        
        $estudiantesAActualizar = array_filter($todosLosEstudiantes, function($estudiante) use ($estudiantesFiltrados) {
            return in_array($estudiante['id'], $estudiantesFiltrados);
        });
        
        error_log("FILTRO APLICADO - Estudiantes encontrados: " . count($estudiantesAActualizar));
        error_log("FILTRO APLICADO - Total disponibles: " . count($todosLosEstudiantes));
    } else {
        error_log("SIN FILTRO - Procesando todos los estudiantes: " . count($todosLosEstudiantes));
    }
    
    if (empty($estudiantesAActualizar)) {
        enviarRespuesta(['success' => false, 'message' => 'No se encontraron estudiantes para actualizar']);
    }
    
    // Determinar la acción a realizar
    $calificacionNumerica = null;
    $calificacionCualitativa = null;
    $accionRealizada = '';
    $eliminarCalificacion = false; // NUEVA VARIABLE
    
    if (isset($_POST['acreditar_todos_contenido'])) {
        if ($contenido['tipo_evaluacion'] === 'numerica') {
            $calificacionNumerica = 7;
            $calificacionCualitativa = null;
        } else {
            $calificacionNumerica = null;
            $calificacionCualitativa = 'Acreditado';
        }
        $accionRealizada = 'acreditados';
        
    } elseif (isset($_POST['no_acreditar_todos_contenido'])) {
        if ($contenido['tipo_evaluacion'] === 'numerica') {
            $calificacionNumerica = 1;
            $calificacionCualitativa = null;
        } else {
            $calificacionNumerica = null;
            $calificacionCualitativa = 'No Acreditado';
        }
        $accionRealizada = 'marcados como no acreditados';
        
    } elseif (isset($_POST['no_corresponde_todos_contenido'])) {
        $calificacionNumerica = null;
        $calificacionCualitativa = 'No Corresponde';
        $accionRealizada = 'marcados como no corresponde';
        
    } elseif (isset($_POST['sin_calificar_todos_contenido'])) {
        // NUEVA ACCIÓN: Sin calificar todos
        $eliminarCalificacion = true;
        $accionRealizada = 'sin calificación (eliminadas)';
        
    } else {
        enviarRespuesta(['success' => false, 'message' => 'Acción no especificada']);
    }
    
    // Procesar estudiantes
    // Procesar estudiantes - VERSIÓN ACTUALIZADA
    $estudiantesProcesados = 0;
    $errores = [];
    
    try {
        $db->transaction(function($db) use ($estudiantesAActualizar, $contenidoId, $calificacionNumerica, $calificacionCualitativa, $eliminarCalificacion, &$estudiantesProcesados, &$errores) {
            foreach ($estudiantesAActualizar as $estudiante) {
                try {
                    // Verificar si ya existe una calificación para este estudiante y contenido
                    $calificacionExistente = $db->fetchOne(
                        "SELECT id FROM contenidos_calificaciones WHERE estudiante_id = ? AND contenido_id = ?",
                        [$estudiante['id'], $contenidoId]
                    );
                    
                    if ($eliminarCalificacion) {
                        // NUEVA LÓGICA: Eliminar calificación si existe
                        if ($calificacionExistente) {
                            $db->query(
                                "DELETE FROM contenidos_calificaciones WHERE id = ?",
                                [$calificacionExistente['id']]
                            );
                            $estudiantesProcesados++;
                        }
                        // Si no existe calificación, no hay nada que eliminar pero cuenta como procesado
                        else {
                            $estudiantesProcesados++;
                        }
                    } else {
                        // LÓGICA EXISTENTE: Actualizar o insertar calificación
                        if ($calificacionExistente) {
                            // Actualizar calificación existente
                            $db->query(
                                "UPDATE contenidos_calificaciones 
                                 SET calificacion_numerica = ?, calificacion_cualitativa = ?, updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?",
                                [$calificacionNumerica, $calificacionCualitativa, $calificacionExistente['id']]
                            );
                        } else {
                            // Insertar nueva calificación
                            $db->query(
                                "INSERT INTO contenidos_calificaciones 
                                 (estudiante_id, contenido_id, calificacion_numerica, calificacion_cualitativa, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                                [$estudiante['id'], $contenidoId, $calificacionNumerica, $calificacionCualitativa]
                            );
                        }
                        $estudiantesProcesados++;
                    }
                    
                } catch (Exception $e) {
                    $errores[] = "Error con {$estudiante['apellido']}, {$estudiante['nombre']}: " . $e->getMessage();
                }
            }
        });
    } catch (Exception $e) {
        manejarError('Error en la transacción: ' . $e->getMessage(), $e);
    }
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'message' => "{$estudiantesProcesados} estudiantes {$accionRealizada} correctamente",
        'estudiantes_procesados' => $estudiantesProcesados,
        'total_disponibles' => count($todosLosEstudiantes),
        'filtro_aplicado' => $aplicarFiltro,
        'estudiantes_en_filtro' => count($estudiantesAActualizar)
    ];
    
    if (!empty($errores)) {
        $response['errores'] = $errores;
        $response['message'] .= ". Se encontraron " . count($errores) . " errores.";
    }
    
    if ($aplicarFiltro) {
        $response['mensaje_filtro'] = "Se aplicó filtro: {$estudiantesProcesados} de " . count($estudiantesAActualizar) . " estudiantes filtrados fueron procesados";
    }
    
    enviarRespuesta($response);
    
} catch (Exception $e) {
    manejarError('Error interno del servidor: ' . $e->getMessage(), $e);
}
?>
