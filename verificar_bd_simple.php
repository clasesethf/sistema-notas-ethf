<?php
/**
 * verificar_bd_simple.php - Verificación simple del estado de la base de datos
 */

header('Content-Type: application/json');

try {
    require_once 'config.php';
    $db = Database::getInstance();
    
    $resultado = [
        'success' => true,
        'message' => 'Base de datos conectada correctamente',
        'tablas_verificadas' => []
    ];
    
    // Verificar tablas principales
    $tablasImportantes = [
        'configuracion_subgrupos',
        'materias_por_curso', 
        'ciclos_lectivos',
        'cursos',
        'materias'
    ];
    
    foreach ($tablasImportantes as $tabla) {
        $existe = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$tabla]);
        $resultado['tablas_verificadas'][$tabla] = $existe ? 'EXISTS' : 'MISSING';
    }
    
    // Verificar ciclo activo
    $cicloActivo = $db->fetchOne("SELECT id, anio FROM ciclos_lectivos WHERE activo = 1");
    $resultado['ciclo_activo'] = $cicloActivo ? $cicloActivo : null;
    
    echo json_encode($resultado);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => 'database_error'
    ]);
}
?>