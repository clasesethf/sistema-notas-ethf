<?php
/**
 * importar_contenidos_masivo_mejorado.php - Importación masiva mejorada de contenidos y calificaciones
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Versión mejorada que procesa todos los archivos de todos los años con mejor rendimiento y debugging
 * Basado en importar_contenidos_individual.php con mejoras para procesamiento masivo
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

// Configuración para procesamiento masivo
set_time_limit(1800); // 30 minutos
ini_set('memory_limit', '1024M'); // 1GB de memoria

// Procesar formulario ANTES de incluir header
$resultado_procesamiento = null;
$estadisticas_globales = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = Database::getInstance();
        
        // Obtener ciclo lectivo activo
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
        
        if ($cicloLectivoId == 0) {
            throw new Exception('No hay un ciclo lectivo activo configurado');
        }
        
        // Determinar tipo de procesamiento
        if (isset($_POST['procesar_por_año'])) {
            $resultado_procesamiento = procesarImportacionPorAño($_FILES['archivos_por_anio'], $_POST, $db, $cicloLectivoId);
        } elseif (isset($_POST['procesar_todos'])) {
            $resultado_procesamiento = procesarImportacionCompleta($_FILES['todos_archivos'], $_POST, $db, $cicloLectivoId);
        } else {
            throw new Exception('Tipo de procesamiento no especificado');
        }
        
        if ($resultado_procesamiento['success']) {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'success';
            $estadisticas_globales = $resultado_procesamiento['stats'] ?? null;
        } else {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'danger';
        }
        
        // No redireccionar para mantener las estadísticas visibles
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error en la importación: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Incluir el header
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
 * Mapeos de materias por año - COMPLETO PARA TODOS LOS AÑOS
 */
