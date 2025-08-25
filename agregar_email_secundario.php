<?php
/**
 * agregar_email_secundario.php - Agregar campo email_secundario a usuarios
 * Ejecutar UNA VEZ desde el navegador
 */

require_once 'config.php';

// Solo permitir a administradores
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('<h1>❌ Acceso denegado</h1><p>Solo administradores pueden ejecutar esta actualización.</p>');
}

$db = Database::getInstance();
$mensajes = [];
$errores = [];

function agregarMensaje($texto, $esError = false) {
    global $mensajes, $errores;
    if ($esError) {
        $errores[] = $texto;
    } else {
        $mensajes[] = $texto;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_campo'])) {
    
    agregarMensaje("🚀 Iniciando actualización de tabla usuarios...");
    
    try {
        // Verificar si ya existe el campo email_secundario
        $columnas = $db->fetchAll("PRAGMA table_info(usuarios)");
        $tieneEmailSecundario = false;
        
        foreach ($columnas as $columna) {
            if ($columna['name'] === 'email_secundario') {
                $tieneEmailSecundario = true;
                break;
            }
        }
        
        if ($tieneEmailSecundario) {
            agregarMensaje("ℹ️ El campo 'email_secundario' ya existe en la tabla usuarios");
        } else {
            // Agregar campo email_secundario
            $db->query("ALTER TABLE usuarios ADD COLUMN email_secundario VARCHAR(255) DEFAULT NULL");
            agregarMensaje("✅ Campo 'email_secundario' agregado correctamente");
        }
        
        // Verificar estructura final
        $columnasFinales = $db->fetchAll("PRAGMA table_info(usuarios)");
        $camposEmail = [];
        
        foreach ($columnasFinales as $columna) {
            if (strpos($columna['name'], 'email') !== false) {
                $camposEmail[] = $columna['name'] . ' (' . $columna['type'] . ')';
            }
        }
        
        agregarMensaje("📋 Campos de email en usuarios: " . implode(', ', $camposEmail));
        
        // Estadísticas actuales
        $totalEstudiantes = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante'")['count'];
        $conEmailPrincipal = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND email IS NOT NULL AND email != ''")['count'];
        $conEmailSecundario = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND email_secundario IS NOT NULL AND email_secundario != ''")['count'];
        
        agregarMensaje("📊 Estadísticas actuales:");
        agregarMensaje("   • Total estudiantes: $totalEstudiantes");
        agregarMensaje("   • Con email principal: $conEmailPrincipal");
        agregarMensaje("   • Con email secundario: $conEmailSecundario");
        
        agregarMensaje("🎉 ¡Actualización completada exitosamente!");
        agregarMensaje("📝 Próximo paso: Usar import_emails_excel.php para importar datos");
        
    } catch (Exception $e) {
        agregarMensaje("❌ Error durante la actualización: " . $e->getMessage(), true);
    }
}

// Verificar estado actual
$columnas = $db->fetchAll("PRAGMA table_info(usuarios)");
$tieneEmailSecundario = false;

foreach ($columnas as $columna) {
    if ($columna['name'] === 'email_secundario') {
        $tieneEmailSecundario = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar Email Secundario - Escuela Técnica Henry Ford</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                
                <div class="card">
                    <div class="card-header bg-info text-white text-center">
                        <h1><i class="bi bi-plus-circle"></i> Agregar Campo Email Secundario</h1>
                        <p class="mb-0">Preparar tabla usuarios para importación masiva</p>
                    </div>
                    
                    <div class="card-body">
                        
                        <!-- Estado actual -->
                        <h3><i class="bi bi-info-circle"></i> Estado Actual</h3>
                        
                        <div class="alert <?= $tieneEmailSecundario ? 'alert-success' : 'alert-warning' ?>">
                            <strong>Campo email_secundario:</strong>
                            <?php if ($tieneEmailSecundario): ?>
                                <i class="bi bi-check-circle"></i> Ya existe en la tabla usuarios
                            <?php else: ?>
                                <i class="bi bi-exclamation-triangle"></i> No existe, necesita ser agregado
                            <?php endif; ?>
                        </div>
                        
                        <!-- Información sobre la importación -->
                        <div class="alert alert-info">
                            <h5><i class="bi bi-file-earmark-excel"></i> Sobre la importación</h5>
                            <p>Su archivo Excel "Base mails familias 2025.xlsx" contiene:</p>
                            <ul>
                                <li><strong>7 hojas:</strong> Una por cada año (1er año a 7mo año)</li>
                                <li><strong>Columnas:</strong> DNI, Apellidos, Nombres, Email 1, Email 2</li>
                                <li><strong>Total aproximado:</strong> ~1000 registros</li>
                            </ul>
                            <p>Para importar ambos emails necesitamos el campo <code>email_secundario</code>.</p>
                        </div>
                        
                        <?php if (!$tieneEmailSecundario): ?>
                        <div class="alert alert-warning">
                            <h5><i class="bi bi-tools"></i> Actualización necesaria</h5>
                            <p>Este script agregará el campo <code>email_secundario</code> a la tabla usuarios.</p>
                            <p><strong>Es seguro: no modifica datos existentes, solo agrega una columna nueva.</strong></p>
                        </div>
                        
                        <form method="POST">
                            <div class="d-grid">
                                <button type="submit" name="agregar_campo" class="btn btn-primary btn-lg">
                                    <i class="bi bi-plus-circle"></i> Agregar Campo Email Secundario
                                </button>
                            </div>
                        </form>
                        
                        <?php else: ?>
                        <div class="alert alert-success">
                            <h5><i class="bi bi-check-circle"></i> Campo ya existe</h5>
                            <p>La tabla usuarios ya tiene el campo <code>email_secundario</code>.</p>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <a href="import_emails_excel.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-right"></i> Importar Emails desde Excel
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Resultados -->
                        <?php if (!empty($mensajes) || !empty($errores)): ?>
                        <hr>
                        <h4><i class="bi bi-terminal"></i> Resultados</h4>
                        
                        <div class="bg-dark text-light p-3 rounded">
                            <?php foreach ($mensajes as $mensaje): ?>
                                <div class="text-success"><?= htmlspecialchars($mensaje) ?></div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($errores as $error): ?>
                                <div class="text-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($errores)): ?>
                        <div class="alert alert-success mt-3">
                            <h5><i class="bi bi-check-circle"></i> ¡Actualización exitosa!</h5>
                            <p>Puede continuar con la importación desde Excel.</p>
                            
                            <a href="import_emails_excel.php" class="btn btn-primary">
                                <i class="bi bi-arrow-right"></i> Importar Emails desde Excel
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                        <!-- Información técnica -->
                        <hr>
                        <h4><i class="bi bi-info-circle"></i> Información Técnica</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>¿Qué hace este script?</h6>
                                <ul>
                                    <li>Verifica si existe email_secundario</li>
                                    <li>Agrega la columna si no existe</li>
                                    <li>Mantiene todos los datos existentes</li>
                                    <li>Prepara para importación masiva</li>
                                </ul>
                            </div>
                            
                            <div class="col-md-6">
                                <h6>Estructura final:</h6>
                                <ul>
                                    <li><code>email</code> → Email principal</li>
                                    <li><code>email_secundario</code> → Email alternativo</li>
                                    <li>Ambos campos pueden estar vacíos</li>
                                    <li>Compatible con sistema actual</li>
                                </ul>
                            </div>
                        </div>
                        
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
