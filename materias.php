<?php
/**
 * materias.php - Gestión MEJORADA de materias del sistema CON MÚLTIPLES PROFESORES
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * NUEVAS CARACTERÍSTICAS:
 * - Soporte para hasta 3 profesores por materia
 * - Gestión avanzada de equipos docentes
 * - Vista mejorada para mostrar todos los profesores
 */

// Iniciar buffer de salida al principio
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos) ANTES de incluir header.php
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Verificar y crear columnas para múltiples profesores si no existen
try {
    $columns = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
    $hasProfesor2 = false;
    $hasProfesor3 = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'profesor_id_2') $hasProfesor2 = true;
        if ($column['name'] === 'profesor_id_3') $hasProfesor3 = true;
    }
    
    if (!$hasProfesor2) {
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id_2 INTEGER");
    }
    if (!$hasProfesor3) {
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id_3 INTEGER");
    }
} catch (Exception $e) {
    // Error silencioso si las columnas ya existen
}

// Procesar acciones ANTES de incluir header.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'crear_materia':
            $resultado = crearMateria($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'editar_materia':
            $resultado = editarMateria($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'eliminar_materia':
            $resultado = eliminarMateria($db, $_POST['materia_id']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'asignar_profesores':
            $resultado = asignarProfesores($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'remover_asignacion':
            $resultado = removerAsignacion($db, $_POST['asignacion_id']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'cambiar_estado_subgrupos':
            $resultado = cambiarEstadoSubgrupos($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'marcar_multiples_subgrupos':
            $resultado = marcarMultiplesSubgrupos($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
    }
    
    // Construir URL con TODOS los parámetros originales
    $queryParams = [];
    
    // Obtener filtros originales del POST o mantener los de GET
    $busqueda = $_POST['busqueda_original'] ?? $_GET['busqueda'] ?? '';
    $paginaActual = $_POST['pagina_original'] ?? $_GET['pagina'] ?? 1;
    $vista = $_POST['vista_original'] ?? $_GET['vista'] ?? 'tarjetas';
    $categoria = $_POST['categoria_original'] ?? $_GET['categoria'] ?? '';
    $anoFiltro = $_POST['ano_original'] ?? $_GET['ano'] ?? '';
    $estadoSubgrupos = $_POST['subgrupos_original'] ?? $_GET['subgrupos'] ?? '';
    
    // NUEVO: Capturar posición de scroll
    $scrollPosition = $_POST['scroll_position'] ?? 0;
    
    // Construir parámetros para mantener TODOS los filtros y la página
    if (!empty($busqueda)) $queryParams['busqueda'] = $busqueda;
    if ($paginaActual > 1) $queryParams['pagina'] = $paginaActual;
    if ($vista !== 'tarjetas') $queryParams['vista'] = $vista;
    if (!empty($categoria)) $queryParams['categoria'] = $categoria;
    if (!empty($anoFiltro)) $queryParams['ano'] = $anoFiltro;
    if (!empty($estadoSubgrupos)) $queryParams['subgrupos'] = $estadoSubgrupos;
    
    // NUEVO: Agregar posición de scroll
    if ($scrollPosition > 0) $queryParams['scroll'] = $scrollPosition;
    
    $redirectUrl = 'materias.php';
    if (!empty($queryParams)) {
        $redirectUrl .= '?' . http_build_query($queryParams);
    }
    
    header('Location: ' . $redirectUrl);
    exit;
}

// Variables para filtros y paginación
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$paginaActual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'tarjetas';
$categoria = isset($_GET['categoria']) ? $_GET['categoria'] : '';
$anoFiltro = isset($_GET['ano']) ? intval($_GET['ano']) : 0;
$estadoSubgrupos = isset($_GET['subgrupos']) ? $_GET['subgrupos'] : '';
$registrosPorPagina = $vista === 'tarjetas' ? 48 : 30;
$offset = ($paginaActual - 1) * $registrosPorPagina;

// AHORA incluir el encabezado después de procesar POST
require_once 'header.php';

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Función auxiliar para obtener todos los profesores de una materia
function obtenerProfesoresMateria($materia) {
    $profesoresMateria = [];
    
    // Profesor 1
    if (!empty($materia['profesor_1_id'])) {
        $nombre = '';
        if (isset($materia['profesor_1_nombre_completo'])) {
            $nombre = $materia['profesor_1_nombre_completo'];
        } elseif (isset($materia['profesor_1_apellido']) && isset($materia['profesor_1_nombre'])) {
            $nombre = trim($materia['profesor_1_apellido'] . ', ' . $materia['profesor_1_nombre']);
        } elseif (isset($materia['profesor_1_nombre'])) {
            $nombre = $materia['profesor_1_nombre'];
        } else {
            $nombre = 'Nombre no disponible';
        }
        
        $profesoresMateria[] = [
            'id' => $materia['profesor_1_id'],
            'nombre' => $nombre,
            'posicion' => 1
        ];
    }
    
    // Profesor 2
    if (!empty($materia['profesor_2_id'])) {
        $nombre = '';
        if (isset($materia['profesor_2_nombre_completo'])) {
            $nombre = $materia['profesor_2_nombre_completo'];
        } elseif (isset($materia['profesor_2_apellido']) && isset($materia['profesor_2_nombre'])) {
            $nombre = trim($materia['profesor_2_apellido'] . ', ' . $materia['profesor_2_nombre']);
        } elseif (isset($materia['profesor_2_nombre'])) {
            $nombre = $materia['profesor_2_nombre'];
        } else {
            $nombre = 'Nombre no disponible';
        }
        
        $profesoresMateria[] = [
            'id' => $materia['profesor_2_id'],
            'nombre' => $nombre,
            'posicion' => 2
        ];
    }
    
    // Profesor 3
    if (!empty($materia['profesor_3_id'])) {
        $nombre = '';
        if (isset($materia['profesor_3_nombre_completo'])) {
            $nombre = $materia['profesor_3_nombre_completo'];
        } elseif (isset($materia['profesor_3_apellido']) && isset($materia['profesor_3_nombre'])) {
            $nombre = trim($materia['profesor_3_apellido'] . ', ' . $materia['profesor_3_nombre']);
        } elseif (isset($materia['profesor_3_nombre'])) {
            $nombre = $materia['profesor_3_nombre'];
        } else {
            $nombre = 'Nombre no disponible';
        }
        
        $profesoresMateria[] = [
            'id' => $materia['profesor_3_id'],
            'nombre' => $nombre,
            'posicion' => 3
        ];
    }
    
    return $profesoresMateria;
}

// Construir consulta con filtros avanzados usando la nueva vista
$whereClause = 'WHERE c.ciclo_lectivo_id = ?';
$parametros = [$cicloLectivoId];

if (!empty($busqueda)) {
    // Corregir las referencias de columnas para que coincidan con la vista v_materias_con_profesores
    $whereClause .= ' AND (v.materia_nombre LIKE ? OR v.materia_codigo LIKE ? OR c.nombre LIKE ? OR v.profesores_nombres LIKE ?)';
    $parametros[] = "%$busqueda%";
    $parametros[] = "%$busqueda%";
    $parametros[] = "%$busqueda%";
    $parametros[] = "%$busqueda%";
}

if ($anoFiltro > 0) {
    $whereClause .= ' AND c.anio = ?';
    $parametros[] = $anoFiltro;
}

// Filtros por categoría
switch ($categoria) {
    case 'talleres':
        $whereClause .= ' AND mp.requiere_subgrupos = 1 AND NOT (LOWER(m.nombre) LIKE "%construccion%ciudadania%" OR LOWER(m.nombre) LIKE "%constr%ciud%")';
        break;
    case 'ciudadania':
        $whereClause .= ' AND (LOWER(m.nombre) LIKE "%construccion%ciudadania%" OR LOWER(m.nombre) LIKE "%constr%ciud%" OR m.codigo IN ("CCE", "CCM", "CCM1"))';
        break;
    case 'basicas':
        $whereClause .= ' AND mp.requiere_subgrupos = 0';
        break;
}

// Filtros por estado de subgrupos
switch ($estadoSubgrupos) {
    case 'con':
        $whereClause .= ' AND mp.requiere_subgrupos = 1';
        break;
    case 'sin':
        $whereClause .= ' AND mp.requiere_subgrupos = 0';
        break;
    case 'configurados':
        $whereClause .= ' AND mp.requiere_subgrupos = 1 AND cs.id IS NOT NULL';
        break;
    case 'sin_configurar':
        $whereClause .= ' AND mp.requiere_subgrupos = 1 AND cs.id IS NULL';
        break;
}

// Obtener total de registros para paginación usando vista mejorada
$totalRegistros = $db->fetchOne(
    "SELECT COUNT(DISTINCT v.materia_curso_id) as total 
     FROM v_materias_con_profesores v
     JOIN cursos c ON v.curso_id = c.id
     LEFT JOIN configuracion_subgrupos cs ON v.materia_curso_id = cs.materia_curso_id AND cs.ciclo_lectivo_id = ?
     $whereClause",
    array_merge([$cicloLectivoId], $parametros)
)['total'];

$totalPaginas = ceil($totalRegistros / $registrosPorPagina);

// Obtener materias con información completa de múltiples profesores
$materias = $db->fetchAll(
    "SELECT v.*,
            cs.tipo_division, cs.cantidad_grupos, cs.rotacion_automatica, cs.periodo_rotacion,
            COALESCE(
                (SELECT COUNT(DISTINCT ep2.estudiante_id) FROM estudiantes_por_materia ep2 
                 WHERE ep2.materia_curso_id = v.materia_curso_id AND ep2.ciclo_lectivo_id = ? AND ep2.activo = 1), 0
            ) + COALESCE(
                (SELECT COUNT(DISTINCT mr2.estudiante_id) FROM materias_recursado mr2 
                 WHERE (mr2.materia_curso_id = v.materia_curso_id OR mr2.materia_liberada_id = v.materia_curso_id) 
                 AND mr2.ciclo_lectivo_id = ? AND mr2.estado = 'activo'), 0
            ) as estudiantes_asignados
     FROM v_materias_con_profesores v
     JOIN cursos c ON v.curso_id = c.id
     LEFT JOIN configuracion_subgrupos cs ON v.materia_curso_id = cs.materia_curso_id AND cs.ciclo_lectivo_id = ?
     $whereClause
     ORDER BY c.anio, v.materia_nombre 
     LIMIT ? OFFSET ?",
    array_merge([$cicloLectivoId, $cicloLectivoId, $cicloLectivoId], $parametros, [$registrosPorPagina, $offset])
);

// Obtener estadísticas generales mejoradas
$estadisticas = $db->fetchOne(
    "SELECT 
        COUNT(DISTINCT v.materia_curso_id) as total_materias,
        COUNT(DISTINCT CASE WHEN v.requiere_subgrupos = 1 THEN v.materia_curso_id END) as con_subgrupos,
        COUNT(DISTINCT CASE WHEN v.requiere_subgrupos = 0 THEN v.materia_curso_id END) as sin_subgrupos,
        COUNT(DISTINCT CASE WHEN v.requiere_subgrupos = 1 AND cs.id IS NOT NULL THEN v.materia_curso_id END) as configurados,
        COUNT(DISTINCT CASE WHEN v.total_profesores > 0 THEN v.materia_curso_id END) as con_profesor,
        COUNT(DISTINCT CASE WHEN v.total_profesores > 1 THEN v.materia_curso_id END) as con_multiples_profesores,
        AVG(v.total_profesores) as promedio_profesores_por_materia,
        COUNT(DISTINCT CASE WHEN v.requiere_subgrupos = 1 AND (LOWER(v.materia_nombre) LIKE '%construccion%ciudadania%' OR LOWER(v.materia_nombre) LIKE '%constr%ciud%' OR v.materia_codigo IN ('CCE', 'CCM', 'CCM1')) THEN v.materia_curso_id END) as ciudadania,
        COUNT(DISTINCT CASE WHEN v.requiere_subgrupos = 1 AND NOT (LOWER(v.materia_nombre) LIKE '%construccion%ciudadania%' OR LOWER(v.materia_nombre) LIKE '%constr%ciud%' OR v.materia_codigo IN ('CCE', 'CCM', 'CCM1')) THEN v.materia_curso_id END) as talleres
     FROM v_materias_con_profesores v
     JOIN cursos c ON v.curso_id = c.id
     LEFT JOIN configuracion_subgrupos cs ON v.materia_curso_id = cs.materia_curso_id AND cs.ciclo_lectivo_id = ?
     WHERE c.ciclo_lectivo_id = ?",
    [$cicloLectivoId, $cicloLectivoId]
);

// Obtener cursos para el selector
$cursos = [];
if ($cicloLectivoId > 0) {
    $cursos = $db->fetchAll(
        "SELECT id, nombre, anio FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio, nombre",
        [$cicloLectivoId]
    );
}

// OBTENER PROFESORES PARA EL SELECTOR (VARIABLE SEPARADA PARA EVITAR CONFLICTOS)
$profesoresParaSelect = [];
try {
    // Verificar estructura de la tabla
    $columns = $db->fetchAll("PRAGMA table_info(usuarios)");
    $hasApellido = false;
    $hasNombre = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'apellido') $hasApellido = true;
        if ($column['name'] === 'nombre') $hasNombre = true;
    }
    
    if ($hasApellido && $hasNombre) {
        // La tabla tiene apellido y nombre separados
        $profesoresParaSelect = $db->fetchAll(
            "SELECT id, 
                    COALESCE(apellido, '') as apellido, 
                    COALESCE(nombre, '') as nombre,
                    COALESCE(apellido, '') || ', ' || COALESCE(nombre, '') as nombre_completo
             FROM usuarios 
             WHERE tipo = 'profesor' AND activo = 1 
             ORDER BY apellido, nombre"
        );
    } else {
        // La tabla solo tiene nombre completo
        $profesoresParaSelect = $db->fetchAll(
            "SELECT id, 
                    nombre as nombre_completo,
                    CASE 
                        WHEN INSTR(nombre, ' ') > 0 THEN SUBSTR(nombre, INSTR(nombre, ' ') + 1)
                        ELSE nombre 
                    END as apellido,
                    CASE 
                        WHEN INSTR(nombre, ' ') > 0 THEN SUBSTR(nombre, 1, INSTR(nombre, ' ') - 1)
                        ELSE nombre 
                    END as nombre
             FROM usuarios 
             WHERE tipo = 'profesor' AND activo = 1 
             ORDER BY nombre"
        );
    }
} catch (Exception $e) {
    // Fallback: usar solo el campo nombre
    $profesoresParaSelect = $db->fetchAll(
        "SELECT id, nombre as nombre_completo, nombre as apellido, '' as nombre
         FROM usuarios 
         WHERE tipo = 'profesor' AND activo = 1 
         ORDER BY nombre"
    );
}

echo "<!-- DEBUG: Profesores para select obtenidos: " . count($profesoresParaSelect) . " -->";

/**
 * Función para clasificar tipo de materia
 */
function clasificarTipoMateria($materia) {
    $tipoMateria = 'Básica';
    $colorTipo = 'secondary';
    $iconoTipo = 'book';
    
    // Verificar si es construcción de ciudadanía
    if (stripos($materia['materia_nombre'], 'construccion') !== false && stripos($materia['materia_nombre'], 'ciudadania') !== false) {
        $tipoMateria = 'Construcción Ciudadanía';
        $colorTipo = 'info';
        $iconoTipo = 'people';
    }
    // Verificar si es taller por código específico
    elseif (in_array($materia['materia_codigo'], ['CCE', 'CCM', 'CCM1'])) {
        $tipoMateria = 'Construcción Ciudadanía';
        $colorTipo = 'info';
        $iconoTipo = 'people';
    }
    // Verificar materias de taller por patrón de código
    elseif (preg_match('/^(\d+)(PT|LT|ST|MEA|DPM|IAE|DT|LME|LMCC|MME|PDE|PDIE)/', $materia['materia_codigo'], $matches)) {
        $ano = $matches[1];
        $categoria = $matches[2];
        
        switch($categoria) {
            case 'PT':
                $tipoMateria = 'Taller Producción';
                $colorTipo = 'warning';
                $iconoTipo = 'tools';
                break;
            case 'LT':
                $tipoMateria = 'Lab. Técnico';
                $colorTipo = 'primary';
                $iconoTipo = 'laptop';
                break;
            case 'ST':
                $tipoMateria = 'Sem. Técnico';
                $colorTipo = 'success';
                $iconoTipo = 'gear';
                break;
            default:
                $tipoMateria = 'Taller Especializado';
                $colorTipo = 'warning';
                $iconoTipo = 'tools';
        }
    }
    
    return [
        'tipo' => $tipoMateria,
        'color' => $colorTipo,
        'icono' => $iconoTipo
    ];
}

/**
 * Función NUEVA para asignar múltiples profesores
 */
function asignarProfesores($db, $datos) {
    try {
        $materiaId = intval($datos['materia_id']);
        $cursoId = intval($datos['curso_id']);
        $profesor1Id = !empty($datos['profesor_1_id']) ? intval($datos['profesor_1_id']) : null;
        $profesor2Id = !empty($datos['profesor_2_id']) ? intval($datos['profesor_2_id']) : null;
        $profesor3Id = !empty($datos['profesor_3_id']) ? intval($datos['profesor_3_id']) : null;
        
        if ($materiaId <= 0 || $cursoId <= 0) {
            return ['type' => 'danger', 'message' => 'Datos inválidos para la asignación'];
        }
        
        // Verificar que al menos un profesor esté asignado
        if (!$profesor1Id && !$profesor2Id && !$profesor3Id) {
            return ['type' => 'danger', 'message' => 'Debe asignar al menos un profesor'];
        }
        
        // Verificar que no se repitan profesores
        $profesoresAsignados = array_filter([$profesor1Id, $profesor2Id, $profesor3Id]);
        if (count($profesoresAsignados) !== count(array_unique($profesoresAsignados))) {
            return ['type' => 'danger', 'message' => 'No puede asignar el mismo profesor múltiples veces'];
        }
        
        $asignacionExistente = $db->fetchOne(
            "SELECT id FROM materias_por_curso WHERE materia_id = ? AND curso_id = ?",
            [$materiaId, $cursoId]
        );
        
        if ($asignacionExistente) {
            $db->query(
                "UPDATE materias_por_curso 
                 SET profesor_id = ?, profesor_id_2 = ?, profesor_id_3 = ? 
                 WHERE id = ?",
                [$profesor1Id, $profesor2Id, $profesor3Id, $asignacionExistente['id']]
            );
        } else {
            $db->query(
                "INSERT INTO materias_por_curso (materia_id, curso_id, profesor_id, profesor_id_2, profesor_id_3) 
                 VALUES (?, ?, ?, ?, ?)",
                [$materiaId, $cursoId, $profesor1Id, $profesor2Id, $profesor3Id]
            );
        }
        
        $totalProfesores = count($profesoresAsignados);
        $mensaje = $totalProfesores == 1 ? 'Profesor asignado correctamente' : "$totalProfesores profesores asignados correctamente";
        
        return ['type' => 'success', 'message' => $mensaje];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al asignar profesores: ' . $e->getMessage()];
    }
}

/**
 * Funciones auxiliares actualizadas (mantener las originales con pequeños ajustes)
 */
function crearMateria($db, $datos) {
    try {
        $nombre = trim($datos['nombre']);
        $codigo = trim($datos['codigo']);
        
        if (empty($nombre) || empty($codigo)) {
            return ['type' => 'danger', 'message' => 'El nombre y código son obligatorios'];
        }
        
        $materiaExistente = $db->fetchOne("SELECT id FROM materias WHERE nombre = ?", [$nombre]);
        if ($materiaExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe una materia con ese nombre'];
        }
        
        $codigoExistente = $db->fetchOne("SELECT id FROM materias WHERE codigo = ?", [$codigo]);
        if ($codigoExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe una materia con ese código'];
        }
        
        $db->query("INSERT INTO materias (nombre, codigo) VALUES (?, ?)", [$nombre, $codigo]);
        
        return ['type' => 'success', 'message' => 'Materia creada correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al crear materia: ' . $e->getMessage()];
    }
}

function editarMateria($db, $datos) {
    try {
        $materiaId = intval($datos['materia_id']);
        $nombre = trim($datos['nombre']);
        $codigo = trim($datos['codigo']);
        
        if (empty($nombre) || empty($codigo)) {
            return ['type' => 'danger', 'message' => 'El nombre y código son obligatorios'];
        }
        
        $materiaExistente = $db->fetchOne("SELECT id FROM materias WHERE nombre = ? AND id != ?", [$nombre, $materiaId]);
        if ($materiaExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe otra materia con ese nombre'];
        }
        
        $codigoExistente = $db->fetchOne("SELECT id FROM materias WHERE codigo = ? AND id != ?", [$codigo, $materiaId]);
        if ($codigoExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe otra materia con ese código'];
        }
        
        $db->query("UPDATE materias SET nombre = ?, codigo = ? WHERE id = ?", [$nombre, $codigo, $materiaId]);
        
        return ['type' => 'success', 'message' => 'Materia actualizada correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al actualizar materia: ' . $e->getMessage()];
    }
}

function eliminarMateria($db, $materiaId) {
    try {
        $materiaId = intval($materiaId);
        
        $materia = $db->fetchOne("SELECT * FROM materias WHERE id = ?", [$materiaId]);
        if (!$materia) {
            return ['type' => 'danger', 'message' => 'Materia no encontrada'];
        }
        
        $asignaciones = $db->fetchOne("SELECT COUNT(*) as count FROM materias_por_curso WHERE materia_id = ?", [$materiaId]);
        if ($asignaciones['count'] > 0) {
            return ['type' => 'danger', 'message' => 'No se puede eliminar la materia porque tiene asignaciones a cursos'];
        }
        
        $db->query("DELETE FROM materias WHERE id = ?", [$materiaId]);
        
        return ['type' => 'success', 'message' => 'Materia eliminada correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar materia: ' . $e->getMessage()];
    }
}

function removerAsignacion($db, $asignacionId) {
    try {
        $asignacionId = intval($asignacionId);
        
        $calificaciones = $db->fetchOne(
            "SELECT COUNT(*) as count FROM calificaciones WHERE materia_curso_id = ?",
            [$asignacionId]
        );
        
        if ($calificaciones['count'] > 0) {
            return ['type' => 'danger', 'message' => 'No se puede eliminar la asignación porque tiene calificaciones registradas'];
        }
        
        $db->query("DELETE FROM materias_por_curso WHERE id = ?", [$asignacionId]);
        
        return ['type' => 'success', 'message' => 'Asignación eliminada correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar asignación: ' . $e->getMessage()];
    }
}

function cambiarEstadoSubgrupos($db, $datos) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        $requiereSubgrupos = intval($datos['requiere_subgrupos']);
        
        $db->query(
            "UPDATE materias_por_curso SET requiere_subgrupos = ? WHERE id = ?",
            [$requiereSubgrupos, $materiaCursoId]
        );
        
        $estado = $requiereSubgrupos ? 'activados' : 'desactivados';
        return ['type' => 'success', 'message' => "Subgrupos {$estado} correctamente"];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al cambiar estado: ' . $e->getMessage()];
    }
}

function marcarMultiplesSubgrupos($db, $datos) {
    try {
        $materiasIds = $datos['materias_ids'] ?? [];
        $requiereSubgrupos = intval($datos['requiere_subgrupos']);
        
        if (empty($materiasIds)) {
            return ['type' => 'danger', 'message' => 'No se seleccionaron materias'];
        }
        
        $placeholders = implode(',', array_fill(0, count($materiasIds), '?'));
        $db->query(
            "UPDATE materias_por_curso SET requiere_subgrupos = ? WHERE id IN ($placeholders)",
            array_merge([$requiereSubgrupos], $materiasIds)
        );
        
        $estado = $requiereSubgrupos ? 'marcadas para requerir' : 'desmarcadas de requerir';
        $cantidad = count($materiasIds);
        return ['type' => 'success', 'message' => "{$cantidad} materias {$estado} subgrupos"];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error en operación masiva: ' . $e->getMessage()];
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Estadísticas mejoradas -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-graph-up"></i> Dashboard de Materias - Ciclo <?= $cicloActivo['anio'] ?? date('Y') ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-md-2">
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h3 class="text-primary"><?= $estadisticas['total_materias'] ?></h3>
                                    <p class="card-text">Total Materias</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h3 class="text-success"><?= $estadisticas['con_profesor'] ?></h3>
                                    <p class="card-text">Con Profesor(es)</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2 d-none">
                            <div class="card border-warning">
                                <div class="card-body">
                                    <h3 class="text-warning"><?= $estadisticas['con_multiples_profesores'] ?></h3>
                                    <p class="card-text">Equipos Docentes</p>
                                    <small class="text-muted">Múltiples profesores</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h3 class="text-info"><?= $estadisticas['talleres'] ?></h3>
                                    <p class="card-text">Talleres</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card border-secondary">
                                <div class="card-body">
                                    <h3 class="text-secondary"><?= $estadisticas['sin_subgrupos'] ?></h3>
                                    <p class="card-text">De Aula</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros y controles mejorados -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-funnel"></i> Filtros y Gestión
                    </h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearMateria">
                            <i class="bi bi-plus-circle"></i> Nueva Materia
                        </button>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAsignarProfesores">
                            <i class="bi bi-people-fill"></i> Asignar Profesores
                        </button>
                        <button type="button" class="btn btn-warning" onclick="mostrarGestionMasiva()">
                            <i class="bi bi-list-check"></i> Gestión Masiva
                        </button>
                        <a href="gestionar_subgrupos.php" class="btn btn-info">
                            <i class="bi bi-gear"></i> Gestionar Subgrupos
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filtros principales -->
                    <form method="GET" action="materias.php" class="mb-4" id="formFiltros">
                        <div class="row">
                            <div class="col-md-3">
                                <label for="busqueda" class="form-label">Buscar:</label>
                                <input type="text" name="busqueda" id="busqueda" class="form-control" 
                                       placeholder="Nombre, código, curso, profesor..." value="<?= htmlspecialchars($busqueda) ?>">
                            </div>
                            
                            <div class="col-md-2">
                                <label for="categoria" class="form-label">Categoría:</label>
                                <select name="categoria" id="categoria" class="form-select">
                                    <option value="">Todas</option>
                                    <option value="talleres" <?= $categoria == 'talleres' ? 'selected' : '' ?>>Talleres</option>
                                    <option value="ciudadania" <?= $categoria == 'ciudadania' ? 'selected' : '' ?>>Construcción Ciudadanía</option>
                                    <option value="basicas" <?= $categoria == 'basicas' ? 'selected' : '' ?>>Materias Básicas</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="ano" class="form-label">Año:</label>
                                <select name="ano" id="ano" class="form-select">
                                    <option value="">Todos</option>
                                    <?php for ($i = 1; $i <= 7; $i++): ?>
                                    <option value="<?= $i ?>" <?= $anoFiltro == $i ? 'selected' : '' ?>><?= $i ?>°</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="subgrupos" class="form-label">Subgrupos:</label>
                                <select name="subgrupos" id="subgrupos" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="con" <?= $estadoSubgrupos == 'con' ? 'selected' : '' ?>>Con Subgrupos</option>
                                    <option value="sin" <?= $estadoSubgrupos == 'sin' ? 'selected' : '' ?>>Sin Subgrupos</option>
                                    <option value="configurados" <?= $estadoSubgrupos == 'configurados' ? 'selected' : '' ?>>Configurados</option>
                                    <option value="sin_configurar" <?= $estadoSubgrupos == 'sin_configurar' ? 'selected' : '' ?>>Sin Configurar</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label for="vista" class="form-label">Vista:</label>
                                <select name="vista" id="vista" class="form-select">
                                    <option value="tarjetas" <?= $vista == 'tarjetas' ? 'selected' : '' ?>>Tarjetas</option>
                                    <option value="tabla" <?= $vista == 'tabla' ? 'selected' : '' ?>>Tabla</option>
                                </select>
                            </div>
                            
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Filtros rápidos -->
                        <div class="mt-3">
                            <small class="text-muted">Accesos rápidos:</small>
                            <div class="btn-group btn-group-sm mt-1" role="group">
                                <a href="?categoria=talleres" class="btn btn-outline-warning">
                                    <i class="bi bi-tools"></i> Talleres (<?= $estadisticas['talleres'] ?>)
                                </a>
                                <a href="?categoria=ciudadania" class="btn btn-outline-info">
                                    <i class="bi bi-people"></i> Ciudadanía (<?= $estadisticas['ciudadania'] ?>)
                                </a>
                                <a href="?categoria=basicas" class="btn btn-outline-secondary">
                                    <i class="bi bi-book"></i> De Aula (<?= $estadisticas['sin_subgrupos'] ?>)
                                </a>
                                
                                <a href="?" class="btn btn-outline-dark">
                                    <i class="bi bi-arrow-clockwise"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Panel de gestión masiva (inicialmente oculto) -->
                    <div id="panelGestionMasiva" style="display: none;" class="border rounded p-3 bg-light mb-3">
                        <h6><i class="bi bi-list-check"></i> Gestión Masiva de Subgrupos</h6>
                        <form id="formGestionMasiva">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-check-inline">
                                        <input type="checkbox" class="form-check-input" id="selectAllMaterias">
                                        <label class="form-check-label" for="selectAllMaterias">
                                            <strong>Seleccionar todas las materias visibles</strong>
                                        </label>
                                    </div>
                                    <div id="contadorSeleccionadas" class="small text-muted mt-1">0 materias seleccionadas</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-success btn-sm" onclick="aplicarGestionMasiva(1)">
                                            <i class="bi bi-check"></i> Marcar Subgrupos
                                        </button>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="aplicarGestionMasiva(0)">
                                            <i class="bi bi-x"></i> Desmarcar Subgrupos
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="ocultarGestionMasiva()">
                                            <i class="bi bi-x-circle"></i> Cerrar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contenido principal: Vista de tarjetas o tabla -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <?php if ($categoria): ?>
                            <?php
                            $titulos = [
                                'talleres' => '<i class="bi bi-tools"></i> Materias de Taller',
                                'ciudadania' => '<i class="bi bi-people"></i> Construcción de la Ciudadanía', 
                                'basicas' => '<i class="bi bi-book"></i> Materias Básicas'
                            ];
                            echo $titulos[$categoria];
                            ?>
                        <?php else: ?>
                            <i class="bi bi-grid"></i> Todas las Materias
                        <?php endif; ?>
                        <span class="badge bg-secondary ms-2"><?= $totalRegistros ?> materias</span>
                    </h5>
                    <div class="btn-group btn-group-sm">
                        <a href="?<?= http_build_query(array_merge($_GET, ['vista' => 'tarjetas'])) ?>" 
                           class="btn btn-<?= $vista == 'tarjetas' ? 'primary' : 'outline-primary' ?>">
                            <i class="bi bi-grid-3x3"></i> Tarjetas
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['vista' => 'tabla'])) ?>" 
                           class="btn btn-<?= $vista == 'tabla' ? 'primary' : 'outline-primary' ?>">
                            <i class="bi bi-table"></i> Tabla
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($materias) > 0): ?>
                        
                        <?php if ($vista === 'tarjetas'): ?>
