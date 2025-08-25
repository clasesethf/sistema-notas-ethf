<?php
/**
 * obtener_curso_estudiante.php - API para obtener materias del curso actual de un estudiante
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
    
    // Verificar que se haya enviado el ID del estudiante
    if (!isset($_GET['estudiante_id']) || !is_numeric($_GET['estudiante_id'])) {
        enviarRespuesta(false, 'ID de estudiante inválido o faltante');
    }
    
    $estudianteId = intval($_GET['estudiante_id']);
    
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    if (!$cicloActivo) {
        enviarRespuesta(false, 'No hay ciclo lectivo activo');
    }
    $cicloLectivoId = $cicloActivo['id'];
    
    // Verificar que el estudiante existe
    $estudiante = $db->fetchOne(
        "SELECT id, nombre, apellido, dni FROM usuarios WHERE id = ? AND tipo = 'estudiante'",
        [$estudianteId]
    );
    
    if (!$estudiante) {
        enviarRespuesta(false, 'Estudiante no encontrado');
    }
    
    // Obtener el curso actual del estudiante (matrícula activa)
    $matricula = $db->fetchOne(
        "SELECT c.id as curso_id, c.nombre as curso_nombre, c.anio
         FROM matriculas m
         JOIN cursos c ON m.curso_id = c.id
         WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?
         ORDER BY m.fecha_matriculacion DESC
         LIMIT 1",
        [$estudianteId, $cicloLectivoId]
    );
    
    if (!$matricula) {
        enviarRespuesta(false, 'El estudiante no tiene matrícula activa en el ciclo lectivo actual');
    }
    
    // Obtener las materias del curso actual del estudiante que puede liberar
    $materias = $db->fetchAll(
        "SELECT mp.id, m.nombre as materia_nombre, m.codigo as materia_codigo,
                u.apellido as profesor_apellido, u.nombre as profesor_nombre
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id AND u.tipo = 'profesor'
         WHERE mp.curso_id = ?
         ORDER BY m.nombre",
        [$matricula['curso_id']]
    );
    
    // Verificar si el estudiante ya tiene recursados activos y excluir esas materias liberadas
    $recursadosActivos = $db->fetchAll(
        "SELECT materia_liberada_id 
         FROM materias_recursado 
         WHERE estudiante_id = ? AND ciclo_lectivo_id = ? AND estado = 'activo' AND materia_liberada_id IS NOT NULL",
        [$estudianteId, $cicloLectivoId]
    );
    
    $materiasYaLiberadas = array_column($recursadosActivos, 'materia_liberada_id');
    
    // Filtrar materias que ya están liberadas
    $materiasDisponibles = array_filter($materias, function($materia) use ($materiasYaLiberadas) {
        return !in_array($materia['id'], $materiasYaLiberadas);
    });
    
    // Reindexar el array
    $materiasDisponibles = array_values($materiasDisponibles);
    
    // Log para debugging
    error_log("obtener_curso_estudiante.php - Estudiante ID: $estudianteId, Curso: " . $matricula['curso_nombre'] . ", Materias disponibles: " . count($materiasDisponibles));
    
    enviarRespuesta(true, 'Materias obtenidas correctamente', [
        'estudiante' => $estudiante,
        'curso' => $matricula,
        'materias' => $materiasDisponibles
    ]);
    
} catch (Exception $e) {
    error_log("Error en obtener_curso_estudiante.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al obtener datos: ' . $e->getMessage());
}
?>