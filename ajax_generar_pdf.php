<?php
/**
 * ajax_generar_pdf.php - VERSIÓN COMPLETA CON MANEJO DE ERRORES MEJORADO
 * NO debe haber NADA antes de esta línea
 */

// Desactivar reporte de errores que puedan contaminar el JSON
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. PRIMERO: Capturar toda salida desde el inicio
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// 2. SEGUNDO: Configurar headers JSON ANTES de cualquier otra cosa
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// 3. TERCERO: Log para debug
error_log("=== INICIO ajax_generar_pdf.php ===");

// 4. CUARTO: Iniciar sesión de forma segura
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 5. QUINTO: Verificar y limpiar cualquier salida hasta aquí
$salida_hasta_aqui = ob_get_contents();
if (!empty($salida_hasta_aqui)) {
    error_log("SALIDA DETECTADA antes de include config: " . substr($salida_hasta_aqui, 0, 200));
    ob_clean(); // Limpiar la salida
}

// 6. SEXTO: Incluir config con manejo de errores robusto
try {
    // Capturar cualquier salida de config.php
    ob_start();
    require_once 'config.php';
    $salida_config = ob_get_contents();
    ob_end_clean();
    
    if (!empty($salida_config)) {
        error_log("SALIDA DE CONFIG.PHP: " . substr($salida_config, 0, 200));
    }
    
    error_log("Config.php incluido exitosamente");
} catch (Exception $e) {
    ob_end_clean();
    error_log("ERROR al incluir config.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error en config.php: ' . $e->getMessage()]);
    exit;
}

// 7. SÉPTIMO: Verificar que Database esté disponible
if (!class_exists('Database')) {
    ob_end_clean();
    error_log("ERROR: Clase Database no disponible");
    echo json_encode(['success' => false, 'error' => 'Clase Database no encontrada']);
    exit;
}

// 8. OCTAVO: Limpiar cualquier buffer restante
ob_end_clean();

// 9. NOVENO: Verificar sesión
if (!isset($_SESSION['user_id'])) {
    error_log("Sesión no válida");
    echo json_encode(['success' => false, 'error' => 'Sesión no válida']);
    exit;
}

// 10. DÉCIMO: Obtener acción
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
error_log("Acción recibida: " . $action);

// 11. DÉCIMO PRIMERO: Ejecutar acción
try {
    switch ($action) {
        case 'init':
            error_log("Ejecutando inicialización");
            inicializarSesionSimple();
            break;
        case 'generate':
            error_log("Ejecutando generación");
            generarPDFEstudiante();
            break;
        case 'createZip':
            error_log("Ejecutando creación de ZIP");
            crearArchivoZIP();
            break;
        case 'cleanup':
            error_log("Ejecutando limpieza");
            limpiarArchivosTemporales();
            break;
        case 'download':
            error_log("Ejecutando descarga");
            descargarArchivo();
            break;
        default:
            error_log("Acción no válida: " . $action);
            echo json_encode(['success' => false, 'error' => 'Acción no válida: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Error en switch: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

/**
 * Función de inicialización mejorada con mejor validación
 */
function inicializarSesionSimple() {
    error_log("=== Iniciando inicializarSesionSimple ===");
    
    $sessionId = $_POST['sessionId'] ?? '';
    $cursoId = intval($_POST['cursoId'] ?? 0);
    
    error_log("SessionId recibido: " . $sessionId);
    error_log("CursoId recibido: " . $cursoId);
    error_log("Todos los POST: " . json_encode($_POST));
    
    // CORREGIDO: Validación más específica
    if (empty($sessionId)) {
        error_log("SessionId vacío");
        echo json_encode(['success' => false, 'error' => 'SessionId vacío']);
        return;
    }
    
    if ($cursoId <= 0) {
        error_log("CursoId inválido: " . $cursoId);
        echo json_encode(['success' => false, 'error' => 'CursoId inválido: ' . $cursoId]);
        return;
    }
    
    try {
        // Verificar base de datos
        $db = Database::getInstance();
        error_log("Database instance obtenida");
        
        $curso = $db->fetchOne("SELECT id, nombre FROM cursos WHERE id = ?", [$cursoId]);
        error_log("Consulta de curso ejecutada");
        
        if (!$curso) {
            error_log("Curso no encontrado para ID: " . $cursoId);
            echo json_encode(['success' => false, 'error' => 'Curso no encontrado para ID: ' . $cursoId]);
            return;
        }
        
        error_log("Curso encontrado: " . $curso['nombre']);
        
        // Crear directorio temporal
        $dirTemp = sys_get_temp_dir() . '/boletines_' . $sessionId;
        error_log("Directorio temporal: " . $dirTemp);
        
        if (!file_exists($dirTemp)) {
            if (!mkdir($dirTemp, 0777, true)) {
                error_log("No se pudo crear directorio");
                echo json_encode(['success' => false, 'error' => 'No se pudo crear directorio temporal']);
                return;
            }
        }
        
        error_log("Directorio creado exitosamente");
        
        // Guardar en sesión
        $_SESSION['gen_' . $sessionId] = [
            'cursoId' => $cursoId,
            'directorio' => $dirTemp,
            'archivos' => [],
            'timestamp' => time()
        ];
        
        error_log("Datos guardados en sesión");
        
        // Respuesta exitosa
        $respuesta = [
            'success' => true, 
            'sessionId' => $sessionId,
            'directorio' => $dirTemp,
            'curso' => $curso['nombre']
        ];
        
        error_log("Enviando respuesta: " . json_encode($respuesta));
        echo json_encode($respuesta);
        
    } catch (Exception $e) {
        error_log("Error en inicializarSesionSimple: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
    }
}

/**
 * Función para generar PDF de estudiante
 */
function generarPDFEstudiante() {
    // Limpiar cualquier salida previa
    if (ob_get_level()) {
        ob_clean();
    }
    
    error_log("=== Iniciando generarPDFEstudiante ===");
    
    $sessionId = $_POST['sessionId'] ?? '';
    $estudianteId = intval($_POST['estudianteId'] ?? 0);
    
    error_log("SessionId: " . $sessionId);
    error_log("EstudianteId: " . $estudianteId);
    
    if (!$sessionId || !isset($_SESSION['gen_' . $sessionId])) {
        error_log("Sesión no válida");
        echo json_encode(['success' => false, 'error' => 'Sesión no válida']);
        return;
    }
    
    try {
        $db = Database::getInstance();
        
        // Obtener datos del estudiante
        $estudiante = $db->fetchOne(
            "SELECT id, nombre, apellido, dni FROM usuarios WHERE id = ? AND tipo = 'estudiante'",
            [$estudianteId]
        );
        
        if (!$estudiante) {
            error_log("Estudiante no encontrado");
            echo json_encode(['success' => false, 'error' => 'Estudiante no encontrado']);
            return;
        }
        
        $nombreCompleto = trim($estudiante['apellido'] . ', ' . $estudiante['nombre']);
        error_log("Procesando estudiante: " . $nombreCompleto);
        
        // Limpiar nombre para archivo (quitar caracteres especiales)
        $nombreArchivo = limpiarNombreArchivo($nombreCompleto);
        $archivoNombre = "Boletin_" . $nombreArchivo . ".pdf";
        
        // Obtener directorio de la sesión
        $datosSession = $_SESSION['gen_' . $sessionId];
        $rutaArchivo = $datosSession['directorio'] . '/' . $archivoNombre;
        
        error_log("Ruta del archivo: " . $rutaArchivo);
        
        // Limpiar cualquier output buffer antes de generar PDF
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Iniciar captura de salida para el PDF
        ob_start();
        
        // Generar PDF real
        $contenidoPDF = generarContenidoPDFSimulado($estudiante);
        
        // Limpiar buffer y obtener solo el contenido del PDF
        $output = ob_get_clean();
        
        // Si hay salida inesperada, limpiarla
        if (!empty($output)) {
            error_log("Salida inesperada durante generación PDF: " . substr($output, 0, 200));
        }
        
        if (empty($contenidoPDF)) {
            throw new Exception("El PDF generado está vacío");
        }
        
        if (file_put_contents($rutaArchivo, $contenidoPDF) === false) {
            throw new Exception("No se pudo escribir el archivo PDF");
        }
        
        // Verificar que el archivo se creó correctamente
        if (!file_exists($rutaArchivo) || filesize($rutaArchivo) == 0) {
            throw new Exception("El archivo PDF no se generó correctamente");
        }
        
        // Actualizar archivos en sesión
        $_SESSION['gen_' . $sessionId]['archivos'][] = [
            'nombre' => $archivoNombre,
            'ruta' => $rutaArchivo,
            'estudiante' => $nombreCompleto,
            'tamaño' => filesize($rutaArchivo)
        ];
        
        error_log("PDF generado exitosamente: " . $archivoNombre . " (" . filesize($rutaArchivo) . " bytes)");
        
        $respuesta = [
            'success' => true,
            'archivo' => $archivoNombre,
            'size' => filesize($rutaArchivo),
            'estudiante' => $nombreCompleto
        ];
        
        error_log("Enviando respuesta de generación: " . json_encode($respuesta));
        echo json_encode($respuesta);
        
    } catch (Exception $e) {
        error_log("Error en generarPDFEstudiante: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => 'Error al generar PDF: ' . $e->getMessage()]);
    }
}

/**
 * Función para crear archivo ZIP con todos los PDFs
 */
function crearArchivoZIP() {
    error_log("=== Iniciando crearArchivoZIP ===");
    
    $sessionId = $_POST['sessionId'] ?? '';
    $cursoId = intval($_POST['cursoId'] ?? 0);
    
    if (!$sessionId || !isset($_SESSION['gen_' . $sessionId])) {
        error_log("Sesión no válida");
        echo json_encode(['success' => false, 'error' => 'Sesión no válida']);
        return;
    }
    
    try {
        $datosSession = $_SESSION['gen_' . $sessionId];
        $archivos = $datosSession['archivos'];
        
        if (empty($archivos)) {
            error_log("No hay archivos para comprimir");
            echo json_encode(['success' => false, 'error' => 'No hay archivos PDF para comprimir']);
            return;
        }
        
        // Obtener nombre del curso para el ZIP
        $db = Database::getInstance();
        $curso = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
        $nombreCurso = $curso ? limpiarNombreArchivo($curso['nombre']) : 'Curso_' . $cursoId;
        
        // Crear nombre del archivo ZIP
        $fechaHora = date('Y-m-d_H-i-s');
        $nombreZIP = "Boletines_{$nombreCurso}_{$fechaHora}.zip";
        $rutaZIP = $datosSession['directorio'] . '/' . $nombreZIP;
        
        error_log("Creando ZIP: " . $rutaZIP);
        error_log("Archivos a incluir: " . count($archivos));
        
        // Verificar que la extensión ZIP esté disponible
        if (!class_exists('ZipArchive')) {
            throw new Exception('La extensión ZIP no está disponible en el servidor');
        }
        
        $zip = new ZipArchive();
        $resultado = $zip->open($rutaZIP, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($resultado !== TRUE) {
            throw new Exception("No se pudo crear el archivo ZIP. Código de error: " . $resultado);
        }
        
        $archivosIncluidos = 0;
        $tamañoTotal = 0;
        
        // Agregar cada archivo PDF al ZIP
        foreach ($archivos as $archivo) {
            if (file_exists($archivo['ruta'])) {
                $zip->addFile($archivo['ruta'], $archivo['nombre']);
                $archivosIncluidos++;
                $tamañoTotal += $archivo['tamaño'];
                error_log("Agregado al ZIP: " . $archivo['nombre']);
            } else {
                error_log("Archivo no encontrado: " . $archivo['ruta']);
            }
        }
        
        // Cerrar el archivo ZIP
        if (!$zip->close()) {
            throw new Exception("Error al cerrar el archivo ZIP");
        }
        
        if ($archivosIncluidos === 0) {
            throw new Exception("No se pudo agregar ningún archivo al ZIP");
        }
        
        $tamañoZIP = filesize($rutaZIP);
        error_log("ZIP creado exitosamente");
        error_log("Archivos incluidos: " . $archivosIncluidos);
        error_log("Tamaño del ZIP: " . $tamañoZIP . " bytes");
        
        // Guardar información del ZIP en la sesión
        $_SESSION['gen_' . $sessionId]['zip'] = [
            'nombre' => $nombreZIP,
            'ruta' => $rutaZIP,
            'tamaño' => $tamañoZIP,
            'archivos_incluidos' => $archivosIncluidos
        ];
        
        $respuesta = [
            'success' => true,
            'archivo' => $nombreZIP,
            'size' => $tamañoZIP,
            'archivosIncluidos' => $archivosIncluidos,
            'tamañoOriginal' => $tamañoTotal
        ];
        
        error_log("Enviando respuesta ZIP: " . json_encode($respuesta));
        echo json_encode($respuesta);
        
    } catch (Exception $e) {
        error_log("Error en crearArchivoZIP: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error al crear ZIP: ' . $e->getMessage()]);
    }
}

/**
 * Función para descargar archivo ZIP
 */
function descargarArchivo() {
    error_log("=== Iniciando descargarArchivo ===");
    
    $archivo = $_GET['file'] ?? '';
    
    if (!$archivo) {
        error_log("Archivo no especificado");
        echo json_encode(['success' => false, 'error' => 'Archivo no especificado']);
        return;
    }
    
    // Buscar el archivo en las sesiones activas
    $rutaArchivo = null;
    foreach ($_SESSION as $key => $value) {
        if (strpos($key, 'gen_') === 0 && isset($value['zip']) && $value['zip']['nombre'] === $archivo) {
            $rutaArchivo = $value['zip']['ruta'];
            break;
        }
    }
    
    if (!$rutaArchivo || !file_exists($rutaArchivo)) {
        error_log("Archivo no encontrado: " . $archivo);
        echo json_encode(['success' => false, 'error' => 'Archivo no encontrado']);
        return;
    }
    
    error_log("Iniciando descarga: " . $rutaArchivo);
    
    // Limpiar cualquier salida previa
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Configurar headers para descarga
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($archivo) . '"');
    header('Content-Length: ' . filesize($rutaArchivo));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Enviar archivo
    readfile($rutaArchivo);
    
    error_log("Descarga completada");
    exit;
}

/**
 * Función para limpiar archivos temporales
 */
function limpiarArchivosTemporales() {
    error_log("=== Iniciando limpiarArchivosTemporales ===");
    
    $sessionId = $_POST['sessionId'] ?? '';
    
    if (!$sessionId || !isset($_SESSION['gen_' . $sessionId])) {
        echo json_encode(['success' => true, 'mensaje' => 'No hay archivos para limpiar']);
        return;
    }
    
    try {
        $datosSession = $_SESSION['gen_' . $sessionId];
        $directorio = $datosSession['directorio'];
        
        $archivosEliminados = 0;
        
        if (is_dir($directorio)) {
            // Eliminar todos los archivos del directorio
            $archivos = glob($directorio . '/*');
            foreach ($archivos as $archivo) {
                if (is_file($archivo)) {
                    unlink($archivo);
                    $archivosEliminados++;
                    error_log("Archivo eliminado: " . basename($archivo));
                }
            }
            
            // Eliminar el directorio
            rmdir($directorio);
            error_log("Directorio eliminado: " . $directorio);
        }
        
        // Limpiar datos de sesión
        unset($_SESSION['gen_' . $sessionId]);
        
        error_log("Limpieza completada. Archivos eliminados: " . $archivosEliminados);
        
        echo json_encode([
            'success' => true, 
            'mensaje' => 'Archivos temporales eliminados',
            'archivosEliminados' => $archivosEliminados
        ]);
        
    } catch (Exception $e) {
        error_log("Error en limpiarArchivosTemporales: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error al limpiar: ' . $e->getMessage()]);
    }
}

/**
 * Función auxiliar para limpiar nombres de archivo
 */
function limpiarNombreArchivo($nombre) {
    // Convertir a ASCII y eliminar caracteres especiales
    $nombre = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
    
    // Reemplazar espacios y caracteres no válidos con guiones bajos
    $nombre = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $nombre);
    
    // Eliminar múltiples guiones bajos consecutivos
    $nombre = preg_replace('/_+/', '_', $nombre);
    
    // Eliminar guiones bajos al inicio y final
    $nombre = trim($nombre, '_');
    
    // Limitar longitud
    if (strlen($nombre) > 50) {
        $nombre = substr($nombre, 0, 50);
    }
    
    return $nombre;
}

/**
 * Función para generar PDF real del boletín RITE
 * Versión robusta con manejo de errores mejorado
 */
function generarContenidoPDFSimulado($estudiante) {
    try {
        error_log("DEBUG: Iniciando generación de PDF para estudiante ID: " . $estudiante['id']);
        
        // Verificar que las funciones de PDF estén disponibles
        if (!file_exists('lib/fpdf_utf8.php')) {
            error_log("ERROR: No se encuentra lib/fpdf_utf8.php");
            throw new Exception("Librería FPDF no encontrada");
        }
        
        // Incluir funciones de PDF de forma segura
        if (file_exists('includes/boletines_pdf_functions.php')) {
            require_once 'includes/boletines_pdf_functions.php';
            error_log("DEBUG: Funciones de PDF incluidas desde includes/");
        } else {
            error_log("WARNING: No se encuentra includes/boletines_pdf_functions.php, usando funciones básicas");
        }
        
        require_once 'lib/fpdf_utf8.php';
        
        // Obtener parámetros desde POST con validación
        $cursoId = intval($_POST['cursoId'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'cuatrimestre';
        $cuatrimestre = intval($_POST['cuatrimestre'] ?? 1);
        $bimestre = intval($_POST['bimestre'] ?? 1);
        $cicloId = intval($_POST['cicloId'] ?? 0);
        
        if ($cursoId <= 0 || $cicloId <= 0) {
            throw new Exception("Parámetros inválidos: cursoId=$cursoId, cicloId=$cicloId");
        }
        
        error_log("DEBUG: Parámetros validados - cursoId=$cursoId, tipo=$tipo, cuatrimestre=$cuatrimestre, bimestre=$bimestre, cicloId=$cicloId");
        
        // Verificar que las funciones necesarias estén disponibles
        if (!class_exists('BoletinPDF')) {
            error_log("WARNING: Clase BoletinPDF no encontrada, generando PDF básico");
            return generarPDFBasico($estudiante);
        }
        
        // Intentar generar PDF completo
        $contenidoPDF = generarPDFRITECompleto($estudiante, $cursoId, $tipo, $cuatrimestre, $bimestre, $cicloId);
        
        if (empty($contenidoPDF)) {
            error_log("WARNING: PDF completo vacío, generando PDF básico");
            return generarPDFBasico($estudiante);
        }
        
        error_log("DEBUG: PDF generado exitosamente, tamaño: " . strlen($contenidoPDF) . " bytes");
        return $contenidoPDF;
        
    } catch (Exception $e) {
        error_log("ERROR en generarContenidoPDFSimulado: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // Como fallback, generar un PDF básico
        try {
            error_log("DEBUG: Intentando generar PDF básico como fallback");
            return generarPDFBasico($estudiante);
        } catch (Exception $e2) {
            error_log("ERROR en PDF básico: " . $e2->getMessage());
            throw new Exception("Error al generar PDF: " . $e->getMessage());
        }
    }
}

/**
 * Función de fallback para generar PDF básico cuando falla el completo
 */
function generarPDFBasico($estudiante) {
    try {
        require_once 'lib/fpdf_utf8.php';
        
        // Crear PDF básico con FPDF
        $pdf = new FPDF_UTF8('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        
        // Título
        $pdf->Cell(0, 15, 'ESCUELA TÉCNICA HENRY FORD', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, 'BOLETÍN DE CALIFICACIONES', 0, 1, 'C');
        
        $pdf->Ln(10);
        
        // Datos del estudiante
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, 'Estudiante: ' . $estudiante['apellido'] . ', ' . $estudiante['nombre'], 0, 1, 'L');
        
        if (!empty($estudiante['dni'])) {
            $pdf->Cell(0, 8, 'DNI: ' . $estudiante['dni'], 0, 1, 'L');
        }
        
        $pdf->Ln(10);
        
        // Mensaje informativo
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->MultiCell(0, 6, 'Este es un boletín generado de forma masiva. Para obtener el boletín completo con todas las calificaciones y detalles, genere el boletín individual desde el sistema.', 0, 'L');
        
        $pdf->Ln(10);
        
        // Fecha de generación
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 5, 'Generado el: ' . date('d/m/Y H:i'), 0, 1, 'R');
        
        return $pdf->Output('S');
        
    } catch (Exception $e) {
        error_log("ERROR en generarPDFBasico: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Función principal para generar PDF RITE completo
 * Adaptación de la lógica original para uso en AJAX
 */
function generarPDFRITECompleto($datosEstudiante, $cursoId, $tipoBoletinSeleccionado, $cuatrimestreSeleccionado, $bimestreSeleccionado, $cicloLectivoId) {
    try {
        $db = Database::getInstance();
        
        // Obtener ciclo lectivo activo
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE id = ?", [$cicloLectivoId]);
        if (!$cicloActivo) {
            throw new Exception('Ciclo lectivo no encontrado');
        }
        
        $anioActivo = $cicloActivo['anio'];
        
        // Obtener datos completos del estudiante
        $datosCompletos = $db->fetchOne(
            "SELECT u.id, u.nombre, u.apellido, u.dni, 
                    c.nombre as curso_nombre, c.anio as curso_anio
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             JOIN cursos c ON m.curso_id = c.id
             WHERE u.id = ? AND m.curso_id = ?",
            [$datosEstudiante['id'], $cursoId]
        );
        
        if (!$datosCompletos) {
            throw new Exception('No se encontraron datos del estudiante');
        }
        
        // Obtener materias liberadas
        $materiasLiberadas = obtenerMateriasLiberadas($db, $datosEstudiante['id'], $cicloLectivoId);
        $materiasLiberadasIds = array_column($materiasLiberadas, 'materia_liberada_id');
        
        // Obtener materias pendientes agrupadas (versión simplificada)
        $materiasAgrupadasPendientes = [];
        if (function_exists('agruparMateriasPendientesPorGrupo')) {
            try {
                $materiasAgrupadasPendientes = agruparMateriasPendientesPorGrupo($db, $datosEstudiante['id'], $cicloLectivoId);
            } catch (Exception $e) {
                error_log("Error al obtener materias pendientes: " . $e->getMessage());
                $materiasAgrupadasPendientes = [];
            }
        }
        
        // Generar PDF según el tipo
        if ($tipoBoletinSeleccionado === 'cuatrimestre') {
            $calificacionesCombinadas = obtenerCalificacionesCombinadas($db, $datosEstudiante['id'], $cicloLectivoId, $datosCompletos['curso_anio']);
            
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
                [$datosEstudiante['id'], $cursoId]
            );
            
            $asistenciasPorCuatrimestre = [];
            foreach ($asistencias as $asistencia) {
                $asistenciasPorCuatrimestre[$asistencia['cuatrimestre']] = $asistencia;
            }
            
            return generarPDFCuatrimestral($datosCompletos, $calificacionesCombinadas, $materiasAgrupadasPendientes, $asistenciasPorCuatrimestre, $materiasLiberadasIds, $anioActivo, $cuatrimestreSeleccionado);
            
        } else {
            $valoracionesCombinadas = obtenerValoracionesBimestralesCombinadas($db, $datosEstudiante['id'], $cicloLectivoId, $datosCompletos['curso_anio'], $bimestreSeleccionado);
            
            $cuatrimestreCorrespondiente = ($bimestreSeleccionado == 1) ? 1 : 2;
            $asistenciasBimestre = $db->fetchOne(
                "SELECT cuatrimestre,
                        COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                        COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas,
                        COUNT(CASE WHEN estado = 'justificada' THEN 1 END) as justificadas,
                        COUNT(*) as total_dias
                 FROM asistencias
                 WHERE estudiante_id = ? AND curso_id = ? AND cuatrimestre = ?",
                [$datosEstudiante['id'], $cursoId, $cuatrimestreCorrespondiente]
            );
            
            return generarPDFBimestral($datosCompletos, $valoracionesCombinadas, $materiasAgrupadasPendientes, $asistenciasBimestre, $materiasLiberadasIds, $anioActivo, $bimestreSeleccionado);
        }
        
    } catch (Exception $e) {
        error_log("Error en generarPDFRITECompleto: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Generar PDF Cuatrimestral (RITE)
 */
function generarPDFCuatrimestral($datosEstudiante, $calificacionesCombinadas, $materiasAgrupadasPendientes, $asistenciasPorCuatrimestre, $materiasLiberadasIds, $anioActivo, $cuatrimestreSeleccionado) {
    
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
    
    // Contenido del PDF
    $pdf->TablaCalificacionesCombinadas($calificacionesCombinadas, $datosEstudiante);
    $pdf->Ln(2);
    $pdf->TablaMateriasesPendientes($materiasAgrupadasPendientes);
    $pdf->Ln(2);
    $pdf->TablaAsistenciasConFirmas($asistenciasPorCuatrimestre);
    $pdf->Ln(1);
    
    return $pdf->Output('S'); // Retornar como string
}

/**
 * Generar PDF Bimestral
 */
function generarPDFBimestral($datosEstudiante, $valoracionesCombinadas, $materiasAgrupadasPendientes, $asistenciasBimestre, $materiasLiberadasIds, $anioActivo, $bimestreSeleccionado) {
    
    $pdf = new BoletinPDF('bimestre', $bimestreSeleccionado, 1, $materiasLiberadasIds);
    $bimestreTexto = ($bimestreSeleccionado == 1) ? '1er' : '3er';
    $pdf->SetTitle('Boletin Bimestral (' . $bimestreTexto . ') - ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']);
    $pdf->SetAuthor('Escuela Técnica Henry Ford');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Información del estudiante
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(85, 6, 'Estudiante: ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre'], 0, 0, 'L');
    $pdf->Cell(30, 6, 'Curso: ' . $datosEstudiante['curso_nombre'], 0, 0, 'L');
    $pdf->Cell(40, 6, 'Ciclo Lectivo: ' . $anioActivo, 0, 1, 'L');
    $pdf->Ln(1);
    
    // Contenido del PDF
    $pdf->TablaValoracionesBimestralesCombinadas($valoracionesCombinadas, $bimestreSeleccionado);
    $pdf->Ln(5);
    $pdf->TablaMateriasesPendientes($materiasAgrupadasPendientes);
    $pdf->Ln(1);
    
    if (isset($asistenciasBimestre) && $asistenciasBimestre) {
        $pdf->TablaAsistenciasBimestreConFirmas($asistenciasBimestre, $bimestreSeleccionado);
    }
    $pdf->Ln(1);
    
    return $pdf->Output('S'); // Retornar como string
}

error_log("=== FIN ajax_generar_pdf.php ===");
?>
