<?php
/**
 * mis_calificaciones.php - Vista de calificaciones para estudiantes
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
        
        // Determinar cuatrimestre actual
        $fechaActual = new DateTime();
        $fechaInicio = new DateTime($cicloActivo['fecha_inicio']);
        $fechaMitad = clone $fechaInicio;
        $fechaMitad->modify('+3 months');
        $cuatrimestreActual = ($fechaActual > $fechaMitad) ? 2 : 1;
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al conectar con la base de datos: ' . $e->getMessage() . '</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
    $cuatrimestreActual = 1;
}

// Obtener información del estudiante
$estudianteId = $_SESSION['user_id'];
$estudianteInfo = null;
$calificaciones = [];
$materiasPendientes = [];
$estadisticas = [];

try {
    // Obtener información del estudiante y su curso
    $estudianteInfo = $db->fetchOne(
        "SELECT u.nombre, u.apellido, u.dni, c.nombre as curso_nombre, c.anio as curso_anio
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         JOIN cursos c ON m.curso_id = c.id 
         WHERE u.id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?",
        [$estudianteId, $cicloLectivoId]
    );
    
    if ($estudianteInfo) {
        // Obtener calificaciones del estudiante
        $calificaciones = $db->fetchAll(
            "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                    COALESCE(u.nombre || ' ' || u.apellido, 'Sin asignar') as profesor_nombre
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             LEFT JOIN usuarios u ON mp.profesor_id = u.id
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        // Obtener materias pendientes de intensificación
        $materiasPendientes = $db->fetchAll(
            "SELECT i.*, m.nombre as materia_nombre, m.codigo as materia_codigo
             FROM intensificaciones i
             JOIN materias m ON i.materia_id = m.id
             WHERE i.estudiante_id = ? AND i.ciclo_lectivo_id = ?
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        // Calcular estadísticas
        $totalMaterias = count($calificaciones);
        $materiasAprobadas = 0;
        $materiasPendientesCount = 0;
        $sumaCalificaciones = 0;
        $materiasConCalificacionFinal = 0;
        
        foreach ($calificaciones as $calificacion) {
            if (!empty($calificacion['calificacion_final']) && is_numeric($calificacion['calificacion_final'])) {
                $materiasConCalificacionFinal++;
                $sumaCalificaciones += $calificacion['calificacion_final'];
                
                if ($calificacion['calificacion_final'] >= 4) {
                    $materiasAprobadas++;
                } else {
                    $materiasPendientesCount++;
                }
            }
        }
        
        $estadisticas = [
            'total_materias' => $totalMaterias,
            'materias_aprobadas' => $materiasAprobadas,
            'materias_pendientes' => $materiasPendientesCount,
            'materias_en_intensificacion' => count($materiasPendientes),
            'promedio_general' => $materiasConCalificacionFinal > 0 ? round($sumaCalificaciones / $materiasConCalificacionFinal, 2) : 0
        ];
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener calificaciones: ' . $e->getMessage() . '</div>';
}
?>

<div class="container-fluid mt-4">
    <?php if ($estudianteInfo): ?>
    <!-- Información del estudiante -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-person-badge"></i> Mis Calificaciones - Ciclo Lectivo <?= $anioActivo ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="bi bi-info-circle"></i> Información Personal</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Estudiante:</strong></td>
                                    <td><?= htmlspecialchars($estudianteInfo['apellido']) ?>, <?= htmlspecialchars($estudianteInfo['nombre']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>DNI:</strong></td>
                                    <td><?= htmlspecialchars($estudianteInfo['dni']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Curso:</strong></td>
                                    <td><?= htmlspecialchars($estudianteInfo['curso_nombre']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Cuatrimestre actual:</strong></td>
                                    <td><?= $cuatrimestreActual ?>° cuatrimestre</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="bi bi-graph-up"></i> Resumen Académico</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Total de materias:</strong></td>
                                    <td><?= $estadisticas['total_materias'] ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Materias aprobadas:</strong></td>
                                    <td>
                                        <span class="badge bg-success"><?= $estadisticas['materias_aprobadas'] ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Materias pendientes:</strong></td>
                                    <td>
                                        <span class="badge bg-warning"><?= $estadisticas['materias_pendientes'] ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Promedio general:</strong></td>
                                    <td>
                                        <?php if ($estadisticas['promedio_general'] > 0): ?>
                                            <span class="badge bg-<?= $estadisticas['promedio_general'] >= 7 ? 'success' : ($estadisticas['promedio_general'] >= 4 ? 'warning' : 'danger') ?>">
                                                <?= $estadisticas['promedio_general'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Sin calificaciones</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Calificaciones por materia -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-journal-check"></i> Calificaciones por Materia
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($calificaciones) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Materia</th>
                                    <th>Profesor/a</th>
                                    <th class="text-center">1° Cuatrimestre</th>
                                    <th class="text-center">2° Cuatrimestre</th>
                                    <th class="text-center">Intensificación</th>
                                    <th class="text-center">Calificación Final</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($calificaciones as $calificacion): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($calificacion['materia_nombre']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($calificacion['materia_codigo']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($calificacion['profesor_nombre']) ?></td>
                                    <td class="text-center">
                                        <div>
                                            <?php if (!empty($calificacion['valoracion_preliminar_1c'])): ?>
                                                <span class="badge bg-info"><?= $calificacion['valoracion_preliminar_1c'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($calificacion['calificacion_1c'])): ?>
                                            <strong><?= $calificacion['calificacion_1c'] ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div>
                                            <?php if (!empty($calificacion['valoracion_preliminar_2c'])): ?>
                                                <span class="badge bg-info"><?= $calificacion['valoracion_preliminar_2c'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($calificacion['calificacion_2c'])): ?>
                                            <strong><?= $calificacion['calificacion_2c'] ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($calificacion['intensificacion_1c'])): ?>
                                            <?= $calificacion['intensificacion_1c'] ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($calificacion['calificacion_final'])): ?>
                                            <span class="badge bg-<?= $calificacion['calificacion_final'] >= 7 ? 'success' : ($calificacion['calificacion_final'] >= 4 ? 'warning' : 'danger') ?> fs-6">
                                                <?= $calificacion['calificacion_final'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($calificacion['calificacion_final'])): ?>
                                            <?php if ($calificacion['calificacion_final'] >= 4): ?>
                                                <span class="badge bg-success">Aprobada</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Desaprobada</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">En curso</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        No hay calificaciones registradas aún para este ciclo lectivo.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Materias en intensificación -->
    <?php if (count($materiasPendientes) > 0): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-arrow-repeat"></i> Materias en Intensificación
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Materia</th>
                                    <th>Año Cursada</th>
                                    <th class="text-center">Marzo</th>
                                    <th class="text-center">Julio</th>
                                    <th class="text-center">Agosto</th>
                                    <th class="text-center">Diciembre</th>
                                    <th class="text-center">Febrero</th>
                                    <th class="text-center">Estado Final</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materiasPendientes as $materia): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($materia['materia_codigo']) ?></small>
                                    </td>
                                    <td><?= $materia['ciclo_lectivo_cursada_id'] ?? 'N/A' ?></td>
                                    <td class="text-center">
                                        <?php if (!empty($materia['estado_marzo'])): ?>
                                            <span class="badge bg-<?= $materia['estado_marzo'] == 'AA' ? 'success' : 'warning' ?>">
                                                <?= $materia['estado_marzo'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($materia['estado_julio'])): ?>
                                            <span class="badge bg-<?= $materia['estado_julio'] == 'AA' ? 'success' : 'warning' ?>">
                                                <?= $materia['estado_julio'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($materia['estado_agosto'])): ?>
                                            <span class="badge bg-<?= $materia['estado_agosto'] == 'AA' ? 'success' : 'warning' ?>">
                                                <?= $materia['estado_agosto'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($materia['estado_diciembre'])): ?>
                                            <span class="badge bg-<?= $materia['estado_diciembre'] == 'AA' ? 'success' : 'warning' ?>">
                                                <?= $materia['estado_diciembre'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($materia['estado_febrero'])): ?>
                                            <span class="badge bg-<?= $materia['estado_febrero'] == 'AA' ? 'success' : 'warning' ?>">
                                                <?= $materia['estado_febrero'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($materia['calificacion_final']) && $materia['calificacion_final'] >= 4): ?>
                                            <span class="badge bg-success">Aprobada (<?= $materia['calificacion_final'] ?>)</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">En proceso</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Referencias -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-info-circle"></i> Referencias
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6>Valoraciones Preliminares:</h6>
                            <ul class="list-unstyled">
                                <li><span class="badge bg-info">TEA</span> Trayectoria Educativa Avanzada</li>
                                <li><span class="badge bg-info">TEP</span> Trayectoria Educativa en Proceso</li>
                                <li><span class="badge bg-info">TED</span> Trayectoria Educativa Discontinua</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6>Estados de Intensificación:</h6>
                            <ul class="list-unstyled">
                                <li><span class="badge bg-success">AA</span> Aprobó y Acreditó</li>
                                <li><span class="badge bg-warning">CCA</span> Continúa, Con Avances</li>
                                <li><span class="badge bg-warning">CSA</span> Continúa, Sin Avances</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6>Escala de Calificaciones:</h6>
                            <ul class="list-unstyled">
                                <li><span class="badge bg-success">7-10</span> Muy Bueno/Excelente</li>
                                <li><span class="badge bg-warning">4-6</span> Aprobado</li>
                                <li><span class="badge bg-danger">1-3</span> Desaprobado</li>
                            </ul>
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