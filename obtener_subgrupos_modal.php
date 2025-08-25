<?php
/**
 * obtener_subgrupos_modal.php
 * Endpoint para cargar contenido del modal de subgrupos
 */

require_once 'config.php';

// Verificar permisos
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">No tiene permisos para acceder a esta sección</div>';
    exit;
}

$db = Database::getInstance();
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;

if (!$materiaSeleccionada) {
    echo '<div class="alert alert-danger">Materia no especificada</div>';
    exit;
}

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Obtener información de la materia
$materiaInfo = $db->fetchOne(
    "SELECT mp.*, m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre
     FROM materias_por_curso mp
     JOIN materias m ON mp.materia_id = m.id
     JOIN cursos c ON mp.curso_id = c.id
     WHERE mp.id = ?",
    [$materiaSeleccionada]
);

if (!$materiaInfo) {
    echo '<div class="alert alert-danger">Materia no encontrada</div>';
    exit;
}

// Obtener configuración de subgrupos
$configSubgrupo = $db->fetchOne(
    "SELECT * FROM configuracion_subgrupos 
     WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
    [$materiaSeleccionada, $cicloLectivoId]
);

// Obtener estudiantes del curso
$infoCurso = $db->fetchOne(
    "SELECT c.id as curso_id, c.nombre as curso_nombre
     FROM materias_por_curso mp
     JOIN cursos c ON mp.curso_id = c.id
     WHERE mp.id = ?",
    [$materiaSeleccionada]
);

$estudiantesDelCurso = [];
$estudiantesAsignados = [];

if ($infoCurso) {
    $estudiantesDelCurso = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.activo = 1
         ORDER BY u.apellido, u.nombre",
        [$infoCurso['curso_id']]
    );
    
    // Obtener estudiantes asignados con información de curso
    $estudiantesAsignados = $db->fetchAll(
        "SELECT ep.*, u.nombre, u.apellido, u.dni,
                c.nombre as curso_estudiante, c.id as curso_estudiante_id,
                c2.nombre as curso_materia, c2.id as curso_materia_id
         FROM estudiantes_por_materia ep
         JOIN usuarios u ON ep.estudiante_id = u.id
         JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
         JOIN cursos c ON m.curso_id = c.id
         JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
         JOIN cursos c2 ON mp.curso_id = c2.id
         WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
         ORDER BY ep.periodo_inicio, ep.subgrupo, u.apellido, u.nombre",
        [$materiaSeleccionada, $cicloLectivoId]
    );
}

$esCiudadania = stripos($materiaInfo['materia_nombre'], 'ciudadania') !== false;
$esTaller = preg_match('/^\d/', $materiaInfo['codigo']) || stripos($materiaInfo['materia_nombre'], 'taller') !== false;

// Detectar problemas de curso
$hayProblemasCurso = false;
$estudiantesProblematicos = [];
foreach ($estudiantesAsignados as $asignado) {
    if ($asignado['curso_estudiante_id'] !== $asignado['curso_materia_id']) {
        $hayProblemasCurso = true;
        $estudiantesProblematicos[] = $asignado;
    }
}
?>

<!-- Información de la materia con datos para JavaScript -->
<div data-materia-nombre="<?= htmlspecialchars($materiaInfo['materia_nombre']) ?>" style="display: none;"></div>

