<?php
/**
 * script_estructura_grupos.php - Script para analizar estructura de grupos_materias y materias_grupo
 * Ejecuta este script y pásame la salida completa
 */

require_once 'config.php';

echo "<h2>Análisis de la estructura de agrupación de materias</h2>\n";

try {
    $db = Database::getInstance();
    
    // 1. Estructura de la tabla grupos_materias
    echo "<h3>1. Estructura de la tabla grupos_materias:</h3>\n";
    echo "<pre>";
    $columns = $db->fetchAll("PRAGMA table_info(grupos_materias)");
    foreach ($columns as $column) {
        echo "Columna: {$column['name']} | Tipo: {$column['type']} | Nulo: " . ($column['notnull'] ? 'NO' : 'SI') . " | Default: {$column['dflt_value']}\n";
    }
    echo "</pre>";
    
    // 2. Contenido de muestra de la tabla grupos_materias
    echo "<h3>2. Contenido de muestra de grupos_materias (primeros 10 registros):</h3>\n";
    echo "<pre>";
    $grupos = $db->fetchAll("SELECT * FROM grupos_materias LIMIT 10");
    if (!empty($grupos)) {
        // Mostrar encabezados
        echo implode(" | ", array_keys($grupos[0])) . "\n";
        echo str_repeat("-", 100) . "\n";
        
        foreach ($grupos as $grupo) {
            echo implode(" | ", array_map(function($v) { return $v ?? 'NULL'; }, $grupo)) . "\n";
        }
    } else {
        echo "No hay registros en la tabla grupos_materias\n";
    }
    echo "</pre>";
    
    // 3. Estructura de la tabla materias_grupo
    echo "<h3>3. Estructura de la tabla materias_grupo (tabla de relación):</h3>\n";
    try {
        echo "<pre>";
        $columns = $db->fetchAll("PRAGMA table_info(materias_grupo)");
        foreach ($columns as $column) {
            echo "Columna: {$column['name']} | Tipo: {$column['type']} | Nulo: " . ($column['notnull'] ? 'NO' : 'SI') . " | Default: {$column['dflt_value']}\n";
        }
        echo "</pre>";
        
        echo "<h4>Contenido de muestra de materias_grupo (primeros 15 registros):</h4>";
        echo "<pre>";
        $relaciones = $db->fetchAll("SELECT * FROM materias_grupo LIMIT 15");
        if (!empty($relaciones)) {
            echo implode(" | ", array_keys($relaciones[0])) . "\n";
            echo str_repeat("-", 100) . "\n";
            foreach ($relaciones as $rel) {
                echo implode(" | ", array_map(function($v) { return $v ?? 'NULL'; }, $rel)) . "\n";
            }
        } else {
            echo "No hay registros en materias_grupo\n";
        }
        echo "</pre>";
    } catch (Exception $e) {
        echo "<p>Error con materias_grupo: " . $e->getMessage() . "</p>";
    }
    
    // 4. Ejemplo de relación completa grupos-materias con materias reales
    echo "<h3>4. Relación completa grupos-materias (ejemplo real):</h3>\n";
    try {
        $ejemplo = $db->fetchAll(
            "SELECT 
                gm.id as grupo_id,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                mg.id as relacion_id,
                mg.materia_curso_id,
                m.nombre as materia_nombre,
                m.codigo as materia_codigo,
                c.anio,
                c.nombre as curso_nombre
             FROM grupos_materias gm
             JOIN materias_grupo mg ON gm.id = mg.grupo_id
             JOIN materias_por_curso mpc ON mg.materia_curso_id = mpc.id
             JOIN materias m ON mpc.materia_id = m.id  
             JOIN cursos c ON mpc.curso_id = c.id
             ORDER BY gm.nombre, c.anio, m.nombre
             LIMIT 20"
        );
        
        if (!empty($ejemplo)) {
            echo "<pre>";
            echo "Grupo_ID | Grupo_Nombre | Grupo_Cod | Materia_Curso_ID | Materia | Materia_Cod | Año | Curso\n";
            echo str_repeat("-", 120) . "\n";
            foreach ($ejemplo as $ej) {
                printf("%-8s | %-20s | %-9s | %-16s | %-15s | %-11s | %-3s | %s\n",
                    $ej['grupo_id'] ?? 'NULL',
                    substr($ej['grupo_nombre'] ?? 'NULL', 0, 20),
                    $ej['grupo_codigo'] ?? 'NULL',
                    $ej['materia_curso_id'] ?? 'NULL',
                    substr($ej['materia_nombre'] ?? 'NULL', 0, 15),
                    $ej['materia_codigo'] ?? 'NULL',
                    $ej['anio'] ?? 'NULL',
                    substr($ej['curso_nombre'] ?? 'NULL', 0, 15)
                );
            }
            echo "</pre>";
        } else {
            echo "<p>No se encontraron relaciones - verificar estructura de datos</p>";
        }
    } catch (Exception $e) {
        echo "<p>Error en consulta de relación: " . $e->getMessage() . "</p>";
        echo "<p>SQL problemático - intentemos consultas más básicas...</p>";
        
        // Consulta más básica para debug
        try {
            echo "<h4>Debug - Solo grupos_materias:</h4>";
            $soloGrupos = $db->fetchAll("SELECT * FROM grupos_materias LIMIT 5");
            echo "<pre>";
            print_r($soloGrupos);
            echo "</pre>";
            
            echo "<h4>Debug - Solo materias_grupo:</h4>";
            $soloRelaciones = $db->fetchAll("SELECT * FROM materias_grupo LIMIT 5");
            echo "<pre>";
            print_r($soloRelaciones);
            echo "</pre>";
        } catch (Exception $e2) {
            echo "<p>Error en debug: " . $e2->getMessage() . "</p>";
        }
    }
    
    // 5. Estadísticas y verificaciones
    echo "<h3>5. Estadísticas:</h3>\n";
    try {
        $totalGrupos = $db->fetchOne("SELECT COUNT(*) as total FROM grupos_materias");
        echo "<p>Total de grupos: " . $totalGrupos['total'] . "</p>";
        
        $totalRelaciones = $db->fetchOne("SELECT COUNT(*) as total FROM materias_grupo");
        echo "<p>Total de relaciones materia-grupo: " . $totalRelaciones['total'] . "</p>";
        
        $totalMaterias = $db->fetchOne("SELECT COUNT(*) as total FROM materias");
        echo "<p>Total de materias: " . $totalMaterias['total'] . "</p>";
        
        // Materias SIN grupo
        $materiasSinGrupo = $db->fetchOne(
            "SELECT COUNT(DISTINCT mpc.id) as total 
             FROM materias_por_curso mpc 
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id 
             WHERE mg.materia_curso_id IS NULL"
        );
        echo "<p>Materias sin grupo asignado: " . $materiasSinGrupo['total'] . "</p>";
        
        // Materias CON grupo
        $materiasConGrupo = $db->fetchOne(
            "SELECT COUNT(DISTINCT mpc.id) as total 
             FROM materias_por_curso mpc 
             JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id"
        );
        echo "<p>Materias con grupo asignado: " . $materiasConGrupo['total'] . "</p>";
        
    } catch (Exception $e) {
        echo "<p>Error en estadísticas: " . $e->getMessage() . "</p>";
    }
    
    // 6. Ejemplos específicos de grupos con sus materias
    echo "<h3>6. Ejemplos específicos - Grupos con sus materias:</h3>\n";
    try {
        $ejemplosEspecificos = $db->fetchAll(
            "SELECT 
                gm.nombre as grupo_nombre,
                COUNT(mg.materia_curso_id) as cantidad_materias,
                GROUP_CONCAT(m.nombre, ' | ') as materias_del_grupo
             FROM grupos_materias gm
             LEFT JOIN materias_grupo mg ON gm.id = mg.grupo_id
             LEFT JOIN materias_por_curso mpc ON mg.materia_curso_id = mpc.id
             LEFT JOIN materias m ON mpc.materia_id = m.id
             GROUP BY gm.id, gm.nombre
             ORDER BY gm.nombre
             LIMIT 10"
        );
        
        if (!empty($ejemplosEspecificos)) {
            echo "<pre>";
            foreach ($ejemplosEspecificos as $ej) {
                echo "GRUPO: " . ($ej['grupo_nombre'] ?? 'Sin nombre') . "\n";
                echo "  Cantidad de materias: " . $ej['cantidad_materias'] . "\n";
                echo "  Materias: " . ($ej['materias_del_grupo'] ?? 'Ninguna') . "\n";
                echo str_repeat("-", 80) . "\n";
            }
            echo "</pre>";
        }
        
        // Buscar específicamente el ejemplo de "Sistemas Tecnológicos" y "Robótica"
        echo "<h4>Buscando específicamente grupos con 'Sistemas' o 'Robótica':</h4>";
        $busqueda = $db->fetchAll(
            "SELECT 
                gm.id, gm.nombre as grupo_nombre, gm.codigo,
                m.nombre as materia_nombre, c.anio
             FROM grupos_materias gm
             LEFT JOIN materias_grupo mg ON gm.id = mg.grupo_id
             LEFT JOIN materias_por_curso mpc ON mg.materia_curso_id = mpc.id
             LEFT JOIN materias m ON mpc.materia_id = m.id
             LEFT JOIN cursos c ON mpc.curso_id = c.id
             WHERE gm.nombre LIKE '%Sistemas%' OR gm.nombre LIKE '%Tecnol%' 
                OR m.nombre LIKE '%Robot%' OR m.nombre LIKE '%robot%'
             ORDER BY gm.nombre, c.anio, m.nombre"
        );
        
        if (!empty($busqueda)) {
            echo "<pre>";
            foreach ($busqueda as $b) {
                echo "Grupo: {$b['grupo_nombre']} | Materia: " . ($b['materia_nombre'] ?? 'Sin materia') . " | Año: " . ($b['anio'] ?? 'N/A') . "\n";
            }
            echo "</pre>";
        } else {
            echo "<p>No se encontraron grupos con 'Sistemas' o materias con 'Robot'</p>";
        }
        
    } catch (Exception $e) {
        echo "<p>Error en ejemplos específicos: " . $e->getMessage() . "</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Error general: " . $e->getMessage() . "</p>";
}

echo "\n<hr>\n";
echo "<p><strong>Por favor, copia y pega TODA esta salida para que pueda analizar tu estructura.</strong></p>";
?>
