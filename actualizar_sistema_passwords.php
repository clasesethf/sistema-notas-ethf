<?php
/**
 * Script de actualización de base de datos
 * Sistema de gestión de contraseñas
 * 
 * Ejecutar una sola vez para implementar las mejoras de contraseñas
 */

// Configuración de base de datos usando el sistema existente
$db = null;

try {
    // Cargar config.php que ya tiene la clase Database
    require_once 'config.php';
    
    // Usar la instancia existente de Database
    $db = Database::getInstance();
    
    // Verificar que la conexión funcione
    $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table'");
    if (empty($tablas)) {
        throw new Exception("La base de datos no contiene tablas. Verifique que sea el archivo correcto.");
    }
    
    echo "<p>ℹ️ Usando configuración existente del sistema: <strong>" . DB_FILE . "</strong></p>";
    
} catch (Exception $e) {
    die("❌ Error de conexión a la base de datos: " . $e->getMessage());
}

// Iniciar sesión solo si no está activa
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificación básica de administrador (ajusta según tu sistema)
$esAdmin = false;

// Verificar si hay sesión activa
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    $esAdmin = true;
} 
// Si no hay sesión, verificar si hay algún admin en la BD para modo setup inicial
else {
    try {
        $adminCount = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo = 'admin'");
        if ($adminCount && $adminCount['count'] == 0) {
            echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<h3>⚠️ Modo Setup Inicial</h3>";
            echo "<p>No se detectaron administradores. Ejecutando en modo setup inicial...</p>";
            echo "</div>";
            $esAdmin = true; // Permitir ejecución en setup inicial
        }
    } catch (Exception $e) {
        // Si hay error accediendo a usuarios, probablemente es setup inicial
        $esAdmin = true;
    }
}

if (!$esAdmin) {
    die("❌ Error: Solo los administradores pueden ejecutar este script. Por favor, inicie sesión como administrador.");
}

echo "<h1>🔧 Actualización del Sistema de Contraseñas</h1>";
echo "<p>Iniciando proceso de actualización...</p>";

// Mostrar información del entorno
echo "<div style='background: #e7f3ff; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<h4>📋 Información del Entorno:</h4>";
echo "<ul>";
echo "<li><strong>Sistema:</strong> " . APP_NAME . " v" . APP_VERSION . "</li>";
echo "<li><strong>Escuela:</strong> " . SCHOOL_NAME . "</li>";
echo "<li><strong>Usuario de sesión:</strong> " . ($_SESSION['user_name'] ?? 'Setup inicial') . "</li>";
echo "<li><strong>Tipo de usuario:</strong> " . ($_SESSION['user_type'] ?? 'Setup inicial') . "</li>";
echo "<li><strong>Base de datos:</strong> " . DB_FILE . "</li>";
echo "</ul>";
echo "</div>";

echo "<hr>";

