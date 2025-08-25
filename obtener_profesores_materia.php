<?php
/**
 * obtener_profesores_materia.php - Obtener profesores asignados a una materia
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

header('Content-Type: application/json');

require_once 'config.php';

// Verificar que el usuario esté logueado y tenga permisos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$materiaCursoId = intval($_GET['materia_curso_id'] ?? 0);

if ($materiaCursoId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de materia inválido']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Para profesores, verificar que tengan acceso a esta materia
    if ($_SESSION['user_type'] === 'profesor') {
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
    }
    
    // Obtener profesores asignados a la materia
    $asignacion = $db->fetchOne(
        "SELECT profesor_id, profesor_id_2, profesor_id_3 FROM materias_por_curso WHERE id = ?",
        [$materiaCursoId]
    );
    
    if (!$asignacion) {
        echo json_encode(['success' => false, 'message' => 'Materia no encontrada']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'profesor_1_id' => $asignacion['profesor_id'],
        'profesor_2_id' => $asignacion['profesor_id_2'],
        'profesor_3_id' => $asignacion['profesor_id_3']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>