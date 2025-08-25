<?php
/**
 * generar_reporte_excel.php - Generación de reportes en Excel
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para tener acceso a la clase Database
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar permisos (solo admin, directivos y profesores)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    $_SESSION['message'] = 'No tiene permisos para exportar reportes';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetros
if (!isset($_GET['tipo_reporte'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el reporte';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Obtener parámetros
$tipoReporte = $_GET['tipo_reporte'];
$cursoId = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$materiaId = isset($_GET['materia']) ? intval($_GET['materia']) : 0;
$cuatrimestre = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 0;

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: reportes.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Variables para almacenar datos del reporte
$reporteData = [];
$titulo = '';
$descripcion = '';

// Generar reporte según el tipo seleccionado
try {
    switch ($tipoReporte) {
        case 'rendimiento_curso':
            generarReporteRendimientoCurso($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'rendimiento_materia':
            generarReporteRendimientoMateria($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'valoraciones_preliminares':
            generarReporteValoracionesPreliminares($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'asistencia':
            generarReporteAsistencia($db, $cursoId, $cuatrimestre, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'intensificacion':
            generarReporteIntensificacion($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        case 'global':
            generarReporteGlobal($db, $cicloLectivoId, $reporteData, $titulo, $descripcion, $anioActivo);
            break;
            
        default:
            throw new Exception('Tipo de reporte no válido');
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al generar el reporte: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Verificar si hay datos para el reporte
if (empty($reporteData)) {
    $_SESSION['message'] = 'No hay datos para generar el reporte seleccionado';
    $_SESSION['message_type'] = 'warning';
    header('Location: reportes.php');
    exit;
}

/**
 * Función para generar reporte de rendimiento por curso
 */
function generarReporteRendimientoCurso($db, $cursoId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        throw new Exception('Debe seleccionar un curso');
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        throw new Exception('Curso no encontrado');
    }

    $campoCalificacion = 'c.calificacion_final'; // Por defecto, calificación final
    $filtroCuatrimestreDesc = "ambos cuatrimestres (final)";
    if ($cuatrimestre == 1) {
        $campoCalificacion = 'c.calificacion_1c';
        $filtroCuatrimestreDesc = "el 1° cuatrimestre";
    } elseif ($cuatrimestre == 2) {
        $campoCalificacion = 'c.calificacion_2c';
        $filtroCuatrimestreDesc = "el 2° cuatrimestre";
    }
    
    $reporteData = $db->fetchAll(
        "SELECT m.nombre as materia, m.codigo, 
                COUNT(c.id) as total_estudiantes,
                SUM(CASE WHEN $campoCalificacion >= 4 THEN 1 ELSE 0 END) as aprobados,
                SUM(CASE WHEN $campoCalificacion < 4 AND $campoCalificacion IS NOT NULL THEN 1 ELSE 0 END) as desaprobados,
                ROUND(AVG(CASE WHEN $campoCalificacion IS NOT NULL AND $campoCalificacion <> '' THEN $campoCalificacion ELSE NULL END), 2) as promedio
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id AND c.ciclo_lectivo_id = ?
         WHERE mp.curso_id = ?
         GROUP BY m.id, m.nombre, m.codigo
         ORDER BY m.nombre",
        [$cicloLectivoId, $cursoId]
    );
    
    $titulo = "Rendimiento por Materias - " . $cursoInfo['nombre'];
    $descripcion = "Rendimiento de estudiantes en cada materia del curso " . $cursoInfo['nombre'] . 
                   " durante " . $filtroCuatrimestreDesc . " del ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte de rendimiento por materia
 */
