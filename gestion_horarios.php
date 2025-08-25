<?php
/**
 * gestion_horarios.php - Sistema de Gestión de Horarios
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * CARACTERÍSTICAS:
 * - Creación y gestión de horarios por curso
 * - Carga automática de horarios basada en el horario institucional
 * - Gestión de materias por día y hora
 * - Configuración de aulas y docentes
 */

require_once 'config.php';
require_once 'header.php';

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Crear tabla de horarios si no existe
try {
    $db->query("CREATE TABLE IF NOT EXISTS horarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        curso_id INTEGER NOT NULL,
        materia_id INTEGER NOT NULL,
        dia_semana INTEGER NOT NULL, -- 1=lunes, 2=martes, etc.
        hora_inicio TIME NOT NULL,
        hora_fin TIME NOT NULL,
        aula VARCHAR(100) NULL,
        docente VARCHAR(200) NULL,
        observaciones TEXT NULL,
        activo BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (curso_id) REFERENCES cursos(id),
        FOREIGN KEY (materia_id) REFERENCES materias(id),
        UNIQUE(curso_id, dia_semana, hora_inicio, hora_fin)
    )");
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al crear tabla de horarios: ' . $e->getMessage() . '</div>';
}

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    if (!$cicloActivo) {
        echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado.</div>';
        exit;
    }
    $cicloLectivoId = $cicloActivo['id'];
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener ciclo lectivo: ' . $e->getMessage() . '</div>';
    exit;
}

// Obtener cursos
$cursos = [];
try {
    $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener cursos: ' . $e->getMessage() . '</div>';
}

// Definir horarios base de la escuela
$horariosBase = [
    1 => ['inicio' => '07:30', 'fin' => '08:25', 'nombre' => '1° hora'],
    2 => ['inicio' => '08:30', 'fin' => '09:25', 'nombre' => '2° hora'],
    3 => ['inicio' => '09:40', 'fin' => '10:35', 'nombre' => '3° hora'],
    4 => ['inicio' => '10:40', 'fin' => '11:35', 'nombre' => '4° hora'],
    5 => ['inicio' => '11:40', 'fin' => '12:30', 'nombre' => 'Almuerzo'],
    6 => ['inicio' => '12:30', 'fin' => '13:25', 'nombre' => '6° hora'],
    7 => ['inicio' => '13:30', 'fin' => '14:25', 'nombre' => '7° hora'],
    8 => ['inicio' => '14:35', 'fin' => '15:30', 'nombre' => '8° hora'],
    9 => ['inicio' => '15:35', 'fin' => '16:30', 'nombre' => '9° hora']
];

// Nombres de días
$nombresDias = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
    5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'
];