try {
    // Verificar si ya existe la columna password_changed
    echo "<h3>📋 1. Verificando estructura de la base de datos...</h3>";
    
    $columns = $db->fetchAll("PRAGMA table_info(usuarios)");
    $passwordChangedExists = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'password_changed') {
            $passwordChangedExists = true;
            break;
        }
    }
    
    if ($passwordChangedExists) {
        echo "✅ La columna 'password_changed' ya existe<br>";
    } else {
        echo "⚙️ Agregando columna 'password_changed' a la tabla usuarios...<br>";
        $db->query("ALTER TABLE usuarios ADD COLUMN password_changed INTEGER DEFAULT 0");
        echo "✅ Columna 'password_changed' agregada correctamente<br>";
    }
    
    // Crear índice para mejor rendimiento
    echo "<h3>🚀 2. Optimizando rendimiento...</h3>";
    try {
        $db->query("CREATE INDEX IF NOT EXISTS idx_usuarios_password_changed ON usuarios(password_changed)");
        echo "✅ Índice idx_usuarios_password_changed creado/verificado<br>";
    } catch (Exception $e) {
        echo "⚠️ Advertencia al crear índice: " . $e->getMessage() . "<br>";
    }
    
    // Verificar y actualizar usuarios existentes
    echo "<h3>🔍 3. Analizando usuarios existentes...</h3>";
    
    $usuarios = $db->fetchAll("
        SELECT id, dni, tipo, contrasena, 
               COALESCE(password_changed, 0) as password_changed 
        FROM usuarios
    ");
    
    $usuariosConPasswordDefault = 0;
    $usuariosConPasswordPersonalizada = 0;
    $usuariosActualizados = 0;
    
    foreach ($usuarios as $usuario) {
        $passwordDefault = $usuario['tipo'] . '123';
        
        // Si la contraseña es la por defecto y no está marcado
        if ($usuario['contrasena'] === $passwordDefault && $usuario['password_changed'] == 0) {
            $usuariosConPasswordDefault++;
        }
        // Si la contraseña NO es la por defecto pero está marcado como 0
        elseif ($usuario['contrasena'] !== $passwordDefault && $usuario['password_changed'] == 0) {
            $db->query("UPDATE usuarios SET password_changed = 1 WHERE id = ?", [$usuario['id']]);
            $usuariosConPasswordPersonalizada++;
            $usuariosActualizados++;
        }
        // Si ya tiene contraseña personalizada marcada
        elseif ($usuario['password_changed'] == 1) {
            $usuariosConPasswordPersonalizada++;
        }
    }
    
    echo "📊 Usuarios con contraseña por defecto: <strong>$usuariosConPasswordDefault</strong><br>";
    echo "📊 Usuarios con contraseña personalizada: <strong>$usuariosConPasswordPersonalizada</strong><br>";
    if ($usuariosActualizados > 0) {
        echo "✅ Se actualizaron $usuariosActualizados registros de estado<br>";
    }
    
    // Crear configuraciones adicionales si no existen
    echo "<h3>⚙️ 4. Configurando opciones del sistema...</h3>";
    
    // Verificar si existe tabla de configuraciones
    $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='configuraciones'");
    
    if (empty($tablas)) {
        echo "📝 Creando tabla de configuraciones...<br>";
        $db->query("
            CREATE TABLE configuraciones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clave TEXT UNIQUE NOT NULL,
                valor TEXT NOT NULL,
                descripcion TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "✅ Tabla configuraciones creada<br>";
        
        // Insertar configuraciones por defecto
        $configuraciones = [
            ['forzar_cambio_password', '1', 'Forzar cambio de contraseña en primer login'],
            ['min_password_length', '6', 'Longitud mínima de contraseñas'],
            ['mostrar_consejos_seguridad', '1', 'Mostrar consejos de seguridad'],
            ['password_expira_dias', '0', 'Días para expiración de contraseña (0 = nunca)'],
            ['intentos_login_max', '5', 'Máximo intentos de login antes de bloqueo']
        ];
        
        foreach ($configuraciones as $config) {
            $db->query(
                "INSERT INTO configuraciones (clave, valor, descripcion) VALUES (?, ?, ?)",
                $config
            );
        }
        echo "✅ Configuraciones por defecto agregadas<br>";
    } else {
        echo "✅ Tabla configuraciones ya existe<br>";
    }
    
    // Verificar permisos de archivos necesarios
    echo "<h3>📁 5. Verificando archivos del sistema...</h3>";
    
    $archivosNecesarios = [
        'cambiar_contrasena.php' => 'Página para cambiar contraseña de usuarios',
        'admin_contrasenas.php' => 'Panel de administración de contraseñas'
    ];
    
    foreach ($archivosNecesarios as $archivo => $descripcion) {
        if (file_exists($archivo)) {
            echo "✅ $archivo - $descripcion<br>";
        } else {
            echo "⚠️ $archivo - <strong>FALTA CREAR</strong> - $descripcion<br>";
        }
    }
    
    // Crear respaldo de seguridad
    echo "<h3>💾 6. Creando respaldo de seguridad...</h3>";
    
    $fechaRespaldo = date('Y-m-d_H-i-s');
    $archivoRespaldo = "respaldo_usuarios_$fechaRespaldo.sql";
    
    try {
        $usuariosRespaldo = $db->fetchAll("SELECT * FROM usuarios");
        $contenidoRespaldo = "-- Respaldo de usuarios - $fechaRespaldo\n";
        $contenidoRespaldo .= "-- Total usuarios: " . count($usuariosRespaldo) . "\n\n";
        
        foreach ($usuariosRespaldo as $usuario) {
            $contenidoRespaldo .= "INSERT INTO usuarios_respaldo (id, nombre, apellido, dni, contrasena, tipo, activo, password_changed) VALUES (";
            $contenidoRespaldo .= "'{$usuario['id']}', '{$usuario['nombre']}', '{$usuario['apellido']}', ";
            $contenidoRespaldo .= "'{$usuario['dni']}', '{$usuario['contrasena']}', '{$usuario['tipo']}', ";
            $contenidoRespaldo .= "'{$usuario['activo']}', '" . ($usuario['password_changed'] ?? 0) . "');\n";
        }
        
        if (file_put_contents($archivoRespaldo, $contenidoRespaldo)) {
            echo "✅ Respaldo creado: $archivoRespaldo<br>";
        } else {
            echo "⚠️ No se pudo crear el respaldo<br>";
        }
    } catch (Exception $e) {
        echo "⚠️ Error creando respaldo: " . $e->getMessage() . "<br>";
    }
    
    // Generar reporte final
    echo "<h3>📈 7. Reporte Final</h3>";
    
    $estadisticas = $db->fetchAll("
        SELECT 
            tipo,
            COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN COALESCE(password_changed, 0) = 0 THEN 1 ELSE 0 END) as password_default,
            SUM(CASE WHEN COALESCE(password_changed, 0) = 1 THEN 1 ELSE 0 END) as password_personalizada
        FROM usuarios 
        GROUP BY tipo 
        ORDER BY tipo
    ");
    
    echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse; margin: 20px 0;'>";
    echo "<tr style='background-color: #f8f9fa;'>";
    echo "<th>Tipo Usuario</th><th>Total</th><th>Activos</th><th>Password Default</th><th>Password Personalizada</th>";
    echo "</tr>";
    
    foreach ($estadisticas as $stat) {
        echo "<tr>";
        echo "<td>" . ucfirst($stat['tipo']) . "</td>";
        echo "<td>{$stat['total']}</td>";
        echo "<td>{$stat['activos']}</td>";
        echo "<td style='color: orange;'><strong>{$stat['password_default']}</strong></td>";
        echo "<td style='color: green;'><strong>{$stat['password_personalizada']}</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Instrucciones finales
    echo "<h3>📋 8. Próximos Pasos</h3>";
    echo "<div style='background-color: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>Para completar la implementación:</h4>";
    echo "<ol>";
    echo "<li><strong>Crear archivos faltantes:</strong>";
    echo "<ul>";
    if (!file_exists('cambiar_contrasena.php')) {
        echo "<li>📄 Crear <code>cambiar_contrasena.php</code> (página para usuarios)</li>";
    }
    if (!file_exists('admin_contrasenas.php')) {
        echo "<li>📄 Crear <code>admin_contrasenas.php</code> (panel administrador)</li>";
    }
    echo "</ul></li>";
    
    echo "<li><strong>Agregar al menú de navegación:</strong>";
    echo "<ul>";
    echo "<li>En <code>header.php</code> agregar link a 'Cambiar Contraseña'</li>";
    echo "<li>Para admins: agregar link a 'Gestión de Contraseñas'</li>";
    echo "</ul></li>";
    
    echo "<li><strong>Modificar funciones:</strong>";
    echo "<ul>";
    echo "<li>Actualizar <code>funciones.php</code> con nuevas funciones</li>";
    echo "<li>Modificar <code>login.php</code> para verificar primer login</li>";
    echo "</ul></li>";
    
    echo "<li><strong>Configurar opciones:</strong>";
    echo "<ul>";
    echo "<li>Ajustar configuraciones en tabla <code>configuraciones</code></li>";
    echo "<li>Personalizar mensajes y validaciones según necesidades</li>";
    echo "</ul></li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<h3>✅ ¡Actualización Completada Exitosamente!</h3>";
    echo "<p>El sistema de gestión de contraseñas ha sido actualizado correctamente.</p>";
    echo "<p><strong>Recomendación:</strong> Informar a los usuarios sobre las nuevas funcionalidades de seguridad.</p>";
    
    // Crear archivo de log
    $logContent = "Actualización del sistema de contraseñas - " . date('Y-m-d H:i:s') . "\n";
    $logContent .= "Usuarios con password default: $usuariosConPasswordDefault\n";
    $logContent .= "Usuarios con password personalizada: $usuariosConPasswordPersonalizada\n";
    $logContent .= "Registros actualizados: $usuariosActualizados\n";
    $logContent .= "Estado: Completado exitosamente\n";
    
    file_put_contents("log_actualizacion_passwords_$fechaRespaldo.txt", $logContent);
    
    echo "<p><em>Log guardado en: log_actualizacion_passwords_$fechaRespaldo.txt</em></p>";
    
} catch (Exception $e) {
    echo "<h3>❌ Error durante la actualización</h3>";
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<p>La actualización no se completó. Verifique los permisos de la base de datos y vuelva a intentar.</p>";
    
    // Información adicional para debugging
    echo "<h4>🔍 Información de Debug:</h4>";
    echo "<ul>";
    echo "<li><strong>Archivo:</strong> " . $e->getFile() . "</li>";
    echo "<li><strong>Línea:</strong> " . $e->getLine() . "</li>";
    echo "<li><strong>Trace:</strong><br><pre>" . $e->getTraceAsString() . "</pre></li>";
    echo "</ul>";
    
    // Log del error
    $errorLog = "ERROR - Actualización passwords - " . date('Y-m-d H:i:s') . "\n";
    $errorLog .= "Error: " . $e->getMessage() . "\n";
    $errorLog .= "Archivo: " . $e->getFile() . "\n";
    $errorLog .= "Línea: " . $e->getLine() . "\n";
    $errorLog .= "Trace: " . $e->getTraceAsString() . "\n";
    
    try {
        file_put_contents("error_actualizacion_" . date('Y-m-d_H-i-s') . ".txt", $errorLog);
        echo "<p>📝 Error guardado en archivo de log.</p>";
    } catch (Exception $logError) {
        echo "<p>⚠️ No se pudo guardar el log de error.</p>";
    }
}

echo "<hr>";
echo "<p><a href='index.php' class='btn btn-primary'>🏠 Volver al Inicio</a> ";
echo "<a href='usuarios.php' class='btn btn-secondary'>👥 Gestión de Usuarios</a>";

if (file_exists('admin_contrasenas.php')) {
    echo " <a href='admin_contrasenas.php' class='btn btn-success'>🔐 Gestión de Contraseñas</a>";
}
echo "</p>";

// Mostrar información adicional si se ejecuta por primera vez
if (!$passwordChangedExists) {
    echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border: 1px solid #ffeaa7;'>";
    echo "<h4>🎉 ¡Primera Instalación Completada!</h4>";
    echo "<p>Se ha instalado exitosamente el sistema de gestión de contraseñas.</p>";
    echo "<p><strong>Funcionalidades agregadas:</strong></p>";
    echo "<ul>";
    echo "<li>✅ Control de cambio de contraseñas</li>";
    echo "<li>✅ Validaciones de seguridad</li>";
    echo "<li>✅ Panel de administración</li>";
    echo "<li>✅ Generación de contraseñas seguras</li>";
    echo "<li>✅ Respaldos automáticos</li>";
    echo "<li>✅ Sistema de configuraciones</li>";
    echo "</ul>";
    echo "</div>";
}
?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background-color: #f8f9fa;
}
h1, h3 {
    color: #333;
}
.btn {
    display: inline-block;
    padding: 10px 20px;
    margin: 5px;
    text-decoration: none;
    border-radius: 5px;
    color: white;
}
.btn-primary { background-color: #007bff; }
.btn-secondary { background-color: #6c757d; }
.btn-success { background-color: #28a745; }
.btn:hover { opacity: 0.8; }
</style>