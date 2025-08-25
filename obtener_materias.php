<?php
/**
 * obtener_materia.php - API para obtener datos de una materia (CORREGIDO)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

header('Content-Type: application/json; charset=utf-8');

// Función para enviar respuesta JSON y terminar
function enviarRespuesta($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Incluir config.php para la conexión a la base de datos
    if (!file_exists('config.php')) {
        enviarRespuesta(false, 'Archivo de configuración no encontrado');
    }
    
    require_once 'config.php';
    
    // Verificar que el usuario esté autenticado
    if (!isset($_SESSION['user_id'])) {
        enviarRespuesta(false, 'No autorizado');
    }
    
    // Verificar permisos
    if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
        enviarRespuesta(false, 'Sin permisos');
    }
    
    // Verificar que se haya enviado el ID
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        enviarRespuesta(false, 'ID de materia inválido');
    }
    
    $materiaId = intval($_GET['id']);
    
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Obtener datos de la materia
    $materia = $db->fetchOne(
        "SELECT id, nombre, codigo FROM materias WHERE id = ?",
        [$materiaId]
    );
    
    if (!$materia) {
        enviarRespuesta(false, 'Materia no encontrada');
    }
    
    // Log para debugging
    error_log("obtener_materia.php - ID solicitado: $materiaId, Materia encontrada: " . json_encode($materia));
    
    enviarRespuesta(true, 'Materia obtenida correctamente', ['materia' => $materia]);
    
} catch (Exception $e) {
    error_log("Error en obtener_materia.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al obtener datos: ' . $e->getMessage());
}
?>