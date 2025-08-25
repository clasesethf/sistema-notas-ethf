<?php
/**
 * reporte_contenidos_alumno.php - Interfaz actualizada con gráficos y estadísticas detalladas
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Con gráficos, porcentajes y vista detallada igual al PDF
 * CORREGIDO: Manejo correcto de "No Corresponde" y "Sin Calificar"
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

/**
 * Función para convertir texto a mayúsculas manteniendo las tildes
 */
function convertirMayusculasConTildes($texto) {
    // Mapeo manual de caracteres acentuados a mayúsculas
    $mapeo = array(
        'á' => 'Á', 'à' => 'À', 'ä' => 'Ä', 'â' => 'Â',
        'é' => 'É', 'è' => 'È', 'ë' => 'Ë', 'ê' => 'Ê',
        'í' => 'Í', 'ì' => 'Ì', 'ï' => 'Ï', 'î' => 'Î',
        'ó' => 'Ó', 'ò' => 'Ò', 'ö' => 'Ö', 'ô' => 'Ô',
        'ú' => 'Ú', 'ù' => 'Ù', 'ü' => 'Ü', 'û' => 'Û',
        'ñ' => 'Ñ',
        'ç' => 'Ç'
    );
    
    // Primero convertir a mayúsculas normal
    $textoMayuscula = strtoupper($texto);
    
    // Luego aplicar el mapeo manual para caracteres acentuados
    foreach ($mapeo as $minuscula => $mayuscula) {
        $textoMayuscula = str_replace($minuscula, $mayuscula, $textoMayuscula);
    }
    
    return $textoMayuscula;
}

// Verificar permisos (admin, directivo y profesores)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
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

// Obtener cursos según el tipo de usuario
$cursos = [];
$profesorId = $_SESSION['user_type'] == 'profesor' ? $_SESSION['user_id'] : null;

