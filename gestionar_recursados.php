<?php
/**
 * gestionar_recursados.php - Gestión de asignaciones de recursado
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * CORREGIDO: Incluye ciclo_lectivo_id en todas las operaciones
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

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
$anioActivo = $cicloActivo ? $cicloActivo['anio'] : date('Y');

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
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                    [$recursado['estudiante_id'], $recursado['materia_curso_id'], $recursado['ciclo_lectivo_id']]
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
                             WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                            [$recursado['estudiante_id'], $recursado['materia_curso_id'], $recursado['ciclo_lectivo_id']]
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
            
        case 'asignar_recursado':
            $estudianteId = intval($_POST['estudiante_id']);
            $materiaCursoId = intval($_POST['materia_curso_id']);
            $materiaLiberadaId = isset($_POST['materia_liberada_id']) ? intval($_POST['materia_liberada_id']) : null;
            $observaciones = trim($_POST['observaciones'] ?? '');
            
            try {
                // Verificar que no existe ya un recursado activo para este estudiante y materia
                $recursadoExistente = $db->fetchOne(
                    "SELECT id FROM materias_recursado 
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ? AND estado = 'activo'",
                    [$estudianteId, $materiaCursoId, $cicloLectivoId]
                );
                
                if ($recursadoExistente) {
                    $_SESSION['message'] = 'El estudiante ya tiene un recursado activo para esta materia';
                    $_SESSION['message_type'] = 'warning';
                    break;
                }
                
                // INSERCIÓN CORREGIDA: Incluir ciclo_lectivo_id
                $db->query(
                    "INSERT INTO materias_recursado (estudiante_id, materia_curso_id, materia_liberada_id, ciclo_lectivo_id, fecha_asignacion, observaciones, estado) 
                     VALUES (?, ?, ?, ?, date('now'), ?, 'activo')",
                    [$estudianteId, $materiaCursoId, $materiaLiberadaId, $cicloLectivoId, $observaciones]
                );
                
                $_SESSION['message'] = 'Recursado asignado correctamente';
                $_SESSION['message_type'] = 'success';
                
            } catch (Exception $e) {
                $_SESSION['message'] = 'Error al asignar recursado: ' . $e->getMessage();
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

// Obtener recursados activos con filtro por ciclo lectivo
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
            mr.ciclo_lectivo_id,
            c_actual.nombre as curso_actual,
            c_actual.anio as anio_actual,
            COUNT(cal.id) as tiene_calificaciones,
            ml.nombre as materia_liberada_nombre,
            ml.codigo as materia_liberada_codigo
        FROM materias_recursado mr
        JOIN usuarios u ON mr.estudiante_id = u.id
        JOIN materias_por_curso mp ON mr.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        JOIN cursos c ON mp.curso_id = c.id
        JOIN ciclos_lectivos cl ON mr.ciclo_lectivo_id = cl.id
        LEFT JOIN matriculas mat ON u.id = mat.estudiante_id AND mat.estado = 'activo'
        LEFT JOIN cursos c_actual ON mat.curso_id = c_actual.id
        LEFT JOIN calificaciones cal ON u.id = cal.estudiante_id AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = mr.ciclo_lectivo_id
        LEFT JOIN materias_por_curso mp_lib ON mr.materia_liberada_id = mp_lib.id
        LEFT JOIN materias ml ON mp_lib.materia_id = ml.id
        WHERE mr.ciclo_lectivo_id = ?
        GROUP BY mr.id
        ORDER BY mr.fecha_asignacion DESC, u.apellido, u.nombre",
        [$cicloLectivoId]
    );
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener recursados: ' . $e->getMessage() . '</div>';
}

// Obtener estadísticas
$estadisticas = [];
if ($cicloLectivoId > 0) {
    try {
        $estadisticas = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_recursados,
                COUNT(CASE WHEN estado = 'activo' THEN 1 END) as activos,
                COUNT(CASE WHEN estado = 'finalizado' THEN 1 END) as finalizados,
                COUNT(CASE WHEN estado = 'cancelado' THEN 1 END) as cancelados
             FROM materias_recursado 
             WHERE ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
    } catch (Exception $e) {
        $estadisticas = ['total_recursados' => 0, 'activos' => 0, 'finalizados' => 0, 'cancelados' => 0];
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Encabezado con información del ciclo lectivo -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-arrow-repeat"></i> Gestión de Recursados - Ciclo Lectivo <?= $anioActivo ?>
                    </h5>
                    <div>
                        <?php if ($cicloLectivoId == 0): ?>
                        <span class="badge bg-danger">Sin ciclo activo</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark">Ciclo ID: <?= $cicloLectivoId ?></span>
                        <?php endif; ?>
                        <a href="cursos.php" class="btn btn-light btn-sm ms-2">
                            <i class="bi bi-arrow-left"></i> Volver a Cursos
                        </a>
                    </div>
                </div>
                <?php if (!empty($estadisticas)): ?>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h3 class="text-primary"><?= $estadisticas['total_recursados'] ?></h3>
                                    <p class="card-text">Total Recursados</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h3 class="text-success"><?= $estadisticas['activos'] ?></h3>
                                    <p class="card-text">Activos</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h3 class="text-info"><?= $estadisticas['finalizados'] ?></h3>
                                    <p class="card-text">Finalizados</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-secondary">
                                <div class="card-body">
                                    <h3 class="text-secondary"><?= $estadisticas['cancelados'] ?></h3>
                                    <p class="card-text">Cancelados</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Verificación de ciclo lectivo -->
    <?php if ($cicloLectivoId == 0): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="alert alert-danger border-start border-5 border-danger">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle fs-4 me-3"></i>
                    <div>
                        <h6 class="alert-heading">No hay ciclo lectivo activo</h6>
                        <p class="mb-2">Para gestionar recursados, debe tener un ciclo lectivo activo configurado en el sistema.</p>
                        <a href="index.php" class="btn btn-outline-danger">
                            <i class="bi bi-arrow-left"></i> Volver al Panel Principal
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Lista de recursados -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-check"></i> Recursados Registrados
                    </h5>
                    <div>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAsignarRecursado">
                            <i class="bi bi-plus-circle"></i> Asignar Nuevo Recursado
                        </button>
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
                                        <th>Materia Liberada</th>
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
                                            <?php if ($recursado['materia_liberada_nombre']): ?>
                                                <strong><?= htmlspecialchars($recursado['materia_liberada_nombre']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($recursado['materia_liberada_codigo']) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Sin liberar</span>
                                            <?php endif; ?>
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
                            <i class="bi bi-info-circle display-4 text-muted"></i>
                            <h5 class="mt-3">No hay recursados registrados</h5>
                            <p>Los estudiantes asignados para recursado aparecerán aquí.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAsignarRecursado">
                                <i class="bi bi-plus-circle"></i> Asignar Primer Recursado
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Asignar Recursado -->
<div class="modal fade" id="modalAsignarRecursado" tabindex="-1" aria-labelledby="modalAsignarRecursadoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_recursados.php">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="modalAsignarRecursadoLabel">
                        <i class="bi bi-plus-circle"></i> Asignar Nuevo Recursado
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="asignar_recursado">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Asignar recursado:</strong> Permite que un estudiante curse una materia de un año anterior mientras libera una materia de su año actual.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="estudiante_id" class="form-label">
                                    <i class="bi bi-person"></i> Estudiante: *
                                </label>
                                <select name="estudiante_id" id="estudiante_id" class="form-select" required>
                                    <option value="">-- Seleccione estudiante --</option>
                                    <?php
                                    try {
                                        $estudiantes = $db->fetchAll(
                                            "SELECT u.id, u.nombre, u.apellido, u.dni, c.nombre as curso_nombre, c.anio
                                             FROM usuarios u
                                             JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
                                             JOIN cursos c ON m.curso_id = c.id
                                             WHERE u.tipo = 'estudiante' AND u.activo = 1 AND c.ciclo_lectivo_id = ?
                                             ORDER BY u.apellido, u.nombre",
                                            [$cicloLectivoId]
                                        );
                                        
                                        foreach ($estudiantes as $estudiante): ?>
                                            <option value="<?= $estudiante['id'] ?>">
                                                <?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?> 
                                                (<?= htmlspecialchars($estudiante['dni']) ?>) - <?= htmlspecialchars($estudiante['curso_nombre']) ?>
                                            </option>
                                        <?php endforeach;
                                    } catch (Exception $e) {
                                        echo '<option value="">Error al cargar estudiantes</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="materia_curso_id" class="form-label">
                                    <i class="bi bi-journal-text"></i> Materia a Recursar: *
                                </label>
                                <select name="materia_curso_id" id="materia_curso_id" class="form-select" required>
                                    <option value="">-- Seleccione materia --</option>
                                    <?php
                                    try {
                                        $materias = $db->fetchAll(
                                            "SELECT mp.id, m.nombre, m.codigo, c.nombre as curso_nombre, c.anio
                                             FROM materias_por_curso mp
                                             JOIN materias m ON mp.materia_id = m.id
                                             JOIN cursos c ON mp.curso_id = c.id
                                             WHERE c.ciclo_lectivo_id = ?
                                             ORDER BY c.anio, m.nombre",
                                            [$cicloLectivoId]
                                        );
                                        
                                        foreach ($materias as $materia): ?>
                                            <option value="<?= $materia['id'] ?>">
                                                <?= htmlspecialchars($materia['nombre']) ?> (<?= htmlspecialchars($materia['codigo']) ?>) 
                                                - <?= $materia['anio'] ?>° año
                                            </option>
                                        <?php endforeach;
                                    } catch (Exception $e) {
                                        echo '<option value="">Error al cargar materias</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="materia_liberada_id" class="form-label">
                            <i class="bi bi-journal-check"></i> Materia Liberada (opcional):
                        </label>
                        <select name="materia_liberada_id" id="materia_liberada_id" class="form-select">
                            <option value="">-- Sin liberar materia --</option>
                            <?php
                            // Reutilizar la misma lista de materias
                            foreach ($materias as $materia): ?>
                                <option value="<?= $materia['id'] ?>">
                                    <?= htmlspecialchars($materia['nombre']) ?> (<?= htmlspecialchars($materia['codigo']) ?>) 
                                    - <?= $materia['anio'] ?>° año
                                </option>
                            <?php endforeach;
                            ?>
                        </select>
                        <small class="form-text text-muted">Materia del año actual que el estudiante no cursará para poder recursar</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="observaciones" class="form-label">
                            <i class="bi bi-chat-text"></i> Observaciones:
                        </label>
                        <textarea name="observaciones" id="observaciones" class="form-control" rows="3"
                                  placeholder="Motivo del recursado, condiciones especiales, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> Asignar Recursado
                    </button>
                </div>
            </form>
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
                    <li>Materia liberada (si aplica)</li>
                </ul>
            </div>
        `;
    }, 500);
}

// Validation para el formulario de asignar recursado
document.addEventListener('DOMContentLoaded', function() {
    const estudianteSelect = document.getElementById('estudiante_id');
    const materiaSelect = document.getElementById('materia_curso_id');
    const materiaLiberadaSelect = document.getElementById('materia_liberada_id');
    
    // Evitar que el estudiante recurse la misma materia que libera
    if (materiaSelect && materiaLiberadaSelect) {
        materiaSelect.addEventListener('change', function() {
            const materiaSeleccionada = this.value;
            Array.from(materiaLiberadaSelect.options).forEach(option => {
                if (option.value === materiaSeleccionada) {
                    option.disabled = true;
                    option.title = 'No puede liberar la misma materia que va a recursar';
                } else {
                    option.disabled = false;
                    option.title = '';
                }
            });
            
            // Si la materia liberada seleccionada es la misma que la materia a recursar, resetear
            if (materiaLiberadaSelect.value === materiaSeleccionada) {
                materiaLiberadaSelect.value = '';
            }
        });
        
        materiaLiberadaSelect.addEventListener('change', function() {
            const materiaLiberada = this.value;
            Array.from(materiaSelect.options).forEach(option => {
                if (option.value === materiaLiberada) {
                    option.disabled = true;
                    option.title = 'No puede recursar la misma materia que va a liberar';
                } else {
                    option.disabled = false;
                    option.title = '';
                }
            });
            
            // Si la materia a recursar seleccionada es la misma que la liberada, resetear
            if (materiaSelect.value === materiaLiberada) {
                materiaSelect.value = '';
            }
        });
    }
});
</script>

<?php
require_once 'footer.php';
?>