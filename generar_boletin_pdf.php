<?php
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin' && isset($_GET['debug'])) {
    // Mostrar errores en pantalla
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    
    echo "<h2>🔍 DEBUG MODE ACTIVADO</h2>";
    echo "<p>Agregue <code>&debug=1</code> a la URL para ver este debugging</p>";
    echo "<hr>";
}
// Iniciar buffer de salida al principio del archivo
ob_start();

/**
 * generar_boletin_pdf.php - Generación de boletines en PDF CORREGIDO Y ACTUALIZADO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * NUEVA FUNCIONALIDAD: Fper
 * - Materias pendientes de intensificación incluidas
 * - Soporte para grupos de materias
 * - Manejo de materias liberadas con color amarillo
 * 
 * CORRECCIÓN ESPECÍFICA: Respetar valoraciones existentes en la base de datos
 * - Nunca sobreescribir valoraciones que ya existen (TEA, TEP, TED)
 * - Solo calcular valoraciones automáticamente cuando están realmente vacías
 * 
 * CORRECCIÓN PRINCIPAL: Nombres y años de materias pendientes agrupadas
 * - Mostrar correctamente nombres de grupos y materias individuales
 * - Mostrar año correcto de cursada de la materia pendiente
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir las funciones de agrupación si están disponibles
if (file_exists('includes/funciones_agrupacion_materias.php')) {
    require_once 'includes/funciones_agrupacion_materias.php';
}

require_once 'funciones_grupos_pendientes.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar parámetros requeridos
if (!isset($_GET['estudiante']) || !isset($_GET['curso']) || !isset($_GET['tipo'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el boletín';
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Obtener parámetros
$estudianteId = intval($_GET['estudiante']);
$cursoId = intval($_GET['curso']);
$tipoBoletinSeleccionado = $_GET['tipo']; // 'cuatrimestre' o 'bimestre'
$cuatrimestreSeleccionado = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 1;
$bimestreSeleccionado = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 1;

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: boletines.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Obtener datos del estudiante
try {
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
        $_SESSION['message'] = 'No se encontraron datos del estudiante.';
        $_SESSION['message_type'] = 'danger';
        header('Location: boletines.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos del estudiante: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// NUEVA FUNCIÓN: Obtener materias liberadas del estudiante
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

// NUEVA FUNCIÓN: Verificar si un grupo tiene alguna materia liberada
function grupoTieneMateriasLiberadas($db, $grupoId, $materiasLiberadasIds, $estudianteId, $cicloLectivoId) {
    try {
        // Obtener todas las materias del grupo
        $materiasGrupo = $db->fetchAll(
            "SELECT mp.id as materia_curso_id 
             FROM materias_grupo mg
             JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
             WHERE mg.grupo_id = ? AND mg.activo = 1",
            [$grupoId]
        );
        
        // Verificar si alguna materia del grupo está liberada
        foreach ($materiasGrupo as $materia) {
            if (in_array($materia['materia_curso_id'], $materiasLiberadasIds)) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error al verificar materias liberadas en grupo: " . $e->getMessage());
        return false;
    }
}

// FUNCIÓN MEJORADA: Obtener información detallada de liberaciones por grupo
function obtenerDetalleMateriasLiberadasGrupo($db, $grupoId, $materiasLiberadasIds) {
    try {
        $materiasLiberadasGrupo = $db->fetchAll(
            "SELECT m.nombre as materia_nombre, m.codigo as materia_codigo, mp.id as materia_curso_id
             FROM materias_grupo mg
             JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             WHERE mg.grupo_id = ? AND mg.activo = 1 AND mp.id IN (" . 
             str_repeat('?,', count($materiasLiberadasIds) - 1) . "?)",
            array_merge([$grupoId], $materiasLiberadasIds)
        );
        
        return $materiasLiberadasGrupo;
    } catch (Exception $e) {
        error_log("Error al obtener detalle de materias liberadas del grupo: " . $e->getMessage());
        return [];
    }
}

// Obtener materias liberadas
$materiasLiberadas = obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId);
$materiasLiberadasIds = array_column($materiasLiberadas, 'materia_liberada_id');

// Obtener materias pendientes de intensificación CON INFORMACIÓN DE GRUPOS
$materiasAgrupadasPendientes = [];
$estadisticasPendientes = [];
try {
    // Usar la nueva función de agrupación
    $materiasAgrupadasPendientes = agruparMateriasPendientesPorGrupo($db, $estudianteId, $cicloLectivoId);
    $estadisticasPendientes = obtenerEstadisticasPendientesAgrupadas($materiasAgrupadasPendientes);
    
    // DEBUG: Verificar datos obtenidos
    if (isset($_GET['debug']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        echo "<h3>📋 MATERIAS PENDIENTES DEBUG:</h3>";
        echo "<p><strong>Total items pendientes:</strong> " . count($materiasAgrupadasPendientes) . "</p>";
        
        foreach (array_slice($materiasAgrupadasPendientes, 0, 3) as $index => $item) {
            echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px; background: #fff3cd;'>";
            echo "<h5>Item Pendiente $index " . ($item['es_grupo'] ? '(GRUPO)' : '(MATERIA)') . ":</h5>";
            echo "<ul>";
            echo "<li><strong>es_grupo:</strong> " . ($item['es_grupo'] ? 'SÍ' : 'NO') . "</li>";
            
            if ($item['es_grupo']) {
                echo "<li><strong>grupo_nombre:</strong> '" . ($item['grupo_nombre'] ?? 'NULL') . "'</li>";
                echo "<li><strong>grupo_codigo:</strong> '" . ($item['grupo_codigo'] ?? 'NULL') . "'</li>";
                echo "<li><strong>curso_anio:</strong> '" . ($item['curso_anio'] ?? 'NULL') . "'</li>";
                echo "<li><strong>materias en grupo:</strong> " . count($item['materias'] ?? []) . "</li>";
                if (!empty($item['materias'])) {
                    $primeraMateria = $item['materias'][0];
                    echo "<li><strong>primera_materia_curso_anio:</strong> '" . ($primeraMateria['curso_anio'] ?? 'NULL') . "'</li>";
                    echo "<li><strong>primera_materia_nombre:</strong> '" . ($primeraMateria['materia_nombre'] ?? 'NULL') . "'</li>";
                }
            } else {
                if (!empty($item['materias'])) {
                    $materia = $item['materias'][0];
                    echo "<li><strong>materia_nombre:</strong> '" . ($materia['materia_nombre'] ?? 'NULL') . "'</li>";
                    echo "<li><strong>nombre_mostrar:</strong> '" . ($materia['nombre_mostrar'] ?? 'NULL') . "'</li>";
                    echo "<li><strong>curso_anio:</strong> '" . ($materia['curso_anio'] ?? 'NULL') . "'</li>";
                }
            }
            
            echo "</ul></div>";
        }
        
        echo "<div style='margin: 20px; padding: 20px; background: #e7f3ff; border: 1px solid #0066cc;'>";
        echo "<a href='" . preg_replace('/[&?]debug=1/', '', $_SERVER['REQUEST_URI']) . "' style='background: #0066cc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>✅ Continuar con PDF</a>";
        echo "</div>";
        exit;
    }
    
} catch (Exception $e) {
    // Si hay error, continuar sin materias pendientes
    $materiasAgrupadasPendientes = [];
    $estadisticasPendientes = ['total' => 0, 'aprobadas' => 0, 'en_proceso' => 0, 'sin_evaluar' => 0];
}

function tieneAlgunDato($item, $esGrupo = false) {
    if ($esGrupo) {
        $cal = $item['calificaciones_calculadas'];
        return !empty($cal['valoracion_preliminar_1c']) || 
               !empty($cal['calificacion_1c']) || 
               !empty($cal['valoracion_preliminar_2c']) || 
               !empty($cal['calificacion_2c']) || 
               !empty($cal['intensificacion_1c']) || 
               !empty($cal['intensificacion_diciembre']) || 
               !empty($cal['intensificacion_febrero']) || 
               !empty($cal['calificacion_final']) ||
               !empty($cal['observaciones']);
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

/**
 * FUNCIÓN ACTUALIZADA: Obtener orden personalizado de materias por año
 */