<!-- Vista de tarjetas MEJORADA para múltiples profesores -->
<div class="row">
    <?php foreach ($materias as $materia): ?>
    <?php 
        $clasificacion = clasificarTipoMateria($materia);
        $tieneSubgrupos = $materia['requiere_subgrupos'] == 1;
        $estaConfigurado = !empty($materia['tipo_division']);
        $profesores = obtenerProfesoresMateria($materia);
        $totalProfesores = count($profesores);
    ?>
    <div class="col-md-6 col-lg-4 col-xl-3 mb-4">
        <div class="card h-100 <?= $tieneSubgrupos ? 'border-warning' : 'border-secondary' ?>">
            <!-- Header de la tarjeta -->
            <div class="card-header bg-<?= $clasificacion['color'] ?> text-white d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-<?= $clasificacion['icono'] ?>"></i>
                    <strong><?= $materia['curso_anio'] ?>°</strong>
                </div>
                <div>
                    <div class="form-check form-check-inline" style="display: none;" id="gestion-<?= $materia['materia_curso_id'] ?>">
                        <input class="form-check-input materia-checkbox" type="checkbox" 
                               value="<?= $materia['materia_curso_id'] ?>" id="check-<?= $materia['materia_curso_id'] ?>">
                    </div>
                    <span class="badge bg-light text-dark"><?= htmlspecialchars($materia['materia_codigo']) ?></span>
                </div>
            </div>
            
            <!-- Cuerpo de la tarjeta -->
            <div class="card-body">
                <h6 class="card-title"><?= htmlspecialchars($materia['materia_nombre']) ?></h6>
                <p class="card-text">
                    <small class="text-muted">
                        <i class="bi bi-building"></i> <?= htmlspecialchars($materia['curso_nombre']) ?>
                    </small>
                </p>
                
                <!-- Información de profesores MEJORADA -->
                <div class="mb-2">
                    <?php if ($totalProfesores > 0): ?>
                        <small>
                            <i class="bi bi-<?= $totalProfesores > 1 ? 'people-fill' : 'person' ?>"></i> 
                            <?= $totalProfesores > 1 ? 'Equipo Docente' : 'Profesor' ?>:
                        </small>
                        <?php foreach ($profesores as $index => $profesor): ?>
                            <div class="small <?= $totalProfesores > 1 ? 'text-primary' : '' ?>">
                                <?php if ($totalProfesores > 1): ?>
                                    <span class="badge bg-primary badge-sm"><?= $profesor['posicion'] ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($profesor['nombre']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <small>
                            <i class="bi bi-person-x text-danger"></i> 
                            Sin asignar
                        </small>
                    <?php endif; ?>
                </div>
                
                <!-- Estado de subgrupos -->
                <div class="mb-2">
                    <?php if ($tieneSubgrupos): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-people"></i> Con Subgrupos
                        </span>
                        <?php if ($estaConfigurado): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-gear"></i> Configurado
                        </span>
                        <?php else: ?>
                        <span class="badge bg-danger">
                            <i class="bi bi-exclamation"></i> Sin Configurar
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-secondary">
                            <i class="bi bi-book"></i> Materia Normal
                        </span>
                    <?php endif; ?>
                </div>
                
                <!-- Información adicional si tiene subgrupos -->
                <?php if ($tieneSubgrupos && $estaConfigurado): ?>
                <div class="small text-muted mb-2">
                    <i class="bi bi-info-circle"></i>
                    <?= ucfirst($materia['tipo_division']) ?> en <?= $materia['cantidad_grupos'] ?> grupos
                    <?php if ($materia['rotacion_automatica']): ?>
                    <br><i class="bi bi-arrow-repeat"></i> Rotación <?= $materia['periodo_rotacion'] ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Estadísticas -->
                <?php if ($materia['estudiantes_asignados'] > 0): ?>
                <div class="small text-success">
                    <i class="bi bi-people-fill"></i> <?= $materia['estudiantes_asignados'] ?> estudiantes asignados
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Footer con acciones -->
            <div class="card-footer">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-primary btn-sm" 
                            onclick="editarMateria(<?= $materia['materia_curso_id'] ?>, '<?= addslashes($materia['materia_nombre']) ?>', '<?= addslashes($materia['materia_codigo']) ?>')"
                            data-bs-toggle="modal" data-bs-target="#modalEditarMateria"
                            title="Editar">
                        <i class="bi bi-pencil"></i>
                    </button>
                    
                    <button type="button" class="btn btn-outline-success btn-sm" 
                            onclick="editarProfesores(<?= $materia['materia_curso_id'] ?>, <?= $materia['materia_id'] ?>, <?= $materia['curso_id'] ?>)"
                            data-bs-toggle="modal" data-bs-target="#modalAsignarProfesores"
                            title="Editar profesores">
                        <i class="bi bi-<?= $totalProfesores > 1 ? 'people-fill' : 'person-plus' ?>"></i>
                    </button>
                    
                    <?php if ($tieneSubgrupos): ?>
                    <a href="gestionar_subgrupos.php?materia=<?= $materia['materia_curso_id'] ?>" 
                       class="btn btn-outline-info btn-sm" title="Gestionar Subgrupos">
                        <i class="bi bi-people"></i>
                    </a>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-outline-info btn-sm" 
                            onclick="verAsignaciones(<?= $materia['materia_id'] ?>)"
                            data-bs-toggle="modal" data-bs-target="#modalVerAsignaciones"
                            title="Ver asignaciones">
                        <i class="bi bi-eye"></i>
                    </button>
                    
                    <button type="button" class="btn btn-outline-<?= $tieneSubgrupos ? 'warning' : 'success' ?> btn-sm" 
                            onclick="toggleSubgrupos(<?= $materia['materia_curso_id'] ?>, <?= $tieneSubgrupos ? 0 : 1 ?>)"
                            title="<?= $tieneSubgrupos ? 'Desactivar' : 'Activar' ?> subgrupos">
                        <i class="bi bi-<?= $tieneSubgrupos ? 'toggle-on' : 'toggle-off' ?>"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- Vista de tabla MEJORADA para múltiples profesores -->
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th class="d-none" id="headerCheckContainer">
                    <input type="checkbox" class="form-check-input" id="selectAllTable">
                </th>
                <th>Código</th>
                <th>Materia</th>
                <th>Curso</th>
                <th>Profesores</th>
                <th>Tipo</th>
                <th>Subgrupos</th>
                <th>Estudiantes</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($materias as $materia): ?>
            <?php 
                $clasificacion = clasificarTipoMateria($materia);
                $tieneSubgrupos = $materia['requiere_subgrupos'] == 1;
                $estaConfigurado = !empty($materia['tipo_division']);
                $profesores = obtenerProfesoresMateria($materia);
                $totalProfesores = count($profesores);
            ?>
            <tr>
                <td class="materia-check-container d-none">
                    <input class="form-check-input materia-checkbox" type="checkbox" 
                           value="<?= $materia['materia_curso_id'] ?>" id="table-check-<?= $materia['materia_curso_id'] ?>">
                </td>
                
                <td>
                    <span class="badge bg-<?= $clasificacion['color'] ?> text-white">
                        <?= htmlspecialchars($materia['materia_codigo']) ?>
                    </span>
                </td>
                
                <td>
                    <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                    <br>
                    <small class="text-muted">
                        <i class="bi bi-<?= $clasificacion['icono'] ?>"></i>
                        <?= $clasificacion['tipo'] ?>
                    </small>
                </td>
                
                <td>
                    <span class="badge bg-primary"><?= $materia['curso_anio'] ?>°</span>
                    <br>
                    <small class="text-muted"><?= htmlspecialchars($materia['curso_nombre']) ?></small>
                </td>
                
                <td>
                    <?php if ($totalProfesores > 0): ?>
                        <div class="d-flex align-items-center mb-1">
                            <i class="bi bi-<?= $totalProfesores > 1 ? 'people-fill text-primary' : 'person-check text-success' ?>"></i>
                            <span class="ms-1 small"><?= $totalProfesores > 1 ? "Equipo ($totalProfesores)" : 'Asignado' ?></span>
                        </div>
                        <?php foreach ($profesores as $index => $profesor): ?>
                            <div class="small">
                                <?php if ($totalProfesores > 1): ?>
                                    <span class="badge bg-primary badge-sm me-1"><?= $profesor['posicion'] ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($profesor['nombre']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <i class="bi bi-person-x text-danger"></i>
                        <em class="text-muted">Sin asignar</em>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($tieneSubgrupos): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-people"></i> Con Subgrupos
                        </span>
                        <br>
                        <?php if ($estaConfigurado): ?>
                            <small class="text-success">
                                <i class="bi bi-gear"></i> Configurado
                                (<?= $materia['cantidad_grupos'] ?> grupos)
                            </small>
                        <?php else: ?>
                            <small class="text-danger">
                                <i class="bi bi-exclamation-triangle"></i> Sin configurar
                            </small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-secondary">
                            <i class="bi bi-book"></i> Normal
                        </span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($tieneSubgrupos && $estaConfigurado): ?>
                        <div class="small">
                            <strong><?= ucfirst($materia['tipo_division']) ?></strong>
                            <?php if ($materia['rotacion_automatica']): ?>
                                <br><i class="bi bi-arrow-repeat"></i> 
                                Rotación <?= $materia['periodo_rotacion'] ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <?php if ($materia['estudiantes_asignados'] > 0): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-people-fill"></i>
                            <?= $materia['estudiantes_asignados'] ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                onclick="editarMateria(<?= $materia['materia_curso_id'] ?>, '<?= addslashes($materia['materia_nombre']) ?>', '<?= addslashes($materia['materia_codigo']) ?>')"
                                data-bs-toggle="modal" data-bs-target="#modalEditarMateria"
                                title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        
                        <button type="button" class="btn btn-outline-success btn-sm" 
                                onclick="editarProfesores(<?= $materia['materia_curso_id'] ?>, <?= $materia['materia_id'] ?>, <?= $materia['curso_id'] ?>)"
                                data-bs-toggle="modal" data-bs-target="#modalAsignarProfesores"
                                title="Editar profesores">
                            <i class="bi bi-<?= $totalProfesores > 1 ? 'people-fill' : 'person-plus' ?>"></i>
                        </button>
                        
                        <?php if ($tieneSubgrupos): ?>
                        <a href="gestionar_subgrupos.php?materia=<?= $materia['materia_curso_id'] ?>" 
                           class="btn btn-outline-info btn-sm" title="Gestionar Subgrupos">
                            <i class="bi bi-people"></i>
                        </a>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-outline-info btn-sm" 
                                onclick="verAsignaciones(<?= $materia['materia_id'] ?>)"
                                data-bs-toggle="modal" data-bs-target="#modalVerAsignaciones"
                                title="Ver asignaciones">
                            <i class="bi bi-eye"></i>
                        </button>
                        
                        <button type="button" class="btn btn-outline-<?= $tieneSubgrupos ? 'warning' : 'success' ?> btn-sm" 
                                onclick="toggleSubgrupos(<?= $materia['materia_curso_id'] ?>, <?= $tieneSubgrupos ? 0 : 1 ?>)"
                                title="<?= $tieneSubgrupos ? 'Desactivar' : 'Activar' ?> subgrupos">
                            <i class="bi bi-<?= $tieneSubgrupos ? 'toggle-on' : 'toggle-off' ?>"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (count($materias) == 0): ?>
<div class="text-center py-5">
    <i class="bi bi-table" style="font-size: 4rem; color: #6c757d;"></i>
    <h5 class="text-muted mt-3">No hay materias para mostrar</h5>
                        <p class="text-muted">Intente ajustar los filtros o crear una nueva materia</p>
                </div>
                <?php endif; ?>

                <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-search" style="font-size: 4rem; color: #6c757d;"></i>
                            <h5 class="text-muted mt-3">No se encontraron materias</h5>
                            <p class="text-muted">Intente ajustar los filtros o crear una nueva materia</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearMateria">
                                <i class="bi bi-plus-circle"></i> Crear Primera Materia
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Paginación -->
    <?php if ($totalPaginas > 1): ?>
    <nav aria-label="Paginación de materias">
        <ul class="pagination justify-content-center">
            <?php if ($paginaActual > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual - 1])) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            <?php endif; ?>
            
            <?php for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++): ?>
                <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>
            
            <?php if ($paginaActual < $totalPaginas): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual + 1])) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <div class="text-center">
        <small class="text-muted">
            Mostrando <?= $offset + 1 ?> - <?= min($offset + $registrosPorPagina, $totalRegistros) ?> 
            de <?= $totalRegistros ?> materias
        </small>
    </div>
    <?php endif; ?>
