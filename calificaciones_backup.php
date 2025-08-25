<?php
/**
 * calificaciones.php - Página de gestión de calificaciones con valoraciones detalladas
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar permisos (solo admin, directivos y profesores)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
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

// Obtener período actual configurado
$periodoActual = 'cuatrimestre'; // default
$nombrePeriodo = 'Cuatrimestre';
try {
    $periodo = $db->fetchOne("SELECT * FROM configuracion_periodos WHERE ciclo_lectivo_id = ? AND activo = 1", [$cicloLectivoId]);
    if ($periodo) {
        $periodoActual = (strpos($periodo['periodo_actual'], 'bim') !== false) ? 'valoracion' : 'cuatrimestre';
        $nombrePeriodo = $periodo['nombre_periodo'];
    }
} catch (Exception $e) {
    // Usar valores por defecto
}

// Permitir cambio manual del tipo de carga
$tipoCarga = isset($_GET['tipo']) ? $_GET['tipo'] : $periodoActual;

// Obtener cursos
$cursos = [];
try {
    if ($cicloLectivoId > 0) {
        if ($_SESSION['user_type'] == 'profesor') {
            $cursos = $db->fetchAll(
                "SELECT DISTINCT c.id, c.nombre, c.anio 
                 FROM cursos c
                 JOIN materias_por_curso mp ON c.id = mp.curso_id
                 WHERE mp.profesor_id = ? AND c.ciclo_lectivo_id = ?
                 ORDER BY c.anio, c.nombre",
                [$_SESSION['user_id'], $cicloLectivoId]
            );
        } else {
            $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
        }
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los cursos: ' . $e->getMessage() . '</div>';
}

// Procesar selección de curso
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;

// Variables para almacenar datos
$estudiantes = [];
$materias = [];
$calificaciones = [];
$observacionesPredefinidas = [];

// Obtener observaciones predefinidas
try {
    $observacionesPredefinidas = $db->fetchAll(
        "SELECT * FROM observaciones_predefinidas WHERE activo = 1 ORDER BY tipo, categoria, mensaje"
    );
} catch (Exception $e) {
    // Continuar sin observaciones predefinidas
}

// Si se seleccionó un curso
if ($cursoSeleccionado) {
    try {
        // Verificar permisos para profesores
        if ($_SESSION['user_type'] == 'profesor') {
            $cursoPermitido = $db->fetchOne(
                "SELECT COUNT(*) as count FROM materias_por_curso mp 
                 JOIN cursos c ON mp.curso_id = c.id
                 WHERE mp.profesor_id = ? AND c.id = ? AND c.ciclo_lectivo_id = ?",
                [$_SESSION['user_id'], $cursoSeleccionado, $cicloLectivoId]
            );
            
            if ($cursoPermitido['count'] == 0) {
                echo '<div class="alert alert-danger">No tiene permisos para acceder a este curso</div>';
                $cursoSeleccionado = null;
            }
        }
        
        if ($cursoSeleccionado) {
            // Obtener materias del curso
            if ($_SESSION['user_type'] == 'profesor') {
                $materias = $db->fetchAll(
                    "SELECT mp.id, m.nombre, m.codigo 
                     FROM materias_por_curso mp 
                     JOIN materias m ON mp.materia_id = m.id 
                     WHERE mp.curso_id = ? AND mp.profesor_id = ?
                     ORDER BY m.nombre",
                    [$cursoSeleccionado, $_SESSION['user_id']]
                );
            } else {
                $materias = $db->fetchAll(
                    "SELECT mp.id, m.nombre, m.codigo 
                     FROM materias_por_curso mp 
                     JOIN materias m ON mp.materia_id = m.id 
                     WHERE mp.curso_id = ? 
                     ORDER BY m.nombre",
                    [$cursoSeleccionado]
                );
            }
        }
        
        // Si también se seleccionó una materia, obtener estudiantes
        if ($materiaSeleccionada) {
            $estudiantes = $db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
                 ORDER BY u.apellido, u.nombre",
                [$cursoSeleccionado]
            );
            
            // Obtener calificaciones existentes
            foreach ($estudiantes as $estudiante) {
                $calificacion = $db->fetchOne(
                    "SELECT * FROM calificaciones 
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                    [$estudiante['id'], $materiaSeleccionada, $cicloLectivoId]
                );
                
                if ($calificacion) {
                    $calificaciones[$estudiante['id']] = $calificacion;
                }
            }
        }

    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_datos']) && $cicloLectivoId > 0) {
    try {
        $tipoGuardado = $_POST['tipo_carga'];
        
        $db->transaction(function($db) use ($cicloLectivoId, $materiaSeleccionada, $tipoGuardado) {
            foreach ($_POST['estudiantes'] as $estudianteId => $datos) {
                // Verificar si ya existe un registro
                $existe = $db->fetchOne(
                    "SELECT id FROM calificaciones 
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                    [$estudianteId, $materiaSeleccionada, $cicloLectivoId]
                );
                
                if ($tipoGuardado === 'valoracion') {
                    // Guardar datos de valoración (bimestral)
                    $bimestre = isset($_POST['bimestre']) ? $_POST['bimestre'] : '1';
                    $campo_valoracion = 'valoracion_' . $bimestre . 'bim';
                    $campo_desempeno = 'desempeno_' . $bimestre . 'bim';
                    $campo_observaciones = 'observaciones_' . $bimestre . 'bim';
                    
                    $valoracion = isset($datos['valoracion']) && in_array($datos['valoracion'], ['TEA', 'TEP', 'TED']) ? 
                                  $datos['valoracion'] : null;
                    $desempeno = isset($datos['desempeno']) ? $datos['desempeno'] : null;
                    $observaciones = isset($datos['observaciones']) ? $datos['observaciones'] : null;
                    
                    if ($existe) {
                        $db->query(
                            "UPDATE calificaciones SET $campo_valoracion = ?, $campo_desempeno = ?, $campo_observaciones = ? WHERE id = ?",
                            [$valoracion, $desempeno, $observaciones, $existe['id']]
                        );
                    } else {
                        $db->query(
                            "INSERT INTO calificaciones 
                             (estudiante_id, materia_curso_id, ciclo_lectivo_id, $campo_valoracion, $campo_desempeno, $campo_observaciones)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [$estudianteId, $materiaSeleccionada, $cicloLectivoId, $valoracion, $desempeno, $observaciones]
                        );
                    }
                } else {
                    // Guardar datos de cuatrimestre (lógica original)
                    $valoracion1c = isset($datos['valoracion_1c']) && in_array($datos['valoracion_1c'], ['TEA', 'TEP', 'TED']) ? 
                                    $datos['valoracion_1c'] : null;
                    $calificacion1c = isset($datos['calificacion_1c']) && is_numeric($datos['calificacion_1c']) ? 
                                      intval($datos['calificacion_1c']) : null;
                    $valoracion2c = isset($datos['valoracion_2c']) && in_array($datos['valoracion_2c'], ['TEA', 'TEP', 'TED']) ? 
                                    $datos['valoracion_2c'] : null;
                    $calificacion2c = isset($datos['calificacion_2c']) && is_numeric($datos['calificacion_2c']) ? 
                                      intval($datos['calificacion_2c']) : null;
                    $intensificacion1c = isset($datos['intensificacion_1c']) && is_numeric($datos['intensificacion_1c']) ? 
                                        intval($datos['intensificacion_1c']) : null;
                    $calificacionFinal = isset($datos['calificacion_final']) && is_numeric($datos['calificacion_final']) ? 
                                        intval($datos['calificacion_final']) : null;
                    $observaciones = isset($datos['observaciones']) ? $datos['observaciones'] : null;
                    $tipoCursada = isset($datos['tipo_cursada']) ? $datos['tipo_cursada'] : 'C';
                    
                    $estadoFinal = ($calificacionFinal !== null && $calificacionFinal >= 4) ? 'aprobada' : 'pendiente';
                    
                    if ($existe) {
                        $db->query(
                            "UPDATE calificaciones SET 
                             valoracion_preliminar_1c = ?, calificacion_1c = ?,
                             valoracion_preliminar_2c = ?, calificacion_2c = ?,
                             intensificacion_1c = ?, calificacion_final = ?,
                             tipo_cursada = ?, observaciones = ?, estado_final = ?
                             WHERE id = ?",
                            [
                                $valoracion1c, $calificacion1c,
                                $valoracion2c, $calificacion2c,
                                $intensificacion1c, $calificacionFinal,
                                $tipoCursada, $observaciones, $estadoFinal,
                                $existe['id']
                            ]
                        );
                    } else {
                        $db->query(
                            "INSERT INTO calificaciones 
                             (estudiante_id, materia_curso_id, ciclo_lectivo_id,
                              valoracion_preliminar_1c, calificacion_1c,
                              valoracion_preliminar_2c, calificacion_2c,
                              intensificacion_1c, calificacion_final,
                              tipo_cursada, observaciones, estado_final)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $estudianteId, $materiaSeleccionada, $cicloLectivoId,
                                $valoracion1c, $calificacion1c,
                                $valoracion2c, $calificacion2c,
                                $intensificacion1c, $calificacionFinal,
                                $tipoCursada, $observaciones, $estadoFinal
                            ]
                        );
                    }
                }
            }
        });
        
        $_SESSION['message'] = 'Datos guardados correctamente';
        $_SESSION['message_type'] = 'success';
        
        header("Location: calificaciones.php?curso=$cursoSeleccionado&materia=$materiaSeleccionada&tipo=$tipoGuardado");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al guardar los datos: ' . $e->getMessage() . '</div>';
    }
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Gestión de Calificaciones - Ciclo Lectivo <?= isset($anioActivo) ? $anioActivo : date('Y') ?></h5>
            </div>
            <div class="card-body">
                <!-- Selector de tipo de carga -->
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="btn-group" role="group" aria-label="Tipo de carga">
                            <input type="radio" class="btn-check" name="tipo_carga_radio" id="tipo_valoracion" 
                                   value="valoracion" <?= $tipoCarga === 'valoracion' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="tipo_valoracion">
                                <i class="bi bi-clipboard-check"></i> Valoración Preliminar (Bimestral)
                            </label>
                            
                            <input type="radio" class="btn-check" name="tipo_carga_radio" id="tipo_cuatrimestre" 
                                   value="cuatrimestre" <?= $tipoCarga === 'cuatrimestre' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-success" for="tipo_cuatrimestre">
                                <i class="bi bi-journal-text"></i> Calificaciones Cuatrimestrales
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            Seleccione el tipo de datos que desea cargar
                        </small>
                    </div>
                </div>
                
                <form method="GET" action="calificaciones.php" class="mb-4" id="form-seleccion">
                    <input type="hidden" name="tipo" id="hidden_tipo" value="<?= $tipoCarga ?>">
                    <div class="row align-items-end">
                        <div class="col-md-5">
                            <label for="curso" class="form-label">Seleccione Curso:</label>
                            <select name="curso" id="curso" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                    <?= $curso['nombre'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($cursoSeleccionado): ?>
                        <div class="col-md-5">
                            <label for="materia" class="form-label">Seleccione Materia:</label>
                            <select name="materia" id="materia" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleccione una materia --</option>
                                <?php foreach ($materias as $materia): ?>
                                <option value="<?= $materia['id'] ?>" <?= ($materiaSeleccionada == $materia['id']) ? 'selected' : '' ?>>
                                    <?= $materia['nombre'] ?> (<?= $materia['codigo'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
                
                <?php if ($cursoSeleccionado && $materiaSeleccionada && count($estudiantes) > 0): ?>
                
                <!-- Selector de bimestre para valoraciones -->
                <?php if ($tipoCarga === 'valoracion'): ?>
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="btn-group" role="group" aria-label="Bimestre">
                            <input type="radio" class="btn-check" name="bimestre_radio" id="bim1" value="1" checked>
                            <label class="btn btn-outline-info" for="bim1">1er Bimestre</label>
                            
                            <input type="radio" class="btn-check" name="bimestre_radio" id="bim3" value="3">
                            <label class="btn btn-outline-info" for="bim3">3er Bimestre</label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Formulario principal -->
                <form method="POST" action="calificaciones.php?curso=<?= $cursoSeleccionado ?>&materia=<?= $materiaSeleccionada ?>&tipo=<?= $tipoCarga ?>">
                    <input type="hidden" name="tipo_carga" value="<?= $tipoCarga ?>">
                    <input type="hidden" name="bimestre" id="bimestre_hidden" value="1">
                    
                    <?php if ($tipoCarga === 'valoracion'): ?>
                        <!-- Vista de valoraciones preliminares -->
                        <?php include 'includes/vista_valoraciones.php'; ?>
                    <?php else: ?>
                        <!-- Vista de calificaciones cuatrimestrales -->
                        <?php include 'includes/vista_cuatrimestral.php'; ?>
                    <?php endif; ?>
                    
                    <div class="text-center mt-3">
                        <button type="submit" name="guardar_datos" class="btn btn-primary btn-lg">
                            <i class="bi bi-save"></i> Guardar Datos
                        </button>
                    </div>
                </form>
                
                <?php elseif ($cursoSeleccionado && $materiaSeleccionada): ?>
                <div class="alert alert-warning">
                    No se encontraron estudiantes matriculados en este curso.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// JavaScript para manejar el cambio de tipo de carga
document.addEventListener('DOMContentLoaded', function() {
    // Manejar cambio de tipo de carga
    document.querySelectorAll('input[name="tipo_carga_radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.getElementById('hidden_tipo').value = this.value;
            document.getElementById('form-seleccion').submit();
        });
    });
    
    // Manejar cambio de bimestre
    document.querySelectorAll('input[name="bimestre_radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.getElementById('bimestre_hidden').value = this.value;
            cargarDatosBimestre(this.value);
        });
    });
});

// Función para cargar datos del bimestre seleccionado
function cargarDatosBimestre(bimestre) {
    // Aquí puedes implementar la lógica para cargar datos via AJAX
    // Por ahora, simplemente recargamos la página con el parámetro bimestre
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('bimestre', bimestre);
    window.location.href = currentUrl.toString();
}

// Función para insertar observación predefinida
function insertarObservacion(estudianteId, mensaje) {
    const textarea = document.querySelector(`textarea[name="estudiantes[${estudianteId}][observaciones]"]`);
    if (textarea) {
        if (textarea.value.trim() !== '') {
            textarea.value += '. ' + mensaje;
        } else {
            textarea.value = mensaje;
        }
    }
}
</script>

<?php
// Limpiar el buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>