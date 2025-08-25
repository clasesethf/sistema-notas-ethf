<?php
/**
 * edicion_masiva_materias.php - Editor visual masivo de propiedades de materias (CORREGIDO)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * CORRECCIÓN: Se solucionaron los errores de variables undefined $db y $cicloLectivoId
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos)
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    // Si es una petición AJAX, devolver JSON
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para acceder a esta sección']);
        exit;
    }
    // Si no es AJAX, redireccionar normalmente
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Incluir el encabezado
require_once 'header.php';

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

$mensaje = '';
$tipo = '';

/**
 * Función para detectar automáticamente el tipo de materia (MEJORADA)
 * Incluye detección precisa de las 68 materias de taller oficiales
 */
function detectarTipoMateria($materia) {
    $nombre = strtolower($materia['nombre'] ?? '');
    $codigo = $materia['codigo'] ?? '';
    $ano = $materia['curso_anio'] ?? 1;
    
    // 1. CONSTRUCCIÓN DE CIUDADANÍA (6 materias - 2° y 3° año)
    if (in_array($ano, [2, 3])) {
        if (stripos($nombre, 'construccion') !== false && stripos($nombre, 'ciudadania') !== false) {
            return ['tipo' => 'ciudadania', 'es_oficial' => true, 'categoria' => 'Construcción de la Ciudadanía'];
        }
        if (stripos($nombre, 'constr') !== false && stripos($nombre, 'ciud') !== false) {
            return ['tipo' => 'ciudadania', 'es_oficial' => true, 'categoria' => 'Construcción de la Ciudadanía'];
        }
        if (in_array($codigo, ['CCE', 'CCM', 'CCM1'])) {
            return ['tipo' => 'ciudadania', 'es_oficial' => true, 'categoria' => 'Construcción de la Ciudadanía'];
        }
    }
    
    // 2. MATERIAS DE TALLER OFICIALES (68 materias según PDF)
    $talleresPorAno = [
        1 => [ // 1° año - 3 talleres
            'LDT1' => 'Laboratorio de Dibujo Técnico I',
            'PT1' => 'Práctica de Taller I', 
            'SPT1' => 'Seguridad en Práctica de Taller I'
        ],
        2 => [ // 2° año - 4 talleres
            '2FE' => 'Formación Ética',
            'LDT2' => 'Laboratorio de Dibujo Técnico II',
            'PT2' => 'Práctica de Taller II',
            'SPT2' => 'Seguridad en Práctica de Taller II'
        ],
        3 => [ // 3° año - 6 talleres
            '3F' => 'Fundición',
            '3F1' => 'Fundición I',
            '3IED1' => 'Instalaciones Eléctricas Domiciliarias I',
            'LDT3' => 'Laboratorio de Dibujo Técnico III',
            'PT3' => 'Práctica de Taller III',
            'SPT3' => 'Seguridad en Práctica de Taller III'
        ],
        4 => [ // 4° año - 11 talleres
            '4DA' => 'Diseño Asistido',
            '4DT' => 'Dibujo Técnico',
            '4E' => 'Electricidad',
            '4EB' => 'Electrónica Básica',
            '4IED2' => 'Instalaciones Eléctricas Domiciliarias II',
            '4M' => 'Mecanizado',
            '4M1' => 'Mecanizado I',
            '4M2' => 'Mecanizado II',
            '4PA' => 'Proyecto y Automatización',
            '4S' => 'Soldadura',
            '4T' => 'Tornería'
        ],
        5 => [ // 5° año - 9 talleres
            '5C' => 'CNC',
            '5C1' => 'CNC I',
            '5CF1' => 'Control y Fabricación I',
            '5CF2' => 'Control y Fabricación II',
            '5ED' => 'Electrónica Digital',
            '5M3' => 'Mecanizado III',
            '5ME' => 'Mantenimiento Eléctrico',
            '5MTT' => 'Máquinas, Técnicas y Tecnologías',
            '5R' => 'Robótica'
        ],
        6 => [ // 6° año - 11 talleres
            '6C2' => 'CNC II',
            '6CF' => 'Control y Fabricación',
            '6CT' => 'Control de Calidad y Técnicas',
            '6DEE' => 'Diseño de Equipos Eléctricos',
            '6L' => 'Laboratorio',
            '6LME' => 'Laboratorio de Mediciones Eléctricas',
            '6M' => 'Mantenimiento',
            '6M1' => 'Mantenimiento I',
            '6MCI1' => 'Mantenimiento y Control Industrial I',
            '6P' => 'Proyecto',
            '6SC' => 'Sistemas de Control'
        ],
        7 => [ // 7° año - 11 talleres
            '7C3' => 'CNC III',
            '7CC' => 'Control de Calidad',
            '7CE' => 'Control Eléctrico',
            '7DE' => 'Diseño Eléctrico',
            '7DP' => 'Diseño de Proyecto',
            '7LMC' => 'Laboratorio de Mediciones y Control',
            '7MCI2' => 'Mantenimiento y Control Industrial II',
            '7ME' => 'Mantenimiento Eléctrico',
            '7ME1' => 'Mantenimiento Eléctrico I',
            '7MM' => 'Mantenimiento Mecánico',
            '7RI' => 'Robótica Industrial'
        ]
    ];
    
    // Verificar si es una materia de taller oficial
    if (isset($talleresPorAno[$ano][$codigo])) {
        $nombreOficial = $talleresPorAno[$ano][$codigo];
        return ['tipo' => 'taller', 'es_oficial' => true, 'categoria' => 'Taller Oficial', 'nombre_oficial' => $nombreOficial];
    }
    
    // 3. DETECCIÓN ADICIONAL DE TALLERES (patrones de código)
    if (preg_match('/^(\d+)(PT|LT|ST|MEA|DPM|IAE|DT|LME|LMCC|MME|PDE|PDIE)/', $codigo)) {
        return ['tipo' => 'taller', 'es_oficial' => false, 'categoria' => 'Taller Detectado por Patrón'];
    }
    
    // 4. DETECCIÓN POR PALABRAS CLAVE EN NOMBRE
    $palabrasTaller = ['taller', 'laboratorio', 'mecanizado', 'soldadura', 'electricidad', 'electronica', 
                       'automatizacion', 'dibujo', 'cad', 'cnc', 'fundicion', 'instalaciones', 'practica'];
    
    foreach ($palabrasTaller as $palabra) {
        if (stripos($nombre, $palabra) !== false) {
            return ['tipo' => 'taller', 'es_oficial' => false, 'categoria' => 'Taller Detectado por Nombre'];
        }
    }
    
    // 5. MATERIA BÁSICA (por defecto)
    return ['tipo' => 'basica', 'es_oficial' => true, 'categoria' => 'Materia Básica'];
}

/**
 * Función para aplicar presets automáticos
 */
