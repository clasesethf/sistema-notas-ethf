<?php
/**
 * limpiador_fechas.php - Eliminador de asistencias posteriores a una fecha específica
 * Elimina todas las asistencias posteriores al 16 de julio de 2025
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🗑️ Limpiador de Asistencias por Fecha</h1>";
echo "<p>Herramienta para eliminar asistencias posteriores a una fecha específica</p>";

try {
    $db = Database::getInstance();
    
    // Fecha límite
    $fechaLimite = '2025-07-18';
    
    echo "<div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #ffc107;'>";
    echo "<h3 style='color: #856404; margin-top: 0;'>⚠️ OPERACIÓN PELIGROSA</h3>";
    echo "<p><strong>Esta operación eliminará PERMANENTEMENTE todas las asistencias con fecha posterior al:</strong></p>";
    echo "<div style='text-align: center; font-size: 24px; font-weight: bold; color: #dc3545; background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "📅 16 de JULIO de 2025";
    echo "</div>";
    echo "<p style='color: #856404;'><strong>⚠️ IMPORTANTE:</strong> Esta acción NO se puede deshacer. Asegúrate de tener un respaldo de la base de datos antes de continuar.</p>";
    echo "</div>";
    
    // PASO 1: Análisis previo
    echo "<h2>📊 PASO 1: Análisis de Datos</h2>";
    
    try {
        // Contar registros que se eliminarían
        $registrosEliminar = $db->fetchOne(
            "SELECT COUNT(*) as total FROM asistencias WHERE fecha > ?",
            [$fechaLimite]
        );
        
        // Contar registros que se mantendrían
        $registrosConservar = $db->fetchOne(
            "SELECT COUNT(*) as total FROM asistencias WHERE fecha <= ?",
            [$fechaLimite]
        );
        
        // Obtener rango de fechas a eliminar
        $rangoEliminar = $db->fetchOne(
            "SELECT 
                MIN(fecha) as fecha_min, 
                MAX(fecha) as fecha_max,
                COUNT(DISTINCT estudiante_id) as estudiantes_afectados
             FROM asistencias 
             WHERE fecha > ?",
            [$fechaLimite]
        );
        
        echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;'>";
        
        // Tarjeta de registros a conservar
        echo "<div style='background: #d4edda; border: 2px solid #28a745; padding: 20px; border-radius: 10px; text-align: center;'>";
        echo "<div style='font-size: 40px; color: #28a745;'>✅</div>";
        echo "<div style='font-size: 32px; font-weight: bold; color: #28a745;'>{$registrosConservar['total']}</div>";
        echo "<div style='color: #155724; font-weight: bold;'>Registros a CONSERVAR</div>";
        echo "<div style='color: #155724; font-size: 14px; margin-top: 5px;'>Fechas ≤ 16/07/2025</div>";
        echo "</div>";
        
        // Tarjeta de registros a eliminar
        echo "<div style='background: #f8d7da; border: 2px solid #dc3545; padding: 20px; border-radius: 10px; text-align: center;'>";
        echo "<div style='font-size: 40px; color: #dc3545;'>🗑️</div>";
        echo "<div style='font-size: 32px; font-weight: bold; color: #dc3545;'>{$registrosEliminar['total']}</div>";
        echo "<div style='color: #721c24; font-weight: bold;'>Registros a ELIMINAR</div>";
        echo "<div style='color: #721c24; font-size: 14px; margin-top: 5px;'>Fechas > 16/07/2025</div>";
        echo "</div>";
        
        echo "</div>";
        
        if ($registrosEliminar['total'] > 0 && $rangoEliminar['fecha_min']) {
            echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<h4 style='margin-top: 0; color: #495057;'>📋 Detalles de eliminación:</h4>";
            echo "<ul>";
            echo "<li><strong>📅 Rango de fechas a eliminar:</strong> {$rangoEliminar['fecha_min']} a {$rangoEliminar['fecha_max']}</li>";
            echo "<li><strong>👥 Estudiantes afectados:</strong> {$rangoEliminar['estudiantes_afectados']}</li>";
            echo "<li><strong>🗑️ Total de registros a eliminar:</strong> {$registrosEliminar['total']}</li>";
            echo "</ul>";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error en el análisis: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // PASO 2: Confirmación y ejecución
    echo "<h2>🚨 PASO 2: Confirmación y Ejecución</h2>";
    
    if (isset($_POST['confirmar_eliminacion'])) {
        $confirmacion = $_POST['confirmacion_texto'] ?? '';
        
        if ($confirmacion !== 'ELIMINAR DEFINITIVAMENTE') {
            echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
            echo "<h4 style='color: #721c24;'>❌ Confirmación incorrecta</h4>";
            echo "<p>Debes escribir exactamente: <code>ELIMINAR DEFINITIVAMENTE</code></p>";
            echo "</div>";
        } else {
            echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4>🔄 Ejecutando eliminación...</h4>";
            echo "<div id='progreso' style='max-height: 300px; overflow-y: auto; background: white; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 13px;'>";
            
            try {
                $conn = $db->getConnection();
                $conn->exec('BEGIN TRANSACTION');
                echo "<span style='color: green; font-weight: bold;'>🔄 Transacción iniciada</span><br>";
                
                // Obtener lista detallada antes de eliminar (para log)
                echo "<span style='color: blue;'>📋 Obteniendo lista detallada...</span><br>";
                $registrosDetalle = $db->fetchAll(
                    "SELECT u.dni, u.nombre, u.apellido, a.fecha, a.estado 
                     FROM asistencias a 
                     JOIN usuarios u ON a.estudiante_id = u.id 
                     WHERE a.fecha > ? 
                     ORDER BY a.fecha DESC 
                     LIMIT 10",
                    [$fechaLimite]
                );
                
                if (!empty($registrosDetalle)) {
                    echo "<div style='background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 3px;'>";
                    echo "<strong>📋 Ejemplos de registros que se eliminarán:</strong><br>";
                    foreach ($registrosDetalle as $registro) {
                        echo "• DNI {$registro['dni']} ({$registro['nombre']} {$registro['apellido']}) - {$registro['fecha']} - {$registro['estado']}<br>";
                    }
                    if ($registrosEliminar['total'] > 10) {
                        echo "• ... y " . ($registrosEliminar['total'] - 10) . " registros más<br>";
                    }
                    echo "</div>";
                }
                
                // Ejecutar eliminación
                echo "<span style='color: red; font-weight: bold;'>🗑️ Eliminando registros...</span><br>";
                $stmt = $conn->prepare("DELETE FROM asistencias WHERE fecha > ?");
                $resultado = $stmt->execute([$fechaLimite]);
                $eliminados = $stmt->rowCount();
                
                if ($resultado) {
                    $conn->exec('COMMIT');
                    echo "<span style='color: green; font-weight: bold;'>✅ Eliminación completada exitosamente</span><br>";
                    
                    echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; font-weight: bold;'>";
                    echo "<span style='color: green; font-size: 18px;'>✅ OPERACIÓN EXITOSA</span>";
                    echo "</div>";
                    
                    // Resumen final
                    echo "<br><div style='background: white; border: 2px solid #28a745; padding: 20px; border-radius: 8px; font-size: 16px;'>";
                    echo "<h4 style='margin-top: 0; color: #28a745;'>📊 RESUMEN DE ELIMINACIÓN</h4>";
                    echo "<table style='width: 100%; border-collapse: collapse;'>";
                    echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🗑️ Registros eliminados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: red; font-weight: bold;'>$eliminados</td></tr>";
                    echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>📅 Fecha límite aplicada:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: #007bff; font-weight: bold;'>$fechaLimite</td></tr>";
                    echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>✅ Registros conservados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: green; font-weight: bold;'>{$registrosConservar['total']}</td></tr>";
                    echo "<tr><td style='padding: 8px;'><strong>⏰ Fecha de operación:</strong></td><td style='padding: 8px; color: #6c757d; font-weight: bold;'>" . date('Y-m-d H:i:s') . "</td></tr>";
                    echo "</table>";
                    echo "</div>";
                    
                } else {
                    $conn->exec('ROLLBACK');
                    echo "<span style='color: red; font-weight: bold;'>❌ Error en la eliminación</span><br>";
                }
                
            } catch (Exception $e) {
                try {
                    $conn->exec('ROLLBACK');
                } catch (Exception $rollbackError) {
                    // Ignorar errores de rollback
                }
                echo "<span style='color: red; font-weight: bold;'>❌ ERROR FATAL: " . htmlspecialchars($e->getMessage()) . "</span><br>";
            }
            
            echo "</div>";
            echo "</div>";
        }
    }
    
    // Mostrar formulario de confirmación solo si hay registros para eliminar
    if ($registrosEliminar['total'] > 0 && !isset($_POST['confirmar_eliminacion'])) {
        echo "<div style='background: #f8d7da; padding: 25px; border-radius: 10px; margin: 30px 0; border: 3px solid #dc3545;'>";
        echo "<h3 style='margin-top: 0; color: #721c24;'>🚨 CONFIRMACIÓN REQUERIDA</h3>";
        echo "<p><strong>Para proceder con la eliminación de {$registrosEliminar['total']} registros, debes:</strong></p>";
        
        echo "<form method='post'>";
        echo "<div style='margin: 20px 0;'>";
        echo "<label style='display: block; font-weight: bold; margin-bottom: 10px; color: #721c24;'>";
        echo "Escribe exactamente: <code style='background: #f8f9fa; padding: 2px 5px;'>ELIMINAR DEFINITIVAMENTE</code>";
        echo "</label>";
        echo "<input type='text' name='confirmacion_texto' style='width: 100%; max-width: 300px; padding: 10px; font-size: 16px; border: 2px solid #dc3545; border-radius: 5px;' placeholder='Escribe aquí...' required>";
        echo "</div>";
        
        echo "<button type='submit' name='confirmar_eliminacion' value='1' style='background: #dc3545; color: white; padding: 15px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: bold;'>";
        echo "🗑️ ELIMINAR {$registrosEliminar['total']} REGISTROS DEFINITIVAMENTE";
        echo "</button>";
        
        echo "<p style='margin-top: 15px; font-size: 14px; color: #721c24;'>";
        echo "⚠️ <strong>Esta acción NO se puede deshacer.</strong> Asegúrate de tener un respaldo de la base de datos.";
        echo "</p>";
        echo "</form>";
        echo "</div>";
    } elseif ($registrosEliminar['total'] == 0) {
        echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #155724; margin-top: 0;'>✅ No hay registros para eliminar</h3>";
        echo "<p>No se encontraron asistencias posteriores al 16 de julio de 2025.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h4 style='color: #721c24; margin-top: 0;'>❌ Error Fatal</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Archivo:</strong> " . $e->getFile() . ":" . $e->getLine() . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<div style='margin: 30px 0;'>";
echo "<a href='importador_final_json.php' style='background: #007bff; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-right: 15px; font-weight: bold;'>📥 Volver al Importador</a>";
echo "<a href='asistencias.php' style='background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-right: 15px; font-weight: bold;'>📋 Ver Asistencias</a>";
echo "<a href='index.php' style='background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: bold;'>🏠 Panel Principal</a>";
echo "</div>";
?>
