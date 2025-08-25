<?php
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
 * importar_contenidos_individual.php - Importación individual de contenidos con debugging
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Versión simplificada para procesar un archivo a la vez y mostrar debugging detallado
 */

// Iniciar buffer de salida para evitar problemas con headers
ob_start();

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir archivos necesarios
require_once 'config.php';

// Verificar permisos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección. Solo administradores y directivos pueden importar contenidos.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Procesar formulario
$resultado_procesamiento = null;
$debug_info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Configurar tiempo de ejecución extendido
    set_time_limit(300);
    ini_set('memory_limit', '256M');
    
    try {
        $db = Database::getInstance();
        
        // Obtener ciclo lectivo activo
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
        
        if ($cicloLectivoId == 0) {
            throw new Exception('No hay un ciclo lectivo activo configurado');
        }
        
        $resultado_procesamiento = procesarArchivoIndividual($_FILES['archivo_individual'], $_POST, $db, $cicloLectivoId);
        
        if ($resultado_procesamiento['success']) {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $resultado_procesamiento['message'];
            $_SESSION['message_type'] = 'danger';
        }
        
        // Guardar información de debug para mostrar
        $debug_info = $resultado_procesamiento['debug'] ?? [];
        
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
 * Procesar archivo individual con debugging detallado
 */
function procesarArchivoIndividual($archivo, $post, $db, $cicloLectivoId) {
    $debug = [];
    
    try {
        $cursoId = intval($post['curso']);
        $materiaId = intval($post['materia']);
        $bimestre = 1; // Siempre 1er bimestre
        $fechaContenido = '2025-04-17'; // Fecha fija
        
        $debug[] = "Parámetros: Curso ID=$cursoId, Materia ID=$materiaId, Bimestre=$bimestre";
        
        // Validar archivo
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al subir archivo: ' . $archivo['error'], 'debug' => $debug];
        }
        
        $debug[] = "Archivo subido correctamente: " . $archivo['name'] . " (" . $archivo['size'] . " bytes)";
        
        // Verificar que la materia existe y obtener información
        $materiaInfo = $db->fetchOne(
            "SELECT mp.id, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, m.nombre, m.codigo
             FROM materias_por_curso mp 
             JOIN materias m ON mp.materia_id = m.id 
             WHERE mp.id = ? AND mp.curso_id = ?",
            [$materiaId, $cursoId]
        );
        
        if (!$materiaInfo) {
            return ['success' => false, 'message' => 'Materia no encontrada en el curso especificado', 'debug' => $debug];
        }
        
        $debug[] = "Materia encontrada: " . $materiaInfo['nombre'] . " (ID: " . $materiaInfo['id'] . ")";
        
        // Verificar profesor
        if (empty($materiaInfo['profesor_id'])) {
            return ['success' => false, 'message' => 'La materia no tiene profesor asignado', 'debug' => $debug];
        }
        
        $debug[] = "Profesor asignado: ID " . $materiaInfo['profesor_id'];
        
        // Leer y procesar archivo CSV
        $rutaTemporal = $archivo['tmp_name'];
        $contenido = file_get_contents($rutaTemporal);
        
        if ($contenido === false) {
            return ['success' => false, 'message' => 'No se pudo leer el archivo', 'debug' => $debug];
        }
        
        $debug[] = "Archivo leído: " . strlen($contenido) . " caracteres";
        
        // Convertir encoding si es necesario
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', ['ISO-8859-1', 'Windows-1252', 'UTF-8']);
            $debug[] = "Encoding convertido a UTF-8";
        }
        
        // Dividir en líneas
        $lineas = str_getcsv($contenido, "\n");
        $debug[] = "Total de líneas: " . count($lineas);
        
        // Mostrar las primeras líneas para debugging
        for ($i = 0; $i < min(5, count($lineas)); $i++) {
            $debug[] = "Línea $i: " . substr($lineas[$i], 0, 100) . (strlen($lineas[$i]) > 100 ? '...' : '');
        }
        
        // Buscar la línea A3 (contenidos) - línea 3 del archivo (índice 2)
        $lineaContenidos = null;
        $posicionLinea = -1;
        
        // Según tu CSV, la línea 3 (índice 2) contiene los contenidos
        if (isset($lineas[2])) {
            $lineaContenidos = str_getcsv($lineas[2], ",");
            $posicionLinea = 2;
            $debug[] = "Línea de contenidos encontrada en posición 2 (línea 3): " . count($lineaContenidos) . " campos";
        } else {
            // Fallback: buscar automáticamente
            for ($i = 0; $i < min(10, count($lineas)); $i++) {
                if (isset($lineas[$i])) {
                    $lineaActual = str_getcsv($lineas[$i], ",");
                    $debug[] = "Analizando línea $i: " . count($lineaActual) . " campos";
                    
                    // Buscar la línea que contiene "AÑO" en los primeros campos
                    if (count($lineaActual) > 5) {
                        $primerCampo = trim($lineaActual[0]);
                        if (preg_match('/\d+er\s+AÑO/i', $primerCampo)) {
                            $lineaContenidos = $lineaActual;
                            $posicionLinea = $i;
                            $debug[] = "Línea de contenidos detectada por patrón AÑO en posición: $i";
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$lineaContenidos) {
            return ['success' => false, 'message' => 'No se encontró la línea de contenidos en el archivo', 'debug' => $debug];
        }
        
        $debug[] = "Línea de contenidos encontrada con " . count($lineaContenidos) . " campos";
        
        // Buscar contenidos válidos según la estructura real del CSV
        $titulosContenidos = [];
        $inicioContenidos = 2; // Empezar desde la columna 2 (después de "1er AÑO 2025" y campo vacío)
        $finContenidos = count($lineaContenidos);
        
        // Buscar donde terminan los contenidos - buscar "Valoración preliminar"
        for ($i = $inicioContenidos; $i < count($lineaContenidos); $i++) {
            $campo = trim($lineaContenidos[$i]);
            if (preg_match('/valoraci[óo]n\s+preliminar|trayectoria|desempe[ñn]o|observaciones/i', $campo)) {
                $finContenidos = $i;
                $debug[] = "Fin de contenidos detectado en posición $i: '$campo'";
                break;
            }
        }
        
        $debug[] = "Rango de contenidos: columnas $inicioContenidos a $finContenidos";
        
        // Extraer títulos de contenidos según tu estructura
        for ($i = $inicioContenidos; $i < $finContenidos; $i++) {
            $titulo = trim($lineaContenidos[$i]);
            
            $debug[] = "Posición $i: '$titulo'";
            
            // Solo agregar si no es "0" o vacío y tiene contenido válido
            if (!empty($titulo) && $titulo !== '0' && strlen($titulo) > 2 && !is_numeric($titulo)) {
                $titulosContenidos[] = [
                    'titulo' => $titulo,
                    'orden' => (count($titulosContenidos) + 1)
                ];
                $debug[] = "✅ Contenido válido encontrado: '$titulo' (orden: " . count($titulosContenidos) . ")";
            } else {
                $debug[] = "❌ Contenido omitido: '$titulo' (vacío, 0, o inválido)";
            }
        }
        
        if (empty($titulosContenidos)) {
            return ['success' => false, 'message' => 'No se encontraron contenidos válidos para crear', 'debug' => $debug];
        }
        
        $debug[] = "Total contenidos a crear: " . count($titulosContenidos);
        
        // Crear contenidos en la base de datos
        $contenidosCreados = 0;
        $contenidosOmitidos = 0;
        $contenidosIds = []; // Para almacenar los IDs de contenidos creados/existentes
        
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
                $debug[] = "Contenido omitido (ya existe): " . $contenidoData['titulo'] . " (ID: {$existente['id']})";
                continue;
            }
            
            // Crear el contenido
            try {
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
                $debug[] = "Contenido creado exitosamente: " . $contenidoData['titulo'] . " (ID: $nuevoId)";
                
            } catch (Exception $e) {
                $debug[] = "Error al crear contenido '" . $contenidoData['titulo'] . "': " . $e->getMessage();
            }
        }
        
        $debug[] = "Contenidos procesados. Iniciando procesamiento de calificaciones...";
        
        // NUEVO: Procesar calificaciones de estudiantes
        $calificacionesProcesadas = 0;
        $calificacionesOmitidas = 0;
        $estudiantesProcesados = 0;
        
        // Buscar líneas de estudiantes (a partir de la línea que contiene datos de estudiantes)
        for ($lineaIndex = 0; $lineaIndex < count($lineas); $lineaIndex++) {
            $lineaActual = str_getcsv($lineas[$lineaIndex], ",");
            
            // Buscar líneas que empiecen con número y tengan nombre de estudiante
            if (count($lineaActual) > 5 && 
                is_numeric(trim($lineaActual[0])) && 
                !empty(trim($lineaActual[1])) && 
                strlen(trim($lineaActual[1])) > 5) {
                
                $numeroEstudiante = intval(trim($lineaActual[0]));
                $nombreEstudiante = trim($lineaActual[1], ' "');
                
                $debug[] = "Procesando estudiante: $numeroEstudiante - $nombreEstudiante";
                
                // Buscar el estudiante en la base de datos
                $estudiante = buscarEstudiantePorNombre($db, $nombreEstudiante, $materiaInfo['id']);
                
                if (!$estudiante) {
                    $debug[] = "⚠️ Estudiante no encontrado en BD: $nombreEstudiante";
                    continue;
                }
                
                $debug[] = "✅ Estudiante encontrado: {$estudiante['apellido']}, {$estudiante['nombre']} (ID: {$estudiante['id']})";
                $estudiantesProcesados++;
                
                // Procesar calificaciones de cada contenido para este estudiante
                foreach ($titulosContenidos as $contenidoData) {
                    $ordenContenido = $contenidoData['orden'];
                    $contenidoId = $contenidosIds[$ordenContenido] ?? null;
                    
                    if (!$contenidoId) {
                        $debug[] = "⚠️ No se encontró ID para contenido orden $ordenContenido";
                        continue;
                    }
                    
                    // La calificación está en la posición: 2 + (orden - 1)
                    // Posición 2 = primer contenido, posición 3 = segundo contenido, etc.
                    $posicionCalificacion = 1 + $ordenContenido; // +1 porque después del nombre empieza en posición 2
                    
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
                                if (!empty($calificacionRaw)) {
                                    $debug[] = "⚠️ Calificación desconocida '$calificacionRaw' para {$estudiante['apellido']} en contenido {$contenidoData['titulo']}";
                                }
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
                            $debug[] = "📝 Calificación actualizada: {$estudiante['apellido']} - {$contenidoData['titulo']} = $calificacionFinal";
                        } else {
                            // Crear nueva calificación
                            $db->insert(
                                "INSERT INTO contenidos_calificaciones 
                                 (contenido_id, estudiante_id, calificacion_cualitativa, fecha_evaluacion)
                                 VALUES (?, ?, ?, date('now'))",
                                [$contenidoId, $estudiante['id'], $calificacionFinal]
                            );
                            $debug[] = "✅ Calificación creada: {$estudiante['apellido']} - {$contenidoData['titulo']} = $calificacionFinal";
                        }
                        
                        $calificacionesProcesadas++;
                    }
                }
            }
        }
        
        $mensaje = "Procesado archivo '{$archivo['name']}': $contenidosCreados contenidos creados";
        if ($contenidosOmitidos > 0) {
            $mensaje .= ", $contenidosOmitidos omitidos (ya existían)";
        }
        $mensaje .= " | $calificacionesProcesadas calificaciones procesadas para $estudiantesProcesados estudiantes";
        
        return [
            'success' => true,
            'message' => $mensaje,
            'debug' => $debug,
            'creados' => $contenidosCreados,
            'omitidos' => $contenidosOmitidos,
            'calificaciones' => $calificacionesProcesadas,
            'estudiantes' => $estudiantesProcesados
        ];
        
    } catch (Exception $e) {
        $debug[] = "Error: " . $e->getMessage();
        return ['success' => false, 'message' => 'Error al procesar archivo: ' . $e->getMessage(), 'debug' => $debug];
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
                        <i class="bi bi-upload"></i> Importar Contenidos - Modo Individual
                    </h1>
                    <p class="text-muted">Procese un archivo CSV a la vez con información detallada de debugging</p>
                </div>
                <div>
                    <a href="importar_contenidos.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a Importación Masiva
                    </a>
                </div>
            </div>
            
            <!-- Información sobre debugging -->
            <div class="alert alert-info mb-4">
                <h6 class="alert-heading">
                    <i class="bi bi-bug"></i> Modo de Debugging Activado - Contenidos y Calificaciones
                </h6>
                <p class="mb-1">Este modo procesa un archivo a la vez y muestra información detallada del proceso para identificar problemas.</p>
                <p class="mb-1"><strong>Procesa:</strong></p>
                <ul class="mb-1">
                    <li><strong>Contenidos:</strong> Extrae títulos de la línea A3 del CSV</li>
                    <li><strong>Calificaciones:</strong> Procesa las calificaciones de estudiantes (A=Acreditado, 0=No Acreditado, N/C=No Corresponde)</li>
                </ul>
                <p class="mb-0"><strong>Utilice este modo cuando:</strong> Los archivos no se procesen correctamente en el modo masivo.</p>
            </div>
            
            <!-- Formulario de importación individual -->
            <?php if (count($cursos) > 0): ?>
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-file-earmark-text"></i> Importación Individual de Contenidos
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="curso" class="form-label">Curso:</label>
                                <select name="curso" id="curso" class="form-select" required onchange="cargarMaterias()">
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
                                <label for="materia" class="form-label">Materia:</label>
                                <select name="materia" id="materia" class="form-select" required disabled>
                                    <option value="">-- Primero seleccione un curso --</option>
                                </select>
                                <div class="invalid-feedback">
                                    Por favor seleccione una materia.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="archivo_individual" class="form-label">Archivo CSV:</label>
                            <input type="file" name="archivo_individual" id="archivo_individual" 
                                   class="form-control" accept=".csv" required>
                            <div class="form-text">
                                Seleccione UN archivo CSV para procesar con debugging detallado.
                            </div>
                            <div class="invalid-feedback">
                                Por favor seleccione un archivo CSV.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">
                                    <i class="bi bi-info-circle"></i> Configuración para Debugging
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Contenidos:</strong>
                                        <ul class="mb-0">
                                            <li><strong>Bimestre:</strong> 1er bimestre (automático)</li>
                                            <li><strong>Fecha:</strong> 17 de abril de 2025 (automático)</li>
                                            <li><strong>Tipo:</strong> Evaluación cualitativa (automático)</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Calificaciones:</strong>
                                        <ul class="mb-0">
                                            <li><strong>A</strong> → Acreditado</li>
                                            <li><strong>0</strong> → No Acreditado</li>
                                            <li><strong>N/C</strong> → No Corresponde</li>
                                            <li><strong>Debugging:</strong> Información detallada</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-bug"></i> Procesar Contenidos y Calificaciones
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                No hay cursos disponibles en el ciclo lectivo actual. Contacte al administrador.
            </div>
            <?php endif; ?>
            
            <!-- Mostrar información de debugging si existe -->
            <?php if (!empty($debug_info)): ?>
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-terminal"></i> Información de Debugging
                    </h5>
                </div>
                <div class="card-body">
                    <div class="bg-dark text-light p-3" style="font-family: monospace; max-height: 400px; overflow-y: auto;">
                        <?php foreach ($debug_info as $index => $info): ?>
                        <div class="mb-1">
                            <span class="text-warning">[<?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?>]</span> 
                            <?= htmlspecialchars($info) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<script>
// Cargar materias cuando se selecciona un curso
function cargarMaterias() {
    const cursoId = document.getElementById('curso').value;
    const materiaSelect = document.getElementById('materia');
    
    if (!cursoId) {
        materiaSelect.innerHTML = '<option value="">-- Primero seleccione un curso --</option>';
        materiaSelect.disabled = true;
        return;
    }
    
    // Mostrar loading
    materiaSelect.innerHTML = '<option value="">-- Cargando materias... --</option>';
    materiaSelect.disabled = true;
    
    // Hacer petición AJAX para obtener materias
    fetch('obtener_materias_curso.php?curso_id=' + cursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.materias) {
                let html = '<option value="">-- Seleccione una materia --</option>';
                data.materias.forEach(materia => {
                    const profesorInfo = materia.profesor_id ? ' ✓' : ' ⚠️ Sin profesor';
                    html += `<option value="${materia.id}">${materia.nombre}${profesorInfo}</option>`;
                });
                materiaSelect.innerHTML = html;
                materiaSelect.disabled = false;
            } else {
                materiaSelect.innerHTML = '<option value="">-- Error al cargar materias --</option>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            materiaSelect.innerHTML = '<option value="">-- Error de conexión --</option>';
        });
}

// Validación de formulario Bootstrap
document.addEventListener('DOMContentLoaded', function() {
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
</script>

<?php
// Limpiar buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>