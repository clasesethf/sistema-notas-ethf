<?php
/**
 * reportes.php - Sistema de Reportes y Estadísticas MEJORADO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORAS IMPLEMENTADAS:
 * - Sistema de estadísticas avanzado inspirado en mis_materias.php
 * - Gráficos interactivos de múltiples tipos (barras, líneas, pie, radar)
 * - Análisis detallado por períodos con detección automática
 * - Reportes de equipos docentes y subgrupos
 * - Estadísticas de materias con más desaprobados
 * - Análisis de tendencias y comparativas
 * - Dashboard interactivo con filtros avanzados
 * - NUEVO: Reportes detallados por alumno
 * - NUEVO: Ranking de estudiantes por promedio
 * - NUEVO: Análisis de estudiantes destacados y en riesgo
 */

// 1. Incluir config.php (maneja conexión BD, podría iniciar sesión)
require_once 'config.php';

// 2. Verificar permisos
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// 3. Procesar exportación
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    $queryParamsForExport = $_GET;
    unset($queryParamsForExport['export']);

    if ($exportType == 'pdf') {
        header('Location: generar_reporte_pdf.php?' . http_build_query($queryParamsForExport));
        exit;
    } elseif ($exportType == 'excel') {
        header('Location: generar_reporte_excel.php?' . http_build_query($queryParamsForExport));
        exit;
    }
}

require_once 'header.php';

// Variables para mensajes
$page_error_message = '';
$page_info_message = '';

// Obtener conexión a la base de datos
$db = Database::getInstance();
$cicloLectivoId = 0;
$anioActivo = date('Y');

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if ($cicloActivo) {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
    } else {
        $page_error_message = 'No hay un ciclo lectivo activo configurado en el sistema.';
    }
} catch (Exception $e) {
    $page_error_message = 'Error al conectar con la base de datos: ' . htmlspecialchars($e->getMessage());
}

// Función para obtener período actual (copiada de mis_materias.php)
function obtenerPeriodoActual() {
    $fechaActual = new DateTime();
    $anioActual = (int)$fechaActual->format('Y');
    
    $fechas = [
        'inicio_1c' => new DateTime($anioActual . '-03-10'),
        'valoracion_1c' => new DateTime($anioActual . '-05-16'),
        'cierre_1c' => new DateTime($anioActual . '-07-11'),
        'intensif_1c_inicio' => new DateTime($anioActual . '-07-16'),
        'intensif_1c_fin' => new DateTime($anioActual . '-08-08'),
        'inicio_2c' => new DateTime($anioActual . '-08-01'),
        'valoracion_2c' => new DateTime($anioActual . '-09-01'),
        'cierre_2c' => new DateTime($anioActual . '-11-20'),
        'intensif_2c_dic_inicio' => new DateTime($anioActual . '-12-09'),
        'intensif_2c_dic_fin' => new DateTime($anioActual . '-12-20'),
        'intensif_2c_feb_inicio' => new DateTime(($anioActual + 1) . '-02-10'),
        'intensif_2c_feb_fin' => new DateTime(($anioActual + 1) . '-02-28')
    ];
    
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
        return [
            'cuatrimestre' => 1,
            'periodo' => 'primer_cuatrimestre',
            'descripcion' => '1° Cuatrimestre',
            'campo_calificacion' => 'calificacion_1c'
        ];
    }
}

// Función para obtener estudiantes de una materia (incluye subgrupos y recursados)
function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
    try {
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
            $estudiantesRegulares = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                        'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
                [$materiaInfo['curso_id']]
            );

            $estudiantesRecursando = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        'recursando' as tipo_matricula
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'",
                [$materiaCursoId, $cicloLectivoId]
            );

            $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
        }

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

// Obtener cursos para los selectores
$cursos = [];
if ($cicloLectivoId > 0) {
    try {
        if ($_SESSION['user_type'] == 'profesor') {
            $cursos = $db->fetchAll(
                "SELECT DISTINCT c.* 
                 FROM cursos c 
                 JOIN materias_por_curso mp ON c.id = mp.curso_id 
                 WHERE c.ciclo_lectivo_id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                 ORDER BY c.anio, c.nombre", 
                [$cicloLectivoId, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
            );
        } else {
            $cursos = $db->fetchAll(
                "SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio, nombre", 
                [$cicloLectivoId]
            );
        }
    } catch (Exception $e) {
        $page_error_message .= (empty($page_error_message) ? '' : '<br>') . 'Error al obtener los cursos: ' . htmlspecialchars($e->getMessage());
    }
}

// Obtener estudiantes para el selector
$estudiantes = [];
if ($cicloLectivoId > 0) {
    try {
        // Obtener todos los estudiantes si es admin/directivo, o solo del curso seleccionado
        if ($_SESSION['user_type'] != 'profesor' && empty($_GET['curso'])) {
            $estudiantes = $db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni, c.nombre as curso_nombre
                 FROM usuarios u
                 JOIN matriculas m ON u.id = m.estudiante_id
                 JOIN cursos c ON m.curso_id = c.id
                 WHERE u.tipo = 'estudiante' AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?
                 ORDER BY c.nombre, u.apellido, u.nombre",
                [$cicloLectivoId]
            );
        } elseif (isset($_GET['curso']) && $_GET['curso'] > 0) {
            $cursoSeleccionado = intval($_GET['curso']);
            $estudiantes = $db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni
                 FROM usuarios u
                 JOIN matriculas m ON u.id = m.estudiante_id
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 ORDER BY u.apellido, u.nombre",
                [$cursoSeleccionado]
            );
        }
    } catch (Exception $e) {
        $page_error_message .= (empty($page_error_message) ? '' : '<br>') . 'Error al obtener los estudiantes: ' . htmlspecialchars($e->getMessage());
    }
}

// Procesar selección de reporte y filtros
$tipoReporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : '';
$cursoId = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$materiaId = isset($_GET['materia']) ? intval($_GET['materia']) : 0;
$estudianteId = isset($_GET['estudiante']) ? intval($_GET['estudiante']) : 0;
$cuatrimestre = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 0;

// Variables para almacenar datos del reporte
$reporteData = [];
$materias = [];
$titulo = '';
$descripcion = '';

// Si se ha seleccionado un curso, obtener sus materias
if ($cursoId > 0 && $cicloLectivoId > 0) {
    try {
        if ($_SESSION['user_type'] == 'profesor') {
            $materias = $db->fetchAll(
                "SELECT m.id as materia_id, m.nombre, m.codigo, mp.id as materia_curso_id, mp.requiere_subgrupos,
                        mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                        p1.apellido as profesor_1_apellido, p1.nombre as profesor_1_nombre,
                        p2.apellido as profesor_2_apellido, p2.nombre as profesor_2_nombre,
                        p3.apellido as profesor_3_apellido, p3.nombre as profesor_3_nombre
                 FROM materias_por_curso mp 
                 JOIN materias m ON mp.materia_id = m.id 
                 LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
                 LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
                 LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
                 WHERE mp.curso_id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)
                 ORDER BY m.nombre",
                [$cursoId, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
            );
        } else {
            $materias = $db->fetchAll(
                "SELECT m.id as materia_id, m.nombre, m.codigo, mp.id as materia_curso_id, mp.requiere_subgrupos,
                        mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                        p1.apellido as profesor_1_apellido, p1.nombre as profesor_1_nombre,
                        p2.apellido as profesor_2_apellido, p2.nombre as profesor_2_nombre,
                        p3.apellido as profesor_3_apellido, p3.nombre as profesor_3_nombre
                 FROM materias_por_curso mp 
                 JOIN materias m ON mp.materia_id = m.id 
                 LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
                 LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
                 LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
                 WHERE mp.curso_id = ? 
                 ORDER BY m.nombre",
                [$cursoId]
            );
        }
    } catch (Exception $e) {
         $page_error_message .= (empty($page_error_message) ? '' : '<br>') . 'Error al obtener las materias: ' . htmlspecialchars($e->getMessage());
    }
}

/**
 * FUNCIÓN MEJORADA: Reporte de rendimiento por curso con estadísticas avanzadas
 */
function generarReporteRendimientoCursoMejorado($db, $cursoId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        $titulo = "Rendimiento por Curso - Análisis Avanzado";
        $descripcion = "Seleccione un curso para ver el análisis detallado de rendimiento.";
        $reporteData = [];
        return;
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre, anio FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        $titulo = "Error";
        $descripcion = "Curso no encontrado.";
        $reporteData = [];
        return;
    }

    $periodoActual = obtenerPeriodoActual();
    $campoCalificacion = $periodoActual['campo_calificacion'];
    $filtroCuatrimestreDesc = $periodoActual['descripcion'];
    
    if ($cuatrimestre == 1) {
        $campoCalificacion = 'c.calificacion_1c';
        $filtroCuatrimestreDesc = "1° Cuatrimestre";
    } elseif ($cuatrimestre == 2) {
        $campoCalificacion = 'c.calificacion_2c';
        $filtroCuatrimestreDesc = "2° Cuatrimestre";
    } elseif ($cuatrimestre == 0) {
        $campoCalificacion = 'c.calificacion_final';
        $filtroCuatrimestreDesc = "Calificación Final";
    }
    
    // Consulta mejorada con más estadísticas
    $reporteData = $db->fetchAll(
        "SELECT m.nombre as materia, m.codigo, mp.requiere_subgrupos,
                mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                p1.apellido as profesor_1_apellido, p1.nombre as profesor_1_nombre,
                p2.apellido as profesor_2_apellido, p2.nombre as profesor_2_nombre,
                p3.apellido as profesor_3_apellido, p3.nombre as profesor_3_nombre,
                COUNT(c.id) as total_estudiantes,
                SUM(CASE WHEN $campoCalificacion >= 7 THEN 1 ELSE 0 END) as aprobados,
                SUM(CASE WHEN $campoCalificacion < 7 AND $campoCalificacion IS NOT NULL THEN 1 ELSE 0 END) as desaprobados,
                SUM(CASE WHEN $campoCalificacion IS NULL OR $campoCalificacion = '' THEN 1 ELSE 0 END) as sin_calificar,
                ROUND(AVG(CASE WHEN $campoCalificacion IS NOT NULL AND $campoCalificacion <> '' AND $campoCalificacion > 0 THEN $campoCalificacion ELSE NULL END), 2) as promedio,
                MAX(CASE WHEN $campoCalificacion IS NOT NULL AND $campoCalificacion <> '' THEN $campoCalificacion ELSE 0 END) as nota_maxima,
                MIN(CASE WHEN $campoCalificacion IS NOT NULL AND $campoCalificacion <> '' AND $campoCalificacion > 0 THEN $campoCalificacion ELSE 10 END) as nota_minima,
                mp.id as materia_curso_id
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id AND p1.tipo = 'profesor'
         LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id AND p2.tipo = 'profesor'
         LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id AND p3.tipo = 'profesor'
         LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id AND c.ciclo_lectivo_id = ?
         WHERE mp.curso_id = ?
         GROUP BY m.id, m.nombre, m.codigo, mp.id
         ORDER BY m.nombre",
        [$cicloLectivoId, $cursoId]
    );
    
    // Agregar información de equipos docentes y subgrupos
    foreach ($reporteData as &$row) {
        $equipoDocente = [];
        $totalProfesores = 0;
        
        if ($row['profesor_id']) {
            $equipoDocente[] = $row['profesor_1_apellido'] . ', ' . $row['profesor_1_nombre'];
            $totalProfesores++;
        }
        if ($row['profesor_id_2']) {
            $equipoDocente[] = $row['profesor_2_apellido'] . ', ' . $row['profesor_2_nombre'];
            $totalProfesores++;
        }
        if ($row['profesor_id_3']) {
            $equipoDocente[] = $row['profesor_3_apellido'] . ', ' . $row['profesor_3_nombre'];
            $totalProfesores++;
        }
        
        $row['equipo_docente'] = implode(' | ', $equipoDocente);
        $row['total_profesores'] = $totalProfesores;
        $row['es_equipo'] = $totalProfesores > 1;
        $row['tiene_subgrupos'] = $row['requiere_subgrupos'] == 1;
        
        // Calcular porcentajes y estadísticas adicionales
        $row['porcentaje_aprobacion'] = $row['total_estudiantes'] > 0 ? 
            round(($row['aprobados'] / $row['total_estudiantes']) * 100, 2) : 0;
        $row['porcentaje_desaprobacion'] = $row['total_estudiantes'] > 0 ? 
            round(($row['desaprobados'] / $row['total_estudiantes']) * 100, 2) : 0;
        $row['porcentaje_sin_calificar'] = $row['total_estudiantes'] > 0 ? 
            round(($row['sin_calificar'] / $row['total_estudiantes']) * 100, 2) : 0;
        
        // Nivel de riesgo
        if ($row['porcentaje_aprobacion'] >= 80) {
            $row['nivel_riesgo'] = 'Bajo';
            $row['clase_riesgo'] = 'success';
        } elseif ($row['porcentaje_aprobacion'] >= 60) {
            $row['nivel_riesgo'] = 'Medio';
            $row['clase_riesgo'] = 'warning';
        } else {
            $row['nivel_riesgo'] = 'Alto';
            $row['clase_riesgo'] = 'danger';
        }
    }
    
    $titulo = "Análisis de Rendimiento Avanzado - " . htmlspecialchars($cursoInfo['nombre']);
    $descripcion = "Análisis detallado del rendimiento en " . htmlspecialchars($cursoInfo['nombre']) . 
                   " durante " . $filtroCuatrimestreDesc . " - Ciclo lectivo " . htmlspecialchars($anioActivo) . 
                   ". Incluye equipos docentes, subgrupos y análisis de riesgo.";
}