function obtenerOrdenPersonalizadoMaterias($anio) {
    $ordenPorAnio = [
        1 => [
            'PRÁCTICAS DEL LENGUAJE',
            'CIENCIAS SOCIALES', 
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN FÍSICA',
            'EDUCACIÓN ARTÍSTICA',
            'INGLÉS',
            'MATEMÁTICA',
            'CIENCIAS NATURALES',
            'LENGUAJES TECNOLÓGICOS',
            'SISTEMAS TECNOLÓGICOS',
            'PROCEDIMIENTOS TÉCNICOS'
        ],
        2 => [
            'BIOLOGÍA',
            'CONSTRUCCIÓN DE CIUDADANÍA',  // ✅ CORREGIDO: Incluir todas las variantes
            'EDUCACIÓN ARTÍSTICA', 
            'EDUCACIÓN FÍSICA',
            'FÍSICO QUÍMICA',
            'GEOGRAFÍA',
            'HISTORIA',
            'INGLÉS',
            'MATEMÁTICA',
            'PRÁCTICAS DEL LENGUAJE',
            'PROCEDIMIENTOS TÉCNICOS',
            'LENGUAJES TECNOLÓGICOS',
            'SISTEMAS TECNOLÓGICOS'
        ],
        3 => [
            'BIOLOGÍA',
            'CONSTRUCCIÓN DE CIUDADANÍA',  // ✅ CORREGIDO: Incluir todas las variantes
            'EDUCACIÓN ARTÍSTICA',
            'EDUCACIÓN FÍSICA',
            'FÍSICO QUÍMICA',
            'GEOGRAFÍA', 
            'HISTORIA',
            'INGLÉS',
            'MATEMÁTICA',
            'PRÁCTICAS DEL LENGUAJE',
            'PROCEDIMIENTOS TÉCNICOS',
            'LENGUAJES TECNOLÓGICOS', 
            'SISTEMAS TECNOLÓGICOS'
        ],
        4 => [
            'LITERATURA',
            'INGLÉS',
            'EDUCACIÓN FÍSICA',
            'SALUD Y ADOLESCENCIA',
            'HISTORIA',
            'GEOGRAFÍA',
            'MATEMÁTICA',
            'FÍSICA',
            'QUÍMICA',
            'CONOCIMIENTO DE LOS MATERIALES',
            'DIBUJO TECNOLÓGICO',                          // ✅ CORREGIDO: Antes de las técnicas
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',          // ✅ CORREGIDO: Posición correcta
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        5 => [
            'LITERATURA',
            'INGLÉS',
            'EDUCACIÓN FÍSICA',
            'POLÍTICA Y CIUDADANÍA',
            'HISTORIA',
            'GEOGRAFÍA',
            'ANÁLISIS MATEMÁTICO',                         // ✅ CORREGIDO: Después de GEOGRAFÍA
            'MECÁNICA Y MECANISMOS',                       // ✅ CORREGIDO: Posición correcta
            'ELECTROTECNIA',
            'RESISTENCIA Y ENSAYO DE MATERIALES',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        6 => [
            'LITERATURA',
            'INGLÉS', 
            'EDUCACIÓN FÍSICA',
            'FILOSOFÍA',
            'ARTE',
            'MATEMÁTICA APLICADA',
            'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',           // ✅ CORREGIDO: Después de MATEMÁTICA APLICADA
            'ELECTROTECNIA',
            'SISTEMAS MECÁNICOS',                          // ✅ CORREGIDO: Posición específica
            'DERECHOS DEL TRABAJO',
            'LABORATORIO DE MEDICIONES ELÉCTRICAS',        // ✅ CORREGIDO: Posición específica
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        7 => [
            'PRÁCTICAS PROFESIONALIZANTES',
            'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
            'ELECTRÓNICA INDUSTRIAL',
            'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
            'MÁQUINAS ELÉCTRICAS',
            'SISTEMAS MECÁNICOS',
            'LABORATORIO DE METROLOGÍA Y CONTROL DE CALIDAD', 
            'MANTENIMIENTO Y MONTAJE ELECTROMECÁNICO',
            'PROYECTO Y DISEÑO ELECTROMECÁNICO',
            'PROYECTO Y DISEÑO DE INSTALACIONES ELÉCTRICAS'
        ]
    ];
    
    return $ordenPorAnio[$anio] ?? [];
}

/**
 * FUNCIÓN: Determinar si una materia está en situación de recursado
 */
function esMateriaPorRecursar($item, $anioEstudiante) {
    if ($item['es_grupo']) {
        // Para grupos, no aplicamos lógica de recursado
        return false;
    } else {
        // Para materias individuales
        $anioMateria = $item['materia_anio'] ?? $anioEstudiante;
        return ($anioEstudiante > $anioMateria);
    }
}

/**
 * FUNCIÓN: Ordenar materias/grupos según orden personalizado y separar recursadas
 */
function ordenarMateriasPersonalizado($calificacionesCombinadas, $anioEstudiante) {
    $ordenPersonalizado = obtenerOrdenPersonalizadoMaterias($anioEstudiante);
    
    // PASO 1: Separar materias actuales del año vs materias por recursar
    $materiasActuales = [];
    $materiasRecursando = [];
    
    foreach ($calificacionesCombinadas as $item) {
        if (esMateriaPorRecursar($item, $anioEstudiante)) {
            $materiasRecursando[] = $item;
        } else {
            $materiasActuales[] = $item;
        }
    }
    
    // PASO 2: Ordenar las materias actuales según el orden personalizado
    $materiasActualesOrdenadas = ordenarGrupoMaterias($materiasActuales, $ordenPersonalizado);
    
    // PASO 3: Ordenar las materias por recursar alfabéticamente por año y luego por nombre
    usort($materiasRecursando, function($a, $b) {
        // Primero por año de la materia
        $anioA = $a['materia_anio'] ?? 0;
        $anioB = $b['materia_anio'] ?? 0;
        
        if ($anioA !== $anioB) {
            return $anioA <=> $anioB;
        }
        
        // Si son del mismo año, ordenar alfabéticamente
        $nombreA = $a['es_grupo'] ? $a['nombre'] : $a['materia_nombre'];
        $nombreB = $b['es_grupo'] ? $b['nombre'] : $b['materia_nombre'];
        return strcasecmp($nombreA, $nombreB);
    });
    
    // PASO 4: Combinar: primero materias actuales, luego materias por recursar
    return array_merge($materiasActualesOrdenadas, $materiasRecursando);
}

/**
 * FUNCIÓN: Ordenar un grupo de materias según orden personalizado
 */
function ordenarGrupoMaterias($materias, $ordenPersonalizado) {
    if (empty($ordenPersonalizado)) {
        // Si no hay orden personalizado, usar orden alfabético
        usort($materias, function($a, $b) {
            $nombreA = $a['es_grupo'] ? $a['nombre'] : $a['materia_nombre'];
            $nombreB = $b['es_grupo'] ? $b['nombre'] : $b['materia_nombre'];
            return strcasecmp($nombreA, $nombreB);
        });
        return $materias;
    }
    
    // Crear un mapa de posiciones para el orden personalizado
    $posiciones = array_flip(array_map('strtoupper', $ordenPersonalizado));
    
    // Separar materias que están en el orden personalizado de las que no
    $materiasConOrden = [];
    $materiasSinOrden = [];
    
    foreach ($materias as $item) {
        $nombreMateria = strtoupper($item['es_grupo'] ? $item['nombre'] : $item['materia_nombre']);
        
        // BÚSQUEDA MÁS FLEXIBLE: Buscar coincidencias parciales
        $encontrado = false;
        $posicionEncontrada = null;
        
        // Primero buscar coincidencia exacta
        if (isset($posiciones[$nombreMateria])) {
            $encontrado = true;
            $posicionEncontrada = $posiciones[$nombreMateria];
        } else {
            // Buscar coincidencias parciales o variaciones comunes
            foreach ($posiciones as $nombreOrden => $posicion) {
                // Verificar variaciones comunes de nombres
                if (sonNombresEquivalentes($nombreMateria, $nombreOrden)) {
                    $encontrado = true;
                    $posicionEncontrada = $posicion;
                    break;
                }
            }
        }
        
        if ($encontrado) {
            $materiasConOrden[] = [
                'item' => $item,
                'posicion' => $posicionEncontrada
            ];
        } else {
            $materiasSinOrden[] = $item;
        }
    }
    
    // Ordenar las materias que están en el orden personalizado
    usort($materiasConOrden, function($a, $b) {
        return $a['posicion'] <=> $b['posicion'];
    });
    
    // Ordenar alfabéticamente las materias que no están en el orden personalizado
    usort($materiasSinOrden, function($a, $b) {
        $nombreA = $a['es_grupo'] ? $a['nombre'] : $a['materia_nombre'];
        $nombreB = $b['es_grupo'] ? $b['nombre'] : $b['materia_nombre'];
        return strcasecmp($nombreA, $nombreB);
    });
    
    // Combinar: primero las del orden personalizado, luego las otras
    $resultado = [];
    
    // Agregar materias con orden personalizado
    foreach ($materiasConOrden as $materiaConOrden) {
        $resultado[] = $materiaConOrden['item'];
    }
    
    // Agregar materias sin orden personalizado al final
    foreach ($materiasSinOrden as $materiaSinOrden) {
        $resultado[] = $materiaSinOrden;
    }
    
    return $resultado;
}

/**
 * NUEVA FUNCIÓN: Obtener información de grupo para materia usando la misma lógica que materias pendientes
 */
function obtenerInfoGrupoMateria($db, $materiaCursoId, $cicloLectivoId) {
    try {
        // Consulta mejorada para obtener información de grupo
        $materiaConGrupo = $db->fetchOne(
            "SELECT m.nombre as materia_nombre, 
                    m.codigo as materia_codigo,
                    gm.nombre as grupo_nombre,
                    gm.codigo as grupo_codigo,
                    gm.curso_anio,
                    c.anio as curso_anio_real,
                    CASE 
                        WHEN gm.nombre IS NOT NULL THEN gm.nombre
                        ELSE m.nombre
                    END as nombre_mostrar
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mp.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        if ($materiaConGrupo) {
            // Log para debugging
            error_log("INFO GRUPO MATERIA - Materia: " . $materiaConGrupo['materia_nombre']);
            error_log("INFO GRUPO MATERIA - Grupo: " . ($materiaConGrupo['grupo_nombre'] ?? 'NULL'));
            error_log("INFO GRUPO MATERIA - Nombre mostrar: " . ($materiaConGrupo['nombre_mostrar'] ?? 'NULL'));
            
            return $materiaConGrupo;
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Error en obtenerInfoGrupoMateria: " . $e->getMessage());
        return null;
    }
}

/**
 * FUNCIÓN AUXILIAR: Verificar si dos nombres de materias son equivalentes
 */
function sonNombresEquivalentes($nombre1, $nombre2) {
    // Normalizar ambos nombres antes de comparar
    $nombre1Normalizado = normalizarNombreMateria($nombre1);
    $nombre2Normalizado = normalizarNombreMateria($nombre2);
    
    // Comparar nombres normalizados
    if ($nombre1Normalizado === $nombre2Normalizado) {
        return true;
    }
    
    // Convertir a mayúsculas y limpiar espacios para comparación adicional
    $nombre1 = trim(strtoupper($nombre1));
    $nombre2 = trim(strtoupper($nombre2));
    
    // Coincidencia exacta
    if ($nombre1 === $nombre2) {
        return true;
    }
    
    // ✅ MAPEO COMPLETO DE EQUIVALENCIAS - AMPLIADO CON TODAS LAS VARIANTES
    $equivalencias = [
        // Construcción de Ciudadanía - TODAS las modalidades
        'CONSTRUCCIÓN DE CIUDADANÍA' => [
            'CONSTR. DE CIUD. - MADERAS', 
            'CONSTR. DE CIUD. - METALES', 
            'CONSTR. DE CIUD. - ELECTRICIDAD', 
            'CONSTRUCCION DE CIUDADANIA',
            'CONST. CIUDADANIA',
            'CONSTR. DE CIUDADANIA',
            'CONSTR DE CIUD - MADERAS',
            'CONSTR DE CIUD - METALES',
            'CONSTR DE CIUD - ELECTRICIDAD'
        ],
        
        // Materias comunes
        'FÍSICO QUÍMICA' => ['FISICO QUIMICA', 'FÍSICO-QUÍMICA', 'FISICOQUIMICA'],
        'PRÁCTICAS DEL LENGUAJE' => ['PRACTICAS DEL LENGUAJE', 'PRACTICAS LENGUAJE', 'LENGUA'],
        'EDUCACIÓN FÍSICA' => ['EDUCACION FISICA', 'ED. FISICA', 'ED FISICA'],
        'EDUCACIÓN ARTÍSTICA' => ['EDUCACION ARTISTICA', 'ED. ARTISTICA', 'ED ARTISTICA'],
        'CIENCIAS NATURALES' => ['CIENCIAS NAT.', 'C. NATURALES'],
        'CIENCIAS SOCIALES' => ['CIENCIAS SOC.', 'C. SOCIALES'],
        'LENGUAJES TECNOLÓGICOS' => ['LENGUAJES TECNOLOGICOS', 'LENG. TECNOLOGICOS'],
        'SISTEMAS TECNOLÓGICOS' => ['SISTEMAS TECNOLOGICOS', 'SIST. TECNOLOGICOS'],
        'PROCEDIMIENTOS TÉCNICOS' => ['PROCEDIMIENTOS TECNICOS', 'PROC. TECNICOS'],
        'BIOLOGÍA' => ['BIOLOGIA'],
        'INGLÉS' => ['INGLES'],
        'MATEMÁTICA' => ['MATEMATICA'],
        'GEOGRAFÍA' => ['GEOGRAFIA'],
        'HISTORIA' => ['HIST'],
        'FILOSOFÍA' => ['FILOSOFIA'],
        'FÍSICA' => ['FISICA'],
        'QUÍMICA' => ['QUIMICA'],
        
        // ✅ Materias técnicas 4° año
        'DIBUJO TECNOLÓGICO' => ['DIBUJO TECNOLOGICO'],
        'CONOCIMIENTO DE LOS MATERIALES' => ['CONOCIMIENTO DE MATERIALES'],
        'SALUD Y ADOLESCENCIA' => ['SALUD Y ADOLESC.'],
        
        // ✅ Materias técnicas 5° año
        'ANÁLISIS MATEMÁTICO' => ['ANALISIS MATEMATICO'],
        'MECÁNICA Y MECANISMOS' => ['MECANICA Y MECANISMOS'],
        'RESISTENCIA Y ENSAYO DE MATERIALES' => ['RESISTENCIA Y ENSAYO MATERIALES', 'RESIST. Y ENSAYO MAT.'],
        'POLÍTICA Y CIUDADANÍA' => ['POLITICA Y CIUDADANIA'],
        
        // ✅ Materias técnicas 6° año
        'TERMODINÁMICA Y MÁQUINAS TÉRMICAS' => [
            'TERMO. Y MAQ. TÉRMICAS ELECTROTECNIA',
            'TERMODINAMICA Y MAQUINAS TERMICAS',
            'TERMO. Y MAQ. TERMICAS',
            'TERMODINÁMICA Y MÁQUINAS TÉRMICAS ELECTROTECNIA',
            'TERMO Y MAQ TERMICAS'
        ],
        'SISTEMAS MECÁNICOS' => [
            'SIST. MECÁNICOS DERECHOS DEL TRABAJO',
            'SISTEMAS MECANICOS',
            'SIST. MECANICOS'
        ],
        'LABORATORIO DE MEDICIONES ELÉCTRICAS' => [
            'LABORATORIO DE MED. ELÉCTRICAS',
            'LABORATORIO DE MEDICIONES ELECTRICAS',
            'LAB. DE MEDICIONES ELECTRICAS',
            'LAB. MED. ELECTRICAS'
        ],
        'DERECHOS DEL TRABAJO' => ['DERECHOS TRABAJO', 'DERECHOS DEL TRAB.'],
        'MATEMÁTICA APLICADA' => ['MATEMATICA APLICADA'],
        
        // ✅ Materias técnicas 7° año
        'PRÁCTICAS PROFESIONALIZANTES' => ['PRACTICAS PROFESIONALIZANTES'],
        'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL' => [
            'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
            'EMPRENDIMIENTOS PROD. Y DESARROLLO LOCAL'
        ],
        'ELECTRÓNICA INDUSTRIAL' => ['ELECTRONICA INDUSTRIAL'],
        'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE' => [
            'SEGURIDAD HIGIENE Y PROTECCION DEL MEDIO AMBIENTE',
            'SEG., HIG. Y PROT. DEL MEDIO AMB.'
        ],
        'LABORATORIO DE METROLOGÍA Y CONTROL DE CALIDAD' => [
            'LAB. DE METROLOGIA Y CONTROL DE CALIDAD',
            'LABORATORIO METROLOGIA Y CONTROL CALIDAD'
        ],
        'MANTENIMIENTO Y MONTAJE ELECTROMECÁNICO' => [
            'MANTENIMIENTO Y MONTAJE ELECTROMECANICO',
            'MANT. Y MONTAJE ELECTROMECANICO'
        ],
        'PROYECTO Y DISEÑO ELECTROMECÁNICO' => [
            'PROYECTO Y DISEÑO ELECTROMECANICO',
            'PROY. Y DISEÑO ELECTROMECANICO'
        ],
        'PROYECTO Y DISEÑO DE INSTALACIONES ELÉCTRICAS' => [
            'PROYECTO Y DISEÑO DE INSTALACIONES ELECTRICAS',
            'PROY. Y DISEÑO DE INST. ELECTRICAS'
        ],
        
        // Materias técnicas generales
        'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS' => [
            'MAQUINAS ELECTRICAS Y AUTOMATISMOS', 
            'MAQ. ELEC. Y AUTOMATISMOS', 
            'Máquinas Eléctricas y Automatismos',
            'MAQUINAS ELEC. Y AUTOMATISMOS'
        ],
        'DISEÑO Y PROCESAMIENTO MECÁNICO' => [
            'DISEÑO Y PROCESAMIENTO MEC.', 
            'DISEÑO Y PROC. MECANICO',
            'DISEÑO Y PROCESAMIENTO MECANICO'
        ],
        'INSTALACIONES Y APLICACIONES DE LA ENERGÍA' => [
            'INSTALACION Y APLIC. DE LA ENERGIA', 
            'INST. Y APLIC. ENERGIA',
            'INSTALACIONES Y APLIC. DE LA ENERGIA',
            'INSTALACION Y APLICACIONES DE LA ENERGIA'
        ]
    ];
    
    // Verificar equivalencias
    foreach ($equivalencias as $principal => $variantes) {
        if ($nombre1 === $principal || in_array($nombre1, $variantes)) {
            if ($nombre2 === $principal || in_array($nombre2, $variantes)) {
                return true;
            }
        }
    }
    
    // Verificar coincidencias parciales inteligentes (código existente...)
    $palabrasClaveEquivalencias = [
        'TERMODINÁMICA' => ['TERMO', 'TÉRMICAS'],
        'SISTEMAS MECÁNICOS' => ['SIST', 'MECÁNICOS'],
        'LABORATORIO' => ['LAB'],
        'MEDICIONES' => ['MED'],
        'ELÉCTRICAS' => ['ELEC'],
        'MÁQUINAS' => ['MAQ'],
        'APLICACIONES' => ['APLIC'],
        'INSTALACIONES' => ['INST'],
        'CONSTRUCCIÓN' => ['CONSTR'],
        'CIUDADANÍA' => ['CIUD'],
        'ANÁLISIS' => ['ANALISIS'],
        'MECÁNICA' => ['MECANICA'],
        'PRÁCTICAS' => ['PRACTICAS'],
        'PROFESIONALIZANTES' => ['PROF']
    ];
    
    // Verificar si uno contiene palabras clave del otro
    foreach ($palabrasClaveEquivalencias as $completa => $abreviadas) {
        $nombre1ContieneCompleta = strpos($nombre1, $completa) !== false;
        $nombre2ContieneCompleta = strpos($nombre2, $completa) !== false;
        
        $nombre1ContieneAbrev = false;
        $nombre2ContieneAbrev = false;
        
        foreach ($abreviadas as $abrev) {
            if (strpos($nombre1, $abrev) !== false) $nombre1ContieneAbrev = true;
            if (strpos($nombre2, $abrev) !== false) $nombre2ContieneAbrev = true;
        }
        
        if (($nombre1ContieneCompleta && $nombre2ContieneAbrev) || 
            ($nombre2ContieneCompleta && $nombre1ContieneAbrev)) {
            return true;
        }
    }
    
    // Verificar si uno contiene al otro (para casos de abreviaciones)
    if (strpos($nombre1, $nombre2) !== false || strpos($nombre2, $nombre1) !== false) {
        return true;
    }
    
    return false;
}


/**
 * FUNCIÓN CORREGIDA: Respetar valoraciones existentes
 */
function calcularValoracionesFaltantesMateriasIndividuales($materia) {
    $esING = (strtoupper($materia['codigo'] ?? '') === 'ING' || 
              strtoupper($materia['materia_codigo'] ?? '') === 'ING' || 
              strpos(strtoupper($materia['nombre'] ?? ''), 'INGLÉS') !== false);
    
    if ($esING) {
        error_log("DEBUG ING - Función iniciada para: " . ($materia['nombre'] ?? $materia['materia_nombre'] ?? 'sin nombre'));
        error_log("DEBUG ING - Valoración 1C original: '" . ($materia['valoracion_preliminar_1c'] ?? 'NULL') . "'");
        error_log("DEBUG ING - Calificación 1C: '" . ($materia['calificacion_1c'] ?? 'NULL') . "'");
    }
    
    // OBTENER valoraciones existentes de forma segura
    $valoracion1c_existente = isset($materia['valoracion_preliminar_1c']) ? trim($materia['valoracion_preliminar_1c']) : '';
    $valoracion2c_existente = isset($materia['valoracion_preliminar_2c']) ? trim($materia['valoracion_preliminar_2c']) : '';
    
    // VERIFICACIÓN ESTRICTA: Solo considerar vacío si NO es una valoración válida
    $valoracion1cEsValida = in_array($valoracion1c_existente, ['TEA', 'TEP', 'TED']);
    $valoracion2cEsValida = in_array($valoracion2c_existente, ['TEA', 'TEP', 'TED']);
    
    if ($esING) {
        error_log("DEBUG ING - Valoración 1C después de trim: '$valoracion1c_existente'");
        error_log("DEBUG ING - ¿Es valoración 1C válida?: " . ($valoracion1cEsValida ? 'SÍ' : 'NO'));
    }
    
    // REGLA PRINCIPAL: Si ya tiene una valoración válida, NUNCA la sobreescribir
    if ($valoracion1cEsValida) {
        if ($esING) {
            error_log("DEBUG ING - RESPETANDO valoración 1C existente: '$valoracion1c_existente'");
        }
        // No hacer nada, mantener la valoración existente
    } else {
        // Solo calcular si NO hay valoración válida Y hay calificación numérica
        if (!empty($materia['calificacion_1c']) && is_numeric($materia['calificacion_1c'])) {
            $calificacion1c = intval($materia['calificacion_1c']);
            if ($calificacion1c >= 7) {
                $materia['valoracion_preliminar_1c'] = 'TEA';
            } elseif ($calificacion1c >= 4) {
                $materia['valoracion_preliminar_1c'] = 'TEP';
            } else {
                $materia['valoracion_preliminar_1c'] = 'TED';
            }
            
            if ($esING) {
                error_log("DEBUG ING - CALCULANDO valoración 1C: " . $materia['valoracion_preliminar_1c'] . " (basada en calificación: $calificacion1c)");
            }
        }
    }
    
    // Misma lógica para 2° cuatrimestre
    if ($valoracion2cEsValida) {
        if ($esING) {
            error_log("DEBUG ING - RESPETANDO valoración 2C existente: '$valoracion2c_existente'");
        }
    } else {
        if (!empty($materia['calificacion_2c']) && is_numeric($materia['calificacion_2c'])) {
            $calificacion2c = intval($materia['calificacion_2c']);
            if ($calificacion2c >= 7) {
                $materia['valoracion_preliminar_2c'] = 'TEA';
            } elseif ($calificacion2c >= 4) {
                $materia['valoracion_preliminar_2c'] = 'TEP';
            } else {
                $materia['valoracion_preliminar_2c'] = 'TED';
            }
            
            if ($esING) {
                error_log("DEBUG ING - CALCULANDO valoración 2C: " . $materia['valoracion_preliminar_2c'] . " (basada en calificación: $calificacion2c)");
            }
        }
    }
    
    if ($esING) {
        error_log("DEBUG ING - Valores FINALES - Val1C: '" . ($materia['valoracion_preliminar_1c'] ?? 'NULL') . "', Cal1C: '" . ($materia['calificacion_1c'] ?? 'NULL') . "'");
    }
    
    return $materia;
}

/**
 * FUNCIÓN CORREGIDA Y ACTUALIZADA: Obtener calificaciones combinando grupos y materias individuales
 * NUEVA FUNCIONALIDAD: Soporte para grupos de materias y materias liberadas
 */
// FUNCIÓN ACTUALIZADA: Obtener calificaciones combinadas CON DETECCIÓN DE GRUPOS LIBERADOS
function obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio) {
    try {
        $resultado = [];
        
        // Obtener materias liberadas
        $materiasLiberadas = obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId);
        $materiasLiberadasIds = array_column($materiasLiberadas, 'materia_liberada_id');
        
        // Verificar si existen las funciones de agrupación del sistema principal
        $usarFuncionesAgrupacion = function_exists('obtenerGruposMaterias') && 
                                   function_exists('obtenerCalificacionesGruposEstudiante');
        
        if ($usarFuncionesAgrupacion) {
            // INTEGRACIÓN CON SISTEMA PRINCIPAL (CORREGIDA)
            
            $cursoEstudiante = $db->fetchOne(
                "SELECT c.* FROM cursos c
                 JOIN matriculas m ON c.id = m.curso_id
                 WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?",
                [$estudianteId, $cicloLectivoId]
            );
            
            if ($cursoEstudiante) {
                $grupos = obtenerGruposMaterias($db, $cursoEstudiante['id'], $cicloLectivoId);
                $calificacionesGrupos = obtenerCalificacionesGruposEstudiante($db, $estudianteId, $cicloLectivoId, $cursoEstudiante['id']);
                
                $materiasEnGrupos = [];
                
                foreach ($grupos as $grupo) {
                    $grupoId = $grupo['id'];
                    $calificacionesGrupo = $calificacionesGrupos[$grupoId] ?? [];
                    
                    // ✅ CORREGIDO: Asegurar que el grupo tenga NOMBRE y AÑO
                    $grupo['nombre'] = $grupo['nombre'] ?? 'Grupo sin nombre';
                    $grupo['anio_curso'] = $cursoEstudiante['anio']; // ✅ AGREGAR AÑO DEL CURSO
                    
                    // NUEVA FUNCIONALIDAD: Verificar si el grupo tiene materias liberadas
                    $grupo['tiene_materias_liberadas'] = grupoTieneMateriasLiberadas($db, $grupoId, $materiasLiberadasIds, $estudianteId, $cicloLectivoId);
                    
                    if ($grupo['tiene_materias_liberadas']) {
                        $grupo['materias_liberadas_detalle'] = obtenerDetalleMateriasLiberadasGrupo($db, $grupoId, $materiasLiberadasIds);
                    }
                    
                    // Registrar materias que están en grupos
                    if (isset($grupo['materias']) && is_array($grupo['materias'])) {
                        foreach ($grupo['materias'] as $materia) {
                            $materiasEnGrupos[] = $materia['materia_curso_id'];
                        }
                    }
                    
                    // ✅ CORREGIDO: Estructura de calificaciones calculadas
                    $grupo['calificaciones_calculadas'] = [
                        'valoracion_preliminar_1c' => null,
                        'calificacion_1c' => null,
                        'valoracion_preliminar_2c' => null,
                        'calificacion_2c' => null,
                        'intensificacion_1c' => null,
                        'intensificacion_diciembre' => null,
                        'intensificacion_febrero' => null,
                        'calificacion_final' => null,
                        'observaciones' => ''
                    ];
                    
                    // Procesar calificaciones del grupo para 1C
                    if (isset($calificacionesGrupo['cuatrimestres'][1])) {
                        $cal1c = $calificacionesGrupo['cuatrimestres'][1];
                        $grupo['calificaciones_calculadas']['calificacion_1c'] = $cal1c['calificacion_final'];
                        
                        if ($cal1c['calificacion_final']) {
                            if ($cal1c['calificacion_final'] >= 7) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_1c'] = 'TEA';
                            } elseif ($cal1c['calificacion_final'] >= 4) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_1c'] = 'TEP';
                            } else {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_1c'] = 'TED';
                            }
                        }
                    }
                    
                    // Procesar calificaciones del grupo para 2C
                    if (isset($calificacionesGrupo['cuatrimestres'][2])) {
                        $cal2c = $calificacionesGrupo['cuatrimestres'][2];
                        $grupo['calificaciones_calculadas']['calificacion_2c'] = $cal2c['calificacion_final'];
                        
                        if ($cal2c['calificacion_final']) {
                            if ($cal2c['calificacion_final'] >= 7) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_2c'] = 'TEA';
                            } elseif ($cal2c['calificacion_final'] >= 4) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_2c'] = 'TEP';
                            } else {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_2c'] = 'TED';
                            }
                        }
                    }
                    
                    // Calcular calificación final si hay ambos cuatrimestres
                    if ($grupo['calificaciones_calculadas']['calificacion_1c'] && 
                        $grupo['calificaciones_calculadas']['calificacion_2c']) {
                        $promedio = ($grupo['calificaciones_calculadas']['calificacion_1c'] + 
                                    $grupo['calificaciones_calculadas']['calificacion_2c']) / 2;
                        $grupo['calificaciones_calculadas']['calificacion_final'] = intval($promedio);
                    }
                    
                    $grupo['es_grupo'] = true;
                    $resultado[] = $grupo;
                }
            } else {
                $materiasEnGrupos = [];
            }
        } else {
            // LÓGICA CORREGIDA CON DETECCIÓN DE GRUPOS LIBERADOS
            
            $grupos = $db->fetchAll(
                "SELECT gm.*, rc.tipo_calculo, rc.nota_minima_prevalece
                 FROM grupos_materias gm
                 LEFT JOIN reglas_calculo_grupo rc ON gm.id = rc.grupo_id AND rc.activo = 1
                 WHERE gm.curso_anio = ? AND gm.ciclo_lectivo_id = ? AND gm.activo = 1
                 ORDER BY gm.orden_visualizacion",
                [$cursoAnio, $cicloLectivoId]
            );
            
            $materiasEnGrupos = [];
            
            foreach ($grupos as $grupo) {
                // ✅ CORREGIDO: Asegurar que el grupo tenga NOMBRE y AÑO
                $grupo['nombre'] = $grupo['nombre'] ?? 'Grupo sin nombre';
                $grupo['anio_curso'] = $cursoAnio; // ✅ AGREGAR AÑO DEL CURSO EXPLÍCITAMENTE
                
                // Verificar si el grupo tiene materias liberadas
                $grupo['tiene_materias_liberadas'] = grupoTieneMateriasLiberadas($db, $grupo['id'], $materiasLiberadasIds, $estudianteId, $cicloLectivoId);
                
                if ($grupo['tiene_materias_liberadas']) {
                    $grupo['materias_liberadas_detalle'] = obtenerDetalleMateriasLiberadasGrupo($db, $grupo['id'], $materiasLiberadasIds);
                }
                
                // Obtener materias del grupo CON sus valoraciones preliminares
                $materiasGrupo = $db->fetchAll(
                    "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                            mg.tipo_duracion, mg.trimestre_inicio,
                            mp.requiere_subgrupos, mp.id as materia_curso_id,
                            c.valoracion_preliminar_1c, c.valoracion_preliminar_2c,
                            c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                            c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                            c.observaciones
                     FROM materias_grupo mg
                     JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
                     JOIN materias m ON mp.materia_id = m.id
                     LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id 
                                                AND c.estudiante_id = ? 
                                                AND c.ciclo_lectivo_id = ?
                     WHERE mg.grupo_id = ? AND mg.activo = 1
                     ORDER BY mg.trimestre_inicio, m.nombre",
                    [$estudianteId, $cicloLectivoId, $grupo['id']]
                );
                
                // Registrar las materias que están en grupos
                foreach ($materiasGrupo as $materia) {
                    if (!empty($materia['materia_curso_id'])) {
                        $materiasEnGrupos[] = $materia['materia_curso_id'];
                    }
                }
                
                if (!empty($materiasGrupo)) {
                    $grupo['materias'] = $materiasGrupo;
                    $grupo['calificaciones_calculadas'] = calcularCalificacionesGrupo($materiasGrupo, $grupo);
                    $grupo['es_grupo'] = true;
                    $resultado[] = $grupo;
                }
            }
        }
        
        // 2. OBTENER MATERIAS INDIVIDUALES (CORREGIDAS)
        $materiasIndividuales = $db->fetchAll(
            "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo, 
                    curso_materia.anio as materia_anio, mp.id as materia_curso_id,
                    -- Mapear valoraciones bimestrales a preliminares
                    COALESCE(c.valoracion_preliminar_1c, c.valoracion_1bim) as valoracion_preliminar_1c,
                    COALESCE(c.valoracion_preliminar_2c, c.valoracion_3bim) as valoracion_preliminar_2c,
                    c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                    c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                    c.observaciones,
                    c.tipo_cursada,
                    m.id as materia_id,
                    -- Información de grupo (si existe)
                    gm.nombre as grupo_nombre,
                    gm.codigo as grupo_codigo,
                    -- Indicador si es parte de un grupo
                    CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo,
                    -- DEBUG: Incluir valores originales para verificación
                    c.valoracion_1bim, c.valoracion_3bim
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
             LEFT JOIN materias_grupo mg ON mp.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
             AND (c.tipo_cursada IS NULL OR c.tipo_cursada IN ('C', 'R'))
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        foreach ($materiasIndividuales as $materia) {
    // Solo incluir si NO está en un grupo
    if (!in_array($materia['materia_curso_id'], $materiasEnGrupos)) {
        
        // Verificar si es una materia liberada individual
        $materia['es_liberada'] = in_array($materia['materia_curso_id'], $materiasLiberadasIds);
        
        // 🆕 USAR LA MISMA LÓGICA QUE MATERIAS PENDIENTES
        $infoGrupoMateria = obtenerInfoGrupoMateria($db, $materia['materia_curso_id'], $cicloLectivoId);
        
        // Debug para todas las materias recursadas
        $anioEstudiante = $datosEstudiante['curso_anio'];
        $anioMateria = $materia['materia_anio'] ?? $anioEstudiante;
        $esRecursada = ($anioEstudiante > $anioMateria) || ($materia['tipo_cursada'] === 'R');
        
        if ($esRecursada) {
            error_log("=== DEBUG PDF MATERIA RECURSADA ===");
            error_log("Materia original: '" . $materia['materia_nombre'] . "'");
            error_log("materia_curso_id: " . $materia['materia_curso_id']);
            error_log("Información de grupo encontrada: " . ($infoGrupoMateria ? 'SÍ' : 'NO'));
            if ($infoGrupoMateria) {
                error_log("Grupo nombre: '" . ($infoGrupoMateria['grupo_nombre'] ?? 'NULL') . "'");
                error_log("Nombre a mostrar: '" . ($infoGrupoMateria['nombre_mostrar'] ?? 'NULL') . "'");
            }
        }
        
        // ✅ CORRECCIÓN PRINCIPAL: Aplicar lógica de grupo SIEMPRE que exista información de grupo
        if ($infoGrupoMateria && !empty($infoGrupoMateria['grupo_nombre'])) {
            // Usar el nombre del grupo (igual que materias pendientes)
            $materia['es_grupo'] = false; 
            $materia['nombre'] = $infoGrupoMateria['grupo_nombre']; 
            $materia['codigo'] = $infoGrupoMateria['grupo_codigo'] ?? $materia['materia_codigo'];
            $materia['nombre_original'] = $materia['materia_nombre']; 
            $materia['es_parte_grupo'] = 1;
            
            if ($esRecursada) {
                error_log("APLICANDO CAMBIO: '" . $materia['materia_nombre'] . "' -> '" . $materia['nombre'] . "'");
            }
        } else {
            // Materia individual normal
            $materia['es_grupo'] = false;
            $materia['nombre'] = $materia['materia_nombre']; 
            $materia['codigo'] = $materia['materia_codigo'];
            $materia['es_parte_grupo'] = 0;
            
            if ($esRecursada) {
                error_log("NO SE ENCONTRÓ GRUPO - manteniendo nombre original: '" . $materia['materia_nombre'] . "'");
            }
        }
        
        if ($esRecursada) {
            error_log("=== FIN DEBUG PDF MATERIA RECURSADA ===");
        }
        
        // RESTO DEL CÓDIGO DE MAPEO Y CÁLCULO...
        // (el código existente para mapeo de valoraciones continúa igual)
        
        // Mapeo y cálculo de valoraciones (código existente...)
        if (empty(trim($materia['valoracion_preliminar_1c'] ?? '')) && !empty(trim($materia['valoracion_1bim'] ?? ''))) {
            $materia['valoracion_preliminar_1c'] = $materia['valoracion_1bim'];
        }
        
        if (empty(trim($materia['valoracion_preliminar_2c'] ?? '')) && !empty(trim($materia['valoracion_3bim'] ?? ''))) {
            $materia['valoracion_preliminar_2c'] = $materia['valoracion_3bim'];
        }
        
        $materia = calcularValoracionesFaltantesMateriasIndividuales($materia);
        
        $resultado[] = $materia;
    }
}
        
        return $resultado;
        
    } catch (Exception $e) {
        error_log("ERROR en obtenerCalificacionesCombinadas: " . $e->getMessage());
        throw new Exception("Error al obtener calificaciones combinadas: " . $e->getMessage());
    }
}
/**
 * FUNCIÓN CORREGIDA: Calcular calificaciones de un grupo
 */
