<?php
/**
 * ajax_cargar_calificacion.php - Cargar vista de calificación para modal
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * MODIFICADO: Permitir acceso a admin y directivo además de profesores
 */

// Incluir archivos necesarios
require_once 'config.php';

// Verificar sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// MODIFICADO: Permitir acceso a admin, directivo y profesor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    echo '<div class="alert alert-danger">No tiene permisos para esta acción</div>';
    exit;
}

// Obtener parámetros
$contenidoId = intval($_GET['contenido'] ?? 0);
$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];

if (!$contenidoId) {
    echo '<div class="alert alert-danger">Contenido no especificado</div>';
    exit;
}

$db = Database::getInstance();

// Función para obtener estudiantes de la materia (misma lógica que contenidos_calificar.php)
function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
    try {
        $materiaInfo = $db->fetchOne(
            "SELECT COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos, 
                    c.id as curso_id, c.nombre as curso_nombre, c.anio
             FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );

        if (!$materiaInfo) {
            return [];
        }

        $estudiantes = [];

        if ($materiaInfo['requiere_subgrupos']) {
            $estudiantesSubgrupos = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                        'subgrupo' as tipo_matricula,
                        c.nombre as curso_origen,
                        ep.subgrupo as subgrupo_nombre
                 FROM estudiantes_por_materia ep
                 JOIN usuarios u ON ep.estudiante_id = u.id
                 JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
                 JOIN cursos c ON m.curso_id = c.id
                 WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                 ORDER BY u.apellido, u.nombre",
                [$materiaCursoId, $cicloLectivoId]
            );
            
            $estudiantes = $estudiantesSubgrupos;
            
        } else {
            $estudiantesRegulares = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                        'regular' as tipo_matricula,
                        NULL as curso_origen,
                        NULL as subgrupo_nombre
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 ORDER BY u.apellido, u.nombre",
                [$materiaInfo['curso_id']]
            );

            $estudiantesRecursando = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        'recursando' as tipo_matricula,
                        c_actual.nombre as curso_origen,
                        NULL as subgrupo_nombre
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 JOIN matriculas m_actual ON u.id = m_actual.estudiante_id AND m_actual.estado = 'activo'
                 JOIN cursos c_actual ON m_actual.curso_id = c_actual.id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'
                 ORDER BY u.apellido, u.nombre",
                [$materiaCursoId, $cicloLectivoId]
            );

            $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
        }

        // Filtrar estudiantes con materias liberadas
        $estudiantesFiltrados = [];
        foreach ($estudiantes as $estudiante) {
            $materiaLiberada = $db->fetchOne(
                "SELECT id FROM materias_recursado 
                 WHERE estudiante_id = ? AND materia_liberada_id = ? AND estado = 'activo'",
                [$estudiante['id'], $materiaCursoId]
            );
            
            if (!$materiaLiberada) {
                $estudiantesFiltrados[] = $estudiante;
            }
        }

        return $estudiantesFiltrados;
        
    } catch (Exception $e) {
        error_log("Error en obtenerEstudiantesMateria: " . $e->getMessage());
        return [];
    }
}

