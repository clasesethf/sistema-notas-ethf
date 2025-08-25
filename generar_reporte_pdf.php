<?php
/**
 * generar_reporte_pdf.php - Generación de reportes en PDF
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
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

// Verificar permisos (solo admin, directivos y profesores)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    $_SESSION['message'] = 'No tiene permisos para exportar reportes';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetros
if (!isset($_GET['tipo_reporte'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el reporte';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Obtener parámetros
$tipoReporte = $_GET['tipo_reporte'];
$cursoId = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$materiaId = isset($_GET['materia']) ? intval($_GET['materia']) : 0;
$cuatrimestre = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 0;

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: reportes.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Variables para almacenar datos del reporte
$reporteData = [];
$titulo = '';
$descripcion = '';

// Generar reporte según el tipo seleccionado
try {
    switch ($tipoReporte) {
        case 'rendimiento_curso':
            generarReporteRendimientoCurso($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'rendimiento_materia':
            generarReporteRendimientoMateria($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'valoraciones_preliminares':
            generarReporteValoracionesPreliminares($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'asistencia':
            generarReporteAsistencia($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'intensificacion':
            generarReporteIntensificacion($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'global':
            generarReporteGlobal($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        default:
            throw new Exception('Tipo de reporte no válido');
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al generar el reporte: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Verificar si hay datos para el reporte
if (empty($reporteData)) {
    $_SESSION['message'] = 'No hay datos para generar el reporte seleccionado';
    $_SESSION['message_type'] = 'warning';
    header('Location: reportes.php');
    exit;
}

/**
 * Función para generar reporte de rendimiento por curso
 */
function generarReporteRendimientoCurso($db, $cursoId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        throw new Exception('Debe seleccionar un curso');
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        throw new Exception('Curso no encontrado');
    }

    $campoCalificacion = 'c.calificacion_final'; // Por defecto, calificación final
    $filtroCuatrimestreDesc = "ambos cuatrimestres (final)";
    if ($cuatrimestre == 1) {
        $campoCalificacion = 'c.calificacion_1c';
        $filtroCuatrimestreDesc = "el 1° cuatrimestre";
    } elseif ($cuatrimestre == 2) {
        $campoCalificacion = 'c.calificacion_2c';
        $filtroCuatrimestreDesc = "el 2° cuatrimestre";
    }
    
    $reporteData = $db->fetchAll(
        "SELECT m.nombre as materia, m.codigo, 
                COUNT(c.id) as total_estudiantes,
                SUM(CASE WHEN $campoCalificacion >= 4 THEN 1 ELSE 0 END) as aprobados,
                SUM(CASE WHEN $campoCalificacion < 4 AND $campoCalificacion IS NOT NULL THEN 1 ELSE 0 END) as desaprobados,
                ROUND(AVG(CASE WHEN $campoCalificacion IS NOT NULL AND $campoCalificacion <> '' THEN $campoCalificacion ELSE NULL END), 2) as promedio
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id AND c.ciclo_lectivo_id = ?
         WHERE mp.curso_id = ?
         GROUP BY m.id, m.nombre, m.codigo
         ORDER BY m.nombre",
        [$cicloLectivoId, $cursoId]
    );
    
    $titulo = "Rendimiento por Materias - " . $cursoInfo['nombre'];
    $descripcion = "Rendimiento de estudiantes en cada materia del curso " . $cursoInfo['nombre'] . 
                   " durante " . $filtroCuatrimestreDesc . " del ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte de rendimiento por materia
 */
function generarReporteRendimientoMateria($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0 || $materiaId == 0) {
        throw new Exception('Debe seleccionar un curso y una materia');
    }
    
    // Obtener información del curso y materia
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    $materiaInfo = $db->fetchOne("SELECT m.nombre, m.codigo, mp.id as materia_curso_id 
                                 FROM materias m 
                                 JOIN materias_por_curso mp ON m.id = mp.materia_id 
                                 WHERE m.id = ? AND mp.curso_id = ? 
                                 LIMIT 1", 
                                [$materiaId, $cursoId]);
    
    if (!$cursoInfo || !$materiaInfo) {
        throw new Exception('Curso o materia no encontrados');
    }
    
    $materiaCursoId = $materiaInfo['materia_curso_id'];
    
    // Construir consulta según el cuatrimestre seleccionado
    $camposSelect = 'c.valoracion_preliminar_1c, c.calificacion_1c, c.valoracion_preliminar_2c, c.calificacion_2c, c.intensificacion_1c, c.calificacion_final, c.tipo_cursada';
    
    $reporteData = $db->fetchAll(
        "SELECT u.nombre, u.apellido, u.dni, $camposSelect
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         LEFT JOIN calificaciones c ON u.id = c.estudiante_id AND c.materia_curso_id = ? AND c.ciclo_lectivo_id = ?
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         ORDER BY u.apellido, u.nombre",
        [$materiaCursoId, $cicloLectivoId, $cursoId]
    );
    
    $titulo = "Rendimiento en " . $materiaInfo['nombre'] . " (" . $materiaInfo['codigo'] . ")";
    $descripcion = "Calificaciones de estudiantes en la materia " . $materiaInfo['nombre'] . 
                   " del curso " . $cursoInfo['nombre'] . " - Ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte de valoraciones preliminares
 */
