<?php
// test_ajax.php - Archivo de diagnóstico
header('Content-Type: application/json; charset=utf-8');

try {
    // Test básico
    if ($_POST['action'] === 'test') {
        echo json_encode([
            'success' => true,
            'message' => 'Test básico funcionando',
            'post_data' => $_POST
        ]);
        exit;
    }
    
    // Test con config
    if ($_POST['action'] === 'test_config') {
        require_once 'config.php';
        echo json_encode([
            'success' => true,
            'message' => 'Config.php cargado correctamente',
            'db_available' => class_exists('Database')
        ]);
        exit;
    }
    
    // Test con FPDF
    if ($_POST['action'] === 'test_fpdf') {
        if (file_exists('lib/fpdf_utf8.php')) {
            require_once 'lib/fpdf_utf8.php';
            echo json_encode([
                'success' => true,
                'message' => 'FPDF disponible',
                'fpdf_class' => class_exists('FPDF_UTF8')
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'lib/fpdf_utf8.php no encontrado'
            ]);
        }
        exit;
    }
    
    // Test con funciones PDF
    if ($_POST['action'] === 'test_pdf_functions') {
        if (file_exists('includes/boletines_pdf_functions.php')) {
            require_once 'includes/boletines_pdf_functions.php';
            echo json_encode([
                'success' => true,
                'message' => 'Funciones PDF disponibles',
                'boletin_class' => class_exists('BoletinPDF')
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'includes/boletines_pdf_functions.php no encontrado'
            ]);
        }
        exit;
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Acción no reconocida'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} catch (Error $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error fatal: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
