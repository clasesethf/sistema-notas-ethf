<?php
/**
 * detectar_calificaciones_huerfanas.php
 * Herramienta para detectar y limpiar calificaciones inconsistentes
 */

require_once 'config.php';

// Verificar permisos
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    die('Sin permisos');
}

require_once 'header.php';

$db = Database::getInstance();
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Procesar acciones de limpieza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar_calificacion'])) {
    try {
        $calificacionId = intval($_POST['calificacion_id']);
        
        $db->query("DELETE FROM calificaciones WHERE id = ?", [$calificacionId]);
        
        $_SESSION['message'] = 'Calificación eliminada correctamente';
        $_SESSION['message_type'] = 'success';
        
        header('Location: detectar_calificaciones_huerfanas.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al eliminar: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Detectar calificaciones huérfanas
$calificacionesHuerfanas = [];
if ($cicloLectivoId > 0) {
    $calificacionesHuerfanas = $db->fetchAll(
        "SELECT c.id as calificacion_id, c.*, u.apellido, u.nombre, u.dni,
                m.nombre as materia_nombre, m.codigo,
                cu.nombre as curso_nombre, cu.anio,
                CASE 
                    WHEN mat.id IS NULL THEN 'Estudiante no matriculado en este curso'
                    WHEN mr.id IS NOT NULL AND mr.estado != 'activo' THEN 'Recursado inactivo/eliminado'
                    WHEN mr.id IS NULL AND c.tipo_cursada = 'R' THEN 'Marcado como recursado pero sin registro'
                    ELSE 'Motivo desconocido'
                END as motivo_huerfana
         FROM calificaciones c
         JOIN usuarios u ON c.estudiante_id = u.id
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cu ON mp.curso_id = cu.id
         LEFT JOIN matriculas mat ON u.id = mat.estudiante_id 
                                   AND mat.curso_id = cu.id 
                                   AND mat.estado = 'activo'
         LEFT JOIN materias_recursado mr ON c.estudiante_id = mr.estudiante_id 
                                           AND c.materia_curso_id = mr.materia_curso_id
                                           AND c.ciclo_lectivo_id = mr.ciclo_lectivo_id
         WHERE c.ciclo_lectivo_id = ?
         AND (
             -- Calificación tipo R sin recursado activo
             (c.tipo_cursada = 'R' AND (mr.id IS NULL OR mr.estado != 'activo'))
             OR
             -- Estudiante no matriculado en el curso de la materia
             (mat.id IS NULL)
         )
         ORDER BY u.apellido, u.nombre, m.nombre",
        [$cicloLectivoId]
    );
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Detector de Calificaciones Huérfanas
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>¿Qué son las calificaciones huérfanas?</strong><br>
                        Son calificaciones que quedaron en el sistema en situaciones inconsistentes:
                        <ul class="mb-0 mt-2">
                            <li>Marcadas como "recursado" (R) pero sin un recursado activo registrado</li>
                            <li>Estudiantes que no están matriculados en el curso de la materia</li>
                            <li>Calificaciones de recursados que fueron eliminados incorrectamente</li>
                        </ul>
                    </div>
                    
                    <?php if (!empty($calificacionesHuerfanas)): ?>
                        <div class="alert alert-warning">
                            <strong>Se encontraron <?= count($calificacionesHuerfanas) ?> calificaciones huérfanas</strong>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Materia</th>
                                        <th>Curso</th>
                                        <th>Tipo</th>
                                        <th>Motivo</th>
                                        <th>Calificaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($calificacionesHuerfanas as $cal): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($cal['apellido']) ?>, <?= htmlspecialchars($cal['nombre']) ?></strong>
                                            <br><small class="text-muted">DNI: <?= htmlspecialchars($cal['dni']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($cal['materia_nombre']) ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($cal['codigo']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($cal['curso_nombre']) ?>
                                            <br><small class="text-muted"><?= $cal['anio'] ?>° año</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $cal['tipo_cursada'] == 'R' ? 'warning' : 'primary' ?>">
                                                <?= $cal['tipo_cursada'] ?? 'C' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-danger"><?= htmlspecialchars($cal['motivo_huerfana']) ?></small>
                                        </td>
                                        <td>
                                            <small>
                                                1°C: <?= $cal['calificacion_1c'] ?? '-' ?> | 
                                                2°C: <?= $cal['calificacion_2c'] ?? '-' ?> | 
                                                Final: <?= $cal['calificacion_final'] ?? '-' ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-info" 
                                                        onclick="verDetalles(<?= $cal['calificacion_id'] ?>)"
                                                        title="Ver detalles">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="eliminarCalificacion(<?= $cal['calificacion_id'] ?>, '<?= addslashes($cal['apellido'] . ', ' . $cal['nombre']) ?>', '<?= addslashes($cal['materia_nombre']) ?>')"
                                                        title="Eliminar calificación">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i>
                            <strong>¡Excelente!</strong> No se detectaron calificaciones huérfanas en el sistema.
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-4">
                        <a href="gestionar_recursados.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver a Gestión de Recursados
                        </a>
                        <button type="button" class="btn btn-primary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Actualizar Análisis
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulario oculto para eliminar -->
<form id="formEliminarCalificacion" method="POST" style="display: none;">
    <input type="hidden" name="limpiar_calificacion" value="1">
    <input type="hidden" name="calificacion_id" id="calificacion_id_eliminar">
</form>

<script>
function eliminarCalificacion(calificacionId, estudiante, materia) {
    if (confirm(`¿Eliminar la calificación huérfana?\n\nEstudiante: ${estudiante}\nMateria: ${materia}\n\nEsta acción no se puede deshacer.`)) {
        document.getElementById('calificacion_id_eliminar').value = calificacionId;
        document.getElementById('formEliminarCalificacion').submit();
    }
}

function verDetalles(calificacionId) {
    // Implementar modal con detalles si es necesario
    alert('Función de detalles - ID: ' + calificacionId);
}
</script>

<?php require_once 'footer.php'; ?>