/**
 * NUEVA FUNCIÓN: Análisis de materias problemáticas
 */
function generarAnalisisMateriasProblematicas($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    $periodoActual = obtenerPeriodoActual();
    $campoCalificacion = $periodoActual['campo_calificacion'];
    
    $reporteData = $db->fetchAll(
        "SELECT c.nombre as curso_nombre, c.anio as curso_anio, c.id as curso_id,
                m.nombre as materia, m.codigo,
                mp.requiere_subgrupos, mp.id as materia_curso_id,
                p1.apellido as profesor_apellido, p1.nombre as profesor_nombre,
                COUNT(cal.id) as total_estudiantes,
                SUM(CASE WHEN $campoCalificacion < 7 AND $campoCalificacion IS NOT NULL THEN 1 ELSE 0 END) as desaprobados,
                ROUND(AVG(CASE WHEN $campoCalificacion IS NOT NULL AND $campoCalificacion > 0 THEN $campoCalificacion ELSE NULL END), 2) as promedio,
                ROUND((SUM(CASE WHEN $campoCalificacion < 7 AND $campoCalificacion IS NOT NULL THEN 1 ELSE 0 END) * 100.0 / COUNT(cal.id)), 2) as porcentaje_desaprobacion
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id
         LEFT JOIN calificaciones cal ON mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
         WHERE c.ciclo_lectivo_id = ?
         GROUP BY mp.id, c.nombre, m.nombre, m.codigo
         HAVING COUNT(cal.id) > 0 AND porcentaje_desaprobacion > 30
         ORDER BY porcentaje_desaprobacion DESC, desaprobados DESC
         LIMIT 15",
        [$cicloLectivoId, $cicloLectivoId]
    );
    
    $titulo = "Análisis de Materias con Mayor Índice de Desaprobación";
    $descripcion = "Materias con más del 30% de desaprobación en " . $periodoActual['descripcion'] . 
                   " - Ciclo lectivo " . htmlspecialchars($anioActivo) . 
                   ". Ordenadas por porcentaje de desaprobación.";
}

/**
 * NUEVA FUNCIÓN: Reporte detallado por alumno
 */
function generarReporteDetalladoAlumno($db, $estudianteId, $cursoId, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($estudianteId == 0) {
        $titulo = "Reporte Detallado por Alumno";
        $descripcion = "Seleccione un estudiante para ver su reporte académico completo.";
        $reporteData = [];
        return;
    }
    
    // Obtener información del estudiante
    $estudianteInfo = $db->fetchOne(
        "SELECT u.id, u.nombre, u.apellido, u.dni, u.direccion, u.telefono, u.email,
                c.nombre as curso_nombre, c.anio as curso_anio, c.id as curso_id
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         JOIN cursos c ON m.curso_id = c.id
         WHERE u.id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
        [$estudianteId]
    );
    
    if (!$estudianteInfo) {
        $titulo = "Error";
        $descripcion = "Estudiante no encontrado o no matriculado.";
        $reporteData = [];
        return;
    }
    
    $periodoActual = obtenerPeriodoActual();
    
    // Obtener todas las materias del estudiante con calificaciones
    $materiasEstudiante = $db->fetchAll(
        "SELECT m.nombre as materia, m.codigo, mp.id as materia_curso_id, mp.requiere_subgrupos,
                p1.apellido as profesor_apellido, p1.nombre as profesor_nombre,
                c.valoracion_preliminar_1c, c.calificacion_1c, c.intensificacion_1c,
                c.valoracion_preliminar_2c, c.calificacion_2c, c.calificacion_final,
                c.tipo_cursada,
                -- Calcular promedio parcial
                CASE 
                    WHEN c.calificacion_1c IS NOT NULL AND c.calificacion_2c IS NOT NULL 
                    THEN ROUND((c.calificacion_1c + c.calificacion_2c) / 2.0, 2)
                    WHEN c.calificacion_1c IS NOT NULL 
                    THEN c.calificacion_1c
                    WHEN c.calificacion_2c IS NOT NULL 
                    THEN c.calificacion_2c
                    ELSE NULL
                END as promedio_parcial
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id
         LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id AND c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
         WHERE cur.id = ?
         ORDER BY m.nombre",
        [$estudianteId, $cicloLectivoId, $estudianteInfo['curso_id']]
    );
    
    // Obtener estadísticas de asistencia
    $asistenciaEstudiante = $db->fetchOne(
        "SELECT 
            COUNT(*) as dias_totales,
            SUM(CASE WHEN estado = 'presente' THEN 1 ELSE 0 END) as presentes,
            SUM(CASE WHEN estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
            SUM(CASE WHEN estado = 'media_falta' THEN 0.5 ELSE 0 END) as medias_faltas,
            SUM(CASE WHEN estado = 'cuarto_falta' THEN 0.25 ELSE 0 END) as cuartos_faltas,
            SUM(CASE WHEN estado = 'justificada' THEN 1 ELSE 0 END) as justificadas
         FROM asistencias 
         WHERE estudiante_id = ? AND curso_id = ?",
        [$estudianteId, $estudianteInfo['curso_id']]
    );
    
    // Calcular estadísticas académicas
    $totalMaterias = count($materiasEstudiante);
    $materiasAprobadas = 0;
    $materiasDesaprobadas = 0;
    $materiasSinCalificar = 0;
    $sumaPromedios = 0;
    $materiasConPromedio = 0;
    
    foreach ($materiasEstudiante as &$materia) {
        $calificacionFinal = $materia['calificacion_final'];
        $promedioParcial = $materia['promedio_parcial'];
        
        if ($calificacionFinal !== null && $calificacionFinal !== '') {
            if ($calificacionFinal >= 7) {
                $materiasAprobadas++;
                $materia['estado_materia'] = 'Aprobada';
                $materia['clase_estado'] = 'success';
            } else {
                $materiasDesaprobadas++;
                $materia['estado_materia'] = 'Desaprobada';
                $materia['clase_estado'] = 'danger';
            }
        } else {
            $materiasSinCalificar++;
            $materia['estado_materia'] = 'Sin calificar';
            $materia['clase_estado'] = 'secondary';
        }
        
        // Para el promedio general, usar calificación final o promedio parcial
        $notaParaPromedio = $calificacionFinal ?: $promedioParcial;
        if ($notaParaPromedio && $notaParaPromedio > 0) {
            $sumaPromedios += $notaParaPromedio;
            $materiasConPromedio++;
        }
    }
    
    $promedioGeneral = $materiasConPromedio > 0 ? round($sumaPromedios / $materiasConPromedio, 2) : 0;
    
    // Calcular asistencia
    $porcentajeAsistencia = 0;
    $estadoRegularidad = 'Sin datos';
    $claseRegularidad = 'secondary';
    
    if ($asistenciaEstudiante && $asistenciaEstudiante['dias_totales'] > 0) {
        $totalFaltas = $asistenciaEstudiante['ausentes'] + $asistenciaEstudiante['medias_faltas'] + $asistenciaEstudiante['cuartos_faltas'];
        $porcentajeAsistencia = round(($asistenciaEstudiante['presentes'] / $asistenciaEstudiante['dias_totales']) * 100, 2);
        
        if ($porcentajeAsistencia >= 85) {
            $estadoRegularidad = 'Regular';
            $claseRegularidad = 'success';
        } elseif ($porcentajeAsistencia >= 75) {
            $estadoRegularidad = 'En Riesgo';
            $claseRegularidad = 'warning';
        } else {
            $estadoRegularidad = 'Libre';
            $claseRegularidad = 'danger';
        }
    }
    
    $reporteData = [
        'estudiante' => $estudianteInfo,
        'materias' => $materiasEstudiante,
        'asistencia' => $asistenciaEstudiante,
        'estadisticas' => [
            'total_materias' => $totalMaterias,
            'materias_aprobadas' => $materiasAprobadas,
            'materias_desaprobadas' => $materiasDesaprobadas,
            'materias_sin_calificar' => $materiasSinCalificar,
            'promedio_general' => $promedioGeneral,
            'porcentaje_asistencia' => $porcentajeAsistencia,
            'estado_regularidad' => $estadoRegularidad,
            'clase_regularidad' => $claseRegularidad
        ],
        'periodo_actual' => $periodoActual
    ];
    
    $titulo = "Reporte Académico Completo - " . htmlspecialchars($estudianteInfo['apellido'] . ', ' . $estudianteInfo['nombre']);
    $descripcion = "Análisis detallado del rendimiento académico y asistencia de " . 
                   htmlspecialchars($estudianteInfo['apellido'] . ', ' . $estudianteInfo['nombre']) . 
                   " (" . htmlspecialchars($estudianteInfo['dni']) . ") - " . 
                   htmlspecialchars($estudianteInfo['curso_nombre']) . " - Ciclo lectivo " . htmlspecialchars($anioActivo);
}

/**
 * NUEVA FUNCIÓN: Ranking de estudiantes por promedio
 */
function generarRankingEstudiantesPorPromedio($db, $cursoId, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        $titulo = "Ranking de Estudiantes por Promedio";
        $descripcion = "Seleccione un curso para ver el ranking de estudiantes por promedio.";
        $reporteData = [];
        return;
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre, anio FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        $titulo = "Error";
        $descripcion = "Curso no encontrado.";
        $reporteData = [];
        return;
    }
    
    // Obtener todos los estudiantes del curso con sus promedios
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni,
                COUNT(c.id) as total_materias,
                SUM(CASE WHEN c.calificacion_final >= 7 THEN 1 ELSE 0 END) as materias_aprobadas,
                SUM(CASE WHEN c.calificacion_final < 7 AND c.calificacion_final IS NOT NULL THEN 1 ELSE 0 END) as materias_desaprobadas,
                SUM(CASE WHEN c.calificacion_final IS NULL OR c.calificacion_final = '' THEN 1 ELSE 0 END) as materias_sin_calificar,
                -- Promedio de calificaciones finales
                ROUND(AVG(CASE WHEN c.calificacion_final IS NOT NULL AND c.calificacion_final > 0 THEN c.calificacion_final ELSE NULL END), 2) as promedio_final,
                -- Promedio de primer cuatrimestre
                ROUND(AVG(CASE WHEN c.calificacion_1c IS NOT NULL AND c.calificacion_1c > 0 THEN c.calificacion_1c ELSE NULL END), 2) as promedio_1c,
                -- Promedio de segundo cuatrimestre
                ROUND(AVG(CASE WHEN c.calificacion_2c IS NOT NULL AND c.calificacion_2c > 0 THEN c.calificacion_2c ELSE NULL END), 2) as promedio_2c,
                -- Mejor nota
                MAX(CASE WHEN c.calificacion_final IS NOT NULL THEN c.calificacion_final ELSE 0 END) as mejor_nota,
                -- Peor nota (excluyendo nulls y 0s)
                MIN(CASE WHEN c.calificacion_final IS NOT NULL AND c.calificacion_final > 0 THEN c.calificacion_final ELSE 10 END) as peor_nota
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         LEFT JOIN calificaciones c ON u.id = c.estudiante_id AND c.ciclo_lectivo_id = ?
         LEFT JOIN materias_por_curso mp ON c.materia_curso_id = mp.id AND mp.curso_id = ?
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         GROUP BY u.id, u.nombre, u.apellido, u.dni
         ORDER BY promedio_final DESC, u.apellido, u.nombre",
        [$cicloLectivoId, $cursoId, $cursoId]
    );
    
    // Agregar información de posición y análisis
    foreach ($estudiantes as $index => &$estudiante) {
        $estudiante['posicion'] = $index + 1;
        $promedio = $estudiante['promedio_final'] ?: 0;
        
        // Clasificar por rendimiento
        if ($promedio >= 8.5) {
            $estudiante['nivel_rendimiento'] = 'Excelente';
            $estudiante['clase_rendimiento'] = 'success';
            $estudiante['icono_rendimiento'] = 'trophy';
        } elseif ($promedio >= 7.5) {
            $estudiante['nivel_rendimiento'] = 'Muy Bueno';
            $estudiante['clase_rendimiento'] = 'info';
            $estudiante['icono_rendimiento'] = 'award';
        } elseif ($promedio >= 7) {
            $estudiante['nivel_rendimiento'] = 'Bueno';
            $estudiante['clase_rendimiento'] = 'primary';
            $estudiante['icono_rendimiento'] = 'check-circle';
        } elseif ($promedio >= 6) {
            $estudiante['nivel_rendimiento'] = 'Regular';
            $estudiante['clase_rendimiento'] = 'warning';
            $estudiante['icono_rendimiento'] = 'exclamation-triangle';
        } else {
            $estudiante['nivel_rendimiento'] = 'Necesita Mejora';
            $estudiante['clase_rendimiento'] = 'danger';
            $estudiante['icono_rendimiento'] = 'x-circle';
        }
        
        // Porcentaje de aprobación
        $estudiante['porcentaje_aprobacion'] = $estudiante['total_materias'] > 0 ? 
            round(($estudiante['materias_aprobadas'] / $estudiante['total_materias']) * 100, 2) : 0;
    }
    
    // Obtener estadísticas del curso
    $totalEstudiantes = count($estudiantes);
    $promediosCurso = array_filter(array_column($estudiantes, 'promedio_final'));
    $promedioGeneralCurso = count($promediosCurso) > 0 ? round(array_sum($promediosCurso) / count($promediosCurso), 2) : 0;
    $mejorPromedio = count($promediosCurso) > 0 ? max($promediosCurso) : 0;
    $peorPromedio = count($promediosCurso) > 0 ? min($promediosCurso) : 0;
    
    $reporteData = [
        'estudiantes' => $estudiantes,
        'estadisticas_curso' => [
            'total_estudiantes' => $totalEstudiantes,
            'promedio_general_curso' => $promedioGeneralCurso,
            'mejor_promedio' => $mejorPromedio,
            'peor_promedio' => $peorPromedio,
            'estudiantes_excelentes' => count(array_filter($estudiantes, function($e) { return ($e['promedio_final'] ?: 0) >= 8.5; })),
            'estudiantes_en_riesgo' => count(array_filter($estudiantes, function($e) { return ($e['promedio_final'] ?: 0) < 6; }))
        ]
    ];
    
    $titulo = "Ranking de Estudiantes por Promedio - " . htmlspecialchars($cursoInfo['nombre']);
    $descripcion = "Ranking completo de estudiantes del curso " . htmlspecialchars($cursoInfo['nombre']) . 
                   " ordenados por promedio general - Ciclo lectivo " . htmlspecialchars($anioActivo) . 
                   ". Incluye análisis de rendimiento y estadísticas comparativas.";
}

