<?php
require_once 'config.php';

try {
    require_once 'EmailManager.php';
    $emailManager = new EmailManager();
    echo "✅ EmailManager cargado correctamente<br>";
    echo "🎉 ¡Listo para configurar el email!";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
