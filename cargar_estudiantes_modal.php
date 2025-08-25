<?php
/**
 * cargar_estudiantes_modal.php - Cargar estudiantes para el modal de gestión
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
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    if (!$cicloLectivoId) {
        enviarRespuesta(false, 'No hay ciclo lectivo activo');
    }
    
    // Obtener información de la materia
    $materia = $db->fetchOne(
        "SELECT mp.id, mp.curso_id, m.nombre, m.codigo, c.nombre as curso_nombre
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    if (!$materia) {
        enviarRespuesta(false, 'Materia no encontrada');
    }
    
    // Obtener configuración de subgrupos
    $configuracion = $db->fetchOne(
        "SELECT * FROM configuracion_subgrupos 
         WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
        [$materiaCursoId, $cicloLectivoId]
    );
    
    if (!$configuracion) {
        enviarRespuesta(false, 'No se encontró configuración de subgrupos para esta materia');
    }
    
    // Obtener estudiantes del curso
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.activo = 1
         ORDER BY u.apellido, u.nombre",
        [$materia['curso_id']]
    );
    
    // Obtener estudiantes ya asignados con más detalles
    $estudiantesAsignados = $db->fetchAll(
        "SELECT ep.id, ep.estudiante_id, ep.subgrupo, ep.periodo_inicio, ep.periodo_fin,
                u.nombre, u.apellido, u.dni,
                c.nombre as curso_estudiante, c.id as curso_estudiante_id,
                mp.curso_id as curso_materia_id
         FROM estudiantes_por_materia ep
         JOIN usuarios u ON ep.estudiante_id = u.id
         JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
         JOIN cursos c ON m.curso_id = c.id
         JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
         WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
         ORDER BY ep.subgrupo, u.apellido, u.nombre",
        [$materiaCursoId, $cicloLectivoId]
    );
    
    // Debug: Log de estudiantes asignados
    error_log("Estudiantes asignados encontrados: " . json_encode($estudiantesAsignados));
    
    // Log para debugging
    error_log("cargar_estudiantes_modal.php - Materia ID: $materiaCursoId, Estudiantes: " . count($estudiantes) . ", Asignados: " . count($estudiantesAsignados));
    
    enviarRespuesta(true, 'Estudiantes cargados correctamente', [
        'materia' => $materia,
        'configuracion' => $configuracion,
        'estudiantes' => $estudiantes,
        'estudiantes_asignados' => $estudiantesAsignados
    ]);
    
} catch (Exception $e) {
    error_log("Error en cargar_estudiantes_modal.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al cargar estudiantes: ' . $e->getMessage());
}
?>