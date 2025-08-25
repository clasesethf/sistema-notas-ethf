<?php
/**
 * buscar_materias_ajax.php
 * Endpoint para búsqueda en vivo de materias
 */

require_once 'config.php';

header('Content-Type: application/json');

// Verificar permisos
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin permisos']);
    exit;
}

$db = Database::getInstance();
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($busqueda)) {
    echo json_encode(['materias' => []]);
    exit;
}

try {
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Búsqueda de materias
    $materias = $db->fetchAll(
        "SELECT m.id, m.nombre, m.codigo,
                COUNT(DISTINCT mp.id) as total_asignaciones,
                COUNT(DISTINCT CASE WHEN c.ciclo_lectivo_id = ? THEN mp.id END) as asignaciones_actuales
         FROM materias m
         LEFT JOIN materias_por_curso mp ON m.id = mp.materia_id
         LEFT JOIN cursos c ON mp.curso_id = c.id
         WHERE (m.nombre LIKE ? OR m.codigo LIKE ?)
         GROUP BY m.id, m.nombre, m.codigo
         ORDER BY 
            CASE WHEN m.codigo LIKE ? THEN 1 ELSE 2 END,
            CASE WHEN m.nombre LIKE ? THEN 1 ELSE 2 END,
            m.codigo
         LIMIT 10",
        [
            $cicloLectivoId,
            "%$busqueda%",
            "%$busqueda%", 
            "$busqueda%",
            "$busqueda%"
        ]
    );
    
    // Agregar información de tipo para cada materia
    foreach ($materias as &$materia) {
        $materia['tipo'] = determinarTipoMateria($materia['codigo']);
        $materia['asignaciones'] = $materia['asignaciones_actuales'];
    }
    
    echo json_encode(['materias' => $materias]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Función para determinar el tipo de materia según su código
 */
function determinarTipoMateria($codigo) {
    if (preg_match('/^(\d+)(PT|LT|ST|MEA|DPM|IAE|DT)/', $codigo, $matches)) {
        $categoria = $matches[2];
        
        switch($categoria) {
            case 'PT': return 'Taller de Producción';
            case 'LT': return 'Laboratorio Técnico';
            case 'ST': return 'Seminario Técnico';
            case 'MEA': return 'Mecatrónica y Automatización';
            case 'DPM': return 'Diseño y Producción Mecánica';
            case 'IAE': return 'Instalaciones y Automatización';
            case 'DT': return 'Dibujo Tecnológico';
        }
    }
    
    if (strpos($codigo, 'LME') !== false) return 'Laboratorio de Mediciones';
    if (strpos($codigo, 'LMCC') !== false) return 'Lab. Metrología y Control';
    if (strpos($codigo, 'MME') !== false) return 'Mantenimiento';
    if (strpos($codigo, 'PDE') !== false) return 'Procesos de Diseño';
    if (strpos($codigo, 'PDIE') !== false) return 'Procesos Diseño Instalaciones';
    
    return 'Materia básica';
}
?>