/**
 * NUEVA FUNCIÓN: Análisis de estudiantes destacados y en riesgo
 */
function generarAnalisisEstudiantesDestacados($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    // Obtener estudiantes destacados (promedio >= 8.5) y en riesgo (promedio < 6)
    $estudiantesDestacados = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, c.nombre as curso_nombre, c.anio as curso_anio,
                COUNT(cal.id) as total_materias,
                ROUND(AVG(CASE WHEN cal.calificacion_final IS NOT NULL AND cal.calificacion_final > 0 THEN cal.calificacion_final ELSE NULL END), 2) as promedio_final,
                SUM(CASE WHEN cal.calificacion_final >= 7 THEN 1 ELSE 0 END) as materias_aprobadas,
                MAX(cal.calificacion_final) as mejor_nota
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         JOIN cursos c ON m.curso_id = c.id
         LEFT JOIN calificaciones cal ON u.id = cal.estudiante_id AND cal.ciclo_lectivo_id = ?
         WHERE u.tipo = 'estudiante' AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?
         GROUP BY u.id, u.nombre, u.apellido, u.dni, c.nombre, c.anio
         HAVING promedio_final >= 8.5
         ORDER BY promedio_final DESC, u.apellido
         LIMIT 20",
        [$cicloLectivoId, $cicloLectivoId]
    );
    
    $estudiantesEnRiesgo = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, c.nombre as curso_nombre, c.anio as curso_anio,
                COUNT(cal.id) as total_materias,
                ROUND(AVG(CASE WHEN cal.calificacion_final IS NOT NULL AND cal.calificacion_final > 0 THEN cal.calificacion_final ELSE NULL END), 2) as promedio_final,
                SUM(CASE WHEN cal.calificacion_final < 7 AND cal.calificacion_final IS NOT NULL THEN 1 ELSE 0 END) as materias_desaprobadas,
                MIN(CASE WHEN cal.calificacion_final IS NOT NULL AND cal.calificacion_final > 0 THEN cal.calificacion_final ELSE 10 END) as peor_nota
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         JOIN cursos c ON m.curso_id = c.id
         LEFT JOIN calificaciones cal ON u.id = cal.estudiante_id AND cal.ciclo_lectivo_id = ?
         WHERE u.tipo = 'estudiante' AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?
         GROUP BY u.id, u.nombre, u.apellido, u.dni, c.nombre, c.anio
         HAVING promedio_final < 6 AND promedio_final > 0
         ORDER BY promedio_final ASC, materias_desaprobadas DESC
         LIMIT 20",
        [$cicloLectivoId, $cicloLectivoId]
    );
    
    $reporteData = [
        'destacados' => $estudiantesDestacados,
        'en_riesgo' => $estudiantesEnRiesgo
    ];
    
    $titulo = "Análisis de Estudiantes Destacados y en Riesgo Académico";
    $descripcion = "Identificación de estudiantes con mejor rendimiento (promedio ≥ 8.5) y estudiantes que requieren atención especial (promedio < 6) - Ciclo lectivo " . htmlspecialchars($anioActivo);
}

/**
 * FUNCIÓN: Dashboard de estadísticas generales
 */