// Horarios predefinidos por curso basados en el PDF adjunto
$horariosInstitucionales = [
    '1° año' => [
        1 => [ // Lunes
            1 => ['materia' => 'Inglés', 'docente' => 'Manniello - Ferrari - Iuorio'],
            2 => ['materia' => 'Inglés', 'docente' => 'Manniello - Ferrari - Iuorio'],
            3 => ['materia' => 'Taller', 'docente' => 'Romano'],
            4 => ['materia' => 'Taller', 'docente' => 'Romano'],
            6 => ['materia' => 'Taller', 'docente' => 'Montoto - Kloster - Cardalda'],
            7 => ['materia' => 'Taller', 'docente' => 'Montoto - Kloster - Cardalda'],
            8 => ['materia' => 'Taller', 'docente' => 'Montoto - Kloster - Cardalda'],
            9 => ['materia' => 'Entrenamiento', 'docente' => 'Chacón - Montoto']
        ],
        2 => [ // Martes
            1 => ['materia' => 'Ciencias Sociales', 'docente' => 'Iglesias, Federico'],
            2 => ['materia' => 'Ciencias Sociales', 'docente' => 'Iglesias, Federico'],
            3 => ['materia' => 'Ciencias Naturales', 'docente' => 'Cid de Lopez, Marta'],
            4 => ['materia' => 'Ciencias Naturales', 'docente' => 'Cid de Lopez, Marta'],
            6 => ['materia' => 'Entrenamiento Deportivo', 'docente' => 'Dottori, Daniel'],
            7 => ['materia' => 'Taller', 'docente' => 'Dis Tecnológ - Impresión 3D - Robótica'],
            8 => ['materia' => 'Taller', 'docente' => 'Dis Tecnológ - Impresión 3D - Robótica'],
            9 => ['materia' => 'Taller', 'docente' => 'Dis Tecnológ - Impresión 3D - Robótica']
        ],
        3 => [ // Miércoles
            1 => ['materia' => 'Prácticas del Lenguaje', 'docente' => 'Gómez, Maria Victoria'],
            2 => ['materia' => 'Prácticas del Lenguaje', 'docente' => 'Gómez, Maria Victoria'],
            3 => ['materia' => 'Matemática', 'docente' => 'Chacón, Martín'],
            4 => ['materia' => 'Matemática', 'docente' => 'Chacón, Martín'],
            6 => ['materia' => 'Inglés', 'docente' => 'Ferrari, Paula'],
            7 => ['materia' => 'Taller', 'docente' => 'Tecnología 1'],
            8 => ['materia' => 'Ciencias Naturales', 'docente' => 'Cid de Lopez, Marta'],
            9 => ['materia' => 'Ciencias Naturales', 'docente' => 'Cid de Lopez, Marta']
        ],
        4 => [ // Jueves
            1 => ['materia' => 'Inglés', 'docente' => 'Manniello - Ferrari - Iuorio'],
            2 => ['materia' => 'Inglés', 'docente' => 'Manniello - Ferrari - Iuorio'],
            3 => ['materia' => 'Educación Física', 'docente' => 'Dottori, Daniel'],
            4 => ['materia' => 'Educación Física', 'docente' => 'Dottori, Daniel'],
            6 => ['materia' => 'Prácticas del Lenguaje', 'docente' => 'Gómez, Maria Victoria'],
            7 => ['materia' => 'Prácticas del Lenguaje', 'docente' => 'Gómez, Maria Victoria'],
            8 => ['materia' => 'Educación Artística', 'docente' => 'Maristany - Gendra'],
            9 => ['materia' => 'Educación Artística', 'docente' => 'Maristany - Gendra']
        ],
        5 => [ // Viernes
            1 => ['materia' => 'Ciencias Sociales', 'docente' => 'Iglesias, Federico'],
            2 => ['materia' => 'Ciencias Sociales', 'docente' => 'Iglesias, Federico'],
            3 => ['materia' => 'Matemática', 'docente' => 'Chacón, Martín'],
            4 => ['materia' => 'Matemática', 'docente' => 'Chacón, Martín'],
            6 => ['materia' => 'Construcción de Ciudadanía', 'docente' => 'Galdeano, Federico'],
            7 => ['materia' => 'Construcción de Ciudadanía', 'docente' => 'Galdeano, Federico'],
            8 => ['materia' => 'Taller', 'docente' => 'Dibujo Técnico'],
            9 => ['materia' => 'Taller', 'docente' => 'Dibujo Técnico']
        ]
    ],
    // Agregar más cursos según necesidad...
];

// Procesar selección de curso
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$materias = [];
$horariosExistentes = [];

