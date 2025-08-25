<?php
/**
 * install_emails.php - Instalador del sistema de emails
 * Ejecutar SOLO UNA VEZ desde el navegador
 */

require_once 'config.php';

// Solo permitir ejecución a administradores
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('<h1>❌ Acceso denegado</h1><p>Solo administradores pueden ejecutar la instalación.</p>');
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

// Verificar si ya está instalado
function yaEstaInstalado($db) {
    try {
        $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('envios_boletines', 'configuracion_email')");
        return count($tablas) >= 2;
    } catch (Exception $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['instalar'])) {
    
    agregarMensaje("🚀 Iniciando instalación del sistema de emails...");
    
    try {
        // 1. Crear tabla envios_boletines
        $sqlEnvios = "
            CREATE TABLE IF NOT EXISTS envios_boletines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                estudiante_id INTEGER NOT NULL,
                curso_id INTEGER NOT NULL,
                ciclo_lectivo_id INTEGER NOT NULL,
                tipo_boletin TEXT NOT NULL CHECK (tipo_boletin IN ('cuatrimestre', 'bimestre')),
                periodo INTEGER NOT NULL,
                email_destinatario TEXT NOT NULL,
                fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
                estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('enviado', 'error', 'pendiente')),
                mensaje_error TEXT NULL,
                nombre_archivo TEXT NOT NULL,
                usuario_enviador INTEGER NOT NULL,
                FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                FOREIGN KEY (curso_id) REFERENCES cursos(id),
                FOREIGN KEY (usuario_enviador) REFERENCES usuarios(id)
            )
        ";
        
        $db->query($sqlEnvios);
        agregarMensaje("✅ Tabla 'envios_boletines' creada correctamente");
        
        // 2. Crear tabla configuracion_email
        $sqlConfig = "
            CREATE TABLE IF NOT EXISTS configuracion_email (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                smtp_servidor TEXT NOT NULL DEFAULT 'smtp.office365.com',
                smtp_puerto INTEGER NOT NULL DEFAULT 587,
                smtp_seguridad TEXT NOT NULL DEFAULT 'tls' CHECK (smtp_seguridad IN ('tls', 'ssl')),
                email_institucional TEXT NOT NULL,
                password_email TEXT NOT NULL,
                nombre_remitente TEXT NOT NULL DEFAULT 'Escuela Técnica Henry Ford',
                activo INTEGER DEFAULT 1 CHECK (activo IN (0, 1)),
                fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ";
        
        $db->query($sqlConfig);
        agregarMensaje("✅ Tabla 'configuracion_email' creada correctamente");
        
        // 3. Insertar configuración inicial
        $configExiste = $db->fetchOne("SELECT id FROM configuracion_email LIMIT 1");
        if (!$configExiste) {
            $sqlInsertConfig = "
                INSERT INTO configuracion_email 
                (email_institucional, password_email, nombre_remitente) 
                VALUES ('ffernandez@henryford.edu.ar', 'CAMBIAR_PASSWORD', 'Escuela Técnica Henry Ford')
            ";
            $db->query($sqlInsertConfig);
            agregarMensaje("✅ Configuración inicial insertada");
        } else {
            agregarMensaje("ℹ️ Configuración ya existía, no se modificó");
        }
        
        // 4. Crear índices
        $indices = [
            "CREATE INDEX IF NOT EXISTS idx_envios_estudiante ON envios_boletines(estudiante_id)",
            "CREATE INDEX IF NOT EXISTS idx_envios_curso ON envios_boletines(curso_id)",
            "CREATE INDEX IF NOT EXISTS idx_envios_fecha ON envios_boletines(fecha_envio)"
        ];
        
        foreach ($indices as $indice) {
            $db->query($indice);
        }
        agregarMensaje("✅ Índices creados correctamente");
        
        // 5. Verificar estructura de usuarios
        $columnasUsuarios = $db->fetchAll("PRAGMA table_info(usuarios)");
        $tieneEmail = false;
        foreach ($columnasUsuarios as $columna) {
            if ($columna['name'] === 'email') {
                $tieneEmail = true;
                break;
            }
        }
        
        if ($tieneEmail) {
            agregarMensaje("✅ Campo 'email' encontrado en tabla usuarios");
        } else {
            agregarMensaje("⚠️ ATENCIÓN: No se encontró campo 'email' en tabla usuarios", true);
        }
        
        // 6. Verificar tabla matriculas
        $tablaMatriculas = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='matriculas'");
        if ($tablaMatriculas) {
            agregarMensaje("✅ Tabla 'matriculas' encontrada");
        } else {
            agregarMensaje("⚠️ ATENCIÓN: No se encontró tabla 'matriculas'", true);
        }
        
        // 7. Estadísticas de emails
        if ($tieneEmail) {
            $totalEstudiantes = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante'")['count'];
            $conEmail = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND email IS NOT NULL AND email != ''")['count'];
            $sinEmail = $totalEstudiantes - $conEmail;
            
            agregarMensaje("📊 Estadísticas actuales:");
            agregarMensaje("   • Total estudiantes: $totalEstudiantes");
            agregarMensaje("   • Con email: $conEmail");
            agregarMensaje("   • Sin email: $sinEmail");
            agregarMensaje("   • Cobertura: " . ($totalEstudiantes > 0 ? round(($conEmail / $totalEstudiantes) * 100, 1) : 0) . "%");
        }
        
        agregarMensaje("🎉 ¡Instalación completada exitosamente!");
        agregarMensaje("📝 Próximos pasos:");
        agregarMensaje("   1. Configurar password en config_emails.php");
        agregarMensaje("   2. Cargar emails de familias");
        agregarMensaje("   3. Probar envío desde boletines.php");
        
    } catch (Exception $e) {
        agregarMensaje("❌ Error durante la instalación: " . $e->getMessage(), true);
    }
}