try {
    if ($_SESSION['user_type'] == 'profesor') {
        // Solo cursos donde el profesor enseña
        $cursos = $db->fetchAll(
            "SELECT DISTINCT c.* 
             FROM cursos c
             JOIN materias_por_curso mp ON c.id = mp.curso_id
             WHERE mp.profesor_id = ? AND c.ciclo_lectivo_id = ?
             ORDER BY c.anio, c.nombre",
            [$profesorId, $cicloLectivoId]
        );
    } else {
        // Todos los cursos - CORREGIDO: quitado el alias 'c' de ORDER BY
        $cursos = $db->fetchAll(
            "SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio, nombre",
            [$cicloLectivoId]
        );
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener cursos: ' . $e->getMessage() . '</div>';
}

// Procesar selección
$tipoReporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : 'alumno_materia';
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;
$estudianteSeleccionado = isset($_GET['estudiante']) ? intval($_GET['estudiante']) : null;

// Variables para almacenar datos
$materias = [];
$estudiantes = [];
$contenidosCalificaciones = [];
$datosReporte = [];

// Si se seleccionó un curso
if ($cursoSeleccionado) {
    try {
        // Obtener materias del curso
        if ($_SESSION['user_type'] == 'profesor') {
            $materias = $db->fetchAll(
                "SELECT mp.id, m.nombre, m.codigo 
                 FROM materias_por_curso mp
                 JOIN materias m ON mp.materia_id = m.id
                 WHERE mp.curso_id = ? AND mp.profesor_id = ?
                 ORDER BY m.nombre",
                [$cursoSeleccionado, $profesorId]
            );
        } else {
            $materias = $db->fetchAll(
                "SELECT mp.id, m.nombre, m.codigo, (u.apellido || ', ' || u.nombre) as profesor
                 FROM materias_por_curso mp
                 JOIN materias m ON mp.materia_id = m.id
                 LEFT JOIN usuarios u ON mp.profesor_id = u.id
                 WHERE mp.curso_id = ?
                 ORDER BY m.nombre",
                [$cursoSeleccionado]
            );
        }
        
        // Obtener estudiantes del curso
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni
             FROM usuarios u
             JOIN matriculas mat ON u.id = mat.estudiante_id
             WHERE mat.curso_id = ? AND mat.estado = 'activo'
             ORDER BY u.apellido, u.nombre",
            [$cursoSeleccionado]
        );
        
        // Procesar según el tipo de reporte
        if ($tipoReporte == 'alumno_materia' && $materiaSeleccionada && $estudianteSeleccionado) {
            $contenidosCalificaciones = $db->fetchAll(
                "SELECT c.*, 
                        cc.calificacion_cualitativa,
                        cc.observaciones as observaciones_calificacion
                 FROM contenidos c
                 LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                                       AND cc.estudiante_id = ?
                 WHERE c.materia_curso_id = ? 
                   AND c.activo = 1
                   AND cc.calificacion_cualitativa IS NOT NULL 
                   AND cc.calificacion_cualitativa != ''
                   AND LTRIM(RTRIM(cc.calificacion_cualitativa)) != ''
                   AND LTRIM(RTRIM(cc.calificacion_cualitativa)) != 'Sin Calificar'
                 ORDER BY c.fecha_clase, c.bimestre",
                [$estudianteSeleccionado, $materiaSeleccionada]
            );
        } elseif ($tipoReporte == 'alumno_completo' && $estudianteSeleccionado) {
            // Reporte completo del alumno (todas sus materias)
            $datosReporte = obtenerReporteCompletoAlumno($db, $estudianteSeleccionado, $cursoSeleccionado, $cicloLectivoId, $profesorId);
        } elseif ($tipoReporte == 'materia_completa' && $materiaSeleccionada) {
            // Reporte completo de la materia (todos los alumnos)
            $datosReporte = obtenerReporteCompletoMateria($db, $materiaSeleccionada, $cursoSeleccionado, $cicloLectivoId);
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

// Función para obtener reporte completo del alumno - ACTUALIZADA
function obtenerReporteCompletoAlumno($db, $estudianteId, $cursoId, $cicloLectivoId, $profesorId = null) {
    $filtroProfesor = $profesorId ? "AND mp.profesor_id = $profesorId" : "";
    
    $materias = $db->fetchAll(
        "SELECT mp.id as materia_curso_id, m.nombre as materia_nombre, m.codigo as materia_codigo,
                (u.apellido || ', ' || u.nombre) as profesor_nombre
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id
         WHERE mp.curso_id = ? $filtroProfesor
         ORDER BY m.nombre",
        [$cursoId]
    );
    
    $resultado = [];
    foreach ($materias as $materia) {
        $contenidos = $db->fetchAll(
            "SELECT c.*, 
                    cc.calificacion_cualitativa,
                    cc.observaciones as observaciones_calificacion
             FROM contenidos c
             LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                                   AND cc.estudiante_id = ?
             WHERE c.materia_curso_id = ? 
               AND c.activo = 1
               AND cc.calificacion_cualitativa IS NOT NULL 
               AND cc.calificacion_cualitativa != ''
               AND LTRIM(RTRIM(cc.calificacion_cualitativa)) != ''
               AND LTRIM(RTRIM(cc.calificacion_cualitativa)) != 'Sin Calificar'
             ORDER BY c.fecha_clase, c.bimestre",
            [$estudianteId, $materia['materia_curso_id']]
        );
        
        $materia['contenidos'] = $contenidos;
        $resultado[] = $materia;
    }
    
    return $resultado;
}

// Función para obtener reporte completo de la materia - ACTUALIZADA
function obtenerReporteCompletoMateria($db, $materiaCursoId, $cursoId, $cicloLectivoId) {
    // Obtener estudiantes del curso
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni
         FROM usuarios u
         JOIN matriculas mat ON u.id = mat.estudiante_id
         WHERE mat.curso_id = ? AND mat.estado = 'activo'
         ORDER BY u.apellido, u.nombre",
        [$cursoId]
    );
    
    $resultado = [];
    foreach ($estudiantes as $estudiante) {
        $contenidos = $db->fetchAll(
            "SELECT c.*, 
                    cc.calificacion_cualitativa,
                    cc.observaciones as observaciones_calificacion
             FROM contenidos c
             LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                                   AND cc.estudiante_id = ?
             WHERE c.materia_curso_id = ? 
               AND c.activo = 1
               AND cc.calificacion_cualitativa IS NOT NULL 
               AND cc.calificacion_cualitativa != ''
               AND LTRIM(RTRIM(cc.calificacion_cualitativa)) != ''
               AND LTRIM(RTRIM(cc.calificacion_cualitativa)) != 'Sin Calificar'
             ORDER BY c.fecha_clase, c.bimestre",
            [$estudiante['id'], $materiaCursoId]
        );
        
        $estudiante['contenidos'] = $contenidos;
        $resultado[] = $estudiante;
    }
    
    return $resultado;
}

// NUEVA función para obtener estadísticas sin filtrar (para mostrar información completa)
function obtenerEstadisticasCompletas($db, $estudianteId, $materiaCursoId) {
    return $db->fetchOne(
        "SELECT 
            COUNT(*) as total_contenidos,
            COUNT(cc.calificacion_cualitativa) as contenidos_calificados,
            COUNT(*) - COUNT(cc.calificacion_cualitativa) as contenidos_sin_calificar,
            SUM(CASE WHEN cc.calificacion_cualitativa = 'Acreditado' THEN 1 ELSE 0 END) as acreditados,
            SUM(CASE WHEN cc.calificacion_cualitativa = 'No Acreditado' THEN 1 ELSE 0 END) as no_acreditados,
            SUM(CASE WHEN cc.calificacion_cualitativa = 'No Corresponde' THEN 1 ELSE 0 END) as no_corresponde
         FROM contenidos c
         LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                               AND cc.estudiante_id = ?
         WHERE c.materia_curso_id = ? AND c.activo = 1",
        [$estudianteId, $materiaCursoId]
    );
}

// Función para calcular estadísticas mejoradas - VERSIÓN CORREGIDA
function calcularEstadisticasMejoradas($contenidos) {
    $stats = [
        'total' => 0,
        'calificados' => 0,
        'acreditados' => 0,
        'no_acreditados' => 0,
        'no_corresponde' => 0,
        'sin_calificar' => 0
    ];
    
    foreach ($contenidos as $contenido) {
        // Solo contar contenidos que tienen alguna calificación válida
        if ($contenido['calificacion_cualitativa'] && 
            !empty(trim($contenido['calificacion_cualitativa']))) {
            
            $calificacion = trim($contenido['calificacion_cualitativa']);
            
            // Incrementar total solo para contenidos calificados (excluir "Sin Calificar")
            $stats['total']++;
            $stats['calificados']++;
            
            if ($calificacion == 'Acreditado') {
                $stats['acreditados']++;
            } elseif ($calificacion == 'No Acreditado') {
                $stats['no_acreditados']++;
            } elseif ($calificacion == 'No Corresponde') {
                $stats['no_corresponde']++;
                // "No Corresponde" no cuenta ni como acreditado ni no acreditado
            }
        } else {
            // Los sin calificar se cuentan aparte pero no aparecen en los reportes
            $stats['sin_calificar']++;
        }
    }
    
    // Calcular porcentajes basados SOLO en Acreditado vs No Acreditado
    $baseCalculo = $stats['acreditados'] + $stats['no_acreditados']; // Excluir "No Corresponde"
    
    $stats['porcentaje_calificados'] = ($stats['total'] + $stats['sin_calificar']) > 0 ? 
        round(($stats['total'] / ($stats['total'] + $stats['sin_calificar'])) * 100, 1) : 0;
    
    // Porcentajes de acreditación basados SOLO en Acreditado vs No Acreditado
    $stats['porcentaje_acreditados'] = $baseCalculo > 0 ? 
        round(($stats['acreditados'] / $baseCalculo) * 100, 1) : 0;
    $stats['porcentaje_no_acreditados'] = $baseCalculo > 0 ? 
        round(($stats['no_acreditados'] / $baseCalculo) * 100, 1) : 0;
    $stats['porcentaje_no_corresponde'] = $stats['total'] > 0 ? 
        round(($stats['no_corresponde'] / $stats['total']) * 100, 1) : 0;
    
    return $stats;
}
?>

<!-- Agregar Chart.js para gráficos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-file-earmark-text"></i> Reportes de Contenidos y Calificaciones
            </h1>
            
            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="bi bi-funnel"></i> Configurar Reporte
                    </h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <!-- Tipo de Reporte -->
                        <div class="col-md-12 mb-3">
                            <label for="tipo_reporte" class="form-label"><strong>Tipo de Reporte</strong></label>
                            <select name="tipo_reporte" id="tipo_reporte" class="form-select" onchange="this.form.submit()">
                                <option value="alumno_materia" <?= $tipoReporte == 'alumno_materia' ? 'selected' : '' ?>>
                                    📋 Por Alumno y Materia Específica
                                </option>
                                <option value="alumno_completo" <?= $tipoReporte == 'alumno_completo' ? 'selected' : '' ?>>
                                    👤 Por Alumno (Todas sus Materias)
                                </option>
                                <option value="materia_completa" <?= $tipoReporte == 'materia_completa' ? 'selected' : '' ?>>
                                    📚 Por Materia (Todos los Alumnos)
                                </option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="curso" class="form-label">Curso</label>
                            <select name="curso" id="curso" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>" <?= $cursoSeleccionado == $curso['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($curso['nombre']) ?> 
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($tipoReporte == 'alumno_materia' || $tipoReporte == 'materia_completa'): ?>
                        <div class="col-md-4">
                            <label for="materia" class="form-label">Materia</label>
                            <select name="materia" id="materia" class="form-select" onchange="this.form.submit()" 
                                    <?= !$cursoSeleccionado ? 'disabled' : '' ?>>
                                <option value="">-- Seleccione una materia --</option>
                                <?php foreach ($materias as $materia): ?>
                                    <option value="<?= $materia['id'] ?>" <?= $materiaSeleccionada == $materia['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($materia['nombre']) ?> (<?= htmlspecialchars($materia['codigo']) ?>)
                                        <?php if (isset($materia['profesor'])): ?>
                                            - <?= htmlspecialchars($materia['profesor']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tipoReporte == 'alumno_materia' || $tipoReporte == 'alumno_completo'): ?>
                        <div class="col-md-4">
                            <label for="estudiante" class="form-label">Estudiante</label>
                            <select name="estudiante" id="estudiante" class="form-select" onchange="this.form.submit()"
                                    <?= !$cursoSeleccionado ? 'disabled' : '' ?>>
                                <option value="">-- Seleccione un estudiante --</option>
                                <?php foreach ($estudiantes as $estudiante): ?>
                                    <option value="<?= $estudiante['id'] ?>" <?= $estudianteSeleccionado == $estudiante['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?> 
                                        (DNI: <?= htmlspecialchars($estudiante['dni']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Botones de descarga -->
                        <div class="col-md-12 mt-3">
                            <?php 
                            $puedeDescargar = false;
                            $urlDescarga = '';
                            
                            if ($tipoReporte == 'alumno_materia' && $estudianteSeleccionado && $materiaSeleccionada && count($contenidosCalificaciones) > 0) {
                                $puedeDescargar = true;
                                $urlDescarga = "generar_reporte_contenidos_pdf.php?estudiante=$estudianteSeleccionado&materia=$materiaSeleccionada&curso=$cursoSeleccionado&tipo=alumno_materia";
                            } elseif ($tipoReporte == 'alumno_completo' && $estudianteSeleccionado && count($datosReporte) > 0) {
                                $puedeDescargar = true;
                                $urlDescarga = "generar_reporte_contenidos_pdf.php?estudiante=$estudianteSeleccionado&curso=$cursoSeleccionado&tipo=alumno_completo";
                            } elseif ($tipoReporte == 'materia_completa' && $materiaSeleccionada && count($datosReporte) > 0) {
                                $puedeDescargar = true;
                                $urlDescarga = "generar_reporte_contenidos_pdf.php?materia=$materiaSeleccionada&curso=$cursoSeleccionado&tipo=materia_completa";
                            }
                            ?>
                            
                            <?php if ($puedeDescargar): ?>
                            <a href="<?= $urlDescarga ?>" target="_blank" class="btn btn-danger">
                                <i class="bi bi-file-pdf"></i> Descargar PDF
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Resultados -->
            <?php if ($tipoReporte == 'alumno_materia' && $estudianteSeleccionado && count($contenidosCalificaciones) > 0): ?>
                <!-- VISTA: Alumno + Materia específica -->
                <?php
                // Obtener información del estudiante y materia
                $infoEstudiante = $db->fetchOne(
                    "SELECT * FROM usuarios WHERE id = ?",
                    [$estudianteSeleccionado]
                );
                
                $infoMateria = $db->fetchOne(
                    "SELECT m.*, c.nombre as curso_nombre, c.anio as curso_anio,
                            (u.apellido || ', ' || u.nombre) as profesor_nombre
                     FROM materias m
                     JOIN materias_por_curso mp ON m.id = mp.materia_id
                     JOIN cursos c ON mp.curso_id = c.id
                     LEFT JOIN usuarios u ON mp.profesor_id = u.id
                     WHERE mp.id = ?",
                    [$materiaSeleccionada]
                );
                
                $stats = calcularEstadisticasMejoradas($contenidosCalificaciones);
                ?>
                
                <!-- Información General -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="m-0">
                            <i class="bi bi-person-fill"></i> 
                            REPORTE INDIVIDUAL DE CONTENIDOS
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">INFORMACIÓN DEL ESTUDIANTE</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Apellido y Nombre:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoEstudiante['apellido'] . ', ' . $infoEstudiante['nombre'])) ?></td></tr>
                                    <tr><td><strong>Matrícula:</strong></td><td><?= htmlspecialchars($infoEstudiante['dni']) ?></td></tr>
                                    <tr><td><strong>Materia:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['nombre'])) ?></td></tr>
                                    <tr><td><strong>Profesor/a:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['profesor_nombre'])) ?></td></tr>
                                    <tr><td><strong>Curso:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['curso_nombre'])) ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <!-- Gráfico circular de progreso -->
                                <div class="text-center">
                                    <h6 class="text-primary">ESTADÍSTICAS DE PROGRESO</h6>
                                    <canvas id="graficoProgreso" width="300" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas Detalladas - VERSIÓN CORREGIDA -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <i class="bi bi-list-ul text-primary" style="font-size: 2rem;"></i>
                                <h4 class="text-primary"><?= $stats['total'] ?></h4>
                                <p class="mb-0">Contenidos Evaluables</p>
                                <small class="text-muted">
                                    <?php if ($stats['sin_calificar'] > 0): ?>
                                        (<?= $stats['sin_calificar'] ?> sin evaluar)
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <i class="bi bi-award text-success" style="font-size: 2rem;"></i>
                                <h4 class="text-success"><?= $stats['acreditados'] ?></h4>
                                <p class="mb-0">Acreditados</p>
                                <small class="text-muted">(<?= $stats['porcentaje_acreditados'] ?>%)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <i class="bi bi-x-circle text-danger" style="font-size: 2rem;"></i>
                                <h4 class="text-danger"><?= $stats['no_acreditados'] ?></h4>
                                <p class="mb-0">No Acreditados</p>
                                <small class="text-muted">(<?= $stats['porcentaje_no_acreditados'] ?>%)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-secondary">
                            <div class="card-body text-center">
                                <i class="bi bi-dash-circle text-secondary" style="font-size: 2rem;"></i>
                                <h4 class="text-secondary"><?= $stats['no_corresponde'] ?></h4>
                                <p class="mb-0">No Corresponde</p>
                                <small class="text-muted">(No evaluable)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resumen Ejecutivo Actualizado -->
                <div class="alert alert-info mb-4">
                    <h6><i class="bi bi-info-circle"></i> RESUMEN</h6>
                    <strong>Total Evaluables:</strong> <?= $stats['total'] ?>
                    <?php if ($stats['sin_calificar'] > 0): ?>
                        <span class="text-muted">(<?= $stats['sin_calificar'] ?> sin evaluar no se muestran)</span>
                    <?php endif; ?>
                    
                    <?php if (($stats['acreditados'] + $stats['no_acreditados']) > 0): ?>
                        <br>
                        <strong>Distribución de Evaluados:</strong>
                        Acreditados: <?= $stats['acreditados'] ?> (<?= $stats['porcentaje_acreditados'] ?>%) |
                        No Acreditados: <?= $stats['no_acreditados'] ?> (<?= $stats['porcentaje_no_acreditados'] ?>%)
                        
                        <?php if ($stats['no_corresponde'] > 0): ?>
                            <br><strong>No Corresponde:</strong> <?= $stats['no_corresponde'] ?> contenidos (no evaluables)
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Tabla de progreso con barras actualizadas -->
                <div class="bg-light p-3 mb-3">
                    <h5 class="text-primary mb-2"><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['nombre'])) ?></h5>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="progress mb-2" style="height: 25px;">
                                <?php 
                                // Calcular el total evaluable
                                $totalEvaluable = $stats['acreditados'] + $stats['no_acreditados'];
                                ?>
                                <?php if ($stats['total'] > 0): ?>
                                    <div class="progress-bar bg-success" 
                                         style="width: <?= ($stats['acreditados'] / $stats['total']) * 100 ?>%" 
                                         title="Acreditados: <?= $stats['acreditados'] ?>">
                                        <?php if ($stats['acreditados'] > 0): ?>
                                            <?= $stats['acreditados'] ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="progress-bar bg-danger" 
                                         style="width: <?= ($stats['no_acreditados'] / $stats['total']) * 100 ?>%" 
                                         title="No Acreditados: <?= $stats['no_acreditados'] ?>">
                                        <?php if ($stats['no_acreditados'] > 0): ?>
                                            <?= $stats['no_acreditados'] ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($stats['no_corresponde'] > 0): ?>
                                    <div class="progress-bar bg-secondary" 
                                         style="width: <?= ($stats['no_corresponde'] / $stats['total']) * 100 ?>%" 
                                         title="No Corresponde: <?= $stats['no_corresponde'] ?>">
                                        <?= $stats['no_corresponde'] ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <small>
                                <strong>Evaluables:</strong> <?= $stats['total'] ?>
                                <?php if ($stats['sin_calificar'] > 0): ?>
                                    <br><strong>Sin Evaluar:</strong> <?= $stats['sin_calificar'] ?> (no se muestran)
                                <?php endif; ?>
                                
                                <?php if ($totalEvaluable > 0): ?>
                                    <br><strong>Acreditación:</strong> <?= $stats['porcentaje_acreditados'] ?>% exitosa
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Contenidos y Calificaciones -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="m-0"><i class="bi bi-table"></i> CONTENIDOS Y CALIFICACIONES</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-primary">
                                    <tr>
                                        <th style="width: 70%;">Contenido</th>
                                        <th style="width: 30%;">Calificación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contenidosCalificaciones as $contenido): ?>
                                    <tr>
                                        <td>
                                            <strong><?= convertirMayusculasConTildes(htmlspecialchars($contenido['titulo'])) ?></strong>
                                            <?php if ($contenido['descripcion']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($contenido['descripcion']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($contenido['observaciones_calificacion']): ?>
                                                <br><small class="text-info"><strong>Observaciones:</strong> <?= htmlspecialchars($contenido['observaciones_calificacion']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($contenido['calificacion_cualitativa']): ?>
                                                <?php if ($contenido['calificacion_cualitativa'] == 'Acreditado'): ?>
                                                    <span class="badge bg-success fs-6 p-2">ACREDITADO</span>
                                                <?php elseif ($contenido['calificacion_cualitativa'] == 'No Acreditado'): ?>
                                                    <span class="badge bg-danger fs-6 p-2">NO ACREDITADO</span>
                                                <?php elseif ($contenido['calificacion_cualitativa'] == 'No Corresponde'): ?>
                                                    <span class="badge bg-secondary fs-6 p-2">NO CORRESPONDE</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary fs-6 p-2">SIN EVALUAR</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <script>
                // Gráfico circular de progreso (individual) - CORREGIDO
                const ctx = document.getElementById('graficoProgreso').getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Acreditados', 'No Acreditados', 'No Corresponde'],
                        datasets: [{
                            data: [
                                <?= $stats['acreditados'] ?>, 
                                <?= $stats['no_acreditados'] ?>, 
                                <?= $stats['no_corresponde'] ?>
                            ],
                            backgroundColor: [
                                '#28a745',  // Verde para Acreditados
                                '#dc3545',  // Rojo para No Acreditados  
                                '#6c757d'   // Gris para No Corresponde
                            ],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12
                                    },
                                    filter: function(legendItem, data) {
                                        // Solo mostrar en la leyenda si tiene datos
                                        return data.datasets[0].data[legendItem.datasetIndex] > 0;
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = <?= $stats['total'] ?>;
                                        const value = context.parsed;
                                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                        return context.label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
                </script>
                
            <?php elseif ($tipoReporte == 'alumno_completo' && $estudianteSeleccionado && count($datosReporte) > 0): ?>
			
			<!-- VISTA: Alumno completo (todas las materias) -->
                <?php
                $infoEstudiante = $db->fetchOne(
                    "SELECT u.*, c.nombre as curso_nombre, c.anio as curso_anio
                     FROM usuarios u
                     JOIN matriculas m ON u.id = m.estudiante_id
                     JOIN cursos c ON m.curso_id = c.id
                     WHERE u.id = ? AND m.curso_id = ?",
                    [$estudianteSeleccionado, $cursoSeleccionado]
                );

                // Calcular estadísticas generales
                $statsGenerales = ['total' => 0, 'calificados' => 0, 'acreditados' => 0, 'no_acreditados' => 0, 'no_corresponde' => 0, 'sin_calificar' => 0];
                $datosMaterias = [];
                
                foreach ($datosReporte as $materia) {
                    $statMateria = calcularEstadisticasMejoradas($materia['contenidos']);
                    $datosMaterias[] = [
                        'nombre' => $materia['materia_nombre'],
                        'stats' => $statMateria
                    ];
                    
                    $statsGenerales['total'] += $statMateria['total'];
                    $statsGenerales['calificados'] += $statMateria['calificados'];
                    $statsGenerales['acreditados'] += $statMateria['acreditados'];
                    $statsGenerales['no_acreditados'] += $statMateria['no_acreditados'];
                    $statsGenerales['no_corresponde'] += $statMateria['no_corresponde'];
                    $statsGenerales['sin_calificar'] += $statMateria['sin_calificar'];
                }
                
                $baseCalculoGeneral = $statsGenerales['acreditados'] + $statsGenerales['no_acreditados'];
                $statsGenerales['porcentaje_calificados'] = ($statsGenerales['total'] + $statsGenerales['sin_calificar']) > 0 ? 
                    round(($statsGenerales['total'] / ($statsGenerales['total'] + $statsGenerales['sin_calificar'])) * 100, 1) : 0;
                $statsGenerales['porcentaje_acreditados'] = $baseCalculoGeneral > 0 ? 
                    round(($statsGenerales['acreditados'] / $baseCalculoGeneral) * 100, 1) : 0;
                $statsGenerales['porcentaje_no_acreditados'] = $baseCalculoGeneral > 0 ? 
                    round(($statsGenerales['no_acreditados'] / $baseCalculoGeneral) * 100, 1) : 0;
                ?>
                
                <!-- Información General -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="m-0">
                            <i class="bi bi-person-check-fill"></i> 
                            REPORTE COMPLETO DEL ESTUDIANTE
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-success">INFORMACIÓN DEL ESTUDIANTE</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Apellido y Nombre:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoEstudiante['apellido'] . ', ' . $infoEstudiante['nombre'])) ?></td></tr>
                                    <tr><td><strong>Matrícula:</strong></td><td><?= htmlspecialchars($infoEstudiante['dni']) ?></td></tr>
                                    <tr><td><strong>Curso:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoEstudiante['curso_nombre'])) ?></td></tr>
                                    <tr><td><strong>Total de Materias:</strong></td><td><?= count($datosReporte) ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <!-- Gráfico general -->
                                <div class="text-center">
                                    <h6 class="text-success">ESTADÍSTICAS GENERALES</h6>
                                    <canvas id="graficoGeneral" width="300" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas Generales - CORREGIDAS -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <i class="bi bi-list-ul text-primary" style="font-size: 2rem;"></i>
                                <h4 class="text-primary"><?= $statsGenerales['total'] ?></h4>
                                <p class="mb-0">Contenidos Evaluables</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <i class="bi bi-award text-success" style="font-size: 2rem;"></i>
                                <h4 class="text-success"><?= $statsGenerales['acreditados'] ?></h4>
                                <p class="mb-0">Acreditados (<?= $statsGenerales['porcentaje_acreditados'] ?>%)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <i class="bi bi-x-circle text-danger" style="font-size: 2rem;"></i>
                                <h4 class="text-danger"><?= $statsGenerales['no_acreditados'] ?></h4>
                                <p class="mb-0">No Acreditados (<?= $statsGenerales['porcentaje_no_acreditados'] ?>%)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-secondary">
                            <div class="card-body text-center">
                                <i class="bi bi-dash-circle text-secondary" style="font-size: 2rem;"></i>
                                <h4 class="text-secondary"><?= $statsGenerales['no_corresponde'] ?></h4>
                                <p class="mb-0">No Corresponde</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resumen General -->
                <div class="alert alert-info mb-4">
                    <h6><i class="bi bi-info-circle"></i> RESUMEN GENERAL</h6>
                    <strong>Total Evaluables:</strong> <?= $statsGenerales['total'] ?>
                    <?php if ($statsGenerales['sin_calificar'] > 0): ?>
                        <span class="text-muted">(<?= $statsGenerales['sin_calificar'] ?> sin evaluar no se muestran)</span>
                    <?php endif; ?>
                    <?php if ($baseCalculoGeneral > 0): ?>
                        <br><strong>Distribución:</strong> 
                        Acreditados: <?= $statsGenerales['acreditados'] ?> (<?= $statsGenerales['porcentaje_acreditados'] ?>%) | 
                        No Acreditados: <?= $statsGenerales['no_acreditados'] ?> (<?= $statsGenerales['porcentaje_no_acreditados'] ?>%)
                        <?php if ($statsGenerales['no_corresponde'] > 0): ?>
                            | No Corresponde: <?= $statsGenerales['no_corresponde'] ?> (no evaluables)
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Gráfico de barras por materia -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="m-0"><i class="bi bi-bar-chart"></i> Progreso por Materia</h6>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 600px;">
                            <canvas id="graficoMaterias"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tabla detallada por materia -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="m-0"><i class="bi bi-table"></i> DETALLE POR MATERIA</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($datosReporte as $indice => $materia): ?>
                            <?php if (!empty($materia['contenidos'])): ?>
                                <?php $stats = calcularEstadisticasMejoradas($materia['contenidos']); ?>
                                
                                <!-- Título de materia -->
                                <div class="bg-light p-3 mb-3">
                                    <h5 class="text-primary mb-2"><?= convertirMayusculasConTildes(htmlspecialchars($materia['materia_nombre'])) ?></h5>
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="progress mb-2">
                                                <?php if ($stats['total'] > 0): ?>
                                                <div class="progress-bar bg-success" style="width: <?= ($stats['acreditados'] / $stats['total']) * 100 ?>%"></div>
                                                <div class="progress-bar bg-danger" style="width: <?= ($stats['no_acreditados'] / $stats['total']) * 100 ?>%"></div>
                                                <div class="progress-bar bg-secondary" style="width: <?= ($stats['no_corresponde'] / $stats['total']) * 100 ?>%"></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <small><strong>Evaluables:</strong> <?= $stats['total'] ?>
                                            <?php if (($stats['acreditados'] + $stats['no_acreditados']) > 0): ?>
                                                | Acreditados: <?= $stats['acreditados'] ?> (<?= $stats['porcentaje_acreditados'] ?>%)
                                            <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tabla de contenidos de esta materia -->
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-primary">
                                            <tr>
                                                <th>Contenido</th>
                                                <th width="150">Calificación</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($materia['contenidos'] as $contenido): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= convertirMayusculasConTildes(htmlspecialchars($contenido['titulo'])) ?></strong>
                                                    <?php if ($contenido['descripcion']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($contenido['descripcion']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($contenido['calificacion_cualitativa']): ?>
                                                        <?php if ($contenido['calificacion_cualitativa'] == 'Acreditado'): ?>
                                                            <span class="badge bg-success">ACREDITADO</span>
                                                        <?php elseif ($contenido['calificacion_cualitativa'] == 'No Acreditado'): ?>
                                                            <span class="badge bg-danger">NO ACREDITADO</span>
                                                        <?php elseif ($contenido['calificacion_cualitativa'] == 'No Corresponde'): ?>
                                                            <span class="badge bg-secondary">NO CORRESPONDE</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">SIN EVALUAR</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($indice < count($datosReporte) - 1): ?>
                                    <hr class="my-4">
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <script>
                // Gráfico general del estudiante - CORREGIDO
                const ctxGeneral = document.getElementById('graficoGeneral').getContext('2d');
                new Chart(ctxGeneral, {
                    type: 'doughnut',
                    data: {
                        labels: ['Acreditados', 'No Acreditados', 'No Corresponde'],
                        datasets: [{
                            data: [<?= $statsGenerales['acreditados'] ?>, <?= $statsGenerales['no_acreditados'] ?>, <?= $statsGenerales['no_corresponde'] ?>],
                            backgroundColor: ['#28a745', '#dc3545', '#6c757d'],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: { size: 12 }
                                }
                            }
                        }
                    }
                });

                // Gráfico de barras por materia - CORREGIDO
                const ctxMaterias = document.getElementById('graficoMaterias').getContext('2d');
                new Chart(ctxMaterias, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach ($datosMaterias as $dm): ?>
                                '<?= addslashes($dm['nombre']) ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [
                            {
                                label: 'Acreditados',
                                data: [
                                    <?php foreach ($datosMaterias as $dm): ?>
                                        <?= $dm['stats']['acreditados'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: '#28a745'
                            },
                            {
                                label: 'No Acreditados',
                                data: [
                                    <?php foreach ($datosMaterias as $dm): ?>
                                        <?= $dm['stats']['no_acreditados'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: '#dc3545'
                            },
                            {
                                label: 'No Corresponde',
                                data: [
                                    <?php foreach ($datosMaterias as $dm): ?>
                                        <?= $dm['stats']['no_corresponde'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: '#6c757d'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true,
                                ticks: {
                                    font: { size: 12 },
                                    maxRotation: 45,
                                    minRotation: 0
                                }
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: { font: { size: 12 } }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    font: { size: 14 },
                                    padding: 20
                                }
                            },
                            tooltip: {
                                titleFont: { size: 14 },
                                bodyFont: { size: 12 }
                            }
                        },
                        layout: {
                            padding: { top: 20, bottom: 20, left: 10, right: 10 }
                        }
                    }
                });
                </script>
                
            <?php elseif ($tipoReporte == 'materia_completa' && $materiaSeleccionada && count($datosReporte) > 0): ?>
                <!-- VISTA: Materia completa (todos los alumnos) -->
                <?php
                $infoMateria = $db->fetchOne(
                    "SELECT m.*, c.nombre as curso_nombre, c.anio as curso_anio,
                            (u.apellido || ', ' || u.nombre) as profesor_nombre
                     FROM materias m
                     JOIN materias_por_curso mp ON m.id = mp.materia_id
                     JOIN cursos c ON mp.curso_id = c.id
                     LEFT JOIN usuarios u ON mp.profesor_id = u.id
                     WHERE mp.id = ?",
                    [$materiaSeleccionada]
                );

                // Calcular estadísticas generales y por estudiante
                $statsGenerales = ['total' => 0, 'calificados' => 0, 'acreditados' => 0, 'no_acreditados' => 0, 'no_corresponde' => 0, 'sin_calificar' => 0];
                $datosEstudiantes = [];
                
                foreach ($datosReporte as $estudiante) {
                    $statEstudiante = calcularEstadisticasMejoradas($estudiante['contenidos']);
                    $datosEstudiantes[] = [
                        'nombre' => $estudiante['apellido'] . ', ' . $estudiante['nombre'],
                        'dni' => $estudiante['dni'],
                        'stats' => $statEstudiante
                    ];
                    
                    $statsGenerales['total'] += $statEstudiante['total'];
                    $statsGenerales['calificados'] += $statEstudiante['calificados'];
                    $statsGenerales['acreditados'] += $statEstudiante['acreditados'];
                    $statsGenerales['no_acreditados'] += $statEstudiante['no_acreditados'];
                    $statsGenerales['no_corresponde'] += $statEstudiante['no_corresponde'];
                    $statsGenerales['sin_calificar'] += $statEstudiante['sin_calificar'];
                }
                
                $baseCalculoGeneral = $statsGenerales['acreditados'] + $statsGenerales['no_acreditados'];
                $statsGenerales['porcentaje_calificados'] = ($statsGenerales['total'] + $statsGenerales['sin_calificar']) > 0 ? 
                    round(($statsGenerales['total'] / ($statsGenerales['total'] + $statsGenerales['sin_calificar'])) * 100, 1) : 0;
                $statsGenerales['porcentaje_acreditados'] = $baseCalculoGeneral > 0 ? 
                    round(($statsGenerales['acreditados'] / $baseCalculoGeneral) * 100, 1) : 0;
                $statsGenerales['porcentaje_no_acreditados'] = $baseCalculoGeneral > 0 ? 
                    round(($statsGenerales['no_acreditados'] / $baseCalculoGeneral) * 100, 1) : 0;
                ?>
                
                <!-- Información General -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="m-0">
                            <i class="bi bi-book-fill"></i> 
                            REPORTE COMPLETO DE MATERIA
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-info">INFORMACIÓN DE LA MATERIA</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>Materia:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['nombre'] . ' (' . $infoMateria['codigo'] . ')')) ?></td></tr>
                                    <tr><td><strong>Profesor:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['profesor_nombre'])) ?></td></tr>
                                    <tr><td><strong>Curso:</strong></td><td><?= convertirMayusculasConTildes(htmlspecialchars($infoMateria['curso_nombre'] . ' (' . $infoMateria['curso_anio'] . '° año)')) ?></td></tr>
                                    <tr><td><strong>Total de Estudiantes:</strong></td><td><?= count($datosReporte) ?></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <!-- Gráfico general de la materia -->
                                <div class="text-center">
                                    <h6 class="text-info">ESTADÍSTICAS GENERALES</h6>
                                    <canvas id="graficoMateriaGeneral" width="300" height="300"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Estadísticas Generales - CORREGIDAS -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <i class="bi bi-list-ul text-primary" style="font-size: 2rem;"></i>
                                <h4 class="text-primary"><?= $statsGenerales['total'] ?></h4>
                                <p class="mb-0">Contenidos Evaluables</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-success">
                            <div class="card-body text-center">
                                <i class="bi bi-award text-success" style="font-size: 2rem;"></i>
                                <h4 class="text-success"><?= $statsGenerales['acreditados'] ?></h4>
                                <p class="mb-0">Acreditados (<?= $statsGenerales['porcentaje_acreditados'] ?>%)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <i class="bi bi-x-circle text-danger" style="font-size: 2rem;"></i>
                                <h4 class="text-danger"><?= $statsGenerales['no_acreditados'] ?></h4>
                                <p class="mb-0">No Acreditados (<?= $statsGenerales['porcentaje_no_acreditados'] ?>%)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-secondary">
                            <div class="card-body text-center">
                                <i class="bi bi-dash-circle text-secondary" style="font-size: 2rem;"></i>
                                <h4 class="text-secondary"><?= $statsGenerales['no_corresponde'] ?></h4>
                                <p class="mb-0">No Corresponde</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resumen General -->
                <div class="alert alert-info mb-4">
                    <h6><i class="bi bi-info-circle"></i> RESUMEN GENERAL</h6>
                    <strong>Total Evaluables:</strong> <?= $statsGenerales['total'] ?>
                    <?php if ($statsGenerales['sin_calificar'] > 0): ?>
                        <span class="text-muted">(<?= $statsGenerales['sin_calificar'] ?> sin evaluar no se muestran)</span>
                    <?php endif; ?>
                    <?php if ($baseCalculoGeneral > 0): ?>
                        <br><strong>Distribución:</strong> 
                        Acreditados: <?= $statsGenerales['acreditados'] ?> (<?= $statsGenerales['porcentaje_acreditados'] ?>%) | 
                        No Acreditados: <?= $statsGenerales['no_acreditados'] ?> (<?= $statsGenerales['porcentaje_no_acreditados'] ?>%)
                        <?php if ($statsGenerales['no_corresponde'] > 0): ?>
                            | No Corresponde: <?= $statsGenerales['no_corresponde'] ?> (no evaluables)
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Gráfico de barras por estudiante -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="m-0"><i class="bi bi-bar-chart"></i> Progreso por Estudiante</h6>
                    </div>
                    <div class="card-body">
                        <div style="position: relative; height: 600px;">
                            <canvas id="graficoEstudiantes"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tabla resumen por estudiante -->
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="m-0"><i class="bi bi-table"></i> RESUMEN POR ESTUDIANTE</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-primary">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>DNI</th>
                                        <th class="text-center">Evaluables</th>
                                        <th class="text-center">Acreditados</th>
                                        <th class="text-center">No Acreditados</th>
                                        <th class="text-center">No Corresponde</th>
                                        <th class="text-center">% Acreditación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($datosEstudiantes as $de): ?>
                                    <tr>
                                        <td><strong><?= convertirMayusculasConTildes(htmlspecialchars($de['nombre'])) ?></strong></td>
                                        <td><?= htmlspecialchars($de['dni']) ?></td>
                                        <td class="text-center"><?= $de['stats']['total'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= $de['stats']['acreditados'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= $de['stats']['no_acreditados'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?= $de['stats']['no_corresponde'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $de['stats']['porcentaje_acreditados'] >= 80 ? 'success' : ($de['stats']['porcentaje_acreditados'] >= 50 ? 'warning' : 'danger') ?>">
                                                <?= $de['stats']['porcentaje_acreditados'] ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Detalle por estudiante -->
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h6 class="m-0"><i class="bi bi-list-check"></i> DETALLE POR ESTUDIANTE</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($datosReporte as $indice => $estudiante): ?>
                            <?php $stats = calcularEstadisticasMejoradas($estudiante['contenidos']); ?>
                            
                            <!-- Título del estudiante -->
                            <div class="bg-light p-3 mb-3">
                                <h5 class="text-info mb-2"><?= convertirMayusculasConTildes(htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre'])) ?> (DNI: <?= htmlspecialchars($estudiante['dni']) ?>)</h5>
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="progress mb-2">
                                            <?php if ($stats['total'] > 0): ?>
                                            <div class="progress-bar bg-success" style="width: <?= ($stats['acreditados'] / $stats['total']) * 100 ?>%"></div>
                                            <div class="progress-bar bg-danger" style="width: <?= ($stats['no_acreditados'] / $stats['total']) * 100 ?>%"></div>
                                            <div class="progress-bar bg-secondary" style="width: <?= ($stats['no_corresponde'] / $stats['total']) * 100 ?>%"></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <small><strong>Evaluables:</strong> <?= $stats['total'] ?>
                                        <?php if (($stats['acreditados'] + $stats['no_acreditados']) > 0): ?>
                                            | Acreditados: <?= $stats['acreditados'] ?> (<?= $stats['porcentaje_acreditados'] ?>%)
                                        <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- Tabla de contenidos de este estudiante -->
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-hover">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>Contenido</th>
                                            <th width="150">Calificación</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($estudiante['contenidos'] as $contenido): ?>
                                        <tr>
                                            <td>
                                                <strong><?= convertirMayusculasConTildes(htmlspecialchars($contenido['titulo'])) ?></strong>
                                                <?php if ($contenido['descripcion']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($contenido['descripcion']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($contenido['calificacion_cualitativa']): ?>
                                                    <?php if ($contenido['calificacion_cualitativa'] == 'Acreditado'): ?>
                                                        <span class="badge bg-success">ACREDITADO</span>
                                                    <?php elseif ($contenido['calificacion_cualitativa'] == 'No Acreditado'): ?>
                                                        <span class="badge bg-danger">NO ACREDITADO</span>
                                                    <?php elseif ($contenido['calificacion_cualitativa'] == 'No Corresponde'): ?>
                                                        <span class="badge bg-secondary">NO CORRESPONDE</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">SIN EVALUAR</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($indice < count($datosReporte) - 1): ?>
                                <hr class="my-4">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <script>
                // Gráfico general de la materia - CORREGIDO
                const ctxMateriaGeneral = document.getElementById('graficoMateriaGeneral').getContext('2d');
                new Chart(ctxMateriaGeneral, {
                    type: 'doughnut',
                    data: {
                        labels: ['Acreditados', 'No Acreditados', 'No Corresponde'],
                        datasets: [{
                            data: [<?= $statsGenerales['acreditados'] ?>, <?= $statsGenerales['no_acreditados'] ?>, <?= $statsGenerales['no_corresponde'] ?>],
                            backgroundColor: ['#28a745', '#dc3545', '#6c757d'],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: { size: 12 }
                                }
                            }
                        }
                    }
                });

                // Gráfico de barras por estudiante - CORREGIDO
                const ctxEstudiantes = document.getElementById('graficoEstudiantes').getContext('2d');
                new Chart(ctxEstudiantes, {
                    type: 'bar',
                    data: {
                        labels: [
                            <?php foreach ($datosEstudiantes as $de): ?>
                                '<?= addslashes($de['nombre']) ?>',
                            <?php endforeach; ?>
                        ],
                        datasets: [
                            {
                                label: 'Acreditados',
                                data: [
                                    <?php foreach ($datosEstudiantes as $de): ?>
                                        <?= $de['stats']['acreditados'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: '#28a745'
                            },
                            {
                                label: 'No Acreditados',
                                data: [
                                    <?php foreach ($datosEstudiantes as $de): ?>
                                        <?= $de['stats']['no_acreditados'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: '#dc3545'
                            },
                            {
                                label: 'No Corresponde',
                                data: [
                                    <?php foreach ($datosEstudiantes as $de): ?>
                                        <?= $de['stats']['no_corresponde'] ?>,
                                    <?php endforeach; ?>
                                ],
                                backgroundColor: '#6c757d'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true,
                                ticks: {
                                    font: { size: 12 },
                                    maxRotation: 45,
                                    minRotation: 0
                                }
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: { font: { size: 12 } }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    font: { size: 14 },
                                    padding: 20
                                }
                            },
                            tooltip: {
                                titleFont: { size: 14 },
                                bodyFont: { size: 12 }
                            }
                        },
                        layout: {
                            padding: { top: 20, bottom: 20, left: 10, right: 10 }
                        }
                    }
                });
                </script>
                
            <?php elseif ($cursoSeleccionado && (
                ($tipoReporte == 'alumno_materia' && $estudianteSeleccionado && $materiaSeleccionada) ||
                ($tipoReporte == 'alumno_completo' && $estudianteSeleccionado) ||
                ($tipoReporte == 'materia_completa' && $materiaSeleccionada)
            )): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Sin datos:</strong> No se encontraron contenidos para la selección realizada.
                    <br><small>Verifique que existan contenidos registrados para esta combinación de curso, materia y/o estudiante.</small>
                </div>
            <?php elseif (!$cursoSeleccionado): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Instrucciones:</strong> 
                    <ol class="mb-0 mt-2">
                        <li>Seleccione el <strong>tipo de reporte</strong> que desea generar</li>
                        <li>Elija un <strong>curso</strong> de la lista</li>
                        <li>Complete los campos adicionales según el tipo de reporte seleccionado</li>
                        <li>Los datos se mostrarán automáticamente y podrá descargar el PDF</li>
                    </ol>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Estilos adicionales para mejorar la visualización - ACTUALIZADOS -->
<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
    margin-bottom: 1rem;
}

.card-header {
    font-weight: 600;
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.table th {
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
    font-weight: 600;
}

.table td {
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
    padding: 0.375rem 0.75rem;
}

.progress {
    height: 1.5rem;
    font-size: 0.75rem;
}

.alert {
    border: 1px solid transparent;
    border-radius: 0.375rem;
}

.bg-light {
    background-color: #f8f9fa !important;
    border-radius: 0.375rem;
}

/* Estilos para los iconos de estadísticas */
.card i[style*="font-size: 2rem"] {
    margin-bottom: 0.5rem;
    display: block;
}

/* Mejorar la legibilidad de las tablas */
.table-responsive {
    border-radius: 0.375rem;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.025);
}

/* Estilos para los gráficos */
canvas {
    max-height: 600px !important;
}

/* Para gráficos circulares, mantener tamaño moderado */
#graficoProgreso, #graficoGeneral, #graficoMateriaGeneral {
    max-height: 350px !important;
}

/* Colores personalizados actualizados */
.border-primary {
    border-color: #007bff !important;
}

.border-success {
    border-color: #28a745 !important;
}

.border-danger {
    border-color: #dc3545 !important;
}

.border-info {
    border-color: #17a2b8 !important;
}

.border-secondary {
    border-color: #6c757d !important;
}

.bg-primary {
    background-color: #007bff !important;
}

.bg-success {
    background-color: #28a745 !important;
}

.bg-danger {
    background-color: #dc3545 !important;
}

.bg-info {
    background-color: #17a2b8 !important;
}

.bg-secondary {
    background-color: #6c757d !important;
}

.bg-dark {
    background-color: #343a40 !important;
}

.text-secondary {
    color: #6c757d !important;
}

/* Colores de las barras de progreso actualizados */
.progress-bar.bg-success {
    background-color: #28a745 !important;
}

.progress-bar.bg-danger {
    background-color: #dc3545 !important;
}

.progress-bar.bg-secondary {
    background-color: #6c757d !important;
}

/* Responsividad mejorada */
@media (max-width: 768px) {
    .col-md-3, .col-md-4, .col-md-6 {
        margin-bottom: 1rem;
    }
    
    .card-body > div[style*="height: 600px"] {
        height: 400px !important;
    }
    
    canvas {
        max-height: 400px !important;
    }
    
    #graficoProgreso, #graficoGeneral, #graficoMateriaGeneral {
        max-height: 250px !important;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    h1.h3 {
        font-size: 1.5rem;
    }
    
    .card-body {
        padding: 1rem;
    }
}

/* Mejoras en la tipografía */
h5, h6 {
    font-weight: 600;
}

.text-muted {
    color: #6c757d !important;
}

/* Espaciado mejorado */
.mb-4 {
    margin-bottom: 1.5rem !important;
}

.mt-4 {
    margin-top: 1.5rem !important;
}

/* Animaciones sutiles */
.card:hover {
    transform: translateY(-2px);
    transition: transform 0.2s ease-in-out;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.btn:hover {
    transform: translateY(-1px);
    transition: transform 0.2s ease-in-out;
}

/* Mejoras en los badges */
.badge.fs-6 {
    font-size: 0.875rem !important;
    padding: 0.5rem 1rem;
    font-weight: 500;
}

/* Estilos para las listas ordenadas en alertas */
.alert ol {
    padding-left: 1.2rem;
}

.alert ol li {
    margin-bottom: 0.25rem;
}
</style>

<?php require_once 'footer.php'; ?>


