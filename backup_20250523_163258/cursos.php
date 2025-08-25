<?php
/**
 * cursos.php - Gestión de cursos del sistema
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Iniciar buffer de salida al principio
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos) ANTES de incluir header.php
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar acciones ANTES de incluir header.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'crear_curso':
            $resultado = crearCurso($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'editar_curso':
            $resultado = editarCurso($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'eliminar_curso':
            $resultado = eliminarCurso($db, $_POST['curso_id']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'matricular_estudiante':
            $resultado = matricularEstudiante($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'cambiar_estado_matricula':
            $resultado = cambiarEstadoMatricula($db, $_POST['matricula_id'], $_POST['nuevo_estado']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'asignar_materia_recursado':
            $resultado = asignarMateriaRecursado($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
    }
    
    // Variables para filtros
    $cicloFiltro = isset($_POST['ciclo_original']) ? $_POST['ciclo_original'] : '';
    $busqueda = isset($_POST['busqueda_original']) ? $_POST['busqueda_original'] : '';
    $paginaActual = isset($_POST['pagina_original']) ? $_POST['pagina_original'] : 1;
    
    // Redireccionar para evitar reenvío del formulario
    $queryParams = [];
    if (!empty($cicloFiltro)) $queryParams['ciclo'] = $cicloFiltro;
    if (!empty($busqueda)) $queryParams['busqueda'] = $busqueda;
    if ($paginaActual > 1) $queryParams['pagina'] = $paginaActual;
    
    $redirectUrl = 'cursos.php';
    if (!empty($queryParams)) {
        $redirectUrl .= '?' . http_build_query($queryParams);
    }
    
    header('Location: ' . $redirectUrl);
    exit;
}

// Variables para filtros y paginación
$cicloFiltro = isset($_GET['ciclo']) ? intval($_GET['ciclo']) : '';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$paginaActual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registrosPorPagina = 15;
$offset = ($paginaActual - 1) * $registrosPorPagina;

// AHORA incluir el encabezado después de procesar POST
require_once 'header.php';

// Obtener ciclos lectivos
$ciclosDisponibles = $db->fetchAll("SELECT * FROM ciclos_lectivos ORDER BY anio DESC");

// Construir consulta con filtros
$whereClause = 'WHERE 1=1';
$parametros = [];

if (!empty($cicloFiltro)) {
    $whereClause .= ' AND c.ciclo_lectivo_id = ?';
    $parametros[] = $cicloFiltro;
}

if (!empty($busqueda)) {
    $whereClause .= ' AND c.nombre LIKE ?';
    $parametros[] = "%$busqueda%";
}

// Obtener total de registros para paginación
$totalRegistros = $db->fetchOne(
    "SELECT COUNT(*) as total FROM cursos c $whereClause",
    $parametros
)['total'];

$totalPaginas = ceil($totalRegistros / $registrosPorPagina);

// Obtener cursos con información adicional
$cursos = $db->fetchAll(
    "SELECT c.id, c.nombre, c.anio,
            cl.anio as ciclo_anio, cl.activo as ciclo_activo,
            COUNT(DISTINCT m.id) as total_matriculados,
            COUNT(DISTINCT CASE WHEN m.estado = 'activo' THEN m.id END) as matriculados_activos,
            COUNT(DISTINCT mp.id) as materias_asignadas
     FROM cursos c
     JOIN ciclos_lectivos cl ON c.ciclo_lectivo_id = cl.id
     LEFT JOIN matriculas m ON c.id = m.curso_id
     LEFT JOIN materias_por_curso mp ON c.id = mp.curso_id
     $whereClause
     GROUP BY c.id, c.nombre, c.anio, cl.anio, cl.activo
     ORDER BY cl.anio DESC, c.anio, c.nombre
     LIMIT ? OFFSET ?",
    array_merge($parametros, [$registrosPorPagina, $offset])
);

// Obtener estadísticas generales
$estadisticas = $db->fetchOne(
    "SELECT 
        COUNT(DISTINCT c.id) as total_cursos,
        COUNT(DISTINCT m.id) as total_matriculas,
        COUNT(DISTINCT CASE WHEN m.estado = 'activo' THEN m.id END) as matriculas_activas,
        COUNT(DISTINCT mp.id) as total_asignaciones_materias
     FROM cursos c
     LEFT JOIN matriculas m ON c.id = m.curso_id
     LEFT JOIN materias_por_curso mp ON c.id = mp.curso_id"
);

// Obtener estudiantes para matriculación
$estudiantes = $db->fetchAll(
    "SELECT id, nombre, apellido, dni FROM usuarios WHERE tipo = 'estudiante' AND activo = 1 ORDER BY apellido, nombre"
);

// Obtener materias disponibles
$materias = $db->fetchAll(
    "SELECT id, nombre, codigo FROM materias ORDER BY nombre"
);

/**
 * Función para crear un nuevo curso
 */
