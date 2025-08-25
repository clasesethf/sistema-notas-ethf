<?php
/**
 * obtener_materia_por_curso.php - API para obtener datos de una materia por ID de materia_curso
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
    if (!isset($_GET['materia_curso_id']) || !is_numeric($_GET['materia_curso_id'])) {
        enviarRespuesta(false, 'ID de materia_curso inválido');
    }
    
    $materiaCursoId = intval($_GET['materia_curso_id']);
    
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Obtener datos de la materia a través de materia_curso
    $materia = $db->fetchOne(
        "SELECT m.id, m.nombre, m.codigo 
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    if (!$materia) {
        enviarRespuesta(false, 'Materia no encontrada');
    }
    
    // Log para debugging
    error_log("obtener_materia_por_curso.php - MateriaCurso ID: $materiaCursoId, Materia encontrada: " . json_encode($materia));
    
    enviarRespuesta(true, 'Materia obtenida correctamente', ['materia' => $materia]);
    
} catch (Exception $e) {
    error_log("Error en obtener_materia_por_curso.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al obtener datos: ' . $e->getMessage());
}
?>