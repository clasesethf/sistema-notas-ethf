<?php
/**
 * materias_pendientes_agrupadas.php - Gestión de Materias Pendientes con Agrupación
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORADO: Gestiona materias pendientes con lógica de agrupación
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';
require_once 'funciones_grupos_pendientes.php';

// Verificar permisos (solo preceptores y administradores pueden acceder)
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['preceptor', 'admin'])) {
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

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['accion'])) {
            switch ($_POST['accion']) {
                case 'asignar_materia':
                    // Asignar nueva materia pendiente
                    $estudiante_id = intval($_POST['estudiante_id']);
                    $materia_curso_id = intval($_POST['materia_curso_id']);
                    $anio_cursada = intval($_POST['anio_cursada']);
                    $saberes_iniciales = $_POST['saberes_iniciales'] ?? '';
                    
                    // Verificar que no exista ya la asignación
                    $existe = $db->fetchOne(
                        "SELECT id FROM materias_pendientes_intensificacion 
                         WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                        [$estudiante_id, $materia_curso_id, $cicloLectivoId]
                    );
                    
                    if (!$existe) {
                        $db->query(
                            "INSERT INTO materias_pendientes_intensificacion 
                             (estudiante_id, materia_curso_id, ciclo_lectivo_id, anio_cursada, 
                              saberes_iniciales, fecha_creacion) 
                             VALUES (?, ?, ?, ?, ?, DATETIME('now'))",
                            [$estudiante_id, $materia_curso_id, $cicloLectivoId, $anio_cursada, $saberes_iniciales]
                        );
                        
                        $_SESSION['message'] = 'Materia pendiente asignada exitosamente.';
                        $_SESSION['message_type'] = 'success';
                    } else {
                        $_SESSION['message'] = 'Esta materia ya está asignada como pendiente para este estudiante.';
                        $_SESSION['message_type'] = 'warning';
                    }
                    break;

                case 'asignar_multiples_materias':
                    // Asignar múltiples materias pendientes
                    $estudiante_id = intval($_POST['estudiante_id']);
                    $materias_seleccionadas = $_POST['materias_seleccionadas'] ?? [];
                    $anios_cursadas = $_POST['anios_cursadas'] ?? [];
                    $saberes_iniciales_multiple = $_POST['saberes_iniciales_multiple'] ?? [];
                    
                    $materiasAsignadas = 0;
                    $materiasYaExisten = 0;
                    $errores = [];
                    
                    foreach ($materias_seleccionadas as $materia_curso_id) {
                        $materia_curso_id = intval($materia_curso_id);
                        $anio_cursada = intval($anios_cursadas[$materia_curso_id] ?? date('Y') - 1);
                        $saberes_iniciales = $saberes_iniciales_multiple[$materia_curso_id] ?? '';
                        
                        try {
                            // Verificar que no exista ya la asignación
                            $existe = $db->fetchOne(
                                "SELECT id FROM materias_pendientes_intensificacion 
                                 WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                                [$estudiante_id, $materia_curso_id, $cicloLectivoId]
                            );
                            
                            if (!$existe) {
                                $db->query(
                                    "INSERT INTO materias_pendientes_intensificacion 
                                     (estudiante_id, materia_curso_id, ciclo_lectivo_id, anio_cursada, 
                                      saberes_iniciales, fecha_creacion) 
                                     VALUES (?, ?, ?, ?, ?, DATETIME('now'))",
                                    [$estudiante_id, $materia_curso_id, $cicloLectivoId, $anio_cursada, $saberes_iniciales]
                                );
                                $materiasAsignadas++;
                            } else {
                                $materiasYaExisten++;
                            }
                        } catch (Exception $e) {
                            $errores[] = "Error al asignar materia ID $materia_curso_id: " . $e->getMessage();
                        }
                    }
                    
                    // Crear mensaje de resultado
                    $mensajes = [];
                    if ($materiasAsignadas > 0) {
                        $mensajes[] = "$materiasAsignadas materia(s) asignada(s) exitosamente";
                    }
                    if ($materiasYaExisten > 0) {
                        $mensajes[] = "$materiasYaExisten materia(s) ya estaban asignadas";
                    }
                    if (!empty($errores)) {
                        $mensajes = array_merge($mensajes, $errores);
                    }
                    
                    if ($materiasAsignadas > 0) {
                        $_SESSION['message'] = implode('. ', $mensajes);
                        $_SESSION['message_type'] = 'success';
                    } else if ($materiasYaExisten > 0 && empty($errores)) {
                        $_SESSION['message'] = implode('. ', $mensajes);
                        $_SESSION['message_type'] = 'warning';
                    } else {
                        $_SESSION['message'] = 'No se pudo asignar ninguna materia. ' . implode('. ', $mensajes);
                        $_SESSION['message_type'] = 'danger';
                    }
                    break;
                    
                case 'actualizar_calificacion':
                    // Actualizar calificaciones de intensificación
                    $pendiente_id = intval($_POST['pendiente_id']);
                    $campo = $_POST['campo'];
                    $valor = $_POST['valor'] ?? null;
                    
                    // Validar campos permitidos
                    $camposPermitidos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero', 'calificacion_final', 'saberes_cierre'];
                    
                    if (in_array($campo, $camposPermitidos)) {
                        $sql = "UPDATE materias_pendientes_intensificacion SET $campo = ? WHERE id = ?";
                        $db->query($sql, [$valor, $pendiente_id]);
                        
                        $_SESSION['message'] = 'Calificación actualizada exitosamente.';
                        $_SESSION['message_type'] = 'success';
                    }
                    break;
                    
                case 'eliminar_pendiente':
                    // Eliminar materia pendiente
                    $pendiente_id = intval($_POST['pendiente_id']);
                    
                    $db->query(
                        "DELETE FROM materias_pendientes_intensificacion WHERE id = ?",
                        [$pendiente_id]
                    );
                    
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

// Obtener cursos
$cursos = [];
try {
    $cursos = $db->fetchAll(
        "SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio",
        [$cicloLectivoId]
    );
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los cursos: ' . $e->getMessage() . '</div>';
}

// Procesar selección
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$estudianteSeleccionado = isset($_GET['estudiante']) ? intval($_GET['estudiante']) : null;

// Variables para almacenar datos
$estudiantes = [];
$datosEstudiante = null;
$materiasAgrupadasPendientes = [];
$todasLasMaterias = [];

// Si se seleccionó un curso
if ($cursoSeleccionado) {
    try {
        // Obtener estudiantes del curso
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni 
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE m.curso_id = ? AND m.estado = 'activo'
             ORDER BY u.apellido, u.nombre",
            [$cursoSeleccionado]
        );
        
        // Obtener todas las materias disponibles
        $todasLasMaterias = $db->fetchAll(
            "SELECT 
                mpc.id as materia_curso_id, 
                m.nombre as materia_nombre, 
                m.codigo as materia_codigo, 
                c.anio, 
                c.nombre as curso_nombre,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM materias_por_curso mpc
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE c.ciclo_lectivo_id = ?
             ORDER BY c.anio, nombre_mostrar, m.nombre",
            [$cicloLectivoId]
        );
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

// Si se seleccionó un estudiante
if ($estudianteSeleccionado && $cursoSeleccionado) {
    try {
        // Obtener datos del estudiante
        $datosEstudiante = $db->fetchOne(
            "SELECT u.*, c.nombre as curso_nombre, c.anio as curso_anio
             FROM usuarios u
             JOIN matriculas m ON u.id = m.estudiante_id
             JOIN cursos c ON m.curso_id = c.id
             WHERE u.id = ? AND m.curso_id = ?",
            [$estudianteSeleccionado, $cursoSeleccionado]
        );
        
        // Obtener materias pendientes agrupadas
        $materiasAgrupadasPendientes = agruparMateriasPendientesPorGrupo($db, $estudianteSeleccionado, $cicloLectivoId);
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos del estudiante: ' . $e->getMessage() . '</div>';
    }
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-clock-history"></i> Materias Pendientes de Intensificación - Con Agrupación</h1>
                <div class="text-muted">
                    <i class="bi bi-calendar3"></i> Ciclo Lectivo <?= $anioActivo ?>
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

    <!-- Información sobre agrupación -->
    <div class="alert alert-info mb-4">
        <h6><i class="bi bi-info-circle"></i> Información sobre Agrupación de Materias</h6>
        <div class="row">
            <div class="col-md-8">
                <p><strong>Nueva funcionalidad:</strong> Las materias que pertenecen al mismo grupo (como "Lenguajes Tecnológicos") se muestran agrupadas en una sola fila.</p>
                <ul class="mb-0">
                    <li><strong>Grupos:</strong> Varias materias relacionadas se evalúan como una unidad</li>
                    <li><strong>Criterio de aprobación:</strong> Debe aprobar TODAS las materias del grupo para aprobar el grupo completo</li>
                    <li><strong>Calificación:</strong> La calificación final del grupo se calcula automáticamente</li>
                </ul>
            </div>
            <div class="col-md-4">
                <div class="text-center">
                    <span class="badge bg-primary fs-6">GRUPOS</span><br>
                    <small>Materias agrupadas</small>
                    <hr class="my-2">
                    <span class="badge bg-secondary fs-6">INDIVIDUALES</span><br>
                    <small>Materias independientes</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Selector de Curso -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-building"></i> Seleccionar Curso</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label for="curso" class="form-label">Curso</label>
                    <select name="curso" id="curso" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecciona un curso...</option>
                        <?php foreach ($cursos as $curso): ?>
                            <option value="<?= $curso['id'] ?>" 
                                    <?= $cursoSeleccionado == $curso['id'] ? 'selected' : '' ?>>
                                <?= $curso['anio'] ?>° Año - <?= htmlspecialchars($curso['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Selector de Estudiante -->
    <?php if ($cursoSeleccionado && !empty($estudiantes)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-person"></i> Seleccionar Estudiante</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="curso" value="<?= $cursoSeleccionado ?>">
                <div class="col-md-8">
                    <label for="estudiante" class="form-label">Estudiante</label>
                    <select name="estudiante" id="estudiante" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecciona un estudiante...</option>
                        <?php foreach ($estudiantes as $estudiante): ?>
                            <option value="<?= $estudiante['id'] ?>" 
                                    <?= $estudianteSeleccionado == $estudiante['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?> 
                                (DNI: <?= $estudiante['dni'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Gestión de Materias Pendientes del Estudiante -->
    <?php if ($datosEstudiante): ?>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5><i class="bi bi-person-check"></i> 
                <?= htmlspecialchars($datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre']) ?>
                <small class="ms-2"><?= $datosEstudiante['curso_anio'] ?>° Año - DNI: <?= $datosEstudiante['dni'] ?></small>
            </h5>
        </div>
        <div class="card-body">
            
            <!-- Formulario para Asignar Nueva Materia Pendiente -->
            <div class="row mb-4">
                <div class="col-12">
                    <h6><i class="bi bi-plus-circle"></i> Asignar Nueva Materia Pendiente</h6>
                    
                    <!-- Pestañas para elegir entre asignación individual o múltiple -->
                    <ul class="nav nav-tabs" id="asignacionTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="individual-tab" data-bs-toggle="tab" data-bs-target="#individual" type="button" role="tab">
                                <i class="bi bi-file-plus"></i> Individual
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="multiple-tab" data-bs-toggle="tab" data-bs-target="#multiple" type="button" role="tab">
                                <i class="bi bi-files"></i> Múltiples Materias
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content border border-top-0 p-3" id="asignacionTabsContent">
                        <!-- Tab Individual -->
                        <div class="tab-pane fade show active" id="individual" role="tabpanel">
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="accion" value="asignar_materia">
                                <input type="hidden" name="estudiante_id" value="<?= $datosEstudiante['id'] ?>">
                                
                                <div class="col-md-4">
                                    <label class="form-label">Materia</label>
                                    <select name="materia_curso_id" class="form-select" required>
                                        <option value="">Selecciona una materia...</option>
                                        <?php foreach ($todasLasMaterias as $materia): ?>
                                            <option value="<?= $materia['materia_curso_id'] ?>">
                                                <?= $materia['anio'] ?>° Año - <?= htmlspecialchars($materia['nombre_mostrar']) ?>
                                                <?php if ($materia['es_parte_grupo'] == 1): ?>
                                                    <small class="text-muted"> → <?= htmlspecialchars($materia['materia_nombre']) ?></small>
                                                <?php endif; ?>
                                                <?php if ($materia['codigo_mostrar']): ?> (<?= $materia['codigo_mostrar'] ?>)<?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Año Cursada</label>
                                    <select name="anio_cursada" class="form-select" required>
                                        <?php for ($i = 2020; $i <= $anioActivo; $i++): ?>
                                            <option value="<?= $i ?>" <?= $i == ($anioActivo - 1) ? 'selected' : '' ?>><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="form-label">Saberes Iniciales Pendientes</label>
                                    <input type="text" name="saberes_iniciales" class="form-control" 
                                           placeholder="Descripción de saberes pendientes...">
                                </div>
                                
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bi bi-plus"></i> Asignar
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Tab Múltiples -->
                        <div class="tab-pane fade" id="multiple" role="tabpanel">
                            <form method="POST" id="formMultiple">
                                <input type="hidden" name="accion" value="asignar_multiples_materias">
                                <input type="hidden" name="estudiante_id" value="<?= $datosEstudiante['id'] ?>">
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Asignación múltiple:</strong> Selecciona todas las materias que quieres asignar como pendientes. 
                                    Las materias del mismo grupo se mostrarán agrupadas en el boletín.
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="seleccionarTodasMaterias()">
                                            <i class="bi bi-check-all"></i> Seleccionar Todas
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm ms-2" onclick="deseleccionarTodasMaterias()">
                                            <i class="bi bi-x-square"></i> Deseleccionar Todas
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Materias agrupadas por año y grupo -->
                                <?php 
                                $materiasPorAnio = [];
                                foreach ($todasLasMaterias as $materia) {
                                    $materiasPorAnio[$materia['anio']][] = $materia;
                                }
                                ?>
                                
                                <div class="row">
                                    <?php foreach ($materiasPorAnio as $anio => $materias): ?>
                                    <div class="col-md-6 mb-4">
                                        <div class="card">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0">
                                                    <input type="checkbox" class="form-check-input me-2" 
                                                           onchange="toggleAnio(<?= $anio ?>, this.checked)">
                                                    <?= $anio ?>° Año
                                                </h6>
                                            </div>
                                            <div class="card-body p-2">
                                                <?php foreach ($materias as $materia): ?>
                                                <div class="border-bottom py-2 <?= $materia['es_parte_grupo'] ? 'materia-grupo' : 'materia-individual' ?>">
                                                    <div class="form-check">
                                                        <input type="checkbox" 
                                                               class="form-check-input materia-checkbox anio-<?= $anio ?>" 
                                                               name="materias_seleccionadas[]" 
                                                               value="<?= $materia['materia_curso_id'] ?>"
                                                               id="materia_<?= $materia['materia_curso_id'] ?>"
                                                               onchange="toggleMateriaConfig(<?= $materia['materia_curso_id'] ?>, this.checked)">
                                                        <label class="form-check-label fw-bold" for="materia_<?= $materia['materia_curso_id'] ?>">
                                                            <?php if ($materia['es_parte_grupo'] == 1): ?>
                                                                <span class="badge bg-primary me-1">GRUPO</span>
                                                                <span class="grupo-nombre"><?= htmlspecialchars($materia['nombre_mostrar']) ?></span>
                                                                <br><small class="text-muted ms-3">
                                                                    <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($materia['materia_nombre']) ?>
                                                                </small>
                                                            <?php else: ?>
                                                                <span class="materia-nombre-principal"><?= htmlspecialchars($materia['nombre_mostrar']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($materia['codigo_mostrar']): ?>
                                                                <small class="text-muted">(<?= $materia['codigo_mostrar'] ?>)</small>
                                                            <?php endif; ?>
                                                        </label>
                                                    </div>
                                                    
                                                    <!-- Configuración específica de la materia -->
                                                    <div id="config_<?= $materia['materia_curso_id'] ?>" class="mt-2" style="display: none;">
                                                        <div class="row g-2">
                                                            <div class="col-4">
                                                                <label class="form-label small">Año que Cursó</label>
                                                                <select name="anios_cursadas[<?= $materia['materia_curso_id'] ?>]" class="form-select form-select-sm">
                                                                    <?php for ($i = 2020; $i <= $anioActivo; $i++): ?>
                                                                        <option value="<?= $i ?>" <?= $i == ($anioActivo - 1) ? 'selected' : '' ?>><?= $i ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-8">
                                                                <label class="form-label small">Saberes Iniciales</label>
                                                                <input type="text" 
                                                                       name="saberes_iniciales_multiple[<?= $materia['materia_curso_id'] ?>]" 
                                                                       class="form-control form-control-sm" 
                                                                       placeholder="Saberes pendientes...">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <button type="submit" class="btn btn-success btn-lg" disabled id="btnAsignarMultiple">
                                        <i class="bi bi-check-circle"></i> Asignar <span id="contadorSeleccionadas">0</span> Materias Seleccionadas
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de Materias Pendientes Agrupadas (Formato RITE) -->
            <?php if (!empty($materiasAgrupadasPendientes)): ?>
            <div class="table-responsive">
                <h6 class="mb-3">
                    <i class="bi bi-table"></i> Materias Pendientes de Aprobación y Acreditación - Intensificación (Agrupadas)
                    <span class="badge bg-warning text-dark ms-2">
                        <?php 
                        $estadisticas = obtenerEstadisticasPendientesAgrupadas($materiasAgrupadasPendientes);
                        echo $estadisticas['total'] . ' materia(s)/grupo(s)';
                        ?>
                    </span>
                </h6>
                
                <table class="table table-bordered table-sm">
                    <thead class="table-primary">
                        <tr>
                            <th rowspan="2" class="align-middle text-center" style="min-width: 250px;">MATERIAS/GRUPOS</th>
                            <th rowspan="2" class="align-middle text-center">AÑO</th>
                            <th rowspan="2" class="align-middle text-center" style="min-width: 150px;">
                                SABERES INICIALES<br>PENDIENTES DE APROBACIÓN<br>AL INICIO DEL CICLO LECTIVO
                            </th>
                            <th colspan="5" class="text-center" style="background-color: #e3f2fd;">
                                PERÍODO DE INTENSIFICACIÓN
                            </th>
                            <th rowspan="2" class="align-middle text-center">
                                CALIFICACIÓN<br>FINAL
                            </th>
                            <th rowspan="2" class="align-middle text-center" style="min-width: 150px;">
                                SABERES PENDIENTES DE<br>APROBACIÓN AL CIERRE<br>DEL CICLO LECTIVO
                            </th>
                            <th rowspan="2" class="align-middle text-center">ACCIONES</th>
                        </tr>
                        <tr>
                            <th class="text-center" style="background-color: #e3f2fd;">MARZO</th>
                            <th class="text-center" style="background-color: #e3f2fd;">JULIO</th>
                            <th class="text-center" style="background-color: #e3f2fd;">AGOSTO</th>
                            <th class="text-center" style="background-color: #e3f2fd;">DICIEMBRE</th>
                            <th class="text-center" style="background-color: #e3f2fd;">FEBRERO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materiasAgrupadasPendientes as $item): ?>
                        <?php if ($item['es_grupo']): ?>
                            <!-- Fila del grupo -->
                            <?php $estadoGrupo = calcularEstadoGrupoPendiente($item['materias']); ?>
                            <tr class="grupo-principal-row">
                                <td class="fw-bold">
                                    <!-- Información del grupo -->
                                    <div class="grupo-header">
                                        <span class="badge bg-primary me-2">GRUPO</span>
                                        <span class="grupo-titulo"><?= htmlspecialchars($item['grupo_nombre']) ?></span>
                                        <?php if ($item['grupo_codigo']): ?>
                                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($item['grupo_codigo']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Materias del grupo -->
                                    <div class="materias-del-grupo mt-2">
                                        <?php foreach ($item['materias'] as $matGrupo): ?>
                                        <div class="materia-individual-grupo border-start border-2 border-primary ps-2 mb-1">
                                            <small>
                                                <i class="bi bi-arrow-right text-primary"></i> 
                                                <strong><?= htmlspecialchars($matGrupo['materia_nombre']) ?></strong>
                                                <?php if ($matGrupo['materia_codigo']): ?>
                                                    <span class="text-muted">(<?= htmlspecialchars($matGrupo['materia_codigo']) ?>)</span>
                                                <?php endif; ?>
                                                
                                                <!-- Estado individual de cada materia -->
                                                <?php if (!empty($matGrupo['calificacion_final'])): ?>
                                                    <span class="badge bg-<?= $matGrupo['calificacion_final'] >= 4 ? 'success' : 'danger' ?> ms-1" style="font-size: 0.6em;">
                                                        <?= $matGrupo['calificacion_final'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <?php 
                                                    $ultimoEstado = '';
                                                    $periodos = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo'];
                                                    foreach ($periodos as $periodo) {
                                                        if (!empty($matGrupo[$periodo])) {
                                                            $ultimoEstado = $matGrupo[$periodo];
                                                            break;
                                                        }
                                                    }
                                                    if ($ultimoEstado): ?>
                                                        <span class="badge bg-<?= $ultimoEstado === 'AA' ? 'success' : ($ultimoEstado === 'CCA' ? 'warning' : 'danger') ?> ms-1" style="font-size: 0.6em;">
                                                            <?= $ultimoEstado ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <!-- Botón para editar materia individual -->
                                                <button type="button" class="btn btn-outline-primary btn-sm ms-1" style="font-size: 0.6em; padding: 1px 4px;" 
                                                        onclick="editarMateriaIndividual(<?= $matGrupo['id'] ?>, '<?= addslashes($matGrupo['materia_nombre']) ?>')"
                                                        title="Editar esta materia específica">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                
                                <td class="text-center"><?= $item['materias'][0]['curso_anio'] ?>°</td>
                                
                                <!-- Saberes iniciales consolidados -->
                                <td>
                                    <?php 
                                    $saberes = array_filter(array_map(function($m) { return $m['saberes_iniciales']; }, $item['materias']));
                                    if (!empty($saberes)): 
                                    ?>
                                        <?php foreach ($saberes as $saber): ?>
                                            <div class="mb-1"><small><?= nl2br(htmlspecialchars($saber)) ?></small></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Períodos de intensificación consolidados -->
                                <?php 
                                $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
                                foreach ($periodos as $periodo): 
                                    $estadosPeriodo = [];
                                    foreach ($item['materias'] as $matGrupo) {
                                        if (!empty($matGrupo[$periodo])) {
                                            $estadosPeriodo[] = $matGrupo[$periodo];
                                        }
                                    }
                                    
                                    $estadoConsolidado = '';
                                    if (count($estadosPeriodo) === count($item['materias'])) {
                                        // Todas tienen estado
                                        if (count(array_filter($estadosPeriodo, function($e) { return $e === 'AA'; })) === count($estadosPeriodo)) {
                                            $estadoConsolidado = 'AA';
                                        } elseif (in_array('CCA', $estadosPeriodo)) {
                                            $estadoConsolidado = 'CCA';
                                        } else {
                                            $estadoConsolidado = 'CSA';
                                        }
                                    }
                                ?>
                                <td class="text-center">
                                    <?php if ($estadoConsolidado): ?>
                                        <span class="badge bg-<?= $estadoConsolidado === 'AA' ? 'success' : ($estadoConsolidado === 'CCA' ? 'warning' : 'danger') ?>">
                                            <?= $estadoConsolidado ?>
                                        </span>
                                        <br><small class="text-muted"><?= count($estadosPeriodo) ?>/<?= count($item['materias']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php if (count($estadosPeriodo) > 0): ?>
                                        <br><small class="text-warning"><?= count($estadosPeriodo) ?>/<?= count($item['materias']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                                
                                <!-- Calificación final del grupo -->
                                <td class="text-center">
                                    <?php if ($estadoGrupo['calificacion_final_grupo'] !== null): ?>
                                        <span class="badge bg-<?= $estadoGrupo['calificacion_final_grupo'] >= 7 ? 'success' : ($estadoGrupo['calificacion_final_grupo'] >= 4 ? 'warning' : 'danger') ?>">
                                            <?= $estadoGrupo['calificacion_final_grupo'] ?>
                                        </span>
                                        <br><small class="text-muted">GRUPO</small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php 
                                        $conCalif = array_filter($item['materias'], function($m) { return !empty($m['calificacion_final']); });
                                        if (!empty($conCalif)): 
                                        ?>
                                        <br><small class="text-info"><?= count($conCalif) ?>/<?= count($item['materias']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Saberes al cierre consolidados -->
                                <td>
                                    <?php 
                                    $saberesCierre = array_filter(array_map(function($m) { return $m['saberes_cierre']; }, $item['materias']));
                                    if (!empty($saberesCierre)): 
                                    ?>
                                        <?php foreach ($saberesCierre as $saber): ?>
                                            <div class="mb-1"><small><?= nl2br(htmlspecialchars($saber)) ?></small></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Acciones -->
                                <td class="text-center">
                                    <div class="btn-group-vertical">
                                        <button type="button" class="btn btn-outline-info btn-sm mb-1" 
                                                onclick="verDetalleGrupo(<?= $item['grupo_id'] ?>)"
                                                title="Ver detalle del grupo">
                                            <i class="bi bi-eye"></i> Grupo
                                        </button>
                                        <?php foreach ($item['materias'] as $matGrupo): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                onclick="eliminarMateriaPendiente(<?= $matGrupo['id'] ?>, '<?= addslashes($matGrupo['materia_nombre']) ?>')"
                                                title="Eliminar <?= htmlspecialchars($matGrupo['materia_nombre']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <!-- Materia individual -->
                            <?php $materia = $item['materias'][0]; ?>
                            <tr class="materia-individual-row">
                                <td class="fw-bold">
                                    <span class="materia-nombre-principal"><?= htmlspecialchars($materia['nombre_mostrar']) ?></span>
                                    <?php if ($materia['codigo_mostrar']): ?>
                                        <span class="badge bg-secondary ms-1"><?= htmlspecialchars($materia['codigo_mostrar']) ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center"><?= $materia['curso_anio'] ?>°</td>
                                
                                <td>
                                    <div class="editable-cell" data-campo="saberes_iniciales" data-id="<?= $materia['id'] ?>">
                                        <?= htmlspecialchars($materia['saberes_iniciales'] ?? '') ?>
                                    </div>
                                </td>
                                
                                <!-- Período de Intensificación -->
                                <td class="text-center">
                                    <div class="editable-cell" data-campo="marzo" data-id="<?= $materia['id'] ?>">
                                        <?= $materia['marzo'] ?? '-' ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="editable-cell" data-campo="julio" data-id="<?= $materia['id'] ?>">
                                        <?= $materia['julio'] ?? '-' ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="editable-cell" data-campo="agosto" data-id="<?= $materia['id'] ?>">
                                        <?= $materia['agosto'] ?? '-' ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="editable-cell" data-campo="diciembre" data-id="<?= $materia['id'] ?>">
                                        <?= $materia['diciembre'] ?? '-' ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="editable-cell" data-campo="febrero" data-id="<?= $materia['id'] ?>">
                                        <?= $materia['febrero'] ?? '-' ?>
                                    </div>
                                </td>
                                
                                <td class="text-center">
                                    <div class="editable-cell" data-campo="calificacion_final" data-id="<?= $materia['id'] ?>">
                                        <?php if ($materia['calificacion_final']): ?>
                                            <span class="badge bg-<?= $materia['calificacion_final'] >= 7 ? 'success' : ($materia['calificacion_final'] >= 4 ? 'warning' : 'danger') ?>">
                                                <?= $materia['calificacion_final'] ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td>
                                    <div class="editable-cell" data-campo="saberes_cierre" data-id="<?= $materia['id'] ?>">
                                        <?= htmlspecialchars($materia['saberes_cierre'] ?? '') ?>
                                    </div>
                                </td>
                                
                                <td class="text-center">
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('¿Estás seguro de eliminar esta materia pendiente?')">
                                        <input type="hidden" name="accion" value="eliminar_pendiente">
                                        <input type="hidden" name="pendiente_id" value="<?= $materia['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> 
                Este estudiante no tiene materias pendientes de intensificación asignadas para el ciclo lectivo <?= $anioActivo ?>.
            </div>
            <?php endif; ?>
            
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal para Edición Rápida -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Editar Calificación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST">
                    <input type="hidden" name="accion" value="actualizar_calificacion">
                    <input type="hidden" id="pendiente_id" name="pendiente_id">
                    <input type="hidden" id="campo" name="campo">
                    
                    <div class="mb-3">
                        <label for="valor" class="form-label">Valor</label>
                        <input type="text" class="form-control" id="valor" name="valor">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="editForm" class="btn btn-primary">Guardar</button>
            </div>
        </div>
    </div>
</div>

<script>
// Funcionalidad de edición en línea
document.addEventListener('DOMContentLoaded', function() {
    const editableCells = document.querySelectorAll('.editable-cell');
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
    
    editableCells.forEach(cell => {
        cell.style.cursor = 'pointer';
        cell.title = 'Click para editar';
        
        cell.addEventListener('click', function() {
            const campo = this.dataset.campo;
            const id = this.dataset.id;
            const valorActual = this.textContent.trim();
            
            document.getElementById('pendiente_id').value = id;
            document.getElementById('campo').value = campo;
            document.getElementById('valor').value = valorActual === '-' ? '' : valorActual;
            
            editModal.show();
        });
        
        // Efecto hover
        cell.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        cell.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
});

// Funcionalidad para asignación múltiple de materias
function toggleMateriaConfig(materiaId, mostrar) {
    const configDiv = document.getElementById('config_' + materiaId);
    if (configDiv) {
        configDiv.style.display = mostrar ? 'block' : 'none';
    }
    actualizarContadorSeleccionadas();
}

function seleccionarTodasMaterias() {
    const checkboxes = document.querySelectorAll('.materia-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
        toggleMateriaConfig(checkbox.value, true);
    });
    actualizarContadorSeleccionadas();
}

function deseleccionarTodasMaterias() {
    const checkboxes = document.querySelectorAll('.materia-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
        toggleMateriaConfig(checkbox.value, false);
    });
    actualizarContadorSeleccionadas();
}

function toggleAnio(anio, seleccionar) {
    const checkboxes = document.querySelectorAll('.anio-' + anio);
    checkboxes.forEach(checkbox => {
        checkbox.checked = seleccionar;
        toggleMateriaConfig(checkbox.value, seleccionar);
    });
    actualizarContadorSeleccionadas();
}

function actualizarContadorSeleccionadas() {
    const seleccionadas = document.querySelectorAll('.materia-checkbox:checked').length;
    const contador = document.getElementById('contadorSeleccionadas');
    const boton = document.getElementById('btnAsignarMultiple');
    
    if (contador) contador.textContent = seleccionadas;
    if (boton) {
        boton.disabled = seleccionadas === 0;
        boton.innerHTML = seleccionadas === 0 
            ? '<i class="bi bi-check-circle"></i> Selecciona materias para asignar'
            : `<i class="bi bi-check-circle"></i> Asignar ${seleccionadas} Materia${seleccionadas > 1 ? 's' : ''} Seleccionada${seleccionadas > 1 ? 's' : ''}`;
    }
}

// Función para editar materia individual dentro de un grupo
function editarMateriaIndividual(materiaId, nombreMateria) {
    document.getElementById('pendiente_id').value = materiaId;
    document.getElementById('campo').value = 'calificacion_final';
    document.getElementById('valor').value = '';
    
    const editModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal'));
    document.getElementById('editModalLabel').textContent = 'Editar: ' + nombreMateria;
    editModal.show();
}

// Función para ver detalle del grupo
function verDetalleGrupo(grupoId) {
    // Implementar modal o página de detalle del grupo
    console.log('Ver detalle del grupo ID:', grupoId);
}

// Función para eliminar materia pendiente
function eliminarMateriaPendiente(materiaId, nombreMateria) {
    if (confirm('¿Estás seguro de eliminar la materia pendiente "' + nombreMateria + '"?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="eliminar_pendiente">
            <input type="hidden" name="pendiente_id" value="${materiaId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Actualizar contador al cambiar checkboxes
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('materia-checkbox')) {
        actualizarContadorSeleccionadas();
    }
});
</script>

<style>
/* Estilos para visualización de grupos de materias */
.grupo-principal-row {
    background-color: #f8f9fa;
    border-left: 4px solid #007bff;
}