function crearCurso($db, $datos) {
    try {
        $nombre = trim($datos['nombre']);
        $anio = intval($datos['anio']);
        $cicloLectivoId = intval($datos['ciclo_lectivo_id']);
        
        // Validaciones
        if (empty($nombre) || $anio < 1 || $anio > 7 || $cicloLectivoId <= 0) {
            return ['type' => 'danger', 'message' => 'Todos los campos obligatorios deben completarse correctamente'];
        }
        
        // Verificar si ya existe un curso con el mismo nombre en el mismo ciclo
        $cursoExistente = $db->fetchOne(
            "SELECT id FROM cursos WHERE nombre = ? AND ciclo_lectivo_id = ?", 
            [$nombre, $cicloLectivoId]
        );
        if ($cursoExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe un curso con ese nombre en el ciclo lectivo seleccionado'];
        }
        
        // Crear curso
        $db->query(
            "INSERT INTO cursos (nombre, anio, ciclo_lectivo_id) 
             VALUES (?, ?, ?)",
            [$nombre, $anio, $cicloLectivoId]
        );
        
        return ['type' => 'success', 'message' => 'Curso creado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al crear curso: ' . $e->getMessage()];
    }
}

/**
 * Función para editar un curso
 */
function editarCurso($db, $datos) {
    try {
        $cursoId = intval($datos['curso_id']);
        $nombre = trim($datos['nombre']);
        $anio = intval($datos['anio']);
        $cicloLectivoId = intval($datos['ciclo_lectivo_id']);
        
        // Validaciones
        if (empty($nombre) || $anio < 1 || $anio > 7 || $cicloLectivoId <= 0) {
            return ['type' => 'danger', 'message' => 'Todos los campos obligatorios deben completarse correctamente'];
        }
        
        // Verificar si ya existe otro curso con el mismo nombre en el mismo ciclo
        $cursoExistente = $db->fetchOne(
            "SELECT id FROM cursos WHERE nombre = ? AND ciclo_lectivo_id = ? AND id != ?", 
            [$nombre, $cicloLectivoId, $cursoId]
        );
        if ($cursoExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe otro curso con ese nombre en el ciclo lectivo seleccionado'];
        }
        
        // Actualizar curso
        $db->query(
            "UPDATE cursos 
             SET nombre = ?, anio = ?, ciclo_lectivo_id = ?
             WHERE id = ?",
            [$nombre, $anio, $cicloLectivoId, $cursoId]
        );
        
        return ['type' => 'success', 'message' => 'Curso actualizado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al actualizar curso: ' . $e->getMessage()];
    }
}

/**
 * Función para eliminar un curso
 */
