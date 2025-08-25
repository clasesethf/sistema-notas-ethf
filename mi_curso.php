<?php
/**
 * mi_curso.php - Vista del curso para estudiantes
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar que el usuario sea estudiante
if ($_SESSION['user_type'] !== 'estudiante') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
        $cicloLectivoId = 0;
        $anioActivo = date('Y');
    } else {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al conectar con la base de datos: ' . $e->getMessage() . '</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
}

// Obtener información del estudiante y su matrícula
$estudianteId = $_SESSION['user_id'];
$matriculaInfo = null;
$materias = [];
$compañeros = [];

try {
    // Obtener matrícula activa del estudiante
    $matriculaInfo = $db->fetchOne(
        "SELECT m.*, c.nombre as curso_nombre, c.anio as curso_anio, c.id as curso_id
         FROM matriculas m 
         JOIN cursos c ON m.curso_id = c.id 
         WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?",
        [$estudianteId, $cicloLectivoId]
    );
    
    if ($matriculaInfo) {
        $cursoId = $matriculaInfo['curso_id'];
        
        // Obtener materias del curso con sus profesores
        $materias = $db->fetchAll(
            "SELECT m.nombre as materia_nombre, m.codigo, 
                    COALESCE(u.nombre || ' ' || u.apellido, 'Sin asignar') as profesor_nombre,
                    u.telefono as profesor_telefono
             FROM materias_por_curso mp 
             JOIN materias m ON mp.materia_id = m.id 
             LEFT JOIN usuarios u ON mp.profesor_id = u.id AND u.tipo = 'profesor'
             WHERE mp.curso_id = ? 
             ORDER BY m.nombre",
            [$cursoId]
        );
        
        // Obtener compañeros de curso
        $compañeros = $db->fetchAll(
            "SELECT u.nombre, u.apellido, u.telefono
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.id != ?
             ORDER BY u.apellido, u.nombre",
            [$cursoId, $estudianteId]
        );
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener información del curso: ' . $e->getMessage() . '</div>';
}
?>

<div class="container-fluid mt-4">
    <?php if ($matriculaInfo): ?>
    <!-- Información del curso -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-book"></i> Mi Curso - Ciclo Lectivo <?= $anioActivo ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="bi bi-info-circle"></i> Información del Curso</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Curso:</strong></td>
                                    <td><?= htmlspecialchars($matriculaInfo['curso_nombre']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Año:</strong></td>
                                    <td><?= htmlspecialchars($matriculaInfo['curso_anio']) ?>° año</td>
                                </tr>
                                <tr>
                                    <td><strong>Fecha de matrícula:</strong></td>
                                    <td><?= date('d/m/Y', strtotime($matriculaInfo['fecha_matriculacion'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Estado:</strong></td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?= ucfirst($matriculaInfo['estado']) ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="bi bi-graph-up"></i> Estadísticas del Curso</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Total de materias:</strong></td>
                                    <td><?= count($materias) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Compañeros de curso:</strong></td>
                                    <td><?= count($compañeros) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Ciclo lectivo:</strong></td>
                                    <td><?= $anioActivo ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Materias del curso -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-journal-text"></i> Materias del Curso
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($materias) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Materia</th>
                                    <th>Código</th>
                                    <th>Profesor/a</th>
                                    <th>Contacto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materias as $materia): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= htmlspecialchars($materia['codigo']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($materia['profesor_nombre']) ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($materia['profesor_telefono'])): ?>
                                            <i class="bi bi-telephone"></i> 
                                            <?= htmlspecialchars($materia['profesor_telefono']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">No disponible</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No hay materias asignadas a este curso aún.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Compañeros de curso -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-people"></i> Compañeros de Curso
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($compañeros) > 0): ?>
                    <div class="row">
                        <?php foreach ($compañeros as $compañero): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="bi bi-person"></i>
                                        <?= htmlspecialchars($compañero['apellido']) ?>, 
                                        <?= htmlspecialchars($compañero['nombre']) ?>
                                    </h6>
                                    <?php if (!empty($compañero['telefono'])): ?>
                                    <p class="card-text">
                                        <i class="bi bi-telephone"></i> 
                                        <?= htmlspecialchars($compañero['telefono']) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        No hay otros estudiantes matriculados en este curso.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Enlaces rápidos -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-lightning"></i> Accesos Rápidos
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <a href="mis_calificaciones.php" class="btn btn-primary w-100 py-3">
                                <i class="bi bi-journal-check mb-2 d-block fs-3"></i>
                                Ver Mis Calificaciones
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="reportes.php" class="btn btn-info w-100 py-3 text-white">
                                <i class="bi bi-bar-chart mb-2 d-block fs-3"></i>
                                Ver Reportes
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="index.php" class="btn btn-secondary w-100 py-3">
                                <i class="bi bi-house mb-2 d-block fs-3"></i>
                                Volver al Inicio
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- No matriculado -->
    <div class="row">
        <div class="col-md-12">
            <div class="alert alert-warning">
                <h4 class="alert-heading">
                    <i class="bi bi-exclamation-triangle"></i> Sin matrícula activa
                </h4>
                <p>No se encontró una matrícula activa para el ciclo lectivo <?= $anioActivo ?>.</p>
                <hr>
                <p class="mb-0">
                    Por favor, contacte con la administración de la escuela para resolver esta situación.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>