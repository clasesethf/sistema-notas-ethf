<?php
/**
 * importar_calificaciones.php - Sistema de importación de calificaciones desde archivos
 * Soporta CSV, Excel y análisis de PDF para importar datos de boletines previos
 * COMPATIBLE CON SQLITE - OPTIMIZADO PARA FORMATO HENRY FORD
 * INCLUYE IMPORTACIÓN MASIVA POR AÑO
 */

// Iniciar buffer de salida para evitar problemas con headers
ob_start();

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir archivos necesarios
require_once 'config.php';

// Verificar que existe la clase Database
if (!class_exists('Database')) {
    die('Error: No se pudo cargar la clase Database. Verifique que config.php esté correcto.');
}

// Verificar permisos ANTES de incluir header
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Procesar formulario ANTES de incluir header para permitir redirecciones
$resultado_procesamiento = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance();
        
        // Obtener ciclo lectivo activo
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
        
        if ($cicloLectivoId == 0) {
            throw new Exception('No hay un ciclo lectivo activo configurado');
        }
        
        // Verificar si es importación masiva
        if (isset($_POST['importacion_masiva']) && $_POST['importacion_masiva'] === '1') {
            $resultado_procesamiento = procesarImportacionMasiva($_FILES['archivo_importacion'], $_POST, $db, $cicloLectivoId);
        } else {
            $resultado_procesamiento = procesarImportacion($_FILES['archivo_importacion'], $_POST, $db, $cicloLectivoId);
        }
        
        if ($resultado_procesamiento['success']) {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'danger';
        }
        
        // Redireccionar para evitar reenvío en F5
        $redirect_url = $_SERVER['REQUEST_URI'];
        if (isset($_POST['curso']) || isset($_POST['curso_masivo'])) {
            $curso = isset($_POST['curso']) ? intval($_POST['curso']) : intval($_POST['curso_masivo']);
            $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'curso=' . $curso;
        }
        
        header('Location: ' . $redirect_url);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error en la importación: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Ahora sí incluir el header
require_once 'header.php';

// Obtener conexión a la base de datos
try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error de conexión a la base de datos: ' . $e->getMessage() . '</div>';
    require_once 'footer.php';
    exit;
}

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        echo '<div class="alert alert-warning">No hay un ciclo lectivo activo configurado en el sistema.</div>';
        $cicloLectivoId = 0;
        $anioActivo = date('Y');
    } else {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener ciclo lectivo: ' . $e->getMessage() . '</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
}

// Obtener cursos y materias del usuario
$cursos = [];
$materias = [];

