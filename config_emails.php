<?php
/**
 * config_emails.php - Configuración del email institucional
 * Para configurar SMTP y credenciales
 */

require_once 'config.php';

// Verificar que es administrador o directivo
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();

// Verificar que las tablas existen
$tablaConfigEmail = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='configuracion_email'");
if (!$tablaConfigEmail) {
    // Crear tabla si no existe
    $db->query("
        CREATE TABLE IF NOT EXISTS configuracion_email (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_institucional TEXT NOT NULL,
            password_email TEXT NOT NULL,
            nombre_remitente TEXT NOT NULL,
            smtp_servidor TEXT NOT NULL DEFAULT 'smtp.office365.com',
            smtp_puerto INTEGER NOT NULL DEFAULT 587,
            smtp_seguridad TEXT NOT NULL DEFAULT 'tls',
            activo INTEGER NOT NULL DEFAULT 1,
            fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

// Procesar actualización de configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $emailInstitucional = trim($_POST['email_institucional']);
        $passwordEmail = trim($_POST['password_email']);
        $nombreRemitente = trim($_POST['nombre_remitente']);
        $smtpServidor = trim($_POST['smtp_servidor']);
        $smtpPuerto = intval($_POST['smtp_puerto']);
        $smtpSeguridad = $_POST['smtp_seguridad'];
        
        // Validaciones básicas
        if (!filter_var($emailInstitucional, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email institucional no válido");
        }
        
        if (empty($passwordEmail)) {
            throw new Exception("Password es requerido");
        }
        
        if (empty($nombreRemitente)) {
            throw new Exception("Nombre del remitente es requerido");
        }
        
        // Verificar si ya existe configuración
        $configExistente = $db->fetchOne("SELECT id FROM configuracion_email LIMIT 1");
        
        if ($configExistente) {
            // Actualizar
            $db->query(
                "UPDATE configuracion_email SET 
                 email_institucional = ?, password_email = ?, nombre_remitente = ?,
                 smtp_servidor = ?, smtp_puerto = ?, smtp_seguridad = ?,
                 fecha_actualizacion = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [$emailInstitucional, $passwordEmail, $nombreRemitente, 
                 $smtpServidor, $smtpPuerto, $smtpSeguridad, $configExistente['id']]
            );
        } else {
            // Insertar
            $db->query(
                "INSERT INTO configuracion_email 
                 (email_institucional, password_email, nombre_remitente, smtp_servidor, smtp_puerto, smtp_seguridad)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$emailInstitucional, $passwordEmail, $nombreRemitente, 
                 $smtpServidor, $smtpPuerto, $smtpSeguridad]
            );
        }
        
        $_SESSION['message'] = 'Configuración de emails actualizada correctamente';
        $_SESSION['message_type'] = 'success';
        
        // Probar configuración si se solicita
        if (isset($_POST['probar_configuracion'])) {
            $resultadoPrueba = probarConfiguracionSMTP($emailInstitucional, $passwordEmail, $smtpServidor, $smtpPuerto, $smtpSeguridad, $nombreRemitente);
            if ($resultadoPrueba['success']) {
                $_SESSION['message'] .= ' - Prueba de conexión exitosa';
            } else {
                $_SESSION['message'] .= ' - ERROR en prueba: ' . $resultadoPrueba['error'];
                $_SESSION['message_type'] = 'warning';
            }
        }
        
        // Usar JavaScript para redireccionar en lugar de header()
        echo "<script>window.location.href = 'config_emails.php';</script>";
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al actualizar configuración: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Incluir header después del procesamiento
require_once 'header.php';

// Obtener configuración actual
$configuracion = $db->fetchOne("SELECT * FROM configuracion_email WHERE activo = 1 ORDER BY id DESC LIMIT 1");

// Obtener estadísticas de envíos
$estadisticas = [
    'total_enviados' => 0,
    'total_errores' => 0,
    'envios_hoy' => 0,
    'envios_semana' => 0
];

try {
    $tablaEnvios = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='envios_boletines'");
    if ($tablaEnvios) {
        $estadisticas['total_enviados'] = $db->fetchOne("SELECT COUNT(*) as count FROM envios_boletines WHERE estado = 'enviado'")['count'] ?? 0;
        $estadisticas['total_errores'] = $db->fetchOne("SELECT COUNT(*) as count FROM envios_boletines WHERE estado = 'error'")['count'] ?? 0;
        $estadisticas['envios_hoy'] = $db->fetchOne("SELECT COUNT(*) as count FROM envios_boletines WHERE DATE(fecha_envio) = DATE('now')")['count'] ?? 0;
        $estadisticas['envios_semana'] = $db->fetchOne("SELECT COUNT(*) as count FROM envios_boletines WHERE fecha_envio >= DATE('now', '-7 days')")['count'] ?? 0;
    }
} catch (Exception $e) {
    // Si hay error, mantener estadísticas en 0
}

// Obtener estadísticas de emails configurados
$emailStats = [
    'total_estudiantes' => 0,
    'con_email' => 0,
    'sin_email' => 0,
    'porcentaje' => 0
];

try {
    $emailStats['total_estudiantes'] = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante'")['count'] ?? 0;
    $emailStats['con_email'] = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND email IS NOT NULL AND email != ''")['count'] ?? 0;
    $emailStats['sin_email'] = $emailStats['total_estudiantes'] - $emailStats['con_email'];
    $emailStats['porcentaje'] = $emailStats['total_estudiantes'] > 0 ? round(($emailStats['con_email'] / $emailStats['total_estudiantes']) * 100, 1) : 0;
} catch (Exception $e) {
    // Si hay error, mantener estadísticas en 0
}

/**
 * Función para probar configuración SMTP
 */
function probarConfiguracionSMTP($email, $password, $servidor, $puerto, $seguridad, $nombre) {
    try {
        // Verificar si PHPMailer está disponible
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // Intentar cargar PHPMailer desde diferentes ubicaciones
            $posiblesPaths = [
                'vendor/autoload.php',
                '../vendor/autoload.php',
                'phpmailer/src/PHPMailer.php'
            ];
            
            $cargado = false;
            foreach ($posiblesPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    $cargado = true;
                    break;
                }
            }
            
            if (!$cargado) {
                return ['success' => false, 'error' => 'PHPMailer no está instalado. Configure manualmente.'];
            }
        }
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración del servidor
        $mail->isSMTP();
        $mail->Host = $servidor;
        $mail->SMTPAuth = true;
        $mail->Username = $email;
        $mail->Password = $password;
        $mail->SMTPSecure = ($seguridad === 'tls') ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $puerto;
        
        // Configuración adicional
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Intentar conectar (sin enviar email)
        $mail->smtpConnect();
        $mail->smtpClose();
        
        return ['success' => true, 'error' => null];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-envelope-gear"></i> Configuración del Sistema de Emails</h2>
                    <p class="text-muted">Configure el email institucional para envío de boletines</p>
                </div>
                <div>
                    <a href="gestionar_emails.php" class="btn btn-outline-primary">
                        <i class="bi bi-people"></i> Gestionar Emails Familias
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $estadisticas['total_enviados'] ?></h4>
                            <p class="card-text">Emails Enviados</p>
                        </div>
                        <div><i class="bi bi-envelope-check fs-1"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $estadisticas['total_errores'] ?></h4>
                            <p class="card-text">Errores</p>
                        </div>
                        <div><i class="bi bi-envelope-x fs-1"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $estadisticas['envios_hoy'] ?></h4>
                            <p class="card-text">Hoy</p>
                        </div>
                        <div><i class="bi bi-calendar-day fs-1"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4><?= $emailStats['con_email'] ?>/<?= $emailStats['total_estudiantes'] ?></h4>
                            <p class="card-text">Emails Configurados (<?= $emailStats['porcentaje'] ?>%)</p>
                        </div>
                        <div><i class="bi bi-people fs-1"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Configuración SMTP -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-gear"></i> Configuración SMTP</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email_institucional" class="form-label">Email Institucional *</label>
                                    <input type="email" class="form-control" id="email_institucional" 
                                           name="email_institucional" required
                                           value="<?= htmlspecialchars($configuracion['email_institucional'] ?? 'ffernandez@henryford.edu.ar') ?>">
                                    <small class="form-text text-muted">Su email institucional de Outlook</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password_email" class="form-label">Password del Email *</label>
                                    <input type="password" class="form-control" id="password_email" 
                                           name="password_email" required
                                           value="<?= ($configuracion['password_email'] ?? '') !== 'CAMBIAR_PASSWORD' ? htmlspecialchars($configuracion['password_email'] ?? '') : '' ?>"
                                           placeholder="Ingrese su contraseña de Outlook">
                                    <small class="form-text text-muted">Contraseña de su email institucional</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nombre_remitente" class="form-label">Nombre del Remitente *</label>
                            <input type="text" class="form-control" id="nombre_remitente" 
                                   name="nombre_remitente" required
                                   value="<?= htmlspecialchars($configuracion['nombre_remitente'] ?? 'Escuela Técnica Henry Ford') ?>">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="smtp_servidor" class="form-label">Servidor SMTP *</label>
                                    <input type="text" class="form-control" id="smtp_servidor" 
                                           name="smtp_servidor" required
                                           value="<?= htmlspecialchars($configuracion['smtp_servidor'] ?? 'smtp.office365.com') ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="smtp_puerto" class="form-label">Puerto *</label>
                                    <input type="number" class="form-control" id="smtp_puerto" 
                                           name="smtp_puerto" required
                                           value="<?= htmlspecialchars($configuracion['smtp_puerto'] ?? '587') ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="smtp_seguridad" class="form-label">Seguridad *</label>
                                    <select class="form-select" id="smtp_seguridad" name="smtp_seguridad" required>
                                        <option value="tls" <?= (($configuracion['smtp_seguridad'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>TLS</option>
                                        <option value="ssl" <?= (($configuracion['smtp_seguridad'] ?? 'tls') === 'ssl') ? 'selected' : '' ?>>SSL</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i> Guardar Configuración
                            </button>
                            <button type="submit" name="probar_configuracion" class="btn btn-secondary">
                                <i class="bi bi-plug"></i> Guardar y Probar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Panel de información -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-info-circle"></i> Información</h5>
                </div>
                <div class="card-body">
                    <h6>Configuración para Outlook/Office365:</h6>
                    <ul class="list-unstyled">
                        <li><strong>Servidor:</strong> smtp.office365.com</li>
                        <li><strong>Puerto:</strong> 587</li>
                        <li><strong>Seguridad:</strong> TLS</li>
                        <li><strong>Autenticación:</strong> Requerida</li>
                    </ul>
                    
                    <hr>
                    
                    <div class="alert alert-warning">
                        <small><i class="bi bi-exclamation-triangle"></i> 
                        Asegúrese de que su email tenga habilitado el acceso SMTP. 
                        En algunos casos puede necesitar una "contraseña de aplicación".</small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="gestionar_emails.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-envelope-at"></i> Gestionar Emails Familias
                        </a>
                        <a href="boletines.php" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-file-text"></i> Ir a Boletines
                        </a>
                        <?php if ($emailStats['sin_email'] > 0): ?>
                        <a href="gestionar_emails.php" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-exclamation-triangle"></i> <?= $emailStats['sin_email'] ?> sin email
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Últimos envíos -->
            <div class="card mt-3">
                <div class="card-header">
                    <h5><i class="bi bi-clock-history"></i> Últimos Envíos</h5>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $ultimosEnvios = $db->fetchAll(
                            "SELECT eb.*, u.apellido, u.nombre 
                             FROM envios_boletines eb
                             JOIN usuarios u ON eb.estudiante_id = u.id
                             ORDER BY eb.fecha_envio DESC 
                             LIMIT 5"
                        );
                    } catch (Exception $e) {
                        $ultimosEnvios = [];
                    }
                    ?>
                    
                    <?php if (!empty($ultimosEnvios)): ?>
                        <?php foreach ($ultimosEnvios as $envio): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <small class="fw-bold"><?= htmlspecialchars($envio['apellido'] . ', ' . $envio['nombre']) ?></small><br>
                                    <small class="text-muted"><?= date('d/m/Y H:i', strtotime($envio['fecha_envio'])) ?></small>
                                </div>
                                <div>
                                    <?php if ($envio['estado'] === 'enviado'): ?>
                                        <span class="badge bg-success">✓</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">✗</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No hay envíos registrados</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Validación del formulario
document.querySelector('form').addEventListener('submit', function(e) {
    const email = document.getElementById('email_institucional').value;
    const password = document.getElementById('password_email').value;
    
    if (!email || !password) {
        e.preventDefault();
        alert('Por favor complete todos los campos requeridos');
        return false;
    }
    
    // Validar formato de email
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Por favor ingrese un email válido');
        return false;
    }
});

// Mostrar/ocultar contraseña
const passwordInput = document.getElementById('password_email');
passwordInput.addEventListener('dblclick', function() {
    this.type = this.type === 'password' ? 'text' : 'password';
});
</script>

<?php require_once 'footer.php'; ?>
