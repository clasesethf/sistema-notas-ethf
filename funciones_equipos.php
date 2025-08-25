<?php
/**
 * funciones_equipos.php - Funciones auxiliares para manejo de equipos docentes
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

/**
 * Verificar si un profesor tiene acceso a una materia (individual o en equipo)
 */
function verificarAccesoMateria($db, $profesorId, $materiaCursoId) {
    try {
        $acceso = $db->fetchOne(
            "SELECT mp.id, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                    m.nombre as materia_nombre, m.codigo as materia_codigo,
                    c.nombre as curso_nombre, c.anio as curso_anio
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)",
            [$materiaCursoId, $profesorId, $profesorId, $profesorId]
        );
        
        return $acceso;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Obtener información completa del equipo docente de una materia
 */
function obtenerEquipoDocente($db, $materiaCursoId) {
    try {
        $equipoData = $db->fetchOne(
            "SELECT mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                    p1.nombre as profesor_1_nombre, p1.apellido as profesor_1_apellido, p1.email as profesor_1_email,
                    p2.nombre as profesor_2_nombre, p2.apellido as profesor_2_apellido, p2.email as profesor_2_email,
                    p3.nombre as profesor_3_nombre, p3.apellido as profesor_3_apellido, p3.email as profesor_3_email
             FROM materias_por_curso mp
             LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
             LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
             LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        if (!$equipoData) {
            return [];
        }
        
        $equipo = [];
        
        // Profesor 1
        if ($equipoData['profesor_id']) {
            $equipo[] = [
                'id' => $equipoData['profesor_id'],
                'posicion' => 1,
                'nombre_completo' => trim($equipoData['profesor_1_apellido'] . ', ' . $equipoData['profesor_1_nombre']),
                'apellido' => $equipoData['profesor_1_apellido'],
                'nombre' => $equipoData['profesor_1_nombre'],
                'email' => $equipoData['profesor_1_email']
            ];
        }
        
        // Profesor 2
        if ($equipoData['profesor_id_2']) {
            $equipo[] = [
                'id' => $equipoData['profesor_id_2'],
                'posicion' => 2,
                'nombre_completo' => trim($equipoData['profesor_2_apellido'] . ', ' . $equipoData['profesor_2_nombre']),
                'apellido' => $equipoData['profesor_2_apellido'],
                'nombre' => $equipoData['profesor_2_nombre'],
                'email' => $equipoData['profesor_2_email']
            ];
        }
        
        // Profesor 3
        if ($equipoData['profesor_id_3']) {
            $equipo[] = [
                'id' => $equipoData['profesor_id_3'],
                'posicion' => 3,
                'nombre_completo' => trim($equipoData['profesor_3_apellido'] . ', ' . $equipoData['profesor_3_nombre']),
                'apellido' => $equipoData['profesor_3_apellido'],
                'nombre' => $equipoData['profesor_3_nombre'],
                'email' => $equipoData['profesor_3_email']
            ];
        }
        
        return $equipo;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Verificar si una materia tiene equipo docente (más de un profesor)
 */
function esEquipoDocente($db, $materiaCursoId) {
    try {
        $profesores = $db->fetchOne(
            "SELECT 
                CASE WHEN profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                CASE WHEN profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                CASE WHEN profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END as total_profesores
             FROM materias_por_curso WHERE id = ?",
            [$materiaCursoId]
        );
        
        return $profesores && $profesores['total_profesores'] > 1;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Obtener todas las materias accesibles para un profesor (individual o en equipo)
 */
function obtenerMateriasProfesor($db, $profesorId, $cicloLectivoId = null) {
    try {
        $whereClause = '';
        $params = [$profesorId, $profesorId, $profesorId];
        
        if ($cicloLectivoId) {
            $whereClause = ' AND c.ciclo_lectivo_id = ?';
            $params[] = $cicloLectivoId;
        }
        
        $materias = $db->fetchAll(
            "SELECT mp.id as materia_curso_id, mp.materia_id, mp.curso_id, mp.requiere_subgrupos,
                    m.nombre as materia_nombre, m.codigo as materia_codigo,
                    c.nombre as curso_nombre, c.anio as curso_anio,
                    mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                    CASE 
                        WHEN mp.profesor_id = ? THEN 1
                        WHEN mp.profesor_id_2 = ? THEN 2
                        WHEN mp.profesor_id_3 = ? THEN 3
                        ELSE 0
                    END as posicion_profesor,
                    (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) $whereClause
             ORDER BY c.anio, m.nombre",
            array_merge([$profesorId, $profesorId, $profesorId], $params)
        );
        
        return $materias;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Registrar actividad de equipo docente
 */
function registrarActividadEquipo($db, $materiaCursoId, $profesorId, $accion, $detalles = '') {
    try {
        // Obtener información del equipo
        $equipo = obtenerEquipoDocente($db, $materiaCursoId);
        $esEquipo = count($equipo) > 1;
        
        if ($esEquipo) {
            // Registrar en log de actividades si existe la tabla
            $db->query(
                "INSERT INTO actividades_equipo_docente (materia_curso_id, profesor_id, accion, detalles, fecha) 
                 VALUES (?, ?, ?, ?, datetime('now')) 
                 ON CONFLICT DO NOTHING",
                [$materiaCursoId, $profesorId, $accion, $detalles]
            );
        }
        
        return true;
    } catch (Exception $e) {
        // Fallar silenciosamente si la tabla no existe
        return false;
    }
}

/**
 * Verificar y crear columnas de múltiples profesores si no existen
 */
function verificarColumnasMultiplesProfesores($db) {
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
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Generar mensaje de equipo docente para mostrar en interfaces
 */
function generarMensajeEquipo($equipo, $profesorActualId) {
    if (count($equipo) <= 1) {
        return '';
    }
    
    $profesorActual = null;
    $otrosProfesores = [];
    
    foreach ($equipo as $profesor) {
        if ($profesor['id'] == $profesorActualId) {
            $profesorActual = $profesor;
        } else {
            $otrosProfesores[] = $profesor['nombre_completo'];
        }
    }
    
    if (count($otrosProfesores) > 0) {
        $mensaje = 'Trabaja en equipo con: ' . implode(', ', $otrosProfesores);
        return $mensaje;
    }
    
    return '';
}
?>