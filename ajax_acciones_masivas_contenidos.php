<?php
/**
 * ajax_acciones_masivas_contenidos.php - Acciones masivas para contenidos_calificar.php
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * SOPORTE COMPLETO PARA FILTROS DE SUBGRUPOS
 */

// Evitar cualquier salida antes del JSON
ob_start();

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Limpiar cualquier salida previa
ob_clean();

header('Content-Type: application/json');

// Función para enviar respuesta JSON y terminar
function enviarRespuesta($data) {
    ob_clean(); // Limpiar buffer antes de enviar JSON
    echo json_encode($data);
    exit;
}

// Función para manejar errores
function manejarError($mensaje, $excepcion = null) {
    $respuesta = ['success' => false, 'message' => $mensaje];
    
    if ($excepcion) {
        error_log("Error en ajax_acciones_masivas_contenidos.php: " . $excepcion->getMessage());
        $respuesta['debug_info'] = $excepcion->getMessage();
        $respuesta['file'] = $excepcion->getFile();
        $respuesta['line'] = $excepcion->getLine();
    }
    
    enviarRespuesta($respuesta);
}

// Capturar errores fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Error fatal de PHP',
            'debug_info' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

try {
    require_once 'config.php';

    // Verificar que el usuario esté logueado y sea profesor
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'profesor') {
        enviarRespuesta(['success' => false, 'message' => 'No tiene permisos para esta acción']);
    }
    $db = Database::getInstance();
    $profesorId = $_SESSION['user_id'];
    
    // Obtener parámetros
    $contenidoId = intval($_POST['contenido_id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
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
    
    if (!$accion) {
        enviarRespuesta(['success' => false, 'message' => 'Acción no especificada']);
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
    
    // Incluir la función obtenerEstudiantesMateria directamente aquí
    // NO incluir contenidos_calificar.php completo porque genera HTML
    
    // Función para obtener estudiantes (copiada desde contenidos_calificar.php)
    function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
        try {
            // Obtener información de la materia
            $materiaInfo = $db->fetchOne(
                "SELECT COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos, 
                        c.id as curso_id, c.nombre as curso_nombre, c.anio
                 FROM materias_por_curso mp
                 JOIN cursos c ON mp.curso_id = c.id
                 WHERE mp.id = ?",
                [$materiaCursoId]
            );
            
            if (!$materiaInfo) {
                return [];
            }
            
            $estudiantes = [];
            
            // 1. ESTUDIANTES REGULARES DEL CURSO (desde matriculas)
            try {
                $estudiantesRegulares = $db->fetchAll(
                    "SELECT u.id, u.apellido, u.nombre, u.dni, 'regular' as tipo_matricula,
                            '' as subgrupo, '' as subgrupo_nombre
                     FROM matriculas m
                     JOIN usuarios u ON m.estudiante_id = u.id
                     WHERE m.curso_id = ? AND m.estado = 'activo' AND u.tipo = 'estudiante'
                     ORDER BY u.apellido, u.nombre",
                    [$materiaInfo['curso_id']]
                );
                
                foreach ($estudiantesRegulares as $estudiante) {
                    $estudiantes[] = $estudiante;
                }
            } catch (Exception $e) {
                error_log("Error obteniendo estudiantes regulares: " . $e->getMessage());
            }
            
            // 2. ESTUDIANTES RECURSANDO (desde materias_recursado si existe)
            try {
                $tablaRecursadoExiste = false;
                $tablasRecursado = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='materias_recursado'");
                $tablaRecursadoExiste = !empty($tablasRecursado);
                
                if ($tablaRecursadoExiste) {
                    $estudiantesRecursando = $db->fetchAll(
                        "SELECT u.id, u.apellido, u.nombre, u.dni, 'recursando' as tipo_matricula,
                                '' as subgrupo, '' as subgrupo_nombre
                         FROM materias_recursado mr
                         JOIN usuarios u ON mr.estudiante_id = u.id
                         WHERE mr.materia_curso_id = ? AND u.tipo = 'estudiante'
                         ORDER BY u.apellido, u.nombre",
                        [$materiaCursoId]
                    );
                    
                    foreach ($estudiantesRecursando as $estudiante) {
                        $estudiantes[] = $estudiante;
                    }
                }
            } catch (Exception $e) {
                error_log("Error obteniendo estudiantes recursando: " . $e->getMessage());
            }
            
            // 3. ESTUDIANTES POR MATERIA (desde estudiantes_por_materia si existe)
            try {
                $tablaEstudiantesPorMateriaExiste = false;
                $tablasEPM = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='estudiantes_por_materia'");
                $tablaEstudiantesPorMateriaExiste = !empty($tablasEPM);
                
                if ($tablaEstudiantesPorMateriaExiste) {
                    $sqlEstudiantesPorMateria = "SELECT u.id, u.apellido, u.nombre, u.dni, 
                                                       'regular' as tipo_matricula,
                                                       COALESCE(epm.subgrupo, '') as subgrupo,
                                                       COALESCE(epm.subgrupo, '') as subgrupo_nombre
                                                FROM estudiantes_por_materia epm
                                                JOIN usuarios u ON epm.estudiante_id = u.id
                                                WHERE epm.materia_curso_id = ? 
                                                  AND epm.ciclo_lectivo_id = ?
                                                  AND epm.activo = 1 
                                                  AND u.tipo = 'estudiante'
                                                ORDER BY u.apellido, u.nombre";
                    
                    $estudiantesPorMateria = $db->fetchAll($sqlEstudiantesPorMateria, [$materiaCursoId, $cicloLectivoId]);
                    
                    // Agregar o reemplazar estudiantes
                    foreach ($estudiantesPorMateria as $estudiante) {
                        // Buscar si ya existe en el array
                        $existe = false;
                        for ($i = 0; $i < count($estudiantes); $i++) {
                            if ($estudiantes[$i]['id'] == $estudiante['id']) {
                                // Reemplazar con la información más específica (incluye subgrupo)
                                $estudiantes[$i] = $estudiante;
                                $existe = true;
                                break;
                            }
                        }
                        
                        // Si no existe, agregarlo
                        if (!$existe) {
                            $estudiantes[] = $estudiante;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error obteniendo estudiantes por materia: " . $e->getMessage());
            }
            
            // Eliminar duplicados y ordenar
            $estudiantesUnicos = [];
            $idsVistos = [];
            
            foreach ($estudiantes as $estudiante) {
                if (!in_array($estudiante['id'], $idsVistos)) {
                    $estudiantesUnicos[] = $estudiante;
                    $idsVistos[] = $estudiante['id'];
                }
            }
            
            // Ordenar por apellido, nombre
            usort($estudiantesUnicos, function($a, $b) {
                return strcmp($a['apellido'] . ', ' . $a['nombre'], $b['apellido'] . ', ' . $b['nombre']);
            });
            
            return $estudiantesUnicos;
            
        } catch (Exception $e) {
            error_log("Error en obtenerEstudiantesMateria: " . $e->getMessage());
            return [];
        }
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Obtener todos los estudiantes de la materia
    $todosLosEstudiantes = obtenerEstudiantesMateria($db, $contenido['materia_curso_id'], $cicloLectivoId);
    
    // Determinar estudiantes a actualizar según filtro
    $estudiantesAActualizar = $todosLosEstudiantes;
    
    if ($aplicarFiltro && !empty($estudiantesFiltrados)) {
        error_log("FILTRO APLICADO - IDs filtrados: " . implode(', ', $estudiantesFiltrados));
        
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
    $eliminarCalificacion = false;
    
    switch ($accion) {
        case 'acreditar_todos':
            if ($contenido['tipo_evaluacion'] === 'numerica') {
                $calificacionNumerica = 7;
                $calificacionCualitativa = null;
            } else {
                $calificacionNumerica = null;
                $calificacionCualitativa = 'Acreditado';
            }
            $accionRealizada = 'acreditados';
            break;
            
        case 'no_acreditar_todos':
            if ($contenido['tipo_evaluacion'] === 'numerica') {
                $calificacionNumerica = 1;
                $calificacionCualitativa = null;
            } else {
                $calificacionNumerica = null;
                $calificacionCualitativa = 'No Acreditado';
            }
            $accionRealizada = 'marcados como no acreditados';
            break;
            
        case 'no_corresponde_todos':
            $calificacionNumerica = null;
            $calificacionCualitativa = 'No Corresponde';
            $accionRealizada = 'marcados como no corresponde';
            break;
            
        case 'sin_calificar_todos':
            $eliminarCalificacion = true;
            $accionRealizada = 'sin calificación (eliminadas)';
            break;
            
        default:
            enviarRespuesta(['success' => false, 'message' => 'Acción no válida']);
    }
    
    // Procesar estudiantes
    $estudiantesProcesados = 0;
    $errores = [];
    
    try {
        $db->query("BEGIN TRANSACTION");
        
        foreach ($estudiantesAActualizar as $estudiante) {
            try {
                // Verificar si ya existe una calificación para este estudiante y contenido
                $calificacionExistente = $db->fetchOne(
                    "SELECT id FROM contenidos_calificaciones WHERE estudiante_id = ? AND contenido_id = ?",
                    [$estudiante['id'], $contenidoId]
                );
                
                if ($eliminarCalificacion) {
                    // ACCIÓN: Sin calificar todos (eliminar calificación)
                    if ($calificacionExistente) {
                        $db->query(
                            "DELETE FROM contenidos_calificaciones WHERE id = ?",
                            [$calificacionExistente['id']]
                        );
                        $estudiantesProcesados++;
                    } else {
                        // Si no existe calificación, no hay nada que eliminar pero cuenta como procesado
                        $estudiantesProcesados++;
                    }
                } else {
                    // ACCIONES: Acreditar, No acreditar, No corresponde
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
        
        $db->query("COMMIT");
        
    } catch (Exception $e) {
        $db->query("ROLLBACK");
        manejarError('Error en la transacción: ' . $e->getMessage(), $e);
    }
    
    // Registrar actividad de equipo docente si aplica
    if (function_exists('registrarActividadEquipo')) {
        $totalProfesores = ($contenido['profesor_id'] ? 1 : 0) + 
                         ($contenido['profesor_id_2'] ? 1 : 0) + 
                         ($contenido['profesor_id_3'] ? 1 : 0);
        if ($totalProfesores > 1) {
            $filtroTexto = $aplicarFiltro ? " (con filtro: " . count($estudiantesAActualizar) . " estudiantes)" : "";
            registrarActividadEquipo($db, $contenido['materia_curso_id'], $profesorId, $accion, "Ejecutó '$accion' en contenido ID: $contenidoId$filtroTexto");
        }
    }
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'message' => "{$estudiantesProcesados} estudiantes {$accionRealizada} correctamente",
        'estudiantes_procesados' => $estudiantesProcesados,
        'total_disponibles' => count($todosLosEstudiantes),
        'filtro_aplicado' => $aplicarFiltro,
        'estudiantes_en_filtro' => count($estudiantesAActualizar),
        'accion' => $accion
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
