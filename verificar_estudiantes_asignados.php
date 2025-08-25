<?php
/**
 * verificar_estudiantes_asignados.php
 * Endpoint para verificar si una materia tiene estudiantes asignados y problemas
 */

require_once 'config.php';

header('Content-Type: application/json');

// Verificar permisos
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permisos']);
    exit;
}

$db = Database::getInstance();
$materiaId = isset($_GET['materia']) ? intval($_GET['materia']) : null;

if (!$materiaId) {
    echo json_encode(['error' => 'Materia no especificada']);
    exit;
}

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

try {
    // Verificar si hay estudiantes asignados
    $estudiantesAsignados = $db->fetchAll(
        "SELECT ep.*, u.nombre, u.apellido,
                c.nombre as curso_estudiante, c.id as curso_estudiante_id,
                c2.nombre as curso_materia, c2.id as curso_materia_id
         FROM estudiantes_por_materia ep
         JOIN usuarios u ON ep.estudiante_id = u.id
         JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
         JOIN cursos c ON m.curso_id = c.id
         JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
         JOIN cursos c2 ON mp.curso_id = c2.id
         WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1",
        [$materiaId, $cicloLectivoId]
    );
    
    $tieneEstudiantes = count($estudiantesAsignados) > 0;
    $problemasCorso = false;
    $estudiantesProblematicos = [];
    
    // Detectar problemas de curso
    foreach ($estudiantesAsignados as $asignado) {
        if ($asignado['curso_estudiante_id'] !== $asignado['curso_materia_id']) {
            $problemasCorso = true;
            $estudiantesProblematicos[] = [
                'apellido' => $asignado['apellido'],
                'nombre' => $asignado['nombre'],
                'curso_estudiante' => $asignado['curso_estudiante'],
                'curso_materia' => $asignado['curso_materia']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'tiene_estudiantes' => $tieneEstudiantes,
        'problemas_curso' => $problemasCorso,
        'estudiantes_problematicos' => $estudiantesProblematicos,
        'total_estudiantes' => count($estudiantesAsignados)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>