try {
    if ($_SESSION['user_type'] == 'profesor') {
        $cursos = $db->fetchAll(
            "SELECT DISTINCT c.id, c.nombre, c.anio 
             FROM cursos c
             JOIN materias_por_curso mp ON c.id = mp.curso_id
             WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
             AND c.ciclo_lectivo_id = ?
             ORDER BY c.anio, c.nombre",
            [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $cicloLectivoId]
        );
    } else {
        $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener cursos: ' . $e->getMessage() . '</div>';
}

$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
if ($cursoSeleccionado && $cicloLectivoId > 0) {
    try {
        if ($_SESSION['user_type'] == 'profesor') {
            $materias = $db->fetchAll(
                "SELECT mp.id, m.nombre, m.codigo
                 FROM materias_por_curso mp 
                 JOIN materias m ON mp.materia_id = m.id 
                 WHERE mp.curso_id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)
                 ORDER BY m.nombre",
                [$cursoSeleccionado, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
            );
        } else {
            $materias = $db->fetchAll(
                "SELECT mp.id, m.nombre, m.codigo
                 FROM materias_por_curso mp 
                 JOIN materias m ON mp.materia_id = m.id 
                 WHERE mp.curso_id = ? 
                 ORDER BY m.nombre",
                [$cursoSeleccionado]
            );
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener materias: ' . $e->getMessage() . '</div>';
    }
}

/**
 * Mapeos de materias por año
 */
function obtenerMapeoMaterias($anio) {
    switch ($anio) {
        case 3:
            return [
                1 => 'BIOLOGÍA',
                2 => 'CONSTR. DE CIUD. - Maderas',
                3 => 'CONSTR. DE CIUD. - Metales',
                4 => 'CONSTR. DE CIUD. - Electricidad',
                5 => 'EDUCACIÓN ARTÍSTICA - Plástica',
                6 => 'EDUCACIÓN ARTÍSTICA - Música',
                7 => 'EDUCACIÓN FÍSICA',
                8 => 'FÍSICO QUÍMICA',
                9 => 'GEOGRAFÍA',
                10 => 'HISTORIA',
                11 => 'INGLÉS',
                12 => 'MATEMÁTICA',
                13 => 'PRÁCTICAS DEL LENGUAJE',
                14 => 'Metales 3',
                15 => 'Fundición',
                16 => 'Tecnología 3',
                17 => 'Dibujo Técnico 3',
                18 => 'Informática 3',
                19 => 'Inst. Eléctr. Domicililarias 1',
                20 => 'Fluidos',
                21 => 'Proyecto Tecnológico 3',
                22 => 'Robótica 3'
            ];
        case 4:
            return [
                1 => 'LITERATURA',
                2 => 'INGLÉS',
                3 => 'EDUCACIÓN FÍSICA',
                4 => 'SALUD Y ADOLESCENCIA',
                5 => 'HISTORIA',
                6 => 'GEOGRAFÍA',
                7 => 'MATEMÁTICA',
                8 => 'FÍSICA',
                9 => 'QUÍMICA',
                10 => 'CONOCIMIENTO DE LOS MATERIALES',
                11 => 'Dibujo Técnológico',
                12 => 'Dibujo con Autocad',
                13 => 'Electrónica Básica',
                14 => 'Transformadores',
                15 => 'Principios de Automatización',
                16 => 'Metrología',
                17 => 'Mecanizado 1',
                18 => 'Mecanizado 2',
                19 => 'Instalaciones Eléctricas Domicililarias 2',
                20 => 'Soldadura',
                21 => 'Electrotecnia'
            ];
        case 5:
            return [
                1 => 'LITERATURA',
                2 => 'INGLÉS',
                3 => 'EDUCACIÓN FÍSICA',
                4 => 'POLÍTICA Y CIUDADANÍA',
                5 => 'HISTORIA',
                6 => 'GEOGRAFÍA',
                7 => 'ANALISIS MATEMÁTICO',
                8 => 'MECÁNICA Y MECANISMOS',
                9 => 'ELECTROTECNIA',
                10 => 'RESISTENCIA Y ENSAYO DE MATERIALES',
                11 => 'Contactores',
                12 => 'Electrónica Digital',
                13 => 'Motores Eléctricos',
                14 => 'Mecanizado 3',
                15 => 'Metalografía y Tratamientos Térmicos',
                16 => 'CAD 1',
                17 => 'Control de Fluidos 1',
                18 => 'Control de Fluidos 2',
                19 => 'Refrigeración'
            ];
        case 6:
            return [
                1 => 'LITERATURA',
                2 => 'INGLÉS',
                3 => 'EDUCACIÓN FÍSICA',
                4 => 'FILOSOFÍA',
                5 => 'ARTE',
                6 => 'MATEMÁTICA APLICADA',
                7 => 'ELECTROTECNIA',
                8 => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
                9 => 'SISTEMAS MECÁNICOS',
                10 => 'DERECHOS DEL TRABAJO',
                11 => 'Laboratorio de Mediciones Eléctricas',
                12 => 'Microcontroladores',
                13 => 'PLC',
                14 => 'Sistemas de Control',
                15 => 'CNC Torno',
                16 => 'CAD 2',
                17 => 'Fresadora',
                18 => 'Motores de Combustión Interna 1',
                19 => 'Luminotecnia',
                20 => 'Distribución de Energía Eléctrica',
                21 => 'MASTER'
            ];
        case 7:
            return [
                1 => 'PRÁCTICAS PROFESIONALIZANTES',
                2 => 'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
                3 => 'ELECTRÓNICA INDUSTRIAL',
                4 => 'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
                5 => 'MÁQUINAS ELÉCTRICAS',
                6 => 'SISTEMAS MECÁNICOS',
                7 => 'Laboratorio de Metrología y Control de Calidad',
                8 => 'Mantenimiento Mecánico',
                9 => 'Mantenimiento Edilicio',
                10 => 'Mantenimiento Eléctrico',
                11 => 'CAD CAM',
                12 => 'Desafío ECO',
                13 => 'Diseño de Procesos',
                14 => 'Motores de Combustión Interna 2',
                15 => 'Robótica Industrial',
                16 => 'CAD 3',
                17 => 'Centrales Eléctricas'
            ];
        default:
            return [];
    }
}

/**
 * Procesar importación masiva por año - CORREGIDO PARA MANEJAR MÚLTIPLES ARCHIVOS
 */
function procesarImportacionMasiva($archivos, $post, $db, $cicloLectivoId) {
    try {
        $anioSeleccionado = intval($post['anio_masivo']);
        $bimestre = isset($post['bimestre_masivo']) ? intval($post['bimestre_masivo']) : 1;
        $cursoId = intval($post['curso_masivo']);
        
        // Obtener mapeo de materias según el año
        $mapeoMaterias = obtenerMapeoMaterias($anioSeleccionado);
        
        if (empty($mapeoMaterias)) {
            return ['success' => false, 'message' => 'Mapeo de materias no disponible para este año'];
        }
        
        // Obtener todas las materias del curso
        $materiasDisponibles = $db->fetchAll(
            "SELECT mp.id, m.nombre, m.codigo
             FROM materias_por_curso mp 
             JOIN materias m ON mp.materia_id = m.id 
             WHERE mp.curso_id = ? 
             ORDER BY m.nombre",
            [$cursoId]
        );
        
        // Crear índice de materias por nombre para búsqueda rápida
        $indiceMaterias = [];
        foreach ($materiasDisponibles as $materia) {
            $nombreNormalizado = normalizarNombreMateria($materia['nombre']);
            $indiceMaterias[$nombreNormalizado] = $materia['id'];
            
            if (!empty($materia['codigo'])) {
                $indiceMaterias[strtoupper($materia['codigo'])] = $materia['id'];
            }
        }
        
        $resultados = [];
        $archivosExitosos = 0;
        $archivosConError = 0;
        $totalProcesados = 0;
        $totalOmitidos = 0;
        
        // Verificar estructura de archivos - CORREGIDO
        if (!isset($archivos['name']) || !is_array($archivos['name'])) {
            return ['success' => false, 'message' => 'No se recibieron archivos válidos'];
        }
        
        // Procesar cada archivo
        for ($indice = 0; $indice < count($archivos['name']); $indice++) {
            $nombreArchivo = $archivos['name'][$indice];
            
            if ($archivos['error'][$indice] !== UPLOAD_ERR_OK) {
                $resultados[] = "❌ $nombreArchivo: Error al subir archivo (código: {$archivos['error'][$indice]})";
                $archivosConError++;
                continue;
            }
            
            // Extraer número de materia del nombre del archivo
            if (preg_match('/\((\d+)\)\.csv$/i', $nombreArchivo, $matches)) {
                $numeroMateria = intval($matches[1]);
                
                if (!isset($mapeoMaterias[$numeroMateria])) {
                    $resultados[] = "⚠️ $nombreArchivo: Número de materia ($numeroMateria) no encontrado en el mapeo para {$anioSeleccionado}º año";
                    $archivosConError++;
                    continue;
                }
                
                $nombreMateriaBuscada = $mapeoMaterias[$numeroMateria];
                $nombreNormalizado = normalizarNombreMateria($nombreMateriaBuscada);
                
                if (!isset($indiceMaterias[$nombreNormalizado])) {
                    $resultados[] = "⚠️ $nombreArchivo: Materia '$nombreMateriaBuscada' no encontrada en el curso";
                    $archivosConError++;
                    continue;
                }
                
                $materiaId = $indiceMaterias[$nombreNormalizado];
                
                // Procesar el archivo CSV
                $rutaTemporal = $archivos['tmp_name'][$indice];
                $resultado = procesarArchivoHenryFord($rutaTemporal, $materiaId, $bimestre, $db, $cicloLectivoId);
                
                if ($resultado['success']) {
                    $resultados[] = "✅ $nombreArchivo ($nombreMateriaBuscada): " . $resultado['message'];
                    $archivosExitosos++;
                    
                    // Extraer estadísticas del mensaje
                    if (preg_match('/(\d+) registros procesados/', $resultado['message'], $matchProc)) {
                        $totalProcesados += intval($matchProc[1]);
                    }
                    if (preg_match('/(\d+) omitidos/', $resultado['message'], $matchOmit)) {
                        $totalOmitidos += intval($matchOmit[1]);
                    }
                } else {
                    $resultados[] = "❌ $nombreArchivo ($nombreMateriaBuscada): " . $resultado['message'];
                    $archivosConError++;
                }
            } else {
                $resultados[] = "⚠️ $nombreArchivo: Formato de nombre incorrecto. Use: {$anioSeleccionado}º AÑO(NUMERO).csv";
                $archivosConError++;
            }
        }
        
        // Preparar mensaje final
        $mensajeFinal = "IMPORTACIÓN MASIVA COMPLETADA - {$anioSeleccionado}º AÑO\n\n";
        $mensajeFinal .= "📊 RESUMEN:\n";
        $mensajeFinal .= "• Archivos exitosos: $archivosExitosos\n";
        $mensajeFinal .= "• Archivos con error: $archivosConError\n";
        $mensajeFinal .= "• Total estudiantes procesados: $totalProcesados\n";
        $mensajeFinal .= "• Total estudiantes omitidos: $totalOmitidos\n\n";
        $mensajeFinal .= "📋 DETALLE POR ARCHIVO:\n";
        $mensajeFinal .= implode("\n", $resultados);
        
        return [
            'success' => $archivosExitosos > 0,
            'message' => $mensajeFinal,
            'stats' => [
                'exitosos' => $archivosExitosos,
                'errores' => $archivosConError,
                'procesados' => $totalProcesados,
                'omitidos' => $totalOmitidos
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Error en procesarImportacionMasiva: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
    }
}

/**
 * Normalizar nombre de materia para comparación
 */
function normalizarNombreMateria($nombre) {
    $nombre = strtoupper($nombre);
    
    $reemplazos = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'DOMICILIARIAS' => 'DOMICILILARIAS',
        'ELECT.' => 'ELÉCTR.',
        'INST.' => 'INST',
        ' - ' => ' ',
        '  ' => ' '
    ];
    
    $nombre = str_replace(array_keys($reemplazos), array_values($reemplazos), $nombre);
    $nombre = preg_replace('/[^A-Z0-9\s]/', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    
    return trim($nombre);
}

/**
 * Función principal para procesar la importación - MEJORADA PARA SQLITE
 */
function procesarImportacion($archivo, $post, $db, $cicloLectivoId) {
    try {
        $materiaSeleccionada = intval($post['materia']);
        $tipoImportacion = $post['tipo_importacion'];
        $bimestre = isset($post['bimestre']) ? intval($post['bimestre']) : 1;
        
        // Validar archivo
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al subir el archivo: ' . $archivo['error']];
        }
        
        if ($archivo['size'] > 10 * 1024 * 1024) {
            return ['success' => false, 'message' => 'El archivo es demasiado grande (máximo 10MB)'];
        }
        
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        $rutaTemporal = $archivo['tmp_name'];
        
        if (!file_exists($rutaTemporal)) {
            return ['success' => false, 'message' => 'No se pudo acceder al archivo subido'];
        }
        
        switch ($extension) {
            case 'csv':
                return importarDesdeCSV($rutaTemporal, $materiaSeleccionada, $tipoImportacion, $bimestre, $db, $cicloLectivoId);
            
            case 'xlsx':
            case 'xls':
                return importarDesdeExcel($rutaTemporal, $materiaSeleccionada, $tipoImportacion, $bimestre, $db, $cicloLectivoId);
            
            case 'pdf':
                return analizarPDF($rutaTemporal, $materiaSeleccionada, $tipoImportacion, $bimestre, $db, $cicloLectivoId);
            
            default:
                return ['success' => false, 'message' => 'Formato de archivo no soportado. Use CSV, Excel o PDF'];
        }
    } catch (Exception $e) {
        error_log("Error en procesarImportacion: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
    }
}

/**
 * Importar desde CSV - OPTIMIZADO PARA FORMATO HENRY FORD
 */
function importarDesdeCSV($rutaArchivo, $materiaId, $tipoImportacion, $bimestre, $db, $cicloLectivoId) {
    if ($tipoImportacion === 'valoracion') {
        return procesarArchivoHenryFord($rutaArchivo, $materiaId, $bimestre, $db, $cicloLectivoId);
    }
    
    $contenido = file_get_contents($rutaArchivo);
    
    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', ['ISO-8859-1', 'Windows-1252', 'UTF-8']);
    }
    
    $lineas = str_getcsv($contenido, "\n");
    $header = str_getcsv(array_shift($lineas), ",");
    
    $mapeoColumnas = detectarColumnasHenryFord($header);
    
    if (!$mapeoColumnas) {
        return ['success' => false, 'message' => 'No se pudo detectar el formato del archivo CSV. Columnas encontradas: ' . implode(', ', $header)];
    }
    
    $datosImportados = [];
    $errores = [];
    
    foreach ($lineas as $numeroLinea => $linea) {
        if (empty(trim($linea))) continue;
        
        $campos = str_getcsv($linea, ",");
        $datoEstudiante = procesarLineaCSVHenryFord($campos, $mapeoColumnas, $numeroLinea + 2);
        
        if ($datoEstudiante['error']) {
            $errores[] = $datoEstudiante['error'];
        } else {
            $datosImportados[] = $datoEstudiante;
        }
    }
    
    if (count($errores) > 0 && count($datosImportados) === 0) {
        return ['success' => false, 'message' => 'Errores en el archivo: ' . implode(', ', $errores)];
    }
    
    return guardarDatosImportados($datosImportados, $materiaId, $tipoImportacion, $bimestre, $db, $cicloLectivoId);
}

/**
 * Importar desde Excel
 */
function importarDesdeExcel($rutaArchivo, $materiaId, $tipoImportacion, $bimestre, $db, $cicloLectivoId) {
    return ['success' => false, 'message' => 'Soporte para Excel no implementado. Por favor use formato CSV'];
}

/**
 * Analizar PDF - MEJORADO PARA PROCESAR DIRECTAMENTE
 */
function analizarPDF($rutaArchivo, $materiaId, $tipoImportacion, $bimestre, $db, $cicloLectivoId) {
    try {
        if (function_exists('shell_exec')) {
            $resultado = extraerTextoPdfToText($rutaArchivo);
            if ($resultado['success']) {
                return procesarTextoPDF($resultado['texto'], $materiaId, $bimestre, $db, $cicloLectivoId);
            }
        }
        
        return [
            'success' => false, 
            'message' => 'El PDF no se pudo procesar automáticamente. Use el convertidor manual o guarde como CSV.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al procesar PDF: ' . $e->getMessage()
        ];
    }
}

/**
 * Extraer texto usando pdftotext
 */
function extraerTextoPdfToText($rutaArchivo) {
    try {
        $comandos = [
            "pdftotext -layout " . escapeshellarg($rutaArchivo) . " -",
            "pdftotext -raw " . escapeshellarg($rutaArchivo) . " -",
            "pdftotext " . escapeshellarg($rutaArchivo) . " -"
        ];
        
        foreach ($comandos as $comando) {
            $output = @shell_exec($comando);
            
            if ($output && strlen(trim($output)) > 100) {
                return ['success' => true, 'texto' => $output];
            }
        }
        
        return ['success' => false, 'mensaje' => 'pdftotext no produjo resultado válido'];
    } catch (Exception $e) {
        return ['success' => false, 'mensaje' => $e->getMessage()];
    }
}

/**
 * Procesar texto extraído del PDF
 */
function procesarTextoPDF($texto, $materiaId, $bimestre, $db, $cicloLectivoId) {
    try {
        $lineas = explode("\n", $texto);
        $datosImportados = [];
        
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if (empty($linea)) continue;
            
            if (preg_match('/^(\d+)\s+([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ\s,\.]+?)\s+.*?(TEA|TEP|TED)\s+(B|MB|E|R|M)\s*(.*?)$/i', $linea, $matches)) {
                $datosImportados[] = procesarLineaEstudiantePDF($matches, 1);
            }
        }
        
        if (count($datosImportados) === 0) {
            return ['success' => false, 'message' => 'No se pudieron extraer datos válidos del PDF'];
        }
        
        return guardarDatosImportados($datosImportados, $materiaId, 'valoracion', $bimestre, $db, $cicloLectivoId);
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al procesar texto del PDF: ' . $e->getMessage()];
    }
}

/**
 * Procesar línea de estudiante desde PDF
 */
function procesarLineaEstudiantePDF($matches, $patron) {
    try {
        $numero = intval($matches[1]);
        $nombre = trim($matches[2]);
        $valoracion = strtoupper(trim($matches[3]));
        
        $dato = [
            'nombre' => $nombre,
            'valoracion' => $valoracion,
            'error' => null
        ];
        
        if (isset($matches[4])) {
            $mapeoDesempeno = [
                'E' => 'Excelente', 'MB' => 'Muy Bueno', 'B' => 'Bueno',
                'R' => 'Regular', 'M' => 'Malo'
            ];
            $dato['desempeno'] = isset($mapeoDesempeno[$matches[4]]) ? $mapeoDesempeno[$matches[4]] : $matches[4];
        }
        
        if (isset($matches[5]) && !empty(trim($matches[5]))) {
            $dato['observaciones'] = trim($matches[5]);
        }
        
        return $dato;
        
    } catch (Exception $e) {
        return ['error' => 'Error procesando línea: ' . $e->getMessage()];
    }
}

/**
 * Función específica para procesar archivos del formato Henry Ford - OPTIMIZADA
 */
function procesarArchivoHenryFord($rutaArchivo, $materiaId, $bimestre, $db, $cicloLectivoId) {
    $contenido = file_get_contents($rutaArchivo);
    
    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', ['ISO-8859-1', 'Windows-1252', 'UTF-8']);
    }
    
    $lineas = str_getcsv($contenido, "\n");
    
    $indiceHeader = -1;
    $header = null;
    $indiceInicioDatos = 0;
    
    for ($i = 0; $i < min(10, count($lineas)); $i++) {
        $lineaActual = str_getcsv($lineas[$i], ",");
        
        foreach ($lineaActual as $celda) {
            $celdaLimpia = strtolower(trim($celda));
            if (strpos($celdaLimpia, 'alumno') !== false || 
                strpos($celdaLimpia, 'nro') !== false ||
                strpos($celdaLimpia, 'estudiante') !== false ||
                strpos($celdaLimpia, 'nombre') !== false) {
                $header = $lineaActual;
                $indiceHeader = $i;
                $indiceInicioDatos = $i + 1;
                break 2;
            }
        }
    }
    
    if (!$header) {
        for ($i = 0; $i < min(5, count($lineas)); $i++) {
            $lineaActual = str_getcsv($lineas[$i], ",");
            
            if (count($lineaActual) >= 3) {
                $primerCampo = trim($lineaActual[0]);
                $segundoCampo = trim($lineaActual[1]);
                
                if (is_numeric($primerCampo) && !empty($segundoCampo) && 
                    preg_match('/[A-ZÁÉÍÓÚÑ]/i', $segundoCampo)) {
                    
                    $header = crearEncabezadoArtificialHenryFord($lineaActual);
                    $indiceHeader = -1;
                    $indiceInicioDatos = $i;
                    break;
                }
            }
        }
    }
    
    if (!$header) {
        return ['success' => false, 'message' => 'No se pudo detectar la estructura del archivo CSV'];
    }
    
    $lineas = array_slice($lineas, $indiceInicioDatos);
    $mapeoColumnas = detectarColumnasHenryFord($header);
    
    if (!$mapeoColumnas) {
        return ['success' => false, 'message' => 'No se pudo mapear las columnas del archivo CSV'];
    }
    
    $datosImportados = [];
    $errores = [];
    $lineasProcesadas = 0;
    
    foreach ($lineas as $numeroLinea => $linea) {
        if (empty(trim($linea))) continue;
        
        $campos = str_getcsv($linea, ",");
        
        if (count($campos) < 2) continue;
        
        $primerCampo = trim($campos[0]);
        
        if (!is_numeric($primerCampo) || count($campos) < 3) continue;
        
        $posibleNombre = isset($mapeoColumnas['nombre']) ? 
                        trim($campos[$mapeoColumnas['nombre']] ?? '') : 
                        trim($campos[1] ?? '');
        
        if (empty($posibleNombre) || is_numeric($posibleNombre) || strlen($posibleNombre) < 3) continue;
        
        $datoEstudiante = procesarLineaCSVHenryFord($campos, $mapeoColumnas, $indiceInicioDatos + $numeroLinea + 1);
        
        if ($datoEstudiante['error']) {
            $errores[] = $datoEstudiante['error'];
        } else {
            $datosImportados[] = $datoEstudiante;
            $lineasProcesadas++;
        }
    }
    
    if (count($datosImportados) === 0) {
        return ['success' => false, 'message' => 'No se encontraron datos válidos para importar'];
    }
    
    return guardarDatosImportados($datosImportados, $materiaId, 'valoracion', $bimestre, $db, $cicloLectivoId);
}

/**
 * Crear encabezado artificial basado en la estructura detectada
 */
function crearEncabezadoArtificialHenryFord($lineaEjemplo) {
    $encabezado = [];
    $numColumnas = count($lineaEjemplo);
    
    for ($i = 0; $i < $numColumnas; $i++) {
        switch ($i) {
            case 0:
                $encabezado[] = 'Nro';
                break;
            case 1:
                $encabezado[] = 'Alumno';
                break;
            default:
                $valor = trim($lineaEjemplo[$i]);
                
                if ($i >= 2 && $i <= 21) {
                    $encabezado[] = 'Contenido_' . ($i - 1);
                } elseif ($i == 22) {
                    $encabezado[] = 'Total_Contenidos';
                } elseif (in_array(strtoupper($valor), ['TEA', 'TEP', 'TED'])) {
                    $encabezado[] = 'Valoración preliminar de la trayectoria';
                } elseif (in_array(strtoupper($valor), ['B', 'MB', 'E', 'R', 'M'])) {
                    $encabezado[] = 'Desempeño académico del bimestre';
                } elseif (strlen($valor) > 10 && !in_array(strtoupper($valor), ['A', '0'])) {
                    $encabezado[] = 'Observaciones';
                } else {
                    $encabezado[] = 'Campo_' . $i;
                }
                break;
        }
    }
    
    return $encabezado;
}

/**
 * Detectar columnas en el archivo
 */
function detectarColumnasHenryFord($header) {
    $mapeo = [];
    
    $mapeo['numero'] = 0;
    $mapeo['nombre'] = 1;
    
    for ($indice = 0; $indice < count($header); $indice++) {
        $columna = trim($header[$indice]);
        $columnaLimpia = strtolower($columna);
        
        if (strpos($columnaLimpia, 'valoracion preliminar') !== false || 
            strpos($columnaLimpia, 'valoración preliminar') !== false ||
            strpos($columnaLimpia, 'valoracion') !== false || 
            strpos($columnaLimpia, 'valoración') !== false ||
            strpos($columnaLimpia, 'trayectoria') !== false ||
            in_array(strtoupper($columna), ['TEA', 'TEP', 'TED']) ||
            ($indice >= 20 && in_array(strtoupper($columna), ['TEA', 'TEP', 'TED']))) {
            $mapeo['valoracion'] = $indice;
        }
        
        elseif (strpos($columnaLimpia, 'desempeño') !== false || 
                strpos($columnaLimpia, 'desempeno') !== false ||
                strpos($columnaLimpia, 'desempeño academico') !== false ||
                strpos($columnaLimpia, 'desempeño académico') !== false ||
                in_array(strtoupper($columna), ['B', 'MB', 'E', 'R', 'M', 'BUENO', 'MUY BUENO', 'EXCELENTE']) ||
                ($indice >= 20 && in_array(strtoupper($columna), ['B', 'MB', 'E', 'R', 'M']))) {
            $mapeo['desempeno'] = $indice;
        }
        
        elseif (strpos($columnaLimpia, 'observacion') !== false || 
                strpos($columnaLimpia, 'observación') !== false ||
                strpos($columnaLimpia, 'comentario') !== false ||
                ($indice >= 23 && strlen($columna) > 10 && !in_array(strtoupper($columna), ['A', '0', 'TEA', 'TEP', 'TED', 'B', 'MB']))) {
            $mapeo['observaciones'] = $indice;
        }
    }
    
    if (!isset($mapeo['valoracion'])) {
        for ($i = 20; $i < min(count($header), 26); $i++) {
            if (isset($header[$i])) {
                $valor = strtoupper(trim($header[$i]));
                if (in_array($valor, ['TEA', 'TEP', 'TED'])) {
                    $mapeo['valoracion'] = $i;
                    break;
                }
            }
        }
    }
    
    if (!isset($mapeo['desempeno']) && isset($mapeo['valoracion'])) {
        $posicionDesempeno = $mapeo['valoracion'] + 1;
        if ($posicionDesempeno < count($header)) {
            $mapeo['desempeno'] = $posicionDesempeno;
        }
    }
    
    if (!isset($mapeo['observaciones']) && isset($mapeo['desempeno'])) {
        $posicionObservaciones = $mapeo['desempeno'] + 1;
        if ($posicionObservaciones < count($header)) {
            $mapeo['observaciones'] = $posicionObservaciones;
        }
    }
    
    if (!isset($mapeo['nombre'])) {
        return false;
    }
    
    return $mapeo;
}

/**
 * Procesar línea individual del CSV - OPTIMIZADO PARA FORMATO HENRY FORD CON SUBGRUPOS
 */
function procesarLineaCSVHenryFord($campos, $mapeoColumnas, $numeroLinea) {
    $dato = ['error' => null];
    
    if (count($campos) < 5) {
        return ['error' => "Línea $numeroLinea: Formato inválido, muy pocos campos"];
    }
    
    $numero = trim($campos[0]);
    if (!is_numeric($numero)) {
        return ['error' => "Línea $numeroLinea: Número de estudiante inválido"];
    }
    
    $nombre = trim($campos[1]);
    $nombre = trim($nombre, '"');
    
    if (empty($nombre)) {
        return ['error' => "Línea $numeroLinea: Nombre de estudiante vacío"];
    }
    $dato['nombre'] = $nombre;
    
    $valoracion = null;
    $posicionValoracion = -1;
    
    for ($i = 20; $i < count($campos); $i++) {
        $valor = trim(strtoupper($campos[$i]));
        if (in_array($valor, ['TEA', 'TEP', 'TED'])) {
            $valoracion = $valor;
            $posicionValoracion = $i;
            break;
        }
    }
    
    if (!$valoracion) {
        for ($i = 2; $i < count($campos); $i++) {
            $valor = trim(strtoupper($campos[$i]));
            if (in_array($valor, ['TEA', 'TEP', 'TED'])) {
                $valoracion = $valor;
                $posicionValoracion = $i;
                break;
            }
        }
    }
    
    // NUEVA LÓGICA PARA SUBGRUPOS
    if (!$valoracion) {
        $tieneDatos = false;
        
        for ($i = 2; $i < count($campos); $i++) {
            $valor = trim($campos[$i]);
            
            if (!empty($valor) && $valor !== '0' && $valor !== '-' && $valor !== 'A') {
                if (strlen($valor) > 1 && !is_numeric($valor)) {
                    $tieneDatos = true;
                    break;
                }
            }
        }
        
        if (!$tieneDatos) {
            return [
                'nombre' => $nombre,
                'no_cursa' => true,
                'motivo' => 'Sin valoración - no cursa esta materia',
                'error' => null
            ];
        } else {
            return ['error' => "Línea $numeroLinea: No se encontró valoración (TEA/TEP/TED) para " . $nombre . " pero tiene otros datos"];
        }
    }
    
    $dato['valoracion'] = $valoracion;
    
    $desempeno = null;
    if ($posicionValoracion >= 0 && $posicionValoracion + 1 < count($campos)) {
        $valorDesempeno = trim(strtoupper($campos[$posicionValoracion + 1]));
        
        $mapeoDesempeno = [
            'E' => 'Excelente',
            'MB' => 'Muy Bueno', 
            'B' => 'Bueno',
            'R' => 'Regular',
            'M' => 'Malo',
            'EXCELENTE' => 'Excelente',
            'MUY BUENO' => 'Muy Bueno',
            'BUENO' => 'Bueno',
            'REGULAR' => 'Regular',
            'MALO' => 'Malo'
        ];
        
        if (isset($mapeoDesempeno[$valorDesempeno])) {
            $desempeno = $mapeoDesempeno[$valorDesempeno];
        } elseif (!empty($valorDesempeno) && strlen($valorDesempeno) <= 10) {
            $desempeno = $valorDesempeno;
        }
    }
    
    if (!$desempeno && $posicionValoracion >= 0) {
        for ($offset = -1; $offset <= 2; $offset++) {
            $pos = $posicionValoracion + $offset;
            if ($pos >= 0 && $pos < count($campos) && $pos !== $posicionValoracion) {
                $valor = trim(strtoupper($campos[$pos]));
                if (in_array($valor, ['E', 'MB', 'B', 'R', 'M', 'EXCELENTE', 'MUY BUENO', 'BUENO', 'REGULAR', 'MALO'])) {
                    $mapeoDesempeno = [
                        'E' => 'Excelente', 'MB' => 'Muy Bueno', 'B' => 'Bueno',
                        'R' => 'Regular', 'M' => 'Malo', 'EXCELENTE' => 'Excelente',
                        'MUY BUENO' => 'Muy Bueno', 'BUENO' => 'Bueno',
                        'REGULAR' => 'Regular', 'MALO' => 'Malo'
                    ];
                    $desempeno = isset($mapeoDesempeno[$valor]) ? $mapeoDesempeno[$valor] : $valor;
                    break;
                }
            }
        }
    }
    
    if (!$desempeno) {
        $desempeno = 'Bueno';
    }
    
    $dato['desempeno'] = $desempeno;
    
    $observaciones = '';
    if ($posicionValoracion >= 0) {
        for ($i = $posicionValoracion + 2; $i < count($campos); $i++) {
            $valor = trim($campos[$i]);
            
            if (!empty($valor) && $valor !== '0' && $valor !== '-' && 
                !in_array(strtoupper($valor), ['A', '0', 'TEA', 'TEP', 'TED', 'B', 'MB', 'E', 'R', 'M']) &&
                strlen($valor) > 2) {
                $observaciones = $valor;
                break;
            }
        }
    }
    
    if (!empty($observaciones)) {
        $dato['observaciones'] = $observaciones;
    }
    
    return $dato;
}

/**
 * Guardar datos importados en la base de datos - OPTIMIZADO PARA SQLITE CON SUBGRUPOS
 */
function guardarDatosImportados($datosImportados, $materiaId, $tipoImportacion, $bimestre, $db, $cicloLectivoId) {
    $procesados = 0;
    $errores = [];
    $nuevos = 0;
    $actualizados = 0;
    $omitidos = 0;
    
    try {
        $db->query("BEGIN TRANSACTION");
        
        foreach ($datosImportados as $dato) {
            try {
                if (isset($dato['no_cursa']) && $dato['no_cursa'] === true) {
                    $omitidos++;
                    error_log("SUBGRUPO: Omitido " . $dato['nombre'] . " - " . $dato['motivo']);
                    continue;
                }
                
                $estudiante = buscarEstudiante($db, $dato['nombre'], $dato['dni'] ?? null, $materiaId);
                
                if (!$estudiante) {
                    $errores[] = "No se encontró estudiante: " . $dato['nombre'];
                    continue;
                }
                
                $calificacionExistente = $db->fetchOne(
                    "SELECT * FROM calificaciones 
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                    [$estudiante['id'], $materiaId, $cicloLectivoId]
                );
                
                if ($tipoImportacion === 'valoracion') {
                    $campos = [];
                    
                    if (isset($dato['valoracion'])) {
                        $campos['valoracion_' . $bimestre . 'bim'] = $dato['valoracion'];
                        $campoPreliminnar = ($bimestre == 1) ? 'valoracion_preliminar_1c' : 'valoracion_preliminar_2c';
                        $campos[$campoPreliminnar] = $dato['valoracion'];
                    }
                    
                    if (isset($dato['desempeno'])) {
                        $campos['desempeno_' . $bimestre . 'bim'] = $dato['desempeno'];
                    }
                    
                    if (isset($dato['observaciones'])) {
                        $campos['observaciones_' . $bimestre . 'bim'] = $dato['observaciones'];
                    }
                    
                } else {
                    $campos = [];
                    
                    if (isset($dato['calificacion'])) {
                        $campos['calificacion_final'] = $dato['calificacion'];
                        $campos['estado_final'] = ($dato['calificacion'] >= 4) ? 'aprobada' : 'pendiente';
                    }
                    
                    if (isset($dato['observaciones'])) {
                        $campos['observaciones'] = $dato['observaciones'];
                    }
                    
                    if (isset($dato['valoracion'])) {
                        $campos['valoracion_preliminar_1c'] = $dato['valoracion'];
                        $campos['valoracion_preliminar_2c'] = $dato['valoracion'];
                    }
                }
                
                if (empty($campos)) {
                    $errores[] = "Sin datos válidos para: " . $dato['nombre'];
                    continue;
                }
                
                if ($calificacionExistente) {
                    $setClauses = [];
                    $valoresUpdate = [];
                    
                    foreach ($campos as $campo => $valor) {
                        $setClauses[] = "$campo = ?";
                        $valoresUpdate[] = $valor;
                    }
                    
                    $valoresUpdate[] = $calificacionExistente['id'];
                    $sql = "UPDATE calificaciones SET " . implode(', ', $setClauses) . " WHERE id = ?";
                    $db->query($sql, $valoresUpdate);
                    $actualizados++;
                    
                } else {
                    $camposInsert = ['estudiante_id', 'materia_curso_id', 'ciclo_lectivo_id'];
                    $valoresInsert = [$estudiante['id'], $materiaId, $cicloLectivoId];
                    
                    foreach ($campos as $campo => $valor) {
                        $camposInsert[] = $campo;
                        $valoresInsert[] = $valor;
                    }
                    
                    $placeholders = str_repeat('?,', count($valoresInsert) - 1) . '?';
                    $sql = "INSERT INTO calificaciones (" . implode(', ', $camposInsert) . ") VALUES ($placeholders)";
                    $db->query($sql, $valoresInsert);
                    $nuevos++;
                }
                
                $procesados++;
                
            } catch (Exception $e) {
                $errores[] = "Error con " . $dato['nombre'] . ": " . $e->getMessage();
            }
        }
        
        $db->query("COMMIT");
        
        $mensaje = "Importación completada: $procesados registros procesados";
        if ($nuevos > 0) $mensaje .= ", $nuevos nuevos";
        if ($actualizados > 0) $mensaje .= ", $actualizados actualizados";
        if ($omitidos > 0) $mensaje .= ", $omitidos omitidos (no cursan esta materia)";
        if (count($errores) > 0) {
            $mensaje .= ". Errores (" . count($errores) . "): " . implode(', ', array_slice($errores, 0, 3));
            if (count($errores) > 3) $mensaje .= "...";
        }
        
        return ['success' => true, 'message' => $mensaje];
        
    } catch (Exception $e) {
        try {
            $db->query("ROLLBACK");
        } catch (Exception $rollbackError) {
            error_log("Error en rollback: " . $rollbackError->getMessage());
        }
        
        return ['success' => false, 'message' => 'Error al guardar datos: ' . $e->getMessage()];
    }
}

/**
 * Buscar estudiante en la base de datos
 */
function buscarEstudiante($db, $nombre, $dni, $materiaId) {
    $nombreLimpio = limpiarNombre($nombre);
    
    if ($dni && !empty(trim($dni))) {
        $estudiante = $db->fetchOne(
            "SELECT u.* FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             JOIN materias_por_curso mp ON m.curso_id = mp.curso_id
             WHERE u.dni = ? AND mp.id = ? AND u.tipo = 'estudiante'",
            [$dni, $materiaId]
        );
        
        if ($estudiante) return $estudiante;
    }
    
    $estudiantes = $db->fetchAll(
        "SELECT u.*, 
                CASE 
                    WHEN mr.id IS NOT NULL THEN 'recursando'
                    ELSE 'regular'
                END as tipo_matricula
         FROM usuarios u 
         LEFT JOIN matriculas m ON u.id = m.estudiante_id 
         LEFT JOIN materias_por_curso mp ON m.curso_id = mp.curso_id
         LEFT JOIN materias_recursado mr ON u.id = mr.estudiante_id AND mr.materia_curso_id = mp.id
         WHERE (mp.id = ? OR mr.materia_curso_id = ?) AND u.tipo = 'estudiante'
         AND (m.estado = 'activo' OR mr.estado = 'activo')",
        [$materiaId, $materiaId]
    );
    
    $mejorCoincidencia = null;
    $mejorPorcentaje = 0;
    
    foreach ($estudiantes as $estudiante) {
        $variacionesNombre = [
            $estudiante['apellido'] . ', ' . $estudiante['nombre'],
            $estudiante['apellido'] . ' ' . $estudiante['nombre'],
            $estudiante['nombre'] . ' ' . $estudiante['apellido'],
            strtoupper($estudiante['apellido'] . ', ' . $estudiante['nombre']),
            strtoupper($estudiante['apellido'] . ' ' . $estudiante['nombre'])
        ];
        
        foreach ($variacionesNombre as $variacion) {
            $variacionLimpia = limpiarNombre($variacion);
            
            similar_text($nombreLimpio, $variacionLimpia, $porcentaje);
            
            if ($porcentaje > $mejorPorcentaje && $porcentaje > 75) {
                $mejorPorcentaje = $porcentaje;
                $mejorCoincidencia = $estudiante;
            }
            
            if ($nombreLimpio === $variacionLimpia) {
                return $estudiante;
            }
        }
        
        $apellidosEstudiante = explode(' ', strtolower($estudiante['apellido']));
        $nombreCompleto = strtolower($nombre);
        
        $coincidenciasApellido = 0;
        foreach ($apellidosEstudiante as $apellido) {
            if (strlen($apellido) > 3 && strpos($nombreCompleto, $apellido) !== false) {
                $coincidenciasApellido++;
            }
        }
        
        if ($coincidenciasApellido >= 1 && strpos($nombreCompleto, strtolower($estudiante['nombre'])) !== false) {
            return $estudiante;
        }
    }
    
    if ($mejorCoincidencia && $mejorPorcentaje > 85) {
        error_log("Coincidencia encontrada: {$nombre} -> {$mejorCoincidencia['apellido']}, {$mejorCoincidencia['nombre']} ({$mejorPorcentaje}%)");
        return $mejorCoincidencia;
    }
    
    error_log("No se encontró estudiante para: {$nombre}");
    
    return null;
}

/**
 * Limpiar nombre para comparación
 */
function limpiarNombre($nombre) {
    $nombre = strtolower($nombre);
    
    $reemplazos = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ç' => 'c'
    ];
    
    $nombre = str_replace(array_keys($reemplazos), array_values($reemplazos), $nombre);
    $nombre = preg_replace('/[^a-z0-9\s,]/', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    
    return trim($nombre);
}

?>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">
                    <i class="bi bi-upload"></i> Importar Calificaciones desde Archivos
                </h5>
                <p class="card-text">
                    Importe calificaciones desde archivos CSV, Excel o analice PDFs de boletines anteriores
                </p>
            </div>
            <div class="card-body">
                
                <!-- Información sobre formatos soportados -->
                <div class="alert alert-info">
                    <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Formatos Soportados</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>CSV:</strong>
                            <ul class="small mb-0">
                                <li>Debe incluir columna de nombres/estudiantes</li>
                                <li>Columnas de valoración (TEA/TEP/TED)</li>
                                <li>Opcionalmente: DNI, desempeño, observaciones</li>
                                <li>Calificaciones numéricas (1-10)</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <strong>PDF:</strong>
                            <ul class="small mb-0">
                                <li>Se analizará el contenido automáticamente</li>
                                <li>Para mejores resultados, convierta a CSV</li>
                                <li>Soporta formatos de boletín estándar</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <strong>Ejemplo CSV:</strong>
                            <small>
                                <code>
                                Nro,Alumno,Valoración,Desempeño,Observaciones<br>
                                1,"ACOSTA, Alma",TEP,B,"Se sugiere..."<br>
                                2,"ALITTA, Renzo",TEA,B,""
                                </code>
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Formulario de selección -->
                <form method="GET" class="mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <label for="curso" class="form-label">Seleccione Curso:</label>
                            <select name="curso" id="curso" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($curso['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($cursoSeleccionado): ?>
                        <div class="col-md-6">
                            <label for="materia_preview" class="form-label">Materias Disponibles:</label>
                            <select id="materia_preview" class="form-select" disabled>
                                <option value="">-- Materias disponibles para importación --</option>
                                <?php foreach ($materias as $materia): ?>
                                <option value="<?= $materia['id'] ?>">
                                    <?= htmlspecialchars($materia['nombre']) ?> (<?= htmlspecialchars($materia['codigo']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Seleccione la materia en el formulario de importación abajo</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
                
                <?php if ($cursoSeleccionado && count($materias) > 0): ?>
                
                <!-- Formulario de importación individual -->
                <form method="POST" enctype="multipart/form-data" class="border rounded p-4 bg-light">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="materia" class="form-label">Materia:</label>
                            <select name="materia" id="materia" class="form-select" required>
                                <option value="">-- Seleccione materia --</option>
                                <?php foreach ($materias as $materia): ?>
                                <option value="<?= $materia['id'] ?>">
                                    <?= htmlspecialchars($materia['nombre']) ?> (<?= htmlspecialchars($materia['codigo']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="tipo_importacion" class="form-label">Tipo de Datos:</label>
                            <select name="tipo_importacion" id="tipo_importacion" class="form-select" required>
                                <option value="valoracion">Valoraciones Preliminares (Bimestrales)</option>
                                <option value="cuatrimestre">Calificaciones Cuatrimestrales</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3" id="div_bimestre">
                        <div class="col-md-6">
                            <label for="bimestre" class="form-label">Bimestre:</label>
                            <select name="bimestre" id="bimestre" class="form-select">
                                <option value="1">1er Bimestre</option>
                                <option value="3">3er Bimestre</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="archivo_importacion" class="form-label">Archivo a Importar:</label>
                        <input type="file" name="archivo_importacion" id="archivo_importacion" 
                               class="form-control" accept=".csv,.xlsx,.xls,.pdf" required>
                        <div class="form-text">
                            Formatos soportados: CSV, Excel (.xlsx, .xls), PDF. Tamaño máximo: 10MB
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="sobrescribir" name="sobrescribir" value="1">
                            <label class="form-check-label" for="sobrescribir">
                                Sobrescribir datos existentes
                            </label>
                            <div class="form-text">
                                Si está marcado, se actualizarán las calificaciones existentes. 
                                Si no, solo se cargarán estudiantes sin calificaciones previas.
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-upload"></i> Importar Calificaciones
                        </button>
                    </div>
                </form>
                
                <!-- Separador -->
                <hr class="my-5">
                
                <!-- Importación Masiva -->
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-file-earmark-zip"></i> Importación Masiva por Año
                        </h5>
                        <p class="card-text mb-0">
                            Suba múltiples archivos CSV de un año completo para procesamiento automático
                        </p>
                    </div>
                    <div class="card-body">
                        
                        <!-- Información sobre el formato -->
                        <div class="alert alert-info">
                            <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Formato de Archivos para Importación Masiva</h6>
                            
                            <!-- Pestañas para diferentes años -->
                            <ul class="nav nav-tabs" id="tabsAnios" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="tab-3" data-bs-toggle="tab" data-bs-target="#mapeo-3" type="button" role="tab">3º Año</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-4" data-bs-toggle="tab" data-bs-target="#mapeo-4" type="button" role="tab">4º Año</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-5" data-bs-toggle="tab" data-bs-target="#mapeo-5" type="button" role="tab">5º Año</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-6" data-bs-toggle="tab" data-bs-target="#mapeo-6" type="button" role="tab">6º Año</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="tab-7" data-bs-toggle="tab" data-bs-target="#mapeo-7" type="button" role="tab">7º Año</button>
                                </li>
                            </ul>
                            
                            <!-- Contenido de las pestañas -->
                            <div class="tab-content mt-3" id="contenidoTabsAnios">
                                
                                <!-- 3er Año -->
                                <div class="tab-pane fade show active" id="mapeo-3" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Nomenclatura:</strong> <code>3º AÑO(NUMERO).csv</code>
                                            <div style="max-height: 300px; overflow-y: auto;" class="small mt-2">
                                                <table class="table table-sm table-striped">
                                                    <thead><tr><th>N°</th><th>Materia</th></tr></thead>
                                                    <tbody>
                                                        <tr><td>1</td><td>BIOLOGÍA</td></tr>
                                                        <tr><td>2</td><td>CONSTR. DE CIUD. - Maderas</td></tr>
                                                        <tr><td>3</td><td>CONSTR. DE CIUD. - Metales</td></tr>
                                                        <tr><td>4</td><td>CONSTR. DE CIUD. - Electricidad</td></tr>
                                                        <tr><td>5</td><td>EDUCACIÓN ARTÍSTICA - Plástica</td></tr>
                                                        <tr><td>6</td><td>EDUCACIÓN ARTÍSTICA - Música</td></tr>
                                                        <tr><td>7</td><td>EDUCACIÓN FÍSICA</td></tr>
                                                        <tr><td>8</td><td>FÍSICO QUÍMICA</td></tr>
                                                        <tr><td>9</td><td>GEOGRAFÍA</td></tr>
                                                        <tr><td>10</td><td>HISTORIA</td></tr>
                                                        <tr><td>11</td><td>INGLÉS</td></tr>
                                                        <tr><td>12</td><td>MATEMÁTICA</td></tr>
                                                        <tr><td>13</td><td>PRÁCTICAS DEL LENGUAJE</td></tr>
                                                        <tr><td>14</td><td>3PT1 - Metales 3</td></tr>
                                                        <tr><td>15</td><td>3PT2 - Fundición</td></tr>
                                                        <tr><td>16</td><td>3PT3 - Tecnología 3</td></tr>
                                                        <tr><td>17</td><td>3LT1 - Dibujo Técnico 3</td></tr>
                                                        <tr><td>18</td><td>3LT2 - Informática 3</td></tr>
                                                        <tr><td>19</td><td>3ST1 - Inst. Eléctr. Domicililarias 1</td></tr>
                                                        <tr><td>20</td><td>3ST2 - Fluidos</td></tr>
                                                        <tr><td>21</td><td>3ST3 - Proyecto Tecnológico 3</td></tr>
                                                        <tr><td>22</td><td>3ST4 - Robótica 3</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Ejemplos de archivos:</strong>
                                            <ul class="small">
                                                <li><code>3º AÑO(1).csv</code> → Biología</li>
                                                <li><code>3º AÑO(12).csv</code> → Matemática</li>
                                                <li><code>3º AÑO(22).csv</code> → 3ST4 - Robótica 3</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- 4to Año -->
                                <div class="tab-pane fade" id="mapeo-4" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Nomenclatura:</strong> <code>4º AÑO(NUMERO).csv</code>
                                            <div style="max-height: 300px; overflow-y: auto;" class="small mt-2">
                                                <table class="table table-sm table-striped">
                                                    <thead><tr><th>N°</th><th>Materia</th></tr></thead>
                                                    <tbody>
                                                        <tr><td>1</td><td>LITERATURA</td></tr>
                                                        <tr><td>2</td><td>INGLÉS</td></tr>
                                                        <tr><td>3</td><td>EDUCACIÓN FÍSICA</td></tr>
                                                        <tr><td>4</td><td>SALUD Y ADOLESCENCIA</td></tr>
                                                        <tr><td>5</td><td>HISTORIA</td></tr>
                                                        <tr><td>6</td><td>GEOGRAFÍA</td></tr>
                                                        <tr><td>7</td><td>MATEMÁTICA</td></tr>
                                                        <tr><td>8</td><td>FÍSICA</td></tr>
                                                        <tr><td>9</td><td>QUÍMICA</td></tr>
                                                        <tr><td>10</td><td>CONOCIMIENTO DE LOS MATERIALES</td></tr>
                                                        <tr><td>11</td><td>4DT1 - Dibujo Técnológico</td></tr>
                                                        <tr><td>12</td><td>4DT2 - Dibujo con Autocad</td></tr>
                                                        <tr><td>13</td><td>4MEA1 - Electrónica Básica</td></tr>
                                                        <tr><td>14</td><td>4MEA2 - Transformadores</td></tr>
                                                        <tr><td>15</td><td>4MEA3 - Principios de Automatización</td></tr>
                                                        <tr><td>16</td><td>4DPM1 - Metrología</td></tr>
                                                        <tr><td>17</td><td>4DPM2 - Mecanizado 1</td></tr>
                                                        <tr><td>18</td><td>4DPM3 - Mecanizado 2</td></tr>
                                                        <tr><td>19</td><td>4IAE1 - Instalaciones Eléctricas Domicililarias 2</td></tr>
                                                        <tr><td>20</td><td>4IAE2 - Soldadura</td></tr>
                                                        <tr><td>21</td><td>4IAE3 - Electrotecnia</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Ejemplos de archivos:</strong>
                                            <ul class="small">
                                                <li><code>4º AÑO(1).csv</code> → Literatura</li>
                                                <li><code>4º AÑO(7).csv</code> → Matemática</li>
                                                <li><code>4º AÑO(21).csv</code> → 4IAE3 - Electrotecnia</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- 5to Año -->
                                <div class="tab-pane fade" id="mapeo-5" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Nomenclatura:</strong> <code>5º AÑO(NUMERO).csv</code>
                                            <div style="max-height: 300px; overflow-y: auto;" class="small mt-2">
                                                <table class="table table-sm table-striped">
                                                    <thead><tr><th>N°</th><th>Materia</th></tr></thead>
                                                    <tbody>
                                                        <tr><td>1</td><td>LITERATURA</td></tr>
                                                        <tr><td>2</td><td>INGLÉS</td></tr>
                                                        <tr><td>3</td><td>EDUCACIÓN FÍSICA</td></tr>
                                                        <tr><td>4</td><td>POLÍTICA Y CIUDADANÍA</td></tr>
                                                        <tr><td>5</td><td>HISTORIA</td></tr>
                                                        <tr><td>6</td><td>GEOGRAFÍA</td></tr>
                                                        <tr><td>7</td><td>ANALISIS MATEMÁTICO</td></tr>
                                                        <tr><td>8</td><td>MECÁNICA Y MECANISMOS</td></tr>
                                                        <tr><td>9</td><td>ELECTROTECNIA</td></tr>
                                                        <tr><td>10</td><td>RESISTENCIA Y ENSAYO DE MATERIALES</td></tr>
                                                        <tr><td>11</td><td>5MEA1 - Contactores</td></tr>
                                                        <tr><td>12</td><td>5MEA2 - Electrónica Digital</td></tr>
                                                        <tr><td>13</td><td>5MEA3 - Motores Eléctricos</td></tr>
                                                        <tr><td>14</td><td>5DPM1 - Mecanizado 3</td></tr>
                                                        <tr><td>15</td><td>5DPM2 - Metalografía y Tratamientos Térmicos</td></tr>
                                                        <tr><td>16</td><td>5DPM3 - CAD 1</td></tr>
                                                        <tr><td>17</td><td>5IAE1 - Control de Fluidos 1</td></tr>
                                                        <tr><td>18</td><td>5IAE2 - Control de Fluidos 2</td></tr>
                                                        <tr><td>19</td><td>5IAE3 - Refrigeración</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Ejemplos de archivos:</strong>
                                            <ul class="small">
                                                <li><code>5º AÑO(1).csv</code> → Literatura</li>
                                                <li><code>5º AÑO(7).csv</code> → Análisis Matemático</li>
                                                <li><code>5º AÑO(19).csv</code> → 5IAE3 - Refrigeración</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- 6to Año -->
                                <div class="tab-pane fade" id="mapeo-6" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Nomenclatura:</strong> <code>6º AÑO(NUMERO).csv</code>
                                            <div style="max-height: 300px; overflow-y: auto;" class="small mt-2">
                                                <table class="table table-sm table-striped">
                                                    <thead><tr><th>N°</th><th>Materia</th></tr></thead>
                                                    <tbody>
                                                        <tr><td>1</td><td>LITERATURA</td></tr>
                                                        <tr><td>2</td><td>INGLÉS</td></tr>
                                                        <tr><td>3</td><td>EDUCACIÓN FÍSICA</td></tr>
                                                        <tr><td>4</td><td>FILOSOFÍA</td></tr>
                                                        <tr><td>5</td><td>ARTE</td></tr>
                                                        <tr><td>6</td><td>MATEMÁTICA APLICADA</td></tr>
                                                        <tr><td>7</td><td>ELECTROTECNIA</td></tr>
                                                        <tr><td>8</td><td>TERMODINÁMICA Y MÁQUINAS TÉRMICAS</td></tr>
                                                        <tr><td>9</td><td>SISTEMAS MECÁNICOS</td></tr>
                                                        <tr><td>10</td><td>DERECHOS DEL TRABAJO</td></tr>
                                                        <tr><td>11</td><td>6LME - Laboratorio de Mediciones Eléctricas</td></tr>
                                                        <tr><td>12</td><td>6MEA1 - Microcontroladores</td></tr>
                                                        <tr><td>13</td><td>6MEA2 - PLC</td></tr>
                                                        <tr><td>14</td><td>6MEA3 - Sistemas de Control</td></tr>
                                                        <tr><td>15</td><td>6DPM1 - CNC Torno</td></tr>
                                                        <tr><td>16</td><td>6DPM2 - CAD 2</td></tr>
                                                        <tr><td>17</td><td>6DPM3 - CNC Fresadora</td></tr>
                                                        <tr><td>18</td><td>6IAE1 - Motores de Combustión Interna 1</td></tr>
                                                        <tr><td>19</td><td>6IAE2 - Luminotecnia</td></tr>
                                                        <tr><td>20</td><td>6IAE3 - Distribución de Energía Eléctrica</td></tr>
                                                        <tr><td>21</td><td>6IAE4 - MASTER</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Ejemplos de archivos:</strong>
                                            <ul class="small">
                                                <li><code>6º AÑO(1).csv</code> → Literatura</li>
                                                <li><code>6º AÑO(6).csv</code> → Matemática Aplicada</li>
                                                <li><code>6º AÑO(21).csv</code> → 6IAE4 - MASTER</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- 7mo Año -->
                                <div class="tab-pane fade" id="mapeo-7" role="tabpanel">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Nomenclatura:</strong> <code>7º AÑO(NUMERO).csv</code>
                                            <div style="max-height: 300px; overflow-y: auto;" class="small mt-2">
                                                <table class="table table-sm table-striped">
                                                    <thead><tr><th>N°</th><th>Materia</th></tr></thead>
                                                    <tbody>
                                                        <tr><td>1</td><td>PRÁCTICAS PROFESIONALIZANTES</td></tr>
                                                        <tr><td>2</td><td>EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL</td></tr>
                                                        <tr><td>3</td><td>ELECTRÓNICA INDUSTRIAL</td></tr>
                                                        <tr><td>4</td><td>SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE</td></tr>
                                                        <tr><td>5</td><td>MÁQUINAS ELÉCTRICAS</td></tr>
                                                        <tr><td>6</td><td>SISTEMAS MECÁNICOS</td></tr>
                                                        <tr><td>7</td><td>7LMCC - Laboratorio de Metrología y Control de Calidad</td></tr>
                                                        <tr><td>8</td><td>7MME1 - Mantenimiento Mecánico</td></tr>
                                                        <tr><td>9</td><td>7MME2 - Mantenimiento Edilicio</td></tr>
                                                        <tr><td>10</td><td>7MME3 - Mantenimiento Eléctrico</td></tr>
                                                        <tr><td>11</td><td>7PDE1 - CAD CAM</td></tr>
                                                        <tr><td>12</td><td>7PDE2 - Desafío ECO</td></tr>
                                                        <tr><td>13</td><td>7PDE3 - Diseño de Procesos</td></tr>
                                                        <tr><td>14</td><td>7PDIE1 - Motores de Combustión Interna 2</td></tr>
                                                        <tr><td>15</td><td>7PDIE2 - Robótica Industrial</td></tr>
                                                        <tr><td>16</td><td>7PDIE3 - CAD 3</td></tr>
                                                        <tr><td>17</td><td>7PDIE4 - Centrales Eléctricas</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Ejemplos de archivos:</strong>
                                            <ul class="small">
                                                <li><code>7º AÑO(1).csv</code> → Prácticas Profesionalizantes</li>
                                                <li><code>7º AÑO(5).csv</code> → Máquinas Eléctricas</li>
                                                <li><code>7º AÑO(17).csv</code> → 7PDIE4 - Centrales Eléctricas</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Formulario de importación masiva -->
                        <form method="POST" enctype="multipart/form-data" class="border rounded p-4 bg-light">
                            <input type="hidden" name="importacion_masiva" value="1">
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="curso_masivo" class="form-label">Curso:</label>
                                    <select name="curso_masivo" id="curso_masivo" class="form-select" required>
                                        <?php foreach ($cursos as $curso): ?>
                                        <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($curso['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="anio_masivo" class="form-label">Año:</label>
                                    <select name="anio_masivo" id="anio_masivo" class="form-select" required>
                                        <option value="3">3er Año</option>
                                        <option value="4">4to Año</option>
                                        <option value="5">5to Año</option>
                                        <option value="6">6to Año</option>
                                        <option value="7">7mo Año</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="bimestre_masivo" class="form-label">Bimestre:</label>
                                    <select name="bimestre_masivo" id="bimestre_masivo" class="form-select" required>
                                        <option value="1">1er Bimestre</option>
                                        <option value="3">3er Bimestre</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="archivos_masivos" class="form-label">Archivos CSV:</label>
                                <input type="file" name="archivo_importacion[]" id="archivos_masivos" 
                                       class="form-control" accept=".csv" multiple required>
                                <div class="form-text">
                                    <strong>Seleccione múltiples archivos CSV</strong> (Ctrl+Click para seleccionar varios).<br>
                                    Los archivos deben seguir la nomenclatura: <code>AÑO(NUMERO).csv</code><br>
                                    Ejemplo: <code>3º AÑO(1).csv, 3º AÑO(2).csv, 3º AÑO(12).csv</code>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="sobrescribir_masivo" name="sobrescribir_masivo" value="1" checked>
                                    <label class="form-check-label" for="sobrescribir_masivo">
                                        Sobrescribir datos existentes
                                    </label>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg" onclick="return confirmarImportacionMasiva()">
                                    <i class="bi bi-upload"></i> Importar Todas las Materias
                                </button>
                            </div>
                        </form>
                        
                        <!-- Indicador de progreso (oculto inicialmente) -->
                        <div id="progreso_masivo" style="display: none;" class="mt-4">
                            <div class="alert alert-info">
                                <i class="bi bi-clock-history"></i> <strong>Procesando archivos...</strong>
                                <div class="progress mt-2">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                         role="progressbar" style="width: 100%"></div>
                                </div>
                                <small>Esto puede tomar unos minutos dependiendo del número de archivos.</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="alert alert-warning">
                    <?php if (!$cursoSeleccionado): ?>
                        Seleccione un curso para continuar con la importación.
                    <?php else: ?>
                        No se encontraron materias disponibles para este curso.
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Plantilla de ejemplo -->
                <div class="mt-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title">
                                <i class="bi bi-download"></i> Plantilla de Ejemplo
                            </h6>
                        </div>
                        <div class="card-body">
                            <button type="button" class="btn btn-outline-success" onclick="descargarPlantilla()">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Descargar Plantilla CSV
                            </button>
                            
                            <div class="mt-3">
                                <h6>Estructura recomendada:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Nro</th>
                                                <th>Alumno</th>
                                                <th>DNI</th>
                                                <th>Valoración</th>
                                                <th>Desempeño</th>
                                                <th>Calificación</th>
                                                <th>Observaciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>1</td>
                                                <td>ACOSTA ANAYA, Alma Sofia</td>
                                                <td>12345678</td>
                                                <td>TEP</td>
                                                <td>B</td>
                                                <td>7</td>
                                                <td>Se sugiere dedicar mayor tiempo al estudio</td>
                                            </tr>
                                            <tr>
                                                <td>2</td>
                                                <td>ALITTA, Renzo</td>
                                                <td>87654321</td>
                                                <td>TEA</td>
                                                <td>MB</td>
                                                <td>9</td>
                                                <td>Demuestra un sólido dominio de los contenidos</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mostrar/ocultar selector de bimestre para importación individual
    const tipoImportacion = document.getElementById('tipo_importacion');
    const divBimestre = document.getElementById('div_bimestre');
    
    if (tipoImportacion) {
        tipoImportacion.addEventListener('change', function() {
            if (this.value === 'valoracion') {
                divBimestre.style.display = 'block';
            } else {
                divBimestre.style.display = 'none';
            }
        });
    }
    
    // Previsualizar archivo individual
    const archivoInput = document.getElementById('archivo_importacion');
    if (archivoInput) {
        archivoInput.addEventListener('change', function() {
            const archivo = this.files[0];
            if (archivo && archivo.type === 'text/csv') {
                previsualizarCSV(archivo);
            }
        });
    }
    
    // Validar nombres de archivos masivos en tiempo real
    const archivosMasivos = document.getElementById('archivos_masivos');
    if (archivosMasivos) {
        archivosMasivos.addEventListener('change', function() {
            validarArchivosMasivos(this.files);
        });
    }
});

function confirmarImportacionMasiva() {
    const archivos = document.getElementById('archivos_masivos').files;
    const anio = document.getElementById('anio_masivo').value;
    const bimestre = document.getElementById('bimestre_masivo').value;
    
    if (archivos.length === 0) {
        alert('Por favor seleccione al menos un archivo CSV.');
        return false;
    }
    
    const mensaje = `¿Está seguro de importar ${archivos.length} archivo(s) para ${anio}º año, ${bimestre}º bimestre?\n\nEsto procesará automáticamente todas las materias seleccionadas.`;
    
    if (confirm(mensaje)) {
        // Mostrar indicador de progreso
        document.getElementById('progreso_masivo').style.display = 'block';
        return true;
    }
    
    return false;
}

function validarArchivosMasivos(archivos) {
    let archivosValidos = 0;
    let archivosInvalidos = [];
    
    for (let i = 0; i < archivos.length; i++) {
        const nombre = archivos[i].name;
        if (/\(\d+\)\.csv$/i.test(nombre)) {
            archivosValidos++;
        } else {
            archivosInvalidos.push(nombre);
        }
    }
    
    // Mostrar resumen
    let texto = archivosValidos > 0 ? 
        `✅ ${archivosValidos} archivo(s) válido(s) seleccionado(s)` : 
        '❌ No hay archivos válidos seleccionados';
    
    if (archivosInvalidos.length > 0) {
        texto += `\n⚠️ Archivos con formato incorrecto: ${archivosInvalidos.slice(0, 3).join(', ')}`;
        if (archivosInvalidos.length > 3) {
            texto += ` y ${archivosInvalidos.length - 3} más...`;
        }
    }
    
    // Crear o actualizar elemento de información
    let infoDiv = document.getElementById('info_archivos_masivos');
    if (!infoDiv) {
        infoDiv = document.createElement('div');
        infoDiv.id = 'info_archivos_masivos';
        infoDiv.className = 'mt-2 small';
        document.getElementById('archivos_masivos').parentNode.appendChild(infoDiv);
    }
    
    const className = archivosValidos > 0 ? 'text-success' : 'text-danger';
    infoDiv.innerHTML = `<span class="${className}">${texto.replace(/\n/g, '<br>')}</span>`;
}

function previsualizarCSV(archivo) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const contenido = e.target.result;
        const lineas = contenido.split('\n').slice(0, 5); // Primeras 5 líneas
        
        console.log('Previsualización CSV:', lineas);
    };
    reader.readAsText(archivo);
}

function descargarPlantilla() {
    const contenidoCSV = `Nro,Alumno,DNI,Valoración,Desempeño,Calificación,Observaciones
1,"ACOSTA ANAYA, Alma Sofia",12345678,TEP,B,7,"Se sugiere dedicar mayor tiempo al estudio"
2,"ALITTA, Renzo",87654321,TEA,MB,9,"Demuestra un sólido dominio de los contenidos"
3,"BARROS, Maximo",11223344,TEA,MB,8,"Muestra gran interés y participación en clase"`;
    
    const blob = new Blob([contenidoCSV], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'plantilla_importacion_calificaciones.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
// Limpiar buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>