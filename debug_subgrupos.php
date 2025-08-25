<?php
/**
 * debug_subgrupos.php - Archivo para diagnosticar problemas con subgrupos
 * Usar temporalmente para ver qué datos hay en la base de datos
 */

require_once 'config.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    die('No autorizado');
}

$db = Database::getInstance();
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Obtener materia específica si se proporciona
$materiaCursoId = isset($_GET['materia']) ? intval($_GET['materia']) : null;

if ($materiaCursoId) {
    echo "<h2>Debug para Materia ID: $materiaCursoId</h2>";
    
    // Información de la materia
    $materia = $db->fetchOne(
        "SELECT mp.id, mp.curso_id, m.nombre, m.codigo, c.nombre as curso_nombre
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    echo "<h3>Información de la Materia:</h3>";
    echo "<pre>" . print_r($materia, true) . "</pre>";
    
    // Configuración de subgrupos
    $config = $db->fetchOne(
        "SELECT * FROM configuracion_subgrupos 
         WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
        [$materiaCursoId, $cicloLectivoId]
    );
    
    echo "<h3>Configuración de Subgrupos:</h3>";
    echo "<pre>" . print_r($config, true) . "</pre>";
    
    // Estudiantes del curso
    if ($materia) {
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni, m.curso_id
             FROM usuarios u
             JOIN matriculas m ON u.id = m.estudiante_id
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.activo = 1
             ORDER BY u.apellido, u.nombre",
            [$materia['curso_id']]
        );
        
        echo "<h3>Estudiantes del Curso (Total: " . count($estudiantes) . "):</h3>";
        echo "<pre>" . print_r($estudiantes, true) . "</pre>";
    }
    
    // Asignaciones actuales
    $asignaciones = $db->fetchAll(
        "SELECT ep.*, u.nombre, u.apellido, u.dni, 
                c.nombre as curso_estudiante, c.id as curso_estudiante_id,
                mp.curso_id as curso_materia_id
         FROM estudiantes_por_materia ep
         JOIN usuarios u ON ep.estudiante_id = u.id
         JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
         JOIN cursos c ON m.curso_id = c.id
         JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
         WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ?
         ORDER BY ep.subgrupo, u.apellido, u.nombre",
        [$materiaCursoId, $cicloLectivoId]
    );
    
    echo "<h3>Asignaciones Actuales (Total: " . count($asignaciones) . "):</h3>";
    echo "<pre>" . print_r($asignaciones, true) . "</pre>";
    
    // Verificar problemas de curso
    $problemasCorso = [];
    foreach ($asignaciones as $asignacion) {
        if ($asignacion['curso_estudiante_id'] != $asignacion['curso_materia_id']) {
            $problemasCorso[] = $asignacion;
        }
    }
    
    if (!empty($problemasCorso)) {
        echo "<h3 style='color: red;'>⚠️ PROBLEMAS DE CURSO DETECTADOS:</h3>";
        echo "<pre>" . print_r($problemasCorso, true) . "</pre>";
    }
    
} else {
    echo "<h2>Debug General de Subgrupos</h2>";
    
    // Todas las materias con subgrupos
    $materias = $db->fetchAll(
        "SELECT mp.id, m.nombre, m.codigo, c.nombre as curso_nombre, c.anio,
                cs.tipo_division, cs.cantidad_grupos, cs.rotacion_automatica,
                COUNT(ep.id) as estudiantes_asignados
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         LEFT JOIN configuracion_subgrupos cs ON mp.id = cs.materia_curso_id AND cs.ciclo_lectivo_id = ?
         LEFT JOIN estudiantes_por_materia ep ON mp.id = ep.materia_curso_id AND ep.ciclo_lectivo_id = ?
         WHERE mp.requiere_subgrupos = 1 AND c.ciclo_lectivo_id = ?
         GROUP BY mp.id
         ORDER BY c.anio, m.nombre",
        [$cicloLectivoId, $cicloLectivoId, $cicloLectivoId]
    );
    
    echo "<h3>Materias con Subgrupos:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Materia</th><th>Curso</th><th>Configurado</th><th>Estudiantes</th><th>Acciones</th></tr>";
    
    foreach ($materias as $mat) {
        $configurado = $mat['tipo_division'] ? 'SÍ' : 'NO';
        echo "<tr>";
        echo "<td>{$mat['id']}</td>";
        echo "<td>{$mat['nombre']} ({$mat['codigo']})</td>";
        echo "<td>{$mat['curso_nombre']}</td>";
        echo "<td>$configurado</td>";
        echo "<td>{$mat['estudiantes_asignados']}</td>";
        echo "<td><a href='?materia={$mat['id']}'>Ver Detalles</a></td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

echo "<br><br><a href='gestionar_subgrupos.php'>← Volver a Gestionar Subgrupos</a>";
?>