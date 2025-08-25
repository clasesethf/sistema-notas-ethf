<?php
/**
 * gestionar_emails.php - Gestión de emails de familias
 * Versión con soporte para email principal y secundario
 */

require_once 'config.php';

// Verificar permisos - ampliado para incluir preceptores
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'preceptor'])) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();

// Verificar que la columna email_secundario existe
try {
    $db->query("ALTER TABLE usuarios ADD COLUMN email_secundario TEXT");
} catch (Exception $e) {
    // La columna ya existe, continuar
}

// Procesar actualización de emails
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_emails'])) {
    try {
        $actualizados = 0;
        $errores = 0;
        
        foreach ($_POST['emails'] as $estudianteId => $emailData) {
            try {
                $email = !empty($emailData['email']) ? trim($emailData['email']) : null;
                $email_secundario = !empty($emailData['email_secundario']) ? trim($emailData['email_secundario']) : null;
                
                // Validar emails si están presentes
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Email principal inválido para estudiante ID $estudianteId");
                }
                
                if ($email_secundario && !filter_var($email_secundario, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Email secundario inválido para estudiante ID $estudianteId");
                }
                
                $db->query(
                    "UPDATE usuarios SET email = ?, email_secundario = ? WHERE id = ? AND tipo = 'estudiante'",
                    [$email, $email_secundario, $estudianteId]
                );
                
                $actualizados++;
            } catch (Exception $e) {
                $errores++;
                error_log("Error actualizando emails estudiante $estudianteId: " . $e->getMessage());
            }
        }
        
        $_SESSION['message'] = "Emails actualizados: $actualizados exitosos, $errores errores";
        $_SESSION['message_type'] = ($errores === 0) ? 'success' : 'warning';
        
        // Usar JavaScript para redireccionar en lugar de header()
        echo "<script>window.location.href = 'gestionar_emails.php?" . http_build_query($_GET) . "';</script>";
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al actualizar emails: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Incluir header después del procesamiento
require_once 'header.php';

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
if (!$cicloActivo) {
    echo '<div class="container-fluid"><div class="alert alert-danger">No hay un ciclo lectivo activo.</div></div>';
    require_once 'footer.php';
    exit;
}

// Obtener cursos
$cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio, nombre", [$cicloActivo['id']]);

// Filtros
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$mostrarSolo = isset($_GET['mostrar']) ? $_GET['mostrar'] : 'todos'; // 'todos', 'sin_emails', 'con_emails', 'parcial'

// Obtener estudiantes según filtros
$estudiantes = [];
if ($cursoSeleccionado) {
    $whereEmail = '';
    $params = [$cursoSeleccionado];
    
    if ($mostrarSolo === 'sin_emails') {
        $whereEmail = "AND (u.email IS NULL OR u.email = '') AND (u.email_secundario IS NULL OR u.email_secundario = '')";
    } elseif ($mostrarSolo === 'con_emails') {
        $whereEmail = "AND ((u.email IS NOT NULL AND u.email != '') OR (u.email_secundario IS NOT NULL AND u.email_secundario != ''))";
    } elseif ($mostrarSolo === 'parcial') {
        $whereEmail = "AND ((u.email IS NOT NULL AND u.email != '' AND (u.email_secundario IS NULL OR u.email_secundario = '')) OR (u.email_secundario IS NOT NULL AND u.email_secundario != '' AND (u.email IS NULL OR u.email = '')))";
    }
    
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, u.email, u.email_secundario,
                c.nombre as curso_nombre
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         JOIN cursos c ON m.curso_id = c.id
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
         $whereEmail
         ORDER BY u.apellido, u.nombre",
        $params
    );
}