function generarReporteRendimientoMateria($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0 || $materiaId == 0) {
        throw new Exception('Debe seleccionar un curso y una materia');
    }
    
    // Obtener información del curso y materia
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    $materiaInfo = $db->fetchOne("SELECT m.nombre, m.codigo, mp.id as materia_curso_id 
                                 FROM materias m 
                                 JOIN materias_por_curso mp ON m.id = mp.materia_id 
                                 WHERE m.id = ? AND mp.curso_id = ? 
                                 LIMIT 1", 
                                [$materiaId, $cursoId]);
    
    if (!$cursoInfo || !$materiaInfo) {
        throw new Exception('Curso o materia no encontrados');
    }
    
    $materiaCursoId = $materiaInfo['materia_curso_id'];
    
    $reporteData = $db->fetchAll(
        "SELECT u.nombre, u.apellido, u.dni, c.valoracion_preliminar_1c, c.calificacion_1c, 
                c.valoracion_preliminar_2c, c.calificacion_2c, c.intensificacion_1c, 
                c.calificacion_final, c.tipo_cursada
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         LEFT JOIN calificaciones c ON u.id = c.estudiante_id AND c.materia_curso_id = ? AND c.ciclo_lectivo_id = ?
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         ORDER BY u.apellido, u.nombre",
        [$materiaCursoId, $cicloLectivoId, $cursoId]
    );
    
    $titulo = "Rendimiento en " . $materiaInfo['nombre'] . " (" . $materiaInfo['codigo'] . ")";
    $descripcion = "Calificaciones de estudiantes en la materia " . $materiaInfo['nombre'] . 
                   " del curso " . $cursoInfo['nombre'] . " - Ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte de valoraciones preliminares
 */
function generarReporteValoracionesPreliminares($db, $cursoId, $materiaId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        throw new Exception('Debe seleccionar un curso');
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        throw new Exception('Curso no encontrado');
    }

    // Determinar qué campo de valoración usar según el cuatrimestre
    $campoValoracion = 'c.valoracion_preliminar_1c'; // Por defecto, 1er cuatrimestre
    $filtroCuatrimestreDesc = "el 1° cuatrimestre";
    
    if ($cuatrimestre == 2) {
        $campoValoracion = 'c.valoracion_preliminar_2c';
        $filtroCuatrimestreDesc = "el 2° cuatrimestre";
    }
    
    // Construir la consulta base
    $sqlBase = "SELECT m.nombre as materia, m.codigo,
                       COUNT(c.id) as total_estudiantes,
                       SUM(CASE WHEN $campoValoracion = 'TEA' THEN 1 ELSE 0 END) as tea,
                       SUM(CASE WHEN $campoValoracion = 'TEP' THEN 1 ELSE 0 END) as tep,
                       SUM(CASE WHEN $campoValoracion = 'TED' THEN 1 ELSE 0 END) as ted,
                       SUM(CASE WHEN $campoValoracion IS NULL OR $campoValoracion = '' THEN 1 ELSE 0 END) as sin_valoracion
                FROM materias_por_curso mp
                JOIN materias m ON mp.materia_id = m.id
                LEFT JOIN calificaciones c ON mp.id = c.materia_curso_id AND c.ciclo_lectivo_id = ?
                WHERE mp.curso_id = ?";
    
    $parametros = [$cicloLectivoId, $cursoId];
    
    // Filtrar por materia si se especificó
    if ($materiaId > 0) {
        $sqlBase .= " AND m.id = ?";
        $parametros[] = $materiaId;
        
        // Obtener información de la materia
        $materiaInfo = $db->fetchOne("SELECT nombre, codigo FROM materias WHERE id = ?", [$materiaId]);
        if ($materiaInfo) {
            $titulo = "Valoraciones Preliminares - " . $materiaInfo['nombre'] . " (" . $materiaInfo['codigo'] . ")";
            $descripcion = "Distribución de valoraciones preliminares en " . $materiaInfo['nombre'] . 
                           " del curso " . $cursoInfo['nombre'] . " durante " . $filtroCuatrimestreDesc . 
                           " - Ciclo lectivo " . $anioActivo . ".";
        }
    } else {
        $titulo = "Valoraciones Preliminares - " . $cursoInfo['nombre'];
        $descripcion = "Distribución de valoraciones preliminares por materia del curso " . $cursoInfo['nombre'] . 
                       " durante " . $filtroCuatrimestreDesc . " - Ciclo lectivo " . $anioActivo . ".";
    }
    
    $sqlBase .= " GROUP BY m.id, m.nombre, m.codigo ORDER BY m.nombre";
    
    $reporteData = $db->fetchAll($sqlBase, $parametros);
}

/**
 * Función para generar reporte de asistencia
 */
