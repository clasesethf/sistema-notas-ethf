<?php
/**
 * login.php - Página de inicio de sesión con logo local
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 *
 * MODIFIED: Integrated Active Directory (AD) authentication with fallback to database.
 */

// Iniciar sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirigir si ya está autenticado
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Incluir archivo de configuración
require_once 'config.php'; // Still needed for APP_NAME, SCHOOL_NAME etc.

// --- Active Directory Configuration ---
// Enable/disable AD authentication. Set to true to attempt AD first.
$AD_AUTH_ENABLED = extension_loaded('ldap'); // true or false
const AD_HOST = '10.0.20.21';
const AD_PORT = 389; // Use 636 for LDAPS in production! (Requires SSL/TLS setup on AD)
const AD_DOMAIN = 'ad.henryford.edu.ar';
const AD_BASE_DN = 'dc=ad,dc=henryford,dc=edu,dc=ar';
// Example: adjust this to the actual DN of your 'Profesor' group in AD
const AD_REQUIRED_GROUP = 'CN=Profesor ETHF,OU=Staff,OU=Security_and_access,OU=Domain_Groups,DC=ad,DC=henryford,DC=edu,DC=ar';

// Process the login form
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['dni'] ?? ''; // Can be DNI for DB or sAMAccountName for AD
    $password = $_POST['password'] ?? '';

    // Get database connection
    $db = Database::getInstance();

    if (empty($identifier) || empty($password)) {
        $error = 'Por favor, complete todos los campos.';
    } else {
        $authenticated = false;
        $userSessionData = []; // To store user details for session

        // --- 1. Attempt Active Directory Authentication (if enabled) ---
        if ($AD_AUTH_ENABLED) {
            $ldapConn = @ldap_connect(AD_HOST, AD_PORT);

            if ($ldapConn) {
                // Set LDAP options
                ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0); // Important for AD
                ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3); // Standard for AD

                // The bind DN for AD is typically 'username@domain.com'
                $bindDn = "{$identifier}@" . AD_DOMAIN;

                // Attempt to bind (authenticate) the user
                $ldapBind = @ldap_bind($ldapConn, $bindDn, $password);

                if ($ldapBind) {
                    // AD Authentication successful
                    // Now, retrieve user details and check group membership
                    $filter = "(sAMAccountName={$identifier})";
                    $search = @ldap_search(
                        $ldapConn,
                        AD_BASE_DN,
                        $filter,
                        ['displayname', 'mail', 'memberof', 'samaccountname'],
                    );
                    $entries = @ldap_get_entries($ldapConn, $search);

                    $isMemberOfRequiredGroup = false;
                    $adDisplayName = $identifier; // Default to identifier if displayname not found

                    if ($entries['count'] > 0) {
                        $adUserDetails = $entries[0];
                        $adDisplayName =
                            $adUserDetails['displayname'][0] ?? $identifier;

                        // Check if the user is a member of the required group
                        if (isset($adUserDetails['memberof'])) {
                            foreach ($adUserDetails['memberof'] as $groupDn) {
                                if (
                                    is_string($groupDn) &&
                                    strtolower($groupDn) ===
                                    strtolower(AD_REQUIRED_GROUP)
                                ) {
                                    $isMemberOfRequiredGroup = true;
                                    break;
                                }
                            }
                        }
                    }

                    ldap_close($ldapConn);

                    if ($isMemberOfRequiredGroup) {
                        // Search user by DNI
                        $user = $db->fetchOne(
                            "SELECT id, nombre, apellido, dni, contrasena, tipo, activo FROM usuarios WHERE dni = ?",
                            [$identifier],
                        );

                        if ($user && $user['activo'] == 1) {
                            // Check if a database password is set and is not empty
                            if (
                                isset($user['contrasena']) &&
                                $user['contrasena'] !== null &&
                                $user['contrasena'] !== ''
                            ) {
                                $authenticated = true;
                                $userSessionData = [
                                    'user_id' => $user['id'],
                                    'user_name' => $user['nombre'] . ' ' . $user['apellido'],
                                    'user_type' => $user['tipo'],
                                ];
                            } else {
                                // Password not defined in DB, treat as incorrect if AD failed
                                $error = 'Usuario o contraseña incorrectos.'; // Generic error
                            }
                        } else {
                            if ($user && $user['activo'] != 1) {
                                $error = 'Cuenta desactivada.';
                            } else {
                                $error = 'Usuario no encontrado.';
                            }
                        }
                    } else {
                        // User authenticated via AD but not in the required group
                        $error =
                            'Su cuenta no tiene permitido el acceso.';
                        error_log(
                            "AD: User '{$identifier}' authenticated but not in group '" .
                            AD_REQUIRED_GROUP .
                            "'.",
                        );
                    }
                } else {
                    // AD Authentication failed for this user
                    $adErrorMessage = ldap_error($ldapConn);
                    error_log(
                        "AD: Bind failed for {$bindDn}: " . $adErrorMessage,
                    );
                    ldap_close($ldapConn);
                    // Do not set $error here, try DB authentication next
                }
            } else {
                error_log(
                    "AD: Could not connect to LDAP server at " .
                    AD_HOST .
                    ":" .
                    AD_PORT .
                    ". Attempting database authentication.",
                );
                // Do not set $error here, allow fallback to DB
            }
        }

        // --- 2. Fallback to Database Authentication (if AD failed or not enabled) ---
        if (!$authenticated) {
            // Search user by DNI
            $user = $db->fetchOne(
                "SELECT id, nombre, apellido, dni, contrasena, tipo, activo FROM usuarios WHERE dni = ?",
                [$identifier],
            );

            if ($user && $user['activo'] == 1) {
                // Check if a database password is set and is not empty
                if (
                    isset($user['contrasena']) &&
                    $user['contrasena'] !== null &&
                    $user['contrasena'] !== ''
                ) {
                    // Verify password (in production use password_verify)
                    if ($password === $user['contrasena']) {
                        $authenticated = true;
                        $userSessionData = [
                            'user_id' => $user['id'],
                            'user_name' => $user['nombre'] . ' ' . $user['apellido'],
                            'user_type' => $user['tipo'],
                        ];
                    } else {
                        $error = 'Usuario o contraseña incorrectos.'; // Generic error
                    }
                } else {
                    // Password not defined in DB, treat as incorrect if AD failed
                    $error = 'Usuario o contraseña incorrectos.'; // Generic error
                }
            } else {
                if ($user && $user['activo'] != 1) {
                    $error = 'Cuenta desactivada.';
                } else {
                    $error = 'Usuario no encontrado.';
                }
            }
        }

        // --- 3. Finalize Session and Redirect ---
        if ($authenticated) {
            $_SESSION['user_id'] = $userSessionData['user_id'];
            $_SESSION['user_name'] = $userSessionData['user_name'];
            $_SESSION['user_type'] = $userSessionData['user_type'];

            // Redirect to the main panel
            header('Location: index.php');
            exit;
        } else {
            // If we reached here, neither AD nor DB authentication succeeded
            // $error should already be set by the failing method
            if (empty($error)) {
                $error = 'Usuario o contraseña incorrectos.';
            }
        }
    }
}