function calcularCalificacionesGrupo($materiasGrupo, $grupo) {
    $resultado = [
        'valoracion_preliminar_1c' => null,
        'calificacion_1c' => null,
        'valoracion_preliminar_2c' => null,
        'calificacion_2c' => null,
        'intensificacion_1c' => null,
        'intensificacion_diciembre' => null,
        'intensificacion_febrero' => null,
        'calificacion_final' => null,
        'observaciones' => ''
    ];
    
    // Campos numéricos para calcular
    $camposNumericos = [
        'calificacion_1c', 
        'calificacion_2c',
        'intensificacion_1c',
        'intensificacion_diciembre', 
        'intensificacion_febrero',
        'calificacion_final'
    ];
    
    // Procesar campos numéricos
    foreach ($camposNumericos as $campo) {
        $notas = [];
        $observacionesGrupo = [];
        
        foreach ($materiasGrupo as $materia) {
            if (isset($materia[$campo]) && $materia[$campo] !== null && $materia[$campo] !== '') {
                if (is_numeric($materia[$campo])) {
                    $notas[] = floatval($materia[$campo]);
                }
            }
            
            if (!empty($materia['observaciones'])) {
                $observacionesGrupo[] = $materia['materia_nombre'] . ': ' . $materia['observaciones'];
            }
        }
        
        if (!empty($notas)) {
            $notaMinima = min($notas);
            if ($notaMinima <= 6.0) {
                $resultado[$campo] = intval($notaMinima);
            } else {
                $promedio = array_sum($notas) / count($notas);
                $resultado[$campo] = intval($promedio);
            }
        }
        
        if (!empty($observacionesGrupo)) {
            $resultado['observaciones'] = implode(' | ', $observacionesGrupo);
        }
    }
    
    // PARA VALORACIONES: Usar las existentes, NO calcular automáticamente
    $valoracionesExistentes1c = [];
    $valoracionesExistentes2c = [];
    
    foreach ($materiasGrupo as $materia) {
        $val1c = trim($materia['valoracion_preliminar_1c'] ?? '');
        if (in_array($val1c, ['TEA', 'TEP', 'TED'])) {
            $valoracionesExistentes1c[] = $val1c;
        }
        
        $val2c = trim($materia['valoracion_preliminar_2c'] ?? '');
        if (in_array($val2c, ['TEA', 'TEP', 'TED'])) {
            $valoracionesExistentes2c[] = $val2c;
        }
    }
    
    // Determinar valoración del grupo basándose en las valoraciones existentes
    if (!empty($valoracionesExistentes1c)) {
        $valoracionesUnicas1c = array_unique($valoracionesExistentes1c);
        if (count($valoracionesUnicas1c) === 1) {
            $resultado['valoracion_preliminar_1c'] = $valoracionesUnicas1c[0];
        } else {
            // Si hay mixtas, usar la "peor": TED > TEP > TEA
            $jerarquia = ['TED' => 3, 'TEP' => 2, 'TEA' => 1];
            $peorValoracion = 'TEA';
            $peorPuntaje = 0;
            foreach ($valoracionesUnicas1c as $val) {
                $puntaje = $jerarquia[$val] ?? 0;
                if ($puntaje > $peorPuntaje) {
                    $peorPuntaje = $puntaje;
                    $peorValoracion = $val;
                }
            }
            $resultado['valoracion_preliminar_1c'] = $peorValoracion;
        }
    }
    
    if (!empty($valoracionesExistentes2c)) {
        $valoracionesUnicas2c = array_unique($valoracionesExistentes2c);
        if (count($valoracionesUnicas2c) === 1) {
            $resultado['valoracion_preliminar_2c'] = $valoracionesUnicas2c[0];
        } else {
            $jerarquia = ['TED' => 3, 'TEP' => 2, 'TEA' => 1];
            $peorValoracion = 'TEA';
            $peorPuntaje = 0;
            foreach ($valoracionesUnicas2c as $val) {
                $puntaje = $jerarquia[$val] ?? 0;
                if ($puntaje > $peorPuntaje) {
                    $peorPuntaje = $puntaje;
                    $peorValoracion = $val;
                }
            }
            $resultado['valoracion_preliminar_2c'] = $peorValoracion;
        }
    }
    
    return $resultado;
}

