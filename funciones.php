<?php
/**
 * Archivo de funciones generales del sistema
 * Sistema de Gestión Escolar
 */

/**
 * Verificar si el usuario está autenticado
 */
function verificarAutenticacion() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Verificar si es administrador
 */
function esAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

/**
 * Verificar si es directivo (incluye admin)
 */
function esDirectivo() {
    return isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'directivo']);
}

/**
 * Verificar si es profesor
 */
function esProfesor() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'profesor';
}

/**
 * Verificar si es preceptor
 */
function esPreceptor() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'preceptor';
}

/**
 * Verificar si es estudiante
 */
function esEstudiante() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'estudiante';
}

/**
 * Obtener el nombre completo del usuario actual
 */
function obtenerNombreUsuario() {
    return $_SESSION['user_name'] ?? 'Usuario';
}

/**
 * Obtener el tipo de usuario actual
 */
function obtenerTipoUsuario() {
    return $_SESSION['user_type'] ?? 'guest';
}

/**
 * Verificar si el usuario debe cambiar su contraseña obligatoriamente
 */
function verificarCambioPasswordObligatorio() {
    global $db;
    
    // Verificar si está habilitado el cambio obligatorio
    $configForzar = obtenerConfiguracion('forzar_cambio_password');
    if (!$configForzar || $configForzar !== '1') {
        return;
    }
    
    $usuario = $db->fetchOne(
        "SELECT password_changed FROM usuarios WHERE id = ?", 
        [$_SESSION['user_id']]
    );
    
    // Si no ha cambiado la contraseña y no está en páginas permitidas
    if ((!isset($usuario['password_changed']) || $usuario['password_changed'] == 0)) {
        $pagina_actual = basename($_SERVER['PHP_SELF']);
        $paginas_permitidas = ['index.php', 'logout.php', 'cambiar_contrasena.php'];
        
        if (!in_array($pagina_actual, $paginas_permitidas)) {
            header('Location: cambiar_contrasena.php?obligatorio=1');
            exit;
        }
    }
}

/**
 * Ver contraseña de usuario (solo admin)
 */
