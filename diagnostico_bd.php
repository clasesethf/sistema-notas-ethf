<?php
/**
 * diagnostico_bd.php - Script para diagnosticar problemas con la base de datos
 * Usar este script primero para identificar el problema
 */

session_start();

// Verificar autenticación básica
if (!isset($_SESSION['user_id'])) {
    die('<h1>Acceso denegado</h1><p>Debe estar logueado para usar este script.</p>');
}

try {
    require_once 'config.php';
    $db = Database::getInstance();
} catch (Exception $e) {
    die('<h1>Error de conexión</h1><p>No se pudo conectar a la base de datos: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico Base de Datos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .test-success { color: #28a745; }
        .test-error { color: #dc3545; }
        .test-warning { color: #ffc107; }
        .code-block { 
            background: #f8f9fa; 
            border: 1px solid #dee2e6; 
            border-radius: 0.375rem; 
            padding: 1rem; 
            font-family: 'Courier New', monospace; 
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <h1>🔍 Diagnóstico de Base de Datos</h1>
    
    <?php
    
    function testQuery($description, $query, $params = []) {
        global $db;
        
        echo "<div class='mb-3'>";
        echo "<h6>$description</h6>";
        echo "<div class='code-block'>$query</div>";
        
        try {
            if (empty($params)) {
                $result = $db->fetchAll($query);
            } else {
                $result = $db->fetchAll($query, $params);
            }
            
            echo "<div class='test-success'>✓ Éxito - Registros encontrados: " . count($result) . "</div>";
            
            if (count($result) > 0 && count($result) <= 10) {
                echo "<details><summary>Ver resultados</summary><pre>" . print_r($result, true) . "</pre></details>";
            }
            
            return true;
        } catch (Exception $e) {
            echo "<div class='test-error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            return false;
        }
        
        echo "</div>";
    }
    
    echo "<h2>1. Pruebas Básicas de Conexión</h2>";
    
    // Test 1: Verificar que SQLite funciona
    testQuery("Verificar versión de SQLite", "SELECT sqlite_version() as version");
    
    // Test 2: Listar todas las tablas
    testQuery("Listar todas las tablas", "SELECT name FROM sqlite_master WHERE type='table'");
    
    echo "<h2>2. Análisis de Tabla usuarios</h2>";
    
    // Test 3: Verificar estructura de tabla usuarios
    testQuery("Estructura de tabla usuarios", "PRAGMA table_info(usuarios)");
    
    // Test 4: Contar usuarios
    testQuery("Contar total de usuarios", "SELECT COUNT(*) as total FROM usuarios");
    
    // Test 5: Buscar usuarios con diferentes métodos
    echo "<h3>Búsqueda de usuarios PABLO</h3>";
    
    testQuery("Método 1: LIKE con %", "SELECT nombre, apellido FROM usuarios WHERE nombre LIKE '%Pablo%'");
    
    testQuery("Método 2: UPPER con LIKE", "SELECT nombre, apellido FROM usuarios WHERE UPPER(nombre) LIKE UPPER('%pablo%')");
    
    testQuery("Método 3: Con parámetros", "SELECT nombre, apellido FROM usuarios WHERE UPPER(nombre) LIKE ?", ['%PABLO%']);
    
    testQuery("Método 4: Búsqueda exacta", "SELECT nombre, apellido FROM usuarios WHERE nombre = 'Pablo'");
    
    testQuery("Método 5: Listar primeros 10 usuarios", "SELECT nombre, apellido, tipo FROM usuarios LIMIT 10");
    
    echo "<h2>3. Información del Sistema</h2>";
    
    echo "<div class='card'>";
    echo "<div class='card-body'>";
    echo "<h6>Información de PHP y SQLite:</h6>";
    echo "<ul>";
    echo "<li>Versión PHP: " . phpversion() . "</li>";
    echo "<li>SQLite habilitado: " . (extension_loaded('sqlite3') ? '✓ Sí' : '✗ No') . "</li>";
    echo "<li>PDO SQLite: " . (extension_loaded('pdo_sqlite') ? '✓ Sí' : '✗ No') . "</li>";
    
    // Información de la clase Database
    echo "<li>Clase Database: " . (class_exists('Database') ? '✓ Existe' : '✗ No existe') . "</li>";
    
    try {
        $reflection = new ReflectionClass('Database');
        echo "<li>Métodos de Database: " . implode(', ', array_map(function($method) { 
            return $method->getName(); 
        }, $reflection->getMethods())) . "</li>";
    } catch (Exception $e) {
        echo "<li>Error al analizar clase Database: " . htmlspecialchars($e->getMessage()) . "</li>";
    }
    
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    
    echo "<h2>4. Prueba Manual de Actualización</h2>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_update'])) {
        echo "<div class='alert alert-info'>Ejecutando prueba de actualización...</div>";
        
        try {
            // Verificar si la columna ya existe
            $columns = $db->fetchAll("PRAGMA table_info(usuarios)");
            $hasRolesSecundarios = false;
            
            foreach ($columns as $column) {
                if ($column['name'] === 'roles_secundarios') {
                    $hasRolesSecundarios = true;
                    break;
                }
            }
            
            if (!$hasRolesSecundarios) {
                echo "<p>Agregando columna roles_secundarios...</p>";
                $db->query("ALTER TABLE usuarios ADD COLUMN roles_secundarios TEXT");
                echo "<div class='test-success'>✓ Columna agregada exitosamente</div>";
            } else {
                echo "<div class='test-warning'>⚠ La columna roles_secundarios ya existe</div>";
            }
            
            // Buscar Pablo
            echo "<p>Buscando Pablo Lanfranco...</p>";
            $users = $db->fetchAll("SELECT id, nombre, apellido, tipo FROM usuarios WHERE UPPER(nombre) LIKE ? OR UPPER(apellido) LIKE ?", ['%PABLO%', '%LANFRANCO%']);
            
            if ($users) {
                echo "<div class='test-success'>✓ Usuarios encontrados:</div>";
                foreach ($users as $user) {
                    echo "<p>- ID: {$user['id']}, Nombre: {$user['nombre']} {$user['apellido']}, Tipo: {$user['tipo']}</p>";
                }
                
                // Si hay un usuario específico, actualizar
                foreach ($users as $user) {
                    if (stripos($user['nombre'], 'pablo') !== false && stripos($user['apellido'], 'lanfranco') !== false) {
                        echo "<p>Actualizando usuario: {$user['nombre']} {$user['apellido']}</p>";
                        $db->query("UPDATE usuarios SET roles_secundarios = ? WHERE id = ?", ['directivo', $user['id']]);
                        echo "<div class='test-success'>✓ Usuario actualizado</div>";
                        break;
                    }
                }
            } else {
                echo "<div class='test-warning'>⚠ No se encontraron usuarios con Pablo o Lanfranco</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='test-error'>✗ Error en actualización: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<form method='POST'>";
        echo "<div class='alert alert-warning'>";
        echo "<strong>⚠️ Atención:</strong> Esta prueba modificará la base de datos. ";
        echo "Solo úsela si está seguro y ha hecho backup.";
        echo "</div>";
        echo "<button type='submit' name='test_update' class='btn btn-warning'>Ejecutar Prueba de Actualización</button>";
        echo "</form>";
    }
    
    ?>
    
    <div class="mt-4">
        <a href="index.php" class="btn btn-secondary">← Volver al sistema</a>
    </div>
</div>
</body>
</html>
