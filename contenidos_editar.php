<?php
/**
 * contenidos_editar.php - Edición de contenidos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';
require_once 'sistema_periodos_automaticos.php';

// Verificar que el usuario sea profesor
if ($_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetro de contenido
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['message'] = 'Contenido no especificado';
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos.php');
    exit;
}

$contenidoId = intval($_GET['id']);
$profesorId = $_SESSION['user_id'];

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener información del contenido
try {
    $contenido = $db->fetchOne(
        "SELECT c.*, mp.profesor_id, mp.materia_id, mp.curso_id,
                m.nombre as materia_nombre, cur.nombre as curso_nombre
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         WHERE c.id = ? AND mp.profesor_id = ? AND c.activo = 1",
        [$contenidoId, $profesorId]
    );
    
    if (!$contenido) {
        $_SESSION['message'] = 'Contenido no encontrado o no tiene permisos para editarlo';
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos.php');
        exit;
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $anioActivo = $cicloActivo ? $cicloActivo['anio'] : date('Y');
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener el contenido: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos.php');
    exit;
}

// Detectar período según la fecha actual del contenido
$periodoActual = SistemaPeriodos::detectarPeriodo($contenido['fecha_clase'], $anioActivo);

// Incluir el encabezado
require_once 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="contenidos.php">Contenidos</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Editar Contenido</li>
                </ol>
            </nav>
            
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-pencil-square"></i> Editar Contenido
            </h1>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show" role="alert">
                    <?= $_SESSION['message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">
                                Información del Contenido
                            </h6>
                        </div>
                        <div class="card-body">
                            <form action="contenidos_guardar.php" method="POST" id="formEditarContenido">
                                <input type="hidden" name="accion" value="editar">
                                <input type="hidden" name="contenido_id" value="<?= $contenidoId ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">Materia</label>
                                    <input type="text" class="form-control" readonly 
                                           value="<?= htmlspecialchars($contenido['materia_nombre']) ?> - <?= htmlspecialchars($contenido['curso_nombre']) ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="titulo" class="form-label">Título del Contenido *</label>
                                    <input type="text" class="form-control" id="titulo" name="titulo" 
                                           required maxlength="255" value="<?= htmlspecialchars($contenido['titulo']) ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="descripcion" class="form-label">Descripción</label>
                                    <textarea class="form-control" id="descripcion" name="descripcion" rows="3"><?= htmlspecialchars($contenido['descripcion'] ?? '') ?></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="fecha_clase" class="form-label">Fecha de Clase *</label>
                                    <input type="date" class="form-control" id="fecha_clase" name="fecha_clase" 
                                           required value="<?= $contenido['fecha_clase'] ?>" 
                                           onchange="actualizarPeriodo(this.value)">
                                    <div id="periodo_detectado" class="form-text">
                                        Período actual: <?= $periodoActual['periodo_nombre'] ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="tipo_evaluacion" class="form-label">Tipo de Evaluación *</label>
                                    <select class="form-select" id="tipo_evaluacion" name="tipo_evaluacion" required>
                                        <option value="numerica" <?= $contenido['tipo_evaluacion'] == 'numerica' ? 'selected' : '' ?> style="display: none;">
                                            Numérica (1-10)
                                        </option>
                                        <option value="cualitativa" <?= $contenido['tipo_evaluacion'] == 'cualitativa' ? 'selected' : '' ?>>
                                            Cualitativa (Acreditado/No Acreditado)
                                        </option>
                                    </select>
                                    <?php
                                    // Verificar si tiene calificaciones
                                    $tieneCalificaciones = $db->fetchOne(
                                        "SELECT COUNT(*) as total FROM contenidos_calificaciones WHERE contenido_id = ?",
                                        [$contenidoId]
                                    )['total'] ?? 0;
                                    
                                    if ($tieneCalificaciones > 0):
                                    ?>
                                    <div class="alert alert-warning mt-2">
                                        <i class="bi bi-exclamation-triangle"></i> 
                                        <strong>Advertencia:</strong> Este contenido ya tiene <?= $tieneCalificaciones ?> calificaciones registradas. 
                                        Si cambia el tipo de evaluación, todas las calificaciones existentes serán eliminadas.
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Guardar Cambios
                                    </button>
                                    <a href="contenidos.php?materia=<?= $contenido['materia_curso_id'] ?>&bimestre=<?= $contenido['bimestre'] ?>" 
                                       class="btn btn-secondary">
                                        <i class="bi bi-x-circle"></i> Cancelar
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h6 class="m-0 font-weight-bold">Información Adicional</h6>
                        </div>
                        <div class="card-body">
                            <p><strong>Bimestre actual:</strong> <?= $contenido['bimestre'] ?>°</p>
                            <p><strong>Creado el:</strong> <?= date('d/m/Y H:i', strtotime($contenido['created_at'])) ?></p>
                            <?php if ($contenido['updated_at'] != $contenido['created_at']): ?>
                            <p><strong>Última modificación:</strong> <?= date('d/m/Y H:i', strtotime($contenido['updated_at'])) ?></p>
                            <?php endif; ?>
                            
                            <?php if ($tieneCalificaciones > 0): ?>
                            <hr>
                            <div class="alert alert-info">
                                <h6>Estado de calificaciones:</h6>
                                <p class="mb-0">Este contenido tiene <strong><?= $tieneCalificaciones ?></strong> calificaciones registradas.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header bg-warning">
                            <h6 class="m-0 font-weight-bold">Notas importantes</h6>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <li>Si cambia la fecha, el sistema detectará automáticamente el nuevo bimestre</li>
                                <li>Cambiar el tipo de evaluación eliminará todas las calificaciones existentes</li>
                                <li>Los cambios se reflejarán inmediatamente en las calificaciones automáticas</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Función para detectar período automáticamente
function actualizarPeriodo(fecha) {
    if (!fecha) return;
    
    // Llamar a PHP para detectar el período
    fetch('api_detectar_periodo.php?fecha=' + fecha)
        .then(response => response.json())
        .then(data => {
            document.getElementById('periodo_detectado').innerHTML = 
                'Período detectado: ' + data.periodo_nombre;
            
            if (data.es_intensificacion) {
                document.getElementById('periodo_detectado').innerHTML += 
                    '<br><span class="text-warning">⚠️ Período de intensificación</span>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// Confirmar cambio de tipo de evaluación si hay calificaciones
document.getElementById('tipo_evaluacion').addEventListener('change', function(e) {
    const tieneCalificaciones = <?= $tieneCalificaciones ?>;
    const tipoOriginal = '<?= $contenido['tipo_evaluacion'] ?>';
    
    if (tieneCalificaciones > 0 && this.value !== tipoOriginal) {
        if (!confirm(`¿Está seguro de cambiar el tipo de evaluación?\n\nEsto eliminará las ${tieneCalificaciones} calificaciones existentes.`)) {
            this.value = tipoOriginal;
        }
    }
});

// Validar formulario antes de enviar
document.getElementById('formEditarContenido').addEventListener('submit', function(e) {
    const titulo = document.getElementById('titulo').value.trim();
    const fecha = document.getElementById('fecha_clase').value;
    
    if (!titulo) {
        e.preventDefault();
        alert('El título es obligatorio');
        return false;
    }
    
    if (!fecha) {
        e.preventDefault();
        alert('La fecha es obligatoria');
        return false;
    }
    
    // Validar que la fecha esté dentro del ciclo lectivo
    const fechaSeleccionada = new Date(fecha);
    const inicioClases = new Date('<?= $anioActivo ?>-03-03');
    const finClases = new Date('<?= $anioActivo ?>-12-20');
    
    if (fechaSeleccionada < inicioClases || fechaSeleccionada > finClases) {
        e.preventDefault();
        alert('La fecha debe estar dentro del ciclo lectivo <?= $anioActivo ?> (03/03 - 20/12)');
        return false;
    }
});
</script>

<?php require_once 'footer.php'; ?>