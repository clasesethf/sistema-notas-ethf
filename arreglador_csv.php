<?php
/**
 * arreglador_csv.php - Arregla CSV con delimitadores incorrectos e importa
 * Soluciona el problema de columnas fusionadas en una sola celda
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🔧 Arreglador de CSV</h1>";
echo "<p>Soluciona el problema de delimitadores incorrectos y hace la importación</p>";

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
        echo "<p>Coloca el archivo <code>inasistencias_ethf.csv</code> en la raíz del proyecto.</p>";
        echo "</div>";
        exit;
    }
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p>✅ <strong>Archivo encontrado:</strong> $archivoCsv</p>";
    echo "</div>";
    
    if (isset($_POST['analizar_problema'])) {
        echo "<h2>🔍 ANÁLISIS DEL PROBLEMA</h2>";
        echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;'>";
        
        $handle = fopen($archivoCsv, 'r');
        $primeraLinea = fgets($handle);
        echo "<strong>📋 Primera línea del archivo:</strong><br>";
        echo "<pre style='background: white; padding: 10px; border-radius: 3px;'>" . htmlspecialchars($primeraLinea) . "</pre>";
        
        // Probar diferentes delimitadores
        $delimitadores = [',', ';', '|', "\t"];
        
        fseek($handle, 0); // Volver al inicio
        foreach ($delimitadores as $delim) {
            $headers = fgetcsv($handle, 0, $delim, '"', '\\');
            echo "<strong>Con delimitador '$delim':</strong> " . count($headers) . " columnas<br>";
            if (count($headers) >= 6) {
                echo "<span style='color: green;'>✅ ¡Este delimitador funciona!</span><br>";
                echo "<span style='color: blue;'>Columnas: " . implode(' | ', $headers) . "</span><br>";
            }
            fseek($handle, 0); // Resetear para siguiente prueba
        }
        
        // Detectar automáticamente
        fseek($handle, 0);
        $content = fread($handle, 1000); // Leer primeros 1000 caracteres
        
        echo "<br><strong>🔍 Detección automática:</strong><br>";
        echo "Comas encontradas: " . substr_count($content, ',') . "<br>";
        echo "Punto y coma encontrados: " . substr_count($content, ';') . "<br>";
        echo "Pipes encontrados: " . substr_count($content, '|') . "<br>";
        echo "Tabs encontrados: " . substr_count($content, "\t") . "<br>";
        
        fclose($handle);
        echo "</div>";
    }
    
    if (isset($_POST['importar_corregido'])) {
        echo "<h2>📥 IMPORTACIÓN CORREGIDA</h2>";
        echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<div style='max-height: 500px; overflow-y: auto; background: white; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 13px;'>";
        
        $handle = fopen($archivoCsv, 'r');
        
        // Detectar delimitador correcto
        $primeraLinea = fgets($handle);
        $delimitadorCorrecto = ','; // Por defecto
        
        // Si hay más comas que punto y coma, usar coma
        if (substr_count($primeraLinea, ',') > substr_count($primeraLinea, ';')) {
            $delimitadorCorrecto = ',';
        } elseif (substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',')) {
            $delimitadorCorrecto = ';';
        }
        
        echo "<span style='color: blue;'>🔍 Delimitador detectado: '$delimitadorCorrecto'</span><br>";
        
        fseek($handle, 0); // Volver al inicio
        
        // MÉTODO ALTERNATIVO: Leer como texto plano y parsear manualmente
        echo "<span style='color: purple;'>🔧 Usando parser manual para datos mal formateados...</span><br><br>";
        
        $conn = $db->getConnection();
        $conn->exec('BEGIN TRANSACTION');
        echo "<span style='color: green; font-weight: bold;'>🔄 Transacción iniciada</span><br>";
        
        $procesados = 0;
        $errores = 0;
        $ignorados1710 = 0;
        $noEncontrados = [];
        $lineNumber = 0;
        
        // Preparar consultas
        $stmtBuscar = $conn->prepare(
            "SELECT u.id as estudiante_id, m.curso_id 
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE u.dni = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
             LIMIT 1"
        );
        
        $stmtInsertar = $conn->prepare(
            "INSERT INTO asistencias 
             (estudiante_id, curso_id, fecha, estado, observaciones, cuatrimestre) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        while (($linea = fgets($handle)) !== false && $procesados < 2100) {
            $lineNumber++;
            
            // Saltar encabezados
            if ($lineNumber === 1) continue;
            
            try {
                // Limpiar la línea
                $linea = trim($linea);
                if (empty($linea)) continue;
                
                // PARSER MANUAL para datos con formato CSV problemático
                // Buscar patrón: numero,"numero","fecha","texto","numero","numero"
                if (preg_match('/^(\d+),"(\d+)","([^"]+)","([^"]+)","(\d+)","(\d+)"$/', $linea, $matches)) {
                    $matricula = $matches[1];
                    $porcentaje = intval($matches[2]);
                    $fecha = $matches[3];
                    $detalle = $matches[4];
                    $id_original = $matches[5];
                    $justificada = intval($matches[6]);
                } else {
                    // Método alternativo: separar por comas respetando comillas
                    $campos = str_getcsv($linea, ',', '"', '\\');
                    
                    if (count($campos) < 6) {
                        if ($lineNumber <= 10) {
                            echo "<span style='color: orange;'>⚠ Línea $lineNumber: Solo " . count($campos) . " campos</span><br>";
                        }
                        continue;
                    }
                    
                    $matricula = trim($campos[0]);
                    $porcentaje = intval($campos[1]);
                    $fecha = trim($campos[2]);
                    $detalle = trim($campos[3]);
                    $id_original = intval($campos[4]);
                    $justificada = intval($campos[5]);
                }
                
                // Debug para primeras líneas
                if ($lineNumber <= 5) {
                    echo "<span style='color: purple;'>DEBUG línea $lineNumber: DNI='$matricula', %=$porcentaje, fecha='$fecha'</span><br>";
                }
                
                // Ignorar DNI 1710
                if ($matricula === '1710') {
                    $ignorados1710++;
                    if ($ignorados1710 <= 3) {
                        echo "<span style='color: gray;'>⏭ DNI 1710 ignorado (usuario de prueba)</span><br>";
                    }
                    continue;
                }
                
                if (empty($matricula) || empty($fecha)) {
                    continue;
                }
                
                // Mapear estado
                $estado = 'presente';
                switch ($porcentaje) {
                    case 100: $estado = 'ausente'; break;
                    case 50:  $estado = 'media_falta'; break;
                    case 25:  $estado = 'cuarto_falta'; break;
                }
                
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
                
                // Insertar (cambié 'detalle' por 'observaciones' como dijiste)
                $stmtInsertar->execute([
                    $estudiante['estudiante_id'],
                    $estudiante['curso_id'],
                    $fecha,
                    $estado,
                    $detalle, // Esto va a la columna 'observaciones'
                    $cuatrimestre
                ]);
                
                $procesados++;
                
                if ($procesados <= 10 || $procesados % 100 === 0) {
                    echo "<span style='color: green;'>✓ $procesados: DNI $matricula - $fecha - $estado</span><br>";
                }
                
            } catch (Exception $e) {
                $errores++;
                if ($errores <= 5) {
                    echo "<span style='color: red;'>✗ Error línea $lineNumber: " . htmlspecialchars($e->getMessage()) . "</span><br>";
                }
            }
        }
        
        fclose($handle);
        
        // Resultado
        if ($procesados > 0) {
            $conn->exec('COMMIT');
            echo "<br><span style='color: green; font-weight: bold; background: #d4edda; padding: 10px; border-radius: 5px;'>🎉 IMPORTACIÓN EXITOSA</span><br>";
        } else {
            $conn->exec('ROLLBACK');
            echo "<br><span style='color: red; font-weight: bold; background: #f8d7da; padding: 10px; border-radius: 5px;'>❌ IMPORTACIÓN CANCELADA</span><br>";
        }
        
        echo "<br><div style='background: white; border: 2px solid #007bff; padding: 20px; border-radius: 8px;'>";
        echo "<h4 style='color: #007bff; margin-top: 0;'>📊 RESUMEN FINAL</h4>";
        echo "<table style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🆕 Registros importados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: green; font-weight: bold; font-size: 18px;'>$procesados</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>❌ Errores:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: red; font-weight: bold;'>$errores</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🚫 DNI 1710 ignorados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: gray; font-weight: bold;'>$ignorados1710</td></tr>";
        echo "<tr><td style='padding: 8px;'><strong>👤 Estudiantes no encontrados:</strong></td><td style='padding: 8px; color: orange; font-weight: bold;'>" . count($noEncontrados) . "</td></tr>";
        echo "</table>";
        
        if (!empty($noEncontrados)) {
            echo "<br><p><strong>🔍 DNIs no encontrados:</strong></p>";
            echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 3px;'>";
            foreach (array_slice($noEncontrados, 0, 15) as $dni) {
                echo "<code style='margin: 2px; padding: 2px 5px; background: #e9ecef; border-radius: 2px;'>$dni</code> ";
            }
            if (count($noEncontrados) > 15) {
                echo "<br><em>... y " . (count($noEncontrados) - 15) . " más</em>";
            }
            echo "</div>";
        }
        
        if ($procesados > 0) {
            echo "<br><div style='background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px;'>";
            echo "<h5 style='color: #0c5460; margin-top: 0;'>🎯 ¡Importación completada!</h5>";
            echo "<p style='color: #0c5460; margin-bottom: 0;'>Se han importado exitosamente <strong>$procesados registros</strong> de asistencias.</p>";
            echo "</div>";
        }
        
        echo "</div>";
        
        echo "</div>";
        echo "</div>";
    }
    
    // Interfaz
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔧 Herramientas</h3>";
    
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
    echo "<h4 style='color: #856404; margin-top: 0;'>🐛 PROBLEMA DETECTADO</h4>";
    echo "<p>Tu CSV tiene <strong>todas las columnas fusionadas en una sola celda</strong>. Esto indica un problema de delimitadores.</p>";
    echo "<p><strong>Ejemplo de lo que encontramos:</strong></p>";
    echo "<code style='background: #f8f9fa; padding: 5px; border-radius: 3px;'>[1710,\"50\",\"2025-04-28\",\"Retiro anticipado...\"]</code>";
    echo "<p style='margin-bottom: 0;'>Debería ser 6 columnas separadas, pero está todo en una.</p>";
    echo "</div>";
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='analizar_problema' value='1' style='background: #17a2b8; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;'>";
    echo "🔍 ANALIZAR PROBLEMA DE DELIMITADORES";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'>Detecta qué delimitador usar (coma, punto y coma, etc.)</small>";
    echo "</form>";
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='importar_corregido' value='1' style='background: #28a745; color: white; padding: 15px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold;'>";
    echo "🔧 IMPORTAR CON CORRECCIÓN AUTOMÁTICA";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'>Usa parser manual que ignora delimitadores problemáticos</small>";
    echo "</form>";
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='asistencias.php' style='background: #17a2b8; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>📋 Ver Asistencias</a>";
echo "<a href='index.php' style='background: #6c757d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🏠 Panel Principal</a></p>";
?>
