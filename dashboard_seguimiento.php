<?php
/**
 * dashboard_seguimiento.php - Dashboard de seguimiento de calificaciones
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Para administradores y directivos
 * VERSIÓN COMPLETA CORREGIDA: Soporta valoraciones y cuatrimestres
 */

require_once 'config.php';

// Verificar sesión y permisos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    header('Location: login.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
if (!$cicloActivo) {
    $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$cicloLectivoId = $cicloActivo['id'];

// Obtener filtros - VERSIÓN CORREGIDA
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$tipoCalificacion = isset($_GET['tipo']) ? $_GET['tipo'] : 'cuatrimestre';
$periodoSeleccionado = isset($_GET['periodo']) ? intval($_GET['periodo']) : 1;

// Validar tipo de calificación
if (!in_array($tipoCalificacion, ['cuatrimestre', 'valoracion'])) {
    $tipoCalificacion = 'cuatrimestre';
}

// Validar período según el tipo
if ($tipoCalificacion === 'valoracion') {
    // Para valoraciones: 1 o 3 (bimestres)
    if (!in_array($periodoSeleccionado, [1, 3])) {
        $periodoSeleccionado = 1;
    }
} else {
    // Para cuatrimestres: 1 o 2
    if (!in_array($periodoSeleccionado, [1, 2])) {
        $periodoSeleccionado = 1;
    }
}

// Obtener cursos disponibles
$cursos = $db->fetchAll(
    "SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio",
    [$cicloLectivoId]
);

/**
 * Función mejorada para obtener estadísticas de carga de calificaciones
 * Incluye manejo correcto de recursados y liberados
 * Soporta tanto cuatrimestres como valoraciones preliminares
 */
function obtenerEstadisticasCalificaciones($db, $cicloLectivoId, $cursoId = null, $tipoCalificacion = 'cuatrimestre', $periodo = 1) {
    $whereClause = $cursoId ? "AND c.id = ?" : "";
    
    // Determinar el campo de calificación según el tipo y periodo
    if ($tipoCalificacion === 'valoracion') {
        // Para valoraciones preliminares (bimestrales)
        if ($periodo == 1) {
            $campo_calificacion = 'valoracion_1bim';
            $descripcion_periodo = '1er Bimestre';
        } else {
            $campo_calificacion = 'valoracion_3bim';
            $descripcion_periodo = '3er Bimestre';
        }
    } else {
        // Para calificaciones cuatrimestrales
        if ($periodo == 1) {
            $campo_calificacion = 'calificacion_1c';
            $descripcion_periodo = '1er Cuatrimestre';
        } else {
            $campo_calificacion = 'calificacion_2c';
            $descripcion_periodo = '2do Cuatrimestre';
        }
    }
    
    // Primero obtenemos la información básica de materias
    $queryBase = "
        SELECT 
            c.id as curso_id,
            c.nombre as curso_nombre,
            c.anio as curso_anio,
            m.id as materia_id,
            m.nombre as materia_nombre,
            m.codigo as materia_codigo,
            mp.id as materia_curso_id,
            u.apellido || ', ' || u.nombre as profesor_nombre,
            mp.requiere_subgrupos
        FROM cursos c
        JOIN materias_por_curso mp ON c.id = mp.curso_id
        JOIN materias m ON mp.materia_id = m.id
        LEFT JOIN usuarios u ON mp.profesor_id = u.id
        WHERE c.ciclo_lectivo_id = ? {$whereClause}
        ORDER BY c.anio, m.nombre
    ";
    
    $params = $cursoId ? [$cicloLectivoId, $cursoId] : [$cicloLectivoId];
    $estadisticas = $db->fetchAll($queryBase, $params);
    
    // Ahora calculamos los totales para cada materia
    foreach ($estadisticas as &$stat) {
        $materiaCursoId = $stat['materia_curso_id'];
        $cursoIdMateria = $stat['curso_id'];
        
        // Contar estudiantes regulares del curso
        $estudiantesRegulares = $db->fetchOne(
            "SELECT COUNT(DISTINCT mat.estudiante_id) as count
             FROM matriculas mat
             WHERE mat.curso_id = ? AND mat.estado = 'activo'",
            [$cursoIdMateria]
        )['count'] ?? 0;
        
        // Contar estudiantes recursando esta materia específica
        $estudiantesRecursando = $db->fetchOne(
            "SELECT COUNT(DISTINCT mr.estudiante_id) as count
             FROM materias_recursado mr
             WHERE mr.materia_curso_id = ? AND mr.ciclo_lectivo_id = ? AND mr.estado = 'activo'",
            [$materiaCursoId, $cicloLectivoId]
        )['count'] ?? 0;
        
        // Contar estudiantes que tienen liberada esta materia
        $estudiantesLiberados = $db->fetchOne(
            "SELECT COUNT(DISTINCT mr2.estudiante_id) as count
             FROM materias_recursado mr2
             WHERE mr2.materia_liberada_id = ? AND mr2.ciclo_lectivo_id = ? AND mr2.estado = 'activo'",
            [$materiaCursoId, $cicloLectivoId]
        )['count'] ?? 0;
        
        // Total de estudiantes = regulares + recursando - liberados
        $totalEstudiantes = $estudiantesRegulares + $estudiantesRecursando - $estudiantesLiberados;
        
        // Contar estudiantes con calificaciones válidas según el tipo
        if ($tipoCalificacion === 'valoracion') {
            // Para valoraciones, verificar que tengan TEA, TEP o TED
            $estudiantesConNota = $db->fetchOne(
                "SELECT COUNT(DISTINCT cal.estudiante_id) as count
                 FROM calificaciones cal
                 WHERE cal.materia_curso_id = ? 
                       AND cal.ciclo_lectivo_id = ?
                       AND cal.{$campo_calificacion} IS NOT NULL 
                       AND cal.{$campo_calificacion} != ''
                       AND cal.{$campo_calificacion} IN ('TEA', 'TEP', 'TED')",
                [$materiaCursoId, $cicloLectivoId]
            )['count'] ?? 0;
        } else {
            // Para calificaciones numéricas cuatrimestrales
            $estudiantesConNota = $db->fetchOne(
                "SELECT COUNT(DISTINCT cal.estudiante_id) as count
                 FROM calificaciones cal
                 WHERE cal.materia_curso_id = ? 
                       AND cal.ciclo_lectivo_id = ?
                       AND cal.{$campo_calificacion} IS NOT NULL 
                       AND cal.{$campo_calificacion} != ''
                       AND cal.{$campo_calificacion} != '0'
                       AND CAST(cal.{$campo_calificacion} AS INTEGER) BETWEEN 1 AND 10",
                [$materiaCursoId, $cicloLectivoId]
            )['count'] ?? 0;
        }
        
        // Asignar los valores calculados
        $stat['total_estudiantes'] = max(0, $totalEstudiantes); // No puede ser negativo
        $stat['estudiantes_con_nota'] = $estudiantesConNota;
        $stat['descripcion_periodo'] = $descripcion_periodo;
        $stat['tipo_calificacion'] = $tipoCalificacion;
    }
    
    // Agrupar por curso y calcular estados
    $resultado = [];
    foreach ($estadisticas as $stat) {
        $cursoId = $stat['curso_id'];
        if (!isset($resultado[$cursoId])) {
            $resultado[$cursoId] = [
                'curso_info' => [
                    'id' => $stat['curso_id'],
                    'nombre' => $stat['curso_nombre'],
                    'anio' => $stat['curso_anio']
                ],
                'materias' => [],
                'resumen' => [
                    'total_materias' => 0,
                    'completas' => 0,
                    'parciales' => 0,
                    'sin_calificaciones' => 0,
                    'sin_estudiantes' => 0
                ]
            ];
        }
        
        // Determinar estado de la materia
        $totalEst = intval($stat['total_estudiantes']);
        $conNota = intval($stat['estudiantes_con_nota']);
        
        $estado_carga = 'sin_estudiantes';
        if ($totalEst > 0) {
            if ($conNota == 0) {
                $estado_carga = 'sin_calificaciones';
            } elseif ($conNota == $totalEst) {
                $estado_carga = 'completas';
            } else {
                $estado_carga = 'parciales';
            }
        }
        
        $stat['estado_carga'] = $estado_carga;
        $resultado[$cursoId]['materias'][] = $stat;
        $resultado[$cursoId]['resumen']['total_materias']++;
        
        // Incrementar contador correspondiente
        if (isset($resultado[$cursoId]['resumen'][$estado_carga])) {
            $resultado[$cursoId]['resumen'][$estado_carga]++;
        }
    }
    
    return $resultado;
}

/**
 * Función mejorada para obtener grupos de materias y su estado
 */
function obtenerEstadisticasGrupos($db, $cicloLectivoId, $cursoId = null, $tipoCalificacion = 'cuatrimestre', $periodo = 1) {
    $whereClause = $cursoId ? "AND c.id = ?" : "";
    
    // Determinar el campo de calificación según el tipo y periodo
    if ($tipoCalificacion === 'valoracion') {
        $campo_calificacion = ($periodo == 1) ? 'valoracion_1bim' : 'valoracion_3bim';
    } else {
        $campo_calificacion = ($periodo == 1) ? 'calificacion_1c' : 'calificacion_2c';
    }
    
    // Query base para grupos
    $query = "
        SELECT 
            gm.id as grupo_id,
            gm.nombre as grupo_nombre,
            gm.codigo as grupo_codigo,
            gm.curso_anio,
            COUNT(DISTINCT mg.materia_curso_id) as materias_en_grupo
        FROM grupos_materias gm
        JOIN materias_grupo mg ON gm.id = mg.grupo_id AND mg.activo = 1
        JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
        JOIN cursos c ON mp.curso_id = c.id
        WHERE gm.ciclo_lectivo_id = ? AND gm.activo = 1 {$whereClause}
        GROUP BY gm.id
        ORDER BY gm.curso_anio, gm.orden_visualizacion
    ";
    
    $params = $cursoId ? [$cicloLectivoId, $cursoId] : [$cicloLectivoId];
    $grupos = $db->fetchAll($query, $params);
    
    // Calcular estadísticas para cada grupo
    foreach ($grupos as &$grupo) {
        $grupoId = $grupo['grupo_id'];
        $cursoAnio = $grupo['curso_anio'];
        
        // Contar estudiantes del grupo (regulares del año correspondiente)
        $totalEstudiantes = $db->fetchOne(
            "SELECT COUNT(DISTINCT mat.estudiante_id) as count
             FROM matriculas mat
             JOIN cursos c_temp ON mat.curso_id = c_temp.id
             WHERE c_temp.anio = ? 
                   AND c_temp.ciclo_lectivo_id = ?
                   AND mat.estado = 'activo'",
            [$cursoAnio, $cicloLectivoId]
        )['count'] ?? 0;
        
        // Contar calificaciones del grupo según el tipo
        if ($tipoCalificacion === 'valoracion') {
            $calificacionesIndividuales = $db->fetchOne(
                "SELECT COUNT(*) as count
                 FROM calificaciones cal
                 JOIN materias_grupo mg_cal ON cal.materia_curso_id = mg_cal.materia_curso_id
                 WHERE mg_cal.grupo_id = ?
                       AND cal.ciclo_lectivo_id = ?
                       AND cal.{$campo_calificacion} IS NOT NULL 
                       AND cal.{$campo_calificacion} != ''
                       AND cal.{$campo_calificacion} IN ('TEA', 'TEP', 'TED')",
                [$grupoId, $cicloLectivoId]
            )['count'] ?? 0;
        } else {
            $calificacionesIndividuales = $db->fetchOne(
                "SELECT COUNT(*) as count
                 FROM calificaciones cal
                 JOIN materias_grupo mg_cal ON cal.materia_curso_id = mg_cal.materia_curso_id
                 WHERE mg_cal.grupo_id = ?
                       AND cal.ciclo_lectivo_id = ?
                       AND cal.{$campo_calificacion} IS NOT NULL 
                       AND cal.{$campo_calificacion} != ''
                       AND cal.{$campo_calificacion} != '0'
                       AND CAST(cal.{$campo_calificacion} AS INTEGER) BETWEEN 1 AND 10",
                [$grupoId, $cicloLectivoId]
            )['count'] ?? 0;
        }
        
        $grupo['total_estudiantes_grupo'] = $totalEstudiantes;
        $grupo['calificaciones_individuales'] = $calificacionesIndividuales;
        
        // Calcular estado del grupo
        $totalEsperado = $totalEstudiantes * intval($grupo['materias_en_grupo']);
        
        if ($totalEstudiantes == 0) {
            $grupo['estado_grupo'] = 'sin_estudiantes';
        } elseif ($calificacionesIndividuales == 0) {
            $grupo['estado_grupo'] = 'sin_calificaciones';
        } elseif ($calificacionesIndividuales == $totalEsperado) {
            $grupo['estado_grupo'] = 'completa';
        } else {
            $grupo['estado_grupo'] = 'parcial';
        }
    }
    
    return $grupos;
}

// Obtener estadísticas
$estadisticas = obtenerEstadisticasCalificaciones($db, $cicloLectivoId, $cursoSeleccionado ?: null, $tipoCalificacion, $periodoSeleccionado);

// Obtener estadísticas de grupos (solo si existen grupos en el sistema)
$estadisticasGrupos = [];
try {
    // Verificar si hay grupos de materias configurados
    $tieneGrupos = $db->fetchOne("SELECT COUNT(*) as count FROM grupos_materias WHERE ciclo_lectivo_id = ? AND activo = 1", [$cicloLectivoId]);
    if ($tieneGrupos && $tieneGrupos['count'] > 0) {
        $estadisticasGrupos = obtenerEstadisticasGrupos($db, $cicloLectivoId, $cursoSeleccionado ?: null, $tipoCalificacion, $periodoSeleccionado);
    }
} catch (Exception $e) {
    // Si hay error con grupos, continuar sin ellos
    error_log("Error al obtener estadísticas de grupos: " . $e->getMessage());
    $estadisticasGrupos = [];
}

// Calcular resumen general
$resumenGeneral = [
    'total_materias' => 0,
    'completas' => 0,
    'parciales' => 0,
    'sin_calificaciones' => 0,
    'sin_estudiantes' => 0
];

foreach ($estadisticas as $curso) {
    if (!isset($curso['resumen']) || !is_array($curso['resumen'])) {
        continue;
    }
    
    foreach ($curso['resumen'] as $key => $value) {
        if (isset($resumenGeneral[$key]) && is_numeric($value)) {
            $resumenGeneral[$key] += intval($value);
        }
    }
}

include 'header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1><i class="bi bi-graph-up"></i> Dashboard de Seguimiento</h1>
                    <p class="text-muted">
                        Seguimiento del estado de carga de calificaciones - Ciclo 
                        <?= htmlspecialchars($cicloActivo['anio'] ?? $cicloActivo['nombre'] ?? 'Activo') ?>
                        <br>
                        <span class="badge bg-info">
                            <?php 
                            if ($tipoCalificacion === 'valoracion') {
                                echo ($periodoSeleccionado == 1) ? 'Valoraciones - 1er Bimestre' : 'Valoraciones - 3er Bimestre';
                            } else {
                                echo ($periodoSeleccionado == 1) ? 'Calificaciones - 1er Cuatrimestre' : 'Calificaciones - 2do Cuatrimestre';
                            }
                            ?>
                        </span>
                    </p>
                </div>
                <div class="text-end">
                    <small class="text-muted">Última actualización: <?= date('d/m/Y H:i') ?></small>
                    <br>
                    <button onclick="location.reload()" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Actualizar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="curso" class="form-label">Curso</label>
                            <select name="curso" id="curso" class="form-select">
                                <option value="0">Todos los cursos</option>
                                <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" <?= $cursoSeleccionado == $curso['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(($curso['anio'] ?? 'N/A') . '° año' ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="tipo" class="form-label">Tipo de Calificación</label>
                            <select name="tipo" id="tipo" class="form-select" onchange="actualizarPeriodos()">
                                <option value="cuatrimestre" <?= $tipoCalificacion == 'cuatrimestre' ? 'selected' : '' ?>>Calificaciones Cuatrimestrales</option>
                                <option value="valoracion" <?= $tipoCalificacion == 'valoracion' ? 'selected' : '' ?>>Valoraciones Preliminares</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="periodo" class="form-label">
                                <span id="label-periodo">
                                    <?= $tipoCalificacion === 'valoracion' ? 'Bimestre' : 'Cuatrimestre' ?>
                                </span>
                            </label>
                            <select name="periodo" id="periodo" class="form-select">
                                <option value="1" <?= $periodoSeleccionado == 1 ? 'selected' : '' ?> id="periodo1">
                                    <?= $tipoCalificacion === 'valoracion' ? '1er Bimestre' : '1er Cuatrimestre' ?>
                                </option>
                                <option value="2" <?= $periodoSeleccionado == 2 ? 'selected' : '' ?> id="periodo2" 
                                        <?= $tipoCalificacion === 'valoracion' ? 'style="display:none"' : '' ?>>
                                    2do Cuatrimestre
                                </option>
                                <option value="3" <?= $periodoSeleccionado == 3 ? 'selected' : '' ?> id="periodo3" 
                                        <?= $tipoCalificacion === 'valoracion' ? '' : 'style="display:none"' ?>>
                                    3er Bimestre
                                </option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-funnel"></i> Filtrar
                            </button>
                            <a href="?" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Limpiar
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen general -->
    <div class="row mb-4">
        <div class="col-12">
            <h3>Resumen General 
                <small class="text-muted">
                    (<?php 
                    if ($tipoCalificacion === 'valoracion') {
                        echo ($periodoSeleccionado == 1) ? '1er Bimestre' : '3er Bimestre';
                    } else {
                        echo ($periodoSeleccionado == 1) ? '1er Cuatrimestre' : '2do Cuatrimestre';
                    }
                    ?>)
                </small>
            </h3>
        </div>
        <div class="col-md-2">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h4><?= $resumenGeneral['total_materias'] ?></h4>
                    <small>Total Materias</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h4><?= $resumenGeneral['completas'] ?? 0 ?></h4>
                    <small>Completas</small>
                    <?php if ($resumenGeneral['total_materias'] > 0): ?>
                        <div class="small"><?= round((($resumenGeneral['completas'] ?? 0) / $resumenGeneral['total_materias']) * 100, 1) ?>%</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-warning text-white">
                <div class="card-body text-center">
                    <h4><?= $resumenGeneral['parciales'] ?? 0 ?></h4>
                    <small>Parciales</small>
                    <?php if ($resumenGeneral['total_materias'] > 0): ?>
                        <div class="small"><?= round((($resumenGeneral['parciales'] ?? 0) / $resumenGeneral['total_materias']) * 100, 1) ?>%</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-danger text-white">
                <div class="card-body text-center">
                    <h4><?= $resumenGeneral['sin_calificaciones'] ?? 0 ?></h4>
                    <small>Sin Calificaciones</small>
                    <?php if ($resumenGeneral['total_materias'] > 0): ?>
                        <div class="small"><?= round((($resumenGeneral['sin_calificaciones'] ?? 0) / $resumenGeneral['total_materias']) * 100, 1) ?>%</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h4><?= $resumenGeneral['sin_estudiantes'] ?? 0 ?></h4>
                    <small>Sin Estudiantes</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerta informativa sobre recursados -->
    <?php if ($cursoSeleccionado == 0): ?>
    <div class="row mb-3">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>Información:</strong> Los conteos incluyen estudiantes recursando materias y excluyen aquellos que tienen materias liberadas. 
                <?php if ($tipoCalificacion === 'valoracion'): ?>
                    Mostrando valoraciones preliminares (TEA, TEP, TED) para el <?= ($periodoSeleccionado == 1) ? '1er Bimestre' : '3er Bimestre' ?>.
                <?php else: ?>
                    Mostrando calificaciones numéricas (1-10) para el <?= ($periodoSeleccionado == 1) ? '1er Cuatrimestre' : '2do Cuatrimestre' ?>.
                <?php endif; ?>
                Para ver detalles específicos de recursados, visite la <a href="gestionar_recursados.php" class="alert-link">gestión de recursados</a>.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Grupos de materias -->
    <?php if (!empty($estadisticasGrupos)): ?>
    <div class="row mb-4 d-none">
        <div class="col-12">
            <h3>Estado de Grupos de Materias</h3>
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Año</th>
                                    <th>Grupo</th>
                                    <th>Código</th>
                                    <th>Materias</th>
                                    <th>Estudiantes</th>
                                    <th>Estado</th>
                                    <th>Progreso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estadisticasGrupos as $grupo): ?>
                                <tr>
                                    <td><?= htmlspecialchars($grupo['curso_anio'] ?? 'N/A') ?>°</td>
                                    <td><?= htmlspecialchars($grupo['grupo_nombre'] ?? 'Sin nombre') ?></td>
                                    <td><code><?= htmlspecialchars($grupo['grupo_codigo'] ?? 'Sin código') ?></code></td>
                                    <td><?= intval($grupo['materias_en_grupo'] ?? 0) ?></td>
                                    <td><?= intval($grupo['total_estudiantes_grupo'] ?? 0) ?></td>
                                    <td>
                                        <?php
                                        $estadoGrupo = $grupo['estado_grupo'] ?? 'sin_estudiantes';
                                        $badgeClass = [
                                            'completa' => 'bg-success',
                                            'parcial' => 'bg-warning',
                                            'sin_calificaciones' => 'bg-danger',
                                            'sin_estudiantes' => 'bg-info'
                                        ];
                                        $badgeText = [
                                            'completa' => 'Completa',
                                            'parcial' => 'Parcial',
                                            'sin_calificaciones' => 'Sin Calificaciones',
                                            'sin_estudiantes' => 'Sin Estudiantes'
                                        ];
                                        ?>
                                        <span class="badge <?= $badgeClass[$estadoGrupo] ?? 'bg-secondary' ?>">
                                            <?= $badgeText[$estadoGrupo] ?? 'Desconocido' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $totalEsperado = intval($grupo['total_estudiantes_grupo'] ?? 0) * intval($grupo['materias_en_grupo'] ?? 0);
                                        $calificacionesIndividuales = intval($grupo['calificaciones_individuales'] ?? 0);
                                        $porcentaje = $totalEsperado > 0 ? ($calificacionesIndividuales / $totalEsperado) * 100 : 0;
                                        ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?= $porcentaje == 100 ? 'bg-success' : ($porcentaje > 0 ? 'bg-warning' : 'bg-danger') ?>" 
                                                 style="width: <?= $porcentaje ?>%">
                                                <?= round($porcentaje, 1) ?>%
                                            </div>
                                        </div>
                                        <small class="text-muted"><?= $calificacionesIndividuales ?>/<?= $totalEsperado ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Detalle por curso -->
    <div class="row">
        <div class="col-12">
            <h3>Detalle por Materia</h3>
            
            <?php foreach ($estadisticas as $cursoData): ?>
            <?php 
                if (!isset($cursoData['curso_info']) || !isset($cursoData['materias']) || !isset($cursoData['resumen'])) {
                    continue;
                }
                
                $cursoInfo = $cursoData['curso_info'];
                $resumen = $cursoData['resumen'];
            ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <?= htmlspecialchars($cursoInfo['anio'] ?? 'N/A') ?>° año - <?= htmlspecialchars($cursoInfo['nombre'] ?? 'Sin nombre') ?>
                        <span class="badge bg-secondary ms-2"><?= $resumen['total_materias'] ?? 0 ?> materias</span>
                    </h5>
                    <div class="row mt-2">
                        <div class="col-md-3">
                            <small class="text-success">✓ Completas: <?= $resumen['completas'] ?? 0 ?></small>
                        </div>
                        <div class="col-md-3">
                            <small class="text-warning">⚠ Parciales: <?= $resumen['parciales'] ?? 0 ?></small>
                        </div>
                        <div class="col-md-3">
                            <small class="text-danger">✗ Sin calif.: <?= $resumen['sin_calificaciones'] ?? 0 ?></small>
                        </div>
                        <div class="col-md-3">
                            <small class="text-info">○ Sin estudiantes: <?= $resumen['sin_estudiantes'] ?? 0 ?></small>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Materia</th>
                                    <th>Código</th>
                                    <th>Profesor</th>
                                    <th>Estudiantes <i class="bi bi-info-circle" title="Incluye regulares + recursando - liberados"></i></th>
                                    <th>Con Nota</th>
                                    <th>Estado</th>
                                    <th>Progreso</th>
                                    <th>Subgrupos</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $materias = isset($cursoData['materias']) && is_array($cursoData['materias']) ? $cursoData['materias'] : [];
                                ?>
                                <?php foreach ($materias as $materia): ?>
                                <tr>
                                    <td><?= htmlspecialchars($materia['materia_nombre'] ?? 'Sin nombre') ?></td>
                                    <td><code><?= htmlspecialchars($materia['materia_codigo'] ?? 'Sin código') ?></code></td>
                                    <td>
                                        <?php if (!empty($materia['profesor_nombre'])): ?>
                                            <?= htmlspecialchars($materia['profesor_nombre']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= intval($materia['total_estudiantes'] ?? 0) ?></strong>
                                        <?php 
                                        // Mostrar desglose si hay recursados
                                        $materiaCursoId = $materia['materia_curso_id'];
                                        try {
                                            $recursando = $db->fetchOne(
                                                "SELECT COUNT(*) as count FROM materias_recursado 
                                                 WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? AND estado = 'activo'",
                                                [$materiaCursoId, $cicloLectivoId]
                                            );
                                            $liberados = $db->fetchOne(
                                                "SELECT COUNT(*) as count FROM materias_recursado 
                                                 WHERE materia_liberada_id = ? AND ciclo_lectivo_id = ? AND estado = 'activo'",
                                                [$materiaCursoId, $cicloLectivoId]
                                            );
                                            
                                            $recursandoCount = $recursando['count'] ?? 0;
                                            $liberadosCount = $liberados['count'] ?? 0;
                                            
                                            if ($recursandoCount > 0 || $liberadosCount > 0): ?>
                                                <br><small class="text-muted">
                                                    <?php if ($recursandoCount > 0): ?>
                                                        +<?= $recursandoCount ?> recursando
                                                    <?php endif; ?>
                                                    <?php if ($liberadosCount > 0): ?>
                                                        <?= $recursandoCount > 0 ? ', ' : '' ?>-<?= $liberadosCount ?> liberados
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif;
                                        } catch (Exception $e) {
                                            // Ignorar errores de consulta
                                            $recursandoCount = 0;
                                            $liberadosCount = 0;
                                        }
                                        ?>
                                    </td>
                                    <td><?= intval($materia['estudiantes_con_nota'] ?? 0) ?></td>
                                    <td>
                                        <?php
                                        $estado = $materia['estado_carga'] ?? 'sin_estudiantes';
                                        $badgeClass = [
                                            'completas' => 'bg-success',
                                            'parciales' => 'bg-warning text-dark',
                                            'sin_calificaciones' => 'bg-danger',
                                            'sin_estudiantes' => 'bg-info'
                                        ];
                                        $badgeText = [
                                            'completas' => 'Completa',
                                            'parciales' => 'Parcial',
                                            'sin_calificaciones' => 'Sin Calif.',
                                            'sin_estudiantes' => 'Sin Est.'
                                        ];
                                        ?>
                                        <span class="badge <?= $badgeClass[$estado] ?? 'bg-secondary' ?>">
                                            <?= $badgeText[$estado] ?? 'Desconocido' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $totalEst = intval($materia['total_estudiantes'] ?? 0);
                                        $conNota = intval($materia['estudiantes_con_nota'] ?? 0);
                                        ?>
                                        <?php if ($totalEst > 0): ?>
                                            <?php $porcentaje = ($conNota / $totalEst) * 100; ?>
                                            <div class="progress" style="height: 15px;">
                                                <div class="progress-bar <?= $porcentaje == 100 ? 'bg-success' : ($porcentaje > 0 ? 'bg-warning' : 'bg-danger') ?>" 
                                                     style="width: <?= $porcentaje ?>%">
                                                </div>
                                            </div>
                                            <small><?= round($porcentaje, 1) ?>%</small>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($materia['requiere_subgrupos']) && $materia['requiere_subgrupos'] == 1): ?>
                                            <span class="badge bg-info">Sí</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="calificaciones.php?curso=<?= $cursoInfo['id'] ?>&materia=<?= $materiaCursoId ?>&tipo=<?= $tipoCalificacion ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Ver/Editar calificaciones">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($recursandoCount > 0 || $liberadosCount > 0): ?>
                                            <a href="gestionar_recursados.php" 
                                               class="btn btn-sm btn-outline-info" title="Gestionar recursados">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.progress {
    background-color: #e9ecef;
}

