<?php
/**
 * modificar_tabla_asignaciones_multiple.php - Script para permitir asignaciones múltiples
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * PROPÓSITO: Modificar la estructura de la tabla materias_por_curso para permitir
 * que múltiples profesores estén asignados a la misma materia en el mismo curso.
 */

require_once 'config.php';

// Verificar permisos (solo admin)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('<div class="alert alert-danger">Solo los administradores pueden ejecutar este script</div>');
}

$db = Database::getInstance();

try {
    echo "<h2>🔧 Modificando Estructura para Asignaciones Múltiples</h2>";
    echo "<hr>";
    
    // PASO 1: Analizar la estructura actual
    echo "<h4>📋 Paso 1: Analizando Estructura Actual</h4>";
    
    $tableInfo = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
    $indexes = $db->fetchAll("PRAGMA index_list(materias_por_curso)");
    
    echo "<div class='alert alert-info'>";
    echo "<h6>Estructura actual de materias_por_curso:</h6>";
    echo "<table class='table table-sm'>";
    echo "<thead><tr><th>Columna</th><th>Tipo</th><th>No Null</th><th>Default</th><th>PK</th></tr></thead>";
    echo "<tbody>";
    foreach ($tableInfo as $column) {
        echo "<tr>";
        echo "<td>{$column['name']}</td>";
        echo "<td>{$column['type']}</td>";
        echo "<td>" . ($column['notnull'] ? 'Sí' : 'No') . "</td>";
        echo "<td>" . ($column['dflt_value'] ?? 'NULL') . "</td>";
        echo "<td>" . ($column['pk'] ? 'Sí' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</div>";
    
    echo "<div class='alert alert-warning'>";
    echo "<h6>Índices actuales:</h6>";
    echo "<ul>";
    foreach ($indexes as $index) {
        $indexInfo = $db->fetchAll("PRAGMA index_info('{$index['name']}')");
        $columns = array_column($indexInfo, 'name');
        echo "<li><strong>{$index['name']}</strong>: " . implode(', ', $columns) . 
             ($index['unique'] ? " (UNIQUE)" : "") . "</li>";
    }
    echo "</ul>";
    echo "</div>";
    
    // PASO 2: Verificar si existe restricción UNIQUE problemática
    echo "<h4>🔍 Paso 2: Verificando Restricciones</h4>";
    
    $uniqueConstraints = [];
    foreach ($indexes as $index) {
        if ($index['unique'] == 1) {
            $indexInfo = $db->fetchAll("PRAGMA index_info('{$index['name']}')");
            $columns = array_column($indexInfo, 'name');
            $uniqueConstraints[] = [
                'name' => $index['name'],
                'columns' => $columns
            ];
        }
    }
    
    $hasProblematicConstraint = false;
    foreach ($uniqueConstraints as $constraint) {
        if (in_array('materia_id', $constraint['columns']) && 
            in_array('curso_id', $constraint['columns']) && 
            count($constraint['columns']) == 2) {
            $hasProblematicConstraint = true;
            echo "<div class='alert alert-danger'>";
            echo "⚠️ <strong>Restricción problemática encontrada:</strong> {$constraint['name']}<br>";
            echo "Columnas: " . implode(', ', $constraint['columns']);
            echo "</div>";
            break;
        }
    }
    
    if (!$hasProblematicConstraint) {
        echo "<div class='alert alert-success'>";
        echo "✅ No se encontraron restricciones que impidan asignaciones múltiples.";
        echo "</div>";
        
        // Verificar si ya tenemos asignaciones múltiples de inglés
        $asignacionesIngles = $db->fetchAll(
            "SELECT mp.*, m.nombre as materia, c.nombre as curso, c.anio, 
                    u.apellido || ', ' || u.nombre as profesor
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             JOIN usuarios u ON mp.profesor_id = u.id
             WHERE m.nombre = 'INGLÉS'
             ORDER BY c.anio, u.apellido"
        );
        
        echo "<h5>Asignaciones actuales de Inglés:</h5>";
        if (empty($asignacionesIngles)) {
            echo "<div class='alert alert-warning'>No se encontraron asignaciones de inglés.</div>";
        } else {
            echo "<table class='table table-striped table-sm'>";
            echo "<thead><tr><th>Año</th><th>Curso</th><th>Profesor</th></tr></thead>";
            echo "<tbody>";
            foreach ($asignacionesIngles as $asig) {
                echo "<tr><td>{$asig['anio']}°</td><td>{$asig['curso']}</td><td>{$asig['profesor']}</td></tr>";
            }
            echo "</tbody></table>";
        }
        
        echo "<div class='alert alert-info'>";
        echo "<p>La tabla ya permite asignaciones múltiples. Puedes proceder con el script de configuración de inglés.</p>";
        echo "<a href='configurar_ingles_multiple.php' class='btn btn-primary'>Configurar Inglés Múltiple</a>";
        echo "</div>";
        
    } else {
        
        // PASO 3: Respaldar datos actuales
        echo "<h4>💾 Paso 3: Respaldando Datos Actuales</h4>";
        
        $backupData = $db->fetchAll("SELECT * FROM materias_por_curso ORDER BY id");
        
        echo "<div class='alert alert-info'>";
        echo "✅ Datos respaldados: " . count($backupData) . " registros";
        echo "</div>";
        
        // PASO 4: Recrear tabla sin restricción UNIQUE problemática
        echo "<h4>🔨 Paso 4: Recreando Tabla</h4>";
        
        $db->transaction(function($db) use ($backupData) {
            
            // Renombrar tabla actual
            $db->query("ALTER TABLE materias_por_curso RENAME TO materias_por_curso_backup");
            
            // Crear nueva tabla sin la restricción UNIQUE problemática
            $createTableSQL = "
                CREATE TABLE materias_por_curso (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    materia_id INTEGER NOT NULL,
                    curso_id INTEGER NOT NULL,
                    profesor_id INTEGER,
                    requiere_subgrupos INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    
                    FOREIGN KEY (materia_id) REFERENCES materias(id),
                    FOREIGN KEY (curso_id) REFERENCES cursos(id),
                    FOREIGN KEY (profesor_id) REFERENCES usuarios(id)
                )
            ";
            
            $db->query($createTableSQL);
            
            // Crear índices útiles (pero NO unique en materia_id + curso_id)
            $db->query("CREATE INDEX idx_materias_por_curso_materia ON materias_por_curso(materia_id)");
            $db->query("CREATE INDEX idx_materias_por_curso_curso ON materias_por_curso(curso_id)");
            $db->query("CREATE INDEX idx_materias_por_curso_profesor ON materias_por_curso(profesor_id)");
            
            // Crear índice UNIQUE solo en la combinación de los 3 campos (materia_id + curso_id + profesor_id)
            // Esto previene duplicados exactos pero permite múltiples profesores por materia/curso
            $db->query("CREATE UNIQUE INDEX idx_materias_por_curso_unique ON materias_por_curso(materia_id, curso_id, profesor_id)");
            
            // Restaurar datos
            foreach ($backupData as $row) {
                $db->insert(
                    "INSERT INTO materias_por_curso 
                     (id, materia_id, curso_id, profesor_id, requiere_subgrupos, created_at, updated_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $row['id'],
                        $row['materia_id'],
                        $row['curso_id'],
                        $row['profesor_id'],
                        $row['requiere_subgrupos'] ?? 0,
                        $row['created_at'] ?? 'CURRENT_TIMESTAMP',
                        $row['updated_at'] ?? 'CURRENT_TIMESTAMP'
                    ]
                );
            }
            
            return true;
        });
        
        echo "<div class='alert alert-success'>";
        echo "✅ Tabla recreada exitosamente con estructura que permite asignaciones múltiples<br>";
        echo "✅ Datos restaurados: " . count($backupData) . " registros<br>";
        echo "✅ Nueva restricción UNIQUE: materia_id + curso_id + profesor_id (evita duplicados exactos)";
        echo "</div>";
        
        // PASO 5: Verificar nueva estructura
        echo "<h4>✅ Paso 5: Verificando Nueva Estructura</h4>";
        
        $newTableInfo = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
        $newIndexes = $db->fetchAll("PRAGMA index_list(materias_por_curso)");
        
        echo "<div class='alert alert-success'>";
        echo "<h6>Nueva estructura de materias_por_curso:</h6>";
        echo "<ul>";
        foreach ($newIndexes as $index) {
            if ($index['unique'] == 1) {
                $indexInfo = $db->fetchAll("PRAGMA index_info('{$index['name']}')");
                $columns = array_column($indexInfo, 'name');
                echo "<li><strong>{$index['name']}</strong>: " . implode(', ', $columns) . " (UNIQUE)</li>";
            }
        }
        echo "</ul>";
        echo "</div>";
        
        // Limpiar tabla de respaldo (opcional)
        echo "<div class='alert alert-warning'>";
        echo "<h6>🗑️ Limpieza</h6>";
        echo "<p>La tabla de respaldo 'materias_por_curso_backup' se mantendrá por seguridad.</p>";
        echo "<p>Si todo funciona correctamente, puedes eliminarla más tarde con:</p>";
        echo "<code>DROP TABLE materias_por_curso_backup;</code>";
        echo "</div>";
        
    }
    
    // PASO FINAL: Instrucciones
    echo "<div class='alert alert-primary'>";
    echo "<h5>🎯 Próximos Pasos</h5>";
    echo "<ol>";
    echo "<li>La tabla ahora permite <strong>múltiples profesores por materia/curso</strong></li>";
    echo "<li>Puedes ejecutar el script de configuración de inglés: ";
    echo "<a href='configurar_ingles_multiple.php' class='btn btn-sm btn-primary'>Configurar Inglés</a></li>";
    echo "<li>Verifica que todo funcione correctamente antes de eliminar la tabla de respaldo</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h5>❌ Error durante la modificación:</h5>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "<p><strong>Los datos están seguros.</strong> Si existe materias_por_curso_backup, los datos se pueden restaurar.</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='index.php' class='btn btn-secondary'>← Volver al Panel Principal</a></p>";
?>