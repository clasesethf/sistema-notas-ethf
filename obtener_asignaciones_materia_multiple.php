<?php
/**
 * obtener_asignaciones_materia_multiple.php - API para obtener asignaciones con múltiples profesores
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

header('Content-Type: application/json');

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar que el usuario esté autenticado
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Verificar permisos
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    echo json_encode(['success' => false, 'message' => 'Sin permisos']);
    exit;
}

// Verificar que se haya enviado el ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de materia inválido']);
    exit;
}

$materiaId = intval($_GET['id']);

try {
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Verificar y agregar columnas para múltiples profesores si no existen
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
        // Las columnas ya existen, continuar
    }
    
    // Obtener datos de la materia
    $materia = $db->fetchOne(
        "SELECT id, nombre, codigo FROM materias WHERE id = ?",
        [$materiaId]
    );
    
    if (!$materia) {
        echo json_encode(['success' => false, 'message' => 'Materia no encontrada']);
        exit;
    }
    
    // Obtener asignaciones de la materia con múltiples profesores
    $asignaciones = $db->fetchAll(
        "SELECT mp.id, 
                c.nombre as curso_nombre, 
                c.anio as curso_anio,
                cl.anio as ciclo_anio,
                mp.profesor_id,
                mp.profesor_id_2,
                mp.profesor_id_3,
                p1.apellido as profesor_1_apellido,
                p1.nombre as profesor_1_nombre,
                p2.apellido as profesor_2_apellido,
                p2.nombre as profesor_2_nombre,
                p3.apellido as profesor_3_apellido,
                p3.nombre as profesor_3_nombre,
                COUNT(cal.id) as total_calificaciones
         FROM materias_por_curso mp
         JOIN cursos c ON mp.curso_id = c.id
         JOIN ciclos_lectivos cl ON c.ciclo_lectivo_id = cl.id
         LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
         LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
         LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
         LEFT JOIN calificaciones cal ON mp.id = cal.materia_curso_id
         WHERE mp.materia_id = ?
         GROUP BY mp.id, c.nombre, c.anio, cl.anio, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                  p1.apellido, p1.nombre, p2.apellido, p2.nombre, p3.apellido, p3.nombre
         ORDER BY cl.anio DESC, c.anio, c.nombre",
        [$materiaId]
    );
    
    // Procesar las asignaciones para incluir información de profesores
    $asignacionesProcesadas = [];
    foreach ($asignaciones as $asignacion) {
        $profesores = [];
        
        // Profesor 1
        if ($asignacion['profesor_id']) {
            $profesores[] = $asignacion['profesor_1_apellido'] . ', ' . $asignacion['profesor_1_nombre'];
        }
        
        // Profesor 2
        if ($asignacion['profesor_id_2']) {
            $profesores[] = $asignacion['profesor_2_apellido'] . ', ' . $asignacion['profesor_2_nombre'];
        }
        
        // Profesor 3
        if ($asignacion['profesor_id_3']) {
            $profesores[] = $asignacion['profesor_3_apellido'] . ', ' . $asignacion['profesor_3_nombre'];
        }
        
        $asignacionesProcesadas[] = [
            'id' => $asignacion['id'],
            'curso_nombre' => $asignacion['curso_nombre'],
            'curso_anio' => $asignacion['curso_anio'],
            'ciclo_anio' => $asignacion['ciclo_anio'],
            'profesores' => $profesores,
            'total_profesores' => count($profesores),
            'total_calificaciones' => $asignacion['total_calificaciones']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'materia' => $materia,
        'asignaciones' => $asignacionesProcesadas
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener datos: ' . $e->getMessage()]);
}
?>