function verContrasenaUsuario($db, $usuarioId) {
    try {
        if (!esAdmin()) {
            return ['type' => 'danger', 'message' => 'No tiene permisos para esta acción'];
        }
        
        $usuario = $db->fetchOne(
            "SELECT nombre, apellido, dni, contrasena, tipo FROM usuarios WHERE id = ?", 
            [$usuarioId]
        );
        
        if (!$usuario) {
            return ['type' => 'danger', 'message' => 'Usuario no encontrado'];
        }
        
        return [
            'type' => 'success', 
            'data' => $usuario,
            'message' => 'Información obtenida correctamente'
        ];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Cambiar contraseña de usuario (admin)
 */
function cambiarContrasenaUsuario($db, $usuarioId, $nuevaContrasena) {
    try {
        if (!esAdmin()) {
            return ['type' => 'danger', 'message' => 'No tiene permisos para esta acción'];
        }
        
        $minLength = obtenerConfiguracion('min_password_length', 6);
        if (strlen($nuevaContrasena) < $minLength) {
            return ['type' => 'danger', 'message' => 'La contraseña debe tener al menos ' . $minLength . ' caracteres'];
        }
        
        $db->query(
            "UPDATE usuarios SET contrasena = ?, password_changed = 1 WHERE id = ?",
            [$nuevaContrasena, $usuarioId]
        );
        
        return ['type' => 'success', 'message' => 'Contraseña actualizada correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Generar contraseña aleatoria segura
 */
function generarContrasenaAleatoria($longitud = 8) {
    $mayusculas = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $minusculas = 'abcdefghijklmnopqrstuvwxyz';
    $numeros = '0123456789';
    $simbolos = '!@#$%&*';
    
    $contrasena = '';
    $contrasena .= $mayusculas[rand(0, strlen($mayusculas) - 1)];
    $contrasena .= $minusculas[rand(0, strlen($minusculas) - 1)];
    $contrasena .= $numeros[rand(0, strlen($numeros) - 1)];
    $contrasena .= $simbolos[rand(0, strlen($simbolos) - 1)];
    
    $todos = $mayusculas . $minusculas . $numeros . $simbolos;
    for ($i = 4; $i < $longitud; $i++) {
        $contrasena .= $todos[rand(0, strlen($todos) - 1)];
    }
    
    return str_shuffle($contrasena);
}

/**
 * Validar fortaleza de contraseña
 */
function validarFortalezaContrasena($contrasena) {
    $errores = [];
    $minLength = obtenerConfiguracion('min_password_length', 6);
    
    if (strlen($contrasena) < $minLength) {
        $errores[] = 'Debe tener al menos ' . $minLength . ' caracteres';
    }
    
    if (!preg_match('/[A-Z]/', $contrasena)) {
        $errores[] = 'Debe contener al menos una letra mayúscula';
    }
    
    if (!preg_match('/[a-z]/', $contrasena)) {
        $errores[] = 'Debe contener al menos una letra minúscula';
    }
    
    if (!preg_match('/[0-9]/', $contrasena)) {
        $errores[] = 'Debe contener al menos un número';
    }
    
    return [
        'valida' => empty($errores),
        'errores' => $errores,
        'nivel' => calcularNivelSeguridad($contrasena)
    ];
}

/**
 * Calcular nivel de seguridad de contraseña
 */
function calcularNivelSeguridad($contrasena) {
    $puntos = 0;
    $longitud = strlen($contrasena);
    
    // Puntos por longitud
    if ($longitud >= 6) $puntos += 1;
    if ($longitud >= 8) $puntos += 1;
    if ($longitud >= 12) $puntos += 1;
    
    // Puntos por variedad de caracteres
    if (preg_match('/[A-Z]/', $contrasena)) $puntos += 1;
    if (preg_match('/[a-z]/', $contrasena)) $puntos += 1;
    if (preg_match('/[0-9]/', $contrasena)) $puntos += 1;
    if (preg_match('/[^A-Za-z0-9]/', $contrasena)) $puntos += 1;
    
    if ($puntos >= 6) return 'alta';
    if ($puntos >= 4) return 'media';
    return 'baja';
}

/**
 * Obtener configuración del sistema
 */
function obtenerConfiguracion($clave, $valorPorDefecto = null) {
    global $db;
    
    try {
        // Verificar si existe la tabla configuraciones
        $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='configuraciones'");
        
        if (empty($tablas)) {
            return $valorPorDefecto;
        }
        
        $config = $db->fetchOne("SELECT valor FROM configuraciones WHERE clave = ?", [$clave]);
        return $config ? $config['valor'] : $valorPorDefecto;
        
    } catch (Exception $e) {
        return $valorPorDefecto;
    }
}

/**
 * Establecer configuración del sistema
 */
function establecerConfiguracion($clave, $valor, $descripcion = null) {
    global $db;
    
    try {
        // Verificar si la configuración existe
        $existe = $db->fetchOne("SELECT id FROM configuraciones WHERE clave = ?", [$clave]);
        
        if ($existe) {
            $db->query(
                "UPDATE configuraciones SET valor = ?, updated_at = CURRENT_TIMESTAMP WHERE clave = ?",
                [$valor, $clave]
            );
        } else {
            $db->query(
                "INSERT INTO configuraciones (clave, valor, descripcion) VALUES (?, ?, ?)",
                [$clave, $valor, $descripcion]
            );
        }
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Formatear fecha
 */
function formatearFecha($fecha, $incluirHora = false) {
    if (!$fecha) return '-';
    
    $timestamp = strtotime($fecha);
    if ($incluirHora) {
        return date('d/m/Y H:i', $timestamp);
    } else {
        return date('d/m/Y', $timestamp);
    }
}

/**
 * Limpiar texto para mostrar de forma segura
 */
function limpiarTexto($texto) {
    return htmlspecialchars(trim($texto), ENT_QUOTES, 'UTF-8');
}

/**
 * Generar token de seguridad
 */
function generarToken($longitud = 32) {
    return bin2hex(random_bytes($longitud / 2));
}

/**
 * Validar DNI
 */
function validarDNI($dni) {
    // Eliminar espacios y caracteres especiales
    $dni = preg_replace('/[^0-9]/', '', $dni);
    
    // Verificar longitud (entre 7 y 8 dígitos)
    if (strlen($dni) < 7 || strlen($dni) > 8) {
        return false;
    }
    
    // Verificar que sea numérico
    return is_numeric($dni);
}

/**
 * Obtener edad desde fecha de nacimiento
 */
function calcularEdad($fechaNacimiento) {
    if (!$fechaNacimiento) return null;
    
    $hoy = new DateTime();
    $nacimiento = new DateTime($fechaNacimiento);
    $edad = $hoy->diff($nacimiento);
    
    return $edad->y;
}

/**
 * Registrar actividad del usuario
 */
function registrarActividad($accion, $descripcion = '', $usuarioId = null) {
    global $db;
    
    if (!$usuarioId) {
        $usuarioId = $_SESSION['user_id'] ?? null;
    }
    
    if (!$usuarioId) return false;
    
    try {
        // Verificar si existe tabla de logs
        $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='logs_actividad'");
        
        if (empty($tablas)) {
            // Crear tabla de logs si no existe
            $db->query("
                CREATE TABLE logs_actividad (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    usuario_id INTEGER,
                    accion TEXT NOT NULL,
                    descripcion TEXT,
                    ip_address TEXT,
                    user_agent TEXT,
                    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
                )
            ");
        }
        
        $db->query(
            "INSERT INTO logs_actividad (usuario_id, accion, descripcion, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $usuarioId,
                $accion,
                $descripcion,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]
        );
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Enviar notificación al usuario
 */
function enviarNotificacion($usuarioId, $titulo, $mensaje, $tipo = 'info') {
    global $db;
    
    try {
        // Verificar si existe tabla de notificaciones
        $tablas = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='notificaciones'");
        
        if (!empty($tablas)) {
            $db->query(
                "INSERT INTO notificaciones (usuario_id, titulo, mensaje, tipo, leida, fecha_creacion) 
                 VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)",
                [$usuarioId, $titulo, $mensaje, $tipo]
            );
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Verificar permisos para una acción específica
 */
function verificarPermiso($accion) {
    $tipoUsuario = obtenerTipoUsuario();
    
    $permisos = [
        'admin' => ['*'], // Acceso total
        'directivo' => [
            'ver_usuarios', 'crear_usuarios', 'editar_usuarios',
            'ver_reportes', 'gestionar_cursos', 'gestionar_materias'
        ],
        'profesor' => [
            'ver_estudiantes', 'gestionar_calificaciones', 'tomar_asistencia',
            'ver_horarios', 'cambiar_contrasena'
        ],
        'preceptor' => [
            'ver_estudiantes', 'tomar_asistencia', 'gestionar_disciplina',
            'ver_horarios', 'cambiar_contrasena'
        ],
        'estudiante' => [
            'ver_calificaciones', 'ver_asistencia', 'ver_horarios', 'cambiar_contrasena'
        ]
    ];
    
    // Admin tiene acceso total
    if ($tipoUsuario === 'admin') {
        return true;
    }
    
    // Verificar permisos específicos
    if (isset($permisos[$tipoUsuario])) {
        return in_array($accion, $permisos[$tipoUsuario]);
    }
    
    return false;
}

/**
 * Redirigir con mensaje
 */
function redirigirConMensaje($url, $mensaje, $tipo = 'info') {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['mensaje'] = $mensaje;
    $_SESSION['tipo_mensaje'] = $tipo;
    
    header("Location: $url");
    exit;
}

/**
 * Mostrar mensaje almacenado en sesión
 */
function mostrarMensajeSesion() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['mensaje'])) {
        $mensaje = $_SESSION['mensaje'];
        $tipo = $_SESSION['tipo_mensaje'] ?? 'info';
        
        $claseCSS = '';
        switch ($tipo) {
            case 'success': $claseCSS = 'alert-success'; break;
            case 'error':
            case 'danger': $claseCSS = 'alert-danger'; break;
            case 'warning': $claseCSS = 'alert-warning'; break;
            default: $claseCSS = 'alert-info'; break;
        }
        
        echo "<div class='alert $claseCSS alert-dismissible fade show' role='alert'>";
        echo htmlspecialchars($mensaje);
        echo "<button type='button' class='btn-close' data-bs-dismiss='alert'></button>";
        echo "</div>";
        
        // Limpiar mensaje de la sesión
        unset($_SESSION['mensaje'], $_SESSION['tipo_mensaje']);
    }
}

// Configuraciones por defecto
if (!defined('MIN_PASSWORD_LENGTH')) {
    define('MIN_PASSWORD_LENGTH', 6);
}

if (!defined('FORZAR_CAMBIO_PASSWORD')) {
    define('FORZAR_CAMBIO_PASSWORD', true);
}

if (!defined('MOSTRAR_CONSEJOS_SEGURIDAD')) {
    define('MOSTRAR_CONSEJOS_SEGURIDAD', true);
}
?>