<div class="container-fluid">
    <!-- Información de configuración -->
    <?php if ($configSubgrupo): ?>
    <div class="alert alert-info">
        <strong>Configuración actual:</strong>
        <?= ucfirst($configSubgrupo['tipo_division']) ?> en <?= $configSubgrupo['cantidad_grupos'] ?> grupos
        <?php if ($configSubgrupo['rotacion_automatica']): ?>
        - <strong>Rotación automática <?= $configSubgrupo['periodo_rotacion'] ?></strong>
        <?php else: ?>
        - <strong>Grupos fijos (sin rotación)</strong>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- División automática para Construcción de la Ciudadanía -->
    <?php if ($esCiudadania): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card bg-info text-white">
                <div class="card-header">
                    <h6 class="card-title mb-0">División Automática - Construcción de la Ciudadanía</h6>
                </div>
                <div class="card-body">
                    <p>Esta materia se divide en tercios fijos. Cada tercio del curso permanece con el mismo profesor durante todo el ciclo lectivo.</p>
                    <button type="button" class="btn btn-light" onclick="ejecutarDivisionCiudadaniaModal(<?= $materiaSeleccionada ?>)">
                        <i class="bi bi-people"></i> Dividir Automáticamente en Tercios
                    </button>
                    <small class="d-block mt-2">
                        <strong>Resultado:</strong> Grupo 1 (Profesor A), Grupo 2 (Profesor B), Grupo 3 (Profesor C)
                    </small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Rotación automática para materias de taller -->
    <?php if ($esTaller && $configSubgrupo && $configSubgrupo['rotacion_automatica']): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card bg-warning">
                <div class="card-header">
                    <h6 class="card-title mb-0">Rotación Automática - Materia de Taller</h6>
                </div>
                <div class="card-body">
                    <p>Esta materia tiene configurada rotación automática trimestral.</p>
                    <button type="button" class="btn btn-dark" onclick="ejecutarRotacionModal(<?= $materiaSeleccionada ?>)">
                        <i class="bi bi-arrow-repeat"></i> Ejecutar Rotación Trimestral
                    </button>
                    <small class="d-block mt-2">
                        <strong>Resultado:</strong> Taller 1, Taller 2, Taller 3 - Los estudiantes rotan cada trimestre
                    </small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Asignación manual y vista de asignaciones actuales -->
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title">Estudiantes del Curso (<?= count($estudiantesDelCurso) ?>)</h6>
                </div>
                <div class="card-body">
                    <form id="formAsignarEstudiantesModal">
                        <div class="mb-3">
                            <label for="subgrupo_modal" class="form-label">Nombre del Subgrupo:</label>
                            <input type="text" name="subgrupo" id="subgrupo_modal" class="form-control" 
                                   placeholder="<?= $esCiudadania ? 'ej: Grupo 1 - Profesor A' : 'ej: Taller 1, Taller 2' ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="periodo_inicio_modal" class="form-label">Período:</label>
                            <select name="periodo_inicio" id="periodo_inicio_modal" class="form-select" required>
                                <?php if ($esCiudadania): ?>
                                <option value="anual">Todo el Año</option>
                                <?php else: ?>
                                <option value="1trim">1er Trimestre</option>
                                <option value="2trim">2do Trimestre</option>
                                <option value="3trim">3er Trimestre</option>
                                <option value="1cuatri">1er Cuatrimestre</option>
                                <option value="2cuatri">2do Cuatrimestre</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Seleccionar Estudiantes:</label>
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="selectAllStudentsModal">
                                <label class="form-check-label" for="selectAllStudentsModal">
                                    <strong>Seleccionar todos</strong>
                                </label>
                            </div>
                            <hr>
                            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.75rem;">
                                <?php foreach ($estudiantesDelCurso as $estudiante): ?>
                                <div class="form-check mb-1">
                                    <input type="checkbox" class="form-check-input student-checkbox-modal" 
                                           name="estudiantes[]" value="<?= $estudiante['id'] ?>" 
                                           id="est_modal_<?= $estudiante['id'] ?>">
                                    <label class="form-check-label" for="est_modal_<?= $estudiante['id'] ?>">
                                        <?= htmlspecialchars($estudiante['apellido']) ?>, 
                                        <?= htmlspecialchars($estudiante['nombre']) ?>
                                        <small class="text-muted">(<?= htmlspecialchars($estudiante['dni']) ?>)</small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="button" class="btn btn-primary" onclick="asignarEstudiantesModal(<?= $materiaSeleccionada ?>)">
                                <i class="bi bi-check-circle"></i> Asignar Estudiantes
                            </button>
                            <button type="button" class="btn btn-secondary ms-2" onclick="limpiarSeleccionModal()">
                                <i class="bi bi-x-circle"></i> Limpiar Selección
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title">Asignaciones Actuales</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($estudiantesAsignados)): ?>
                        <?php
                        // Agrupar por período y subgrupo
                        $asignacionesPorPeriodo = [];
                        foreach ($estudiantesAsignados as $asignado) {
                            $periodo = $asignado['periodo_inicio'] ?? 'Sin período';
                            $subgrupo = $asignado['subgrupo'] ?? 'Sin subgrupo';
                            $asignacionesPorPeriodo[$periodo][$subgrupo][] = $asignado;
                        }
                        ?>
                        
                        <?php foreach ($asignacionesPorPeriodo as $periodo => $subgrupos): ?>
                        <div class="mb-3">
                            <h6 class="border-bottom pb-1 text-primary">
                                <i class="bi bi-calendar"></i> 
                                <?= $periodo === 'anual' ? 'Todo el Año' : ucfirst($periodo) ?>
                            </h6>
                            <?php foreach ($subgrupos as $nombreSubgrupo => $estudiantes): ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?= htmlspecialchars($nombreSubgrupo) ?></strong>
                                        <span class="badge bg-info"><?= count($estudiantes) ?> estudiantes</span>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                                onclick="eliminarSubgrupoCompletoModal(<?= $materiaSeleccionada ?>, '<?= addslashes($nombreSubgrupo) ?>', '<?= $periodo ?>')"
                                                title="Eliminar todo el subgrupo">
                                            <i class="bi bi-trash"></i> Todo
                                        </button>
                                    </div>
                                </div>
                                <ul class="list-unstyled mt-1">
                                    <?php foreach ($estudiantes as $est): ?>
                                    <?php 
                                        $esProblematico = $est['curso_estudiante_id'] !== $est['curso_materia_id'];
                                        $parametrosAsignacion = base64_encode(json_encode([
                                            'materia_curso_id' => $est['materia_curso_id'],
                                            'estudiante_id' => $est['estudiante_id'],
                                            'ciclo_lectivo_id' => $est['ciclo_lectivo_id'],
                                            'subgrupo' => $est['subgrupo'],
                                            'periodo_inicio' => $est['periodo_inicio']
                                        ]));
                                    ?>
                                    <li class="ms-3 d-flex justify-content-between align-items-center <?= $esProblematico ? 'text-danger' : '' ?>">
                                        <span>
                                            <small>• <?= htmlspecialchars($est['apellido']) ?>, <?= htmlspecialchars($est['nombre']) ?></small>
                                            <?php if ($esProblematico): ?>
                                            <span class="badge bg-danger ms-1" title="Estudiante de otro curso">⚠️</span>
                                            <?php endif; ?>
                                        </span>
                                        <button type="button" class="btn btn-outline-danger btn-sm ms-2"
                                                onclick="eliminarAsignacionIndividualModal('<?= $parametrosAsignacion ?>', <?= $materiaSeleccionada ?>)"
                                                title="Eliminar esta asignación">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            No hay estudiantes asignados a subgrupos aún.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Funciones específicas del modal

