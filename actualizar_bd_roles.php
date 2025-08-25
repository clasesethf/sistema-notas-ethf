<?php
/**
 * actualizar_bd_roles.php - Script para actualizar la base de datos con sistema de roles múltiples
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * INSTRUCCIONES:
 * 1. Subir este archivo al directorio raíz del proyecto
 * 2. Acceder desde el navegador: http://tudominio/actualizar_bd_roles.php
 * 3. Seguir las instrucciones en pantalla
 * 4. ELIMINAR este archivo después de usar por seguridad
 */

// Iniciar buffer de salida
ob_start();

// Configuración de seguridad
$PERMITIR_EJECUCION = true; // Cambiar a false después de usar
$USUARIOS_AUTORIZADOS = ['admin']; // Solo estos tipos de usuario pueden ejecutar

// Inicializar sesión
session_start();

// Verificar si se permite la ejecución
if (!$PERMITIR_EJECUCION) {
    die('<h1>Script deshabilitado</h1><p>Este script ha sido deshabilitado por seguridad.</p>');
}

// Verificar autenticación (opcional pero recomendado)
if (isset($_SESSION['user_id']) && !in_array($_SESSION['user_type'], $USUARIOS_AUTORIZADOS)) {
    die('<h1>Acceso denegado</h1><p>No tiene permisos para ejecutar este script.</p>');
}