try {
    // MODIFICADO: Consulta diferente según el tipo de usuario
    if ($userType === 'admin' || $userType === 'directivo') {
        // Admin y directivo pueden ver cualquier contenido
        $contenido = $db->fetchOne(
            "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, mp.curso_id, 
                    COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                    m.nombre as materia_nombre, cur.nombre as curso_nombre, cur.anio as curso_anio,
                    (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores,
                    u1.nombre as profesor1_nombre, u1.apellido as profesor1_apellido,
                    u2.nombre as profesor2_nombre, u2.apellido as profesor2_apellido,
                    u3.nombre as profesor3_nombre, u3.apellido as profesor3_apellido
             FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos cur ON mp.curso_id = cur.id
             LEFT JOIN usuarios u1 ON mp.profesor_id = u1.id
             LEFT JOIN usuarios u2 ON mp.profesor_id_2 = u2.id
             LEFT JOIN usuarios u3 ON mp.profesor_id_3 = u3.id
             WHERE c.id = ? AND c.activo = 1",
            [$contenidoId]
        );
    } else {
        // Profesor solo puede ver contenidos de sus materias
        $contenido = $db->fetchOne(
            "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, mp.curso_id, 
                    COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                    m.nombre as materia_nombre, cur.nombre as curso_nombre, cur.anio as curso_anio,
                    (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores,
                    u1.nombre as profesor1_nombre, u1.apellido as profesor1_apellido,
                    u2.nombre as profesor2_nombre, u2.apellido as profesor2_apellido,
                    u3.nombre as profesor3_nombre, u3.apellido as profesor3_apellido
             FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos cur ON mp.curso_id = cur.id
             LEFT JOIN usuarios u1 ON mp.profesor_id = u1.id
             LEFT JOIN usuarios u2 ON mp.profesor_id_2 = u2.id
             LEFT JOIN usuarios u3 ON mp.profesor_id_3 = u3.id
             WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
            [$contenidoId, $userId, $userId, $userId]
        );
    }
    
    if (!$contenido) {
        echo '<div class="alert alert-danger">Contenido no encontrado o no tiene permisos</div>';
        exit;
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Obtener estudiantes
    $estudiantes = obtenerEstudiantesMateria($db, $contenido['materia_curso_id'], $cicloLectivoId);
    
    // Obtener calificaciones existentes
    $calificaciones = [];
    $calificacionesData = $db->fetchAll(
        "SELECT * FROM contenidos_calificaciones WHERE contenido_id = ?",
        [$contenidoId]
    );
    
    foreach ($calificacionesData as $cal) {
        $calificaciones[$cal['estudiante_id']] = $cal;
    }
    
    // Determinar si es equipo docente
    $esEquipoDocente = $contenido['total_profesores'] > 1;
    
    // NUEVO: Construir información de profesores para mostrar
    $profesoresInfo = [];
    if ($contenido['profesor1_nombre']) {
        $profesoresInfo[] = $contenido['profesor1_apellido'] . ', ' . $contenido['profesor1_nombre'];
    }
    if ($contenido['profesor2_nombre']) {
        $profesoresInfo[] = $contenido['profesor2_apellido'] . ', ' . $contenido['profesor2_nombre'];
    }
    if ($contenido['profesor3_nombre']) {
        $profesoresInfo[] = $contenido['profesor3_apellido'] . ', ' . $contenido['profesor3_nombre'];
    }
    
    // ========== SISTEMA DE SUBGRUPOS PARA MODAL ==========
    // (El resto del código de subgrupos permanece igual...)
    
    // Obtener lista única de subgrupos disponibles en el modal
    $subgruposDisponiblesModal = [];
    $estudiantesConSubgruposModal = [];
    
    foreach ($estudiantes as $estudiante) {
        if (!empty($estudiante['subgrupo_nombre'])) {
            $subgrupo = trim($estudiante['subgrupo_nombre']);
            
            // Normalizar formato: si es numérico, convertir a "Subgrupo X"
            if (is_numeric($subgrupo)) {
                $subgrupoFormateado = "Subgrupo " . $subgrupo;
            } else {
                $subgrupoFormateado = $subgrupo;
            }
            
            // Usar el subgrupo original para las estadísticas, pero el formateado para la vista
            if (!in_array($subgrupo, $subgruposDisponiblesModal)) {
                $subgruposDisponiblesModal[] = $subgrupo;
            }
            
            // Actualizar el estudiante con el formato correcto
            $estudiante['subgrupo_nombre'] = $subgrupoFormateado;
            $estudiantesConSubgruposModal[] = $estudiante;
        }
    }
    
    // Ordenar subgrupos naturalmente
    natsort($subgruposDisponiblesModal);
    
    // Estadísticas por subgrupo en el modal
    $estadisticasSubgruposModal = [];
    if (!empty($subgruposDisponiblesModal)) {
        foreach ($subgruposDisponiblesModal as $subgrupo) {
            $estudiantesSubgrupo = array_filter($estudiantes, function($e) use ($subgrupo) {
                return trim($e['subgrupo_nombre'] ?? '') === $subgrupo;
            });
            
            $regulares = array_filter($estudiantesSubgrupo, function($e) {
                return $e['tipo_matricula'] === 'regular';
            });
            
            $recursando = array_filter($estudiantesSubgrupo, function($e) {
                return $e['tipo_matricula'] === 'recursando';
            });

            $subgrupoEstudiantes = array_filter($estudiantesSubgrupo, function($e) {
                return $e['tipo_matricula'] === 'subgrupo';
            });
            
            $estadisticasSubgruposModal[$subgrupo] = [
                'total' => count($estudiantesSubgrupo),
                'regulares' => count($regulares),
                'recursando' => count($recursando),
                'subgrupos' => count($subgrupoEstudiantes),
                'con_calificacion' => count(array_filter($estudiantesSubgrupo, function($e) use ($calificaciones) {
                    return isset($calificaciones[$e['id']]);
                }))
            ];
        }
    }
    
    // Estadísticas de estudiantes sin subgrupo
    $estudiantesSinSubgrupo = array_filter($estudiantes, function($e) {
        return empty($e['subgrupo_nombre']);
    });
    
    $estadisticasSinSubgrupo = [
        'total' => count($estudiantesSinSubgrupo),
        'regulares' => count(array_filter($estudiantesSinSubgrupo, function($e) {
            return $e['tipo_matricula'] === 'regular';
        })),
        'recursando' => count(array_filter($estudiantesSinSubgrupo, function($e) {
            return $e['tipo_matricula'] === 'recursando';
        })),
        'con_calificacion' => count(array_filter($estudiantesSinSubgrupo, function($e) use ($calificaciones) {
            return isset($calificaciones[$e['id']]);
        }))
    ];
    
    // Clasificar estudiantes por tipo para estadísticas generales
    $regulares = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'regular'; });
    $recursando = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'recursando'; });
    $subgrupos = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'subgrupo'; });
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al cargar contenido: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<!-- Información del contenido con indicador de usuario actual -->
<div class="alert alert-primary">
    <div class="row">
        <div class="col-md-8">
            <h6 class="alert-heading mb-1">
                <i class="bi bi-book"></i> <?= htmlspecialchars($contenido['materia_nombre']) ?>
                
                
                <?php if (!empty($subgruposDisponiblesModal)): ?>
                <span class="badge bg-secondary ms-2">
                    <i class="bi bi-diagram-3"></i> <?= count($subgruposDisponiblesModal) ?> Rotación
                </span>
                <?php endif; ?>
            </h6>
            <p class="mb-1">
                <strong>Contenido:</strong> <?= htmlspecialchars($contenido['titulo']) ?><br>
                <strong>Curso:</strong> <?= htmlspecialchars($contenido['curso_nombre']) ?> | 
                <strong>Bimestre:</strong> <?= $contenido['bimestre'] ?>° | 
                <strong>Fecha:</strong> <?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?>
                <?php if ($userType === 'admin' || $userType === 'directivo'): ?>
                <br><strong>Profesores:</strong> <?= htmlspecialchars(implode(' | ', $profesoresInfo)) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="col-md-4">
            <div class="text-end">
                <span class="badge bg-<?= $contenido['tipo_evaluacion'] == 'numerica' ? 'primary' : 'success' ?> fs-6">
                    <?= $contenido['tipo_evaluacion'] == 'numerica' ? 'Evaluación Numérica' : 'Evaluación Cualitativa' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- NUEVO: Alert informativo para admin/directivo -->
