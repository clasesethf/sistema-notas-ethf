<?php
/**
 * api_detectar_periodo.php - API para detectar período escolar según fecha
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Calendario Académico 2025 - Fechas exactas
 */

require_once 'config.php';

// Configurar headers para JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Función para detectar período según calendario 2025
function detectarPeriodoEscolar2025($fecha, $anio) {
    try {
        $fechaObj = new DateTime($fecha);
        $año = intval($anio);
        
        // CALENDARIO ACADÉMICO 2025 - FECHAS EXACTAS
        $periodos = [
            'primer_cuatrimestre' => [
                'inicio' => new DateTime($año . '-03-10'),           // 10/03/2025
                'valoracion_1bim' => new DateTime($año . '-05-16'),  // 16/05/2025 - 1° Valoración preliminar
                'cierre' => new DateTime($año . '-07-11'),          // 11/07/2025 - Cierre 1° Cuatrimestre
                'intensificacion_jul_inicio' => new DateTime($año . '-07-07'),  // 07/07/2025
                'intensificacion_jul_fin' => new DateTime($año . '-07-18'),     // 18/07/2025
                'intensificacion_ago_inicio' => new DateTime($año . '-08-04'),  // 04/08/2025
                'intensificacion_ago_fin' => new DateTime($año . '-08-08')      // 08/08/2025
            ],
            'segundo_cuatrimestre' => [
                'inicio' => new DateTime($año . '-08-01'),           // 01/08/2025
                'valoracion_2bim' => new DateTime($año . '-09-01'),  // 01/09/2025 - 2° Valoración preliminar
                'cierre' => new DateTime($año . '-11-20'),          // 20/11/2025 - Cierre 2° Cuatrimestre
                'intensificacion_dic_inicio' => new DateTime($año . '-12-09'),  // 09/12/2025
                'intensificacion_dic_fin' => new DateTime($año . '-12-20'),     // 20/12/2025
                'intensificacion_feb_inicio' => new DateTime(($año + 1) . '-02-10'), // 10/02/2026
                'intensificacion_feb_fin' => new DateTime(($año + 1) . '-02-28')     // 28/02/2026
            ]
        ];
        
        $fechaActual = $fechaObj;
        
        // PRIMER CUATRIMESTRE (10/03 al 11/07 + Intensificaciones)
        if ($fechaActual >= $periodos['primer_cuatrimestre']['inicio'] && 
            $fechaActual <= $periodos['primer_cuatrimestre']['cierre']) {
            
            // Determinar bimestre específico dentro del 1° cuatrimestre
            if ($fechaActual < $periodos['primer_cuatrimestre']['valoracion_1bim']) {
                $bimestre = 1;
                $periodo_nombre = '1er Bimestre';
            } else {
                $bimestre = 2;
                $periodo_nombre = '2do Bimestre';
            }
            
            return [
                'cuatrimestre' => 1,
                'bimestre' => $bimestre,
                'periodo' => 'cursado',
                'periodo_nombre' => $periodo_nombre . ' (1° Cuatrimestre)',
                'es_intensificacion' => false,
                'success' => true
            ];
            
        // INTENSIFICACIÓN JULIO (07/07 al 18/07)
        } elseif ($fechaActual >= $periodos['primer_cuatrimestre']['intensificacion_jul_inicio'] && 
                  $fechaActual <= $periodos['primer_cuatrimestre']['intensificacion_jul_fin']) {
            
            return [
                'cuatrimestre' => 1,
                'bimestre' => 2, // Último bimestre del cuatrimestre
                'periodo' => 'intensificacion_julio',
                'periodo_nombre' => '1° Cuatrimestre - Intensificación Julio',
                'es_intensificacion' => true,
                'success' => true
            ];
            
        // INTENSIFICACIÓN AGOSTO (04/08 al 08/08)  
        } elseif ($fechaActual >= $periodos['primer_cuatrimestre']['intensificacion_ago_inicio'] && 
                  $fechaActual <= $periodos['primer_cuatrimestre']['intensificacion_ago_fin']) {
            
            return [
                'cuatrimestre' => 1,
                'bimestre' => 2,
                'periodo' => 'intensificacion_agosto',
                'periodo_nombre' => '1° Cuatrimestre - Intensificación Agosto',
                'es_intensificacion' => true,
                'success' => true
            ];
            
        // SEGUNDO CUATRIMESTRE (01/08 al 20/11)
        } elseif ($fechaActual >= $periodos['segundo_cuatrimestre']['inicio'] && 
                  $fechaActual <= $periodos['segundo_cuatrimestre']['cierre']) {
            
            // Determinar bimestre específico dentro del 2° cuatrimestre
            if ($fechaActual < $periodos['segundo_cuatrimestre']['valoracion_2bim']) {
                $bimestre = 3;
                $periodo_nombre = '3er Bimestre';
            } else {
                $bimestre = 4;
                $periodo_nombre = '4to Bimestre';
            }
            
            return [
                'cuatrimestre' => 2,
                'bimestre' => $bimestre,
                'periodo' => 'cursado',
                'periodo_nombre' => $periodo_nombre . ' (2° Cuatrimestre)',
                'es_intensificacion' => false,
                'success' => true
            ];
            
        // INTENSIFICACIÓN DICIEMBRE (09/12 al 20/12)
        } elseif ($fechaActual >= $periodos['segundo_cuatrimestre']['intensificacion_dic_inicio'] && 
                  $fechaActual <= $periodos['segundo_cuatrimestre']['intensificacion_dic_fin']) {
            
            return [
                'cuatrimestre' => 2,
                'bimestre' => 4, // Último bimestre del cuatrimestre
                'periodo' => 'intensificacion_diciembre',
                'periodo_nombre' => '2° Cuatrimestre - Intensificación Diciembre',
                'es_intensificacion' => true,
                'success' => true
            ];
            
        // INTENSIFICACIÓN FEBRERO (10/02 al 28/02 del siguiente año)
        } elseif ($fechaActual >= $periodos['segundo_cuatrimestre']['intensificacion_feb_inicio'] && 
                  $fechaActual <= $periodos['segundo_cuatrimestre']['intensificacion_feb_fin']) {
            
            return [
                'cuatrimestre' => 2,
                'bimestre' => 4,
                'periodo' => 'intensificacion_febrero',
                'periodo_nombre' => '2° Cuatrimestre - Intensificación Febrero',
                'es_intensificacion' => true,
                'success' => true
            ];
            
        } else {
            // PERÍODOS FUERA DEL CALENDARIO ACADÉMICO
            
            if ($fechaActual < $periodos['primer_cuatrimestre']['inicio']) {
                // Antes del inicio del año académico
                return [
                    'cuatrimestre' => 1,
                    'bimestre' => 1,
                    'periodo' => 'pre_inicio',
                    'periodo_nombre' => 'Pre-inicio del Año Académico ' . $año,
                    'es_intensificacion' => false,
                    'success' => true,
                    'warning' => 'Fecha anterior al inicio de clases (10/03/' . $año . ')'
                ];
                
            } elseif ($fechaActual > $periodos['primer_cuatrimestre']['cierre'] && 
                     $fechaActual < $periodos['segundo_cuatrimestre']['inicio']) {
                // Receso entre cuatrimestres (excluyendo intensificaciones)
                
                // Verificar si no está en períodos de intensificación
                $enIntensificacion = ($fechaActual >= $periodos['primer_cuatrimestre']['intensificacion_jul_inicio'] && 
                                     $fechaActual <= $periodos['primer_cuatrimestre']['intensificacion_jul_fin']) ||
                                    ($fechaActual >= $periodos['primer_cuatrimestre']['intensificacion_ago_inicio'] && 
                                     $fechaActual <= $periodos['primer_cuatrimestre']['intensificacion_ago_fin']);
                
                if (!$enIntensificacion) {
                    return [
                        'cuatrimestre' => 2,
                        'bimestre' => 3,
                        'periodo' => 'receso_intercuatrimestral',
                        'periodo_nombre' => 'Receso entre Cuatrimestres',
                        'es_intensificacion' => false,
                        'success' => true,
                        'warning' => 'Período de receso entre cuatrimestres'
                    ];
                }
                
            } elseif ($fechaActual > $periodos['segundo_cuatrimestre']['cierre'] && 
                     $fechaActual < $periodos['segundo_cuatrimestre']['intensificacion_dic_inicio']) {
                // Después del 2° cuatrimestre, antes de intensificación diciembre
                return [
                    'cuatrimestre' => 2,
                    'bimestre' => 4,
                    'periodo' => 'fin_cuatrimestre',
                    'periodo_nombre' => 'Fin del 2° Cuatrimestre',
                    'es_intensificacion' => false,
                    'success' => true,
                    'warning' => 'Período posterior al cierre del 2° cuatrimestre'
                ];
                
            } else {
                // Fin del año académico
                return [
                    'cuatrimestre' => 1, // Preparándose para el próximo año
                    'bimestre' => 1,
                    'periodo' => 'fin_año_academico',
                    'periodo_nombre' => 'Fin del Año Académico ' . $año,
                    'es_intensificacion' => false,
                    'success' => true,
                    'warning' => 'Año académico finalizado'
                ];
            }
        }
        
        // Fallback - no debería llegar aquí
        return [
            'cuatrimestre' => 1,
            'bimestre' => 1,
            'periodo' => 'indeterminado',
            'periodo_nombre' => 'Período no determinado',
            'es_intensificacion' => false,
            'success' => true,
            'warning' => 'No se pudo determinar el período específico'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Error al procesar la fecha: ' . $e->getMessage(),
            'cuatrimestre' => 1,
            'bimestre' => 1,
            'periodo_nombre' => 'Error en detección',
            'es_intensificacion' => false
        ];
    }
}

