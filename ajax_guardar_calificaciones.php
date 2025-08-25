<?php
/**
 * ajax_guardar_calificaciones.php - Guardar calificaciones via AJAX desde modal
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Soporte completo para equipos docentes, subgrupos y recursado
 */

// Configurar headers para JSON
header('Content-Type: application/json');

// Incluir archivos necesarios
require_once 'config.php';

// Verificar sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'profesor') {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos']);
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$profesorId = $_SESSION['user_id'];
$db = Database::getInstance();

try {
    // Obtener contenido ID
    $contenidoId = intval($_POST['contenido_id'] ?? 0);
    
    if (!$contenidoId) {
        throw new Exception('ID de contenido no especificado');
    }
    
    // Verificar que el contenido pertenece al profesor (incluye equipos docentes)
    $contenido = $db->fetchOne(
        "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, mp.curso_id, 
                COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                 CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                 CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
        [$contenidoId, $profesorId, $profesorId, $profesorId]
    );
    
    if (!$contenido) {
        throw new Exception('Contenido no encontrado o no tiene permisos');
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Función para obtener estudiantes de la materia
    function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
        try {
            // Verificar si la materia requiere subgrupos
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

            // CASO 1: Materia CON sistema de subgrupos
            if ($materiaInfo['requiere_subgrupos']) {
                // Obtener solo estudiantes asignados específicamente a subgrupos
                $estudiantesSubgrupos = $db->fetchAll(
                    "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                            'subgrupo' as tipo_matricula,
                            c.nombre as curso_origen,
                            ep.subgrupo as subgrupo_nombre
                     FROM estudiantes_por_materia ep
                     JOIN usuarios u ON ep.estudiante_id = u.id
                     JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
                     JOIN cursos c ON m.curso_id = c.id
                     WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                     ORDER BY u.apellido, u.nombre",
                    [$materiaCursoId, $cicloLectivoId]
                );
                
                $estudiantes = $estudiantesSubgrupos;
                
            } else {
                // CASO 2: Materia SIN sistema de subgrupos (materia regular)
                
                // 1. Estudiantes regulares del curso
                $estudiantesRegulares = $db->fetchAll(
                    "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                            'regular' as tipo_matricula,
                            NULL as curso_origen,
                            NULL as subgrupo_nombre
                     FROM usuarios u 
                     JOIN matriculas m ON u.id = m.estudiante_id 
                     WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                     ORDER BY u.apellido, u.nombre",
                    [$materiaInfo['curso_id']]
                );

                // 2. Estudiantes recursando esta materia específica
                $estudiantesRecursando = $db->fetchAll(
                    "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                            'recursando' as tipo_matricula,
                            c_actual.nombre as curso_origen,
                            NULL as subgrupo_nombre
                     FROM usuarios u
                     JOIN materias_recursado mr ON u.id = mr.estudiante_id
                     JOIN matriculas m_actual ON u.id = m_actual.estudiante_id AND m_actual.estado = 'activo'
                     JOIN cursos c_actual ON m_actual.curso_id = c_actual.id
                     WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                     AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'
                     ORDER BY u.apellido, u.nombre",
                    [$materiaCursoId, $cicloLectivoId]
                );

                // 3. Combinar ambos grupos de estudiantes
                $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
            }

            // 4. Filtrar estudiantes que tienen materias liberadas para recursado
            $estudiantesFiltrados = [];
            foreach ($estudiantes as $estudiante) {
                // Verificar si este estudiante tiene liberada esta materia para recursar otra
                $materiaLiberada = $db->fetchOne(
                    "SELECT id FROM materias_recursado 
                     WHERE estudiante_id = ? AND materia_liberada_id = ? AND estado = 'activo'",
                    [$estudiante['id'], $materiaCursoId]
                );
                
                // Si no tiene liberada esta materia, incluirlo en la lista
                if (!$materiaLiberada) {
                    $estudiantesFiltrados[] = $estudiante;
                }
            }

            return $estudiantesFiltrados;
            
        } catch (Exception $e) {
            error_log("Error en obtenerEstudiantesMateria: " . $e->getMessage());
            return [];
        }
    }
    
    // Obtener estudiantes
    $estudiantes = obtenerEstudiantesMateria($db, $contenido['materia_curso_id'], $cicloLectivoId);
    
    if (empty($estudiantes)) {
        throw new Exception('No se encontraron estudiantes para calificar');
    }
    
    $estudiantesActualizados = 0;
    $estudiantesNuevos = 0;
    $estudiantesSinCambios = 0;
    
    // Usar transacción para guardar todas las calificaciones
    $db->transaction(function($db) use ($estudiantes, $contenido, $contenidoId, $cicloLectivoId, &$estudiantesActualizados, &$estudiantesNuevos, &$estudiantesSinCambios) {
        foreach ($estudiantes as $estudiante) {
            $estudianteId = $estudiante['id'];
            
            // Obtener datos del formulario
            $calificacionKey = 'calificacion_' . $estudianteId;
            $observacionesKey = 'observaciones_' . $estudianteId;
            
            if ($contenido['tipo_evaluacion'] === 'numerica') {
                $calificacion = isset($_POST[$calificacionKey]) && $_POST[$calificacionKey] !== '' ? 
                               floatval($_POST[$calificacionKey]) : null;
                $calificacionCualitativa = null;
            } else {
                $calificacion = null;
                $calificacionCualitativa = $_POST[$calificacionKey] ?? null;
                if ($calificacionCualitativa === '') {
                    $calificacionCualitativa = null;
                }
            }
            
            $observaciones = trim($_POST[$observacionesKey] ?? '');
            if ($observaciones === '') {
                $observaciones = null;
            }
            
            // Verificar si ya existe una calificación
            $existente = $db->fetchOne(
                "SELECT id, calificacion_numerica, calificacion_cualitativa, observaciones 
                 FROM contenidos_calificaciones 
                 WHERE contenido_id = ? AND estudiante_id = ?",
                [$contenidoId, $estudianteId]
            );
            
            if ($existente) {
                // Verificar si realmente hay cambios
                $hayChangios = false;
                
                if ($contenido['tipo_evaluacion'] === 'numerica') {
                    $hayChangios = ($existente['calificacion_numerica'] != $calificacion);
                } else {
                    $hayChangios = ($existente['calificacion_cualitativa'] != $calificacionCualitativa);
                }
                
                // También verificar cambios en observaciones
                $hayChangios = $hayChangios || ($existente['observaciones'] != $observaciones);
                
                if ($hayChangios) {
                    // Actualizar calificación existente
                    $db->query(
                        "UPDATE contenidos_calificaciones 
                         SET calificacion_numerica = ?, calificacion_cualitativa = ?, 
                             observaciones = ?, fecha_evaluacion = CURRENT_DATE
                         WHERE contenido_id = ? AND estudiante_id = ?",
                        [$calificacion, $calificacionCualitativa, $observaciones, $contenidoId, $estudianteId]
                    );
                    $estudiantesActualizados++;
                } else {
                    $estudiantesSinCambios++;
                }
            } else if ($calificacion !== null || $calificacionCualitativa !== null || $observaciones !== null) {
                // Insertar nueva calificación solo si se ingresó algún dato
                $db->insert(
                    "INSERT INTO contenidos_calificaciones 
                     (contenido_id, estudiante_id, calificacion_numerica, calificacion_cualitativa, observaciones)
                     VALUES (?, ?, ?, ?, ?)",
                    [$contenidoId, $estudianteId, $calificacion, $calificacionCualitativa, $observaciones]
                );
                $estudiantesNuevos++;
            } else {
                $estudiantesSinCambios++;
            }
        }
    });
    
    // Registrar actividad de equipo docente si aplica
    if (file_exists('funciones_equipos.php')) {
        require_once 'funciones_equipos.php';
        
        if (function_exists('registrarActividadEquipo')) {
            $totalProfesores = $contenido['total_profesores'];
            
            if ($totalProfesores > 1) {
                $detalleActividad = "Calificó contenido vía modal ID: $contenidoId ($estudiantesActualizados actualizados, $estudiantesNuevos nuevos)";
                registrarActividadEquipo($db, $contenido['materia_curso_id'], $profesorId, 'calificar_contenido_ajax', $detalleActividad);
            }
        }
    }
    
    // Calcular estadísticas finales de la calificación
    $estadisticas = $db->fetchOne(
        "SELECT 
            COUNT(*) as total_calificaciones,
            COUNT(CASE WHEN calificacion_numerica IS NOT NULL OR calificacion_cualitativa IS NOT NULL THEN 1 END) as calificaciones_cargadas,
            COUNT(CASE WHEN calificacion_cualitativa = 'Acreditado' THEN 1 END) as acreditados_cualitativo,
            COUNT(CASE WHEN calificacion_numerica >= 7 THEN 1 END) as acreditados_numerico,
            COUNT(CASE WHEN calificacion_cualitativa = 'No Acreditado' THEN 1 END) as no_acreditados_cualitativo,
            COUNT(CASE WHEN calificacion_numerica < 7 AND calificacion_numerica IS NOT NULL THEN 1 END) as no_acreditados_numerico,
            AVG(CASE WHEN calificacion_numerica IS NOT NULL THEN calificacion_numerica END) as promedio_numerico
         FROM contenidos_calificaciones 
         WHERE contenido_id = ?",
        [$contenidoId]
    );
    
    // Preparar estadísticas según tipo de evaluación
    if ($contenido['tipo_evaluacion'] === 'cualitativa') {
        $acreditados = $estadisticas['acreditados_cualitativo'] ?? 0;
        $noAcreditados = $estadisticas['no_acreditados_cualitativo'] ?? 0;
        $promedio = null;
    } else {
        $acreditados = $estadisticas['acreditados_numerico'] ?? 0;
        $noAcreditados = $estadisticas['no_acreditados_numerico'] ?? 0;
        $promedio = $estadisticas['promedio_numerico'] ? round($estadisticas['promedio_numerico'], 2) : null;
    }
    
    // Preparar mensaje de resultado
    $totalProcesados = $estudiantesActualizados + $estudiantesNuevos;
    $mensaje = "Calificaciones guardadas exitosamente";
    
    if ($totalProcesados > 0) {
        $detalles = [];
        if ($estudiantesNuevos > 0) {
            $detalles[] = "$estudiantesNuevos nuevas";
        }
        if ($estudiantesActualizados > 0) {
            $detalles[] = "$estudiantesActualizados actualizadas";
        }
        
        if (!empty($detalles)) {
            $mensaje .= " (" . implode(", ", $detalles) . ")";
        }
    }
    
    // Obtener información del contenido para el response
    $contenidoInfo = $db->fetchOne(
        "SELECT c.titulo, m.nombre as materia_nombre, cur.nombre as curso_nombre
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         WHERE c.id = ?",
        [$contenidoId]
    );
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'datos' => [
            'estudiantes_procesados' => $totalProcesados,
            'estudiantes_nuevos' => $estudiantesNuevos,
            'estudiantes_actualizados' => $estudiantesActualizados,
            'estudiantes_sin_cambios' => $estudiantesSinCambios,
            'total_estudiantes' => count($estudiantes)
        ],
        'estadisticas' => [
            'total_calificaciones' => $estadisticas['total_calificaciones'] ?? 0,
            'calificaciones_cargadas' => $estadisticas['calificaciones_cargadas'] ?? 0,
            'acreditados' => $acreditados,
            'no_acreditados' => $noAcreditados,
            'promedio' => $promedio,
            'porcentaje_completado' => count($estudiantes) > 0 ? 
                round((($estadisticas['calificaciones_cargadas'] ?? 0) / count($estudiantes)) * 100, 1) : 0
        ],
        'contenido' => [
            'id' => $contenidoId,
            'titulo' => $contenidoInfo['titulo'] ?? 'Contenido',
            'materia' => $contenidoInfo['materia_nombre'] ?? '',
            'curso' => $contenidoInfo['curso_nombre'] ?? '',
            'tipo_evaluacion' => $contenido['tipo_evaluacion']
        ],
        'equipo_docente' => [
            'es_equipo' => $contenido['total_profesores'] > 1,
            'total_profesores' => $contenido['total_profesores']
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Log del error para debugging
    error_log("Error en ajax_guardar_calificaciones.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => [
            'file' => __FILE__,
            'line' => $e->getLine(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>