function generarDashboardGeneral($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    $periodoActual = obtenerPeriodoActual();
    
    try {
        // Estadísticas por curso
        $estadisticasCursos = $db->fetchAll(
            "SELECT c.id, c.nombre as curso, c.anio,
                    COUNT(DISTINCT m.estudiante_id) as total_estudiantes,
                    COUNT(DISTINCT mp.id) as total_materias,
                    COUNT(DISTINCT CASE WHEN mp.requiere_subgrupos = 1 THEN mp.id END) as materias_con_subgrupos,
                    COUNT(DISTINCT CASE WHEN mp.profesor_id_2 IS NOT NULL OR mp.profesor_id_3 IS NOT NULL THEN mp.id END) as materias_en_equipo
             FROM cursos c
             LEFT JOIN matriculas m ON c.id = m.curso_id AND m.estado = 'activo'
             LEFT JOIN materias_por_curso mp ON c.id = mp.curso_id
             WHERE c.ciclo_lectivo_id = ?
             GROUP BY c.id, c.nombre, c.anio
             ORDER BY c.anio, c.nombre",
            [$cicloLectivoId]
        );
        
        // Estadísticas de profesores
        $estadisticasProfesores = $db->fetchAll(
            "SELECT p.id, p.apellido, p.nombre,
                    COUNT(DISTINCT mp.id) as materias_asignadas,
                    COUNT(DISTINCT mp.curso_id) as cursos_diferentes,
                    COUNT(DISTINCT CASE WHEN mp.requiere_subgrupos = 1 THEN mp.id END) as materias_con_subgrupos,
                    COUNT(DISTINCT CASE WHEN mp.profesor_id_2 IS NOT NULL OR mp.profesor_id_3 IS NOT NULL THEN mp.id END) as materias_en_equipo
             FROM usuarios p
             LEFT JOIN materias_por_curso mp ON (p.id = mp.profesor_id OR p.id = mp.profesor_id_2 OR p.id = mp.profesor_id_3)
             LEFT JOIN cursos c ON mp.curso_id = c.id AND c.ciclo_lectivo_id = ?
             WHERE p.tipo = 'profesor' AND p.activo = 1
             GROUP BY p.id, p.apellido, p.nombre
             HAVING materias_asignadas > 0
             ORDER BY materias_asignadas DESC",
            [$cicloLectivoId]
        );
        
        // Resumen global
        $resumenGlobal = $db->fetchOne(
            "SELECT 
                COUNT(DISTINCT c.id) as total_cursos,
                COUNT(DISTINCT m.estudiante_id) as total_estudiantes,
                COUNT(DISTINCT mp.id) as total_materias,
                COUNT(DISTINCT p.id) as total_profesores_activos,
                COUNT(DISTINCT CASE WHEN mp.requiere_subgrupos = 1 THEN mp.id END) as total_materias_subgrupos,
                COUNT(DISTINCT CASE WHEN mp.profesor_id_2 IS NOT NULL OR mp.profesor_id_3 IS NOT NULL THEN mp.id END) as total_materias_equipo
             FROM cursos c
             LEFT JOIN matriculas m ON c.id = m.curso_id AND m.estado = 'activo'
             LEFT JOIN materias_por_curso mp ON c.id = mp.curso_id
             LEFT JOIN usuarios p ON (p.id = mp.profesor_id OR p.id = mp.profesor_id_2 OR p.id = mp.profesor_id_3) AND p.tipo = 'profesor' AND p.activo = 1
             WHERE c.ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
        
        $reporteData = [
            'cursos' => $estadisticasCursos,
            'profesores' => $estadisticasProfesores,
            'resumen' => $resumenGlobal,
            'periodo_actual' => $periodoActual
        ];
        
    } catch (Exception $e) {
        error_log("Error en generarDashboardGeneral: " . $e->getMessage());
        $reporteData = [
            'cursos' => [],
            'profesores' => [],
            'resumen' => [],
            'periodo_actual' => $periodoActual
        ];
    }
    
    $titulo = "Dashboard General del Sistema - " . $periodoActual['descripcion'];
    $descripcion = "Vista general del sistema educativo para el ciclo lectivo " . htmlspecialchars($anioActivo) . 
                   ". Incluye estadísticas de cursos, profesores, equipos docentes y subgrupos.";
}

// Función mejorada para reporte de asistencia con análisis de patrones
function generarReporteAsistenciaMejorado($db, $cursoId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        $titulo = "Análisis Avanzado de Asistencia";
        $descripcion = "Seleccione un curso para ver el análisis detallado de patrones de asistencia.";
        $reporteData = [];
        return;
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        $titulo = "Error";
        $descripcion = "Curso no encontrado.";
        $reporteData = [];
        return;
    }

    $whereClause = "a.curso_id = ?";
    $parametros = [$cursoId];
    
    if ($cuatrimestre > 0) {
        $whereClause .= " AND a.cuatrimestre = ?";
        $parametros[] = $cuatrimestre;
        $filtroCuatrimestreDesc = $cuatrimestre == 1 ? "1° Cuatrimestre" : "2° Cuatrimestre";
    } else {
        $filtroCuatrimestreDesc = "todo el ciclo lectivo";
    }
    
    $reporteData = $db->fetchAll(
        "SELECT u.nombre, u.apellido, u.dni,
                COUNT(*) as dias_totales,
                SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
                SUM(CASE WHEN a.estado = 'media_falta' THEN 1 ELSE 0 END) as medias_faltas_cantidad,
                SUM(CASE WHEN a.estado = 'cuarto_falta' THEN 1 ELSE 0 END) as cuartos_faltas_cantidad,
                SUM(CASE WHEN a.estado = 'media_falta' THEN 0.5 ELSE 0 END) as medias_faltas,
                SUM(CASE WHEN a.estado = 'cuarto_falta' THEN 0.25 ELSE 0 END) as cuartos_faltas,
                SUM(CASE WHEN a.estado = 'justificada' THEN 1 ELSE 0 END) as justificadas,
                GROUP_CONCAT(DISTINCT CASE WHEN a.estado = 'justificada' AND a.motivo_falta IS NOT NULL AND a.motivo_falta != '' 
                    THEN a.motivo_falta END) as motivos_justificaciones,
                -- Análisis de patrones
                COUNT(CASE WHEN a.estado = 'ausente' AND strftime('%w', a.fecha) = '1' THEN 1 END) as ausentes_lunes,
                COUNT(CASE WHEN a.estado = 'ausente' AND strftime('%w', a.fecha) = '5' THEN 1 END) as ausentes_viernes,
                MAX(CASE WHEN a.estado = 'ausente' THEN a.fecha END) as ultima_ausencia
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         LEFT JOIN asistencias a ON u.id = a.estudiante_id AND $whereClause
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         GROUP BY u.id, u.nombre, u.apellido, u.dni
         ORDER BY u.apellido, u.nombre",
        array_merge($parametros, [$cursoId])
    );
    
    $motivosJustificados = [
        'certificado_medico' => 'Certificado médico',
        'tramite_familiar' => 'Trámite familiar',
        'viaje_familiar' => 'Viaje familiar',
        'duelo_familiar' => 'Duelo familiar',
        'consulta_medica' => 'Consulta médica',
        'estudios_medicos' => 'Estudios médicos',
        'tramite_documentacion' => 'Trámite de documentación',
        'problema_transporte' => 'Problema de transporte',
        'emergencia_familiar' => 'Emergencia familiar',
        'actividad_deportiva' => 'Actividad deportiva representativa',
        'actividad_cultural' => 'Actividad cultural/artística',
        'comparendo_judicial' => 'Comparendo judicial',
        'mudanza' => 'Mudanza',
        'boda_familiar' => 'Boda familiar',
        'nacimiento_hermano' => 'Nacimiento de hermano',
        'otro' => 'Otro motivo'
    ];
    
    foreach ($reporteData as &$estudiante) {
        if ($estudiante['dias_totales'] > 0) {
            $totalFaltas = $estudiante['ausentes'] + $estudiante['medias_faltas'] + $estudiante['cuartos_faltas'];
            $estudiante['total_faltas_computables'] = round($totalFaltas, 2);
            $estudiante['porcentaje_asistencia'] = round(($estudiante['presentes'] / $estudiante['dias_totales']) * 100, 2);
            
            // Análisis de patrones mejorado
            $estudiante['patron_ausencias_lunes_viernes'] = ($estudiante['ausentes_lunes'] + $estudiante['ausentes_viernes']) > ($estudiante['ausentes'] * 0.4);
            $estudiante['ausencias_frecuentes'] = $estudiante['ausentes'] > 10;
            $estudiante['riesgo_abandono'] = $estudiante['porcentaje_asistencia'] < 60;
            
            if ($estudiante['porcentaje_asistencia'] >= 85) {
                $estudiante['estado_regularidad'] = 'Regular';
                $estudiante['clase_estado'] = 'success';
            } elseif ($estudiante['porcentaje_asistencia'] >= 75) {
                $estudiante['estado_regularidad'] = 'En Riesgo';
                $estudiante['clase_estado'] = 'warning';
            } else {
                $estudiante['estado_regularidad'] = 'Libre';
                $estudiante['clase_estado'] = 'danger';
            }
            
            if (!empty($estudiante['motivos_justificaciones'])) {
                $motivosCodigos = explode(',', $estudiante['motivos_justificaciones']);
                $motivosTexto = [];
                foreach ($motivosCodigos as $codigo) {
                    $codigo = trim($codigo);
                    if (isset($motivosJustificados[$codigo])) {
                        $motivosTexto[] = $motivosJustificados[$codigo];
                    } elseif (!empty($codigo)) {
                        $motivosTexto[] = $codigo;
                    }
                }
                $estudiante['motivos_justificaciones_texto'] = implode(', ', array_unique($motivosTexto));
            } else {
                $estudiante['motivos_justificaciones_texto'] = '-';
            }
            
        } else {
            $estudiante['total_faltas_computables'] = 0;
            $estudiante['porcentaje_asistencia'] = 0;
            $estudiante['estado_regularidad'] = 'Sin datos';
            $estudiante['clase_estado'] = 'secondary';
            $estudiante['motivos_justificaciones_texto'] = '-';
            $estudiante['patron_ausencias_lunes_viernes'] = false;
            $estudiante['ausencias_frecuentes'] = false;
            $estudiante['riesgo_abandono'] = false;
        }
    }
    
    $titulo = "Análisis Avanzado de Asistencia - " . htmlspecialchars($cursoInfo['nombre']);
    $descripcion = "Análisis detallado de patrones de asistencia del curso " . htmlspecialchars($cursoInfo['nombre']) . 
                   " durante " . $filtroCuatrimestreDesc . " - Ciclo lectivo " . htmlspecialchars($anioActivo) . 
                   ". Incluye detección de patrones y análisis de riesgo.";
}