function generarReporteAsistencia($db, $cursoId, $cuatrimestre, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    if ($cursoId == 0) {
        throw new Exception('Debe seleccionar un curso');
    }
    
    $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoId]);
    if (!$cursoInfo) {
        throw new Exception('Curso no encontrado');
    }

    // Construir cláusula WHERE para el cuatrimestre
    $whereClause = "a.curso_id = ?";
    $parametros = [$cursoId];
    
    if ($cuatrimestre > 0) {
        $whereClause .= " AND a.cuatrimestre = ?";
        $parametros[] = $cuatrimestre;
        $filtroCuatrimestreDesc = $cuatrimestre == 1 ? "el 1° cuatrimestre" : "el 2° cuatrimestre";
    } else {
        $filtroCuatrimestreDesc = "todo el ciclo lectivo";
    }
    
    // Consulta para obtener asistencias por estudiante
    $reporteData = $db->fetchAll(
        "SELECT u.nombre, u.apellido, u.dni,
                COUNT(*) as dias_totales,
                SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
                SUM(CASE WHEN a.estado = 'media_falta' THEN 1 ELSE 0 END) as medias_faltas_cantidad,
                SUM(CASE WHEN a.estado = 'media_falta' THEN 0.5 ELSE 0 END) as medias_faltas,
                SUM(CASE WHEN a.estado = 'justificada' THEN 1 ELSE 0 END) as justificadas
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         LEFT JOIN asistencias a ON u.id = a.estudiante_id AND $whereClause
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
         GROUP BY u.id, u.nombre, u.apellido, u.dni
         ORDER BY u.apellido, u.nombre",
        array_merge($parametros, [$cursoId])
    );
    
    // Calcular porcentajes y totales
    foreach ($reporteData as &$estudiante) {
        if ($estudiante['dias_totales'] > 0) {
            $totalFaltas = $estudiante['ausentes'] + $estudiante['medias_faltas'];
            $estudiante['total_faltas_computables'] = $totalFaltas;
            $estudiante['porcentaje_asistencia'] = round(($estudiante['presentes'] / $estudiante['dias_totales']) * 100, 2);
        } else {
            $estudiante['total_faltas_computables'] = 0;
            $estudiante['porcentaje_asistencia'] = 0;
        }
    }
    
    $titulo = "Reporte de Asistencia - " . $cursoInfo['nombre'];
    $descripcion = "Estadísticas de asistencia del curso " . $cursoInfo['nombre'] . 
                   " durante " . $filtroCuatrimestreDesc . " - Ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte de intensificación
 */
function generarReporteIntensificacion($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    // Consulta para obtener estadísticas de intensificación
    $reporteData = $db->fetchAll(
        "SELECT c.nombre as curso_nombre, m.nombre as materia, m.codigo,
                COUNT(i.id) as total_estudiantes_intensificacion,
                SUM(CASE WHEN i.calificacion_final >= 4 THEN 1 ELSE 0 END) as aprobados_intensificacion,
                SUM(CASE WHEN i.calificacion_final < 4 OR i.calificacion_final IS NULL THEN 1 ELSE 0 END) as pendientes_intensificacion
         FROM intensificaciones i
         JOIN materias m ON i.materia_id = m.id
         JOIN cursos c ON i.ciclo_lectivo_id = c.ciclo_lectivo_id
         WHERE i.ciclo_lectivo_id = ?
         GROUP BY c.id, c.nombre, m.id, m.nombre, m.codigo
         ORDER BY c.nombre, m.nombre",
        [$cicloLectivoId]
    );
    
    $titulo = "Reporte de Intensificación - Ciclo Lectivo " . $anioActivo;
    $descripcion = "Estadísticas de estudiantes en intensificación por materia y curso - Ciclo lectivo " . $anioActivo . ".";
}

/**
 * Función para generar reporte global
 */
function generarReporteGlobal($db, $cicloLectivoId, &$reporteData, &$titulo, &$descripcion, $anioActivo) {
    // Consulta para obtener estadísticas globales por curso
    $reporteData = $db->fetchAll(
        "SELECT c.nombre as curso, c.anio,
                (SELECT COUNT(*) FROM matriculas m WHERE m.curso_id = c.id AND m.estado = 'activo') as matriculados,
                (SELECT COUNT(DISTINCT cal.estudiante_id) 
                 FROM calificaciones cal 
                 JOIN materias_por_curso mp ON cal.materia_curso_id = mp.id 
                 WHERE mp.curso_id = c.id AND cal.ciclo_lectivo_id = ? AND cal.calificacion_final >= 4) as aprobados_total_estudiantes,
                (SELECT COUNT(DISTINCT cal.estudiante_id) 
                 FROM calificaciones cal 
                 JOIN materias_por_curso mp ON cal.materia_curso_id = mp.id 
                 WHERE mp.curso_id = c.id AND cal.ciclo_lectivo_id = ? AND cal.calificacion_final < 4 AND cal.calificacion_final IS NOT NULL) as desaprobados_total_estudiantes,
                (SELECT COUNT(DISTINCT i.estudiante_id) 
                 FROM intensificaciones i 
                 JOIN usuarios u ON i.estudiante_id = u.id
                 JOIN matriculas m ON u.id = m.estudiante_id
                 WHERE m.curso_id = c.id AND i.ciclo_lectivo_id = ?) as en_intensificacion_estudiantes
         FROM cursos c
         WHERE c.ciclo_lectivo_id = ?
         ORDER BY c.anio, c.nombre",
        [$cicloLectivoId, $cicloLectivoId, $cicloLectivoId, $cicloLectivoId]
    );
    
    $titulo = "Reporte Global - Ciclo Lectivo " . $anioActivo;
    $descripcion = "Estadísticas generales de rendimiento académico por curso - Ciclo lectivo " . $anioActivo . ".";
}

