<?php
/**
 * api_detalle_pendiente.php - API para ver detalle completo de materias pendientes ACTUALIZADO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * CORREGIDO: Botones funcionando correctamente y mejor organización
 */

require_once 'config.php';

// Solo incluir funciones auxiliares si el archivo existe
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

try {
    // Verificar qué columnas existen en la tabla
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
        u.telefono as estudiante_telefono,
        u.direccion as estudiante_direccion,
        m.nombre as materia_nombre,
        m.codigo as materia_codigo,
        c.anio as curso_anio,
        c.nombre as curso_nombre,
        c_actual.anio as curso_actual_anio,
        c_actual.nombre as curso_actual_nombre,
        p1.apellido as profesor_apellido,
        p1.nombre as profesor_nombre,
        p1.email as profesor_email,
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
     JOIN matriculas mat ON u.id = mat.estudiante_id AND mat.estado = 'activo'
     JOIN cursos c_actual ON mat.curso_id = c_actual.id
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

    // Obtener historial de calificaciones si existe la tabla
    $historial = [];
    try {
        $historial = $db->fetchAll(
            "SELECT campo_modificado, valor_anterior, valor_nuevo, fecha_modificacion, 
                    u.apellido, u.nombre
             FROM historial_modificaciones_pendientes hmp
             JOIN usuarios u ON hmp.modificado_por = u.id
             WHERE hmp.pendiente_id = ?
             ORDER BY hmp.fecha_modificacion DESC",
            [$id]
        );
    } catch (Exception $e) {
        // Si la tabla no existe, simplemente continúa sin historial
    }

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

?>

<!-- Información del estudiante -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-person"></i> Información del Estudiante</h6>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <td><strong>Nombre completo:</strong></td>
                        <td><?= htmlspecialchars($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>DNI:</strong></td>
                        <td><?= $pendiente['estudiante_dni'] ?></td>
                    </tr>
                    <tr>
                        <td><strong>Curso actual:</strong></td>
                        <td><?= $pendiente['curso_actual_anio'] ?>° - <?= htmlspecialchars($pendiente['curso_actual_nombre']) ?></td>
                    </tr>
                    <?php if ($pendiente['estudiante_telefono']): ?>
                    <tr>
                        <td><strong>Teléfono:</strong></td>
                        <td><?= htmlspecialchars($pendiente['estudiante_telefono']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($pendiente['estudiante_direccion']): ?>
                    <tr>
                        <td><strong>Dirección:</strong></td>
                        <td><?= htmlspecialchars($pendiente['estudiante_direccion']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="bi bi-book"></i> Información de la Materia</h6>
            </div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tr>
                        <td><strong>Materia:</strong></td>
                        <td>
                            <?php if ($pendiente['es_parte_grupo'] == 1): ?>
                                <div class="materia-info-principal">
                                    <span class="badge bg-primary me-1">GRUPO</span>
                                    <strong><?= htmlspecialchars($pendiente['grupo_nombre']) ?></strong>
                                    <?php if ($pendiente['grupo_codigo']): ?>
                                        <span class="badge bg-primary ms-1"><?= htmlspecialchars($pendiente['grupo_codigo']) ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <small class="text-muted d-block mt-2">
                                    <i class="bi bi-arrow-right"></i> <strong>Materia específica:</strong> 
                                    <?= htmlspecialchars($pendiente['materia_nombre']) ?>
                                    <?php if ($pendiente['materia_codigo'] && $pendiente['materia_codigo'] != $pendiente['grupo_codigo']): ?>
                                        <span class="badge bg-secondary ms-1"><?= htmlspecialchars($pendiente['materia_codigo']) ?></span>
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <div class="materia-info-principal">
                                    <strong><?= htmlspecialchars($pendiente['nombre_mostrar']) ?></strong>
                                    <?php if ($pendiente['codigo_mostrar']): ?>
                                        <span class="badge bg-primary ms-1"><?= htmlspecialchars($pendiente['codigo_mostrar']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Tipo:</strong></td>
                        <td>
                            <?php if ($pendiente['es_parte_grupo'] == 1): ?>
                                <span class="badge bg-success">Parte de Grupo</span>
                                <small class="text-muted d-block">Esta materia forma parte del grupo "<?= htmlspecialchars($pendiente['grupo_nombre']) ?>"</small>
                            <?php else: ?>
                                <span class="badge bg-info">Materia Individual</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Año cursada:</strong></td>
                        <td><?= $pendiente['curso_anio'] ?>° Año</td>
                    </tr>
                    <tr>
                        <td><strong>Profesor:</strong></td>
                        <td>
                            <?php if ($pendiente['profesor_apellido']): ?>
                                <?= htmlspecialchars($pendiente['profesor_apellido'] . ', ' . $pendiente['profesor_nombre']) ?>
                                <?php if ($pendiente['profesor_email']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($pendiente['profesor_email']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Sin asignar</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Estado:</strong></td>
                        <td>
                            <span class="badge bg-<?= $pendiente['estado'] === 'activo' ? 'success' : 'danger' ?>">
                                <?= ucfirst($pendiente['estado']) ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Registro de Intensificación (Formato RITE Oficial) -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h6 class="mb-0">
            <i class="bi bi-table"></i> Registro de Intensificación - Formato RITE
            <br><small class="opacity-75">
                Materia: <?= htmlspecialchars($pendiente['nombre_mostrar']) ?>
                <?php if ($pendiente['es_parte_grupo'] == 1): ?>
                    → <?= htmlspecialchars($pendiente['materia_nombre']) ?>
                <?php endif; ?>
            </small>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0" style="font-size: 0.9em;">
                <!-- Encabezado principal -->
                <thead>
                    <tr class="table-primary text-center">
                        <td colspan="8" class="fw-bold py-3" style="background-color: #0ea5e9; color: white; font-size: 1.1em;">
                            MATERIA PENDIENTE DE APROBACIÓN Y ACREDITACIÓN - INTENSIFICACIÓN
                        </td>
                    </tr>
                </thead>
                
                <!-- Encabezados de columnas -->
                <thead>
                    <tr class="table-info text-center">
                        <th rowspan="2" class="align-middle" style="background-color: #0ea5e9; color: white;">
                            <strong>SABERES INICIALES<br>PENDIENTES</strong>
                        </th>
                        <th colspan="5" class="text-center" style="background-color: #0ea5e9; color: white;">
                            <strong>PERÍODO DE INTENSIFICACIÓN</strong>
                        </th>
                        <th rowspan="2" class="align-middle" style="background-color: #0ea5e9; color: white;">
                            <strong>CALIFICACIÓN<br>FINAL</strong>
                        </th>
                        <th rowspan="2" class="align-middle" style="background-color: #0ea5e9; color: white;">
                            <strong>SABERES PENDIENTES<br>AL CIERRE</strong>
                        </th>
                    </tr>
                    <tr class="table-info text-center">
                        <th style="background-color: #0ea5e9; color: white;"><strong>MARZO</strong></th>
                        <th style="background-color: #0ea5e9; color: white;"><strong>JULIO</strong></th>
                        <th style="background-color: #0ea5e9; color: white;"><strong>AGOSTO</strong></th>
                        <th style="background-color: #0ea5e9; color: white;"><strong>DICIEMBRE</strong></th>
                        <th style="background-color: #0ea5e9; color: white;"><strong>FEBRERO</strong></th>
                    </tr>
                </thead>
                
                <!-- Datos del estudiante -->
                <tbody>
                    <tr>
                        <!-- Saberes iniciales -->
                        <td style="vertical-align: middle; min-width: 200px; padding: 15px;">
                            <?php if ($pendiente['saberes_iniciales']): ?>
                                <?= nl2br(htmlspecialchars($pendiente['saberes_iniciales'])) ?>
                            <?php else: ?>
                                <span class="text-muted">Sin especificar</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Períodos de intensificación -->
                        <td class="text-center align-middle" style="padding: 15px;">
                            <?php if ($pendiente['marzo']): ?>
                                <span class="badge bg-<?= $pendiente['marzo'] === 'AA' ? 'success' : ($pendiente['marzo'] === 'CCA' ? 'warning' : 'danger') ?> fs-6">
                                    <?= $pendiente['marzo'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center align-middle" style="padding: 15px;">
                            <?php if ($pendiente['julio']): ?>
                                <span class="badge bg-<?= $pendiente['julio'] === 'AA' ? 'success' : ($pendiente['julio'] === 'CCA' ? 'warning' : 'danger') ?> fs-6">
                                    <?= $pendiente['julio'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center align-middle" style="padding: 15px;">
                            <?php if ($pendiente['agosto']): ?>
                                <span class="badge bg-<?= $pendiente['agosto'] === 'AA' ? 'success' : ($pendiente['agosto'] === 'CCA' ? 'warning' : 'danger') ?> fs-6">
                                    <?= $pendiente['agosto'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center align-middle" style="padding: 15px;">
                            <?php if ($pendiente['diciembre']): ?>
                                <span class="badge bg-<?= $pendiente['diciembre'] === 'AA' ? 'success' : ($pendiente['diciembre'] === 'CCA' ? 'warning' : 'danger') ?> fs-6">
                                    <?= $pendiente['diciembre'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="text-center align-middle" style="padding: 15px;">
                            <?php if ($pendiente['febrero']): ?>
                                <span class="badge bg-<?= $pendiente['febrero'] === 'AA' ? 'success' : ($pendiente['febrero'] === 'CCA' ? 'warning' : 'danger') ?> fs-6">
                                    <?= $pendiente['febrero'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Calificación final -->
                        <td class="text-center align-middle" style="padding: 15px;">
                            <?php if ($pendiente['calificacion_final']): ?>
                                <span class="badge bg-<?= $pendiente['calificacion_final'] >= 7 ? 'success' : ($pendiente['calificacion_final'] >= 4 ? 'warning' : 'danger') ?>" style="font-size: 1.2em; padding: 8px 12px;">
                                    <?= $pendiente['calificacion_final'] ?>
                                </span>
                                <br><small class="text-muted mt-1">
                                    <?php if ($pendiente['calificacion_final'] >= 4): ?>
                                        Aprobó y Acreditó
                                    <?php else: ?>
                                        No Aprobó
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <span class="text-muted">Sin calificar</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Saberes al cierre -->
                        <td style="vertical-align: middle; min-width: 200px; padding: 15px;">
                            <?php if ($pendiente['saberes_cierre']): ?>
                                <?= nl2br(htmlspecialchars($pendiente['saberes_cierre'])) ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Análisis del progreso -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card border-success">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="bi bi-graph-up"></i> Análisis del Progreso</h6>
            </div>
            <div class="card-body">
                <?php
                $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
                $progreso = [];
                $ultimoEstado = '';
                $ultimoPeriodo = '';
                
                foreach ($periodos as $periodo) {
                    if (!empty($pendiente[$periodo])) {
                        $progreso[] = [
                            'periodo' => ucfirst($periodo),
                            'estado' => $pendiente[$periodo],
                            'completado' => true
                        ];
                        $ultimoEstado = $pendiente[$periodo];
                        $ultimoPeriodo = ucfirst($periodo);
                    } else {
                        $progreso[] = [
                            'periodo' => ucfirst($periodo),
                            'estado' => '',
                            'completado' => false
                        ];
                    }
                }
                ?>
                
                <div class="row">
                    <div class="col-md-12">
                        <h6>Progreso por períodos:</h6>
                        <div class="progress mb-3" style="height: 25px;">
                            <?php 
                            $completados = count(array_filter($progreso, function($p) { return $p['completado']; }));
                            $porcentaje = ($completados / 5) * 100;
                            ?>
                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $porcentaje ?>%">
                                <?= $completados ?>/5 períodos completados
                            </div>
                        </div>
                        
                        <div class="row">
                            <?php foreach ($progreso as $p): ?>
                            <div class="col">
                                <div class="text-center">
                                    <div class="badge bg-<?= $p['completado'] ? ($p['estado'] === 'AA' ? 'success' : ($p['estado'] === 'CCA' ? 'warning' : 'danger')) : 'secondary' ?> mb-1" style="width: 100%;">
                                        <?= $p['periodo'] ?>
                                    </div>
                                    <small class="d-block text-muted">
                                        <?= $p['completado'] ? $p['estado'] : 'Pendiente' ?>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6">
                        <strong>Estado actual:</strong>
                        <?php if ($ultimoEstado === 'AA'): ?>
                            <span class="badge bg-success ms-2">Aprobó y Acreditó (<?= $ultimoPeriodo ?>)</span>
                        <?php elseif ($ultimoEstado === 'CCA'): ?>
                            <span class="badge bg-warning ms-2">Continúa Con Avances (<?= $ultimoPeriodo ?>)</span>
                        <?php elseif ($ultimoEstado === 'CSA'): ?>
                            <span class="badge bg-danger ms-2">Continúa Sin Avances (<?= $ultimoPeriodo ?>)</span>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2">Sin evaluar</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Situación final:</strong>
                        <?php if ($pendiente['calificacion_final']): ?>
                            <?php if ($pendiente['calificacion_final'] >= 4): ?>
                                <span class="badge bg-success ms-2">MATERIA APROBADA (<?= $pendiente['calificacion_final'] ?>)</span>
                            <?php else: ?>
                                <span class="badge bg-danger ms-2">MATERIA NO APROBADA (<?= $pendiente['calificacion_final'] ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-warning ms-2">EN PROCESO</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="bi bi-clock-history"></i> Información de Registro</h6>
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <?php if (!empty($pendiente['fecha_creacion'])): ?>
                    <tr>
                        <td><strong>Creado:</strong></td>
                        <td><?= date('d/m/Y H:i', strtotime($pendiente['fecha_creacion'])) ?></td>
                    </tr>
                    <?php if ($pendiente['creador_apellido']): ?>
                    <tr>
                        <td><strong>Por:</strong></td>
                        <td><?= htmlspecialchars($pendiente['creador_apellido'] . ', ' . $pendiente['creador_nombre']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if (!empty($pendiente['fecha_modificacion'])): ?>
                    <tr>
                        <td><strong>Modificado:</strong></td>
                        <td><?= date('d/m/Y H:i', strtotime($pendiente['fecha_modificacion'])) ?></td>
                    </tr>
                    <?php if ($pendiente['modificador_apellido']): ?>
                    <tr>
                        <td><strong>Por:</strong></td>
                        <td><?= htmlspecialchars($pendiente['modificador_apellido'] . ', ' . $pendiente['modificador_nombre']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Historial de modificaciones (si existe) -->
<?php if (!empty($historial)): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Historial de Modificaciones</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Campo</th>
                        <th>Valor Anterior</th>
                        <th>Valor Nuevo</th>
                        <th>Modificado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $h): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($h['fecha_modificacion'])) ?></td>
                        <td><span class="badge bg-info"><?= htmlspecialchars($h['campo_modificado']) ?></span></td>
                        <td><small class="text-muted"><?= htmlspecialchars($h['valor_anterior'] ?? '-') ?></small></td>
                        <td><strong><?= htmlspecialchars($h['valor_nuevo'] ?? '-') ?></strong></td>
                        <td><?= htmlspecialchars($h['apellido'] . ', ' . $h['nombre']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Botones de acción -->
<div class="mt-4 text-center">
    <button type="button" class="btn btn-primary" onclick="editarPendienteDesdeDetalle(<?= $pendiente['id'] ?>)">
        <i class="bi bi-pencil-square"></i> Editar Calificaciones
    </button>
    <button type="button" class="btn btn-success" onclick="imprimirDetalle()">
        <i class="bi bi-printer"></i> Imprimir Registro
    </button>
    <button type="button" class="btn btn-info" onclick="exportarPDF(<?= $pendiente['id'] ?>)">
        <i class="bi bi-file-pdf"></i> Exportar PDF
    </button>
    <?php if (in_array($_SESSION['user_type'], ['admin', 'directivo'])): ?>
    <button type="button" class="btn btn-danger" onclick="eliminarPendienteDesdeDetalle(<?= $pendiente['id'] ?>)">
        <i class="bi bi-trash"></i> Eliminar Registro
    </button>
    <?php endif; ?>
</div>

<script>
function editarPendienteDesdeDetalle(id) {
    // Cerrar modal actual y abrir modal de edición
    const modalActual = bootstrap.Modal.getInstance(document.getElementById('modalDetallePendiente'));
    if (modalActual) {
        modalActual.hide();
    }
    
    setTimeout(() => {
        if (window.parent && window.parent.editarPendiente) {
            window.parent.editarPendiente(id, '<?= addslashes($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?>');
        }
    }, 500);
}

function imprimirDetalle() {
    window.print();
}

function exportarPDF(id) {
    window.open('generar_pdf_pendiente.php?id=' + id, '_blank');
}

function eliminarPendienteDesdeDetalle(id) {
    if (confirm('¿Está seguro de eliminar esta materia pendiente?\n\nEsta acción marcará el registro como eliminado.')) {
        // Crear formulario dinámico para eliminar
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
        idInput.value = id;
        form.appendChild(idInput);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<style>
/* Estilos para impresión */
@media print {
    .btn, .modal-footer {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        box-shadow: none !important;
    }
    
    .table th, .table td {
        border: 1px solid #000 !important;
    }
}

.badge {
    font-size: 0.9em;
}

.table th, .table td {
    vertical-align: middle;
}

.materia-info-principal {
    line-height: 1.4;
}
</style>