</div>

<!-- MODALES ACTUALIZADOS PARA MÚLTIPLES PROFESORES -->

<!-- Modal Crear Materia -->
<div class="modal fade" id="modalCrearMateria" tabindex="-1" aria-labelledby="modalCrearMateriaLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="materias.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearMateriaLabel">Crear Nueva Materia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_materia">
                    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
                    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
                    
                    <div class="mb-3">
                        <label for="crear_nombre" class="form-label">Nombre de la Materia *</label>
                        <input type="text" class="form-control" id="crear_nombre" name="nombre" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="crear_codigo" class="form-label">Código *</label>
                        <input type="text" class="form-control" id="crear_codigo" name="codigo" required 
                               placeholder="ej: MAT, FIS, 1PT1">
                        <small class="form-text text-muted">Código único para identificar la materia</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Materia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Materia -->
<div class="modal fade" id="modalEditarMateria" tabindex="-1" aria-labelledby="modalEditarMateriaLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="materias.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarMateriaLabel">Editar Materia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="editar_materia">
                    <input type="hidden" name="materia_id" id="editar_materia_id">
                    <!-- CAMPOS CORREGIDOS PARA MANTENER TODOS LOS FILTROS -->
                    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
                    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
                    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
                    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
                    <input type="hidden" name="ano_original" value="<?= htmlspecialchars($anoFiltro) ?>">
                    <input type="hidden" name="subgrupos_original" value="<?= htmlspecialchars($estadoSubgrupos) ?>">
                    
                    <div class="mb-3">
                        <label for="editar_nombre" class="form-label">Nombre de la Materia *</label>
                        <input type="text" class="form-control" id="editar_nombre" name="nombre" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editar_codigo" class="form-label">Código *</label>
                        <input type="text" class="form-control" id="editar_codigo" name="codigo" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Materia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Modal Asignar Profesores - AÑADIR campos faltantes (buscar línea ~900)
