<?php
/**
 * generar_boletin_pdf_core.php - Lógica core para generación de PDFs
 * Extraído de generar_boletin_pdf.php sin headers ni redirecciones
 */

// NO incluir config.php aquí, debe estar incluido antes

// Obtener parámetros (pueden venir de $_GET o ser establecidos previamente)
$estudianteId = intval($_GET['estudiante'] ?? 0);
$cursoId = intval($_GET['curso'] ?? 0);
$tipoBoletinSeleccionado = $_GET['tipo'] ?? 'cuatrimestre';
$cuatrimestreSeleccionado = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 1;
$bimestreSeleccionado = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 1;

if (!$estudianteId || !$cursoId) {
    throw new Exception('Parámetros incorrectos para generar el boletín');
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
if (!$cicloActivo) {
    throw new Exception('No hay un ciclo lectivo activo configurado en el sistema.');
}

$cicloLectivoId = $cicloActivo['id'];
$anioActivo = $cicloActivo['anio'];

// Obtener datos del estudiante
$datosEstudiante = $db->fetchOne(
    "SELECT u.id, u.nombre, u.apellido, u.dni, 
            c.nombre as curso_nombre, c.anio as curso_anio
     FROM usuarios u 
     JOIN matriculas m ON u.id = m.estudiante_id 
     JOIN cursos c ON m.curso_id = c.id
     WHERE u.id = ? AND m.curso_id = ?",
    [$estudianteId, $cursoId]
);

if (!$datosEstudiante) {
    throw new Exception('No se encontraron datos del estudiante.');
}

// Incluir funciones necesarias (solo si existen)
if (file_exists('includes/funciones_agrupacion_materias.php')) {
    require_once 'includes/funciones_agrupacion_materias.php';
}

if (file_exists('funciones_grupos_pendientes.php')) {
    require_once 'funciones_grupos_pendientes.php';
}

// FUNCIONES AUXILIARES NECESARIAS (copiadas del archivo original)

function obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId) {
    try {
        return $db->fetchAll(
            "SELECT materia_liberada_id 
             FROM materias_recursado 
             WHERE estudiante_id = ? AND ciclo_lectivo_id = ? AND materia_liberada_id IS NOT NULL",
            [$estudianteId, $cicloLectivoId]
        );
    } catch (Exception $e) {
        error_log("Error al obtener materias liberadas: " . $e->getMessage());
        return [];
    }
}

function obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio) {
    // Versión simplificada para casos donde no hay funciones de agrupación
    
    $resultado = [];
    
    // Obtener materias individuales
    $materiasIndividuales = $db->fetchAll(
        "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo, 
                curso_materia.anio as materia_anio, mp.id as materia_curso_id,
                c.valoracion_preliminar_1c, c.valoracion_preliminar_2c,
                c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                c.observaciones, c.tipo_cursada, m.id as materia_id
         FROM calificaciones c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
         WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
         AND (c.tipo_cursada IS NULL OR c.tipo_cursada IN ('C', 'R'))
         ORDER BY m.nombre",
        [$estudianteId, $cicloLectivoId]
    );
    
    foreach ($materiasIndividuales as $materia) {
        $materia['es_grupo'] = false;
        $materia['nombre'] = $materia['materia_nombre'];
        $materia['codigo'] = $materia['materia_codigo'];
        $resultado[] = $materia;
    }
    
    return $resultado;
}

// Obtener materias liberadas
$materiasLiberadas = obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId);
$materiasLiberadasIds = array_column($materiasLiberadas, 'materia_liberada_id');

