<?php
// admin_contrasenas.php
require_once 'config.php';
require_once 'funciones.php';

// Verificar que sea administrador
verificarAutenticacion();
if (!esAdmin()) {
    header('Location: index.php');
    exit;
}

$mensaje = '';
$error = '';

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'ver_contrasena':
            $usuarioId = intval($_POST['usuario_id']);
            $resultado = verContrasenaUsuario($db, $usuarioId);
            if ($resultado['type'] === 'success') {
                $datosUsuario = $resultado['data'];
            } else {
                $error = $resultado['message'];
            }
            break;
            
        case 'cambiar_contrasena':
            $usuarioId = intval($_POST['usuario_id']);
            $nuevaContrasena = trim($_POST['nueva_contrasena']);
            $resultado = cambiarContrasenaUsuario($db, $usuarioId, $nuevaContrasena);
            if ($resultado['type'] === 'success') {
                $mensaje = $resultado['message'];
            } else {
                $error = $resultado['message'];
            }
            break;
            
        case 'generar_contrasena_aleatoria':
            $usuarioId = intval($_POST['usuario_id']);
            $contrasenaAleatoria = generarContrasenaAleatoria();
            $resultado = cambiarContrasenaUsuario($db, $usuarioId, $contrasenaAleatoria);
            if ($resultado['type'] === 'success') {
                $mensaje = "Contraseña actualizada: $contrasenaAleatoria";
            } else {
                $error = $resultado['message'];
            }
            break;
    }
}

// Obtener todos los usuarios
$usuarios = $db->fetchAll(
    "SELECT id, nombre, apellido, dni, tipo, activo, 
            COALESCE(password_changed, 0) as password_changed,
            contrasena
     FROM usuarios 
     ORDER BY tipo, apellido, nombre"
);

