<?php
/**
 * importador_texto_puro.php - Solución definitiva para CSV mal formateado
 * Procesa el archivo como texto puro línea por línea
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🛠️ Importador de Texto Puro</h1>";
echo "<p>Solución definitiva para CSV mal formateado - Procesa como texto puro</p>";

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
        echo "</div>";
        exit;
    }
    
    if (isset($_POST['ver_contenido_crudo'])) {
        echo "<h2>🔍 CONTENIDO CRUDO DEL ARCHIVO</h2>";
        echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        
        $contenido = file_get_contents($archivoCsv);
        $lineas = explode("\n", $contenido);
        
        echo "<p><strong>Total de líneas:</strong> " . count($lineas) . "</p>";
        echo "<p><strong>Tamaño del archivo:</strong> " . number_format(strlen($contenido)) . " caracteres</p>";
        
        echo "<h4>Primeras 10 líneas (texto crudo):</h4>";
        echo "<div style='max-height: 300px; overflow-y: auto; background: white; padding: 10px; border-radius: 3px; font-family: monospace; font-size: 11px;'>";
        
        for ($i = 0; $i < min(10, count($lineas)); $i++) {
            $lineNum = $i + 1;
            $linea = $lineas[$i];
            echo "<strong>Línea $lineNum:</strong><br>";
            echo "<pre style='margin: 5px 0; background: #f8f9fa; padding: 5px; border-radius: 2px;'>" . htmlspecialchars($linea) . "</pre>";
            
            // Analizar caracteres especiales
            if (strlen($linea) > 0) {
                echo "<small style='color: #666;'>Caracteres: " . strlen($linea) . " | ";
                echo "Comas: " . substr_count($linea, ',') . " | ";
                echo "Comillas: " . substr_count($linea, '"') . "</small><br><br>";
            }
        }
        
        echo "</div>";
        echo "</div>";
    }
    
    if (isset($_POST['importar_texto_puro'])) {
        echo "<h2>📥 IMPORTACIÓN COMO TEXTO PURO</h2>";
        echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<div style='max-height: 500px; overflow-y: auto; background: white; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px;'>";
        
        $contenido = file_get_contents($archivoCsv);
        $lineas = explode("\n", $contenido);
        
        echo "<span style='color: blue; font-weight: bold;'>📂 Archivo leído: " . count($lineas) . " líneas</span><br>";
        
        $conn = $db->getConnection();
        $conn->exec('BEGIN TRANSACTION');
        echo "<span style='color: green; font-weight: bold;'>🔄 Transacción iniciada</span><br><br>";
        
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
        
        $procesados = 0;
        $errores = 0;
        $ignorados1710 = 0;
        $noEncontrados = [];
        $saltados = 0;
        
        foreach ($lineas as $numeroLinea => $linea) {
            $lineNumber = $numeroLinea + 1;
            
            // Saltar primera línea (encabezados)
            if ($lineNumber === 1) {
                echo "<span style='color: gray;'>⏭ Línea 1: Encabezados saltados</span><br>";
                continue;
            }
            
            $linea = trim($linea);
            if (empty($linea)) {
                continue;
            }
            
            try {
                // MÚLTIPLES MÉTODOS DE PARSING
                $datos = null;
                
                // MÉTODO 1: Regex específico para el formato detectado
                // Patrón: numero,"numero","fecha","texto con comas posibles","numero","numero"
                if (preg_match('/^(\d+),"(\d+)","([^"]+)","([^"]+(?:"[^"]*"[^"]*)*)","(\d+)","(\d+)"$/', $linea, $matches)) {
                    $datos = [
                        'matricula' => $matches[1],
                        'porcentaje' => intval($matches[2]),
                        'fecha' => $matches[3],
                        'detalle' => $matches[4],
                        'id' => $matches[5],
                        'justificada' => intval($matches[6])
                    ];
                    if ($lineNumber <= 5) {
                        echo "<span style='color: purple;'>✓ Método 1 (regex): DNI={$datos['matricula']}</span><br>";
                    }
                }
                
                // MÉTODO 2: Separación manual más agresiva
                if (!$datos) {
                    // Remover comillas del inicio y final si existen
                    $lineaLimpia = trim($linea, '"');
                    
                    // Buscar patrón específico: numero,numero,fecha,texto,numero,numero
                    $partes = [];
                    
                    // Extraer primer número (matricula)
                    if (preg_match('/^(\d+),/', $lineaLimpia, $match)) {
                        $partes[] = $match[1];
                        $resto = substr($lineaLimpia, strlen($match[0]));
                        
                        // Extraer segundo número (porcentaje)
                        if (preg_match('/^"?(\d+)"?,/', $resto, $match)) {
                            $partes[] = intval($match[1]);
                            $resto = substr($resto, strlen($match[0]));
                            
                            // Extraer fecha
                            if (preg_match('/^"?([^"]+?)"?,/', $resto, $match)) {
                                $partes[] = trim($match[1], '"');
                                $resto = substr($resto, strlen($match[0]));
                                
                                // El resto es más complejo, usar posición de las últimas dos comas
                                $ultimaComa = strrpos($resto, ',');
                                $penultimaComa = strrpos($resto, ',', $ultimaComa - strlen($resto) - 1);
                                
                                if ($ultimaComa !== false && $penultimaComa !== false) {
                                    $detalle = trim(substr($resto, 0, $penultimaComa), '", ');
                                    $id = trim(substr($resto, $penultimaComa + 1, $ultimaComa - $penultimaComa - 1), '", ');
                                    $justificada = trim(substr($resto, $ultimaComa + 1), '", ');
                                    
                                    $datos = [
                                        'matricula' => $partes[0],
                                        'porcentaje' => $partes[1],
                                        'fecha' => $partes[2],
                                        'detalle' => $detalle,
                                        'id' => $id,
                                        'justificada' => intval($justificada)
                                    ];
                                    
                                    if ($lineNumber <= 5) {
                                        echo "<span style='color: orange;'>✓ Método 2 (manual): DNI={$datos['matricula']}</span><br>";
                                    }
                                }
                            }
                        }
                    }
                }
                
                // MÉTODO 3: Fallback con str_getcsv en la línea cruda
                if (!$datos) {
                    $campos = str_getcsv($linea, ',', '"', '\\');
                    if (count($campos) >= 6) {
                        $datos = [
                            'matricula' => trim($campos[0]),
                            'porcentaje' => intval($campos[1]),
                            'fecha' => trim($campos[2]),
                            'detalle' => trim($campos[3]),
                            'id' => $campos[4],
                            'justificada' => intval($campos[5])
                        ];
                        
                        if ($lineNumber <= 5) {
                            echo "<span style='color: cyan;'>✓ Método 3 (str_getcsv): DNI={$datos['matricula']}</span><br>";
                        }
                    }
                }
                
                // Si ningún método funcionó
                if (!$datos) {
                    if ($saltados < 10) {
                        echo "<span style='color: red;'>✗ Línea $lineNumber: No se pudo parsear</span><br>";
                        echo "<span style='color: gray;'>Contenido: " . htmlspecialchars(substr($linea, 0, 100)) . "...</span><br>";
                    }
                    $saltados++;
                    continue;
                }
                
                // Validar datos extraídos
                $matricula = $datos['matricula'];
                $porcentaje = $datos['porcentaje'];
                $fecha = $datos['fecha'];
                $detalle = $datos['detalle'];
                $justificada = $datos['justificada'];
                
                if (empty($matricula) || empty($fecha)) {
                    if ($saltados < 5) {
                        echo "<span style='color: orange;'>⚠ Línea $lineNumber: Matrícula o fecha vacía</span><br>";
                    }
                    $saltados++;
                    continue;
                }
                
                // Ignorar DNI 1710
                if ($matricula === '1710') {
                    $ignorados1710++;
                    if ($ignorados1710 <= 3) {
                        echo "<span style='color: gray;'>⏭ DNI 1710 ignorado</span><br>";
                    }
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
                
                // Insertar
                $stmtInsertar->execute([
                    $estudiante['estudiante_id'],
                    $estudiante['curso_id'],
                    $fecha,
                    $estado,
                    $detalle, // Va a columna 'observaciones'
                    $cuatrimestre
                ]);
                
                $procesados++;
                
                if ($procesados <= 15 || $procesados % 200 === 0) {
                    echo "<span style='color: green;'>✓ $procesados: DNI $matricula - $fecha - $estado</span><br>";
                }
                
            } catch (Exception $e) {
                $errores++;
                if ($errores <= 5) {
                    echo "<span style='color: red;'>✗ Error línea $lineNumber: " . htmlspecialchars($e->getMessage()) . "</span><br>";
                }
            }
        }
        
        // Resultado final
        if ($procesados > 0) {
            $conn->exec('COMMIT');
            echo "<br><span style='color: green; font-weight: bold; background: #d4edda; padding: 10px; border-radius: 5px;'>🎉 IMPORTACIÓN EXITOSA</span><br>";
        } else {
            $conn->exec('ROLLBACK');
            echo "<br><span style='color: red; font-weight: bold; background: #f8d7da; padding: 10px; border-radius: 5px;'>❌ IMPORTACIÓN CANCELADA</span><br>";
        }
        
        echo "<br><div style='background: white; border: 2px solid #28a745; padding: 20px; border-radius: 8px;'>";
        echo "<h4 style='color: #28a745; margin-top: 0;'>📊 RESUMEN FINAL</h4>";
        
        $totalProcesado = $procesados + $errores + $saltados + $ignorados1710;
        
        echo "<table style='width: 100%; border-collapse: collapse;'>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🆕 Registros importados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: green; font-weight: bold; font-size: 20px;'>$procesados</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>❌ Errores:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: red; font-weight: bold;'>$errores</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>⏭ Saltados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: orange; font-weight: bold;'>$saltados</td></tr>";
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🚫 DNI 1710 ignorados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: gray; font-weight: bold;'>$ignorados1710</td></tr>";
        echo "<tr><td style='padding: 8px;'><strong>👤 No encontrados:</strong></td><td style='padding: 8px; color: orange; font-weight: bold;'>" . count($noEncontrados) . "</td></tr>";
        echo "</table>";
        
        if ($procesados > 0) {
            echo "<br><div style='background: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px;'>";
            echo "<h5 style='color: #0c5460; margin-top: 0;'>🎯 ¡MISIÓN CUMPLIDA!</h5>";
            echo "<p style='color: #0c5460; margin-bottom: 0;'>Se han importado <strong>$procesados registros de asistencias</strong> exitosamente a pesar del CSV mal formateado.</p>";
            echo "</div>";
        }
        
        echo "</div>";
        
        echo "</div>";
        echo "</div>";
    }
    
    // Interfaz
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🛠️ Herramientas de Procesamiento</h3>";
    
    echo "<div style='background: #e9ecef; border-left: 4px solid #6c757d; padding: 15px; margin: 15px 0;'>";
    echo "<h4 style='color: #495057; margin-top: 0;'>🔍 ESTRATEGIA AVANZADA</h4>";
    echo "<p>Este importador usa <strong>3 métodos diferentes</strong> para procesar cada línea:</p>";
    echo "<ul>";
    echo "<li><strong>Método 1:</strong> Regex específico para el patrón detectado</li>";
    echo "<li><strong>Método 2:</strong> Parser manual que ignora delimitadores</li>";
    echo "<li><strong>Método 3:</strong> str_getcsv como fallback</li>";
    echo "</ul>";
    echo "<p style='margin-bottom: 0;'>Si un método falla, automáticamente prueba el siguiente.</p>";
    echo "</div>";
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='ver_contenido_crudo' value='1' style='background: #6c757d; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;'>";
    echo "🔍 VER CONTENIDO CRUDO";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'>Examina el archivo línea por línea como texto puro</small>";
    echo "</form>";
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='importar_texto_puro' value='1' style='background: #28a745; color: white; padding: 15px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold;'>";
    echo "🚀 IMPORTAR COMO TEXTO PURO";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'><strong>RECOMENDADO:</strong> Funciona sin importar cómo esté formateado el CSV</small>";
    echo "</form>";
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<div style='margin: 20px 0;'>";
echo "<a href='asistencias.php' style='background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; margin-right: 15px; font-weight: bold;'>📋 Ver Asistencias</a>";
echo "<a href='index.php' style='background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>🏠 Panel Principal</a>";
echo "</div>";
?>
