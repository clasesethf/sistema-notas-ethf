<?php
/**
 * obtener_estudiantes_curso.php - API para obtener estudiantes de un curso
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
    
    // Obtener datos del curso
    $curso = $db->fetchOne(
        "SELECT id, nombre, anio FROM cursos WHERE id = ?",
        [$cursoId]
    );
    
    if (!$curso) {
        enviarRespuesta(false, 'Curso no encontrado');
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    if (!$cicloActivo) {
        enviarRespuesta(false, 'No hay ciclo lectivo activo');
    }
    $cicloLectivoId = $cicloActivo['id'];
    
    // Obtener estudiantes regulares (matriculados en el curso)
    $estudiantesRegulares = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, m.estado, m.fecha_matriculacion
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         WHERE m.curso_id = ? AND u.tipo = 'estudiante'
         ORDER BY u.apellido, u.nombre",
        [$cursoId]
    );
    
    // Obtener estudiantes recursando materias de este curso
    $estudiantesRecursando = $db->fetchAll(
        "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                mat.nombre as materia_nombre, mat.codigo as materia_codigo,
                c_actual.anio as anio_actual, c_actual.nombre as curso_actual
         FROM usuarios u
         JOIN materias_recursado mr ON u.id = mr.estudiante_id
         JOIN materias_por_curso mp ON mr.materia_curso_id = mp.id
         JOIN materias mat ON mp.materia_id = mat.id
         JOIN matriculas m_actual ON u.id = m_actual.estudiante_id AND m_actual.estado = 'activo'
         JOIN cursos c_actual ON m_actual.curso_id = c_actual.id
         WHERE mp.curso_id = ? AND mr.ciclo_lectivo_id = ? AND mr.estado = 'activo'
         ORDER BY u.apellido, u.nombre",
        [$cursoId, $cicloLectivoId]
    );
    
    // Log para debugging
    error_log("obtener_estudiantes_curso.php - Curso ID: $cursoId, Regulares: " . count($estudiantesRegulares) . ", Recursando: " . count($estudiantesRecursando));
    
    enviarRespuesta(true, 'Estudiantes obtenidos correctamente', [
        'curso' => $curso,
        'estudiantes_regulares' => $estudiantesRegulares,
        'estudiantes_recursando' => $estudiantesRecursando
    ]);
    
} catch (Exception $e) {
    error_log("Error en obtener_estudiantes_curso.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al obtener datos: ' . $e->getMessage());
}
?>