// Obtener datos según el tipo de boletín
if ($tipoBoletinSeleccionado === 'cuatrimestre') {
    // Obtener calificaciones combinadas
    $calificacionesCombinadas = obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $datosEstudiante['curso_anio']);
    
    // Obtener asistencias por cuatrimestre
    $asistencias = $db->fetchAll(
        "SELECT cuatrimestre, 
                COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas,
                COUNT(CASE WHEN estado = 'justificada' THEN 1 END) as justificadas,
                COUNT(*) as total_dias
         FROM asistencias
         WHERE estudiante_id = ? AND curso_id = ?
         GROUP BY cuatrimestre",
        [$estudianteId, $cursoId]
    );
    
    // Formatear asistencias
    $asistenciasPorCuatrimestre = [];
    foreach ($asistencias as $asistencia) {
        $asistenciasPorCuatrimestre[$asistencia['cuatrimestre']] = $asistencia;
    }
    
    $materiasAgrupadasPendientes = []; // Simplificado para esta versión
} else {
    // Para boletines bimestrales (versión simplificada)
    $valoracionesCombinadas = [];
    $asistenciasBimestre = null;
    $materiasAgrupadasPendientes = [];
}

// Incluir la biblioteca FPDF
require('lib/fpdf_utf8.php');

// FUNCIONES AUXILIARES PARA NOMBRES
function convertirMayusculasConTildes($texto) {
    $mapeo = array(
        'á' => 'Á', 'à' => 'À', 'ä' => 'Ä', 'â' => 'Â',
        'é' => 'É', 'è' => 'È', 'ë' => 'Ë', 'ê' => 'Ê',
        'í' => 'Í', 'ì' => 'Ì', 'ï' => 'Ï', 'î' => 'Î',
        'ó' => 'Ó', 'ò' => 'Ò', 'ö' => 'Ö', 'ô' => 'Ô',
        'ú' => 'Ú', 'ù' => 'Ù', 'ü' => 'Ü', 'û' => 'Û',
        'ñ' => 'Ñ', 'ç' => 'Ç'
    );
    
    $textoMayuscula = strtoupper($texto);
    
    foreach ($mapeo as $minuscula => $mayuscula) {
        $textoMayuscula = str_replace($minuscula, $mayuscula, $textoMayuscula);
    }
    
    return $textoMayuscula;
}

function normalizarNombreMateria($nombreMateria) {
    return convertirMayusculasConTildes(trim($nombreMateria));
}

function tieneAlgunDato($item, $esGrupo = false) {
    if ($esGrupo) {
        return false; // Simplificado
    } else {
        return !empty(trim($item['valoracion_preliminar_1c'] ?? '')) || 
               !empty($item['calificacion_1c']) || 
               !empty(trim($item['valoracion_preliminar_2c'] ?? '')) || 
               !empty($item['calificacion_2c']) || 
               !empty($item['intensificacion_1c']) || 
               !empty($item['intensificacion_diciembre']) || 
               !empty($item['intensificacion_febrero']) || 
               !empty($item['calificacion_final']) ||
               !empty($item['observaciones']);
    }
}

// CLASE PDF SIMPLIFICADA
class BoletinPDF extends FPDF_UTF8 {
    private $tipoBoletín;
    private $bimestre;
    private $cuatrimestre;
    private $materiasLiberadasIds;
    
    public function __construct($tipoBoletín = 'cuatrimestre', $bimestre = 1, $cuatrimestre = 1, $materiasLiberadasIds = []) {
        parent::__construct('L', 'mm', 'A4');
        $this->tipoBoletín = $tipoBoletín;
        $this->bimestre = $bimestre;
        $this->cuatrimestre = $cuatrimestre;
        $this->materiasLiberadasIds = $materiasLiberadasIds;
        $this->SetMargins(10, 5, 10);
        $this->SetAutoPageBreak(true, 5);
    }
    
