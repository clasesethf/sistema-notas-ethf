<?php
/**
 * cambiar_rol.php - Endpoint para cambiar entre roles múltiples
 * Compatible con SQLite
 */

session_start();
require_once 'config.php';

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Verificar que haya sesión activa
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Sesión no válida']);
    exit;
}

try {
    $db = Database::getInstance();
    $nuevoRol = trim($_POST['rol'] ?? '');
    
    if (empty($nuevoRol)) {
        throw new Exception('Rol no especificado');
    }
    
    // Obtener roles disponibles del usuario
    $usuario = $db->fetchOne(
        "SELECT tipo, roles_secundarios FROM usuarios WHERE id = ?", 
        [$_SESSION['user_id']]
    );
    
    if (!$usuario) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Construir array de roles disponibles
    $rolesDisponibles = [$usuario['tipo']]; // Rol principal
    
    if (!empty($usuario['roles_secundarios'])) {
        $rolesSecundarios = explode(',', $usuario['roles_secundarios']);
        $rolesDisponibles = array_merge($rolesDisponibles, array_map('trim', $rolesSecundarios));
    }
    
    $rolesDisponibles = array_unique(array_filter($rolesDisponibles));
    
    // Verificar que el rol solicitado esté disponible
    if (!in_array($nuevoRol, $rolesDisponibles)) {
        throw new Exception('Rol no disponible para este usuario');
    }
    
    // Guardar rol anterior para log
    $rolAnterior = $_SESSION['rol_activo'] ?? $_SESSION['user_type'];
    
    // Cambiar el rol activo
    $_SESSION['rol_activo'] = $nuevoRol;
    $_SESSION['user_type'] = $nuevoRol; // Actualizar para compatibilidad con código existente
    $_SESSION['roles_disponibles'] = $rolesDisponibles;
    $_SESSION['rol_principal'] = $usuario['tipo'];
    
    // Registrar el cambio en log (opcional)
    try {
        // Verificar si existe la tabla de log
        $tablaExiste = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='log_cambios_rol'");
        
        if ($tablaExiste) {
            $db->query(
                "INSERT INTO log_cambios_rol (usuario_id, rol_anterior, rol_nuevo, fecha_cambio, ip_address) 
                 VALUES (?, ?, ?, datetime('now'), ?)",
                [$_SESSION['user_id'], $rolAnterior, $nuevoRol, $_SERVER['REMOTE_ADDR'] ?? 'N/A']
            );
        }
    } catch (Exception $logError) {
        // Log silencioso, no fallar por esto
        error_log("Error al registrar cambio de rol: " . $logError->getMessage());
    }
    
    // Respuesta exitosa
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'rol_anterior' => $rolAnterior,
        'rol_nuevo' => $nuevoRol,
        'mensaje' => "Cambiado exitosamente a rol: " . ucfirst($nuevoRol),
        'roles_disponibles' => $rolesDisponibles
    ]);

} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ]);
}
?>