function generarReporteValoracionesPreliminares($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        throw new Exception('Debe seleccionar un curso');
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        throw new Exception('Curso no encontrado');
    }

    // Determinar qué campo de valoración usar según el cuatrimestre
    $campoValoracion = 'c.valoracion_preliminar_1c'; // Por defecto, 1er cuatrimestre
    $filtroCuatrimestreDesc = "el 1° cuatrimestre";
    
    if ($cuatrimestre == 2) {
        $campoValoracion = 'c.valoracion_preliminar_2c';
        $filtroCuatrimestreDesc = "el 2° cuatrimestre";
    }
    
    // Construir la consulta base
    $sqlBase = "SELECT m.nombre as materia, m.codigo,
                       COUNT(c.id) as total_estudiantes,
                       SUM(CASE WHEN $campoValoracion = 'TEA' THEN 1 ELSE 0 END) as tea,
                       SUM(CASE WHEN $campoValoracion = 'TEP' THEN 1 ELSE 0 END) as tep,
                       SUM(CASE WHEN $campoValoracion = 'TED' THEN 1 ELSE 0 END) as ted,
                       SUM(CASE WHEN $campoValoracion IS NULL OR $campoValoracion = '' THEN 1 ELSE 0 END) as sin_valoracion
                FROM materias_por_curso mp
                JOIN materias m ON mp.materia_id = m.id
                LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id AND c.ciclo_lectivo_id = ?
                WHERE mp.curso_id = ?";
    
    $parametros = [$cicloLectivoId, $cursoId];
    
    // Filtrar por materia si se especificó
    if ($materiaId > 0) {
        $sqlBase .= " AND m.id = ?";
        $parametros[] = $materiaId;
        
        // Obtener información de la materia
        $materiaInfo = $db->fetchOne("SELECT nombre, codigo FROM materias WHERE id = ?", [$materiaId]);
        if ($materiaInfo) {
            $titulo = "Valoraciones Preliminares - " . $materiaInfo['nombre'] . " (" . $materiaInfo['codigo'] . ")";
            $descripcion = "Distribución de valoraciones preliminares en " . $materiaInfo['nombre'] . 
                           " del curso " . $cursoInfo['nombre'] . " durante " . $filtroCuatrimestreDesc . 
                           " - Ciclo lectivo " . $anioActivo . ".";
        }
    } else {
        $titulo = "Valoraciones Preliminares - " . $cursoInfo['nombre'];
        $descripcion = "Distribución de valoraciones preliminares por materia del curso " . $cursoInfo['nombre'] . 
                       " durante " . $filtroCuatrimestreDesc . " - Ciclo lectivo " . $anioActivo . ".";
    }
    
    $sqlBase .= " GROUP BY m.id, m.nombre, m.codigo ORDER BY m.nombre";
    
    $reporteData = $db->fetchAll($sqlBase, $parametros);
}

/**
 * Función para generar reporte de asistencia
 */
function generarReporteAsistencia($db, $cursoId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        throw new Exception('Debe seleccionar un curso');
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        throw new Exception('Curso no encontrado');
    }

    // Construir cláusula WHERE para el cuatrimestre
    $whereClause = "a.curso_id = ?";
    $parametros = [$cursoId];
    
    if ($cuatrimestre > 0) {
        $whereClause .= " AND a.cuatrimestre = ?";
        $parametros[] = $cuatrimestre;
        $filtroCuatrimestreDesc = $cuatrimestre == 1 ? "el 1° cuatrimestre" : "el 2° cuatrimestre";
    } else {
        $filtroCuatrimestreDesc = "todo el ciclo lectivo";
    }
    
    // Consulta para obtener asistencias por estudiante
    $reporteData = $db->fetchAll(
        "SELECT u.nombre, u.apellido, u.dni,
                COUNT(*) as dias_totales,
                SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
                SUM(CASE WHEN a.estado = 'media_falta' THEN 1 ELSE 0 END) as medias_faltas_cantidad,
                SUM(CASE WHEN a.estado = 'media_falta' THEN 0.5 ELSE 0 END) as medias_faltas,
                SUM(CASE WHEN a.estado = 'justificada' THEN 1 ELSE 0 END) as justificadas
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         LEFT JOIN asistencias a ON u.id = a.estudiante_id AND $whereClause
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         GROUP BY u.id, u.nombre, u.apellido, u.dni
         ORDER BY u.apellido, u.nombre",
        array_merge($parametros, [$cursoId])
    );
    
    // Calcular porcentajes y totales
    foreach ($reporteData as &$estudiante) {
        if ($estudiante['dias_totales'] > 0) {
            $totalFaltas = $estudiante['ausentes'] + $estudiante['medias_faltas'];
            $estudiante['total_faltas_computables'] = $totalFaltas;
            $estudiante['porcentaje_asistencia'] = round(($estudiante['presentes'] / $estudiante['dias_totales']) * 100, 2);
        } else {
            $estudiante['total_faltas_computables'] = 0;
            $estudiante['porcentaje_asistencia'] = 0;
        }
    }
    
    $titulo = "Reporte de Asistencia - " . $cursoInfo['nombre'];
    $descripcion = "Estadísticas de asistencia del curso " . $cursoInfo['nombre'] . 
                   " durante " . $filtroCuatrimestreDesc . " - Ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte de intensificación
 */
