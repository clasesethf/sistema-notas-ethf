<?php
/**
 * admin_materias_pendientes.php - Control Administrativo de Materias Pendientes CORREGIDO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * CORREGIDO: Filtros de búsqueda funcionando y soporte para agrupación
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';
require_once 'funciones_grupos_pendientes.php';

// Verificar permisos (solo admin y directivos pueden acceder)
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tienes permisos para acceder a esta sección.';
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
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al conectar con la base de datos: ' . $e->getMessage() . '</div>';
    exit;
}

// Procesar actualizaciones de calificaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verificar qué columnas existen en la tabla
        $columns = $db->fetchAll("PRAGMA table_info(materias_pendientes_intensificacion)");
        $hasAuditColumns = false;
        
        foreach ($columns as $column) {
            if ($column['name'] === 'creado_por_admin') {
                $hasAuditColumns = true;
                break;
            }
        }
        
        if (isset($_POST['accion'])) {
            switch ($_POST['accion']) {
                case 'actualizar_calificacion':
                    $pendiente_id = intval($_POST['pendiente_id']);
                    $campo = $_POST['campo'];
                    $valor = $_POST['valor'] ?? '';
                    
                    // Validar campos permitidos
                    $camposPermitidos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero', 'calificacion_final', 'saberes_cierre', 'saberes_iniciales'];
                    
                    if (in_array($campo, $camposPermitidos)) {
                        // Los admin y directivos pueden editar cualquier calificación
                        if ($hasAuditColumns) {
                            $sql = "UPDATE materias_pendientes_intensificacion SET $campo = ?, fecha_modificacion = DATETIME('now'), modificado_por_admin = ? WHERE id = ?";
                            $db->query($sql, [$valor, $_SESSION['user_id'], $pendiente_id]);
                        } else {
                            $sql = "UPDATE materias_pendientes_intensificacion SET $campo = ? WHERE id = ?";
                            $db->query($sql, [$valor, $pendiente_id]);
                        }
                        
                        $_SESSION['message'] = 'Calificación actualizada exitosamente por administración.';
                        $_SESSION['message_type'] = 'success';
                    }
                    break;

                case 'crear_pendiente':
                    $estudiante_id = intval($_POST['estudiante_id']);
                    $materia_curso_id = intval($_POST['materia_curso_id']);
                    $saberes_iniciales = trim($_POST['saberes_iniciales']);
                    
                    if ($estudiante_id && $materia_curso_id && $saberes_iniciales) {
                        // Verificar que no exista ya
                        $existe = $db->fetchOne(
                            "SELECT id FROM materias_pendientes_intensificacion 
                             WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ? AND estado = 'activo'",
                            [$estudiante_id, $materia_curso_id, $cicloLectivoId]
                        );
                        
                        if (!$existe) {
                            if ($hasAuditColumns) {
                                $db->query(
                                    "INSERT INTO materias_pendientes_intensificacion 
                                     (estudiante_id, materia_curso_id, ciclo_lectivo_id, saberes_iniciales, estado, creado_por_admin, fecha_creacion)
                                     VALUES (?, ?, ?, ?, 'activo', ?, DATETIME('now'))",
                                    [$estudiante_id, $materia_curso_id, $cicloLectivoId, $saberes_iniciales, $_SESSION['user_id']]
                                );
                            } else {
                                $db->query(
                                    "INSERT INTO materias_pendientes_intensificacion 
                                     (estudiante_id, materia_curso_id, ciclo_lectivo_id, saberes_iniciales, estado)
                                     VALUES (?, ?, ?, ?, 'activo')",
                                    [$estudiante_id, $materia_curso_id, $cicloLectivoId, $saberes_iniciales]
                                );
                            }
                            
                            $_SESSION['message'] = 'Materia pendiente creada exitosamente.';
                            $_SESSION['message_type'] = 'success';
                        } else {
                            $_SESSION['message'] = 'Ya existe una materia pendiente activa para este estudiante y materia.';
                            $_SESSION['message_type'] = 'warning';
                        }
                    }
                    break;

                case 'eliminar_pendiente':
                    $pendiente_id = intval($_POST['pendiente_id']);
                    
                    // Marcar como inactivo en lugar de eliminar
                    if ($hasAuditColumns) {
                        $db->query(
                            "UPDATE materias_pendientes_intensificacion 
                             SET estado = 'inactivo', fecha_modificacion = DATETIME('now'), modificado_por_admin = ? 
                             WHERE id = ?",
                            [$_SESSION['user_id'], $pendiente_id]
                        );
                    } else {
                        $db->query(
                            "UPDATE materias_pendientes_intensificacion 
                             SET estado = 'inactivo' 
                             WHERE id = ?",
                            [$pendiente_id]
                        );
                    }
                    
                    $_SESSION['message'] = 'Materia pendiente eliminada exitosamente.';
                    $_SESSION['message_type'] = 'success';
                    break;
            }
        }
        
        // Redireccionar para evitar reenvío del formulario
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING']);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al procesar la solicitud: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Incluir el encabezado
require_once 'header.php';

// OBTENER FILTROS CORREGIDO
$filtroProfesor = isset($_GET['profesor']) && $_GET['profesor'] !== '' ? intval($_GET['profesor']) : null;
$filtroMateria = isset($_GET['materia']) && $_GET['materia'] !== '' ? intval($_GET['materia']) : null;
$filtroCurso = isset($_GET['curso']) && $_GET['curso'] !== '' ? intval($_GET['curso']) : null;
$filtroEstudiante = isset($_GET['estudiante']) && $_GET['estudiante'] !== '' ? intval($_GET['estudiante']) : null;
$filtroEstado = isset($_GET['estado']) && $_GET['estado'] !== '' ? $_GET['estado'] : 'activo';
$mostrarAgrupadas = isset($_GET['agrupadas']) && $_GET['agrupadas'] == '1';

// CONSTRUIR CONSULTA CON FILTROS CORREGIDOS
$whereConditions = ["mpi.ciclo_lectivo_id = ?"];
$params = [$cicloLectivoId];

if ($filtroProfesor) {
    $whereConditions[] = "(mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?)";
    $params[] = $filtroProfesor;
    $params[] = $filtroProfesor;
    $params[] = $filtroProfesor;
}

if ($filtroMateria) {
    $whereConditions[] = "mpc.id = ?";
    $params[] = $filtroMateria;
}

if ($filtroCurso) {
    $whereConditions[] = "c.id = ?";
    $params[] = $filtroCurso;
}

if ($filtroEstudiante) {
    $whereConditions[] = "u.id = ?";
    $params[] = $filtroEstudiante;
}

if ($filtroEstado) {
    $whereConditions[] = "mpi.estado = ?";
    $params[] = $filtroEstado;
}

$whereClause = implode(" AND ", $whereConditions);

try {
    // Verificar qué columnas existen en la tabla
    $columns = $db->fetchAll("PRAGMA table_info(materias_pendientes_intensificacion)");
    $hasAuditColumns = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'creado_por_admin') {
            $hasAuditColumns = true;
            break;
        }
    }
    
    // CONSULTA PRINCIPAL CORREGIDA CON INFORMACIÓN DE GRUPOS
    if ($hasAuditColumns) {
        $selectQuery = "SELECT 
            mpi.*,
            u.nombre as estudiante_nombre,
            u.apellido as estudiante_apellido,
            u.dni as estudiante_dni,
            m.nombre as materia_nombre,
            m.codigo as materia_codigo,
            c.anio as curso_anio,
            c.nombre as curso_nombre,
            c_actual.anio as curso_actual_anio,
            c_actual.nombre as curso_actual_nombre,
            p1.apellido as profesor_apellido,
            p1.nombre as profesor_nombre,
            -- Información de grupos
            gm.nombre as grupo_nombre,
            gm.codigo as grupo_codigo,
            COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
            COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
            CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo,
            -- Auditoría
            admin_creador.apellido as creador_apellido,
            admin_creador.nombre as creador_nombre,
            admin_modificador.apellido as modificador_apellido,
            admin_modificador.nombre as modificador_nombre
         FROM materias_pendientes_intensificacion mpi
         JOIN usuarios u ON mpi.estudiante_id = u.id
         JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
         JOIN materias m ON mpc.materia_id = m.id
         JOIN cursos c ON mpc.curso_id = c.id
         JOIN matriculas mat ON u.id = mat.estudiante_id AND mat.estado = 'activo'
         JOIN cursos c_actual ON mat.curso_id = c_actual.id
         LEFT JOIN usuarios p1 ON mpc.profesor_id = p1.id
         LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
         LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
         LEFT JOIN usuarios admin_creador ON mpi.creado_por_admin = admin_creador.id
         LEFT JOIN usuarios admin_modificador ON mpi.modificado_por_admin = admin_modificador.id";
    } else {
        $selectQuery = "SELECT 
            mpi.*,
            u.nombre as estudiante_nombre,
            u.apellido as estudiante_apellido,
            u.dni as estudiante_dni,
            m.nombre as materia_nombre,
            m.codigo as materia_codigo,
            c.anio as curso_anio,
            c.nombre as curso_nombre,
            c_actual.anio as curso_actual_anio,
            c_actual.nombre as curso_actual_nombre,
            p1.apellido as profesor_apellido,
            p1.nombre as profesor_nombre,
            -- Información de grupos
            gm.nombre as grupo_nombre,
            gm.codigo as grupo_codigo,
            COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
            COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
            CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo,
            -- Sin auditoría
            NULL as creador_apellido,
            NULL as creador_nombre,
            NULL as modificador_apellido,
            NULL as modificador_nombre
         FROM materias_pendientes_intensificacion mpi
         JOIN usuarios u ON mpi.estudiante_id = u.id
         JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
         JOIN materias m ON mpc.materia_id = m.id
         JOIN cursos c ON mpc.curso_id = c.id
         JOIN matriculas mat ON u.id = mat.estudiante_id AND mat.estado = 'activo'
         JOIN cursos c_actual ON mat.curso_id = c_actual.id
         LEFT JOIN usuarios p1 ON mpc.profesor_id = p1.id
         LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
         LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1";
    }
    
    $materiasPendientes = $db->fetchAll(
        $selectQuery . " WHERE $whereClause ORDER BY u.apellido, u.nombre, c.anio, nombre_mostrar",
        $params
    );

    // OBTENER LISTAS PARA FILTROS CORREGIDAS
    $profesores = $db->fetchAll(
        "SELECT DISTINCT u.id, u.apellido, u.nombre
         FROM usuarios u
         JOIN materias_por_curso mpc ON (u.id = mpc.profesor_id OR u.id = mpc.profesor_id_2 OR u.id = mpc.profesor_id_3)
         JOIN cursos c ON mpc.curso_id = c.id
         WHERE u.tipo = 'profesor' AND c.ciclo_lectivo_id = ?
         ORDER BY u.apellido, u.nombre",
        [$cicloLectivoId]
    );

    $materias = $db->fetchAll(
        "SELECT DISTINCT mpc.id, m.nombre, m.codigo, c.anio,
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
         FROM materias_por_curso mpc
         JOIN materias m ON mpc.materia_id = m.id
         JOIN cursos c ON mpc.curso_id = c.id
         LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
         LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
         WHERE c.ciclo_lectivo_id = ?
         ORDER BY c.anio, nombre_mostrar",
        [$cicloLectivoId]
    );

    $cursos = $db->fetchAll(
        "SELECT id, nombre, anio
         FROM cursos
         WHERE ciclo_lectivo_id = ?
         ORDER BY anio",
        [$cicloLectivoId]
    );

    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.apellido, u.nombre, u.dni, c.anio, c.nombre as curso_nombre
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
         JOIN cursos c ON m.curso_id = c.id
         WHERE u.tipo = 'estudiante' AND c.ciclo_lectivo_id = ?
         ORDER BY u.apellido, u.nombre",
        [$cicloLectivoId]
    );

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los datos: ' . $e->getMessage() . '</div>';
    $materiasPendientes = [];
    $profesores = [];
    $materias = [];
    $cursos = [];
    $estudiantes = [];
}

// Si se solicita vista agrupada, procesar datos
if ($mostrarAgrupadas && !empty($materiasPendientes)) {
    // Agrupar materias pendientes por estudiante
    $estudiantesConPendientes = [];
    foreach ($materiasPendientes as $pendiente) {
        $estudianteId = $pendiente['estudiante_id'];
        if (!isset($estudiantesConPendientes[$estudianteId])) {
            $estudiantesConPendientes[$estudianteId] = [
                'estudiante' => [
                    'id' => $estudianteId,
                    'nombre' => $pendiente['estudiante_nombre'],
                    'apellido' => $pendiente['estudiante_apellido'],
                    'dni' => $pendiente['estudiante_dni']
                ],
                'materias_agrupadas' => []
            ];
        }
    }
    
    // Obtener materias agrupadas para cada estudiante
    foreach ($estudiantesConPendientes as $estudianteId => &$datos) {
        try {
            $datos['materias_agrupadas'] = agruparMateriasPendientesPorGrupo($db, $estudianteId, $cicloLectivoId);
            $datos['estadisticas'] = obtenerEstadisticasPendientesAgrupadas($datos['materias_agrupadas']);
        } catch (Exception $e) {
            $datos['materias_agrupadas'] = [];
            $datos['estadisticas'] = ['total' => 0, 'aprobadas' => 0, 'en_proceso' => 0, 'sin_evaluar' => 0];
        }
    }
}

// Estadísticas generales
$estadisticas = [
    'total_pendientes' => count(array_filter($materiasPendientes, function($m) { return $m['estado'] === 'activo'; })),
    'total_aprobadas' => count(array_filter($materiasPendientes, function($m) { 
        return $m['estado'] === 'activo' && !empty($m['calificacion_final']) && $m['calificacion_final'] >= 4; 
    })),
    'total_no_aprobadas' => count(array_filter($materiasPendientes, function($m) { 
        return $m['estado'] === 'activo' && !empty($m['calificacion_final']) && $m['calificacion_final'] < 4; 
    })),
    'total_sin_calificar' => count(array_filter($materiasPendientes, function($m) { 
        return $m['estado'] === 'activo' && empty($m['calificacion_final']); 
    }))
];

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>
                    <i class="bi bi-shield-check"></i> Control Administrativo - Materias Pendientes
                </h1>
                <div class="text-muted">
                    <i class="bi bi-calendar3"></i> Ciclo Lectivo <?= $anioActivo ?>
                    <br><small><?= ucfirst($_SESSION['user_type']) ?>: <?= htmlspecialchars($_SESSION['user_name'] ?? 'N/A') ?></small>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($_SESSION['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <!-- Verificar instalación de tablas -->
    <?php 
    try {
        $tablaExiste = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='materias_pendientes_intensificacion'");
        if (!$tablaExiste) {
            echo '<div class="alert alert-danger">
                <h6><i class="bi bi-exclamation-triangle"></i> Sistema No Instalado</h6>
                <p>Las tablas del sistema de materias pendientes no están instaladas.</p>
                <a href="crear_tablas_pendientes.php" class="btn btn-warning">
                    <i class="bi bi-database-gear"></i> Instalar Sistema
                </a>
            </div>';
        } elseif (!$hasAuditColumns) {
            echo '<div class="alert alert-warning d-none">
                <h6><i class="bi bi-info-circle"></i> Instalación Incompleta</h6>
                <p>Las tablas existen pero faltan columnas de auditoría. Se recomienda actualizar.</p>
                <a href="crear_tablas_pendientes.php" class="btn btn-info btn-sm">
                    <i class="bi bi-arrow-repeat"></i> Actualizar Sistema
                </a>
            </div>';
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error verificando instalación: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>

    <!-- Estadísticas generales -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Pendientes
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_pendientes'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-journal-medical text-primary" style="font-size: 2rem;"></i>
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
                                Aprobadas
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_aprobadas'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                No Aprobadas
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_no_aprobadas'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-x-circle text-danger" style="font-size: 2rem;"></i>
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
                                Sin Calificar
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_sin_calificar'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock text-warning" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros CORREGIDOS -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-funnel"></i> Filtros de Búsqueda</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="profesor" class="form-label">Profesor</label>
                    <select name="profesor" id="profesor" class="form-select">
                        <option value="">Todos los profesores</option>
                        <?php foreach ($profesores as $profesor): ?>
                            <option value="<?= $profesor['id'] ?>" <?= $filtroProfesor == $profesor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($profesor['apellido'] . ', ' . $profesor['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="materia" class="form-label">Materia</label>
                    <select name="materia" id="materia" class="form-select">
                        <option value="">Todas las materias</option>
                        <?php foreach ($materias as $materia): ?>
                            <option value="<?= $materia['id'] ?>" <?= $filtroMateria == $materia['id'] ? 'selected' : '' ?>>
                                <?= $materia['anio'] ?>° - <?= htmlspecialchars($materia['nombre_mostrar']) ?>
                                <?php if ($materia['es_parte_grupo'] == 1): ?>
                                    <small>(Grupo)</small>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="curso" class="form-label">Curso</label>
                    <select name="curso" id="curso" class="form-select">
                        <option value="">Todos los cursos</option>
                        <?php foreach ($cursos as $curso): ?>
                            <option value="<?= $curso['id'] ?>" <?= $filtroCurso == $curso['id'] ? 'selected' : '' ?>>
                                <?= $curso['anio'] ?>° - <?= htmlspecialchars($curso['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="estudiante" class="form-label">Estudiante</label>
                    <select name="estudiante" id="estudiante" class="form-select">
                        <option value="">Todos los estudiantes</option>
                        <?php foreach ($estudiantes as $estudiante): ?>
                            <option value="<?= $estudiante['id'] ?>" <?= $filtroEstudiante == $estudiante['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="estado" class="form-label">Estado</label>
                    <select name="estado" id="estado" class="form-select">
                        <option value="activo" <?= $filtroEstado === 'activo' ? 'selected' : '' ?>>Activos</option>
                        <option value="inactivo" <?= $filtroEstado === 'inactivo' ? 'selected' : '' ?>>Eliminados</option>
                        <option value="" <?= $filtroEstado === '' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                    </div>
                </div>
            </form>
            
            <!-- Opciones de vista -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="agrupadas" id="agrupadas" value="1" 
                               <?= $mostrarAgrupadas ? 'checked' : '' ?> onchange="toggleVistaAgrupada()">
                        <label class="form-check-label" for="agrupadas">
                            <i class="bi bi-collection"></i> Vista Agrupada por Estudiante
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Botones de acción -->
    <div class="mb-3">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCrearPendiente">
            <i class="bi bi-plus-circle"></i> Crear Nueva Materia Pendiente
        </button>
        <a href="reportes_materias_pendientes.php" class="btn btn-info ms-2">
            <i class="bi bi-file-earmark-text"></i> Generar Reporte
        </a>
        <a href="materias_pendientes_agrupadas.php" class="btn btn-warning ms-2">
            <i class="bi bi-collection"></i> Gestión con Agrupación
        </a>
    </div>

    <!-- Contenido principal -->
    <?php if ($mostrarAgrupadas && !empty($estudiantesConPendientes)): ?>
        <!-- Vista agrupada por estudiante -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> 
                    Vista Agrupada por Estudiante 
                    <span class="badge bg-secondary ms-2"><?= count($estudiantesConPendientes) ?> estudiantes</span>
                </h5>
            </div>
            <div class="card-body">
                <?php foreach ($estudiantesConPendientes as $datos): ?>
                    <div class="card mb-3 border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-person"></i> 
                                <?= htmlspecialchars($datos['estudiante']['apellido'] . ', ' . $datos['estudiante']['nombre']) ?>
                                <small class="ms-2">DNI: <?= $datos['estudiante']['dni'] ?></small>
                                <span class="badge bg-light text-dark ms-2"><?= $datos['estadisticas']['total'] ?> pendientes</span>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($datos['materias_agrupadas'])): ?>
                                <?php foreach ($datos['materias_agrupadas'] as $item): ?>
                                    <div class="border rounded p-2 mb-2 <?= $item['es_grupo'] ? 'bg-light' : '' ?>">
                                        <?php if ($item['es_grupo']): ?>
                                            <!-- Grupo de materias -->
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="badge bg-primary me-2">GRUPO</span>
                                                    <strong><?= htmlspecialchars($item['grupo_nombre']) ?></strong>
                                                    <small class="text-muted">
                                                        (<?= count($item['materias']) ?> materias)
                                                    </small>
                                                </div>
                                                <div>
                                                    <?php $estadoGrupo = calcularEstadoGrupoPendiente($item['materias']); ?>
                                                    <span class="badge bg-<?= $estadoGrupo['estado_badge'] ?>">
                                                        <?= $estadoGrupo['estado_texto'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <?php foreach ($item['materias'] as $matGrupo): ?>
                                                    <small class="d-block ms-3">
                                                        <i class="bi bi-arrow-right"></i> 
                                                        <?= htmlspecialchars($matGrupo['materia_nombre']) ?>
                                                        <?php if (!empty($matGrupo['calificacion_final'])): ?>
                                                            <span class="badge bg-<?= $matGrupo['calificacion_final'] >= 4 ? 'success' : 'danger' ?> ms-1">
                                                                <?= $matGrupo['calificacion_final'] ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-primary btn-sm ms-2" 
                                                                onclick="editarPendiente(<?= $matGrupo['id'] ?>, '<?= addslashes($matGrupo['materia_nombre']) ?>')"
                                                                style="padding: 1px 4px; font-size: 0.7em;">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    </small>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <!-- Materia individual -->
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= htmlspecialchars($item['materias'][0]['nombre_mostrar']) ?></strong>
                                                    <small class="text-muted">
                                                        <?= $item['materias'][0]['curso_anio'] ?>° Año
                                                    </small>
                                                </div>
                                                <div>
                                                    <?php if (!empty($item['materias'][0]['calificacion_final'])): ?>
                                                        <span class="badge bg-<?= $item['materias'][0]['calificacion_final'] >= 4 ? 'success' : 'danger' ?>">
                                                            <?= $item['materias'][0]['calificacion_final'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Sin calificar</span>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-outline-primary btn-sm ms-2" 
                                                            onclick="editarPendiente(<?= $item['materias'][0]['id'] ?>, '<?= addslashes($item['materias'][0]['nombre_mostrar']) ?>')">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted mb-0">No hay materias pendientes para este estudiante.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Vista tradicional de tabla -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-table"></i> 
                    Materias Pendientes de Intensificación 
                    <span class="badge bg-secondary ms-2"><?= count($materiasPendientes) ?> registros</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($materiasPendientes)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0" style="font-size: 0.9em;">
                        <thead class="table-dark">
                            <tr>
                                <th>Estudiante</th>
                                <th>Materia/Grupo</th>
                                <th>Profesor</th>
                                <th>Curso Actual</th>
                                <th>Estado Actual</th>
                                <th>Calificación Final</th>
                                <th>Última Modificación</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materiasPendientes as $pendiente): ?>
                            <tr class="<?= $pendiente['estado'] === 'inactivo' ? 'table-danger' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?></strong>
                                    <br><small class="text-muted">DNI: <?= $pendiente['estudiante_dni'] ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($pendiente['nombre_mostrar']) ?></strong>
                                    <?php if ($pendiente['es_parte_grupo'] == 1): ?>
                                        <span class="badge bg-primary ms-1">GRUPO</span>
                                        <br><small class="text-muted">
                                            <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($pendiente['materia_nombre']) ?>
                                        </small>
                                    <?php endif; ?>
                                    <br><small class="text-muted"><?= $pendiente['curso_anio'] ?>° Año</small>
                                    <?php if ($pendiente['codigo_mostrar']): ?>
                                    <br><small class="text-muted"><?= $pendiente['codigo_mostrar'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pendiente['profesor_apellido']): ?>
                                        <?= htmlspecialchars($pendiente['profesor_apellido'] . ', ' . $pendiente['profesor_nombre']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $pendiente['curso_actual_anio'] ?>° - <?= htmlspecialchars($pendiente['curso_actual_nombre']) ?>
                                </td>
                                <td>
                                    <!-- Estado según los períodos completados -->
                                    <?php
                                    $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
                                    $ultimoPeriodo = '';
                                    $ultimoEstado = '';
                                    
                                    foreach ($periodos as $periodo) {
                                        if (!empty($pendiente[$periodo])) {
                                            $ultimoPeriodo = ucfirst($periodo);
                                            $ultimoEstado = $pendiente[$periodo];
                                        }
                                    }
                                    
                                    if ($ultimoEstado === 'AA') {
                                        echo '<span class="badge bg-success">Aprobó y Acreditó (' . $ultimoPeriodo . ')</span>';
                                    } elseif ($ultimoEstado === 'CCA') {
                                        echo '<span class="badge bg-warning">Continúa Con Avances (' . $ultimoPeriodo . ')</span>';
                                    } elseif ($ultimoEstado === 'CSA') {
                                        echo '<span class="badge bg-danger">Continúa Sin Avances (' . $ultimoPeriodo . ')</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">Sin evaluar</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($pendiente['calificacion_final']): ?>
                                        <span class="badge bg-<?= $pendiente['calificacion_final'] >= 7 ? 'success' : ($pendiente['calificacion_final'] >= 4 ? 'warning' : 'danger') ?> fs-6">
                                            <?= $pendiente['calificacion_final'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Sin calificar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($pendiente['fecha_modificacion'])): ?>
                                        <small><?= date('d/m/Y H:i', strtotime($pendiente['fecha_modificacion'])) ?></small>
                                        <?php if (!empty($pendiente['modificador_apellido'])): ?>
                                        <br><small class="text-muted">
                                            por <?= htmlspecialchars($pendiente['modificador_apellido']) ?>
                                        </small>
                                        <?php endif; ?>
                                    <?php elseif (!empty($pendiente['fecha_creacion'])): ?>
                                        <small><?= date('d/m/Y', strtotime($pendiente['fecha_creacion'])) ?></small>
                                        <?php if (!empty($pendiente['creador_apellido'])): ?>
                                        <br><small class="text-muted">
                                            por <?= htmlspecialchars($pendiente['creador_apellido']) ?>
                                        </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small class="text-muted">Sin fecha</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <?php if ($pendiente['estado'] === 'activo'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" 
                                                onclick="editarPendiente(<?= $pendiente['id'] ?>, '<?= addslashes($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?>')"
                                                title="Editar calificaciones">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="verDetallePendiente(<?= $pendiente['id'] ?>)"
                                                title="Ver detalle completo">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                                onclick="eliminarPendiente(<?= $pendiente['id'] ?>, '<?= addslashes($pendiente['estudiante_apellido'] . ', ' . $pendiente['estudiante_nombre']) ?>', '<?= addslashes($pendiente['nombre_mostrar']) ?>')"
                                                title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Eliminado</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-info m-3">
                    <i class="bi bi-info-circle"></i> 
                    No se encontraron materias pendientes con los filtros aplicados.
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal para crear nueva materia pendiente -->
<div class="modal fade" id="modalCrearPendiente" tabindex="-1" aria-labelledby="modalCrearPendienteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearPendienteLabel">
                        <i class="bi bi-plus-circle"></i> Crear Nueva Materia Pendiente
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_pendiente">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="estudiante_id" class="form-label">Estudiante *</label>
                                <select name="estudiante_id" id="estudiante_id" class="form-select" required>
                                    <option value="">Seleccionar estudiante...</option>
                                    <?php foreach ($estudiantes as $estudiante): ?>
                                        <option value="<?= $estudiante['id'] ?>">
                                            <?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?> 
                                            (<?= $estudiante['anio'] ?>° - DNI: <?= $estudiante['dni'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="materia_curso_id" class="form-label">Materia *</label>
                                <select name="materia_curso_id" id="materia_curso_id" class="form-select" required>
                                    <option value="">Seleccionar materia...</option>
                                    <?php foreach ($materias as $materia): ?>
                                        <option value="<?= $materia['id'] ?>">
                                            <?= $materia['anio'] ?>° - <?= htmlspecialchars($materia['nombre_mostrar']) ?>
                                            <?php if ($materia['es_parte_grupo'] == 1): ?>
                                                <small>(Grupo)</small>
                                            <?php endif; ?>
                                            <?php if ($materia['codigo']): ?>(<?= $materia['codigo'] ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="saberes_iniciales" class="form-label">Saberes Iniciales Pendientes *</label>
                        <textarea name="saberes_iniciales" id="saberes_iniciales" class="form-control" rows="4" 
                                  placeholder="Describe los saberes pendientes de aprobación al inicio del ciclo lectivo..." required></textarea>
                        <div class="form-text">
                            Detallar los saberes específicos que el estudiante debe aprobar durante la intensificación.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Crear Materia Pendiente
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal para editar calificaciones -->
<div class="modal fade" id="modalEditarPendiente" tabindex="-1" aria-labelledby="modalEditarPendienteLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditarPendienteLabel">
                    <i class="bi bi-pencil-square"></i> Editar Calificaciones de Intensificación
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoEdicionPendiente">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para ver detalle -->
<div class="modal fade" id="modalDetallePendiente" tabindex="-1" aria-labelledby="modalDetallePendienteLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalDetallePendienteLabel">
                    <i class="bi bi-eye"></i> Detalle Completo de Materia Pendiente
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoDetallePendiente">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulario oculto para eliminar -->
<form id="formEliminar" method="POST" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_pendiente">
    <input type="hidden" name="pendiente_id" id="pendienteIdEliminar">
</form>

<script>
// Función para alternar vista agrupada
function toggleVistaAgrupada() {
    const checkbox = document.getElementById('agrupadas');
    const url = new URL(window.location);
    
    if (checkbox.checked) {
        url.searchParams.set('agrupadas', '1');
    } else {
        url.searchParams.delete('agrupadas');
    }
    
    window.location.href = url.toString();
}

// Función para editar materia pendiente
function editarPendiente(id, estudiante) {
    const modal = new bootstrap.Modal(document.getElementById('modalEditarPendiente'));
    
    // Cargar contenido del modal
    fetch('api_editar_pendiente.php?id=' + id)
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('contenidoEdicionPendiente').innerHTML = html;
            document.getElementById('modalEditarPendienteLabel').innerHTML = 
                '<i class="bi bi-pencil-square"></i> Editar: ' + estudiante;
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoEdicionPendiente').innerHTML = 
                '<div class="alert alert-danger">Error al cargar los datos: ' + error.message + '</div>';
            modal.show();
        });
}

// Función para ver detalle completo
function verDetallePendiente(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalDetallePendiente'));
    
    // Cargar contenido del modal
    fetch('api_detalle_pendiente.php?id=' + id)
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('contenidoDetallePendiente').innerHTML = html;
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoDetallePendiente').innerHTML = 
                '<div class="alert alert-danger">Error al cargar los datos: ' + error.message + '</div>';
            modal.show();
        });
}

// Función para eliminar materia pendiente
function eliminarPendiente(id, estudiante, materia) {
    if (confirm(`¿Está seguro de eliminar la materia pendiente?\n\nEstudiante: ${estudiante}\nMateria: ${materia}\n\nEsta acción marcará el registro como eliminado.`)) {
        document.getElementById('pendienteIdEliminar').value = id;
        document.getElementById('formEliminar').submit();
    }
}
</script>

<style>
.border-left-primary {
    border-left: 0.25rem solid #007bff !important;
}

.border-left-success {
    border-left: 0.25rem solid #28a745 !important;
}

.border-left-danger {
    border-left: 0.25rem solid #dc3545 !important;
}

.border-left-warning {
    border-left: 0.25rem solid #ffc107 !important;
}

.text-xs {
    font-size: 0.7rem;
}

.font-weight-bold {
    font-weight: 700;
}

.text-gray-800 {
    color: #5a5c69;
}

.table th {
    position: sticky;
    top: 0;
    background-color: var(--bs-dark);
    z-index: 10;
}
</style>

<script>
// JavaScript para manejo de modales de materias pendientes

// Función para manejar actualización de calificaciones en el modal
function actualizarCalificacion(pendienteId, campo, valor) {
    if (!pendienteId || !campo) {
        alert('Error: Datos incompletos para la actualización.');
        return;
    }

    // Crear formulario para envío
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';

    // Agregar campos ocultos
    const accionInput = document.createElement('input');
    accionInput.type = 'hidden';
    accionInput.name = 'accion';
    accionInput.value = 'actualizar_calificacion';
    form.appendChild(accionInput);

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'pendiente_id';
    idInput.value = pendienteId;
    form.appendChild(idInput);

    const campoInput = document.createElement('input');
    campoInput.type = 'hidden';
    campoInput.name = 'campo';
    campoInput.value = campo;
    form.appendChild(campoInput);

    const valorInput = document.createElement('input');
    valorInput.type = 'hidden';
    valorInput.name = 'valor';
    valorInput.value = valor;
    form.appendChild(valorInput);

    // Agregar al DOM y enviar
    document.body.appendChild(form);
    form.submit();
}

// Configurar eventos para botones de actualización en modales
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('btn-actualizar-pendiente') || 
        e.target.closest('.btn-actualizar-pendiente')) {
        
        e.preventDefault();
        
        const button = e.target.classList.contains('btn-actualizar-pendiente') ? 
                      e.target : e.target.closest('.btn-actualizar-pendiente');
        
        const pendienteId = button.getAttribute('data-pendiente-id');
        const campo = button.getAttribute('data-campo');
        const inputId = button.getAttribute('data-input-id');
        
        if (inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                const valor = input.value;
                
                // Validaciones específicas
                if (campo === 'calificacion_final' && valor !== '') {
                    const numValor = parseFloat(valor);
                    if (isNaN(numValor) || numValor < 1 || numValor > 10) {
                        alert('La calificación final debe ser un número entre 1 y 10.');
                        return;
                    }
                }
                
                // Confirmar actualización
                if (confirm(`¿Confirma actualizar el campo "${campo}" con el valor "${valor}"?`)) {
                    button.innerHTML = '<i class="bi bi-hourglass-split"></i> Actualizando...';
                    button.disabled = true;
                    
                    actualizarCalificacion(pendienteId, campo, valor);
                }
            }
        }
    }
});

// Función para eliminar desde modal
function eliminarPendienteDesdeModal(pendienteId, estudianteNombre, materiaNombre) {
    if (confirm(`¿Está seguro de eliminar la materia pendiente?\n\nEstudiante: ${estudianteNombre}\nMateria: ${materiaNombre}\n\nEsta acción marcará el registro como eliminado.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';

        const accionInput = document.createElement('input');
        accionInput.type = 'hidden';
        accionInput.name = 'accion';
        accionInput.value = 'eliminar_pendiente';
        form.appendChild(accionInput);

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'pendiente_id';
        idInput.value = pendienteId;
        form.appendChild(idInput);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php require_once 'footer.php'; ?>
