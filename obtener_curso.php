<?php
/**
 * obtener_curso.php - API para obtener datos de un curso específico
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
    
    // Verificar que se haya enviado el ID del curso
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        enviarRespuesta(false, 'ID de curso inválido o faltante');
    }
    
    $cursoId = intval($_GET['id']);
    
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Obtener datos del curso
    $curso = $db->fetchOne(
        "SELECT id, nombre, anio, ciclo_lectivo_id FROM cursos WHERE id = ?",
        [$cursoId]
    );
    
    if (!$curso) {
        enviarRespuesta(false, 'Curso no encontrado');
    }
    
    // Log para debugging
    error_log("obtener_curso.php - Curso ID: $cursoId, Curso encontrado: " . $curso['nombre']);
    
    enviarRespuesta(true, 'Curso obtenido correctamente', [
        'curso' => $curso
    ]);
    
} catch (Exception $e) {
    error_log("Error en obtener_curso.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al obtener datos: ' . $e->getMessage());
}
?>