// Title of the page
$pageTitle = 'Iniciar Sesión';
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #4472c4 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow: hidden;
        }

        /* Elementos decorativos de fondo */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translate(0px, 0px) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
        }

        /* Formas geométricas flotantes */
        .floating-shape {
            position: absolute;
            opacity: 0.1;
            animation: floatShape 15s ease-in-out infinite;
        }

        .shape-1 {
            top: 10%;
            left: 10%;
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            animation-delay: -5s;
        }

        .shape-2 {
            top: 70%;
            right: 15%;
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.1);
            transform: rotate(45deg);
            animation-delay: -10s;
        }

        .shape-3 {
            bottom: 20%;
            left: 20%;
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.1);
            border-radius: 30% 70% 70% 30%;
            animation-delay: -2s;
        }

        @keyframes floatShape {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* Contenedor principal del login */
        .login-container {
            background: white; /* Fondo completamente blanco */
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            padding: 3rem 2rem;
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 10;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Encabezado del formulario */
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-container {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .logo-img {
            max-width: 150px; /* Tamaño original más grande */
            height: auto;
            border-radius: 0; /* Sin bordes redondeados como el original */
            /* Sin sombras para integración perfecta */
            transition: all 0.4s ease;
            /* Efecto flotante sutil */
            animation: logoFloat 3s ease-in-out infinite;
            /* Sin fondo ni padding para integración total */
        }

        /* Animación de flotación del logo (sin sombras) */
        @keyframes logoFloat {
            0%, 100% { 
                transform: translateY(0px);
            }
            50% { 
                transform: translateY(-8px);
            }
        }

        .logo-img:hover {
            transform: translateY(-5px) scale(1.02);
        }

        .school-name {
            color: #2a5298;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            line-height: 1.2;
        }

        .app-name {
            color: #6c757d;
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 0;
        }

        /* Formulario */
        .login-form {
            width: 100%;
        }

        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1rem 1rem 1rem 3rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-control:focus {
            border-color: #2a5298;
            box-shadow: 0 0 0 0.2rem rgba(42, 82, 152, 0.25);
            background: white;
            outline: none;
        }

        .form-control::placeholder {
            color: #adb5bd;
            font-weight: 400;
        }

        /* Iconos en los campos */
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 1.1rem;
            z-index: 5;
            transition: color 0.3s ease;
        }

        .form-control:focus + .input-icon {
            color: #2a5298;
        }

        /* Botón de envío */
        .btn-login {
            background: linear-gradient(135deg, #2a5298 0%, #4472c4 100%);
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(42, 82, 152, 0.3);
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(42, 82, 152, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        /* Alerta de error */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.2);
        }

        .alert-danger {
            background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
            color: white;
        }

        /* Efectos de carga */
        .btn-login.loading {
            pointer-events: none;
            position: relative;
        }

        .btn-login.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: translate(-50%, -50%) rotate(360deg); }
        }

        /* Footer opcional */
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #6c757d;
            font-size: 0.85rem;
        }

        /* Responsive */
        @media (max-width: 576px) {
            .login-container {
                margin: 1rem;
                padding: 2rem 1.5rem;
            }
            
            .school-name {
                font-size: 1.3rem;
            }
            
            .form-control {
                padding: 0.875rem 1rem 0.875rem 2.75rem;
            }
        }

        /* Animaciones de entrada */
        .login-container {
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Efecto de partículas sutiles */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            animation: floatParticle 6s ease-in-out infinite;
        }

        @keyframes floatParticle {
            0%, 100% { 
                transform: translateY(0px) translateX(0px);
                opacity: 0;
            }
            50% { 
                transform: translateY(-100px) translateX(20px);
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <!-- Elementos decorativos de fondo -->
    <div class="floating-shape shape-1"></div>
    <div class="floating-shape shape-2"></div>
    <div class="floating-shape shape-3"></div>
    
    <!-- Partículas flotantes -->
    <div class="particles" id="particles"></div>

    <!-- Contenedor principal del login -->
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                <img class="logo-img" src="assets/img/logo.png" alt="Logo Escuela Técnica Henry Ford">
            </div>
            <h1 class="school-name"><?= SCHOOL_NAME ?></h1>
            <p class="app-name"><?= APP_NAME ?></p>
        </div>

        <form class="login-form" method="POST" action="login.php" id="loginForm">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <input type="text" 
                       class="form-control" 
                       id="dni" 
                       name="dni" 
                       placeholder="Ingrese su usuario" 
                       required
                       autocomplete="username">
                <i class="bi bi-person-fill input-icon"></i>
            </div>

            <div class="form-group">
                <input type="password" 
                       class="form-control" 
                       id="password" 
                       name="password" 
                       placeholder="Ingrese su contraseña" 
                       required
                       autocomplete="current-password">
                <i class="bi bi-lock-fill input-icon"></i>
            </div>

            <button class="btn-login" type="submit" id="loginBtn">
                <span class="btn-text">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Iniciar Sesión
                </span>
            </button>
        </form>

        <div class="login-footer d-none">
            <small>&copy; <?= date('Y') ?> <?= SCHOOL_NAME ?></small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Crear partículas flotantes
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 15;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 6 + 's';
                particle.style.animationDuration = (Math.random() * 3 + 3) + 's';
                particlesContainer.appendChild(particle);
            }
        }

        // Efecto de carga del botón
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnText = btn.querySelector('.btn-text');
            
            btn.classList.add('loading');
            btnText.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Iniciando sesión...';
            btn.disabled = true;
        });

        // Efectos de foco en los campos
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        });

        // Animación del logo al hacer clic (más sutil)
        document.querySelector('.logo-img').addEventListener('click', function() {
            this.style.animation = 'none';
            this.style.transform = 'translateY(-10px) scale(1.05)';
            setTimeout(() => {
                this.style.animation = 'logoFloat 3s ease-in-out infinite';
                this.style.transform = '';
            }, 400);
        });

        // Mostrar/ocultar contraseña (funcionalidad opcional)
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const icon = passwordInput.nextElementSibling;
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.className = 'bi bi-eye-fill input-icon';
            } else {
                passwordInput.type = 'password';
                icon.className = 'bi bi-lock-fill input-icon';
            }
        }

        // Inicializar efectos
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            
            // Enfocar el primer campo
            document.getElementById('dni').focus();
        });

        // Efecto de typing en placeholders (opcional)
        function typeWriter(element, text, speed = 100) {
            let i = 0;
            element.placeholder = '';
            
            function type() {
                if (i < text.length) {
                    element.placeholder += text.charAt(i);
                    i++;
                    setTimeout(type, speed);
                }
            }
            type();
        }

        // Validación en tiempo real (opcional)
        document.getElementById('dni').addEventListener('input', function(e) {
            const value = e.target.value;
            if (value.length > 0) {
                e.target.style.borderColor = '#28a745';
            } else {
                e.target.style.borderColor = '#e9ecef';
            }
        });
    </script>
</body>

</html>