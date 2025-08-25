<?php
/**
 * obtener_materia.php - API para obtener datos de una materia
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
    
    echo json_encode([
        'success' => true,
        'materia' => $materia
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al obtener datos: ' . $e->getMessage()]);
}
?>