<?php
/**
 * importador_simple_debug.php - Importador simplificado con debug extensivo
 * Para resolver problemas de fgetcsv y datos saltados
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🔧 Importador Simple con Debug</h1>";
echo "<p>Versión simplificada para resolver problemas de CSV y datos saltados</p>";

try {
    $db = Database::getInstance();
    
    // Buscar archivo CSV
    $archivoCsv = null;
    $posiblesArchivos = [
        'inasistencias_ethf.csv',
        'database/inasistencias_ethf.csv',
        __DIR__ . '/inasistencias_ethf.csv'
    ];
    
    foreach ($posiblesArchivos as $archivo) {
        if (file_exists($archivo)) {
            $archivoCsv = $archivo;
            break;
        }
    }
    
    if (!$archivoCsv) {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
        echo "<h4>❌ Archivo CSV no encontrado</h4>";
        echo "<p><strong>Archivos buscados:</strong></p>";
        echo "<ul>";
        foreach ($posiblesArchivos as $archivo) {
            echo "<li><code>$archivo</code> - " . (file_exists($archivo) ? '✅ Existe' : '❌ No existe') . "</li>";
        }
        echo "</ul>";
        echo "</div>";
        exit;
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p>✅ <strong>Archivo encontrado:</strong> $archivoCsv</p>";
    echo "<p><strong>Tamaño:</strong> " . number_format(filesize($archivoCsv)) . " bytes</p>";
    echo "</div>";
    
    if (isset($_POST['analizar_csv'])) {
        echo "<h2>🔍 ANÁLISIS DEL ARCHIVO CSV</h2>";
        echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;'>";
        
        $handle = fopen($archivoCsv, 'r');
        if (!$handle) {
            echo "<span style='color: red;'>❌ No se puede abrir el archivo</span>";
            exit;
        }
        
        echo "<span style='color: blue;'>📂 Archivo abierto correctamente</span><br>";
        
        // Leer primera línea (encabezados) con todos los parámetros
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if ($headers === false) {
            echo "<span style='color: red;'>❌ Error leyendo encabezados</span><br>";
        } else {
            echo "<span style='color: green;'>✅ Encabezados leídos: " . count($headers) . " columnas</span><br>";
            echo "<span style='color: purple;'>📋 Columnas: " . implode(' | ', $headers) . "</span><br><br>";
        }
        
        // Leer primeras 10 líneas de datos
        $lineCount = 0;
        $validRows = 0;
        $invalidRows = 0;
        
        echo "<strong>📊 Análisis de las primeras 20 líneas:</strong><br>";
        
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE && $lineCount < 20) {
            $lineCount++;
            $lineNumber = $lineCount + 1; // +1 porque la línea 1 son los encabezados
            
            if (count($data) >= 6) {
                $validRows++;
                $matricula = trim($data[0]);
                $porcentaje = trim($data[1]);
                $fecha = trim($data[2]);
                $detalle = trim($data[3]);
                
                echo "<span style='color: green;'>✅ Línea $lineNumber: DNI='$matricula' | %='$porcentaje' | fecha='$fecha' | detalle='" . substr($detalle, 0, 30) . "...'</span><br>";
            } else {
                $invalidRows++;
                echo "<span style='color: red;'>❌ Línea $lineNumber: Solo " . count($data) . " columnas - Datos: [" . implode(' | ', $data) . "]</span><br>";
            }
        }
        
        // Contar todas las líneas
        $totalLines = 1; // Empezar en 1 por los encabezados
        while (fgetcsv($handle, 0, ',', '"', '\\') !== FALSE) {
            $totalLines++;
        }
        
        fclose($handle);
        
        echo "<br><div style='background: white; padding: 10px; border-radius: 3px; border: 1px solid #ddd;'>";
        echo "<strong>📈 RESUMEN DEL ANÁLISIS:</strong><br>";
        echo "• Total de líneas en archivo: $totalLines<br>";
        echo "• Líneas con datos válidos (6+ columnas): $validRows<br>";
        echo "• Líneas con datos inválidos: $invalidRows<br>";
        echo "• Porcentaje válido: " . round(($validRows / $lineCount) * 100, 1) . "%<br>";
        echo "</div>";
        
        echo "</div>";
    }
    
    if (isset($_POST['importar_simple'])) {
        echo "<h2>📥 IMPORTACIÓN SIMPLIFICADA</h2>";
        echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<div style='max-height: 500px; overflow-y: auto; background: white; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 13px;'>";
        
        $handle = fopen($archivoCsv, 'r');
        
        // Saltar encabezados
        fgetcsv($handle, 0, ',', '"', '\\');
        
        $conn = $db->getConnection();
        $conn->exec('BEGIN TRANSACTION');
        echo "<span style='color: green; font-weight: bold;'>🔄 Transacción iniciada</span><br><br>";
        
        $procesados = 0;
        $errores = 0;
        $ignorados1710 = 0;
        $noEncontrados = [];
        $lineNumber = 1;
        
        // Preparar consulta de búsqueda
        $stmtBuscar = $conn->prepare(
            "SELECT u.id as estudiante_id, m.curso_id 
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE u.dni = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
             LIMIT 1"
        );
        
        // Preparar consulta de inserción
        $stmtInsertar = $conn->prepare(
            "INSERT INTO asistencias 
             (estudiante_id, curso_id, fecha, estado, observaciones, cuatrimestre) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE && $procesados < 2100) {
            $lineNumber++;
            
            try {
                if (count($data) < 6) {
                    if ($lineNumber <= 10) {
                        echo "<span style='color: orange;'>⚠ Línea $lineNumber: Solo " . count($data) . " columnas</span><br>";
                    }
                    continue;
                }
                
                $matricula = trim($data[0]);
                $porcentaje = intval($data[1]);
                $fecha = trim($data[2]);
                $detalle = trim($data[3]);
                
                // Ignorar DNI 1710
                if ($matricula === '1710') {
                    $ignorados1710++;
                    if ($ignorados1710 <= 3) {
                        echo "<span style='color: gray;'>⏭ DNI 1710 ignorado</span><br>";
                    }
                    continue;
                }
                
                if (empty($matricula) || empty($fecha)) {
                    continue;
                }
                
                // Mapear estado
                $estado = 'presente';
                if ($porcentaje == 100) $estado = 'ausente';
                elseif ($porcentaje == 50) $estado = 'media_falta';
                elseif ($porcentaje == 25) $estado = 'cuarto_falta';
                
                // Buscar estudiante
                $stmtBuscar->execute([$matricula]);
                $estudiante = $stmtBuscar->fetch();
                
                if (!$estudiante) {
                    if (!in_array($matricula, $noEncontrados)) {
                        $noEncontrados[] = $matricula;
                        if (count($noEncontrados) <= 5) {
                            echo "<span style='color: red;'>✗ DNI $matricula no encontrado</span><br>";
                        }
                    }
                    $errores++;
                    continue;
                }
                
                // Determinar cuatrimestre
                $cuatrimestre = (strtotime($fecha) < strtotime('2025-08-01')) ? 1 : 2;
                
                // Insertar
                $stmtInsertar->execute([
                    $estudiante['estudiante_id'],
                    $estudiante['curso_id'],
                    $fecha,
                    $estado,
                    $detalle,
                    $cuatrimestre
                ]);
                
                $procesados++;
                
                if ($procesados <= 10 || $procesados % 100 === 0) {
                    echo "<span style='color: green;'>✓ $procesados: DNI $matricula - $fecha - $estado</span><br>";
                }
                
            } catch (Exception $e) {
                $errores++;
                echo "<span style='color: red;'>✗ Error línea $lineNumber: " . htmlspecialchars($e->getMessage()) . "</span><br>";
            }
        }
        
        fclose($handle);
        
        if ($procesados > 0) {
            $conn->exec('COMMIT');
            echo "<br><span style='color: green; font-weight: bold; background: #d4edda; padding: 10px; border-radius: 5px;'>✅ IMPORTACIÓN EXITOSA</span><br>";
        } else {
            $conn->exec('ROLLBACK');
            echo "<br><span style='color: red; font-weight: bold; background: #f8d7da; padding: 10px; border-radius: 5px;'>❌ IMPORTACIÓN CANCELADA</span><br>";
        }
        
        echo "<br><strong>📊 RESUMEN FINAL:</strong><br>";
        echo "✅ Procesados: $procesados<br>";
        echo "❌ Errores: $errores<br>";
        echo "🚫 DNI 1710 ignorados: $ignorados1710<br>";
        echo "👤 Estudiantes no encontrados: " . count($noEncontrados) . "<br>";
        
        if (!empty($noEncontrados)) {
            echo "<br><strong>DNIs no encontrados:</strong> " . implode(', ', array_slice($noEncontrados, 0, 10));
            if (count($noEncontrados) > 10) echo " ... y " . (count($noEncontrados) - 10) . " más";
        }
        
        echo "</div>";
        echo "</div>";
    }
    
    // Interfaz
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔧 Herramientas de Debug</h3>";
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='analizar_csv' value='1' style='background: #17a2b8; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;'>";
    echo "🔍 ANALIZAR ARCHIVO CSV";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'>Revisa la estructura y primeras líneas del CSV</small>";
    echo "</form>";
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='importar_simple' value='1' style='background: #007bff; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer;'>";
    echo "📥 IMPORTAR CON DEBUG";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'>Importa con información detallada de debug</small>";
    echo "</form>";
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='solucionador_sqlite.php' style='background: #6c757d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>← Volver al Solucionador</a></p>";
?>
