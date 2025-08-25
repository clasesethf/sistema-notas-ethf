<?php
/**
 * api_editar_pendiente.php - API para editar materias pendientes CORREGIDO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * CORREGIDO: Botones de actualización funcionando correctamente
 */

require_once 'config.php';

// Incluir funciones auxiliares si existen
if (file_exists('funciones_grupos_pendientes.php')) {
    require_once 'funciones_grupos_pendientes.php';
}

// Verificar permisos
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">No tienes permisos para esta acción.</div>';
    exit;
}

$db = Database::getInstance();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    echo '<div class="alert alert-danger">ID no válido.</div>';
    exit;
}

// Procesar actualizaciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['campo'])) {
    try {
        $campo = $_POST['campo'] ?? '';
        $valor = $_POST['valor'] ?? '';
        
        // Validar campos permitidos
        $camposPermitidos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero', 'calificacion_final', 'saberes_cierre', 'saberes_iniciales'];
        
        if (in_array($campo, $camposPermitidos)) {
            // Verificar permisos específicos según el tipo de usuario
            if ($_SESSION['user_type'] === 'profesor') {
                // Los profesores solo pueden editar sus propias materias
                $verificacion = $db->fetchOne(
                    "SELECT mpi.id 
                     FROM materias_pendientes_intensificacion mpi
                     JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
                     WHERE mpi.id = ? AND (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?)",
                    [$id, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
                );
                
                if (!$verificacion) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'No tienes permisos para editar esta materia.']);
                    exit;
                }
            }
            
            // Verificar si existen columnas de auditoría
            $columns = $db->fetchAll("PRAGMA table_info(materias_pendientes_intensificacion)");
            $hasAuditColumns = false;
            
            foreach ($columns as $column) {
                if ($column['name'] === 'modificado_por_admin') {
                    $hasAuditColumns = true;
                    break;
                }
            }
            
            // Actualizar el campo
            if ($hasAuditColumns) {
                $sql = "UPDATE materias_pendientes_intensificacion SET $campo = ?, fecha_modificacion = DATETIME('now'), modificado_por_admin = ? WHERE id = ?";
                $db->query($sql, [$valor, $_SESSION['user_id'], $id]);
            } else {
                $sql = "UPDATE materias_pendientes_intensificacion SET $campo = ? WHERE id = ?";
                $db->query($sql, [$valor, $id]);
            }
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Calificación actualizada exitosamente.']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Campo no válido.']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

try {
    // Verificar si existen columnas de auditoría
    $columns = $db->fetchAll("PRAGMA table_info(materias_pendientes_intensificacion)");
    $hasAuditColumns = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'creado_por_admin') {
            $hasAuditColumns = true;
            break;
        }
    }
    
    // Construir consulta base
    $baseQuery = "SELECT 
        mpi.*,
        u.nombre as estudiante_nombre,
        u.apellido as estudiante_apellido,
        u.dni as estudiante_dni,
        m.nombre as materia_nombre,
        m.codigo as materia_codigo,
        c.anio as curso_anio,
        c.nombre as curso_nombre,
        p1.apellido as profesor_apellido,
        p1.nombre as profesor_nombre,
        -- Información de grupos
        gm.nombre as grupo_nombre,
        gm.codigo as grupo_codigo,
        COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
        COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
        CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo";
    
    // Agregar campos de auditoría si existen
    if ($hasAuditColumns) {
        $baseQuery .= ",
        admin_creador.apellido as creador_apellido,
        admin_creador.nombre as creador_nombre,
        admin_modificador.apellido as modificador_apellido,
        admin_modificador.nombre as modificador_nombre";
    } else {
        $baseQuery .= ",
        NULL as creador_apellido,
        NULL as creador_nombre,
        NULL as modificador_apellido,
        NULL as modificador_nombre";
    }
    
    $baseQuery .= " FROM materias_pendientes_intensificacion mpi
     JOIN usuarios u ON mpi.estudiante_id = u.id
     JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
     JOIN materias m ON mpc.materia_id = m.id
     JOIN cursos c ON mpc.curso_id = c.id
     LEFT JOIN usuarios p1 ON mpc.profesor_id = p1.id
     LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
     LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1";
    
    // Agregar JOINs de auditoría si existen las columnas
    if ($hasAuditColumns) {
        $baseQuery .= "
        LEFT JOIN usuarios admin_creador ON mpi.creado_por_admin = admin_creador.id
        LEFT JOIN usuarios admin_modificador ON mpi.modificado_por_admin = admin_modificador.id";
    }
    
    $baseQuery .= " WHERE mpi.id = ?";
    
    $pendiente = $db->fetchOne($baseQuery, [$id]);

    if (!$pendiente) {
        echo '<div class="alert alert-danger">No se encontró la materia pendiente.</div>';
        exit;
    }

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