if ($cursoSeleccionado) {
    try {
        // Obtener materias del curso
        $materias = $db->fetchAll(
            "SELECT * FROM materias WHERE curso_id = ? ORDER BY nombre",
            [$cursoSeleccionado]
        );
        
        // Obtener horarios existentes
        $horariosExistentes = $db->fetchAll(
            "SELECT h.*, m.nombre as materia_nombre 
             FROM horarios h 
             JOIN materias m ON h.materia_id = m.id 
             WHERE h.curso_id = ? AND h.activo = 1 
             ORDER BY h.dia_semana, h.hora_inicio",
            [$cursoSeleccionado]
        );
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

// Procesar formulario de carga de horario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_horario'])) {
    try {
        $db->transaction(function($db) use ($cursoSeleccionado) {
            foreach ($_POST['horarios'] as $dia => $horas) {
                foreach ($horas as $hora => $datos) {
                    if (!empty($datos['materia_id'])) {
                        $materiaId = intval($datos['materia_id']);
                        $aula = $datos['aula'] ?? '';
                        $docente = $datos['docente'] ?? '';
                        $observaciones = $datos['observaciones'] ?? '';
                        
                        // Obtener horario base
                        global $horariosBase;
                        $horaInicio = $horariosBase[$hora]['inicio'];
                        $horaFin = $horariosBase[$hora]['fin'];
                        
                        // Verificar si ya existe
                        $existe = $db->fetchOne(
                            "SELECT id FROM horarios 
                             WHERE curso_id = ? AND dia_semana = ? AND hora_inicio = ? AND hora_fin = ?",
                            [$cursoSeleccionado, $dia, $horaInicio, $horaFin]
                        );
                        
                        if ($existe) {
                            // Actualizar
                            $db->query(
                                "UPDATE horarios SET 
                                 materia_id = ?, aula = ?, docente = ?, observaciones = ?, updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?",
                                [$materiaId, $aula, $docente, $observaciones, $existe['id']]
                            );
                        } else {
                            // Insertar
                            $db->query(
                                "INSERT INTO horarios 
                                 (curso_id, materia_id, dia_semana, hora_inicio, hora_fin, aula, docente, observaciones)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                [$cursoSeleccionado, $materiaId, $dia, $horaInicio, $horaFin, $aula, $docente, $observaciones]
                            );
                        }
                    }
                }
            }
        });
        
        $_SESSION['message'] = 'Horarios guardados correctamente';
        $_SESSION['message_type'] = 'success';
        
        header("Location: gestion_horarios.php?curso=$cursoSeleccionado");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al guardar horarios: ' . $e->getMessage() . '</div>';
    }
}

// Procesar carga automática de horarios institucionales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cargar_automatico'])) {
    try {
        $nombreCurso = '';
        foreach ($cursos as $curso) {
            if ($curso['id'] == $cursoSeleccionado) {
                $nombreCurso = $curso['nombre'];
                break;
            }
        }
        
        if (isset($horariosInstitucionales[$nombreCurso])) {
            $db->transaction(function($db) use ($cursoSeleccionado, $nombreCurso, $horariosInstitucionales, $horariosBase, $materias) {
                // Limpiar horarios existentes
                $db->query("DELETE FROM horarios WHERE curso_id = ?", [$cursoSeleccionado]);
                
                $horariosCurso = $horariosInstitucionales[$nombreCurso];
                
                foreach ($horariosCurso as $dia => $horas) {
                    foreach ($horas as $hora => $datos) {
                        // Buscar o crear materia
                        $materiaExistente = null;
                        foreach ($materias as $materia) {
                            if (stripos($materia['nombre'], $datos['materia']) !== false || 
                                stripos($datos['materia'], $materia['nombre']) !== false) {
                                $materiaExistente = $materia;
                                break;
                            }
                        }
                        
                        if (!$materiaExistente) {
                            // Crear nueva materia
                            $db->query(
                                "INSERT INTO materias (nombre, codigo, curso_id, activa) VALUES (?, ?, ?, 1)",
                                [$datos['materia'], strtoupper(substr($datos['materia'], 0, 3)), $cursoSeleccionado]
                            );
                            $materiaId = $db->lastInsertId();
                        } else {
                            $materiaId = $materiaExistente['id'];
                        }
                        
                        // Insertar horario
                        $horaInicio = $horariosBase[$hora]['inicio'];
                        $horaFin = $horariosBase[$hora]['fin'];
                        
                        $db->query(
                            "INSERT INTO horarios 
                             (curso_id, materia_id, dia_semana, hora_inicio, hora_fin, docente)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [$cursoSeleccionado, $materiaId, $dia, $horaInicio, $horaFin, $datos['docente']]
                        );
                    }
                }
            });
            
            $_SESSION['message'] = 'Horarios institucionales cargados automáticamente';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'No se encontraron horarios predefinidos para este curso';
            $_SESSION['message_type'] = 'warning';
        }
        
        header("Location: gestion_horarios.php?curso=$cursoSeleccionado");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al cargar horarios automáticos: ' . $e->getMessage() . '</div>';
    }
}
?>

