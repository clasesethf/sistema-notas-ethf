<?php
/**
 * api_obtener_contenido.php - API para obtener datos de contenido
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * NUEVO: Permite obtener información de un contenido para la duplicación
 */

header('Content-Type: application/json');
require_once 'config.php';

// Verificar que el usuario sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'profesor') {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permisos']);
    exit;
}

// Verificar parámetros
if (!isset($_GET['contenido_id']) || empty($_GET['contenido_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta ID del contenido']);
    exit;
}

$contenidoId = intval($_GET['contenido_id']);
$profesorId = $_SESSION['user_id'];

try {
    $db = Database::getInstance();
    
    // Obtener información del contenido
    $contenido = $db->fetchOne(
        "SELECT c.*, mp.profesor_id, m.nombre as materia_nombre, cur.nombre as curso_nombre
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         WHERE c.id = ? AND mp.profesor_id = ? AND c.activo = 1",
        [$contenidoId, $profesorId]
    );
    
    if (!$contenido) {
        http_response_code(404);
        echo json_encode(['error' => 'Contenido no encontrado']);
        exit;
    }
    
    // Verificar si tiene calificaciones
    $tieneCalificaciones = $db->fetchOne(
        "SELECT COUNT(*) as total FROM contenidos_calificaciones WHERE contenido_id = ?",
        [$contenidoId]
    )['total'] ?? 0;
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'contenido' => [
            'id' => $contenido['id'],
            'titulo' => $contenido['titulo'],
            'descripcion' => $contenido['descripcion'],
            'tipo_evaluacion' => $contenido['tipo_evaluacion'],
            'fecha_clase' => $contenido['fecha_clase'],
            'bimestre' => $contenido['bimestre'],
            'materia_nombre' => $contenido['materia_nombre'],
            'curso_nombre' => $contenido['curso_nombre'],
            'tiene_calificaciones' => $tieneCalificaciones > 0,
            'total_calificaciones' => $tieneCalificaciones
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno: ' . $e->getMessage()]);
}
?>