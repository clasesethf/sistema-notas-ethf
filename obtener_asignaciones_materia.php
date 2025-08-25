<?php
/**
 * obtener_asignaciones_materia.php - API para obtener asignaciones de una materia
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
    
    // Obtener datos de la materia
    $materia = $db->fetchOne(
        "SELECT id, nombre, codigo FROM materias WHERE id = ?",
        [$materiaId]
    );
    
    if (!$materia) {
        echo json_encode(['success' => false, 'message' => 'Materia no encontrada']);
        exit;
    }
    
    // Obtener asignaciones de la materia
    $asignaciones = $db->fetchAll(
        "SELECT mp.id, 
                c.nombre as curso_nombre, 
                c.anio as curso_anio,
                cl.anio as ciclo_anio,
                COALESCE(u.apellido || ', ' || u.nombre, 'Sin asignar') as profesor_nombre,
                COUNT(cal.id) as total_calificaciones
         FROM materias_por_curso mp
         JOIN cursos c ON mp.curso_id = c.id
         JOIN ciclos_lectivos cl ON c.ciclo_lectivo_id = cl.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id AND u.tipo = 'profesor'
         LEFT JOIN calificaciones cal ON mp.id = cal.materia_curso_id
         WHERE mp.materia_id = ?
         GROUP BY mp.id, c.nombre, c.anio, cl.anio, u.apellido, u.nombre
         ORDER BY cl.anio DESC, c.anio, c.nombre",
        [$materiaId]
    );
    
    echo json_encode([
        'success' => true,
        'materia' => $materia,
        'asignaciones' => $asignaciones
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener datos: ' . $e->getMessage()]);
}
?>