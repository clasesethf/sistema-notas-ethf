<?php
/**
 * debug_estadisticas.php - Diagnóstico de estadísticas de calificaciones
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ARCHIVO DE DEBUG - Para identificar problemas en el cálculo de estadísticas
 */

// Configurar headers para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

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

// Verificar parámetros
if (!isset($_GET['materia_curso_id'])) {
    echo json_encode(['success' => false, 'message' => 'Falta parámetro materia_curso_id']);
    exit;
}

$materiaCursoId = intval($_GET['materia_curso_id']);
$profesorId = $_SESSION['user_id'];
$db = Database::getInstance();

try {
    // Verificar acceso del profesor a esta materia
    $verificacion = $db->fetchOne(
        "SELECT id FROM materias_por_curso 
         WHERE id = ? AND (profesor_id = ? OR profesor_id_2 = ? OR profesor_id_3 = ?)",
        [$materiaCursoId, $profesorId, $profesorId, $profesorId]
    );
    
    if (!$verificacion) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para esta materia']);
        exit;
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Función para obtener período actual (copiada de mis_materias.php)
    function obtenerPeriodoActualDebug() {
        $fechaActual = new DateTime();
        $anioActual = (int)$fechaActual->format('Y');
        
        $periodos = [
            'primer_cuatrimestre' => [
                'inicio' => new DateTime($anioActual . '-03-10'),
                'valoracion' => new DateTime($anioActual . '-05-16'),
                'cierre' => new DateTime($anioActual . '-07-11'),
                'intensificacion_inicio' => new DateTime($anioActual . '-07-07'),
                'intensificacion_fin' => new DateTime($anioActual . '-08-08')
            ],
            'segundo_cuatrimestre' => [
                'inicio' => new DateTime($anioActual . '-08-01'),
                'valoracion' => new DateTime($anioActual . '-09-01'),
                'cierre' => new DateTime($anioActual . '-11-20'),
                'intensificacion_dic_inicio' => new DateTime($anioActual . '-12-09'),
                'intensificacion_dic_fin' => new DateTime($anioActual . '-12-20'),
                'intensificacion_feb_inicio' => new DateTime(($anioActual + 1) . '-02-10'),
                'intensificacion_feb_fin' => new DateTime(($anioActual + 1) . '-02-28')
            ]
        ];
        
        if ($fechaActual >= $periodos['primer_cuatrimestre']['inicio'] && 
            $fechaActual <= $periodos['primer_cuatrimestre']['intensificacion_fin']) {
            return [
                'cuatrimestre' => 1,
                'periodo' => 'primer_cuatrimestre',
                'descripcion' => '1° Cuatrimestre'
            ];
        } elseif ($fechaActual >= $periodos['segundo_cuatrimestre']['inicio'] && 
                  $fechaActual <= $periodos['segundo_cuatrimestre']['intensificacion_dic_fin']) {
            return [
                'cuatrimestre' => 2,
                'periodo' => 'segundo_cuatrimestre',
                'descripcion' => '2° Cuatrimestre'
            ];
        } else {
            return [
                'cuatrimestre' => 1,
                'periodo' => 'pre_inicio',
                'descripcion' => 'Pre-inicio'
            ];
        }
    }
    
    // Función para obtener estudiantes (simplificada)
    function obtenerEstudiantesDebug($db, $materiaCursoId, $cicloLectivoId) {
        $materiaInfo = $db->fetchOne(
            "SELECT mp.requiere_subgrupos, c.id as curso_id
             FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );

        if (!$materiaInfo) return [];

        if ($materiaInfo['requiere_subgrupos']) {
            return $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, 'subgrupo' as tipo_matricula
                 FROM usuarios u 
                 JOIN estudiantes_por_materia ep ON u.id = ep.estudiante_id
                 WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                   AND u.tipo = 'estudiante' AND u.activo = 1",
                [$materiaCursoId, $cicloLectivoId]
            );
        } else {
            $regulares = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, 'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
                [$materiaInfo['curso_id']]
            );

            $recursando = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, 'recursando' as tipo_matricula
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'",
                [$materiaCursoId, $cicloLectivoId]
            );

            return array_merge($regulares, $recursando);
        }
    }
    
    // Obtener estudiantes
    $estudiantes = obtenerEstudiantesDebug($db, $materiaCursoId, $cicloLectivoId);
    $totalEstudiantes = count($estudiantes);
    
    if ($totalEstudiantes == 0) {
        echo json_encode([
            'success' => true,
            'total_estudiantes' => 0,
            'aprobados' => 0,
            'desaprobados' => 0,
            'sin_calificar' => 0,
            'estudiantes_debug' => [],
            'periodo_actual' => 'Sin estudiantes',
            'logica_aplicada' => 'No hay estudiantes para evaluar'
        ]);
        exit;
    }
    
    // Obtener período actual
    $periodoActual = obtenerPeriodoActualDebug();
    
    // Obtener calificaciones detalladas
    $estudiantesIds = array_column($estudiantes, 'id');
    $placeholders = str_repeat('?,', count($estudiantesIds) - 1) . '?';
    
    $calificaciones = $db->fetchAll(
        "SELECT estudiante_id, 
                calificacion_final, 
                calificacion_1c, 
                calificacion_2c,
                intensificacion_1c,
                valoracion_preliminar_1c,
                valoracion_preliminar_2c,
                valoracion_1bim,
                valoracion_3bim
         FROM calificaciones 
         WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? 
         AND estudiante_id IN ($placeholders)",
        array_merge([$materiaCursoId, $cicloLectivoId], $estudiantesIds)
    );
    
    // Crear array asociativo
    $calificacionesPorEstudiante = [];
    foreach ($calificaciones as $cal) {
        $calificacionesPorEstudiante[$cal['estudiante_id']] = $cal;
    }
    
    // Debug cada estudiante
    $estudiantesDebug = [];
    $aprobados = 0;
    $desaprobados = 0;
    $sinCalificar = 0;
    $logicaAplicada = [];
    
    foreach ($estudiantes as $estudiante) {
        $calData = $calificacionesPorEstudiante[$estudiante['id']] ?? null;
        
        $debug = [
            'id' => $estudiante['id'],
            'nombre' => $estudiante['apellido'] . ', ' . $estudiante['nombre'],
            'tipo' => $estudiante['tipo_matricula'],
            'cal_final' => $calData['calificacion_final'] ?? null,
            'cal_1c' => $calData['calificacion_1c'] ?? null,
            'cal_2c' => $calData['calificacion_2c'] ?? null,
            'intensif' => $calData['intensificacion_1c'] ?? null,
            'valoracion_1bim' => $calData['valoracion_1bim'] ?? null,
            'valoracion_3bim' => $calData['valoracion_3bim'] ?? null,
            'cal_usada' => null,
            'estado' => 'sin_calificar'
        ];
        
        if (!$calData) {
            $sinCalificar++;
            $debug['estado'] = 'sin_calificar';
            $logicaAplicada[] = "Estudiante {$estudiante['id']}: Sin registro en tabla calificaciones";
        } else {
            // NUEVA LÓGICA: Aplicar la misma lógica progresiva que en calcularEstadisticasMateria
            $calificacionAEvaluar = null;
            $razon = '';
            
            // Lógica según el período académico progresivo
            switch ($periodoActual['periodo']) {
                case 'cursado_inicial':
                case 'cursado':
                    if ($periodoActual['cuatrimestre'] == 1) {
                        // Durante el 1er cuatrimestre: usar calificacion_1c
                        if (!empty($calData['calificacion_1c']) && is_numeric($calData['calificacion_1c'])) {
                            $calificacionAEvaluar = (float)$calData['calificacion_1c'];
                            $razon = '1° Cuatrimestre (período actual)';
                        }
                    } else {
                        // Durante el 2do cuatrimestre: usar calificacion_2c
                        if (!empty($calData['calificacion_2c']) && is_numeric($calData['calificacion_2c'])) {
                            $calificacionAEvaluar = (float)$calData['calificacion_2c'];
                            $razon = '2° Cuatrimestre (período actual)';
                        }
                    }
                    break;
                
                case 'intensificacion':
                    if ($periodoActual['cuatrimestre'] == 1) {
                        // Intensificación 1er cuatrimestre
                        if (!empty($calData['intensificacion_1c']) && is_numeric($calData['intensificacion_1c'])) {
                            $calificacionAEvaluar = (float)$calData['intensificacion_1c'];
                            $razon = 'Intensificación 1° C';
                        } elseif (!empty($calData['calificacion_1c']) && is_numeric($calData['calificacion_1c'])) {
                            $calificacionAEvaluar = (float)$calData['calificacion_1c'];
                            $razon = '1° Cuatrimestre (sin intensificación)';
                        }
                    } else {
                        // Intensificación 2do cuatrimestre
                        if (!empty($calData['calificacion_2c']) && is_numeric($calData['calificacion_2c'])) {
                            $calificacionAEvaluar = (float)$calData['calificacion_2c'];
                            $razon = '2° Cuatrimestre (intensificación)';
                        }
                    }
                    break;
                
                case 'intensificacion_febrero':
                    // Intensificación febrero
                    if (!empty($calData['calificacion_2c']) && is_numeric($calData['calificacion_2c'])) {
                        $calificacionAEvaluar = (float)$calData['calificacion_2c'];
                        $razon = '2° Cuatrimestre (intensificación febrero)';
                    }
                    break;
                
                case 'pre_inicio':
                default:
                    // Al final del ciclo o cuando hay calificación final
                    if (!empty($calData['calificacion_final']) && is_numeric($calData['calificacion_final'])) {
                        $calificacionAEvaluar = (float)$calData['calificacion_final'];
                        $razon = 'Calificación Final';
                    } elseif (!empty($calData['calificacion_2c']) && is_numeric($calData['calificacion_2c'])) {
                        $calificacionAEvaluar = (float)$calData['calificacion_2c'];
                        $razon = '2° Cuatrimestre (última disponible)';
                    } elseif (!empty($calData['calificacion_1c']) && is_numeric($calData['calificacion_1c'])) {
                        $calificacionAEvaluar = (float)$calData['calificacion_1c'];
                        $razon = '1° Cuatrimestre (única disponible)';
                    }
                    break;
            }
            
            // CASOS ESPECIALES: Valoraciones preliminares para 3er bimestre
            $fechaActual = new DateTime();
            $inicioSegundoCuatrimestre = new DateTime(date('Y') . '-08-01');
            $valoracionSegundoCuatrimestre = new DateTime(date('Y') . '-09-01');
            
            if ($fechaActual >= $inicioSegundoCuatrimestre && 
                $fechaActual < $valoracionSegundoCuatrimestre) {
                // Mostrar valoración del 3er bimestre si existe
                if (!empty($calData['valoracion_3bim'])) {
                    $valoracion = $calData['valoracion_3bim'];
                    if ($valoracion === 'TEA') {
                        $aprobados++;
                        $debug['estado'] = 'aprobado';
                        $debug['cal_usada'] = 'TEA (3er bim)';
                        $logicaAplicada[] = "Estudiante {$estudiante['id']}: Aprobado con TEA en 3er bimestre";
                        $estudiantesDebug[] = $debug;
                        continue;
                    } elseif ($valoracion === 'TED') {
                        $desaprobados++;
                        $debug['estado'] = 'desaprobado';
                        $debug['cal_usada'] = 'TED (3er bim)';
                        $logicaAplicada[] = "Estudiante {$estudiante['id']}: Desaprobado con TED en 3er bimestre";
                        $estudiantesDebug[] = $debug;
                        continue;
                    } elseif ($valoracion === 'TEP') {
                        $sinCalificar++;
                        $debug['estado'] = 'en_proceso';
                        $debug['cal_usada'] = 'TEP (3er bim)';
                        $logicaAplicada[] = "Estudiante {$estudiante['id']}: En proceso con TEP en 3er bimestre";
                        $estudiantesDebug[] = $debug;
                        continue;
                    }
                }
            }
            
            $debug['cal_usada'] = $calificacionAEvaluar;
            
            if ($calificacionAEvaluar === null) {
                $sinCalificar++;
                $debug['estado'] = 'sin_calificar';
                $logicaAplicada[] = "Estudiante {$estudiante['id']}: Sin calificación válida para período {$periodoActual['periodo']}";
            } elseif ($calificacionAEvaluar >= 4) {
                $aprobados++;
                $debug['estado'] = 'aprobado';
                $logicaAplicada[] = "Estudiante {$estudiante['id']}: Aprobado con {$calificacionAEvaluar} ({$razon})";
            } else {
                $desaprobados++;
                $debug['estado'] = 'desaprobado';
                $logicaAplicada[] = "Estudiante {$estudiante['id']}: Desaprobado con {$calificacionAEvaluar} ({$razon})";
            }
        }
        
        $estudiantesDebug[] = $debug;
    }
    
    // Respuesta
    echo json_encode([
        'success' => true,
        'total_estudiantes' => $totalEstudiantes,
        'aprobados' => $aprobados,
        'desaprobados' => $desaprobados,
        'sin_calificar' => $sinCalificar,
        'estudiantes_debug' => $estudiantesDebug,
        'periodo_actual' => $periodoActual['descripcion'],
        'logica_aplicada' => implode('; ', $logicaAplicada),
        'materia_curso_id' => $materiaCursoId,
        'ciclo_lectivo_id' => $cicloLectivoId,
        'fecha_debug' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => __FILE__,
        'line' => $e->getLine()
    ]);
}
?>