.table th {
    background-color: #f8f9fa;
    border-top: none;
}

.badge {
    font-size: 0.75em;
}

.card-header h5 {
    color: #495057;
}

.text-success { color: #198754 !important; }
.text-warning { color: #fd7e14 !important; }
.text-danger { color: #dc3545 !important; }
.text-info { color: #0dcaf0 !important; }

/* Tooltip para información adicional */
[title] {
    cursor: help;
}

/* Mejora visual para indicadores de recursado */
.text-muted small {
    font-size: 0.75em;
}
</style>

<script>
// Auto-refresh cada 5 minutos
setTimeout(function() {
    location.reload();
}, 300000);

// Función para actualizar las opciones de período según el tipo seleccionado
function actualizarPeriodos() {
    const tipoSelect = document.getElementById('tipo');
    const periodoSelect = document.getElementById('periodo');
    const labelPeriodo = document.getElementById('label-periodo');
    const periodo1 = document.getElementById('periodo1');
    const periodo2 = document.getElementById('periodo2');
    const periodo3 = document.getElementById('periodo3');
    
    if (tipoSelect.value === 'valoracion') {
        // Mostrar opciones para valoraciones (1er y 3er bimestre)
        labelPeriodo.textContent = 'Bimestre';
        periodo1.textContent = '1er Bimestre';
        periodo1.value = '1';
        periodo2.style.display = 'none'; // Ocultar 2do cuatrimestre
        periodo3.style.display = 'block'; // Mostrar 3er bimestre
        periodo3.textContent = '3er Bimestre';
        periodo3.value = '3';
        
        // Si está seleccionado el 2do cuatrimestre, cambiar al 1er bimestre
        if (periodoSelect.value === '2') {
            periodoSelect.value = '1';
        }
    } else {
        // Mostrar opciones para cuatrimestres
        labelPeriodo.textContent = 'Cuatrimestre';
        periodo1.textContent = '1er Cuatrimestre';
        periodo1.value = '1';
        periodo2.style.display = 'block'; // Mostrar 2do cuatrimestre
        periodo2.textContent = '2do Cuatrimestre';
        periodo2.value = '2';
        periodo3.style.display = 'none'; // Ocultar 3er bimestre
        
        // Si está seleccionado el 3er bimestre, cambiar al 1er cuatrimestre
        if (periodoSelect.value === '3') {
            periodoSelect.value = '1';
        }
    }
}

// Tooltip para elementos con información adicional
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips de Bootstrap si está disponible
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Configurar las opciones de período al cargar la página
    actualizarPeriodos();
});
</script>

<?php include 'footer.php'; ?>