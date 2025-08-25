<?php
/**
 * limpiar_detalles_envio.php
 * Script auxiliar para limpiar datos de sesión después de mostrar resultados
 */

session_start();

// Limpiar datos de envío de la sesión
if (isset($_SESSION['detalles_envio'])) {
    unset($_SESSION['detalles_envio']);
}

// Respuesta exitosa
header('Content-Type: application/json');
echo json_encode(['success' => true]);
?>
