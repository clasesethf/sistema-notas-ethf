<?php
/**
 * limpiar_materias_duplicadas.php
 * Script para limpiar materias duplicadas y gestionar la base de datos
 */

require_once 'config.php';

// Verificar permisos
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    echo '<div class="alert alert-danger">No tiene permisos para acceder a esta sección</div>';
    exit;
}

$db = Database::getInstance();

echo "<h2>🧹 Limpieza de Materias Duplicadas y Gestión de BD</h2>";

// Función para mostrar estadísticas actuales
function mostrarEstadisticas($db) {
    $stats = $db->fetchOne(
        "SELECT 
            COUNT(DISTINCT m.id) as total_materias,
            COUNT(DISTINCT mp.id) as total_asignaciones,
            COUNT(DISTINCT c.id) as total_configuraciones
         FROM materias m
         LEFT JOIN materias_por_curso mp ON m.id = mp.materia_id
         LEFT JOIN configuracion_subgrupos c ON mp.id = c.materia_curso_id"
    );
    
    echo "<div style='background: #e3f2fd; padding: 15px; border: 1px solid #90caf9; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>📊 Estado actual:</h3>";
    echo "<ul>";
    echo "<li><strong>Total materias:</strong> {$stats['total_materias']}</li>";
    echo "<li><strong>Total asignaciones:</strong> {$stats['total_asignaciones']}</li>";
    echo "<li><strong>Configuraciones de subgrupos:</strong> {$stats['total_configuraciones']}</li>";
    echo "</ul>";
    echo "</div>";
}

