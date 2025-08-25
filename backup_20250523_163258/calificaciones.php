<?php
/**
 * calificaciones.php - Página de gestión de calificaciones
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

// Obtener ciclo lectivo activo - Con verificación de errores
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        // Si no hay ciclo activo, mostrar mensaje de error
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

// Obtener cursos
$cursos = [];
try {
    if ($cicloLectivoId > 0) {
        // Filtrar cursos según el tipo de usuario
        if ($_SESSION['user_type'] == 'profesor') {
            // Para profesores: solo cursos donde tienen materias asignadas
            $cursos = $db->fetchAll(
                "SELECT DISTINCT c.id, c.nombre, c.anio 
                 FROM cursos c
                 JOIN materias_por_curso mp ON c.id = mp.curso_id
                 WHERE mp.profesor_id = ? AND c.ciclo_lectivo_id = ?
                 ORDER BY c.anio, c.nombre",
                [$_SESSION['user_id'], $cicloLectivoId]
            );
        } else {
            // Para admin y directivos: todos los cursos
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
            // Obtener materias del curso (filtrado por profesor si corresponde)
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
        
        // Si también se seleccionó una materia, obtener estudiantes y sus calificaciones
        if ($materiaSeleccionada) {
            // CONSULTA MODIFICADA: Obtener estudiantes regulares + recursantes
            
            // Primero verificar si existe la tabla materias_recursado
            $tablaRecursadoExiste = false;
            try {
                $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='materias_recursado'");
                $tablaRecursadoExiste = true;
            } catch (Exception $e) {
                // La tabla no existe, solo usar estudiantes regulares
            }
            
            if ($tablaRecursadoExiste) {
                // Consulta completa con recursantes
                $estudiantes = $db->fetchAll(
                    "SELECT DISTINCT
                        u.id, 
                        u.nombre, 
                        u.apellido, 
                        u.dni,
                        CASE 
                            WHEN mr.id IS NOT NULL THEN 'R' 
                            ELSE 'C' 
                        END as tipo_cursada_sugerido,
                        CASE 
                            WHEN mr.id IS NOT NULL THEN 1 
                            ELSE 0 
                        END as es_recursante,
                        CASE 
                            WHEN mr.id IS NOT NULL THEN c_actual.nombre 
                            ELSE c.nombre 
                        END as curso_referencia,
                        CASE 
                            WHEN mr.id IS NOT NULL THEN c_actual.anio 
                            ELSE c.anio 
                        END as anio_referencia
                    FROM usuarios u
                    LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
                    LEFT JOIN cursos c ON m.curso_id = c.id
                    LEFT JOIN materias_recursado mr ON u.id = mr.estudiante_id AND mr.materia_curso_id = ? AND mr.estado = 'activo'
                    LEFT JOIN cursos c_actual ON m.curso_id = c_actual.id
                    WHERE 
                        u.tipo = 'estudiante' 
                        AND (
                            -- Estudiantes regulares del curso
                            (m.curso_id = ? AND mr.id IS NULL)
                            OR 
                            -- Estudiantes recursando esta materia
                            (mr.materia_curso_id = ? AND mr.estado = 'activo')
                        )
                    ORDER BY u.apellido, u.nombre",
                    [$materiaSeleccionada, $cursoSeleccionado, $materiaSeleccionada]
                );
            } else {
                // Consulta simple sin recursantes (por compatibilidad)
                $estudiantes = $db->fetchAll(
                    "SELECT u.id, u.nombre, u.apellido, u.dni,
                            'C' as tipo_cursada_sugerido,
                            0 as es_recursante,
                            c.nombre as curso_referencia,
                            c.anio as anio_referencia
                     FROM usuarios u 
                     JOIN matriculas m ON u.id = m.estudiante_id 
                     JOIN cursos c ON m.curso_id = c.id
                     WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
                     ORDER BY u.apellido, u.nombre",
                    [$cursoSeleccionado]
                );
            }
            
            // Agregar información adicional al array de estudiantes
            foreach ($estudiantes as $key => $estudiante) {
                $estudiantes[$key]['es_recursante'] = ($estudiante['tipo_cursada_sugerido'] == 'R');
            }
            
            // Obtener calificaciones existentes (sin cambios)
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

// Procesar formulario de calificaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_calificaciones']) && $cicloLectivoId > 0) {
    try {
        $db->transaction(function($db) use ($cicloLectivoId, $materiaSeleccionada) {
            foreach ($_POST['estudiantes'] as $estudianteId => $datos) {
                // Verificar si ya existe un registro para este estudiante, materia y ciclo
                $existe = $db->fetchOne(
                    "SELECT id FROM calificaciones 
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                    [$estudianteId, $materiaSeleccionada, $cicloLectivoId]
                );
                
                // Preparar datos
                // IMPORTANTE: Asegurarse de que la valoración sea uno de los valores permitidos
                $valoracion1c = isset($datos['valoracion_1c']) && in_array($datos['valoracion_1c'], ['TEA', 'TEP', 'TED']) ? 
                                $datos['valoracion_1c'] : null;
                
                $calificacion1c = isset($datos['calificacion_1c']) && is_numeric($datos['calificacion_1c']) ? 
                                  intval($datos['calificacion_1c']) : null;
                
                // IMPORTANTE: Asegurarse de que la valoración sea uno de los valores permitidos
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
                
                // Determinar estado final (aprobada si calificación final >= 4)
                $estadoFinal = ($calificacionFinal !== null && $calificacionFinal >= 4) ? 'aprobada' : 'pendiente';
                
                if ($existe) {
                    // Actualizar registro existente
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
                    // Crear nuevo registro
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
        });
        
        // Mensaje de éxito
        $_SESSION['message'] = 'Calificaciones guardadas correctamente';
        $_SESSION['message_type'] = 'success';
        
        // Recargar la página para mostrar los datos actualizados
        header("Location: calificaciones.php?curso=$cursoSeleccionado&materia=$materiaSeleccionada");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al guardar las calificaciones: ' . $e->getMessage() . '</div>';
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
                <form method="GET" action="calificaciones.php" class="mb-4">
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
                <!-- Formulario para cargar calificaciones -->
                <form method="POST" action="calificaciones.php?curso=<?= $cursoSeleccionado ?>&materia=<?= $materiaSeleccionada ?>">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th rowspan="2">Estudiante</th>
                                    <th rowspan="2">Tipo</th>
                                    <th colspan="2" class="text-center">1° Cuatrimestre</th>
                                    <th colspan="2" class="text-center">2° Cuatrimestre</th>
                                    <th rowspan="2">Int. 1° Cuat.</th>
                                    <th rowspan="2">Calif. Final</th>
                                    <th rowspan="2">Observaciones</th>
                                </tr>
                                <tr>
                                    <th>Valoración</th>
                                    <th>Calif.</th>
                                    <th>Valoración</th>
                                    <th>Calif.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes as $estudiante): ?>
                                <?php 
                                    $calificacion = isset($calificaciones[$estudiante['id']]) ? $calificaciones[$estudiante['id']] : null;
                                    $valoracion1c = $calificacion ? $calificacion['valoracion_preliminar_1c'] : '';
                                    $calificacion1c = $calificacion ? $calificacion['calificacion_1c'] : '';
                                    $valoracion2c = $calificacion ? $calificacion['valoracion_preliminar_2c'] : '';
                                    $calificacion2c = $calificacion ? $calificacion['calificacion_2c'] : '';
                                    $intensificacion1c = $calificacion ? $calificacion['intensificacion_1c'] : '';
                                    $calificacionFinal = $calificacion ? $calificacion['calificacion_final'] : '';
                                    $observaciones = $calificacion ? $calificacion['observaciones'] : '';
                                    $tipoCursada = $calificacion ? $calificacion['tipo_cursada'] : 'C';
                                ?>
                                <tr <?= isset($estudiante['es_recursante']) && $estudiante['es_recursante'] ? 'class="table-warning"' : '' ?>>
    <td>
        <?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?> (<?= $estudiante['dni'] ?>)
        <?php if (isset($estudiante['es_recursante']) && $estudiante['es_recursante']): ?>
            <br><small class="text-warning">
                <i class="bi bi-arrow-repeat"></i> Recursando desde <?= $estudiante['anio_referencia'] ?>° año
            </small>
        <?php endif; ?>
        <input type="hidden" name="estudiantes[<?= $estudiante['id'] ?>][id]" value="<?= $estudiante['id'] ?>">
    </td>
    <td>
        <select name="estudiantes[<?= $estudiante['id'] ?>][tipo_cursada]" class="form-select form-select-sm">
            <option value="C" <?= (!isset($estudiante['es_recursante']) || !$estudiante['es_recursante']) && $tipoCursada == 'C' ? 'selected' : (!isset($estudiante['es_recursante']) || !$estudiante['es_recursante'] ? 'selected' : '') ?>>C</option>
            <option value="R" <?= (isset($estudiante['es_recursante']) && $estudiante['es_recursante']) || $tipoCursada == 'R' ? 'selected' : '' ?>>R</option>
        </select>
    </td>
                                        <input type="hidden" name="estudiantes[<?= $estudiante['id'] ?>][id]" value="<?= $estudiante['id'] ?>">
                                    </td>
                                    <td>
                                        <select name="estudiantes[<?= $estudiante['id'] ?>][tipo_cursada]" class="form-select form-select-sm">
                                            <option value="C" <?= $tipoCursada == 'C' ? 'selected' : '' ?>>C</option>
                                            <option value="R" <?= $tipoCursada == 'R' ? 'selected' : '' ?>>R</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="estudiantes[<?= $estudiante['id'] ?>][valoracion_1c]" class="form-select form-select-sm" <?= isset($cuatrimestreActual) && $cuatrimestreActual < 1 ? 'disabled' : '' ?>>
                                            <option value="" <?= empty($valoracion1c) ? 'selected' : '' ?>>--</option>
                                            <option value="TEA" <?= $valoracion1c == 'TEA' ? 'selected' : '' ?>>TEA</option>
                                            <option value="TEP" <?= $valoracion1c == 'TEP' ? 'selected' : '' ?>>TEP</option>
                                            <option value="TED" <?= $valoracion1c == 'TED' ? 'selected' : '' ?>>TED</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][calificacion_1c]" 
                                               id="calificacion_1c_<?= $estudiante['id'] ?>"
                                               class="form-control form-control-sm" 
                                               min="1" max="10" value="<?= $calificacion1c ?>" 
                                               <?= isset($cuatrimestreActual) && $cuatrimestreActual < 1 ? 'disabled' : '' ?>>
                                    </td>
                                    <td>
                                        <select name="estudiantes[<?= $estudiante['id'] ?>][valoracion_2c]" class="form-select form-select-sm" <?= isset($cuatrimestreActual) && $cuatrimestreActual < 2 ? 'disabled' : '' ?>>
                                            <option value="" <?= empty($valoracion2c) ? 'selected' : '' ?>>--</option>
                                            <option value="TEA" <?= $valoracion2c == 'TEA' ? 'selected' : '' ?>>TEA</option>
                                            <option value="TEP" <?= $valoracion2c == 'TEP' ? 'selected' : '' ?>>TEP</option>
                                            <option value="TED" <?= $valoracion2c == 'TED' ? 'selected' : '' ?>>TED</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][calificacion_2c]" 
                                               id="calificacion_2c_<?= $estudiante['id'] ?>"
                                               class="form-control form-control-sm" 
                                               min="1" max="10" value="<?= $calificacion2c ?>" 
                                               <?= isset($cuatrimestreActual) && $cuatrimestreActual < 2 ? 'disabled' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][intensificacion_1c]" 
                                               id="intensificacion_1c_<?= $estudiante['id'] ?>"
                                               class="form-control form-control-sm" 
                                               min="1" max="10" value="<?= $intensificacion1c ?>">
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][calificacion_final]" 
                                                   id="calificacion_final_<?= $estudiante['id'] ?>"
                                                   class="form-control form-control-sm" 
                                                   min="1" max="10" value="<?= $calificacionFinal ?>">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" 
                                                    onclick="calcularCalificacionFinal(<?= $estudiante['id'] ?>)" 
                                                    title="Calcular promedio">
                                                <i class="bi bi-calculator"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td>
                                        <textarea name="estudiantes[<?= $estudiante['id'] ?>][observaciones]" 
                                                  class="form-control form-control-sm" 
                                                  rows="2"><?= $observaciones ?></textarea>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <strong>Referencias:</strong><br>
                                <ul class="mb-0">
                                    <li><strong>Tipo:</strong> C = Cursada por primera vez, R = Recursada</li>
                                    <li><strong>Valoraciones:</strong> TEA = Trayectoria Educativa Avanzada, TEP = Trayectoria Educativa en Proceso, TED = Trayectoria Educativa Discontinua</li>
                                    <li><strong>Int. 1° Cuat.:</strong> Intensificación de contenidos del 1° cuatrimestre (solo si corresponde)</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-3">
                        <button type="submit" name="guardar_calificaciones" class="btn btn-primary">Guardar Calificaciones</button>
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

<!-- Guía de valoraciones -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Guía para el Registro de Calificaciones según Resolución N° 1650/24</h5>
            </div>
            <div class="card-body">
                <h6>Valoraciones Preliminares:</h6>
                <ul>
                    <li><strong>TEA (Trayectoria Educativa Avanzada):</strong> Estudiantes que han alcanzado los aprendizajes correspondientes y sostuvieron una buena vinculación pedagógica.</li>
                    <li><strong>TEP (Trayectoria Educativa en Proceso):</strong> Estudiantes que no han alcanzado de forma suficiente los aprendizajes correspondientes, pero que mantienen una buena vinculación pedagógica.</li>
                    <li><strong>TED (Trayectoria Educativa Discontinua):</strong> Estudiantes que no han alcanzado los aprendizajes correspondientes y que tuvieron una escasa vinculación pedagógica.</li>
                </ul>
                
                <h6>Calificación del cuatrimestre:</h6>
                <p>La calificación de cierre de cada cuatrimestre resultará de la ponderación de las valoraciones parciales cualitativas y cuantitativas obtenidas por el estudiante, en la escala de uno (1) a diez (10).</p>
                
                <h6>Intensificación:</h6>
                <p>En caso de que el estudiante durante el segundo cuatrimestre intensifique contenidos del primero, la calificación correspondiente se consignará en esta columna.</p>
                
                <h6>Calificación final:</h6>
                <p>Se registrará al momento en que la materia haya sido aprobada y acreditada o al cierre del ciclo lectivo, sea que la materia haya sido aprobada o quede pendiente de aprobación y acreditación.</p>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * JavaScript adicional para calificaciones con recursado
 */

