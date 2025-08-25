<?php
/**
 * obtener_usuario.php - API para obtener datos de un usuario específico
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sin permisos']);
    exit;
}

// Verificar que se envió el ID del usuario
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de usuario no válido']);
    exit;
}

$usuarioId = intval($_GET['id']);

// Obtener conexión a la base de datos
$db = Database::getInstance();

try {
    // Verificar si la columna created_at existe
    $columns = $db->fetchAll("PRAGMA table_info(usuarios)");
    $hasCreatedAt = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'created_at') {
            $hasCreatedAt = true;
            break;
        }
    }
    
    // Construir consulta según las columnas disponibles
    $selectFields = "id, nombre, apellido, dni, tipo, direccion, telefono, activo";
    if ($hasCreatedAt) {
        $selectFields .= ", created_at";
    }
    
    // Obtener datos del usuario
    $usuario = $db->fetchOne(
        "SELECT $selectFields 
         FROM usuarios 
         WHERE id = ?",
        [$usuarioId]
    );
    
    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        exit;
    }
    
    // Si es estudiante, obtener información de matriculación
    $matriculacion = null;
    if ($usuario['tipo'] === 'estudiante') {
        $matriculacion = $db->fetchOne(
            "SELECT m.*, c.nombre as curso_nombre, c.anio as curso_anio
             FROM matriculas m
             JOIN cursos c ON m.curso_id = c.id
             WHERE m.estudiante_id = ? AND m.estado = 'activo'
             ORDER BY m.fecha_matriculacion DESC
             LIMIT 1",
            [$usuarioId]
        );
    }
    
    // Si es profesor, obtener materias asignadas
    $materias = [];
    if ($usuario['tipo'] === 'profesor') {
        $materias = $db->fetchAll(
            "SELECT m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.profesor_id = ?
             ORDER BY c.anio, m.nombre",
            [$usuarioId]
        );
    }
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'usuario' => $usuario,
        'matriculacion' => $matriculacion,
        'materias' => $materias
    ];
    
    // Establecer header para JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
}
?>