<?php
/**
 * obtener_info_ciclos.php - Obtener información detallada de ciclos lectivos
 */

header('Content-Type: application/json');

try {
    require_once 'config.php';
    $db = Database::getInstance();
    
    $ciclos = $db->fetchAll("SELECT * FROM ciclos_lectivos ORDER BY anio DESC");
    
    $resultado = [
        'success' => true,
        'ciclos' => $ciclos,
        'total' => count($ciclos)
    ];
    
    // Información adicional por ciclo
    foreach ($resultado['ciclos'] as &$ciclo) {
        $ciclo['cursos_count'] = $db->fetchOne(
            "SELECT COUNT(*) as count FROM cursos WHERE ciclo_lectivo_id = ?", 
            [$ciclo['id']]
        )['count'];
        
        $ciclo['materias_count'] = $db->fetchOne(
            "SELECT COUNT(*) as count FROM materias_por_curso mp 
             JOIN cursos c ON mp.curso_id = c.id 
             WHERE c.ciclo_lectivo_id = ?", 
            [$ciclo['id']]
        )['count'];
    }
    
    echo json_encode($resultado);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>