// Generar archivo Excel
generarExcel($reporteData, $titulo, $descripcion, $tipoReporte);

/**
 * Función para generar archivo Excel
 */
function generarExcel($data, $titulo, $descripcion, $tipoReporte) {
    // Limpiar el buffer de salida para asegurarnos de que no haya nada antes del Excel
    ob_clean();
    
    // Cabeceras para forzar la descarga del archivo Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="' . formatearNombreArchivo($titulo) . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Crear tabla HTML que Excel puede interpretar
    echo '<!DOCTYPE html>';
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>' . htmlspecialchars($titulo) . '</title>';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th, td { border: 1px solid #ddd; padding: 8px; }';
    echo 'th { background-color: #f2f2f2; }';
    echo '.header { background-color: #4CAF50; color: white; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Encabezado del reporte
    echo '<h2>' . htmlspecialchars($titulo) . '</h2>';
    echo '<p>' . htmlspecialchars($descripcion) . '</p>';
    echo '<p>Fecha de generación: ' . date('d/m/Y H:i:s') . '</p>';
    
    // Tabla de datos según el tipo de reporte
    echo '<table>';
    
    switch ($tipoReporte) {
        case 'rendimiento_curso':
            // Cabecera de la tabla
            echo '<tr>';
            echo '<th>Materia (Código)</th>';
            echo '<th>Total Estudiantes</th>';
            echo '<th>Aprobados</th>';
            echo '<th>Desaprobados</th>';
            echo '<th>% Aprobación</th>';
            echo '<th>Promedio</th>';
            echo '</tr>';
            
            // Datos
            foreach ($data as $row) {
                $porcentajeAprobacion = ($row['total_estudiantes'] ?? 0) > 0 ?
                    round((($row['aprobados'] ?? 0) / $row['total_estudiantes']) * 100, 2) : 0;
                
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['materia'] ?? 'N/A') . ' (' . htmlspecialchars($row['codigo'] ?? 'N/A') . ')</td>';
                echo '<td>' . htmlspecialchars($row['total_estudiantes'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['aprobados'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['desaprobados'] ?? '0') . '</td>';
                echo '<td>' . $porcentajeAprobacion . '%</td>';
                echo '<td>' . (isset($row['promedio']) && is_numeric($row['promedio']) ? number_format($row['promedio'], 2) : '-') . '</td>';
                echo '</tr>';
            }
            break;
            
        case 'rendimiento_materia':
            // Cabecera de la tabla
            echo '<tr>';
            echo '<th>Estudiante (DNI)</th>';
            echo '<th>Cursada</th>';
            echo '<th>1° Val.</th>';
            echo '<th>1° Calif.</th>';
            echo '<th>2° Val.</th>';
            echo '<th>2° Calif.</th>';
            echo '<th>Intensif.</th>';
            echo '<th>Calif. Final</th>';
            echo '<th>Estado</th>';
            echo '</tr>';
            
            // Datos
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars(($row['apellido'] ?? '') . ', ' . ($row['nombre'] ?? '')) . ' (' . htmlspecialchars($row['dni'] ?? '') . ')</td>';
                echo '<td>' . htmlspecialchars($row['tipo_cursada'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['valoracion_preliminar_1c'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['calificacion_1c'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['valoracion_preliminar_2c'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['calificacion_2c'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['intensificacion_1c'] ?? '-') . '</td>';
                echo '<td>' . htmlspecialchars($row['calificacion_final'] ?? '-') . '</td>';
                
                // Estado
                if (isset($row['calificacion_final']) && $row['calificacion_final'] !== '') {
                    if ($row['calificacion_final'] >= 4) {
                        echo '<td>Aprobado</td>';
                    } else {
                        echo '<td>Pendiente</td>';
                    }
                } else {
                    echo '<td>Sin calificar</td>';
                }
                
                echo '</tr>';
            }
            break;
            
        case 'valoraciones_preliminares':
            // Cabecera de la tabla
            echo '<tr>';
            echo '<th>Materia (Código)</th>';
            echo '<th>Total Est.</th>';
            echo '<th>TEA</th>';
            echo '<th>TEP</th>';
            echo '<th>TED</th>';
            echo '<th>Sin Valorar</th>';
            echo '</tr>';
            
            // Datos
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['materia'] ?? 'N/A') . ' (' . htmlspecialchars($row['codigo'] ?? 'N/A') . ')</td>';
                echo '<td>' . htmlspecialchars($row['total_estudiantes'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['tea'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['tep'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['ted'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['sin_valoracion'] ?? '0') . '</td>';
                echo '</tr>';
            }
            break;
            
        case 'asistencia':
            // Cabecera de la tabla
            echo '<tr>';
            echo '<th>Estudiante (DNI)</th>';
            echo '<th>Presentes</th>';
            echo '<th>Ausentes</th>';
            echo '<th>Medias Faltas (Cant.)</th>';
            echo '<th>Total Faltas (Comp.)</th>';
            echo '<th>Justificadas</th>';
            echo '<th>% Asistencia</th>';
            echo '</tr>';
            
            // Datos
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars(($row['apellido'] ?? '') . ', ' . ($row['nombre'] ?? '')) . ' (' . htmlspecialchars($row['dni'] ?? '') . ')</td>';
                echo '<td>' . htmlspecialchars($row['presentes'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['ausentes'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['medias_faltas_cantidad'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['total_faltas_computables'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['justificadas'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['porcentaje_asistencia'] ?? '0') . '%</td>';
                echo '</tr>';
            }
            break;
            
        case 'intensificacion':
            // Cabecera de la tabla
            echo '<tr>';
            echo '<th>Curso</th>';
            echo '<th>Materia (Código)</th>';
            echo '<th>Total Est. Intensif.</th>';
            echo '<th>Aprobados Intensif.</th>';
            echo '<th>Pendientes Intensif.</th>';
            echo '</tr>';
            
            // Datos
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['curso_nombre'] ?? 'N/A') . '</td>';
                echo '<td>' . htmlspecialchars($row['materia'] ?? 'N/A') . ' (' . htmlspecialchars($row['codigo'] ?? 'N/A') . ')</td>';
                echo '<td>' . htmlspecialchars($row['total_estudiantes_intensificacion'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['aprobados_intensificacion'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['pendientes_intensificacion'] ?? '0') . '</td>';
                echo '</tr>';
            }
            break;
            
        case 'global':
            // Cabecera de la tabla
            echo '<tr>';
            echo '<th>Curso (Año)</th>';
            echo '<th>Matriculados</th>';
            echo '<th>Est. Aprobados</th>';
            echo '<th>Est. Desaprobados</th>';
            echo '<th>Est. en Intensificación</th>';
            echo '</tr>';
            
            // Datos
            foreach ($data as $row) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['curso'] ?? 'N/A') . ' (' . htmlspecialchars($row['anio'] ?? 'N/A') . ')</td>';
                echo '<td>' . htmlspecialchars($row['matriculados'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['aprobados_total_estudiantes'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['desaprobados_total_estudiantes'] ?? '0') . '</td>';
                echo '<td>' . htmlspecialchars($row['en_intensificacion_estudiantes'] ?? '0') . '</td>';
                echo '</tr>';
            }
            break;
    }
    
    echo '</table>';
    
    // Información adicional
    echo '<p>Reporte generado por el Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford</p>';
    
    echo '</body>';
    echo '</html>';
    
    exit;
}

/**
 * Función para formatear el nombre del archivo (eliminar caracteres especiales)
 */
function formatearNombreArchivo($texto) {
    // Reemplazar espacios por guiones
    $texto = str_replace(' ', '_', $texto);
    
    // Eliminar caracteres especiales
    $texto = preg_replace('/[^A-Za-z0-9\-_]/', '', $texto);
    
    // Añadir fecha
    $texto .= '_' . date('Y-m-d');
    
    return $texto;
}
?>