function ejecutarDivisionCiudadaniaModal(materiaId) {
    if (confirm('¿Dividir automáticamente en tercios? Esto asignará cada estudiante a un profesor para todo el año.')) {
        ejecutarAccionModal('auto_dividir_ciudadania', materiaId);
    }
}

function ejecutarRotacionModal(materiaId) {
    if (confirm('¿Ejecutar la rotación automática? Esto redistribuirá todos los estudiantes en los 3 trimestres.')) {
        ejecutarAccionModal('rotacion_automatica', materiaId);
    }
}

function asignarEstudiantesModal(materiaId) {
    const form = document.getElementById('formAsignarEstudiantesModal');
    const formData = new FormData(form);
    formData.append('accion', 'asignar_estudiantes');
    formData.append('materia_curso_id', materiaId);
    
    // Verificar que hay estudiantes seleccionados
    const estudiantesSeleccionados = formData.getAll('estudiantes[]');
    if (estudiantesSeleccionados.length === 0) {
        alert('Debe seleccionar al menos un estudiante');
        return;
    }
    
    // Verificar que hay nombre de subgrupo
    if (!formData.get('subgrupo').trim()) {
        alert('Debe ingresar un nombre para el subgrupo');
        return;
    }
    
    fetch('gestionar_subgrupos.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        // Recargar contenido del modal
        abrirModalSubgrupos(materiaId);
        mostrarMensajeModal('Estudiantes asignados correctamente', 'success');
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarMensajeModal('Error al asignar estudiantes', 'danger');
    });
}

function eliminarAsignacionIndividualModal(parametros, materiaId) {
    if (confirm('¿Eliminar esta asignación individual?')) {
        const formData = new FormData();
        formData.append('accion', 'eliminar_asignacion');
        formData.append('estudiante_materia_sql', parametros);
        
        fetch('gestionar_subgrupos.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            abrirModalSubgrupos(materiaId);
            mostrarMensajeModal('Asignación eliminada correctamente', 'success');
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarMensajeModal('Error al eliminar asignación', 'danger');
        });
    }
}

function eliminarSubgrupoCompletoModal(materiaId, subgrupo, periodo) {
    if (confirm(`¿Eliminar COMPLETAMENTE el subgrupo "${subgrupo}" del período "${periodo}"?`)) {
        const formData = new FormData();
        formData.append('accion', 'eliminar_subgrupo_completo');
        formData.append('materia_curso_id', materiaId);
        formData.append('subgrupo', subgrupo);
        formData.append('periodo_inicio', periodo);
        
        fetch('gestionar_subgrupos.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            abrirModalSubgrupos(materiaId);
            mostrarMensajeModal('Subgrupo eliminado correctamente', 'success');
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarMensajeModal('Error al eliminar subgrupo', 'danger');
        });
    }
}

function limpiarSeleccionModal() {
    document.querySelectorAll('.student-checkbox-modal').forEach(checkbox => {
        checkbox.checked = false;
    });
    const selectAll = document.getElementById('selectAllStudentsModal');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
}

// Event listeners para el modal
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAllStudentsModal');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox-modal');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            studentCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }
    
    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allChecked = Array.from(studentCheckboxes).every(cb => cb.checked);
            const noneChecked = Array.from(studentCheckboxes).every(cb => !cb.checked);
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
            }
        });
    });
});
</script>