// Estadísticas
$estadisticas = [];
if ($cursoSeleccionado) {
    $totalEstudiantes = $db->fetchOne(
        "SELECT COUNT(*) as count FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
        [$cursoSeleccionado]
    )['count'] ?? 0;
    
    $conEmailPrincipal = $db->fetchOne(
        "SELECT COUNT(*) as count FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         AND u.email IS NOT NULL AND u.email != ''",
        [$cursoSeleccionado]
    )['count'] ?? 0;
    
    $conEmailSecundario = $db->fetchOne(
        "SELECT COUNT(*) as count FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         AND u.email_secundario IS NOT NULL AND u.email_secundario != ''",
        [$cursoSeleccionado]
    )['count'] ?? 0;
    
    $conAmbosEmails = $db->fetchOne(
        "SELECT COUNT(*) as count FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         AND u.email IS NOT NULL AND u.email != ''
         AND u.email_secundario IS NOT NULL AND u.email_secundario != ''",
        [$cursoSeleccionado]
    )['count'] ?? 0;
    
    $conAlgunEmail = $db->fetchOne(
        "SELECT COUNT(*) as count FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         AND ((u.email IS NOT NULL AND u.email != '') OR (u.email_secundario IS NOT NULL AND u.email_secundario != ''))",
        [$cursoSeleccionado]
    )['count'] ?? 0;
    
    $sinEmails = $totalEstudiantes - $conAlgunEmail;
    
    $estadisticas = [
        'total' => $totalEstudiantes,
        'con_email_principal' => $conEmailPrincipal,
        'con_email_secundario' => $conEmailSecundario,
        'con_ambos_emails' => $conAmbosEmails,
        'con_algun_email' => $conAlgunEmail,
        'sin_emails' => $sinEmails,
        'porcentaje_cobertura' => $totalEstudiantes > 0 ? round(($conAlgunEmail / $totalEstudiantes) * 100, 1) : 0
    ];
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-envelope-at"></i> Gestión de Emails de Familias</h2>
                    <p class="text-muted">Administre emails principal y secundario para el envío de boletines</p>
                </div>
                <div>
                    <a href="config_emails.php" class="btn btn-outline-primary">
                        <i class="bi bi-gear"></i> Configurar Email
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-funnel"></i> Filtros</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="gestionar_emails.php">
                <div class="row align-items-end">
                    <div class="col-md-3">
                        <label for="curso" class="form-label">Curso:</label>
                        <select name="curso" id="curso" class="form-select" required onchange="this.form.submit()">
                            <option value="">-- Seleccione un curso --</option>
                            <?php foreach ($cursos as $curso): ?>
                            <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($curso['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($cursoSeleccionado): ?>
                    <div class="col-md-4">
                        <label for="mostrar" class="form-label">Mostrar:</label>
                        <select name="mostrar" id="mostrar" class="form-select" onchange="this.form.submit()">
                            <option value="todos" <?= ($mostrarSolo === 'todos') ? 'selected' : '' ?>>Todos los estudiantes</option>
                            <option value="sin_emails" <?= ($mostrarSolo === 'sin_emails') ? 'selected' : '' ?>>Sin emails</option>
                            <option value="con_emails" <?= ($mostrarSolo === 'con_emails') ? 'selected' : '' ?>>Con algún email</option>
                            <option value="parcial" <?= ($mostrarSolo === 'parcial') ? 'selected' : '' ?>>Solo un email configurado</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <a href="boletines.php" class="btn btn-outline-success">
                            <i class="bi bi-file-text"></i> Ir a Boletines
                        </a>
                        <button type="button" class="btn btn-outline-info" onclick="exportarPlantilla()">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Estadísticas mejoradas -->
    <?php if (!empty($estadisticas)): ?>
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-white bg-primary">
                <div class="card-body text-center">
                    <h4><?= $estadisticas['total'] ?></h4>
                    <p class="card-text small">Total Estudiantes</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-success">
                <div class="card-body text-center">
                    <h4><?= $estadisticas['con_email_principal'] ?></h4>
                    <p class="card-text small">Email Principal</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-info">
                <div class="card-body text-center">
                    <h4><?= $estadisticas['con_email_secundario'] ?></h4>
                    <p class="card-text small">Email Secundario</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-dark">
                <div class="card-body text-center">
                    <h4><?= $estadisticas['con_ambos_emails'] ?></h4>
                    <p class="card-text small">Ambos Emails</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-warning">
                <div class="card-body text-center">
                    <h4><?= $estadisticas['sin_emails'] ?></h4>
                    <p class="card-text small">Sin Emails</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-white bg-secondary">
                <div class="card-body text-center">
                    <h4><?= $estadisticas['porcentaje_cobertura'] ?>%</h4>
                    <p class="card-text small">Cobertura</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> 
        <strong>Cobertura de emails:</strong> <?= $estadisticas['porcentaje_cobertura'] ?>% de familias tienen al menos un email configurado
        <?php if ($estadisticas['sin_emails'] > 0): ?>
            - <strong><?= $estadisticas['sin_emails'] ?> estudiantes necesitan email</strong>
        <?php endif; ?>
        <br>
        <strong>Detalles:</strong> <?= $estadisticas['con_ambos_emails'] ?> familias con ambos emails, 
        <?= ($estadisticas['con_algun_email'] - $estadisticas['con_ambos_emails']) ?> familias con solo un email
    </div>
    <?php endif; ?>
    
    <!-- Lista de estudiantes -->
    <?php if (!empty($estudiantes)): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5><i class="bi bi-table"></i> Emails por Estudiante - <?= htmlspecialchars($estudiantes[0]['curso_nombre']) ?></h5>
            <span class="badge bg-secondary"><?= count($estudiantes) ?> estudiantes</span>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="4%">#</th>
                                <th width="20%">Estudiante</th>
                                <th width="10%">Matr.</th>
                                <th width="28%">Email Principal</th>
                                <th width="28%">Email Secundario</th>
                                <th width="10%">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $contador = 1; ?>
                            <?php foreach ($estudiantes as $estudiante): ?>
                            <tr>
                                <td><?= $contador++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($estudiante['dni']) ?></td>
                                <td>
                                    <input type="email" 
                                           class="form-control form-control-sm" 
                                           name="emails[<?= $estudiante['id'] ?>][email]"
                                           value="<?= htmlspecialchars($estudiante['email'] ?? '') ?>"
                                           placeholder="principal@familia.com"
                                           onchange="validarEmail(this)">
                                </td>
                                <td>
                                    <input type="email" 
                                           class="form-control form-control-sm" 
                                           name="emails[<?= $estudiante['id'] ?>][email_secundario]"
                                           value="<?= htmlspecialchars($estudiante['email_secundario'] ?? '') ?>"
                                           placeholder="secundario@familia.com"
                                           onchange="validarEmail(this)">
                                </td>
                                <td class="text-center">
                                    <?php 
                                    $tieneEmailPrincipal = !empty($estudiante['email']);
                                    $tieneEmailSecundario = !empty($estudiante['email_secundario']);
                                    ?>
                                    
                                    <?php if ($tieneEmailPrincipal && $tieneEmailSecundario): ?>
                                        <span class="badge bg-success" title="Ambos emails">✓✓</span>
                                    <?php elseif ($tieneEmailPrincipal || $tieneEmailSecundario): ?>
                                        <span class="badge bg-warning" title="Solo un email">✓</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger" title="Sin emails">✗</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div>
                        <small class="text-muted">
                            <i class="bi bi-lightbulb"></i> 
                            <strong>Tip:</strong> Configure ambos emails para mayor efectividad. Se enviarán copias a ambas direcciones.
                        </small>
                    </div>
                    <div>
                        <button type="submit" name="actualizar_emails" class="btn btn-primary">
                            <i class="bi bi-check"></i> Guardar Cambios
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="exportarPlantilla()">
                            <i class="bi bi-file-earmark-spreadsheet"></i> Exportar Plantilla
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php elseif ($cursoSeleccionado): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> 
        No se encontraron estudiantes con los filtros seleccionados.
    </div>
    <?php endif; ?>
    
    <!-- Ayuda -->
    <div class="card mt-4">
        <div class="card-header">
            <h5><i class="bi bi-question-circle"></i> Ayuda</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>📧 Gestión de emails:</h6>
                    <ul>
                        <li><strong>Email Principal:</strong> Email primario de la familia</li>
                        <li><strong>Email Secundario:</strong> Email adicional (padre/madre/tutor)</li>
                        <li>Ambos emails recibirán copia del boletín</li>
                        <li>Configurar al menos uno es suficiente para envíos</li>
                        <li>Se pueden usar Gmail, Hotmail, Yahoo, etc.</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>📊 Estados de configuración:</h6>
                    <ul>
                        <li><span class="badge bg-success">✓✓</span> Ambos emails configurados</li>
                        <li><span class="badge bg-warning">✓</span> Solo un email configurado</li>
                        <li><span class="badge bg-danger">✗</span> Sin emails configurados</li>
                    </ul>
                    
                    <h6 class="mt-3">📥 Importación masiva:</h6>
                    <ul>
                        <li>Use el botón "Exportar CSV" para obtener plantilla</li>
                        <li>Complete los emails en Excel o similar</li>
                        <li>Importe el archivo modificado</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Validación en tiempo real
function validarEmail(input) {
    const email = input.value.trim();
    if (email && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        input.classList.add('is-invalid');
        return false;
    } else {
        input.classList.remove('is-invalid');
        if (email) input.classList.add('is-valid');
        return true;
    }
}

// Aplicar validación a todos los campos email
document.querySelectorAll('input[type="email"]').forEach(input => {
    input.addEventListener('input', () => validarEmail(input));
});

// Exportar plantilla CSV
function exportarPlantilla() {
    <?php if (!empty($estudiantes)): ?>
    let csv = 'DNI,Apellido,Nombre,Email_Principal,Email_Secundario\n';
    <?php foreach ($estudiantes as $estudiante): ?>
    csv += '<?= $estudiante['dni'] ?>,<?= addslashes($estudiante['apellido']) ?>,<?= addslashes($estudiante['nombre']) ?>,<?= addslashes($estudiante['email'] ?? '') ?>,<?= addslashes($estudiante['email_secundario'] ?? '') ?>\n';
    <?php endforeach; ?>
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'plantilla_emails_<?= htmlspecialchars($estudiantes[0]['curso_nombre'] ?? 'curso') ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    <?php else: ?>
    alert('Seleccione un curso primero');
    <?php endif; ?>
}

// Validación del formulario antes de enviar
document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
    const emailInputs = this.querySelectorAll('input[type="email"]');
    let hayErrores = false;
    
    emailInputs.forEach(input => {
        if (!validarEmail(input)) {
            hayErrores = true;
        }
    });
    
    if (hayErrores) {
        e.preventDefault();
        alert('Por favor corrija los emails inválidos antes de guardar');
        return false;
    }
});

// Confirmar antes de guardar cambios
document.querySelector('button[name="actualizar_emails"]')?.addEventListener('click', function(e) {
    if (!confirm('¿Está seguro de que desea guardar los cambios en los emails?')) {
        e.preventDefault();
    }
});
</script>

<?php require_once 'footer.php'; ?>