// Obtener fecha del parámetro
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$anio = null;

// Obtener año del ciclo lectivo activo
try {
    $db = Database::getInstance();
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $anio = $cicloActivo ? $cicloActivo['anio'] : date('Y');
} catch (Exception $e) {
    // Si no se puede conectar a la DB, usar año actual
    $anio = date('Y');
}

// Permitir override del año via parámetro
if (isset($_GET['anio']) && is_numeric($_GET['anio'])) {
    $anio = intval($_GET['anio']);
}

// Detectar período usando el calendario 2025
$resultado = detectarPeriodoEscolar2025($fecha, $anio);

// Agregar información adicional útil
$resultado['fecha_consultada'] = $fecha;
$resultado['anio_academico'] = $anio;
$resultado['timestamp'] = time();
$resultado['calendario'] = '2025'; // Indicar que usa el calendario 2025

// Información de debugging (solo si se solicita)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $resultado['debug'] = [
        'fecha_objeto' => (new DateTime($fecha))->format('Y-m-d H:i:s'),
        'año_academico' => $anio,
        'server_time' => date('Y-m-d H:i:s'),
        'parametros_recibidos' => $_GET,
        'calendario_usado' => 'Calendario Académico 2025 - Henry Ford'
    ];
}

// Devolver resultado como JSON
echo json_encode($resultado, JSON_PRETTY_PRINT);
?>