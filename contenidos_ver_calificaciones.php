<?php
/**
 * contenidos_ver_calificaciones.php - Vista de calificaciones automáticas generadas
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar que el usuario sea profesor
if ($_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener parámetros
$materiaCursoId = isset($_GET['materia']) ? intval($_GET['materia']) : 0;
$profesorId = $_SESSION['user_id'];

// Verificar que la materia pertenece al profesor
if ($materiaCursoId) {
    $materiaInfo = $db->fetchOne(
        "SELECT mp.*, m.nombre as materia_nombre, c.nombre as curso_nombre
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         WHERE mp.id = ? AND mp.profesor_id = ?",
        [$materiaCursoId, $profesorId]
    );
    
    if (!$materiaInfo) {
        $_SESSION['message'] = 'No tiene permisos para ver esta materia';
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos.php');
        exit;
    }
}

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Obtener calificaciones con detalles de contenidos
$calificaciones = [];
if ($materiaCursoId && $cicloLectivoId) {
    $calificaciones = $db->fetchAll(
        "SELECT u.apellido, u.nombre, u.dni,
                cal.valoracion_1bim, cal.valoracion_3bim,
                cal.valoracion_preliminar_1c, cal.valoracion_preliminar_2c,
                cal.nota_1c, cal.nota_2c,
                cal.observaciones_automaticas
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         JOIN materias_por_curso mp ON m.curso_id = mp.curso_id
         LEFT JOIN calificaciones cal ON u.id = cal.estudiante_id 
                                      AND cal.materia_curso_id = mp.id 
                                      AND cal.ciclo_lectivo_id = ?
         WHERE mp.id = ? AND m.estado = 'activo'
         ORDER BY u.apellido, u.nombre",
        [$cicloLectivoId, $materiaCursoId]
    );
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="contenidos.php">Contenidos</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Calificaciones Automáticas</li>
                </ol>
            </nav>
            
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-calculator"></i> Calificaciones Automáticas Generadas
            </h1>
            
            <?php if ($materiaCursoId && $materiaInfo): ?>
            
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h6 class="m-0 font-weight-bold">
                        <?= htmlspecialchars($materiaInfo['materia_nombre']) ?> - 
                        <?= htmlspecialchars($materiaInfo['curso_nombre']) ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> <strong>Sistema de cálculo automático:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>TEA</strong>: Todos los contenidos con nota ≥ 7 o "Acreditado"</li>
                            <li><strong>TEP</strong>: Al menos un contenido con nota ≤ 6 o "No Acreditado"</li>
                            <li><strong>Nota cuatrimestral</strong>: Si hay TEP usa la nota más baja, si no promedia</li>
                        </ul>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th rowspan="2">Estudiante</th>
                                    <th colspan="2" class="text-center">1° Cuatrimestre</th>
                                    <th colspan="2" class="text-center">2° Cuatrimestre</th>
                                    <th rowspan="2" width="300">Observaciones</th>
                                </tr>
                                <tr>
                                    <th class="text-center">Valoración</th>
                                    <th class="text-center">Nota</th>
                                    <th class="text-center">Valoración</th>
                                    <th class="text-center">Nota</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($calificaciones as $cal): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cal['apellido'] . ', ' . $cal['nombre']) ?></td>
                                    <td class="text-center">
                                        <?php if ($cal['valoracion_preliminar_1c']): ?>
                                            <span class="badge bg-<?= 
                                                $cal['valoracion_preliminar_1c'] == 'TEA' ? 'success' : 
                                                ($cal['valoracion_preliminar_1c'] == 'TEP' ? 'warning' : 'danger') 
                                            ?>">
                                                <?= $cal['valoracion_preliminar_1c'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $cal['nota_1c'] ?? '-' ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($cal['valoracion_preliminar_2c']): ?>
                                            <span class="badge bg-<?= 
                                                $cal['valoracion_preliminar_2c'] == 'TEA' ? 'success' : 
                                                ($cal['valoracion_preliminar_2c'] == 'TEP' ? 'warning' : 'danger') 
                                            ?>">
                                                <?= $cal['valoracion_preliminar_2c'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $cal['nota_2c'] ?? '-' ?>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($cal['observaciones_automaticas'] ?? 'Sin calificaciones automáticas') ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <a href="contenidos.php?materia=<?= $materiaCursoId ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver a Contenidos
                        </a>
                        <a href="calificaciones.php?curso=<?= $materiaInfo['curso_id'] ?>&materia=<?= $materiaCursoId ?>" 
                           class="btn btn-primary">
                            <i class="bi bi-pencil"></i> Ver/Editar Calificaciones Completas
                        </a>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> Debe seleccionar una materia para ver las calificaciones automáticas.
                <br><br>
                <a href="contenidos.php" class="btn btn-primary">Ir a Contenidos</a>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>