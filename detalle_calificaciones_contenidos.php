<?php
/**
 * detalle_calificaciones_contenidos.php - Ver y editar calificaciones de contenidos por estudiante
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';
// require_once 'sistema_calculo_automatico.php'; // ELIMINADO: Ya no se necesita

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar parámetros
if (!isset($_GET['estudiante']) || !isset($_GET['materia'])) {
    $_SESSION['message'] = 'Parámetros incorrectos';
    $_SESSION['message_type'] = 'danger';
    header('Location: calificaciones.php');
    exit;
}

$estudianteId = intval($_GET['estudiante']);
$materiaCursoId = intval($_GET['materia']);
$origen = $_GET['origen'] ?? 'calificaciones.php';

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Verificar permisos
$tienePermiso = false;
if ($_SESSION['user_type'] == 'admin' || $_SESSION['user_type'] == 'directivo') {
    $tienePermiso = true;
} elseif ($_SESSION['user_type'] == 'profesor') {
    // Verificar si es profesor principal, secundario o terciario de la materia
    $esProfesor = $db->fetchOne(
        "SELECT id FROM materias_por_curso 
         WHERE id = ? AND (profesor_id = ? OR profesor_id_2 = ? OR profesor_id_3 = ?)",
        [$materiaCursoId, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
    );
    $tienePermiso = ($esProfesor !== false);
}

if (!$tienePermiso) {
    $_SESSION['message'] = 'No tiene permisos para ver esta información';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $origen);
    exit;
}

// Procesar actualización de calificaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_calificaciones'])) {
    try {
        $db->transaction(function($db) use ($estudianteId, $materiaCursoId, $cicloLectivoId) {
            $calificaciones = $_POST['calificaciones'] ?? [];
            
            foreach ($calificaciones as $contenidoId => $datos) {
                $contenidoId = intval($contenidoId);
                
                // Obtener información del contenido
                $contenido = $db->fetchOne(
                    "SELECT tipo_evaluacion FROM contenidos WHERE id = ? AND activo = 1",
                    [$contenidoId]
                );
                
                if (!$contenido) continue;
                
                $calificacionNumerica = null;
                $calificacionCualitativa = null;
                
                if ($contenido['tipo_evaluacion'] == 'numerica') {
                    $calificacionNumerica = !empty($datos['calificacion']) ? floatval($datos['calificacion']) : null;
                } else {
                    $calificacionCualitativa = !empty($datos['calificacion']) ? $datos['calificacion'] : null;
                }
                
                $observaciones = trim($datos['observaciones'] ?? '');
                
                // Verificar si existe calificación
                $existe = $db->fetchOne(
                    "SELECT id FROM contenidos_calificaciones WHERE contenido_id = ? AND estudiante_id = ?",
                    [$contenidoId, $estudianteId]
                );
                
                if ($existe) {
                    // Actualizar
                    if ($calificacionNumerica !== null || $calificacionCualitativa !== null) {
                        $db->query(
                            "UPDATE contenidos_calificaciones 
                             SET calificacion_numerica = ?, calificacion_cualitativa = ?, 
                                 observaciones = ?, fecha_evaluacion = CURRENT_DATE
                             WHERE contenido_id = ? AND estudiante_id = ?",
                            [$calificacionNumerica, $calificacionCualitativa, $observaciones, $contenidoId, $estudianteId]
                        );
                    } else {
                        // Si no hay calificación, eliminar el registro
                        $db->query(
                            "DELETE FROM contenidos_calificaciones WHERE contenido_id = ? AND estudiante_id = ?",
                            [$contenidoId, $estudianteId]
                        );
                    }
                } else if ($calificacionNumerica !== null || $calificacionCualitativa !== null) {
                    // Insertar solo si hay calificación
                    $db->insert(
                        "INSERT INTO contenidos_calificaciones 
                         (contenido_id, estudiante_id, calificacion_numerica, calificacion_cualitativa, observaciones)
                         VALUES (?, ?, ?, ?, ?)",
                        [$contenidoId, $estudianteId, $calificacionNumerica, $calificacionCualitativa, $observaciones]
                    );
                }
            }
        });
        
        // ELIMINADO: Sistema de cálculo automático
        // $calculador = new CalculadorCalificaciones();
        // $calculador->actualizarCalificacionesEstudiante($estudianteId, $materiaCursoId, $cicloLectivoId);
        
        $_SESSION['message'] = 'Calificaciones de contenidos guardadas exitosamente';
        $_SESSION['message_type'] = 'success';
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al actualizar calificaciones: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    // Redirigir para evitar reenvío de formulario
    header('Location: detalle_calificaciones_contenidos.php?estudiante=' . $estudianteId . '&materia=' . $materiaCursoId . '&origen=' . urlencode($origen));
    exit;
}

// Obtener información del estudiante y materia
$estudiante = $db->fetchOne(
    "SELECT u.*, c.nombre as curso_nombre
     FROM usuarios u
     JOIN matriculas mat ON u.id = mat.estudiante_id
     JOIN materias_por_curso mp ON mat.curso_id = mp.curso_id
     JOIN cursos c ON mat.curso_id = c.id
     WHERE u.id = ? AND mp.id = ?",
    [$estudianteId, $materiaCursoId]
);

$materia = $db->fetchOne(
    "SELECT m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre,
            mp.profesor_id, u.nombre as profesor_nombre, u.apellido as profesor_apellido
     FROM materias_por_curso mp
     JOIN materias m ON mp.materia_id = m.id
     JOIN cursos c ON mp.curso_id = c.id
     LEFT JOIN usuarios u ON mp.profesor_id = u.id
     WHERE mp.id = ?",
    [$materiaCursoId]
);

if (!$estudiante || !$materia) {
    $_SESSION['message'] = 'Estudiante o materia no encontrados';
    $_SESSION['message_type'] = 'danger';
    header('Location: ' . $origen);
    exit;
}

// Obtener contenidos y calificaciones por bimestre
$contenidosPorBimestre = [];
for ($bimestre = 1; $bimestre <= 4; $bimestre++) {
    $contenidosPorBimestre[$bimestre] = $db->fetchAll(
        "SELECT c.*, cc.calificacion_numerica, cc.calificacion_cualitativa, 
                cc.observaciones as cal_observaciones, cc.fecha_evaluacion
         FROM contenidos c
         LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id AND cc.estudiante_id = ?
         WHERE c.materia_curso_id = ? AND c.bimestre = ? AND c.activo = 1
         ORDER BY c.fecha_clase, c.orden",
        [$estudianteId, $materiaCursoId, $bimestre]
    );
}

// Obtener calificación principal actual
$calificacionPrincipal = $db->fetchOne(
    "SELECT * FROM calificaciones 
     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
    [$estudianteId, $materiaCursoId, $cicloLectivoId]
);

// Incluir el encabezado
require_once 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($origen) ?>">Calificaciones</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Detalle de Contenidos</li>
                </ol>
            </nav>
            
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-list-check"></i> Detalle de Calificaciones por Contenido
            </h1>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show" role="alert">
                    <?= $_SESSION['message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
            
            <!-- Información del estudiante y materia -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h6 class="m-0"><i class="bi bi-person"></i> Información del Estudiante</h6>
                        </div>
                        <div class="card-body">
                            <p><strong>Nombre:</strong> <?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?></p>
                            <p><strong>DNI:</strong> <?= htmlspecialchars($estudiante['dni']) ?></p>
                            <p><strong>Curso:</strong> <?= htmlspecialchars($estudiante['curso_nombre']) ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h6 class="m-0"><i class="bi bi-book"></i> Información de la Materia</h6>
                        </div>
                        <div class="card-body">
                            <p><strong>Materia:</strong> <?= htmlspecialchars($materia['materia_nombre']) ?> (<?= htmlspecialchars($materia['codigo']) ?>)</p>
                            <p><strong>Profesor:</strong> <?= htmlspecialchars($materia['profesor_apellido'] . ', ' . $materia['profesor_nombre']) ?></p>
                            <p><strong>Curso:</strong> <?= htmlspecialchars($materia['curso_nombre']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Resumen de calificaciones actuales -->
            <?php if ($calificacionPrincipal): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h6 class="m-0"><i class="bi bi-clipboard-check"></i> Calificaciones Actuales</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>1° Cuatrimestre:</strong> 
                                <?php if ($calificacionPrincipal['valoracion_preliminar_1c']): ?>
                                    <span class="badge bg-<?= 
                                        $calificacionPrincipal['valoracion_preliminar_1c'] == 'TEA' ? 'success' : 
                                        ($calificacionPrincipal['valoracion_preliminar_1c'] == 'TEP' ? 'warning' : 'danger') 
                                    ?>">
                                        <?= $calificacionPrincipal['valoracion_preliminar_1c'] ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($calificacionPrincipal['calificacion_1c']): ?>
                                    - Nota: <strong><?= $calificacionPrincipal['calificacion_1c'] ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>2° Cuatrimestre:</strong> 
                                <?php if ($calificacionPrincipal['valoracion_preliminar_2c']): ?>
                                    <span class="badge bg-<?= 
                                        $calificacionPrincipal['valoracion_preliminar_2c'] == 'TEA' ? 'success' : 
                                        ($calificacionPrincipal['valoracion_preliminar_2c'] == 'TEP' ? 'warning' : 'danger') 
                                    ?>">
                                        <?= $calificacionPrincipal['valoracion_preliminar_2c'] ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($calificacionPrincipal['calificacion_2c']): ?>
                                    - Nota: <strong><?= $calificacionPrincipal['calificacion_2c'] ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($calificacionPrincipal['observaciones_automaticas']): ?>
                    <p class="mb-0"><small class="text-muted"><?= htmlspecialchars($calificacionPrincipal['observaciones_automaticas']) ?></small></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Información importante -->
            <div class="alert alert-warning mb-4">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Nota importante:</strong> Las calificaciones de contenidos se guardan individualmente. 
                Las calificaciones principales (cuatrimestrales) deben ser gestionadas manualmente desde la página de calificaciones principales.
            </div>
            
            <!-- Formulario de calificaciones por contenido -->
            <form method="POST" action="">
                <?php for ($cuatrimestre = 1; $cuatrimestre <= 2; $cuatrimestre++): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="m-0"><?= $cuatrimestre ?>° Cuatrimestre</h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $bimestreInicio = $cuatrimestre == 1 ? 1 : 3;
                        $bimestreFin = $cuatrimestre == 1 ? 2 : 4;
                        
                        for ($bimestre = $bimestreInicio; $bimestre <= $bimestreFin; $bimestre++):
                            $contenidos = $contenidosPorBimestre[$bimestre];
                        ?>
                        <h6 class="mb-3">
                            <?= $bimestre ?>° Bimestre
                        </h6>
                        
                        <?php if (count($contenidos) > 0): ?>
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-sm">
                                <thead>
                                    <tr>
                                        <th width="100">Fecha</th>
                                        <th>Contenido</th>
                                        <th width="150">Tipo</th>
                                        <th width="150">Calificación</th>
                                        <th>Observaciones</th>
                                        <th width="120">Última Eval.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contenidos as $contenido): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($contenido['titulo']) ?></strong>
                                            <?php if ($contenido['descripcion']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($contenido['descripcion']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $contenido['tipo_evaluacion'] == 'numerica' ? 'primary' : 'success' ?>">
                                                <?= $contenido['tipo_evaluacion'] == 'numerica' ? 'Numérica' : 'Cualitativa' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($contenido['tipo_evaluacion'] == 'numerica'): ?>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="calificaciones[<?= $contenido['id'] ?>][calificacion]"
                                                       min="1" max="10" step="0.5"
                                                       value="<?= $contenido['calificacion_numerica'] ?>"
                                                       placeholder="1-10">
                                            <?php else: ?>
                                                <select class="form-select form-select-sm" 
                                                        name="calificaciones[<?= $contenido['id'] ?>][calificacion]">
                                                    <option value="">-- Sin calificar --</option>
                                                    <option value="Acreditado" 
                                                            <?= $contenido['calificacion_cualitativa'] == 'Acreditado' ? 'selected' : '' ?>>
                                                        Acreditado
                                                    </option>
                                                    <option value="No Acreditado" 
                                                            <?= $contenido['calificacion_cualitativa'] == 'No Acreditado' ? 'selected' : '' ?>>
                                                        No Acreditado
                                                    </option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" 
                                                   name="calificaciones[<?= $contenido['id'] ?>][observaciones]"
                                                   value="<?= htmlspecialchars($contenido['cal_observaciones'] ?? '') ?>"
                                                   placeholder="Opcional">
                                        </td>
                                        <td class="text-center">
                                            <?= $contenido['fecha_evaluacion'] ? date('d/m/Y', strtotime($contenido['fecha_evaluacion'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No hay contenidos cargados para este bimestre.
                        </div>
                        <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endfor; ?>
                
                <div class="d-flex justify-content-between">
                    <a href="<?= htmlspecialchars($origen) ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver
                    </a>
                    <button type="submit" name="actualizar_calificaciones" class="btn btn-primary">
                        <i class="bi bi-save"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Validar calificaciones numéricas
document.querySelectorAll('input[type="number"]').forEach(input => {
    input.addEventListener('change', function() {
        const value = parseFloat(this.value);
        if (value < 1) this.value = 1;
        if (value > 10) this.value = 10;
    });
});
</script>

<?php require_once 'footer.php'; ?>