// Función para calcular calificación final
function calcularCalificacionFinal(estudianteId) {
    // Obtener valores
    const cal1c = document.getElementById('calificacion_1c_' + estudianteId);
    const cal2c = document.getElementById('calificacion_2c_' + estudianteId);
    const int1c = document.getElementById('intensificacion_1c_' + estudianteId);
    const calFinal = document.getElementById('calificacion_final_' + estudianteId);
    
    let valores = [];
    
    // Agregar calificaciones válidas
    if (cal1c && cal1c.value && parseInt(cal1c.value) >= 1) {
        valores.push(parseInt(cal1c.value));
    }
    
    if (cal2c && cal2c.value && parseInt(cal2c.value) >= 1) {
        valores.push(parseInt(cal2c.value));
    }
    
    // Si hay intensificación, usar ese valor en lugar del 1er cuatrimestre
    if (int1c && int1c.value && parseInt(int1c.value) >= 1) {
        // Reemplazar la primera calificación si existe
        if (valores.length > 0) {
            valores[0] = parseInt(int1c.value);
        } else {
            valores.push(parseInt(int1c.value));
        }
    }
    
    // Calcular promedio
    if (valores.length > 0) {
        const promedio = valores.reduce((a, b) => a + b, 0) / valores.length;
        const promedioRedondeado = Math.round(promedio);
        
        if (calFinal) {
            calFinal.value = promedioRedondeado;
        }
        
        // Mostrar feedback visual
        if (promedioRedondeado >= 4) {
            calFinal.style.backgroundColor = '#d4edda';
            calFinal.style.color = '#155724';
        } else {
            calFinal.style.backgroundColor = '#f8d7da';
            calFinal.style.color = '#721c24';
        }
        
        // Quitar colores después de un tiempo
        setTimeout(() => {
            calFinal.style.backgroundColor = '';
            calFinal.style.color = '';
        }, 2000);
    } else {
        alert('No hay calificaciones válidas para calcular el promedio');
    }
}

// Función para resaltar recursantes
document.addEventListener('DOMContentLoaded', function() {
    // Agregar tooltip a recursantes
    const recursantes = document.querySelectorAll('.table-warning');
    recursantes.forEach(fila => {
        fila.title = 'Este estudiante está recursando la materia';
    });
});
</script>

<style>
/* Estilos adicionales para recursantes */
.table-warning {
    background-color: #fff3cd !important;
}

.table-warning td {
    border-color: #ffeaa7 !important;
}

.text-warning {
    color: #856404 !important;
}
</style>

<?php
// Limpiar el buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>