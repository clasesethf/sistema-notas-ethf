<?php
// cambiar_contrasena.php
require_once 'config.php';
require_once 'funciones.php';

// Verificar autenticación
verificarAutenticacion();

$error = '';
$mensaje = '';

// Procesar el formulario de cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contrasenaActual = trim($_POST['contrasena_actual'] ?? '');
    $contrasenaNueva = trim($_POST['contrasena_nueva'] ?? '');
    $contrasenaConfirmar = trim($_POST['contrasena_confirmar'] ?? '');
    
    // Validaciones
    if (empty($contrasenaActual) || empty($contrasenaNueva) || empty($contrasenaConfirmar)) {
        $error = 'Todos los campos son obligatorios';
    } elseif (strlen($contrasenaNueva) < 6) {
        $error = 'La nueva contraseña debe tener al menos 6 caracteres';
    } elseif ($contrasenaNueva !== $contrasenaConfirmar) {
        $error = 'Las contraseñas nuevas no coinciden';
    } else {
        try {
            // Verificar contraseña actual
            $usuario = $db->fetchOne(
                "SELECT id, contrasena FROM usuarios WHERE id = ?", 
                [$_SESSION['user_id']]
            );
            
            if (!$usuario || $usuario['contrasena'] !== $contrasenaActual) {
                $error = 'La contraseña actual es incorrecta';
            } else {
                // Actualizar contraseña
                $db->query(
                    "UPDATE usuarios SET contrasena = ?, password_changed = 1 WHERE id = ?",
                    [$contrasenaNueva, $_SESSION['user_id']]
                );
                
                $mensaje = 'Contraseña actualizada correctamente';
                
                // Limpiar campos
                $contrasenaActual = $contrasenaNueva = $contrasenaConfirmar = '';
            }
        } catch (Exception $e) {
            $error = 'Error al actualizar la contraseña: ' . $e->getMessage();
        }
    }
}

// Obtener información del usuario actual
$usuarioActual = $db->fetchOne(
    "SELECT nombre, apellido, dni, tipo, password_changed FROM usuarios WHERE id = ?", 
    [$_SESSION['user_id']]
);

$esPrimeraVez = !isset($usuarioActual['password_changed']) || $usuarioActual['password_changed'] == 0;

require_once 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h4><i class="bi bi-key"></i> Cambiar Contraseña</h4>
                    <?php if ($esPrimeraVez): ?>
                        <div class="alert alert-warning mt-2 mb-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Importante:</strong> Se recomienda cambiar la contraseña por defecto por una personalizada.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($mensaje)): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($mensaje) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Información del Usuario</h5>
                            <div class="bg-light p-3 rounded">
                                <p><strong>Nombre:</strong> <?= htmlspecialchars($usuarioActual['nombre'] . ' ' . $usuarioActual['apellido']) ?></p>
                                <p><strong>DNI:</strong> <?= htmlspecialchars($usuarioActual['dni']) ?></p>
                                <p><strong>Tipo:</strong> <?= ucfirst(htmlspecialchars($usuarioActual['tipo'])) ?></p>
                                <p><strong>Estado de contraseña:</strong> 
                                    <?php if ($esPrimeraVez): ?>
                                        <span class="badge bg-warning">Contraseña por defecto</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Contraseña personalizada</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h5>Cambiar Contraseña</h5>
                            <form method="POST" action="cambiar_contrasena.php">
                                <div class="mb-3">
                                    <label for="contrasena_actual" class="form-label">Contraseña Actual</label>
                                    <input type="password" class="form-control" id="contrasena_actual" 
                                           name="contrasena_actual" required 
                                           placeholder="<?= $esPrimeraVez ? $usuarioActual['tipo'] . '123' : 'Ingrese su contraseña actual' ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="contrasena_nueva" class="form-label">Nueva Contraseña</label>
                                    <input type="password" class="form-control" id="contrasena_nueva" 
                                           name="contrasena_nueva" required minlength="6"
                                           placeholder="Mínimo 6 caracteres">
                                    <div class="form-text">La contraseña debe tener al menos 6 caracteres.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="contrasena_confirmar" class="form-label">Confirmar Nueva Contraseña</label>
                                    <input type="password" class="form-control" id="contrasena_confirmar" 
                                           name="contrasena_confirmar" required minlength="6"
                                           placeholder="Repita la nueva contraseña">
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg"></i> Cambiar Contraseña
                                </button>
                                
                                <a href="index.php" class="btn btn-secondary ms-2">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </a>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Consejos de seguridad -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="bi bi-shield-check"></i> Consejos de Seguridad</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Características de una buena contraseña:</h6>
                            <ul>
                                <li>Al menos 8 caracteres de longitud</li>
                                <li>Combina letras mayúsculas y minúsculas</li>
                                <li>Incluye números</li>
                                <li>Usa símbolos especiales (!@#$%)</li>
                                <li>No uses información personal</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Recomendaciones:</h6>
                            <ul>
                                <li>No compartas tu contraseña con nadie</li>
                                <li>Cámbiala periódicamente</li>
                                <li>No uses la misma contraseña en otros sitios</li>
                                <li>Cierra sesión al terminar de usar el sistema</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Validación en tiempo real
document.getElementById('contrasena_confirmar').addEventListener('input', function() {
    const nueva = document.getElementById('contrasena_nueva').value;
    const confirmar = this.value;
    
    if (nueva !== confirmar && confirmar.length > 0) {
        this.setCustomValidity('Las contraseñas no coinciden');
        this.classList.add('is-invalid');
    } else {
        this.setCustomValidity('');
        this.classList.remove('is-invalid');
    }
});

// Mostrar/ocultar contraseñas
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = event.target;
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}
</script>

<?php require_once 'footer.php'; ?>


<?php
// Script SQL para agregar la columna password_changed (ejecutar una sola vez)
/*
ALTER TABLE usuarios ADD COLUMN password_changed INTEGER DEFAULT 0;
*/

// Función para agregar al archivo de funciones.php o usuarios.php
function verificarCambioPasswordObligatorio() {
    global $db;
    
    // Verificar si el usuario debe cambiar su contraseña
    $usuario = $db->fetchOne(
        "SELECT password_changed FROM usuarios WHERE id = ?", 
        [$_SESSION['user_id']]
    );
    
    // Si no ha cambiado la contraseña y no está en la página de cambio
    if ((!isset($usuario['password_changed']) || $usuario['password_changed'] == 0) 
        && !strpos($_SERVER['PHP_SELF'], 'cambiar_contrasena.php')) {
        
        $pagina_actual = basename($_SERVER['PHP_SELF']);
        
        // Permitir acceso solo a páginas esenciales
        $paginas_permitidas = ['index.php', 'logout.php', 'cambiar_contrasena.php'];
        
        if (!in_array($pagina_actual, $paginas_permitidas)) {
            header('Location: cambiar_contrasena.php');
            exit;
        }
    }
}

// Función para el administrador ver/cambiar contraseñas
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

function cambiarContrasenaUsuario($db, $usuarioId, $nuevaContrasena) {
    try {
        if (!esAdmin()) {
            return ['type' => 'danger', 'message' => 'No tiene permisos para esta acción'];
        }
        
        if (strlen($nuevaContrasena) < 6) {
            return ['type' => 'danger', 'message' => 'La contraseña debe tener al menos 6 caracteres'];
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
?>