// Verificar estado actual
$instalado = yaEstaInstalado($db);
// Intentar cargar PHPMailer
$phpmailerInstalado = false;
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
    $phpmailerInstalado = class_exists('PHPMailer\PHPMailer\PHPMailer');
} elseif (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
    $phpmailerInstalado = class_exists('PHPMailer\PHPMailer\PHPMailer');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador Sistema de Emails - Escuela Técnica Henry Ford</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .install-header { background: linear-gradient(135deg, #49ADD7, #2980b9); }
        .mensaje { padding: 0.5rem 0; }
        .error { color: #dc3545; }
        .exito { color: #198754; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                
                <!-- Header -->
                <div class="card">
                    <div class="card-header install-header text-white text-center">
                        <h1><i class="bi bi-envelope-gear"></i> Instalador Sistema de Emails</h1>
                        <p class="mb-0">Escuela Técnica Henry Ford - Sistema de Boletines por Email</p>
                    </div>
                    
                    <div class="card-body">
                        
                        <!-- Estado actual -->
                        <h3><i class="bi bi-info-circle"></i> Estado Actual</h3>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="alert <?= $instalado ? 'alert-success' : 'alert-warning' ?>">
                                    <strong>Base de Datos:</strong>
                                    <?php if ($instalado): ?>
                                        <i class="bi bi-check-circle"></i> Sistema ya instalado
                                    <?php else: ?>
                                        <i class="bi bi-exclamation-triangle"></i> Pendiente de instalación
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="alert <?= $phpmailerInstalado ? 'alert-success' : 'alert-danger' ?>">
                                    <strong>PHPMailer:</strong>
                                    <?php if ($phpmailerInstalado): ?>
                                        <i class="bi bi-check-circle"></i> Instalado correctamente
                                    <?php else: ?>
                                        <i class="bi bi-x-circle"></i> No instalado
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!$phpmailerInstalado): ?>
                        <div class="alert alert-danger">
                            <h5><i class="bi bi-exclamation-triangle"></i> PHPMailer no encontrado</h5>
                            <p>Debe instalar PHPMailer antes de continuar:</p>
                            <code>composer require phpmailer/phpmailer</code>
                            <p class="mt-2">O descárguelo manualmente desde: <a href="https://github.com/PHPMailer/PHPMailer" target="_blank">GitHub PHPMailer</a></p>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Formulario de instalación -->
                        <?php if (!$instalado && $phpmailerInstalado): ?>
                        <div class="alert alert-info">
                            <h5><i class="bi bi-gear"></i> Listo para instalar</h5>
                            <p>El sistema creará las tablas necesarias en su base de datos SQLite existente.</p>
                            <p><strong>No se modificará ninguna tabla existente.</strong></p>
                        </div>
                        
                        <form method="POST">
                            <div class="d-grid">
                                <button type="submit" name="instalar" class="btn btn-primary btn-lg">
                                    <i class="bi bi-download"></i> Instalar Sistema de Emails
                                </button>
                            </div>
                        </form>
                        
                        <?php elseif ($instalado): ?>
                        <div class="alert alert-success">
                            <h5><i class="bi bi-check-circle"></i> Sistema ya instalado</h5>
                            <p>El sistema de emails ya está configurado en su base de datos.</p>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <a href="config_emails.php" class="btn btn-primary">
                                    <i class="bi bi-gear"></i> Configurar Email
                                </a>
                                <a href="gestionar_emails.php" class="btn btn-secondary">
                                    <i class="bi bi-envelope-at"></i> Gestionar Emails Familias
                                </a>
                                <a href="boletines.php" class="btn btn-success">
                                    <i class="bi bi-file-text"></i> Ir a Boletines
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Resultados de instalación -->
                        <?php if (!empty($mensajes) || !empty($errores)): ?>
                        <hr>
                        <h4><i class="bi bi-terminal"></i> Resultados de la Instalación</h4>
                        
                        <div class="bg-dark text-light p-3 rounded">
                            <?php foreach ($mensajes as $mensaje): ?>
                                <div class="mensaje exito"><?= htmlspecialchars($mensaje) ?></div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($errores as $error): ?>
                                <div class="mensaje error"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($errores)): ?>
                        <div class="alert alert-success mt-3">
                            <h5><i class="bi bi-check-circle"></i> ¡Instalación exitosa!</h5>
                            <p>Puede continuar con la configuración del sistema.</p>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                <a href="config_emails.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-right"></i> Configurar Email Institucional
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                        <!-- Información -->
                        <hr>
                        <h4><i class="bi bi-info-circle"></i> Información</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Lo que hace este instalador:</h6>
                                <ul>
                                    <li>Crea tabla <code>envios_boletines</code></li>
                                    <li>Crea tabla <code>configuracion_email</code></li>
                                    <li>Agrega configuración inicial</li>
                                    <li>Crea índices para mejor rendimiento</li>
                                    <li>Verifica estructura existente</li>
                                </ul>
                            </div>
                            
                            <div class="col-md-6">
                                <h6>Después de instalar:</h6>
                                <ol>
                                    <li>Configurar email institucional</li>
                                    <li>Cargar emails de familias</li>
                                    <li>Probar envío individual</li>
                                    <li>Usar envío masivo por curso</li>
                                </ol>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <small>
                                <strong>Nota:</strong> Este instalador es seguro y no modifica ninguna tabla existente. 
                                Solo agrega las funcionalidades de email al sistema actual.
                            </small>
                        </div>
                        
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
