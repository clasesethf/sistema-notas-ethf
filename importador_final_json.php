<?php
/**
 * importador_final_json.php - Importador optimizado para archivo JSON
 * Adaptado para usar inasistencias_ethf.json en lugar de CSV
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🚀 Importador Final de Asistencias (JSON)</h1>";
echo "<p>Optimizado para tu estructura SQLite específica - Versión JSON</p>";

try {
    $db = Database::getInstance();
    
    // PASO 1: Verificar DNIs a procesar (excluir 1710)
    echo "<h2>👥 PASO 1: Configuración de importación</h2>";
    
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>📋 Configuración actual:</h4>";
    echo "<ul>";
    echo "<li>✅ <strong>DNIs válidos:</strong> Todos excepto 1710 (usuario de prueba)</li>";
    echo "<li>✅ <strong>Estados permitidos:</strong> presente, ausente, media_falta, cuarto_falta, justificada</li>";
    echo "<li>✅ <strong>Período:</strong> Desde marzo 2025 hasta agosto 2025</li>";
    echo "<li>✅ <strong>Duplicados:</strong> Se actualizarán automáticamente</li>";
    echo "<li>🆕 <strong>Formato:</strong> JSON (inasistencias_ethf.json)</li>";
    echo "</ul>";
    echo "</div>";
    
    // Verificar DNIs principales (excluyendo 1710)
    $dnisImportantes = ['2021', '2022', '2023'];
    echo "<h3>🔍 Verificando DNIs principales (sin 1710):</h3>";
    
    foreach ($dnisImportantes as $dni) {
        $estudiante = $db->fetchOne(
            "SELECT u.id, u.nombre, u.apellido, 
                    m.curso_id, c.nombre as curso_nombre, c.anio
             FROM usuarios u 
             LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
             LEFT JOIN cursos c ON m.curso_id = c.id
             WHERE u.dni = ? AND u.tipo = 'estudiante'",
            [$dni]
        );
        
        if ($estudiante) {
            $curso = $estudiante['curso_id'] ? "{$estudiante['anio']}° {$estudiante['curso_nombre']}" : 'Sin matrícula';
            echo "<p>✅ <strong>DNI $dni:</strong> {$estudiante['nombre']} {$estudiante['apellido']} - $curso</p>";
        } else {
            echo "<p>❌ <strong>DNI $dni:</strong> No encontrado</p>";
        }
    }
    
    // PASO 2: Importación de asistencias
    echo "<h2>📥 PASO 2: Importación de Asistencias JSON</h2>";
    
    if (isset($_POST['importar_asistencias'])) {
        // Buscar archivo JSON
        $archivoJson = null;
        $posiblesArchivos = [
            'inasistencias_ethf.json',
            'database/inasistencias_ethf.json',
            __DIR__ . '/inasistencias_ethf.json'
        ];
        
        foreach ($posiblesArchivos as $archivo) {
            if (file_exists($archivo)) {
                $archivoJson = $archivo;
                break;
            }
        }
        
        if (!$archivoJson) {
            echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
            echo "<h4>❌ Archivo JSON no encontrado</h4>";
            echo "<p><strong>Archivos buscados:</strong></p>";
            echo "<ul>";
            foreach ($posiblesArchivos as $archivo) {
                echo "<li><code>$archivo</code></li>";
            }
            echo "</ul>";
            echo "<p><strong>Solución:</strong> Coloca el archivo <code>inasistencias_ethf.json</code> en la raíz del proyecto.</p>";
            echo "</div>";
        } else {
            echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4>📂 Procesando: $archivoJson</h4>";
            echo "<div id='progreso' style='max-height: 500px; overflow-y: auto; background: white; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 13px;'>";
            
            try {
                // Leer archivo JSON
                $jsonContent = file_get_contents($archivoJson);
                if ($jsonContent === false) {
                    throw new Exception('No se puede leer el archivo JSON');
                }
                
                $data = json_decode($jsonContent, true);
                if ($data === null) {
                    throw new Exception('Error al decodificar el archivo JSON: ' . json_last_error_msg());
                }
                
                echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 3px; margin-bottom: 10px;'>";
                echo "<strong>📊 Archivo JSON cargado:</strong> " . count($data) . " registros encontrados";
                echo "<br><strong>📋 Estructura detectada:</strong> matricula, porcentaje_falta, fecha, detalle, id, justificada";
                echo "</div>";
                
                $conn = $db->getConnection();
                $conn->exec('BEGIN TRANSACTION');
                echo "<span style='color: green; font-weight: bold;'>🔄 Transacción iniciada</span><br><br>";
                
                // Contadores
                $procesados = 0;
                $actualizados = 0;
                $errores = 0;
                $saltados = 0;
                $ignorados1710 = 0;
                $noEncontrados = [];
                
                // Preparar consultas optimizadas
                $stmtBuscarEstudiante = $conn->prepare(
                    "SELECT u.id as estudiante_id, m.curso_id 
                     FROM usuarios u 
                     JOIN matriculas m ON u.id = m.estudiante_id 
                     WHERE u.dni = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                     LIMIT 1"
                );
                
                $stmtVerificarExiste = $conn->prepare(
                    "SELECT id FROM asistencias 
                     WHERE estudiante_id = ? AND fecha = ? AND curso_id = ?"
                );
                
                $stmtInsertar = $conn->prepare(
                    "INSERT INTO asistencias 
                     (estudiante_id, curso_id, fecha, estado, observaciones, cuatrimestre, motivo_falta, motivo_otro) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                $stmtActualizar = $conn->prepare(
                    "UPDATE asistencias SET 
                     estado = ?, observaciones = ?, cuatrimestre = ?, motivo_falta = ?, motivo_otro = ?
                     WHERE id = ?"
                );
                
                $numeroRegistro = 0;
                foreach ($data as $registro) {
                    $numeroRegistro++;
                    
                    try {
                        // Extraer datos del JSON
                        $matricula = strval($registro['matricula'] ?? '');
                        $porcentaje = intval($registro['porcentaje_falta'] ?? 0);
                        $fecha = trim($registro['fecha'] ?? '');
                        $detalle = trim($registro['detalle'] ?? '');
                        $idOriginal = intval($registro['id'] ?? 0);
                        $justificada = intval($registro['justificada'] ?? 0);
                        
                        // Validar datos críticos
                        if (empty($matricula) || empty($fecha)) {
                            echo "<span style='color: orange;'>⚠ Registro $numeroRegistro: Matrícula o fecha vacía</span><br>";
                            $saltados++;
                            continue;
                        }
                        
                        // FILTRO: Ignorar DNI 1710 (usuario de prueba)
                        if ($matricula === '1710') {
                            if ($ignorados1710 < 5) {
                                echo "<span style='color: #6c757d;'>⏭ DNI 1710 ignorado (usuario de prueba)</span><br>";
                            }
                            $ignorados1710++;
                            continue;
                        }
                        
                        // Validar formato de fecha
                        $fechaTimestamp = strtotime($fecha);
                        if (!$fechaTimestamp) {
                            echo "<span style='color: orange;'>⚠ Registro $numeroRegistro: Fecha inválida '$fecha'</span><br>";
                            $saltados++;
                            continue;
                        }
                        
                        // Mapear estado según tu estructura
                        $estado = 'presente';
                        switch ($porcentaje) {
                            case 100: $estado = 'ausente'; break;
                            case 50:  $estado = 'media_falta'; break;
                            case 25:  $estado = 'cuarto_falta'; break;
                            case 0:   $estado = 'presente'; break;
                            default:  $estado = 'ausente'; break;
                        }
                        
                        // Determinar motivo según justificación
                        $motivo = null;
                        $motivoOtro = null;
                        if ($justificada == 1) {
                            $detalleMin = strtolower($detalle);
                            if (strpos($detalleMin, 'tarde') !== false) {
                                $motivo = 'problema_transporte';
                            } elseif (strpos($detalleMin, 'enfermedad') !== false) {
                                $motivo = 'enfermedad';
                            } elseif (strpos($detalleMin, 'médic') !== false) {
                                $motivo = 'consulta_medica';
                            } else {
                                $motivo = 'otro';
                                $motivoOtro = $detalle;
                            }
                        }
                        
                        // Determinar cuatrimestre
                        $cuatrimestre = ($fechaTimestamp < strtotime('2025-08-01')) ? 1 : 2;
                        
                        // Buscar estudiante
                        $stmtBuscarEstudiante->execute([$matricula]);
                        $estudiante = $stmtBuscarEstudiante->fetch();
                        
                        if (!$estudiante) {
                            if (!in_array($matricula, $noEncontrados)) {
                                $noEncontrados[] = $matricula;
                                echo "<span style='color: red;'>✗ Estudiante DNI '$matricula' no encontrado</span><br>";
                            }
                            $errores++;
                            continue;
                        }
                        
                        // Verificar si ya existe
                        $stmtVerificarExiste->execute([
                            $estudiante['estudiante_id'], 
                            $fecha, 
                            $estudiante['curso_id']
                        ]);
                        $existe = $stmtVerificarExiste->fetch();
                        
                        if ($existe) {
                            // Actualizar registro existente
                            $stmtActualizar->execute([
                                $estado,
                                $detalle,
                                $cuatrimestre,
                                $motivo,
                                $motivoOtro,
                                $existe['id']
                            ]);
                            
                            $actualizados++;
                            echo "<span style='color: blue;'>↻ DNI $matricula - $fecha - $estado (actualizado)</span><br>";
                        } else {
                            // Insertar nuevo registro
                            $stmtInsertar->execute([
                                $estudiante['estudiante_id'],
                                $estudiante['curso_id'],
                                $fecha,
                                $estado,
                                $detalle,
                                $cuatrimestre,
                                $motivo,
                                $motivoOtro
                            ]);
                            
                            $procesados++;
                            echo "<span style='color: green;'>✓ DNI $matricula - $fecha - $estado (nuevo)</span><br>";
                        }
                        
                        // Mostrar progreso cada 100 registros
                        if (($procesados + $actualizados) % 100 === 0) {
                            $total = $procesados + $actualizados;
                            echo "<div style='background: #e7f3ff; padding: 8px; margin: 8px 0; border-radius: 4px; font-weight: bold;'>";
                            echo "📊 PROGRESO: $total registros procesados | ✅ Nuevos: $procesados | 🔄 Actualizados: $actualizados | ❌ Errores: $errores";
                            echo "</div>";
                            flush();
                        }
                        
                        // Límite de seguridad
                        if ($procesados + $actualizados >= 2100) {
                            echo "<div style='background: #fff3cd; padding: 8px; margin: 8px 0; border-radius: 4px; font-weight: bold;'>";
                            echo "⚠️ Límite de seguridad alcanzado (2100 registros). Procesamiento detenido.";
                            echo "</div>";
                            break;
                        }
                        
                    } catch (Exception $e) {
                        $errores++;
                        echo "<span style='color: red;'>✗ Error registro $numeroRegistro: " . htmlspecialchars($e->getMessage()) . "</span><br>";
                    }
                }
                
                // Resultado de la transacción
                $totalProcesados = $procesados + $actualizados;
                if ($totalProcesados > 0 && $errores < $totalProcesados) {
                    $conn->exec('COMMIT');
                    echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; font-weight: bold;'>";
                    echo "<span style='color: green; font-size: 18px;'>✅ IMPORTACIÓN EXITOSA</span>";
                    echo "</div>";
                } else {
                    $conn->exec('ROLLBACK');
                    echo "<br><div style='background: #f8d7da; padding: 15px; border-radius: 5px; font-weight: bold;'>";
                    echo "<span style='color: red; font-size: 18px;'>❌ IMPORTACIÓN CANCELADA</span>";
                    echo "</div>";
                }
                
                // Mostrar resumen de DNI 1710 si se encontraron registros
                if ($ignorados1710 > 0) {
                    echo "<div style='background: #f8f9fa; border-left: 4px solid #6c757d; padding: 10px; margin: 10px 0;'>";
                    echo "<span style='color: #6c757d; font-weight: bold;'>📋 Se ignoraron $ignorados1710 registros del DNI 1710 (usuario de prueba)</span>";
                    echo "</div>";
                }
                
                // Resumen final
                echo "<br><div style='background: white; border: 2px solid #007bff; padding: 20px; border-radius: 8px; font-size: 16px;'>";
                echo "<h4 style='margin-top: 0; color: #007bff;'>📊 RESUMEN FINAL</h4>";
                echo "<table style='width: 100%; border-collapse: collapse;'>";
                echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🆕 Registros nuevos:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: green; font-weight: bold;'>$procesados</td></tr>";
                echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🔄 Registros actualizados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: blue; font-weight: bold;'>$actualizados</td></tr>";
                echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>❌ Errores:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: red; font-weight: bold;'>$errores</td></tr>";
                echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>⏭ Saltados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: orange; font-weight: bold;'>$saltados</td></tr>";
                echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'><strong>🚫 DNI 1710 ignorados:</strong></td><td style='padding: 8px; border-bottom: 1px solid #ddd; color: #6c757d; font-weight: bold;'>$ignorados1710</td></tr>";
                echo "<tr><td style='padding: 8px;'><strong>📈 Total procesado:</strong></td><td style='padding: 8px; color: #007bff; font-weight: bold; font-size: 18px;'>$totalProcesados</td></tr>";
                echo "</table>";
                
                if (!empty($noEncontrados)) {
                    echo "<br><p><strong>👤 Estudiantes no encontrados (" . count($noEncontrados) . "):</strong></p>";
                    echo "<div style='max-height: 100px; overflow-y: auto; background: #f8f9fa; padding: 10px; border-radius: 3px;'>";
                    foreach (array_slice($noEncontrados, 0, 20) as $dni) {
                        echo "<code style='margin: 2px; padding: 2px 5px; background: #e9ecef; border-radius: 2px;'>$dni</code> ";
                    }
                    if (count($noEncontrados) > 20) {
                        echo "<br><em>... y " . (count($noEncontrados) - 20) . " más</em>";
                    }
                    echo "</div>";
                }
                
                echo "</div>";
                
            } catch (Exception $e) {
                try {
                    $conn->exec('ROLLBACK');
                } catch (Exception $rollbackError) {
                    // Ignorar errores de rollback
                }
                echo "<span style='color: red; font-weight: bold; background: #f8d7da; padding: 10px; border-radius: 5px;'>";
                echo "❌ ERROR FATAL: " . htmlspecialchars($e->getMessage());
                echo "</span>";
            }
            
            echo "</div>";
            echo "</div>";
        }
    }
    
    // PASO 3: Estadísticas post-importación (igual que antes)
    if (isset($_POST['ver_estadisticas'])) {
        echo "<h2>📊 PASO 3: Estadísticas de Asistencias</h2>";
        
        try {
            $stats = $db->fetchOne(
                "SELECT 
                    COUNT(*) as total,
                    COUNT(DISTINCT estudiante_id) as estudiantes_con_asistencias,
                    COUNT(DISTINCT fecha) as fechas_con_registros,
                    SUM(CASE WHEN estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                    SUM(CASE WHEN estado = 'ausente' THEN 1 ELSE 0 END) as ausencias,
                    SUM(CASE WHEN estado = 'media_falta' THEN 1 ELSE 0 END) as medias_faltas,
                    SUM(CASE WHEN estado = 'cuarto_falta' THEN 1 ELSE 0 END) as cuartos_falta,
                    SUM(CASE WHEN estado = 'justificada' THEN 1 ELSE 0 END) as justificadas,
                    MIN(fecha) as fecha_min,
                    MAX(fecha) as fecha_max
                 FROM asistencias"
            );
            
            if ($stats && $stats['total'] > 0) {
                echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;'>";
                
                $tarjetas = [
                    ['Total Registros', $stats['total'], '#007bff', '📊'],
                    ['Estudiantes', $stats['estudiantes_con_asistencias'], '#28a745', '👥'],
                    ['Fechas', $stats['fechas_con_registros'], '#ffc107', '📅'],
                    ['Presentes', $stats['presentes'], '#28a745', '✅'],
                    ['Ausencias', $stats['ausencias'], '#dc3545', '❌'],
                    ['Medias Faltas', $stats['medias_faltas'], '#fd7e14', '🟡'],
                    ['Cuartos Falta', $stats['cuartos_falta'], '#6f42c1', '🟣'],
                    ['Justificadas', $stats['justificadas'], '#17a2b8', '📝']
                ];
                
                foreach ($tarjetas as [$titulo, $valor, $color, $icono]) {
                    echo "<div style='background: white; border: 2px solid $color; padding: 20px; border-radius: 10px; text-align: center;'>";
                    echo "<div style='font-size: 30px;'>$icono</div>";
                    echo "<div style='font-size: 28px; font-weight: bold; color: $color;'>$valor</div>";
                    echo "<div style='color: #666; font-size: 14px;'>$titulo</div>";
                    echo "</div>";
                }
                
                echo "</div>";
                
                echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
                echo "<p><strong>📅 Período:</strong> {$stats['fecha_min']} a {$stats['fecha_max']}</p>";
                echo "<p><strong>📊 Promedio por estudiante:</strong> " . round($stats['total'] / $stats['estudiantes_con_asistencias'], 1) . " registros</p>";
                echo "</div>";
                
            } else {
                echo "<p style='color: orange;'>⚠️ No hay datos de asistencias para mostrar estadísticas.</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error obteniendo estadísticas: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // Interfaz de acciones
    echo "<div style='background: #f8f9fa; padding: 25px; border-radius: 10px; margin: 30px 0;'>";
    echo "<h3 style='margin-top: 0;'>🎯 Acciones Disponibles</h3>";
    
    // Verificar si tenemos el archivo JSON
    $tieneJson = file_exists('inasistencias_ethf.json') || file_exists('database/inasistencias_ethf.json');
    
    if (!$tieneJson) {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
        echo "<h4>⚠️ Archivo JSON requerido</h4>";
        echo "<p><strong>Para continuar, necesitas:</strong></p>";
        echo "<ol>";
        echo "<li>Colocar el archivo <code>inasistencias_ethf.json</code> en la raíz del proyecto</li>";
        echo "<li>El archivo debe contener un array JSON con la estructura: matricula, porcentaje_falta, fecha, detalle, id, justificada</li>";
        echo "</ol>";
        echo "</div>";
    }
    
    echo "<div style='display: flex; gap: 15px; flex-wrap: wrap; margin: 20px 0;'>";
    
    // Botón importar (principal)
    echo "<form method='post' style='flex: 1; min-width: 300px;'>";
    $disabled = !$tieneJson ? 'disabled' : '';
    $bgColor = !$tieneJson ? '#6c757d' : '#007bff';
    echo "<button type='submit' name='importar_asistencias' value='1' $disabled style='width: 100%; background: $bgColor; color: white; padding: 15px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: bold;'>";
    echo "📥 IMPORTAR ASISTENCIAS JSON";
    echo "</button>";
    echo "<small style='display: block; margin-top: 8px; color: #666;'>Procesa todos los registros del archivo JSON</small>";
    echo "</form>";
    
    // Botón estadísticas
    echo "<form method='post' style='flex: 1; min-width: 200px;'>";
    echo "<button type='submit' name='ver_estadisticas' value='1' style='width: 100%; background: #28a745; color: white; padding: 15px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: bold;'>";
    echo "📊 VER ESTADÍSTICAS";
    echo "</button>";
    echo "<small style='display: block; margin-top: 8px; color: #666;'>Mostrar resumen de asistencias actuales</small>";
    echo "</form>";
    
    echo "</div>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h4 style='color: #721c24; margin-top: 0;'>❌ Error Fatal</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Archivo:</strong> " . $e->getFile() . ":" . $e->getLine() . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<div style='margin: 30px 0;'>";
echo "<a href='asistencias.php' style='background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-right: 15px; font-weight: bold;'>📋 Ver Asistencias</a>";
echo "<a href='index.php' style='background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; font-weight: bold;'>🏠 Panel Principal</a>";
echo "</div>";
?>
