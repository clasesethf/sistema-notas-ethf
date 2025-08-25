<?php
// Iniciar buffer de salida al principio del archivo
ob_start();

/**
 * generar_valoraciones_pdf.php - Generación de boletines de valoraciones bimestrales en PDF
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * CARACTERÍSTICAS ESPECÍFICAS PARA BOLETINES BIMESTRALES:
 * - Respeta el orden personalizado igual que el cuatrimestral
 * - Muestra cada materia individual de los grupos con formato: "GRUPO (MATERIA)"
 * - Usa la misma estética y funcionalidad que el cuatrimestral
 * - Soporte para materias liberadas
 * - Versión simplificada sin debug ni materias pendientes
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar parámetros requeridos
if (!isset($_GET['estudiante']) || !isset($_GET['curso']) || !isset($_GET['bimestre'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el boletín';
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Obtener parámetros
$estudianteId = intval($_GET['estudiante']);
$cursoId = intval($_GET['curso']);
$bimestreSeleccionado = intval($_GET['bimestre']);

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

// Obtener materias liberadas
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

$materiasLiberadas = obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId);
$materiasLiberadasIds = array_column($materiasLiberadas, 'materia_liberada_id');

/**
 * FUNCIÓN: Obtener valoraciones bimestrales expandidas (cada materia del grupo por separado)
 */
function obtenerValoracionesBimestralesExpandidas($db, $estudianteId, $cicloLectivoId, $cursoAnio, $bimestre) {
    try {
        $campo_valoracion = 'valoracion_' . $bimestre . 'bim';
        $campo_desempeno = 'desempeno_' . $bimestre . 'bim';
        $campo_observaciones = 'observaciones_' . $bimestre . 'bim';
        
        $resultado = [];
        $materiasEnGrupos = [];
        
        // 1. OBTENER GRUPOS DE MATERIAS Y EXPANDIR CADA MATERIA
        $grupos = $db->fetchAll(
            "SELECT gm.*
             FROM grupos_materias gm
             WHERE gm.curso_anio = ? AND gm.ciclo_lectivo_id = ? AND gm.activo = 1
             ORDER BY gm.orden_visualizacion",
            [$cursoAnio, $cicloLectivoId]
        );
        
        foreach ($grupos as $grupo) {
            // Obtener todas las materias del grupo CON sus valoraciones
            $materiasDelGrupo = $db->fetchAll(
                "SELECT c.$campo_valoracion as valoracion_bimestral,
                        c.$campo_desempeno as desempeno_bimestral, 
                        c.$campo_observaciones as observaciones_bimestrales,
                        m.nombre as materia_nombre, m.codigo as materia_codigo,
                        mp.id as materia_curso_id,
                        gm.nombre as grupo_nombre,
                        gm.codigo as grupo_codigo,
                        curso_materia.anio as materia_anio
                 FROM materias_grupo mg
                 JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
                 JOIN materias m ON mp.materia_id = m.id
                 JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
                 JOIN calificaciones c ON mp.id = c.materia_curso_id 
                                       AND c.estudiante_id = ? 
                                       AND c.ciclo_lectivo_id = ?
                 LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
                 WHERE mg.grupo_id = ? AND mg.activo = 1 
                 ORDER BY mg.trimestre_inicio, m.nombre",
                [$estudianteId, $cicloLectivoId, $grupo['id']]
            );
            
            // EXPANDIR: Agregar cada materia del grupo como item individual
            foreach ($materiasDelGrupo as $materia) {
                // Registrar que esta materia está en un grupo
                $materiasEnGrupos[] = $materia['materia_curso_id'];
                
                // Solo incluir si tiene valoración bimestral
                if (!empty(trim($materia['valoracion_bimestral']))) {
                    // CREAR NOMBRE COMBINADO: "GRUPO (MATERIA)"
                    $nombreCombinado = trim($materia['grupo_nombre']) . ' (' . trim($materia['materia_nombre']) . ')';
                    
                    $materia['nombre'] = $nombreCombinado;
                    $materia['es_grupo'] = false; // No es un grupo en sí, es una materia expandida
                    $materia['es_materia_expandida'] = true; // Indicador especial
                    $materia['nombre_original_materia'] = $materia['materia_nombre'];
                    $materia['nombre_original_grupo'] = $materia['grupo_nombre'];
                    
                    $resultado[] = $materia;
                }
            }
        }
        
        // 2. OBTENER MATERIAS INDIVIDUALES (QUE NO ESTÁN EN GRUPOS)
        $materiasIndividuales = $db->fetchAll(
            "SELECT c.$campo_valoracion as valoracion_bimestral,
                    c.$campo_desempeno as desempeno_bimestral,
                    c.$campo_observaciones as observaciones_bimestrales,
                    m.nombre as materia_nombre, m.codigo as materia_codigo,
                    mp.id as materia_curso_id,
                    curso_materia.anio as materia_anio,
                    -- Información de grupo (si existe)
                    gm.nombre as grupo_nombre,
                    gm.codigo as grupo_codigo,
                    -- Indicador si es parte de un grupo
                    CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
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
                
                // Si la materia pertenece a un grupo pero no fue procesada arriba, usar nombre del grupo
                if ($materia['es_parte_grupo'] == 1 && !empty($materia['grupo_nombre'])) {
                    // Es parte de un grupo: crear nombre combinado
                    $materia['nombre'] = trim($materia['grupo_nombre']) . ' (' . trim($materia['materia_nombre']) . ')';
                    $materia['es_materia_expandida'] = true;
                    $materia['nombre_original_materia'] = $materia['materia_nombre'];
                    $materia['nombre_original_grupo'] = $materia['grupo_nombre'];
                } else {
                    // Materia individual normal
                    $materia['nombre'] = $materia['materia_nombre'];
                    $materia['es_materia_expandida'] = false;
                }
                
                $materia['es_grupo'] = false;
                $resultado[] = $materia;
            }
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        throw new Exception("Error al obtener valoraciones bimestrales expandidas: " . $e->getMessage());
    }
}