    function Header() {
        $anchoPagina = $this->GetPageWidth();
        $logoAncho = 16;
        $logoAlto = 16;
        
        // Logo (si existe)
        if (file_exists('assets/img/logo.png')) {
            $this->Image('assets/img/logo.png', 10, 8, $logoAncho, $logoAlto);
        }
        
        $inicioTextoX = 5 + $logoAncho + 2;
        $anchoTextoDisponible = $anchoPagina - $inicioTextoX - 5;
        
        $this->SetXY($inicioTextoX, 5);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($anchoTextoDisponible, 7, 'ESCUELA TÉCNICA HENRY FORD', 0, 1, 'C');
        
        $this->SetX($inicioTextoX);
        $this->SetFont('Arial', 'B', 11);
        if ($this->tipoBoletín === 'cuatrimestre') {
            $this->Cell($anchoTextoDisponible, 7, 'REGISTRO INSTITUCIONAL DE TRAYECTORIAS EDUCATIVAS (RITE)', 0, 1, 'C');
        } else {
            $bimestreTexto = ($this->bimestre == 1) ? '1er' : '3er';
            $this->Cell($anchoTextoDisponible, 7, 'BOLETÍN DE VALORACIONES BIMESTRALES', 0, 1, 'C');
        }
        
        $this->Ln(4);
    }

    function TablaCalificacionesCombinadas($calificacionesCombinadas, $datosEstudiante) {
        // Cabeceras
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 7);
        
