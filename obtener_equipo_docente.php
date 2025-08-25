<?php
/**
 * obtener_equipo_docente.php - Obtener información del equipo docente
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

header('Content-Type: application/json');

require_once 'config.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Verificar que sea profesor
if ($_SESSION['user_type'] !== 'profesor') {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos']);
    exit;
}

$materiaCursoId = intval($_GET['materia_curso_id'] ?? 0);

if ($materiaCursoId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de materia inválido']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Verificar que el profesor actual tenga acceso a esta materia
    $profesorId = $_SESSION['user_id'];
    $accesoMateria = $db->fetchOne(
        "SELECT mp.id FROM materias_por_curso mp 
         WHERE mp.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)",
        [$materiaCursoId, $profesorId, $profesorId, $profesorId]
    );
    
    if (!$accesoMateria) {
        echo json_encode(['success' => false, 'message' => 'No tiene acceso a esta materia']);
        exit;
    }
    
    // Obtener información de la materia
    $materiaInfo = $db->fetchOne(
        "SELECT m.nombre, m.codigo, c.nombre as curso_nombre, c.anio
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    // Obtener equipo docente
    $equipoDocente = $db->fetchOne(
        "SELECT mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                p1.nombre as profesor_1_nombre, p1.apellido as profesor_1_apellido, p1.telefono as profesor_1_telefono,
                p2.nombre as profesor_2_nombre, p2.apellido as profesor_2_apellido, p2.telefono as profesor_2_telefono,
                p3.nombre as profesor_3_nombre, p3.apellido as profesor_3_apellido, p3.telefono as profesor_3_telefono
         FROM materias_por_curso mp
         LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
         LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
         LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    $equipo = [];
    
    // Profesor 1
    if ($equipoDocente['profesor_id']) {
        $equipo[] = [
            'id' => $equipoDocente['profesor_id'],
            'posicion' => 1,
            'nombre' => trim($equipoDocente['profesor_1_apellido'] . ', ' . $equipoDocente['profesor_1_nombre']),
            'telefono' => $equipoDocente['profesor_1_telefono'],
            'es_actual' => $equipoDocente['profesor_id'] == $profesorId
        ];
    }
    
    // Profesor 2
    if ($equipoDocente['profesor_id_2']) {
        $equipo[] = [
            'id' => $equipoDocente['profesor_id_2'],
            'posicion' => 2,
            'nombre' => trim($equipoDocente['profesor_2_apellido'] . ', ' . $equipoDocente['profesor_2_nombre']),
            'telefono' => $equipoDocente['profesor_2_telefono'],
            'es_actual' => $equipoDocente['profesor_id_2'] == $profesorId
        ];
    }
    
    // Profesor 3
    if ($equipoDocente['profesor_id_3']) {
        $equipo[] = [
            'id' => $equipoDocente['profesor_id_3'],
            'posicion' => 3,
            'nombre' => trim($equipoDocente['profesor_3_apellido'] . ', ' . $equipoDocente['profesor_3_nombre']),
            'telefono' => $equipoDocente['profesor_3_telefono'],
            'es_actual' => $equipoDocente['profesor_id_3'] == $profesorId
        ];
    }
    
    echo json_encode([
        'success' => true,
        'materia' => [
            'nombre' => $materiaInfo['nombre'],
            'codigo' => $materiaInfo['codigo'],
            'curso' => $materiaInfo['curso_nombre'] . ' (' . $materiaInfo['anio'] . '° año)'
        ],
        'equipo' => $equipo
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>