function aplicarPreset($db, $cicloLectivoId, $preset) {
    try {
        $actualizados = 0;
        
        switch ($preset) {
            case 'reset_todo':
                // Marcar todas como sin subgrupos
                $result = $db->query(
                    "UPDATE materias_por_curso SET requiere_subgrupos = 0 
                     WHERE id IN (
                         SELECT mp.id FROM materias_por_curso mp
                         JOIN cursos c ON mp.curso_id = c.id
                         WHERE c.ciclo_lectivo_id = ?
                     )",
                    [$cicloLectivoId]
                );
                $actualizados = $db->rowCount();
                break;
                
            case 'auto_talleres':
                // Marcar talleres automáticamente
                $codigosTaller = [
                    'LDT1', 'PT1', 'SPT1', '2FE', 'LDT2', 'PT2', 'SPT2',
                    '3F', '3F1', '3IED1', 'LDT3', 'PT3', 'SPT3',
                    '4DA', '4DT', '4E', '4EB', '4IED2', '4M', '4M1', '4M2', '4PA', '4S', '4T',
                    '5C', '5C1', '5CF1', '5CF2', '5ED', '5M3', '5ME', '5MTT', '5R',
                    '6C2', '6CF', '6CT', '6DEE', '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC',
                    '7C3', '7CC', '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI'
                ];
                
                foreach ($codigosTaller as $codigo) {
                    $db->query(
                        "UPDATE materias_por_curso SET requiere_subgrupos = 1 
                         WHERE id IN (
                             SELECT mp.id FROM materias_por_curso mp
                             JOIN materias m ON mp.materia_id = m.id
                             JOIN cursos c ON mp.curso_id = c.id
                             WHERE c.ciclo_lectivo_id = ? AND m.codigo = ?
                         )",
                        [$cicloLectivoId, $codigo]
                    );
                    $actualizados += $db->rowCount();
                }
                break;
                
            case 'auto_ciudadania':
                // Marcar construcción de ciudadanía
                $codigosCiudadania = ['CCE', 'CCM', 'CCM1'];
                foreach ($codigosCiudadania as $codigo) {
                    $db->query(
                        "UPDATE materias_por_curso SET requiere_subgrupos = 1 
                         WHERE id IN (
                             SELECT mp.id FROM materias_por_curso mp
                             JOIN materias m ON mp.materia_id = m.id
                             JOIN cursos c ON mp.curso_id = c.id
                             WHERE c.ciclo_lectivo_id = ? AND m.codigo = ? AND c.anio IN (2, 3)
                         )",
                        [$cicloLectivoId, $codigo]
                    );
                    $actualizados += $db->rowCount();
                }
                break;
                
            case 'solo_oficiales':
                // Marcar SOLO talleres oficiales (no detectados por patrón)
                $codigosTallerOficiales = [
                    // 1° año
                    'LDT1', 'PT1', 'SPT1',
                    // 2° año  
                    '2FE', 'LDT2', 'PT2', 'SPT2',
                    // 3° año
                    '3F', '3F1', '3IED1', 'LDT3', 'PT3', 'SPT3',
                    // 4° año
                    '4DA', '4DT', '4E', '4EB', '4IED2', '4M', '4M1', '4M2', '4PA', '4S', '4T',
                    // 5° año
                    '5C', '5C1', '5CF1', '5CF2', '5ED', '5M3', '5ME', '5MTT', '5R',
                    // 6° año
                    '6C2', '6CF', '6CT', '6DEE', '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC',
                    // 7° año
                    '7C3', '7CC', '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI'
                ];
                
                // Primero resetear todo
                $db->query(
                    "UPDATE materias_por_curso SET requiere_subgrupos = 0 
                     WHERE id IN (
                         SELECT mp.id FROM materias_por_curso mp
                         JOIN cursos c ON mp.curso_id = c.id
                         WHERE c.ciclo_lectivo_id = ?
                     )",
                    [$cicloLectivoId]
                );
                
                // Luego marcar solo los oficiales
                foreach ($codigosTallerOficiales as $codigo) {
                    $db->query(
                        "UPDATE materias_por_curso SET requiere_subgrupos = 1 
                         WHERE id IN (
                             SELECT mp.id FROM materias_por_curso mp
                             JOIN materias m ON mp.materia_id = m.id
                             JOIN cursos c ON mp.curso_id = c.id
                             WHERE c.ciclo_lectivo_id = ? AND m.codigo = ?
                         )",
                        [$cicloLectivoId, $codigo]
                    );
                    $actualizados += $db->rowCount();
                }
                break;
                
            case 'configuracion_completa':
                // Aplicar configuración completa (talleres + ciudadanía)
                $resultado1 = aplicarPreset($db, $cicloLectivoId, 'solo_oficiales');
                $resultado2 = aplicarPreset($db, $cicloLectivoId, 'auto_ciudadania');
                $actualizados = $resultado1['actualizados'] + $resultado2['actualizados'];
                break;
        }
        
        return [
            'success' => true,
            'message' => "Preset aplicado correctamente. Se actualizaron {$actualizados} materias.",
            'actualizados' => $actualizados
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al aplicar preset: ' . $e->getMessage()
        ];
    }
}

/**
 * Función para obtener estadísticas de materias oficiales (CORREGIDA)
 */
function obtenerEstadisticasOficiales($materias) {
    $stats = [
        'talleres_oficiales' => 0,
        'talleres_no_oficiales' => 0,
        'ciudadania_oficial' => 0,
        'basicas' => 0,
        'total_con_subgrupos_oficial' => 0,
        'detalles_por_ano' => []
    ];
    
    foreach ($materias as $materia) {
        $deteccion = $materia['deteccion_completa'] ?? ['tipo' => 'basica', 'es_oficial' => true];
        $ano = $materia['curso_anio'];
        
        if (!isset($stats['detalles_por_ano'][$ano])) {
            $stats['detalles_por_ano'][$ano] = [
                'talleres_oficiales' => 0,
                'talleres_no_oficiales' => 0,
                'ciudadania' => 0,
                'basicas' => 0
            ];
        }
        
        switch ($deteccion['tipo']) {
            case 'taller':
                if ($deteccion['es_oficial']) {
                    $stats['talleres_oficiales']++;
                    $stats['detalles_por_ano'][$ano]['talleres_oficiales']++;
                    if ($materia['requiere_subgrupos']) {
                        $stats['total_con_subgrupos_oficial']++;
                    }
                } else {
                    $stats['talleres_no_oficiales']++;
                    $stats['detalles_por_ano'][$ano]['talleres_no_oficiales']++;
                }
                break;
                
            case 'ciudadania':
                $stats['ciudadania_oficial']++;
                $stats['detalles_por_ano'][$ano]['ciudadania']++;
                if ($materia['requiere_subgrupos']) {
                    $stats['total_con_subgrupos_oficial']++;
                }
                break;
                
            case 'basica':
                $stats['basicas']++;
                $stats['detalles_por_ano'][$ano]['basicas']++;
                break;
        }
    }
    
    return $stats;
}

/**
 * Función para calcular estadísticas (CORREGIDA)
 */
function calcularEstadisticas($materias) {
    $stats = [
        'total' => count($materias),
        'con_subgrupos' => 0,
        'sin_subgrupos' => 0,
        'talleres' => 0,
        'ciudadania' => 0,
        'basicas' => 0,
        'configurados' => 0,
        'por_ano' => []
    ];
    
    foreach ($materias as $materia) {
        $ano = $materia['curso_anio'] ?? 1;
        
        if (!isset($stats['por_ano'][$ano])) {
            $stats['por_ano'][$ano] = [
                'total' => 0,
                'con_subgrupos' => 0,
                'talleres' => 0,
                'ciudadania' => 0,
                'basicas' => 0
            ];
        }
        
        $stats['por_ano'][$ano]['total']++;
        
        if ($materia['requiere_subgrupos']) {
            $stats['con_subgrupos']++;
            $stats['por_ano'][$ano]['con_subgrupos']++;
        } else {
            $stats['sin_subgrupos']++;
        }
        
        if ($materia['configuracion_id']) {
            $stats['configurados']++;
        }
        
        // Asegurar que tipo_detectado existe con valor por defecto
        $tipoDetectado = $materia['tipo_detectado'] ?? 'basica';
        
        switch ($tipoDetectado) {
            case 'taller':
                $stats['talleres']++;
                $stats['por_ano'][$ano]['talleres']++;
                break;
            case 'ciudadania':
                $stats['ciudadania']++;
                $stats['por_ano'][$ano]['ciudadania']++;
                break;
            case 'basica':
            default:
                $stats['basicas']++;
                $stats['por_ano'][$ano]['basicas']++;
                break;
        }
    }
    
    return $stats;
}