        // Primera fila de cabeceras
        $this->Cell(10, 7, 'TIPO', 1, 0, 'C', true);
        $this->Cell(75, 7, 'MATERIAS', 1, 0, 'C', true);
        $this->Cell(10, 7, 'AÑO', 1, 0, 'C', true);
        $this->Cell(30, 7, '1° CUATRIMESTRE', 1, 0, 'C', true);
        $this->Cell(50, 7, '2° CUATRIMESTRE', 1, 0, 'C', true);
        $this->Cell(30, 7, 'INTENSIFICACIÓN', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(45, 7, 'OBSERVACIONES', 1, 0, 'C', true);
        $this->Ln();
        
        // Segunda fila de cabeceras
        $this->Cell(10, 7, '(C-R)', 1, 0, 'C', true);
        $this->Cell(75, 7, '', 1, 0, 'C', true);
        $this->Cell(10, 7, '', 1, 0, 'C', true);
        $this->Cell(15, 7, '1° VAL. PR.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(15, 7, '2° VAL. PR.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(20, 7, 'INT. 1° CUAT.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'DIC.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'FEB.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'FINAL', 1, 0, 'C', true);
        $this->Cell(45, 7, '', 1, 0, 'C', true);
        $this->Ln();
        
        $this->SetFont('Arial', '', 7);
        
        // Datos
        $fill = false;
        foreach($calificacionesCombinadas as $item) {
            
            // Verificar si es liberada
            $esLiberada = false;
            if (isset($item['materia_curso_id'])) {
                $esLiberada = in_array($item['materia_curso_id'], $this->materiasLiberadasIds);
            }
            
            // Establecer colores de fila
            if ($esLiberada) {
                $colorFilaR = 255; $colorFilaG = 255; $colorFilaB = 150;
            } elseif ($fill) {
                $colorFilaR = 224; $colorFilaG = 235; $colorFilaB = 255;
            } else {
                $colorFilaR = 255; $colorFilaG = 255; $colorFilaB = 255;
            }
            
            // Determinar tipo de cursada
            $anioEstudiante = $datosEstudiante['curso_anio'];
            $anioMateria = $item['materia_anio'] ?? $anioEstudiante;
            
            if ($esLiberada) {
                $tipoCursada = '';
            } else {
                $tipoCursada = ($anioEstudiante > $anioMateria) ? 'R' : 'C';
            }
            
            $nombreItem = normalizarNombreMateria($item['materia_nombre']);
            
            // Render de celdas
            $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
            $this->SetTextColor(0, 0, 0);
            
            $this->Cell(10, 6, $tipoCursada, 1, 0, 'C', true);
            $this->Cell(75, 6, $nombreItem, 1, 0, 'L', true);
            $this->Cell(10, 6, $anioMateria, 1, 0, 'C', true);
            
            // Valoraciones y calificaciones
            $val1c = trim($item['valoracion_preliminar_1c'] ?? '');
            $cal1c = $item['calificacion_1c'];
            $val2c = trim($item['valoracion_preliminar_2c'] ?? '');
            $cal2c = $item['calificacion_2c'];
            
            $this->Cell(15, 6, (!empty($val1c) && $val1c !== '') ? $val1c : '-', 1, 0, 'C', true);
            
            // Calificación 1C con color condicional
            if (!empty($cal1c) && is_numeric($cal1c) && floatval($cal1c) <= 6) {
                $this->SetFillColor(255, 200, 200);
                $this->SetTextColor(139, 0, 0);
            } else {
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
            }
            $this->Cell(15, 6, (!empty($cal1c) && is_numeric($cal1c)) ? $cal1c : '-', 1, 0, 'C', true);
            
            $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
            $this->SetTextColor(0, 0, 0);
            
            $this->Cell(15, 6, (!empty($val2c) && $val2c !== '') ? $val2c : '-', 1, 0, 'C', true);
            
            // Calificación 2C
            if (!empty($cal2c) && is_numeric($cal2c) && floatval($cal2c) <= 6) {
                $this->SetFillColor(255, 200, 200);
                $this->SetTextColor(139, 0, 0);
            } else {
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
            }
            $this->Cell(15, 6, (!empty($cal2c) && is_numeric($cal2c)) ? $cal2c : '-', 1, 0, 'C', true);
            
            // Intensificaciones
            $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
            $this->SetTextColor(0, 0, 0);
            
            $intensificacion1c = $item['intensificacion_1c'];
            $this->Cell(20, 6, !empty($intensificacion1c) ? $intensificacion1c : '-', 1, 0, 'C', true);
            
            $intensificacionDic = $item['intensificacion_diciembre'];
            $this->Cell(15, 6, !empty($intensificacionDic) ? $intensificacionDic : '-', 1, 0, 'C', true);
            
            $intensificacionFeb = $item['intensificacion_febrero'];
            $this->Cell(15, 6, !empty($intensificacionFeb) ? $intensificacionFeb : '-', 1, 0, 'C', true);
            
            // Calificación Final
            $calificacionFinal = $item['calificacion_final'];
            if (!empty($calificacionFinal) && is_numeric($calificacionFinal) && floatval($calificacionFinal) <= 6) {
                $this->SetFillColor(255, 200, 200);
                $this->SetTextColor(139, 0, 0);
            } else {
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
            }
            $this->Cell(15, 6, !empty($calificacionFinal) ? $calificacionFinal : '-', 1, 0, 'C', true);
            
            // Observaciones
            $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
            $this->SetTextColor(0, 0, 0);
            $observaciones = '';
            
            if ($esLiberada) {
                $observaciones = 'Se pospone la cursada de la materia';
            } elseif (!tieneAlgunDato($item, false)) {
                $observaciones = 'No cursa la materia';
            } else {
                $observaciones = !empty($item['observaciones']) ? $item['observaciones'] : '-';
            }
            
            $this->Cell(45, 6, $observaciones, 1, 0, 'L', true);
            
            $this->Ln();
            $fill = !$fill;
        }
    }
    
    function TablaAsistenciasConFirmas($asistenciasPorCuatrimestre) {
        // Cabeceras
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 7);
        
        $this->Cell(25, 7, '', 1, 0, 'C', true);
        $this->Cell(30, 7, '1° CUATRIM.', 1, 0, 'C', true);
        $this->Cell(30, 7, '2° CUATRIM.', 1, 0, 'C', true);
        $this->Cell(25, 7, 'TOTAL', 1, 0, 'C', true);
        
        // Espacio para firmas
        $this->SetX(125);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->Cell(0, 5, 'FIRMAS', 0, 1, 'L');
        
        // Preparar datos
        $datos1c = isset($asistenciasPorCuatrimestre[1]) ? $asistenciasPorCuatrimestre[1] : [
            'total_dias' => 0, 'ausentes' => 0, 'medias_faltas' => 0, 'justificadas' => 0
        ];
        $datos2c = isset($asistenciasPorCuatrimestre[2]) ? $asistenciasPorCuatrimestre[2] : [
            'total_dias' => 0, 'ausentes' => 0, 'medias_faltas' => 0, 'justificadas' => 0
        ];
        
        // Calcular inasistencias
        $ausentes1 = $datos1c['ausentes'] ?? 0;
        $mediasFaltas1 = ($datos1c['medias_faltas'] ?? 0) * 0.5;
        $inasistencias1c = $ausentes1 + $mediasFaltas1;
        
        $ausentes2 = $datos2c['ausentes'] ?? 0;
        $mediasFaltas2 = ($datos2c['medias_faltas'] ?? 0) * 0.5;
        $inasistencias2c = $ausentes2 + $mediasFaltas2;
        
        $totalInasistencias = $inasistencias1c + $inasistencias2c;
        
        // Fila de inasistencias
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(25, 6, 'INASISTENCIAS', 1, 0, 'L', true);
        
        $this->SetFillColor(240, 248, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 7);
        
        $this->Cell(30, 6, $inasistencias1c, 1, 0, 'C', true);
        $this->Cell(30, 6, $inasistencias2c, 1, 0, 'C', true);
        
        $this->SetFillColor(220, 235, 255);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(25, 6, $totalInasistencias, 1, 0, 'C', true);
        
        // Firmas (lado derecho)
        $xFirmas = 140;
        $this->SetXY($xFirmas, $this->GetY() - 5);
        
        $anchoFirma = 50;
        
        // Líneas para firmas
        $this->SetX($xFirmas);
        $this->SetFont('Arial', '', 8);
        $this->Cell($anchoFirma, 3, '____________________', 0, 0, 'C');
        $this->Cell($anchoFirma, 3, '____________________', 0, 0, 'C');
        $this->Cell($anchoFirma, 3, '____________________', 0, 1, 'C');
        
        $this->Ln(2);
        
        // Información de firmas
        $this->SetX($xFirmas);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($anchoFirma, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell($anchoFirma, 4, 'Firma del Estudiante', 0, 0, 'C');
        $this->Cell($anchoFirma, 4, 'Firma del Responsable', 0, 1, 'C');
        
        $this->SetX($xFirmas);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($anchoFirma, 4, 'DIRECTORA', 0, 0, 'C');
        $this->SetFont('Arial', '', 7);
        $this->Cell($anchoFirma, 4, '', 0, 0, 'C');
        $this->Cell($anchoFirma, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
        
        $this->Ln(5);
    }
}

function limpiarNombreArchivo($texto) {
    $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ');
    $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N');
    return str_replace($buscar, $reemplazar, $texto);
}

// GENERAR EL PDF
$pdf = new BoletinPDF('cuatrimestre', 1, $cuatrimestreSeleccionado, $materiasLiberadasIds);
$pdf->SetTitle('RITE - ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']);
$pdf->SetAuthor('Escuela Técnica Henry Ford');
$pdf->AliasNbPages();
$pdf->AddPage();

// Información del estudiante
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(85, 6, 'Estudiante: ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre'], 0, 0, 'L');
$pdf->Cell(30, 6, 'Curso: ' . $datosEstudiante['curso_nombre'], 0, 0, 'L');
$pdf->Cell(40, 6, 'Ciclo Lectivo: ' . $anioActivo, 0, 1, 'L');
$pdf->Ln(1);

if ($tipoBoletinSeleccionado === 'cuatrimestre') {
    // Tabla de calificaciones combinadas
    $pdf->TablaCalificacionesCombinadas($calificacionesCombinadas, $datosEstudiante);
    $pdf->Ln(2);
    
    // Tabla de asistencias
    $pdf->TablaAsistenciasConFirmas($asistenciasPorCuatrimestre);
    $pdf->Ln(1);
}

// Generar y retornar el PDF
$pdf->Output();
?>