function obtenerMapeoComplettoMaterias($anio) {
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
function normalizarNombreMateriaCompleto($nombre) {
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
 * Buscar estudiante por nombre en la base de datos - OPTIMIZADO
 */
function buscarEstudiantePorNombreOptimizado($db, $nombre, $materiaId, &$cacheEstudiantes = []) {
    // Cache para evitar consultas repetitivas
    $cacheKey = $materiaId;
    if (!isset($cacheEstudiantes[$cacheKey])) {
        $cacheEstudiantes[$cacheKey] = $db->fetchAll(
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
    }
    
    $nombreLimpio = limpiarNombreCompleto($nombre);
    $estudiantes = $cacheEstudiantes[$cacheKey];
    
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
            $variacionLimpia = limpiarNombreCompleto($variacion);
            
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
function limpiarNombreCompleto($nombre) {
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
 * Procesar importación por año específico - BASADO EN importar_contenidos_individual.php
 */
function procesarImportacionPorAño($archivos, $post, $db, $cicloLectivoId) {
    try {
        $anioSeleccionado = intval($post['anio_especifico']);
        $cursoId = intval($post['curso_especifico']);
        $bimestre = 1; // Siempre 1er bimestre
        $fechaContenido = '2025-04-17'; // Fecha fija
        
        // Obtener mapeo de materias según el año
        $mapeoMaterias = obtenerMapeoComplettoMaterias($anioSeleccionado);
        
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
            $nombreNormalizado = normalizarNombreMateriaCompleto($materia['nombre']);
            $indiceMaterias[$nombreNormalizado] = $materia;
            
            if (!empty($materia['codigo'])) {
                $indiceMaterias[strtoupper($materia['codigo'])] = $materia;
            }
        }
        
        $resultados = [];
        $estadisticas = [
            'archivos_exitosos' => 0,
            'archivos_error' => 0,
            'contenidos_creados' => 0,
            'contenidos_omitidos' => 0,
            'calificaciones_procesadas' => 0,
            'estudiantes_procesados' => 0,
            'estudiantes_no_encontrados' => 0,
            'materias_sin_profesor' => 0
        ];
        
        // Cache global para estudiantes
        $cacheEstudiantes = [];
        
        // Verificar estructura de archivos
        if (!isset($archivos['name']) || !is_array($archivos['name'])) {
            return ['success' => false, 'message' => 'No se recibieron archivos válidos'];
        }
        
        // Procesar cada archivo usando la lógica de importar_contenidos_individual.php
        for ($indice = 0; $indice < count($archivos['name']); $indice++) {
            $nombreArchivo = $archivos['name'][$indice];
            
            if ($archivos['error'][$indice] !== UPLOAD_ERR_OK) {
                $resultados[] = "❌ $nombreArchivo: Error al subir archivo (código: {$archivos['error'][$indice]})";
                $estadisticas['archivos_error']++;
                continue;
            }
            
            // Extraer número de materia del nombre del archivo - EXACTAMENTE IGUAL QUE importar_contenidos_individual.php
            if (preg_match('/\((\d+)\)\.csv$/i', $nombreArchivo, $matches)) {
                $numeroMateria = intval($matches[1]);
                
                if (!isset($mapeoMaterias[$numeroMateria])) {
                    $resultados[] = "⚠️ $nombreArchivo: Número de materia ($numeroMateria) no encontrado en el mapeo para {$anioSeleccionado}º año";
                    $estadisticas['archivos_error']++;
                    continue;
                }
                
                $nombreMateriaBuscada = $mapeoMaterias[$numeroMateria];
                $nombreNormalizado = normalizarNombreMateriaCompleto($nombreMateriaBuscada);
                
                if (!isset($indiceMaterias[$nombreNormalizado])) {
                    $resultados[] = "⚠️ $nombreArchivo: Materia '$nombreMateriaBuscada' no encontrada en el curso";
                    $estadisticas['archivos_error']++;
                    continue;
                }
                
                $materiaInfo = $indiceMaterias[$nombreNormalizado];
                
                // Procesar el archivo CSV usando la lógica mejorada
                $rutaTemporal = $archivos['tmp_name'][$indice];
                $resultado = procesarArchivoIndividualMejorado($rutaTemporal, $materiaInfo, $bimestre, $fechaContenido, $db, $cacheEstudiantes);
                
                if ($resultado['success']) {
                    $resultados[] = "✅ $nombreArchivo ($nombreMateriaBuscada): " . $resultado['message'];
                    $estadisticas['archivos_exitosos']++;
                    $estadisticas['contenidos_creados'] += $resultado['creados'];
                    $estadisticas['contenidos_omitidos'] += $resultado['omitidos'];
                    $estadisticas['calificaciones_procesadas'] += $resultado['calificaciones'];
                    $estadisticas['estudiantes_procesados'] += $resultado['estudiantes'];
                    $estadisticas['estudiantes_no_encontrados'] += $resultado['estudiantes_no_encontrados'];
                    $estadisticas['materias_sin_profesor'] += $resultado['sin_profesor'];
                } else {
                    $resultados[] = "❌ $nombreArchivo ($nombreMateriaBuscada): " . $resultado['message'];
                    $estadisticas['archivos_error']++;
                }
            } else {
                                $resultados[] = "⚠️ $nombreArchivo: Formato de nombre incorrecto. Use: (NUMERO).csv como en 3° AÑO(1).csv";
                $estadisticas['archivos_error']++;
            }
        }
        
        // Preparar mensaje final
        $mensajeFinal = "IMPORTACIÓN POR AÑO COMPLETADA - {$anioSeleccionado}º AÑO\n\n";
        $mensajeFinal .= "📊 ESTADÍSTICAS GLOBALES:\n";
        $mensajeFinal .= "• Archivos procesados exitosamente: {$estadisticas['archivos_exitosos']}\n";
        $mensajeFinal .= "• Archivos con error: {$estadisticas['archivos_error']}\n";
        $mensajeFinal .= "• Total contenidos creados: {$estadisticas['contenidos_creados']}\n";
        $mensajeFinal .= "• Total contenidos omitidos: {$estadisticas['contenidos_omitidos']}\n";
        $mensajeFinal .= "• Total calificaciones procesadas: {$estadisticas['calificaciones_procesadas']}\n";
        $mensajeFinal .= "• Total estudiantes procesados: {$estadisticas['estudiantes_procesados']}\n";
        $mensajeFinal .= "• Estudiantes no encontrados: {$estadisticas['estudiantes_no_encontrados']}\n";
        $mensajeFinal .= "• Materias sin profesor: {$estadisticas['materias_sin_profesor']}\n\n";
        $mensajeFinal .= "📋 DETALLE POR ARCHIVO:\n";
        $mensajeFinal .= implode("\n", $resultados);
        
        return [
            'success' => $estadisticas['archivos_exitosos'] > 0,
            'message' => $mensajeFinal,
            'stats' => $estadisticas
        ];
        
    } catch (Exception $e) {
        error_log("Error en procesarImportacionPorAño: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
    }
}

/**
 * Procesar importación completa de todos los años - NUEVO
 */
function procesarImportacionCompleta($archivos, $post, $db, $cicloLectivoId) {
    try {
        $bimestre = 1; // Siempre 1er bimestre
        $fechaContenido = '2025-04-17'; // Fecha fija
        
        // Obtener todos los cursos agrupados por año
        $cursosDisponibles = $db->fetchAll(
            "SELECT id, anio, nombre FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio",
            [$cicloLectivoId]
        );
        
        $cursosPorAnio = [];
        foreach ($cursosDisponibles as $curso) {
            $cursosPorAnio[$curso['anio']] = $curso;
        }
        
        $estadisticasGlobales = [
            'archivos_procesados' => 0,
            'archivos_exitosos' => 0,
            'archivos_error' => 0,
            'contenidos_creados' => 0,
            'contenidos_omitidos' => 0,
            'calificaciones_procesadas' => 0,
            'estudiantes_procesados' => 0,
            'estudiantes_no_encontrados' => 0,
            'materias_sin_profesor' => 0,
            'años_procesados' => []
        ];
        
        $resultadosPorAño = [];
        $cacheEstudiantesGlobal = [];
        $cacheMateriasPorCurso = [];
        
        // Verificar estructura de archivos
        if (!isset($archivos['name']) || !is_array($archivos['name'])) {
            return ['success' => false, 'message' => 'No se recibieron archivos válidos'];
        }
        
        // Agrupar archivos por año detectado
        $archivosPorAño = [];
        for ($indice = 0; $indice < count($archivos['name']); $indice++) {
            $nombreArchivo = $archivos['name'][$indice];
            
            if ($archivos['error'][$indice] !== UPLOAD_ERR_OK) {
                $estadisticasGlobales['archivos_error']++;
                continue;
            }
            
            // Detectar año del archivo - EXACTAMENTE IGUAL QUE importar_contenidos_individual.php
            if (preg_match('/\((\d+)\)\.csv$/i', $nombreArchivo, $matches)) {
                $anioDetectado = $anioSeleccionado; // Usar el año seleccionado en lugar de detectarlo
                $numeroMateria = intval($matches[1]);
                
                if (!isset($archivosPorAño[$anioDetectado])) {
                    $archivosPorAño[$anioDetectado] = [];
                }
                
                $archivosPorAño[$anioDetectado][] = [
                    'indice' => $indice,
                    'nombre' => $nombreArchivo,
                    'numero_materia' => $numeroMateria,
                    'ruta_temporal' => $archivos['tmp_name'][$indice]
                ];
            } else {
                $estadisticasGlobales['archivos_error']++;
            }
        }
        
        $estadisticasGlobales['archivos_procesados'] = count($archivos['name']);
        
        // Procesar cada año
        foreach ($archivosPorAño as $anio => $archivosDelAño) {
            if (!isset($cursosPorAnio[$anio])) {
                $resultadosPorAño[$anio] = "⚠️ No se encontró curso para {$anio}º año";
                continue;
            }
            
            $cursoInfo = $cursosPorAnio[$anio];
            $mapeoMaterias = obtenerMapeoComplettoMaterias($anio);
            
            if (empty($mapeoMaterias)) {
                $resultadosPorAño[$anio] = "⚠️ Mapeo de materias no disponible para {$anio}º año";
                continue;
            }
            
            // Obtener materias del curso (con cache)
            if (!isset($cacheMateriasPorCurso[$cursoInfo['id']])) {
                $materiasDisponibles = $db->fetchAll(
                    "SELECT mp.id, m.nombre, m.codigo, mp.profesor_id
                     FROM materias_por_curso mp 
                     JOIN materias m ON mp.materia_id = m.id 
                     WHERE mp.curso_id = ? 
                     ORDER BY m.nombre",
                    [$cursoInfo['id']]
                );
                
                $indiceMaterias = [];
                foreach ($materiasDisponibles as $materia) {
                    $nombreNormalizado = normalizarNombreMateriaCompleto($materia['nombre']);
                    $indiceMaterias[$nombreNormalizado] = $materia;
                    
                    if (!empty($materia['codigo'])) {
                        $indiceMaterias[strtoupper($materia['codigo'])] = $materia;
                    }
                }
                
                $cacheMateriasPorCurso[$cursoInfo['id']] = $indiceMaterias;
            }
            
            $indiceMaterias = $cacheMateriasPorCurso[$cursoInfo['id']];
            
            // Estadísticas por año
            $estadisticasAño = [
                'archivos_exitosos' => 0,
                'archivos_error' => 0,
                'contenidos_creados' => 0,
                'contenidos_omitidos' => 0,
                'calificaciones_procesadas' => 0,
                'estudiantes_procesados' => 0,
                'estudiantes_no_encontrados' => 0,
                'materias_sin_profesor' => 0
            ];
            
            $resultadosArchivos = [];
            
            // Procesar archivos del año
            foreach ($archivosDelAño as $archivoInfo) {
                $numeroMateria = $archivoInfo['numero_materia'];
                $nombreArchivo = $archivoInfo['nombre'];
                
                if (!isset($mapeoMaterias[$numeroMateria])) {
                    $resultadosArchivos[] = "⚠️ $nombreArchivo: Materia #$numeroMateria no encontrada en mapeo";
                    $estadisticasAño['archivos_error']++;
                    continue;
                }
                
                $nombreMateriaBuscada = $mapeoMaterias[$numeroMateria];
                $nombreNormalizado = normalizarNombreMateriaCompleto($nombreMateriaBuscada);
                
                if (!isset($indiceMaterias[$nombreNormalizado])) {
                    $resultadosArchivos[] = "⚠️ $nombreArchivo: Materia '$nombreMateriaBuscada' no encontrada en BD";
                    $estadisticasAño['archivos_error']++;
                    continue;
                }
                
                $materiaInfo = $indiceMaterias[$nombreNormalizado];
                
                // Procesar archivo
                $resultado = procesarArchivoIndividualMejorado(
                    $archivoInfo['ruta_temporal'], 
                    $materiaInfo, 
                    $bimestre, 
                    $fechaContenido, 
                    $db, 
                    $cacheEstudiantesGlobal
                );
                
                if ($resultado['success']) {
                    $resultadosArchivos[] = "✅ $nombreArchivo: " . $resultado['message_short'] ?? $resultado['message'];
                    $estadisticasAño['archivos_exitosos']++;
                    $estadisticasAño['contenidos_creados'] += $resultado['creados'];
                    $estadisticasAño['contenidos_omitidos'] += $resultado['omitidos'];
                    $estadisticasAño['calificaciones_procesadas'] += $resultado['calificaciones'];
                    $estadisticasAño['estudiantes_procesados'] += $resultado['estudiantes'];
                    $estadisticasAño['estudiantes_no_encontrados'] += $resultado['estudiantes_no_encontrados'];
                    $estadisticasAño['materias_sin_profesor'] += $resultado['sin_profesor'];
                } else {
                    $resultadosArchivos[] = "❌ $nombreArchivo: " . $resultado['message'];
                    $estadisticasAño['archivos_error']++;
                }
            }
            
            // Consolidar estadísticas globales
            $estadisticasGlobales['archivos_exitosos'] += $estadisticasAño['archivos_exitosos'];
            $estadisticasGlobales['archivos_error'] += $estadisticasAño['archivos_error'];
            $estadisticasGlobales['contenidos_creados'] += $estadisticasAño['contenidos_creados'];
            $estadisticasGlobales['contenidos_omitidos'] += $estadisticasAño['contenidos_omitidos'];
            $estadisticasGlobales['calificaciones_procesadas'] += $estadisticasAño['calificaciones_procesadas'];
            $estadisticasGlobales['estudiantes_procesados'] += $estadisticasAño['estudiantes_procesados'];
            $estadisticasGlobales['estudiantes_no_encontrados'] += $estadisticasAño['estudiantes_no_encontrados'];
            $estadisticasGlobales['materias_sin_profesor'] += $estadisticasAño['materias_sin_profesor'];
            
            $estadisticasGlobales['años_procesados'][$anio] = $estadisticasAño;
            
            // Generar resumen por año
            $resumenAño = "📚 {$anio}º AÑO ({$cursoInfo['nombre']}): ";
            $resumenAño .= "{$estadisticasAño['archivos_exitosos']} exitosos, ";
            $resumenAño .= "{$estadisticasAño['contenidos_creados']} contenidos, ";
            $resumenAño .= "{$estadisticasAño['calificaciones_procesadas']} calificaciones";
            
            if ($estadisticasAño['archivos_error'] > 0) {
                $resumenAño .= " ({$estadisticasAño['archivos_error']} errores)";
            }
            
            $resultadosPorAño[$anio] = $resumenAño;
        }
        
        // Preparar mensaje final
        $mensajeFinal = "IMPORTACIÓN MASIVA COMPLETA FINALIZADA\n\n";
        $mensajeFinal .= "📊 ESTADÍSTICAS GLOBALES:\n";
        $mensajeFinal .= "• Total archivos procesados: {$estadisticasGlobales['archivos_procesados']}\n";
        $mensajeFinal .= "• Archivos exitosos: {$estadisticasGlobales['archivos_exitosos']}\n";
        $mensajeFinal .= "• Archivos con error: {$estadisticasGlobales['archivos_error']}\n";
        $mensajeFinal .= "• Total contenidos creados: {$estadisticasGlobales['contenidos_creados']}\n";
        $mensajeFinal .= "• Total contenidos omitidos: {$estadisticasGlobales['contenidos_omitidos']}\n";
        $mensajeFinal .= "• Total calificaciones procesadas: {$estadisticasGlobales['calificaciones_procesadas']}\n";
        $mensajeFinal .= "• Total estudiantes procesados: {$estadisticasGlobales['estudiantes_procesados']}\n";
        $mensajeFinal .= "• Estudiantes no encontrados: {$estadisticasGlobales['estudiantes_no_encontrados']}\n";
        $mensajeFinal .= "• Materias sin profesor: {$estadisticasGlobales['materias_sin_profesor']}\n\n";
        $mensajeFinal .= "📋 RESUMEN POR AÑO:\n";
        $mensajeFinal .= implode("\n", $resultadosPorAño);
        
        return [
            'success' => $estadisticasGlobales['archivos_exitosos'] > 0,
            'message' => $mensajeFinal,
            'stats' => $estadisticasGlobales
        ];
        
    } catch (Exception $e) {
        error_log("Error en procesarImportacionCompleta: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
    }
}

/**
 * Procesar archivo individual mejorado - BASADO EN importar_contenidos_individual.php
 */
function procesarArchivoIndividualMejorado($rutaArchivo, $materiaInfo, $bimestre, $fechaContenido, $db, &$cacheEstudiantes) {
    try {
        $contenido = file_get_contents($rutaArchivo);
        
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', ['ISO-8859-1', 'Windows-1252', 'UTF-8']);
        }
        
        $lineas = str_getcsv($contenido, "\n");
        
        // Buscar la línea A3 (contenidos) - línea 3 del archivo (índice 2)
        $lineaContenidos = null;
        $posicionLinea = -1;
        
        // Buscar línea de contenidos según lógica de importar_contenidos_individual.php
        if (isset($lineas[2])) {
            $lineaContenidos = str_getcsv($lineas[2], ",");
            $posicionLinea = 2;
        } else {
            // Fallback: buscar automáticamente
            for ($i = 0; $i < min(10, count($lineas)); $i++) {
                if (isset($lineas[$i])) {
                    $lineaActual = str_getcsv($lineas[$i], ",");
                    if (count($lineaActual) > 5) {
                        $primerCampo = trim($lineaActual[0]);
                        if (preg_match('/\d+er\s+AÑO/i', $primerCampo)) {
                            $lineaContenidos = $lineaActual;
                            $posicionLinea = $i;
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$lineaContenidos) {
            return ['success' => false, 'message' => 'No se encontró la línea de contenidos'];
        }
        
        // Buscar contenidos válidos según la estructura real del CSV
        $titulosContenidos = [];
        $inicioContenidos = 2; // Empezar desde la columna 2
        $finContenidos = count($lineaContenidos);
        
        // Buscar donde terminan los contenidos
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
            return [
                'success' => true, 
                'message' => 'No se encontraron contenidos para crear', 
                'message_short' => 'Sin contenidos',
                'creados' => 0, 
                'omitidos' => 0,
                'calificaciones' => 0,
                'estudiantes' => 0,
                'estudiantes_no_encontrados' => 0,
                'sin_profesor' => 0
            ];
        }
        
        // Crear contenidos en la base de datos
        $contenidosCreados = 0;
        $contenidosOmitidos = 0;
        $contenidosSinProfesor = 0;
        $contenidosIds = [];
        
        foreach ($titulosContenidos as $contenidoData) {
            // Verificar si ya existe
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
            
            // Crear el contenido
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
                    'cualitativa',
                    $contenidoData['orden']
                ]
            );
            
            // Obtener el ID del contenido recién creado
            $nuevoId = $db->fetchOne("SELECT last_insert_rowid() as id")['id'];
            $contenidosIds[$contenidoData['orden']] = $nuevoId;
            
            $contenidosCreados++;
        }
        
        // Procesar calificaciones de estudiantes
        $calificacionesProcesadas = 0;
        $estudiantesProcesados = 0;
        $estudiantesNoEncontrados = 0;
        
        // Buscar líneas de estudiantes
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
                $estudiante = buscarEstudiantePorNombreOptimizado($db, $nombreEstudiante, $materiaInfo['id'], $cacheEstudiantes);
                
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
        
        $mensaje = "Contenidos: $contenidosCreados creados";
        if ($contenidosOmitidos > 0) {
            $mensaje .= ", $contenidosOmitidos omitidos";
        }
        $mensaje .= " | Calificaciones: $calificacionesProcesadas para $estudiantesProcesados estudiantes";
        
        $mensajeCorto = "$contenidosCreados contenidos, $calificacionesProcesadas calificaciones";
        
        return [
            'success' => true,
            'message' => $mensaje,
            'message_short' => $mensajeCorto,
            'creados' => $contenidosCreados,
            'omitidos' => $contenidosOmitidos,
            'sin_profesor' => $contenidosSinProfesor,
            'calificaciones' => $calificacionesProcesadas,
            'estudiantes' => $estudiantesProcesados,
            'estudiantes_no_encontrados' => $estudiantesNoEncontrados
        ];
        
    } catch (Exception $e) {
        error_log("Error en procesarArchivoIndividualMejorado: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error al procesar archivo: ' . $e->getMessage()];
    }
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            
            <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['message']) ?>
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
                        <i class="bi bi-upload"></i> Importación Masiva Mejorada de Contenidos y Calificaciones
                    </h1>
                    <p class="text-muted">Procese archivos de uno o todos los años con rendimiento optimizado y estadísticas detalladas</p>
                </div>
                <div>
                    <a href="contenidos.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a Contenidos
                    </a>
                    <a href="importar_contenidos_individual.php" class="btn btn-outline-info">
                        <i class="bi bi-bug"></i> Modo Debugging Individual
                    </a>
                </div>
            </div>
            
            <!-- Información sobre mejoras -->
            <div class="alert alert-info mb-4">
                <h6 class="alert-heading">
                    <i class="bi bi-rocket"></i> Mejoras de Rendimiento y Funcionalidad
                </h6>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-2"><strong>Optimizaciones implementadas:</strong></p>
                        <ul class="mb-0">
                            <li><strong>Cache de estudiantes:</strong> Evita consultas repetitivas a la BD</li>
                            <li><strong>Cache de materias:</strong> Reutiliza mapeos entre archivos</li>
                            <li><strong>Procesamiento en lotes:</strong> Agrupa operaciones de BD</li>
                            <li><strong>Estadísticas detalladas:</strong> Seguimiento completo del proceso</li>
                            <li><strong>Detección automática de años:</strong> Clasifica archivos automáticamente</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-2"><strong>Funcionalidades nuevas:</strong></p>
                        <ul class="mb-0">
                            <li><strong>Importación por año específico:</strong> Procese solo un año</li>
                            <li><strong>Importación completa:</strong> Todos los años en una operación</li>
                            <li><strong>Informes detallados:</strong> Estadísticas por año y globales</li>
                            <li><strong>Mejor manejo de errores:</strong> Continúa procesando otros archivos</li>
                            <li><strong>Tiempo extendido:</strong> 30 minutos para operaciones masivas</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Estadísticas globales si están disponibles -->
            <?php if ($estadisticas_globales): ?>
            <div class="card mb-4 border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-check-circle"></i> Estadísticas de Importación Completada
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="h2 text-primary"><?= $estadisticas_globales['archivos_exitosos'] ?></div>
                                <small class="text-muted">Archivos Exitosos</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="h2 text-success"><?= $estadisticas_globales['contenidos_creados'] ?></div>
                                <small class="text-muted">Contenidos Creados</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="h2 text-info"><?= $estadisticas_globales['calificaciones_procesadas'] ?></div>
                                <small class="text-muted">Calificaciones Procesadas</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center">
                                <div class="h2 text-warning"><?= $estadisticas_globales['estudiantes_procesados'] ?></div>
                                <small class="text-muted">Estudiantes Procesados</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (isset($estadisticas_globales['años_procesados']) && count($estadisticas_globales['años_procesados']) > 0): ?>
                    <hr>
                    <h6>Desglose por Año:</h6>
                    <div class="row">
                        <?php foreach ($estadisticas_globales['años_procesados'] as $anio => $stats): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card border-light">
                                <div class="card-body text-center">
                                    <h6 class="card-title"><?= $anio ?>º Año</h6>
                                    <div class="small">
                                        <div>✅ <?= $stats['archivos_exitosos'] ?> archivos</div>
                                        <div>📝 <?= $stats['contenidos_creados'] ?> contenidos</div>
                                        <div>📊 <?= $stats['calificaciones_procesadas'] ?> calificaciones</div>
                                        <?php if ($stats['archivos_error'] > 0): ?>
                                        <div class="text-danger">❌ <?= $stats['archivos_error'] ?> errores</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Formularios de importación -->
            <?php if (count($cursos) > 0): ?>
            
            <!-- Importación por año específico -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-filter"></i> Opción 1: Importación por Año Específico
                    </h5>
                    <p class="card-text mb-0">Procese archivos de un año en particular con control detallado</p>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="curso_especifico" class="form-label">Curso:</label>
                                <select name="curso_especifico" id="curso_especifico" class="form-select" required>
                                    <option value="">-- Seleccione un curso --</option>
                                    <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" data-anio="<?= $curso['anio'] ?>">
                                        <?= htmlspecialchars($curso['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Por favor seleccione un curso.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="anio_especifico" class="form-label">Año:</label>
                                <select name="anio_especifico" id="anio_especifico" class="form-select" required>
                                    <option value="">-- Se detectará automáticamente --</option>
                                    <option value="1">1er Año</option>
                                    <option value="2">2do Año</option>
                                    <option value="3">3er Año</option>
                                    <option value="4">4to Año</option>
                                    <option value="5">5to Año</option>
                                    <option value="6">6to Año</option>
                                    <option value="7">7mo Año</option>
                                </select>
                                <div class="invalid-feedback">Por favor seleccione el año.</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="archivos_por_anio" class="form-label">Archivos CSV del Año:</label>
                            <input type="file" name="archivos_por_anio[]" id="archivos_por_anio" 
                                   class="form-control" accept=".csv" multiple required>
                            <div class="form-text">
                                <strong>Seleccione todos los archivos CSV del año elegido.</strong><br>
                                Ejemplo: <code>3º AÑO(1).csv, 3° AÑO(2).csv, 3º AÑO(12).csv</code>
                            </div>
                            <div class="invalid-feedback">Por favor seleccione al menos un archivo CSV.</div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" name="procesar_por_año" class="btn btn-primary btn-lg">
                                <i class="bi bi-upload"></i> Procesar Año Específico
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Separador -->
            <div class="text-center my-4">
                <div class="border-top w-50 mx-auto"></div>
                <span class="badge bg-secondary px-3 py-2">O</span>
            </div>
            
            <!-- Importación completa -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-collection"></i> Opción 2: Importación Masiva Completa
                    </h5>
                    <p class="card-text mb-0">Procese archivos de TODOS los años en una sola operación</p>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <h6 class="alert-heading">
                            <i class="bi bi-exclamation-triangle"></i> Importación Masiva Completa
                        </h6>
                        <div class="row">
                            <div class="col-md-8">
                                <p class="mb-1"><strong>Esta opción procesará automáticamente:</strong></p>
                                <ul class="mb-0">
                                    <li>Archivos de <strong>todos los años</strong> (1º a 7º) simultáneamente</li>
                                    <li>Detección <strong>automática del año</strong> por nombre de archivo</li>
                                    <li>Asignación <strong>automática al curso</strong> correspondiente</li>
                                    <li>Procesamiento <strong>optimizado con cache</strong> para mejor rendimiento</li>
                                    <li>Estadísticas <strong>detalladas por año</strong> y globales</li>
                                </ul>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-danger mb-0">
                                    <small>
                                        <strong>⚠️ RECOMENDACIONES:</strong><br>
                                        • Use esta opción para migración inicial completa<br>
                                        • Asegúrese de tener todos los cursos creados<br>
                                        • Verifique que las materias tengan profesores<br>
                                        • Tiempo estimado: 10-30 minutos
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        
                        <div class="mb-3">
                            <label for="todos_archivos" class="form-label">Todos los Archivos CSV:</label>
                            <input type="file" name="todos_archivos[]" id="todos_archivos" 
                                   class="form-control" accept=".csv" multiple required>
                            <div class="form-text">
                                <strong>Seleccione TODOS los archivos CSV de TODOS los años.</strong><br>
                                El sistema detectará automáticamente el año y curso de cada archivo.<br>
                                <strong>Formatos soportados:</strong> <code>3º AÑO(1).csv</code>, <code>3° AÑO(1).csv</code>, <code>7º AÑO(17).csv</code>, etc.
                            </div>
                            <div class="invalid-feedback">Por favor seleccione al menos un archivo CSV.</div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="alert alert-info">
                                <h6 class="alert-heading">
                                    <i class="bi bi-info-circle"></i> Configuración Automática Global
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li><strong>Bimestre:</strong> 1er bimestre (todos los contenidos)</li>
                                            <li><strong>Fecha:</strong> 17 de abril de 2025 (fija)</li>
                                            <li><strong>Tipo:</strong> Evaluación cualitativa (todos)</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li><strong>Detección:</strong> Año automático por nombre archivo</li>
                                            <li><strong>Asignación:</strong> Curso automático por año detectado</li>
                                            <li><strong>Calificaciones:</strong> A=Acreditado, 0=No Acreditado, N/C=No Corresponde</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" name="procesar_todos" class="btn btn-success btn-lg" onclick="return confirmarImportacionCompleta()">
                                <i class="bi bi-rocket"></i> Procesar Importación Masiva Completa
                            </button>
                        </div>
                    </form>
                    
                    <!-- Indicador de progreso para importación completa -->
                    <div id="progreso_completo" style="display: none;" class="mt-4">
                        <div class="alert alert-info">
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm me-3" role="status">
                                    <span class="visually-hidden">Procesando...</span>
                                </div>
                                <div>
                                    <strong>Procesando importación masiva completa...</strong><br>
                                    <small>Detectando años, creando contenidos y procesando calificaciones de todos los archivos.</small>
                                </div>
                            </div>
                            <div class="progress mt-3">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 100%"></div>
                            </div>
                            <div class="mt-2">
                                <small>
                                    <strong>Tiempo estimado según cantidad de archivos:</strong>
                                    <ul class="mb-0 mt-1 small">
                                        <li>10-20 archivos: 5-10 minutos</li>
                                        <li>50-100 archivos: 15-25 minutos</li>
                                        <li>100+ archivos: 20-30 minutos</li>
                                    </ul>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Información técnica sobre el procesamiento -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-gear"></i> Información Técnica del Procesamiento
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Lógica de Procesamiento:</h6>
                            <ol class="small">
                                <li><strong>Detección de archivos:</strong> Analiza nombres para determinar año y materia</li>
                                <li><strong>Búsqueda de línea A3:</strong> Localiza contenidos en fila 3 del CSV</li>
                                <li><strong>Extracción de contenidos:</strong> Filtra títulos válidos (no "0", no vacíos)</li>
                                <li><strong>Creación en BD:</strong> Inserta contenidos si no existen y materia tiene profesor</li>
                                <li><strong>Procesamiento de estudiantes:</strong> Busca líneas con datos de alumnos</li>
                                <li><strong>Calificaciones:</strong> Mapea A/0/N-C a Acreditado/No Acreditado/No Corresponde</li>
                                <li><strong>Cache y optimización:</strong> Reutiliza consultas para mejor rendimiento</li>
                            </ol>
                        </div>
                        <div class="col-md-6">
                            <h6>Mapeo de Archivos a Materias:</h6>
                            <div class="alert alert-light">
                                <small>
                                    <strong>Ejemplos de detección automática:</strong><br>
                                    • <code>1º AÑO(7).csv</code> → 1er Año, Materia #7 (Matemática)<br>
                                    • <code>3º AÑO(12).csv</code> → 3er Año, Materia #12 (Matemática)<br>
                                    • <code>6º AÑO(21).csv</code> → 6to Año, Materia #21 (MASTER)<br>
                                    <br>
                                    <strong>Patrones soportados:</strong><br>
                                    • <code>Nº AÑO(X).csv</code> (con º ordinal)<br>
                                    • <code>N° AÑO(X).csv</code> (con ° grado)<br>
                                    • <code>N AÑO(X).csv</code> (sin símbolo)<br>
                                    • <code>NAÑOX.csv</code> (sin espacios ni paréntesis)<br>
                                </small>
                            </div>
                            
                            <h6>Configuración de Rendimiento:</h6>
                            <ul class="small mb-0">
                                <li><strong>Tiempo límite:</strong> 30 minutos</li>
                                <li><strong>Memoria:</strong> 1GB asignado</li>
                                <li><strong>Cache de estudiantes:</strong> Por materia</li>
                                <li><strong>Cache de materias:</strong> Por curso</li>
                                <li><strong>Procesamiento:</strong> Secuencial optimizado</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                No hay cursos disponibles en el ciclo lectivo actual. 
                <strong>Debe crear los cursos antes de poder importar contenidos.</strong>
                <br><small>Contacte al administrador para configurar los cursos del ciclo lectivo.</small>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<script>
// Función para confirmar importación completa
function confirmarImportacionCompleta() {
    const archivos = document.getElementById('todos_archivos').files;
    
    if (archivos.length === 0) {
        alert('Por favor seleccione al menos un archivo CSV.');
        return false;
    }
    
    // Analizar archivos seleccionados
    let añosDetectados = new Set();
    let archivosValidos = 0;
    let archivosInvalidos = [];
    
    for (let i = 0; i < archivos.length; i++) {
        const nombre = archivos[i].name;
        const match = nombre.match(/(\d+)[º°]?\s*AÑO.*\((\d+)\)\.csv$/i);
        
        if (match) {
            añosDetectados.add(match[1]);
            archivosValidos++;
        } else {
            archivosInvalidos.push(nombre);
        }
    }
    
    const añosArray = Array.from(añosDetectados).sort();
    const mensajeAños = añosArray.length > 0 ? añosArray.join('º, ') + 'º año' : 'años no detectados';
    
    let mensaje = `¿Está seguro de procesar ${archivosValidos} archivo(s) válido(s) de ${añosArray.length} año(s) diferente(s)?\n\n`;
    mensaje += `AÑOS DETECTADOS: ${mensajeAños}\n\n`;
    mensaje += `ESTE PROCESO:\n`;
    mensaje += `• Detectará automáticamente el año y materia de cada archivo\n`;
    mensaje += `• Creará contenidos en el 1er bimestre con fecha 17/04/2025\n`;
    mensaje += `• Procesará calificaciones de estudiantes automáticamente\n`;
    mensaje += `• Puede tomar 10-30 minutos según la cantidad de archivos\n`;
    mensaje += `• Utilizará cache para optimizar el rendimiento\n\n`;
    
    if (archivosInvalidos.length > 0) {
        mensaje += `⚠️ ARCHIVOS CON FORMATO INCORRECTO (serán omitidos):\n`;
        mensaje += archivosInvalidos.slice(0, 5).join('\n');
        if (archivosInvalidos.length > 5) {
            mensaje += `\n... y ${archivosInvalidos.length - 5} más`;
        }
        mensaje += '\n\n';
    }
    
    mensaje += `¿Desea continuar con la importación masiva completa?`;
    
    if (confirm(mensaje)) {
        // Mostrar indicador de progreso
        document.getElementById('progreso_completo').style.display = 'block';
        
        // Deshabilitar botón para evitar doble envío
        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-clock-history"></i> Procesando Importación Masiva...';
        
        // Scroll hasta el indicador de progreso
        document.getElementById('progreso_completo').scrollIntoView({ behavior: 'smooth' });
        
        return true;
    }
    
    return false;
}

// Sincronización automática de curso y año
document.addEventListener('DOMContentLoaded', function() {
    const cursoSelect = document.getElementById('curso_especifico');
    const anioSelect = document.getElementById('anio_especifico');
    
    if (cursoSelect && anioSelect) {
        cursoSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const anio = selectedOption.getAttribute('data-anio');
            
            if (anio) {
                anioSelect.value = anio;
            }
        });
    }
    
    // Validación en tiempo real para archivos por año
    const archivosPorAnio = document.getElementById('archivos_por_anio');
    if (archivosPorAnio) {
        archivosPorAnio.addEventListener('change', function() {
            validarArchivosPorAño(this.files);
        });
    }
    
    // Validación en tiempo real para todos los archivos
    const todosArchivos = document.getElementById('todos_archivos');
    if (todosArchivos) {
        todosArchivos.addEventListener('change', function() {
            validarTodosLosArchivos(this.files);
        });
    }
    
    // Validación de formularios Bootstrap
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

function validarArchivosPorAño(archivos) {
    const anioSeleccionado = document.getElementById('anio_especifico').value;
    
    if (!anioSeleccionado) {
        return;
    }
    
    let archivosValidos = 0;
    let archivosDelAñoIncorrecto = 0;
    let archivosFormatoIncorrecto = 0;
    
    for (let i = 0; i < archivos.length; i++) {
        const nombre = archivos[i].name;
        const match = nombre.match(/(\d+)[º°]?\s*AÑO.*\((\d+)\)\.csv$/i);
        
        if (match) {
            const anioArchivo = match[1];
            if (anioArchivo === anioSeleccionado) {
                archivosValidos++;
            } else {
                archivosDelAñoIncorrecto++;
            }
        } else {
            archivosFormatoIncorrecto++;
        }
    }
    
    // Mostrar información
    let mensaje = '';
    if (archivosValidos > 0) {
        mensaje += `✅ ${archivosValidos} archivo(s) válido(s) para ${anioSeleccionado}º año`;
    }
    if (archivosDelAñoIncorrecto > 0) {
        if (mensaje) mensaje += '\n';
        mensaje += `⚠️ ${archivosDelAñoIncorrecto} archivo(s) de año diferente`;
    }
    if (archivosFormatoIncorrecto > 0) {
        if (mensaje) mensaje += '\n';
        mensaje += `❌ ${archivosFormatoIncorrecto} archivo(s) con formato incorrecto`;
    }
    
    mostrarInfoValidacion('info_archivos_por_anio', mensaje, archivosValidos > 0);
}

function validarTodosLosArchivos(archivos) {
    let añosDetectados = new Set();
    let archivosValidosPorAño = {};
    let archivosInvalidos = 0;
    
    for (let i = 0; i < archivos.length; i++) {
        const nombre = archivos[i].name;
        const match = nombre.match(/(\d+)[º°]?\s*AÑO.*\((\d+)\)\.csv$/i);
        
        if (match) {
            const anio = match[1];
            añosDetectados.add(anio);
            archivosValidosPorAño[anio] = (archivosValidosPorAño[anio] || 0) + 1;
        } else {
            archivosInvalidos++;
        }
    }
    
    const totalValidos = Object.values(archivosValidosPorAño).reduce((a, b) => a + b, 0);
    const añosArray = Array.from(añosDetectados).sort();
    
    let mensaje = '';
    if (totalValidos > 0) {
        mensaje += `✅ ${totalValidos} archivo(s) válido(s) detectado(s)`;
        if (añosArray.length > 0) {
            mensaje += `\n📚 Años detectados: ${añosArray.join('º, ')}º`;
            
            // Desglose por año
            for (const anio of añosArray) {
                mensaje += `\n   • ${anio}º año: ${archivosValidosPorAño[anio]} archivo(s)`;
            }
        }
    }
    if (archivosInvalidos > 0) {
        if (mensaje) mensaje += '\n';
        mensaje += `❌ ${archivosInvalidos} archivo(s) con formato incorrecto`;
    }
    
    mostrarInfoValidacion('info_todos_archivos', mensaje, totalValidos > 0);
}

function mostrarInfoValidacion(elementId, mensaje, esValido) {
    let infoDiv = document.getElementById(elementId);
    if (!infoDiv) {
        infoDiv = document.createElement('div');
        infoDiv.id = elementId;
        infoDiv.className = 'mt-2 small';
        
        // Buscar el input correspondiente
        const inputId = elementId.replace('info_', '');
        const input = document.getElementById(inputId);
        if (input) {
            input.parentNode.appendChild(infoDiv);
        }
    }
    
    const className = esValido ? 'text-success' : 'text-danger';
    infoDiv.innerHTML = `<span class="${className}">${mensaje.replace(/\n/g, '<br>')}</span>`;
}
</script>

<?php
// Limpiar buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>