function eliminarCurso($db, $cursoId) {
    try {
        $cursoId = intval($cursoId);
        
        // Verificar que el curso existe
        $curso = $db->fetchOne("SELECT * FROM cursos WHERE id = ?", [$cursoId]);
        if (!$curso) {
            return ['type' => 'danger', 'message' => 'Curso no encontrado'];
        }
        
        // Verificar si tiene matriculaciones
        $matriculaciones = $db->fetchOne("SELECT COUNT(*) as count FROM matriculas WHERE curso_id = ?", [$cursoId]);
        if ($matriculaciones['count'] > 0) {
            return ['type' => 'danger', 'message' => 'No se puede eliminar el curso porque tiene estudiantes matriculados'];
        }
        
        // Verificar si tiene materias asignadas
        $materias = $db->fetchOne("SELECT COUNT(*) as count FROM materias_por_curso WHERE curso_id = ?", [$cursoId]);
        if ($materias['count'] > 0) {
            return ['type' => 'danger', 'message' => 'No se puede eliminar el curso porque tiene materias asignadas'];
        }
        
        // Eliminar curso
        $db->query("DELETE FROM cursos WHERE id = ?", [$cursoId]);
        
        return ['type' => 'success', 'message' => 'Curso eliminado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar curso: ' . $e->getMessage()];
    }
}

/**
 * Función para matricular estudiante
 */
function matricularEstudiante($db, $datos) {
    try {
        $estudianteId = intval($datos['estudiante_id']);
        $cursoId = intval($datos['curso_id']);
        
        if ($estudianteId <= 0 || $cursoId <= 0) {
            return ['type' => 'danger', 'message' => 'Datos inválidos para la matriculación'];
        }
        
        // Verificar si ya está matriculado en el curso
        $matriculaExistente = $db->fetchOne(
            "SELECT id FROM matriculas WHERE estudiante_id = ? AND curso_id = ?",
            [$estudianteId, $cursoId]
        );
        
        if ($matriculaExistente) {
            return ['type' => 'danger', 'message' => 'El estudiante ya está matriculado en este curso'];
        }
        
        // Crear matriculación
        $db->query(
            "INSERT INTO matriculas (estudiante_id, curso_id, fecha_matriculacion, estado) 
             VALUES (?, ?, date('now'), 'activo')",
            [$estudianteId, $cursoId]
        );
        
        return ['type' => 'success', 'message' => 'Estudiante matriculado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al matricular estudiante: ' . $e->getMessage()];
    }
}

/**
 * Función para cambiar estado de matrícula
 */
function cambiarEstadoMatricula($db, $matriculaId, $nuevoEstado) {
    try {
        $matriculaId = intval($matriculaId);
        
        $db->query("UPDATE matriculas SET estado = ? WHERE id = ?", [$nuevoEstado, $matriculaId]);
        
        $estadoTexto = $nuevoEstado === 'activo' ? 'activada' : 'desactivada';
        return ['type' => 'success', 'message' => "Matrícula $estadoTexto correctamente"];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al cambiar estado de matrícula: ' . $e->getMessage()];
    }
}

/**
 * Función para asignar materia en recursado
 */