?>

<!-- Información de la materia pendiente -->
<div class="alert alert-info">
    <div class="row">
        <div class="col-md-8">
            <h6><i class="bi bi-info-circle"></i> Información de la Materia Pendiente</h6>
            <strong>Estudiante:</strong> <?= htmlspecialchars($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?><br>
            <strong>Materia:</strong> 
            <?php if ($pendiente['es_parte_grupo'] == 1): ?>
                <span class="badge bg-primary me-1">GRUPO</span>
                <?= htmlspecialchars($pendiente['grupo_nombre']) ?>
                <br><small class="text-muted ms-4">
                    <i class="bi bi-arrow-right"></i> Materia específica: <?= htmlspecialchars($pendiente['materia_nombre']) ?>
                </small>
            <?php else: ?>
                <?= htmlspecialchars($pendiente['nombre_mostrar']) ?>
            <?php endif; ?>
            <br><strong>Año:</strong> <?= $pendiente['curso_anio'] ?>°
        </div>
        <div class="col-md-4">
            <?php if ($pendiente['profesor_apellido']): ?>
                <strong>Profesor:</strong><br>
                <?= htmlspecialchars($pendiente['profesor_apellido'] . ', ' . $pendiente['profesor_nombre']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Área de mensajes dinámicos -->
<div id="mensajes-area"></div>

<!-- Formulario de edición -->
<form id="formEditarPendiente">
    <!-- Saberes Iniciales -->
    <div class="card mb-3">
        <div class="card-header bg-info text-white">
            <h6 class="mb-0">Saberes Iniciales Pendientes</h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="saberes_iniciales" class="form-label">Saberes iniciales pendientes de aprobación</label>
                <textarea class="form-control" id="saberes_iniciales" name="saberes_iniciales" rows="3" 
                          placeholder="Describe los saberes pendientes al inicio del ciclo lectivo..."><?= htmlspecialchars($pendiente['saberes_iniciales'] ?? '') ?></textarea>
                <button type="button" class="btn btn-sm btn-primary mt-2" 
                        onclick="actualizarCampo('saberes_iniciales', document.getElementById('saberes_iniciales').value)">
                    <i class="bi bi-check"></i> Actualizar
                </button>
            </div>
        </div>
    </div>

    <!-- Períodos de Intensificación -->
    <div class="card mb-3">
        <div class="card-header bg-warning text-dark">
            <h6 class="mb-0">Períodos de Intensificación</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-secondary">
                        <tr>
                            <th>Período</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $periodos = [
                            'marzo' => 'Marzo',
                            'julio' => 'Julio', 
                            'agosto' => 'Agosto',
                            'diciembre' => 'Diciembre',
                            'febrero' => 'Febrero'
                        ];
                        
                        foreach ($periodos as $campo => $nombre): 
                        ?>
                        <tr class="estado-row" id="row-<?= $campo ?>">
                            <td><strong><?= $nombre ?></strong></td>
                            <td>
                                <select class="form-select estado-select" id="<?= $campo ?>" name="<?= $campo ?>">
                                    <option value="">Sin calificar</option>
                                    <option value="AA" <?= ($pendiente[$campo] ?? '') === 'AA' ? 'selected' : '' ?>>AA - Aprobó y Acreditó</option>
                                    <option value="CCA" <?= ($pendiente[$campo] ?? '') === 'CCA' ? 'selected' : '' ?>>CCA - Continúa, Con Avances</option>
                                    <option value="CSA" <?= ($pendiente[$campo] ?? '') === 'CSA' ? 'selected' : '' ?>>CSA - Continúa, Sin Avances</option>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary btn-actualizar-pendiente"
                                        data-pendiente-id="<?= $pendiente['id'] ?>"
                                        data-campo="<?= $campo ?>"
                                        data-input-id="<?= $campo ?>">
                                    <i class="bi bi-check-circle"></i> Actualizar
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="alert alert-light">
                <small>
                    <strong>Códigos:</strong><br>
                    <span class="badge bg-success me-1">AA</span> Aprobó y Acreditó<br>
                    <span class="badge bg-warning me-1">CCA</span> Continúa, Con Avances<br>
                    <span class="badge bg-danger me-1">CSA</span> Continúa, Sin Avances
                </small>
            </div>
        </div>
    </div>

    <!-- Calificación Final -->
    <div class="card mb-3">
        <div class="card-header bg-success text-white">
            <h6 class="mb-0">Calificación Final</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <label for="calificacion_final" class="form-label">Calificación Final (1-10)</label>
                    <select class="form-select calificacion-input" id="calificacion_final" name="calificacion_final">
                        <option value="">Sin calificación</option>
                        <?php for ($i = 10; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>" <?= ($pendiente['calificacion_final'] ?? '') == $i ? 'selected' : '' ?>><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                    <div class="invalid-feedback"></div>
                    <button type="button" class="btn btn-sm btn-success mt-2 btn-actualizar-pendiente"
                            data-pendiente-id="<?= $pendiente['id'] ?>"
                            data-campo="calificacion_final"
                            data-input-id="calificacion_final">
                        <i class="bi bi-check-circle"></i> Actualizar Calificación
                    </button>
                </div>
                <div class="col-md-8">
                    <div class="alert alert-info">
                        <small>
                            <strong>Criterio de calificación:</strong><br>
                            • <strong>4-10:</strong> Aprobó y acreditó la materia<br>
                            • <strong>1-3:</strong> No aprobó la materia<br>
                            • <strong>Sin calificación:</strong> Aún en proceso de intensificación
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Saberes al Cierre -->
    <div class="card mb-3">
        <div class="card-header bg-secondary text-white">
            <h6 class="mb-0">Saberes Pendientes al Cierre</h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label for="saberes_cierre" class="form-label">Saberes pendientes de aprobación al cierre del ciclo lectivo</label>
                <textarea class="form-control" id="saberes_cierre" name="saberes_cierre" rows="3" 
                          placeholder="Solo completar si la materia NO fue aprobada y acreditada..."><?= htmlspecialchars($pendiente['saberes_cierre'] ?? '') ?></textarea>
                <div class="form-text">
                    Completar únicamente si la materia no fue aprobada durante el ciclo lectivo.
                </div>
                <button type="button" class="btn btn-sm btn-secondary mt-2 btn-actualizar-pendiente"
                        data-pendiente-id="<?= $pendiente['id'] ?>"
                        data-campo="saberes_cierre"
                        data-input-id="saberes_cierre">
                    <i class="bi bi-check-circle"></i> Actualizar Saberes
                </button>
            </div>
        </div>
    </div>
</form>

<!-- Estado actual de la materia -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">Estado Actual de la Materia</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Progreso en Períodos:</h6>
                <?php 
                $periodosCompletados = 0;
                foreach (['marzo', 'julio', 'agosto', 'diciembre', 'febrero'] as $periodo) {
                    if (!empty($pendiente[$periodo])) {
                        $periodosCompletados++;
                        $estado = $pendiente[$periodo];
                        $badgeClass = $estado === 'AA' ? 'success' : ($estado === 'CCA' ? 'warning' : 'danger');
                        echo '<span class="badge bg-' . $badgeClass . ' me-1">' . ucfirst($periodo) . ': ' . $estado . '</span> ';
                    }
                }
                if ($periodosCompletados === 0) {
                    echo '<span class="text-muted">No hay períodos evaluados</span>';
                }
                ?>
                <br><small class="text-muted"><?= $periodosCompletados ?>/5 períodos completados</small>
            </div>
            <div class="col-md-6">
                <h6>Situación Final:</h6>
                <?php if (!empty($pendiente['calificacion_final'])): ?>
                    <?php if ($pendiente['calificacion_final'] >= 4): ?>
                        <span class="badge bg-success fs-6">MATERIA APROBADA (<?= $pendiente['calificacion_final'] ?>)</span>
                    <?php else: ?>
                        <span class="badge bg-danger fs-6">MATERIA NO APROBADA (<?= $pendiente['calificacion_final'] ?>)</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge bg-warning fs-6">EN PROCESO DE INTENSIFICACIÓN</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Información de auditoría -->
<?php if ($hasAuditColumns): ?>
<div class="card mt-3">
    <div class="card-header bg-light">
        <h6 class="mb-0">Información de Auditoría</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <?php if (!empty($pendiente['fecha_creacion'])): ?>
                    <small><strong>Creado:</strong> <?= date('d/m/Y H:i', strtotime($pendiente['fecha_creacion'])) ?></small>
                    <?php if ($pendiente['creador_apellido']): ?>
                        <br><small><strong>Por:</strong> <?= htmlspecialchars($pendiente['creador_apellido'] . ', ' . $pendiente['creador_nombre']) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <?php if (!empty($pendiente['fecha_modificacion'])): ?>
                    <small><strong>Última modificación:</strong> <?= date('d/m/Y H:i', strtotime($pendiente['fecha_modificacion'])) ?></small>
                    <?php if ($pendiente['modificador_apellido']): ?>
                        <br><small><strong>Por:</strong> <?= htmlspecialchars($pendiente['modificador_apellido'] . ', ' . $pendiente['modificador_nombre']) ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Botones del modal -->
<div class="row mt-4">
    <div class="col-12">
        <div class="d-flex justify-content-between">
            <button type="button" class="btn btn-danger"
                    onclick="eliminarPendienteDesdeModal(<?= $pendiente['id'] ?>, '<?= addslashes($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?>', '<?= addslashes($pendiente['nombre_mostrar']) ?>')">
                <i class="bi bi-trash"></i> Eliminar Materia Pendiente
            </button>
            
            <div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cerrar
                </button>
                <button type="button" class="btn btn-info" onclick="verDetallePendiente(<?= $pendiente['id'] ?>)">
                    <i class="bi bi-eye"></i> Ver Detalle Completo
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Función para actualizar un campo específico usando AJAX
function actualizarCampo(campo, valor) {
    // Mostrar indicador de carga
    const button = event.target;
    const originalText = button.innerHTML;
    button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Guardando...';
    button.disabled = true;
    
    // Crear FormData
    const formData = new FormData();
    formData.append('campo', campo);
    formData.append('valor', valor);
    
    // Enviar petición AJAX
    fetch('api_editar_pendiente.php?id=<?= $id ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mostrar éxito temporalmente
            button.innerHTML = '<i class="bi bi-check"></i> ¡Guardado!';
            button.className = button.className.replace(/btn-\w+/, 'btn-success');
            
            // Mostrar mensaje de éxito
            mostrarMensaje('success', data.message);
            
            // Restaurar botón después de 2 segundos
            setTimeout(() => {
                button.innerHTML = originalText;
                button.className = button.className.replace(/btn-success/, 'btn-primary');
                button.disabled = false;
            }, 2000);
        } else {
            // Mostrar error
            button.innerHTML = '<i class="bi bi-x"></i> Error';
            button.className = button.className.replace(/btn-\w+/, 'btn-danger');
            mostrarMensaje('danger', data.message);
            
            // Restaurar botón después de 3 segundos
            setTimeout(() => {
                button.innerHTML = originalText;
                button.className = button.className.replace(/btn-danger/, 'btn-primary');
                button.disabled = false;
            }, 3000);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        button.innerHTML = '<i class="bi bi-x"></i> Error de conexión';
        button.className = button.className.replace(/btn-\w+/, 'btn-danger');
        mostrarMensaje('danger', 'Error de conexión: ' + error.message);
        
        // Restaurar botón
        setTimeout(() => {
            button.innerHTML = originalText;
            button.className = button.className.replace(/btn-danger/, 'btn-primary');
            button.disabled = false;
        }, 3000);
    });
}

// Función para mostrar mensajes
function mostrarMensaje(tipo, mensaje) {
    // Crear elemento de alerta
    const alertaDiv = document.createElement('div');
    alertaDiv.className = `alert alert-${tipo} alert-dismissible fade show`;
    alertaDiv.innerHTML = `
        ${mensaje}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insertar en el área de mensajes
    const mensajesArea = document.getElementById('mensajes-area');
    mensajesArea.appendChild(alertaDiv);
    
    // Auto-ocultar después de 5 segundos
    setTimeout(() => {
        if (alertaDiv.parentNode) {
            alertaDiv.remove();
        }
    }, 5000);
}

// Configurar eventos cuando se carga el modal
document.addEventListener('DOMContentLoaded', function() {
    // Aplicar estilos según estado actual
    document.querySelectorAll('.estado-select').forEach(function(select) {
        const row = select.closest('tr');
        const valor = select.value;
        
        // Remover clases previas
        row.classList.remove('table-success', 'table-warning', 'table-danger');
        
        // Agregar clase según el estado
        switch(valor) {
            case 'AA':
                row.classList.add('table-success');
                break;
            case 'CCA':
                row.classList.add('table-warning');
                break;
            case 'CSA':
                row.classList.add('table-danger');
                break;
        }
    });
    
    // Configurar eventos para cambios en selects
    document.querySelectorAll('.estado-select').forEach(function(select) {
        select.addEventListener('change', function() {
            const row = this.closest('tr');
            const valor = this.value;
            
            // Remover clases previas
            row.classList.remove('table-success', 'table-warning', 'table-danger');
            
            // Agregar clase según el estado
            switch(valor) {
                case 'AA':
                    row.classList.add('table-success');
                    break;
                case 'CCA':
                    row.classList.add('table-warning');
                    break;
                case 'CSA':
                    row.classList.add('table-danger');
                    break;
            }
        });
    });
});

// Función para eliminar desde modal
function eliminarPendienteDesdeModal(pendienteId, estudianteNombre, materiaNombre) {
    if (confirm(`¿Está seguro de eliminar la materia pendiente?\n\nEstudiante: ${estudianteNombre}\nMateria: ${materiaNombre}\n\nEsta acción marcará el registro como eliminado.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin_materias_pendientes.php';
        form.style.display = 'none';

        const accionInput = document.createElement('input');
        accionInput.type = 'hidden';
        accionInput.name = 'accion';
        accionInput.value = 'eliminar_pendiente';
        form.appendChild(accionInput);

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'pendiente_id';
        idInput.value = pendienteId;
        form.appendChild(idInput);

        document.body.appendChild(form);
        form.submit();
    }
}

// Función para ver detalle
function verDetallePendiente(id) {
    // Cerrar modal actual y abrir modal de detalle
    bootstrap.Modal.getInstance(document.getElementById('modalEditarPendiente')).hide();
    
    setTimeout(() => {
        if (window.parent && window.parent.verDetallePendiente) {
            window.parent.verDetallePendiente(id);
        }
    }, 500);
}
</script>

<style>
.estado-row.table-success {
    background-color: #d4edda !important;
}
.estado-row.table-warning {
    background-color: #fff3cd !important;
}
.estado-row.table-danger {
    background-color: #f8d7da !important;
}

.btn-sm {
    font-size: 0.8em;
}

.card-header h6 {
    margin-bottom: 0;
}

.badge {
    font-size: 0.8em;
}

#mensajes-area {
    margin-bottom: 1rem;
}
</style>
