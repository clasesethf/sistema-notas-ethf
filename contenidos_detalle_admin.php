<?php
/**
 * contenidos_detalle_admin.php - Vista detallada de contenido para administradores
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar que el usuario sea admin o directivo
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetro
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['message'] = 'Contenido no especificado';
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos_admin.php');
    exit;
}

$contenidoId = intval($_GET['id']);

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener información completa del contenido
try {
    $contenido = $db->fetchOne(
        "SELECT c.*, 
                m.nombre as materia_nombre, m.codigo as materia_codigo,
                cur.nombre as curso_nombre, cur.anio as curso_anio, cur.id as curso_id,
                u.nombre as profesor_nombre, u.apellido as profesor_apellido,
                u.dni as profesor_dni, u.telefono as profesor_telefono
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         JOIN usuarios u ON c.profesor_id = u.id
         WHERE c.id = ?",
        [$contenidoId]
    );
    
    if (!$contenido) {
        $_SESSION['message'] = 'Contenido no encontrado';
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos_admin.php');
        exit;
    }
    
    // Obtener estadísticas de calificaciones - Consulta corregida
    $totalEstudiantes = $db->fetchOne(
        "SELECT COUNT(*) as total 
         FROM matriculas m
         JOIN materias_por_curso mp ON m.curso_id = mp.curso_id
         WHERE mp.id = ? AND m.estado = 'activo'",
        [$contenido['materia_curso_id']]
    )['total'] ?? 0;
    
    $calificacionesStats = $db->fetchOne(
        "SELECT 
            COUNT(DISTINCT cc.estudiante_id) as total_calificados,
            AVG(cc.calificacion_numerica) as promedio_numerico,
            SUM(CASE WHEN cc.calificacion_cualitativa = 'Acreditado' THEN 1 ELSE 0 END) as total_acreditados,
            SUM(CASE WHEN cc.calificacion_cualitativa = 'No Acreditado' THEN 1 ELSE 0 END) as total_no_acreditados
         FROM contenidos_calificaciones cc
         WHERE cc.contenido_id = ?",
        [$contenidoId]
    );
    
    $estadisticas = [
        'total_estudiantes' => $totalEstudiantes,
        'total_calificados' => $calificacionesStats['total_calificados'] ?? 0,
        'promedio_numerico' => $calificacionesStats['promedio_numerico'],
        'total_acreditados' => $calificacionesStats['total_acreditados'] ?? 0,
        'total_no_acreditados' => $calificacionesStats['total_no_acreditados'] ?? 0
    ];
    
    // Obtener lista de calificaciones
    $calificaciones = $db->fetchAll(
        "SELECT u.nombre, u.apellido, u.dni,
                cc.calificacion_numerica, cc.calificacion_cualitativa,
                cc.observaciones, cc.fecha_evaluacion
         FROM contenidos_calificaciones cc
         JOIN usuarios u ON cc.estudiante_id = u.id
         WHERE cc.contenido_id = ?
         ORDER BY u.apellido, u.nombre",
        [$contenidoId]
    );
    
    // Calcular porcentajes
    $porcentajeCalificado = $estadisticas['total_estudiantes'] > 0 ? 
                           round(($estadisticas['total_calificados'] / $estadisticas['total_estudiantes']) * 100) : 0;
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos_admin.php');
    exit;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="contenidos_admin.php">Contenidos</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Detalle del Contenido</li>
                </ol>
            </nav>
            
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-file-text"></i> Detalle del Contenido
            </h1>
            
            <!-- Información del contenido -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h6 class="m-0 font-weight-bold">Información del Contenido</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Título:</strong> <?= htmlspecialchars($contenido['titulo']) ?></p>
                                    <p><strong>Materia:</strong> <?= htmlspecialchars($contenido['materia_nombre']) ?> (<?= htmlspecialchars($contenido['materia_codigo']) ?>)</p>
                                    <p><strong>Curso:</strong> <?= htmlspecialchars($contenido['curso_nombre']) ?> (<?= $contenido['curso_anio'] ?>° año)</p>
                                    <p><strong>Bimestre:</strong> <?= $contenido['bimestre'] ?>°</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Fecha de clase:</strong> <?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?></p>
                                    <p><strong>Tipo de evaluación:</strong> 
                                        <span class="badge bg-<?= $contenido['tipo_evaluacion'] == 'numerica' ? 'primary' : 'success' ?>">
                                            <?= $contenido['tipo_evaluacion'] == 'numerica' ? 'Numérica' : 'Cualitativa' ?>
                                        </span>
                                    </p>
                                    <p><strong>Estado:</strong> 
                                        <span class="badge bg-<?= $contenido['activo'] ? 'success' : 'danger' ?>">
                                            <?= $contenido['activo'] ? 'Activo' : 'Inactivo' ?>
                                        </span>
                                    </p>
                                    <p><strong>Creado:</strong> <?= date('d/m/Y H:i', strtotime($contenido['created_at'])) ?></p>
                                </div>
                            </div>
                            
                            <?php if (!empty($contenido['descripcion'])): ?>
                            <hr>
                            <p><strong>Descripción:</strong><br><?= nl2br(htmlspecialchars($contenido['descripcion'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Información del profesor -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Información del Profesor</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Nombre:</strong> <?= htmlspecialchars($contenido['profesor_apellido'] . ', ' . $contenido['profesor_nombre']) ?></p>
                                    
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Estadísticas -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h6 class="m-0 font-weight-bold">Estadísticas de Calificación</h6>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <h2 class="mb-0"><?= $estadisticas['total_calificados'] ?>/<?= $estadisticas['total_estudiantes'] ?></h2>
                                <p class="text-muted">Estudiantes calificados</p>
                                
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar <?= $porcentajeCalificado == 100 ? 'bg-success' : 'bg-warning' ?>" 
                                         role="progressbar" style="width: <?= $porcentajeCalificado ?>%">
                                        <?= $porcentajeCalificado ?>%
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($contenido['tipo_evaluacion'] == 'numerica' && $estadisticas['promedio_numerico']): ?>
                            <hr>
                            <p><strong>Promedio general:</strong> 
                                <span class="badge bg-primary fs-6"><?= number_format($estadisticas['promedio_numerico'], 2) ?></span>
                            </p>
                            <?php elseif ($contenido['tipo_evaluacion'] == 'cualitativa'): ?>
                            <hr>
                            <p><strong>Acreditados:</strong> 
                                <span class="badge bg-success"><?= $estadisticas['total_acreditados'] ?></span>
                            </p>
                            <p><strong>No acreditados:</strong> 
                                <span class="badge bg-danger"><?= $estadisticas['total_no_acreditados'] ?></span>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Acciones -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">Acciones</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="contenidos_admin.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </a>
                                <?php if ($_SESSION['user_type'] == 'admin'): ?>
                                <button type="button" class="btn btn-warning" onclick="exportarCalificaciones()">
                                    <i class="bi bi-download"></i> Exportar Calificaciones
                                </button>
                                <?php if (!$contenido['activo']): ?>
                                <button type="button" class="btn btn-success" onclick="reactivarContenido()">
                                    <i class="bi bi-arrow-clockwise"></i> Reactivar Contenido
                                </button>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabla de calificaciones -->
            <?php if (count($calificaciones) > 0): ?>
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Calificaciones Registradas
                        <span class="badge bg-secondary float-end"><?= count($calificaciones) ?> registros</span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>N°</th>
                                    <th>Estudiante</th>
                                    <th>Matr.</th>
                                    <th>Calificación</th>
                                    <th>Observaciones</th>
                                    <th>Fecha Evaluación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $contador = 1;
                                foreach ($calificaciones as $cal): 
                                ?>
                                <tr>
                                    <td class="text-center"><?= $contador++ ?></td>
                                    <td><?= htmlspecialchars($cal['apellido'] . ', ' . $cal['nombre']) ?></td>
                                    <td><?= htmlspecialchars($cal['dni']) ?></td>
                                    <td class="text-center">
                                        <?php if ($contenido['tipo_evaluacion'] == 'numerica'): ?>
                                            <span class="badge bg-<?= $cal['calificacion_numerica'] >= 7 ? 'success' : ($cal['calificacion_numerica'] >= 4 ? 'warning' : 'danger') ?> fs-6">
                                                <?= $cal['calificacion_numerica'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-<?= $cal['calificacion_cualitativa'] == 'Acreditado' ? 'success' : 'danger' ?>">
                                                <?= $cal['calificacion_cualitativa'] ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($cal['observaciones'] ?? '-') ?></td>
                                    <td><?= date('d/m/Y', strtotime($cal['fecha_evaluacion'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No hay calificaciones registradas para este contenido.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function exportarCalificaciones() {
    if (confirm('¿Desea exportar las calificaciones de este contenido?')) {
        window.location.href = 'exportar_calificaciones_contenido.php?id=<?= $contenidoId ?>';
    }
}

function reactivarContenido() {
    if (confirm('¿Está seguro de que desea reactivar este contenido?')) {
        // Crear formulario para enviar la solicitud
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'contenidos_admin_acciones.php';
        
        const accionInput = document.createElement('input');
        accionInput.type = 'hidden';
        accionInput.name = 'accion';
        accionInput.value = 'reactivar';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'contenido_id';
        idInput.value = '<?= $contenidoId ?>';
        
        form.appendChild(accionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once 'footer.php'; ?>