function generarReporteIntensificacion($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    // Consulta para obtener estadísticas de intensificación
    $reporteData = $db->fetchAll(
        "SELECT c.nombre as curso_nombre, m.nombre as materia, m.codigo,
                COUNT(i.id) as total_estudiantes_intensificacion,
                SUM(CASE WHEN i.calificacion_final >= 4 THEN 1 ELSE 0 END) as aprobados_intensificacion,
                SUM(CASE WHEN i.calificacion_final < 4 OR i.calificacion_final IS NULL THEN 1 ELSE 0 END) as pendientes_intensificacion
         FROM intensificaciones i
         JOIN materias m ON i.materia_id = m.id
         JOIN cursos c ON i.ciclo_lectivo_id = c.ciclo_lectivo_id
         WHERE i.ciclo_lectivo_id = ?
         GROUP BY c.id, c.nombre, m.id, m.nombre, m.codigo
         ORDER BY c.nombre, m.nombre",
        [$cicloLectivoId]
    );
    
    $titulo = "Reporte de Intensificación - Ciclo Lectivo " . $anioActivo;
    $descripcion = "Estadísticas de estudiantes en intensificación por materia y curso - Ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte global
 */
function generarReporteGlobal($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    // Consulta para obtener estadísticas globales por curso
    $reporteData = $db->fetchAll(
        "SELECT c.nombre as curso, c.anio,
                (SELECT COUNT(*) FROM matriculas m WHERE m.curso_id = c.id AND m.estado = 'activo') as matriculados,
                (SELECT COUNT(DISTINCT cal.estudiante_id) 
                 FROM calificaciones cal 
                 JOIN materias_por_curso mp ON cal.materia_curso_id = mp.id 
                 WHERE mp.curso_id = c.id AND cal.ciclo_lectivo_id = ? AND cal.calificacion_final >= 4) as aprobados_total_estudiantes,
                (SELECT COUNT(DISTINCT cal.estudiante_id) 
                 FROM calificaciones cal 
                 JOIN materias_por_curso mp ON cal.materia_curso_id = mp.id 
                 WHERE mp.curso_id = c.id AND cal.ciclo_lectivo_id = ? AND cal.calificacion_final < 4 AND cal.calificacion_final IS NOT NULL) as desaprobados_total_estudiantes,
                (SELECT COUNT(DISTINCT i.estudiante_id) 
                 FROM intensificaciones i 
                 JOIN usuarios u ON i.estudiante_id = u.id
                 JOIN matriculas m ON u.id = m.estudiante_id
                 WHERE m.curso_id = c.id AND i.ciclo_lectivo_id = ?) as en_intensificacion_estudiantes
         FROM cursos c
         WHERE c.ciclo_lectivo_id = ?
         ORDER BY c.anio, c.nombre",
        [$cicloLectivoId, $cicloLectivoId, $cicloLectivoId, $cicloLectivoId]
    );
    
    $titulo = "Reporte Global - Ciclo Lectivo " . $anioActivo;
    $descripcion = "Estadísticas generales de rendimiento académico por curso - Ciclo lectivo " . $anioActivo . ".";
}

// Incluir la biblioteca FPDF para la generación del PDF
require_once('lib/fpdf_utf8.php');

// Crear clase personalizada para el PDF
class ReportePDF extends FPDF_UTF8 {
    // Variables para almacenar datos del reporte
    protected $titulo;
    protected $descripcion;
    
    // Constructor
    function __construct($titulo, $descripcion, $orientation = 'P', $unit = 'mm', $size = 'A4') {
        parent::__construct($orientation, $unit, $size);
        $this->titulo = $titulo;
        $this->descripcion = $descripcion;
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
        
        // Descripción
        $this->SetFont('Arial', 'I', 10);
        $this->Cell(0, 8, $this->descripcion, 0, 1, 'C');
        
        // Fecha
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 8, 'Fecha: ' . date('d/m/Y'), 0, 1, 'R');
        
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
    
    // Tabla de rendimiento por curso
    function TablaRendimientoCurso($data) {
        $this->SetFont('Arial', 'B', 10);
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->Cell(60, 7, 'Materia', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Estudiantes', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Aprobados', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Desaprobados', 1, 0, 'C', true);
        $this->Cell(25, 7, '% Aprobación', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Promedio', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($data as $row) {
            $porcentajeAprobacion = $row['total_estudiantes'] > 0 ? 
                round(($row['aprobados'] / $row['total_estudiantes']) * 100, 2) : 0;
            $promedio = number_format($row['promedio'], 2);
            
            $this->Cell(60, 7, $row['materia'] . ' (' . $row['codigo'] . ')', 1, 0, 'L');
            $this->Cell(25, 7, $row['total_estudiantes'], 1, 0, 'C');
            $this->Cell(25, 7, $row['aprobados'], 1, 0, 'C');
            $this->Cell(25, 7, $row['desaprobados'], 1, 0, 'C');
            $this->Cell(25, 7, $porcentajeAprobacion . '%', 1, 0, 'C');
            $this->Cell(25, 7, $promedio, 1, 1, 'C');
        }
    }
    
    // Tabla de rendimiento por materia
    function TablaRendimientoMateria($data) {
        $this->SetFont('Arial', 'B', 10);
        
        // Reducir tamaño de fuente si hay muchos estudiantes
        if (count($data) > 30) {
            $this->SetFont('Arial', 'B', 8);
        }
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->Cell(50, 7, 'Estudiante', 1, 0, 'C', true);
        $this->Cell(18, 7, 'DNI', 1, 0, 'C', true);
        $this->Cell(12, 7, 'Tipo', 1, 0, 'C', true);
        $this->Cell(18, 7, '1° Val.', 1, 0, 'C', true);
        $this->Cell(18, 7, '1° Cuat.', 1, 0, 'C', true);
        $this->Cell(18, 7, '2° Val.', 1, 0, 'C', true);
        $this->Cell(18, 7, '2° Cuat.', 1, 0, 'C', true);
        $this->Cell(18, 7, 'Intens.', 1, 0, 'C', true);
        $this->Cell(18, 7, 'Final', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', count($data) > 30 ? 8 : 10);
        
        foreach ($data as $row) {
            $this->Cell(50, 7, $row['apellido'] . ', ' . $row['nombre'], 1, 0, 'L');
            $this->Cell(18, 7, $row['dni'], 1, 0, 'C');
            $this->Cell(12, 7, $row['tipo_cursada'] ?? 'C', 1, 0, 'C');
            $this->Cell(18, 7, $row['valoracion_preliminar_1c'] ?? '-', 1, 0, 'C');
            $this->Cell(18, 7, $row['calificacion_1c'] ?? '-', 1, 0, 'C');
            $this->Cell(18, 7, $row['valoracion_preliminar_2c'] ?? '-', 1, 0, 'C');
            $this->Cell(18, 7, $row['calificacion_2c'] ?? '-', 1, 0, 'C');
            $this->Cell(18, 7, $row['intensificacion_1c'] ?? '-', 1, 0, 'C');
            $this->Cell(18, 7, $row['calificacion_final'] ?? '-', 1, 1, 'C');
        }
    }
    
    // Tabla de valoraciones preliminares
    function TablaValoracionesPreliminares($data) {
        $this->SetFont('Arial', 'B', 10);
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->Cell(60, 7, 'Materia', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Estudiantes', 1, 0, 'C', true);
        $this->Cell(20, 7, 'TEA', 1, 0, 'C', true);
        $this->Cell(20, 7, 'TEP', 1, 0, 'C', true);
        $this->Cell(20, 7, 'TED', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Sin Val.', 1, 0, 'C', true);
        $this->Cell(30, 7, '% TEA', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($data as $row) {
            $porcentajeTEA = $row['total_estudiantes'] > 0 ? 
                round(($row['tea'] / $row['total_estudiantes']) * 100, 2) : 0;
            
            $this->Cell(60, 7, $row['materia'] . ' (' . $row['codigo'] . ')', 1, 0, 'L');
            $this->Cell(20, 7, $row['total_estudiantes'], 1, 0, 'C');
            $this->Cell(20, 7, $row['tea'], 1, 0, 'C');
            $this->Cell(20, 7, $row['tep'], 1, 0, 'C');
            $this->Cell(20, 7, $row['ted'], 1, 0, 'C');
            $this->Cell(20, 7, $row['sin_valoracion'], 1, 0, 'C');
            $this->Cell(30, 7, $porcentajeTEA . '%', 1, 1, 'C');
        }
    }
    
    // Tabla de asistencia
    function TablaAsistencia($data) {
        $this->SetFont('Arial', 'B', 10);
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->Cell(50, 7, 'Estudiante', 1, 0, 'C', true);
        $this->Cell(25, 7, 'DNI', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Días Totales', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Presentes', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Ausentes', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Justificadas', 1, 0, 'C', true);
        $this->Cell(25, 7, '% Asistencia', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($data as $row) {
            // Determinar color según porcentaje de asistencia
            if ($row['porcentaje_asistencia'] >= 85) {
                $this->SetFillColor(198, 239, 206); // Verde claro
            } elseif ($row['porcentaje_asistencia'] >= 75) {
                $this->SetFillColor(255, 235, 156); // Amarillo claro
            } else {
                $this->SetFillColor(255, 199, 206); // Rojo claro
            }
            
            $this->Cell(50, 7, $row['apellido'] . ', ' . $row['nombre'], 1, 0, 'L', true);
            $this->Cell(25, 7, $row['dni'], 1, 0, 'C', true);
            $this->Cell(20, 7, $row['dias_totales'], 1, 0, 'C', true);
            $this->Cell(20, 7, $row['presentes'], 1, 0, 'C', true);
            $this->Cell(20, 7, $row['ausentes'] . '+' . $row['medias_faltas'], 1, 0, 'C', true);
            $this->Cell(20, 7, $row['justificadas'], 1, 0, 'C', true);
            $this->Cell(25, 7, $row['porcentaje_asistencia'] . '%', 1, 1, 'C', true);
        }
    }
    
    // Tabla de intensificación
    function TablaIntensificacion($data) {
        $this->SetFont('Arial', 'B', 10);
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->Cell(50, 7, 'Materia', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Curso', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Total Estudiantes', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Aprobados', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Pendientes', 1, 0, 'C', true);
        $this->Cell(20, 7, '% Aprob.', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($data as $row) {
            $porcentajeAprobacion = $row['total_estudiantes_intensificacion'] > 0 ? 
                round(($row['aprobados_intensificacion'] / $row['total_estudiantes_intensificacion']) * 100, 2) : 0;
            
            $this->Cell(50, 7, $row['materia'] . ' (' . $row['codigo'] . ')', 1, 0, 'L');
            $this->Cell(30, 7, $row['curso_nombre'], 1, 0, 'C');
            $this->Cell(30, 7, $row['total_estudiantes_intensificacion'], 1, 0, 'C');
            $this->Cell(30, 7, $row['aprobados_intensificacion'], 1, 0, 'C');
            $this->Cell(30, 7, $row['pendientes_intensificacion'], 1, 0, 'C');
            $this->Cell(20, 7, $porcentajeAprobacion . '%', 1, 1, 'C');
        }
    }
    
    // Tabla de reporte global
    function TablaGlobal($data) {
        $this->SetFont('Arial', 'B', 10);
        
        // Cabecera de la tabla
        $this->SetFillColor(230, 230, 230);
        $this->Cell(40, 7, 'Curso', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Año', 1, 0, 'C', true);
        $this->Cell(25, 7, 'Matriculados', 1, 0, 'C', true);
        $this->Cell(35, 7, 'Aprobados', 1, 0, 'C', true);
        $this->Cell(35, 7, 'Desaprobados', 1, 0, 'C', true);
        $this->Cell(35, 7, 'En Intensificación', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 10);
        foreach ($data as $row) {
            $this->Cell(40, 7, $row['curso'], 1, 0, 'L');
            $this->Cell(20, 7, $row['anio'] . '°', 1, 0, 'C');
            $this->Cell(25, 7, $row['matriculados'], 1, 0, 'C');
            $this->Cell(35, 7, $row['aprobados_total_estudiantes'], 1, 0, 'C');
            $this->Cell(35, 7, $row['desaprobados_total_estudiantes'], 1, 0, 'C');
            $this->Cell(35, 7, $row['en_intensificacion_estudiantes'], 1, 1, 'C');
        }
    }
    
    // Resumen estadístico
    function ResumenEstadistico($data, $tipoReporte) {
        $this->AddPage();
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'Resumen Estadístico', 0, 1, 'C');
        $this->Ln(5);
        
        switch ($tipoReporte) {
            case 'rendimiento_curso':
                $this->ResumenRendimientoCurso($data);
                break;
            
            case 'valoraciones_preliminares':
                $this->ResumenValoracionesPreliminares($data);
                break;
                
            case 'asistencia':
                $this->ResumenAsistencia($data);
                break;
                
            case 'intensificacion':
                $this->ResumenIntensificacion($data);
                break;
                
            case 'global':
                $this->ResumenGlobal($data);
                break;
        }
    }
    
    // Resumen de rendimiento por curso
    function ResumenRendimientoCurso($data) {
        // Calcular totales
        $totalEstudiantes = 0;
        $totalAprobados = 0;
        $totalDesaprobados = 0;
        $sumaPromedios = 0;
        
        foreach ($data as $row) {
            $totalEstudiantes += $row['total_estudiantes'];
            $totalAprobados += $row['aprobados'];
            $totalDesaprobados += $row['desaprobados'];
            if (is_numeric($row['promedio'])) {
                $sumaPromedios += $row['promedio'];
            }
        }
        
        $promedioGeneral = count($data) > 0 ? $sumaPromedios / count($data) : 0;
        $porcentajeAprobacionGeneral = $totalEstudiantes > 0 ? ($totalAprobados / $totalEstudiantes) * 100 : 0;
        
        // Mostrar estadísticas generales
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Estadísticas Generales', 0, 1);
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 6, '• Total de estudiantes evaluados: ' . $totalEstudiantes, 0, 1);
        $this->Cell(0, 6, '• Total de aprobados: ' . $totalAprobados . ' (' . round($porcentajeAprobacionGeneral, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Total de desaprobados: ' . $totalDesaprobados . ' (' . round(100 - $porcentajeAprobacionGeneral, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Promedio general: ' . number_format($promedioGeneral, 2), 0, 1);
        
        $this->Ln(10);
        
        // Tabla de materias con mejor rendimiento
        if (count($data) > 0) {
            // Ordenar por porcentaje de aprobación
            $dataOrdenada = $data;
            usort($dataOrdenada, function($a, $b) {
                $porcA = $a['total_estudiantes'] > 0 ? ($a['aprobados'] / $a['total_estudiantes']) : 0;
                $porcB = $b['total_estudiantes'] > 0 ? ($b['aprobados'] / $b['total_estudiantes']) : 0;
                return $porcB <=> $porcA;
            });
            
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 8, 'Materias con Mayor Porcentaje de Aprobación', 0, 1);
            
            $this->SetFillColor(230, 230, 230);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(60, 7, 'Materia', 1, 0, 'C', true);
            $this->Cell(30, 7, 'Aprobados', 1, 0, 'C', true);
            $this->Cell(30, 7, 'Total', 1, 0, 'C', true);
            $this->Cell(30, 7, '% Aprobación', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 10);
            $count = 0;
            foreach ($dataOrdenada as $row) {
                if ($count >= 5) break; // Mostrar solo las 5 mejores
                
                $porcentajeAprobacion = $row['total_estudiantes'] > 0 ? 
                    round(($row['aprobados'] / $row['total_estudiantes']) * 100, 2) : 0;
                
                $this->Cell(60, 7, $row['materia'] . ' (' . $row['codigo'] . ')', 1, 0, 'L');
                $this->Cell(30, 7, $row['aprobados'], 1, 0, 'C');
                $this->Cell(30, 7, $row['total_estudiantes'], 1, 0, 'C');
                $this->Cell(30, 7, $porcentajeAprobacion . '%', 1, 1, 'C');
                
                $count++;
            }
        }
    }
    
    // Resumen de valoraciones preliminares
    function ResumenValoracionesPreliminares($data) {
        // Calcular totales
        $totalEstudiantes = 0;
        $totalTEA = 0;
        $totalTEP = 0;
        $totalTED = 0;
        $totalSinValoracion = 0;
        
        foreach ($data as $row) {
            $totalEstudiantes += $row['total_estudiantes'];
            $totalTEA += $row['tea'];
            $totalTEP += $row['tep'];
            $totalTED += $row['ted'];
            $totalSinValoracion += $row['sin_valoracion'];
        }
        
        $porcentajeTEA = $totalEstudiantes > 0 ? ($totalTEA / $totalEstudiantes) * 100 : 0;
        $porcentajeTEP = $totalEstudiantes > 0 ? ($totalTEP / $totalEstudiantes) * 100 : 0;
        $porcentajeTED = $totalEstudiantes > 0 ? ($totalTED / $totalEstudiantes) * 100 : 0;
        $porcentajeSinValoracion = $totalEstudiantes > 0 ? ($totalSinValoracion / $totalEstudiantes) * 100 : 0;
        
        // Mostrar estadísticas generales
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Distribución General de Valoraciones', 0, 1);
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 6, '• Total de estudiantes: ' . $totalEstudiantes, 0, 1);
        $this->Cell(0, 6, '• Trayectoria Educativa Avanzada (TEA): ' . $totalTEA . ' (' . round($porcentajeTEA, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Trayectoria Educativa en Proceso (TEP): ' . $totalTEP . ' (' . round($porcentajeTEP, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Trayectoria Educativa Discontinua (TED): ' . $totalTED . ' (' . round($porcentajeTED, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Sin valoración asignada: ' . $totalSinValoracion . ' (' . round($porcentajeSinValoracion, 2) . '%)', 0, 1);
        
        $this->Ln(10);
        
        // Leyenda de valoraciones
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Referencia de Valoraciones', 0, 1);
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 6, 'TEA (Trayectoria Educativa Avanzada): Estudiantes que han alcanzado los aprendizajes correspondientes y sostuvieron una buena vinculación pedagógica.', 0, 'L');
        $this->MultiCell(0, 6, 'TEP (Trayectoria Educativa en Proceso): Estudiantes que no han alcanzado de forma suficiente los aprendizajes correspondientes, pero que mantienen una buena vinculación pedagógica.', 0, 'L');
        $this->MultiCell(0, 6, 'TED (Trayectoria Educativa Discontinua): Estudiantes que no han alcanzado los aprendizajes correspondientes y que tuvieron una escasa vinculación pedagógica.', 0, 'L');
    }
    
    // Resumen de asistencia
    function ResumenAsistencia($data) {
        // Calcular totales
        $totalEstudiantes = count($data);
        $totalPresentes = 0;
        $totalAusentes = 0;
        $totalMediasFaltas = 0;
        $totalJustificadas = 0;
        $totalDiasTotales = 0;
        
        $regulares = 0;
        $enRiesgo = 0;
        $libres = 0;
        
        foreach ($data as $row) {
            $totalPresentes += $row['presentes'];
            $totalAusentes += $row['ausentes'];
            $totalMediasFaltas += $row['medias_faltas'];
            $totalJustificadas += $row['justificadas'];
            $totalDiasTotales += $row['dias_totales'];
            
            if ($row['porcentaje_asistencia'] >= 85) {
                $regulares++;
            } elseif ($row['porcentaje_asistencia'] >= 75) {
                $enRiesgo++;
            } else {
                $libres++;
            }
        }
        
        $porcentajeAsistenciaGeneral = $totalDiasTotales > 0 ? ($totalPresentes / $totalDiasTotales) * 100 : 0;
        
        // Mostrar estadísticas generales
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Estadísticas Generales de Asistencia', 0, 1);
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 6, '• Total de estudiantes: ' . $totalEstudiantes, 0, 1);
        $this->Cell(0, 6, '• Estudiantes en condición regular (>=85%): ' . $regulares . ' (' . round(($regulares / $totalEstudiantes) * 100, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Estudiantes en riesgo (75-85%): ' . $enRiesgo . ' (' . round(($enRiesgo / $totalEstudiantes) * 100, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Estudiantes en condición libre (<75%): ' . $libres . ' (' . round(($libres / $totalEstudiantes) * 100, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Porcentaje de asistencia general: ' . round($porcentajeAsistenciaGeneral, 2) . '%', 0, 1);
        
        $this->Ln(10);
        
        // Tabla de estudiantes con menor asistencia
        if (count($data) > 0) {
            // Ordenar por porcentaje de asistencia (ascendente)
            $dataOrdenada = $data;
            usort($dataOrdenada, function($a, $b) {
                return $a['porcentaje_asistencia'] <=> $b['porcentaje_asistencia'];
            });
            
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 8, 'Estudiantes con Menor Porcentaje de Asistencia', 0, 1);
            
            $this->SetFillColor(230, 230, 230);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(60, 7, 'Estudiante', 1, 0, 'C', true);
            $this->Cell(30, 7, 'DNI', 1, 0, 'C', true);
            $this->Cell(30, 7, 'Presentes', 1, 0, 'C', true);
            $this->Cell(30, 7, 'Ausentes', 1, 0, 'C', true);
            $this->Cell(30, 7, '% Asistencia', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 10);
            $count = 0;
            foreach ($dataOrdenada as $row) {
                if ($count >= 5) break; // Mostrar solo los 5 con menor asistencia
                
                $this->Cell(60, 7, $row['apellido'] . ', ' . $row['nombre'], 1, 0, 'L');
                $this->Cell(30, 7, $row['dni'], 1, 0, 'C');
                $this->Cell(30, 7, $row['presentes'], 1, 0, 'C');
                $this->Cell(30, 7, $row['ausentes'] . '+' . $row['medias_faltas'], 1, 0, 'C');
                $this->Cell(30, 7, $row['porcentaje_asistencia'] . '%', 1, 1, 'C');
                
                $count++;
            }
        }
    }
    
    // Resumen de intensificación
    function ResumenIntensificacion($data) {
        // Calcular totales
        $totalEstudiantes = 0;
        $totalAprobados = 0;
        $totalPendientes = 0;
        
        foreach ($data as $row) {
            $totalEstudiantes += $row['total_estudiantes_intensificacion'];
            $totalAprobados += $row['aprobados_intensificacion'];
            $totalPendientes += $row['pendientes_intensificacion'];
        }
        
        $porcentajeAprobacion = $totalEstudiantes > 0 ? ($totalAprobados / $totalEstudiantes) * 100 : 0;
        
        // Mostrar estadísticas generales
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Estadísticas Generales de Intensificación', 0, 1);
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 6, '• Total de estudiantes en intensificación: ' . $totalEstudiantes, 0, 1);
        $this->Cell(0, 6, '• Estudiantes que aprobaron: ' . $totalAprobados . ' (' . round($porcentajeAprobacion, 2) . '%)', 0, 1);
        $this->Cell(0, 6, '• Estudiantes con materias pendientes: ' . $totalPendientes . ' (' . round(100 - $porcentajeAprobacion, 2) . '%)', 0, 1);
        
        $this->Ln(10);
        
        // Información sobre períodos de intensificación
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Períodos de Intensificación', 0, 1);
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, '• Marzo: Primer período de intensificación', 0, 1);
        $this->Cell(0, 6, '• Julio: Intensificación de mitad de año', 0, 1);
        $this->Cell(0, 6, '• Agosto: Inicio de segundo cuatrimestre', 0, 1);
        $this->Cell(0, 6, '• Diciembre: Intensificación de fin de año', 0, 1);
        $this->Cell(0, 6, '• Febrero: Intensificación previa al inicio del siguiente ciclo lectivo', 0, 1);
    }
    
    // Resumen global
    function ResumenGlobal($data) {
        // Calcular totales
        $totalMatriculados = 0;
        $totalAprobados = 0;
        $totalDesaprobados = 0;
        $totalIntensificacion = 0;
        
        foreach ($data as $row) {
            $totalMatriculados += $row['matriculados'];
            $totalAprobados += $row['aprobados_total_estudiantes'];
            $totalDesaprobados += $row['desaprobados_total_estudiantes'];
            $totalIntensificacion += $row['en_intensificacion_estudiantes'];
        }
        
        // Calcular porcentajes
        $porcentajeAprobacion = $totalAprobados + $totalDesaprobados > 0 ? 
            ($totalAprobados / ($totalAprobados + $totalDesaprobados)) * 100 : 0;
        
        // Mostrar estadísticas generales
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Estadísticas Globales del Ciclo Lectivo', 0, 1);
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 6, '• Total de estudiantes matriculados: ' . $totalMatriculados, 0, 1);
        $this->Cell(0, 6, '• Estudiantes con todas las materias aprobadas: ' . $totalAprobados, 0, 1);
        $this->Cell(0, 6, '• Estudiantes con materias desaprobadas: ' . $totalDesaprobados, 0, 1);
        $this->Cell(0, 6, '• Estudiantes en intensificación: ' . $totalIntensificacion, 0, 1);
        $this->Cell(0, 6, '• Porcentaje global de aprobación: ' . round($porcentajeAprobacion, 2) . '%', 0, 1);
        
        $this->Ln(10);
        
        // Cursos con mejor rendimiento
        if (count($data) > 0) {
            // Ordenar por porcentaje de aprobación
            $dataOrdenada = $data;
            usort($dataOrdenada, function($a, $b) {
                $porcA = ($a['aprobados_total_estudiantes'] + $a['desaprobados_total_estudiantes']) > 0 ? 
                    ($a['aprobados_total_estudiantes'] / ($a['aprobados_total_estudiantes'] + $a['desaprobados_total_estudiantes'])) : 0;
                $porcB = ($b['aprobados_total_estudiantes'] + $b['desaprobados_total_estudiantes']) > 0 ? 
                    ($b['aprobados_total_estudiantes'] / ($b['aprobados_total_estudiantes'] + $b['desaprobados_total_estudiantes'])) : 0;
                return $porcB <=> $porcA;
            });
            
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 8, 'Cursos con Mayor Porcentaje de Aprobación', 0, 1);
            
            $this->SetFillColor(230, 230, 230);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(40, 7, 'Curso', 1, 0, 'C', true);
            $this->Cell(35, 7, 'Aprobados', 1, 0, 'C', true);
            $this->Cell(35, 7, 'Desaprobados', 1, 0, 'C', true);
            $this->Cell(35, 7, '% Aprobación', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 10);
            $count = 0;
            foreach ($dataOrdenada as $row) {
                if ($count >= 5) break; // Mostrar solo los 5 mejores cursos
                
                $totalEvaluados = $row['aprobados_total_estudiantes'] + $row['desaprobados_total_estudiantes'];
                $porcentajeAprobacion = $totalEvaluados > 0 ? 
                    round(($row['aprobados_total_estudiantes'] / $totalEvaluados) * 100, 2) : 0;
                
                $this->Cell(40, 7, $row['curso'] . ' (' . $row['anio'] . '°)', 1, 0, 'L');
                $this->Cell(35, 7, $row['aprobados_total_estudiantes'], 1, 0, 'C');
                $this->Cell(35, 7, $row['desaprobados_total_estudiantes'], 1, 0, 'C');
                $this->Cell(35, 7, $porcentajeAprobacion . '%', 1, 1, 'C');
                
                $count++;
            }
        }
    }
}

// Crear PDF según el tipo de reporte seleccionado
$orientation = 'P'; // Portrait por defecto

// Ajustar orientación según el tipo de reporte
if (in_array($tipoReporte, ['rendimiento_materia', 'asistencia'])) {
    $orientation = 'L'; // Landscape para reportes con muchas columnas
}

$pdf = new ReportePDF($titulo, $descripcion, $orientation);
$pdf->AliasNbPages();
$pdf->AddPage();

// Generar contenido según el tipo de reporte
switch ($tipoReporte) {
    case 'rendimiento_curso':
        $pdf->TablaRendimientoCurso($reporteData);
        $pdf->ResumenEstadistico($reporteData, $tipoReporte);
        break;
        
    case 'rendimiento_materia':
        $pdf->TablaRendimientoMateria($reporteData);
        break;
        
    case 'valoraciones_preliminares':
        $pdf->TablaValoracionesPreliminares($reporteData);
        $pdf->ResumenEstadistico($reporteData, $tipoReporte);
        break;
        
    case 'asistencia':
        $pdf->TablaAsistencia($reporteData);
        $pdf->ResumenEstadistico($reporteData, $tipoReporte);
        break;
        
    case 'intensificacion':
        $pdf->TablaIntensificacion($reporteData);
        $pdf->ResumenEstadistico($reporteData, $tipoReporte);
        break;
        
    case 'global':
        $pdf->TablaGlobal($reporteData);
        $pdf->ResumenEstadistico($reporteData, $tipoReporte);
        break;
}

// Limpiar buffer de salida para evitar problemas con el PDF
ob_clean();

// Nombre del archivo
$nombreArchivo = 'Reporte_' . str_replace(' ', '_', $titulo) . '_' . date('Y-m-d') . '.pdf';

// Salida del PDF
$pdf->Output($nombreArchivo, 'D'); // 'D' para descargar

exit;
?>