/**
 * FUNCIÓN: Obtener orden personalizado de materias por año
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
            'CONSTRUCCIÓN DE CIUDADANÍA',
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
            'CONSTRUCCIÓN DE CIUDADANÍA',
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
            'DIBUJO TECNOLÓGICO',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
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
            'ANÁLISIS MATEMÁTICO',
            'MECÁNICA Y MECANISMOS',
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
            'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
            'ELECTROTECNIA',
            'SISTEMAS MECÁNICOS',
            'DERECHOS DEL TRABAJO',
            'LABORATORIO DE MEDICIONES ELÉCTRICAS',
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
 * FUNCIÓN: Normalización completa de nombres de materias
 */
function normalizarNombreMateria($nombreMateria) {
    // Convertir a mayúsculas para comparación
    $nombreUpper = strtoupper(trim($nombreMateria));
    
    // Reglas de normalización específicas
    $reglasNormalizacion = [
        // Construcción de Ciudadanía
        '/^CONSTR\.?\s*DE\s*CIUD\.?\s*-?\s*.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONSTRUCCION\s*DE\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONST\.?\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        
        // Materias técnicas de 4° año
        '/^DIBUJO\s*TECNOLOGICO$/i' => 'DIBUJO TECNOLÓGICO',
        '/^MAQUINAS\s*ELECTRICAS\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        '/^MAQ\.?\s*ELEC\.?\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        
        // Materias de 5° año
        '/^ANALISIS\s*MATEMATICO$/i' => 'ANÁLISIS MATEMÁTICO',
        '/^MECANICA\s*Y\s*MECANISMOS$/i' => 'MECÁNICA Y MECANISMOS',
        '/^RESISTENCIA\s*Y\s*ENSAYO\s*DE\s*MATERIALES$/i' => 'RESISTENCIA Y ENSAYO DE MATERIALES',
        '/^POLITICA\s*Y\s*CIUDADANIA$/i' => 'POLÍTICA Y CIUDADANÍA',
        
        // Materias de 6° año
        '/^TERMO\.?\s*Y\s*MAQ\.?\s*TÉRMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^TERMODINAMICA\s*Y\s*MAQUINAS\s*TERMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^SIST\.?\s*MECÁNICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^SISTEMAS\s*MECANICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^LAB\.?\s*DE\s*MED\.?\s*ELÉCTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^LABORATORIO\s*DE\s*MEDICIONES\s*ELECTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^DERECHOS\s*DEL\s*TRABAJO$/i' => 'DERECHOS DEL TRABAJO',
        
        // Materias de 7° año
        '/^PRACTICAS\s*PROFESIONALIZANTES$/i' => 'PRÁCTICAS PROFESIONALIZANTES',
        '/^EMPRENDIMIENTOS\s*PRODUCTIVOS\s*Y\s*DESARROLLO\s*LOCAL$/i' => 'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
        '/^ELECTRONICA\s*INDUSTRIAL$/i' => 'ELECTRÓNICA INDUSTRIAL',
        '/^SEGURIDAD,?\s*HIGIENE\s*Y\s*PROTECCION\s*DEL\s*MEDIO\s*AMBIENTE$/i' => 'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
        
        // Materias comunes
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
        
        // Materias técnicas generales
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
 * FUNCIÓN: Determinar si una materia está en situación de recursado
 */
function esMateriaPorRecursar($item, $anioEstudiante) {
    // Para materias expandidas o individuales
    $anioMateria = $item['materia_anio'] ?? $anioEstudiante;
    return ($anioEstudiante > $anioMateria);
}

/**
 * FUNCIÓN: Verificar si dos nombres de materias son equivalentes
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
    
    return false;
}

/**
 * FUNCIÓN: Ordenar materias según orden personalizado y separar recursadas
 */
function ordenarMateriasPersonalizadoBimestre($valoracionesCombinadas, $anioEstudiante) {
    $ordenPersonalizado = obtenerOrdenPersonalizadoMaterias($anioEstudiante);
    
    // PASO 1: Separar materias actuales del año vs materias por recursar
    $materiasActuales = [];
    $materiasRecursando = [];
    
    foreach ($valoracionesCombinadas as $item) {
        if (esMateriaPorRecursar($item, $anioEstudiante)) {
            $materiasRecursando[] = $item;
        } else {
            $materiasActuales[] = $item;
        }
    }
    
    // PASO 2: Ordenar las materias actuales según el orden personalizado
    $materiasActualesOrdenadas = ordenarGrupoMateriasBimestre($materiasActuales, $ordenPersonalizado);
    
    // PASO 3: Ordenar las materias por recursar alfabéticamente por año y luego por nombre
    usort($materiasRecursando, function($a, $b) {
        // Primero por año de la materia
        $anioA = $a['materia_anio'] ?? 0;
        $anioB = $b['materia_anio'] ?? 0;
        
        if ($anioA !== $anioB) {
            return $anioA <=> $anioB;
        }
        
        // Si son del mismo año, ordenar alfabéticamente
        $nombreA = $a['nombre'];
        $nombreB = $b['nombre'];
        return strcasecmp($nombreA, $nombreB);
    });
    
    // PASO 4: Combinar: primero materias actuales, luego materias por recursar
    return array_merge($materiasActualesOrdenadas, $materiasRecursando);
}

/**
 * FUNCIÓN: Ordenar un grupo de materias según orden personalizado
 */
function ordenarGrupoMateriasBimestre($materias, $ordenPersonalizado) {
    if (empty($ordenPersonalizado)) {
        // Si no hay orden personalizado, usar orden alfabético
        usort($materias, function($a, $b) {
            return strcasecmp($a['nombre'], $b['nombre']);
        });
        return $materias;
    }
    
    // Crear un mapa de posiciones para el orden personalizado
    $posiciones = array_flip(array_map('strtoupper', $ordenPersonalizado));
    
    // Separar materias que están en el orden personalizado de las que no
    $materiasConOrden = [];
    $materiasSinOrden = [];
    
    foreach ($materias as $item) {
        // Para materias expandidas, usamos el nombre del grupo para el ordenamiento
        if (isset($item['es_materia_expandida']) && $item['es_materia_expandida'] && isset($item['nombre_original_grupo'])) {
            $nombreParaOrdenar = strtoupper($item['nombre_original_grupo']);
        } else {
            $nombreParaOrdenar = strtoupper($item['nombre']);
        }
        
        // BÚSQUEDA MÁS FLEXIBLE: Buscar coincidencias parciales
        $encontrado = false;
        $posicionEncontrada = null;
        
        // Primero buscar coincidencia exacta
        if (isset($posiciones[$nombreParaOrdenar])) {
            $encontrado = true;
            $posicionEncontrada = $posiciones[$nombreParaOrdenar];
        } else {
            // Buscar equivalencias
            foreach ($posiciones as $nombreOrden => $posicion) {
                if (sonNombresEquivalentes($nombreParaOrdenar, $nombreOrden)) {
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
        return strcasecmp($a['nombre'], $b['nombre']);
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

// Obtener datos para el boletín bimestral
try {
    // Obtener valoraciones bimestrales expandidas
    $valoracionesExpandidas = obtenerValoracionesBimestralesExpandidas($db, $estudianteId, $cicloLectivoId, $datosEstudiante['curso_anio'], $bimestreSeleccionado);
    
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
    $_SESSION['message'] = 'Error al obtener datos bimestrales expandidos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Incluir la biblioteca FPDF
require('lib/fpdf_utf8.php');

// Función para limpiar nombres de archivo
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

// Crear una clase personalizada para el PDF del boletín de valoraciones
class ValoracionesPDF extends FPDF_UTF8 {
    private $bimestre;
    private $materiasLiberadasIds;
    
    public function __construct($bimestre = 1, $materiasLiberadasIds = []) {
        parent::__construct('L', 'mm', 'A4');
        $this->bimestre = $bimestre;
        $this->materiasLiberadasIds = $materiasLiberadasIds;
        
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
        
        // Subtítulo centrado
        $this->SetX($inicioTextoX);
        $this->SetFont('Arial', 'B', 11);
        $bimestreTexto = ($this->bimestre == 1) ? '1er' : '3er';
        $this->Cell($anchoTextoDisponible, 7, 'BOLETÍN DE VALORACIONES BIMESTRALES - ' . $bimestreTexto . ' BIMESTRE', 0, 1, 'C');
        
        // Espacio antes del contenido (reducido)
        $this->Ln(4);
    }

    // FUNCIÓN: Tabla de valoraciones bimestrales expandidas con orden personalizado
    function TablaValoracionesExpandidas($valoracionesExpandidas, $datosEstudiante) {
        // APLICAR ORDENAMIENTO PERSONALIZADO
        $valoracionesExpandidas = ordenarMateriasPersonalizadoBimestre($valoracionesExpandidas, $datosEstudiante['curso_anio']);
        
        // Cabeceras
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 8);
        
        $this->Cell(10, 8, 'TIPO', 1, 0, 'C', true);
        $this->Cell(100, 8, 'MATERIAS', 1, 0, 'C', true);
        $this->Cell(10, 8, 'AÑO', 1, 0, 'C', true);
        $this->Cell(30, 8, 'VALORACIÓN', 1, 0, 'C', true);
        $this->Cell(30, 8, 'DESEMPEÑO', 1, 0, 'C', true);
        $this->Cell(85, 8, 'OBSERVACIONES', 1, 0, 'C', true);
        $this->Ln();
        
        // Restaurar colores
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 7);
        
        // Datos
        $contador = 0;
        $fill = false;
        
        foreach($valoracionesExpandidas as $item) {
            $contador++;
            
            // Verificar si es una materia liberada
            $esLiberada = in_array($item['materia_curso_id'], $this->materiasLiberadasIds);
            
            // ESTABLECER COLOR DE FILA BASE (igual que cuatrimestral)
            if ($esLiberada) {
                $colorFilaBaseR = 255; $colorFilaBaseG = 255; $colorFilaBaseB = 150; // Amarillo para liberadas
            } elseif ($fill) {
                $colorFilaBaseR = 224; $colorFilaBaseG = 235; $colorFilaBaseB = 255; // Azul claro alternado
            } else {
                $colorFilaBaseR = 255; $colorFilaBaseG = 255; $colorFilaBaseB = 255; // Blanco
            }
            
            // Determinar tipo de cursada y año
            $anioEstudiante = $datosEstudiante['curso_anio'];
            $anioMateria = $item['materia_anio'] ?? $anioEstudiante;
            
            if ($esLiberada) {
                $tipoCursada = '';
            } else {
                $tipoCursada = ($anioEstudiante > $anioMateria) ? 'R' : 'C';
            }
            
            // Normalizar el nombre mostrado
            $nombreMostrado = normalizarNombreMateria($item['nombre']);
            
            // Render de celdas CON COLOR BASE
            $this->SetFillColor($colorFilaBaseR, $colorFilaBaseG, $colorFilaBaseB);
            $this->SetTextColor(0, 0, 0);
            
            $this->Cell(10, 6, $tipoCursada, 1, 0, 'C', true);
            $this->Cell(100, 6, $nombreMostrado, 1, 0, 'L', true);
            $this->Cell(10, 6, $anioMateria, 1, 0, 'C', true);
            
            // CELDA DE VALORACIÓN CON COLOR ESPECÍFICO
            $valoracion = $item['valoracion_bimestral'];
            if ($valoracion === 'TED' || $valoracion === 'TEP') {
                // Solo la celda de valoración en rojo si es TED o TEP
                $this->SetFillColor(255, 200, 200); // Rojo claro
                $this->SetTextColor(139, 0, 0); // Texto rojo oscuro
            } else {
                // Mantener color de fila base
                $this->SetFillColor($colorFilaBaseR, $colorFilaBaseG, $colorFilaBaseB);
                $this->SetTextColor(0, 0, 0);
            }
            $this->Cell(30, 6, $valoracion, 1, 0, 'C', true);
            
            // Restaurar color base para resto de celdas
            $this->SetFillColor($colorFilaBaseR, $colorFilaBaseG, $colorFilaBaseB);
            $this->SetTextColor(0, 0, 0);
            
            $this->Cell(30, 6, !empty($item['desempeno_bimestral']) ? $item['desempeno_bimestral'] : '-', 1, 0, 'C', true);
            
            // Observaciones (truncar si es muy largo)
            $observaciones = '';
            if ($esLiberada) {
                $observaciones = 'Se pospone la cursada de la materia';
            } else {
                $observaciones = !empty($item['observaciones_bimestrales']) ? $item['observaciones_bimestrales'] : '-';
            }
            
            if (strlen($observaciones) > 85) {
                $observaciones = substr($observaciones, 0, 82) . '...';
            }
            $this->Cell(85, 6, $observaciones, 1, 0, 'L', true);
            $this->Ln();
            
            $fill = !$fill;
        }
        
        if ($contador === 0) {
            $this->SetFillColor(255, 255, 255);
            $this->Cell(265, 6, 'No hay valoraciones registradas para este bimestre', 1, 0, 'C', true);
            $this->Ln();
        }

    }
    
    // FUNCIÓN: Tabla de asistencias con formato del boletín cuatrimestral
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
    
    // PARTE 1: TABLA DE ASISTENCIAS (lado izquierdo) - IGUAL QUE CUATRIMESTRAL
    // Cabeceras - aplicando la misma estética que el cuatrimestral
    $this->SetFillColor(73, 173, 245);
    $this->SetTextColor(255);
    $this->SetDrawColor(128, 128, 128);
    $this->SetLineWidth(0.3);
    $this->SetFont('Arial', 'B', 7);
    
    // Primera fila de cabeceras - REDUCIR ANCHO TOTAL
    $this->Cell(25, 7, '', 1, 0, 'C', true); // Celda vacía esquina superior izquierda
    $this->Cell(30, 7, $cuatrimestreCorrespondiente . '° CUATRIM.', 1, 0, 'C', true); // Reducido de 35 a 30
    $this->Cell(25, 7, 'TOTAL', 1, 0, 'C', true);
    // Total ancho tabla: 80mm (antes era 110mm)
    $this->Ln();
    
    // Preparar datos
    $totalDias = isset($asistenciasBimestre['total_dias']) ? $asistenciasBimestre['total_dias'] : 0;
    $ausentes = isset($asistenciasBimestre['ausentes']) ? $asistenciasBimestre['ausentes'] : 0;
    $mediasFaltas = isset($asistenciasBimestre['medias_faltas']) ? $asistenciasBimestre['medias_faltas'] : 0;
    $cuartosFaltas = isset($asistenciasBimestre['cuartos_faltas']) ? $asistenciasBimestre['cuartos_faltas'] : 0;
    $justificadas = isset($asistenciasBimestre['justificadas']) ? $asistenciasBimestre['justificadas'] : 0;
    
    // Calcular totales
    $totalInasistencias = $ausentes + ($mediasFaltas * 0.5) + ($cuartosFaltas * 0.25);
    
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
    
    // Calcular inasistencias del cuatrimestre correspondiente
    $inasistenciasCuatrimestre = $ausentes + ($mediasFaltas * 0.5) + ($cuartosFaltas * 0.25);
    
    $this->Cell(30, 6, $inasistenciasCuatrimestre, 1, 0, 'C', true);
    
    // Total con énfasis
    $this->SetFillColor(220, 235, 255);
    $this->SetFont('Arial', 'B', 7);
    $this->Cell(25, 6, $totalInasistencias, 1, 0, 'C', true);
    
    // PARTE 2: FIRMAS (lado derecho) - SIN HACER Ln()
    // Calcular posición X para las firmas (después de la tabla + margen)
    $margenEntreTablaYFirmas = 15; // 15mm de separación
    $xFirmas = 25 + 30 + 25 + $margenEntreTablaYFirmas; // = 95mm
    
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

// Crear instancia de PDF para valoraciones bimestrales
$pdf = new ValoracionesPDF($bimestreSeleccionado, $materiasLiberadasIds);
$bimestreTexto = ($bimestreSeleccionado == 1) ? '1er' : '3er';
$pdf->SetTitle('Boletín Valoraciones (' . $bimestreTexto . ') - ' . $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']);
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

// Tabla de valoraciones expandidas con orden personalizado
$pdf->TablaValoracionesExpandidas($valoracionesExpandidas, $datosEstudiante);

$pdf->Ln(2);

// Asistencias del bimestre
if (isset($asistenciasBimestre) && $asistenciasBimestre) {
    $pdf->TablaAsistenciasBimestreConFirmas($asistenciasBimestre, $bimestreSeleccionado);
}

$pdf->Ln(1);

// Limpiar el buffer de salida para asegurarnos de que no haya nada antes del PDF
ob_clean();

// Salida del PDF
$bimestreTexto = ($bimestreSeleccionado == 1) ? '1er' : '3er';
$nombreArchivo = 'Informe_Valoraciones_' . $bimestreTexto . '_' . 
                 limpiarNombreArchivo($datosEstudiante['apellido']) . '_' . 
                 limpiarNombreArchivo($datosEstudiante['nombre']) . '_' . $anioActivo . '.pdf';

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