function asignarMateriaRecursado($db, $datos) {
    try {
        $estudianteId = intval($datos['estudiante_id']);
        $materiaCursoId = intval($datos['materia_curso_id']);
        $observaciones = trim($datos['observaciones'] ?? '');
        
        if ($estudianteId <= 0 || $materiaCursoId <= 0) {
            return ['type' => 'danger', 'message' => 'Datos inválidos para la asignación'];
        }
        
        // Verificar si ya está asignado
        $asignacionExistente = $db->fetchOne(
            "SELECT id FROM materias_recursado WHERE estudiante_id = ? AND materia_curso_id = ?",
            [$estudianteId, $materiaCursoId]
        );
        
        if ($asignacionExistente) {
            return ['type' => 'danger', 'message' => 'El estudiante ya tiene asignada esta materia para recursado'];
        }
        
        // Crear asignación de recursado
        $db->query(
            "INSERT INTO materias_recursado (estudiante_id, materia_curso_id, fecha_asignacion, observaciones, estado) 
             VALUES (?, ?, date('now'), ?, 'activo')",
            [$estudianteId, $materiaCursoId, $observaciones]
        );
        
        return ['type' => 'success', 'message' => 'Materia asignada para recursado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al asignar materia para recursado: ' . $e->getMessage()];
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Resumen de Cursos</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h3 class="text-primary"><?= $estadisticas['total_cursos'] ?></h3>
                                    <p class="card-text">Total Cursos</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h3 class="text-success"><?= $estadisticas['matriculas_activas'] ?></h3>
                                    <p class="card-text">Matrículas Activas</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h3 class="text-info"><?= $estadisticas['total_matriculas'] ?></h3>
                                    <p class="card-text">Total Matrículas</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-warning">
                                <div class="card-body">
                                    <h3 class="text-warning"><?= $estadisticas['total_asignaciones_materias'] ?></h3>
                                    <p class="card-text">Materias Asignadas</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros y acciones -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Gestión de Cursos</h5>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearCurso">
                            <i class="bi bi-plus-circle"></i> Nuevo Curso
                        </button>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalMatricularEstudiante">
                            <i class="bi bi-person-plus"></i> Matricular Estudiante
                        </button>
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalAsignarRecursado">
                            <i class="bi bi-arrow-repeat"></i> Asignar Recursado
                        </button>
                        <a href="gestionar_recursados.php" class="btn btn-info">
                            <i class="bi bi-list-check"></i> Gestionar Recursados
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filtros -->
                    <form method="GET" action="cursos.php" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <label for="ciclo" class="form-label">Ciclo Lectivo:</label>
                                <select name="ciclo" id="ciclo" class="form-select">
                                    <option value="">-- Todos los ciclos --</option>
                                    <?php foreach ($ciclosDisponibles as $ciclo): ?>
                                    <option value="<?= $ciclo['id'] ?>" <?= $cicloFiltro == $ciclo['id'] ? 'selected' : '' ?>>
                                        <?= $ciclo['anio'] ?> <?= $ciclo['activo'] ? '(Activo)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label for="busqueda" class="form-label">Buscar Curso:</label>
                                <input type="text" name="busqueda" id="busqueda" class="form-control" 
                                       placeholder="Nombre del curso" value="<?= htmlspecialchars($busqueda) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Buscar
                                </button>
                                <a href="cursos.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Tabla de cursos -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Curso</th>
                                    <th>Año</th>
                                    <th>Ciclo</th>
                                    <th>Matriculados</th>
                                    <th>Materias</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($cursos) > 0): ?>
                                    <?php foreach ($cursos as $curso): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($curso['nombre']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= $curso['anio'] ?>°</span>
                                        </td>
                                        <td>
                                            <?= $curso['ciclo_anio'] ?>
                                            <?php if ($curso['ciclo_activo']): ?>
                                                <span class="badge bg-success">Activo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?= $curso['matriculados_activos'] ?></span>
                                            <?php if ($curso['total_matriculados'] != $curso['matriculados_activos']): ?>
                                                <small class="text-muted">/ <?= $curso['total_matriculados'] ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= $curso['materias_asignadas'] ?></span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        onclick="editarCurso(<?= $curso['id'] ?>)"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditarCurso">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                
                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                        onclick="verEstudiantes(<?= $curso['id'] ?>)"
                                                        data-bs-toggle="modal" data-bs-target="#modalVerEstudiantes">
                                                    <i class="bi bi-people"></i>
                                                </button>
                                                
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="eliminarCurso(<?= $curso['id'] ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No se encontraron cursos</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginación de cursos">
                        <ul class="pagination justify-content-center">
                            <?php if ($paginaActual > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual - 1])) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++): ?>
                                <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($paginaActual < $totalPaginas): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual + 1])) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    
                    <div class="text-center">
                        <small class="text-muted">
                            Mostrando <?= $offset + 1 ?> - <?= min($offset + $registrosPorPagina, $totalRegistros) ?> 
                            de <?= $totalRegistros ?> cursos
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modales -->
<?php include 'modales_cursos.php'; ?>

<!-- Scripts -->
<script src="js/cursos.js"></script>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>