<?php
/**
 * importar_contenidos.php - Sistema de importación masiva de contenidos y calificaciones desde archivos CSV
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Importa contenidos y calificaciones del sistema anterior analizando archivos CSV
 * - Extrae contenidos de la fila A3
 * - Procesa calificaciones de estudiantes automáticamente
 */

// Iniciar buffer de salida para evitar problemas con headers
ob_start();

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir archivos necesarios
require_once 'config.php';
require_once 'sistema_periodos_automaticos.php';

// Verificar permisos ANTES de incluir header
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección. Solo administradores y directivos pueden importar contenidos.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Procesar formulario ANTES de incluir header para permitir redirecciones
$resultado_procesamiento = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Configurar tiempo de ejecución extendido para importaciones masivas
    set_time_limit(600); // 10 minutos para calificaciones
    ini_set('memory_limit', '512M'); // Aumentar memoria para procesamiento masivo
    
    try {
        $db = Database::getInstance();
        
        // Obtener ciclo lectivo activo
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
        
        if ($cicloLectivoId == 0) {
            throw new Exception('No hay un ciclo lectivo activo configurado');
        }
        
        $resultado_procesamiento = procesarImportacionContenidos($_FILES['archivo_importacion'], $_POST, $db, $cicloLectivoId);
        
        if ($resultado_procesamiento['success']) {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'danger';
        }
        
        // Redireccionar para evitar reenvío en F5
        $redirect_url = $_SERVER['REQUEST_URI'];
        if (isset($_POST['curso_masivo'])) {
            $curso = intval($_POST['curso_masivo']);
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

// Obtener cursos
$cursos = [];
try {
    $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener cursos: ' . $e->getMessage() . '</div>';
}

/**
 * Mapeos de materias por año - INCLUYE 1ER Y 2DO AÑO
 */
function obtenerMapeoMateriasContenidos($anio) {
    switch ($anio) {
        case 1:
            return [
                1 => 'CIENCIAS NATURALES',
                2 => 'CIENCIAS SOCIALES',
                3 => 'EDUCACIÓN ARTÍSTICA - Plástica',
                4 => 'EDUCACIÓN ARTÍSTICA - Música',
                5 => 'EDUCACIÓN FÍSICA',
                6 => 'INGLÉS',
                7 => 'MATEMÁTICA',
                8 => 'PRÁCTICAS DEL LENGUAJE',
                9 => 'CONSTRUCCIÓN DE CIUDADANÍA',
                10 => 'Metales 1',
                11 => 'Maderas 1',
                12 => 'Tecnología 1',
                13 => 'Informática 1',
                14 => 'Impresión 3D',
                15 => 'Dibujo Técnico 1',
                16 => 'Robótica 1',
                17 => 'Diseño Tecnológico',
                18 => 'Proyecto Tecnológico 1'
            ];
        case 2:
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
                14 => 'Metales 2',
                15 => 'Maderas 2',
                16 => 'Tecnología 2',
                17 => 'Dibujo Técnico 2',
                18 => 'Informática 2',
                19 => 'Robótica 2',
                20 => 'Fundamentos de Electricidad',
                21 => 'Proyecto Tecnológico 2'
            ];
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
 * Buscar estudiante por nombre en la base de datos
 */
function buscarEstudiantePorNombre($db, $nombre, $materiaId) {
    $nombreLimpio = limpiarNombre($nombre);
    
    // Primero buscar estudiantes del curso de la materia
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni,
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
    }
    
    if ($mejorCoincidencia && $mejorPorcentaje > 85) {
        return $mejorCoincidencia;
    }
    
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

/**
 * Procesar importación masiva de contenidos y calificaciones
 */
function procesarImportacionContenidos($archivos, $post, $db, $cicloLectivoId) {
    try {
        $anioSeleccionado = intval($post['anio_masivo']);
        $bimestre = 1; // Siempre será 1er bimestre según los requerimientos
        $cursoId = intval($post['curso_masivo']);
        $fechaContenido = '2025-04-17'; // Fecha fija para 1er bimestre
        
        // Obtener mapeo de materias según el año
        $mapeoMaterias = obtenerMapeoMateriasContenidos($anioSeleccionado);
        
        if (empty($mapeoMaterias)) {
            return ['success' => false, 'message' => 'Mapeo de materias no disponible para este año'];
        }
        
        // Obtener todas las materias del curso
        $materiasDisponibles = $db->fetchAll(
            "SELECT mp.id, m.nombre, m.codigo, mp.profesor_id
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
            $indiceMaterias[$nombreNormalizado] = $materia;
            
            if (!empty($materia['codigo'])) {
                $indiceMaterias[strtoupper($materia['codigo'])] = $materia;
            }
        }
        
        $resultados = [];
        $archivosExitosos = 0;
        $archivosConError = 0;
        $totalContenidosCreados = 0;
        $totalContenidosOmitidos = 0;
        $totalCalificacionesProcesadas = 0;
        $totalEstudiantesProcesados = 0;
        
        // Verificar estructura de archivos
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
            
            // Extraer número de materia del nombre del archivo - Patrones más flexibles
            if (preg_match('/(\d+)[º°]\s*AÑO[\(\s]*(\d+)[\)\s]*\.csv$/i', $nombreArchivo, $matches)) {
                $numeroMateria = intval($matches[2]);
                
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
                
                $materiaInfo = $indiceMaterias[$nombreNormalizado];
                
                // Procesar el archivo CSV para extraer contenidos y calificaciones
                $rutaTemporal = $archivos['tmp_name'][$indice];
                $resultado = procesarContenidosYCalificacionesCSV($rutaTemporal, $materiaInfo, $bimestre, $fechaContenido, $db);
                
                if ($resultado['success']) {
                    $resultados[] = "✅ $nombreArchivo ($nombreMateriaBuscada): " . $resultado['message'];
                    $archivosExitosos++;
                    $totalContenidosCreados += $resultado['creados'];
                    $totalContenidosOmitidos += $resultado['omitidos'];
                    
                    // Agregar estadísticas de calificaciones si están disponibles
                    if (isset($resultado['calificaciones'])) {
                        $totalCalificacionesProcesadas += $resultado['calificaciones'];
                    }
                    if (isset($resultado['estudiantes'])) {
                        $totalEstudiantesProcesados += $resultado['estudiantes'];
                    }
                    
                    // Verificar si hay materias sin profesor
                    if (isset($resultado['sin_profesor']) && $resultado['sin_profesor'] > 0) {
                        $resultados[count($resultados)-1] .= " ⚠️ ATENCIÓN: {$resultado['sin_profesor']} contenidos no se crearon porque la materia no tiene profesor asignado";
                    }
                    
                    // Verificar si hay estudiantes no encontrados
                    if (isset($resultado['estudiantes_no_encontrados']) && $resultado['estudiantes_no_encontrados'] > 0) {
                        $resultados[count($resultados)-1] .= " ⚠️ {$resultado['estudiantes_no_encontrados']} estudiantes no encontrados en BD";
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
        $mensajeFinal = "IMPORTACIÓN MASIVA DE CONTENIDOS Y CALIFICACIONES COMPLETADA - {$anioSeleccionado}º AÑO\n\n";
        $mensajeFinal .= "📊 RESUMEN:\n";
        $mensajeFinal .= "• Archivos procesados exitosamente: $archivosExitosos\n";
        $mensajeFinal .= "• Archivos con error: $archivosConError\n";
        $mensajeFinal .= "• Total contenidos creados: $totalContenidosCreados\n";
        $mensajeFinal .= "• Total contenidos omitidos (ya existían): $totalContenidosOmitidos\n";
        $mensajeFinal .= "• Total calificaciones procesadas: $totalCalificacionesProcesadas\n";
        $mensajeFinal .= "• Total estudiantes procesados: $totalEstudiantesProcesados\n\n";
        $mensajeFinal .= "📋 DETALLE POR ARCHIVO:\n";
        $mensajeFinal .= implode("\n", $resultados);
        
        return [
            'success' => $archivosExitosos > 0,
            'message' => $mensajeFinal,
            'stats' => [
                'exitosos' => $archivosExitosos,
                'errores' => $archivosConError,
                'contenidos_creados' => $totalContenidosCreados,
                'contenidos_omitidos' => $totalContenidosOmitidos,
                'calificaciones_procesadas' => $totalCalificacionesProcesadas,
                'estudiantes_procesados' => $totalEstudiantesProcesados
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Error en procesarImportacionContenidos: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
    }
}

/**
 * Procesar CSV para extraer contenidos de la fila A3 y calificaciones de estudiantes
 */
function procesarContenidosYCalificacionesCSV($rutaArchivo, $materiaInfo, $bimestre, $fechaContenido, $db) {
    try {
        $contenido = file_get_contents($rutaArchivo);
        
        // Convertir encoding si es necesario
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', ['ISO-8859-1', 'Windows-1252', 'UTF-8']);
        }
        
        $lineas = str_getcsv($contenido, "\n");
        
        // Buscar la línea A3 (contenidos) - línea 3 del archivo (índice 2)
        $lineaContenidos = null;
        
        if (isset($lineas[2])) {
            $lineaContenidos = str_getcsv($lineas[2], ",");
        } else {
            // Fallback: buscar línea que contenga "AÑO"
            for ($i = 0; $i < min(10, count($lineas)); $i++) {
                if (isset($lineas[$i])) {
                    $lineaActual = str_getcsv($lineas[$i], ",");
                    if (count($lineaActual) > 5) {
                        $primerCampo = trim($lineaActual[0]);
                        if (preg_match('/\d+er\s+AÑO/i', $primerCampo)) {
                            $lineaContenidos = $lineaActual;
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$lineaContenidos) {
            return ['success' => false, 'message' => 'No se encontró la línea de contenidos (A3)'];
        }
        
        // Buscar contenidos válidos según la estructura del CSV
        $titulosContenidos = [];
        $inicioContenidos = 2; // Empezar desde la columna 2
        $finContenidos = count($lineaContenidos);
        
        // Buscar donde terminan los contenidos - buscar "Valoración preliminar"
        for ($i = $inicioContenidos; $i < count($lineaContenidos); $i++) {
            $campo = trim($lineaContenidos[$i]);
            if (preg_match('/valoraci[óo]n\s+preliminar|trayectoria|desempe[ñn]o|observaciones/i', $campo)) {
                $finContenidos = $i;
                break;
            }
        }
        
        // Extraer títulos de contenidos
        for ($i = $inicioContenidos; $i < $finContenidos; $i++) {
            $titulo = trim($lineaContenidos[$i]);
            
            // Solo agregar si no es "0" o vacío y tiene contenido válido
            if (!empty($titulo) && $titulo !== '0' && strlen($titulo) > 2 && !is_numeric($titulo)) {
                $titulosContenidos[] = [
                    'titulo' => $titulo,
                    'orden' => (count($titulosContenidos) + 1)
                ];
            }
        }
        
        if (empty($titulosContenidos)) {
            return ['success' => true, 'message' => 'No se encontraron contenidos para crear', 'creados' => 0, 'omitidos' => 0];
        }
        
        // Crear contenidos en la base de datos
        $contenidosCreados = 0;
        $contenidosOmitidos = 0;
        $contenidosSinProfesor = 0;
        $contenidosIds = []; // Para almacenar los IDs de contenidos creados/existentes
        
        foreach ($titulosContenidos as $contenidoData) {
            // Verificar si ya existe un contenido similar
            $existente = $db->fetchOne(
                "SELECT id FROM contenidos 
                 WHERE materia_curso_id = ? AND bimestre = ? AND titulo = ? AND activo = 1",
                [$materiaInfo['id'], $bimestre, $contenidoData['titulo']]
            );
            
            if ($existente) {
                $contenidosOmitidos++;
                $contenidosIds[$contenidoData['orden']] = $existente['id'];
                continue;
            }
            
            // Verificar que la materia tenga un profesor asignado
            if (empty($materiaInfo['profesor_id'])) {
                $contenidosSinProfesor++;
                continue;
            }
            
            // Crear el contenido (ahora permitido para administradores)
            $db->insert(
                "INSERT INTO contenidos (materia_curso_id, profesor_id, bimestre, titulo, descripcion, 
                                       fecha_clase, tipo_evaluacion, orden, activo) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [
                    $materiaInfo['id'],
                    $materiaInfo['profesor_id'],
                    $bimestre,
                    $contenidoData['titulo'],
                    'Contenido migrado del sistema anterior - Importado por: ' . ($_SESSION['nombre'] ?? 'Usuario ID: ' . $_SESSION['user_id']),
                    $fechaContenido,
                    'cualitativa', // Todos los contenidos son cualitativos según requerimientos
                    $contenidoData['orden']
                ]
            );
            
            // Obtener el ID del contenido recién creado
            $nuevoId = $db->fetchOne("SELECT last_insert_rowid() as id")['id'];
            $contenidosIds[$contenidoData['orden']] = $nuevoId;
            
            $contenidosCreados++;
        }
        
        // NUEVO: Procesar calificaciones de estudiantes
        $calificacionesProcesadas = 0;
        $estudiantesProcesados = 0;
        $estudiantesNoEncontrados = 0;
        
        // Buscar líneas de estudiantes (a partir de las líneas que contienen datos de estudiantes)
        for ($lineaIndex = 0; $lineaIndex < count($lineas); $lineaIndex++) {
            $lineaActual = str_getcsv($lineas[$lineaIndex], ",");
            
            // Buscar líneas que empiecen con número y tengan nombre de estudiante
            if (count($lineaActual) > 5 && 
                is_numeric(trim($lineaActual[0])) && 
                !empty(trim($lineaActual[1])) && 
                strlen(trim($lineaActual[1])) > 5) {
                
                $numeroEstudiante = intval(trim($lineaActual[0]));
                $nombreEstudiante = trim($lineaActual[1], ' "');
                
                // Buscar el estudiante en la base de datos
                $estudiante = buscarEstudiantePorNombre($db, $nombreEstudiante, $materiaInfo['id']);
                
                if (!$estudiante) {
                    $estudiantesNoEncontrados++;
                    continue;
                }
                
                $estudiantesProcesados++;
                
                // Procesar calificaciones de cada contenido para este estudiante
                foreach ($titulosContenidos as $contenidoData) {
                    $ordenContenido = $contenidoData['orden'];
                    $contenidoId = $contenidosIds[$ordenContenido] ?? null;
                    
                    if (!$contenidoId) {
                        continue;
                    }
                    
                    // La calificación está en la posición: 1 + orden del contenido
                    $posicionCalificacion = 1 + $ordenContenido;
                    
                    if (isset($lineaActual[$posicionCalificacion])) {
                        $calificacionRaw = trim(strtoupper($lineaActual[$posicionCalificacion]));
                        
                        // Mapear calificaciones
                        $calificacionFinal = null;
                        switch ($calificacionRaw) {
                            case 'A':
                                $calificacionFinal = 'Acreditado';
                                break;
                            case '0':
                                $calificacionFinal = 'No Acreditado';
                                break;
                            case 'N/C':
                            case 'NC':
                                $calificacionFinal = 'No Corresponde';
                                break;
                            default:
                                // Si está vacío o es otro valor, omitir
                                continue 2;
                        }
                        
                        // Verificar si ya existe la calificación
                        $calificacionExistente = $db->fetchOne(
                            "SELECT id FROM contenidos_calificaciones 
                             WHERE contenido_id = ? AND estudiante_id = ?",
                            [$contenidoId, $estudiante['id']]
                        );
                        
                        if ($calificacionExistente) {
                            // Actualizar calificación existente
                            $db->query(
                                "UPDATE contenidos_calificaciones 
                                 SET calificacion_cualitativa = ?, fecha_evaluacion = date('now')
                                 WHERE id = ?",
                                [$calificacionFinal, $calificacionExistente['id']]
                            );
                        } else {
                            // Crear nueva calificación
                            $db->insert(
                                "INSERT INTO contenidos_calificaciones 
                                 (contenido_id, estudiante_id, calificacion_cualitativa, fecha_evaluacion)
                                 VALUES (?, ?, ?, date('now'))",
                                [$contenidoId, $estudiante['id'], $calificacionFinal]
                            );
                        }
                        
                        $calificacionesProcesadas++;
                    }
                }
            }
        }
        
        $mensaje = "Procesados " . count($titulosContenidos) . " contenidos: $contenidosCreados creados";
        if ($contenidosOmitidos > 0) {
            $mensaje .= ", $contenidosOmitidos omitidos (ya existían)";
        }
        if ($contenidosSinProfesor > 0) {
            $mensaje .= ", $contenidosSinProfesor sin profesor asignado";
        }
        $mensaje .= " | $calificacionesProcesadas calificaciones procesadas para $estudiantesProcesados estudiantes";
        if ($estudiantesNoEncontrados > 0) {
            $mensaje .= " ($estudiantesNoEncontrados estudiantes no encontrados)";
        }
        
        return [
            'success' => true,
            'message' => $mensaje,
            'creados' => $contenidosCreados,
            'omitidos' => $contenidosOmitidos,
            'sin_profesor' => $contenidosSinProfesor,
            'calificaciones' => $calificacionesProcesadas,
            'estudiantes' => $estudiantesProcesados,
            'estudiantes_no_encontrados' => $estudiantesNoEncontrados
        ];
        
    } catch (Exception $e) {
        error_log("Error en procesarContenidosYCalificacionesCSV: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error al procesar CSV: ' . $e->getMessage()];
    }
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            
            <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show">
                <?= $_SESSION['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php 
            unset($_SESSION['message']);
            unset($_SESSION['message_type']);
            endif; ?>
            
            <!-- Encabezado -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1 text-gray-800">
                        <i class="bi bi-upload"></i> Importar Contenidos y Calificaciones Masivamente
                    </h1>
                    <p class="text-muted">Migre contenidos y calificaciones del sistema anterior analizando archivos CSV</p>
                </div>
                <div>
                    <a href="contenidos.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a Contenidos
                    </a>
                    <a href="importar_contenidos_individual.php" class="btn btn-outline-info">
                        <i class="bi bi-bug"></i> Modo Debugging
                    </a>
                </div>
            </div>
            
            <!-- Información sobre el proceso -->
            <div class="alert alert-info mb-4">
                <h6 class="alert-heading">
                    <i class="bi bi-info-circle"></i> Información sobre la Importación de Contenidos y Calificaciones
                </h6>
                <div class="row">
                    <div class="col-md-8">
                        <p class="mb-2"><strong>Este proceso:</strong></p>
                        <ul class="mb-0">
                            <li>Analiza la <strong>fila A3</strong> de cada archivo CSV para extraer títulos de contenidos</li>
                            <li>Crea contenidos con <strong>evaluación cualitativa</strong> (todos los contenidos migrados)</li>
                            <li>Asigna fecha <strong>17 de abril de 2025</strong> para garantizar 1er bimestre</li>
                            <li>Procesa <strong>calificaciones de estudiantes</strong> (A=Acreditado, 0=No Acreditado, N/C=No Corresponde)</li>
                            <li>Omite contenidos que ya existen en el sistema</li>
                            <li>Asigna el contenido al profesor principal de la materia</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-warning mb-0">
                            <small>
                                <strong>⚠️ Importante:</strong>
                                <br>• Solo administradores y directivos pueden usar esta función
                                <br>• Los archivos deben ser del formato original del sistema anterior
                                <br>• Se procesan contenidos Y calificaciones automáticamente
                                <br>• Los estudiantes deben existir en la base de datos
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Información sobre formatos -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-file-earmark-text"></i> Formato de Archivos CSV Esperado
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Estructura de la Fila A3 (línea de contenidos):</h6>
                            <code class="d-block bg-light p-2 small">
                                1er AÑO 2025,,Los materiales y sus transformaciones,Biosfera,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,,Valoración preliminar de la trayectoria,Desempeño académico del bimestre,Observaciones,,,
                            </code>
                            <small class="text-muted">
                                Los espacios con <strong>texto</strong> son contenidos creados.<br>
                                Los espacios con <strong>"0"</strong> indican que no hay contenido.
                            </small>
                            
                            <h6 class="mt-3">Estructura de líneas de estudiantes:</h6>
                            <code class="d-block bg-light p-2 small">
                                1,"ACOSTA ANAYA, Alma Sofia ",0,A,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,TEP,B,0,,,
                            </code>
                            <small class="text-muted">
                                <strong>Calificaciones:</strong> A=Acreditado, 0=No Acreditado, N/C=No Corresponde
                            </small>
                        </div>
                        <div class="col-md-6">
                            <h6>Nomenclatura de archivos:</h6>
                            <ul class="small">
                                <li><code>1º AÑO(1).csv</code> → Ciencias Naturales</li>
                                <li><code>2º AÑO(12).csv</code> → Matemática</li>
                                <li><code>3º AÑO(22).csv</code> → Robótica 3</li>
                                <li><em>etc...</em></li>
                            </ul>
                            <div class="alert alert-light mt-2">
                                <small>
                                    <strong>Resultado:</strong> Se crearán contenidos Y calificaciones:
                                    <br>• <strong>Contenidos:</strong> Título, fecha 17/04/2025, tipo cualitativo
                                    <br>• <strong>Calificaciones:</strong> Acreditado/No Acreditado/No Corresponde
                                    <br>• <strong>Estudiantes:</strong> Se buscan automáticamente en la BD
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Mapeos de materias por año -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-list-ol"></i> Mapeo de Materias por Año
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Pestañas para diferentes años -->
                    <ul class="nav nav-tabs" id="tabsAniosContenidos" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-1" data-bs-toggle="tab" data-bs-target="#mapeo-1" type="button" role="tab">1º Año</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-2" data-bs-toggle="tab" data-bs-target="#mapeo-2" type="button" role="tab">2º Año</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-3" data-bs-toggle="tab" data-bs-target="#mapeo-3" type="button" role="tab">3º Año</button>
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
                    <div class="tab-content mt-3" id="contenidoTabsAniosContenidos">
                        
                        <!-- 1er Año -->
                        <div class="tab-pane fade show active" id="mapeo-1" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div style="max-height: 300px; overflow-y: auto;" class="small">
                                        <table class="table table-sm table-striped">
                                            <thead><tr><th>N°</th><th>Materia</th><th>Archivo Esperado</th></tr></thead>
                                            <tbody>
                                                <tr><td>1</td><td>CIENCIAS NATURALES</td><td><code>1º AÑO(1).csv</code></td></tr>
                                                <tr><td>2</td><td>CIENCIAS SOCIALES</td><td><code>1º AÑO(2).csv</code></td></tr>
                                                <tr><td>3</td><td>EDUCACIÓN ARTÍSTICA - Plástica</td><td><code>1º AÑO(3).csv</code></td></tr>
                                                <tr><td>4</td><td>EDUCACIÓN ARTÍSTICA - Música</td><td><code>1º AÑO(4).csv</code></td></tr>
                                                <tr><td>5</td><td>EDUCACIÓN FÍSICA</td><td><code>1º AÑO(5).csv</code></td></tr>
                                                <tr><td>6</td><td>INGLÉS</td><td><code>1º AÑO(6).csv</code></td></tr>
                                                <tr><td>7</td><td>MATEMÁTICA</td><td><code>1º AÑO(7).csv</code></td></tr>
                                                <tr><td>8</td><td>PRÁCTICAS DEL LENGUAJE</td><td><code>1º AÑO(8).csv</code></td></tr>
                                                <tr><td>9</td><td>CONSTRUCCIÓN DE CIUDADANÍA</td><td><code>1º AÑO(9).csv</code></td></tr>
                                                <tr><td>10</td><td>Metales 1</td><td><code>1º AÑO(10).csv</code></td></tr>
                                                <tr><td>11</td><td>Maderas 1</td><td><code>1º AÑO(11).csv</code></td></tr>
                                                <tr><td>12</td><td>Tecnología 1</td><td><code>1º AÑO(12).csv</code></td></tr>
                                                <tr><td>13</td><td>Informática 1</td><td><code>1º AÑO(13).csv</code></td></tr>
                                                <tr><td>14</td><td>Impresión 3D</td><td><code>1º AÑO(14).csv</code></td></tr>
                                                <tr><td>15</td><td>Dibujo Técnico 1</td><td><code>1º AÑO(15).csv</code></td></tr>
                                                <tr><td>16</td><td>Robótica 1</td><td><code>1º AÑO(16).csv</code></td></tr>
                                                <tr><td>17</td><td>Diseño Tecnológico</td><td><code>1º AÑO(17).csv</code></td></tr>
                                                <tr><td>18</td><td>Proyecto Tecnológico 1</td><td><code>1º AÑO(18).csv</code></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 2do Año -->
                        <div class="tab-pane fade" id="mapeo-2" role="tabpanel">
                            <div class="row">
                                <div class="col-md-12">
                                    <div style="max-height: 300px; overflow-y: auto;" class="small">
                                        <table class="table table-sm table-striped">
                                            <thead><tr><th>N°</th><th>Materia</th><th>Archivo Esperado</th></tr></thead>
                                            <tbody>
                                                <tr><td>1</td><td>BIOLOGÍA</td><td><code>2º AÑO(1).csv</code></td></tr>
                                                <tr><td>2</td><td>CONSTR. DE CIUD. - Maderas</td><td><code>2º AÑO(2).csv</code></td></tr>
                                                <tr><td>3</td><td>CONSTR. DE CIUD. - Metales</td><td><code>2º AÑO(3).csv</code></td></tr>
                                                <tr><td>4</td><td>CONSTR. DE CIUD. - Electricidad</td><td><code>2º AÑO(4).csv</code></td></tr>
                                                <tr><td>5</td><td>EDUCACIÓN ARTÍSTICA - Plástica</td><td><code>2º AÑO(5).csv</code></td></tr>
                                                <tr><td>6</td><td>EDUCACIÓN ARTÍSTICA - Música</td><td><code>2º AÑO(6).csv</code></td></tr>
                                                <tr><td>7</td><td>EDUCACIÓN FÍSICA</td><td><code>2º AÑO(7).csv</code></td></tr>
                                                <tr><td>8</td><td>FÍSICO QUÍMICA</td><td><code>2º AÑO(8).csv</code></td></tr>
                                                <tr><td>9</td><td>GEOGRAFÍA</td><td><code>2º AÑO(9).csv</code></td></tr>
                                                <tr><td>10</td><td>HISTORIA</td><td><code>2º AÑO(10).csv</code></td></tr>
                                                <tr><td>11</td><td>INGLÉS</td><td><code>2º AÑO(11).csv</code></td></tr>
                                                <tr><td>12</td><td>MATEMÁTICA</td><td><code>2º AÑO(12).csv</code></td></tr>
                                                <tr><td>13</td><td>PRÁCTICAS DEL LENGUAJE</td><td><code>2º AÑO(13).csv</code></td></tr>
                                                <tr><td>14</td><td>Metales 2</td><td><code>2º AÑO(14).csv</code></td></tr>
                                                <tr><td>15</td><td>Maderas 2</td><td><code>2º AÑO(15).csv</code></td></tr>
                                                <tr><td>16</td><td>Tecnología 2</td><td><code>2º AÑO(16).csv</code></td></tr>
                                                <tr><td>17</td><td>Dibujo Técnico 2</td><td><code>2º AÑO(17).csv</code></td></tr>
                                                <tr><td>18</td><td>Informática 2</td><td><code>2º AÑO(18).csv</code></td></tr>
                                                <tr><td>19</td><td>Robótica 2</td><td><code>2º AÑO(19).csv</code></td></tr>
                                                <tr><td>20</td><td>Fundamentos de Electricidad</td><td><code>2º AÑO(20).csv</code></td></tr>
                                                <tr><td>21</td><td>Proyecto Tecnológico 2</td><td><code>2º AÑO(21).csv</code></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Los demás años usan el mismo mapeo que ya tenías -->
                        <div class="tab-pane fade" id="mapeo-3" role="tabpanel">
                            <div class="alert alert-info">
                                <p class="mb-0">Mapeo idéntico al sistema de importación de calificaciones para 3er año.</p>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="mapeo-4" role="tabpanel">
                            <div class="alert alert-info">
                                <p class="mb-0">Mapeo idéntico al sistema de importación de calificaciones para 4to año.</p>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="mapeo-5" role="tabpanel">
                            <div class="alert alert-info">
                                <p class="mb-0">Mapeo idéntico al sistema de importación de calificaciones para 5to año.</p>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="mapeo-6" role="tabpanel">
                            <div class="alert alert-info">
                                <p class="mb-0">Mapeo idéntico al sistema de importación de calificaciones para 6to año.</p>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="mapeo-7" role="tabpanel">
                            <div class="alert alert-info">
                                <p class="mb-0">Mapeo idéntico al sistema de importación de calificaciones para 7mo año.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Formulario de importación -->
            <?php if (count($cursos) > 0): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-upload"></i> Importación Masiva de Contenidos y Calificaciones
                    </h5>
                    <p class="card-text mb-0">
                        Procese múltiples archivos CSV para importar contenidos y calificaciones automáticamente
                    </p>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="curso_masivo" class="form-label">Curso:</label>
                                <select name="curso_masivo" id="curso_masivo" class="form-select" required>
                                    <option value="">-- Seleccione un curso --</option>
                                    <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>">
                                        <?= htmlspecialchars($curso['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Por favor seleccione un curso.
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="anio_masivo" class="form-label">Año:</label>
                                <select name="anio_masivo" id="anio_masivo" class="form-select" required>
                                    <option value="">-- Seleccione el año --</option>
                                    <option value="1">1er Año</option>
                                    <option value="2">2do Año</option>
                                    <option value="3">3er Año</option>
                                    <option value="4">4to Año</option>
                                    <option value="5">5to Año</option>
                                    <option value="6">6to Año</option>
                                    <option value="7">7mo Año</option>
                                </select>
                                <div class="invalid-feedback">
                                    Por favor seleccione el año.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="archivos_contenidos" class="form-label">Archivos CSV:</label>
                            <input type="file" name="archivo_importacion[]" id="archivos_contenidos" 
                                   class="form-control" accept=".csv" multiple required>
                            <div class="form-text">
                                <strong>Seleccione múltiples archivos CSV</strong> (Ctrl+Click para seleccionar varios).<br>
                                Los archivos pueden usar cualquiera de estos formatos:<br>
                                • <code>1º AÑO(1).csv</code>, <code>1º AÑO(7).csv</code> (con paréntesis)<br>
                                • <code>1° AÑO1.csv</code>, <code>1° AÑO7.csv</code> (sin paréntesis)<br>
                                • <code>1º AÑO1.csv</code>, <code>1° AÑO(1).csv</code> (combinaciones)
                            </div>
                            <div class="invalid-feedback">
                                Por favor seleccione al menos un archivo CSV.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">
                                    <i class="bi bi-exclamation-triangle"></i> Configuración Automática y Requisitos
                                </h6>
                                <div class="row">
                                    <div class="col-md-8">
                                        <ul class="mb-0">
                                            <li><strong>Bimestre:</strong> Todos los contenidos se asignarán al <strong>1er bimestre</strong></li>
                                            <li><strong>Fecha:</strong> Se utilizará la fecha <strong>17 de abril de 2025</strong></li>
                                            <li><strong>Tipo:</strong> Todos los contenidos serán de <strong>evaluación cualitativa</strong></li>
                                            <li><strong>Profesor:</strong> Se asignará al profesor principal de cada materia</li>
                                            <li><strong>Calificaciones:</strong> Se procesarán automáticamente para todos los estudiantes</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="alert alert-danger mb-0">
                                            <small>
                                                <strong>⚠️ IMPORTANTE:</strong><br>
                                                • Todas las materias deben tener un profesor asignado<br>
                                                • Los estudiantes deben existir en la base de datos<br>
                                                • Las calificaciones se mapean automáticamente<br>
                                                • Se procesarán contenidos Y calificaciones
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg" onclick="return confirmarImportacionContenidos()">
                                <i class="bi bi-upload"></i> Importar Contenidos y Calificaciones Masivamente
                            </button>
                            <a href="contenidos.php" class="btn btn-secondary btn-lg ms-2">
                                <i class="bi bi-arrow-left"></i> Cancelar
                            </a>
                        </div>
                    </form>
                    
                    <!-- Indicador de progreso (oculto inicialmente) -->
                    <div id="progreso_contenidos" style="display: none;" class="mt-4">
                        <div class="alert alert-info">
                            <i class="bi bi-clock-history"></i> <strong>Procesando archivos, creando contenidos y calificaciones...</strong>
                            <div class="progress mt-2">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 100%"></div>
                            </div>
                            <small>
                                <strong>Tiempo estimado:</strong>
                                <ul class="mb-0 mt-2 small">
                                    <li>1-3 archivos: 1-2 minutos</li>
                                    <li>5-10 archivos: 2-5 minutos</li>
                                    <li>15+ archivos: 5-10 minutos</li>
                                </ul>
                                <em>El tiempo es mayor porque también se procesan calificaciones de estudiantes.</em>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                No hay cursos disponibles en el ciclo lectivo actual. Contacte al administrador.
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<script>
function confirmarImportacionContenidos() {
    const archivos = document.getElementById('archivos_contenidos').files;
    const anio = document.getElementById('anio_masivo').value;
    const curso = document.getElementById('curso_masivo').selectedOptions[0]?.text || 'curso seleccionado';
    
    if (archivos.length === 0) {
        alert('Por favor seleccione al menos un archivo CSV.');
        return false;
    }
    
    if (!anio) {
        alert('Por favor seleccione el año.');
        return false;
    }
    
    const mensaje = `¿Está seguro de importar contenidos y calificaciones desde ${archivos.length} archivo(s) para ${anio}º año (${curso})?\n\n` +
                   `ESTO CREARÁ CONTENIDOS Y CALIFICACIONES EN EL SISTEMA:\n` +
                   `• Se analizará la fila A3 de cada CSV para contenidos\n` +
                   `• Se procesarán las calificaciones de estudiantes\n` +
                   `• Se crearán contenidos con evaluación cualitativa\n` +
                   `• Fecha: 17 de abril de 2025 (1er bimestre)\n` +
                   `• Los contenidos duplicados serán omitidos\n` +
                   `• Solo se crearán si la materia tiene profesor asignado\n` +
                   `• Las calificaciones se mapearán: A=Acreditado, 0=No Acreditado, N/C=No Corresponde\n\n` +
                   `¿Desea continuar?`;
    
    if (confirm(mensaje)) {
        // Mostrar indicador de progreso
        document.getElementById('progreso_contenidos').style.display = 'block';
        
        // Deshabilitar botón para evitar doble envío
        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-clock-history"></i> Procesando...';
        
        return true;
    }
    
    return false;
}

// Validación en tiempo real de archivos
document.addEventListener('DOMContentLoaded', function() {
    const archivosInput = document.getElementById('archivos_contenidos');
    
    if (archivosInput) {
        archivosInput.addEventListener('change', function() {
            validarArchivosContenidos(this.files);
        });
    }
    
    // Validación de formulario Bootstrap
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
});

function validarArchivosContenidos(archivos) {
    let archivosValidos = 0;
    let archivosInvalidos = [];
    
    for (let i = 0; i < archivos.length; i++) {
        const nombre = archivos[i].name;
        // Patrones más flexibles para aceptar diferentes formatos:
        // 1º AÑO(1).csv, 1° AÑO(1).csv, 1º AÑO1.csv, 1° AÑO1.csv
        if (/\d+[º°]\s*AÑO[\(\d].*\.csv$/i.test(nombre)) {
            archivosValidos++;
        } else {
            archivosInvalidos.push(nombre);
        }
    }
    
    // Mostrar información
    let texto = '';
    if (archivosValidos > 0) {
        texto += `✅ ${archivosValidos} archivo(s) válido(s) seleccionado(s)`;
    }
    if (archivosInvalidos.length > 0) {
        if (texto) texto += '\n';
        texto += `⚠️ Archivos con formato incorrecto: ${archivosInvalidos.slice(0, 3).join(', ')}`;
        if (archivosInvalidos.length > 3) {
            texto += ` y ${archivosInvalidos.length - 3} más...`;
        }
    }
    
    // Crear o actualizar elemento de información
    let infoDiv = document.getElementById('info_archivos_contenidos');
    if (!infoDiv) {
        infoDiv = document.createElement('div');
        infoDiv.id = 'info_archivos_contenidos';
        infoDiv.className = 'mt-2 small';
        document.getElementById('archivos_contenidos').parentNode.appendChild(infoDiv);
    }
    
    const className = archivosValidos > 0 ? 'text-success' : 'text-danger';
    infoDiv.innerHTML = `<span class="${className}">${texto.replace(/\n/g, '<br>')}</span>`;
}
</script>

<?php
// Limpiar buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>