?>
<div class="modal fade" id="modalAsignarProfesores" tabindex="-1" aria-labelledby="modalAsignarProfesoresLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="materias.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalAsignarProfesoresLabel">
                        <i class="bi bi-people-fill"></i> Asignar Profesores a Materia
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="asignar_profesores">
                    <input type="hidden" name="materia_curso_id" id="asignar_materia_curso_id">
                    <!-- CAMPOS CORREGIDOS PARA MANTENER TODOS LOS FILTROS -->
                    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
                    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
                    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
                    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
                    <input type="hidden" name="ano_original" value="<?= htmlspecialchars($anoFiltro) ?>">
                    <input type="hidden" name="subgrupos_original" value="<?= htmlspecialchars($estadoSubgrupos) ?>">
                   
                    
                    <div class="row mb-3" id="seleccion_materia_curso" style="display: none;">
                        <div class="col-md-6">
                            <label for="asignar_materia_id" class="form-label">Materia *</label>
                            <select class="form-select" id="asignar_materia_id" name="materia_id" required>
                                <option value="">-- Seleccione una materia --</option>
                                <?php 
                                $todasMaterias = $db->fetchAll("SELECT id, nombre, codigo FROM materias ORDER BY nombre");
                                foreach ($todasMaterias as $m): 
                                ?>
                                <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?> (<?= htmlspecialchars($m['codigo']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="asignar_curso_id" class="form-label">Curso *</label>
                            <select class="form-select" id="asignar_curso_id" name="curso_id" required>
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?> (<?= $c['anio'] ?>° año)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6 class="alert-heading">
                            <i class="bi bi-info-circle"></i> Equipo Docente
                        </h6>
                        <p class="mb-0">
                            Puede asignar hasta 3 profesores para trabajar en equipo en esta materia. 
                            Los profesores compartirán la responsabilidad de calificar y gestionar a los estudiantes.
                        </p>
                    </div>
                    
                    <!-- Profesor Principal -->
                    <div class="mb-3">
                        <label for="asignar_profesor_1_id" class="form-label">
                            <i class="bi bi-1-circle-fill text-primary"></i> Profesor Principal *
                        </label>
                        <select class="form-select" id="asignar_profesor_1_id" name="profesor_1_id" required>
                            <option value="">-- Seleccione el profesor principal --</option>
                            <?php 
                            if (count($profesoresParaSelect) > 0) {
                                foreach ($profesoresParaSelect as $p): 
                                    $displayName = '';
                                    if (isset($p['nombre_completo']) && !empty($p['nombre_completo'])) {
                                        $displayName = $p['nombre_completo'];
                                    } else {
                                        $apellido = isset($p['apellido']) ? trim($p['apellido']) : '';
                                        $nombre = isset($p['nombre']) ? trim($p['nombre']) : '';
                                        
                                        if (!empty($apellido) && !empty($nombre)) {
                                            $displayName = $apellido . ', ' . $nombre;
                                        } elseif (!empty($apellido)) {
                                            $displayName = $apellido;
                                        } elseif (!empty($nombre)) {
                                            $displayName = $nombre;
                                        } else {
                                            $displayName = 'Sin nombre - ID: ' . $p['id'];
                                        }
                                    }
                            ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($displayName) ?></option>
                            <?php 
                                endforeach; 
                            } else {
                                echo '<option value="" disabled>❌ No hay profesores disponibles</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <!-- Segundo Profesor -->
                    <div class="mb-3">
                        <label for="asignar_profesor_2_id" class="form-label">
                            <i class="bi bi-2-circle text-success"></i> Segundo Profesor (opcional)
                        </label>
                        <select class="form-select" id="asignar_profesor_2_id" name="profesor_2_id">
                            <option value="">-- Ninguno --</option>
                            <?php 
                            if (count($profesoresParaSelect) > 0) {
                                foreach ($profesoresParaSelect as $p): 
                                    $displayName = '';
                                    if (isset($p['nombre_completo']) && !empty($p['nombre_completo'])) {
                                        $displayName = $p['nombre_completo'];
                                    } else {
                                        $apellido = isset($p['apellido']) ? trim($p['apellido']) : '';
                                        $nombre = isset($p['nombre']) ? trim($p['nombre']) : '';
                                        
                                        if (!empty($apellido) && !empty($nombre)) {
                                            $displayName = $apellido . ', ' . $nombre;
                                        } elseif (!empty($apellido)) {
                                            $displayName = $apellido;
                                        } elseif (!empty($nombre)) {
                                            $displayName = $nombre;
                                        } else {
                                            $displayName = 'Sin nombre - ID: ' . $p['id'];
                                        }
                                    }
                            ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($displayName) ?></option>
                            <?php endforeach; } ?>
                        </select>
                    </div>
                    
                    <!-- Tercer Profesor -->
                    <div class="mb-3">
                        <label for="asignar_profesor_3_id" class="form-label">
                            <i class="bi bi-3-circle text-warning"></i> Tercer Profesor (opcional)
                        </label>
                        <select class="form-select" id="asignar_profesor_3_id" name="profesor_3_id">
                            <option value="">-- Ninguno --</option>
                            <?php 
                            if (count($profesoresParaSelect) > 0) {
                                foreach ($profesoresParaSelect as $p): 
                                    $displayName = '';
                                    if (isset($p['nombre_completo']) && !empty($p['nombre_completo'])) {
                                        $displayName = $p['nombre_completo'];
                                    } else {
                                        $apellido = isset($p['apellido']) ? trim($p['apellido']) : '';
                                        $nombre = isset($p['nombre']) ? trim($p['nombre']) : '';
                                        
                                        if (!empty($apellido) && !empty($nombre)) {
                                            $displayName = $apellido . ', ' . $nombre;
                                        } elseif (!empty($apellido)) {
                                            $displayName = $apellido;
                                        } elseif (!empty($nombre)) {
                                            $displayName = $nombre;
                                        } else {
                                            $displayName = 'Sin nombre - ID: ' . $p['id'];
                                        }
                                    }
                            ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($displayName) ?></option>
                            <?php endforeach; } ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning">
                        <small>
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Importante:</strong> No puede asignar el mismo profesor múltiples veces. 
                            Si la materia ya tiene profesores asignados, esta acción los reemplazará.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-people-fill"></i> Asignar Profesores
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ver Asignaciones (actualizado) -->
<div class="modal fade" id="modalVerAsignaciones" tabindex="-1" aria-labelledby="modalVerAsignacionesLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalVerAsignacionesLabel">Asignaciones de la Materia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoAsignaciones">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formularios ocultos para acciones -->
<form id="formEliminarMateria" method="POST" action="materias.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_materia">
    <input type="hidden" name="materia_id" id="eliminar_materia_id">
    <!-- CAMPOS CORREGIDOS -->
    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
    <input type="hidden" name="ano_original" value="<?= htmlspecialchars($anoFiltro) ?>">
    <input type="hidden" name="subgrupos_original" value="<?= htmlspecialchars($estadoSubgrupos) ?>">
</form>

<form id="formRemoverAsignacion" method="POST" action="materias.php" style="display: none;">
    <input type="hidden" name="accion" value="remover_asignacion">
    <input type="hidden" name="asignacion_id" id="remover_asignacion_id">
    <!-- CAMPOS CORREGIDOS -->
    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
    <input type="hidden" name="ano_original" value="<?= htmlspecialchars($anoFiltro) ?>">
    <input type="hidden" name="subgrupos_original" value="<?= htmlspecialchars($estadoSubgrupos) ?>">
</form>

<form id="formToggleSubgrupos" method="POST" action="materias.php" style="display: none;">
    <input type="hidden" name="accion" value="cambiar_estado_subgrupos">
    <input type="hidden" name="materia_curso_id" id="toggle_materia_curso_id">
    <input type="hidden" name="requiere_subgrupos" id="toggle_requiere_subgrupos">
    <!-- CAMPOS CORREGIDOS -->
    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
    <input type="hidden" name="ano_original" value="<?= htmlspecialchars($anoFiltro) ?>">
    <input type="hidden" name="subgrupos_original" value="<?= htmlspecialchars($estadoSubgrupos) ?>">
</form>

<form id="formGestionMasivaSubmit" method="POST" action="materias.php" style="display: none;">
    <input type="hidden" name="accion" value="marcar_multiples_subgrupos">
    <input type="hidden" name="requiere_subgrupos" id="masiva_requiere_subgrupos">
    <!-- CAMPOS CORREGIDOS -->
    <input type="hidden" name="vista_original" value="<?= htmlspecialchars($vista) ?>">
    <input type="hidden" name="categoria_original" value="<?= htmlspecialchars($categoria) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
    <input type="hidden" name="ano_original" value="<?= htmlspecialchars($anoFiltro) ?>">
    <input type="hidden" name="subgrupos_original" value="<?= htmlspecialchars($estadoSubgrupos) ?>">
    <div id="materiasIdsContainer"></div>
</form>

<script>
// Variables globales
let gestionMasivaActiva = false;

// Función NUEVA para editar profesores de una materia existente
function editarProfesores(materiaCursoId, materiaId, cursoId) {
    console.log('Editando profesores:', materiaCursoId, materiaId, cursoId);
    
    // Configurar modal para edición
    document.getElementById('modalAsignarProfesoresLabel').innerHTML = 
        '<i class="bi bi-people-fill"></i> Editar Profesores de la Materia';
    
    // Ocultar selectores de materia y curso
    document.getElementById('seleccion_materia_curso').style.display = 'none';
    
    // Establecer valores ocultos
    document.getElementById('asignar_materia_curso_id').value = materiaCursoId;
    document.getElementById('asignar_materia_id').value = materiaId;
    document.getElementById('asignar_curso_id').value = cursoId;
    
    // Marcar campos como no requeridos para edición
    document.getElementById('asignar_materia_id').required = false;
    document.getElementById('asignar_curso_id').required = false;
    
    // Cargar profesores actuales
    cargarProfesoresActuales(materiaCursoId);
}

// Función para mostrar modal de asignación nueva
function mostrarAsignacionNueva() {
    // Configurar modal para nueva asignación
    document.getElementById('modalAsignarProfesoresLabel').innerHTML = 
        '<i class="bi bi-people-fill"></i> Asignar Profesores a Materia';
    
    // Mostrar selectores de materia y curso
    document.getElementById('seleccion_materia_curso').style.display = 'block';
    
    // Limpiar valores
    document.getElementById('asignar_materia_curso_id').value = '';
    document.getElementById('asignar_materia_id').value = '';
    document.getElementById('asignar_curso_id').value = '';
    document.getElementById('asignar_profesor_1_id').value = '';
    document.getElementById('asignar_profesor_2_id').value = '';
    document.getElementById('asignar_profesor_3_id').value = '';
    
    // Marcar campos como requeridos para nueva asignación
    document.getElementById('asignar_materia_id').required = true;
    document.getElementById('asignar_curso_id').required = true;
}

// Función para cargar profesores actuales de una materia
function cargarProfesoresActuales(materiaCursoId) {
    fetch('obtener_profesores_materia.php?materia_curso_id=' + materiaCursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('asignar_profesor_1_id').value = data.profesor_1_id || '';
                document.getElementById('asignar_profesor_2_id').value = data.profesor_2_id || '';
                document.getElementById('asignar_profesor_3_id').value = data.profesor_3_id || '';
            } else {
                console.error('Error al cargar profesores:', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Función para cargar datos en el modal de edición
function editarMateria(materiaCursoId, nombreMateria, codigoMateria) {
    console.log('Editando materia:', materiaCursoId, nombreMateria, codigoMateria);
    
    // Verificar que los parámetros lleguen correctamente
    if (!materiaCursoId || !nombreMateria || !codigoMateria) {
        console.error('Parámetros incompletos:', {materiaCursoId, nombreMateria, codigoMateria});
        alert('Error: Datos de la materia incompletos');
        return;
    }
    
    // Necesitamos obtener el ID real de la materia desde el servidor
    fetch('obtener_materia_por_curso.php?materia_curso_id=' + materiaCursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editar_materia_id').value = data.materia.id;
                document.getElementById('editar_nombre').value = data.materia.nombre;
                document.getElementById('editar_codigo').value = data.materia.codigo;
                
                console.log('Datos cargados en el modal:', {
                    id: document.getElementById('editar_materia_id').value,
                    nombre: document.getElementById('editar_nombre').value,
                    codigo: document.getElementById('editar_codigo').value
                });
            } else {
                alert('Error al cargar datos: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar datos de la materia');
        });
}

// Función para ver asignaciones de una materia
function verAsignaciones(materiaId) {
    document.getElementById('contenidoAsignaciones').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>
    `;
    
    fetch('obtener_asignaciones_materia_multiple.php?id=' + materiaId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `
                    <h6>Materia: ${data.materia.nombre} (${data.materia.codigo})</h6>
                    <hr>
                `;
                
                if (data.asignaciones.length > 0) {
                    html += `
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Curso</th>
                                        <th>Profesores Asignados</th>
                                        <th>Ciclo</th>
                                        <th>Calificaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    data.asignaciones.forEach(asignacion => {
                        let profesoresHtml = '';
                        if (asignacion.profesores && asignacion.profesores.length > 0) {
                            asignacion.profesores.forEach((profesor, index) => {
                                if (profesor) {
                                    profesoresHtml += `
                                        <div class="small">
                                            <span class="badge bg-primary badge-sm me-1">${index + 1}</span>
                                            ${profesor}
                                        </div>
                                    `;
                                }
                            });
                        } else {
                            profesoresHtml = '<em class="text-muted">Sin asignar</em>';
                        }
                        
                        html += `
                            <tr>
                                <td>${asignacion.curso_nombre} (${asignacion.curso_anio}° año)</td>
                                <td>${profesoresHtml}</td>
                                <td>${asignacion.ciclo_anio}</td>
                                <td><span class="badge bg-info">${asignacion.total_calificaciones || 0}</span></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="removerAsignacion(${asignacion.id})" 
                                            title="Eliminar asignación"
                                            ${asignacion.total_calificaciones > 0 ? 'disabled' : ''}>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    
                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Esta materia no tiene asignaciones a cursos.
                        </div>
                    `;
                }
                
                document.getElementById('contenidoAsignaciones').innerHTML = html;
            } else {
                document.getElementById('contenidoAsignaciones').innerHTML = `
                    <div class="alert alert-danger">
                        Error al cargar las asignaciones: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoAsignaciones').innerHTML = `
                <div class="alert alert-danger">
                    Error al cargar las asignaciones.
                </div>
            `;
        });
}

// Función para eliminar materia
function eliminarMateria(materiaId) {
    if (confirm('¿Está seguro de que desea eliminar esta materia?\n\nEsta acción no se puede deshacer.\n\nNota: No se puede eliminar si tiene asignaciones o calificaciones registradas.')) {
        const form = document.getElementById('formEliminarMateria');
        guardarScrollPosition(form);
        
        document.getElementById('eliminar_materia_id').value = materiaId;
        form.submit();
    }
}

// Función para remover asignación
function removerAsignacion(asignacionId) {
    if (confirm('¿Está seguro de que desea eliminar esta asignación?\n\nNota: No se puede eliminar si tiene calificaciones registradas.')) {
        const form = document.getElementById('formRemoverAsignacion');
        guardarScrollPosition(form);
        
        document.getElementById('remover_asignacion_id').value = asignacionId;
        form.submit();
    }
}

// Función para cambiar estado de subgrupos
function toggleSubgrupos(materiaCursoId, nuevoEstado) {
    const accion = nuevoEstado ? 'activar' : 'desactivar';
    if (confirm(`¿${accion.charAt(0).toUpperCase() + accion.slice(1)} subgrupos para esta materia?`)) {
        const form = document.getElementById('formToggleSubgrupos');
        guardarScrollPosition(form);
        
        document.getElementById('toggle_materia_curso_id').value = materiaCursoId;
        document.getElementById('toggle_requiere_subgrupos').value = nuevoEstado;
        form.submit();
    }
}

// Validación de profesores únicos
function validarProfesoresUnicos() {
    const profesor1 = document.getElementById('asignar_profesor_1_id').value;
    const profesor2 = document.getElementById('asignar_profesor_2_id').value;
    const profesor3 = document.getElementById('asignar_profesor_3_id').value;
    
    const profesores = [profesor1, profesor2, profesor3].filter(p => p !== '');
    const profesoresUnicos = [...new Set(profesores)];
    
    if (profesores.length !== profesoresUnicos.length) {
        alert('No puede asignar el mismo profesor múltiples veces');
        return false;
    }
    
    if (profesores.length === 0) {
        alert('Debe asignar al menos un profesor');
        return false;
    }
    
    return true;
}

// Funciones para gestión masiva (mantener las originales)
function mostrarGestionMasiva() {
    gestionMasivaActiva = true;
    document.getElementById('panelGestionMasiva').style.display = 'block';
    
    // Mostrar checkboxes en vista de tarjetas
    document.querySelectorAll('[id^="gestion-"]').forEach(el => {
        el.style.display = 'block';
    });
    
    // Mostrar checkboxes en vista de tabla
    document.querySelectorAll('.materia-check-container').forEach(el => {
        el.classList.remove('d-none');
    });
    document.getElementById('headerCheckContainer').classList.remove('d-none');
    
    actualizarContadorSeleccionadas();
}

function ocultarGestionMasiva() {
    gestionMasivaActiva = false;
    document.getElementById('panelGestionMasiva').style.display = 'none';
    
    // Ocultar checkboxes en vista de tarjetas
    document.querySelectorAll('[id^="gestion-"]').forEach(el => {
        el.style.display = 'none';
    });
    
    // Ocultar checkboxes en vista de tabla
    document.querySelectorAll('.materia-check-container').forEach(el => {
        el.classList.add('d-none');
    });
    document.getElementById('headerCheckContainer').classList.add('d-none');
    
    // Limpiar selecciones
    document.querySelectorAll('.materia-checkbox').forEach(cb => {
        cb.checked = false;
    });
    const selectAll = document.getElementById('selectAllMaterias');
    if (selectAll) selectAll.checked = false;
    const selectAllTable = document.getElementById('selectAllTable');
    if (selectAllTable) selectAllTable.checked = false;
    
    actualizarContadorSeleccionadas();
}

function actualizarContadorSeleccionadas() {
    const seleccionadas = document.querySelectorAll('.materia-checkbox:checked').length;
    const contador = document.getElementById('contadorSeleccionadas');
    if (contador) {
        contador.textContent = `${seleccionadas} materias seleccionadas`;
    }
}

function aplicarGestionMasiva(requiereSubgrupos) {
    const materiasSeleccionadas = Array.from(document.querySelectorAll('.materia-checkbox:checked')).map(cb => cb.value);
    
    if (materiasSeleccionadas.length === 0) {
        alert('Debe seleccionar al menos una materia');
        return;
    }
    
    const accion = requiereSubgrupos ? 'marcar como que requieren' : 'desmarcar de requerir';
    if (confirm(`¿${accion.charAt(0).toUpperCase() + accion.slice(1)} subgrupos para ${materiasSeleccionadas.length} materias seleccionadas?`)) {
        const form = document.getElementById('formGestionMasivaSubmit');
        guardarScrollPosition(form);
        
        document.getElementById('masiva_requiere_subgrupos').value = requiereSubgrupos;
        
        // Limpiar contenedor anterior
        const container = document.getElementById('materiasIdsContainer');
        container.innerHTML = '';
        
        // Agregar inputs para cada materia seleccionada
        materiasSeleccionadas.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'materias_ids[]';
            input.value = id;
            container.appendChild(input);
        });
        
        form.submit();
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit en cambios de filtros
    const filtros = ['categoria', 'ano', 'subgrupos', 'vista'];
    filtros.forEach(filtroId => {
        const elemento = document.getElementById(filtroId);
        if (elemento) {
            elemento.addEventListener('change', function() {
                document.getElementById('formFiltros').submit();
            });
        }
    });
    
    // Validación al enviar formulario de asignación de profesores
    const formAsignarProfesores = document.querySelector('#modalAsignarProfesores form');
    if (formAsignarProfesores) {
        formAsignarProfesores.addEventListener('submit', function(e) {
            if (!validarProfesoresUnicos()) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Evento para limpiar duplicados en selección de profesores
    ['asignar_profesor_1_id', 'asignar_profesor_2_id', 'asignar_profesor_3_id'].forEach(selectId => {
        const select = document.getElementById(selectId);
        if (select) {
            select.addEventListener('change', function() {
                // Lógica adicional si necesitas prevenir duplicados en tiempo real
            });
        }
    });
    
    // Configurar modal para nueva asignación cuando se abre sin editar
    const btnAsignarProfesores = document.querySelector('[data-bs-target="#modalAsignarProfesores"]');
    if (btnAsignarProfesores) {
        btnAsignarProfesores.addEventListener('click', function() {
            mostrarAsignacionNueva();
        });
    }
    
    // Seleccionar todos - vista de tarjetas
    const selectAllMaterias = document.getElementById('selectAllMaterias');
    if (selectAllMaterias) {
        selectAllMaterias.addEventListener('change', function() {
            document.querySelectorAll('.materia-checkbox').forEach(cb => {
                cb.checked = this.checked;
            });
            actualizarContadorSeleccionadas();
        });
    }
    
    // Seleccionar todos - vista de tabla
    const selectAllTable = document.getElementById('selectAllTable');
    if (selectAllTable) {
        selectAllTable.addEventListener('change', function() {
            document.querySelectorAll('.materia-checkbox').forEach(cb => {
                cb.checked = this.checked;
            });
            actualizarContadorSeleccionadas();
        });
    }
    
    // Actualizar contador cuando cambian checkboxes individuales
    document.querySelectorAll('.materia-checkbox').forEach(cb => {
        cb.addEventListener('change', actualizarContadorSeleccionadas);
    });
    
    // Generar código automáticamente
    const nombreInput = document.getElementById('crear_nombre');
    const codigoInput = document.getElementById('crear_codigo');
    
    if (nombreInput && codigoInput) {
        nombreInput.addEventListener('input', function() {
            const nombre = this.value.trim();
            if (nombre && !codigoInput.value) {
                // Generar código automático
                const palabras = nombre.split(' ');
                let codigo = '';
                
                for (let i = 0; i < Math.min(4, palabras.length); i++) {
                    const palabra = palabras[i];
                    if (palabra.length > 1 && !['de', 'la', 'el', 'los', 'las', 'del', 'con', 'por', 'para', 'en', 'y'].includes(palabra.toLowerCase())) {
                        codigo += palabra.charAt(0).toUpperCase();
                    }
                }
                
                // Si hay números al final, preservarlos
                const match = nombre.match(/(\d+)$/);
                if (match) {
                    codigo += match[1];
                }
                
                codigoInput.value = codigo;
            }
        });
    }
    
    // Limpiar formularios cuando se cierran los modales
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function () {
            const forms = this.querySelectorAll('form');
            forms.forEach(form => {
                if (form.id !== 'formEliminarMateria' && form.id !== 'formRemoverAsignacion' && form.id !== 'formToggleSubgrupos') {
                    form.reset();
                }
            });
        });
    });
    
    // Inicializar tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Funciones auxiliares para mejorar UX
function filtrarRapido(filtro, valor) {
    const url = new URL(window.location);
    url.searchParams.set(filtro, valor);
    window.location.href = url.toString();
}

function limpiarFiltros() {
    window.location.href = 'materias.php';
}

// Búsqueda en tiempo real (opcional)
let timeoutBusqueda;
const busquedaInput = document.getElementById('busqueda');
if (busquedaInput) {
    busquedaInput.addEventListener('input', function() {
        clearTimeout(timeoutBusqueda);
        timeoutBusqueda = setTimeout(() => {
            if (this.value.length >= 3 || this.value.length === 0) {
                document.getElementById('formFiltros').submit();
            }
        }, 1000);
    });
}

// Atajos de teclado
document.addEventListener('keydown', function(e) {
    // Ctrl + N para nueva materia
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        document.querySelector('[data-bs-target="#modalCrearMateria"]').click();
    }
    
    // Ctrl + P para asignar profesores
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        mostrarAsignacionNueva();
        const modal = new bootstrap.Modal(document.getElementById('modalAsignarProfesores'));
        modal.show();
    }
    
    // Escape para cerrar gestión masiva
    if (e.key === 'Escape' && gestionMasivaActiva) {
        ocultarGestionMasiva();
    }
    
    // Ctrl + F para enfocar búsqueda
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('busqueda').focus();
    }
});
// Variables para manejar posición de scroll
let scrollRestored = false;

// Función para guardar posición de scroll en formularios
function guardarScrollPosition(form) {
    const scrollPosition = window.pageYOffset || document.documentElement.scrollTop;
    
    // Buscar si ya existe el campo, si no crearlo
    let scrollInput = form.querySelector('input[name="scroll_position"]');
    if (!scrollInput) {
        scrollInput = document.createElement('input');
        scrollInput.type = 'hidden';
        scrollInput.name = 'scroll_position';
        form.appendChild(scrollInput);
    }
    scrollInput.value = scrollPosition;
}

// Función para restaurar posición de scroll
function restaurarScrollPosition() {
    if (scrollRestored) return; // Evitar múltiples restauraciones
    
    const urlParams = new URLSearchParams(window.location.search);
    const scrollPosition = urlParams.get('scroll');
    
    if (scrollPosition && scrollPosition > 0) {
        // Esperar a que la página esté completamente cargada
        setTimeout(() => {
            window.scrollTo({
                top: parseInt(scrollPosition),
                behavior: 'smooth' // Scroll suave
            });
            scrollRestored = true;
            
            // Limpiar el parámetro scroll de la URL sin recargar
            const newUrl = new URL(window.location);
            newUrl.searchParams.delete('scroll');
            window.history.replaceState({}, '', newUrl);
        }, 100);
    }
}

// Agregar event listeners a TODOS los formularios
document.addEventListener('DOMContentLoaded', function() {
    // Restaurar posición al cargar la página
    restaurarScrollPosition();
    
    // Agregar listeners a todos los formularios que modifican materias
    const formsToTrack = [
        '#modalEditarMateria form',
        '#modalAsignarProfesores form',
        '#formEliminarMateria',
        '#formRemoverAsignacion', 
        '#formToggleSubgrupos',
        '#formGestionMasivaSubmit'
    ];
    
    formsToTrack.forEach(selector => {
        const form = document.querySelector(selector);
        if (form) {
            form.addEventListener('submit', function(e) {
                guardarScrollPosition(this);
            });
        }
    });
    
    // También agregar a botones que ejecutan acciones directas
    document.querySelectorAll('[onclick*="toggleSubgrupos"], [onclick*="eliminarMateria"], [onclick*="removerAsignacion"]').forEach(button => {
        button.addEventListener('click', function() {
            // Pequeño delay para que se ejecute después del click original
            setTimeout(() => {
                const form = document.querySelector('#formToggleSubgrupos, #formEliminarMateria, #formRemoverAsignacion');
                if (form) {
                    guardarScrollPosition(form);
                }
            }, 10);
        });
    });
});
</script>

<style>
/* Estilos adicionales para la vista mejorada de múltiples profesores */
.card-hover-effect:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.badge-categoria {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.stats-card {
    border-left: 4px solid;
    border-radius: 0.5rem;
}

.filter-badge {
    cursor: pointer;
    transition: all 0.2s ease;
}

.filter-badge:hover {
    transform: scale(1.05);
}

.materia-checkbox {
    transform: scale(1.2);
}

.table-responsive {
    border-radius: 0.5rem;
    overflow: hidden;
}

.btn-group-sm .btn {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}

.pagination {
    margin-bottom: 0;
}

/* Estilos específicos para múltiples profesores */
.profesor-badge {
    font-size: 0.7rem;
    margin-right: 0.25rem;
}

.equipo-docente {
    background: linear-gradient(45deg, #007bff, #28a745);
    color: white;
    padding: 0.1rem 0.3rem;
    border-radius: 0.2rem;
    font-size: 0.7rem;
}

.profesor-position {
    display: inline-block;
    width: 1.2rem;
    height: 1.2rem;
    line-height: 1.2rem;
    text-align: center;
    border-radius: 50%;
    font-size: 0.7rem;
    font-weight: bold;
    margin-right: 0.25rem;
}

/* Animaciones suaves */
.card, .table {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive improvements */
@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .card-body .small {
        font-size: 0.75rem;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .profesor-position {
        width: 1rem;
        height: 1rem;
        line-height: 1rem;
        font-size: 0.6rem;
    }
}

/* Estados de subgrupos */
.subgrupos-activo {
    border-left: 3px solid #ffc107;
}

.subgrupos-configurado {
    border-left: 3px solid #198754;
}

.sin-subgrupos {
    border-left: 3px solid #6c757d;
}

/* Indicadores visuales para equipos docentes */
.multi-profesor {
    border-left: 3px solid #0d6efd;
}

.single-profesor {
    border-left: 3px solid #198754;
}

.sin-profesor {
    border-left: 3px solid #dc3545;
}

/* Indicadores de puntos */
.indicator-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
}

.indicator-success { background-color: #198754; }
.indicator-warning { background-color: #ffc107; }
.indicator-danger { background-color: #dc3545; }
.indicator-secondary { background-color: #6c757d; }
.indicator-primary { background-color: #0d6efd; }

.scroll-indicator {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 10px 20px;
    border-radius: 5px;
    z-index: 10000;
    display: none;
}

.scroll-indicator.show {
    display: block;
}
</style>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>