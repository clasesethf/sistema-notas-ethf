<?php
/**
 * dashboard_asistencias.php - Panel de estadísticas de asistencia MEJORADO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORAS IMPLEMENTADAS:
 * - Corrección del error de constante APP_NAME duplicada
 * - Soporte completo para todos los estados de asistencia (1/4, 1/2, 3/4 de falta)
 * - Gráficos mejorados con Chart.js más reciente
 * - Estadísticas más detalladas y precisas
 * - Diseño responsivo mejorado
 * - Cálculos decimales precisos para medias faltas
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar permisos (solo admin, directivos y preceptores)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'preceptor'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// CLASE MEJORADA DE ESTADÍSTICAS DE ASISTENCIA
class EstadisticasAsistenciaMejorada {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Calcular equivalente decimal de diferentes tipos de falta
     */
    private function calcularEquivalenteDecimal($estado) {
        switch ($estado) {
            case 'cuarto_falta':
            case '1/4_falta':
                return 0.25;
            case 'media_falta':
            case '1/2_falta':
                return 0.5;
            case 'tres_cuartos_falta':
            case '3/4_falta':
                return 0.75;
            case 'ausente':
                return 1.0;
            case 'presente':
            case 'justificada':
            case 'no_computa':
            default:
                return 0.0;
        }
    }
    
    /**
     * Generar reporte completo de asistencia para un curso (CORREGIDO PARA SQLITE)
     */
    public function generarReporteAsistencia($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Obtener estudiantes del curso - CONSULTA SIMPLIFICADA PARA SQLITE
            $estudiantes = $this->db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
                 ORDER BY u.apellido, u.nombre",
                [$cursoId]
            );
            
            if (empty($estudiantes)) {
                return ['error' => 'No se encontraron estudiantes en el curso seleccionado'];
            }
            
            // Calcular días hábiles en el período (excluyendo sábados y domingos)
            $diasHabiles = $this->calcularDiasHabiles($fechaInicio, $fechaFin);
            
            // Inicializar estadísticas
            $reporte = [
                'total_estudiantes' => count($estudiantes),
                'dias_habiles' => $diasHabiles,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'estadisticas_generales' => [
                    'presentes' => 0,
                    'cuartos_faltas' => 0,
                    'medias_faltas' => 0,
                    'tres_cuartos_faltas' => 0,
                    'ausentes' => 0,
                    'justificadas' => 0,
                    'no_computa' => 0,
                    'tardanzas' => 0
                ],
                'estadisticas_por_estudiante' => [],
                'estadisticas_por_dia_semana' => [],
                'motivos_falta_comunes' => [],
                'estudiantes_regulares' => 0,
                'estudiantes_en_riesgo' => 0,
                'estudiantes_libres' => 0,
                'porcentaje_regulares' => 0,
                'porcentaje_riesgo' => 0,
                'porcentaje_libres' => 0,
                'tendencia_asistencia' => [] // Vacío - ya no se usa
            ];
            
            // Procesar cada estudiante
            foreach ($estudiantes as $estudiante) {
                $estadisticasEstudiante = $this->calcularEstadisticasEstudiante(
                    $estudiante['id'], $cursoId, $fechaInicio, $fechaFin, $diasHabiles
                );
                
                $estadisticasEstudiante['nombre'] = $estudiante['nombre'];
                $estadisticasEstudiante['apellido'] = $estudiante['apellido'];
                $estadisticasEstudiante['dni'] = $estudiante['dni'];
                
                $reporte['estadisticas_por_estudiante'][] = $estadisticasEstudiante;
                
                // Sumar a estadísticas generales
                $reporte['estadisticas_generales']['presentes'] += $estadisticasEstudiante['presentes'];
                $reporte['estadisticas_generales']['cuartos_faltas'] += $estadisticasEstudiante['cuartos_faltas'];
                $reporte['estadisticas_generales']['medias_faltas'] += $estadisticasEstudiante['medias_faltas'];
                $reporte['estadisticas_generales']['tres_cuartos_faltas'] += $estadisticasEstudiante['tres_cuartos_faltas'];
                $reporte['estadisticas_generales']['ausentes'] += $estadisticasEstudiante['ausentes'];
                $reporte['estadisticas_generales']['justificadas'] += $estadisticasEstudiante['justificadas'];
                $reporte['estadisticas_generales']['no_computa'] += $estadisticasEstudiante['no_computa'];
                $reporte['estadisticas_generales']['tardanzas'] += $estadisticasEstudiante['tardanzas'];
                
                // Clasificar por regularidad
                switch ($estadisticasEstudiante['estado_regularidad']) {
                    case 'regular':
                        $reporte['estudiantes_regulares']++;
                        break;
                    case 'riesgo':
                        $reporte['estudiantes_en_riesgo']++;
                        break;
                    case 'libre':
                        $reporte['estudiantes_libres']++;
                        break;
                }
            }
            
            // Calcular porcentajes de regularidad
            $total = $reporte['total_estudiantes'];
            $reporte['porcentaje_regulares'] = $total > 0 ? ($reporte['estudiantes_regulares'] / $total) * 100 : 0;
            $reporte['porcentaje_riesgo'] = $total > 0 ? ($reporte['estudiantes_en_riesgo'] / $total) * 100 : 0;
            $reporte['porcentaje_libres'] = $total > 0 ? ($reporte['estudiantes_libres'] / $total) * 100 : 0;
            
            // Obtener estadísticas adicionales (con manejo de errores)
            try {
                $reporte['estadisticas_por_dia_semana'] = $this->obtenerEstadisticasPorDiaSemana($cursoId, $fechaInicio, $fechaFin);
            } catch (Exception $e) {
                error_log("Error en estadísticas por día: " . $e->getMessage());
                $reporte['estadisticas_por_dia_semana'] = [];
            }
            
            try {
                $reporte['motivos_falta_comunes'] = $this->obtenerMotivosFaltaComunes($cursoId, $fechaInicio, $fechaFin);
            } catch (Exception $e) {
                error_log("Error en motivos de falta: " . $e->getMessage());
                $reporte['motivos_falta_comunes'] = [];
            }
            
            // COMENTADO: Ya no usamos tendencia de asistencia, reemplazada por tabla de inasistencias
            /*
            try {
                $reporte['tendencia_asistencia'] = $this->calcularTendenciaAsistencia($cursoId, $fechaInicio, $fechaFin);
            } catch (Exception $e) {
                error_log("Error en tendencia de asistencia: " . $e->getMessage());
                $reporte['tendencia_asistencia'] = [];
            }
            */
            $reporte['tendencia_asistencia'] = []; // Vacío ya que no lo usamos
            
            return $reporte;
            
        } catch (Exception $e) {
            error_log("Error general en generarReporteAsistencia: " . $e->getMessage());
            return ['error' => 'Error al generar el reporte: ' . $e->getMessage()];
        }
    }
    
    /**
     * Calcular estadísticas individuales de un estudiante (CORREGIDO PARA SQLITE)
     */
    private function calcularEstadisticasEstudiante($estudianteId, $cursoId, $fechaInicio, $fechaFin, $diasHabiles) {
        try {
            // Verificar las columnas disponibles en la tabla asistencias
            $tablaInfo = $this->db->fetchAll("PRAGMA table_info(asistencias)");
            $columnas = array_column($tablaInfo, 'name');
            
            // Construir la consulta solo con las columnas que existen
            $campos = "estado";
            if (in_array('observaciones', $columnas)) $campos .= ", observaciones";
            if (in_array('motivo_falta', $columnas)) $campos .= ", motivo_falta";
            if (in_array('motivo_otro', $columnas)) $campos .= ", motivo_otro";
            if (in_array('motivo_no_computa', $columnas)) $campos .= ", motivo_no_computa";
            
            $asistencias = $this->db->fetchAll(
                "SELECT {$campos}
                 FROM asistencias 
                 WHERE estudiante_id = ? AND curso_id = ? AND fecha BETWEEN ? AND ?",
                [$estudianteId, $cursoId, $fechaInicio, $fechaFin]
            );
            
            $estadisticas = [
                'id' => $estudianteId,
                'presentes' => 0,
                'cuartos_faltas' => 0,
                'medias_faltas' => 0,
                'tres_cuartos_faltas' => 0,
                'ausentes' => 0,
                'justificadas' => 0,
                'no_computa' => 0,
                'tardanzas' => 0,
                'total_registros' => count($asistencias),
                'faltas_totales_decimal' => 0,
                'porcentaje_asistencia' => 0,
                'estado_regularidad' => 'regular'
            ];
            
            foreach ($asistencias as $asistencia) {
                $estado = $asistencia['estado'];
                
                switch ($estado) {
                    case 'presente':
                        $estadisticas['presentes']++;
                        break;
                    case 'cuarto_falta':
                    case '1/4_falta':
                        $estadisticas['cuartos_faltas']++;
                        $estadisticas['faltas_totales_decimal'] += 0.25;
                        break;
                    case 'media_falta':
                    case '1/2_falta':
                        $estadisticas['medias_faltas']++;
                        $estadisticas['faltas_totales_decimal'] += 0.5;
                        break;
                    case 'tres_cuartos_falta':
                    case '3/4_falta':
                        $estadisticas['tres_cuartos_faltas']++;
                        $estadisticas['faltas_totales_decimal'] += 0.75;
                        break;
                    case 'ausente':
                        $estadisticas['ausentes']++;
                        $estadisticas['faltas_totales_decimal'] += 1.0;
                        break;
                    case 'justificada':
                        $estadisticas['justificadas']++;
                        break;
                    case 'no_computa':
                        $estadisticas['no_computa']++;
                        break;
                    case 'tardanza':
                        $estadisticas['tardanzas']++;
                        break;
                }
            }
            
            // Calcular porcentaje de asistencia
            $diasComputables = $diasHabiles - $estadisticas['no_computa'];
            if ($diasComputables > 0) {
                $diasAsistidos = $diasComputables - $estadisticas['faltas_totales_decimal'];
                $estadisticas['porcentaje_asistencia'] = ($diasAsistidos / $diasComputables) * 100;
            } else {
                $estadisticas['porcentaje_asistencia'] = 100;
            }
            
            // Determinar estado de regularidad
            if ($estadisticas['porcentaje_asistencia'] >= 85) {
                $estadisticas['estado_regularidad'] = 'regular';
            } elseif ($estadisticas['porcentaje_asistencia'] >= 75) {
                $estadisticas['estado_regularidad'] = 'riesgo';
            } else {
                $estadisticas['estado_regularidad'] = 'libre';
            }
            
            // Redondear para facilitar lectura
            $estadisticas['faltas_totales'] = round($estadisticas['faltas_totales_decimal'], 2);
            $estadisticas['porcentaje_asistencia'] = round($estadisticas['porcentaje_asistencia'], 1);
            
            return $estadisticas;
            
        } catch (Exception $e) {
            error_log("Error en calcularEstadisticasEstudiante: " . $e->getMessage());
            // Retornar estadísticas vacías si hay error
            return [
                'id' => $estudianteId,
                'presentes' => 0,
                'cuartos_faltas' => 0,
                'medias_faltas' => 0,
                'tres_cuartos_faltas' => 0,
                'ausentes' => 0,
                'justificadas' => 0,
                'no_computa' => 0,
                'tardanzas' => 0,
                'total_registros' => 0,
                'faltas_totales_decimal' => 0,
                'faltas_totales' => 0,
                'porcentaje_asistencia' => 100,
                'estado_regularidad' => 'regular'
            ];
        }
    }
    
    /**
     * Calcular días hábiles (lunes a viernes)
     */
    private function calcularDiasHabiles($fechaInicio, $fechaFin) {
        $inicio = new DateTime($fechaInicio);
        $fin = new DateTime($fechaFin);
        $diasHabiles = 0;
        
        while ($inicio <= $fin) {
            $diaSemana = $inicio->format('N'); // 1 = lunes, 7 = domingo
            if ($diaSemana >= 1 && $diaSemana <= 5) { // Lunes a viernes
                $diasHabiles++;
            }
            $inicio->modify('+1 day');
        }
        
        return $diasHabiles;
    }
    
    /**
     * Obtener estadísticas por día de la semana (CORREGIDO PARA SQLITE)
     */
    private function obtenerEstadisticasPorDiaSemana($cursoId, $fechaInicio, $fechaFin) {
        $estadisticas = [];
        
        for ($dia = 0; $dia <= 6; $dia++) {
            $estadisticas[$dia] = [
                'presentes' => 0,
                'cuartos_faltas' => 0,
                'medias_faltas' => 0,
                'tres_cuartos_faltas' => 0,
                'ausentes' => 0,
                'justificadas' => 0,
                'no_computa' => 0,
                'tardanzas' => 0
            ];
        }
        
        try {
            // Verificar si la tabla matriculas tiene la estructura esperada
            $tablaInfo = $this->db->fetchAll("PRAGMA table_info(asistencias)");
            $columnasAsistencias = array_column($tablaInfo, 'name');
            
            // Consulta adaptada para SQLite - SIN JOIN con matriculas si no es necesario
            $asistencias = $this->db->fetchAll(
                "SELECT estado, strftime('%w', fecha) as dia_semana 
                 FROM asistencias 
                 WHERE curso_id = ? AND fecha BETWEEN ? AND ?",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            foreach ($asistencias as $asistencia) {
                $dia = intval($asistencia['dia_semana']);
                $estado = $asistencia['estado'];
                
                switch ($estado) {
                    case 'presente':
                        $estadisticas[$dia]['presentes']++;
                        break;
                    case 'cuarto_falta':
                    case '1/4_falta':
                        $estadisticas[$dia]['cuartos_faltas']++;
                        break;
                    case 'media_falta':
                    case '1/2_falta':
                        $estadisticas[$dia]['medias_faltas']++;
                        break;
                    case 'tres_cuartos_falta':
                    case '3/4_falta':
                        $estadisticas[$dia]['tres_cuartos_faltas']++;
                        break;
                    case 'ausente':
                        $estadisticas[$dia]['ausentes']++;
                        break;
                    case 'justificada':
                        $estadisticas[$dia]['justificadas']++;
                        break;
                    case 'no_computa':
                        $estadisticas[$dia]['no_computa']++;
                        break;
                    case 'tardanza':
                        $estadisticas[$dia]['tardanzas']++;
                        break;
                }
            }
        } catch (Exception $e) {
            error_log("Error en obtenerEstadisticasPorDiaSemana: " . $e->getMessage());
            // Mantener estadísticas vacías si hay error
        }
        
        return $estadisticas;
    }
    
    /**
     * Obtener motivos de falta más comunes (CORREGIDO PARA SQLITE)
     */
    private function obtenerMotivosFaltaComunes($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Verificar si las columnas de motivo existen
            $tablaInfo = $this->db->fetchAll("PRAGMA table_info(asistencias)");
            $columnas = array_column($tablaInfo, 'name');
            
            if (!in_array('motivo_falta', $columnas)) {
                return []; // Si no existe la columna, retornar vacío
            }
            
            $motivos = $this->db->fetchAll(
                "SELECT 
                    CASE 
                        WHEN motivo_falta = 'otro' AND motivo_otro IS NOT NULL AND motivo_otro != '' 
                        THEN 'Otro: ' || motivo_otro
                        WHEN motivo_falta IS NOT NULL AND motivo_falta != '' 
                        THEN motivo_falta
                        ELSE 'Sin motivo especificado'
                    END as motivo_falta,
                    COUNT(*) as cantidad
                 FROM asistencias
                 WHERE curso_id = ? AND fecha BETWEEN ? AND ? 
                 AND estado = 'justificada'
                 GROUP BY 
                    CASE 
                        WHEN motivo_falta = 'otro' AND motivo_otro IS NOT NULL AND motivo_otro != '' 
                        THEN 'Otro: ' || motivo_otro
                        WHEN motivo_falta IS NOT NULL AND motivo_falta != '' 
                        THEN motivo_falta
                        ELSE 'Sin motivo especificado'
                    END
                 ORDER BY cantidad DESC
                 LIMIT 10",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            return $motivos ?: [];
        } catch (Exception $e) {
            error_log("Error en obtenerMotivosFaltaComunes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener inasistencias detalladas por fecha (NUEVA FUNCIÓN)
     */
    public function obtenerInasistenciasDetalladas($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Verificar las columnas disponibles en la tabla asistencias
            $tablaInfo = $this->db->fetchAll("PRAGMA table_info(asistencias)");
            $columnas = array_column($tablaInfo, 'name');
            
            // Construir la consulta solo con las columnas que existen
            $camposMotivo = "";
            if (in_array('motivo_falta', $columnas) && in_array('motivo_otro', $columnas)) {
                $camposMotivo = ", CASE 
                    WHEN a.motivo_falta = 'otro' AND a.motivo_otro IS NOT NULL AND a.motivo_otro != '' 
                    THEN a.motivo_otro
                    WHEN a.motivo_falta IS NOT NULL AND a.motivo_falta != '' 
                    THEN a.motivo_falta
                    ELSE NULL
                END as motivo";
            } else {
                $camposMotivo = ", NULL as motivo";
            }
            
            $inasistencias = $this->db->fetchAll(
                "SELECT 
                    a.fecha,
                    u.apellido, u.nombre, u.dni,
                    a.estado
                    {$camposMotivo}
                 FROM asistencias a
                 JOIN usuarios u ON a.estudiante_id = u.id
                 WHERE a.curso_id = ? AND a.fecha BETWEEN ? AND ? 
                 AND a.estado IN ('ausente', 'cuarto_falta', 'media_falta', 'tres_cuartos_falta', 'justificada')
                 ORDER BY a.fecha DESC, u.apellido, u.nombre",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            return $inasistencias ?: [];
        } catch (Exception $e) {
            error_log("Error en obtenerInasistenciasDetalladas: " . $e->getMessage());
            return [];
        }
    }
}

// Crear instancia de estadísticas mejorada
$estadisticas = new EstadisticasAsistenciaMejorada($db);

// Obtener ciclo lectivo activo - Con verificación de errores
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
        $cicloLectivoId = 0;
        $anioActivo = date('Y');
    } else {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
        
        // Determinar cuatrimestre actual
        $fechaActual = new DateTime();
        $fechaInicio = new DateTime($cicloActivo['fecha_inicio']);
        $fechaMitad = clone $fechaInicio;
        $fechaMitad->modify('+3 months');

        $cuatrimestreActual = ($fechaActual > $fechaMitad) ? 2 : 1;
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al conectar con la base de datos: ' . $e->getMessage() . '</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
    $cuatrimestreActual = 1;
}

// Obtener cursos
$cursos = [];
try {
    if ($cicloLectivoId > 0) {
        $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los cursos: ' . $e->getMessage() . '</div>';
}

// Procesar selección de curso y período
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$periodoSeleccionado = isset($_GET['periodo']) ? $_GET['periodo'] : 'mes_actual';

// Determinar fechas según el período seleccionado
$fechaInicio = date('Y-m-01'); // Primer día del mes actual
$fechaFin = date('Y-m-t'); // Último día del mes actual

if ($periodoSeleccionado == '1er_bimestre') {
    // 1er Bimestre (marzo - abril)
    $fechaInicio = $anioActivo . '-03-01';
    $fechaFin = $anioActivo . '-04-30';
} elseif ($periodoSeleccionado == '3er_bimestre') {
    // 3er Bimestre (agosto - septiembre)
    $fechaInicio = $anioActivo . '-08-01';
    $fechaFin = $anioActivo . '-09-30';
} elseif ($periodoSeleccionado == '1er_cuatrimestre') {
    // 1er Cuatrimestre (marzo a julio)
    $fechaInicio = $anioActivo . '-03-01';
    $fechaFin = $anioActivo . '-07-31';
} elseif ($periodoSeleccionado == 'cuatrimestre_actual' && isset($cuatrimestreActual)) {
    if ($cuatrimestreActual == 1) {
        // Primer cuatrimestre (marzo a julio)
        $fechaInicio = $anioActivo . '-03-01';
        $fechaFin = $anioActivo . '-07-31';
    } else {
        // Segundo cuatrimestre (agosto a diciembre)
        $fechaInicio = $anioActivo . '-08-01';
        $fechaFin = $anioActivo . '-12-20';
    }
} elseif ($periodoSeleccionado == 'ciclo_lectivo') {
    $fechaInicio = $cicloActivo['fecha_inicio'];
    $fechaFin = $cicloActivo['fecha_fin'];
} elseif ($periodoSeleccionado == 'personalizado') {
    $fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : $fechaInicio;
    $fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : $fechaFin;
}

// Variables para almacenar datos estadísticos
$reporte = null;

// Si se seleccionó un curso
if ($cursoSeleccionado && $cicloLectivoId > 0) {
    try {
        // Generar reporte de asistencia
        $reporte = $estadisticas->generarReporteAsistencia($cursoSeleccionado, $fechaInicio, $fechaFin);
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al generar reporte de asistencia: ' . $e->getMessage() . '</div>';
    }
}
?>

<!-- Estilos CSS mejorados -->
<style>
.metric-card {
    transition: transform 0.2s ease-in-out;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.metric-value {
    font-size: 2.5rem;
    font-weight: bold;
}

/* CORECCIÓN PARA GRÁFICOS - TAMAÑO FIJO */
.chart-container {
    position: relative;
    width: 100%;
    height: 400px !important;
    margin-bottom: 2rem;
    overflow: hidden;
}

.chart-container canvas {
    max-height: 400px !important;
    max-width: 100% !important;
}

/* Contenedor específico para gráfico de tendencia */
.chart-container-tendencia {
    position: relative;
    width: 100%;
    height: 300px !important;
    margin-bottom: 2rem;
    overflow: hidden;
}

.chart-container-tendencia canvas {
    max-height: 300px !important;
    max-width: 100% !important;
}

/* Contenedor específico para gráfico de dona */
.chart-container-dona {
    position: relative;
    width: 100%;
    height: 350px !important;
    margin-bottom: 1rem;
    overflow: hidden;
}

.chart-container-dona canvas {
    max-height: 350px !important;
    max-width: 100% !important;
}

.progress-custom {
    height: 8px;
    border-radius: 4px;
}

.badge-status {
    font-size: 0.75rem;
    padding: 0.35rem 0.6rem;
}

.table-responsive {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.alert-metric {
    border-left: 4px solid;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
}

.alert-metric.alert-success { border-left-color: #28a745; }
.alert-metric.alert-warning { border-left-color: #ffc107; }
.alert-metric.alert-danger { border-left-color: #dc3545; }
.alert-metric.alert-info { border-left-color: #17a2b8; }

/* Estilos para cards de gráficos */
.chart-card {
    height: 100%;
    min-height: 450px;
}

.chart-card .card-body {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.chart-card .chart-container {
    flex: 1;
    min-height: 300px;
}

@media (max-width: 768px) {
    .metric-value {
        font-size: 2rem;
    }
    
    .chart-container {
        height: 300px !important;
    }
    
    .chart-container canvas {
        max-height: 300px !important;
    }
    
    .chart-container-dona {
        height: 280px !important;
    }
    
    .chart-container-dona canvas {
        max-height: 280px !important;
    }
    
    .chart-card {
        min-height: 350px;
    }
}

@media (max-width: 576px) {
    .chart-container {
        height: 250px !important;
    }
    
    .chart-container canvas {
        max-height: 250px !important;
    }
    
    .chart-container-dona {
        height: 220px !important;
    }
    
    .chart-container-dona canvas {
        max-height: 220px !important;
    }
}

/* Prevenir que Chart.js redimensione automáticamente */
.chartjs-render-monitor {
    animation: none !important;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-graph-up"></i>
                        Dashboard de Asistencias - Ciclo Lectivo <?= isset($anioActivo) ? $anioActivo : date('Y') ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="dashboard_asistencias.php" class="mb-4">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="curso" class="form-label">Seleccione Curso:</label>
                                <select name="curso" id="curso" class="form-select" required>
                                    <option value="">-- Seleccione un curso --</option>
                                    <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                        <?= $curso['nombre'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="periodo" class="form-label">Período:</label>
                                <select name="periodo" id="periodo" class="form-select" onchange="toggleFechasPersonalizadas()">
                                    <option value="mes_actual" <?= ($periodoSeleccionado == 'mes_actual') ? 'selected' : '' ?>>
                                        Mes Actual
                                    </option>
                                    <optgroup label="📘 Bimestres">
                                        <option value="1er_bimestre" <?= ($periodoSeleccionado == '1er_bimestre') ? 'selected' : '' ?>>
                                            1er Bimestre (Marzo - Abril)
                                        </option>
                                        <option value="3er_bimestre" <?= ($periodoSeleccionado == '3er_bimestre') ? 'selected' : '' ?>>
                                            3er Bimestre (Agosto - Septiembre)
                                        </option>
                                    </optgroup>
                                    <optgroup label="📗 Cuatrimestres">
                                        <option value="1er_cuatrimestre" <?= ($periodoSeleccionado == '1er_cuatrimestre') ? 'selected' : '' ?>>
                                            1er Cuatrimestre (Marzo - Julio)
                                        </option>
                                        <option value="cuatrimestre_actual" <?= ($periodoSeleccionado == 'cuatrimestre_actual') ? 'selected' : '' ?>>
                                            <?= isset($cuatrimestreActual) ? $cuatrimestreActual . '° Cuatrimestre Actual' : 'Cuatrimestre Actual' ?>
                                        </option>
                                    </optgroup>
                                    <optgroup label="📕 Otros Períodos">
                                        <option value="ciclo_lectivo" <?= ($periodoSeleccionado == 'ciclo_lectivo') ? 'selected' : '' ?>>
                                            Ciclo Lectivo Completo
                                        </option>
                                        <option value="personalizado" <?= ($periodoSeleccionado == 'personalizado') ? 'selected' : '' ?>>
                                            📅 Personalizado (Elegir fechas)
                                        </option>
                                    </optgroup>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <div id="fechas_personalizadas" style="display: <?= ($periodoSeleccionado == 'personalizado') ? 'block' : 'none' ?>;">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label for="fecha_inicio" class="form-label small">Desde:</label>
                                            <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control form-control-sm" 
                                                   value="<?= $fechaInicio ?>" required>
                                        </div>
                                        <div class="col-6">
                                            <label for="fecha_fin" class="form-label small">Hasta:</label>
                                            <input type="date" name="fecha_fin" id="fecha_fin" class="form-control form-control-sm" 
                                                   value="<?= $fechaFin ?>" required>
                                        </div>
                                    </div>
                                </div>
                                <div id="periodo_info" style="display: <?= ($periodoSeleccionado != 'personalizado') ? 'block' : 'none' ?>;">
                                    <label class="form-label small text-muted">Período seleccionado:</label>
                                    <div class="small text-info fw-bold" id="periodo_fechas">
                                        <?= date('d/m/Y', strtotime($fechaInicio)) ?> al <?= date('d/m/Y', strtotime($fechaFin)) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-12 col-lg-auto">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Generar Reporte
                                </button>
                                <?php if ($cursoSeleccionado && $reporte && !isset($reporte['error'])): ?>
                                <button type="button" class="btn btn-success ms-2" onclick="window.print()">
                                    <i class="bi bi-printer"></i> Imprimir
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                    
                    <?php if ($cursoSeleccionado && $reporte && !isset($reporte['error'])): ?>
                    <!-- Información del período -->
                    <div class="alert alert-info alert-metric">
                        <div class="row">
                            <div class="col-md-6">
                                <strong><i class="bi bi-calendar-range"></i> Período:</strong> 
                                <?= date('d/m/Y', strtotime($fechaInicio)) ?> al <?= date('d/m/Y', strtotime($fechaFin)) ?>
                            </div>
                            <div class="col-md-3">
                                <strong><i class="bi bi-calendar-check"></i> Días hábiles:</strong> 
                                <?= $reporte['dias_habiles'] ?>
                            </div>
                            <div class="col-md-3">
                                <strong><i class="bi bi-people"></i> Estudiantes:</strong> 
                                <?= $reporte['total_estudiantes'] ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Métricas principales -->
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card bg-success text-white h-100">
                                <div class="card-body text-center">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title">Regulares</h6>
                                        <i class="bi bi-check-circle-fill fs-4"></i>
                                    </div>
                                    <div class="metric-value"><?= $reporte['estudiantes_regulares'] ?></div>
                                    <p class="mb-2">≥ 85% asistencia</p>
                                    <div class="progress progress-custom bg-light">
                                        <div class="progress-bar bg-white" style="width: <?= $reporte['porcentaje_regulares'] ?>%">
                                            <?= number_format($reporte['porcentaje_regulares'], 1) ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card bg-warning text-white h-100">
                                <div class="card-body text-center">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title">En Riesgo</h6>
                                        <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                                    </div>
                                    <div class="metric-value"><?= $reporte['estudiantes_en_riesgo'] ?></div>
                                    <p class="mb-2">75% - 84% asistencia</p>
                                    <div class="progress progress-custom bg-light">
                                        <div class="progress-bar bg-dark" style="width: <?= $reporte['porcentaje_riesgo'] ?>%">
                                            <?= number_format($reporte['porcentaje_riesgo'], 1) ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card bg-danger text-white h-100">
                                <div class="card-body text-center">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title">Libres</h6>
                                        <i class="bi bi-x-circle-fill fs-4"></i>
                                    </div>
                                    <div class="metric-value"><?= $reporte['estudiantes_libres'] ?></div>
                                    <p class="mb-2">< 75% asistencia</p>
                                    <div class="progress progress-custom bg-light">
                                        <div class="progress-bar bg-white" style="width: <?= $reporte['porcentaje_libres'] ?>%">
                                            <?= number_format($reporte['porcentaje_libres'], 1) ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <div class="card metric-card bg-info text-white h-100">
                                <div class="card-body text-center">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="card-title">Promedio General</h6>
                                        <i class="bi bi-graph-up fs-4"></i>
                                    </div>
                                    <?php
                                    $promedioGeneral = 0;
                                    if (!empty($reporte['estadisticas_por_estudiante'])) {
                                        $sumaAsistencia = array_sum(array_column($reporte['estadisticas_por_estudiante'], 'porcentaje_asistencia'));
                                        $promedioGeneral = $sumaAsistencia / count($reporte['estadisticas_por_estudiante']);
                                    }
                                    ?>
                                    <div class="metric-value"><?= number_format($promedioGeneral, 1) ?>%</div>
                                    <p class="mb-2">asistencia curso</p>
                                    <div class="progress progress-custom bg-light">
                                        <div class="progress-bar bg-white" style="width: <?= $promedioGeneral ?>%">
                                            <?= number_format($promedioGeneral, 1) ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Gráficos principales -->
                    <div class="row mb-4">
                        <div class="col-lg-6 mb-4">
                            <div class="card chart-card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-pie-chart"></i> Distribución de Estados de Asistencia
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container-dona">
                                        <canvas id="graficoAsistencia"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-6 mb-4">
                            <div class="card chart-card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-bar-chart"></i> Asistencia por Día de la Semana
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="graficoDiasSemana"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tendencia de asistencia -->
                    <?php if (!empty($reporte['tendencia_asistencia'])): ?>
                    <!-- COMENTADO: Gráfico de tendencia reemplazado por tabla de inasistencias
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card chart-card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-graph-up-arrow"></i> Tendencia de Asistencia por Semana
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container-tendencia">
                                        <canvas id="graficoTendencia"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    -->
                    <?php endif; ?>
                    
                    <!-- Estudiantes con problemas de asistencia -->
                    <?php if ($reporte['estudiantes_libres'] > 0 || $reporte['estudiantes_en_riesgo'] > 0): ?>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Estudiantes Requieren Atención (<?= $reporte['estudiantes_libres'] + $reporte['estudiantes_en_riesgo'] ?>)
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Estudiante</th>
                                                    <th>DNI</th>
                                                    <th>Presentes</th>
                                                    <th>1/4</th>
                                                    <th>1/2</th>
                                                    <th>3/4</th>
                                                    <th>Aus.</th>
                                                    <th>Just.</th>
                                                    <th>Total Faltas</th>
                                                    <th>% Asistencia</th>
                                                    <th>Estado</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $estudiantesProblemas = array_filter($reporte['estadisticas_por_estudiante'], function($est) {
                                                    return $est['porcentaje_asistencia'] < 85;
                                                });
                                                
                                                usort($estudiantesProblemas, function($a, $b) {
                                                    return $a['porcentaje_asistencia'] <=> $b['porcentaje_asistencia'];
                                                });
                                                
                                                foreach ($estudiantesProblemas as $estudiante):
                                                    $claseEstado = '';
                                                    $textoEstado = '';
                                                    
                                                    switch ($estudiante['estado_regularidad']) {
                                                        case 'riesgo':
                                                            $claseEstado = 'warning';
                                                            $textoEstado = 'En Riesgo';
                                                            break;
                                                        case 'libre':
                                                            $claseEstado = 'danger';
                                                            $textoEstado = 'Libre';
                                                            break;
                                                    }
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?></strong>
                                                    </td>
                                                    <td><?= $estudiante['dni'] ?></td>
                                                    <td><span class="badge bg-success"><?= $estudiante['presentes'] ?></span></td>
                                                    <td><span class="badge bg-secondary"><?= $estudiante['cuartos_faltas'] ?></span></td>
                                                    <td><span class="badge bg-warning"><?= $estudiante['medias_faltas'] ?></span></td>
                                                    <td><span class="badge bg-warning text-dark"><?= $estudiante['tres_cuartos_faltas'] ?></span></td>
                                                    <td><span class="badge bg-danger"><?= $estudiante['ausentes'] ?></span></td>
                                                    <td><span class="badge bg-info"><?= $estudiante['justificadas'] ?></span></td>
                                                    <td><strong><?= $estudiante['faltas_totales'] ?></strong></td>
                                                    <td>
                                                        <div class="progress progress-custom">
                                                            <div class="progress-bar bg-<?= $claseEstado ?>" 
                                                                 style="width: <?= $estudiante['porcentaje_asistencia'] ?>%"
                                                                 title="<?= $estudiante['porcentaje_asistencia'] ?>%">
                                                            </div>
                                                        </div>
                                                        <small><?= number_format($estudiante['porcentaje_asistencia'], 1) ?>%</small>
                                                    </td>
                                                    <td><span class="badge bg-<?= $claseEstado ?> badge-status"><?= $textoEstado ?></span></td>
                                                    <td>
                                                        <a href="asistencias.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= date('Y-m-d') ?>&historial=1&estudiante_historial=<?= $estudiante['id'] ?>" 
                                                           class="btn btn-sm btn-outline-primary" target="_blank" title="Ver historial">
                                                            <i class="bi bi-clock-history"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- NUEVA TABLA: TODOS LOS ESTUDIANTES DEL CURSO -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-people"></i>
                                        Todos los Estudiantes del Curso (<?= count($reporte['estadisticas_por_estudiante']) ?>)
                                    </h6>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-light" onclick="exportarTablaCSV()">
                                            <i class="bi bi-download"></i> Exportar CSV
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                                <input type="text" class="form-control" id="buscarEstudiante" placeholder="Buscar estudiante por nombre o DNI..." onkeyup="filtrarEstudiantes()">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <select class="form-select form-select-sm" id="filtroEstado" onchange="filtrarEstudiantes()">
                                                <option value="">Todos los estados</option>
                                                <option value="regular">Solo Regulares</option>
                                                <option value="riesgo">Solo En Riesgo</option>
                                                <option value="libre">Solo Libres</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover table-sm" id="tablaEstudiantes">
                                            <thead class="table-primary">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Estudiante</th>
                                                    <th>DNI</th>
                                                    <th>Presentes</th>
                                                    <th>1/4</th>
                                                    <th>1/2</th>
                                                    <th>3/4</th>
                                                    <th>Ausentes</th>
                                                    <th>Justificadas</th>
                                                    <th>No Computa</th>
                                                    <th>Total Faltas</th>
                                                    <th>% Asistencia</th>
                                                    <th>Estado</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                // Ordenar estudiantes por apellido
                                                usort($reporte['estadisticas_por_estudiante'], function($a, $b) {
                                                    return strcmp($a['apellido'], $b['apellido']);
                                                });
                                                
                                                $contador = 1;
                                                foreach ($reporte['estadisticas_por_estudiante'] as $estudiante):
                                                    // Determinar clase CSS según estado de regularidad
                                                    $claseEstado = '';
                                                    $textoEstado = '';
                                                    $colorFila = '';
                                                    
                                                    switch ($estudiante['estado_regularidad']) {
                                                        case 'regular':
                                                            $claseEstado = 'success';
                                                            $textoEstado = 'Regular';
                                                            $colorFila = '';
                                                            break;
                                                        case 'riesgo':
                                                            $claseEstado = 'warning';
                                                            $textoEstado = 'En Riesgo';
                                                            $colorFila = 'table-warning';
                                                            break;
                                                        case 'libre':
                                                            $claseEstado = 'danger';
                                                            $textoEstado = 'Libre';
                                                            $colorFila = 'table-danger';
                                                            break;
                                                    }
                                                ?>
                                                <tr class="<?= $colorFila ?>" data-estado="<?= $estudiante['estado_regularidad'] ?>" data-nombre="<?= strtolower($estudiante['apellido'] . ' ' . $estudiante['nombre']) ?>" data-dni="<?= $estudiante['dni'] ?>">
                                                    <td><?= $contador++ ?></td>
                                                    <td>
                                                        <strong><?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?></strong>
                                                    </td>
                                                    <td><?= $estudiante['dni'] ?></td>
                                                    <td><span class="badge bg-success"><?= $estudiante['presentes'] ?></span></td>
                                                    <td><span class="badge bg-secondary"><?= $estudiante['cuartos_faltas'] ?></span></td>
                                                    <td><span class="badge bg-warning text-dark"><?= $estudiante['medias_faltas'] ?></span></td>
                                                    <td><span class="badge bg-warning"><?= $estudiante['tres_cuartos_faltas'] ?></span></td>
                                                    <td><span class="badge bg-danger"><?= $estudiante['ausentes'] ?></span></td>
                                                    <td><span class="badge bg-info"><?= $estudiante['justificadas'] ?></span></td>
                                                    <td><span class="badge bg-primary"><?= $estudiante['no_computa'] ?></span></td>
                                                    <td><strong><?= number_format($estudiante['faltas_totales'], 2) ?></strong></td>
                                                    <td>
                                                        <div class="progress progress-custom mb-1">
                                                            <div class="progress-bar bg-<?= $claseEstado ?>" 
                                                                 style="width: <?= $estudiante['porcentaje_asistencia'] ?>%"
                                                                 title="<?= $estudiante['porcentaje_asistencia'] ?>%">
                                                            </div>
                                                        </div>
                                                        <small class="fw-bold"><?= number_format($estudiante['porcentaje_asistencia'], 1) ?>%</small>
                                                    </td>
                                                    <td><span class="badge bg-<?= $claseEstado ?> badge-status"><?= $textoEstado ?></span></td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <a href="asistencias.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= date('Y-m-d') ?>&historial=1&estudiante_historial=<?= $estudiante['id'] ?>" 
                                                               class="btn btn-sm btn-outline-primary" target="_blank" title="Ver historial">
                                                                <i class="bi bi-clock-history"></i>
                                                            </a>
                                                            <a href="asistencias.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= date('Y-m-d') ?>" 
                                                               class="btn btn-sm btn-outline-success" target="_blank" title="Tomar asistencia">
                                                                <i class="bi bi-plus-circle"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <!-- Resumen estadístico de la tabla -->
                                    <div class="row mt-3">
                                        <div class="col-md-12">
                                            <div class="alert alert-light">
                                                <div class="row text-center">
                                                    <div class="col-md-3">
                                                        <strong>Total Estudiantes:</strong> 
                                                        <span class="badge bg-secondary fs-6"><?= count($reporte['estadisticas_por_estudiante']) ?></span>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <strong>Regulares:</strong> 
                                                        <span class="badge bg-success fs-6"><?= $reporte['estudiantes_regulares'] ?></span>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <strong>En Riesgo:</strong> 
                                                        <span class="badge bg-warning fs-6"><?= $reporte['estudiantes_en_riesgo'] ?></span>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <strong>Libres:</strong> 
                                                        <span class="badge bg-danger fs-6"><?= $reporte['estudiantes_libres'] ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- NUEVA TABLA: TABLA DE INASISTENCIAS (reemplaza gráfico de tendencia) -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-table"></i> Detalle de Inasistencias por Fecha
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php
                                    // Obtener inasistencias detalladas por fecha
                                    $inasistenciasDetalle = $estadisticas->obtenerInasistenciasDetalladas($cursoSeleccionado, $fechaInicio, $fechaFin);
                                    ?>
                                    
                                    <?php if (!empty($inasistenciasDetalle)): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text"><i class="bi bi-calendar"></i></span>
                                                <input type="date" class="form-control" id="filtroFecha" onchange="filtrarInasistencias()" placeholder="Filtrar por fecha">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <select class="form-select form-select-sm" id="filtroTipoFalta" onchange="filtrarInasistencias()">
                                                <option value="">Todos los tipos</option>
                                                <option value="ausente">Solo Ausentes</option>
                                                <option value="cuarto_falta">Solo 1/4 Faltas</option>
                                                <option value="media_falta">Solo 1/2 Faltas</option>
                                                <option value="tres_cuartos_falta">Solo 3/4 Faltas</option>
                                                <option value="justificada">Solo Justificadas</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover" id="tablaInasistencias">
                                            <thead class="table-info">
                                                <tr>
                                                    <th>Fecha</th>
                                                    <th>Día</th>
                                                    <th>Estudiante</th>
                                                    <th>DNI</th>
                                                    <th>Tipo de Falta</th>
                                                    <th>Motivo</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $diasSemana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                                                foreach ($inasistenciasDetalle as $inasistencia):
                                                    $fecha = new DateTime($inasistencia['fecha']);
                                                    $diaSemana = $diasSemana[$fecha->format('w')];
                                                    
                                                    // Determinar color según tipo de falta
                                                    $badgeClass = '';
                                                    $tipoTexto = '';
                                                    switch ($inasistencia['estado']) {
                                                        case 'ausente':
                                                            $badgeClass = 'bg-danger';
                                                            $tipoTexto = 'Ausente';
                                                            break;
                                                        case 'cuarto_falta':
                                                            $badgeClass = 'bg-secondary';
                                                            $tipoTexto = '1/4 Falta';
                                                            break;
                                                        case 'media_falta':
                                                            $badgeClass = 'bg-warning text-dark';
                                                            $tipoTexto = '1/2 Falta';
                                                            break;
                                                        case 'tres_cuartos_falta':
                                                            $badgeClass = 'bg-warning';
                                                            $tipoTexto = '3/4 Falta';
                                                            break;
                                                        case 'justificada':
                                                            $badgeClass = 'bg-info';
                                                            $tipoTexto = 'Justificada';
                                                            break;
                                                    }
                                                ?>
                                                <tr data-fecha="<?= $inasistencia['fecha'] ?>" data-tipo="<?= $inasistencia['estado'] ?>">
                                                    <td><?= $fecha->format('d/m/Y') ?></td>
                                                    <td><small class="text-muted"><?= $diaSemana ?></small></td>
                                                    <td><strong><?= $inasistencia['apellido'] ?>, <?= $inasistencia['nombre'] ?></strong></td>
                                                    <td><?= $inasistencia['dni'] ?></td>
                                                    <td><span class="badge <?= $badgeClass ?>"><?= $tipoTexto ?></span></td>
                                                    <td>
                                                        <?php if (!empty($inasistencia['motivo'])): ?>
                                                            <small><?= htmlspecialchars($inasistencia['motivo']) ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="alert alert-info mt-3">
                                        <strong><i class="bi bi-info-circle"></i> Total de inasistencias registradas:</strong> 
                                        <?= count($inasistenciasDetalle) ?> en el período seleccionado
                                    </div>
                                    
                                    <?php else: ?>
                                    <div class="alert alert-success">
                                        <i class="bi bi-check-circle"></i> 
                                        <strong>¡Excelente!</strong> No hay inasistencias registradas en el período seleccionado.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Motivos de falta más comunes -->
                    <?php if (!empty($reporte['motivos_falta_comunes'])): ?>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-clipboard-data"></i>
                                        Motivos de Falta Más Comunes
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Motivo</th>
                                                    <th class="text-center">Cantidad</th>
                                                    <th>%</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $totalMotivos = array_sum(array_column($reporte['motivos_falta_comunes'], 'cantidad'));
                                                foreach (array_slice($reporte['motivos_falta_comunes'], 0, 8) as $motivo):
                                                    $porcentaje = $totalMotivos > 0 ? ($motivo['cantidad'] / $totalMotivos) * 100 : 0;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <small><?= htmlspecialchars($motivo['motivo_falta']) ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-info"><?= $motivo['cantidad'] ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="progress progress-custom">
                                                            <div class="progress-bar bg-info" style="width: <?= $porcentaje ?>%"></div>
                                                        </div>
                                                        <small><?= number_format($porcentaje, 1) ?>%</small>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Resumen de estadísticas generales -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-secondary text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-list-check"></i>
                                        Resumen General de Estados
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <div class="alert alert-success alert-metric py-2 mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi bi-check-circle"></i> Presentes:</span>
                                                    <strong><?= $reporte['estadisticas_generales']['presentes'] ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="alert alert-secondary alert-metric py-2 mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi bi-clock"></i> 1/4 Faltas:</span>
                                                    <strong><?= $reporte['estadisticas_generales']['cuartos_faltas'] ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="alert alert-warning alert-metric py-2 mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi bi-slash-circle"></i> 1/2 Faltas:</span>
                                                    <strong><?= $reporte['estadisticas_generales']['medias_faltas'] ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="alert alert-warning alert-metric py-2 mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi bi-clock-history"></i> 3/4 Faltas:</span>
                                                    <strong><?= $reporte['estadisticas_generales']['tres_cuartos_faltas'] ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="alert alert-danger alert-metric py-2 mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi bi-x-circle"></i> Ausentes:</span>
                                                    <strong><?= $reporte['estadisticas_generales']['ausentes'] ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="alert alert-info alert-metric py-2 mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><i class="bi bi-file-text"></i> Justificadas:</span>
                                                    <strong><?= $reporte['estadisticas_generales']['justificadas'] ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php elseif ($cursoSeleccionado && isset($reporte['error'])): ?>
                    <div class="alert alert-danger alert-metric">
                        <strong><i class="bi bi-exclamation-triangle"></i> Error al generar el reporte:</strong> 
                        <?= $reporte['error'] ?>
                    </div>
                    
                    <?php elseif (!$cursoSeleccionado): ?>
                    <div class="alert alert-info alert-metric">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Seleccione un curso</strong> para visualizar las estadísticas de asistencia detalladas.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($cursoSeleccionado && $reporte && !isset($reporte['error'])): ?>
<!-- Scripts para los gráficos mejorados -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Configuración común para todos los gráficos - MEJORADA PARA EVITAR REDIMENSIONAMIENTO
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,  // CLAVE: No mantener aspecto automático
        animation: {
            duration: 1000,
            easing: 'easeInOutQuart'
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true,
                    font: {
                        size: 12
                    }
                }
            }
        },
        onResize: function(chart, size) {
            // Prevenir redimensionamiento automático problemático
            if (size.height < 200) {
                chart.resize(size.width, 300);
            }
        }
    };
    
    // FUNCIÓN PARA ESPERAR A QUE EL DOM ESTÉ COMPLETAMENTE LISTO
    function inicializarGrafico(canvasId, callback) {
        const canvas = document.getElementById(canvasId);
        if (canvas && canvas.getContext) {
            const container = canvas.parentElement;
            // Asegurar que el contenedor tenga dimensiones antes de crear el gráfico
            if (container.offsetHeight > 0 && container.offsetWidth > 0) {
                callback(canvas.getContext('2d'));
            } else {
                // Intentar de nuevo después de un pequeño delay
                setTimeout(() => inicializarGrafico(canvasId, callback), 100);
            }
        }
    }
    
    // Gráfico de distribución de asistencia (Dona mejorada)
    inicializarGrafico('graficoAsistencia', function(ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Presentes', '1/4 Faltas', '1/2 Faltas', '3/4 Faltas', 'Ausentes', 'Justificadas', 'No Computa'],
                datasets: [{
                    data: [
                        <?= $reporte['estadisticas_generales']['presentes'] ?>,
                        <?= $reporte['estadisticas_generales']['cuartos_faltas'] ?>,
                        <?= $reporte['estadisticas_generales']['medias_faltas'] ?>,
                        <?= $reporte['estadisticas_generales']['tres_cuartos_faltas'] ?>,
                        <?= $reporte['estadisticas_generales']['ausentes'] ?>,
                        <?= $reporte['estadisticas_generales']['justificadas'] ?>,
                        <?= $reporte['estadisticas_generales']['no_computa'] ?>
                    ],
                    backgroundColor: [
                        '#28a745',    // Verde - Presentes
                        '#6c757d',    // Gris - 1/4 Faltas
                        '#ffc107',    // Amarillo - 1/2 Faltas
                        '#fd7e14',    // Naranja - 3/4 Faltas
                        '#dc3545',    // Rojo - Ausentes
                        '#17a2b8',    // Azul - Justificadas
                        '#6f42c1'     // Púrpura - No Computa
                    ],
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverBorderWidth: 5,
                    hoverOffset: 10
                }]
            },
            options: {
                ...commonOptions,
                cutout: '45%',
                radius: '90%',
                plugins: {
                    ...commonOptions.plugins,
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#fff',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    });
    
    // Gráfico de asistencia por día de la semana (Barras agrupadas mejoradas)
    inicializarGrafico('graficoDiasSemana', function(ctx) {
        const diasSemana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        const datasets = [
            {
                label: 'Presentes',
                data: [<?php for($i = 0; $i <= 6; $i++) echo ($reporte['estadisticas_por_dia_semana'][$i]['presentes'] ?? 0) . ','; ?>],
                backgroundColor: '#28a745',
                borderColor: '#1e7e34',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            },
            {
                label: '1/4 Faltas',
                data: [<?php for($i = 0; $i <= 6; $i++) echo ($reporte['estadisticas_por_dia_semana'][$i]['cuartos_faltas'] ?? 0) . ','; ?>],
                backgroundColor: '#6c757d',
                borderColor: '#495057',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            },
            {
                label: '1/2 Faltas',
                data: [<?php for($i = 0; $i <= 6; $i++) echo ($reporte['estadisticas_por_dia_semana'][$i]['medias_faltas'] ?? 0) . ','; ?>],
                backgroundColor: '#ffc107',
                borderColor: '#e0a800',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            },
            {
                label: '3/4 Faltas',
                data: [<?php for($i = 0; $i <= 6; $i++) echo ($reporte['estadisticas_por_dia_semana'][$i]['tres_cuartos_faltas'] ?? 0) . ','; ?>],
                backgroundColor: '#fd7e14',
                borderColor: '#dc6502',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            },
            {
                label: 'Ausentes',
                data: [<?php for($i = 0; $i <= 6; $i++) echo ($reporte['estadisticas_por_dia_semana'][$i]['ausentes'] ?? 0) . ','; ?>],
                backgroundColor: '#dc3545',
                borderColor: '#c82333',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            }
        ];
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: diasSemana,
                datasets: datasets
            },
            options: {
                ...commonOptions,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.1)',
                            lineWidth: 1
                        },
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                plugins: {
                    ...commonOptions.plugins,
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#fff',
                        borderWidth: 1
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                }
            }
        });
    });
    
    // Gráfico de tendencia de asistencia por semana (COMENTADO - reemplazado por tabla)
    /*
    <?php if (!empty($reporte['tendencia_asistencia'])): ?>
    inicializarGrafico('graficoTendencia', function(ctx) {
        // ... código del gráfico comentado
    });
    <?php endif; ?>
    */
    
    // Forzar redibujado después de que todos los gráficos estén inicializados
    setTimeout(function() {
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.chart) {
                instance.chart.resize();
            }
        });
    }, 500);
});

// NUEVAS FUNCIONES PARA LAS TABLAS

// Función para filtrar estudiantes en la tabla completa
function filtrarEstudiantes() {
    const busqueda = document.getElementById('buscarEstudiante').value.toLowerCase();
    const filtroEstado = document.getElementById('filtroEstado').value;
    const tabla = document.getElementById('tablaEstudiantes');
    const filas = tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    let contador = 1;
    
    for (let i = 0; i < filas.length; i++) {
        const fila = filas[i];
        const nombre = fila.getAttribute('data-nombre');
        const dni = fila.getAttribute('data-dni');
        const estado = fila.getAttribute('data-estado');
        
        let mostrar = true;
        
        // Filtro por texto (nombre o DNI)
        if (busqueda && !nombre.includes(busqueda) && !dni.includes(busqueda)) {
            mostrar = false;
        }
        
        // Filtro por estado
        if (filtroEstado && estado !== filtroEstado) {
            mostrar = false;
        }
        
        if (mostrar) {
            fila.style.display = '';
            fila.cells[0].textContent = contador++; // Renumerar
        } else {
            fila.style.display = 'none';
        }
    }
}

// Función para filtrar inasistencias
function filtrarInasistencias() {
    const filtroFecha = document.getElementById('filtroFecha').value;
    const filtroTipo = document.getElementById('filtroTipoFalta').value;
    const tabla = document.getElementById('tablaInasistencias');
    
    if (!tabla) return; // Si no existe la tabla, salir
    
    const filas = tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    
    for (let i = 0; i < filas.length; i++) {
        const fila = filas[i];
        const fecha = fila.getAttribute('data-fecha');
        const tipo = fila.getAttribute('data-tipo');
        
        let mostrar = true;
        
        // Filtro por fecha
        if (filtroFecha && fecha !== filtroFecha) {
            mostrar = false;
        }
        
        // Filtro por tipo de falta
        if (filtroTipo && tipo !== filtroTipo) {
            mostrar = false;
        }
        
        fila.style.display = mostrar ? '' : 'none';
    }
}

// Función para exportar tabla de estudiantes a CSV
function exportarTablaCSV() {
    const tabla = document.getElementById('tablaEstudiantes');
    const filas = tabla.querySelectorAll('tr:not([style*="display: none"])'); // Solo filas visibles
    let csv = [];
    
    // Encabezados
    const encabezados = [];
    const celdas_encabezado = filas[0].querySelectorAll('th');
    for (let j = 0; j < celdas_encabezado.length - 1; j++) { // Excluir columna de acciones
        encabezados.push('"' + celdas_encabezado[j].textContent.trim().replace(/"/g, '""') + '"');
    }
    csv.push(encabezados.join(','));
    
    // Datos
    for (let i = 1; i < filas.length; i++) { // Empezar desde 1 para saltar encabezados
        const fila = [];
        const celdas = filas[i].querySelectorAll('td');
        for (let j = 0; j < celdas.length - 1; j++) { // Excluir columna de acciones
            let texto = celdas[j].textContent.trim().replace(/"/g, '""');
            // Limpiar texto de badges y elementos extra
            texto = texto.replace(/\s+/g, ' ');
            fila.push('"' + texto + '"');
        }
        csv.push(fila.join(','));
    }
    
    // Crear y descargar archivo
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'asistencia_curso_<?= date("Y-m-d") ?>.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Inicializar funciones al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Configurar eventos para filtros
    const buscarInput = document.getElementById('buscarEstudiante');
    const filtroEstado = document.getElementById('filtroEstado');
    const filtroFecha = document.getElementById('filtroFecha');
    const filtroTipo = document.getElementById('filtroTipoFalta');
    
    if (buscarInput) {
        buscarInput.addEventListener('input', filtrarEstudiantes);
    }
    
    if (filtroEstado) {
        filtroEstado.addEventListener('change', filtrarEstudiantes);
    }
    
    if (filtroFecha) {
        filtroFecha.addEventListener('change', filtrarInasistencias);
    }
    
    if (filtroTipo) {
        filtroTipo.addEventListener('change', filtrarInasistencias);
    }
});

// Función para mostrar/ocultar campos de fechas personalizadas y actualizar información del período
function toggleFechasPersonalizadas() {
    const periodoSeleccionado = document.getElementById('periodo').value;
    const fechasPersonalizadas = document.getElementById('fechas_personalizadas');
    const periodoInfo = document.getElementById('periodo_info');
    const periodoFechas = document.getElementById('periodo_fechas');
    
    if (periodoSeleccionado === 'personalizado') {
        fechasPersonalizadas.style.display = 'block';
        periodoInfo.style.display = 'none';
    } else {
        fechasPersonalizadas.style.display = 'none';
        periodoInfo.style.display = 'block';
        
        // Actualizar las fechas mostradas según el período seleccionado
        let fechaTexto = '';
        const anioActual = new Date().getFullYear();
        
        switch(periodoSeleccionado) {
            case 'mes_actual':
                const hoy = new Date();
                const primerDia = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                const ultimoDia = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
                fechaTexto = formatearFecha(primerDia) + ' al ' + formatearFecha(ultimoDia);
                break;
            case '1er_bimestre':
                fechaTexto = '01/03/' + anioActual + ' al 30/04/' + anioActual;
                break;
            case '3er_bimestre':
                fechaTexto = '01/08/' + anioActual + ' al 30/09/' + anioActual;
                break;
            case '1er_cuatrimestre':
                fechaTexto = '01/03/' + anioActual + ' al 31/07/' + anioActual;
                break;
            case 'cuatrimestre_actual':
                // Determinar cuatrimestre actual basado en la fecha
                const mesActual = new Date().getMonth() + 1;
                if (mesActual >= 3 && mesActual <= 7) {
                    fechaTexto = '01/03/' + anioActual + ' al 31/07/' + anioActual + ' (1er Cuatrimestre)';
                } else {
                    fechaTexto = '01/08/' + anioActual + ' al 20/12/' + anioActual + ' (2do Cuatrimestre)';
                }
                break;
            case 'ciclo_lectivo':
                fechaTexto = 'Ciclo Lectivo Completo ' + anioActual;
                break;
            default:
                fechaTexto = 'Período no definido';
        }
        
        periodoFechas.textContent = fechaTexto;
    }
}

// Función auxiliar para formatear fechas
function formatearFecha(fecha) {
    const dia = fecha.getDate().toString().padStart(2, '0');
    const mes = (fecha.getMonth() + 1).toString().padStart(2, '0');
    const anio = fecha.getFullYear();
    return dia + '/' + mes + '/' + anio;
}

// Ejecutar al cargar la página para configurar el estado inicial
document.addEventListener('DOMContentLoaded', function() {
    toggleFechasPersonalizadas();
    
    // Agregar listener para el cambio de período
    document.getElementById('periodo').addEventListener('change', toggleFechasPersonalizadas);
});
</script>
<?php endif; ?>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>
