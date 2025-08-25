<?php
/**
 * procesar_estudiantes_modal.php - Procesar cambios de estudiantes desde el modal
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
    
    // Leer datos JSON del cuerpo de la petición
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        enviarRespuesta(false, 'Datos JSON inválidos');
    }
    
    $accion = $data['accion'] ?? '';
    
    // Obtener conexión a la base de datos
    $db = Database::getInstance();
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    if (!$cicloLectivoId) {
        enviarRespuesta(false, 'No hay ciclo lectivo activo');
    }
    
    switch ($accion) {
        case 'asignar_estudiante':
            $estudianteId = intval($data['estudiante_id']);
            $materiaCursoId = intval($data['materia_curso_id']);
            $subgrupo = intval($data['subgrupo']);
            
            if (!$estudianteId || !$materiaCursoId || !$subgrupo) {
                enviarRespuesta(false, 'Datos incompletos para asignación');
            }
            
            // Verificar que el estudiante no esté ya asignado
            $yaAsignado = $db->fetchOne(
                "SELECT id FROM estudiantes_por_materia 
                 WHERE materia_curso_id = ? AND estudiante_id = ? AND ciclo_lectivo_id = ? AND activo = 1",
                [$materiaCursoId, $estudianteId, $cicloLectivoId]
            );
            
            if ($yaAsignado) {
                enviarRespuesta(false, 'El estudiante ya está asignado a un subgrupo');
            }
            
            // Verificar que el estudiante pertenezca al curso correcto
            $cursoMateria = $db->fetchOne(
                "SELECT mp.curso_id 
                 FROM materias_por_curso mp 
                 WHERE mp.id = ?",
                [$materiaCursoId]
            );
            
            $cursoEstudiante = $db->fetchOne(
                "SELECT m.curso_id 
                 FROM matriculas m 
                 WHERE m.estudiante_id = ? AND m.estado = 'activo'",
                [$estudianteId]
            );
            
            if (!$cursoMateria || !$cursoEstudiante || $cursoMateria['curso_id'] != $cursoEstudiante['curso_id']) {
                enviarRespuesta(false, 'El estudiante no pertenece al curso de esta materia');
            }
            
            // Obtener configuración para determinar período
            $config = $db->fetchOne(
                "SELECT * FROM configuracion_subgrupos 
                 WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
                [$materiaCursoId, $cicloLectivoId]
            );
            
            $periodo = 'anual';
            if ($config) {
                if ($config['rotacion_automatica']) {
                    $periodo = '1trim'; // Empezar en primer trimestre para materias con rotación
                } else {
                    $periodo = 'anual'; // Todo el año para materias sin rotación
                }
            }
            
            // Insertar asignación
            $db->query(
                "INSERT INTO estudiantes_por_materia 
                 (materia_curso_id, estudiante_id, ciclo_lectivo_id, subgrupo, periodo_inicio, activo)
                 VALUES (?, ?, ?, ?, ?, 1)",
                [$materiaCursoId, $estudianteId, $cicloLectivoId, "Subgrupo $subgrupo", $periodo]
            );
            
            // Obtener información del estudiante para el mensaje
            $estudiante = $db->fetchOne(
                "SELECT nombre, apellido FROM usuarios WHERE id = ?",
                [$estudianteId]
            );
            
            $nombreEstudiante = $estudiante ? $estudiante['apellido'] . ', ' . $estudiante['nombre'] : "ID $estudianteId";
            
            enviarRespuesta(true, "Estudiante $nombreEstudiante asignado al Subgrupo $subgrupo");
            break;
            
        case 'desasignar_estudiante':
            $asignacionId = intval($data['asignacion_id']);
            
            if (!$asignacionId) {
                enviarRespuesta(false, 'ID de asignación inválido');
            }
            
            // Eliminar asignación
            $db->query(
                "DELETE FROM estudiantes_por_materia WHERE id = ?",
                [$asignacionId]
            );
            
            enviarRespuesta(true, 'Estudiante desasignado correctamente');
            break;
            
        default:
            enviarRespuesta(false, 'Acción no reconocida');
    }
    
} catch (Exception $e) {
    error_log("Error en procesar_estudiantes_modal.php: " . $e->getMessage());
    enviarRespuesta(false, 'Error al procesar solicitud: ' . $e->getMessage());
}
?>