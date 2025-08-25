<?php
/**
 * sistema_periodos_automaticos.php - Sistema de detección automática de períodos escolares
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

class SistemaPeriodos {
    
    /**
     * Determina el bimestre según la fecha
     * @param string $fecha Fecha en formato Y-m-d
     * @param int $anio Año del ciclo lectivo
     * @return array Con keys: bimestre, cuatrimestre, periodo_nombre
     */
    public static function detectarPeriodo($fecha, $anio = null) {
        if ($anio === null) {
            $anio = date('Y', strtotime($fecha));
        }
        
        $fechaTimestamp = strtotime($fecha);
        
        // Definir períodos del ciclo lectivo
        $periodos = [
            [
                'bimestre' => 1,
                'cuatrimestre' => 1,
                'nombre' => '1° Bimestre - 1° Cuatrimestre',
                'inicio' => strtotime("$anio-03-03"),
                'fin' => strtotime("$anio-04-30")
            ],
            [
                'bimestre' => 2,
                'cuatrimestre' => 1,
                'nombre' => '2° Bimestre - 1° Cuatrimestre',
                'inicio' => strtotime("$anio-05-01"),
                'fin' => strtotime("$anio-07-15")
            ],
            [
                'bimestre' => 3,
                'cuatrimestre' => 2,
                'nombre' => '3° Bimestre - 2° Cuatrimestre',
                'inicio' => strtotime("$anio-08-01"),
                'fin' => strtotime("$anio-09-30")
            ],
            [
                'bimestre' => 4,
                'cuatrimestre' => 2,
                'nombre' => '4° Bimestre - 2° Cuatrimestre',
                'inicio' => strtotime("$anio-10-01"),
                'fin' => strtotime("$anio-12-07")
            ]
        ];
        
        // Buscar en qué período cae la fecha
        foreach ($periodos as $periodo) {
            if ($fechaTimestamp >= $periodo['inicio'] && $fechaTimestamp <= $periodo['fin']) {
                return [
                    'bimestre' => $periodo['bimestre'],
                    'cuatrimestre' => $periodo['cuatrimestre'],
                    'periodo_nombre' => $periodo['nombre'],
                    'es_intensificacion' => false
                ];
            }
        }
        
        // Verificar si es período de intensificación
        $intensificaciones = [
            [
                'nombre' => 'Intensificación Julio',
                'inicio' => strtotime("$anio-07-16"),
                'fin' => strtotime("$anio-07-30"),
                'cuatrimestre' => 1
            ],
            [
                'nombre' => 'Intensificación Diciembre',
                'inicio' => strtotime("$anio-12-09"),
                'fin' => strtotime("$anio-12-20"),
                'cuatrimestre' => 2
            ],
            [
                'nombre' => 'Intensificación Febrero',
                'inicio' => strtotime(($anio + 1) . "-02-10"),
                'fin' => strtotime(($anio + 1) . "-02-28"),
                'cuatrimestre' => 2
            ]
        ];
        
        foreach ($intensificaciones as $intensificacion) {
            if ($fechaTimestamp >= $intensificacion['inicio'] && $fechaTimestamp <= $intensificacion['fin']) {
                return [
                    'bimestre' => null,
                    'cuatrimestre' => $intensificacion['cuatrimestre'],
                    'periodo_nombre' => $intensificacion['nombre'],
                    'es_intensificacion' => true
                ];
            }
        }
        
        // Si no cae en ningún período, determinar el más cercano
        return self::periodoMasCercano($fecha, $anio);
    }
    
    /**
     * Encuentra el período más cercano a una fecha
     */
    private static function periodoMasCercano($fecha, $anio) {
        $fechaTimestamp = strtotime($fecha);
        
        // Si es antes del inicio del ciclo
        if ($fechaTimestamp < strtotime("$anio-03-03")) {
            return [
                'bimestre' => 1,
                'cuatrimestre' => 1,
                'periodo_nombre' => '1° Bimestre - 1° Cuatrimestre (fecha anterior al inicio)',
                'es_intensificacion' => false
            ];
        }
        
        // Si es después del fin del ciclo
        if ($fechaTimestamp > strtotime("$anio-12-20")) {
            return [
                'bimestre' => 4,
                'cuatrimestre' => 2,
                'periodo_nombre' => '4° Bimestre - 2° Cuatrimestre (fecha posterior al cierre)',
                'es_intensificacion' => false
            ];
        }
        
        // Por defecto, asignar al bimestre actual según el mes
        $mes = date('n', $fechaTimestamp);
        if ($mes >= 3 && $mes <= 4) {
            return ['bimestre' => 1, 'cuatrimestre' => 1, 'periodo_nombre' => '1° Bimestre - 1° Cuatrimestre', 'es_intensificacion' => false];
        } elseif ($mes >= 5 && $mes <= 7) {
            return ['bimestre' => 2, 'cuatrimestre' => 1, 'periodo_nombre' => '2° Bimestre - 1° Cuatrimestre', 'es_intensificacion' => false];
        } elseif ($mes >= 8 && $mes <= 9) {
            return ['bimestre' => 3, 'cuatrimestre' => 2, 'periodo_nombre' => '3° Bimestre - 2° Cuatrimestre', 'es_intensificacion' => false];
        } else {
            return ['bimestre' => 4, 'cuatrimestre' => 2, 'periodo_nombre' => '4° Bimestre - 2° Cuatrimestre', 'es_intensificacion' => false];
        }
    }
    
    /**
     * Obtiene información completa del calendario escolar
     */
    public static function obtenerCalendarioCompleto($anio) {
        return [
            'primer_cuatrimestre' => [
                'inicio' => "$anio-03-03",
                'valoracion_preliminar' => "$anio-04-03",
                'cierre' => "$anio-07-15",
                'intensificacion' => [
                    'inicio' => "$anio-07-16",
                    'fin' => "$anio-07-30"
                ],
                'bimestres' => [
                    1 => ['inicio' => "$anio-03-03", 'fin' => "$anio-04-30"],
                    2 => ['inicio' => "$anio-05-01", 'fin' => "$anio-07-15"]
                ]
            ],
            'segundo_cuatrimestre' => [
                'inicio' => "$anio-08-01",
                'valoracion_preliminar' => "$anio-09-01",
                'cierre' => "$anio-12-07",
                'intensificacion_diciembre' => [
                    'inicio' => "$anio-12-09",
                    'fin' => "$anio-12-20"
                ],
                'intensificacion_febrero' => [
                    'inicio' => ($anio + 1) . "-02-10",
                    'fin' => ($anio + 1) . "-02-28"
                ],
                'bimestres' => [
                    3 => ['inicio' => "$anio-08-01", 'fin' => "$anio-09-30"],
                    4 => ['inicio' => "$anio-10-01", 'fin' => "$anio-12-07"]
                ]
            ]
        ];
    }
    
    /**
     * Valida si una fecha es válida para cargar contenidos
     */
    public static function esFechaValida($fecha, $anio) {
        $fechaTimestamp = strtotime($fecha);
        $inicioClases = strtotime("$anio-03-03");
        $finClases = strtotime("$anio-12-20");
        
        return $fechaTimestamp >= $inicioClases && $fechaTimestamp <= $finClases;
    }
}
?>