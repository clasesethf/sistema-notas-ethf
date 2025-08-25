<?php
/**
 * diagnostico_estructura.php - Diagnóstico rápido de estructura SQLite
 * Verifica columnas disponibles en tablas clave
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🔍 Diagnóstico de Estructura SQLite</h1>";
echo "<p>Verificando estructura de tablas para evitar errores de importación.</p>";

try {
    $db = Database::getInstance();
    
    // Tablas a verificar
    $tablasImportantes = ['usuarios', 'matriculas', 'asistencias', 'cursos', 'ciclos_lectivos'];
    
    foreach ($tablasImportantes as $tabla) {
        echo "<h2>📋 Tabla: $tabla</h2>";
        
        // Verificar si existe la tabla
        $existeTabla = $db->fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [$tabla]
        );
        
        if (!$existeTabla) {
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p>❌ <strong>Tabla '$tabla' no existe</strong></p>";
            echo "</div>";
            continue;
        }
        
        // Obtener estructura de la tabla
        $columnas = $db->fetchAll("PRAGMA table_info($tabla)");
        
        echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>✅ Estructura de la tabla '$tabla':</h4>";
        
        if (empty($columnas)) {
            echo "<p style='color: red;'>❌ No se pudo obtener información de columnas</p>";
        } else {
            echo "<table style='border-collapse: collapse; width: 100%; background: white;'>";
            echo "<tr style='background: #f8f9fa;'>";
            echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>Columna</th>";
            echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>Tipo</th>";
            echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>Not Null</th>";
            echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>Default</th>";
            echo "<th style='border: 1px solid #ddd; padding: 8px; text-align: left;'>PK</th>";
            echo "</tr>";
            
            foreach ($columnas as $col) {
                $nombre = htmlspecialchars($col['name']);
                $tipo = htmlspecialchars($col['type']);
                $notnull = $col['notnull'] ? '✅ Sí' : '❌ No';
                $default = $col['dflt_value'] ? htmlspecialchars($col['dflt_value']) : '<em>NULL</em>';
                $pk = $col['pk'] ? '🔑 Sí' : '';
                
                echo "<tr>";
                echo "<td style='border: 1px solid #ddd; padding: 8px; font-family: monospace; font-weight: bold;'>$nombre</td>";
                echo "<td style='border: 1px solid #ddd; padding: 8px;'>$tipo</td>";
                echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center;'>$notnull</td>";
                echo "<td style='border: 1px solid #ddd; padding: 8px; font-style: italic;'>$default</td>";
                echo "<td style='border: 1px solid #ddd; padding: 8px; text-align: center;'>$pk</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Mostrar columnas como lista para fácil referencia
            $nombresColumnas = array_column($columnas, 'name');
            echo "<p><strong>Columnas disponibles:</strong> <code>" . implode(', ', $nombresColumnas) . "</code></p>";
        }
        
        echo "</div>";
        
        // Verificaciones específicas por tabla
        if ($tabla === 'usuarios') {
            $tieneCreatedAt = in_array('created_at', array_column($columnas, 'name'));
            if ($tieneCreatedAt) {
                echo "<p style='color: green;'>✅ Tabla usuarios tiene columna created_at</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Tabla usuarios NO tiene columna created_at (se puede crear sin ella)</p>";
            }
        }
        
        if ($tabla === 'asistencias') {
            // Verificar restricciones CHECK
            $schema = $db->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='asistencias'");
            if ($schema) {
                echo "<details style='margin: 10px 0;'>";
                echo "<summary style='cursor: pointer; color: #007bff;'>Ver SQL de creación de tabla</summary>";
                echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 3px; font-size: 12px; overflow-x: auto;'>";
                echo htmlspecialchars($schema['sql']);
                echo "</pre>";
                echo "</details>";
                
                // Analizar restricción CHECK de estado
                if (strpos($schema['sql'], 'CHECK') !== false) {
                    preg_match("/estado.*?CHECK.*?\((.*?)\)/i", $schema['sql'], $matches);
                    if (!empty($matches[1])) {
                        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 5px 0;'>";
                        echo "<p><strong>⚠️ Restricción CHECK encontrada:</strong></p>";
                        echo "<code style='background: #f8f9fa; padding: 5px; border-radius: 3px;'>" . htmlspecialchars($matches[1]) . "</code>";
                        
                        // Verificar si permite cuarto_falta
                        if (strpos($matches[1], 'cuarto_falta') !== false) {
                            echo "<p style='color: green;'>✅ Permite 'cuarto_falta'</p>";
                        } else {
                            echo "<p style='color: red;'>❌ NO permite 'cuarto_falta' - NECESITA ARREGLARSE</p>";
                        }
                        echo "</div>";
                    }
                }
            }
        }
    }
    
    // Verificar datos de ejemplo
    echo "<h2>📊 Verificación de Datos</h2>";
    
    // Contar registros en tablas importantes
    foreach (['usuarios', 'cursos', 'matriculas', 'asistencias'] as $tabla) {
        try {
            $cuenta = $db->fetchOne("SELECT COUNT(*) as total FROM $tabla");
            $total = $cuenta ? $cuenta['total'] : 0;
            
            echo "<div style='display: inline-block; background: white; border: 2px solid #ddd; padding: 15px; margin: 10px; border-radius: 8px; text-align: center;'>";
            echo "<div style='font-size: 24px; font-weight: bold; color: #007bff;'>$total</div>";
            echo "<div style='color: #666;'>$tabla</div>";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div style='display: inline-block; background: #f8d7da; border: 2px solid #f5c6cb; padding: 15px; margin: 10px; border-radius: 8px; text-align: center;'>";
            echo "<div style='font-size: 16px; color: #721c24;'>Error</div>";
            echo "<div style='color: #666;'>$tabla</div>";
            echo "</div>";
        }
    }
    
    echo "<div style='clear: both; margin: 20px 0;'></div>";
    
    // Verificar estudiantes específicos
    echo "<h3>👥 Estudiantes de prueba</h3>";
    $dnisEjemplo = ['1710', '2021', '2022', '2023'];
    
    foreach ($dnisEjemplo as $dni) {
        $estudiante = $db->fetchOne(
            "SELECT u.id, u.nombre, u.apellido, 
                    m.curso_id, c.nombre as curso_nombre
             FROM usuarios u 
             LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
             LEFT JOIN cursos c ON m.curso_id = c.id
             WHERE u.dni = ? AND u.tipo = 'estudiante'",
            [$dni]
        );
        
        if ($estudiante) {
            $curso = $estudiante['curso_id'] ? $estudiante['curso_nombre'] : 'Sin matrícula';
            echo "<p>✅ <strong>DNI $dni:</strong> {$estudiante['nombre']} {$estudiante['apellido']} - $curso</p>";
        } else {
            echo "<p>❌ <strong>DNI $dni:</strong> No encontrado</p>";
        }
    }
    
    // Recomendaciones
    echo "<h2>💡 Recomendaciones</h2>";
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    
    $problemas = [];
    
    // Verificar tabla asistencias
    $asistenciasExiste = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='asistencias'");
    if (!$asistenciasExiste) {
        $problemas[] = "❌ Tabla 'asistencias' no existe - ejecutar schema.sql";
    } else {
        $schema = $db->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='asistencias'");
        if ($schema && strpos($schema['sql'], 'cuarto_falta') === false) {
            $problemas[] = "⚠️ Tabla 'asistencias' no permite 'cuarto_falta' - usar solucionador";
        }
    }
    
    // Verificar estudiantes
    $estudianteCount = $db->fetchOne("SELECT COUNT(*) as total FROM usuarios WHERE tipo = 'estudiante'");
    if (!$estudianteCount || $estudianteCount['total'] == 0) {
        $problemas[] = "⚠️ No hay estudiantes registrados - crear estudiantes primero";
    }
    
    // Verificar cursos
    $cursoCount = $db->fetchOne("SELECT COUNT(*) as total FROM cursos");
    if (!$cursoCount || $cursoCount['total'] == 0) {
        $problemas[] = "⚠️ No hay cursos registrados - crear cursos primero";
    }
    
    if (empty($problemas)) {
        echo "<h4 style='color: green;'>🎉 ¡Base de datos lista para importación!</h4>";
        echo "<p>No se detectaron problemas estructurales importantes.</p>";
        echo "<p><a href='solucionador_sqlite.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🚀 Proceder con Importación</a></p>";
    } else {
        echo "<h4 style='color: red;'>⚠️ Problemas detectados:</h4>";
        echo "<ul>";
        foreach ($problemas as $problema) {
            echo "<li>$problema</li>";
        }
        echo "</ul>";
        echo "<p><a href='solucionador_sqlite.php' style='background: #dc3545; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🔧 Usar Solucionador</a></p>";
    }
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h4>❌ Error en el diagnóstico</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='solucionador_sqlite.php' style='background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>🔧 Solucionador</a>";
echo "<a href='index.php' style='background: #6c757d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🏠 Panel</a></p>";
?>
