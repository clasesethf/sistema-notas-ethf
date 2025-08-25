<?php
/**
 * funciones_auxiliares_grupos.php - Funciones auxiliares para el manejo de grupos de materias
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * ACTUALIZADO: Compatibilidad completa con materias pendientes y nuevas funcionalidades
 */

/**
 * Obtener información completa de una materia con su grupo (si pertenece a uno)
 */
function obtenerInfoMateriaConGrupo($db, $materiaCursoId) {
    try {
        return $db->fetchOne(
            "SELECT 
                mpc.id as materia_curso_id,
                m.nombre as materia_nombre,
                m.codigo as materia_codigo,
                c.anio as curso_anio,
                c.nombre as curso_nombre,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                -- Indicador si es parte de un grupo
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM materias_por_curso mpc
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE mpc.id = ?",
            [$materiaCursoId]
        );
    } catch (Exception $e) {
        error_log("Error en obtenerInfoMateriaConGrupo: " . $e->getMessage());
        return null;
    }
}

/**
 * Formatear el nombre de una materia considerando si pertenece a un grupo
 */
function formatearNombreMateria($infoMateria, $mostrarDetalle = true, $incluirHTML = true) {
    if (!$infoMateria) return '';
    
    $nombrePrincipal = $incluirHTML ? htmlspecialchars($infoMateria['nombre_mostrar']) : $infoMateria['nombre_mostrar'];
    
    // Agregar código si existe
    if ($infoMateria['codigo_mostrar'] && $incluirHTML) {
        $nombrePrincipal .= ' <span class="badge bg-secondary ms-1">' . htmlspecialchars($infoMateria['codigo_mostrar']) . '</span>';
    } elseif ($infoMateria['codigo_mostrar']) {
        $nombrePrincipal .= ' (' . $infoMateria['codigo_mostrar'] . ')';
    }
    
    // Mostrar materia específica si es parte de un grupo
    if ($mostrarDetalle && isset($infoMateria['es_parte_grupo']) && $infoMateria['es_parte_grupo'] == 1) {
        if ($incluirHTML) {
            $nombrePrincipal .= '<br><small class="text-muted">';
            $nombrePrincipal .= '<i class="bi bi-arrow-right"></i> ' . htmlspecialchars($infoMateria['materia_nombre']);
            if ($infoMateria['materia_codigo'] && $infoMateria['materia_codigo'] != $infoMateria['codigo_mostrar']) {
                $nombrePrincipal .= ' (' . htmlspecialchars($infoMateria['materia_codigo']) . ')';
            }
            $nombrePrincipal .= '</small>';
        } else {
            $nombrePrincipal .= ' → ' . $infoMateria['materia_nombre'];
            if ($infoMateria['materia_codigo'] && $infoMateria['materia_codigo'] != $infoMateria['codigo_mostrar']) {
                $nombrePrincipal .= ' (' . $infoMateria['materia_codigo'] . ')';
            }
        }
    }
    
    return $nombrePrincipal;
}

/**
 * Obtener todas las materias con información de grupos para un ciclo lectivo
 */
function obtenerMateriasConGrupos($db, $cicloLectivoId, $profesorId = null, $incluirContadores = false) {
    try {
        $sql = "SELECT 
                    mpc.id as materia_curso_id, 
                    m.nombre as materia_nombre, 
                    m.codigo as materia_codigo, 
                    c.anio, 
                    c.nombre as curso_nombre,
                    gm.nombre as grupo_nombre,
                    gm.codigo as grupo_codigo,
                    -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                    COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                    COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                    -- Indicador si es parte de un grupo
                    CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo";
        
        // Agregar contador de pendientes si se solicita
        if ($incluirContadores) {
            $sql .= ",
                    (SELECT COUNT(*) 
                     FROM materias_pendientes_intensificacion mpi 
                     WHERE mpi.materia_curso_id = mpc.id 
                       AND mpi.ciclo_lectivo_id = ? 
                       AND mpi.estado = 'activo') as total_pendientes";
        }
        
        $sql .= " FROM materias_por_curso mpc
                JOIN materias m ON mpc.materia_id = m.id
                JOIN cursos c ON mpc.curso_id = c.id
                LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
                LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
                WHERE c.ciclo_lectivo_id = ?";
        
        $params = [];
        if ($incluirContadores) {
            $params[] = $cicloLectivoId; // Para el subquery
        }
        $params[] = $cicloLectivoId;
        
        if ($profesorId) {
            $sql .= " AND (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?)";
            $params[] = $profesorId;
            $params[] = $profesorId;
            $params[] = $profesorId;
        }
        
        $sql .= " ORDER BY c.anio, nombre_mostrar, m.nombre";
        
        return $db->fetchAll($sql, $params);
        
    } catch (Exception $e) {
        error_log("Error en obtenerMateriasConGrupos: " . $e->getMessage());
        return [];
    }
}

/**
 * Generar option HTML para un select de materias
 */
function generarOptionMateria($materia, $valorSeleccionado = null, $mostrarAnio = true, $mostrarContadores = false) {
    $selected = ($valorSeleccionado == $materia['materia_curso_id']) ? 'selected' : '';
    
    $texto = '';
    if ($mostrarAnio) {
        $texto .= $materia['anio'] . '° Año - ';
    }
    
    $texto .= htmlspecialchars($materia['nombre_mostrar']);
    
    // Mostrar materia específica si es parte de un grupo
    if (isset($materia['es_parte_grupo']) && $materia['es_parte_grupo'] == 1) {
        $texto .= ' → ' . htmlspecialchars($materia['materia_nombre']);
    }
    
    // Mostrar código si existe
    if ($materia['codigo_mostrar']) {
        $texto .= ' (' . htmlspecialchars($materia['codigo_mostrar']) . ')';
    }
    
    // Mostrar contadores si se especifica
    if ($mostrarContadores && isset($materia['total_pendientes'])) {
        $texto .= ' - ' . $materia['total_pendientes'] . ' estudiante(s) pendiente(s)';
    }
    
    return "<option value=\"{$materia['materia_curso_id']}\" {$selected}>{$texto}</option>";
}

