<?php
/**
 * generar_reporte_asistencia.php - Generación de reportes de asistencia en PDF (VERSIÓN ACTUALIZADA)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORAS IMPLEMENTADAS:
 * - Soporte para estado "cuarto_falta" (1/4 de falta)
 * - Mostrar motivos de ausencia justificada
 * - Mejor visualización de estados
 * - Integración con motivos predefinidos
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
if (!isset($_GET['estudiante']) || !isset($_GET['curso']) || !isset($_GET['mes']) || !isset($_GET['anio'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el reporte';
    $_SESSION['message_type'] = 'danger';
    header('Location: asistencias.php');
    exit;
}

// Obtener parámetros
$estudianteId = intval($_GET['estudiante']);
$cursoId = intval($_GET['curso']);
$mes = intval($_GET['mes']);
$anio = intval($_GET['anio']);

// Definir motivos de ausencia justificada predefinidos (mismo array que en asistencias.php)
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

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: asistencias.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: asistencias.php');
    exit;
}

// Obtener datos del estudiante
try {
    $datosEstudiante = $db->fetchOne(
        "SELECT u.id, u.nombre, u.apellido, u.dni, u.direccion, u.telefono,
                c.nombre as curso_nombre, c.anio as curso_anio
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         JOIN cursos c ON m.curso_id = c.id
         WHERE u.id = ? AND m.curso_id = ?",
        [$estudianteId, $cursoId]
    );
    
    if (!$datosEstudiante) {
        $_SESSION['message'] = 'No se encontraron datos del estudiante.';
        $_SESSION['message_type'] = 'danger';
        header('Location: asistencias.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos del estudiante: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: asistencias.php');
    exit;
}

// Obtener asistencias para el mes y año seleccionados
try {
    $primerDiaMes = sprintf('%04d-%02d-01', $anio, $mes);
    $ultimoDiaMes = date('Y-m-t', strtotime($primerDiaMes));
    
    $asistencias = $db->fetchAll(
        "SELECT a.*, DATE_FORMAT(a.fecha, '%d/%m/%Y') as fecha_formateada, 
                DAYOFWEEK(a.fecha) as dia_semana
         FROM asistencias a 
         WHERE a.estudiante_id = ? AND a.curso_id = ? 
         AND a.fecha BETWEEN ? AND ? 
         ORDER BY a.fecha",
        [$estudianteId, $cursoId, $primerDiaMes, $ultimoDiaMes]
    );
    
    if (empty($asistencias)) {
        $_SESSION['message'] = 'No hay registros de asistencia para el período seleccionado.';
        $_SESSION['message_type'] = 'warning';
        header('Location: asistencias.php?curso=' . $cursoId . '&fecha=' . date('Y-m-d') . '&historial=1&estudiante_historial=' . $estudianteId . '&mes=' . $mes . '&anio=' . $anio);
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos de asistencia: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: asistencias.php');
    exit;
}

// Incluir la biblioteca FPDF
require('lib/fpdf_utf8.php');

// Crear una clase personalizada para el PDF
class AsistenciaPDF extends FPDF_UTF8 {
    // Variables para almacenar datos
    protected $titulo;
    protected $estudiante;
    protected $curso;
    protected $mes;
    protected $anio;
    protected $motivosJustificados;
    
    // Constructor
    function __construct($titulo, $estudiante, $curso, $mes, $anio, $motivosJustificados, $orientation = 'P', $unit = 'mm', $size = 'A4') {
        parent::__construct($orientation, $unit, $size);
        $this->titulo = $titulo;
        $this->estudiante = $estudiante;
        $this->curso = $curso;
        $this->mes = $mes;
        $this->anio = $anio;
        $this->motivosJustificados = $motivosJustificados;
    }
    
    // Cabecera de página
    function Header() {
        // Logo
        $this->Image('assets/img/logo.png', 10, 10, 20);
        
        // Título
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, 'Escuela Técnica Henry Ford', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, $this->titulo, 0, 1, 'C');
        
        // Datos del estudiante
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Estudiante: ' . $this->estudiante['apellido'] . ', ' . $this->estudiante['nombre'], 0, 1, 'R');
        $this->Cell(0, 5, 'DNI: ' . $this->estudiante['dni'], 0, 1, 'R');
        $this->Cell(0, 5, 'Curso: ' . $this->curso, 0, 1, 'R');
        
        // Período
        $this->Cell(0, 5, 'Período: ' . $this->getNombreMes($this->mes) . ' ' . $this->anio, 0, 1, 'R');
        
        // Línea
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }
    
    // Pie de página
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Página ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
    
    // Obtener nombre del mes
    function getNombreMes($mes) {
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        return $meses[$mes] ?? '';
    }
    
    // Generar calendario de asistencia
    function GenerarCalendario($asistencias) {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Calendario de Asistencia', 0, 1, 'L');
        
        // Crear matriz para almacenar asistencias por día
        $diasDelMes = cal_days_in_month(CAL_GREGORIAN, $this->mes, $this->anio);
        $primerDia = date('w', strtotime($this->anio . '-' . $this->mes . '-01'));
        if ($primerDia == 0) $primerDia = 7; // Convertir domingo de 0 a 7
        
        // Preparar datos del calendario
        $datosCalendario = [];
        for ($i = 1; $i <= $diasDelMes; $i++) {
            $datosCalendario[$i] = ['asistencia' => '', 'color' => ''];
        }
        
        // Llenar datos de asistencia
        foreach ($asistencias as $asistencia) {
            $dia = intval(date('j', strtotime($asistencia['fecha'])));
            
            switch ($asistencia['estado']) {
                case 'presente':
                    $datosCalendario[$dia]['asistencia'] = 'P';
                    $datosCalendario[$dia]['color'] = [198, 239, 206]; // Verde claro
                    break;
                case 'ausente':
                    $datosCalendario[$dia]['asistencia'] = 'A';
                    $datosCalendario[$dia]['color'] = [255, 199, 206]; // Rojo claro
                    break;
                case 'media_falta':
                    $datosCalendario[$dia]['asistencia'] = '½';
                    $datosCalendario[$dia]['color'] = [255, 235, 156]; // Amarillo claro
                    break;
                case 'cuarto_falta':
                    $datosCalendario[$dia]['asistencia'] = '¼';
                    $datosCalendario[$dia]['color'] = [226, 227, 229]; // Gris claro
                    break;
                case 'justificada':
                    $datosCalendario[$dia]['asistencia'] = 'J';
                    $datosCalendario[$dia]['color'] = [191, 223, 245]; // Azul claro
                    break;
            }
        }
        
        // Dibujar cabecera del calendario
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(25, 7, 'Lunes', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Martes', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Miércoles', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Jueves', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Viernes', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Sábado', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Domingo', 1, 1, 'C', true);
        
        // Dibujar días del mes
        $this->SetFont('Arial', '', 9);
        
        $diaActual = 1;
        $filaActual = 0;
        
        while ($diaActual <= $diasDelMes) {
            // Nueva fila si es lunes o primera fila
            if (($diaActual == 1 && $primerDia > 1) || ($diaActual > 1 && date('w', strtotime($this->anio . '-' . $this->mes . '-' . $diaActual)) == 1)) {
                if ($diaActual > 1) {
                    $this->Ln();
                }
                $filaActual++;
                
                // Si es la primera fila, agregar celdas vacías para los días anteriores al primer día del mes
                if ($filaActual == 1 && $primerDia > 1) {
                    for ($i = 1; $i < $primerDia; $i++) {
                        $this->Cell(25, 20, '', 1, 0, 'C');
                    }
                }
            }
            
            // Dibujar celda para el día actual
            $diaSemana = date('w', strtotime($this->anio . '-' . $this->mes . '-' . $diaActual));
            if ($diaSemana == 0) $diaSemana = 7; // Convertir domingo de 0 a 7
            
            // Solo dibujar si es un día válido del mes
            if ($diaActual <= $diasDelMes) {
                // Establecer color de fondo según asistencia
                if (!empty($datosCalendario[$diaActual]['color'])) {
                    $color = $datosCalendario[$diaActual]['color'];
                    $this->SetFillColor($color[0], $color[1], $color[2]);
                    $fill = true;
                } else {
                    $this->SetFillColor(255, 255, 255);
                    $fill = false;
                }
                
                // Dibujar número de día y asistencia
                $this->Cell(25, 10, $diaActual, 'LTR', 0, 'L', $fill);
                $this->SetXY($this->GetX() - 25, $this->GetY() + 10);
                $this->Cell(25, 10, $datosCalendario[$diaActual]['asistencia'], 'LBR', 0, 'C', $fill);
                $this->SetXY($this->GetX(), $this->GetY() - 10);
                
                $diaActual++;
            } else {
                // Celda vacía para días después del último día del mes
                $this->Cell(25, 20, '', 1, 0, 'C');
            }
            
            // Salto de línea si es domingo
            if ($diaSemana == 7 && $diaActual <= $diasDelMes) {
                $this->Ln(20);
            }
        }
        
        // Completar última fila con celdas vacías
        $ultimoDiaSemana = date('w', strtotime($this->anio . '-' . $this->mes . '-' . ($diaActual - 1)));
        if ($ultimoDiaSemana != 0) { // Si no termina en domingo
            for ($i = $ultimoDiaSemana + 1; $i <= 7; $i++) {
                $this->Cell(25, 20, '', 1, 0, 'C');
            }
        }
        
        $this->Ln(25);
    }
    
    // Tabla de asistencias con motivos
    function TablaAsistencias($asistencias) {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Detalle de Asistencias', 0, 1, 'L');
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(25, 7, 'Fecha', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Día', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Estado', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Cuatr.', 1, 0, 'C', true);
        $this->Cell(45, 7, 'Motivo/Observaciones', 1, 0, 'C', true);
        $this->Cell(45, 7, 'Observaciones Adicionales', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        $diasSemana = ['', 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        
        foreach ($asistencias as $asistencia) {
            $diaSemana = $diasSemana[$asistencia['dia_semana']];
            
            // Establecer color según estado
            switch ($asistencia['estado']) {
                case 'presente':
                    $this->SetFillColor(198, 239, 206); // Verde claro
                    $estadoTexto = 'Presente';
                    break;
                case 'ausente':
                    $this->SetFillColor(255, 199, 206); // Rojo claro
                    $estadoTexto = 'Ausente';
                    break;
                case 'media_falta':
                    $this->SetFillColor(255, 235, 156); // Amarillo claro
                    $estadoTexto = '1/2 Falta';
                    break;
                case 'cuarto_falta':
                    $this->SetFillColor(226, 227, 229); // Gris claro
                    $estadoTexto = '1/4 Falta';
                    break;
                case 'justificada':
                    $this->SetFillColor(191, 223, 245); // Azul claro
                    $estadoTexto = 'Justificada';
                    break;
                default:
                    $this->SetFillColor(255, 255, 255); // Blanco
                    $estadoTexto = 'No registrado';
            }
            
            // Preparar motivo/observaciones
            $motivoTexto = '';
            if ($asistencia['estado'] === 'justificada' && !empty($asistencia['motivo_falta'])) {
                $motivoTexto = obtenerTextoMotivoJustificado(
                    $asistencia['motivo_falta'], 
                    $asistencia['motivo_otro'] ?? '', 
                    $this->motivosJustificados
                );
                if (strlen($motivoTexto) > 25) {
                    $motivoTexto = substr($motivoTexto, 0, 22) . '...';
                }
            }
            
            // Observaciones adicionales
            $observaciones = $asistencia['observaciones'] ?? '';
            if (strlen($observaciones) > 25) {
                $observaciones = substr($observaciones, 0, 22) . '...';
            }
            
            $this->Cell(25, 7, date('d/m/Y', strtotime($asistencia['fecha'])), 1, 0, 'C', true);
            $this->Cell(25, 7, $diaSemana, 1, 0, 'C', true);
            $this->Cell(30, 7, $estadoTexto, 1, 0, 'C', true);
            $this->Cell(20, 7, $asistencia['cuatrimestre'] . '°', 1, 0, 'C', true);
            $this->Cell(45, 7, $motivoTexto, 1, 0, 'L', true);
            $this->Cell(45, 7, $observaciones, 1, 1, 'L', true);
        }
    }
    
    // Resumen estadístico actualizado
    function ResumenEstadistico($asistencias) {
        $this->AddPage();
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Resumen Estadístico de Asistencia', 0, 1, 'L');
        
        // Calcular estadísticas
        $total = count($asistencias);
        $presentes = 0;
        $ausentes = 0;
        $mediasFaltas = 0;
        $cuartosFaltas = 0;
        $justificadas = 0;
        
        foreach ($asistencias as $asistencia) {
            switch ($asistencia['estado']) {
                case 'presente':
                    $presentes++;
                    break;
                case 'ausente':
                    $ausentes++;
                    break;
                case 'media_falta':
                    $mediasFaltas++;
                    break;
                case 'cuarto_falta':
                    $cuartosFaltas++;
                    break;
                case 'justificada':
                    $justificadas++;
                    break;
            }
        }
        
        // Calcular porcentajes
        $porcentajePresentes = $total > 0 ? ($presentes / $total) * 100 : 0;
        $porcentajeAusentes = $total > 0 ? ($ausentes / $total) * 100 : 0;
        $porcentajeMediasFaltas = $total > 0 ? ($mediasFaltas / $total) * 100 : 0;
        $porcentajeCuartosFaltas = $total > 0 ? ($cuartosFaltas / $total) * 100 : 0;
        $porcentajeJustificadas = $total > 0 ? ($justificadas / $total) * 100 : 0;
        
        // Tabla de estadísticas
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(40, 7, 'Estado', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Cantidad', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Porcentaje', 1, 0, 'C', true);
        $this->Cell(90, 7, 'Gráfico', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 10);
        
        // Presentes
        $this->SetFillColor(198, 239, 206);
        $this->Cell(40, 7, 'Presentes', 1, 0, 'L', true);
        $this->Cell(30, 7, $presentes, 1, 0, 'C', true);
        $this->Cell(30, 7, number_format($porcentajePresentes, 2) . '%', 1, 0, 'C', true);
        $this->Cell(90, 7, $this->GenerarBarraGrafico($porcentajePresentes, [198, 239, 206]), 1, 1, 'L', false);
        
        // Ausentes
        $this->SetFillColor(255, 199, 206);
        $this->Cell(40, 7, 'Ausentes', 1, 0, 'L', true);
        $this->Cell(30, 7, $ausentes, 1, 0, 'C', true);
        $this->Cell(30, 7, number_format($porcentajeAusentes, 2) . '%', 1, 0, 'C', true);
        $this->Cell(90, 7, $this->GenerarBarraGrafico($porcentajeAusentes, [255, 199, 206]), 1, 1, 'L', false);
        
        // 1/2 Faltas
        $this->SetFillColor(255, 235, 156);
        $this->Cell(40, 7, '1/2 Faltas', 1, 0, 'L', true);
        $this->Cell(30, 7, $mediasFaltas, 1, 0, 'C', true);
        $this->Cell(30, 7, number_format($porcentajeMediasFaltas, 2) . '%', 1, 0, 'C', true);
        $this->Cell(90, 7, $this->GenerarBarraGrafico($porcentajeMediasFaltas, [255, 235, 156]), 1, 1, 'L', false);
        
        // 1/4 Faltas
        $this->SetFillColor(226, 227, 229);
        $this->Cell(40, 7, '1/4 Faltas', 1, 0, 'L', true);
        $this->Cell(30, 7, $cuartosFaltas, 1, 0, 'C', true);
        $this->Cell(30, 7, number_format($porcentajeCuartosFaltas, 2) . '%', 1, 0, 'C', true);
        $this->Cell(90, 7, $this->GenerarBarraGrafico($porcentajeCuartosFaltas, [226, 227, 229]), 1, 1, 'L', false);
        
        // Justificadas
        $this->SetFillColor(191, 223, 245);
        $this->Cell(40, 7, 'Justificadas', 1, 0, 'L', true);
        $this->Cell(30, 7, $justificadas, 1, 0, 'C', true);
        $this->Cell(30, 7, number_format($porcentajeJustificadas, 2) . '%', 1, 0, 'C', true);
        $this->Cell(90, 7, $this->GenerarBarraGrafico($porcentajeJustificadas, [191, 223, 245]), 1, 1, 'L', false);
        
        // Total
        $this->SetFillColor(230, 230, 230);
        $this->Cell(40, 7, 'Total', 1, 0, 'L', true);
        $this->Cell(30, 7, $total, 1, 0, 'C', true);
        $this->Cell(30, 7, '100%', 1, 0, 'C', true);
        $this->Cell(90, 7, '', 1, 1, 'L', false);
        
        // Cálculo de inasistencias totales (incluyendo 1/4 faltas)
        $this->Ln(10);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, 'Cómputo de Inasistencias', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $inasistenciasTotales = $ausentes + ($mediasFaltas * 0.5) + ($cuartosFaltas * 0.25);
        $this->Cell(120, 7, 'Inasistencias Totales (Ausentes + 1/2×0.5 + 1/4×0.25):', 0, 0, 'L');
        $this->Cell(30, 7, number_format($inasistenciasTotales, 2), 0, 1, 'L');
        
        $diasHabiles = $this->ContarDiasHabiles($this->mes, $this->anio);
        $this->Cell(120, 7, 'Días Hábiles en el Mes:', 0, 0, 'L');
        $this->Cell(30, 7, $diasHabiles, 0, 1, 'L');
        
        $porcentajeAsistencia = $diasHabiles > 0 ? (($diasHabiles - $inasistenciasTotales) / $diasHabiles) * 100 : 0;
        $porcentajeInasistencia = $diasHabiles > 0 ? ($inasistenciasTotales / $diasHabiles) * 100 : 0;
        
        $this->Cell(120, 7, 'Porcentaje de Asistencia del Mes:', 0, 0, 'L');
        $this->Cell(30, 7, number_format($porcentajeAsistencia, 2) . '%', 0, 1, 'L');
        
        $this->Cell(120, 7, 'Porcentaje de Inasistencia del Mes:', 0, 0, 'L');
        $this->Cell(30, 7, number_format($porcentajeInasistencia, 2) . '%', 0, 1, 'L');
        
        // Advertencia si porcentaje de asistencia es bajo
        if ($porcentajeAsistencia < 75) {
            $this->Ln(5);
            $this->SetFillColor(255, 199, 206);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 10, 'ADVERTENCIA: El estudiante está por debajo del mínimo de asistencia requerido (75%)', 1, 1, 'C', true);
        } elseif ($porcentajeAsistencia < 85) {
            $this->Ln(5);
            $this->SetFillColor(255, 235, 156);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 10, 'ATENCIÓN: El estudiante está cerca del límite mínimo de asistencia (75%)', 1, 1, 'C', true);
        }
    }
    
    // Resumen de motivos de justificación
    function ResumenMotivos($asistencias) {
        // Filtrar solo las justificadas
        $justificadas = array_filter($asistencias, function($a) {
            return $a['estado'] === 'justificada' && !empty($a['motivo_falta']);
        });
        
        if (empty($justificadas)) {
            return; // No mostrar esta sección si no hay justificadas
        }
        
        $this->Ln(10);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, 'Motivos de Ausencias Justificadas', 0, 1, 'L');
        
        // Contar motivos
        $motivosCuenta = [];
        foreach ($justificadas as $justificada) {
            $motivo = $justificada['motivo_falta'];
            $motivoTexto = obtenerTextoMotivoJustificado(
                $motivo, 
                $justificada['motivo_otro'] ?? '', 
                $this->motivosJustificados
            );
            
            if (!isset($motivosCuenta[$motivoTexto])) {
                $motivosCuenta[$motivoTexto] = 0;
            }
            $motivosCuenta[$motivoTexto]++;
        }
        
        // Mostrar tabla de motivos
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(120, 7, 'Motivo', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Cantidad', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 9);
        foreach ($motivosCuenta as $motivo => $cantidad) {
            $this->Cell(120, 7, $motivo, 1, 0, 'L');
            $this->Cell(30, 7, $cantidad, 1, 1, 'C');
        }
    }
    
    // Generar barra gráfica
    function GenerarBarraGrafico($porcentaje, $colorRGB) {
        $ancho = 80;
        $anchoReal = ($porcentaje / 100) * $ancho;
        
        $x = $this->GetX();
        $y = $this->GetY();
        
        $this->SetFillColor($colorRGB[0], $colorRGB[1], $colorRGB[2]);
        $this->Rect($x + 5, $y + 1, $anchoReal, 5, 'F');
        
        return '';
    }
    
    // Contar días hábiles en un mes (lunes a viernes)
    function ContarDiasHabiles($mes, $anio) {
        $diasDelMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $diasHabiles = 0;
        
        for ($dia = 1; $dia <= $diasDelMes; $dia++) {
            $fecha = mktime(0, 0, 0, $mes, $dia, $anio);
            $diaSemana = date('N', $fecha);
            
            if ($diaSemana <= 5) {
                $diasHabiles++;
            }
        }
        
        return $diasHabiles;
    }
}

// Calcular estadísticas
$totalAsistencias = count($asistencias);
$presentes = 0;
$ausentes = 0;
$mediasFaltas = 0;
$cuartosFaltas = 0;
$justificadas = 0;

foreach ($asistencias as $asistencia) {
    switch ($asistencia['estado']) {
        case 'presente':
            $presentes++;
            break;
        case 'ausente':
            $ausentes++;
            break;
        case 'media_falta':
            $mediasFaltas++;
            break;
        case 'cuarto_falta':
            $cuartosFaltas++;
            break;
        case 'justificada':
            $justificadas++;
            break;
    }
}

// Crear PDF
$titulo = "Reporte de Asistencia - " . date('F Y', strtotime($anio . '-' . $mes . '-01'));
$pdf = new AsistenciaPDF($titulo, $datosEstudiante, $datosEstudiante['curso_nombre'], $mes, $anio, $motivosJustificados);
$pdf->AliasNbPages();
$pdf->AddPage();

// Generar calendario
$pdf->GenerarCalendario($asistencias);

// Tabla de asistencias
$pdf->TablaAsistencias($asistencias);

// Resumen estadístico
$pdf->ResumenEstadistico($asistencias);

// Resumen de motivos
$pdf->ResumenMotivos($asistencias);

// Limpiar el buffer de salida para asegurarnos de que no haya nada antes del PDF
ob_clean();

// Generar nombre del archivo
$nombreArchivo = 'Asistencia_' . $datosEstudiante['apellido'] . '_' . $datosEstudiante['nombre'] . '_' . 
                date('Y-m', strtotime($anio . '-' . $mes . '-01')) . '.pdf';

// Salida del PDF
$pdf->Output('D', $nombreArchivo);
exit;