// Función para detectar duplicadas
function detectarDuplicadas($db) {
    echo "<h3>🔍 Detectando materias duplicadas...</h3>";
    
    // Duplicadas por código
    $duplicadasCodigo = $db->fetchAll(
        "SELECT codigo, COUNT(*) as cantidad, GROUP_CONCAT(id) as ids, GROUP_CONCAT(nombre, ' | ') as nombres
         FROM materias 
         GROUP BY codigo 
         HAVING COUNT(*) > 1
         ORDER BY codigo"
    );
    
    // Duplicadas por nombre
    $duplicadasNombre = $db->fetchAll(
        "SELECT nombre, COUNT(*) as cantidad, GROUP_CONCAT(id) as ids, GROUP_CONCAT(codigo, ' | ') as codigos
         FROM materias 
         GROUP BY nombre 
         HAVING COUNT(*) > 1
         ORDER BY nombre"
    );
    
    echo "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffd700; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>📋 Duplicadas por código:</h4>";
    if (count($duplicadasCodigo) > 0) {
        echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><th>Código</th><th>Cantidad</th><th>IDs</th><th>Nombres</th></tr>";
        foreach ($duplicadasCodigo as $dup) {
            echo "<tr>";
            echo "<td><strong>{$dup['codigo']}</strong></td>";
            echo "<td>{$dup['cantidad']}</td>";
            echo "<td>{$dup['ids']}</td>";
            echo "<td>{$dup['nombres']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ No hay duplicadas por código</p>";
    }
    echo "</div>";
    
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>📋 Duplicadas por nombre:</h4>";
    if (count($duplicadasNombre) > 0) {
        echo "<table border='1' style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><th>Nombre</th><th>Cantidad</th><th>IDs</th><th>Códigos</th></tr>";
        foreach ($duplicadasNombre as $dup) {
            echo "<tr>";
            echo "<td><strong>{$dup['nombre']}</strong></td>";
            echo "<td>{$dup['cantidad']}</td>";
            echo "<td>{$dup['ids']}</td>";
            echo "<td>{$dup['codigos']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ No hay duplicadas por nombre</p>";
    }
    echo "</div>";
    
    return ['codigo' => $duplicadasCodigo, 'nombre' => $duplicadasNombre];
}

// Función para limpiar duplicadas automáticamente
function limpiarDuplicadasAutomatico($db) {
    echo "<h3>🧹 Limpiando duplicadas automáticamente...</h3>";
    
    $materiasEliminadas = 0;
    $asignacionesEliminadas = 0;
    $configuracionesEliminadas = 0;
    
    try {
        $db->transaction(function($db) use (&$materiasEliminadas, &$asignacionesEliminadas, &$configuracionesEliminadas) {
            
            // Primero, limpiar duplicadas por código (mantener la primera)
            $duplicadasCodigo = $db->fetchAll(
                "SELECT codigo, MIN(id) as mantener_id, COUNT(*) as cantidad
                 FROM materias 
                 GROUP BY codigo 
                 HAVING COUNT(*) > 1"
            );
            
            foreach ($duplicadasCodigo as $dup) {
                // Obtener IDs a eliminar (todos excepto el primero)
                $idsEliminar = $db->fetchAll(
                    "SELECT id FROM materias WHERE codigo = ? AND id != ?",
                    [$dup['codigo'], $dup['mantener_id']]
                );
                
                foreach ($idsEliminar as $materia) {
                    $materiaId = $materia['id'];
                    
                    // Eliminar configuraciones de subgrupos relacionadas
                    $configuraciones = $db->fetchAll(
                        "SELECT cs.id FROM configuracion_subgrupos cs
                         JOIN materias_por_curso mp ON cs.materia_curso_id = mp.id
                         WHERE mp.materia_id = ?",
                        [$materiaId]
                    );
                    
                    foreach ($configuraciones as $config) {
                        $db->query("DELETE FROM configuracion_subgrupos WHERE id = ?", [$config['id']]);
                        $configuracionesEliminadas++;
                    }
                    
                    // Eliminar asignaciones materia-curso
                    $asignaciones = $db->fetchAll("SELECT id FROM materias_por_curso WHERE materia_id = ?", [$materiaId]);
                    foreach ($asignaciones as $asig) {
                        $db->query("DELETE FROM materias_por_curso WHERE id = ?", [$asig['id']]);
                        $asignacionesEliminadas++;
                    }
                    
                    // Eliminar la materia duplicada
                    $db->query("DELETE FROM materias WHERE id = ?", [$materiaId]);
                    $materiasEliminadas++;
                    
                    echo "<p style='color: red;'>🗑️ Eliminada materia duplicada ID: $materiaId (código: {$dup['codigo']})</p>";
                }
            }
            
            // Luego, limpiar duplicadas por nombre (mantener la primera)
            $duplicadasNombre = $db->fetchAll(
                "SELECT nombre, MIN(id) as mantener_id, COUNT(*) as cantidad
                 FROM materias 
                 GROUP BY nombre 
                 HAVING COUNT(*) > 1"
            );
            
            foreach ($duplicadasNombre as $dup) {
                $idsEliminar = $db->fetchAll(
                    "SELECT id FROM materias WHERE nombre = ? AND id != ?",
                    [$dup['nombre'], $dup['mantener_id']]
                );
                
                foreach ($idsEliminar as $materia) {
                    $materiaId = $materia['id'];
                    
                    // Eliminar configuraciones de subgrupos relacionadas
                    $configuraciones = $db->fetchAll(
                        "SELECT cs.id FROM configuracion_subgrupos cs
                         JOIN materias_por_curso mp ON cs.materia_curso_id = mp.id
                         WHERE mp.materia_id = ?",
                        [$materiaId]
                    );
                    
                    foreach ($configuraciones as $config) {
                        $db->query("DELETE FROM configuracion_subgrupos WHERE id = ?", [$config['id']]);
                        $configuracionesEliminadas++;
                    }
                    
                    // Eliminar asignaciones materia-curso
                    $asignaciones = $db->fetchAll("SELECT id FROM materias_por_curso WHERE materia_id = ?", [$materiaId]);
                    foreach ($asignaciones as $asig) {
                        $db->query("DELETE FROM materias_por_curso WHERE id = ?", [$asig['id']]);
                        $asignacionesEliminadas++;
                    }
                    
                    // Eliminar la materia duplicada
                    $db->query("DELETE FROM materias WHERE id = ?", [$materiaId]);
                    $materiasEliminadas++;
                    
                    echo "<p style='color: red;'>🗑️ Eliminada materia duplicada ID: $materiaId (nombre: {$dup['nombre']})</p>";
                }
            }
        });
        
        echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>✅ Limpieza completada:</h4>";
        echo "<ul>";
        echo "<li><strong>Materias eliminadas:</strong> $materiasEliminadas</li>";
        echo "<li><strong>Asignaciones eliminadas:</strong> $asignacionesEliminadas</li>";
        echo "<li><strong>Configuraciones eliminadas:</strong> $configuracionesEliminadas</li>";
        echo "</ul>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>❌ Error en la limpieza:</h4>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
}

// Función para eliminar todas las materias técnicas agregadas recientemente
function eliminarMateriasTecnicas($db) {
    echo "<h3>🗑️ Eliminando materias técnicas recientes...</h3>";
    
    // Códigos de materias técnicas que se agregaron
    $codigosTecnicos = [
        '1PT1', '1PT2', '1PT3', '1LT1', '1LT2', '1LT3', '1ST1', '1ST2', '1ST3',
        '2PT1', '2PT2', '2PT3', '2LT1', '2LT2', '2ST1', '2ST2', '2ST3',
        '3PT1', '3PT2', '3PT3', '3LT1', '3LT2', '3ST1', '3ST2', '3ST3', '3ST4',
        '4DT1', '4DT2', '4MEA1', '4MEA2', '4MEA3', '4DPM1', '4DPM2', '4DPM3', '4IAE1', '4IAE2', '4IAE3',
        '5MEA1', '5MEA2', '5MEA3', '5DPM1', '5DPM2', '5DPM3', '5IAE1', '5IAE2', '5IAE3',
        '6LME', '6MEA1', '6MEA2', '6MEA3', '6DPM1', '6DPM2', '6DPM3', '6IAE1', '6IAE2', '6IAE3', '6IAE4',
        '7LMCC', '7MME1', '7MME2', '7MME3', '7PDE1', '7PDE2', '7PDE3', '7PDIE1', '7PDIE2', '7PDIE3', '7PDIE4'
    ];
    
    $materiasEliminadas = 0;
    $asignacionesEliminadas = 0;
    $configuracionesEliminadas = 0;
    
    try {
        $db->transaction(function($db) use ($codigosTecnicos, &$materiasEliminadas, &$asignacionesEliminadas, &$configuracionesEliminadas) {
            
            foreach ($codigosTecnicos as $codigo) {
                $materias = $db->fetchAll("SELECT id, nombre FROM materias WHERE codigo = ?", [$codigo]);
                
                foreach ($materias as $materia) {
                    $materiaId = $materia['id'];
                    
                    // Eliminar configuraciones de subgrupos
                    $configuraciones = $db->fetchAll(
                        "SELECT cs.id FROM configuracion_subgrupos cs
                         JOIN materias_por_curso mp ON cs.materia_curso_id = mp.id
                         WHERE mp.materia_id = ?",
                        [$materiaId]
                    );
                    
                    foreach ($configuraciones as $config) {
                        $db->query("DELETE FROM configuracion_subgrupos WHERE id = ?", [$config['id']]);
                        $configuracionesEliminadas++;
                    }
                    
                    // Eliminar asignaciones
                    $asignaciones = $db->fetchAll("SELECT id FROM materias_por_curso WHERE materia_id = ?", [$materiaId]);
                    foreach ($asignaciones as $asig) {
                        $db->query("DELETE FROM materias_por_curso WHERE id = ?", [$asig['id']]);
                        $asignacionesEliminadas++;
                    }
                    
                    // Eliminar materia
                    $db->query("DELETE FROM materias WHERE id = ?", [$materiaId]);
                    $materiasEliminadas++;
                    
                    echo "<p style='color: red;'>🗑️ Eliminada: $codigo - {$materia['nombre']}</p>";
                }
            }
        });
        
        echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>✅ Eliminación de materias técnicas completada:</h4>";
        echo "<ul>";
        echo "<li><strong>Materias eliminadas:</strong> $materiasEliminadas</li>";
        echo "<li><strong>Asignaciones eliminadas:</strong> $asignacionesEliminadas</li>";
        echo "<li><strong>Configuraciones eliminadas:</strong> $configuracionesEliminadas</li>";
        echo "</ul>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>❌ Error:</h4>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
    }
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'limpiar_duplicadas':
            limpiarDuplicadasAutomatico($db);
            break;
        case 'eliminar_tecnicas':
            eliminarMateriasTecnicas($db);
            break;
    }
    
    echo "<hr>";
}

// Mostrar estadísticas actuales
mostrarEstadisticas($db);

// Detectar duplicadas
$duplicadas = detectarDuplicadas($db);

?>

<div style="max-width: 1200px; margin: 20px auto; padding: 20px; font-family: Arial, sans-serif;">

<div style="background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0;">
    <h3>⚠️ Opciones de Limpieza:</h3>
    <p>Tienes las siguientes opciones para solucionar el problema:</p>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
    
    <!-- Opción 1: Limpiar duplicadas -->
    <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffd700; border-radius: 5px;">
        <h4>🧹 Opción 1: Limpiar Duplicadas</h4>
        <p>Elimina automáticamente las materias duplicadas, manteniendo la primera de cada una.</p>
        <ul>
            <li>✅ Mantiene las materias originales</li>
            <li>🗑️ Elimina solo las duplicadas</li>
            <li>🔧 Conserva asignaciones válidas</li>
        </ul>
        <form method="POST" onsubmit="return confirm('¿Limpiar materias duplicadas automáticamente?')">
            <input type="hidden" name="accion" value="limpiar_duplicadas">
            <button type="submit" style="background: #ffc107; color: black; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                🧹 Limpiar Duplicadas
            </button>
        </form>
    </div>
    
    <!-- Opción 2: Eliminar todas las técnicas -->
    <div style="background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px;">
        <h4>🗑️ Opción 2: Eliminar Materias Técnicas</h4>
        <p>Elimina completamente todas las materias técnicas agregadas por el script.</p>
        <ul>
            <li>🗑️ Elimina todas las materias con códigos técnicos</li>
            <li>🔄 Vuelve al estado anterior</li>
            <li>⚠️ Permite empezar de cero</li>
        </ul>
        <form method="POST" onsubmit="return confirm('⚠️ ¿ELIMINAR TODAS las materias técnicas?\n\nEsto eliminará todas las materias con códigos como 1PT1, 2LT1, etc.')">
            <input type="hidden" name="accion" value="eliminar_tecnicas">
            <button type="submit" style="background: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                🗑️ Eliminar Materias Técnicas
            </button>
        </form>
    </div>
</div>

<div style="text-align: center; margin: 30px 0;">
    <a href="materias.php" style="background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
        ← Volver a Gestión de Materias
    </a>
    <a href="cargar_materias_tecnicas.php" style="background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">
        Cargar Materias (Corregido) →
    </a>
</div>

</div>