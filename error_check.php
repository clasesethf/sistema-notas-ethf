<?php
// Mostrar todos los errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Verificación de archivos:\n";
echo "- config.php: " . (file_exists('config.php') ? 'EXISTS' : 'MISSING') . "\n";
echo "- lib/fpdf_utf8.php: " . (file_exists('lib/fpdf_utf8.php') ? 'EXISTS' : 'MISSING') . "\n";
echo "- includes/boletines_pdf_functions.php: " . (file_exists('includes/boletines_pdf_functions.php') ? 'EXISTS' : 'MISSING') . "\n";

try {
    require_once 'config.php';
    echo "- Database class: " . (class_exists('Database') ? 'AVAILABLE' : 'NOT AVAILABLE') . "\n";
} catch (Exception $e) {
    echo "- Error cargando config: " . $e->getMessage() . "\n";
}
?>
