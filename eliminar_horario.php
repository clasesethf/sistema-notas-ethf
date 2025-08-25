<?php
/**
 * eliminar_horario.php - Eliminación de horarios via AJAX
 */

require_once 'config.php';

// Verificar permisos
session_start();
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sin permisos']);
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Obtener datos JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['id']) || !is_numeric($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Eliminar horario
    $result = $db->query("DELETE FROM horarios WHERE id = ?", [intval($input['id'])]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Horario eliminado correctamente']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar horario']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>