.materia-individual-row {
    border-left: 2px solid #6c757d;
}

.grupo-header {
    margin-bottom: 8px;
}

.grupo-titulo {
    font-weight: 600;
    color: #0d6efd;
    font-size: 1.1em;
}

.materias-del-grupo {
    background-color: rgba(13, 110, 253, 0.05);
    border-radius: 6px;
    padding: 10px;
}

.materia-individual-grupo {
    margin-bottom: 6px;
    padding: 4px;
    background-color: white;
    border-radius: 4px;
}

.materia-individual-grupo:last-child {
    margin-bottom: 0;
}

.materia-grupo {
    background-color: rgba(13, 110, 253, 0.1);
    border-radius: 4px;
    margin: 2px 0;
}

.materia-individual {
    background-color: rgba(108, 117, 125, 0.1);
    border-radius: 4px;
    margin: 2px 0;
}

.grupo-nombre {
    font-weight: 600;
    color: #0d6efd;
}

.materia-nombre-principal {
    font-weight: 600;
    color: #212529;
}

.editable-cell {
    transition: background-color 0.2s;
    min-height: 20px;
    padding: 4px;
    cursor: pointer;
}

.editable-cell:hover {
    background-color: #f8f9fa !important;
    border-radius: 3px;
}

.table th {
    font-size: 0.85em;
    vertical-align: middle;
}

.table td {
    font-size: 0.9em;
    vertical-align: middle;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .grupo-titulo {
        font-size: 1em;
    }
    
    .materias-del-grupo {
        padding: 6px;
    }
    
    .table {
        font-size: 0.8em;
    }
}
</style>

<?php require_once 'footer.php'; ?>
