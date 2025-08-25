<?php
/**
 * mis_materias.php - Vista de materias asignadas para profesores - VERSIÓN CORREGIDA
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * CORREGIDO: Estadísticas y detección de períodos
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar que el usuario sea profesor
if ($_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Verificar y crear columnas para múltiples profesores si no existen
try {
    $columns = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
    $hasProfesor2 = false;
    $hasProfesor3 = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'profesor_id_2') $hasProfesor2 = true;
        if ($column['name'] === 'profesor_id_3') $hasProfesor3 = true;
    }
    
    if (!$hasProfesor2) {
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id_2 INTEGER");
    }
    if (!$hasProfesor3) {
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id_3 INTEGER");
    }
} catch (Exception $e) {
    // Error silencioso si las columnas ya existen
}

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

// Función para obtener estudiantes de una materia (regulares + recursando - liberados + SUBGRUPOS)
function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
    try {
        // Verificar si la materia requiere subgrupos
        $materiaInfo = $db->fetchOne(
            "SELECT mp.requiere_subgrupos, c.id as curso_id, c.nombre as curso_nombre, c.anio
             FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );

        if (!$materiaInfo) {
            return [];
        }

        $estudiantes = [];

        // Si la materia requiere subgrupos, obtener solo estudiantes asignados
        if ($materiaInfo['requiere_subgrupos']) {
            $estudiantes = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                        'subgrupo' as tipo_matricula
                 FROM usuarios u 
                 JOIN estudiantes_por_materia ep ON u.id = ep.estudiante_id
                 WHERE ep.materia_curso_id = ? 
                   AND ep.ciclo_lectivo_id = ? 
                   AND ep.activo = 1
                   AND u.tipo = 'estudiante'
                   AND u.activo = 1",
                [$materiaCursoId, $cicloLectivoId]
            );
        } else {
            // Materia normal - lógica original con recursados
            
            // 1. Estudiantes regulares del curso
            $estudiantesRegulares = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                        'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
                [$materiaInfo['curso_id']]
            );

            // 2. Estudiantes recursando esta materia específica
            $estudiantesRecursando = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        'recursando' as tipo_matricula
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'",
                [$materiaCursoId, $cicloLectivoId]
            );

            // 3. Combinar ambos grupos de estudiantes
            $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
        }

        // 4. Filtrar estudiantes que tienen materias liberadas para recursado
        $estudiantesFiltrados = [];
        foreach ($estudiantes as $estudiante) {
            // Verificar si este estudiante tiene liberada esta materia para recursar otra
            $materiaLiberada = $db->fetchOne(
                "SELECT id FROM materias_recursado 
                 WHERE estudiante_id = ? AND materia_liberada_id = ? AND estado = 'activo'",
                [$estudiante['id'], $materiaCursoId]
            );
            
            // Si no tiene liberada esta materia, incluirlo en la lista
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

// CORREGIDO: Función para obtener período actual simplificada
function obtenerPeriodoActual() {
    $fechaActual = new DateTime();
    $anioActual = (int)$fechaActual->format('Y');
    
    // Definir las fechas del ciclo lectivo 2025
    $fechas = [
        'inicio_1c' => new DateTime($anioActual . '-03-10'),        // 10/03/2025
        'valoracion_1c' => new DateTime($anioActual . '-05-16'),    // 16/05/2025
        'cierre_1c' => new DateTime($anioActual . '-07-11'),        // 11/07/2025
        'intensif_1c_inicio' => new DateTime($anioActual . '-07-16'), // 16/07/2025
        'intensif_1c_fin' => new DateTime($anioActual . '-08-08'),   // 08/08/2025
        'inicio_2c' => new DateTime($anioActual . '-08-01'),        // 01/08/2025
        'valoracion_2c' => new DateTime($anioActual . '-09-01'),    // 01/09/2025
        'cierre_2c' => new DateTime($anioActual . '-11-20'),        // 20/11/2025
        'intensif_2c_dic_inicio' => new DateTime($anioActual . '-12-09'), // 09/12/2025
        'intensif_2c_dic_fin' => new DateTime($anioActual . '-12-20'),    // 20/12/2025
        'intensif_2c_feb_inicio' => new DateTime(($anioActual + 1) . '-02-10'), // 10/02/2026
        'intensif_2c_feb_fin' => new DateTime(($anioActual + 1) . '-02-28')     // 28/02/2026
    ];
    
    // LÓGICA SIMPLIFICADA: Determinar período
    if ($fechaActual >= $fechas['inicio_1c'] && $fechaActual <= $fechas['cierre_1c']) {
        return [
            'cuatrimestre' => 1,
            'periodo' => 'primer_cuatrimestre',
            'descripcion' => '1° Cuatrimestre',
            'campo_calificacion' => 'calificacion_1c'
        ];
    } elseif ($fechaActual >= $fechas['intensif_1c_inicio'] && $fechaActual <= $fechas['intensif_1c_fin']) {
        return [
            'cuatrimestre' => 1,
            'periodo' => 'intensificacion_1c',
            'descripcion' => 'Intensificación 1° Cuatrimestre',
            'campo_calificacion' => 'intensificacion_1c'
        ];
    } elseif ($fechaActual >= $fechas['inicio_2c'] && $fechaActual <= $fechas['cierre_2c']) {
        return [
            'cuatrimestre' => 2,
            'periodo' => 'segundo_cuatrimestre',
            'descripcion' => '2° Cuatrimestre',
            'campo_calificacion' => 'calificacion_2c'
        ];
    } elseif ($fechaActual >= $fechas['intensif_2c_dic_inicio'] && $fechaActual <= $fechas['intensif_2c_dic_fin']) {
        return [
            'cuatrimestre' => 2,
            'periodo' => 'intensificacion_2c_dic',
            'descripcion' => 'Intensificación 2° Cuatrimestre - Diciembre',
            'campo_calificacion' => 'calificacion_2c'
        ];
    } elseif ($fechaActual >= $fechas['intensif_2c_feb_inicio'] && $fechaActual <= $fechas['intensif_2c_feb_fin']) {
        return [
            'cuatrimestre' => 2,
            'periodo' => 'intensificacion_2c_feb',
            'descripcion' => 'Intensificación 2° Cuatrimestre - Febrero',
            'campo_calificacion' => 'calificacion_2c'
        ];
    } else {
        // Por defecto, primer cuatrimestre (para testing en cualquier fecha)
        return [
            'cuatrimestre' => 1,
            'periodo' => 'primer_cuatrimestre',
            'descripcion' => '1° Cuatrimestre',
            'campo_calificacion' => 'calificacion_1c'
        ];
    }
}

// COMPLETAMENTE REESCRITA: Función para calcular estadísticas
function calcularEstadisticasMateria($db, $materiaCursoId, $cicloLectivoId, $estudiantes) {
    try {
        $periodoActual = obtenerPeriodoActual();
        $totalEstudiantes = count($estudiantes);
        $estudiantesAprobados = 0;
        $estudiantesDesaprobados = 0;
        $estudiantesSinCalificar = 0;

        if ($totalEstudiantes > 0) {
            $estudiantesIds = array_column($estudiantes, 'id');
            $placeholders = str_repeat('?,', count($estudiantesIds) - 1) . '?';
            
            // Obtener todas las calificaciones
            $calificaciones = $db->fetchAll(
                "SELECT estudiante_id, calificacion_final, calificacion_1c, calificacion_2c, intensificacion_1c
                 FROM calificaciones 
                 WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? 
                 AND estudiante_id IN ($placeholders)",
                array_merge([$materiaCursoId, $cicloLectivoId], $estudiantesIds)
            );

            $calificacionesPorEstudiante = [];
            foreach ($calificaciones as $cal) {
                $calificacionesPorEstudiante[$cal['estudiante_id']] = $cal;
            }

            // PROCESAR CADA ESTUDIANTE
            foreach ($estudiantes as $estudiante) {
                $calData = $calificacionesPorEstudiante[$estudiante['id']] ?? null;
                
                if (!$calData) {
                    $estudiantesSinCalificar++;
                    continue;
                }
                
                // Determinar qué calificación usar según el período
                $calificacionAUsar = null;
                
                switch ($periodoActual['periodo']) {
                    case 'primer_cuatrimestre':
                        $calificacionAUsar = !empty($calData['calificacion_1c']) && is_numeric($calData['calificacion_1c']) 
                            ? (float)$calData['calificacion_1c'] : null;
                        break;
                        
                    case 'intensificacion_1c':
                        if (!empty($calData['intensificacion_1c']) && is_numeric($calData['intensificacion_1c'])) {
                            $calificacionAUsar = (float)$calData['intensificacion_1c'];
                        } elseif (!empty($calData['calificacion_1c']) && is_numeric($calData['calificacion_1c'])) {
                            $calificacionAUsar = (float)$calData['calificacion_1c'];
                        }
                        break;
                        
                    case 'segundo_cuatrimestre':
                    case 'intensificacion_2c_dic':
                    case 'intensificacion_2c_feb':
                        $calificacionAUsar = !empty($calData['calificacion_2c']) && is_numeric($calData['calificacion_2c']) 
                            ? (float)$calData['calificacion_2c'] : null;
                        break;
                }
                
                // Clasificar estudiante
                if ($calificacionAUsar === null) {
                    $estudiantesSinCalificar++;
                } elseif ($calificacionAUsar >= 7) {  // CORREGIDO: Aprueba con 7, no con 4
                    $estudiantesAprobados++;
                } else {
                    $estudiantesDesaprobados++;
                }
            }
        }

        return [
            'total_estudiantes' => $totalEstudiantes,
            'estudiantes_aprobados' => $estudiantesAprobados,
            'estudiantes_desaprobados' => $estudiantesDesaprobados,
            'estudiantes_sin_calificar' => $estudiantesSinCalificar,
            'periodo_actual' => $periodoActual
        ];
        
    } catch (Exception $e) {
        error_log("Error en calcularEstadisticasMateria: " . $e->getMessage());
        return [
            'total_estudiantes' => 0,
            'estudiantes_aprobados' => 0,
            'estudiantes_desaprobados' => 0,
            'estudiantes_sin_calificar' => 0,
            'periodo_actual' => obtenerPeriodoActual()
        ];
    }
}

// Obtener información del profesor
$profesorId = $_SESSION['user_id'];
$profesorInfo = null;
$materiasAsignadas = [];
$estadisticas = [];

try {
    // Obtener información del profesor
    $profesorInfo = $db->fetchOne(
        "SELECT nombre, apellido, dni, telefono FROM usuarios WHERE id = ? AND tipo = 'profesor'",
        [$profesorId]
    );
    
    if ($profesorInfo) {
        // Obtener materias donde este profesor está asignado
        $materiasBase = $db->fetchAll(
            "SELECT mp.id as materia_curso_id, m.nombre as materia_nombre, m.codigo as materia_codigo,
                    c.nombre as curso_nombre, c.anio as curso_anio, c.id as curso_id, mp.requiere_subgrupos,
                    mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                    p1.apellido as profesor_1_apellido, p1.nombre as profesor_1_nombre,
                    p2.apellido as profesor_2_apellido, p2.nombre as profesor_2_nombre,
                    p3.apellido as profesor_3_apellido, p3.nombre as profesor_3_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
             LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
             LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
             WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
               AND c.ciclo_lectivo_id = ?
             ORDER BY c.anio, m.nombre",
            [$profesorId, $profesorId, $profesorId, $cicloLectivoId]
        );

        // Procesar cada materia
        foreach ($materiasBase as $materia) {
            // Obtener información de equipo docente
            $equipoDocente = [];
            $posicionProfesor = 0;
            
            if ($materia['profesor_id']) {
                $equipoDocente[] = [
                    'id' => $materia['profesor_id'],
                    'nombre' => $materia['profesor_1_apellido'] . ', ' . $materia['profesor_1_nombre'],
                    'posicion' => 1,
                    'es_actual' => $materia['profesor_id'] == $profesorId
                ];
                if ($materia['profesor_id'] == $profesorId) $posicionProfesor = 1;
            }
            
            if ($materia['profesor_id_2']) {
                $equipoDocente[] = [
                    'id' => $materia['profesor_id_2'],
                    'nombre' => $materia['profesor_2_apellido'] . ', ' . $materia['profesor_2_nombre'],
                    'posicion' => 2,
                    'es_actual' => $materia['profesor_id_2'] == $profesorId
                ];
                if ($materia['profesor_id_2'] == $profesorId) $posicionProfesor = 2;
            }
            
            if ($materia['profesor_id_3']) {
                $equipoDocente[] = [
                    'id' => $materia['profesor_id_3'],
                    'nombre' => $materia['profesor_3_apellido'] . ', ' . $materia['profesor_3_nombre'],
                    'posicion' => 3,
                    'es_actual' => $materia['profesor_id_3'] == $profesorId
                ];
                if ($materia['profesor_id_3'] == $profesorId) $posicionProfesor = 3;
            }
            
            // Obtener estudiantes
            $estudiantes = obtenerEstudiantesMateria($db, $materia['materia_curso_id'], $cicloLectivoId);
            
            // CALCULAR ESTADÍSTICAS CORREGIDAS
            $estadisticasMateria = calcularEstadisticasMateria($db, $materia['materia_curso_id'], $cicloLectivoId, $estudiantes);

            // Agregar información calculada
            $materia['total_estudiantes'] = $estadisticasMateria['total_estudiantes'];
            $materia['estudiantes_aprobados'] = $estadisticasMateria['estudiantes_aprobados'];
            $materia['estudiantes_desaprobados'] = $estadisticasMateria['estudiantes_desaprobados'];
            $materia['estudiantes_sin_calificar'] = $estadisticasMateria['estudiantes_sin_calificar'];
            $materia['periodo_actual'] = $estadisticasMateria['periodo_actual'];
            $materia['tiene_subgrupos'] = $materia['requiere_subgrupos'] == 1;
            $materia['equipo_docente'] = $equipoDocente;
            $materia['total_profesores'] = count($equipoDocente);
            $materia['posicion_profesor'] = $posicionProfesor;
            $materia['es_equipo'] = count($equipoDocente) > 1;
            
            $materiasAsignadas[] = $materia;
        }
        
        // Calcular estadísticas generales
        $totalMaterias = count($materiasAsignadas);
        $materiasConSubgrupos = count(array_filter($materiasAsignadas, function($m) { return $m['tiene_subgrupos']; }));
        $materiasEnEquipo = count(array_filter($materiasAsignadas, function($m) { return $m['es_equipo']; }));
        $totalEstudiantes = 0;
        $totalAprobados = 0;
        $totalDesaprobados = 0;
        $totalSinCalificar = 0;
        
        foreach ($materiasAsignadas as $materia) {
            $totalEstudiantes += $materia['total_estudiantes'];
            $totalAprobados += $materia['estudiantes_aprobados'];
            $totalDesaprobados += $materia['estudiantes_desaprobados'];
            $totalSinCalificar += $materia['estudiantes_sin_calificar'];
        }
        
        $estadisticas = [
            'total_materias' => $totalMaterias,
            'materias_con_subgrupos' => $materiasConSubgrupos,
            'materias_en_equipo' => $materiasEnEquipo,
            'total_estudiantes' => $totalEstudiantes,
            'total_aprobados' => $totalAprobados,
            'total_desaprobados' => $totalDesaprobados,
            'total_sin_calificar' => $totalSinCalificar,
            'porcentaje_aprobacion' => $totalEstudiantes > 0 ? round(($totalAprobados / $totalEstudiantes) * 100, 2) : 0
        ];
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener materias asignadas: ' . $e->getMessage() . '</div>';
}
?>

<div class="container-fluid mt-4">
    <?php if ($profesorInfo): ?>
    <!-- Información del profesor -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-person-badge"></i> Mis Materias Asignadas - Ciclo Lectivo <?= $anioActivo ?>
                        <?php 
                        $periodoActual = obtenerPeriodoActual();
                        ?>
                        <span class="badge bg-light text-dark ms-2">
                            <i class="bi bi-calendar-event"></i> <?= $periodoActual['descripcion'] ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="bi bi-info-circle"></i> Información Personal</h6>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Profesor/a:</strong></td>
                                    <td><?= htmlspecialchars($profesorInfo['apellido']) ?>, <?= htmlspecialchars($profesorInfo['nombre']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Usuario:</strong></td>
                                    <td><?= htmlspecialchars($profesorInfo['dni']) ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="bi bi-graph-up"></i> Estadísticas Generales - <?= $periodoActual['descripcion'] ?></h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h4 class="text-primary"><?= $estadisticas['total_materias'] ?></h4>
                                            <small>Materias Asignadas</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h4 class="text-info"><?= $estadisticas['total_estudiantes'] ?></h4>
                                            <small>Total Estudiantes</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-4">
                                    <div class="card bg-success text-white">
                                        <div class="card-body text-center">
                                            <h5><?= $estadisticas['total_aprobados'] ?></h5>
                                            <small>Aprobados</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-danger text-white">
                                        <div class="card-body text-center">
                                            <h5><?= $estadisticas['total_desaprobados'] ?></h5>
                                            <small>Desaprobados</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-warning text-white">
                                        <div class="card-body text-center">
                                            <h5><?= $estadisticas['total_sin_calificar'] ?></h5>
                                            <small>Sin Calificar</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de materias -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-journal-bookmark"></i> Detalle de Materias
                        <?php if ($estadisticas['porcentaje_aprobacion'] > 0): ?>
                        <span class="badge bg-light text-dark ms-2">
                            Aprobación General: <?= $estadisticas['porcentaje_aprobacion'] ?>%
                        </span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (count($materiasAsignadas) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Materia</th>
                                    <th>Curso</th>
                                    <th>Equipo Docente</th>
                                    <th class="text-center">Total Estudiantes</th>
                                    <th class="text-center">Aprobados</th>
                                    <th class="text-center">Desaprobados</th>
                                    <th class="text-center">Sin Calificar</th>
                                    <th class="text-center">% Aprobación</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materiasAsignadas as $materia): 
                                    $porcentajeAprobacion = $materia['total_estudiantes'] > 0 ? 
                                        round(($materia['estudiantes_aprobados'] / $materia['total_estudiantes']) * 100, 2) : 0;
                                ?>
                                <tr class="<?= $materia['es_equipo'] ? 'table-info' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($materia['materia_codigo']) ?></small>
                                        <?php if ($materia['tiene_subgrupos']): ?>
                                        <br><span class="badge bg-warning">
                                            <i class="bi bi-people"></i> Subgrupos
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($materia['es_equipo']): ?>
                                        <br><span class="badge bg-info">
                                            <i class="bi bi-people-fill"></i> Equipo Docente
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($materia['curso_nombre']) ?></td>
                                    <td>
                                        <?php if ($materia['es_equipo']): ?>
                                            <div class="mb-1">
                                                <small class="text-info">
                                                    <i class="bi bi-people-fill"></i> 
                                                    Equipo de <?= $materia['total_profesores'] ?> profesores
                                                </small>
                                            </div>
                                            <?php foreach ($materia['equipo_docente'] as $profesor): ?>
                                                <div class="small <?= $profesor['es_actual'] ? 'fw-bold text-primary' : 'text-muted' ?>">
                                                    <span class="badge bg-<?= $profesor['es_actual'] ? 'primary' : 'secondary' ?> badge-sm me-1">
                                                        <?= $profesor['posicion'] ?>
                                                    </span>
                                                    <?= htmlspecialchars($profesor['nombre']) ?>
                                                    <?php if ($profesor['es_actual']): ?>
                                                        <i class="bi bi-arrow-left text-primary"></i>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="small text-success">
                                                <i class="bi bi-person-check"></i>
                                                Solo usted
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info">
                                            <?= $materia['total_estudiantes'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success"><?= $materia['estudiantes_aprobados'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= $materia['estudiantes_desaprobados'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning"><?= $materia['estudiantes_sin_calificar'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $porcentajeAprobacion >= 70 ? 'success' : ($porcentajeAprobacion >= 50 ? 'warning' : 'danger') ?>">
                                            <?= $porcentajeAprobacion ?>%
                                        </span>
                                        <?php if ($materia['estudiantes_desaprobados'] > 0): ?>
                                        <br><small class="text-danger">
                                            <i class="bi bi-exclamation-triangle"></i> 
                                            <?= $materia['estudiantes_desaprobados'] ?> desaprobados
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <a href="calificaciones.php?curso=<?= $materia['curso_id'] ?>&materia=<?= $materia['materia_curso_id'] ?>" 
                                               class="btn btn-sm btn-primary" title="Cargar Calificaciones">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <a href="contenidos.php?materia=<?= $materia['materia_curso_id'] ?>" 
                                               class="btn btn-sm btn-success" title="Gestionar Contenidos">
                                                <i class="bi bi-journal-text"></i>
                                            </a>
                                            <?php if ($materia['tiene_subgrupos']): ?>
                                            <a href="gestionar_subgrupos.php?materia=<?= $materia['materia_curso_id'] ?>" 
                                               class="btn btn-sm btn-info" title="Gestionar Subgrupos">
                                                <i class="bi bi-people"></i>
                                            </a>
                                            <?php endif; ?>
                                            <a href="reportes.php?tipo_reporte=rendimiento_materia&curso=<?= $materia['curso_id'] ?>&materia=<?= $materia['materia_curso_id'] ?>" 
                                               class="btn btn-sm btn-secondary" title="Ver Reporte">
                                                <i class="bi bi-bar-chart"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No tiene materias asignadas para el ciclo lectivo actual.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Estadísticas detalladas por curso -->
    <?php if (count($materiasAsignadas) > 0): ?>
    <div class="row mb-4 mt-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-graph-up"></i> Estadísticas por Curso
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php 
                        // Agrupar materias por curso
                        $materiasPorCurso = [];
                        foreach ($materiasAsignadas as $materia) {
                            $curso = $materia['curso_nombre'];
                            if (!isset($materiasPorCurso[$curso])) {
                                $materiasPorCurso[$curso] = [
                                    'curso_id' => $materia['curso_id'],
                                    'curso_nombre' => $curso,
                                    'curso_anio' => $materia['curso_anio'],
                                    'total_materias' => 0,
                                    'materias_con_subgrupos' => 0,
                                    'materias_en_equipo' => 0,
                                    'total_estudiantes' => 0,
                                    'total_aprobados' => 0,
                                    'total_desaprobados' => 0,
                                    'total_sin_calificar' => 0
                                ];
                            }
                            
                            $materiasPorCurso[$curso]['total_materias']++;
                            if ($materia['tiene_subgrupos']) {
                                $materiasPorCurso[$curso]['materias_con_subgrupos']++;
                            }
                            
                            $materiasPorCurso[$curso]['total_estudiantes'] += $materia['total_estudiantes'];
                            $materiasPorCurso[$curso]['total_aprobados'] += $materia['estudiantes_aprobados'];
                            $materiasPorCurso[$curso]['total_desaprobados'] += $materia['estudiantes_desaprobados'];
                            $materiasPorCurso[$curso]['total_sin_calificar'] += $materia['estudiantes_sin_calificar'];
                        }
                        
                        foreach ($materiasPorCurso as $cursoDatos): 
                            $porcentajeCurso = $cursoDatos['total_estudiantes'] > 0 ? 
                                round(($cursoDatos['total_aprobados'] / $cursoDatos['total_estudiantes']) * 100, 2) : 0;
                        ?>
                        <div class="col-md-6 mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="bi bi-building"></i> <?= htmlspecialchars($cursoDatos['curso_nombre']) ?>
                                        <span class="badge bg-secondary ms-2"><?= $cursoDatos['total_materias'] ?> materia(s)</span>
                                        <?php if ($cursoDatos['materias_con_subgrupos'] > 0): ?>
                                        <span class="badge bg-warning ms-1"><?= $cursoDatos['materias_con_subgrupos'] ?> con subgrupos</span>
                                        <?php endif; ?>
                                        <?php if ($cursoDatos['materias_en_equipo'] > 0): ?>
                                        <span class="badge bg-info ms-1"><?= $cursoDatos['materias_en_equipo'] ?> en equipo</span>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <h5 class="text-primary"><?= $cursoDatos['total_estudiantes'] ?></h5>
                                            <small>Estudiantes Total</small>
                                        </div>
                                        <div class="col-6">
                                            <h5 class="text-<?= $porcentajeCurso >= 70 ? 'success' : ($porcentajeCurso >= 50 ? 'warning' : 'danger') ?>">
                                                <?= $porcentajeCurso ?>%
                                            </h5>
                                            <small>Aprobación</small>
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <div class="progress">
                                            <div class="progress-bar bg-success" style="width: <?= $cursoDatos['total_estudiantes'] > 0 ? ($cursoDatos['total_aprobados'] / $cursoDatos['total_estudiantes']) * 100 : 0 ?>%"></div>
                                            <div class="progress-bar bg-danger" style="width: <?= $cursoDatos['total_estudiantes'] > 0 ? ($cursoDatos['total_desaprobados'] / $cursoDatos['total_estudiantes']) * 100 : 0 ?>%"></div>
                                            <div class="progress-bar bg-warning" style="width: <?= $cursoDatos['total_estudiantes'] > 0 ? ($cursoDatos['total_sin_calificar'] / $cursoDatos['total_estudiantes']) * 100 : 0 ?>%"></div>
                                        </div>
                                        <small class="text-muted">
                                            <span class="text-success"><?= $cursoDatos['total_aprobados'] ?> aprobados</span> |
                                            <span class="text-danger"><?= $cursoDatos['total_desaprobados'] ?> desaprobados</span> |
                                            <span class="text-warning"><?= $cursoDatos['total_sin_calificar'] ?> sin calificar</span>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Información adicional sobre equipos docentes y sistema -->
    <?php 
    // Verificar si hay estudiantes recursando o materias con subgrupos
    $hayRecursando = false;
    $haySubgrupos = $estadisticas['materias_con_subgrupos'] > 0;
    $hayEquipos = $estadisticas['materias_en_equipo'] > 0;
    
    foreach ($materiasAsignadas as $materia) {
        if (!$materia['tiene_subgrupos']) {
            $estudiantesCompletos = obtenerEstudiantesMateria($db, $materia['materia_curso_id'], $cicloLectivoId);
            $recursando = array_filter($estudiantesCompletos, function($e) { return $e['tipo_matricula'] === 'recursando'; });
            if (count($recursando) > 0) {
                $hayRecursando = true;
                break;
            }
        }
    }
    
    if ($hayRecursando || $haySubgrupos || $hayEquipos): ?>
    
    <?php endif; ?>

    <!-- Estadísticas rápidas -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Materias
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_materias'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-journal-bookmark text-primary" style="font-size: 2rem;"></i>
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
                                Estudiantes
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_estudiantes'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-person-check text-success" style="font-size: 2rem;"></i>
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
                                Aprobación General
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['porcentaje_aprobacion'] ?>%</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-trophy text-warning" style="font-size: 2rem;"></i>
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
                                Período Actual
                            </div>
                            <div class="h6 mb-0 font-weight-bold text-gray-800"><?= $periodoActual['cuatrimestre'] ?>°C</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-event text-info" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i>
        Error: No se encontró información del profesor. Contacte con la administración.
    </div>
    <?php endif; ?>
</div>

<style>
.border-left-primary {
    border-left: 0.25rem solid #007bff !important;
}

.border-left-info {
    border-left: 0.25rem solid #17a2b8 !important;
}

.border-left-warning {
    border-left: 0.25rem solid #ffc107 !important;
}

.border-left-success {
    border-left: 0.25rem solid #28a745 !important;
}

.table-info {
    background-color: rgba(13, 110, 253, 0.1);
}

.badge-sm {
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
}
</style>

<?php require_once 'footer.php'; ?>
