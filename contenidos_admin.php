<?php
/**
 * contenidos_admin.php - Vista administrativa de contenidos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Permite a administradores y directivos ver todos los contenidos del sistema
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar que el usuario sea admin o directivo
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
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

// Obtener filtros
$cursoId = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$profesorId = isset($_GET['profesor']) ? intval($_GET['profesor']) : 0;
$materiaId = isset($_GET['materia']) ? intval($_GET['materia']) : 0;
$bimestre = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 0;

// Obtener listas para filtros
$cursos = [];
$profesores = [];
$materias = [];

try {
    // Obtener cursos
    $cursos = $db->fetchAll(
        "SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio, nombre",
        [$cicloLectivoId]
    );
    
    // Obtener profesores con contenidos
    $profesores = $db->fetchAll(
        "SELECT DISTINCT u.id, u.nombre, u.apellido
         FROM usuarios u
         JOIN contenidos c ON u.id = c.profesor_id
         WHERE u.tipo = 'profesor'
         ORDER BY u.apellido, u.nombre"
    );
    
    // Obtener materias
    $materias = $db->fetchAll(
        "SELECT DISTINCT m.id, m.nombre, m.codigo
         FROM materias m
         JOIN materias_por_curso mp ON m.id = mp.materia_id
         JOIN contenidos c ON mp.id = c.materia_curso_id
         ORDER BY m.nombre"
    );
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
}

// Construir consulta de contenidos
$sql = "SELECT c.*, 
               m.nombre as materia_nombre, m.codigo as materia_codigo,
               cur.nombre as curso_nombre, cur.anio as curso_anio,
               u.nombre as profesor_nombre, u.apellido as profesor_apellido,
               COUNT(DISTINCT cc.estudiante_id) as estudiantes_calificados,
               COUNT(DISTINCT mat.estudiante_id) as total_estudiantes
        FROM contenidos c
        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        JOIN cursos cur ON mp.curso_id = cur.id
        JOIN usuarios u ON c.profesor_id = u.id
        LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id
        LEFT JOIN matriculas mat ON cur.id = mat.curso_id AND mat.estado = 'activo'
        WHERE c.activo = 1 AND cur.ciclo_lectivo_id = ?";

$params = [$cicloLectivoId];

// Aplicar filtros
if ($cursoId > 0) {
    $sql .= " AND cur.id = ?";
    $params[] = $cursoId;
}
if ($profesorId > 0) {
    $sql .= " AND c.profesor_id = ?";
    $params[] = $profesorId;
}
if ($materiaId > 0) {
    $sql .= " AND m.id = ?";
    $params[] = $materiaId;
}
if ($bimestre > 0) {
    $sql .= " AND c.bimestre = ?";
    $params[] = $bimestre;
}

$sql .= " GROUP BY c.id ORDER BY cur.anio, cur.nombre, m.nombre, c.bimestre, c.fecha_clase";

// Obtener contenidos
$contenidos = [];
try {
    $contenidos = $db->fetchAll($sql, $params);
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener contenidos: ' . $e->getMessage() . '</div>';
}

// Estadísticas generales
$estadisticas = [
    'total_contenidos' => count($contenidos),
    'contenidos_numericos' => 0,
    'contenidos_cualitativos' => 0,
    'total_calificados' => 0,
    'total_sin_calificar' => 0
];

foreach ($contenidos as $contenido) {
    if ($contenido['tipo_evaluacion'] == 'numerica') {
        $estadisticas['contenidos_numericos']++;
    } else {
        $estadisticas['contenidos_cualitativos']++;
    }
    
    if ($contenido['estudiantes_calificados'] == $contenido['total_estudiantes']) {
        $estadisticas['total_calificados']++;
    } else if ($contenido['estudiantes_calificados'] == 0) {
        $estadisticas['total_sin_calificar']++;
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-list-check"></i> Administración de Contenidos
            </h1>
            
            <!-- Estadísticas -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Total Contenidos
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?= $estadisticas['total_contenidos'] ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-list-task fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Completamente Calificados
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?= $estadisticas['total_calificados'] ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Evaluación Numérica
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?= $estadisticas['contenidos_numericos'] ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-123 fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        Evaluación Cualitativa
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?= $estadisticas['contenidos_cualitativos'] ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-award fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Filtros</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label for="curso" class="form-label">Curso</label>
                            <select name="curso" id="curso" class="form-select">
                                <option value="0">-- Todos los cursos --</option>
                                <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" <?= $cursoId == $curso['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($curso['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="profesor" class="form-label">Profesor</label>
                            <select name="profesor" id="profesor" class="form-select">
                                <option value="0">-- Todos los profesores --</option>
                                <?php foreach ($profesores as $profesor): ?>
                                    <option value="<?= $profesor['id'] ?>" <?= $profesorId == $profesor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($profesor['apellido'] . ', ' . $profesor['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="materia" class="form-label">Materia</label>
                            <select name="materia" id="materia" class="form-select">
                                <option value="0">-- Todas las materias --</option>
                                <?php foreach ($materias as $materia): ?>
                                    <option value="<?= $materia['id'] ?>" <?= $materiaId == $materia['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($materia['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="bimestre" class="form-label">Bimestre</label>
                            <select name="bimestre" id="bimestre" class="form-select">
                                <option value="0">-- Todos --</option>
                                <option value="1" <?= $bimestre == 1 ? 'selected' : '' ?>>1° Bimestre</option>
                                <option value="2" <?= $bimestre == 2 ? 'selected' : '' ?>>2° Bimestre</option>
                                <option value="3" <?= $bimestre == 3 ? 'selected' : '' ?>>3° Bimestre</option>
                                <option value="4" <?= $bimestre == 4 ? 'selected' : '' ?>>4° Bimestre</option>
                            </select>
                        </div>
                        
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabla de contenidos -->
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Contenidos Registrados
                        <?php if (count($contenidos) > 0): ?>
                        <span class="badge bg-secondary float-end"><?= count($contenidos) ?> contenidos</span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (count($contenidos) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Curso</th>
                                    <th>Materia</th>
                                    <th>Bim.</th>
                                    <th>Título</th>
                                    <th>Profesor</th>
                                    <th>Tipo</th>
                                    <th>Calificados</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contenidos as $contenido): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?></td>
                                    <td><?= htmlspecialchars($contenido['curso_nombre']) ?></td>
                                    <td><?= htmlspecialchars($contenido['materia_nombre']) ?></td>
                                    <td class="text-center"><?= $contenido['bimestre'] ?>°</td>
                                    <td><?= htmlspecialchars($contenido['titulo']) ?></td>
                                    <td><?= htmlspecialchars($contenido['profesor_apellido'] . ', ' . $contenido['profesor_nombre']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $contenido['tipo_evaluacion'] == 'numerica' ? 'primary' : 'success' ?>">
                                            <?= $contenido['tipo_evaluacion'] == 'numerica' ? 'Num' : 'Cual' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $porcentaje = $contenido['total_estudiantes'] > 0 ? 
                                                     round(($contenido['estudiantes_calificados'] / $contenido['total_estudiantes']) * 100) : 0;
                                        $color = $porcentaje == 100 ? 'success' : ($porcentaje == 0 ? 'danger' : 'warning');
                                        ?>
                                        <span class="badge bg-<?= $color ?>">
                                            <?= $contenido['estudiantes_calificados'] ?>/<?= $contenido['total_estudiantes'] ?>
                                            (<?= $porcentaje ?>%)
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="contenidos_detalle_admin.php?id=<?= $contenido['id'] ?>" 
                                           class="btn btn-sm btn-info" title="Ver detalles">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No se encontraron contenidos con los filtros seleccionados.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Acciones adicionales -->
            <div class="mt-4 d-none">
                <div class="btn-group" role="group">
                    <a href="crear_tablas_contenidos.php" class="btn btn-warning">
                        <i class="bi bi-database"></i> Verificar Base de Datos
                    </a>
                    <a href="actualizar_bd_calificaciones.php" class="btn btn-info">
                        <i class="bi bi-arrow-clockwise"></i> Actualizar Sistema Automático
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>