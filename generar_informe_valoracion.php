<?php
/**
 * generar_informe_valoracion.php - Generación de informes de valoración bimestral
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Iniciar buffer de salida al principio del archivo
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar parámetros requeridos
if (!isset($_GET['curso']) || !isset($_GET['estudiante']) || !isset($_GET['bimestre'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el informe';
    $_SESSION['message_type'] = 'danger';
    header('Location: calificaciones.php');
    exit;
}

// Obtener parámetros
$cursoId = intval($_GET['curso']);
$estudianteId = intval($_GET['estudiante']);
$bimestre = intval($_GET['bimestre']);

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: calificaciones.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: calificaciones.php');
    exit;
}

// Obtener información del estudiante y curso
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
        $_SESSION['message'] = 'Estudiante no encontrado en el curso especificado.';
        $_SESSION['message_type'] = 'danger';
        header('Location: calificaciones.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos del estudiante: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: calificaciones.php');
    exit;
}

// Obtener materias y valoraciones del estudiante
try {
    $campo_valoracion = 'valoracion_' . $bimestre . 'bim';
    $campo_desempeno = 'desempeno_' . $bimestre . 'bim';
    $campo_observaciones = 'observaciones_' . $bimestre . 'bim';
    
    $materias = $db->fetchAll(
        "SELECT m.nombre as materia_nombre, m.codigo as materia_codigo,
                c.$campo_valoracion as valoracion,
                c.$campo_desempeno as desempeno,
                c.$campo_observaciones as observaciones
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id 
                                    AND c.estudiante_id = ? 
                                    AND c.ciclo_lectivo_id = ?
         WHERE mp.curso_id = ?
         ORDER BY m.nombre",
        [$estudianteId, $cicloLectivoId, $cursoId]
    );
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener materias: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: calificaciones.php');
    exit;
}

// Obtener asistencias
try {
    $asistencias = $db->fetchOne(
        "SELECT COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas
         FROM asistencias
         WHERE estudiante_id = ? AND curso_id = ?",
        [$estudianteId, $cursoId]
    );
    
    $totalInasistencias = ($asistencias['ausentes'] ?? 0) + (($asistencias['medias_faltas'] ?? 0) * 0.5);
} catch (Exception $e) {
    $totalInasistencias = 0;
}

// Incluir la biblioteca FPDF
require('lib/fpdf_utf8.php');

// Crear una clase personalizada para el informe de valoración
class InformeValoracionPDF extends FPDF_UTF8 {
    private $datosEstudiante;
    private $anioActivo;
    private $bimestre;
    
    function __construct($datosEstudiante, $anioActivo, $bimestre, $orientation = 'P', $unit = 'mm', $size = 'A4') {
        parent::__construct($orientation, $unit, $size);
        $this->datosEstudiante = $datosEstudiante;
        $this->anioActivo = $anioActivo;
        $this->bimestre = $bimestre;
    }
    
    // Cabecera de página
    function Header() {
        // Logo
        $this->Image('assets/img/logo.png', 15, 10, 20, 20);
        
        // Título de la escuela
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'Escuela de Educación Secundaria Técnica "Henry Ford"', 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 8, 'DIEGEP 4931', 0, 1, 'C');
        
        $this->Ln(5);
        
        // Título del informe
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'Informe bimestral de avance', 0, 1, 'C');
        
        $this->Ln(5);
    }
    
    // Pie de página
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'N / C: No corresponde a este período', 0, 0, 'L');
    }
    
    // Información del estudiante
    function InformacionEstudiante($totalInasistencias) {
        // Fila con información básica
        $this->SetFillColor(200, 220, 255);
        $this->SetFont('Arial', 'B', 11);
        
        // Primera fila
        $this->Cell(30, 8, 'AÑO: ' . $this->anioActivo, 1, 0, 'L', true);
        $this->Cell(60, 8, 'PERÍODO: ' . ($this->bimestre == 1 ? '1er Bimestre' : '3er Bimestre'), 1, 0, 'L', true);
        $this->Cell(30, 8, 'CURSO: ' . $this->datosEstudiante['curso_anio'] . '°', 1, 1, 'L', true);
        
        // Segunda fila - datos del estudiante
        $this->SetFillColor(240, 240, 240);
        $this->Cell(15, 8, '', 1, 0, 'C', true); // Número
        $this->Cell(75, 8, 'Apellido y nombre', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Valoración preliminar de la trayectoria', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Desempeño académico del bimestre', 1, 0, 'C', true);
        $this->Cell(20, 8, 'Observaciones', 1, 1, 'C', true);
        
        // Datos del estudiante
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(255, 255, 255);
        $this->Cell(15, 8, '1', 1, 0, 'C', false);
        $this->Cell(75, 8, $this->datosEstudiante['apellido'] . ', ' . $this->datosEstudiante['nombre'], 1, 0, 'L', false);
        $this->Cell(40, 8, '', 1, 0, 'C', false); // Se llenará en la tabla de materias
        $this->Cell(40, 8, '', 1, 0, 'C', false); // Se llenará en la tabla de materias
        $this->Cell(20, 8, '', 1, 1, 'L', false); // Se llenará en la tabla de materias
        
        $this->Ln(5);
    }
    
    // Tabla de materias
    function TablaMaterias($materias) {
        // Encabezado de materias
        $this->SetFillColor(255, 255, 150);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 8, 'Materias y Módulos de Taller', 1, 1, 'L', true);
        
        $this->SetFont('Arial', '', 9);
        $this->SetFillColor(255, 255, 255);
        
        // Agrupar materias por tipo
        $materiasGenerales = [];
        $modulosTaller = [];
        
        foreach ($materias as $materia) {
            $nombre = $materia['materia_nombre'];
            if (strpos($nombre, 'MEA') !== false || strpos($nombre, 'DPM') !== false || strpos($nombre, 'IAE') !== false) {
                $modulosTaller[] = $materia;
            } else {
                $materiasGenerales[] = $materia;
            }
        }
        
        // Materias generales
        foreach ($materiasGenerales as $materia) {
            $this->FilaMateria($materia);
        }
        
        // Módulos de taller agrupados
        if (count($modulosTaller) > 0) {
            $this->Ln(2);
            $this->SetFillColor(200, 255, 200);
            $this->SetFont('Arial', 'B', 9);
            
            // Agrupar por especialidad
            $especialidades = [
                'MEA' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
                'DPM' => 'DISEÑO Y PROCESAMIENTO MECÁNICO',
                'IAE' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
            ];
            
            foreach ($especialidades as $codigo => $nombre) {
                $modulosEsp = array_filter($modulosTaller, function($m) use ($codigo) {
                    return strpos($m['materia_nombre'], $codigo) !== false;
                });
                
                if (count($modulosEsp) > 0) {
                    $this->Cell(0, 6, $nombre, 1, 1, 'L', true);
                    $this->SetFillColor(255, 255, 255);
                    $this->SetFont('Arial', '', 9);
                    
                    foreach ($modulosEsp as $modulo) {
                        $this->FilaMateria($modulo, true);
                    }
                }
            }
        }
        
        $this->Ln(5);
    }
    
    // Fila individual de materia
    function FilaMateria($materia, $esModulo = false) {
        $valoracion = $materia['valoracion'] ?? '';
        $desempeno = $materia['desempeno'] ?? '';
        $observaciones = $materia['observaciones'] ?? '';
        
        // Determinar color de fondo según valoración
        $fillColor = [255, 255, 255]; // Blanco por defecto
        if ($valoracion == 'TEP') {
            $fillColor = [255, 200, 200]; // Rojo claro para TEP
        }
        
        $this->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
        
        $prefijo = $esModulo ? '  ' : '';
        $nombreMateria = $prefijo . $materia['materia_nombre'];
        if (strlen($nombreMateria) > 45) {
            $nombreMateria = substr($nombreMateria, 0, 42) . '...';
        }
        
        $this->Cell(80, 6, $nombreMateria, 1, 0, 'L', true);
        $this->Cell(30, 6, $valoracion ?: 'N / C', 1, 0, 'C', true);
        $this->Cell(30, 6, $desempeno ?: 'N / C', 1, 0, 'C', true);
        
        // Observaciones (puede ocupar múltiples líneas)
        $observacionesTexto = $observaciones ?: '';
        if (strlen($observacionesTexto) > 35) {
            $observacionesTexto = substr($observacionesTexto, 0, 32) . '...';
        }
        $this->Cell(50, 6, $observacionesTexto, 1, 1, 'L', true);
    }
    
    // Sección de materias pendientes
    function MateriasPendientes() {
        $this->SetFillColor(255, 255, 150);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 8, 'Materias pendientes de aprobación y acreditación en proceso de intensificación', 1, 1, 'L', true);
        
        $this->SetFillColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, 'NO ADEUDA', 1, 1, 'C', false);
        
        $this->Ln(5);
    }
    
    // Sección de inasistencias
    function Inasistencias($totalInasistencias) {
        $this->SetFillColor(200, 220, 255);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 8, 'Inasistencias a la fecha', 1, 1, 'L', true);
        
        $this->SetFillColor(255, 255, 255);
        $this->SetFont('Arial', '', 12);
        $inasistenciasTexto = $totalInasistencias > 0 ? $totalInasistencias : '0';
        if ($totalInasistencias != intval($totalInasistencias)) {
            $inasistenciasTexto .= ' 1/4'; // Para representar medias faltas
        }
        $this->Cell(0, 8, $inasistenciasTexto, 1, 1, 'C', false);
    }
}

// Función para limpiar nombre de archivo
function limpiarNombreArchivo($texto) {
    if (function_exists('transliterator_transliterate')) {
        $textoSinAcentos = transliterator_transliterate('Any-Latin; Latin-ASCII', $texto);
        return $textoSinAcentos;
    } else {
        $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ');
        $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N');
        return str_replace($buscar, $reemplazar, $texto);
    }
}

// Crear instancia de PDF
$pdf = new InformeValoracionPDF($datosEstudiante, $anioActivo, $bimestre, 'P', 'mm', 'A4');
$pdf->SetTitle('Informe de Valoración - ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']);
$pdf->SetAuthor('Escuela Técnica Henry Ford');
$pdf->AddPage();

// Generar contenido del informe
$pdf->InformacionEstudiante($totalInasistencias);
$pdf->TablaMaterias($materias);
$pdf->MateriasPendientes();
$pdf->Inasistencias($totalInasistencias);

// Limpiar el buffer de salida
ob_clean();

// Generar nombre del archivo
$nombreArchivo = 'Informe_Valoracion_' . 
                limpiarNombreArchivo($datosEstudiante['apellido']) . '_' . 
                limpiarNombreArchivo($datosEstudiante['nombre']) . '_' .
                $bimestre . 'Bim_' . $anioActivo . '.pdf';

// Si el directorio de PDFs no existe, crearlo
$dirPDF = 'pdfs';
if (!file_exists($dirPDF)) {
    mkdir($dirPDF, 0755, true);  
}

// Guardar el PDF en el servidor
$rutaArchivo = $dirPDF . '/' . $nombreArchivo;
$pdf->Output('F', $rutaArchivo);

// Enviar el PDF al navegador
$pdf->Output('D', $nombreArchivo);

exit;