<style>
.horario-cell {
    min-width: 200px;
    padding: 0.5rem;
    border: 1px solid #dee2e6;
    vertical-align: top;
}

.horario-grid {
    overflow-x: auto;
}

.dia-header {
    background-color: #e9ecef;
    font-weight: bold;
    text-align: center;
    padding: 0.75rem;
}

.hora-header {
    background-color: #f8f9fa;
    font-weight: bold;
    text-align: center;
    padding: 0.5rem;
    writing-mode: vertical-rl;
    text-orientation: mixed;
    min-width: 80px;
}

.materia-select {
    width: 100%;
    margin-bottom: 0.25rem;
}

.form-control-sm {
    font-size: 0.8rem;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-calendar3"></i> 
                        Gestión de Horarios - Ciclo Lectivo <?= $cicloActivo['anio'] ?>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Selector de curso -->
                    <form method="GET" action="gestion_horarios.php" class="row g-3 mb-4">
                        <div class="col-md-8">
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
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="btn-group w-100">
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCargaAutomatica">
                                    <i class="bi bi-magic"></i> Carga Automática
                                </button>
                                <a href="gestion_horarios.php?curso=<?= $cursoSeleccionado ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> Recargar
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                    
                    <?php if ($cursoSeleccionado): ?>
                    
                    <!-- Información del curso -->
                    <?php 
                    $nombreCurso = '';
                    foreach ($cursos as $curso) {
                        if ($curso['id'] == $cursoSeleccionado) {
                            $nombreCurso = $curso['nombre'];
                            break;
                        }
                    }
                    ?>
                    
                    <div class="alert alert-info mb-4">
                        <strong>Configurando horarios para:</strong> <?= $nombreCurso ?>
                        <br><small>Total de materias: <?= count($materias) ?> | Horarios configurados: <?= count($horariosExistentes) ?></small>
                    </div>
                    
                    <!-- Formulario de horarios -->
                    <form method="POST" action="gestion_horarios.php?curso=<?= $cursoSeleccionado ?>">
                        <div class="horario-grid">
                            <table class="table table-bordered table-sm">
                                <thead>
                                    <tr>
                                        <th class="hora-header">Hora</th>
                                        <?php foreach ($nombresDias as $diaNum => $nombreDia): ?>
                                            <?php if ($diaNum <= 5): // Solo días hábiles ?>
                                            <th class="dia-header"><?= $nombreDia ?></th>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($horariosBase as $hora => $horarioInfo): ?>
                                        <?php if ($hora != 5): // Excluir almuerzo ?>
                                        <tr>
                                            <td class="hora-header">
                                                <div><?= $horarioInfo['nombre'] ?></div>
                                                <small><?= $horarioInfo['inicio'] ?>-<?= $horarioInfo['fin'] ?></small>
                                            </td>
                                            
                                            <?php foreach ($nombresDias as $diaNum => $nombreDia): ?>
                                                <?php if ($diaNum <= 5): // Solo días hábiles ?>
                                                <?php 
                                                // Buscar horario existente
                                                $horarioExistente = null;
                                                foreach ($horariosExistentes as $he) {
                                                    if ($he['dia_semana'] == $diaNum && $he['hora_inicio'] == $horarioInfo['inicio']) {
                                                        $horarioExistente = $he;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <td class="horario-cell">
                                                    <select name="horarios[<?= $diaNum ?>][<?= $hora ?>][materia_id]" class="form-select form-select-sm materia-select">
                                                        <option value="">-- Sin asignar --</option>
                                                        <?php foreach ($materias as $materia): ?>
                                                        <option value="<?= $materia['id'] ?>" 
                                                                <?= ($horarioExistente && $horarioExistente['materia_id'] == $materia['id']) ? 'selected' : '' ?>>
                                                            <?= $materia['nombre'] ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    
                                                    <input type="text" 
                                                           name="horarios[<?= $diaNum ?>][<?= $hora ?>][aula]" 
                                                           class="form-control form-control-sm mb-1" 
                                                           placeholder="Aula"
                                                           value="<?= $horarioExistente ? htmlspecialchars($horarioExistente['aula']) : '' ?>">
                                                    
                                                    <input type="text" 
                                                           name="horarios[<?= $diaNum ?>][<?= $hora ?>][docente]" 
                                                           class="form-control form-control-sm mb-1" 
                                                           placeholder="Docente"
                                                           value="<?= $horarioExistente ? htmlspecialchars($horarioExistente['docente']) : '' ?>">
                                                    
                                                    <input type="text" 
                                                           name="horarios[<?= $diaNum ?>][<?= $hora ?>][observaciones]" 
                                                           class="form-control form-control-sm" 
                                                           placeholder="Observaciones"
                                                           value="<?= $horarioExistente ? htmlspecialchars($horarioExistente['observaciones']) : '' ?>">
                                                </td>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="text-center mt-3">
                            <button type="submit" name="guardar_horario" class="btn btn-primary btn-lg">
                                <i class="bi bi-save"></i> Guardar Horarios
                            </button>
                        </div>
                    </form>
                    
                    <!-- Resumen de horarios existentes -->
                    <?php if (!empty($horariosExistentes)): ?>
                    <div class="mt-4">
                        <h6><i class="bi bi-list"></i> Resumen de Horarios Configurados</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Día</th>
                                        <th>Horario</th>
                                        <th>Materia</th>
                                        <th>Aula</th>
                                        <th>Docente</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($horariosExistentes as $horario): ?>
                                    <tr>
                                        <td><?= $nombresDias[$horario['dia_semana']] ?></td>
                                        <td><?= $horario['hora_inicio'] ?> - <?= $horario['hora_fin'] ?></td>
                                        <td><?= $horario['materia_nombre'] ?></td>
                                        <td><?= $horario['aula'] ?></td>
                                        <td><?= $horario['docente'] ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="eliminarHorario(<?= $horario['id'] ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        Seleccione un curso para configurar sus horarios.
                    </div>
                    
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para carga automática -->
<?php if ($cursoSeleccionado): ?>
<div class="modal fade" id="modalCargaAutomatica" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Carga Automática de Horarios</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>¿Desea cargar automáticamente los horarios institucionales para <strong><?= $nombreCurso ?></strong>?</p>
                
                <?php if (isset($horariosInstitucionales[$nombreCurso])): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i>
                    Se encontraron horarios predefinidos para este curso.
                </div>
                <p><strong>Advertencia:</strong> Esta acción eliminará todos los horarios existentes y los reemplazará con los horarios institucionales.</p>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    No se encontraron horarios predefinidos para este curso.
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <?php if (isset($horariosInstitucionales[$nombreCurso])): ?>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="cargar_automatico" class="btn btn-success">
                        <i class="bi bi-magic"></i> Cargar Automáticamente
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function eliminarHorario(horarioId) {
    if (confirm('¿Está seguro de eliminar este horario?')) {
        // Enviar petición AJAX para eliminar
        fetch('eliminar_horario.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({id: horarioId})
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error al eliminar el horario');
            }
        });
    }
}
</script>

<?php require_once 'footer.php'; ?>