/**
 * Verificar si una materia pertenece a un grupo
 */
function materiaEsParteDeGrupo($db, $materiaCursoId) {
    try {
        $grupo = $db->fetchOne(
            "SELECT gm.nombre 
             FROM materias_grupo mg
             JOIN grupos_materias gm ON mg.grupo_id = gm.id
             WHERE mg.materia_curso_id = ? AND mg.activo = 1 AND gm.activo = 1",
            [$materiaCursoId]
        );
        
        return $grupo !== false;
    } catch (Exception $e) {
        error_log("Error en materiaEsParteDeGrupo: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtener lista de materias con pendientes para un profesor específico
 * Función especializada para la página de calificación de materias pendientes
 */
function obtenerMateriasConPendientesParaProfesor($db, $profesorId, $cicloLectivoId) {
    try {
        return $db->fetchAll(
            "SELECT DISTINCT 
                mpc.id as materia_curso_id,
                m.nombre as materia_nombre,
                m.codigo as materia_codigo,
                c.anio,
                c.nombre as curso_nombre,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                -- Indicador si es parte de un grupo
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo,
                COUNT(mpi.id) as total_pendientes
             FROM materias_por_curso mpc
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             JOIN materias_pendientes_intensificacion mpi ON mpc.id = mpi.materia_curso_id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?) 
               AND mpi.ciclo_lectivo_id = ? 
               AND mpi.estado = 'activo'
             GROUP BY mpc.id, m.nombre, m.codigo, c.anio, c.nombre, gm.nombre, gm.codigo
             ORDER BY c.anio, nombre_mostrar",
            [$profesorId, $profesorId, $profesorId, $cicloLectivoId]
        );
    } catch (Exception $e) {
        error_log("Error en obtenerMateriasConPendientesParaProfesor: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtener información detallada de una materia pendiente con datos del grupo
 */
function obtenerDetalleMateriaConGrupo($db, $materiaCursoId, $profesorId = null, $cicloLectivoId = null) {
    try {
        $sql = "SELECT 
                mpc.*, 
                m.nombre as materia_nombre, 
                m.codigo as materia_codigo, 
                c.anio as curso_anio, 
                c.nombre as curso_nombre,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                -- Indicador si es parte de un grupo
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM materias_por_curso mpc
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE mpc.id = ?";
        
        $params = [$materiaCursoId];
        
        // Agregar filtro de profesor si se especifica
        if ($profesorId) {
            $sql .= " AND (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?)";
            $params[] = $profesorId;
            $params[] = $profesorId;
            $params[] = $profesorId;
        }
        
        // Agregar filtro de ciclo lectivo si se especifica
        if ($cicloLectivoId) {
            $sql .= " AND c.ciclo_lectivo_id = ?";
            $params[] = $cicloLectivoId;
        }
        
        return $db->fetchOne($sql, $params);
        
    } catch (Exception $e) {
        error_log("Error en obtenerDetalleMateriaConGrupo: " . $e->getMessage());
        return null;
    }
}

/**
 * Formatear nombre de materia para selectores (sin HTML)
 */
function formatearNombreMateriaParaSelect($materia, $incluirAnio = true) {
    $texto = '';
    
    if ($incluirAnio && isset($materia['anio'])) {
        $texto .= $materia['anio'] . '° Año - ';
    }
    
    $texto .= $materia['nombre_mostrar'];
    
    // Mostrar materia específica si es parte de un grupo
    if (isset($materia['es_parte_grupo']) && $materia['es_parte_grupo'] == 1) {
        $texto .= ' → ' . $materia['materia_nombre'];
    }
    
    // Mostrar código si existe
    if ($materia['codigo_mostrar']) {
        $texto .= ' (' . $materia['codigo_mostrar'] . ')';
    }
    
    return $texto;
}

/**
 * Obtener todas las materias disponibles para asignar como pendientes
 */
function obtenerMateriasParaPendientes($db, $cicloLectivoId) {
    try {
        return $db->fetchAll(
            "SELECT 
                mpc.id as materia_curso_id, 
                m.nombre as materia_nombre, 
                m.codigo as materia_codigo, 
                c.anio, 
                c.nombre as curso_nombre,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                -- Indicador si es parte de un grupo
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM materias_por_curso mpc
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE c.ciclo_lectivo_id = ?
             ORDER BY c.anio, nombre_mostrar, m.nombre",
            [$cicloLectivoId]
        );
    } catch (Exception $e) {
        error_log("Error en obtenerMateriasParaPendientes: " . $e->getMessage());
        return [];
    }
}

/**
 * Verificar si las tablas de grupos existen en la base de datos
 */
function verificarTablasGruposExisten($db) {
    try {
        $tablaGrupos = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='grupos_materias'");
        $tablaRelacion = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='materias_grupo'");
        
        return $tablaGrupos && $tablaRelacion;
    } catch (Exception $e) {
        error_log("Error verificando tablas de grupos: " . $e->getMessage());
        return false;
    }
}
?>
