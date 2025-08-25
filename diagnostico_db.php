<?php
/**
 * Diagnóstico y solución para error "readonly database" en SQLite
 * Este script te ayudará a identificar y solucionar el problema
 */

// PASO 1: Verificar permisos de archivos
function verificarPermisosArchivos() {
    echo "<h3>🔍 DIAGNÓSTICO DE PERMISOS</h3>\n";
    
    // Rutas comunes de la base de datos (actualizado para calificaciones.db)
    $posiblesRutas = [
        'database/calificaciones.db',
        'calificaciones.db',
        'database.sqlite',
        'database/database.sqlite',
        'db/database.sqlite',
        'data/database.sqlite',
        'database.db',
        'db/calificaciones.db'
    ];
    
    foreach ($posiblesRutas as $ruta) {
        if (file_exists($ruta)) {
            echo "<h4>📁 Archivo: {$ruta}</h4>\n";
            
            // Verificar si el archivo existe y es legible
            echo "✅ Archivo existe: " . (file_exists($ruta) ? 'SÍ' : 'NO') . "\n";
            echo "📖 Es legible: " . (is_readable($ruta) ? 'SÍ' : 'NO') . "\n";
            echo "✏️ Es escribible: " . (is_writable($ruta) ? 'SÍ' : 'NO') . "\n";
            
            // Mostrar permisos actuales
            $permisos = fileperms($ruta);
            echo "🔒 Permisos octales: " . decoct($permisos & 0777) . "\n";
            echo "👤 Propietario: " . fileowner($ruta) . "\n";
            echo "👥 Grupo: " . filegroup($ruta) . "\n";
            
            // Verificar directorio padre
            $directorio = dirname($ruta);
            echo "📂 Directorio padre escribible: " . (is_writable($directorio) ? 'SÍ' : 'NO') . "\n";
            echo "📂 Permisos directorio: " . decoct(fileperms($directorio) & 0777) . "\n";
            
            echo "\n";
        }
    }
}

