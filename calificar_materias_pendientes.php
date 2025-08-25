<?php
/**
 * calificar_materias_pendientes.php - Calificación de Materias Pendientes de Intensificación
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * CORREGIDO: Error de sintaxis en línea 18
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

require_once 'funciones_auxiliares_grupos.php';

// CORREGIDO: Eliminada la 's' extra al final
require_once 'funciones_grupos_pendientes.php';

// Verificar permisos (solo profesores pueden acceder)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'profesor') {
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
        if (isset($_POST['accion']) && $_POST['accion'] === 'actualizar_calificacion') {
            $pendiente_id = intval($_POST['pendiente_id']);
            $campo = $_POST['campo'];
            $valor = $_POST['valor'] ?? '';
            
            // Validar campos permitidos
            $camposPermitidos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero', 'calificacion_final', 'saberes_cierre', 'saberes_iniciales'];

            
            if (in_array($campo, $camposPermitidos)) {
                // Verificar que el profesor tenga permisos para esta materia pendiente
                $verificacion = $db->fetchOne(
                    "SELECT mpi.id 
                     FROM materias_pendientes_intensificacion mpi
                     JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
                     WHERE mpi.id = ? AND (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?) AND mpi.ciclo_lectivo_id = ?",
                    [$pendiente_id, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $cicloLectivoId]
                );
                
                if ($verificacion) {
                    // Actualizar la calificación
                    $sql = "UPDATE materias_pendientes_intensificacion SET $campo = ?, fecha_modificacion = DATETIME('now') WHERE id = ?";
                    $db->query($sql, [$valor, $pendiente_id]);
                    
                    $_SESSION['message'] = 'Calificación actualizada exitosamente.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'No tienes permisos para calificar esta materia.';
                    $_SESSION['message_type'] = 'danger';
                }
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

// Obtener materias del profesor con estudiantes pendientes
$materiasConPendientes = [];
try {
    $materiasConPendientes = $db->fetchAll(
        "SELECT DISTINCT 
            mpc.id as materia_curso_id,
            m.nombre as materia_nombre,
            m.codigo as materia_codigo,
            c.anio,
            c.nombre as curso_nombre,
            gm.nombre as grupo_nombre,
            gm.codigo as grupo_codigo,
            -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
            COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
            COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
            -- Indicador si es parte de un grupo
            CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo,
            COUNT(mpi.id) as total_pendientes
         FROM materias_por_curso mpc
         JOIN materias m ON mpc.materia_id = m.id
         JOIN cursos c ON mpc.curso_id = c.id
         JOIN materias_pendientes_intensificacion mpi ON mpc.id = mpi.materia_curso_id
         LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
         LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
         WHERE (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?) 
           AND mpi.ciclo_lectivo_id = ? 
           AND mpi.estado = 'activo'
         GROUP BY mpc.id, m.nombre, m.codigo, c.anio, c.nombre, gm.nombre, gm.codigo
         ORDER BY c.anio, nombre_mostrar",
        [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $cicloLectivoId]
    );
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener las materias: ' . $e->getMessage() . '</div>';
}

// Procesar selección de materia
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;
$estudiantesPendientes = [];
$infoMateria = null; // Inicializar variable

if ($materiaSeleccionada) {
    try {
        $infoMateria = $db->fetchOne(
            "SELECT 
                mpc.*, 
                m.nombre as materia_nombre, 
                m.codigo as materia_codigo, 
                c.anio as curso_anio, 
                c.nombre as curso_nombre,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                -- Indicador si es parte de un grupo
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
             FROM materias_por_curso mpc
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             WHERE mpc.id = ? AND (mpc.profesor_id = ? OR mpc.profesor_id_2 = ? OR mpc.profesor_id_3 = ?)",
            [$materiaSeleccionada, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
        );
        
        if ($infoMateria) {
            $estudiantesPendientes = $db->fetchAll(
                "SELECT 
                    mpi.*,
                    u.nombre as estudiante_nombre,
                    u.apellido as estudiante_apellido,
                    u.dni as estudiante_dni,
                    c_actual.anio as curso_actual_anio,
                    c_actual.nombre as curso_actual_nombre
                 FROM materias_pendientes_intensificacion mpi
                 JOIN usuarios u ON mpi.estudiante_id = u.id
                 JOIN matriculas mat ON u.id = mat.estudiante_id AND mat.estado = 'activo'
                 JOIN cursos c_actual ON mat.curso_id = c_actual.id
                 WHERE mpi.materia_curso_id = ? AND mpi.ciclo_lectivo_id = ? AND mpi.estado = 'activo'
                 ORDER BY u.apellido, u.nombre",
                [$materiaSeleccionada, $cicloLectivoId]
            );
        } else {
            $_SESSION['message'] = 'No tienes permisos para acceder a esta materia.';
            $_SESSION['message_type'] = 'danger';
            $materiaSeleccionada = null;
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener los estudiantes: ' . $e->getMessage() . '</div>';
    }
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-journal-medical"></i> Calificar Materias Pendientes de Intensificación</h1>
                <div class="text-muted">
                    <i class="bi bi-calendar3"></i> Ciclo Lectivo <?= $anioActivo ?>
                    <br><small>Profesor: <?= htmlspecialchars($_SESSION['user_name'] ?? 'N/A') ?></small>
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

    <!-- Información sobre el proceso de intensificación -->
    <div class="alert alert-info mb-4">
        <h6><i class="bi bi-info-circle"></i> Información sobre Intensificación</h6>
        <div class="row">
            <div class="col-md-6">
                <p><strong>Períodos de Intensificación:</strong></p>
                <ul class="mb-0">
                    <li><strong>Marzo:</strong> Inicio del ciclo lectivo</li>
                    <li><strong>Julio:</strong> Receso invernal</li>
                    <li><strong>Agosto:</strong> Segundo cuatrimestre</li>
                    <li><strong>Diciembre:</strong> Finalización del ciclo</li>
                    <li><strong>Febrero:</strong> Instancia final</li>
                </ul>
            </div>
            <div class="col-md-6">
                <p><strong>Códigos de Calificación:</strong></p>
                <ul class="mb-0">
                    <li><strong>AA:</strong> Aprobó y Acreditó</li>
                    <li><strong>CCA:</strong> Continúa, Con Avances</li>
                    <li><strong>CSA:</strong> Continúa, Sin Avances</li>
                    <li><strong>Calificación Final:</strong> Nota numérica (4-10)</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Selector de Materia -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="bi bi-book"></i> Seleccionar Materia con Estudiantes Pendientes</h5>
        </div>
        <div class="card-body">
            <?php if (empty($materiasConPendientes)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    No tienes materias con estudiantes pendientes de intensificación en el ciclo lectivo actual.
                </div>
            <?php else: ?>
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <label for="materia" class="form-label">Materia</label>
                        <select name="materia" id="materia" class="form-select" onchange="this.form.submit()">
                            <option value="">Selecciona una materia...</option>
                            <?php foreach ($materiasConPendientes as $materia): ?>
                                <option value="<?= $materia['materia_curso_id'] ?>" 
                                        <?= $materiaSeleccionada == $materia['materia_curso_id'] ? 'selected' : '' ?>>
                                    <?= $materia['anio'] ?>° Año - <?= htmlspecialchars($materia['nombre_mostrar']) ?>
                                    <?php if ($materia['es_parte_grupo'] == 1): ?>
                                        → <?= htmlspecialchars($materia['materia_nombre']) ?>
                                    <?php endif; ?>
                                    <?php if ($materia['codigo_mostrar']): ?> (<?= $materia['codigo_mostrar'] ?>)<?php endif; ?>
                                    - <?= $materia['total_pendientes'] ?> estudiante(s) pendiente(s)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <div class="d-grid" style="margin-top: 2rem;">
                            <button type="submit" class="btn btn-primary" <?= !$materiaSeleccionada ? 'disabled' : '' ?>>
                                <i class="bi bi-eye"></i> Ver Estudiantes
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabla de Calificaciones (Formato RITE Oficial) -->
    <?php if ($materiaSeleccionada && $infoMateria && !empty($estudiantesPendientes)): ?>
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5><i class="bi bi-table"></i> 
                <span class="materia-titulo-principal"><?= htmlspecialchars($infoMateria['nombre_mostrar']) ?></span>
                <?php if ($infoMateria['codigo_mostrar']): ?>
                    <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($infoMateria['codigo_mostrar']) ?></span>
                <?php endif; ?>
                - <?= $infoMateria['curso_anio'] ?>° Año
                
                <?php if ($infoMateria['es_parte_grupo'] == 1): ?>
                    <br><small class="ms-4 opacity-75">
                        <i class="bi bi-arrow-right"></i> Materia específica: <?= htmlspecialchars($infoMateria['materia_nombre']) ?>
                        <?php if ($infoMateria['materia_codigo'] && $infoMateria['materia_codigo'] != $infoMateria['codigo_mostrar']): ?>
                            (<?= htmlspecialchars($infoMateria['materia_codigo']) ?>)
                        <?php endif; ?>
                    </small>
                <?php endif; ?>
                
                <small class="ms-2"><?= count($estudiantesPendientes) ?> estudiante(s) pendiente(s)</small>
            </h5>
        </div>
        <div class="card-body p-0">
            <!-- Formato Oficial RITE -->
            <div class="table-responsive">
                <table class="table table-bordered mb-0" style="font-size: 0.9em;">
                    <!-- Encabezado principal -->
                    <thead>
                        <tr class="table-primary text-center">
                            <td colspan="9" class="fw-bold py-3" style="background-color: #0ea5e9; color: white; font-size: 1.1em;">
                                MATERIAS PENDIENTES DE APROBACIÓN Y ACREDITACIÓN - INTENSIFICACIÓN
                            </td>
                        </tr>
                    </thead>
                    
                    <!-- Encabezados de columnas -->
                    <thead>
                        <tr class="table-info text-center">
                            <th rowspan="2" class="align-middle" style="min-width: 200px; background-color: #0ea5e9; color: white;">
                                <strong>MATERIAS</strong>
                            </th>
                            <th rowspan="2" class="align-middle" style="min-width: 60px; background-color: #0ea5e9; color: white;">
                                <strong>AÑO</strong>
                            </th>
                            <th rowspan="2" class="align-middle" style="min-width: 200px; background-color: #0ea5e9; color: white;">
                                <strong>SABERES INICIALES<br>PENDIENTES DE APROBACIÓN<br>AL INICIO DEL CICLO LECTIVO</strong>
                            </th>
                            <th colspan="5" class="text-center" style="background-color: #0ea5e9; color: white;">
                                <strong>PERÍODO DE INTENSIFICACIÓN</strong>
                            </th>
                            <th rowspan="2" class="align-middle" style="background-color: #0ea5e9; color: white;">
                                <strong>CALIFICACIÓN<br>FINAL</strong>
                            </th>
                            <th rowspan="2" class="align-middle" style="min-width: 200px; background-color: #0ea5e9; color: white;">
                                <strong>SABERES PENDIENTES DE<br>APROBACIÓN AL CIERRE<br>DEL CICLO LECTIVO</strong>
                            </th>
                        </tr>
                        <tr class="table-info text-center">
                            <th style="background-color: #0ea5e9; color: white;"><strong>MARZO</strong></th>
                            <th style="background-color: #0ea5e9; color: white;"><strong>JULIO</strong></th>
                            <th style="background-color: #0ea5e9; color: white;"><strong>AGOSTO</strong></th>
                            <th style="background-color: #0ea5e9; color: white;"><strong>DICIEMBRE</strong></th>
                            <th style="background-color: #0ea5e9; color: white;"><strong>FEBRERO</strong></th>
                        </tr>
                    </thead>
                    
                    <!-- Filas de estudiantes -->
                    <tbody>
                        <?php foreach ($estudiantesPendientes as $index => $estudiante): ?>
                        <tr class="<?= $index % 2 == 0 ? 'table-light' : '' ?>">
                            <!-- Columna de Materia con nombre del estudiante -->
                            <td class="fw-bold" style="vertical-align: middle;">
                                <!-- MOSTRAR EL NOMBRE DEL GRUPO O LA MATERIA -->
                                <div class="materia-nombre-celda">
                                    <?= htmlspecialchars($infoMateria['nombre_mostrar']) ?>
                                    <?php if ($infoMateria['codigo_mostrar']): ?>
                                        <span class="badge bg-secondary ms-1" style="font-size: 0.7em;">
                                            <?= htmlspecialchars($infoMateria['codigo_mostrar']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- SI ES PARTE DE UN GRUPO, MOSTRAR LA MATERIA ESPECÍFICA -->
                                <?php if ($infoMateria['es_parte_grupo'] == 1): ?>
                                    <small class="text-muted d-block mt-1" style="font-style: italic;">
                                        <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($infoMateria['materia_nombre']) ?>
                                        <?php if ($infoMateria['materia_codigo'] && $infoMateria['materia_codigo'] != $infoMateria['codigo_mostrar']): ?>
                                            (<?= htmlspecialchars($infoMateria['materia_codigo']) ?>)
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                                
                                <br>
                                <small class="text-muted">
                                    <i class="bi bi-person"></i> 
                                    <?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>
                                    <br>
                                    <i class="bi bi-book"></i> Curso actual: <?= htmlspecialchars($estudiante['curso_actual_nombre']) ?>
                                </small>
                            </td>
                            
                            <!-- Año cursada -->
                            <td class="text-center align-middle">
                                <strong><?= $infoMateria['curso_anio'] ?>°</strong>
                            </td>
                            
                            <!-- Saberes iniciales -->
                            <td style="vertical-align: middle; padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="saberes_iniciales" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 40px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['saberes_iniciales']): ?>
                                        <?= nl2br(htmlspecialchars($estudiante['saberes_iniciales'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Click para agregar saberes...</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Períodos de intensificación -->
                            <td class="text-center align-middle" style="padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="marzo" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 30px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['marzo']): ?>
                                        <span class="badge bg-<?= $estudiante['marzo'] === 'AA' ? 'success' : ($estudiante['marzo'] === 'CCA' ? 'warning' : 'danger') ?>">
                                            <?= $estudiante['marzo'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="text-center align-middle" style="padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="julio" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 30px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['julio']): ?>
                                        <span class="badge bg-<?= $estudiante['julio'] === 'AA' ? 'success' : ($estudiante['julio'] === 'CCA' ? 'warning' : 'danger') ?>">
                                            <?= $estudiante['julio'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="text-center align-middle" style="padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="agosto" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 30px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['agosto']): ?>
                                        <span class="badge bg-<?= $estudiante['agosto'] === 'AA' ? 'success' : ($estudiante['agosto'] === 'CCA' ? 'warning' : 'danger') ?>">
                                            <?= $estudiante['agosto'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="text-center align-middle" style="padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="diciembre" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 30px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['diciembre']): ?>
                                        <span class="badge bg-<?= $estudiante['diciembre'] === 'AA' ? 'success' : ($estudiante['diciembre'] === 'CCA' ? 'warning' : 'danger') ?>">
                                            <?= $estudiante['diciembre'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="text-center align-middle" style="padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="febrero" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 30px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['febrero']): ?>
                                        <span class="badge bg-<?= $estudiante['febrero'] === 'AA' ? 'success' : ($estudiante['febrero'] === 'CCA' ? 'warning' : 'danger') ?>">
                                            <?= $estudiante['febrero'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Calificación final -->
                            <td class="text-center align-middle" style="padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="calificacion_final" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 30px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['calificacion_final']): ?>
                                        <span class="badge bg-<?= $estudiante['calificacion_final'] >= 7 ? 'success' : ($estudiante['calificacion_final'] >= 4 ? 'warning' : 'danger') ?>" style="font-size: 1em;">
                                            <?= $estudiante['calificacion_final'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Saberes al cierre -->
                            <td style="vertical-align: middle; padding: 10px;">
                                <div class="editable-cell" 
                                     data-campo="saberes_cierre" 
                                     data-id="<?= $estudiante['id'] ?>"
                                     data-estudiante="<?= htmlspecialchars($estudiante['estudiante_apellido'] . ', ' . $estudiante['estudiante_nombre']) ?>"
                                     style="min-height: 40px; cursor: pointer; padding: 8px; border-radius: 4px;">
                                    <?php if ($estudiante['saberes_cierre']): ?>
                                        <?= nl2br(htmlspecialchars($estudiante['saberes_cierre'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
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
    
    <?php elseif ($materiaSeleccionada && $infoMateria && empty($estudiantesPendientes)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> 
        No hay estudiantes con materias pendientes para la materia seleccionada.
    </div>
    
    <?php elseif ($materiaSeleccionada && !$infoMateria): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i> 
        No se pudo encontrar información de la materia seleccionada o no tienes permisos para acceder a ella.
    </div>
    <?php endif; ?>

</div>

<!-- Modal para Edición de Calificaciones -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">
                    <i class="bi bi-pencil-square"></i> Editar Calificación de Intensificación
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST">
                    <input type="hidden" name="accion" value="actualizar_calificacion">
                    <input type="hidden" id="pendiente_id" name="pendiente_id">
                    <input type="hidden" id="campo" name="campo">
                    
                    <div class="alert alert-info" id="estudianteInfo"></div>
                    
                    <div class="mb-3">
                        <label for="valor" class="form-label fw-bold" id="campoLabel">Valor</label>
                        <div id="inputContainer"></div>
                        <div class="form-text" id="ayudaCampo"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
                <button type="submit" form="editForm" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Guardar Calificación
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Funcionalidad de edición de calificaciones
document.addEventListener('DOMContentLoaded', function() {
    const editableCells = document.querySelectorAll('.editable-cell');
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
    
    editableCells.forEach(cell => {
        cell.title = 'Click para editar';
        
        cell.addEventListener('click', function() {
            const campo = this.dataset.campo;
            const id = this.dataset.id;
            const estudiante = this.dataset.estudiante;
            const valorActual = this.textContent.trim();
            
            document.getElementById('pendiente_id').value = id;
            document.getElementById('campo').value = campo;
            
            // Actualizar información del estudiante
            document.getElementById('estudianteInfo').innerHTML = 
                '<i class="bi bi-person"></i> <strong>Estudiante:</strong> ' + estudiante;
            
            // Configurar campos según el tipo
            configurarCampoEdicion(campo, valorActual);
            
            editModal.show();
        });
        
        // Efecto hover
        cell.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#e3f2fd';
            this.style.transform = 'scale(1.02)';
            this.style.transition = 'all 0.2s ease';
        });
        
        cell.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
            this.style.transform = 'scale(1)';
        });
    });
});

function configurarCampoEdicion(campo, valorActual) {
    const campoLabel = document.getElementById('campoLabel');
    const inputContainer = document.getElementById('inputContainer');
    const ayudaCampo = document.getElementById('ayudaCampo');
    
    let labelText = '';
    let inputHtml = '';
    let ayudaText = '';
    
    // Limpiar valores que son "-" o textos de placeholder
    if (valorActual === '-' || valorActual.includes('Click para agregar') || valorActual.includes('Sin especificar')) {
        valorActual = '';
    }
    
    switch(campo) {
        case 'saberes_iniciales':
            labelText = 'Saberes Iniciales Pendientes';
            inputHtml = `<textarea class="form-control" id="valor" name="valor" rows="3" placeholder="Describe los saberes pendientes al inicio del ciclo...">${valorActual}</textarea>`;
            ayudaText = 'Describe los saberes que el estudiante debe aprobar durante la intensificación.';
            break;
            
        case 'marzo':
        case 'julio':
        case 'agosto':
        case 'diciembre':
        case 'febrero':
            labelText = `Período de ${campo.charAt(0).toUpperCase() + campo.slice(1)}`;
            inputHtml = `
                <select class="form-select" id="valor" name="valor">
                    <option value="">Sin calificar</option>
                    <option value="AA" ${valorActual === 'AA' ? 'selected' : ''}>AA - Aprobó y Acreditó</option>
                    <option value="CCA" ${valorActual === 'CCA' ? 'selected' : ''}>CCA - Continúa, Con Avances</option>
                    <option value="CSA" ${valorActual === 'CSA' ? 'selected' : ''}>CSA - Continúa, Sin Avances</option>
                </select>`;
            ayudaText = 'Selecciona el estado del estudiante en este período de intensificación.';
            break;
            
        case 'calificacion_final':
            labelText = 'Calificación Final';
            inputHtml = `
                <select class="form-select" id="valor" name="valor">
                    <option value="">Sin calificación</option>
                    <option value="10" ${valorActual === '10' ? 'selected' : ''}>10</option>
                    <option value="9" ${valorActual === '9' ? 'selected' : ''}>9</option>
                    <option value="8" ${valorActual === '8' ? 'selected' : ''}>8</option>
                    <option value="7" ${valorActual === '7' ? 'selected' : ''}>7</option>
                    <option value="6" ${valorActual === '6' ? 'selected' : ''}>6</option>
                    <option value="5" ${valorActual === '5' ? 'selected' : ''}>5</option>
                    <option value="4" ${valorActual === '4' ? 'selected' : ''}>4</option>
                    <option value="3" ${valorActual === '3' ? 'selected' : ''}>3</option>
                    <option value="2" ${valorActual === '2' ? 'selected' : ''}>2</option>
                    <option value="1" ${valorActual === '1' ? 'selected' : ''}>1</option>
                </select>`;
            ayudaText = 'Calificación final: 4-10 para aprobado y acreditado, 1-3 para no aprobado.';
            break;
            
        case 'saberes_cierre':
            labelText = 'Saberes Pendientes al Cierre';
            inputHtml = `<textarea class="form-control" id="valor" name="valor" rows="3" placeholder="Describe los saberes que quedan pendientes...">${valorActual}</textarea>`;
            ayudaText = 'Solo completar si la materia NO fue aprobada y acreditada durante el ciclo lectivo.';
            break;
    }
    
    campoLabel.textContent = labelText;
    inputContainer.innerHTML = inputHtml;
    ayudaCampo.textContent = ayudaText;
}
</script>

<style>
.editable-cell {
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.editable-cell:hover {
    border-color: #0ea5e9 !important;
    box-shadow: 0 2px 4px rgba(14, 165, 233, 0.2);
}

.table th, .table td {
    vertical-align: middle;
    border: 1px solid #dee2e6;
}

.table-info th {
    font-weight: 600;
    font-size: 0.85em;
}

/* Colores específicos para el formato RITE */
.table-primary td {
    background-color: #0ea5e9 !important;
}

.table thead th {
    border-bottom: 2px solid #0ea5e9;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table {
        font-size: 0.8em;
    }
    
    .editable-cell {
        min-height: 35px !important;
        padding: 3px !important;
    }
}
</style>

<?php require_once 'footer.php'; ?>
