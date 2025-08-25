<?php
/**
 * test_ajax_debug.php - Archivo de diagnóstico
 * Crear este archivo para identificar el problema
 */

// 1. Capturar TODA la salida desde el inicio
ob_start();

// 2. Headers básicos
header('Content-Type: text/plain; charset=utf-8');

// 3. Intentar incluir config
echo "=== DIAGNÓSTICO AJAX ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

echo "1. Verificando sesión...\n";
session_start();
echo "   ✓ Sesión iniciada\n";

echo "2. Verificando config.php...\n";
if (file_exists('config.php')) {
    echo "   ✓ config.php existe\n";
    
    // Capturar salida de config.php
    ob_start();
    require_once 'config.php';
    $config_output = ob_get_clean();
    
    if (empty($config_output)) {
        echo "   ✓ config.php no genera salida\n";
    } else {
        echo "   ❌ config.php genera salida:\n";
        echo "   " . var_export($config_output, true) . "\n";
    }
} else {
    echo "   ❌ config.php NO existe\n";
}

echo "3. Verificando Database class...\n";
try {
    $db = Database::getInstance();
    echo "   ✓ Database::getInstance() funciona\n";
} catch (Exception $e) {
    echo "   ❌ Error en Database: " . $e->getMessage() . "\n";
}

echo "4. Verificando FPDF...\n";
if (file_exists('lib/fpdf_utf8.php')) {
    echo "   ✓ lib/fpdf_utf8.php existe\n";
    
    // Probar incluir FPDF
    ob_start();
    require_once 'lib/fpdf_utf8.php';
    $fpdf_output = ob_get_clean();
    
    if (empty($fpdf_output)) {
        echo "   ✓ FPDF se incluye sin salida\n";
    } else {
        echo "   ❌ FPDF genera salida:\n";
        echo "   " . var_export($fpdf_output, true) . "\n";
    }
} else {
    echo "   ❌ lib/fpdf_utf8.php NO existe\n";
}

echo "5. Verificando ZipArchive...\n";
if (class_exists('ZipArchive')) {
    echo "   ✓ ZipArchive disponible\n";
} else {
    echo "   ❌ ZipArchive NO disponible\n";
}

echo "6. Verificando directorio temporal...\n";
$tempDir = sys_get_temp_dir();
echo "   Directorio: $tempDir\n";
if (is_writable($tempDir)) {
    echo "   ✓ Directorio escribible\n";
} else {
    echo "   ❌ Directorio NO escribible\n";
}

echo "7. Probando consulta simple...\n";
try {
    $db = Database::getInstance();
    $result = $db->fetchOne("SELECT COUNT(*) as total FROM cursos");
    echo "   ✓ Consulta exitosa. Total cursos: " . ($result['total'] ?? 'NULL') . "\n";
} catch (Exception $e) {
    echo "   ❌ Error en consulta: " . $e->getMessage() . "\n";
}

echo "\n=== TEST JSON ===\n";
$test_data = [
    'success' => true,
    'test' => 'OK',
    'timestamp' => time(),
    'date' => date('Y-m-d H:i:s')
];

echo "JSON a enviar:\n";
echo json_encode($test_data, JSON_PRETTY_PRINT) . "\n";

// Capturar toda la salida del diagnóstico
$diagnostic_output = ob_get_clean();

// Mostrar diagnóstico
echo $diagnostic_output;

echo "\n=== ENVIANDO JSON LIMPIO ===\n";

// Limpiar completamente y enviar JSON
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($test_data);
?>
