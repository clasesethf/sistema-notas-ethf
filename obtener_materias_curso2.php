<?php
/**
 * obtener_materias_curso.php - API para obtener materias de un curso específico
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
    if (!isset($_GET['curso_id']) || !is_numeric($_GET['curso_id'])) {
        enviarRespuesta(false, 'ID de curso inválido o faltante');
    }
    
    $cursoId = intval($_GET['curso_id']);
    
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Verificar que el curso existe
    $curso = $db->fetchOne(
        "SELECT id, nombre, anio FROM cursos WHERE id = ?",
        [$cursoId]
    );
    
    if (!$curso) {
        enviarRespuesta(false, 'Curso no encontrado');
    }
    
    // Obtener las materias del curso
    $materias = $db->fetchAll(
        "SELECT mp.id, m.nombre as materia_nombre, m.codigo as materia_codigo,
                u.apellido as profesor_apellido, u.nombre as profesor_nombre,
                COALESCE(u.apellido || ', ' || u.nombre, 'Sin asignar') as profesor_nombre_completo
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id AND u.tipo = 'profesor'
         WHERE mp.curso_id = ?
         ORDER BY m.nombre",
        [$cursoId]
    );
    
    // Formatear datos para el JavaScript
    $materiasFormateadas = [];
    foreach ($materias as $materia) {
        $materiasFormateadas[] = [
            'id' => $materia['id'],
            'materia_nombre' => $materia['materia_nombre'],
            'materia_codigo' => $materia['materia_codigo'],
            'profesor_nombre' => $materia['profesor_nombre_completo']
        ];
    }
    
    // Log para debugging
    error_log("obtener_materias_curso.php - Curso ID: $cursoId, Materias encontradas: " . count($materiasFormateadas));
    
    enviarRespuesta(true, 'Materias obtenidas correctamente', [
        'curso' => $curso,
        'materias' => $materiasFormateadas
    ]);
    
} catch (Exception $e) {
    error_log("Error en obtener_materias_curso.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al obtener datos: ' . $e->getMessage());
}
?>