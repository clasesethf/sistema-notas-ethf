<?php
/**
 * solucionador_sqlite.php - Soluciona problemas específicos de SQLite
 * Arregla restricciones CHECK y busca estudiantes correctamente
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado - Solo administradores');
}

require_once 'config.php';

echo "<h1>🔧 Solucionador SQLite</h1>";
echo "<p>Vamos a arreglar los problemas específicos de tu base de datos SQLite.</p>";

try {
    $db = Database::getInstance();
    
    // PASO 1: Verificar estudiantes SIN REGEXP (compatible con SQLite)
    echo "<h2>👥 PASO 1: Buscando estudiantes en la base de datos</h2>";
    
    // Buscar todos los estudiantes sin usar REGEXP
    $todosLosEstudiantes = $db->fetchAll(
        "SELECT dni, nombre, apellido, id FROM usuarios 
         WHERE tipo = 'estudiante' 
         ORDER BY dni LIMIT 50"
    );
    
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h4>📋 Estudiantes encontrados (" . count($todosLosEstudiantes) . "):</h4>";
    
    if (!empty($todosLosEstudiantes)) {
        echo "<div style='max-height: 200px; overflow-y: auto; background: white; padding: 10px; border-radius: 3px;'>";
        echo "<table style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f8f9fa; position: sticky; top: 0;'>";
        echo "<th style='border: 1px solid #ddd; padding: 5px;'>DNI</th>";
        echo "<th style='border: 1px solid #ddd; padding: 5px;'>Nombre</th>";
        echo "<th style='border: 1px solid #ddd; padding: 5px;'>ID</th>";
        echo "</tr>";
        
        foreach ($todosLosEstudiantes as $est) {
            echo "<tr>";
            echo "<td style='border: 1px solid #ddd; padding: 5px; font-family: monospace; background: #f8f9fa;'>{$est['dni']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 5px;'>{$est['nombre']} {$est['apellido']}</td>";
            echo "<td style='border: 1px solid #ddd; padding: 5px;'>{$est['id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
    } else {
        echo "<p style='color: red;'>❌ No se encontraron estudiantes en la base de datos.</p>";
    }
    echo "</div>";
    
    // Buscar específicamente los DNIs del Excel
    $dnisDelExcel = ['1710', '2021', '2022', '2023'];
    echo "<h3>🔍 Verificando DNIs específicos del Excel:</h3>";
    
    foreach ($dnisDelExcel as $dni) {
        $encontrado = $db->fetchOne(
            "SELECT u.id, u.nombre, u.apellido, 
                    m.curso_id, c.nombre as curso_nombre
             FROM usuarios u 
             LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
             LEFT JOIN cursos c ON m.curso_id = c.id
             WHERE u.dni = ? AND u.tipo = 'estudiante'",
            [$dni]
        );
        
        if ($encontrado) {
            $matriStatus = $encontrado['curso_id'] ? "✅ Matriculado en: {$encontrado['curso_nombre']}" : "⚠️ Sin matrícula activa";
            echo "<p>✅ <strong>DNI $dni:</strong> {$encontrado['nombre']} {$encontrado['apellido']} - $matriStatus</p>";
        } else {
            echo "<p>❌ <strong>DNI $dni:</strong> No encontrado</p>";
        }
    }
    
    // PASO 2: Solución de la restricción CHECK
    echo "<h2>🔧 PASO 2: Solucionando restricción CHECK</h2>";
    
    // Verificar estado actual de la restricción
    $schema = $db->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='asistencias'");
    $necesitaArreglo = false;
    
    if ($schema) {
        echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>📋 Restricción CHECK actual:</h4>";
        
        if (strpos($schema['sql'], 'CHECK') !== false) {
            preg_match("/estado.*?CHECK.*?\((.*?)\)/i", $schema['sql'], $matches);
            if (!empty($matches[1])) {
                echo "<pre style='background: white; padding: 10px; border-radius: 3px; border: 1px solid #ddd; overflow-x: auto;'>";
                echo htmlspecialchars($matches[1]);
                echo "</pre>";
                
                if (strpos($matches[1], 'cuarto_falta') === false) {
                    $necesitaArreglo = true;
                    echo "<p style='color: red; font-weight: bold;'>❌ PROBLEMA: No permite 'cuarto_falta'</p>";
                } else {
                    echo "<p style='color: green; font-weight: bold;'>✅ Ya permite 'cuarto_falta'</p>";
                }
            }
        } else {
            echo "<p style='color: orange;'>⚠️ No se encontró restricción CHECK</p>";
        }
        echo "</div>";
    }
    
    if (isset($_POST['arreglar_tabla'])) {
        echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>🚀 Recreando tabla desde cero (eliminando datos de prueba)...</h4>";
        echo "<div style='max-height: 300px; overflow-y: auto; background: white; padding: 10px; border-radius: 3px; font-family: monospace; font-size: 12px;'>";
        
        try {
            $conn = $db->getConnection();
            $conn->exec('BEGIN TRANSACTION');
            echo "<span style='color: green;'>✓ Transacción iniciada</span><br>";
            
            // 1. Contar registros existentes antes de eliminar
            $registrosExistentes = $db->fetchOne("SELECT COUNT(*) as total FROM asistencias");
            $total = $registrosExistentes ? $registrosExistentes['total'] : 0;
            echo "<span style='color: blue;'>📊 Registros de prueba encontrados: $total</span><br>";
            
            // 2. Eliminar tabla actual SIN backup (datos de prueba)
            $conn->exec("DROP TABLE asistencias");
            echo "<span style='color: orange;'>🗑️ Tabla eliminada (incluyendo $total registros de prueba)</span><br>";
            
            // 3. Crear nueva tabla desde cero con restricciones CORREGIDAS
            $conn->exec("
                CREATE TABLE asistencias (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    estudiante_id INTEGER NOT NULL,
                    curso_id INTEGER NOT NULL,
                    fecha DATE NOT NULL,
                    estado VARCHAR(20) NOT NULL CHECK (estado IN ('presente', 'ausente', 'media_falta', 'cuarto_falta', 'justificada', 'no_computa')),
                    cuatrimestre INTEGER NOT NULL CHECK (cuatrimestre IN (1, 2)),
                    observaciones TEXT,
                    motivo_falta TEXT DEFAULT NULL,
                    motivo_otro TEXT DEFAULT NULL,
                    motivo_no_computa TEXT DEFAULT NULL,
                    FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                    FOREIGN KEY (curso_id) REFERENCES cursos(id),
                    UNIQUE(estudiante_id, curso_id, fecha)
                )
            ");
            echo "<span style='color: green;'>✅ Nueva tabla creada VACÍA con estados corregidos</span><br>";
            echo "<span style='color: green;'>✅ Estados permitidos: presente, ausente, media_falta, <strong>cuarto_falta</strong>, justificada, no_computa</span><br>";
            
            $conn->exec('COMMIT');
            echo "<span style='color: green; font-weight: bold; background: #d4edda; padding: 5px; border-radius: 3px;'>✅ Tabla recreada exitosamente - Lista para importación</span><br>";
            
            // Verificar que la nueva restricción funciona
            echo "<br><span style='color: purple;'>🧪 Probando nueva restricción...</span><br>";
            
            try {
                // Intentar insertar un registro con cuarto_falta para probar
                $testEstudiante = $db->fetchOne("SELECT u.id, m.curso_id FROM usuarios u JOIN matriculas m ON u.id = m.estudiante_id WHERE m.estado = 'activo' LIMIT 1");
                if ($testEstudiante) {
                    $conn->exec("
                        INSERT INTO asistencias (estudiante_id, curso_id, fecha, estado, cuatrimestre) 
                        VALUES ({$testEstudiante['id']}, {$testEstudiante['curso_id']}, '2025-01-01', 'cuarto_falta', 1)
                    ");
                    $conn->exec("DELETE FROM asistencias WHERE fecha = '2025-01-01'"); // Limpiar el test
                    echo "<span style='color: green; font-weight: bold;'>✅ PRUEBA EXITOSA: 'cuarto_falta' funciona correctamente</span><br>";
                    echo "<span style='color: blue;'>🎯 La tabla está lista para recibir la importación completa</span><br>";
                }
            } catch (Exception $testError) {
                echo "<span style='color: red;'>❌ Error en prueba: " . htmlspecialchars($testError->getMessage()) . "</span><br>";
            }
            
        } catch (Exception $e) {
            $conn->exec('ROLLBACK');
            echo "<span style='color: red; font-weight: bold;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        }
        
        echo "</div>";
        echo "</div>";
    }
    
    // PASO 3: Crear estudiantes faltantes
    if (isset($_POST['crear_estudiantes'])) {
        echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<h4>👥 Creando estudiantes faltantes...</h4>";
        
        // Obtener primer curso disponible
        $primerCurso = $db->fetchOne("SELECT id, nombre, anio FROM cursos ORDER BY id LIMIT 1");
        
        if (!$primerCurso) {
            echo "<p style='color: red;'>❌ No hay cursos disponibles. Crea un curso primero.</p>";
        } else {
            echo "<p>📚 Usando curso: {$primerCurso['anio']}° {$primerCurso['nombre']}</p>";
            
            foreach ($dnisDelExcel as $dni) {
                $existe = $db->fetchOne("SELECT id FROM usuarios WHERE dni = ? AND tipo = 'estudiante'", [$dni]);
                
                if (!$existe) {
                    try {
                        // Verificar estructura de tabla usuarios
                        $columnasUsuarios = $db->fetchAll("PRAGMA table_info(usuarios)");
                        $tieneCreatedAt = false;
                        $columnasDisponibles = [];
                        
                        foreach ($columnasUsuarios as $col) {
                            $columnasDisponibles[] = $col['name'];
                            if ($col['name'] === 'created_at') {
                                $tieneCreatedAt = true;
                            }
                        }
                        
                        // Crear estudiante con o sin created_at según esté disponible
                        if ($tieneCreatedAt) {
                            $estudianteId = $db->insert(
                                "INSERT INTO usuarios (nombre, apellido, dni, tipo, direccion, telefono, contrasena, activo, created_at) 
                                 VALUES (?, ?, ?, 'estudiante', '', '', '', 1, datetime('now'))",
                                ["Estudiante", "Apellido$dni", $dni]
                            );
                        } else {
                            $estudianteId = $db->insert(
                                "INSERT INTO usuarios (nombre, apellido, dni, tipo, direccion, telefono, contrasena, activo) 
                                 VALUES (?, ?, ?, 'estudiante', '', '', '', 1)",
                                ["Estudiante", "Apellido$dni", $dni]
                            );
                        }
                        
                        // Verificar estructura de tabla matriculas
                        $columnasMatriculas = $db->fetchAll("PRAGMA table_info(matriculas)");
                        $columnasMatriculasDisponibles = array_column($columnasMatriculas, 'name');
                        
                        // Crear matrícula con campos disponibles
                        if (in_array('fecha_matriculacion', $columnasMatriculasDisponibles)) {
                            $db->insert(
                                "INSERT INTO matriculas (estudiante_id, curso_id, fecha_matriculacion, estado) 
                                 VALUES (?, ?, date('now'), 'activo')",
                                [$estudianteId, $primerCurso['id']]
                            );
                        } else {
                            $db->insert(
                                "INSERT INTO matriculas (estudiante_id, curso_id, estado) 
                                 VALUES (?, ?, 'activo')",
                                [$estudianteId, $primerCurso['id']]
                            );
                        }
                        
                        echo "<p style='color: green;'>✅ Creado: DNI $dni - ID $estudianteId</p>";
                        echo "<p style='color: gray; font-size: 12px;'>Columnas usuarios: " . implode(', ', $columnasDisponibles) . "</p>";
                        
                    } catch (Exception $e) {
                        echo "<p style='color: red;'>❌ Error creando DNI $dni: " . htmlspecialchars($e->getMessage()) . "</p>";
                        
                        // Mostrar detalles adicionales del error
                        echo "<details style='margin: 5px 0;'>";
                        echo "<summary style='color: red; cursor: pointer;'>Ver detalles del error</summary>";
                        echo "<pre style='background: #f8f9fa; padding: 10px; font-size: 11px; border-radius: 3px;'>";
                        echo "Error: " . htmlspecialchars($e->getMessage()) . "\n";
                        echo "Código: " . $e->getCode() . "\n";
                        echo "Archivo: " . $e->getFile() . ":" . $e->getLine();
                        echo "</pre>";
                        echo "</details>";
                    }
                } else {
                    echo "<p style='color: blue;'>ℹ️ DNI $dni ya existe</p>";
                }
            }
        }
        
        echo "</div>";
    }
    
    // Formularios de acción
    echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔧 Acciones disponibles:</h3>";
    
    if ($necesitaArreglo) {
        echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
        echo "<h4 style='color: #856404; margin-top: 0;'>⚠️ ACCIÓN REQUERIDA</h4>";
        echo "<p>Tu tabla de asistencias <strong>NO permite 'cuarto_falta'</strong>. Esto causará errores en la importación.</p>";
        echo "<form method='post' style='margin: 10px 0;'>";
        echo "<button type='submit' name='arreglar_tabla' value='1' style='background: #dc3545; color: white; padding: 15px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: bold;'>";
        echo "🗑️ LIMPIAR Y ARREGLAR TABLA";
        echo "</button>";
        echo "</form>";
        echo "<ul style='margin: 10px 0; color: #856404;'>";
        echo "<li>🗑️ <strong>Eliminará los 34 registros de prueba existentes</strong></li>";
        echo "<li>✅ Recreará la tabla con soporte para 'cuarto_falta'</li>";
        echo "<li>✅ Tabla quedará vacía y lista para importación completa</li>";
        echo "<li>⚠️ NO hay backup - los datos de prueba se perderán permanentemente</li>";
        echo "</ul>";
        echo "<p style='color: #856404; font-weight: bold; margin-top: 15px;'>⚠️ CONFIRMA: ¿Estás seguro de eliminar los 34 registros de prueba?</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
        echo "<p style='color: #155724; font-weight: bold; margin: 0;'>✅ Tu tabla ya permite 'cuarto_falta'. ¡Lista para importación!</p>";
        echo "</div>";
    }
    
    echo "<form method='post' style='margin: 15px 0;'>";
    echo "<button type='submit' name='crear_estudiantes' value='1' style='background: #28a745; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;'>";
    echo "👥 Crear Estudiantes Faltantes";
    echo "</button>";
    echo "<small style='display: block; margin-top: 5px; color: #666;'>Solo crea estudiantes para DNIs que no existen (2021, 2022, 2023 ya existen)</small>";
    echo "</form>";
    
    echo "</div>";
    
    // PASO 4: Importador simplificado
    echo "<h2>📥 PASO 3: Importador Simplificado</h2>";
    
    if (isset($_POST['importar_ahora'])) {
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
            echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px;'>";
            echo "<h4>⚠️ Archivo CSV no encontrado</h4>";
            echo "<p><strong>Pasos para convertir Excel a CSV:</strong></p>";
            echo "<ol>";
            echo "<li>Abre <code>inasistencias_ethf.xlsx</code> en Excel</li>";
            echo "<li>Ve a <strong>Archivo → Guardar como</strong></li>";
            echo "<li>Selecciona <strong>CSV (separado por comas)</strong></li>";
            echo "<li>Guarda como <code>inasistencias_ethf.csv</code> en la raíz del proyecto</li>";
            echo "<li>Vuelve a intentar la importación</li>";
            echo "</ol>";
            echo "</div>";
        } else {
            echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4>📂 Importando desde: $archivoCsv</h4>";
            echo "<div style='max-height: 400px; overflow-y: auto; background: white; padding: 10px; border-radius: 3px; font-family: monospace; font-size: 12px;'>";
            
            try {
                $handle = fopen($archivoCsv, 'r');
                $headers = fgetcsv($handle); // Saltar encabezados
                
                echo "<span style='color: blue;'>📋 Columnas: " . implode(', ', $headers) . "</span><br><br>";
                
                $conn = $db->getConnection();
                $conn->exec('BEGIN TRANSACTION');
                
                $procesados = 0;
                $actualizados = 0;
                $errores = 0;
                $saltados = 0;
                
                // Preparar consultas
                $stmtBuscarEstudiante = $conn->prepare(
                    "SELECT u.id as estudiante_id, m.curso_id 
                     FROM usuarios u 
                     JOIN matriculas m ON u.id = m.estudiante_id 
                     WHERE u.dni = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                     LIMIT 1"
                );
                
                $stmtInsertar = $conn->prepare(
                    "INSERT OR REPLACE INTO asistencias 
                     (estudiante_id, curso_id, fecha, estado, observaciones, cuatrimestre, motivo_falta, motivo_otro) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                
                while (($data = fgetcsv($handle)) !== FALSE && $procesados < 200) { // Límite de seguridad
                    try {
                        if (count($data) < 6) {
                            $saltados++;
                            continue;
                        }
                        
                        $matricula = trim($data[0]);
                        $porcentaje = intval($data[1]);
                        $fecha = trim($data[2]);
                        $detalle = trim($data[3]);
                        $justificada = intval($data[5]);
                        
                        // Validar datos básicos
                        if (empty($matricula) || empty($fecha)) {
                            echo "<span style='color: orange;'>⚠ Datos incompletos: matricula='$matricula', fecha='$fecha'</span><br>";
                            $saltados++;
                            continue;
                        }
                        
                        // Mapear estado con los valores CORRECTOS
                        $estado = 'presente';
                        switch ($porcentaje) {
                            case 100: $estado = 'ausente'; break;
                            case 50:  $estado = 'media_falta'; break;
                            case 25:  $estado = 'cuarto_falta'; break; // AHORA FUNCIONA
                        }
                        
                        // Determinar motivo
                        $motivo = null;
                        $motivoOtro = null;
                        if ($justificada == 1) {
                            $detalleMin = strtolower($detalle);
                            if (strpos($detalleMin, 'tarde') !== false) {
                                $motivo = 'problema_transporte';
                            } else {
                                $motivo = 'otro';
                                $motivoOtro = $detalle;
                            }
                        }
                        
                        // Buscar estudiante
                        $stmtBuscarEstudiante->execute([$matricula]);
                        $estudiante = $stmtBuscarEstudiante->fetch();
                        
                        if ($estudiante) {
                            // Determinar cuatrimestre
                            $cuatrimestre = (strtotime($fecha) < strtotime('2025-08-01')) ? 1 : 2;
                            
                            // Insertar/actualizar
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
                            echo "<span style='color: green;'>✓ {$matricula} - {$fecha} - {$estado}</span><br>";
                            
                        } else {
                            echo "<span style='color: red;'>✗ Estudiante {$matricula} no encontrado</span><br>";
                            $errores++;
                        }
                        
                    } catch (Exception $e) {
                        $errores++;
                        echo "<span style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
                    }
                    
                    // Mostrar progreso
                    if (($procesados + $errores) % 50 === 0) {
                        echo "<div style='background: #e7f3ff; padding: 3px; margin: 3px 0;'>Procesados: $procesados | Errores: $errores</div>";
                        flush();
                    }
                }
                
                fclose($handle);
                
                // Confirmar transacción
                if ($errores < $procesados) { // Si hay más éxitos que errores
                    $conn->exec('COMMIT');
                    echo "<br><span style='color: green; font-weight: bold; background: #d4edda; padding: 5px; border-radius: 3px;'>✅ IMPORTACIÓN EXITOSA</span><br>";
                } else {
                    $conn->exec('ROLLBACK');
                    echo "<br><span style='color: red; font-weight: bold; background: #f8d7da; padding: 5px; border-radius: 3px;'>❌ IMPORTACIÓN CANCELADA</span><br>";
                }
                
                echo "<br><strong>Resumen:</strong><br>";
                echo "✅ Procesados: $procesados<br>";
                echo "❌ Errores: $errores<br>";
                echo "⏭ Saltados: $saltados<br>";
                
            } catch (Exception $e) {
                $conn->exec('ROLLBACK');
                echo "<span style='color: red; font-weight: bold;'>❌ Error fatal: " . htmlspecialchars($e->getMessage()) . "</span>";
            }
            
            echo "</div>";
            echo "</div>";
        }
    }
    
    echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p><strong>📋 Una vez aplicadas las soluciones de arriba:</strong></p>";
    echo "<form method='post'>";
    echo "<button type='submit' name='importar_ahora' value='1' style='background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;'>";
    echo "📥 Importar Asistencias Ahora";
    echo "</button>";
    echo "</form>";
    echo "<small style='color: #666; margin-top: 10px; display: block;'>Procesará hasta 200 registros como prueba. Requiere archivo CSV.</small>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px;'>";
    echo "<h4>❌ Error en el solucionador</h4>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='asistencias.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>📋 Ver Asistencias</a>";
echo "<a href='index.php' style='background: #6c757d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🏠 Panel Principal</a></p>";
?>