// PASO 2: Función para corregir permisos
function corregirPermisos($rutaDB) {
    echo "<h3>🔧 CORRECCIÓN DE PERMISOS</h3>\n";
    
    try {
        // Corregir permisos del archivo de base de datos
        if (file_exists($rutaDB)) {
            if (chmod($rutaDB, 0664)) {
                echo "✅ Permisos del archivo corregidos a 664\n";
            } else {
                echo "❌ No se pudieron cambiar los permisos del archivo\n";
            }
        }
        
        // Corregir permisos del directorio
        $directorio = dirname($rutaDB);
        if (chmod($directorio, 0775)) {
            echo "✅ Permisos del directorio corregidos a 775\n";
        } else {
            echo "❌ No se pudieron cambiar los permisos del directorio\n";
        }
        
        // Verificar archivo WAL (Write-Ahead Log) de SQLite
        $walFile = $rutaDB . '-wal';
        if (file_exists($walFile)) {
            if (chmod($walFile, 0664)) {
                echo "✅ Permisos del archivo WAL corregidos\n";
            }
        }
        
        // Verificar archivo SHM (Shared Memory) de SQLite
        $shmFile = $rutaDB . '-shm';
        if (file_exists($shmFile)) {
            if (chmod($shmFile, 0664)) {
                echo "✅ Permisos del archivo SHM corregidos\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error al corregir permisos: " . $e->getMessage() . "\n";
    }
}

// PASO 3: Verificar conexión y transacciones
function verificarConexionBD($rutaDB) {
    echo "<h3>🔌 VERIFICACIÓN DE CONEXIÓN</h3>\n";
    
    try {
        // Intentar conexión directa con PDO
        $pdo = new PDO("sqlite:$rutaDB");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "✅ Conexión PDO exitosa\n";
        
        // Verificar si podemos escribir
        $testQuery = "CREATE TABLE IF NOT EXISTS test_write (id INTEGER PRIMARY KEY, test_field TEXT)";
        $pdo->exec($testQuery);
        
        $insertQuery = "INSERT OR REPLACE INTO test_write (id, test_field) VALUES (1, 'test')";
        $pdo->exec($insertQuery);
        
        $deleteQuery = "DELETE FROM test_write WHERE id = 1";
        $pdo->exec($deleteQuery);
        
        $dropQuery = "DROP TABLE IF EXISTS test_write";
        $pdo->exec($dropQuery);
        
        echo "✅ Operaciones de escritura exitosas\n";
        
    } catch (PDOException $e) {
        echo "❌ Error de conexión/escritura: " . $e->getMessage() . "\n";
        
        // Diagnóstico adicional
        if (strpos($e->getMessage(), 'readonly') !== false) {
            echo "🔍 Diagnóstico: La base de datos está en modo solo lectura\n";
            echo "📋 Posibles causas:\n";
            echo "   - Permisos de archivo insuficientes\n";
            echo "   - Directorio sin permisos de escritura\n";
            echo "   - Espacio en disco insuficiente\n";
            echo "   - Archivo bloqueado por otro proceso\n";
        }
    }
}

// PASO 4: Función para verificar espacio en disco
function verificarEspacioDisco($rutaDB) {
    echo "<h3>💾 VERIFICACIÓN DE ESPACIO EN DISCO</h3>\n";
    
    $directorio = dirname($rutaDB);
    $espacioLibre = disk_free_space($directorio);
    $espacioTotal = disk_total_space($directorio);
    
    echo "📊 Espacio libre: " . formatBytes($espacioLibre) . "\n";
    echo "📊 Espacio total: " . formatBytes($espacioTotal) . "\n";
    
    if ($espacioLibre < 10 * 1024 * 1024) { // Menos de 10MB
        echo "⚠️ ADVERTENCIA: Espacio en disco muy bajo (menos de 10MB)\n";
    } else {
        echo "✅ Espacio en disco suficiente\n";
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// PASO 5: Verificar procesos que usan la base de datos
function verificarProcesosBD($rutaDB) {
    echo "<h3>🔄 VERIFICACIÓN DE PROCESOS</h3>\n";
    
    // En sistemas Unix/Linux
    if (function_exists('shell_exec') && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $comando = "lsof " . escapeshellarg($rutaDB) . " 2>/dev/null";
        $output = shell_exec($comando);
        
        if ($output) {
            echo "⚠️ Procesos usando la base de datos:\n";
            echo $output . "\n";
        } else {
            echo "✅ No hay otros procesos usando la base de datos\n";
        }
    } else {
        echo "ℹ️ No se puede verificar procesos en este sistema\n";
    }
}

// FUNCIÓN PRINCIPAL DE DIAGNÓSTICO
function diagnosticarProblemaReadonly($rutaDB = null) {
    // Si no se proporciona ruta, intentar detectarla (actualizado para calificaciones.db)
    if (!$rutaDB) {
        $posiblesRutas = [
            'database/calificaciones.db',
            'calificaciones.db',
            'database.sqlite',
            'database/database.sqlite',
            'db/database.sqlite',
            'data/database.sqlite',
            'database.db',
            'db/calificaciones.db'
        ];
        
        foreach ($posiblesRutas as $ruta) {
            if (file_exists($ruta)) {
                $rutaDB = $ruta;
                break;
            }
        }
    }
    
    if (!$rutaDB || !file_exists($rutaDB)) {
        echo "❌ No se pudo encontrar el archivo de base de datos\n";
        echo "📋 Rutas verificadas:\n";
        foreach ($posiblesRutas as $ruta) {
            echo "   - $ruta\n";
        }
        return false;
    }
    
    echo "🏥 DIAGNÓSTICO COMPLETO DE BASE DE DATOS READONLY\n";
    echo "================================================\n";
    echo "📁 Archivo analizado: $rutaDB\n\n";
    
    verificarPermisosArchivos();
    verificarEspacioDisco($rutaDB);
    verificarProcesosBD($rutaDB);
    verificarConexionBD($rutaDB);
    
    return true;
}

// SOLUCIONES PASO A PASO
function mostrarSoluciones($rutaDB) {
    echo "<h3>🛠️ SOLUCIONES RECOMENDADAS</h3>\n";
    echo "================================================\n";
    
    echo "<h4>1. Corregir permisos (más común):</h4>\n";
    echo "```bash\n";
    echo "# Dar permisos de escritura al archivo\n";
    echo "chmod 664 $rutaDB\n";
    echo "# Dar permisos de escritura al directorio\n";
    echo "chmod 775 " . dirname($rutaDB) . "\n";
    echo "# Si es necesario, cambiar propietario\n";
    echo "chown www-data:www-data $rutaDB\n";
    echo "chown www-data:www-data " . dirname($rutaDB) . "\n";
    echo "```\n\n";
    
    echo "<h4>2. Verificar configuración del servidor web:</h4>\n";
    echo "```\n";
    echo "Usuario del servidor web: " . get_current_user() . "\n";
    echo "ID del usuario: " . getmyuid() . "\n";
    echo "ID del grupo: " . getmygid() . "\n";
    echo "```\n\n";
    
    echo "<h4>3. Comando para diagnóstico manual:</h4>\n";
    echo "```bash\n";
    echo "# Verificar permisos\n";
    echo "ls -la $rutaDB\n";
    echo "ls -la " . dirname($rutaDB) . "\n";
    echo "# Verificar espacio en disco\n";
    echo "df -h " . dirname($rutaDB) . "\n";
    echo "# Verificar procesos\n";
    echo "lsof $rutaDB\n";
    echo "```\n\n";
    
    echo "<h4>4. Si nada funciona - Recrear base de datos:</h4>\n";
    echo "```bash\n";
    echo "# Hacer backup\n";
    echo "cp $rutaDB {$rutaDB}.backup\n";
    echo "# Exportar datos\n";
    echo "sqlite3 $rutaDB .dump > backup.sql\n";
    echo "# Recrear con permisos correctos\n";
    echo "rm $rutaDB\n";
    echo "sqlite3 $rutaDB < backup.sql\n";
    echo "chmod 664 $rutaDB\n";
    echo "```\n\n";
}

// FUNCIÓN PARA INTENTAR REPARACIÓN AUTOMÁTICA
function intentarReparacionAutomatica($rutaDB) {
    echo "<h3>🔧 REPARACIÓN AUTOMÁTICA</h3>\n";
    echo "================================\n";
    
    $exito = true;
    
    try {
        // 1. Corregir permisos del archivo
        if (file_exists($rutaDB)) {
            if (chmod($rutaDB, 0664)) {
                echo "✅ Permisos del archivo corregidos\n";
            } else {
                echo "❌ No se pudieron corregir permisos del archivo\n";
                $exito = false;
            }
        }
        
        // 2. Corregir permisos del directorio
        $directorio = dirname($rutaDB);
        if (chmod($directorio, 0775)) {
            echo "✅ Permisos del directorio corregidos\n";
        } else {
            echo "❌ No se pudieron corregir permisos del directorio\n";
            $exito = false;
        }
        
        // 3. Verificar archivos auxiliares de SQLite (.db también usa estos archivos)
        $archivosAuxiliares = [
            $rutaDB . '-wal',
            $rutaDB . '-shm',
            $rutaDB . '-journal'
        ];
        
        foreach ($archivosAuxiliares as $archivo) {
            if (file_exists($archivo)) {
                if (chmod($archivo, 0664)) {
                    echo "✅ Permisos de $archivo corregidos\n";
                } else {
                    echo "⚠️ No se pudieron corregir permisos de $archivo\n";
                }
            }
        }
        
        // 4. Probar escritura
        $pdo = new PDO("sqlite:$rutaDB");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Test simple de escritura
        $pdo->exec("CREATE TABLE IF NOT EXISTS test_repair (id INTEGER)");
        $pdo->exec("INSERT INTO test_repair (id) VALUES (1)");
        $pdo->exec("DELETE FROM test_repair WHERE id = 1");
        $pdo->exec("DROP TABLE test_repair");
        
        echo "✅ Prueba de escritura exitosa\n";
        echo "🎉 REPARACIÓN COMPLETADA CON ÉXITO\n";
        
    } catch (Exception $e) {
        echo "❌ Error durante la reparación: " . $e->getMessage() . "\n";
        $exito = false;
    }
    
    return $exito;
}

// EJECUCIÓN DEL DIAGNÓSTICO
echo "Content-Type: text/plain\n\n";

// Detectar ruta de la base de datos desde config.php si existe
$rutaDB = null;
if (file_exists('config.php')) {
    // Intentar leer la configuración
    $contenido = file_get_contents('config.php');
    if (preg_match('/sqlite:([^"\']+)/', $contenido, $matches)) {
        $rutaDB = $matches[1];
    }
}

// Ejecutar diagnóstico
if (diagnosticarProblemaReadonly($rutaDB)) {
    echo "\n";
    mostrarSoluciones($rutaDB);
    
    echo "\n¿Desea intentar reparación automática? (Sí/No)\n";
    echo "Para ejecutar reparación automática, agregue ?reparar=si al final de esta URL\n\n";
    
    // Si se solicita reparación automática
    if (isset($_GET['reparar']) && $_GET['reparar'] === 'si') {
        echo "🚀 INICIANDO REPARACIÓN AUTOMÁTICA...\n\n";
        if (intentarReparacionAutomatica($rutaDB)) {
            echo "\n✅ La base de datos debería funcionar correctamente ahora.\n";
            echo "🔄 Pruebe nuevamente guardar las calificaciones.\n";
        } else {
            echo "\n❌ La reparación automática no fue completamente exitosa.\n";
            echo "📞 Se recomienda contactar al administrador del sistema.\n";
        }
    }
}
?>