/**
 * FUNCIÓN ACTUALIZADA: Obtener valoraciones bimestrales combinadas con soporte para grupos
 */
function obtenerValoracionesBimestralesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio, $bimestre) {
    try {
        $campo_valoracion = 'valoracion_' . $bimestre . 'bim';
        $campo_desempeno = 'desempeno_' . $bimestre . 'bim';
        $campo_observaciones = 'observaciones_' . $bimestre . 'bim';
        
        $resultado = [];
        
        // 1. OBTENER GRUPOS DE MATERIAS
        $grupos = $db->fetchAll(
            "SELECT gm.*
             FROM grupos_materias gm
             WHERE gm.curso_anio = ? AND gm.ciclo_lectivo_id = ? AND gm.activo = 1
             ORDER BY gm.orden_visualizacion",
            [$cursoAnio, $cicloLectivoId]
        );
        
        $materiasEnGrupos = [];
        
        foreach ($grupos as $grupo) {
            $valoracionesGrupo = $db->fetchAll(
                "SELECT c.$campo_valoracion as valoracion_bimestral,
                        c.$campo_desempeno as desempeno_bimestral, 
                        c.$campo_observaciones as observaciones_bimestrales,
                        m.nombre as materia_nombre, m.codigo as materia_codigo,
                        mp.id as materia_curso_id
                 FROM materias_grupo mg
                 JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
                 JOIN materias m ON mp.materia_id = m.id
                 JOIN calificaciones c ON mp.id = c.materia_curso_id 
                                       AND c.estudiante_id = ? 
                                       AND c.ciclo_lectivo_id = ?
                 WHERE mg.grupo_id = ? AND mg.activo = 1 
                 ORDER BY mg.trimestre_inicio, m.nombre",
                [$estudianteId, $cicloLectivoId, $grupo['id']]
            );
            
            // Registrar materias en grupos
            foreach ($valoracionesGrupo as $val) {
                $materiasEnGrupos[] = $val['materia_curso_id'];
            }
            
            if (!empty($valoracionesGrupo)) {
                // Calcular valoración consolidada del grupo
                $grupo['valoracion_consolidada'] = calcularValoracionGrupo($valoracionesGrupo);
                $grupo['materias_valoraciones'] = $valoracionesGrupo;
                $grupo['es_grupo'] = true;
                $resultado[] = $grupo;
            }
        }
        
        // 2. OBTENER MATERIAS INDIVIDUALES (QUE NO ESTÁN EN GRUPOS) CON INFO DE GRUPOS
        $materiasIndividuales = $db->fetchAll(
            "SELECT c.$campo_valoracion as valoracion_bimestral,
                    c.$campo_desempeno as desempeno_bimestral,
                    c.$campo_observaciones as observaciones_bimestrales,
                    m.nombre as materia_nombre, m.codigo as materia_codigo,
                    mp.id as materia_curso_id,
                    -- Información de grupo (si existe)
                    gm.nombre as grupo_nombre,
                    gm.codigo as grupo_codigo,
                    -- Indicador si es parte de un grupo
                    CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             LEFT JOIN materias_grupo mg ON mp.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ? 
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        foreach ($materiasIndividuales as $materia) {
            // Solo incluir si NO está en un grupo procesado Y tiene valoración
            if (!in_array($materia['materia_curso_id'], $materiasEnGrupos) && 
                !empty(trim($materia['valoracion_bimestral']))) {
                
                // NUEVA LÓGICA: Si la materia pertenece a un grupo, usar el nombre del grupo
                if ($materia['es_parte_grupo'] == 1 && !empty($materia['grupo_nombre'])) {
                    // Es parte de un grupo: usar el nombre del grupo
                    $materia['es_grupo'] = false;
                    $materia['nombre'] = $materia['grupo_nombre']; // MOSTRAR NOMBRE DEL GRUPO
                    $materia['codigo'] = $materia['grupo_codigo'];
                } else {
                    // Materia individual normal
                    $materia['es_grupo'] = false;
                    $materia['nombre'] = $materia['materia_nombre'];
                    $materia['codigo'] = $materia['materia_codigo'];
                }
                
                $resultado[] = $materia;
            }
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        throw new Exception("Error al obtener valoraciones bimestrales combinadas: " . $e->getMessage());
    }
}

/**
 * FUNCIÓN: Calcular valoración consolidada de un grupo
 */
function calcularValoracionGrupo($valoracionesGrupo) {
    $valoraciones = [];
    $desempenos = [];
    $observaciones = [];
    
    foreach ($valoracionesGrupo as $valoracion) {
        $val = trim($valoracion['valoracion_bimestral'] ?? '');
        if (!empty($val)) {
            $valoraciones[] = $val;
        }
        
        $desemp = trim($valoracion['desempeno_bimestral'] ?? '');
        if (!empty($desemp)) {
            $desempenos[] = $desemp;
        }
        
        $obs = trim($valoracion['observaciones_bimestrales'] ?? '');
        if (!empty($obs)) {
            $observaciones[] = $valoracion['materia_nombre'] . ': ' . $obs;
        }
    }
    
    // Determinar valoración predominante (la más baja prevalece)
    $jerarquia = ['TED' => 1, 'TEP' => 2, 'TEA' => 3];
    $valoracionFinal = 'TEA'; // Por defecto
    $menorValor = 3;
    
    foreach ($valoraciones as $val) {
        if (isset($jerarquia[$val]) && $jerarquia[$val] < $menorValor) {
            $menorValor = $jerarquia[$val];
            $valoracionFinal = $val;
        }
    }
    
    return [
        'valoracion_bimestral' => $valoracionFinal,
        'desempeno_bimestral' => !empty($desempenos) ? implode(', ', array_unique($desempenos)) : '',
        'observaciones_bimestrales' => !empty($observaciones) ? implode(' | ', $observaciones) : ''
    ];
}

// Obtener datos según el tipo de boletín
if ($tipoBoletinSeleccionado === 'cuatrimestre') {
    try {
        // Obtener calificaciones combinadas (grupos + materias individuales)
        $calificacionesCombinadas = obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $datosEstudiante['curso_anio']);

        if (isset($_GET['debug']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    echo "<h3>📊 DATOS OBTENIDOS:</h3>";
    echo "<p><strong>Total items:</strong> " . count($calificacionesCombinadas) . "</p>";
    echo "<p><strong>Año del estudiante:</strong> " . ($datosEstudiante['curso_anio'] ?? 'NULL') . "</p>";
    
    echo "<h4>🔍 DETALLE DE LOS PRIMEROS 3 ITEMS:</h4>";
    
    foreach (array_slice($calificacionesCombinadas, 0, 3) as $index => $item) {
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px; background: #f9f9f9;'>";
        echo "<h5>Item $index " . (isset($item['es_grupo']) && $item['es_grupo'] ? '(GRUPO)' : '(MATERIA)') . ":</h5>";
        echo "<ul>";
        echo "<li><strong>es_grupo:</strong> " . (isset($item['es_grupo']) && $item['es_grupo'] ? 'SÍ' : 'NO') . "</li>";
        echo "<li><strong>nombre:</strong> '" . ($item['nombre'] ?? 'NULL') . "'</li>";
        echo "<li><strong>materia_nombre:</strong> '" . ($item['materia_nombre'] ?? 'NULL') . "'</li>";
        echo "<li><strong>anio_curso:</strong> '" . ($item['anio_curso'] ?? 'NULL') . "'</li>";
        echo "<li><strong>materia_anio:</strong> '" . ($item['materia_anio'] ?? 'NULL') . "'</li>";
        echo "<li><strong>curso_anio:</strong> '" . ($item['curso_anio'] ?? 'NULL') . "'</li>";
        
        if (isset($item['es_grupo']) && $item['es_grupo']) {
            echo "<li><strong>calificaciones_calculadas existe:</strong> " . (isset($item['calificaciones_calculadas']) ? 'SÍ' : 'NO') . "</li>";
            if (isset($item['calificaciones_calculadas'])) {
                $cal = $item['calificaciones_calculadas'];
                echo "<li><strong>cal_1c:</strong> '" . ($cal['calificacion_1c'] ?? 'NULL') . "'</li>";
                echo "<li><strong>cal_2c:</strong> '" . ($cal['calificacion_2c'] ?? 'NULL') . "'</li>";
            }
        }
        
        echo "<li><strong>Todos los campos disponibles:</strong> " . implode(', ', array_keys($item)) . "</li>";
        echo "</ul></div>";
    }
    
    // Botón para continuar y generar PDF
    echo "<div style='margin: 20px; padding: 20px; background: #e7f3ff; border: 1px solid #0066cc;'>";
    echo "<h4>🎯 ¿Continuar con la generación del PDF?</h4>";
    echo "<p>Revisa los datos arriba. Si todo se ve bien, haz clic en:</p>";
    
    $currentUrl = $_SERVER['REQUEST_URI'];
    $urlSinDebug = preg_replace('/[&?]debug=1/', '', $currentUrl);
    
    echo "<a href='$urlSinDebug' style='background: #0066cc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>✅ Generar PDF Ahora</a>";
    echo "</div>";
    
    // Parar aquí para revisar los datos
    exit;
}
        
        // Obtener asistencias por cuatrimestre
        $asistencias = $db->fetchAll(
            "SELECT cuatrimestre, 
                    COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                    COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas,
                    COUNT(CASE WHEN estado = 'cuarto_falta' THEN 1 END) as cuartos_faltas,
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
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al obtener datos combinados: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        header('Location: boletines.php');
        exit;
    }

} else {
    try {
        // Obtener valoraciones bimestrales combinadas
        $valoracionesCombinadas = obtenerValoracionesBimestralesCombinadas($db, $estudianteId, $cicloLectivoId, $datosEstudiante['curso_anio'], $bimestreSeleccionado);
        
        // Asistencias del período correspondiente al bimestre
        $cuatrimestreCorrespondiente = ($bimestreSeleccionado == 1) ? 1 : 2;
        $asistenciasBimestre = $db->fetchOne(
            "SELECT cuatrimestre,
                    COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                    COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas,
                    COUNT(CASE WHEN estado = 'cuarto_falta' THEN 1 END) as cuartos_faltas,
                    COUNT(CASE WHEN estado = 'justificada' THEN 1 END) as justificadas,
                    COUNT(*) as total_dias
             FROM asistencias
             WHERE estudiante_id = ? AND curso_id = ? AND cuatrimestre = ?",
            [$estudianteId, $cursoId, $cuatrimestreCorrespondiente]
        );
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al obtener datos bimestrales combinados: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        header('Location: boletines.php');
        exit;
    }
}

// Incluir la biblioteca FPDF
require('lib/fpdf_utf8.php');

function convertirMayusculasConTildes($texto) {
    // Mapeo manual de caracteres acentuados a mayúsculas
    $mapeo = array(
        'á' => 'Á', 'à' => 'À', 'ä' => 'Ä', 'â' => 'Â',
        'é' => 'É', 'è' => 'È', 'ë' => 'Ë', 'ê' => 'Ê',
        'í' => 'Í', 'ì' => 'Ì', 'ï' => 'Ï', 'î' => 'Î',
        'ó' => 'Ó', 'ò' => 'Ò', 'ö' => 'Ö', 'ô' => 'Ô',
        'ú' => 'Ú', 'ù' => 'Ù', 'ü' => 'Ü', 'û' => 'Û',
        'ñ' => 'Ñ',
        'ç' => 'Ç'
    );
    
    // Primero convertir a mayúsculas normal
    $textoMayuscula = strtoupper($texto);
    
    // Luego aplicar el mapeo manual para caracteres acentuados
    foreach ($mapeo as $minuscula => $mayuscula) {
        $textoMayuscula = str_replace($minuscula, $mayuscula, $textoMayuscula);
    }
    
    return $textoMayuscula;
}

/**
 * FUNCIÓN AMPLIADA: Normalización completa con todas las variantes encontradas
 */
function normalizarNombreMateria($nombreMateria) {
    // Convertir a mayúsculas para comparación
    $nombreUpper = strtoupper(trim($nombreMateria));
    
    // Reglas de normalización específicas COMPLETAMENTE AMPLIADAS
    $reglasNormalizacion = [
        // ✅ CONSTRUCCIÓN DE CIUDADANÍA - TODAS las modalidades encontradas
        '/^CONSTR\.?\s*DE\s*CIUD\.?\s*-?\s*.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONSTRUCCION\s*DE\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONST\.?\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        
        // ✅ MATERIAS TÉCNICAS de 4° año
        '/^DIBUJO\s*TECNOLOGICO$/i' => 'DIBUJO TECNOLÓGICO',
        '/^MAQUINAS\s*ELECTRICAS\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        '/^MAQ\.?\s*ELEC\.?\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        
        // ✅ MATERIAS de 5° año
        '/^ANALISIS\s*MATEMATICO$/i' => 'ANÁLISIS MATEMÁTICO',
        '/^MECANICA\s*Y\s*MECANISMOS$/i' => 'MECÁNICA Y MECANISMOS',
        '/^RESISTENCIA\s*Y\s*ENSAYO\s*DE\s*MATERIALES$/i' => 'RESISTENCIA Y ENSAYO DE MATERIALES',
        '/^POLITICA\s*Y\s*CIUDADANIA$/i' => 'POLÍTICA Y CIUDADANÍA',
        
        // ✅ MATERIAS de 6° año - CORREGIDAS
        '/^TERMO\.?\s*Y\s*MAQ\.?\s*TÉRMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^TERMODINAMICA\s*Y\s*MAQUINAS\s*TERMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^SIST\.?\s*MECÁNICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^SISTEMAS\s*MECANICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^LAB\.?\s*DE\s*MED\.?\s*ELÉCTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^LABORATORIO\s*DE\s*MEDICIONES\s*ELECTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^DERECHOS\s*DEL\s*TRABAJO$/i' => 'DERECHOS DEL TRABAJO',
        
        // ✅ MATERIAS de 7° año
        '/^PRACTICAS\s*PROFESIONALIZANTES$/i' => 'PRÁCTICAS PROFESIONALIZANTES',
        '/^EMPRENDIMIENTOS\s*PRODUCTIVOS\s*Y\s*DESARROLLO\s*LOCAL$/i' => 'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
        '/^ELECTRONICA\s*INDUSTRIAL$/i' => 'ELECTRÓNICA INDUSTRIAL',
        '/^SEGURIDAD,?\s*HIGIENE\s*Y\s*PROTECCION\s*DEL\s*MEDIO\s*AMBIENTE$/i' => 'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
        '/^LABORATORIO\s*DE\s*METROLOGIA\s*Y\s*CONTROL\s*DE\s*CALIDAD$/i' => 'LABORATORIO DE METROLOGÍA Y CONTROL DE CALIDAD',
        '/^MANTENIMIENTO\s*Y\s*MONTAJE\s*ELECTROMECANICO$/i' => 'MANTENIMIENTO Y MONTAJE ELECTROMECÁNICO',
        '/^PROYECTO\s*Y\s*DISEÑO\s*ELECTROMECANICO$/i' => 'PROYECTO Y DISEÑO ELECTROMECÁNICO',
        '/^PROYECTO\s*Y\s*DISEÑO\s*DE\s*INSTALACIONES\s*ELECTRICAS$/i' => 'PROYECTO Y DISEÑO DE INSTALACIONES ELÉCTRICAS',
        
        // ✅ MATERIAS COMUNES - COMPLETAR NORMALIZACIÓN
        '/^PRACTICAS\s*DEL\s*LENGUAJE$/i' => 'PRÁCTICAS DEL LENGUAJE',
        '/^EDUCACION\s*FISICA$/i' => 'EDUCACIÓN FÍSICA',
        '/^EDUCACION\s*ARTISTICA$/i' => 'EDUCACIÓN ARTÍSTICA',
        '/^FISICO\s*QUIMICA$/i' => 'FÍSICO QUÍMICA',
        '/^CIENCIAS\s*NAT\.?$/i' => 'CIENCIAS NATURALES',
        '/^CIENCIAS\s*SOC\.?$/i' => 'CIENCIAS SOCIALES',
        '/^LENG\.?\s*TECNOLOGICOS$/i' => 'LENGUAJES TECNOLÓGICOS',
        '/^SIST\.?\s*TECNOLOGICOS$/i' => 'SISTEMAS TECNOLÓGICOS',
        '/^PROC\.?\s*TECNICOS$/i' => 'PROCEDIMIENTOS TÉCNICOS',
        '/^MATEMATICA$/i' => 'MATEMÁTICA',
        '/^MATEMATICA\s*APLICADA$/i' => 'MATEMÁTICA APLICADA',
        '/^GEOGRAFIA$/i' => 'GEOGRAFÍA',
        '/^BIOLOGIA$/i' => 'BIOLOGÍA',
        '/^HISTORIA$/i' => 'HISTORIA',
        '/^INGLES$/i' => 'INGLÉS',
        '/^LITERATURA$/i' => 'LITERATURA',
        '/^FILOSOFIA$/i' => 'FILOSOFÍA',
        '/^FISICA$/i' => 'FÍSICA',
        '/^QUIMICA$/i' => 'QUÍMICA',
        '/^ELECTROTECNIA$/i' => 'ELECTROTECNIA',
        
        // ✅ MATERIAS TÉCNICAS GENERALES
        '/^DISEÑO\s*Y\s*PROC\.?\s*MECANICO$/i' => 'DISEÑO Y PROCESAMIENTO MECÁNICO',
        '/^DISEÑO\s*Y\s*PROCESAMIENTO\s*MEC\.?$/i' => 'DISEÑO Y PROCESAMIENTO MECÁNICO',
        '/^INST\.?\s*Y\s*APLIC\.?\s*ENERGIA$/i' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA',
        '/^INSTALACION\s*Y\s*APLIC\.?\s*DE\s*LA\s*ENERGIA$/i' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA',
        '/^INSTALACIONES\s*Y\s*APLICACIONES\s*DE\s*LA\s*ENERGIA$/i' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA',
        '/^CONOCIMIENTO\s*DE\s*LOS\s*MATERIALES$/i' => 'CONOCIMIENTO DE LOS MATERIALES',
        '/^SALUD\s*Y\s*ADOLESCENCIA$/i' => 'SALUD Y ADOLESCENCIA',
    ];
    
    // Aplicar reglas de normalización
    foreach ($reglasNormalizacion as $patron => $reemplazo) {
        if (preg_match($patron, $nombreUpper)) {
            return $reemplazo;
        }
    }
    
    // Si no coincide con ninguna regla, devolver el nombre original en mayúsculas
    return convertirMayusculasConTildes($nombreMateria);
}

// Crear una clase personalizada para el PDF del boletín
class BoletinPDF extends FPDF_UTF8 {
    private $tipoBoletín;
    private $bimestre;
    private $cuatrimestre;
    private $materiasLiberadasIds; // NUEVA PROPIEDAD
    
    public function __construct($tipoBoletín = 'cuatrimestre', $bimestre = 1, $cuatrimestre = 1, $materiasLiberadasIds = []) {
        parent::__construct('L', 'mm', 'A4');
        $this->tipoBoletín = $tipoBoletín;
        $this->bimestre = $bimestre;
        $this->cuatrimestre = $cuatrimestre;
        $this->materiasLiberadasIds = $materiasLiberadasIds; // NUEVA FUNCIONALIDAD
        
        // MÁRGENES MÁS AMPLIOS
        $this->SetMargins(10, 5, 10); // Izquierdo, Superior, Derecho
        $this->SetAutoPageBreak(true, 5); // Margen inferior
    }
    
    function Header() {
        // Ancho de la página (para A4 en horizontal es aproximadamente 297mm)
        $anchoPagina = $this->GetPageWidth();
        
        // Dimensiones del logo (reducidas)
        $logoAncho = 16;
        $logoAlto = 16;
        
        // Logo a la izquierda
        $this->Image('assets/img/logo.png', 10, 8, $logoAncho, $logoAlto);
        
        // Texto al lado del logo (empezar después del logo + margen)
        $inicioTextoX = 5 + $logoAncho + 2; // 5mm de margen
        
        // Título principal centrado en el espacio restante
        $anchoTextoDisponible = $anchoPagina - $inicioTextoX - 5; // 10mm margen derecho
        $this->SetXY($inicioTextoX, 5);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($anchoTextoDisponible, 7, 'ESCUELA TÉCNICA HENRY FORD', 0, 1, 'C');
        
        // Subtítulo centrado según el tipo
        $this->SetX($inicioTextoX);
        $this->SetFont('Arial', 'B', 11);
        if ($this->tipoBoletín === 'cuatrimestre') {
            $this->Cell($anchoTextoDisponible, 7, 'REGISTRO INSTITUCIONAL DE TRAYECTORIAS EDUCATIVAS (RITE)', 0, 1, 'C');
        } else {
            $bimestreTexto = ($this->bimestre == 1) ? '1er' : '3er';
            $this->Cell($anchoTextoDisponible, 7, 'BOLETÍN DE VALORACIONES BIMESTRALES' , 0, 1, 'C');
        }
        
        // Espacio antes del contenido (reducido)
        $this->Ln(4);
    }

    // FUNCIÓN CORREGIDA: TablaCalificacionesCombinadas con dimensiones correctas
    function TablaCalificacionesCombinadas($calificacionesCombinadas, $datosEstudiante) {
        // APLICAR ORDENAMIENTO PERSONALIZADO
        $calificacionesCombinadas = ordenarMateriasPersonalizado($calificacionesCombinadas, $datosEstudiante['curso_anio']);
        
        // ✅ DEBUG: Log para verificar datos
        if (count($calificacionesCombinadas) > 0) {
            error_log("DEBUG PDF - Primera materia/grupo: " . print_r($calificacionesCombinadas[0], true));
        }
        
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
        
        // Datos combinados
        $fill = false;
        foreach($calificacionesCombinadas as $item) {
            
            // ✅ NUEVA LÓGICA MEJORADA: Verificar si es liberada
            $esLiberada = false;
            
            if ($item['es_grupo']) {
                $esLiberada = isset($item['tiene_materias_liberadas']) && $item['tiene_materias_liberadas'];
            } else {
                $esLiberada = isset($item['es_liberada']) && $item['es_liberada'];
                if (!isset($item['es_liberada']) && isset($item['materia_curso_id'])) {
                    $esLiberada = in_array($item['materia_curso_id'], $this->materiasLiberadasIds);
                }
            }
            
            // ESTABLECER COLORES DE FILA
            if ($esLiberada) {
                $colorFilaR = 255; $colorFilaG = 255; $colorFilaB = 150;
            } elseif ($fill) {
                $colorFilaR = 224; $colorFilaG = 235; $colorFilaB = 255;
            } else {
                $colorFilaR = 255; $colorFilaG = 255; $colorFilaB = 255;
            }
            
            if ($item['es_grupo']) {
                // ===== PROCESAMIENTO DE GRUPOS =====
                $cal = $item['calificaciones_calculadas'];
                $tipoCursada = 'C';
                
                // ✅ CORREGIDO: Obtener AÑO del grupo
                $anioItem = $item['anio_curso'] ?? $datosEstudiante['curso_anio'] ?? '?';
                
                // ✅ CORREGIDO: Obtener NOMBRE del grupo
                $nombreItem = 'Grupo sin nombre'; // Valor por defecto
                if (isset($item['nombre']) && !empty(trim($item['nombre']))) {
                    $nombreItem = normalizarNombreMateria($item['nombre']);
                } elseif (isset($item['grupo_nombre']) && !empty(trim($item['grupo_nombre']))) {
                    $nombreItem = normalizarNombreMateria($item['grupo_nombre']);
                }
                
                // Log para debugging
                error_log("DEBUG PDF GRUPO - Nombre: '$nombreItem', Año: '$anioItem'");
                
                if ($esLiberada) {
                    $tipoCursada = '';
                }
                
                // Render de celdas para GRUPOS
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
                
                $this->Cell(10, 6, $tipoCursada, 1, 0, 'C', true);
                $this->Cell(75, 6, $nombreItem, 1, 0, 'L', true);
                $this->Cell(10, 6, $anioItem, 1, 0, 'C', true);
                
                // Valoración 1C
                $this->Cell(15, 6, !empty($cal['valoracion_preliminar_1c']) ? $cal['valoracion_preliminar_1c'] : '-', 1, 0, 'C', true);
                
                // Calificación 1C con color condicional
                $calificacion1c = $cal['calificacion_1c'];
                if (!empty($calificacion1c) && is_numeric($calificacion1c) && floatval($calificacion1c) <= 6) {
                    $this->SetFillColor(255, 200, 200);
                    $this->SetTextColor(139, 0, 0);
                } else {
                    $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                    $this->SetTextColor(0, 0, 0);
                }
                $this->Cell(15, 6, !empty($calificacion1c) ? $calificacion1c : '-', 1, 0, 'C', true);
                
                // Restaurar color y continuar con el resto de columnas...
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
                
                $this->Cell(15, 6, !empty($cal['valoracion_preliminar_2c']) ? $cal['valoracion_preliminar_2c'] : '-', 1, 0, 'C', true);
                
                // Calificación 2C
                $calificacion2c = $cal['calificacion_2c'];
                if (!empty($calificacion2c) && is_numeric($calificacion2c) && floatval($calificacion2c) <= 6) {
                    $this->SetFillColor(255, 200, 200);
                    $this->SetTextColor(139, 0, 0);
                } else {
                    $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                    $this->SetTextColor(0, 0, 0);
                }
                $this->Cell(15, 6, !empty($calificacion2c) ? $calificacion2c : '-', 1, 0, 'C', true);
                
                // Intensificaciones (con colores condicionales)
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
                
                $intensificacion1c = $cal['intensificacion_1c'];
                $this->Cell(20, 6, !empty($intensificacion1c) ? $intensificacion1c : '-', 1, 0, 'C', true);
                
                $intensificacionDic = $cal['intensificacion_diciembre'];
                $this->Cell(15, 6, !empty($intensificacionDic) ? $intensificacionDic : '-', 1, 0, 'C', true);
                
                $intensificacionFeb = $cal['intensificacion_febrero'];
                $this->Cell(15, 6, !empty($intensificacionFeb) ? $intensificacionFeb : '-', 1, 0, 'C', true);
                
                // Calificación Final
                $calificacionFinal = $cal['calificacion_final'];
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
                } elseif (!tieneAlgunDato($item, true)) {
                    $observaciones = 'No cursa la materia';
                } else {
                    $observaciones = !empty($cal['observaciones']) ? $cal['observaciones'] : '-';
                }
                
                $this->Cell(45, 6, $observaciones, 1, 0, 'L', true);
                
            } else {
                // ===== PROCESAMIENTO DE MATERIAS INDIVIDUALES =====

// ✅ CORREGIDO: Determinar año y tipo de cursada
$anioEstudiante = $datosEstudiante['curso_anio'];
$anioMateria = $item['materia_anio'] ?? $anioEstudiante ?? '?';

if ($esLiberada) {
    $tipoCursada = '';
} else {
    $tipoCursada = ($anioEstudiante > $anioMateria) ? 'R' : 'C';
}

// ✅ CORREGIDO: Obtener NOMBRE de materia individual
$nombreItem = 'Materia sin nombre'; // Valor por defecto

// PRIORIDAD 1: Si tiene 'nombre' (que debería contener el nombre del grupo si corresponde)
if (isset($item['nombre']) && !empty(trim($item['nombre']))) {
    $nombreItem = normalizarNombreMateria($item['nombre']);
} 
// PRIORIDAD 2: Si no tiene 'nombre', usar 'materia_nombre'
elseif (isset($item['materia_nombre']) && !empty(trim($item['materia_nombre']))) {
    $nombreItem = normalizarNombreMateria($item['materia_nombre']);
}

// Log para debugging
error_log("DEBUG PDF INDIVIDUAL - Nombre final: '$nombreItem', Año: '$anioMateria', Tipo: '$tipoCursada'");

// Si es recursada, agregar más info de debug
if ($tipoCursada === 'R') {
    error_log("MATERIA RECURSADA - Original: '" . ($item['materia_nombre'] ?? 'NULL') . "' -> Final: '$nombreItem'");
    error_log("MATERIA RECURSADA - es_parte_grupo: " . ($item['es_parte_grupo'] ?? 'NULL'));
}
                
                // Render de celdas para MATERIAS INDIVIDUALES
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
                
                // Continuar con resto de columnas...
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
            }
            
            $this->Ln();
            $fill = !$fill;
        }
    }

    // ✅ FUNCIÓN CORREGIDA: TablaMateriasesPendientes con nombres y años correctos
function TablaMateriasesPendientes($materiasAgrupadasPendientes) {
    if (empty($materiasAgrupadasPendientes)) {
        return; // No mostrar nada si no hay materias pendientes
    }
    
    
    // Cabeceras - USANDO EL MISMO COLOR AZUL QUE LA TABLA PRINCIPAL
    $this->SetFillColor(73, 173, 245); // Color azul igual al de la tabla principal
    $this->SetTextColor(255); // Texto blanco
    $this->SetDrawColor(128, 128, 128);
    $this->SetLineWidth(0.3);
    $this->SetFont('Arial', 'B', 7);
    
    // Primera fila de cabeceras
    $this->Cell(85, 7, 'MATERIAS PENDIENTES', 1, 0, 'C', true);
    $this->Cell(10, 7, 'AÑO', 1, 0, 'C', true);
    $this->Cell(50, 7, 'PERÍODOS DE INTENSIFICACIÓN', 1, 0, 'C', true);
    $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
    $this->Cell(20, 7, 'ESTADO', 1, 0, 'C', true);
    $this->Cell(85, 7, 'OBSERVACIONES', 1, 0, 'C', true);
    $this->Ln();
    
    // Segunda fila de cabeceras (períodos)
    $this->Cell(85, 7, '', 1, 0, 'C', true);
    $this->Cell(10, 7, '', 1, 0, 'C', true);
    $this->Cell(10, 7, 'MAR', 1, 0, 'C', true);
    $this->Cell(10, 7, 'JUL', 1, 0, 'C', true);
    $this->Cell(10, 7, 'AGO', 1, 0, 'C', true);
    $this->Cell(10, 7, 'DIC', 1, 0, 'C', true);
    $this->Cell(10, 7, 'FEB', 1, 0, 'C', true);
    $this->Cell(15, 7, 'FINAL', 1, 0, 'C', true);
    $this->Cell(20, 7, '', 1, 0, 'C', true);
    $this->Cell(85, 7, '', 1, 0, 'C', true);
    $this->Ln();
    
    // Datos
    $this->SetFont('Arial', '', 7);
    $fill = false;
    
    foreach($materiasAgrupadasPendientes as $item) {
        // Alternar colores de fila - USANDO TONOS AZULES
        if ($fill) {
            $this->SetFillColor(224, 235, 255); // Azul muy claro (igual a tabla principal)
        } else {
            $this->SetFillColor(255, 255, 255); // Blanco
        }
        $this->SetTextColor(0, 0, 0);
        
        if ($item['es_grupo']) {
            // Es un grupo de materias
            $estadoGrupo = calcularEstadoGrupoPendiente($item['materias']);
            $primeraMateria = $item['materias'][0];
            
            // ✅ CORREGIDO: Obtener nombre del grupo correctamente
            $nombreCompleto = '';
            if (!empty($item['grupo_nombre'])) {
                $nombreCompleto = normalizarNombreMateria($item['grupo_nombre']);
            } else {
                $nombreCompleto = 'Grupo sin nombre';
            }
            
            $this->Cell(85, 6, $nombreCompleto, 1, 0, 'L', true);
            
            // ✅ CORREGIDO: Obtener año correcto del grupo
            $anioMostrar = '?';
            if (!empty($primeraMateria['curso_anio'])) {
                $anioMostrar = $primeraMateria['curso_anio'];
            } elseif (!empty($item['curso_anio'])) {
                $anioMostrar = $item['curso_anio'];
            }
            $this->Cell(10, 6, $anioMostrar, 1, 0, 'C', true);
            
            // Períodos consolidados del grupo
            $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
            foreach ($periodos as $periodo) {
                $estadosPeriodo = [];
                foreach ($item['materias'] as $matGrupo) {
                    if (!empty($matGrupo[$periodo])) {
                        $estadosPeriodo[] = $matGrupo[$periodo];
                    }
                }
                
                $estadoConsolidado = '';
                if (count($estadosPeriodo) === count($item['materias'])) {
                    // Todas tienen estado
                    if (count(array_filter($estadosPeriodo, function($e) { return $e === 'AA'; })) === count($estadosPeriodo)) {
                        $estadoConsolidado = 'AA';
                    } elseif (in_array('CSA', $estadosPeriodo)) {
                        $estadoConsolidado = 'CSA';
                    } elseif (in_array('CCA', $estadosPeriodo)) {
                        $estadoConsolidado = 'CCA';
                    } else {
                        $estadoConsolidado = 'CSA';
                    }
                }
                
                // Aplicar color según el estado consolidado
                if ($estadoConsolidado === 'AA') {
                    $this->SetFillColor(220, 255, 220); // Verde claro
                    $this->SetTextColor(0, 100, 0); // Verde oscuro
                } elseif ($estadoConsolidado === 'CCA') {
                    $this->SetFillColor(255, 255, 220); // Amarillo claro
                    $this->SetTextColor(150, 150, 0); // Amarillo oscuro
                } elseif ($estadoConsolidado === 'CSA') {
                    $this->SetFillColor(255, 220, 220); // Rojo claro
                    $this->SetTextColor(150, 0, 0); // Rojo oscuro
                } else {
                    // Sin estado, usar color de fila normal
                    if ($fill) {
                        $this->SetFillColor(224, 235, 255); // Azul claro
                    } else {
                        $this->SetFillColor(255, 255, 255); // Blanco
                    }
                    $this->SetTextColor(0, 0, 0);
                }
                
                $valorMostrar = !empty($estadoConsolidado) ? $estadoConsolidado : '-';
                if (!empty($estadosPeriodo) && count($estadosPeriodo) !== count($item['materias'])) {
                    $valorMostrar = count($estadosPeriodo) . '/' . count($item['materias']);
                }
                
                $this->Cell(10, 6, $valorMostrar, 1, 0, 'C', true);
            }
            
            // Restaurar color de fila para calificación final
            if ($fill) {
                $this->SetFillColor(224, 235, 255); // Azul claro
            } else {
                $this->SetFillColor(255, 255, 255); // Blanco
            }
            $this->SetTextColor(0, 0, 0);
            
            // Calificación final del grupo
            $calificacionFinalGrupo = $estadoGrupo['calificacion_final_grupo'];
            if ($calificacionFinalGrupo !== null) {
                if ($calificacionFinalGrupo < 4) {
                    $this->SetFillColor(255, 200, 200);
                    $this->SetTextColor(139, 0, 0);
                }
                $this->Cell(15, 6, $calificacionFinalGrupo, 1, 0, 'C', true);
            } else {
                $this->Cell(15, 6, '-', 1, 0, 'C', true);
            }
            
            // Restaurar color para estado
            if ($fill) {
                $this->SetFillColor(224, 235, 255); // Azul claro
            } else {
                $this->SetFillColor(255, 255, 255); // Blanco
            }
            $this->SetTextColor(0, 0, 0);
            
            // ✅ NUEVA LÓGICA PARA ESTADO DEL GRUPO
            $estadoTexto = '';
            
            // Verificar si hay calificación final del grupo
            if ($estadoGrupo['calificacion_final_grupo'] !== null) {
                // Si hay calificación final, determinar por la nota
                if ($estadoGrupo['calificacion_final_grupo'] >= 4) {
                    $estadoTexto = 'ACREDITADA';
                } else {
                    $estadoTexto = 'NO ACRED.';
                }
            } else {
                // Si no hay calificación final, buscar el último estado en los períodos del grupo
                $periodosRevision = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo']; // De más reciente a más antiguo
                $ultimoEstadoGrupo = '';
                
                foreach ($periodosRevision as $periodo) {
                    $estadosPeriodo = [];
                    foreach ($item['materias'] as $matGrupo) {
                        if (!empty($matGrupo[$periodo])) {
                            $estadosPeriodo[] = $matGrupo[$periodo];
                        }
                    }
                    
                    if (!empty($estadosPeriodo)) {
                        // Determinar estado consolidado del período
                        if (count($estadosPeriodo) === count($item['materias'])) {
                            // Todas tienen estado en este período
                            if (count(array_filter($estadosPeriodo, function($e) { return $e === 'AA'; })) === count($estadosPeriodo)) {
                                $ultimoEstadoGrupo = 'AA';
                            } elseif (in_array('CSA', $estadosPeriodo)) {
                                $ultimoEstadoGrupo = 'CSA'; // Si alguna tiene CSA, el grupo es CSA
                            } elseif (in_array('CCA', $estadosPeriodo)) {
                                $ultimoEstadoGrupo = 'CCA'; // Si alguna tiene CCA (y ninguna CSA), el grupo es CCA
                            } else {
                                $ultimoEstadoGrupo = 'AA'; // Si todas son AA
                            }
                            break; // Ya encontramos el último período con datos
                        }
                    }
                }
                
                $estadoTexto = !empty($ultimoEstadoGrupo) ? $ultimoEstadoGrupo : '';
            }
            
            $estadoTexto = substr($estadoTexto, 0, 12); // Limitar longitud para la celda
            $this->Cell(20, 6, $estadoTexto, 1, 0, 'C', true);
            
            // Observaciones del grupo
            $observaciones = '';
            $saberes = array_filter(array_map(function($m) { return $m['saberes_cierre']; }, $item['materias']));
            if (!empty($saberes)) {
                $observaciones .= '' . count($saberes) . ' materia(s)';
            } else {
                $observaciones .= '';
            }
            
            $this->Cell(85, 6, $observaciones, 1, 0, 'L', true);
            
        } else {
            // Es una materia individual
            $materia = $item['materias'][0];
            
            // ✅ CORREGIDO: Obtener nombre correcto de la materia individual
            $nombreMateria = '';
            if (!empty($materia['nombre_mostrar'])) {
                $nombreMateria = normalizarNombreMateria($materia['nombre_mostrar']);
            } elseif (!empty($materia['materia_nombre'])) {
                $nombreMateria = normalizarNombreMateria($materia['materia_nombre']);
            } else {
                $nombreMateria = 'Materia sin nombre';
            }
            
            if (strlen($nombreMateria) > 35) {
                $nombreMateria = substr($nombreMateria, 0, 32) . '...';
            }
            $this->Cell(85, 6, $nombreMateria, 1, 0, 'L', true);
            
            // ✅ CORREGIDO: Obtener año correcto de la materia individual
            $anioMostrar = '?';
            if (!empty($materia['curso_anio'])) {
                $anioMostrar = $materia['curso_anio'];
            } elseif (!empty($materia['curso_anio_pendiente'])) {
                $anioMostrar = $materia['curso_anio_pendiente'];
            }
            $this->Cell(10, 6, $anioMostrar, 1, 0, 'C', true);
            
            // Períodos de intensificación
            $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
            foreach ($periodos as $periodo) {
                $valor = $materia[$periodo] ?? '';
                
                // Aplicar color según el valor
                if ($valor === 'AA') {
                    $this->SetFillColor(220, 255, 220); // Verde claro
                    $this->SetTextColor(0, 100, 0); // Verde oscuro
                } elseif ($valor === 'CCA') {
                    $this->SetFillColor(255, 255, 220); // Amarillo claro
                    $this->SetTextColor(150, 150, 0); // Amarillo oscuro
                } elseif ($valor === 'CSA') {
                    $this->SetFillColor(255, 220, 220); // Rojo claro
                    $this->SetTextColor(150, 0, 0); // Rojo oscuro
                } else {
                    // Sin valor, usar color de fila normal
                    if ($fill) {
                        $this->SetFillColor(224, 235, 255); // Azul claro
                    } else {
                        $this->SetFillColor(255, 255, 255); // Blanco
                    }
                    $this->SetTextColor(0, 0, 0);
                }
                
                $this->Cell(10, 6, !empty($valor) ? $valor : '-', 1, 0, 'C', true);
            }
            
            // Restaurar color de fila para calificación final
            if ($fill) {
                $this->SetFillColor(224, 235, 255); // Azul claro
            } else {
                $this->SetFillColor(255, 255, 255); // Blanco
            }
            $this->SetTextColor(0, 0, 0);
            
            // Calificación Final
            $calificacionFinal = $materia['calificacion_final'];
            if (!empty($calificacionFinal)) {
                if (is_numeric($calificacionFinal) && floatval($calificacionFinal) < 4) {
                    $this->SetFillColor(255, 200, 200);
                    $this->SetTextColor(139, 0, 0);
                }
            }
            $this->Cell(15, 6, !empty($calificacionFinal) ? $calificacionFinal : '-', 1, 0, 'C', true);
            
            // Restaurar color para estado
            if ($fill) {
                $this->SetFillColor(224, 235, 255); // Azul claro
            } else {
                $this->SetFillColor(255, 255, 255); // Blanco
            }
            $this->SetTextColor(0, 0, 0);
            
            // ✅ NUEVA LÓGICA PARA ESTADO DE MATERIA INDIVIDUAL
            $estado = '';
            
            // Buscar el último estado registrado en los períodos (de más reciente a más antiguo)
            $ultimoEstadoPeriodo = '';
            $periodosRevision = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo']; // De más reciente a más antiguo
            foreach ($periodosRevision as $periodo) {
                if (!empty($materia[$periodo])) {
                    $ultimoEstadoPeriodo = $materia[$periodo];
                    break; // Tomar el último (más reciente) período con datos
                }
            }
            
            // Determinar el estado final
            if (!empty($calificacionFinal)) {
                // Si hay calificación final, determinar por la nota
                if (is_numeric($calificacionFinal) && floatval($calificacionFinal) >= 4) {
                    $estado = 'ACREDITADA';
                } else {
                    $estado = 'NO ACRED.';
                }
            } else {
                // Si no hay calificación final, usar el último estado de período registrado
                if (!empty($ultimoEstadoPeriodo)) {
                    $estado = $ultimoEstadoPeriodo; // AA, CCA, CSA, etc.
                } else {
                    $estado = ''; // No hay datos
                }
            }
            
            $this->Cell(20, 6, $estado, 1, 0, 'C', true);
            
            // Observaciones (saberes pendientes al cierre)
            $observaciones = '';
            if (!empty($materia['saberes_cierre'])) {
                $observaciones = $materia['saberes_cierre'];
            } elseif (!empty($materia['saberes_iniciales'])) {
                $observaciones = 'Saberes: ' . $materia['saberes_iniciales'];
            }
            
            if (strlen($observaciones) > 65) {
                $observaciones = substr($observaciones, 0, 62) . '...';
            }
            
            $this->Cell(85, 6, !empty($observaciones) ? $observaciones : '', 1, 0, 'L', true);
        }
        
        $this->Ln();
        $fill = !$fill;
    }
    
    // Leyenda actualizada
    $this->Ln(2);
    $this->SetFont('Arial', '', 7);
    $this->SetTextColor(100, 100, 100);
    $this->Cell(0, 4, 'Códigos: AA=Aprobó y Acreditó, CCA=Continúa Con Avances, CSA=Continúa Sin Avances', 0, 1, 'L');
    $this->SetTextColor(0, 0, 0); // Restaurar color negro
}
    
    // FUNCIÓN ACTUALIZADA: Tabla de valoraciones bimestrales combinadas con soporte para grupos
    function TablaValoracionesBimestralesCombinadas($valoracionesCombinadas, $bimestre) {
        $bimestreTexto = ($bimestre == 1) ? '1er' : '3er';
        
        // Título
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Valoraciones del ' . $bimestreTexto . ' Bimestre', 0, 1, 'C');
        $this->Ln(5);
        
        // Cabeceras
        $this->SetFillColor(54, 162, 235);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 9);
        
        $this->Cell(70, 8, 'MATERIAS', 1, 0, 'C', true);
        $this->Cell(30, 8, 'VALORACIÓN', 1, 0, 'C', true);
        $this->Cell(30, 8, 'DESEMPEÑO', 1, 0, 'C', true);
        $this->Cell(120, 8, 'OBSERVACIONES', 1, 0, 'C', true);
        $this->Ln();
        
        // Restaurar colores
        $this->SetFillColor(240, 248, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 8);
        
        // Datos
        $contador = 0;
        foreach($valoracionesCombinadas as $item) {
            
            if ($item['es_grupo']) {
                // Es un grupo
                $val = $item['valoracion_consolidada'];
                if (!empty($val['valoracion_bimestral']) && trim($val['valoracion_bimestral']) !== '') {
                    $contador++;
                    
                    // Determinar color según valoración
                    if ($val['valoracion_bimestral'] === 'TEA') {
                        $this->SetFillColor(220, 255, 220); // Verde claro
                    } elseif ($val['valoracion_bimestral'] === 'TEP') {
                        $this->SetFillColor(255, 255, 220); // Amarillo claro
                    } elseif ($val['valoracion_bimestral'] === 'TED') {
                        $this->SetFillColor(255, 220, 220); // Rojo claro
                    } else {
                        $this->SetFillColor(240, 248, 255); // Azul claro
                    }
                    
                    $nombreItem = normalizarNombreMateria($item['nombre']);
                    $this->Cell(70, 6, $nombreItem, 1, 0, 'L', true);
                    $this->Cell(30, 6, $val['valoracion_bimestral'], 1, 0, 'C', true);
                    $this->Cell(30, 6, !empty($val['desempeno_bimestral']) ? $val['desempeno_bimestral'] : '-', 1, 0, 'C', true);
                    
                    // Observaciones (truncar si es muy largo)
                    $observaciones = !empty($val['observaciones_bimestrales']) ? $val['observaciones_bimestrales'] : '-';
                    if (strlen($observaciones) > 120) {
                        $observaciones = substr($observaciones, 0, 117) . '...';
                    }
                    $this->Cell(120, 6, $observaciones, 1, 0, 'L', true);
                    $this->Ln();
                }
            } else {
                // Es una materia individual O materia que pertenece a un grupo
                if (!empty($item['valoracion_bimestral']) && trim($item['valoracion_bimestral']) !== '') {
                    $contador++;
                    
                    // Determinar color según valoración
                    if ($item['valoracion_bimestral'] === 'TEA') {
                        $this->SetFillColor(220, 255, 220); // Verde claro
                    } elseif ($item['valoracion_bimestral'] === 'TEP') {
                        $this->SetFillColor(255, 255, 220); // Amarillo claro
                    } elseif ($item['valoracion_bimestral'] === 'TED') {
                        $this->SetFillColor(255, 220, 220); // Rojo claro
                    } else {
                        $this->SetFillColor(240, 248, 255); // Azul claro
                    }
                    
                    // NUEVA LÓGICA: Usar el nombre que corresponda (grupo o materia)
                    // Si $item['nombre'] contiene el nombre del grupo, se mostrará el grupo
                    $nombreItem = normalizarNombreMateria($item['nombre']); // YA CONTIENE EL NOMBRE DEL GRUPO SI CORRESPONDE
                    
                    $this->Cell(70, 6, $nombreItem, 1, 0, 'L', true);
                    $this->Cell(30, 6, $item['valoracion_bimestral'], 1, 0, 'C', true);
                    $this->Cell(30, 6, !empty($item['desempeno_bimestral']) ? $item['desempeno_bimestral'] : '-', 1, 0, 'C', true);
                    
                    // Observaciones (truncar si es muy largo)
                    $observaciones = !empty($item['observaciones_bimestrales']) ? $item['observaciones_bimestrales'] : '-';
                    if (strlen($observaciones) > 120) {
                        $observaciones = substr($observaciones, 0, 117) . '...';
                    }
                    $this->Cell(120, 6, $observaciones, 1, 0, 'L', true);
                    $this->Ln();
                }
            }
        }
        
        if ($contador === 0) {
            $this->SetFillColor(255, 255, 255);
            $this->Cell(250, 6, 'No hay valoraciones registradas para este bimestre', 1, 0, 'C', true);
            $this->Ln();
        }
    }
    
    // FUNCIÓN ACTUALIZADA: Tabla de asistencias con diseño horizontal
    function TablaAsistenciasConFirmas($asistenciasPorCuatrimestre) {
    // Guardar posición Y inicial
    $inicioY = $this->GetY();
    
    // PARTE 1: TABLA DE ASISTENCIAS (lado izquierdo)
    // Título
    $this->SetFont('Arial', 'B', 10);
    
    // Cabeceras - aplicando nueva estética
    $this->SetFillColor(73, 173, 245);
    $this->SetTextColor(255);
    $this->SetDrawColor(128, 128, 128);
    $this->SetLineWidth(0.3);
    $this->SetFont('Arial', 'B', 7);
    
    // Primera fila de cabeceras - REDUCIR ANCHO TOTAL
    $this->Cell(25, 7, '', 1, 0, 'C', true); // Celda vacía esquina superior izquierda
    $this->Cell(30, 7, '1° CUATRIM.', 1, 0, 'C', true); // Reducido de 35 a 30
    $this->Cell(30, 7, '2° CUATRIM.', 1, 0, 'C', true); // Reducido de 35 a 30
    $this->Cell(25, 7, 'TOTAL', 1, 0, 'C', true);
    // Total ancho tabla: 110mm (antes era 120mm)
    $this->Ln();
    
    // Preparar datos
    $datos1c = isset($asistenciasPorCuatrimestre[1]) ? $asistenciasPorCuatrimestre[1] : [
        'total_dias' => 0, 'ausentes' => 0, 'medias_faltas' => 0, 'cuartos_faltas' => 0, 'justificadas' => 0
    ];
    $datos2c = isset($asistenciasPorCuatrimestre[2]) ? $asistenciasPorCuatrimestre[2] : [
        'total_dias' => 0, 'ausentes' => 0, 'medias_faltas' => 0, 'cuartos_faltas' => 0, 'justificadas' => 0
    ];
    
    // Calcular totales
    $totalDias = ($datos1c['total_dias'] ?? 0) + ($datos2c['total_dias'] ?? 0);
    $totalAusentes = ($datos1c['ausentes'] ?? 0) + ($datos2c['ausentes'] ?? 0);
    $totalMediasFaltas = ($datos1c['medias_faltas'] ?? 0) + ($datos2c['medias_faltas'] ?? 0);
    $totalCuartosFaltas = ($datos1c['cuartos_faltas'] ?? 0) + ($datos2c['cuartos_faltas'] ?? 0);
    $totalJustificadas = ($datos1c['justificadas'] ?? 0) + ($datos2c['justificadas'] ?? 0);
    $totalInasistencias = $totalAusentes + ($totalMediasFaltas * 0.5) + ($totalCuartosFaltas * 0.25);
    
    // FILA: INASISTENCIAS
    // Etiqueta con color de cabecera
    $this->SetFillColor(73, 173, 245);
    $this->SetTextColor(255);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell(25, 6, 'INASISTENCIAS', 1, 0, 'L', true);
    
    // Datos con fondo claro
    $this->SetFillColor(240, 248, 255);
    $this->SetTextColor(0);
    $this->SetFont('Arial', '', 7);
    
    // Calcular inasistencias 1° cuatrimestre
    $ausentes1 = $datos1c['ausentes'] ?? 0;
    $mediasFaltas1 = ($datos1c['medias_faltas'] ?? 0) * 0.5;
    $cuartosFaltas1 = ($datos1c['cuartos_faltas'] ?? 0) * 0.25;
    $inasistencias1c = $ausentes1 + $mediasFaltas1 + $cuartosFaltas1;
    
    // Calcular inasistencias 2° cuatrimestre  
    $ausentes2 = $datos2c['ausentes'] ?? 0;
    $mediasFaltas2 = ($datos2c['medias_faltas'] ?? 0) * 0.5;
    $cuartosFaltas2 = ($datos2c['cuartos_faltas'] ?? 0) * 0.25;
    $inasistencias2c = $ausentes2 + $mediasFaltas2 + $cuartosFaltas2;
    
    $this->Cell(30, 6, $inasistencias1c, 1, 0, 'C', true);
    $this->Cell(30, 6, $inasistencias2c, 1, 0, 'C', true);
    
    // Total con énfasis
    $this->SetFillColor(220, 235, 255);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell(25, 6, $totalInasistencias, 1, 0, 'C', true);
    
    // PARTE 2: FIRMAS (lado derecho) - SIN HACER Ln()
    // Calcular posición X para las firmas (después de la tabla + margen)
    $margenEntreTablaYFirmas = 15; // 15mm de separación
    $xFirmas = 25 + 30 + 30 + 25 + $margenEntreTablaYFirmas; // = 125mm
    
    // Guardar posición Y actual (nivel de la fila de inasistencias)
    $yFilaInasistencias = $this->GetY();
    
    // Posicionar para las firmas
    $this->SetXY($xFirmas, $yFilaInasistencias - 5); // Subir un poco para alinear mejor
    
    // Ajustar posición para las líneas de firma
    $this->SetX($xFirmas);
    $this->Ln(2); // Espacio antes de las líneas
    
    // Ancho disponible para firmas (desde xFirmas hasta el final de la página)
    $anchoDisponibleFirmas = $this->GetPageWidth() - $xFirmas - 10; // 10mm margen derecho
    $anchoFirmaPorColumna = $anchoDisponibleFirmas / 3; // Dividir en 3 columnas
    
    // Líneas para firmas
    $this->SetX($xFirmas);
    $this->SetFont('Arial', '', 8);
    $this->Cell($anchoFirmaPorColumna, 3, '____________________', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 3, '____________________', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 3, '____________________', 0, 1, 'C');
    
    $this->Ln(2); // Pequeño espacio entre línea y texto
    
    // Información detallada de la directora y otros
    $this->SetX($xFirmas);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell($anchoFirmaPorColumna, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
    $this->SetFont('Arial', '', 8);
    $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Estudiante', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Responsable', 0, 1, 'C');
    
    // Segunda línea de información de la directora
    $this->SetX($xFirmas);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell($anchoFirmaPorColumna, 4, 'DIRECTORA', 0, 0, 'C');
    $this->SetFont('Arial', '', 7);
    $this->Cell($anchoFirmaPorColumna, 4, '', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
    
    // Volver a posición después de tabla de asistencias para continuar el flujo normal
    $this->SetY($yFilaInasistencias + 6); // +6 por la altura de la fila
    $this->Ln(2);
}
    
    function TablaAsistenciasBimestreConFirmas($asistenciasBimestre, $bimestre) {
    if (!$asistenciasBimestre) {
        // Si no hay datos de asistencia, solo mostrar firmas
        $this->FirmasUnicamenteBimestre();
        return;
    }
    
    $bimestreTexto = ($bimestre == 1) ? '1er' : '3er';
    $cuatrimestreCorrespondiente = ($bimestre == 1) ? 1 : 2;
    
    // Guardar posición Y inicial
    $inicioY = $this->GetY();
    
    // PARTE 1: TABLA DE ASISTENCIAS (lado izquierdo) - MÁS COMPACTA
    // Título
    $this->SetFont('Arial', 'B', 9);
    $this->Cell(0, 8, 'Asistencia - ' . $cuatrimestreCorrespondiente . '° Cuatrimestre (' . $bimestreTexto . ' Bimestre)', 0, 1, 'L');
    
    // Cabeceras - REDUCIR ANCHOS
    $this->SetFillColor(73, 173, 245);
    $this->SetTextColor(255);
    $this->SetDrawColor(128, 128, 128);
    $this->SetLineWidth(0.3);
    $this->SetFont('Arial', 'B', 7);
    
    $this->Cell(35, 7, 'Período', 1, 0, 'C', true);          // Reducido
    $this->Cell(20, 7, 'Días Reg.', 1, 0, 'C', true);       // Reducido
    $this->Cell(20, 7, 'Ausentes', 1, 0, 'C', true);        // Reducido
    $this->Cell(20, 7, 'M. Faltas', 1, 0, 'C', true);       // Reducido
    $this->Cell(20, 7, 'Justif.', 1, 0, 'C', true);         // Reducido
    $this->Cell(20, 7, 'Total F.', 1, 0, 'C', true);        // Reducido
    $this->Cell(20, 7, '% Asist.', 1, 0, 'C', true);        // Reducido
    // Total ancho tabla: 155mm (más compacta)
    $this->Ln();
    
    // Datos
    $this->SetFillColor(240, 248, 255);
    $this->SetTextColor(0);
    $this->SetFont('Arial', '', 7);
    
    $totalDias = isset($asistenciasBimestre['total_dias']) ? $asistenciasBimestre['total_dias'] : 0;
    $ausentes = isset($asistenciasBimestre['ausentes']) ? $asistenciasBimestre['ausentes'] : 0;
    $mediasFaltas = isset($asistenciasBimestre['medias_faltas']) ? $asistenciasBimestre['medias_faltas'] : 0;
    $cuartosFaltas = isset($asistenciasBimestre['cuartos_faltas']) ? $asistenciasBimestre['cuartos_faltas'] : 0;
    $justificadas = isset($asistenciasBimestre['justificadas']) ? $asistenciasBimestre['justificadas'] : 0;
    
    $totalFaltas = $ausentes + ($mediasFaltas * 0.5) + ($cuartosFaltas * 0.25);
    $porcentajeAsistencia = $totalDias > 0 ? round((($totalDias - $totalFaltas) / $totalDias) * 100, 1) : 0;
    
    // Guardar Y de la fila de datos
    $yFilaDatos = $this->GetY();
    
    $this->Cell(35, 7, $cuatrimestreCorrespondiente . '° Cuatrimestre', 1, 0, 'L', true);
    $this->Cell(20, 7, $totalDias, 1, 0, 'C', true);
    $this->Cell(20, 7, $ausentes, 1, 0, 'C', true);
    $this->Cell(20, 7, $mediasFaltas, 1, 0, 'C', true);
    $this->Cell(20, 7, $cuartosFaltas, 1, 0, 'C', true);
    $this->Cell(20, 7, $totalFaltas, 1, 0, 'C', true);
    $this->Cell(20, 7, $porcentajeAsistencia . '%', 1, 0, 'C', true);
    
    // PARTE 2: FIRMAS (lado derecho)
    $margenEntreTablaYFirmas = 10;
    $xFirmas = 155 + $margenEntreTablaYFirmas; // Después de la tabla
    
    // Posicionar para las firmas (a la altura del título de asistencia)
    $this->SetXY($xFirmas, $inicioY);
    
    // FIRMAS EN EL ESPACIO DERECHO
    $this->SetFont('Arial', 'B', 9);
    $this->SetTextColor(0);
    $this->Cell(0, 5, 'FIRMAS', 0, 1, 'L');
    
    // Ajustar posición para las líneas de firma
    $this->SetX($xFirmas);
    $this->Ln(5);
    
    // Ancho disponible para firmas
    $anchoDisponibleFirmas = $this->GetPageWidth() - $xFirmas - 10;
    $anchoFirmaPorColumna = $anchoDisponibleFirmas / 3;
    
    // Líneas para firmas
    $this->SetX($xFirmas);
    $this->SetFont('Arial', '', 8);
    $this->Cell($anchoFirmaPorColumna, 6, '________________', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 6, '________________', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 6, '________________', 0, 1, 'C');
    
    $this->Ln(2);
    
    // Información de firmas
    $this->SetX($xFirmas);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell($anchoFirmaPorColumna, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
    $this->SetFont('Arial', '', 8);
    $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Estudiante', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Responsable', 0, 1, 'C');
    
    $this->SetX($xFirmas);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell($anchoFirmaPorColumna, 4, 'DIRECTORA', 0, 0, 'C');
    $this->SetFont('Arial', '', 7);
    $this->Cell($anchoFirmaPorColumna, 4, '', 0, 0, 'C');
    $this->Cell($anchoFirmaPorColumna, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
    
    // Volver a posición después de tabla para observación
    $this->SetY($yFilaDatos + 7);
    
    // Observación sobre asistencia (solo en el lado izquierdo)
    $this->Ln(3);
    $this->SetFont('Arial', '', 8);
    if ($porcentajeAsistencia < 75) {
        $this->SetTextColor(220, 53, 69);
        $this->Cell(155, 5, 'ATENCIÓN: El porcentaje de asistencia está por debajo del mínimo requerido (75%)', 0, 1, 'L');
    } elseif ($porcentajeAsistencia < 85) {
        $this->SetTextColor(255, 193, 7);
        $this->Cell(155, 5, 'ADVERTENCIA: El porcentaje de asistencia está cerca del límite mínimo', 0, 1, 'L');
    } else {
        $this->SetTextColor(40, 167, 69);
        $this->Cell(155, 5, 'El porcentaje de asistencia es satisfactorio', 0, 1, 'L');
    }
    $this->SetTextColor(0);
}
    
   // FUNCIÓN AUXILIAR: Para casos donde solo se necesitan las firmas
function FirmasUnicamenteBimestre() {
    $this->Ln(10);
    
    // Centrar las firmas en la página
    $this->SetFont('Arial', 'B', 10);
    $this->Cell(0, 8, 'FIRMAS', 0, 1, 'C');
    $this->Ln(5);
    
    // Líneas para firmas centradas
    $this->SetFont('Arial', '', 10);
    $anchoTotal = 90 * 3; // 3 columnas de 90mm cada una
    $inicioX = ($this->GetPageWidth() - $anchoTotal) / 2;
    
    $this->SetX($inicioX);
    $this->Cell(90, 6, '________________________', 0, 0, 'C');
    $this->Cell(90, 6, '________________________', 0, 0, 'C');
    $this->Cell(90, 6, '________________________', 0, 1, 'C');
    
    $this->Ln(2);
    
    // Información de firmas
    $this->SetX($inicioX);
    $this->SetFont('Arial', 'B', 8);
    $this->Cell(90, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
    $this->SetFont('Arial', '', 10);
    $this->Cell(90, 4, 'Firma del Estudiante', 0, 0, 'C');
    $this->Cell(90, 4, 'Firma del Responsable', 0, 1, 'C');
    
    $this->SetX($inicioX);
    $this->SetFont('Arial', 'B', 8);
    $this->Cell(90, 4, 'DIRECTORA', 0, 0, 'C');
    $this->SetFont('Arial', '', 8);
    $this->Cell(90, 4, '', 0, 0, 'C');
    $this->Cell(90, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
}
    
}

if (!function_exists('limpiarNombreArchivo')) {
    function limpiarNombreArchivo($texto) {
        // Si existe la función de transliteración, úsala
        if (function_exists('transliterator_transliterate')) {
            // Convertir caracteres acentuados a ASCII
            $textoSinAcentos = transliterator_transliterate('Any-Latin; Latin-ASCII', $texto);
            return $textoSinAcentos;
        } else {
            // Si no existe la función, reemplazar manualmente caracteres comunes
            $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ');
            $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N');
            return str_replace($buscar, $reemplazar, $texto);
        }
    }
}

// Crear instancia de PDF según el tipo CON MATERIAS LIBERADAS
if ($tipoBoletinSeleccionado === 'cuatrimestre') {
    $pdf = new BoletinPDF('cuatrimestre', 1, $cuatrimestreSeleccionado, $materiasLiberadasIds);
    $pdf->SetTitle('RITE - ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']);
} else {
    $pdf = new BoletinPDF('bimestre', $bimestreSeleccionado, 1, $materiasLiberadasIds);
    $bimestreTexto = ($bimestreSeleccionado == 1) ? '1er' : '3er';
    $pdf->SetTitle('Boletin Bimestral (' . $bimestreTexto . ') - ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']);
}

$pdf->SetAuthor('Escuela Técnica Henry Ford');
$pdf->AliasNbPages();
$pdf->AddPage();

// Información del estudiante
$pdf->SetFont('Arial', 'B', 10);

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(85, 6, 'Estudiante: ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre'], 0, 0, 'L');
$pdf->Cell(30, 6, 'Curso: ' . $datosEstudiante['curso_nombre'], 0, 0, 'L');
$pdf->Cell(40, 6, 'Ciclo Lectivo: ' . $anioActivo, 0, 1, 'L');

$pdf->Ln(1);

if ($tipoBoletinSeleccionado === 'cuatrimestre') {
    // Generar boletín cuatrimestral con datos combinados
    
    // Tabla de calificaciones combinadas (grupos + materias individuales + materias liberadas)
    $pdf->TablaCalificacionesCombinadas($calificacionesCombinadas, $datosEstudiante);

    $pdf->Ln(2);

    // Tabla de materias pendientes con soporte para grupos
    $pdf->TablaMateriasesPendientes($materiasAgrupadasPendientes);

    $pdf->Ln(2);

    // Tabla de asistencias
    $pdf->TablaAsistenciasConFirmas($asistenciasPorCuatrimestre);

    $pdf->Ln(1);

    
} else {
    // Generar boletín bimestral con datos combinados
    
    // Tabla de valoraciones bimestrales combinadas
    $pdf->TablaValoracionesBimestralesCombinadas($valoracionesCombinadas, $bimestreSeleccionado);
    
    $pdf->Ln(5);
    
    // Tabla de materias pendientes en boletín bimestral también
    $pdf->TablaMateriasesPendientes($materiasAgrupadasPendientes);
    
    $pdf->Ln(1);
    
    // Asistencias del bimestre
    if (isset($asistenciasBimestre) && $asistenciasBimestre) {
        $pdf->TablaAsistenciasBimestreConFirmas($asistenciasBimestre, $bimestreSeleccionado);
    }
    
    $pdf->Ln(1);
}

// Limpiar el buffer de salida para asegurarnos de que no haya nada antes del PDF
ob_clean();

// Salida del PDF
if ($tipoBoletinSeleccionado === 'cuatrimestre') {
    $nombreArchivo = 'RITE_' . limpiarNombreArchivo($datosEstudiante['apellido']) . '_' . 
                     limpiarNombreArchivo($datosEstudiante['nombre']) . '_' . 
                     $cuatrimestreSeleccionado . 'C_' . $anioActivo . '.pdf';
} else {
    $bimestreTexto = ($bimestreSeleccionado == 1) ? '1er' : '3er';
    $nombreArchivo = 'Boletin_Bimestral_' . $bimestreTexto . '_' . 
                     limpiarNombreArchivo($datosEstudiante['apellido']) . '_' . 
                     limpiarNombreArchivo($datosEstudiante['nombre']) . '_' . $anioActivo . '.pdf';
}

// Si el directorio de PDFs no existe, crearlo
$dirPDF = 'pdfs';
if (!file_exists($dirPDF)) {
    mkdir($dirPDF, 0755, true);
}

// Guardar el PDF en el servidor
$rutaArchivo = $dirPDF . '/' . $nombreArchivo;
$pdf->Output('F', $rutaArchivo);

// Modo archivo para generación masiva
if (isset($GLOBALS['generar_archivo_modo']) && $GLOBALS['generar_archivo_modo']) {
    $rutaArchivo = $GLOBALS['directorio_salida'] . '/' . $nombreArchivo;
    $pdf->Output('F', $rutaArchivo);
    return;
}

// Enviar el PDF al navegador
$pdf->Output('D', $nombreArchivo);

exit;
?>
