<?php
/**
 * gestionar_recursados.php - Gestión de asignaciones de recursado
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'eliminar_recursado':
            $recursadoId = intval($_POST['recursado_id']);
            
            try {
                // Verificar que existe
                $recursado = $db->fetchOne("SELECT * FROM materias_recursado WHERE id = ?", [$recursadoId]);
                if (!$recursado) {
                    $_SESSION['message'] = 'Asignación de recursado no encontrada';
                    $_SESSION['message_type'] = 'danger';
                    break;
                }
                
                // Verificar si tiene calificaciones
                $calificaciones = $db->fetchOne(
                    "SELECT COUNT(*) as count FROM calificaciones 
                     WHERE estudiante_id = ? AND materia_curso_id = ?",
                    [$recursado['estudiante_id'], $recursado['materia_curso_id']]
                );
                
                if ($calificaciones['count'] > 0 && !isset($_POST['confirmar_con_calificaciones'])) {
                    $_SESSION['message'] = 'El estudiante tiene calificaciones registradas. Use la opción "Forzar eliminación" si está seguro.';
                    $_SESSION['message_type'] = 'warning';
                    break;
                }
                
                // Eliminar (con transacción si tiene calificaciones)
                if ($calificaciones['count'] > 0) {
                    $db->transaction(function($db) use ($recursado) {
                        // Eliminar calificaciones relacionadas
                        $db->query(
                            "DELETE FROM calificaciones 
                             WHERE estudiante_id = ? AND materia_curso_id = ?",
                            [$recursado['estudiante_id'], $recursado['materia_curso_id']]
                        );
                        
                        // Eliminar recursado
                        $db->query("DELETE FROM materias_recursado WHERE id = ?", [$recursado['id']]);
                    });
                    
                    $_SESSION['message'] = 'Asignación de recursado y calificaciones relacionadas eliminadas correctamente';
                } else {
                    $db->query("DELETE FROM materias_recursado WHERE id = ?", [$recursadoId]);
                    $_SESSION['message'] = 'Asignación de recursado eliminada correctamente';
                }
                
                $_SESSION['message_type'] = 'success';
                
            } catch (Exception $e) {
                $_SESSION['message'] = 'Error al eliminar recursado: ' . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
            }
            break;
            
        case 'cambiar_estado_recursado':
            $recursadoId = intval($_POST['recursado_id']);
            $nuevoEstado = $_POST['nuevo_estado'];
            
            try {
                $db->query(
                    "UPDATE materias_recursado SET estado = ? WHERE id = ?",
                    [$nuevoEstado, $recursadoId]
                );
                
                $estadoTexto = $nuevoEstado == 'activo' ? 'activado' : ($nuevoEstado == 'finalizado' ? 'finalizado' : 'cancelado');
                $_SESSION['message'] = "Recursado $estadoTexto correctamente";
                $_SESSION['message_type'] = 'success';
                
            } catch (Exception $e) {
                $_SESSION['message'] = 'Error al cambiar estado: ' . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
            }
            break;
    }
    
    // Redireccionar para evitar reenvío del formulario
    header('Location: gestionar_recursados.php');
    exit;
}

// Incluir el encabezado
require_once 'header.php';

// Obtener recursados activos
$recursados = [];
try {
    $recursados = $db->fetchAll(
        "SELECT 
            mr.id,
            mr.estudiante_id,
            u.nombre as estudiante_nombre,
            u.apellido as estudiante_apellido,
            u.dni as estudiante_dni,
            mr.materia_curso_id,
            m.nombre as materia_nombre,
            m.codigo as materia_codigo,
            c.nombre as curso_original,
            c.anio as anio_original,
            cl.anio as ciclo_lectivo,
            mr.fecha_asignacion,
            mr.observaciones,
            mr.estado,
            c_actual.nombre as curso_actual,
            c_actual.anio as anio_actual,
            COUNT(cal.id) as tiene_calificaciones
        FROM materias_recursado mr
        JOIN usuarios u ON mr.estudiante_id = u.id
        JOIN materias_por_curso mp ON mr.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        JOIN cursos c ON mp.curso_id = c.id
        JOIN ciclos_lectivos cl ON c.ciclo_lectivo_id = cl.id
        LEFT JOIN matriculas mat ON u.id = mat.estudiante_id AND mat.estado = 'activo'
        LEFT JOIN cursos c_actual ON mat.curso_id = c_actual.id
        LEFT JOIN calificaciones cal ON u.id = cal.estudiante_id AND mp.id = cal.materia_curso_id
        GROUP BY mr.id
        ORDER BY mr.fecha_asignacion DESC, u.apellido, u.nombre"
    );
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener recursados: ' . $e->getMessage() . '</div>';
}
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Gestión de Recursados</h5>
                    <div>
                        <a href="cursos.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver a Cursos
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($recursados) > 0): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Total de recursados:</strong> <?= count($recursados) ?>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Curso Actual</th>
                                        <th>Materia Recursando</th>
                                        <th>Año Original</th>
                                        <th>Fecha Asignación</th>
                                        <th>Estado</th>
                                        <th>Calificaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recursados as $recursado): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($recursado['estudiante_apellido']) ?>, <?= htmlspecialchars($recursado['estudiante_nombre']) ?></strong>
                                            <br><small class="text-muted">DNI: <?= htmlspecialchars($recursado['estudiante_dni']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($recursado['curso_actual'] ?? 'Sin matrícula') ?>
                                            <?php if ($recursado['anio_actual']): ?>
                                                <br><small class="text-muted"><?= $recursado['anio_actual'] ?>° año</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($recursado['materia_nombre']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($recursado['materia_codigo']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= $recursado['anio_original'] ?>° año</span>
                                            <br><small class="text-muted"><?= htmlspecialchars($recursado['curso_original']) ?></small>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y', strtotime($recursado['fecha_asignacion'])) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $recursado['estado'] == 'activo' ? 'success' : 
                                                ($recursado['estado'] == 'finalizado' ? 'primary' : 'secondary') 
                                            ?>">
                                                <?= ucfirst($recursado['estado']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($recursado['tiene_calificaciones'] > 0): ?>
                                                <span class="badge bg-warning">
                                                    <i class="bi bi-exclamation-triangle"></i> Tiene calificaciones
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Sin calificaciones</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <?php if ($recursado['estado'] == 'activo'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            onclick="cambiarEstado(<?= $recursado['id'] ?>, 'finalizado')"
                                                            title="Marcar como finalizado">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                            onclick="cambiarEstado(<?= $recursado['id'] ?>, 'cancelado')"
                                                            title="Cancelar recursado">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                            onclick="cambiarEstado(<?= $recursado['id'] ?>, 'activo')"
                                                            title="Reactivar recursado">
                                                        <i class="bi bi-arrow-clockwise"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                        onclick="verDetalles(<?= $recursado['id'] ?>)"
                                                        data-bs-toggle="modal" data-bs-target="#modalDetalles"
                                                        title="Ver detalles">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                
                                                <?php if ($recursado['tiene_calificaciones'] > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="eliminarConCalificaciones(<?= $recursado['id'] ?>)"
                                                            title="Eliminar (forzar)">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="eliminarRecursado(<?= $recursado['id'] ?>)"
                                                            title="Eliminar">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="bi bi-info-circle"></i>
                            <h5>No hay recursados registrados</h5>
                            <p>Los estudiantes asignados para recursado aparecerán aquí.</p>
                            <a href="cursos.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Asignar Recursado
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Detalles -->
<div class="modal fade" id="modalDetalles" tabindex="-1" aria-labelledby="modalDetallesLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalDetallesLabel">Detalles del Recursado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoDetalles">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formularios ocultos -->
<form id="formCambiarEstado" method="POST" action="gestionar_recursados.php" style="display: none;">
    <input type="hidden" name="accion" value="cambiar_estado_recursado">
    <input type="hidden" name="recursado_id" id="cambiar_estado_id">
    <input type="hidden" name="nuevo_estado" id="cambiar_estado_valor">
</form>

<form id="formEliminarRecursado" method="POST" action="gestionar_recursados.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_recursado">
    <input type="hidden" name="recursado_id" id="eliminar_recursado_id">
</form>

<form id="formEliminarConCalificaciones" method="POST" action="gestionar_recursados.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_recursado">
    <input type="hidden" name="recursado_id" id="eliminar_con_cal_id">
    <input type="hidden" name="confirmar_con_calificaciones" value="1">
</form>

<script>
// Función para cambiar estado
function cambiarEstado(recursadoId, nuevoEstado) {
    let mensaje = '';
    let clase = '';
    
    switch(nuevoEstado) {
        case 'finalizado':
            mensaje = '¿Marcar este recursado como finalizado?';
            clase = 'success';
            break;
        case 'cancelado':
            mensaje = '¿Cancelar este recursado?';
            clase = 'warning';
            break;
        case 'activo':
            mensaje = '¿Reactivar este recursado?';
            clase = 'info';
            break;
    }
    
    if (confirm(mensaje)) {
        document.getElementById('cambiar_estado_id').value = recursadoId;
        document.getElementById('cambiar_estado_valor').value = nuevoEstado;
        document.getElementById('formCambiarEstado').submit();
    }
}

// Función para eliminar recursado sin calificaciones
function eliminarRecursado(recursadoId) {
    if (confirm('¿Está seguro de que desea eliminar esta asignación de recursado?\n\nEsta acción no se puede deshacer.')) {
        document.getElementById('eliminar_recursado_id').value = recursadoId;
        document.getElementById('formEliminarRecursado').submit();
    }
}

// Función para eliminar recursado con calificaciones
function eliminarConCalificaciones(recursadoId) {
    if (confirm('⚠️ ATENCIÓN: Este recursado tiene calificaciones registradas.\n\n¿Está seguro de que desea eliminar tanto el recursado como sus calificaciones?\n\nEsta acción NO se puede deshacer.')) {
        document.getElementById('eliminar_con_cal_id').value = recursadoId;
        document.getElementById('formEliminarConCalificaciones').submit();
    }
}

// Función para ver detalles
function verDetalles(recursadoId) {
    document.getElementById('contenidoDetalles').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>
    `;
    
    // Aquí podrías hacer una llamada AJAX para obtener más detalles
    // Por ahora mostraremos la información básica
    setTimeout(() => {
        document.getElementById('contenidoDetalles').innerHTML = `
            <div class="alert alert-info">
                <h6>Funcionalidad de detalles</h6>
                <p>Aquí se mostrarían los detalles completos del recursado, incluyendo:</p>
                <ul>
                    <li>Historial de calificaciones</li>
                    <li>Observaciones detalladas</li>
                    <li>Progreso del estudiante</li>
                </ul>
            </div>
        `;
    }, 500);
}
</script>

<?php
require_once 'footer.php';
?>