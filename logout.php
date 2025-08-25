<?php
/**
 * logout.php - Cierre de sesión
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Iniciar sesión
session_start();

// Destruir todas las variables de sesión
$_SESSION = array();

// Si se desea destruir la sesión completamente, borrar también la cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesión
session_destroy();

// Redireccionar a la página de inicio de sesión
header('Location: login.php');
exit;