<?php if ($userType === 'admin' || $userType === 'directivo'): ?>
<div class="alert alert-warning mb-3">
    <i class="bi bi-exclamation-triangle"></i>
    <strong>Modo <?= ucfirst($userType) ?>:</strong> 
    Está editando calificaciones como <?= $userType ?>. Todos los cambios quedarán registrados bajo su usuario.
    <?php if ($esEquipoDocente): ?>
    Esta materia tiene múltiples profesores asignados.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($estudiantes)): ?>

<!-- Información sobre estudiantes con breakdown por subgrupos -->
<div class="alert alert-info mb-3">
    <div class="row">
        <div class="col-md-8">
            <small>
                <strong>Total:</strong> <?= count($estudiantes) ?> estudiantes
                <?php if (count($regulares) > 0): ?>| <strong>Regulares:</strong> <?= count($regulares) ?><?php endif; ?>
                <?php if (count($recursando) > 0): ?>| <strong>Recursando:</strong> <?= count($recursando) ?><?php endif; ?>
                <?php if (count($subgrupos) > 0): ?>| <strong>Subgrupos:</strong> <?= count($subgrupos) ?><?php endif; ?>
                <?php if ($esEquipoDocente): ?>| <strong>Equipo docente:</strong> Calificación colaborativa<?php endif; ?>
            </small>
        </div>
        <div class="col-md-4 text-end">
            <?php if (!empty($subgruposDisponiblesModal)): ?>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="mostrarEstadisticasSubgruposModal()">
                <i class="bi bi-bar-chart"></i> Ver por Subgrupos
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Selector de Subgrupos para Modal -->
<?php if (!empty($subgruposDisponiblesModal)): ?>
<div class="card mb-3" id="selector-subgrupos-modal">
    <div class="card-header bg-secondary text-white py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-funnel"></i> Filtrar por Subgrupo
            </h6>
            <button type="button" class="btn btn-sm btn-outline-light" 
                    data-bs-toggle="collapse" 
                    data-bs-target="#filtros-modal-content" 
                    aria-expanded="true">
                <i class="bi bi-chevron-up" id="icono-filtros-modal"></i>
            </button>
        </div>
    </div>
    <div class="collapse show" id="filtros-modal-content">
        <div class="card-body p-3">
            <div class="row align-items-center">
                <!-- Selector principal -->
                <div class="col-md-6">
                    <select id="filtro-subgrupo-modal" class="form-select form-select-sm" onchange="window.filtrarPorSubgrupoModal()">
                        <option value="todos">📋 Todos los estudiantes</option>
                        <?php if (!empty($estudiantesSinSubgrupo)): ?>
                        <option value="sin-subgrupo">👥 Sin subgrupo (<?= count($estudiantesSinSubgrupo) ?>)</option>
                        <?php endif; ?>
                        <?php foreach ($subgruposDisponiblesModal as $subgrupo): ?>
                        <?php 
                        // Asegurar formato consistente
                        $subgrupoFormateado = is_numeric($subgrupo) ? "Subgrupo " . $subgrupo : $subgrupo;
                        ?>
                        <option value="<?= htmlspecialchars($subgrupoFormateado) ?>">
                            🔸 <?= htmlspecialchars($subgrupoFormateado) ?> (<?= $estadisticasSubgruposModal[$subgrupo]['total'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Información del filtro actual -->
                <div class="col-md-6">
                    <div id="info-filtro-modal" class="alert alert-light mb-0 p-2">
                        <small>
                            <strong>Mostrando:</strong> 
                            <span id="total-mostrados-modal"><?= count($estudiantes) ?></span> estudiantes
                            <span class="ms-2">
                                <span class="badge bg-success me-1" id="regulares-mostrados-modal"><?= count($regulares) ?></span>
                                <span class="badge bg-warning" id="recursando-mostrados-modal"><?= count($recursando) ?></span>
                                <span class="badge bg-info ms-1" id="calificados-mostrados-modal"><?= count($calificaciones) ?></span>
                            </small>
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Botones de acceso rápido -->
            <div class="mt-2">
                <div class="btn-group btn-group-sm flex-wrap" role="group">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.seleccionarSubgrupoModal('todos')">
                        <i class="bi bi-list"></i> Todos
                        <span class="badge bg-secondary ms-1"><?= count($estudiantes) ?></span>
                    </button>
                    <?php foreach ($subgruposDisponiblesModal as $subgrupo): ?>
                    <?php 
                    // Asegurar formato consistente
                    $subgrupoFormateado = is_numeric($subgrupo) ? "Subgrupo " . $subgrupo : $subgrupo;
                    ?>
                    <button type="button" class="btn btn-outline-primary" onclick="window.seleccionarSubgrupoModal('<?= htmlspecialchars($subgrupoFormateado) ?>')">
                        <?= htmlspecialchars($subgrupoFormateado) ?>
                        <span class="badge bg-primary ms-1"><?= $estadisticasSubgruposModal[$subgrupo]['total'] ?></span>
                    </button>
                    <?php endforeach; ?>
                    <?php if (!empty($estudiantesSinSubgrupo)): ?>
                    <button type="button" class="btn btn-outline-warning" onclick="window.seleccionarSubgrupoModal('sin-subgrupo')">
                        Sin Subgrupo
                        <span class="badge bg-warning ms-1"><?= count($estudiantesSinSubgrupo) ?></span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Formulario de calificaciones -->
<form id="formCalificarModal" data-contenido-id="<?= $contenidoId ?>">
    <input type="hidden" name="contenido_id" value="<?= $contenidoId ?>">
    
    <!-- Botones de acción rápida -->
<div class="mb-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="btn-group" role="group" aria-label="Acciones masivas">
        <button type="button" id="btnAcreditarTodosModal" class="btn btn-success btn-sm"
                onclick="window.acreditarTodosContenido(<?= $contenidoId ?>, '')">
            <i class="bi bi-check-circle"></i> 
            <?= $contenido['tipo_evaluacion'] === 'numerica' ? 'Aprobar Todos (7)' : 'Acreditar Todos' ?>
        </button>
        
        <button type="button" id="btnNoAcreditarTodosModal" class="btn btn-outline-danger btn-sm" 
                onclick="window.noAcreditarTodosContenido(<?= $contenidoId ?>, '')">
            <i class="bi bi-x-circle"></i> 
            <?= $contenido['tipo_evaluacion'] === 'numerica' ? 'Reprobar Todos (1)' : 'No Acreditar Todos' ?>
        </button>
        
        <button type="button" id="btnNoCorrespondeTodosModal" class="btn btn-info btn-sm"
                onclick="window.noCorrespondeTodosContenido(<?= $contenidoId ?>, '')">
            <i class="bi bi-dash-circle"></i> No Corresponde Todos
        </button>
        
        <!-- NUEVO BOTÓN: SIN CALIFICAR TODOS -->
        <button type="button" id="btnSinCalificarTodosModal" class="btn btn-outline-danger btn-sm"
                onclick="window.sinCalificarTodosContenido(<?= $contenidoId ?>, '')">
            <i class="bi bi-eraser"></i> Sin Calificar Todos
        </button>
    </div>
    
    <div class="btn-group-sm">
        <button type="button" id="btnLimpiarTodosModal" class="btn btn-outline-secondary btn-sm"
                onclick="window.limpiarFormularioModal()">
            <i class="bi bi-arrow-clockwise"></i> Limpiar Formulario
        </button>
        
        <?php if (!empty($subgruposDisponiblesModal)): ?>
        <button type="button" class="btn btn-outline-info btn-sm" onclick="window.buscarEstudianteEnModal()">
            <i class="bi bi-search"></i> Buscar
        </button>
        <?php endif; ?>
    </div>
</div>
    
    <!-- Información de filtrado activo -->
    <div id="info-filtrado-activo" class="mt-2" style="display: none;">
        <div class="alert alert-info alert-sm p-2 mb-0">
            <i class="bi bi-filter"></i> 
            <small>
                <strong>Filtro activo:</strong> 
                <span id="texto-filtro-activo"></span>
                <button type="button" class="btn btn-link btn-sm p-0 ms-2" onclick="window.seleccionarSubgrupoModal('todos')">
                    <i class="bi bi-x-circle"></i> Quitar filtro
                </button>
            </small>
        </div>
    </div>
</div>
    
    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
        <table class="table table-sm table-hover" id="tabla-estudiantes-modal">
            <thead class="table-light sticky-top">
                <tr>
                    <th width="30">#</th>
                    <th>Estudiante</th>
                    <th width="60">Tipo</th>
                    <?php if (!empty($subgruposDisponiblesModal)): ?>
                    <th width="80" id="columna-subgrupo-modal">Subgrupo</th>
                    <?php endif; ?>
                    <th width="120">Calificación</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $contador = 1;
                foreach ($estudiantes as $estudiante): 
                    $calExistente = $calificaciones[$estudiante['id']] ?? null;
                    $subgrupoEstudiante = $estudiante['subgrupo_nombre'] ?? '';
                    
                    // CRÍTICO: Asegurar que el subgrupo esté exactamente como en el filtro
                    if (!empty($subgrupoEstudiante)) {
                        // Si el subgrupo viene como "Subgrupo 1", mantenerlo así
                        // Si viene como "1", convertirlo a "Subgrupo 1"
                        if (is_numeric($subgrupoEstudiante)) {
                            $subgrupoEstudiante = "Subgrupo " . $subgrupoEstudiante;
                        }
                    }
                ?>
                <tr class="fila-estudiante-modal" 
                    data-subgrupo="<?= htmlspecialchars($subgrupoEstudiante) ?>"
                    data-tipo="<?= htmlspecialchars($estudiante['tipo_matricula']) ?>"
                    data-estudiante-id="<?= intval($estudiante['id']) ?>">
                    <td class="numero-fila"><?= $contador++ ?></td>
                    <td>
                        <strong><?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?></strong>
                        <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                        <br><small class="text-muted">
                            <i class="bi bi-building"></i> 
                            <?= htmlspecialchars($estudiante['curso_origen'] ?? '') ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                            <span class="badge bg-warning text-dark" title="Recursando">R</span>
                        <?php elseif ($estudiante['tipo_matricula'] === 'subgrupo'): ?>
                            <span class="badge bg-info" title="Subgrupo">S</span>
                        <?php else: ?>
                            <span class="badge bg-primary" title="Regular">R</span>
                        <?php endif; ?>
                    </td>
                    <?php if (!empty($subgruposDisponiblesModal)): ?>
                    <td class="celda-subgrupo">
                        <?php if (!empty($subgrupoEstudiante)): ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars($subgrupoEstudiante) ?></span>
                        <?php else: ?>
                            <small class="text-muted">-</small>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php if ($contenido['tipo_evaluacion'] == 'numerica'): ?>
                            <select class="form-select form-select-sm calificacion-input" name="calificacion_<?= $estudiante['id'] ?>">
                                <option value="">-- Sin calificar --</option>
                                <?php for ($nota = 1; $nota <= 10; $nota += 0.5): ?>
                                    <option value="<?= $nota ?>" 
                                            <?= ($calExistente && $calExistente['calificacion_numerica'] == $nota) ? 'selected' : '' ?>>
                                        <?= $nota ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        <?php else: ?>
                            <select class="form-select form-select-sm calificacion-input" name="calificacion_<?= $estudiante['id'] ?>">
                                <option value="">-- Sin calificar --</option>
                                <option value="Acreditado" 
                                        <?= ($calExistente && $calExistente['calificacion_cualitativa'] == 'Acreditado') ? 'selected' : '' ?>>
                                    Acreditado
                                </option>
                                <option value="No Acreditado" 
                                        <?= ($calExistente && $calExistente['calificacion_cualitativa'] == 'No Acreditado') ? 'selected' : '' ?>>
                                    No Acreditado
                                </option>
                                <option value="No Corresponde" 
                                        <?= ($calExistente && $calExistente['calificacion_cualitativa'] == 'No Corresponde') ? 'selected' : '' ?>>
                                    N/C
                                </option>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm observaciones-input" 
                               name="observaciones_<?= $estudiante['id'] ?>"
                               value="<?= $calExistente ? htmlspecialchars($calExistente['observaciones'] ?? '') : '' ?>"
                               placeholder="Observaciones">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<!-- Modal de Estadísticas por Subgrupo -->
<?php if (!empty($subgruposDisponiblesModal)): ?>
<div class="modal fade" id="modalEstadisticasSubgruposModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h6 class="modal-title">
                    <i class="bi bi-bar-chart"></i> Estadísticas por Subgrupo
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Subgrupo</th>
                                <th class="text-center">Total</th>
                                <th class="text-center">Calificados</th>
                                <th class="text-center">% Progreso</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estadisticasSubgruposModal as $subgrupo => $stats): ?>
                            <tr>
                                <td>
                                    <strong>Subgrupo <?= htmlspecialchars($subgrupo) ?></strong>
                                    <br><small class="text-muted">
                                        <?= $stats['regulares'] ?> reg., <?= $stats['recursando'] ?> rec.
                                    </small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= $stats['total'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $stats['con_calificacion'] > 0 ? 'success' : 'secondary' ?>">
                                        <?= $stats['con_calificacion'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php 
                                    $progreso = $stats['total'] > 0 ? round(($stats['con_calificacion'] / $stats['total']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-<?= $progreso == 100 ? 'success' : ($progreso > 50 ? 'warning' : 'danger') ?>" 
                                             style="width: <?= $progreso ?>%"></div>
                                    </div>
                                    <small><?= $progreso ?>%</small>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                            onclick="window.seleccionarSubgrupoModalYCerrar('<?= htmlspecialchars($subgrupo) ?>')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (!empty($estudiantesSinSubgrupo)): ?>
                            <tr class="table-warning">
                                <td>
                                    <strong>Sin Subgrupo</strong>
                                    <br><small class="text-muted">
                                        <?= $estadisticasSinSubgrupo['regulares'] ?> reg., <?= $estadisticasSinSubgrupo['recursando'] ?> rec.
                                    </small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= $estadisticasSinSubgrupo['total'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $estadisticasSinSubgrupo['con_calificacion'] > 0 ? 'success' : 'secondary' ?>">
                                        <?= $estadisticasSinSubgrupo['con_calificacion'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php 
                                    $progresoSin = $estadisticasSinSubgrupo['total'] > 0 ? round(($estadisticasSinSubgrupo['con_calificacion'] / $estadisticasSinSubgrupo['total']) * 100) : 0;
                                    ?>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-<?= $progresoSin == 100 ? 'success' : ($progresoSin > 50 ? 'warning' : 'danger') ?>" 
                                             style="width: <?= $progresoSin ?>%"></div>
                                    </div>
                                    <small><?= $progresoSin ?>%</small>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-outline-warning btn-sm" 
                                            onclick="window.seleccionarSubgrupoModalYCerrar('sin-subgrupo')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info mt-3">
                    <small>
                        <i class="bi bi-info-circle"></i> 
                        <strong>Progreso de calificación:</strong> Muestra qué porcentaje de estudiantes ya tiene calificación asignada en cada subgrupo.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Variables globales para el modal
window.subgruposDisponiblesModalJS = <?= json_encode($subgruposDisponiblesModal ?? []) ?>;
window.estadisticasSubgruposModalJS = <?= json_encode($estadisticasSubgruposModal ?? []) ?>;

// Verificar que las funciones globales estén disponibles
function verificarFuncionesGlobalesModal() {
    const funcionesRequeridas = [
        'seleccionarSubgrupoModal',
        'filtrarPorSubgrupoModal',
        'actualizarInfoFiltroModal',
        'actualizarBotonesAccesoRapidoModal',
        'alternarColumnaSubgrupoModal',
        'mostrarInfoFiltradoActivo',
        'mostrarEstadisticasSubgruposModal',
        'buscarEstudianteEnModal',
        'seleccionarSubgrupoModalYCerrar'
    ];
    
    const faltantes = funcionesRequeridas.filter(func => typeof window[func] !== 'function');
    
    if (faltantes.length === 0) {
        console.log('✅ Funciones globales verificadas en modal');
        return true;
    } else {
        console.error('❌ Funciones faltantes en modal:', faltantes);
        return false;
    }
}

// Inicialización del modal
document.addEventListener('DOMContentLoaded', function() {
    console.log('Modal de calificación cargado');
    
    // Verificar funciones
    const funcionesDisponibles = verificarFuncionesGlobalesModal();
    
    if (funcionesDisponibles) {
        console.log('✅ Sistema de subgrupos del modal inicializado correctamente');
        
        // Inicializar eventos específicos del modal
        const select = document.getElementById('filtro-subgrupo-modal');
        if (select) {
            select.addEventListener('change', window.filtrarPorSubgrupoModal);
        }
        
        // Configurar colapso del filtro
        const btnColapso = document.querySelector('[data-bs-target="#filtros-modal-content"]');
        const iconoColapso = document.getElementById('icono-filtros-modal');
        
        if (btnColapso && iconoColapso) {
            btnColapso.addEventListener('click', function() {
                setTimeout(() => {
                    const isCollapsed = !document.getElementById('filtros-modal-content').classList.contains('show');
                    iconoColapso.className = isCollapsed ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
                }, 350);
            });
        }
        
        // Detectar cambios en calificaciones para actualizar estadísticas
        const inputsCalificacion = document.querySelectorAll('.calificacion-input');
        inputsCalificacion.forEach(input => {
            input.addEventListener('change', function() {
                // Re-aplicar filtro para actualizar contadores
                setTimeout(() => {
                    if (typeof window.filtrarPorSubgrupoModal === 'function') {
                        window.filtrarPorSubgrupoModal();
                    }
                }, 100);
            });
        });
        
        // Inicializar tooltips en el modal
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(tooltip => {
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                new bootstrap.Tooltip(tooltip);
            }
        });
        
    } else {
        console.warn('⚠️ Algunas funciones no están disponibles, el filtrado puede no funcionar');
    }
    
    // CSS específico para el modal (solo si no existe)
    if (!document.getElementById('modal-subgrupos-styles')) {
        const style = document.createElement('style');
        style.id = 'modal-subgrupos-styles';
        style.textContent = `
            .fila-oculta-modal {
                opacity: 0.3;
                transform: scale(0.95);
                transition: all 0.2s ease;
            }
            
            #selector-subgrupos-modal .btn-group {
                gap: 2px;
            }
            
            #selector-subgrupos-modal .btn-group .btn {
                transition: all 0.2s ease;
            }
            
            #selector-subgrupos-modal .btn-group .btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            #info-filtrado-activo {
                animation: fadeInDown 0.3s ease-out;
            }
            
            @keyframes fadeInDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .progress {
                border-radius: 4px;
                overflow: hidden;
            }
            
            .modal-body .table-responsive {
                border-radius: 6px;
                border: 1px solid #dee2e6;
            }
            
            @media (max-width: 768px) {
                #selector-subgrupos-modal .btn-group {
                    flex-direction: column;
                    align-items: stretch;
                }
                
                #selector-subgrupos-modal .btn-group .btn {
                    margin-bottom: 2px;
                    border-radius: 4px !important;
                }
                
                .celda-subgrupo,
                #columna-subgrupo-modal {
                    display: none !important;
                }
            }
        `;
        document.head.appendChild(style);
    }
});

// Función para detectar cuando el modal se va a cerrar
document.addEventListener('hide.bs.modal', function(event) {
    if (event.target.id === 'modalCalificarContenido') {
        console.log('Modal de calificación cerrándose');
    }
});

console.log('Sistema de filtrado de subgrupos para modal cargado correctamente');

// Actualizar la variable global cuando cambie el filtro
document.addEventListener('DOMContentLoaded', function() {
    // Asegurar que la variable global esté sincronizada
    const select = document.getElementById('filtro-subgrupo-modal');
    if (select) {
        // Función para sincronizar filtro con variable global
        function sincronizarFiltroGlobal() {
            if (typeof window.subgrupoActualModal !== 'undefined') {
                window.subgrupoActualModal = select.value;
            } else {
                window.subgrupoActualModal = 'todos';
            }
            console.log('Filtro sincronizado:', window.subgrupoActualModal);
        }
        
        // Sincronizar al cargar
        sincronizarFiltroGlobal();
        
        // Sincronizar al cambiar
        select.addEventListener('change', sincronizarFiltroGlobal);
    }
    
    // Actualizar la función filtrarPorSubgrupoModal existente para sincronizar
    const originalFiltrar = window.filtrarPorSubgrupoModal;
    if (originalFiltrar) {
        window.filtrarPorSubgrupoModal = function() {
            // Llamar función original
            originalFiltrar();
            
            // Sincronizar variable global
            const select = document.getElementById('filtro-subgrupo-modal');
            if (select && typeof window.subgrupoActualModal !== 'undefined') {
                window.subgrupoActualModal = select.value;
                console.log('Filtro actualizado a:', window.subgrupoActualModal);
            }
        };
    }
});

// Debug mejorado específico para el modal
window.debugModalFiltros = function() {
    console.log('=== DEBUG MODAL FILTROS ===');
    
    const select = document.getElementById('filtro-subgrupo-modal');
    const filtroSeleccionado = select ? select.value : 'no encontrado';
    
    console.log('Select encontrado:', !!select);
    console.log('Valor del select:', filtroSeleccionado);
    console.log('Variable global:', window.subgrupoActualModal);
    
    const filasTotal = document.querySelectorAll('.fila-estudiante-modal');
    const filasVisibles = document.querySelectorAll('.fila-estudiante-modal:not(.fila-oculta-modal)');
    const filasOcultas = document.querySelectorAll('.fila-estudiante-modal.fila-oculta-modal');
    
    console.log('Filas totales:', filasTotal.length);
    console.log('Filas visibles:', filasVisibles.length);
    console.log('Filas ocultas:', filasOcultas.length);
    
    const estudiantesVisibles = [];
    filasVisibles.forEach((fila, index) => {
        const id = fila.getAttribute('data-estudiante-id');
        const subgrupo = fila.getAttribute('data-subgrupo');
        estudiantesVisibles.push({
            fila: index + 1,
            id: parseInt(id),
            subgrupo: subgrupo
        });
    });
    
    console.log('Estudiantes visibles detalle:', estudiantesVisibles);
    console.log('IDs para acciones masivas:', estudiantesVisibles.map(e => e.id));
    
    return {
        selectEncontrado: !!select,
        filtroSeleccionado: filtroSeleccionado,
        variableGlobal: window.subgrupoActualModal,
        filasTotal: filasTotal.length,
        filasVisibles: filasVisibles.length,
        estudiantesVisibles: estudiantesVisibles,
        idsParaAcciones: estudiantesVisibles.map(e => e.id)
    };
};
</script>

<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    No hay estudiantes asignados a esta materia para calificar.
</div>
<?php endif; ?>
