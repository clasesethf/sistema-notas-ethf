<?php
/**
 * obtener_materias_curso.php - Obtener materias de un curso para importación
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

header('Content-Type: application/json');

require_once 'config.php';

// Verificar que el usuario esté logueado y tenga permisos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$cursoId = intval($_GET['curso_id'] ?? 0);

if ($cursoId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de curso inválido']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Obtener materias del curso con información del profesor
    $materias = $db->fetchAll(
        "SELECT mp.id, m.nombre, m.codigo, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                u1.apellido as prof1_apellido, u1.nombre as prof1_nombre
         FROM materias_por_curso mp 
         JOIN materias m ON mp.materia_id = m.id 
         LEFT JOIN usuarios u1 ON mp.profesor_id = u1.id
         WHERE mp.curso_id = ? 
         ORDER BY m.nombre",
        [$cursoId]
    );
    
    // Agregar información adicional a cada materia
    foreach ($materias as &$materia) {
        $materia['tiene_profesor'] = !empty($materia['profesor_id']);
        $materia['nombre_profesor'] = $materia['tiene_profesor'] ? 
            $materia['prof1_apellido'] . ', ' . $materia['prof1_nombre'] : 
            'Sin asignar';
    }
    
    echo json_encode([
        'success' => true,
        'materias' => $materias,
        'total' => count($materias)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>