require_once 'header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-shield-lock"></i> Gestión de Contraseñas</h1>
        <div>
            <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalAyuda">
                <i class="bi bi-question-circle"></i> Ayuda
            </button>
        </div>
    </div>
    
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
    
    <!-- Estadísticas -->
    <div class="row mb-4">
        <?php
        $stats = [
            'total' => 0,
            'default_password' => 0,
            'custom_password' => 0,
            'inactive' => 0
        ];
        
        foreach ($usuarios as $usuario) {
            $stats['total']++;
            if ($usuario['activo'] == 0) $stats['inactive']++;
            if ($usuario['password_changed'] == 0) {
                $stats['default_password']++;
            } else {
                $stats['custom_password']++;
            }
        }
        ?>
        
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h4><?= $stats['total'] ?></h4>
                    <p>Total Usuarios</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h4><?= $stats['default_password'] ?></h4>
                    <p>Contraseña por Defecto</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h4><?= $stats['custom_password'] ?></h4>
                    <p>Contraseña Personalizada</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white">
                <div class="card-body">
                    <h4><?= $stats['inactive'] ?></h4>
                    <p>Usuarios Inactivos</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabla de usuarios -->
    <div class="card">
        <div class="card-header">
            <h5>Lista de Usuarios y sus Contraseñas</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Estado Contraseña</th>
                            <th>Contraseña Actual</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($usuario['apellido'] . ', ' . $usuario['nombre']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($usuario['dni']) ?></td>
                                <td>
                                    <span class="badge bg-info"><?= ucfirst($usuario['tipo']) ?></span>
                                </td>
                                <td>
                                    <?php if ($usuario['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($usuario['password_changed'] == 0): ?>
                                        <span class="badge bg-warning">Por defecto</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Personalizada</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <code class="password-field me-2" 
                                              style="display: inline-block; min-width: 100px; font-family: monospace;"
                                              data-password="<?= htmlspecialchars($usuario['contrasena']) ?>">
                                            ********
                                        </code>
                                        <button class="btn btn-sm btn-outline-secondary" 
                                                onclick="togglePassword(this)" title="Mostrar/Ocultar">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="abrirModalCambiarContrasena(<?= $usuario['id'] ?>, '<?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?>')"
                                                title="Cambiar contraseña">
                                            <i class="bi bi-key"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" 
                                                onclick="generarContrasenaAleatoria(<?= $usuario['id'] ?>)"
                                                title="Generar contraseña aleatoria">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                        <button class="btn btn-sm btn-warning" 
                                                onclick="resetearADefault(<?= $usuario['id'] ?>, '<?= $usuario['tipo'] ?>')"
                                                title="Resetear a contraseña por defecto">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal para cambiar contraseña -->
<div class="modal fade" id="modalCambiarContrasena" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar Contraseña</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="cambiar_contrasena">
                    <input type="hidden" name="usuario_id" id="cambiar_usuario_id">
                    
                    <p>Usuario: <strong id="cambiar_usuario_nombre"></strong></p>
                    
                    <div class="mb-3">
                        <label for="nueva_contrasena" class="form-label">Nueva Contraseña</label>
                        <input type="text" class="form-control" id="nueva_contrasena" 
                               name="nueva_contrasena" required minlength="6">
                        <div class="form-text">Mínimo 6 caracteres</div>
                    </div>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" 
                                onclick="generarContrasenaSegura()">
                            <i class="bi bi-shield-check"></i> Generar Contraseña Segura
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cambiar Contraseña</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal de ayuda -->
<div class="modal fade" id="modalAyuda" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ayuda - Gestión de Contraseñas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6>Funciones Disponibles:</h6>
                <ul>
                    <li><strong>Ver contraseña:</strong> Haga clic en el ícono del ojo para mostrar/ocultar la contraseña actual</li>
                    <li><strong>Cambiar contraseña:</strong> Permite establecer una contraseña personalizada</li>
                    <li><strong>Generar aleatoria:</strong> Crea una contraseña segura automáticamente</li>
                    <li><strong>Resetear a defecto:</strong> Restaura la contraseña por defecto (tipo123)</li>
                </ul>
                
                <h6>Estados de Contraseña:</h6>
                <ul>
                    <li><span class="badge bg-warning">Por defecto</span>: El usuario aún no ha cambiado su contraseña</li>
                    <li><span class="badge bg-success">Personalizada</span>: El usuario ya cambió su contraseña por defecto</li>
                </ul>
                
                <h6>Recomendaciones de Seguridad:</h6>
                <ul>
                    <li>Las contraseñas deben tener al menos 6 caracteres</li>
                    <li>Encourage a los usuarios a cambiar las contraseñas por defecto</li>
                    <li>Use contraseñas que combinen letras, números y símbolos</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Formularios ocultos -->
<form id="formAccionRapida" method="POST" style="display: none;">
    <input type="hidden" name="accion" id="accion_rapida">
    <input type="hidden" name="usuario_id" id="usuario_id_rapida">
    <input type="hidden" name="nueva_contrasena" id="nueva_contrasena_rapida">
</form>

<script>
// Mostrar/ocultar contraseñas
function togglePassword(button) {
    const passwordField = button.parentElement.querySelector('.password-field');
    const icon = button.querySelector('i');
    const password = passwordField.getAttribute('data-password');
    
    if (passwordField.textContent.trim() === '********') {
        passwordField.textContent = password;
        icon.classList.replace('bi-eye', 'bi-eye-slash');
        button.title = 'Ocultar';
    } else {
        passwordField.textContent = '********';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
        button.title = 'Mostrar';
    }
}

// Abrir modal para cambiar contraseña
function abrirModalCambiarContrasena(usuarioId, nombreUsuario) {
    document.getElementById('cambiar_usuario_id').value = usuarioId;
    document.getElementById('cambiar_usuario_nombre').textContent = nombreUsuario;
    document.getElementById('nueva_contrasena').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('modalCambiarContrasena'));
    modal.show();
}

// Generar contraseña aleatoria
function generarContrasenaAleatoria(usuarioId) {
    if (confirm('¿Generar una contraseña aleatoria para este usuario?')) {
        document.getElementById('accion_rapida').value = 'generar_contrasena_aleatoria';
        document.getElementById('usuario_id_rapida').value = usuarioId;
        document.getElementById('formAccionRapida').submit();
    }
}

// Resetear a contraseña por defecto
function resetearADefault(usuarioId, tipo) {
    const contrasenaDefault = tipo + '123';
    if (confirm(`¿Resetear la contraseña a "${contrasenaDefault}"?`)) {
        document.getElementById('accion_rapida').value = 'cambiar_contrasena';
        document.getElementById('usuario_id_rapida').value = usuarioId;
        document.getElementById('nueva_contrasena_rapida').value = contrasenaDefault;
        document.getElementById('formAccionRapida').submit();
    }
}

// Generar contraseña segura (JavaScript)
function generarContrasenaSegura() {
    const caracteres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%&*';
    let contrasena = '';
    
    // Asegurar al menos una mayúscula, una minúscula, un número y un símbolo
    contrasena += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)];
    contrasena += 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)];
    contrasena += '0123456789'[Math.floor(Math.random() * 10)];
    contrasena += '!@#$%&*'[Math.floor(Math.random() * 7)];
    
    // Completar hasta 8 caracteres
    for (let i = 4; i < 8; i++) {
        contrasena += caracteres[Math.floor(Math.random() * caracteres.length)];
    }
    
    // Mezclar caracteres
    contrasena = contrasena.split('').sort(() => 0.5 - Math.random()).join('');
    
    document.getElementById('nueva_contrasena').value = contrasena;
}
</script>

<?php require_once 'footer.php'; ?>