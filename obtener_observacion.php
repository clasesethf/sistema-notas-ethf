<?php
/**
 * obtener_observacion.php - API para obtener datos de una observación predefinida
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Verificar permisos
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sin permisos']);
    exit;
}

// Verificar que se proporcione un ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$observacionId = intval($_GET['id']);

// Obtener conexión a la base de datos
$db = Database::getInstance();

try {
    // Obtener datos de la observación
    $observacion = $db->fetchOne(
        "SELECT * FROM observaciones_predefinidas WHERE id = ?",
        [$observacionId]
    );
    
    if ($observacion) {
        // Configurar cabecera JSON
        header('Content-Type: application/json');
        
        echo json_encode([
            'success' => true,
            'observacion' => $observacion
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Observación no encontrada'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
}
?>