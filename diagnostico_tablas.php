<?php
/**
 * diagnostico_tablas.php - Script para diagnosticar las tablas existentes
 */

require_once 'config.php';

try {
    $db = Database::getInstance();
    
    echo "<h3>Diagnóstico de Tablas en la Base de Datos</h3>";
    
    // 1. Mostrar todas las tablas
    echo "<h4>1. Todas las tablas en la base de datos:</h4>";
    $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    
    if (empty($tablas)) {
        echo "<p>No se encontraron tablas en la base de datos.</p>";
    } else {
        echo "<ul>";
        foreach ($tablas as $tabla) {
            echo "<li>" . $tabla['name'] . "</li>";
        }
        echo "</ul>";
    }
    
    // 2. Buscar tablas relacionadas con "pendientes" o "materias"
    echo "<h4>2. Tablas relacionadas con materias pendientes:</h4>";
    $tablas_relacionadas = $db->fetchAll(
        "SELECT name FROM sqlite_master 
         WHERE type='table' 
         AND (name LIKE '%pendiente%' OR name LIKE '%materia%') 
         ORDER BY name"
    );
    
    if (empty($tablas_relacionadas)) {
        echo "<p>No se encontraron tablas relacionadas con materias pendientes.</p>";
    } else {
        echo "<ul>";
        foreach ($tablas_relacionadas as $tabla) {
            echo "<li><strong>" . $tabla['name'] . "</strong></li>";
            
            // Mostrar estructura de la tabla
            $estructura = $db->fetchAll("PRAGMA table_info(" . $tabla['name'] . ")");
            if (!empty($estructura)) {
                echo "<ul>";
                foreach ($estructura as $columna) {
                    echo "<li>" . $columna['name'] . " (" . $columna['type'] . ")</li>";
                }
                echo "</ul>";
            }
        }
        echo "</ul>";
    }
    
    // 3. Verificar si existe la tabla grupos_materias y materias_grupo
    echo "<h4>3. Verificar tablas de agrupación:</h4>";
    
    $tablas_grupos = ['grupos_materias', 'materias_grupo', 'materias_por_curso'];
    
    foreach ($tablas_grupos as $tabla_grupo) {
        $existe = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$tabla_grupo]);
        if ($existe) {
            echo "<p>✅ <strong>$tabla_grupo</strong> existe</p>";
            
            // Mostrar estructura
            $estructura = $db->fetchAll("PRAGMA table_info($tabla_grupo)");
            echo "<ul>";
            foreach ($estructura as $columna) {
                echo "<li>" . $columna['name'] . " (" . $columna['type'] . ")</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>❌ <strong>$tabla_grupo</strong> NO existe</p>";
        }
    }
    
    // 4. Verificar datos en materias_por_curso (si existe)
    $existe_mpc = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name = 'materias_por_curso'");
    if ($existe_mpc) {
        echo "<h4>4. Datos de ejemplo en materias_por_curso:</h4>";
        $datos_mpc = $db->fetchAll("SELECT * FROM materias_por_curso LIMIT 5");
        if (!empty($datos_mpc)) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr>";
            foreach (array_keys($datos_mpc[0]) as $columna) {
                echo "<th style='padding: 5px; background: #f0f0f0;'>$columna</th>";
            }
            echo "</tr>";
            foreach ($datos_mpc as $fila) {
                echo "<tr>";
                foreach ($fila as $valor) {
                    echo "<td style='padding: 5px;'>$valor</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>La tabla materias_por_curso está vacía.</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red; border: 1px solid red; padding: 10px; margin: 10px;'>";
    echo "<strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h3 { color: #2c3e50; }
h4 { color: #34495e; margin-top: 20px; }
ul { margin: 10px 0; }
li { margin: 5px 0; }
table { margin: 10px 0; }
</style>
