<?php
/**
 * gestionar_recursadas.php - Gestión de estudiantes recursados
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Permite ver, modificar y eliminar registros de materias recursadas
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar que el usuario tenga permisos (admin, directivo o profesor)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    $_SESSION['message'] = 'No tienes permisos para acceder a esta página.';
    $_SESSION['message_type'] = 'danger';
    header('Location: dashboard.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar acciones
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'cambiar_tipo':
                $calificacionId = intval($_POST['calificacion_id']);
                $nuevoTipo = $_POST['nuevo_tipo'];
                
                if (in_array($nuevoTipo, ['C', 'R'])) {
                    $result = $db->query(
                        "UPDATE calificaciones SET tipo_cursada = ? WHERE id = ?",
                        [$nuevoTipo, $calificacionId]
                    );
                    $message = 'Tipo de cursada actualizado correctamente.';
                    $messageType = 'success';
                } else {
                    $message = 'Tipo de cursada inválido.';
                    $messageType = 'danger';
                }
                break;
                
            case 'eliminar_calificacion':
                $calificacionId = intval($_POST['calificacion_id']);
                
                $result = $db->query("DELETE FROM calificaciones WHERE id = ?", [$calificacionId]);
                $message = 'Registro de calificación eliminado correctamente.';
                $messageType = 'success';
                break;
                
            case 'corregir_automatico':
                // Corregir automáticamente todos los tipos de cursada
                
                // Primera consulta: marcar como 'R' las que deberían ser recursadas
                $queryActualizarR = "
                    UPDATE calificaciones 
                    SET tipo_cursada = 'R'
                    WHERE EXISTS (
                        SELECT 1
                        FROM usuarios u
                        JOIN matriculas mat ON u.id = mat.estudiante_id
                        JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
                        JOIN materias_por_curso mp ON calificaciones.materia_curso_id = mp.id
                        JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
                        WHERE calificaciones.estudiante_id = u.id
                          AND u.tipo = 'estudiante'
                          AND curso_estudiante.anio > curso_materia.anio 
                          AND calificaciones.tipo_cursada != 'R'
                    )
                ";
                
                // Segunda consulta: marcar como 'C' las que deberían ser cursadas
                $queryActualizarC = "
                    UPDATE calificaciones 
                    SET tipo_cursada = 'C'
                    WHERE EXISTS (
                        SELECT 1
                        FROM usuarios u
                        JOIN matriculas mat ON u.id = mat.estudiante_id
                        JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
                        JOIN materias_por_curso mp ON calificaciones.materia_curso_id = mp.id
                        JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
                        WHERE calificaciones.estudiante_id = u.id
                          AND u.tipo = 'estudiante'
                          AND curso_estudiante.anio = curso_materia.anio 
                          AND calificaciones.tipo_cursada != 'C'
                    )
                ";
                
                $result1 = $db->query($queryActualizarR);
                $result2 = $db->query($queryActualizarC);
                
                $message = 'Corrección automática aplicada correctamente.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Obtener filtros
$filtroTipo = $_GET['tipo'] ?? 'todos';
$filtroCurso = $_GET['curso'] ?? 'todos';
$filtroEstudiante = $_GET['estudiante'] ?? '';

// Construir consulta con filtros
$whereConditions = [];
$params = [];

if ($filtroTipo !== 'todos') {
    $whereConditions[] = "c.tipo_cursada = ?";
    $params[] = $filtroTipo;
}

if ($filtroCurso !== 'todos') {
    $whereConditions[] = "curso_estudiante.id = ?";
    $params[] = intval($filtroCurso);
}

if (!empty($filtroEstudiante)) {
    $whereConditions[] = "(u.nombre LIKE ? OR u.apellido LIKE ? OR u.dni LIKE ?)";
    $busqueda = '%' . $filtroEstudiante . '%';
    $params[] = $busqueda;
    $params[] = $busqueda;
    $params[] = $busqueda;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Obtener todas las calificaciones con información detallada
try {
    $query = "
        SELECT 
            c.id as calificacion_id,
            u.id as estudiante_id,
            u.nombre as estudiante_nombre,
            u.apellido as estudiante_apellido,
            u.dni as estudiante_dni,
            curso_estudiante.nombre as curso_estudiante_nombre,
            curso_estudiante.anio as anio_estudiante,
            m.nombre as materia_nombre,
            m.codigo as materia_codigo,
            curso_materia.anio as anio_materia,
            c.tipo_cursada,
            c.valoracion_preliminar_1c,
            c.calificacion_1c,
            c.valoracion_preliminar_2c,
            c.calificacion_2c,
            c.calificacion_final,
            CASE 
                WHEN curso_estudiante.anio > curso_materia.anio THEN 'DEBERÍA SER R'
                WHEN curso_estudiante.anio = curso_materia.anio THEN 'DEBERÍA SER C'
                ELSE 'VERIFICAR'
            END as tipo_sugerido,
            CASE 
                WHEN curso_estudiante.anio > curso_materia.anio AND c.tipo_cursada != 'R' THEN 'ERROR'
                WHEN curso_estudiante.anio = curso_materia.anio AND c.tipo_cursada != 'C' THEN 'ERROR'
                ELSE 'OK'
            END as estado
        FROM calificaciones c
        JOIN usuarios u ON c.estudiante_id = u.id AND u.tipo = 'estudiante'
        JOIN matriculas mat ON u.id = mat.estudiante_id
        JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
        $whereClause
        ORDER BY u.apellido, u.nombre, m.nombre
    ";
    
    $calificaciones = $db->fetchAll($query, $params);
    
    // Obtener cursos para el filtro
    $cursos = $db->fetchAll("SELECT id, nombre, anio FROM cursos ORDER BY anio");
    
} catch (Exception $e) {
    $message = 'Error al obtener datos: ' . $e->getMessage();
    $messageType = 'danger';
    $calificaciones = [];
    $cursos = [];
}

// Contar estadísticas
$totalRegistros = count($calificaciones);
$totalErrores = count(array_filter($calificaciones, function($cal) { return $cal['estado'] === 'ERROR'; }));
$totalRecursadas = count(array_filter($calificaciones, function($cal) { return $cal['tipo_cursada'] === 'R'; }));
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Recursadas - ETHS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .error-row { background-color: #ffebee; }
        .recursada-row { background-color: #fff3e0; }
        .ok-row { background-color: #e8f5e8; }
        .table-container { max-height: 600px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-arrow-repeat"></i> Gestión de Recursadas</h1>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver al Dashboard
                    </a>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Registros</h5>
                                <h2><?= $totalRegistros ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h5 class="card-title">Recursadas</h5>
                                <h2><?= $totalRecursadas ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h5 class="card-title">Con Errores</h5>
                                <h2><?= $totalErrores ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Correctas</h5>
                                <h2><?= $totalRegistros - $totalErrores ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones masivas -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-tools"></i> Acciones Masivas</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" onsubmit="return confirm('¿Estás seguro de aplicar la corrección automática? Esto modificará todos los registros incorrectos.')">
                            <input type="hidden" name="action" value="corregir_automatico">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-magic"></i> Corregir Automáticamente
                            </button>
                            <small class="text-muted">
                                Esto marcará automáticamente como 'R' las materias donde el estudiante esté en un año superior al de la materia.
                            </small>
                        </form>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-funnel"></i> Filtros</h5>
                    </div>
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Tipo de Cursada</label>
                                <select name="tipo" class="form-select">
                                    <option value="todos" <?= $filtroTipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                                    <option value="C" <?= $filtroTipo === 'C' ? 'selected' : '' ?>>Cursadas (C)</option>
                                    <option value="R" <?= $filtroTipo === 'R' ? 'selected' : '' ?>>Recursadas (R)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Curso del Estudiante</label>
                                <select name="curso" class="form-select">
                                    <option value="todos">Todos los cursos</option>
                                    <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" <?= $filtroCurso == $curso['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($curso['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Buscar Estudiante</label>
                                <input type="text" name="estudiante" class="form-control" 
                                       placeholder="Nombre, apellido o DNI" 
                                       value="<?= htmlspecialchars($filtroEstudiante) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary d-block">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de resultados -->
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-table"></i> Registros de Calificaciones</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-container">
                            <table class="table table-striped table-hover mb-0">
                                <thead class="table-dark sticky-top">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Curso</th>
                                        <th>Materia</th>
                                        <th>Año Mat.</th>
                                        <th>Tipo Actual</th>
                                        <th>Tipo Sugerido</th>
                                        <th>Estado</th>
                                        <th>Calificaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($calificaciones)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="bi bi-search"></i> No se encontraron registros con los filtros aplicados.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($calificaciones as $cal): ?>
                                    <?php 
                                        $rowClass = '';
                                        if ($cal['estado'] === 'ERROR') $rowClass = 'error-row';
                                        elseif ($cal['tipo_cursada'] === 'R') $rowClass = 'recursada-row';
                                        else $rowClass = 'ok-row';
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($cal['estudiante_apellido']) ?>, <?= htmlspecialchars($cal['estudiante_nombre']) ?></strong>
                                            <br><small class="text-muted">DNI: <?= htmlspecialchars($cal['estudiante_dni']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($cal['curso_estudiante_nombre']) ?> (<?= $cal['anio_estudiante'] ?>°)</td>
                                        <td>
                                            <strong><?= htmlspecialchars($cal['materia_nombre']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($cal['materia_codigo']) ?></small>
                                        </td>
                                        <td class="text-center"><?= $cal['anio_materia'] ?>°</td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $cal['tipo_cursada'] === 'R' ? 'warning' : 'primary' ?>">
                                                <?= $cal['tipo_cursada'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= strpos($cal['tipo_sugerido'], 'R') !== false ? 'warning' : 'primary' ?>">
                                                <?= $cal['tipo_sugerido'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $cal['estado'] === 'ERROR' ? 'danger' : 'success' ?>">
                                                <?= $cal['estado'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small>
                                                1°C: <?= $cal['calificacion_1c'] ?? '-' ?> | 
                                                2°C: <?= $cal['calificacion_2c'] ?? '-' ?> | 
                                                Final: <?= $cal['calificacion_final'] ?? '-' ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group-vertical btn-group-sm">
                                                <!-- Cambiar tipo -->
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="cambiar_tipo">
                                                    <input type="hidden" name="calificacion_id" value="<?= $cal['calificacion_id'] ?>">
                                                    <select name="nuevo_tipo" class="form-select form-select-sm mb-1" onchange="this.form.submit()">
                                                        <option value="C" <?= $cal['tipo_cursada'] === 'C' ? 'selected' : '' ?>>C - Cursada</option>
                                                        <option value="R" <?= $cal['tipo_cursada'] === 'R' ? 'selected' : '' ?>>R - Recursada</option>
                                                    </select>
                                                </form>
                                                
                                                <!-- Eliminar -->
                                                <form method="post" class="d-inline" onsubmit="return confirm('¿Estás seguro de eliminar este registro? Esta acción no se puede deshacer.')">
                                                    <input type="hidden" name="action" value="eliminar_calificacion">
                                                    <input type="hidden" name="calificacion_id" value="<?= $cal['calificacion_id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Leyenda -->
                <div class="mt-3">
                    <small class="text-muted">
                        <strong>Leyenda:</strong>
                        <span class="badge bg-danger">ERROR</span> Registro incorrecto |
                        <span class="badge bg-warning">R</span> Recursada |
                        <span class="badge bg-primary">C</span> Cursada |
                        <span class="badge bg-success">OK</span> Correcto
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>