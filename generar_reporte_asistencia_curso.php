<?php
/**
 * generar_reporte_asistencia_curso.php - Generación de reportes de asistencia por curso en PDF (VERSIÓN ACTUALIZADA)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORAS IMPLEMENTADAS:
 * - Soporte para estado "cuarto_falta" (1/4 de falta)
 * - Análisis de motivos de ausencias justificadas
 * - Cálculo mejorado de inasistencias
 * - Mejores estadísticas y recomendaciones
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para tener acceso a la clase Database
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar permisos
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'preceptor'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetros requeridos
if (!isset($_GET['curso']) || !isset($_GET['fecha_inicio']) || !isset($_GET['fecha_fin'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el reporte';
    $_SESSION['message_type'] = 'danger';
    header('Location: dashboard_asistencias.php');
    exit;
}

// Obtener parámetros
$cursoId = intval($_GET['curso']);
$fechaInicio = $_GET['fecha_inicio'];
$fechaFin = $_GET['fecha_fin'];

// Definir motivos de ausencia justificada predefinidos
$motivosJustificados = [
    'certificado_medico' => 'Certificado médico',
    'tramite_familiar' => 'Trámite familiar',
    'viaje_familiar' => 'Viaje familiar',
    'duelo_familiar' => 'Duelo familiar',
    'consulta_medica' => 'Consulta médica',
    'estudios_medicos' => 'Estudios médicos',
    'tramite_documentacion' => 'Trámite de documentación',
    'problema_transporte' => 'Problema de transporte',
    'emergencia_familiar' => 'Emergencia familiar',
    'actividad_deportiva' => 'Actividad deportiva representativa',
    'actividad_cultural' => 'Actividad cultural/artística',
    'comparendo_judicial' => 'Comparendo judicial',
    'mudanza' => 'Mudanza',
    'boda_familiar' => 'Boda familiar',
    'nacimiento_hermano' => 'Nacimiento de hermano',
    'otro' => 'Otro motivo (especificar)'
];

// Función auxiliar para obtener texto del motivo justificado
function obtenerTextoMotivoJustificado($codigo, $textoOtro = '', $motivosJustificados = []) {
    if ($codigo === 'otro' && !empty($textoOtro)) {
        return $motivosJustificados[$codigo] . ': ' . $textoOtro;
    }
    
    return isset($motivosJustificados[$codigo]) ? $motivosJustificados[$codigo] : $codigo;
}

// Incluir la clase de estadísticas de asistencia
define('APP_NAME', APP_NAME); // Definir constante para evitar error
require_once 'estadisticas_asistencia.php';

// Obtener conexión a la base de datos
$db = Database::getInstance();
$estadisticas = new EstadisticasAsistencia($db);

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: dashboard_asistencias.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: dashboard_asistencias.php');
    exit;
}

// Obtener datos del curso
try {
    $curso = $db->fetchOne("SELECT * FROM cursos WHERE id = ?", [$cursoId]);
    
    if (!$curso) {
        $_SESSION['message'] = 'Curso no encontrado.';
        $_SESSION['message_type'] = 'danger';
        header('Location: dashboard_asistencias.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos del curso: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: dashboard_asistencias.php');
    exit;
}

// Generar reporte de asistencia mejorado
try {
    // Obtener estudiantes del curso
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni 
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
         ORDER BY u.apellido, u.nombre",
        [$cursoId]
    );
    
    if (empty($estudiantes)) {
        throw new Exception('No se encontraron estudiantes en el curso seleccionado.');
    }
    
    // Obtener asistencias del período
    $asistencias = $db->fetchAll(
        "SELECT a.*, u.nombre, u.apellido, u.dni, DAYOFWEEK(a.fecha) as dia_semana
         FROM asistencias a 
         JOIN usuarios u ON a.estudiante_id = u.id
         WHERE a.curso_id = ? AND a.fecha BETWEEN ? AND ?
         ORDER BY u.apellido, u.nombre, a.fecha",
        [$cursoId, $fechaInicio, $fechaFin]
    );
    
    // Procesar estadísticas mejoradas
    $reporte = [
        'curso' => $curso,
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin,
        'total_estudiantes' => count($estudiantes),
        'dias_habiles' => contarDiasHabiles($fechaInicio, $fechaFin),
        'estadisticas_por_estudiante' => [],
        'estadisticas_por_dia_semana' => [],
        'estadisticas_generales' => [
            'presentes' => 0,
            'ausentes' => 0,
            'medias_faltas' => 0,
            'cuartos_faltas' => 0,
            'justificadas' => 0,
            'total' => 0
        ],
        'motivos_justificaciones' => [],
        'estudiantes_regulares' => 0,
        'estudiantes_en_riesgo' => 0,
        'estudiantes_libres' => 0
    ];
    
    // Procesar estadísticas por estudiante
    foreach ($estudiantes as $estudiante) {
        $asistenciasEstudiante = array_filter($asistencias, function($a) use ($estudiante) {
            return $a['estudiante_id'] == $estudiante['id'];
        });
        
        $stats = [
            'id' => $estudiante['id'],
            'nombre' => $estudiante['nombre'],
            'apellido' => $estudiante['apellido'],
            'dni' => $estudiante['dni'],
            'presentes' => 0,
            'ausentes' => 0,
            'medias_faltas' => 0,
            'cuartos_faltas' => 0,
            'justificadas' => 0,
            'total_registros' => count($asistenciasEstudiante),
            'faltas_totales' => 0,
            'porcentaje_asistencia' => 0,
            'estado_regularidad' => 'regular'
        ];
        
        foreach ($asistenciasEstudiante as $asistencia) {
            switch ($asistencia['estado']) {
                case 'presente':
                    $stats['presentes']++;
                    $reporte['estadisticas_generales']['presentes']++;
                    break;
                case 'ausente':
                    $stats['ausentes']++;
                    $stats['faltas_totales'] += 1;
                    $reporte['estadisticas_generales']['ausentes']++;
                    break;
                case 'media_falta':
                    $stats['medias_faltas']++;
                    $stats['faltas_totales'] += 0.5;
                    $reporte['estadisticas_generales']['medias_faltas']++;
                    break;
                case 'cuarto_falta':
                    $stats['cuartos_faltas']++;
                    $stats['faltas_totales'] += 0.25;
                    $reporte['estadisticas_generales']['cuartos_faltas']++;
                    break;
                case 'justificada':
                    $stats['justificadas']++;
                    $reporte['estadisticas_generales']['justificadas']++;
                    
                    // Contar motivos de justificación
                    if (!empty($asistencia['motivo_falta'])) {
                        $motivo = obtenerTextoMotivoJustificado(
                            $asistencia['motivo_falta'], 
                            $asistencia['motivo_otro'] ?? '', 
                            $motivosJustificados
                        );
                        
                        if (!isset($reporte['motivos_justificaciones'][$motivo])) {
                            $reporte['motivos_justificaciones'][$motivo] = 0;
                        }
                        $reporte['motivos_justificaciones'][$motivo]++;
                    }
                    break;
            }
            $reporte['estadisticas_generales']['total']++;
        }
        
        // Calcular porcentaje de asistencia
        if ($reporte['dias_habiles'] > 0) {
            $diasEfectivos = $reporte['dias_habiles'] - $stats['faltas_totales'];
            $stats['porcentaje_asistencia'] = ($diasEfectivos / $reporte['dias_habiles']) * 100;
        }
        
        // Determinar estado de regularidad
        if ($stats['porcentaje_asistencia'] >= 85) {
            $stats['estado_regularidad'] = 'regular';
            $reporte['estudiantes_regulares']++;
        } elseif ($stats['porcentaje_asistencia'] >= 75) {
            $stats['estado_regularidad'] = 'riesgo';
            $reporte['estudiantes_en_riesgo']++;
        } else {
            $stats['estado_regularidad'] = 'libre';
            $reporte['estudiantes_libres']++;
        }
        
        $reporte['estadisticas_por_estudiante'][] = $stats;
    }
    
    // Estadísticas por día de la semana
    foreach ($asistencias as $asistencia) {
        $dia = $asistencia['dia_semana'];
        if (!isset($reporte['estadisticas_por_dia_semana'][$dia])) {
            $reporte['estadisticas_por_dia_semana'][$dia] = [
                'presentes' => 0,
                'ausentes' => 0,
                'medias_faltas' => 0,
                'cuartos_faltas' => 0,
                'justificadas' => 0,
                'total' => 0
            ];
        }
        
        switch ($asistencia['estado']) {
            case 'presente':
                $reporte['estadisticas_por_dia_semana'][$dia]['presentes']++;
                break;
            case 'ausente':
                $reporte['estadisticas_por_dia_semana'][$dia]['ausentes']++;
                break;
            case 'media_falta':
                $reporte['estadisticas_por_dia_semana'][$dia]['medias_faltas']++;
                break;
            case 'cuarto_falta':
                $reporte['estadisticas_por_dia_semana'][$dia]['cuartos_faltas']++;
                break;
            case 'justificada':
                $reporte['estadisticas_por_dia_semana'][$dia]['justificadas']++;
                break;
        }
        $reporte['estadisticas_por_dia_semana'][$dia]['total']++;
    }
    
    // Calcular porcentajes generales
    $reporte['porcentaje_regulares'] = $reporte['total_estudiantes'] > 0 ? 
        ($reporte['estudiantes_regulares'] / $reporte['total_estudiantes']) * 100 : 0;
    $reporte['porcentaje_riesgo'] = $reporte['total_estudiantes'] > 0 ? 
        ($reporte['estudiantes_en_riesgo'] / $reporte['total_estudiantes']) * 100 : 0;
    $reporte['porcentaje_libres'] = $reporte['total_estudiantes'] > 0 ? 
        ($reporte['estudiantes_libres'] / $reporte['total_estudiantes']) * 100 : 0;
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al generar reporte de asistencia: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: dashboard_asistencias.php');
    exit;
}

// Función para contar días hábiles
function contarDiasHabiles($fechaInicio, $fechaFin) {
    $inicio = new DateTime($fechaInicio);
    $fin = new DateTime($fechaFin);
    $diasHabiles = 0;
    
    while ($inicio <= $fin) {
        $diaSemana = $inicio->format('N'); // 1 (lunes) a 7 (domingo)
        if ($diaSemana <= 5) { // Lunes a viernes
            $diasHabiles++;
        }
        $inicio->add(new DateInterval('P1D'));
    }
    
    return $diasHabiles;
}

// Incluir la biblioteca FPDF
require('lib/fpdf_utf8.php');

// Crear clase personalizada para el PDF
class ReporteAsistenciaPDF extends FPDF_UTF8 {
    // Variables para almacenar datos del reporte
    protected $titulo;
    protected $curso;
    protected $fechaInicio;
    protected $fechaFin;
    protected $fechaGeneracion;
    protected $motivosJustificados;
    
    // Constructor
    function __construct($titulo, $curso, $fechaInicio, $fechaFin, $motivosJustificados, $orientation = 'L', $unit = 'mm', $size = 'A4') {
        parent::__construct($orientation, $unit, $size);
        $this->titulo = $titulo;
        $this->curso = $curso;
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
        $this->fechaGeneracion = date('d/m/Y H:i:s');
        $this->motivosJustificados = $motivosJustificados;
    }
    
    // Cabecera de página
    function Header() {
        // Logo
        $this->Image('assets/img/logo.png', 10, 10, 20);
        
        // Título del reporte
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, 'Escuela Técnica Henry Ford', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, $this->titulo, 0, 1, 'C');
        
        // Información del curso
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Curso: ' . $this->curso['nombre'], 0, 1, 'R');
        $this->Cell(0, 5, 'Período: ' . date('d/m/Y', strtotime($this->fechaInicio)) . ' - ' . date('d/m/Y', strtotime($this->fechaFin)), 0, 1, 'R');
        $this->Cell(0, 5, 'Fecha de generación: ' . $this->fechaGeneracion, 0, 1, 'R');
        
        // Línea
        $this->Line(10, $this->GetY(), 280, $this->GetY());
        $this->Ln(5);
    }
    
    // Pie de página
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Página ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    // Resumen general mejorado
    function ResumenGeneral($reporte) {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Resumen General', 0, 1, 'L');
        
        // Crear cuadro de resumen
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 7, 'Total de Estudiantes:', 1, 0, 'L', false);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 7, $reporte['total_estudiantes'], 1, 1, 'C', false);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 7, 'Días Hábiles en el Período:', 1, 0, 'L', false);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 7, $reporte['dias_habiles'], 1, 1, 'C', false);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 7, 'Estudiantes Regulares (≥85%):', 1, 0, 'L', false);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 7, $reporte['estudiantes_regulares'] . ' (' . number_format($reporte['porcentaje_regulares'], 1) . '%)', 1, 1, 'C', false);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 7, 'Estudiantes en Riesgo (75-85%):', 1, 0, 'L', false);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 7, $reporte['estudiantes_en_riesgo'] . ' (' . number_format($reporte['porcentaje_riesgo'], 1) . '%)', 1, 1, 'C', false);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 7, 'Estudiantes Libres (<75%):', 1, 0, 'L', false);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 7, $reporte['estudiantes_libres'] . ' (' . number_format($reporte['porcentaje_libres'], 1) . '%)', 1, 1, 'C', false);
        
        $this->Ln(5);
    }
    
    // Tabla de estudiantes mejorada
    function TablaEstudiantes($estadisticasPorEstudiante) {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Detalle de Asistencia por Estudiante', 0, 1, 'L');
        
        // Ordenar por apellido
        usort($estadisticasPorEstudiante, function($a, $b) {
            return strcmp($a['apellido'], $b['apellido']);
        });
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(45, 7, 'Estudiante', 1, 0, 'C', true);
        $this->Cell(20, 7, 'DNI', 1, 0, 'C', true);
        $this->Cell(18, 7, 'Presentes', 1, 0, 'C', true);
        $this->Cell(18, 7, 'Ausentes', 1, 0, 'C', true);
        $this->Cell(18, 7, '1/2 Faltas', 1, 0, 'C', true);
        $this->Cell(18, 7, '1/4 Faltas', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Justificadas', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Faltas Tot.', 1, 0, 'C', true);
        $this->Cell(20, 7, '% Asist.', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Estado', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 8);
        foreach ($estadisticasPorEstudiante as $estudiante) {
            // Determinar color según estado de regularidad
            switch ($estudiante['estado_regularidad']) {
                case 'regular':
                    $this->SetFillColor(198, 239, 206); // Verde claro
                    $estado = 'Regular';
                    break;
                case 'riesgo':
                    $this->SetFillColor(255, 235, 156); // Amarillo claro
                    $estado = 'En Riesgo';
                    break;
                case 'libre':
                    $this->SetFillColor(255, 199, 206); // Rojo claro
                    $estado = 'Libre';
                    break;
                default:
                    $this->SetFillColor(255, 255, 255); // Blanco
                    $estado = 'No Determinado';
            }
            
            $colorear = $estudiante['estado_regularidad'] != 'regular';
            
            $nombreCompleto = $estudiante['apellido'] . ', ' . $estudiante['nombre'];
            if (strlen($nombreCompleto) > 25) {
                $nombreCompleto = substr($nombreCompleto, 0, 22) . '...';
            }
            
            $this->Cell(45, 7, $nombreCompleto, 1, 0, 'L', $colorear);
            $this->Cell(20, 7, $estudiante['dni'], 1, 0, 'C', $colorear);
            $this->Cell(18, 7, $estudiante['presentes'], 1, 0, 'C', $colorear);
            $this->Cell(18, 7, $estudiante['ausentes'], 1, 0, 'C', $colorear);
            $this->Cell(18, 7, $estudiante['medias_faltas'], 1, 0, 'C', $colorear);
            $this->Cell(18, 7, $estudiante['cuartos_faltas'], 1, 0, 'C', $colorear);
            $this->Cell(20, 7, $estudiante['justificadas'], 1, 0, 'C', $colorear);
            $this->Cell(20, 7, number_format($estudiante['faltas_totales'], 2), 1, 0, 'C', $colorear);
            $this->Cell(20, 7, number_format($estudiante['porcentaje_asistencia'], 1) . '%', 1, 0, 'C', $colorear);
            $this->Cell(20, 7, $estado, 1, 1, 'C', $colorear);
        }
    }
    
    // Estudiantes con problemas de asistencia
    function EstudiantesProblemas($estadisticasPorEstudiante) {
        // Filtrar estudiantes con problemas
        $estudiantesProblemas = array_filter($estadisticasPorEstudiante, function($est) {
            return $est['porcentaje_asistencia'] < 85;
        });
        
        // Si no hay estudiantes con problemas, no mostrar esta sección
        if (empty($estudiantesProblemas)) {
            return;
        }
        
        $this->AddPage();
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Estudiantes con Problemas de Asistencia', 0, 1, 'L');
        
        // Ordenar por porcentaje de asistencia (ascendente)
        usort($estudiantesProblemas, function($a, $b) {
            return $a['porcentaje_asistencia'] <=> $b['porcentaje_asistencia'];
        });
        
        // Cabecera de la tabla
        $this->SetFillColor(255, 193, 7); // Amarillo warning
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 7, 'Estudiante', 1, 0, 'C', true);
        $this->Cell(25, 7, 'DNI', 1, 0, 'C', true);
        $this->Cell(25, 7, '% Asistencia', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Faltas Totales', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Estado', 1, 0, 'C', true);
        $this->Cell(60, 7, 'Recomendación', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($estudiantesProblemas as $estudiante) {
            // Determinar recomendación según porcentaje de asistencia
            if ($estudiante['porcentaje_asistencia'] < 75) {
                $recomendacion = 'Notificar a responsables. El estudiante está en condición LIBRE.';
                $this->SetFillColor(255, 199, 206); // Rojo claro
            } else {
                $recomendacion = 'Alertar sobre riesgo de perder regularidad.';
                $this->SetFillColor(255, 235, 156); // Amarillo claro
            }
            
            $nombreCompleto = $estudiante['apellido'] . ', ' . $estudiante['nombre'];
            if (strlen($nombreCompleto) > 35) {
                $nombreCompleto = substr($nombreCompleto, 0, 32) . '...';
            }
            
            $this->Cell(60, 7, $nombreCompleto, 1, 0, 'L', true);
            $this->Cell(25, 7, $estudiante['dni'], 1, 0, 'C', true);
            $this->Cell(25, 7, number_format($estudiante['porcentaje_asistencia'], 1) . '%', 1, 0, 'C', true);
            $this->Cell(25, 7, number_format($estudiante['faltas_totales'], 2), 1, 0, 'C', true);
            $this->Cell(30, 7, $estudiante['estado_regularidad'] == 'libre' ? 'Libre' : 'En Riesgo', 1, 0, 'C', true);
            $this->Cell(60, 7, $recomendacion, 1, 1, 'L', true);
        }
    }
    
    // Análisis de motivos de justificación
    function AnalisisMotivos($motivosJustificaciones) {
        if (empty($motivosJustificaciones)) {
            return; // No mostrar si no hay motivos
        }
        
        $this->AddPage();
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Análisis de Motivos de Ausencias Justificadas', 0, 1, 'L');
        
        // Ordenar motivos por cantidad (descendente)
        arsort($motivosJustificaciones);
        
        // Cabecera de la tabla
        $this->SetFillColor(191, 223, 245); // Azul claro
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(120, 7, 'Motivo', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Cantidad', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Porcentaje', 1, 1, 'C', true);
        
        // Calcular total
        $totalMotivos = array_sum($motivosJustificaciones);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($motivosJustificaciones as $motivo => $cantidad) {
            $porcentaje = $totalMotivos > 0 ? ($cantidad / $totalMotivos) * 100 : 0;
            
            $this->Cell(120, 7, $motivo, 1, 0, 'L');
            $this->Cell(30, 7, $cantidad, 1, 0, 'C');
            $this->Cell(30, 7, number_format($porcentaje, 1) . '%', 1, 1, 'C');
        }
        
        // Total
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(120, 7, 'TOTAL', 1, 0, 'L', true);
        $this->Cell(30, 7, $totalMotivos, 1, 0, 'C', true);
        $this->Cell(30, 7, '100%', 1, 1, 'C', true);
        
        // Análisis de motivos más frecuentes
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, 'Motivos más frecuentes:', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $contador = 1;
        foreach (array_slice($motivosJustificaciones, 0, 3, true) as $motivo => $cantidad) {
            $porcentaje = $totalMotivos > 0 ? ($cantidad / $totalMotivos) * 100 : 0;
            $this->Cell(0, 7, $contador . '. ' . $motivo . ' (' . $cantidad . ' casos - ' . number_format($porcentaje, 1) . '%)', 0, 1, 'L');
            $contador++;
        }
        
        // Recomendaciones según motivos
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, 'Recomendaciones según motivos identificados:', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $motivosPrincipales = array_keys(array_slice($motivosJustificaciones, 0, 2, true));
        
        foreach ($motivosPrincipales as $motivo) {
            $recomendacion = $this->obtenerRecomendacionPorMotivo($motivo);
            if ($recomendacion) {
                $this->MultiCell(0, 7, '• ' . $recomendacion, 0, 'L');
            }
        }
    }
    
    // Obtener recomendación según motivo
    function obtenerRecomendacionPorMotivo($motivo) {
        if (strpos($motivo, 'Certificado médico') !== false || strpos($motivo, 'Consulta médica') !== false) {
            return 'Se observa alta frecuencia de ausencias por motivos médicos. Considerar implementar programas de promoción de la salud.';
        } elseif (strpos($motivo, 'Problema de transporte') !== false) {
            return 'Evaluar alternativas de transporte para estudiantes con dificultades de movilidad.';
        } elseif (strpos($motivo, 'Trámite familiar') !== false) {
            return 'Considerar flexibilidad en horarios para trámites familiares o sugerir realizarlos en horarios no escolares.';
        } elseif (strpos($motivo, 'Emergencia familiar') !== false) {
            return 'Mantener comunicación con las familias para brindar apoyo en situaciones de emergencia.';
        }
        
        return null;
    }
    
    // Estadísticas por día de la semana mejoradas
    function EstadisticasPorDiaSemana($estadisticasPorDiaSemana) {
        $this->AddPage();
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Estadísticas por Día de la Semana', 0, 1, 'L');
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 7, 'Día', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Presentes', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Ausentes', 1, 0, 'C', true);
        $this->Cell(20, 7, '1/2 Faltas', 1, 0, 'C', true);
        $this->Cell(20, 7, '1/4 Faltas', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Justificadas', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Total', 1, 0, 'C', true);
        $this->Cell(25, 7, '% Presentes', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 9);
        $diasSemana = [
            1 => 'Domingo',
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado'
        ];
        
        // Mostrar solo días de lunes a viernes
        for ($i = 2; $i <= 6; $i++) {
            if (isset($estadisticasPorDiaSemana[$i])) {
                $dia = $estadisticasPorDiaSemana[$i];
                
                $porcentajePresentes = $dia['total'] > 0 ? ($dia['presentes'] / $dia['total']) * 100 : 0;
                
                // Colorear según porcentaje de asistencia
                if ($porcentajePresentes < 80) {
                    $this->SetFillColor(255, 199, 206); // Rojo claro
                } elseif ($porcentajePresentes < 90) {
                    $this->SetFillColor(255, 235, 156); // Amarillo claro
                } else {
                    $this->SetFillColor(198, 239, 206); // Verde claro
                }
                
                $this->Cell(25, 7, $diasSemana[$i], 1, 0, 'L', true);
                $this->Cell(20, 7, $dia['presentes'], 1, 0, 'C', true);
                $this->Cell(20, 7, $dia['ausentes'], 1, 0, 'C', true);
                $this->Cell(20, 7, $dia['medias_faltas'], 1, 0, 'C', true);
                $this->Cell(20, 7, $dia['cuartos_faltas'], 1, 0, 'C', true);
                $this->Cell(25, 7, $dia['justificadas'], 1, 0, 'C', true);
                $this->Cell(20, 7, $dia['total'], 1, 0, 'C', true);
                $this->Cell(25, 7, number_format($porcentajePresentes, 1) . '%', 1, 1, 'C', true);
            } else {
                $this->Cell(25, 7, $diasSemana[$i], 1, 0, 'L');
                $this->Cell(20, 7, '0', 1, 0, 'C');
                $this->Cell(20, 7, '0', 1, 0, 'C');
                $this->Cell(20, 7, '0', 1, 0, 'C');
                $this->Cell(20, 7, '0', 1, 0, 'C');
                $this->Cell(25, 7, '0', 1, 0, 'C');
                $this->Cell(20, 7, '0', 1, 0, 'C');
                $this->Cell(25, 7, '0%', 1, 1, 'C');
            }
        }
        
        $this->Ln(10);
        
        // Agregar información sobre los días con mayor y menor asistencia
        $diasOrdenados = [];
        for ($i = 2; $i <= 6; $i++) {
            if (isset($estadisticasPorDiaSemana[$i]) && $estadisticasPorDiaSemana[$i]['total'] > 0) {
                $porcentajePresentes = ($estadisticasPorDiaSemana[$i]['presentes'] / $estadisticasPorDiaSemana[$i]['total']) * 100;
                $diasOrdenados[$i] = [
                    'dia' => $diasSemana[$i],
                    'porcentaje' => $porcentajePresentes
                ];
            }
        }
        
        // Ordenar por porcentaje de presentes (descendente)
        uasort($diasOrdenados, function($a, $b) {
            return $b['porcentaje'] <=> $a['porcentaje'];
        });
        
        if (!empty($diasOrdenados)) {
            $diasKeys = array_keys($diasOrdenados);
            $mejorDia = $diasOrdenados[$diasKeys[0]];
            $peorDia = $diasOrdenados[$diasKeys[count($diasKeys) - 1]];
            
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 7, 'Análisis de Asistencia por Día:', 0, 1);
            
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 7, '• Día con mayor asistencia: ' . $mejorDia['dia'] . ' (' . number_format($mejorDia['porcentaje'], 1) . '%)', 0, 1);
            $this->Cell(0, 7, '• Día con menor asistencia: ' . $peorDia['dia'] . ' (' . number_format($peorDia['porcentaje'], 1) . '%)', 0, 1);
            
            // Recomendación
            if ($peorDia['porcentaje'] < 80) {
                $this->SetFont('Arial', 'B', 10);
                $this->Cell(0, 7, 'Recomendación:', 0, 1);
                $this->SetFont('Arial', '', 10);
                $this->MultiCell(0, 7, 'Se recomienda verificar las causas de la baja asistencia los días ' . $peorDia['dia'] . ' y tomar medidas para mejorar la presencia de los estudiantes en ese día.');
            }
        }
    }
    
    // Observaciones finales mejoradas
    function ObservacionesFinales($reporte) {
        $this->AddPage();
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Observaciones y Recomendaciones', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 11);
        
        // Observaciones generales
        $this->MultiCell(0, 7, 'El presente reporte analiza la asistencia del curso ' . $reporte['curso']['nombre'] . 
                              ' durante el período comprendido entre el ' . date('d/m/Y', strtotime($this->fechaInicio)) . 
                              ' y el ' . date('d/m/Y', strtotime($this->fechaFin)) . '.');
        
        $this->Ln(5);
        
        // Situación de regularidad
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, 'Situación de Regularidad:', 0, 1);
        $this->SetFont('Arial', '', 11);
        
        if ($reporte['estudiantes_libres'] > 0) {
            $this->MultiCell(0, 7, 'Se detectaron ' . $reporte['estudiantes_libres'] . ' estudiantes en condición libre por inasistencias (menos del 75% de asistencia). Es necesario notificar a los responsables sobre esta situación y tomar medidas para regularizar la asistencia de estos estudiantes.');
        } else {
            $this->MultiCell(0, 7, 'Todos los estudiantes mantienen una condición de regularidad aceptable en cuanto a la asistencia.');
        }
        
        if ($reporte['estudiantes_en_riesgo'] > 0) {
            $this->MultiCell(0, 7, 'Hay ' . $reporte['estudiantes_en_riesgo'] . ' estudiantes en riesgo de perder la regularidad (entre 75% y 85% de asistencia). Se recomienda hacer un seguimiento de estos casos para evitar que pasen a condición libre.');
        }
        
        $this->Ln(5);
        
        // Recomendaciones según el porcentaje general de asistencia
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, 'Recomendaciones Generales:', 0, 1);
        $this->SetFont('Arial', '', 11);
        
        $porcentajeGeneralAsistencia = 0;
        $totalPresentes = $reporte['estadisticas_generales']['presentes'];
        $totalRegistros = $reporte['estadisticas_generales']['total'];
        
        if ($totalRegistros > 0) {
            $porcentajeGeneralAsistencia = ($totalPresentes / $totalRegistros) * 100;
        }
        
        if ($porcentajeGeneralAsistencia < 80) {
            $this->MultiCell(0, 7, 'El curso presenta un porcentaje general de asistencia bajo (' . number_format($porcentajeGeneralAsistencia, 1) . '%). Se recomienda:');
            $this->Ln(2);
            $this->MultiCell(0, 7, '• Realizar reuniones con los padres/responsables para concientizar sobre la importancia de la asistencia regular.');
            $this->MultiCell(0, 7, '• Implementar estrategias de motivación para mejorar la asistencia.');
            $this->MultiCell(0, 7, '• Identificar y abordar las causas comunes de ausentismo.');
        } elseif ($porcentajeGeneralAsistencia < 90) {
            $this->MultiCell(0, 7, 'El curso presenta un porcentaje general de asistencia aceptable pero mejorable (' . number_format($porcentajeGeneralAsistencia, 1) . '%). Se recomienda:');
            $this->Ln(2);
            $this->MultiCell(0, 7, '• Hacer un seguimiento personalizado de los estudiantes con mayor cantidad de inasistencias.');
            $this->MultiCell(0, 7, '• Mantener comunicación constante con las familias.');
        } else {
            $this->MultiCell(0, 7, 'El curso presenta un excelente porcentaje general de asistencia (' . number_format($porcentajeGeneralAsistencia, 1) . '%). Se recomienda:');
            $this->Ln(2);
            $this->MultiCell(0, 7, '• Continuar con las estrategias actuales de promoción de la asistencia.');
            $this->MultiCell(0, 7, '• Reconocer y reforzar positivamente esta conducta en los estudiantes.');
        }
        
        // Análisis específico de 1/4 faltas
        $totalCuartosFaltas = $reporte['estadisticas_generales']['cuartos_faltas'];
        if ($totalCuartosFaltas > 0) {
            $this->Ln(5);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(0, 7, 'Análisis de 1/4 Faltas:', 0, 1);
            $this->SetFont('Arial', '', 11);
            $this->MultiCell(0, 7, 'Se registraron ' . $totalCuartosFaltas . ' casos de 1/4 falta (llegadas tardías o retiros tempranos). Se sugiere:');
            $this->MultiCell(0, 7, '• Reforzar la puntualidad mediante charlas educativas.');
            $this->MultiCell(0, 7, '• Identificar causas de tardanzas recurrentes (transporte, horarios familiares).');
            $this->MultiCell(0, 7, '• Implementar estrategias de motivación para la asistencia completa.');
        }
        
        $this->Ln(10);
        
        // Firma del responsable
        $this->Cell(80, 7, '', 0, 0);
        $this->Cell(120, 7, '_____________________________', 0, 1, 'C');
        $this->Cell(80, 7, '', 0, 0);
        $this->Cell(120, 7, 'Firma y sello del responsable', 0, 1, 'C');
    }
}

// Crear PDF 
$titulo = "Reporte de Asistencia - " . $curso['nombre'];
$pdf = new ReporteAsistenciaPDF($titulo, $curso, $fechaInicio, $fechaFin, $motivosJustificados);
$pdf->AliasNbPages();
$pdf->AddPage();

// Generar contenido del reporte
$pdf->ResumenGeneral($reporte);
$pdf->TablaEstudiantes($reporte['estadisticas_por_estudiante']);
$pdf->EstudiantesProblemas($reporte['estadisticas_por_estudiante']);
$pdf->AnalisisMotivos($reporte['motivos_justificaciones']);
$pdf->EstadisticasPorDiaSemana($reporte['estadisticas_por_dia_semana']);
$pdf->ObservacionesFinales($reporte);

// Limpiar el buffer de salida para asegurar que no haya nada antes del PDF
ob_clean();

// Generar nombre del archivo
$nombreArchivo = 'Reporte_Asistencia_' . str_replace(' ', '_', $curso['nombre']) . '_' . 
                date('Y-m-d', strtotime($fechaInicio)) . '_' . date('Y-m-d', strtotime($fechaFin)) . '.pdf';

// Salida del PDF
$pdf->Output('D', $nombreArchivo);
exit;