// Incluir configuración
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
    <title>Actualización BD - Sistema Roles Múltiples</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .log-success { color: #28a745; }
        .log-error { color: #dc3545; }
        .log-warning { color: #ffc107; }
        .log-info { color: #17a2b8; }
        .code-block { 
            background: #f8f9fa; 
            border: 1px solid #dee2e6; 
            border-radius: 0.375rem; 
            padding: 1rem; 
            font-family: 'Courier New', monospace; 
            font-size: 0.9rem;
            white-space: pre-wrap;
        }
        .warning-box {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #ffeb3b;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <h1 class="text-center mb-4">
                <i class="bi bi-database-gear"></i>
                Actualización Base de Datos - Sistema Roles Múltiples
            </h1>
            
            <div class="warning-box">
                <h4><i class="bi bi-exclamation-triangle-fill text-warning"></i> ¡IMPORTANTE!</h4>
                <ul class="mb-0">
                    <li><strong>Haga backup de su base de datos antes de continuar</strong></li>
                    <li>Este script modificará la estructura de la tabla <code>usuarios</code></li>
                    <li>Creará una tabla de auditoría <code>log_cambios_rol</code></li>
                    <li>Asignará rol secundario "directivo" a Pablo Lanfranco</li>
                    <li><strong>Elimine este archivo después de usarlo</strong></li>
                </ul>
            </div>

            <?php
            // Procesar formulario
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ejecutar_actualizacion'])) {
                echo '<div class="card">';
                echo '<div class="card-header bg-primary text-white">';
                echo '<h5><i class="bi bi-gear-fill"></i> Ejecutando Actualización...</h5>';
                echo '</div>';
                echo '<div class="card-body">';
                echo '<div class="log-output" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.9rem;">';
                
                $errores = 0;
                $warnings = 0;
                $exitos = 0;
                
                try {
                    // Función para logging
                    function logMessage($mensaje, $tipo = 'info') {
                        $timestamp = date('H:i:s');
                        $clase = "log-$tipo";
                        $icono = [
                            'success' => 'check-circle-fill',
                            'error' => 'x-circle-fill', 
                            'warning' => 'exclamation-triangle-fill',
                            'info' => 'info-circle-fill'
                        ][$tipo] ?? 'info-circle';
                        
                        echo "<div class='$clase'><i class='bi bi-$icono'></i> [$timestamp] $mensaje</div>";
                        flush();
                        ob_flush();
                    }
                    
                    logMessage("Iniciando actualización de base de datos...", 'info');
                    
                    // PASO 1: Verificar estructura actual
                    logMessage("Verificando estructura actual de la tabla usuarios...", 'info');
                    
                    $columnas = $db->fetchAll("PRAGMA table_info(usuarios)");
                    $tieneRolesSecundarios = false;
                    
                    foreach ($columnas as $columna) {
                        if ($columna['name'] === 'roles_secundarios') {
                            $tieneRolesSecundarios = true;
                            break;
                        }
                    }
                    
                    if ($tieneRolesSecundarios) {
                        logMessage("La columna 'roles_secundarios' ya existe", 'warning');
                        $warnings++;
                    } else {
                        logMessage("La columna 'roles_secundarios' no existe, se creará", 'info');
                    }
                    
                    // PASO 2: Agregar columna roles_secundarios
                    if (!$tieneRolesSecundarios) {
                        logMessage("Agregando columna 'roles_secundarios' a la tabla usuarios...", 'info');
                        
                        $db->query("ALTER TABLE usuarios ADD COLUMN roles_secundarios TEXT");
                        logMessage("✓ Columna 'roles_secundarios' agregada exitosamente", 'success');
                        $exitos++;
                    }
                    
                    // PASO 3: Buscar a Pablo Lanfranco con manejo mejorado de errores
                    logMessage("Buscando usuario Pablo Lanfranco...", 'info');
                    
                    try {
                        $pablo = $db->fetchOne("
                            SELECT id, nombre, apellido, tipo, roles_secundarios 
                            FROM usuarios 
                            WHERE UPPER(nombre) LIKE ? AND UPPER(apellido) LIKE ?
                        ", ['%PABLO%', '%LANFRANCO%']);
                        
                        if ($pablo) {
                            logMessage("✓ Usuario encontrado: {$pablo['nombre']} {$pablo['apellido']} (ID: {$pablo['id']}, Rol: {$pablo['tipo']})", 'success');
                            
                            if (!empty($pablo['roles_secundarios'])) {
                                logMessage("Usuario ya tiene roles secundarios: {$pablo['roles_secundarios']}", 'warning');
                                $warnings++;
                            } else {
                                logMessage("Asignando rol secundario 'directivo' a Pablo Lanfranco...", 'info');
                                $db->query("UPDATE usuarios SET roles_secundarios = ? WHERE id = ?", ['directivo', $pablo['id']]);
                                logMessage("✓ Rol secundario 'directivo' asignado exitosamente", 'success');
                                $exitos++;
                            }
                        } else {
                            logMessage("⚠ No se encontró usuario que coincida exactamente con 'Pablo Lanfranco'", 'warning');
                            
                            // Buscar usuarios similares
                            logMessage("Buscando usuarios similares...", 'info');
                            
                            $usuariosPablo = $db->fetchAll("
                                SELECT id, nombre, apellido, tipo 
                                FROM usuarios 
                                WHERE UPPER(nombre) LIKE ? OR UPPER(apellido) LIKE ?
                            ", ['%PABLO%', '%LANFRANCO%']);
                            
                            if ($usuariosPablo) {
                                logMessage("Usuarios encontrados que podrían coincidir:", 'info');
                                foreach ($usuariosPablo as $usuario) {
                                    logMessage("  - ID: {$usuario['id']}, Nombre: {$usuario['nombre']} {$usuario['apellido']}, Tipo: {$usuario['tipo']}", 'info');
                                }
                                
                                // Si solo hay uno, preguntar si es el correcto
                                if (count($usuariosPablo) == 1) {
                                    $usuario = $usuariosPablo[0];
                                    logMessage("Se encontró un solo usuario similar. Asignando rol a: {$usuario['nombre']} {$usuario['apellido']}", 'warning');
                                    $db->query("UPDATE usuarios SET roles_secundarios = ? WHERE id = ?", ['directivo', $usuario['id']]);
                                    logMessage("✓ Rol secundario 'directivo' asignado a {$usuario['nombre']} {$usuario['apellido']}", 'success');
                                    $exitos++;
                                }
                            } else {
                                logMessage("No se encontraron usuarios similares", 'warning');
                            }
                            $warnings++;
                        }
                    } catch (Exception $e) {
                        logMessage("ERROR al buscar/actualizar usuario: " . $e->getMessage(), 'error');
                        $errores++;
                    }
                    
                    // PASO 4: Verificar si existe tabla de log
                    logMessage("Verificando tabla de auditoría...", 'info');
                    
                    $tablaLog = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='log_cambios_rol'");
                    
                    if ($tablaLog) {
                        logMessage("La tabla 'log_cambios_rol' ya existe", 'warning');
                        $warnings++;
                    } else {
                        logMessage("Creando tabla de auditoría 'log_cambios_rol'...", 'info');
                        
                        $db->query("
                            CREATE TABLE log_cambios_rol (
                                id INTEGER PRIMARY KEY AUTOINCREMENT,
                                usuario_id INTEGER NOT NULL,
                                rol_anterior TEXT NOT NULL,
                                rol_nuevo TEXT NOT NULL,
                                fecha_cambio TEXT NOT NULL DEFAULT (datetime('now')),
                                ip_address TEXT,
                                FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
                            )
                        ");
                        
                        logMessage("✓ Tabla 'log_cambios_rol' creada exitosamente", 'success');
                        $exitos++;
                    }
                    
                    // PASO 5: Crear índices
                    logMessage("Creando índices para optimización...", 'info');
                    
                    $indices = [
                        "CREATE INDEX IF NOT EXISTS idx_log_cambios_usuario ON log_cambios_rol(usuario_id)",
                        "CREATE INDEX IF NOT EXISTS idx_log_cambios_fecha ON log_cambios_rol(fecha_cambio)",
                        "CREATE INDEX IF NOT EXISTS idx_usuarios_roles ON usuarios(tipo, roles_secundarios)"
                    ];
                    
                    foreach ($indices as $indice) {
                        $db->query($indice);
                    }
                    
                    logMessage("✓ Índices creados exitosamente", 'success');
                    $exitos++;
                    
                    // PASO 6: Verificación final
                    logMessage("Ejecutando verificación final...", 'info');
                    
                    $usuariosMultipleRoles = $db->fetchAll("
                        SELECT 
                            id,
                            nombre || ' ' || apellido as nombre_completo,
                            tipo as rol_principal,
                            roles_secundarios
                        FROM usuarios
                        WHERE roles_secundarios IS NOT NULL AND roles_secundarios != ''
                        ORDER BY nombre, apellido
                    ");
                    
                    logMessage("Usuarios con múltiples roles encontrados: " . count($usuariosMultipleRoles), 'info');
                    
                    foreach ($usuariosMultipleRoles as $usuario) {
                        logMessage("  - {$usuario['nombre_completo']}: {$usuario['rol_principal']} + {$usuario['roles_secundarios']}", 'success');
                    }
                    
                    // PASO 7: Registro de finalización
                    logMessage("=== RESUMEN DE ACTUALIZACIÓN ===", 'info');
                    logMessage("✓ Operaciones exitosas: $exitos", 'success');
                    logMessage("⚠ Advertencias: $warnings", 'warning');
                    logMessage("✗ Errores: $errores", $errores > 0 ? 'error' : 'info');
                    logMessage("=== ACTUALIZACIÓN COMPLETADA ===", $errores > 0 ? 'warning' : 'success');
                    
                } catch (Exception $e) {
                    logMessage("ERROR CRÍTICO: " . $e->getMessage(), 'error');
                    logMessage("Stack trace: " . $e->getTraceAsString(), 'error');
                    $errores++;
                }
                
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                // Mostrar siguiente paso
                if ($errores === 0) {
                    echo '
                    <div class="alert alert-success mt-3">
                        <h5><i class="bi bi-check-circle-fill"></i> ¡Actualización completada!</h5>
                        <p>La base de datos ha sido actualizada exitosamente. Los próximos pasos son:</p>
                        <ol>
                            <li>Crear el archivo <code>cambiar_rol.php</code></li>
                            <li>Modificar <code>header.php</code> con las nuevas funciones</li>
                            <li>Actualizar las verificaciones de permisos en otras páginas</li>
                            <li><strong>Eliminar este archivo por seguridad</strong></li>
                        </ol>
                    </div>';
                } else {
                    echo '
                    <div class="alert alert-danger mt-3">
                        <h5><i class="bi bi-exclamation-triangle-fill"></i> Se encontraron errores</h5>
                        <p>Revise el log anterior y corrija los problemas antes de continuar.</p>
                    </div>';
                }
                
            } else {
                // Mostrar formulario de confirmación
                ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-list-check"></i> Verificación Previa</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Verificar estado actual
                        try {
                            echo '<h6>Estado actual de la base de datos:</h6>';
                            
                            // Verificar tabla usuarios
                            $columnas = $db->fetchAll("PRAGMA table_info(usuarios)");
                            $tieneRolesSecundarios = false;
                            foreach ($columnas as $columna) {
                                if ($columna['name'] === 'roles_secundarios') {
                                    $tieneRolesSecundarios = true;
                                    break;
                                }
                            }
                            
                            echo '<ul>';
                            echo '<li>Tabla usuarios: <span class="text-success">✓ Existe</span></li>';
                            echo '<li>Columna roles_secundarios: ' . 
                                 ($tieneRolesSecundarios ? '<span class="text-warning">⚠ Ya existe</span>' : '<span class="text-info">- Será creada</span>') . 
                                 '</li>';
                            
                            // Verificar Pablo Lanfranco con consulta más segura
                            try {
                                if ($tieneRolesSecundarios) {
                                    $pablo = $db->fetchOne("
                                        SELECT nombre, apellido, tipo, roles_secundarios 
                                        FROM usuarios 
                                        WHERE UPPER(nombre) LIKE ? AND UPPER(apellido) LIKE ?
                                    ", ['%PABLO%', '%LANFRANCO%']);
                                } else {
                                    $pablo = $db->fetchOne("
                                        SELECT nombre, apellido, tipo 
                                        FROM usuarios 
                                        WHERE UPPER(nombre) LIKE ? AND UPPER(apellido) LIKE ?
                                    ", ['%PABLO%', '%LANFRANCO%']);
                                }
                                
                                if ($pablo) {
                                    echo '<li>Usuario Pablo Lanfranco: <span class="text-success">✓ Encontrado</span> (' . 
                                         htmlspecialchars($pablo['nombre']) . ' ' . htmlspecialchars($pablo['apellido']) . ' - ' . htmlspecialchars($pablo['tipo']) . ')';
                                    if ($tieneRolesSecundarios && !empty($pablo['roles_secundarios'])) {
                                        echo ' <span class="text-warning">⚠ Ya tiene roles secundarios: ' . htmlspecialchars($pablo['roles_secundarios']) . '</span>';
                                    }
                                    echo '</li>';
                                } else {
                                    echo '<li>Usuario Pablo Lanfranco: <span class="text-warning">⚠ No encontrado</span></li>';
                                    
                                    // Mostrar usuarios con PABLO en el nombre para ayudar
                                    $usuariosPablo = $db->fetchAll("SELECT nombre, apellido FROM usuarios WHERE UPPER(nombre) LIKE ?", ['%PABLO%']);
                                    if ($usuariosPablo) {
                                        echo '<li style="margin-left: 20px; font-size: 0.9em;">Usuarios encontrados con "PABLO": ';
                                        $nombres = [];
                                        foreach ($usuariosPablo as $u) {
                                            $nombres[] = htmlspecialchars($u['nombre'] . ' ' . $u['apellido']);
                                        }
                                        echo implode(', ', $nombres) . '</li>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<li>Usuario Pablo Lanfranco: <span class="text-danger">✗ Error al verificar: ' . htmlspecialchars($e->getMessage()) . '</span></li>';
                            }
                            
                            // Verificar tabla de log con manejo de errores
                            try {
                                $tablaLog = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='log_cambios_rol'");
                                echo '<li>Tabla log_cambios_rol: ' . 
                                     ($tablaLog ? '<span class="text-warning">⚠ Ya existe</span>' : '<span class="text-info">- Será creada</span>') . 
                                     '</li>';
                            } catch (Exception $e) {
                                echo '<li>Tabla log_cambios_rol: <span class="text-danger">✗ Error al verificar: ' . htmlspecialchars($e->getMessage()) . '</span></li>';
                            }
                            
                            echo '</ul>';
                            
                        } catch (Exception $e) {
                            echo '<div class="alert alert-danger">Error al verificar estado: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="bi bi-exclamation-triangle-fill"></i> Confirmación de Ejecución</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Esta operación realizará los siguientes cambios:</strong></p>
                        <ol>
                            <li>Agregar columna <code>roles_secundarios</code> a la tabla <code>usuarios</code></li>
                            <li>Asignar rol secundario "directivo" a Pablo Lanfranco</li>
                            <li>Crear tabla de auditoría <code>log_cambios_rol</code></li>
                            <li>Crear índices para optimización</li>
                        </ol>
                        
                        <div class="alert alert-warning">
                            <strong>¡Atención!</strong> Se recomienda hacer un backup de la base de datos antes de continuar.
                        </div>
                        
                        <form method="POST">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirmar_backup" required>
                                <label class="form-check-label" for="confirmar_backup">
                                    Confirmo que he realizado un backup de la base de datos
                                </label>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirmar_riesgos" required>
                                <label class="form-check-label" for="confirmar_riesgos">
                                    Entiendo los riesgos y deseo continuar con la actualización
                                </label>
                            </div>
                            
                            <button type="submit" name="ejecutar_actualizacion" class="btn btn-warning btn-lg">
                                <i class="bi bi-gear-fill"></i> Ejecutar Actualización
                            </button>
                            
                            <a href="index.php" class="btn btn-secondary btn-lg ms-2">
                                <i class="bi bi-arrow-left"></i> Cancelar
                            </a>
                        </form>
                    </div>
                </div>
                
                <?php
            }
            ?>
            
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5><i class="bi bi-info-circle-fill"></i> Información Adicional</h5>
                </div>
                <div class="card-body">
                    <h6>Archivos que necesitarás crear/modificar después:</h6>
                    <ul>
                        <li><code>cambiar_rol.php</code> - Endpoint para cambio de roles</li>
                        <li><code>header.php</code> - Agregar funciones y selector de rol</li>
                        <li><code>usuarios.php</code> y otras páginas - Actualizar verificaciones de permisos</li>
                    </ul>
                    
                    <h6 class="mt-3">Usuarios que podrán tener múltiples roles:</h6>
                    <p>Después de esta actualización, Pablo Lanfranco podrá cambiar entre los roles "profesor" (principal) y "directivo" (secundario).</p>
                    
                    <div class="alert alert-danger mt-3">
                        <strong>Seguridad:</strong> Elimine este archivo (<code>actualizar_bd_roles.php</code>) después de usarlo para evitar accesos no autorizados.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
