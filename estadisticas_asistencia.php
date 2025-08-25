<?php
/**
 * estadisticas_asistencia.php - Funciones de estadísticas de asistencia (VERSIÓN MEJORADA)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORAS IMPLEMENTADAS:
 * - Soporte para estado "cuarto_falta" (1/4 de falta)
 * - Soporte para estado "tres_cuartos_falta" (3/4 de falta)
 * - Soporte para estado "no_computa" (que no suma para regularidad)
 * - Manejo del campo motivo_falta y motivo_no_computa
 * - Cálculos actualizados para las nuevas fracciones de falta
 * - Exclusión de "no_computa" del cálculo de regularidad
 */

// Prevenir acceso directo a este archivo
if (!defined('APP_NAME')) {
    die("Acceso no autorizado");
}

/**
 * Clase para manejar las estadísticas de asistencia (Versión Mejorada)
 */
class EstadisticasAsistencia {
    private $db;
    
    /**
     * Constructor
     * @param Database $db Instancia de la base de datos
     */
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Obtiene estadísticas generales de asistencia para un período específico
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Estadísticas de asistencia
     */
    public function obtenerEstadisticasGenerales($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Obtener todas las asistencias para el período y curso
            $asistencias = $this->db->fetchAll(
                "SELECT a.estado, COUNT(*) as cantidad 
                 FROM asistencias a 
                 WHERE a.curso_id = ? AND a.fecha BETWEEN ? AND ? 
                 GROUP BY a.estado",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            // Inicializar estadísticas con los nuevos estados
            $estadisticas = [
                'presentes' => 0,
                'ausentes' => 0,
                'medias_faltas' => 0,
                'cuartos_faltas' => 0,
                'tres_cuartos_faltas' => 0,
                'justificadas' => 0,
                'no_computa' => 0,
                'total' => 0
            ];
            
            // Procesar resultados
            foreach ($asistencias as $asistencia) {
                switch ($asistencia['estado']) {
                    case 'presente':
                        $estadisticas['presentes'] = $asistencia['cantidad'];
                        break;
                    case 'ausente':
                        $estadisticas['ausentes'] = $asistencia['cantidad'];
                        break;
                    case 'media_falta':
                        $estadisticas['medias_faltas'] = $asistencia['cantidad'];
                        break;
                    case 'cuarto_falta':
                        $estadisticas['cuartos_faltas'] = $asistencia['cantidad'];
                        break;
                    case 'tres_cuartos_falta':
                        $estadisticas['tres_cuartos_faltas'] = $asistencia['cantidad'];
                        break;
                    case 'justificada':
                        $estadisticas['justificadas'] = $asistencia['cantidad'];
                        break;
                    case 'no_computa':
                        $estadisticas['no_computa'] = $asistencia['cantidad'];
                        break;
                }
                $estadisticas['total'] += $asistencia['cantidad'];
            }
            
            // Calcular porcentajes
            if ($estadisticas['total'] > 0) {
                $estadisticas['porcentaje_presentes'] = ($estadisticas['presentes'] / $estadisticas['total']) * 100;
                $estadisticas['porcentaje_ausentes'] = ($estadisticas['ausentes'] / $estadisticas['total']) * 100;
                $estadisticas['porcentaje_medias_faltas'] = ($estadisticas['medias_faltas'] / $estadisticas['total']) * 100;
                $estadisticas['porcentaje_cuartos_faltas'] = ($estadisticas['cuartos_faltas'] / $estadisticas['total']) * 100;
                $estadisticas['porcentaje_tres_cuartos_faltas'] = ($estadisticas['tres_cuartos_faltas'] / $estadisticas['total']) * 100;
                $estadisticas['porcentaje_justificadas'] = ($estadisticas['justificadas'] / $estadisticas['total']) * 100;
                $estadisticas['porcentaje_no_computa'] = ($estadisticas['no_computa'] / $estadisticas['total']) * 100;
            } else {
                $estadisticas['porcentaje_presentes'] = 0;
                $estadisticas['porcentaje_ausentes'] = 0;
                $estadisticas['porcentaje_medias_faltas'] = 0;
                $estadisticas['porcentaje_cuartos_faltas'] = 0;
                $estadisticas['porcentaje_tres_cuartos_faltas'] = 0;
                $estadisticas['porcentaje_justificadas'] = 0;
                $estadisticas['porcentaje_no_computa'] = 0;
            }
            
            return $estadisticas;
        } catch (Exception $e) {
            // En caso de error, devolver estadísticas vacías
            error_log('Error al obtener estadísticas de asistencia: ' . $e->getMessage());
            return [
                'presentes' => 0,
                'ausentes' => 0,
                'medias_faltas' => 0,
                'cuartos_faltas' => 0,
                'tres_cuartos_faltas' => 0,
                'justificadas' => 0,
                'no_computa' => 0,
                'total' => 0,
                'porcentaje_presentes' => 0,
                'porcentaje_ausentes' => 0,
                'porcentaje_medias_faltas' => 0,
                'porcentaje_cuartos_faltas' => 0,
                'porcentaje_tres_cuartos_faltas' => 0,
                'porcentaje_justificadas' => 0,
                'porcentaje_no_computa' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene estadísticas de asistencia por estudiante para un período específico
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Estadísticas de asistencia por estudiante
     */
    public function obtenerEstadisticasPorEstudiante($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Obtener todos los estudiantes matriculados en el curso
            $estudiantes = $this->db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
                 ORDER BY u.apellido, u.nombre",
                [$cursoId]
            );
            
            // Para cada estudiante, obtener sus estadísticas de asistencia
            $estadisticasEstudiantes = [];
            
            foreach ($estudiantes as $estudiante) {
                $asistencias = $this->db->fetchAll(
                    "SELECT a.estado, COUNT(*) as cantidad 
                     FROM asistencias a 
                     WHERE a.estudiante_id = ? AND a.curso_id = ? AND a.fecha BETWEEN ? AND ? 
                     GROUP BY a.estado",
                    [$estudiante['id'], $cursoId, $fechaInicio, $fechaFin]
                );
                
                // Inicializar estadísticas para este estudiante
                $estadisticaEstudiante = [
                    'id' => $estudiante['id'],
                    'nombre' => $estudiante['nombre'],
                    'apellido' => $estudiante['apellido'],
                    'dni' => $estudiante['dni'],
                    'presentes' => 0,
                    'ausentes' => 0,
                    'medias_faltas' => 0,
                    'cuartos_faltas' => 0,
                    'tres_cuartos_faltas' => 0,
                    'justificadas' => 0,
                    'no_computa' => 0,
                    'total' => 0
                ];
                
                // Procesar resultados
                foreach ($asistencias as $asistencia) {
                    switch ($asistencia['estado']) {
                        case 'presente':
                            $estadisticaEstudiante['presentes'] = $asistencia['cantidad'];
                            break;
                        case 'ausente':
                            $estadisticaEstudiante['ausentes'] = $asistencia['cantidad'];
                            break;
                        case 'media_falta':
                            $estadisticaEstudiante['medias_faltas'] = $asistencia['cantidad'];
                            break;
                        case 'cuarto_falta':
                            $estadisticaEstudiante['cuartos_faltas'] = $asistencia['cantidad'];
                            break;
                        case 'tres_cuartos_falta':
                            $estadisticaEstudiante['tres_cuartos_faltas'] = $asistencia['cantidad'];
                            break;
                        case 'justificada':
                            $estadisticaEstudiante['justificadas'] = $asistencia['cantidad'];
                            break;
                        case 'no_computa':
                            $estadisticaEstudiante['no_computa'] = $asistencia['cantidad'];
                            break;
                    }
                    $estadisticaEstudiante['total'] += $asistencia['cantidad'];
                }
                
                // Calcular días hábiles en el período
                $diasHabiles = $this->contarDiasHabiles($fechaInicio, $fechaFin);
                $estadisticaEstudiante['dias_habiles'] = $diasHabiles;
                
                // Calcular días que computan para regularidad (excluyendo "no_computa")
                $diasQueComputan = $diasHabiles - $estadisticaEstudiante['no_computa'];
                $estadisticaEstudiante['dias_que_computan'] = $diasQueComputan;
                
                // Calcular faltas totales con las nuevas fracciones (excluyendo justificadas y no_computa)
                // ausentes = 1, tres_cuartos_falta = 0.75, medias_faltas = 0.5, cuartos_faltas = 0.25
                $faltasTotales = $estadisticaEstudiante['ausentes'] + 
                               ($estadisticaEstudiante['tres_cuartos_faltas'] * 0.75) +
                               ($estadisticaEstudiante['medias_faltas'] * 0.5) + 
                               ($estadisticaEstudiante['cuartos_faltas'] * 0.25);
                $estadisticaEstudiante['faltas_totales'] = $faltasTotales;
                
                // Calcular porcentaje de asistencia (solo sobre días que computan)
                if ($diasQueComputan > 0) {
                    $diasPresentes = $diasQueComputan - $faltasTotales;
                    $estadisticaEstudiante['porcentaje_asistencia'] = ($diasPresentes / $diasQueComputan) * 100;
                    // Asegurar que el porcentaje esté entre 0 y 100
                    $estadisticaEstudiante['porcentaje_asistencia'] = max(0, min(100, $estadisticaEstudiante['porcentaje_asistencia']));
                } else {
                    $estadisticaEstudiante['porcentaje_asistencia'] = 0;
                }
                
                // Determinar estado de regularidad
                if ($estadisticaEstudiante['porcentaje_asistencia'] >= 85) {
                    $estadisticaEstudiante['estado_regularidad'] = 'regular';
                } elseif ($estadisticaEstudiante['porcentaje_asistencia'] >= 75) {
                    $estadisticaEstudiante['estado_regularidad'] = 'riesgo';
                } else {
                    $estadisticaEstudiante['estado_regularidad'] = 'libre';
                }
                
                $estadisticasEstudiantes[] = $estadisticaEstudiante;
            }
            
            return $estadisticasEstudiantes;
        } catch (Exception $e) {
            // En caso de error, devolver array vacío
            error_log('Error al obtener estadísticas por estudiante: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene estadísticas de asistencia por día de la semana
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Estadísticas por día de la semana
     */
    public function obtenerEstadisticasPorDiaSemana($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Usar strftime que es compatible con SQLite
            $asistencias = $this->db->fetchAll(
                "SELECT CAST(strftime('%w', a.fecha) AS INTEGER) as dia_semana, a.estado, COUNT(*) as cantidad 
                 FROM asistencias a 
                 WHERE a.curso_id = ? AND a.fecha BETWEEN ? AND ? 
                 GROUP BY dia_semana, a.estado 
                 ORDER BY dia_semana, a.estado",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            // Inicializar estadísticas por día (0=domingo, 6=sábado)
            $estadisticasPorDia = [];
            for ($i = 0; $i <= 6; $i++) {
                $estadisticasPorDia[$i] = [
                    'presentes' => 0,
                    'ausentes' => 0,
                    'medias_faltas' => 0,
                    'cuartos_faltas' => 0,
                    'tres_cuartos_faltas' => 0,
                    'justificadas' => 0,
                    'no_computa' => 0,
                    'total' => 0,
                    'porcentaje_presentes' => 0,
                    'porcentaje_ausentes' => 0
                ];
            }
            
            // Procesar resultados
            foreach ($asistencias as $asistencia) {
                $diaSemana = $asistencia['dia_semana'];
                
                switch ($asistencia['estado']) {
                    case 'presente':
                        $estadisticasPorDia[$diaSemana]['presentes'] = $asistencia['cantidad'];
                        break;
                    case 'ausente':
                        $estadisticasPorDia[$diaSemana]['ausentes'] = $asistencia['cantidad'];
                        break;
                    case 'media_falta':
                        $estadisticasPorDia[$diaSemana]['medias_faltas'] = $asistencia['cantidad'];
                        break;
                    case 'cuarto_falta':
                        $estadisticasPorDia[$diaSemana]['cuartos_faltas'] = $asistencia['cantidad'];
                        break;
                    case 'tres_cuartos_falta':
                        $estadisticasPorDia[$diaSemana]['tres_cuartos_faltas'] = $asistencia['cantidad'];
                        break;
                    case 'justificada':
                        $estadisticasPorDia[$diaSemana]['justificadas'] = $asistencia['cantidad'];
                        break;
                    case 'no_computa':
                        $estadisticasPorDia[$diaSemana]['no_computa'] = $asistencia['cantidad'];
                        break;
                }
                
                $estadisticasPorDia[$diaSemana]['total'] += $asistencia['cantidad'];
            }
            
            // Calcular porcentajes para cada día
            foreach ($estadisticasPorDia as $diaSemana => &$estadisticas) {
                if ($estadisticas['total'] > 0) {
                    $estadisticas['porcentaje_presentes'] = ($estadisticas['presentes'] / $estadisticas['total']) * 100;
                    $estadisticas['porcentaje_ausentes'] = ($estadisticas['ausentes'] / $estadisticas['total']) * 100;
                }
            }
            
            return $estadisticasPorDia;
        } catch (Exception $e) {
            // En caso de error, devolver array vacío
            error_log('Error al obtener estadísticas por día de la semana: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene motivos de falta más comunes
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Motivos de falta más comunes
     */
    public function obtenerMotivosFaltaComunes($cursoId, $fechaInicio, $fechaFin) {
        try {
            $motivos = $this->db->fetchAll(
                "SELECT motivo_falta, COUNT(*) as cantidad 
                 FROM asistencias 
                 WHERE curso_id = ? AND fecha BETWEEN ? AND ? 
                 AND estado = 'justificada' AND motivo_falta IS NOT NULL AND motivo_falta != '' 
                 GROUP BY motivo_falta 
                 ORDER BY cantidad DESC 
                 LIMIT 10",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            return $motivos;
        } catch (Exception $e) {
            error_log('Error al obtener motivos de falta: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene motivos de "No computa" más comunes
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Motivos de "No computa" más comunes
     */
    public function obtenerMotivosNoComputaComunes($cursoId, $fechaInicio, $fechaFin) {
        try {
            $motivos = $this->db->fetchAll(
                "SELECT motivo_no_computa, COUNT(*) as cantidad 
                 FROM asistencias 
                 WHERE curso_id = ? AND fecha BETWEEN ? AND ? 
                 AND estado = 'no_computa' AND motivo_no_computa IS NOT NULL AND motivo_no_computa != '' 
                 GROUP BY motivo_no_computa 
                 ORDER BY cantidad DESC 
                 LIMIT 10",
                [$cursoId, $fechaInicio, $fechaFin]
            );
            
            return $motivos;
        } catch (Exception $e) {
            error_log('Error al obtener motivos de no computa: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Genera un reporte de asistencia general para un curso y período (VERSIÓN MEJORADA)
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Datos del reporte
     */
    public function generarReporteAsistencia($cursoId, $fechaInicio, $fechaFin) {
        try {
            // Obtener información del curso
            $curso = $this->db->fetchOne("SELECT * FROM cursos WHERE id = ?", [$cursoId]);
            
            if (!$curso) {
                throw new Exception("Curso no encontrado");
            }
            
            // Obtener estadísticas generales
            $estadisticasGenerales = $this->obtenerEstadisticasGenerales($cursoId, $fechaInicio, $fechaFin);
            
            // Obtener estadísticas por estudiante
            $estadisticasPorEstudiante = $this->obtenerEstadisticasPorEstudiante($cursoId, $fechaInicio, $fechaFin);
            
            // Obtener estadísticas por día de la semana
            $estadisticasPorDiaSemana = $this->obtenerEstadisticasPorDiaSemana($cursoId, $fechaInicio, $fechaFin);
            
            // Obtener motivos de falta más comunes
            $motivosFaltaComunes = $this->obtenerMotivosFaltaComunes($cursoId, $fechaInicio, $fechaFin);
            
            // Obtener motivos de "No computa" más comunes
            $motivosNoComputaComunes = $this->obtenerMotivosNoComputaComunes($cursoId, $fechaInicio, $fechaFin);
            
            // Calcular promedios y totales
            $totalEstudiantes = count($estadisticasPorEstudiante);
            $regularesCount = 0;
            $riesgoCount = 0;
            $libreCount = 0;
            
            foreach ($estadisticasPorEstudiante as $estudiante) {
                switch ($estudiante['estado_regularidad']) {
                    case 'regular':
                        $regularesCount++;
                        break;
                    case 'riesgo':
                        $riesgoCount++;
                        break;
                    case 'libre':
                        $libreCount++;
                        break;
                }
            }
            
            // Crear reporte
            $reporte = [
                'curso' => $curso,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'dias_habiles' => $this->contarDiasHabiles($fechaInicio, $fechaFin),
                'estadisticas_generales' => $estadisticasGenerales,
                'estadisticas_por_estudiante' => $estadisticasPorEstudiante,
                'estadisticas_por_dia_semana' => $estadisticasPorDiaSemana,
                'motivos_falta_comunes' => $motivosFaltaComunes,
                'motivos_no_computa_comunes' => $motivosNoComputaComunes,
                'total_estudiantes' => $totalEstudiantes,
                'estudiantes_regulares' => $regularesCount,
                'estudiantes_en_riesgo' => $riesgoCount,
                'estudiantes_libres' => $libreCount,
                'porcentaje_regulares' => $totalEstudiantes > 0 ? ($regularesCount / $totalEstudiantes) * 100 : 0,
                'porcentaje_riesgo' => $totalEstudiantes > 0 ? ($riesgoCount / $totalEstudiantes) * 100 : 0,
                'porcentaje_libres' => $totalEstudiantes > 0 ? ($libreCount / $totalEstudiantes) * 100 : 0
            ];
            
            return $reporte;
        } catch (Exception $e) {
            error_log('Error al generar reporte de asistencia: ' . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene estudiantes en riesgo de perder la regularidad
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @param float $umbralRiesgo Umbral de riesgo (por defecto 85%)
     * @return array Estudiantes en riesgo
     */
    public function obtenerEstudiantesEnRiesgo($cursoId, $fechaInicio, $fechaFin, $umbralRiesgo = 85) {
        $estadisticasPorEstudiante = $this->obtenerEstadisticasPorEstudiante($cursoId, $fechaInicio, $fechaFin);
        
        $estudiantesEnRiesgo = array_filter($estadisticasPorEstudiante, function($estudiante) use ($umbralRiesgo) {
            return $estudiante['porcentaje_asistencia'] < $umbralRiesgo;
        });
        
        // Ordenar por porcentaje de asistencia (ascendente - los más críticos primero)
        usort($estudiantesEnRiesgo, function($a, $b) {
            return $a['porcentaje_asistencia'] <=> $b['porcentaje_asistencia'];
        });
        
        return $estudiantesEnRiesgo;
    }
    
    /**
     * Contar días hábiles (lunes a viernes) entre dos fechas
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return int Número de días hábiles
     */
    public function contarDiasHabiles($fechaInicio, $fechaFin) {
        try {
            $inicio = new DateTime($fechaInicio);
            $fin = new DateTime($fechaFin);
            $fin->modify('+1 day'); // Para incluir el último día
            
            $diasHabiles = 0;
            $intervalo = new DateInterval('P1D'); // Intervalo de un día
            $periodo = new DatePeriod($inicio, $intervalo, $fin);
            
            foreach ($periodo as $fecha) {
                $diaSemana = $fecha->format('N'); // 1 (lunes) a 7 (domingo)
                
                if ($diaSemana <= 5) { // Lunes a viernes
                    $diasHabiles++;
                }
            }
            
            return $diasHabiles;
        } catch (Exception $e) {
            error_log('Error al contar días hábiles: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Obtiene el resumen de asistencia para un estudiante específico
     * @param int $estudianteId ID del estudiante
     * @param int $cursoId ID del curso
     * @param string $fechaInicio Fecha de inicio (formato Y-m-d)
     * @param string $fechaFin Fecha de fin (formato Y-m-d)
     * @return array Resumen de asistencia del estudiante
     */
    public function obtenerResumenEstudiante($estudianteId, $cursoId, $fechaInicio, $fechaFin) {
        try {
            $estadisticasPorEstudiante = $this->obtenerEstadisticasPorEstudiante($cursoId, $fechaInicio, $fechaFin);
            
            foreach ($estadisticasPorEstudiante as $estudiante) {
                if ($estudiante['id'] == $estudianteId) {
                    return $estudiante;
                }
            }
            
            return null;
        } catch (Exception $e) {
            error_log('Error al obtener resumen del estudiante: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Genera estadísticas comparativas entre dos períodos
     * @param int $cursoId ID del curso
     * @param string $periodo1Inicio Fecha de inicio del primer período
     * @param string $periodo1Fin Fecha de fin del primer período
     * @param string $periodo2Inicio Fecha de inicio del segundo período
     * @param string $periodo2Fin Fecha de fin del segundo período
     * @return array Comparación entre períodos
     */
    public function compararPeriodos($cursoId, $periodo1Inicio, $periodo1Fin, $periodo2Inicio, $periodo2Fin) {
        try {
            $reporte1 = $this->generarReporteAsistencia($cursoId, $periodo1Inicio, $periodo1Fin);
            $reporte2 = $this->generarReporteAsistencia($cursoId, $periodo2Inicio, $periodo2Fin);
            
            if (isset($reporte1['error']) || isset($reporte2['error'])) {
                throw new Exception('Error al generar uno de los reportes de comparación');
            }
            
            return [
                'periodo1' => $reporte1,
                'periodo2' => $reporte2,
                'comparacion' => [
                    'diferencia_regulares' => $reporte2['porcentaje_regulares'] - $reporte1['porcentaje_regulares'],
                    'diferencia_riesgo' => $reporte2['porcentaje_riesgo'] - $reporte1['porcentaje_riesgo'],
                    'diferencia_libres' => $reporte2['porcentaje_libres'] - $reporte1['porcentaje_libres'],
                    'tendencia_asistencia' => $reporte2['porcentaje_regulares'] > $reporte1['porcentaje_regulares'] ? 'mejora' : 
                                            ($reporte2['porcentaje_regulares'] < $reporte1['porcentaje_regulares'] ? 'empeora' : 'estable')
                ]
            ];
        } catch (Exception $e) {
            error_log('Error al comparar períodos: ' . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
?>