// Lógica principal para generar reportes
if ($tipoReporte && $cicloLectivoId > 0) {
    try {
        switch ($tipoReporte) {
            case 'rendimiento_curso_avanzado':
                generarReporteRendimientoCursoMejorado($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            case 'materias_problematicas':
                generarAnalisisMateriasProblematicas($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            case 'dashboard_general':
                generarDashboardGeneral($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            case 'asistencia_avanzada':
                generarReporteAsistenciaMejorado($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            case 'reporte_detallado_alumno':
                generarReporteDetalladoAlumno($db, $estudianteId, $cursoId, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            case 'ranking_estudiantes_promedio':
                generarRankingEstudiantesPorPromedio($db, $cursoId, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            case 'estudiantes_destacados_riesgo':
                generarAnalisisEstudiantesDestacados($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            // Mantener los reportes originales para compatibilidad
            case 'rendimiento_curso':
                generarReporteRendimientoCursoMejorado($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
                break;
            default:
                $page_info_message = "Tipo de reporte no válido seleccionado.";
                $tipoReporte = '';
        }
    } catch (Exception $e) {
        $page_error_message .= (empty($page_error_message) ? '' : '<br>') . 'Error al generar el reporte: ' . htmlspecialchars($e->getMessage());
        $reporteData = [];
        $titulo = "Error al generar reporte";
        $descripcion = htmlspecialchars($e->getMessage());
    }
}

$periodoActual = obtenerPeriodoActual();
?>

<div class="container-fluid mt-4">
    <!-- Mostrar errores si los hay -->
    <?php if (!empty($page_error_message)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> <?= $page_error_message ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($page_info_message) && empty($page_error_message)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> <?= htmlspecialchars($page_info_message) ?>
        </div>
    <?php endif; ?>

    <!-- Header principal con información del período -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h4 class="card-title mb-0">
                        <i class="bi bi-graph-up"></i> Sistema de Reportes y Análisis Avanzado
                        <span class="badge bg-light text-dark ms-3">
                            <i class="bi bi-calendar-event"></i> <?= $periodoActual['descripcion'] ?>
                        </span>
                        <span class="badge bg-warning text-dark ms-2">
                            Ciclo Lectivo <?= htmlspecialchars($anioActivo) ?>
                        </span>
                    </h4>
                </div>
                
                <?php if ($cicloLectivoId > 0): ?>
                <div class="card-body">
                    <!-- Formulario de selección mejorado -->
                    <form method="GET" action="reportes.php" class="mb-4">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="tipo_reporte" class="form-label fw-bold">
                                    <i class="bi bi-clipboard-data"></i> Tipo de Análisis:
                                </label>
                                <select name="tipo_reporte" id="tipo_reporte" class="form-select" required onchange="ajustarCamposAvanzados()">
                                    <option value="" <?= $tipoReporte == '' ? 'selected' : '' ?>>-- Seleccione un análisis --</option>
                                    
                                    <optgroup label="📊 Análisis de Rendimiento">
                                        <option value="rendimiento_curso_avanzado" <?= $tipoReporte == 'rendimiento_curso_avanzado' ? 'selected' : '' ?>>
                                            📈 Rendimiento Avanzado por Curso
                                        </option>
                                        <option value="materias_problematicas" <?= $tipoReporte == 'materias_problematicas' ? 'selected' : '' ?>>
                                            ⚠️ Materias con Mayor Desaprobación
                                        </option>
                                    </optgroup>
                                    
                                    <optgroup label="👥 Análisis de Asistencia">
                                        <option value="asistencia_avanzada" <?= $tipoReporte == 'asistencia_avanzada' ? 'selected' : '' ?>>
                                            📅 Análisis Avanzado de Asistencia
                                        </option>
                                    </optgroup>
                                    
                                    <optgroup label="🎓 Análisis por Estudiantes">
                                        <option value="reporte_detallado_alumno" <?= $tipoReporte == 'reporte_detallado_alumno' ? 'selected' : '' ?>>
                                            👤 Reporte Detallado por Alumno
                                        </option>
                                        <option value="ranking_estudiantes_promedio" <?= $tipoReporte == 'ranking_estudiantes_promedio' ? 'selected' : '' ?>>
                                            🏆 Ranking de Estudiantes por Promedio
                                        </option>
                                        <option value="estudiantes_destacados_riesgo" <?= $tipoReporte == 'estudiantes_destacados_riesgo' ? 'selected' : '' ?>>
                                            ⭐ Estudiantes Destacados y en Riesgo
                                        </option>
                                    </optgroup>
                                    
                                    <optgroup label="🎯 Análisis General">
                                        <option value="dashboard_general" <?= $tipoReporte == 'dashboard_general' ? 'selected' : '' ?>>
                                            🏛️ Dashboard General del Sistema
                                        </option>
                                    </optgroup>
                                    
                                    <optgroup label="📋 Reportes Clásicos">
                                        <option value="rendimiento_curso" <?= $tipoReporte == 'rendimiento_curso' ? 'selected' : '' ?>>
                                            Rendimiento por Curso (Clásico)
                                        </option>
                                    </optgroup>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3" id="campo_curso_avanzado" style="display: none;">
                                <label for="curso" class="form-label fw-bold">
                                    <i class="bi bi-building"></i> Curso:
                                </label>
                                <select name="curso" id="curso" class="form-select" onchange="cargarMateriasYEstudiantes()">
                                    <option value="0">-- Seleccione un curso --</option>
                                    <?php foreach ($cursos as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $cursoId == $c['id'] ? 'selected' : '' ?>>
                                        🎓 <?= htmlspecialchars($c['nombre']) ?> (<?= $c['anio'] ?>° año)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3" id="campo_estudiante" style="display: none;">
                                <label for="estudiante" class="form-label fw-bold">
                                    <i class="bi bi-person"></i> Estudiante:
                                </label>
                                <select name="estudiante" id="estudiante" class="form-select">
                                    <option value="0">-- Seleccione un estudiante --</option>
                                    <?php foreach ($estudiantes as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= $estudianteId == $e['id'] ? 'selected' : '' ?>>
                                        👤 <?= htmlspecialchars($e['apellido'] . ', ' . $e['nombre']) ?> <?= isset($e['curso_nombre']) ? '(' . $e['curso_nombre'] . ')' : '(' . $e['dni'] . ')' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3" id="campo_cuatrimestre_avanzado" style="display: none;">
                                <label for="cuatrimestre" class="form-label fw-bold">
                                    <i class="bi bi-calendar-range"></i> Período:
                                </label>
                                <select name="cuatrimestre" id="cuatrimestre" class="form-select">
                                    <option value="0" <?= $cuatrimestre == 0 ? 'selected' : '' ?>>
                                        📊 Actual (<?= $periodoActual['descripcion'] ?>)
                                    </option>
                                    <option value="1" <?= $cuatrimestre == 1 ? 'selected' : '' ?>>
                                        📅 1° Cuatrimestre
                                    </option>
                                    <option value="2" <?= $cuatrimestre == 2 ? 'selected' : '' ?>>
                                        📅 2° Cuatrimestre
                                    </option>
                                </select>
                            </div>
                            
                            <div class="col-md-2 mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    <i class="bi bi-search"></i> Generar
                                </button>
                            </div>
                        </div>
                        
                        <!-- Botones de exportación -->
                        <?php if ($tipoReporte && !empty($reporteData) && $cicloLectivoId > 0): ?>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="btn-group" role="group">
                                    <?php
                                    $currentQueryForExportButtons = $_GET;
                                    unset($currentQueryForExportButtons['export']);
                                    $exportButtonQueryString = http_build_query($currentQueryForExportButtons);
                                    ?>
                                    <a href="reportes.php?<?= $exportButtonQueryString ?>&export=excel" class="btn btn-success">
                                        <i class="bi bi-file-excel"></i> Exportar a Excel
                                    </a>
                                    <a href="reportes.php?<?= $exportButtonQueryString ?>&export=pdf" class="btn btn-danger">
                                        <i class="bi bi-file-pdf"></i> Exportar a PDF
                                    </a>
                                    <button type="button" class="btn btn-info" onclick="imprimirReporte()">
                                        <i class="bi bi-printer"></i> Imprimir
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mostrar resultados del reporte -->
    <?php if ($tipoReporte && !empty($reporteData) && $cicloLectivoId > 0 && empty($page_error_message)): ?>
    
    <!-- Dashboard General -->
    <?php if ($tipoReporte == 'dashboard_general'): ?>
        <div class="row mb-4">
            <!-- Tarjetas de resumen -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Cursos Activos</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $reporteData['resumen']['total_cursos'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-building text-primary" style="font-size: 2rem;"></i>
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
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Estudiantes</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $reporteData['resumen']['total_estudiantes'] ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-people text-success" style="font-size: 2rem;"></i>
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
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Materias Activas</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $reporteData['resumen']['total_materias'] ?></div>
                                <small class="text-info">
                                    <?= $reporteData['resumen']['total_materias_subgrupos'] ?> con subgrupos
                                </small>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-journal-bookmark text-info" style="font-size: 2rem;"></i>
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
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Profesores</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $reporteData['resumen']['total_profesores_activos'] ?></div>
                                <small class="text-warning">
                                    <?= $reporteData['resumen']['total_materias_equipo'] ?> en equipos
                                </small>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-person-badge text-warning" style="font-size: 2rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos del dashboard -->
        <div class="row">
            <div class="col-xl-8 col-lg-7">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Distribución de Estudiantes por Curso</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 400px;">
                            <canvas id="graficoEstudiantesPorCurso"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-4 col-lg-5">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Materias por Modalidad</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 400px;">
                            <canvas id="graficoMateriasTipo"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de cursos -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Detalle por Cursos</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-primary">
                            <tr>
                                <th>Curso</th>
                                <th class="text-center">Estudiantes</th>
                                <th class="text-center">Materias</th>
                                <th class="text-center">Con Subgrupos</th>
                                <th class="text-center">En Equipo</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporteData['cursos'] as $curso): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($curso['curso']) ?></strong>
                                    <br><small class="text-muted"><?= $curso['anio'] ?>° año</small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= $curso['total_estudiantes'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= $curso['total_materias'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $curso['materias_con_subgrupos'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= $curso['materias_en_equipo'] ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <a href="reportes.php?tipo_reporte=rendimiento_curso_avanzado&curso=<?= $curso['id'] ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="bi bi-graph-up"></i> Rendimiento
                                        </a>
                                        <a href="reportes.php?tipo_reporte=ranking_estudiantes_promedio&curso=<?= $curso['id'] ?>" 
                                           class="btn btn-sm btn-warning">
                                            <i class="bi bi-trophy"></i> Ranking
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    
    <!-- Reporte Detallado por Alumno -->
    <?php elseif ($tipoReporte == 'reporte_detallado_alumno'): ?>
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person-lines-fill"></i> <?= htmlspecialchars($titulo) ?>
                </h5>
                <p class="mb-0"><?= htmlspecialchars($descripcion) ?></p>
            </div>
            <div class="card-body">
                <!-- Información del estudiante -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-light">
                                <h6 class="text-primary mb-0">
                                    <i class="bi bi-person-circle"></i> Información Personal
                                </h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>Estudiante:</strong></td>
                                        <td><?= htmlspecialchars($reporteData['estudiante']['apellido'] . ', ' . $reporteData['estudiante']['nombre']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>DNI:</strong></td>
                                        <td><?= htmlspecialchars($reporteData['estudiante']['dni']) ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Curso:</strong></td>
                                        <td><?= htmlspecialchars($reporteData['estudiante']['curso_nombre']) ?> (<?= $reporteData['estudiante']['curso_anio'] ?>° año)</td>
                                    </tr>
                                    <?php if (!empty($reporteData['estudiante']['email'])): ?>
                                    <tr>
                                        <td><strong>Email:</strong></td>
                                        <td><?= htmlspecialchars($reporteData['estudiante']['email']) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-header bg-light">
                                <h6 class="text-success mb-0">
                                    <i class="bi bi-graph-up-arrow"></i> Estadísticas Académicas
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <h4 class="text-primary"><?= $reporteData['estadisticas']['promedio_general'] ?></h4>
                                        <small>Promedio General</small>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <h4 class="text-info"><?= $reporteData['estadisticas']['porcentaje_asistencia'] ?>%</h4>
                                        <small>Asistencia</small>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="text-success"><?= $reporteData['estadisticas']['materias_aprobadas'] ?></h5>
                                        <small>Aprobadas</small>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="text-danger"><?= $reporteData['estadisticas']['materias_desaprobadas'] ?></h5>
                                        <small>Desaprobadas</small>
                                    </div>
                                    <div class="col-4">
                                        <h5 class="text-warning"><?= $reporteData['estadisticas']['materias_sin_calificar'] ?></h5>
                                        <small>Sin Calificar</small>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <span class="badge bg-<?= $reporteData['estadisticas']['clase_regularidad'] ?>">
                                        Estado: <?= $reporteData['estadisticas']['estado_regularidad'] ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Gráfico de rendimiento del estudiante -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="text-primary">📊 Rendimiento por Materia</h6>
                            </div>
                            <div class="card-body">
                                <div style="height: 400px;">
                                    <canvas id="graficoRendimientoEstudiante"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabla de materias detallada -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-primary">
                            <tr>
                                <th>Materia</th>
                                <th>Profesor</th>
                                <th class="text-center">Val. 1°C</th>
                                <th class="text-center">Calif. 1°C</th>
                                <th class="text-center">Val. 2°C</th>
                                <th class="text-center">Calif. 2°C</th>
                                <th class="text-center">Intensif.</th>
                                <th class="text-center">Calif. Final</th>
                                <th class="text-center">Promedio Parcial</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporteData['materias'] as $materia): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($materia['materia']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($materia['codigo']) ?></small>
                                    <?php if ($materia['requiere_subgrupos']): ?>
                                    <br><span class="badge bg-info">Subgrupos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($materia['profesor_apellido'] . ', ' . $materia['profesor_nombre']) ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $materia['valoracion_preliminar_1c'] == 'TEA' ? 'success' : ($materia['valoracion_preliminar_1c'] == 'TEP' ? 'warning' : ($materia['valoracion_preliminar_1c'] == 'TED' ? 'danger' : 'secondary')) ?>">
                                        <?= $materia['valoracion_preliminar_1c'] ?: '-' ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <strong class="text-<?= isset($materia['calificacion_1c']) && $materia['calificacion_1c'] >= 7 ? 'success' : (isset($materia['calificacion_1c']) && $materia['calificacion_1c'] >= 4 ? 'warning' : 'danger') ?>">
                                        <?= $materia['calificacion_1c'] ?: '-' ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $materia['valoracion_preliminar_2c'] == 'TEA' ? 'success' : ($materia['valoracion_preliminar_2c'] == 'TEP' ? 'warning' : ($materia['valoracion_preliminar_2c'] == 'TED' ? 'danger' : 'secondary')) ?>">
                                        <?= $materia['valoracion_preliminar_2c'] ?: '-' ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <strong class="text-<?= isset($materia['calificacion_2c']) && $materia['calificacion_2c'] >= 7 ? 'success' : (isset($materia['calificacion_2c']) && $materia['calificacion_2c'] >= 4 ? 'warning' : 'danger') ?>">
                                        <?= $materia['calificacion_2c'] ?: '-' ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <strong class="text-<?= isset($materia['intensificacion_1c']) && $materia['intensificacion_1c'] >= 7 ? 'success' : (isset($materia['intensificacion_1c']) && $materia['intensificacion_1c'] >= 4 ? 'warning' : 'danger') ?>">
                                        <?= $materia['intensificacion_1c'] ?: '-' ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <h6 class="text-<?= isset($materia['calificacion_final']) && $materia['calificacion_final'] >= 7 ? 'success' : (isset($materia['calificacion_final']) && $materia['calificacion_final'] >= 4 ? 'warning' : 'danger') ?>">
                                        <?= $materia['calificacion_final'] ?: '-' ?>
                                    </h6>
                                </td>
                                <td class="text-center">
                                    <strong class="text-<?= isset($materia['promedio_parcial']) && $materia['promedio_parcial'] >= 7 ? 'success' : (isset($materia['promedio_parcial']) && $materia['promedio_parcial'] >= 4 ? 'warning' : 'danger') ?>">
                                        <?= $materia['promedio_parcial'] ?: '-' ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $materia['clase_estado'] ?>">
                                        <?= $materia['estado_materia'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Información de asistencia si está disponible -->
                <?php if ($reporteData['asistencia'] && $reporteData['asistencia']['dias_totales'] > 0): ?>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card border-info">
                            <div class="card-header bg-light">
                                <h6 class="text-info mb-0">
                                    <i class="bi bi-calendar-check"></i> Detalle de Asistencia
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-2">
                                        <h5 class="text-success"><?= $reporteData['asistencia']['presentes'] ?></h5>
                                        <small>Presentes</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h5 class="text-danger"><?= $reporteData['asistencia']['ausentes'] ?></h5>
                                        <small>Ausentes</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h5 class="text-warning"><?= round($reporteData['asistencia']['medias_faltas'], 1) ?></h5>
                                        <small>1/2 Faltas</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h5 class="text-secondary"><?= round($reporteData['asistencia']['cuartos_faltas'], 1) ?></h5>
                                        <small>1/4 Faltas</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h5 class="text-info"><?= $reporteData['asistencia']['justificadas'] ?></h5>
                                        <small>Justificadas</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h5 class="text-primary"><?= $reporteData['asistencia']['dias_totales'] ?></h5>
                                        <small>Total Días</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    
    <!-- Ranking de Estudiantes por Promedio -->
    <?php elseif ($tipoReporte == 'ranking_estudiantes_promedio'): ?>
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="card-title mb-0">
                    <i class="bi bi-trophy"></i> <?= htmlspecialchars($titulo) ?>
                </h5>
                <p class="mb-0"><?= htmlspecialchars($descripcion) ?></p>
            </div>
            <div class="card-body">
                <!-- Estadísticas del curso -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> Estadísticas del Curso</h6>
                            <div class="row">
                                <div class="col-md-2">
                                    <strong>Total Estudiantes:</strong>
                                    <span class="badge bg-primary"><?= $reporteData['estadisticas_curso']['total_estudiantes'] ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Promedio General:</strong>
                                    <span class="badge bg-info"><?= $reporteData['estadisticas_curso']['promedio_general_curso'] ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Mejor Promedio:</strong>
                                    <span class="badge bg-success"><?= $reporteData['estadisticas_curso']['mejor_promedio'] ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Peor Promedio:</strong>
                                    <span class="badge bg-danger"><?= $reporteData['estadisticas_curso']['peor_promedio'] ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Excelentes:</strong>
                                    <span class="badge bg-success"><?= $reporteData['estadisticas_curso']['estudiantes_excelentes'] ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>En Riesgo:</strong>
                                    <span class="badge bg-danger"><?= $reporteData['estadisticas_curso']['estudiantes_en_riesgo'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Gráfico de distribución de promedios -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="text-primary">📊 Distribución de Promedios</h6>
                            </div>
                            <div class="card-body">
                                <div style="height: 400px;">
                                    <canvas id="graficoRankingEstudiantes"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabla de ranking -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-warning">
                            <tr>
                                <th class="text-center">Posición</th>
                                <th>Estudiante</th>
                                <th class="text-center">Promedio Final</th>
                                <th class="text-center">Promedio 1°C</th>
                                <th class="text-center">Promedio 2°C</th>
                                <th class="text-center">Materias</th>
                                <th class="text-center">% Aprobación</th>
                                <th class="text-center">Mejor Nota</th>
                                <th class="text-center">Peor Nota</th>
                                <th class="text-center">Nivel</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporteData['estudiantes'] as $estudiante): ?>
                            <tr class="<?= $estudiante['posicion'] <= 3 ? 'table-success' : ($estudiante['posicion'] <= 5 ? 'table-info' : '') ?>">
                                <td class="text-center">
                                    <?php if ($estudiante['posicion'] == 1): ?>
                                        <span class="badge bg-warning text-dark fs-5">
                                            <i class="bi bi-trophy-fill"></i> 1°
                                        </span>
                                    <?php elseif ($estudiante['posicion'] == 2): ?>
                                        <span class="badge bg-secondary fs-5">
                                            <i class="bi bi-award"></i> 2°
                                        </span>
                                    <?php elseif ($estudiante['posicion'] == 3): ?>
                                        <span class="badge bg-success fs-5">
                                            <i class="bi bi-award"></i> 3°
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><?= $estudiante['posicion'] ?>°</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($estudiante['dni']) ?></small>
                                </td>
                                <td class="text-center">
                                    <h5 class="text-<?= $estudiante['clase_rendimiento'] ?>">
                                        <?= $estudiante['promedio_final'] ?: '-' ?>
                                    </h5>
                                </td>
                                <td class="text-center">
                                    <strong><?= $estudiante['promedio_1c'] ?: '-' ?></strong>
                                </td>
                                <td class="text-center">
                                    <strong><?= $estudiante['promedio_2c'] ?: '-' ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= $estudiante['total_materias'] ?></span>
                                    <br><small>
                                        <span class="text-success"><?= $estudiante['materias_aprobadas'] ?> aprobadas</span>
                                    </small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $estudiante['porcentaje_aprobacion'] >= 80 ? 'success' : ($estudiante['porcentaje_aprobacion'] >= 60 ? 'warning' : 'danger') ?>">
                                        <?= $estudiante['porcentaje_aprobacion'] ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <strong class="text-success"><?= $estudiante['mejor_nota'] ?></strong>
                                </td>
                                <td class="text-center">
                                    <strong class="text-danger"><?= $estudiante['peor_nota'] != 10 ? $estudiante['peor_nota'] : '-' ?></strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $estudiante['clase_rendimiento'] ?>">
                                        <i class="bi bi-<?= $estudiante['icono_rendimiento'] ?>"></i>
                                        <?= $estudiante['nivel_rendimiento'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="reportes.php?tipo_reporte=reporte_detallado_alumno&curso=<?= $cursoId ?>&estudiante=<?= $estudiante['id'] ?>" 
                                       class="btn btn-sm btn-primary" title="Ver Reporte Detallado">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    
    <!-- Estudiantes Destacados y en Riesgo -->
    <?php elseif ($tipoReporte == 'estudiantes_destacados_riesgo'): ?>
        <div class="row">
            <!-- Estudiantes Destacados -->
            <div class="col-md-6 mb-4">
                <div class="card border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-star-fill"></i> Estudiantes Destacados
                        </h5>
                        <small>Promedio ≥ 8.5</small>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($reporteData['destacados'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Curso</th>
                                        <th class="text-center">Promedio</th>
                                        <th class="text-center">Mejor Nota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reporteData['destacados'] as $index => $estudiante): ?>
                                    <tr>
                                        <td>
                                            <?php if ($index < 3): ?>
                                                <i class="bi bi-trophy text-warning"></i>
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($estudiante['dni']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($estudiante['curso_nombre']) ?>
                                            <br><small class="text-muted"><?= $estudiante['curso_anio'] ?>° año</small>
                                        </td>
                                        <td class="text-center">
                                            <h6 class="text-success"><?= $estudiante['promedio_final'] ?></h6>
                                        </td>
                                        <td class="text-center">
                                            <strong class="text-primary"><?= $estudiante['mejor_nota'] ?></strong>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No se encontraron estudiantes con promedio ≥ 8.5
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Estudiantes en Riesgo -->
            <div class="col-md-6 mb-4">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-exclamation-triangle-fill"></i> Estudiantes en Riesgo
                        </h5>
                        <small>Promedio < 6.0</small>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($reporteData['en_riesgo'])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Curso</th>
                                        <th class="text-center">Promedio</th>
                                        <th class="text-center">Desaprobadas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reporteData['en_riesgo'] as $estudiante): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($estudiante['dni']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($estudiante['curso_nombre']) ?>
                                            <br><small class="text-muted"><?= $estudiante['curso_anio'] ?>° año</small>
                                        </td>
                                        <td class="text-center">
                                            <h6 class="text-danger"><?= $estudiante['promedio_final'] ?></h6>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= $estudiante['materias_desaprobadas'] ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> ¡Excelente! No hay estudiantes con promedio menor a 6.0
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos comparativos -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="text-primary">📊 Análisis Comparativo de Rendimiento</h6>
                    </div>
                    <div class="card-body">
                        <div style="height: 400px;">
                            <canvas id="graficoDestacadosRiesgo"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    
    <!-- Rendimiento Avanzado por Curso -->
    <?php elseif ($tipoReporte == 'rendimiento_curso_avanzado'): ?>
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="card-title"><?= htmlspecialchars($titulo) ?></h5>
                <p class="card-text text-muted mb-0"><?= htmlspecialchars($descripcion) ?></p>
            </div>
            <div class="card-body">
                <!-- Gráfico de rendimiento -->
                <div class="mb-4">
                    <h6 class="text-primary">📊 Análisis Visual de Rendimiento</h6>
                    <div style="height: 400px; position: relative;">
                        <canvas id="graficoRendimientoAvanzado"></canvas>
                    </div>
                </div>
                
                <!-- Tabla detallada -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead class="table-primary">
                            <tr>
                                <th>Materia</th>
                                <th>Equipo Docente</th>
                                <th class="text-center">Estudiantes</th>
                                <th class="text-center">Aprobados</th>
                                <th class="text-center">Desaprobados</th>
                                <th class="text-center">Sin Calificar</th>
                                <th class="text-center">Promedio</th>
                                <th class="text-center">% Aprobación</th>
                                <th class="text-center">Nivel de Riesgo</th>
                                <th class="text-center">Características</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporteData as $row): ?>
                            <tr class="<?= $row['es_equipo'] ? 'table-info' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($row['materia']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($row['codigo']) ?></small>
                                </td>
                                <td>
                                    <?php if ($row['es_equipo']): ?>
                                        <span class="badge bg-info mb-1">
                                            <i class="bi bi-people-fill"></i> Equipo (<?= $row['total_profesores'] ?>)
                                        </span>
                                        <br><small><?= htmlspecialchars($row['equipo_docente']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-person-check"></i> Individual
                                        </span>
                                        <br><small><?= htmlspecialchars($row['equipo_docente']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?= $row['total_estudiantes'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= $row['aprobados'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger"><?= $row['desaprobados'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $row['sin_calificar'] ?></span>
                                </td>
                                <td class="text-center">
                                    <strong><?= $row['promedio'] ?: '-' ?></strong>
                                    <?php if ($row['promedio']): ?>
                                    <br><small class="text-muted">
                                        Min: <?= $row['nota_minima'] ?> | Max: <?= $row['nota_maxima'] ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $row['porcentaje_aprobacion'] >= 80 ? 'success' : ($row['porcentaje_aprobacion'] >= 60 ? 'warning' : 'danger') ?>">
                                        <?= $row['porcentaje_aprobacion'] ?>%
                                    </span>
                                    <div class="progress mt-1" style="height: 5px;">
                                        <div class="progress-bar bg-<?= $row['porcentaje_aprobacion'] >= 80 ? 'success' : ($row['porcentaje_aprobacion'] >= 60 ? 'warning' : 'danger') ?>" 
                                             style="width: <?= $row['porcentaje_aprobacion'] ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $row['clase_riesgo'] ?>">
                                        <?= $row['nivel_riesgo'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['tiene_subgrupos']): ?>
                                    <span class="badge bg-warning">
                                        <i class="bi bi-people"></i> Subgrupos
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($row['es_equipo']): ?>
                                    <span class="badge bg-info">
                                        <i class="bi bi-people-fill"></i> Equipo
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    
    <!-- Materias Problemáticas -->
    <?php elseif ($tipoReporte == 'materias_problematicas'): ?>
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($titulo) ?>
                </h5>
                <p class="mb-0"><?= htmlspecialchars($descripcion) ?></p>
            </div>
            <div class="card-body">
                <!-- Gráfico de materias problemáticas -->
                <div class="mb-4">
                    <h6 class="text-danger">📈 Ranking de Materias con Mayor Desaprobación</h6>
                    <div style="height: 400px; position: relative;">
                        <canvas id="graficoMateriasProblematicas"></canvas>
                    </div>
                </div>
                
                <!-- Tabla de materias problemáticas -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-danger">
                            <tr>
                                <th>Posición</th>
                                <th>Materia</th>
                                <th>Curso</th>
                                <th>Profesor</th>
                                <th class="text-center">Total Estudiantes</th>
                                <th class="text-center">Desaprobados</th>
                                <th class="text-center">% Desaprobación</th>
                                <th class="text-center">Promedio</th>
                                <th class="text-center">Nivel de Alerta</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporteData as $index => $row): 
                                $nivelAlerta = '';
                                $claseAlerta = '';
                                if ($row['porcentaje_desaprobacion'] >= 70) {
                                    $nivelAlerta = 'CRÍTICO';
                                    $claseAlerta = 'danger';
                                } elseif ($row['porcentaje_desaprobacion'] >= 50) {
                                    $nivelAlerta = 'ALTO';
                                    $claseAlerta = 'warning';
                                } else {
                                    $nivelAlerta = 'MODERADO';
                                    $claseAlerta = 'info';
                                }
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary fs-6"><?= $index + 1 ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($row['materia']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($row['codigo']) ?></small>
                                    <?php if ($row['requiere_subgrupos']): ?>
                                    <br><span class="badge bg-warning">Subgrupos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['curso_nombre']) ?>
                                    <br><small class="text-muted"><?= $row['curso_anio'] ?>° año</small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['profesor_apellido'] . ', ' . $row['profesor_nombre']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= $row['total_estudiantes'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger"><?= $row['desaprobados'] ?></span>
                                </td>
                                <td class="text-center">
                                    <h5 class="text-danger"><?= $row['porcentaje_desaprobacion'] ?>%</h5>
                                    <div class="progress">
                                        <div class="progress-bar bg-danger" style="width: <?= $row['porcentaje_desaprobacion'] ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <strong class="text-<?= $row['promedio'] < 6 ? 'danger' : ($row['promedio'] < 7 ? 'warning' : 'success') ?>">
                                        <?= $row['promedio'] ?: '-' ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $claseAlerta ?> pulse">
                                        <?= $nivelAlerta ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <a href="calificaciones.php?curso=<?= $row['curso_id'] ?? '' ?>&materia=<?= $row['materia_curso_id'] ?>" 
                                           class="btn btn-sm btn-primary" title="Ver Calificaciones">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button class="btn btn-sm btn-warning" title="Plan de Mejora" onclick="mostrarPlanMejora(<?= $row['materia_curso_id'] ?>)">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Análisis estadístico adicional -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-graph-up"></i> Análisis Estadístico</h6>
                            <?php
                            $totalMaterias = count($reporteData);
                            $criticas = count(array_filter($reporteData, function($r) { return $r['porcentaje_desaprobacion'] >= 70; }));
                            $altas = count(array_filter($reporteData, function($r) { return $r['porcentaje_desaprobacion'] >= 50 && $r['porcentaje_desaprobacion'] < 70; }));
                            $promedioDesaprobacion = $totalMaterias > 0 ? round(array_sum(array_column($reporteData, 'porcentaje_desaprobacion')) / $totalMaterias, 2) : 0;
                            ?>
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Materias en Estado Crítico (≥70%):</strong>
                                    <span class="badge bg-danger"><?= $criticas ?></span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Materias de Alto Riesgo (50-70%):</strong>
                                    <span class="badge bg-warning"><?= $altas ?></span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Promedio de Desaprobación:</strong>
                                    <span class="badge bg-info"><?= $promedioDesaprobacion ?>%</span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Total Analizado:</strong>
                                    <span class="badge bg-primary"><?= $totalMaterias ?> materias</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    
    <!-- Asistencia Avanzada -->
    <?php elseif ($tipoReporte == 'asistencia_avanzada'): ?>
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="card-title"><?= htmlspecialchars($titulo) ?></h5>
                <p class="card-text text-muted mb-0"><?= htmlspecialchars($descripcion) ?></p>
            </div>
            <div class="card-body">
                <!-- Gráficos de asistencia -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="text-primary">📊 Distribución de Asistencia por Estudiante</h6>
                            </div>
                            <div class="card-body">
                                <div style="height: 300px;">
                                    <canvas id="graficoAsistenciaEstudiantes"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="text-primary">📈 Estados de Regularidad</h6>
                            </div>
                            <div class="card-body">
                                <div style="height: 300px;">
                                    <canvas id="graficoRegularidad"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabla de asistencia -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead class="table-primary">
                            <tr>
                                <th>Estudiante</th>
                                <th class="text-center">Presentes</th>
                                <th class="text-center">Ausentes</th>
                                <th class="text-center">1/2 Faltas</th>
                                <th class="text-center">1/4 Faltas</th>
                                <th class="text-center">Total Faltas</th>
                                <th class="text-center">Justificadas</th>
                                <th class="text-center">% Asistencia</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Patrones de Riesgo</th>
                                <th class="text-center">Motivos Justificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reporteData as $row): ?>
                            <tr class="<?= $row['riesgo_abandono'] ? 'table-danger' : '' ?>">
                                <td>
                                    <strong><?= htmlspecialchars($row['apellido'] . ', ' . $row['nombre']) ?></strong>
                                    <br><small class="text-muted"><?= htmlspecialchars($row['dni']) ?></small>
                                    <?php if ($row['riesgo_abandono']): ?>
                                    <br><span class="badge bg-danger">
                                        <i class="bi bi-exclamation-triangle"></i> RIESGO ABANDONO
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= $row['presentes'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger"><?= $row['ausentes'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $row['medias_faltas_cantidad'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= $row['cuartos_faltas_cantidad'] ?></span>
                                </td>
                                <td class="text-center">
                                    <strong class="text-<?= $row['total_faltas_computables'] > 15 ? 'danger' : ($row['total_faltas_computables'] > 10 ? 'warning' : 'success') ?>">
                                        <?= $row['total_faltas_computables'] ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info"><?= $row['justificadas'] ?></span>
                                </td>
                                <td class="text-center">
                                    <h6 class="text-<?= $row['porcentaje_asistencia'] >= 85 ? 'success' : ($row['porcentaje_asistencia'] >= 75 ? 'warning' : 'danger') ?>">
                                        <?= $row['porcentaje_asistencia'] ?>%
                                    </h6>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-<?= $row['porcentaje_asistencia'] >= 85 ? 'success' : ($row['porcentaje_asistencia'] >= 75 ? 'warning' : 'danger') ?>" 
                                             style="width: <?= $row['porcentaje_asistencia'] ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $row['clase_estado'] ?>">
                                        <?= $row['estado_regularidad'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['patron_ausencias_lunes_viernes']): ?>
                                    <span class="badge bg-warning mb-1">
                                        <i class="bi bi-calendar-x"></i> Patrón L-V
                                    </span>
                                    <br>
                                    <?php endif; ?>
                                    <?php if ($row['ausencias_frecuentes']): ?>
                                    <span class="badge bg-danger">
                                        <i class="bi bi-exclamation"></i> Frecuentes
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!$row['patron_ausencias_lunes_viernes'] && !$row['ausencias_frecuentes']): ?>
                                    <span class="text-success">
                                        <i class="bi bi-check-circle"></i> Normal
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars($row['motivos_justificaciones_texto']) ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Resumen estadístico de asistencia -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> Resumen de Regularidad y Patrones</h6>
                            <?php
                            $regulares = count(array_filter($reporteData, function($r) { return $r['estado_regularidad'] === 'Regular'; }));
                            $enRiesgo = count(array_filter($reporteData, function($r) { return $r['estado_regularidad'] === 'En Riesgo'; }));
                            $libres = count(array_filter($reporteData, function($r) { return $r['estado_regularidad'] === 'Libre'; }));
                            $patronLV = count(array_filter($reporteData, function($r) { return $r['patron_ausencias_lunes_viernes']; }));
                            $riesgoAbandono = count(array_filter($reporteData, function($r) { return $r['riesgo_abandono']; }));
                            $total = count($reporteData);
                            ?>
                            <div class="row">
                                <div class="col-md-2">
                                    <strong>Regulares (≥85%):</strong>
                                    <span class="badge bg-success"><?= $regulares ?></span>
                                    (<?= $total > 0 ? round(($regulares / $total) * 100, 1) : 0 ?>%)
                                </div>
                                <div class="col-md-2">
                                    <strong>En Riesgo (75-85%):</strong>
                                    <span class="badge bg-warning"><?= $enRiesgo ?></span>
                                    (<?= $total > 0 ? round(($enRiesgo / $total) * 100, 1) : 0 ?>%)
                                </div>
                                <div class="col-md-2">
                                    <strong>Libres (<75%):</strong>
                                    <span class="badge bg-danger"><?= $libres ?></span>
                                    (<?= $total > 0 ? round(($libres / $total) * 100, 1) : 0 ?>%)
                                </div>
                                <div class="col-md-3">
                                    <strong>Patrón Lunes-Viernes:</strong>
                                    <span class="badge bg-warning"><?= $patronLV ?></span>
                                    estudiantes
                                </div>
                                <div class="col-md-3">
                                    <strong>Riesgo de Abandono:</strong>
                                    <span class="badge bg-danger"><?= $riesgoAbandono ?></span>
                                    estudiantes
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    
    <?php endif; ?>
    
    <?php elseif ($tipoReporte && empty($reporteData) && $cicloLectivoId > 0 && empty($page_error_message)): ?>
        <div class="alert alert-warning mt-4">
            <i class="bi bi-exclamation-triangle"></i> No se encontraron datos para el análisis '<?= htmlspecialchars($titulo) ?>' con los filtros seleccionados.
            <?php if (!empty($descripcion)): ?><br><small><?= htmlspecialchars($descripcion) ?></small><?php endif; ?>
        </div>
    <?php elseif (empty($tipoReporte) && $cicloLectivoId > 0 && empty($page_error_message)): ?>
        <!-- Panel de información de tipos de reportes -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle"></i> Tipos de Análisis Disponibles
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                            <div class="col">
                                <div class="card h-100 shadow-sm border-primary">
                                    <div class="card-body">
                                        <h6 class="card-title text-primary">
                                            <i class="bi bi-graph-up"></i> Rendimiento Avanzado por Curso
                                        </h6>
                                        <p class="card-text small">
                                            Análisis detallado del rendimiento académico por materia, incluyendo equipos docentes, subgrupos, estadísticas avanzadas y análisis de riesgo.
                                        </p>
                                        <div class="badge bg-primary">Nuevo</div>
                                        <div class="badge bg-success">Gráficos</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col">
                                <div class="card h-100 shadow-sm border-warning">
                                    <div class="card-body">
                                        <h6 class="card-title text-warning">
                                            <i class="bi bi-exclamation-triangle"></i> Materias con Mayor Desaprobación
                                        </h6>
                                        <p class="card-text small">
                                            Ranking de materias problemáticas con más del 30% de desaprobación. Incluye análisis de causas y niveles de alerta.
                                        </p>
                                        <div class="badge bg-warning">Alerta</div>
                                        <div class="badge bg-info">Ranking</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col">
                                <div class="card h-100 shadow-sm border-info">
                                    <div class="card-body">
                                        <h6 class="card-title text-info">
                                            <i class="bi bi-calendar-check"></i> Análisis Avanzado de Asistencia
                                        </h6>
                                        <p class="card-text small">
                                            Análisis de patrones de asistencia con detección de comportamientos de riesgo, incluyendo análisis de días específicos y predicción de abandono.
                                        </p>
                                        <div class="badge bg-info">Patrones</div>
                                        <div class="badge bg-danger">Riesgo</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col">
                                <div class="card h-100 shadow-sm border-success">
                                    <div class="card-body">
                                        <h6 class="card-title text-success">
                                            <i class="bi bi-speedometer"></i> Dashboard General del Sistema
                                        </h6>
                                        <p class="card-text small">
                                            Vista panorámica del sistema educativo con estadísticas de cursos, estudiantes, profesores, equipos docentes y subgrupos.
                                        </p>
                                        <div class="badge bg-success">Dashboard</div>
                                        <div class="badge bg-primary">General</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col">
                                <div class="card h-100 shadow-sm border-secondary">
                                    <div class="card-body">
                                        <h6 class="card-title text-secondary">
                                            <i class="bi bi-journal-bookmark"></i> Reportes Clásicos
                                        </h6>
                                        <p class="card-text small">
                                            Acceso a los reportes tradicionales del sistema para compatibilidad con procesos existentes.
                                        </p>
                                        <div class="badge bg-secondary">Clásico</div>
                                        <div class="badge bg-light text-dark">Compatibilidad</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col">
                                <div class="card h-100 shadow-sm border-dark">
                                    <div class="card-body">
                                        <h6 class="card-title text-dark">
                                            <i class="bi bi-gear"></i> Configuración y Exportación
                                        </h6>
                                        <p class="card-text small">
                                            Todas las opciones de análisis incluyen exportación a Excel, PDF e impresión directa para facilitar el trabajo administrativo.
                                        </p>
                                        <div class="badge bg-dark">Export</div>
                                        <div class="badge bg-secondary">PDF/Excel</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-light mt-4">
                            <h6><i class="bi bi-lightbulb"></i> Características del Sistema Mejorado</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Detección automática de períodos</strong></li>
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Soporte para equipos docentes</strong></li>
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Análisis de subgrupos de estudiantes</strong></li>
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Gráficos interactivos avanzados</strong></li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Análisis de patrones de riesgo</strong></li>
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Estadísticas en tiempo real</strong></li>
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Exportación mejorada</strong></li>
                                        <li><i class="bi bi-check-circle text-success"></i> <strong>Interface responsive y moderna</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Estilos CSS adicionales -->
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

.pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
    100% {
        transform: scale(1);
    }
}

.card-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.table-info {
    background-color: rgba(13, 110, 253, 0.1);
}

@media print {
    .btn, .form-control, .form-select {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000 !important;
        break-inside: avoid;
    }
}
</style>

<!-- Scripts JavaScript mejorados -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function ajustarCamposAvanzados() {
    var tipoReporte = document.getElementById('tipo_reporte').value;
    var campoCurso = document.getElementById('campo_curso_avanzado');
    var campoCuatrimestre = document.getElementById('campo_cuatrimestre_avanzado');
    
    var selCurso = document.getElementById('curso');
    var selCuatrimestre = document.getElementById('cuatrimestre');

    // Ocultar todos los campos primero
    if(campoCurso) campoCurso.style.display = 'none';
    if(selCurso) selCurso.required = false;
    if(campoCuatrimestre) campoCuatrimestre.style.display = 'none';

    switch (tipoReporte) {
        case 'rendimiento_curso_avanzado':
        case 'asistencia_avanzada':
            if(campoCurso) campoCurso.style.display = 'block';
            if(selCurso) selCurso.required = true;
            if(campoCuatrimestre) campoCuatrimestre.style.display = 'block';
            break;
        case 'materias_problematicas':
        case 'dashboard_general':
            // No requieren parámetros adicionales
            break;
    }
}

function cargarMateriasAvanzadas() {
    // Función placeholder para cargar materias dinámicamente si se necesita
    console.log('Cargando materias para el curso seleccionado...');
}

function imprimirReporte() {
    window.print();
}

function mostrarPlanMejora(materiaCursoId) {
    alert('Plan de mejora para materia ID: ' + materiaCursoId + '\n\nFuncionalidad en desarrollo.');
}

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('tipo_reporte')) {
        ajustarCamposAvanzados(); 
    }

    // Configuración común para todos los gráficos
    Chart.defaults.font.family = 'Arial, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.plugins.legend.position = 'top';
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;

    <?php if ($tipoReporte == 'dashboard_general' && !empty($reporteData) && empty($page_error_message)): ?>
    // Gráfico de estudiantes por curso
    const ctxEstudiantes = document.getElementById('graficoEstudiantesPorCurso');
    if (ctxEstudiantes) {
        const labelsEstudiantes = [];
        const dataEstudiantes = [];
        const coloresEstudiantes = [];
        
        <?php foreach ($reporteData['cursos'] as $curso): ?>
            labelsEstudiantes.push('<?= htmlspecialchars(addslashes($curso['curso']), ENT_QUOTES) ?>');
            dataEstudiantes.push(<?= $curso['total_estudiantes'] ?>);
            coloresEstudiantes.push('rgba(' + Math.floor(Math.random() * 255) + ',' + Math.floor(Math.random() * 255) + ',' + Math.floor(Math.random() * 255) + ',0.7)');
        <?php endforeach; ?>
        
        new Chart(ctxEstudiantes, {
            type: 'bar',
            data: {
                labels: labelsEstudiantes,
                datasets: [{
                    label: 'Estudiantes',
                    data: dataEstudiantes,
                    backgroundColor: coloresEstudiantes,
                    borderColor: coloresEstudiantes.map(color => color.replace('0.7', '1')),
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    title: {
                        display: true,
                        text: 'Distribución de Estudiantes por Curso'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }
    
    // Gráfico de materias por tipo
    const ctxMaterias = document.getElementById('graficoMateriasTipo');
    if (ctxMaterias) {
        const totalMaterias = <?= $reporteData['resumen']['total_materias'] ?>;
        const materiasSubgrupos = <?= $reporteData['resumen']['total_materias_subgrupos'] ?>;
        const materiasEquipo = <?= $reporteData['resumen']['total_materias_equipo'] ?>;
        const materiasNormales = totalMaterias - materiasSubgrupos - materiasEquipo;
        
        new Chart(ctxMaterias, {
            type: 'doughnut',
            data: {
                labels: ['Normales', 'Con Subgrupos', 'En Equipo'],
                datasets: [{
                    data: [materiasNormales, materiasSubgrupos, materiasEquipo],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(0, 123, 255, 0.8)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    title: {
                        display: true,
                        text: 'Tipos de Materias'
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($tipoReporte == 'rendimiento_curso_avanzado' && !empty($reporteData) && empty($page_error_message)): ?>
    // Gráfico de rendimiento avanzado
    const ctxRendimiento = document.getElementById('graficoRendimientoAvanzado');
    if (ctxRendimiento) {
        const labels = [];
        const aprobados = [];
        const desaprobados = [];
        const sinCalificar = [];
        
        <?php foreach ($reporteData as $row): ?>
            labels.push('<?= htmlspecialchars(addslashes($row['materia']), ENT_QUOTES) ?>');
            aprobados.push(<?= $row['aprobados'] ?>);
            desaprobados.push(<?= $row['desaprobados'] ?>);
            sinCalificar.push(<?= $row['sin_calificar'] ?>);
        <?php endforeach; ?>
        
        new Chart(ctxRendimiento, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Aprobados',
                        data: aprobados,
                        backgroundColor: 'rgba(40, 167, 69, 0.8)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2
                    },
                    {
                        label: 'Desaprobados',
                        data: desaprobados,
                        backgroundColor: 'rgba(220, 53, 69, 0.8)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 2
                    },
                    {
                        label: 'Sin Calificar',
                        data: sinCalificar,
                        backgroundColor: 'rgba(255, 193, 7, 0.8)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 2
                    }
                ]
            },
            options: {
                plugins: {
                    title: {
                        display: true,
                        text: 'Análisis de Rendimiento por Materia'
                    }
                },
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($tipoReporte == 'materias_problematicas' && !empty($reporteData) && empty($page_error_message)): ?>
    // Gráfico de materias problemáticas
    const ctxProblematicas = document.getElementById('graficoMateriasProblematicas');
    if (ctxProblematicas) {
        const labels = [];
        const porcentajes = [];
        const colores = [];
        
        <?php foreach ($reporteData as $index => $row): ?>
            labels.push('<?= htmlspecialchars(addslashes($row['materia']), ENT_QUOTES) ?>');
            porcentajes.push(<?= $row['porcentaje_desaprobacion'] ?>);
            <?php if ($row['porcentaje_desaprobacion'] >= 70): ?>
                colores.push('rgba(220, 53, 69, 0.8)'); // Crítico
            <?php elseif ($row['porcentaje_desaprobacion'] >= 50): ?>
                colores.push('rgba(255, 193, 7, 0.8)'); // Alto
            <?php else: ?>
                colores.push('rgba(0, 123, 255, 0.8)'); // Moderado
            <?php endif; ?>
        <?php endforeach; ?>
        
        new Chart(ctxProblematicas, {
            type: 'horizontalBar',
            data: {
                labels: labels,
                datasets: [{
                    label: '% Desaprobación',
                    data: porcentajes,
                    backgroundColor: colores,
                    borderColor: colores.map(color => color.replace('0.8', '1')),
                    borderWidth: 2
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: {
                    title: {
                        display: true,
                        text: 'Ranking de Materias con Mayor Desaprobación'
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($tipoReporte == 'asistencia_avanzada' && !empty($reporteData) && empty($page_error_message)): ?>
    // Gráfico de asistencia por estudiantes (top 10)
    const ctxAsistencia = document.getElementById('graficoAsistenciaEstudiantes');
    if (ctxAsistencia) {
        const labels = [];
        const porcentajes = [];
        const colores = [];
        
        // Tomar solo los primeros 10 estudiantes para mejor visualización
        const estudiantesTop = <?= json_encode(array_slice($reporteData, 0, 10)) ?>;
        
        estudiantesTop.forEach(estudiante => {
            labels.push(estudiante.apellido + ', ' + estudiante.nombre);
            porcentajes.push(parseFloat(estudiante.porcentaje_asistencia));
            
            if (estudiante.porcentaje_asistencia >= 85) {
                colores.push('rgba(40, 167, 69, 0.8)');
            } else if (estudiante.porcentaje_asistencia >= 75) {
                colores.push('rgba(255, 193, 7, 0.8)');
            } else {
                colores.push('rgba(220, 53, 69, 0.8)');
            }
        });
        
        new Chart(ctxAsistencia, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: '% Asistencia',
                    data: porcentajes,
                    backgroundColor: colores,
                    borderColor: colores.map(color => color.replace('0.8', '1')),
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    title: {
                        display: true,
                        text: 'Porcentaje de Asistencia por Estudiante (Top 10)'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45
                        }
                    }
                }
            }
        });
    }
    
    // Gráfico de regularidad
    const ctxRegularidad = document.getElementById('graficoRegularidad');
    if (ctxRegularidad) {
        <?php
        $regulares = count(array_filter($reporteData, function($r) { return $r['estado_regularidad'] === 'Regular'; }));
        $enRiesgo = count(array_filter($reporteData, function($r) { return $r['estado_regularidad'] === 'En Riesgo'; }));
        $libres = count(array_filter($reporteData, function($r) { return $r['estado_regularidad'] === 'Libre'; }));
        ?>
        
        new Chart(ctxRegularidad, {
            type: 'pie',
            data: {
                labels: ['Regular (≥85%)', 'En Riesgo (75-85%)', 'Libre (<75%)'],
                datasets: [{
                    data: [<?= $regulares ?>, <?= $enRiesgo ?>, <?= $libres ?>],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    title: {
                        display: true,
                        text: 'Estados de Regularidad'
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    <?php endif; ?>
});

console.log('Sistema de reportes mejorado cargado correctamente');
</script>

<?php require_once 'footer.php'; ?>