// Procesar acciones AJAX (CORREGIDO - El problema estaba aquí)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == '1') {
    // IMPORTANTE: No incluir header.php ni footer.php en peticiones AJAX
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $accion = $_POST['accion'] ?? '';
        
        // Log para debugging
        error_log("AJAX Request - Acción: " . $accion);
        error_log("AJAX Request - POST: " . print_r($_POST, true));
        
        switch ($accion) {
            case 'actualizar_subgrupos':
                $materiaCursoId = intval($_POST['materia_curso_id']);
                $requiereSubgrupos = intval($_POST['requiere_subgrupos']);
                
                $db->query(
                    "UPDATE materias_por_curso SET requiere_subgrupos = ? WHERE id = ?",
                    [$requiereSubgrupos, $materiaCursoId]
                );
                
                echo json_encode(['success' => true, 'message' => 'Estado de subgrupos actualizado']);
                exit;
                
            case 'actualizar_masivo':
                $cambios = $_POST['cambios'] ?? [];
                if (is_string($cambios)) {
                    $cambios = json_decode($cambios, true);
                }
                
                if (!$cambios || !is_array($cambios)) {
                    echo json_encode(['success' => false, 'message' => 'No se recibieron cambios válidos']);
                    exit;
                }
                
                $actualizados = 0;
                
                foreach ($cambios as $materiaCursoId => $datos) {
                    $requiereSubgrupos = intval($datos['requiere_subgrupos']);
                    
                    $db->query(
                        "UPDATE materias_por_curso SET requiere_subgrupos = ? WHERE id = ?",
                        [$requiereSubgrupos, intval($materiaCursoId)]
                    );
                    $actualizados++;
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Se actualizaron {$actualizados} materias correctamente"
                ]);
                exit;
                
            case 'aplicar_preset':
                $preset = $_POST['preset'] ?? '';
                $resultado = aplicarPreset($db, $cicloLectivoId, $preset);
                echo json_encode($resultado);
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Acción no reconocida: ' . $accion]);
                exit;
        }
        
    } catch (Exception $e) {
        error_log("Error en AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// Obtener todas las materias agrupadas por año
$materiasPorAno = [];
$estadisticas = [];

if ($cicloLectivoId > 0) {
    $materias = $db->fetchAll(
        "SELECT mp.id as materia_curso_id, mp.requiere_subgrupos, 
                m.id as materia_id, m.nombre, m.codigo,
                c.nombre as curso_nombre, c.anio as curso_anio, c.id as curso_id,
                COALESCE(u.apellido || ', ' || u.nombre, 'Sin asignar') as profesor_nombre,
                cs.id as configuracion_id, cs.tipo_division, cs.rotacion_automatica
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id AND u.tipo = 'profesor'
         LEFT JOIN configuracion_subgrupos cs ON mp.id = cs.materia_curso_id AND cs.ciclo_lectivo_id = ?
         WHERE c.ciclo_lectivo_id = ?
         ORDER BY c.anio, m.nombre",
        [$cicloLectivoId, $cicloLectivoId]
    );
    
    // Agrupar por año y asegurar que cada materia tenga tipo_detectado
    foreach ($materias as $materia) {
        $ano = $materia['curso_anio'];
        if (!isset($materiasPorAno[$ano])) {
            $materiasPorAno[$ano] = [];
        }
        
        // MEJORA: Detección completa con información oficial
        $deteccionCompleta = detectarTipoMateria($materia);
        $materia['tipo_detectado'] = $deteccionCompleta['tipo'];
        $materia['es_oficial'] = $deteccionCompleta['es_oficial'];
        $materia['categoria'] = $deteccionCompleta['categoria'];
        $materia['nombre_oficial'] = $deteccionCompleta['nombre_oficial'] ?? null;
        $materia['deteccion_completa'] = $deteccionCompleta;
        
        $materiasPorAno[$ano][] = $materia;
    }
    
    // Calcular estadísticas
    $todasMaterias = [];
    foreach ($materiasPorAno as $materiasAno) {
        $todasMaterias = array_merge($todasMaterias, $materiasAno);
    }
    $estadisticas = calcularEstadisticas($todasMaterias);
    $estadisticasOficiales = obtenerEstadisticasOficiales($todasMaterias);
}

?>

<div class="container-fluid mt-4">
    <!-- Verificación de datos -->
    <?php if (empty($materiasPorAno)): ?>
    <div class="row">
        <div class="col-12">
            <div class="alert alert-warning">
                <h5><i class="bi bi-exclamation-triangle"></i> Sin Datos</h5>
                <p>No se encontraron materias para el ciclo lectivo activo.</p>
                <p>Asegúrese de que:</p>
                <ul>
                    <li>Hay un ciclo lectivo activo configurado</li>
                    <li>Existen cursos y materias cargadas</li>
                    <li>Las materias están asignadas a los cursos</li>
                </ul>
                <a href="materias.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Ir a Gestión de Materias
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Título y controles principales -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h4 class="mb-0">
                        <i class="bi bi-pencil-square"></i> 
                        Editor Masivo de Materias - Gestión Visual de Subgrupos
                    </h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <p class="mb-2">
                                <i class="bi bi-info-circle"></i> 
                                <strong>Edita fácilmente qué materias requieren subgrupos:</strong>
                            </p>
                            <ul class="mb-0">
                                <li><strong>Talleres:</strong> Materias prácticas que se dividen en grupos</li>
                                <li><strong>Construcción Ciudadanía:</strong> Se divide en tercios fijos</li>
                                <li><strong>Materias Básicas:</strong> Sin división en subgrupos</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-success" onclick="guardarCambiosMasivos()">
                                    <i class="bi bi-save"></i> Guardar Todos los Cambios
                                </button>
                                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalPresets">
                                    <i class="bi bi-magic"></i> Aplicar Configuración Automática
                                </button>
                                <button type="button" class="btn btn-warning" onclick="actualizarTiposMaterias()">
                                    <i class="bi bi-tools"></i> Detectar Talleres por Subgrupos
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="revertirTiposMaterias()">
                                    <i class="bi bi-arrow-counterclockwise"></i> Revertir Detección
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Estadísticas en tiempo real MEJORADAS -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-graph-up"></i> Estadísticas Actuales
                        <span class="badge bg-secondary ms-2" id="badgeEstadisticas">
                            <?= $estadisticas['con_subgrupos'] ?>/74 con subgrupos
                        </span>
                        <span class="badge bg-success ms-1" id="badgeOficiales">
                            <?= $estadisticasOficiales['talleres_oficiales'] ?>/68 talleres oficiales
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Estadísticas principales -->
                    <div class="row text-center mb-4">
                        <div class="col-md-2">
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h3 class="text-primary" id="statTotal"><?= $estadisticas['total'] ?></h3>
                                    <p class="card-text">Total</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h3 class="text-success" id="statConSubgrupos"><?= $estadisticas['con_subgrupos'] ?></h3>
                                    <p class="card-text">Con Subgrupos</p>
                                    <small class="text-muted">Objetivo: 74</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-warning">
                                <div class="card-body">
                                    <h3 class="text-warning" id="statTalleresOficiales"><?= $estadisticasOficiales['talleres_oficiales'] ?></h3>
                                    <p class="card-text">Talleres Oficiales</p>
                                    <small class="text-muted">Objetivo: 68</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h3 class="text-info" id="statCiudadania"><?= $estadisticasOficiales['ciudadania_oficial'] ?></h3>
                                    <p class="card-text">Ciudadanía</p>
                                    <small class="text-muted">Objetivo: 6</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-secondary">
                                <div class="card-body">
                                    <h3 class="text-secondary" id="statBasicas"><?= $estadisticasOficiales['basicas'] ?></h3>
                                    <p class="card-text">Básicas</p>
                                    <small class="text-muted">Objetivo: 65</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-dark">
                                <div class="card-body">
                                    <h3 class="text-dark" id="statConfigurados"><?= $estadisticas['configurados'] ?></h3>
                                    <p class="card-text">Configurados</p>
                                    <small class="text-muted">Listos para usar</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Indicador de estado -->
                    <div class="row">
                        <div class="col-12">
                            <div id="alertaEstado" class="alert <?= ($estadisticas['con_subgrupos'] == 74 && $estadisticasOficiales['talleres_oficiales'] == 68) ? 'alert-success' : 'alert-warning' ?>">
                                <i class="bi bi-<?= ($estadisticas['con_subgrupos'] == 74 && $estadisticasOficiales['talleres_oficiales'] == 68) ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                                <span id="textoEstado">
                                    <?php if ($estadisticas['con_subgrupos'] == 74 && $estadisticasOficiales['talleres_oficiales'] == 68): ?>
                                        🎯 ¡CONFIGURACIÓN PERFECTA! 74 materias con subgrupos (68 talleres oficiales + 6 ciudadanía).
                                    <?php else: ?>
                                        📊 Estado actual: <?= $estadisticasOficiales['talleres_oficiales'] ?>/68 talleres oficiales, 
                                        <?= $estadisticasOficiales['ciudadania_oficial'] ?>/6 ciudadanía, 
                                        <?= $estadisticas['con_subgrupos'] ?>/74 total con subgrupos.
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Editor por años -->
    <?php foreach ($materiasPorAno as $ano => $materias): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-<?= $ano ?>"></i> 
                        <?= $ano ?>° Año 
                        <span class="badge bg-primary"><?= count($materias) ?> materias</span>
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-success" onclick="marcarTodasAno(<?= $ano ?>, 1)">
                            <i class="bi bi-check-all"></i> Todas Con Subgrupos
                        </button>
                        <button type="button" class="btn btn-outline-warning" onclick="marcarTodasAno(<?= $ano ?>, 0)">
                            <i class="bi bi-x-circle"></i> Todas Sin Subgrupos
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($materias as $materia): ?>
                        <?php 
                            // CORRECCIÓN: Asegurar que tipo_detectado existe con valor por defecto
                            $tipoDetectado = $materia['tipo_detectado'] ?? 'basica';
                            $esOficial = $materia['es_oficial'] ?? false;
                            $categoria = $materia['categoria'] ?? 'Sin categoría';
                            $nombreOficial = $materia['nombre_oficial'] ?? null;
                        ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card h-100 materia-card <?= $materia['requiere_subgrupos'] ? 'border-warning' : 'border-secondary' ?>" 
                                 data-materia-id="<?= $materia['materia_curso_id'] ?>"
                                 data-tipo-detectado="<?= $tipoDetectado ?>"
                                 data-es-oficial="<?= $esOficial ? 'true' : 'false' ?>">
                                
                                <!-- Header con tipo detectado MEJORADO -->
                                <div class="card-header d-flex justify-content-between align-items-center 
                                            bg-<?= $tipoDetectado == 'taller' ? ($esOficial ? 'warning' : 'warning-subtle') : ($tipoDetectado == 'ciudadania' ? 'info' : 'light') ?>">
                                    <div>
                                        <i class="bi bi-<?= $tipoDetectado == 'taller' ? 'tools' : ($tipoDetectado == 'ciudadania' ? 'people' : 'book') ?>"></i>
                                        <strong><?= htmlspecialchars($materia['codigo'] ?? 'SIN-COD') ?></strong>
                                        <?php if ($esOficial && $tipoDetectado == 'taller'): ?>
                                        <i class="bi bi-check-circle text-success ms-1" title="Taller oficial del plan de estudios"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-column align-items-end">
                                        <span class="badge bg-<?= $tipoDetectado == 'taller' ? ($esOficial ? 'success' : 'dark') : ($tipoDetectado == 'ciudadania' ? 'primary' : 'secondary') ?>">
                                            <?= $esOficial && $tipoDetectado == 'taller' ? 'Taller Oficial' : ucfirst($tipoDetectado) ?>
                                        </span>
                                        <?php if (!$esOficial && $tipoDetectado == 'taller'): ?>
                                        <small class="badge bg-warning mt-1">Detectado</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Cuerpo con información MEJORADO -->
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <?= htmlspecialchars($materia['nombre'] ?? 'Sin nombre') ?>
                                        <?php if ($nombreOficial && $nombreOficial != $materia['nombre']): ?>
                                        <br><small class="text-info">Oficial: <?= htmlspecialchars($nombreOficial) ?></small>
                                        <?php endif; ?>
                                    </h6>
                                    <p class="card-text">
                                        <small class="text-muted">
                                            <i class="bi bi-building"></i> <?= htmlspecialchars($materia['curso_nombre'] ?? 'Sin curso') ?>
                                            <br>
                                            <i class="bi bi-person"></i> <?= htmlspecialchars($materia['profesor_nombre'] ?? 'Sin asignar') ?>
                                            <br>
                                            <i class="bi bi-tag"></i> <?= htmlspecialchars($categoria) ?>
                                        </small>
                                    </p>
                                    
                                    <!-- Estado actual MEJORADO -->
                                    <div class="mb-2">
                                        <span class="estado-actual badge bg-<?= $materia['requiere_subgrupos'] ? 'success' : 'secondary' ?>">
                                            <?= $materia['requiere_subgrupos'] ? 'Con Subgrupos' : 'Sin Subgrupos' ?>
                                        </span>
                                        <?php if (!empty($materia['configuracion_id'])): ?>
                                        <span class="badge bg-primary">
                                            <i class="bi bi-gear"></i> Configurado
                                        </span>
                                        <?php endif; ?>
                                        
                                        <!-- Indicadores especiales -->
                                        <?php if ($esOficial && $tipoDetectado == 'taller'): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-star"></i> Oficial
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Sugerencia automática MEJORADA -->
                                    <?php 
                                    $sugerencia = ($tipoDetectado == 'taller' || $tipoDetectado == 'ciudadania') ? 1 : 0;
                                    $coincide = ($sugerencia == $materia['requiere_subgrupos']);
                                    ?>
                                    <?php if (!$coincide): ?>
                                    <div class="alert alert-warning py-1 px-2 mb-2">
                                        <small>
                                            <i class="bi bi-lightbulb"></i>
                                            <strong>Sugerencia:</strong> 
                                            <?php if ($tipoDetectado == 'taller' && $esOficial): ?>
                                                Taller oficial - DEBERÍA tener subgrupos
                                            <?php elseif ($tipoDetectado == 'ciudadania'): ?>
                                                Construcción Ciudadanía - DEBE tener subgrupos
                                            <?php else: ?>
                                                <?= $sugerencia ? 'Debería tener subgrupos' : 'No debería tener subgrupos' ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Alerta para talleres oficiales sin subgrupos -->
                                    <?php if ($esOficial && $tipoDetectado == 'taller' && !$materia['requiere_subgrupos']): ?>
                                    <div class="alert alert-danger py-1 px-2 mb-2">
                                        <small>
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <strong>¡ATENCIÓN!</strong> Taller oficial sin subgrupos
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Footer con controles -->
                                <div class="card-footer">
                                    <div class="row">
                                        <div class="col-6">
                                            <button type="button" 
                                                    class="btn btn-<?= $materia['requiere_subgrupos'] ? 'outline-success' : 'success' ?> btn-sm w-100 btn-con-subgrupos"
                                                    onclick="toggleSubgrupos(<?= $materia['materia_curso_id'] ?>, 1)"
                                                    <?= $materia['requiere_subgrupos'] ? 'disabled' : '' ?>>
                                                <i class="bi bi-check-circle"></i> Con Subgrupos
                                            </button>
                                        </div>
                                        <div class="col-6">
                                            <button type="button" 
                                                    class="btn btn-<?= !$materia['requiere_subgrupos'] ? 'outline-secondary' : 'secondary' ?> btn-sm w-100 btn-sin-subgrupos"
                                                    onclick="toggleSubgrupos(<?= $materia['materia_curso_id'] ?>, 0)"
                                                    <?= !$materia['requiere_subgrupos'] ? 'disabled' : '' ?>>
                                                <i class="bi bi-x-circle"></i> Sin Subgrupos
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Aplicar sugerencia -->
                                    <?php if (!$coincide): ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-warning btn-sm w-100" 
                                                onclick="aplicarSugerencia(<?= $materia['materia_curso_id'] ?>, <?= $sugerencia ?>)">
                                            <i class="bi bi-magic"></i> Aplicar Sugerencia
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php endif; ?>
</div>

<!-- Modal de Presets Automáticos -->
<div class="modal fade" id="modalPresets" tabindex="-1" aria-labelledby="modalPresetsLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPresetsLabel">
                    <i class="bi bi-magic"></i> Configuración Automática
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Presets automáticos:</strong> Configuraciones predefinidas basadas en el análisis del plan de estudios.
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-arrow-clockwise"></i> Resetear Todo
                                </h6>
                                <p class="card-text">Marca todas las materias como SIN subgrupos. Útil para empezar de cero.</p>
                                <button type="button" class="btn btn-warning w-100" onclick="aplicarPreset('reset_todo')">
                                    Aplicar Reset
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-tools"></i> Auto-Detectar Talleres
                                </h6>
                                <p class="card-text">Marca automáticamente las 68 materias de taller según el PDF oficial.</p>
                                <button type="button" class="btn btn-success w-100" onclick="aplicarPreset('auto_talleres')">
                                    Aplicar Talleres
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h6 class="card-title">
                                    <i class="bi bi-people"></i> Auto-Detectar Ciudadanía
                                </h6>
                                <p class="card-text">Marca las 6 materias de Construcción de la Ciudadanía (2° y 3° año).</p>
                                <button type="button" class="btn btn-info w-100" onclick="aplicarPreset('auto_ciudadania')">
                                    Aplicar Ciudadanía
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100 border-success">
                            <div class="card-body">
                                <h6 class="card-title text-success">
                                    <i class="bi bi-check-all"></i> Configuración Completa Oficial
                                </h6>
                                <p class="card-text"><strong>Recomendado:</strong> Aplica la configuración ideal basada en las 68 materias oficiales de taller + 6 de ciudadanía.</p>
                                <button type="button" class="btn btn-success w-100" onclick="aplicarPreset('configuracion_completa')">
                                    <i class="bi bi-magic"></i> Aplicar Configuración Oficial (74 materias)
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card h-100 border-info">
                            <div class="card-body">
                                <h6 class="card-title text-info">
                                    <i class="bi bi-list-check"></i> Solo Talleres Oficiales
                                </h6>
                                <p class="card-text">Marca únicamente las 68 materias de taller que están en el plan oficial, sin detectar adicionales.</p>
                                <button type="button" class="btn btn-info w-100" onclick="aplicarPreset('solo_oficiales')">
                                    <i class="bi bi-award"></i> Solo Talleres Oficiales (68)
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Indicador de cambios pendientes -->
<div id="indicadorCambios" class="position-fixed bottom-0 end-0 m-3" style="display: none; z-index: 1050;">
    <div class="card border-warning shadow">
        <div class="card-body">
            <h6 class="card-title">
                <i class="bi bi-exclamation-triangle text-warning"></i> 
                Cambios Pendientes
            </h6>
            <p class="card-text">
                <span id="contadorCambios">0</span> cambios sin guardar
            </p>
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-success btn-sm" onclick="guardarCambiosMasivos()">
                    <i class="bi bi-save"></i> Guardar Cambios
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="descartarCambios()">
                    <i class="bi bi-x-circle"></i> Descartar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variables globales
let cambiosPendientes = {};
let estadisticasOriginales = <?= json_encode($estadisticas) ?>;

// Función principal para toggle de subgrupos (CORREGIDA)
function toggleSubgrupos(materiaCursoId, requiereSubgrupos) {
    console.log(`Toggle subgrupos: Materia ${materiaCursoId}, Requiere: ${requiereSubgrupos}`);
    
    // Actualizar visualmente de inmediato
    actualizarInterfazMateria(materiaCursoId, requiereSubgrupos);
    
    // Registrar cambio pendiente
    cambiosPendientes[materiaCursoId] = {
        requiere_subgrupos: requiereSubgrupos
    };
    
    console.log('Cambios pendientes:', cambiosPendientes);
    
    // Actualizar estadísticas en tiempo real
    actualizarEstadisticasVisual();
    
    // Mostrar indicador de cambios pendientes
    mostrarIndicadorCambios();
    
    // Envío inmediato opcional para testing
    if (window.debugMode) {
        enviarCambioInmediato(materiaCursoId, requiereSubgrupos);
    }
}

// Función para envío inmediato de cambios (para debugging)
function enviarCambioInmediato(materiaCursoId, requiereSubgrupos) {
    fetch('edicion_masiva_materias.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=1&accion=actualizar_subgrupos&materia_curso_id=${materiaCursoId}&requiere_subgrupos=${requiereSubgrupos}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('Respuesta del servidor:', data);
        if (!data.success) {
            console.error('Error del servidor:', data.message);
            mostrarMensaje('danger', 'Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error de red:', error);
        mostrarMensaje('danger', 'Error de conexión');
    });
}

// Actualizar interfaz visual de una materia (MEJORADA)
function actualizarInterfazMateria(materiaCursoId, requiereSubgrupos) {
    const card = document.querySelector(`[data-materia-id="${materiaCursoId}"]`);
    if (!card) {
        console.error(`No se encontró la tarjeta con materia-id: ${materiaCursoId}`);
        return;
    }
    
    console.log(`Actualizando interfaz: Materia ${materiaCursoId}, Subgrupos: ${requiereSubgrupos}`);
    
    // Actualizar borde de la tarjeta
    card.classList.remove('border-warning', 'border-secondary');
    card.classList.add(requiereSubgrupos ? 'border-warning' : 'border-secondary');
    
    // Actualizar badge de estado
    const estadoBadge = card.querySelector('.estado-actual');
    if (estadoBadge) {
        estadoBadge.className = `estado-actual badge bg-${requiereSubgrupos ? 'success' : 'secondary'}`;
        estadoBadge.textContent = requiereSubgrupos ? 'Con Subgrupos' : 'Sin Subgrupos';
    }
    
    // Actualizar botones
    const btnCon = card.querySelector('.btn-con-subgrupos');
    const btnSin = card.querySelector('.btn-sin-subgrupos');
    
    if (btnCon && btnSin) {
        // Resetear clases y estados
        btnCon.className = 'btn btn-sm w-100 btn-con-subgrupos';
        btnSin.className = 'btn btn-sm w-100 btn-sin-subgrupos';
        
        if (requiereSubgrupos) {
            btnCon.classList.add('btn-outline-success');
            btnCon.disabled = true;
            btnSin.classList.add('btn-secondary');
            btnSin.disabled = false;
        } else {
            btnCon.classList.add('btn-success');
            btnCon.disabled = false;
            btnSin.classList.add('btn-outline-secondary');
            btnSin.disabled = true;
        }
    } else {
        console.warn('No se encontraron botones en la tarjeta');
    }
    
    // Actualizar sugerencia
    actualizarSugerenciaMateria(card, requiereSubgrupos);
    
    console.log(`Interfaz actualizada para materia ${materiaCursoId}`);
}

// Función separada para actualizar sugerencias
function actualizarSugerenciaMateria(card, requiereSubgrupos) {
    const tipoDetectado = card.dataset.tipoDetectado || 'basica';
    const sugerencia = (tipoDetectado === 'taller' || tipoDetectado === 'ciudadania') ? 1 : 0;
    const coincide = (sugerencia == requiereSubgrupos);
    
    let alertaSugerencia = card.querySelector('.alert-warning');
    let btnSugerencia = card.querySelector('[onclick*="aplicarSugerencia"]');
    
    if (coincide) {
        // Ocultar sugerencia si ahora coincide
        if (alertaSugerencia) alertaSugerencia.style.display = 'none';
        if (btnSugerencia && btnSugerencia.parentElement) {
            btnSugerencia.parentElement.style.display = 'none';
        }
    } else {
        // Mostrar sugerencia si no coincide
        if (alertaSugerencia) alertaSugerencia.style.display = 'block';
        if (btnSugerencia && btnSugerencia.parentElement) {
            btnSugerencia.parentElement.style.display = 'block';
        }
    }
}

// Actualizar estadísticas visuales en tiempo real
function actualizarEstadisticasVisual() {
    const materias = document.querySelectorAll('.materia-card');
    let stats = {
        total: materias.length,
        con_subgrupos: 0,
        talleres: 0,
        ciudadania: 0,
        basicas: 0
    };
    
    materias.forEach(card => {
        const materiaCursoId = card.dataset.materiaId;
        const tipoDetectado = card.dataset.tipoDetectado || 'basica';
        
        // Determinar estado actual (cambio pendiente o estado original)
        let requiereSubgrupos;
        if (cambiosPendientes[materiaCursoId]) {
            requiereSubgrupos = cambiosPendientes[materiaCursoId].requiere_subgrupos;
        } else {
            requiereSubgrupos = card.classList.contains('border-warning');
        }
        
        if (requiereSubgrupos) {
            stats.con_subgrupos++;
        }
        
        switch (tipoDetectado) {
            case 'taller':
                stats.talleres++;
                break;
            case 'ciudadania':
                stats.ciudadania++;
                break;
            case 'basica':
                stats.basicas++;
                break;
        }
    });
    
    // Actualizar números en pantalla
    document.getElementById('statConSubgrupos').textContent = stats.con_subgrupos;
    document.getElementById('badgeEstadisticas').textContent = `${stats.con_subgrupos}/74 con subgrupos`;
    
    // Actualizar alerta de estado
    const alertaEstado = document.getElementById('alertaEstado');
    const textoEstado = document.getElementById('textoEstado');
    
    if (stats.con_subgrupos === 74) {
        alertaEstado.className = 'alert alert-success';
        textoEstado.innerHTML = '<i class="bi bi-check-circle"></i> ¡Perfecto! Configuración ideal alcanzada: 74 materias con subgrupos.';
    } else {
        alertaEstado.className = 'alert alert-warning';
        const faltantes = 74 - stats.con_subgrupos;
        textoEstado.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ${faltantes > 0 ? `Faltan ${faltantes} materias` : `Hay ${Math.abs(faltantes)} materias de más`} para llegar al objetivo de 74 con subgrupos.`;
    }
}

// Aplicar sugerencia automática
function aplicarSugerencia(materiaCursoId, sugerencia) {
    toggleSubgrupos(materiaCursoId, sugerencia);
}

// Marcar todas las materias de un año
function marcarTodasAno(ano, requiereSubgrupos) {
    const mensaje = requiereSubgrupos ? 
        `¿Marcar TODAS las materias de ${ano}° año como CON subgrupos?` :
        `¿Marcar TODAS las materias de ${ano}° año como SIN subgrupos?`;
        
    if (confirm(mensaje)) {
        const cards = document.querySelectorAll('.materia-card');
        cards.forEach(card => {
            const materiaCursoId = card.dataset.materiaId;
            const cardAno = card.closest('.card').querySelector('h5').textContent.charAt(0);
            
            if (cardAno == ano) {
                toggleSubgrupos(materiaCursoId, requiereSubgrupos);
            }
        });
    }
}

// Mostrar indicador de cambios pendientes
function mostrarIndicadorCambios() {
    const contador = Object.keys(cambiosPendientes).length;
    if (contador > 0) {
        document.getElementById('contadorCambios').textContent = contador;
        document.getElementById('indicadorCambios').style.display = 'block';
    } else {
        document.getElementById('indicadorCambios').style.display = 'none';
    }
}

// Guardar cambios masivos (CORREGIDO CON MEJOR MANEJO DE ERRORES)
function guardarCambiosMasivos() {
    const contador = Object.keys(cambiosPendientes).length;
    
    if (contador === 0) {
        alert('No hay cambios pendientes para guardar');
        return;
    }
    
    if (!confirm(`¿Guardar ${contador} cambios pendientes?`)) {
        return;
    }
    
    // Mostrar loading
    const btn = event.target;
    const textoOriginal = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-spinner"></i> Guardando...';
    btn.disabled = true;
    
    console.log('📤 Enviando cambios al servidor:', cambiosPendientes);
    
    // Enviar cambios al servidor
    fetch('edicion_masiva_materias.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=1&accion=actualizar_masivo&cambios=${encodeURIComponent(JSON.stringify(cambiosPendientes))}`
    })
    .then(response => {
        console.log('📥 Respuesta del servidor - Status:', response.status);
        console.log('📥 Respuesta del servidor - Headers:', response.headers.get('content-type'));
        
        // Verificar si la respuesta es realmente JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            console.error('❌ El servidor no devolvió JSON');
            // Obtener el texto de la respuesta para ver el error
            return response.text().then(text => {
                console.error('📄 Contenido de la respuesta:', text.substring(0, 500));
                throw new Error('El servidor devolvió HTML en lugar de JSON. Posible error de PHP.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Datos recibidos:', data);
        
        if (data.success) {
            // Limpiar cambios pendientes
            cambiosPendientes = {};
            mostrarIndicadorCambios();
            
            // Mostrar mensaje de éxito
            mostrarMensaje('success', data.message);
            
            // Opcional: recargar página para sincronizar completamente
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarMensaje('danger', data.message || 'Error desconocido del servidor');
        }
    })
    .catch(error => {
        console.error('❌ Error completo:', error);
        
        // Mensaje de error más detallado
        let mensajeError = 'Error al guardar cambios: ' + error.message;
        
        if (error.message.includes('JSON')) {
            mensajeError = 'Error del servidor PHP. Revisa la consola para más detalles.';
        } else if (error.message.includes('fetch')) {
            mensajeError = 'Error de conexión. Verifica tu conexión a internet.';
        }
        
        mostrarMensaje('danger', mensajeError);
    })
    .finally(() => {
        btn.innerHTML = textoOriginal;
        btn.disabled = false;
    });
}

// Descartar cambios
function descartarCambios() {
    if (confirm('¿Descartar todos los cambios pendientes?')) {
        // Revertir visualmente todos los cambios
        Object.keys(cambiosPendientes).forEach(materiaCursoId => {
            const card = document.querySelector(`[data-materia-id="${materiaCursoId}"]`);
            if (card) {
                // Determinar estado original basado en las clases CSS
                const estadoOriginal = card.classList.contains('border-warning') ? 1 : 0;
                actualizarInterfazMateria(materiaCursoId, estadoOriginal);
            }
        });
        
        // Limpiar cambios pendientes
        cambiosPendientes = {};
        mostrarIndicadorCambios();
        actualizarEstadisticasVisual();
    }
}

// Aplicar presets automáticos (CORREGIDO)
function aplicarPreset(preset) {
    let mensaje = '';
    
    switch (preset) {
        case 'reset_todo':
            mensaje = '¿Marcar TODAS las materias como SIN subgrupos? Esto limpiará la configuración actual.';
            break;
        case 'auto_talleres':
            mensaje = '¿Auto-detectar y marcar las 68 materias de taller según el plan oficial?';
            break;
        case 'auto_ciudadania':
            mensaje = '¿Auto-detectar y marcar las 6 materias de Construcción de la Ciudadanía?';
            break;
        case 'solo_oficiales':
            mensaje = '¿Marcar ÚNICAMENTE las 68 materias de taller del plan oficial?\n\nEsto reseteará todo y marcará solo los talleres oficiales.';
            break;
        case 'configuracion_completa':
            mensaje = '¿Aplicar la configuración completa oficial (74 materias con subgrupos)?\n\nEsto marcará automáticamente:\n- 68 materias de taller oficiales (exactas del plan)\n- 6 materias de construcción ciudadanía';
            break;
    }
    
    if (!confirm(mensaje)) return;
    
    // Cerrar modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalPresets'));
    modal.hide();
    
    // Mostrar loading
    mostrarMensaje('info', 'Aplicando configuración automática...');
    
    console.log('📤 Aplicando preset:', preset);
    
    // Enviar preset al servidor
    fetch('edicion_masiva_materias.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=1&accion=aplicar_preset&preset=${preset}`
    })
    .then(response => {
        console.log('📥 Respuesta preset - Status:', response.status);
        console.log('📥 Respuesta preset - Content-Type:', response.headers.get('content-type'));
        
        // Verificar si es JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('📄 Respuesta HTML recibida:', text.substring(0, 1000));
                throw new Error('El servidor devolvió HTML. Hay un error de PHP en el preset.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Preset aplicado:', data);
        
        if (data.success) {
            mostrarMensaje('success', data.message);
            // Recargar página para mostrar cambios
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarMensaje('danger', data.message || 'Error al aplicar preset');
        }
    })
    .catch(error => {
        console.error('❌ Error aplicando preset:', error);
        
        let mensajeError = 'Error al aplicar preset: ' + error.message;
        if (error.message.includes('HTML')) {
            mensajeError = 'Error de PHP en el servidor. Revisa la consola para más detalles.';
        }
        
        mostrarMensaje('danger', mensajeError);
    });
}

// Función auxiliar para mostrar mensajes
function mostrarMensaje(tipo, mensaje) {
    // Crear alerta temporal
    const alerta = document.createElement('div');
    alerta.className = `alert alert-${tipo} alert-dismissible fade show position-fixed`;
    alerta.style.cssText = 'top: 20px; right: 20px; z-index: 1060; min-width: 300px;';
    alerta.innerHTML = `
        ${mensaje}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alerta);
    
    // Auto-eliminar después de 5 segundos
    setTimeout(() => {
        if (alerta.parentNode) {
            alerta.parentNode.removeChild(alerta);
        }
    }, 5000);
}

// Inicialización (MEJORADA con debugging)
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Editor masivo de materias cargado');
    
    // Activar modo debug para testing
    window.debugMode = false; // Cambiar a true para depuración
    
    // Verificar que las tarjetas están presentes
    const tarjetas = document.querySelectorAll('.materia-card');
    console.log(`📊 Se encontraron ${tarjetas.length} tarjetas de materias`);
    
    // Verificar botones en cada tarjeta
    tarjetas.forEach((card, index) => {
        const materiaId = card.dataset.materiaId;
        const btnCon = card.querySelector('.btn-con-subgrupos');
        const btnSin = card.querySelector('.btn-sin-subgrupos');
        
        if (!btnCon || !btnSin) {
            console.warn(`⚠️ Tarjeta ${index} (ID: ${materiaId}) no tiene botones correctos`);
        } else {
            console.log(`✅ Tarjeta ${index} (ID: ${materiaId}) configurada correctamente`);
        }
        
        // Verificar eventos onclick
        if (btnCon && !btnCon.onclick && !btnCon.getAttribute('onclick')) {
            console.warn(`⚠️ Botón "Con Subgrupos" de materia ${materiaId} no tiene evento onclick`);
        }
        if (btnSin && !btnSin.onclick && !btnSin.getAttribute('onclick')) {
            console.warn(`⚠️ Botón "Sin Subgrupos" de materia ${materiaId} no tiene evento onclick`);
        }
    });
    
    // Agregar efecto hover a las tarjetas
    tarjetas.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '';
        });
    });
    
    // EJECUTAR DETECCIÓN AUTOMÁTICA DE TALLERES
    setTimeout(() => {
        console.log('🔧 Ejecutando detección automática de talleres...');
        actualizarTiposMaterias();
    }, 1000);
    
    // Test de funcionalidad si está en modo debug
    if (window.debugMode) {
        console.log('🔧 Modo debug activado');
        
        // Test de toggle después de 2 segundos
        setTimeout(() => {
            const primeraMateria = document.querySelector('.materia-card');
            if (primeraMateria) {
                const materiaId = primeraMateria.dataset.materiaId;
                console.log(`🧪 Test: Toggling materia ${materiaId}`);
                toggleSubgrupos(materiaId, 1);
            }
        }, 2000);
    }
    
    // Atajos de teclado
    document.addEventListener('keydown', function(e) {
        // Ctrl + S para guardar
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            guardarCambiosMasivos();
        }
        
        // Escape para descartar cambios
        if (e.key === 'Escape' && Object.keys(cambiosPendientes).length > 0) {
            descartarCambios();
        }
        
        // Ctrl + D para activar modo debug
        if (e.ctrlKey && e.key === 'd') {
            e.preventDefault();
            window.debugMode = !window.debugMode;
            console.log(`🔧 Modo debug: ${window.debugMode ? 'ACTIVADO' : 'DESACTIVADO'}`);
            mostrarMensaje(window.debugMode ? 'info' : 'secondary', 
                          `Modo debug ${window.debugMode ? 'activado' : 'desactivado'}`);
        }
        
        // Ctrl + T para detectar talleres
        if (e.ctrlKey && e.key === 't') {
            e.preventDefault();
            actualizarTiposMaterias();
        }
        
        // Ctrl + R para revertir detección
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            revertirTiposMaterias();
        }
    });
    
    console.log('✅ Inicialización completa');
    console.log('💡 Presiona Ctrl+D para activar/desactivar modo debug');
    console.log('💡 Presiona Ctrl+T para detectar talleres manualmente');
    console.log('💡 Presiona Ctrl+R para revertir detección');
});

// 🔧 FUNCIONES PARA DETECTAR TALLERES POR SUBGRUPOS

function actualizarTiposMaterias() {
    console.log('🔧 Iniciando actualización de tipos de materias...');
    
    const materias = document.querySelectorAll('.materia-card');
    let materiasActualizadas = 0;
    
    materias.forEach(card => {
        const tieneSubgrupos = card.classList.contains('border-warning');
        const tipoActual = card.dataset.tipoDetectado;
        const materiaId = card.dataset.materiaId;
        
        // Si tiene subgrupos pero NO es construcción ciudadanía, convertir a taller
        if (tieneSubgrupos && tipoActual !== 'ciudadania') {
            actualizarTarjetaATaller(card);
            materiasActualizadas++;
            console.log(`✅ Materia ${materiaId} actualizada a taller`);
        }
    });
    
    console.log(`🎯 Se actualizaron ${materiasActualizadas} materias a tipo taller`);
    
    if (materiasActualizadas > 0) {
        mostrarMensaje('success', `🔧 Se detectaron ${materiasActualizadas} materias como talleres (aparecen en amarillo con tuerca)`);
        
        // Actualizar estadísticas visuales
        actualizarEstadisticasVisual();
    } else {
        mostrarMensaje('info', 'No se encontraron materias con subgrupos para convertir a talleres');
    }
}

function actualizarTarjetaATaller(card) {
    // 1. Actualizar dataset
    card.dataset.tipoDetectado = 'taller';
    card.dataset.esOficial = 'false'; // Son talleres detectados, no oficiales
    
    // 2. Actualizar header de la tarjeta
    const header = card.querySelector('.card-header');
    if (header) {
        // Cambiar color de fondo a amarillo (taller)
        header.className = header.className.replace(/bg-\w+/, 'bg-warning');
        
        // Actualizar icono a tuerca
        const icono = header.querySelector('i.bi');
        if (icono) {
            icono.className = 'bi bi-tools';
        }
        
        // Actualizar badge principal
        const badges = header.querySelectorAll('.badge');
        badges.forEach(badge => {
            if (!badge.classList.contains('bg-warning')) { // No tocar el badge "Detectado"
                badge.className = 'badge bg-dark';
                badge.textContent = 'Taller Detectado';
            }
        });
        
        // Agregar badge "Auto-Detectado" si no existe
        const badgeDetectado = header.querySelector('.badge.bg-success');
        if (!badgeDetectado) {
            const badgeContainer = header.querySelector('.d-flex.flex-column.align-items-end');
            if (badgeContainer) {
                const nuevoBadge = document.createElement('small');
                nuevoBadge.className = 'badge bg-success mt-1';
                nuevoBadge.innerHTML = '<i class="bi bi-gear-fill"></i> Auto-Detectado';
                badgeContainer.appendChild(nuevoBadge);
            }
        }
    }
    
    // 3. Actualizar categoría en el cuerpo
    const categoriaElement = card.querySelector('.card-text small:last-child');
    if (categoriaElement) {
        categoriaElement.innerHTML = '<i class="bi bi-tag"></i> Taller Detectado (tiene subgrupos configurados)';
    }
    
    // 4. Agregar indicador especial en el estado
    const estadoDiv = card.querySelector('.mb-2');
    if (estadoDiv) {
        let indicadorTaller = estadoDiv.querySelector('.indicador-taller-detectado');
        if (!indicadorTaller) {
            indicadorTaller = document.createElement('span');
            indicadorTaller.className = 'badge bg-warning text-dark indicador-taller-detectado ms-1';
            indicadorTaller.innerHTML = '<i class="bi bi-tools"></i> Auto-Taller';
            indicadorTaller.title = 'Detectado automáticamente como taller por tener subgrupos';
            estadoDiv.appendChild(indicadorTaller);
        }
    }
}

function revertirTiposMaterias() {
    console.log('🔄 Revirtiendo tipos de materias...');
    
    const materias = document.querySelectorAll('.materia-card[data-tipo-detectado="taller"][data-es-oficial="false"]');
    let materiasRevertidas = 0;
    
    materias.forEach(card => {
        const materiaId = card.dataset.materiaId;
        
        // Revertir a básica
        card.dataset.tipoDetectado = 'basica';
        card.dataset.esOficial = 'true';
        
        // Revertir header
        const header = card.querySelector('.card-header');
        if (header) {
            header.className = header.className.replace(/bg-\w+/, 'bg-light');
            
            const icono = header.querySelector('i.bi');
            if (icono) {
                icono.className = 'bi bi-book';
            }
            
            const badges = header.querySelectorAll('.badge');
            badges.forEach(badge => {
                if (badge.classList.contains('bg-dark') || badge.classList.contains('bg-success')) {
                    if (badge.textContent.includes('Auto-Detectado')) {
                        badge.remove();
                    } else {
                        badge.className = 'badge bg-secondary';
                        badge.textContent = 'Básica';
                    }
                }
            });
        }
        
        // Remover indicadores especiales
        const indicadores = card.querySelectorAll('.indicador-taller-detectado');
        indicadores.forEach(ind => ind.remove());
        
        // Revertir categoría
        const categoriaElement = card.querySelector('.card-text small:last-child');
        if (categoriaElement) {
            categoriaElement.innerHTML = '<i class="bi bi-tag"></i> Materia Básica';
        }
        
        materiasRevertidas++;
        console.log(`↩️ Materia ${materiaId} revertida a básica`);
    });
    
    console.log(`🔄 Se revirtieron ${materiasRevertidas} materias`);
    
    if (materiasRevertidas > 0) {
        mostrarMensaje('info', `🔄 Se revirtieron ${materiasRevertidas} materias a tipo básica`);
        actualizarEstadisticasVisual();
    } else {
        mostrarMensaje('warning', 'No se encontraron materias auto-detectadas para revertir');
    }
}
</script>

<style>
/* Estilos específicos para el editor masivo */
.materia-card {
    transition: all 0.2s ease;
    cursor: default;
}

.materia-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.btn-con-subgrupos:disabled {
    opacity: 0.6;
}

.btn-sin-subgrupos:disabled {
    opacity: 0.6;
}

.alert-warning {
    border-left: 3px solid #ffc107;
}

#indicadorCambios {
    animation: fadeInUp 0.3s ease;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Indicadores de estado */
.border-warning {
    border-left: 4px solid #ffc107 !important;
}

.border-secondary {
    border-left: 4px solid #6c757d !important;
}

/* Mejoras responsivas */
@media (max-width: 768px) {
    .col-md-6.col-lg-4 {
        margin-bottom: 1rem;
    }
    
    #indicadorCambios {
        position: fixed !important;
        bottom: 10px !important;
        right: 10px !important;
        left: 10px !important;
        margin: 0 !important;
    }
}

/* Animaciones suaves para estadísticas */
#statConSubgrupos, #statTalleres, #statCiudadania, #statBasicas {
    transition: all 0.3s ease;
}

.card-body h3 {
    font-weight: bold;
    font-size: 2rem;
}
</style>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>