<?php
/**
 * index.php - Panel principal con permisos diferenciados por usuario
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Incluye gestión de subgrupos, estudiantes recursando y EQUIPOS DOCENTES
 * CORREGIDO: Lógica para determinar cuatrimestre actual basada en fechas reales
 */

// Incluir config.php antes que header.php si se necesita la base de datos
require_once 'config.php';

// Incluir el encabezado que ya tiene verificación de sesión
require_once 'header.php';

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Variables generales
$cicloActivo = null;
$cuatrimestreActual = 1;
$estadisticas = [];

try {
    // Obtener ciclo lectivo actual
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if ($cicloActivo) {
        // Determinar cuatrimestre actual basado en fechas reales del calendario académico
        $fechaActual = new DateTime();
        $año = $cicloActivo['anio'];
        
        // Definir fechas del calendario académico basado en el calendario mostrado en el index
        $fechas = [
            'inicio_1c' => new DateTime("$año-03-03"),      // 03/03
            'fin_1c' => new DateTime("$año-07-15"),          // 15/07
            'intensificacion_julio_inicio' => new DateTime("$año-07-16"), // 16/07
            'intensificacion_julio_fin' => new DateTime("$año-07-30"),    // 30/07
            'inicio_2c' => new DateTime("$año-08-01"),       // 01/08
            'fin_2c' => new DateTime("$año-12-07"),          // 07/12
            'intensificacion_dic_inicio' => new DateTime("$año-12-09"),   // 09/12
            'intensificacion_dic_fin' => new DateTime("$año-12-20"),     // 20/12
        ];
        
        // Determinar cuatrimestre actual
        if ($fechaActual >= $fechas['inicio_1c'] && $fechaActual <= $fechas['fin_1c']) {
            $cuatrimestreActual = 1;
        } elseif ($fechaActual >= $fechas['intensificacion_julio_inicio'] && $fechaActual <= $fechas['intensificacion_julio_fin']) {
            $cuatrimestreActual = 1; // Intensificación pertenece al 1° cuatrimestre
        } elseif ($fechaActual >= $fechas['inicio_2c'] && $fechaActual <= $fechas['fin_2c']) {
            $cuatrimestreActual = 2;
        } elseif ($fechaActual >= $fechas['intensificacion_dic_inicio'] && $fechaActual <= $fechas['intensificacion_dic_fin']) {
            $cuatrimestreActual = 2; // Intensificación pertenece al 2° cuatrimestre
        } elseif ($fechaActual < $fechas['inicio_1c']) {
            // Antes del inicio del ciclo lectivo
            $cuatrimestreActual = 1;
        } else {
            // Después del fin del ciclo (receso de verano)
            // Verificar si estamos en intensificación de febrero del año siguiente
            $intensificacion_feb_inicio = new DateTime(($año + 1) . "-02-10");
            $intensificacion_feb_fin = new DateTime(($año + 1) . "-02-28");
            
            if ($fechaActual >= $intensificacion_feb_inicio && $fechaActual <= $intensificacion_feb_fin) {
                $cuatrimestreActual = 2; // Intensificación de febrero pertenece al 2° cuatrimestre del año anterior
            } else {
                $cuatrimestreActual = 1; // Por defecto, preparándose para el próximo ciclo
            }
        }
    }
} catch (Exception $e) {
    // Manejar error silenciosamente
}

// Función auxiliar para obtener estudiantes de una materia (incluyendo subgrupos y recursados)
function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
    try {
        // Verificar si la materia requiere subgrupos
        $materiaInfo = $db->fetchOne(
            "SELECT mp.requiere_subgrupos, c.id as curso_id
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
                "SELECT DISTINCT u.id, 'subgrupo' as tipo_matricula
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
            // Materia normal - estudiantes regulares + recursando
            
            // 1. Estudiantes regulares del curso
            $estudiantesRegulares = $db->fetchAll(
                "SELECT DISTINCT u.id, 'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
                [$materiaInfo['curso_id']]
            );

            // 2. Estudiantes recursando esta materia específica
            $estudiantesRecursando = $db->fetchAll(
                "SELECT DISTINCT u.id, 'recursando' as tipo_matricula
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND mr.ciclo_lectivo_id = ? AND u.tipo = 'estudiante'",
                [$materiaCursoId, $cicloLectivoId]
            );

            // 3. Combinar ambos grupos
            $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
        }

        // 4. Filtrar estudiantes que tienen materias liberadas para recursado
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

// Obtener estadísticas según el tipo de usuario
switch ($_SESSION['user_type']) {
    case 'admin':
    case 'directivo':
        // Estadísticas completas para administradores y directivos
        try {
            $cicloId = $cicloActivo ? $cicloActivo['id'] : 0;
            
            // Estudiantes activos
            $estadisticas['total_estudiantes'] = $db->fetchOne("SELECT COUNT(*) as total FROM usuarios WHERE tipo = 'estudiante' AND activo = 1")['total'];
            
            // Profesores activos
            $estadisticas['total_profesores'] = $db->fetchOne("SELECT COUNT(*) as total FROM usuarios WHERE tipo = 'profesor' AND activo = 1")['total'];
            
            // Total de materias (todas las materias registradas)
            $estadisticas['total_materias'] = $db->fetchOne("SELECT COUNT(*) as total FROM materias")['total'];
            
            // Materias asignadas en el ciclo actual
            if ($cicloId > 0) {
                $estadisticas['materias_asignadas'] = $db->fetchOne(
                    "SELECT COUNT(*) as total FROM materias_por_curso mp 
                     JOIN cursos c ON mp.curso_id = c.id 
                     WHERE c.ciclo_lectivo_id = ?", 
                    [$cicloId]
                )['total'];
            } else {
                $estadisticas['materias_asignadas'] = 0;
            }
            
            // Cursos del ciclo actual
            $estadisticas['total_cursos'] = $cicloId ? $db->fetchOne("SELECT COUNT(*) as total FROM cursos WHERE ciclo_lectivo_id = ?", [$cicloId])['total'] : 0;
            
        } catch (Exception $e) {
            // Valores por defecto en caso de error
            $estadisticas = ['total_estudiantes' => 0, 'total_profesores' => 0, 'total_materias' => 0, 'materias_asignadas' => 0, 'total_cursos' => 0];
        }
        break;
        
    case 'profesor':
        // Estadísticas específicas para profesores (ACTUALIZADO CON EQUIPOS DOCENTES, SUBGRUPOS Y RECURSADOS)
        try {
            $profesorId = $_SESSION['user_id'];
            $cicloId = $cicloActivo ? $cicloActivo['id'] : 0;
            
            if ($cicloId > 0) {
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

                // Materias asignadas al profesor en el ciclo actual (INCLUYENDO EQUIPOS DOCENTES)
                $estadisticas['mis_materias'] = $db->fetchOne(
                    "SELECT COUNT(*) as total FROM materias_por_curso mp 
                     JOIN cursos c ON mp.curso_id = c.id 
                     WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                     AND c.ciclo_lectivo_id = ?", 
                    [$profesorId, $profesorId, $profesorId, $cicloId]
                )['total'];
                
                // Obtener todas las materias donde participa este profesor
                $materiasProfesor = $db->fetchAll(
                    "SELECT mp.id as materia_curso_id, mp.requiere_subgrupos
                     FROM materias_por_curso mp 
                     JOIN cursos c ON mp.curso_id = c.id 
                     WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                     AND c.ciclo_lectivo_id = ?", 
                    [$profesorId, $profesorId, $profesorId, $cicloId]
                );

                // Contar estudiantes totales usando la función auxiliar
                $totalEstudiantesUnicos = [];
                foreach ($materiasProfesor as $materia) {
                    $estudiantes = obtenerEstudiantesMateria($db, $materia['materia_curso_id'], $cicloId);
                    foreach ($estudiantes as $estudiante) {
                        $totalEstudiantesUnicos[$estudiante['id']] = true;
                    }
                }
                $estadisticas['mis_estudiantes'] = count($totalEstudiantesUnicos);
                
                // NUEVA LÓGICA: Calificaciones pendientes según el cuatrimestre actual (INCLUYENDO EQUIPOS DOCENTES)
                if ($cuatrimestreActual == 1) {
                    // PRIMER CUATRIMESTRE: verificar valoraciones preliminares del 1er cuatrimestre
                    $estadisticas['calificaciones_pendientes'] = $db->fetchOne(
                        "SELECT COUNT(*) as total FROM (
                            -- Estudiantes regulares sin valoración preliminar 1c
                            SELECT DISTINCT m.estudiante_id, mp.id as materia_curso_id
                            FROM materias_por_curso mp 
                            JOIN cursos c ON mp.curso_id = c.id 
                            JOIN matriculas m ON c.id = m.curso_id 
                            LEFT JOIN calificaciones cal ON m.estudiante_id = cal.estudiante_id 
                                AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                            WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                            AND c.ciclo_lectivo_id = ? AND m.estado = 'activo' 
                            AND (cal.valoracion_preliminar_1c IS NULL OR cal.valoracion_preliminar_1c = '')
                            
                            UNION
                            
                            -- Estudiantes en subgrupos sin valoración preliminar 1c
                            SELECT DISTINCT ep.estudiante_id, mp.id as materia_curso_id
                            FROM materias_por_curso mp
                            JOIN estudiantes_por_materia ep ON mp.id = ep.materia_curso_id
                            LEFT JOIN calificaciones cal ON ep.estudiante_id = cal.estudiante_id 
                                AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                            WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                            AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                            AND (cal.valoracion_preliminar_1c IS NULL OR cal.valoracion_preliminar_1c = '')
                            
                            UNION
                            
                            -- Estudiantes recursando sin valoración preliminar 1c
                            SELECT DISTINCT mr.estudiante_id, mp.id as materia_curso_id
                            FROM materias_por_curso mp
                            JOIN materias_recursado mr ON mp.id = mr.materia_curso_id
                            LEFT JOIN calificaciones cal ON mr.estudiante_id = cal.estudiante_id 
                                AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                            WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                            AND mr.ciclo_lectivo_id = ? AND mr.estado = 'activo'
                            AND (cal.valoracion_preliminar_1c IS NULL OR cal.valoracion_preliminar_1c = '')
                        ) as pendientes", 
                        [$cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes regulares
                         $cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes en subgrupos
                         $cicloId, $profesorId, $profesorId, $profesorId, $cicloId] // Estudiantes recursando
                    )['total'];
                    
                } else {
                    // SEGUNDO CUATRIMESTRE: verificar según la fecha actual
                    $fechaActual = new DateTime();
                    $año = $cicloActivo['anio'];
                    
                    // Fechas importantes del segundo cuatrimestre
                    $fechas = [
                        'valoracion_2bim' => new DateTime("$año-09-01"), // Fecha límite valoración preliminar 2c
                        'fin_2c' => new DateTime("$año-12-07"),          // Fin del segundo cuatrimestre
                    ];
                    
                    if ($fechaActual < $fechas['valoracion_2bim']) {
                        // Antes del 1° de septiembre: verificar valoraciones preliminares del 2° cuatrimestre
                        $estadisticas['calificaciones_pendientes'] = $db->fetchOne(
                            "SELECT COUNT(*) as total FROM (
                                -- Estudiantes regulares sin valoración preliminar 2c
                                SELECT DISTINCT m.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp 
                                JOIN cursos c ON mp.curso_id = c.id 
                                JOIN matriculas m ON c.id = m.curso_id 
                                LEFT JOIN calificaciones cal ON m.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND c.ciclo_lectivo_id = ? AND m.estado = 'activo' 
                                AND (cal.valoracion_preliminar_2c IS NULL OR cal.valoracion_preliminar_2c = '')
                                
                                UNION
                                
                                -- Estudiantes en subgrupos sin valoración preliminar 2c
                                SELECT DISTINCT ep.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp
                                JOIN estudiantes_por_materia ep ON mp.id = ep.materia_curso_id
                                LEFT JOIN calificaciones cal ON ep.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                                AND (cal.valoracion_preliminar_2c IS NULL OR cal.valoracion_preliminar_2c = '')
                                
                                UNION
                                
                                -- Estudiantes recursando sin valoración preliminar 2c
                                SELECT DISTINCT mr.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp
                                JOIN materias_recursado mr ON mp.id = mr.materia_curso_id
                                LEFT JOIN calificaciones cal ON mr.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND mr.ciclo_lectivo_id = ? AND mr.estado = 'activo'
                                AND (cal.valoracion_preliminar_2c IS NULL OR cal.valoracion_preliminar_2c = '')
                            ) as pendientes", 
                            [$cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes regulares
                             $cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes en subgrupos
                             $cicloId, $profesorId, $profesorId, $profesorId, $cicloId] // Estudiantes recursando
                        )['total'];
                        
                    } elseif ($fechaActual < $fechas['fin_2c']) {
                        // Después del 1° de septiembre pero antes del fin del cuatrimestre: verificar calificaciones cuatrimestrales
                        $estadisticas['calificaciones_pendientes'] = $db->fetchOne(
                            "SELECT COUNT(*) as total FROM (
                                -- Estudiantes regulares sin calificación del 2° cuatrimestre
                                SELECT DISTINCT m.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp 
                                JOIN cursos c ON mp.curso_id = c.id 
                                JOIN matriculas m ON c.id = m.curso_id 
                                LEFT JOIN calificaciones cal ON m.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND c.ciclo_lectivo_id = ? AND m.estado = 'activo' 
                                AND (cal.calificacion_2c IS NULL OR cal.calificacion_2c = '' OR cal.calificacion_2c = 0)
                                
                                UNION
                                
                                -- Estudiantes en subgrupos sin calificación del 2° cuatrimestre
                                SELECT DISTINCT ep.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp
                                JOIN estudiantes_por_materia ep ON mp.id = ep.materia_curso_id
                                LEFT JOIN calificaciones cal ON ep.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                                AND (cal.calificacion_2c IS NULL OR cal.calificacion_2c = '' OR cal.calificacion_2c = 0)
                                
                                UNION
                                
                                -- Estudiantes recursando sin calificación del 2° cuatrimestre
                                SELECT DISTINCT mr.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp
                                JOIN materias_recursado mr ON mp.id = mr.materia_curso_id
                                LEFT JOIN calificaciones cal ON mr.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND mr.ciclo_lectivo_id = ? AND mr.estado = 'activo'
                                AND (cal.calificacion_2c IS NULL OR cal.calificacion_2c = '' OR cal.calificacion_2c = 0)
                            ) as pendientes", 
                            [$cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes regulares
                             $cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes en subgrupos
                             $cicloId, $profesorId, $profesorId, $profesorId, $cicloId] // Estudiantes recursando
                        )['total'];
                        
                    } else {
                        // Después del fin del 2° cuatrimestre: verificar calificaciones finales
                        $estadisticas['calificaciones_pendientes'] = $db->fetchOne(
                            "SELECT COUNT(*) as total FROM (
                                -- Estudiantes regulares sin calificación final
                                SELECT DISTINCT m.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp 
                                JOIN cursos c ON mp.curso_id = c.id 
                                JOIN matriculas m ON c.id = m.curso_id 
                                LEFT JOIN calificaciones cal ON m.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND c.ciclo_lectivo_id = ? AND m.estado = 'activo' 
                                AND (cal.calificacion_final IS NULL OR cal.calificacion_final = '')
                                
                                UNION
                                
                                -- Estudiantes en subgrupos sin calificación final
                                SELECT DISTINCT ep.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp
                                JOIN estudiantes_por_materia ep ON mp.id = ep.materia_curso_id
                                LEFT JOIN calificaciones cal ON ep.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                                AND (cal.calificacion_final IS NULL OR cal.calificacion_final = '')
                                
                                UNION
                                
                                -- Estudiantes recursando sin calificación final
                                SELECT DISTINCT mr.estudiante_id, mp.id as materia_curso_id
                                FROM materias_por_curso mp
                                JOIN materias_recursado mr ON mp.id = mr.materia_curso_id
                                LEFT JOIN calificaciones cal ON mr.estudiante_id = cal.estudiante_id 
                                    AND mp.id = cal.materia_curso_id AND cal.ciclo_lectivo_id = ?
                                WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                                AND mr.ciclo_lectivo_id = ? AND mr.estado = 'activo'
                                AND (cal.calificacion_final IS NULL OR cal.calificacion_final = '')
                            ) as pendientes", 
                            [$cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes regulares
                             $cicloId, $profesorId, $profesorId, $profesorId, $cicloId, // Estudiantes en subgrupos
                             $cicloId, $profesorId, $profesorId, $profesorId, $cicloId] // Estudiantes recursando
                        )['total'];
                    }
                }
            } else {
                $estadisticas = ['mis_materias' => 0, 'mis_estudiantes' => 0, 'calificaciones_pendientes' => 0];
            }
            
        } catch (Exception $e) {
            $estadisticas = ['mis_materias' => 0, 'mis_estudiantes' => 0, 'calificaciones_pendientes' => 0];
        }
        break;
        
    case 'preceptor':
        // Estadísticas para preceptores
        try {
            $cicloId = $cicloActivo ? $cicloActivo['id'] : 0;
            
            // Estudiantes activos
            $estadisticas['total_estudiantes'] = $db->fetchOne("SELECT COUNT(*) as total FROM usuarios WHERE tipo = 'estudiante' AND activo = 1")['total'];
            
            // Cursos del ciclo actual
            $estadisticas['total_cursos'] = $cicloId ? $db->fetchOne("SELECT COUNT(*) as total FROM cursos WHERE ciclo_lectivo_id = ?", [$cicloId])['total'] : 0;
            
            // Asistencias registradas hoy
            $estadisticas['asistencias_hoy'] = $db->fetchOne(
                "SELECT COUNT(*) as total FROM asistencias WHERE DATE(fecha) = DATE('now')"
            )['total'];
            
            // Estudiantes matriculados en el ciclo actual
            if ($cicloId > 0) {
                $estadisticas['estudiantes_matriculados'] = $db->fetchOne(
                    "SELECT COUNT(DISTINCT m.estudiante_id) as total 
                     FROM matriculas m 
                     JOIN cursos c ON m.curso_id = c.id 
                     WHERE c.ciclo_lectivo_id = ? AND m.estado = 'activo'", 
                    [$cicloId]
                )['total'];
            } else {
                $estadisticas['estudiantes_matriculados'] = 0;
            }
            
        } catch (Exception $e) {
            $estadisticas = ['total_estudiantes' => 0, 'total_cursos' => 0, 'asistencias_hoy' => 0, 'estudiantes_matriculados' => 0];
        }
        break;
        
    case 'estudiante':
        // Estadísticas personales para estudiantes
        try {
            $estudianteId = $_SESSION['user_id'];
            $cicloId = $cicloActivo ? $cicloActivo['id'] : 0;
            
            if ($cicloId > 0) {
                // Información del curso del estudiante
                $cursoInfo = $db->fetchOne(
                    "SELECT c.nombre as curso_nombre, c.anio as curso_anio 
                     FROM matriculas m 
                     JOIN cursos c ON m.curso_id = c.id 
                     WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?", 
                    [$estudianteId, $cicloId]
                );
                
                $estadisticas['mi_curso'] = $cursoInfo ? $cursoInfo['curso_nombre'] : 'Sin matrícula';
                
                // Materias del estudiante (según su curso)
                if ($cursoInfo) {
                    $totalMaterias = $db->fetchOne(
                        "SELECT COUNT(*) as total 
                         FROM materias_por_curso mp 
                         JOIN cursos c ON mp.curso_id = c.id 
                         JOIN matriculas m ON c.id = m.curso_id 
                         WHERE m.estudiante_id = ? AND c.ciclo_lectivo_id = ? AND m.estado = 'activo'",
                        [$estudianteId, $cicloId]
                    )['total'];
                    
                    $estadisticas['total_materias'] = $totalMaterias;
                } else {
                    $estadisticas['total_materias'] = 0;
                }
                
                // Calificaciones del estudiante
                $calificaciones = $db->fetchAll(
                    "SELECT calificacion_final FROM calificaciones 
                     WHERE estudiante_id = ? AND ciclo_lectivo_id = ? AND calificacion_final IS NOT NULL AND calificacion_final != ''", 
                    [$estudianteId, $cicloId]
                );
                
                $materias_aprobadas = 0;
                $materias_desaprobadas = 0;
                $suma_notas = 0;
                $total_calificadas = count($calificaciones);
                
                foreach ($calificaciones as $calif) {
                    $nota = floatval($calif['calificacion_final']);
                    $suma_notas += $nota;
                    if ($nota >= 4) {
                        $materias_aprobadas++;
                    } else {
                        $materias_desaprobadas++;
                    }
                }
                
                $estadisticas['materias_aprobadas'] = $materias_aprobadas;
                $estadisticas['materias_desaprobadas'] = $materias_desaprobadas;
                $estadisticas['materias_sin_calificar'] = $estadisticas['total_materias'] - $total_calificadas;
                $estadisticas['promedio_general'] = $total_calificadas > 0 ? round($suma_notas / $total_calificadas, 2) : 0;
                
            } else {
                $estadisticas = ['mi_curso' => 'Sin ciclo activo', 'total_materias' => 0, 'materias_aprobadas' => 0, 'materias_desaprobadas' => 0, 'materias_sin_calificar' => 0, 'promedio_general' => 0];
            }
            
        } catch (Exception $e) {
            $estadisticas = ['mi_curso' => 'Error', 'total_materias' => 0, 'materias_aprobadas' => 0, 'materias_desaprobadas' => 0, 'materias_sin_calificar' => 0, 'promedio_general' => 0];
        }
        break;
}

// Función auxiliar para obtener el período actual con más detalles
function obtenerPeriodoActual($cicloActivo, $cuatrimestreActual) {
    if (!$cicloActivo) return "Sin ciclo activo";
    
    $fechaActual = new DateTime();
    $año = $cicloActivo['anio'];
    
    // Definir todas las fechas importantes
    $fechas = [
        'inicio_1c' => new DateTime("$año-03-03"),
        'valoracion_1bim' => new DateTime("$año-04-03"),
        'fin_1c' => new DateTime("$año-07-15"),
        'intensificacion_julio_inicio' => new DateTime("$año-07-16"),
        'intensificacion_julio_fin' => new DateTime("$año-07-30"),
        'inicio_2c' => new DateTime("$año-08-01"),
        'valoracion_2bim' => new DateTime("$año-09-01"),
        'fin_2c' => new DateTime("$año-12-07"),
        'intensificacion_dic_inicio' => new DateTime("$año-12-09"),
        'intensificacion_dic_fin' => new DateTime("$año-12-20"),
    ];
    
    $periodo = $cuatrimestreActual . "° cuatrimestre";
    
    // Agregar información específica del período
    if ($fechaActual >= $fechas['intensificacion_julio_inicio'] && $fechaActual <= $fechas['intensificacion_julio_fin']) {
        $periodo .= " (Intensificación Julio)";
    } elseif ($fechaActual >= $fechas['intensificacion_dic_inicio'] && $fechaActual <= $fechas['intensificacion_dic_fin']) {
        $periodo .= " (Intensificación Diciembre)";
    } elseif ($fechaActual >= new DateTime(($año + 1) . "-02-10") && $fechaActual <= new DateTime(($año + 1) . "-02-28")) {
        $periodo = "2° cuatrimestre (Intensificación Febrero)";
    }
    
    return $periodo;
}
?>

<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Bienvenido/a, <?= $_SESSION['user_name'] ?></h5>
                <p class="card-text">
                    <?php if ($_SESSION['user_type'] == 'estudiante'): ?>
                        Bienvenido al Sistema de Gestión de Calificaciones. Aquí puedes consultar tus calificaciones, 
                        ver información de tu curso y acceder a tus informes académicos.
                    <?php elseif ($_SESSION['user_type'] == 'profesor'): ?>
                        Bienvenido al Sistema de Gestión de Calificaciones. Gestioná las calificaciones de tus materias,
                        contenidos pedagógicos y consultá el progreso de tus estudiantes (incluyendo subgrupos, recursados y trabajo en equipo docente).
                    <?php elseif ($_SESSION['user_type'] == 'preceptor'): ?>
                        Bienvenido al Sistema de Gestión de Calificaciones. Gestioná la asistencia de los estudiantes
                        y generá boletines para las familias.
                    <?php else: ?>
                        Este es el Sistema de Gestión de Calificaciones de la Escuela Técnica Henry Ford, 
                        desarrollado conforme a la Resolución N° 1650/24 para el registro de trayectorias educativas.
                    <?php endif; ?>
                </p>
                <?php if ($cicloActivo): ?>
                <div class="row">
                    <div class="col-md-6">
                        <p>
                            <strong>Ciclo lectivo actual:</strong> <?= $cicloActivo['anio'] ?><br>
                            <strong>Período actual:</strong> <?= obtenerPeriodoActual($cicloActivo, $cuatrimestreActual) ?><br>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p>
                            <strong>Fecha actual:</strong> <?= (new DateTime())->format('d/m/Y') ?><br>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Estadísticas según el tipo de usuario -->
<div class="row">
    <?php if ($_SESSION['user_type'] == 'admin' || $_SESSION['user_type'] == 'directivo'): ?>
        <!-- Estadísticas para Administradores y Directivos -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Estudiantes</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_estudiantes'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Profesores</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_profesores'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-person-badge fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Materias Asignadas</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['materias_asignadas'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-journal-text fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Cursos</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_cursos'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-building fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    <?php elseif ($_SESSION['user_type'] == 'profesor'): ?>
        <!-- Estadísticas para Profesores (ACTUALIZADO PARA EQUIPOS DOCENTES) -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Mis Materias</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['mis_materias'] ?></div>
                            
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-journal-bookmark fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Mis Estudiantes</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['mis_estudiantes'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4 d-none">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pendientes</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['calificaciones_pendientes'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    <?php elseif ($_SESSION['user_type'] == 'preceptor'): ?>
        <!-- Estadísticas para Preceptores -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Estudiantes</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_estudiantes'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-people fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Cursos</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_cursos'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-building fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Asistencias Hoy</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['asistencias_hoy'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-calendar-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    <?php elseif ($_SESSION['user_type'] == 'estudiante'): ?>
        <!-- Estadísticas para Estudiantes -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Mi Curso</div>
                            <div class="h6 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['mi_curso'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-book fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Materias</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['total_materias'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-journal-text fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Aprobadas</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['materias_aprobadas'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Desaprobadas</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['materias_desaprobadas'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Segunda fila para estudiantes -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Sin Calificar</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $estadisticas['materias_sin_calificar'] ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-hourglass fa-2x text-gray-300"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Promedio</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= $estadisticas['promedio_general'] > 0 ? $estadisticas['promedio_general'] : '-' ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-graph-up fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Accesos rápidos según el tipo de usuario -->
<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card">
            <div class="card-header">
                Accesos Rápidos
            </div>
            <div class="card-body">
                <div class="row">
                    <?php if ($_SESSION['user_type'] == 'admin' || $_SESSION['user_type'] == 'directivo'): ?>
                        <!-- Accesos para Administradores y Directivos -->
                        <div class="col-md-3 mb-3">
                            <a href="usuarios.php" class="btn btn-primary w-100 py-4">
                                <i class="bi bi-people mb-2 d-block fs-3"></i>
                                Gestionar Usuarios
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="calificaciones.php" class="btn btn-success w-100 py-4">
                                <i class="bi bi-pencil-square mb-2 d-block fs-3"></i>
                                Cargar Calificaciones
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="boletines.php" class="btn btn-info w-100 py-4 text-white">
                                <i class="bi bi-file-text mb-2 d-block fs-3"></i>
                                Generar Boletines
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="reportes.php" class="btn btn-warning w-100 py-4">
                                <i class="bi bi-bar-chart mb-2 d-block fs-3"></i>
                                Ver Informes
                            </a>
                        </div>
                        
                    <?php elseif ($_SESSION['user_type'] == 'profesor'): ?>
                        <!-- Accesos para Profesores (ACTUALIZADO PARA EQUIPOS DOCENTES) -->
                        <div class="col-md-3 mb-3">
                            <a href="mis_materias.php" class="btn btn-primary w-100 py-4">
                                <i class="bi bi-journal-bookmark mb-2 d-block fs-3"></i>
                                Mis Materias
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="contenidos.php" class="btn btn-secondary w-100 py-4">
                                <i class="bi bi-list-check mb-2 d-block fs-3"></i>
                                Contenidos
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="calificaciones.php" class="btn btn-success w-100 py-4">
                                <i class="bi bi-pencil-square mb-2 d-block fs-3"></i>
                                Cargar Calificaciones
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="reportes.php" class="btn btn-info w-100 py-4 text-white">
                                <i class="bi bi-bar-chart mb-2 d-block fs-3"></i>
                                Ver Informes
                            </a>
                        </div>
                        
                    <?php elseif ($_SESSION['user_type'] == 'preceptor'): ?>
                        <!-- Accesos para Preceptores -->
                        <div class="col-md-4 mb-3">
                            <a href="asistencias.php" class="btn btn-success w-100 py-4">
                                <i class="bi bi-calendar-check mb-2 d-block fs-3"></i>
                                Registrar Asistencias
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="boletines.php" class="btn btn-info w-100 py-4 text-white">
                                <i class="bi bi-file-text mb-2 d-block fs-3"></i>
                                Generar Boletines
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="reportes.php" class="btn btn-warning w-100 py-4">
                                <i class="bi bi-bar-chart mb-2 d-block fs-3"></i>
                                Ver Informes
                            </a>
                        </div>
                        
                    <?php elseif ($_SESSION['user_type'] == 'estudiante'): ?>
                        <!-- Accesos para Estudiantes -->
                        <div class="col-md-4 mb-3">
                            <a href="mi_curso.php" class="btn btn-primary w-100 py-4">
                                <i class="bi bi-book mb-2 d-block fs-3"></i>
                                Mi Curso
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="mis_calificaciones.php" class="btn btn-success w-100 py-4">
                                <i class="bi bi-journal-check mb-2 d-block fs-3"></i>
                                Mis Calificaciones
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="reportes.php" class="btn btn-info w-100 py-4 text-white">
                                <i class="bi bi-bar-chart mb-2 d-block fs-3"></i>
                                Ver Informes
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sección específica para profesores CON DETALLE DE SUBGRUPOS, RECURSADOS Y EQUIPOS DOCENTES -->
<?php if ($_SESSION['user_type'] == 'profesor'): ?>
<div class="row">
    <div class="col-md-12 mb-4 d-none">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0">Mis Materias Asignadas</h6>
                    <small class="text-muted">Incluye estudiantes regulares, en subgrupos, recursando y trabajo en equipo docente</small>
                </div>
                <div>
                    <a href="contenidos.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-list-check"></i> Gestionar Contenidos
                    </a>
                    <a href="gestionar_subgrupos.php" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-people"></i> Gestionar Subgrupos
                    </a>
                    <a href="mis_materias.php" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-eye"></i> Ver Detalles Completos
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php
                // Obtener materias asignadas al profesor CON CONTEO ACTUALIZADO Y EQUIPOS DOCENTES
                $profesorId = $_SESSION['user_id'];
                $cicloId = $cicloActivo ? $cicloActivo['id'] : 0;
                
                if ($cicloId > 0) {
                    try {
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

                        $materiasProfesor = $db->fetchAll(
                            "SELECT mp.id as materia_curso_id,
                                    m.nombre as materia_nombre, 
                                    m.codigo, 
                                    c.nombre as curso_nombre, 
                                    c.anio as curso_anio,
                                    mp.requiere_subgrupos,
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
                            [$profesorId, $profesorId, $profesorId, $cicloId]
                        );

                        if (count($materiasProfesor) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Materia</th>
                                            <th>Curso</th>
                                            <th>Tipo / Equipo</th>
                                            <th>Estudiantes</th>
                                            <th>Calificados</th>
                                            <th>Progreso</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($materiasProfesor as $materia): ?>
                                        <?php 
                                            // Determinar información del equipo docente
                                            $equipoDocente = [];
                                            $posicionProfesor = 0;
                                            $esEquipo = false;
                                            
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
                                            
                                            $esEquipo = count($equipoDocente) > 1;
                                            
                                            // Obtener estudiantes usando la función auxiliar
                                            $estudiantes = obtenerEstudiantesMateria($db, $materia['materia_curso_id'], $cicloId);
                                            $totalEstudiantes = count($estudiantes);
                                            
                                            // Obtener calificaciones finales
                                            $calificacionesFinales = 0;
                                            if ($totalEstudiantes > 0) {
                                                $estudiantesIds = array_column($estudiantes, 'id');
                                                $placeholders = str_repeat('?,', count($estudiantesIds) - 1) . '?';
                                                
                                                $calificaciones = $db->fetchAll(
                                                    "SELECT estudiante_id, calificacion_final 
                                                     FROM calificaciones 
                                                     WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? 
                                                     AND estudiante_id IN ($placeholders)
                                                     AND calificacion_final IS NOT NULL AND calificacion_final != ''",
                                                    array_merge([$materia['materia_curso_id'], $cicloId], $estudiantesIds)
                                                );
                                                
                                                $calificacionesFinales = count($calificaciones);
                                            }
                                            
                                            $porcentaje = $totalEstudiantes > 0 ? 
                                                round(($calificacionesFinales / $totalEstudiantes) * 100) : 0;
                                            $colorProgreso = $porcentaje >= 80 ? 'success' : ($porcentaje >= 50 ? 'warning' : 'danger');
                                            
                                            // Determinar tipo de materia
                                            $esCiudadania = stripos($materia['materia_nombre'], 'ciudadania') !== false;
                                            $esTaller = preg_match('/^\d/', $materia['codigo']) || stripos($materia['materia_nombre'], 'taller') !== false;
                                            $tipoMateria = $materia['requiere_subgrupos'] ? 
                                                ($esCiudadania ? 'Ciudadanía (Subgrupos)' : ($esTaller ? 'Taller (Subgrupos)' : 'Con Subgrupos')) : 
                                                'Regular';
                                            $colorTipo = $materia['requiere_subgrupos'] ? 
                                                ($esCiudadania ? 'info' : ($esTaller ? 'warning' : 'secondary')) : 
                                                'primary';
                                        ?>
                                        <tr class="<?= $esEquipo ? 'table-info' : '' ?>">
                                            <td>
                                                <div>
                                                    <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                                                    <br><small class="text-muted"><?= htmlspecialchars($materia['codigo']) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $materia['curso_anio'] ?>°</span>
                                                
                                            </td>
                                            <td>
                                                <div class="mb-1">
                                                    <span class="badge bg-<?= $colorTipo ?>">
                                                        <i class="bi bi-<?= $materia['requiere_subgrupos'] ? ($esCiudadania ? 'people' : ($esTaller ? 'tools' : 'diagram-3')) : 'book' ?>"></i>
                                                        <?= $tipoMateria ?>
                                                    </span>
                                                </div>
                                                <?php if ($esEquipo): ?>
                                                <div>
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-people-fill"></i> Equipo Docente
                                                    </span>
                                                    <br><small class="text-muted d-none">
                                                        Usted es profesor <?= $posicionProfesor ?> de <?= count($equipoDocente) ?>
                                                    </small>
                                                </div>
                                                <?php else: ?>
                                                <div>
                                                    <small class="text-success">
                                                        <i class="bi bi-person-check"></i> Solo usted
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-info fs-6"><?= $totalEstudiantes ?></span>
                                                <?php 
                                                // Mostrar desglose si es materia con subgrupos o hay recursando
                                                if ($materia['requiere_subgrupos']): ?>
                                                <br><small class="text-muted d-none">
                                                    <i class="bi bi-people"></i> Asignados a subgrupos
                                                </small>
                                                <?php else:
                                                    // Para materias normales, mostrar desglose de recursando si aplica
                                                    $regulares = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'regular'; });
                                                    $recursando = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'recursando'; });
                                                    
                                                    if (count($recursando) > 0): ?>
                                                    <br><small class="text-muted">
                                                        <?= count($regulares) ?> reg. + <?= count($recursando) ?> rec.
                                                    </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-success"><?= $calificacionesFinales ?></span>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-<?= $colorProgreso ?>" role="progressbar" 
                                                         style="width: <?= $porcentaje ?>%;" 
                                                         aria-valuenow="<?= $porcentaje ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <?= $porcentaje ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="contenidos.php?materia=<?= $materia['materia_curso_id'] ?>" 
                                                       class="btn btn-outline-secondary" title="Gestionar contenidos">
                                                        <i class="bi bi-list-check"></i>
                                                    </a>
                                                    <a href="calificaciones.php?materia=<?= $materia['materia_curso_id'] ?>" 
                                                       class="btn btn-outline-primary" title="Calificar estudiantes">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                    <?php if ($materia['requiere_subgrupos']): ?>
                                                    <a href="gestionar_subgrupos.php?materia=<?= $materia['materia_curso_id'] ?>" 
                                                       class="btn btn-outline-info" title="Gestionar subgrupos">
                                                        <i class="bi bi-people"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                    <?php if ($esEquipo): ?>
                                                    <button type="button" class="btn btn-outline-warning" 
                                                            onclick="mostrarEquipoDocente('<?= addslashes($materia['materia_nombre']) ?>', <?= json_encode($equipoDocente) ?>)"
                                                            title="Ver equipo docente">
                                                        <i class="bi bi-people-fill"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Resumen de tipos de materias CON EQUIPOS DOCENTES -->
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="alert alert-light">
                                        <div class="row text-center">
                                            <div class="col-md-3">
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-book text-primary me-2"></i>
                                                    <div>
                                                        <strong><?= count(array_filter($materiasProfesor, function($m) { 
                                                            return !$m['requiere_subgrupos'] && !($m['profesor_id_2'] || $m['profesor_id_3']); 
                                                        })) ?></strong>
                                                        <br><small class="text-muted">Materias Individuales</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-people text-info me-2"></i>
                                                    <div>
                                                        <strong><?= count(array_filter($materiasProfesor, function($m) { return $m['requiere_subgrupos']; })) ?></strong>
                                                        <br><small class="text-muted">Con Subgrupos</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-people-fill text-warning me-2"></i>
                                                    <div>
                                                        <strong><?= count(array_filter($materiasProfesor, function($m) { 
                                                            return $m['profesor_id_2'] || $m['profesor_id_3']; 
                                                        })) ?></strong>
                                                        <br><small class="text-muted">En Equipo Docente</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-arrow-repeat text-secondary me-2"></i>
                                                    <div>
                                                        <strong><?= array_sum(array_map(function($m) use ($db, $cicloId) {
                                                            $estudiantes = obtenerEstudiantesMateria($db, $m['materia_curso_id'], $cicloId);
                                                            return count(array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'recursando'; }));
                                                        }, $materiasProfesor)) ?></strong>
                                                        <br><small class="text-muted">Total Recursando</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                No tiene materias asignadas para el ciclo lectivo actual. Contacte con la administración.
                            </div>
                        <?php endif;
                    } catch (Exception $e) {
                        echo '<div class="alert alert-danger">Error al obtener materias asignadas: ' . $e->getMessage() . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">No hay un ciclo lectivo activo.</div>';
                }
                ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Para administradores: Alertas y notificaciones del sistema -->
<?php if ($_SESSION['user_type'] == 'admin' || $_SESSION['user_type'] == 'directivo'): ?>
<div class="row">
    <div class="col-md-12 mb-4 d-none">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <i class="bi bi-exclamation-triangle"></i> Sistema en fase de implementación
            </div>
            <div class="card-body">
                <p>El sistema de gestión de calificaciones se encuentra en fase de implementación de acuerdo a la Resolución N° 1650/24.</p>
                <p>Próximas actualizaciones:</p>
                <ul>
                    <li>Módulo de intensificación para recuperación de materias</li>
                    <li>Informes estadísticos avanzados</li>
                    <li>Integración con sistemas externos</li>
                    <li>Notificaciones automáticas</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Información adicional sobre equipos docentes y sistema -->
<?php if ($_SESSION['user_type'] == 'profesor'): ?>
<?php 
// Verificar si hay estudiantes recursando, materias con subgrupos o equipos docentes
$hayRecursando = false;
$haySubgrupos = false;
$hayEquipos = false;

if (isset($materiasProfesor) && count($materiasProfesor) > 0) {
    foreach ($materiasProfesor as $materia) {
        // Verificar subgrupos
        if ($materia['requiere_subgrupos']) {
            $haySubgrupos = true;
        }
        
        // Verificar equipos docentes
        if ($materia['profesor_id_2'] || $materia['profesor_id_3']) {
            $hayEquipos = true;
        }
        
        // Verificar recursando (solo para materias sin subgrupos)
        if (!$materia['requiere_subgrupos']) {
            $estudiantesCompletos = obtenerEstudiantesMateria($db, $materia['materia_curso_id'], $cicloId);
            $recursando = array_filter($estudiantesCompletos, function($e) { return $e['tipo_matricula'] === 'recursando'; });
            if (count($recursando) > 0) {
                $hayRecursando = true;
            }
        }
    }
}

if ($hayRecursando || $haySubgrupos || $hayEquipos): ?>
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info d-none">
            <h6><i class="bi bi-info-circle"></i> Información sobre el Sistema</h6>
            <p class="mb-0">
                Los conteos de estudiantes incluyen automáticamente:
            </p>
            <ul class="mb-0">
                <?php if ($hayEquipos): ?>
                <li><strong>Materias en equipo docente:</strong> Usted comparte la responsabilidad con otros profesores. Todos tienen acceso completo a los estudiantes y calificaciones.</li>
                <?php endif; ?>
                <?php if ($haySubgrupos): ?>
                <li><strong>Materias con subgrupos:</strong> Solo estudiantes asignados a subgrupos específicos (use "Gestionar Subgrupos" para asignar)</li>
                <?php endif; ?>
                <li><strong>Estudiantes regulares:</strong> Matriculados en el curso donde se dicta la materia</li>
                <?php if ($hayRecursando): ?>
                <li><strong>Estudiantes recursando:</strong> Matriculados en otros cursos pero asignados para recursar esta materia</li>
                <?php endif; ?>
                <li><strong>Excluidos automáticamente:</strong> Estudiantes que tienen liberada esta materia para recursar otra</li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Calendario académico (para todos los usuarios) -->
<?php if ($cicloActivo): ?>
<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card">
            <div class="card-header">
                Calendario Académico <?= $cicloActivo['anio'] ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5>1° Cuatrimestre</h5>
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Inicio de clases
                                <span class="badge bg-primary rounded-pill">10/03/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                1° Valoración preliminar
                                <span class="badge bg-primary rounded-pill">16/05/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Cierre 1° Cuatrimestre
                                <span class="badge bg-primary rounded-pill">11/07/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Intensificación Julio
                                <span class="badge bg-warning rounded-pill">07/07 a 18/07 y 04/08 a 08/08<?= $cicloActivo['anio'] ?></span>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h5>2° Cuatrimestre</h5>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Inicio 2° Cuatrimestre
                                <span class="badge bg-primary rounded-pill">01/08/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                2° Valoración preliminar
                                <span class="badge bg-primary rounded-pill">01/09/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Cierre 2° Cuatrimestre
                                <span class="badge bg-primary rounded-pill">20/11/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Intensificación Diciembre
                                <span class="badge bg-warning rounded-pill">09/12 - 20/12/<?= $cicloActivo['anio'] ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Intensificación Febrero
                                <span class="badge bg-warning rounded-pill">10/02 - 28/02/<?= $cicloActivo['anio']+1 ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal para mostrar equipo docente -->
<div class="modal fade" id="modalEquipoDocente" tabindex="-1" aria-labelledby="modalEquipoDocenteLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEquipoDocenteLabel">
                    <i class="bi bi-people-fill"></i> Equipo Docente
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="contenidoEquipoDocente">
            </div>
        </div>
    </div>
</div>

<script>
// Función para mostrar información del equipo docente
function mostrarEquipoDocente(materiaName, equipoDocente) {
    let html = `
        <h6>Materia: ${materiaName}</h6>
        <hr>
        <div class="row">
    `;
    
    equipoDocente.forEach((profesor, index) => {
        const esActual = profesor.es_actual;
        const cardClass = esActual ? 'border-primary' : 'border-secondary';
        const badgeClass = esActual ? 'bg-primary' : 'bg-secondary';
        
        html += `
            <div class="col-md-6 mb-3">
                <div class="card ${cardClass}">
                    <div class="card-header ${esActual ? 'bg-primary text-white' : 'bg-light'}">
                        <h6 class="card-title mb-0">
                            <span class="badge ${badgeClass} me-2">${profesor.posicion}</span>
                            ${profesor.posicion == 1 ? 'Profesor Principal' : `Profesor ${profesor.posicion}`}
                            ${esActual ? ' (Usted)' : ''}
                        </h6>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            <strong>${profesor.nombre}</strong>
                        </p>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
        </div>
        <div class="alert alert-info mt-3">
            <h6 class="alert-heading">Trabajo en Equipo</h6>
            <ul class="mb-0">
                <li>Todos los profesores tienen acceso completo a las calificaciones</li>
                <li>Es importante coordinar criterios de evaluación</li>
                <li>Cualquier profesor puede realizar cambios en las calificaciones</li>
                <li>Se recomienda comunicación constante entre el equipo</li>
            </ul>
        </div>
    `;
    
    document.getElementById('contenidoEquipoDocente').innerHTML = html;
    
    const modal = new bootstrap.Modal(document.getElementById('modalEquipoDocente'));
    modal.show();
}

// Inicializar tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<style>
/* Estilos adicionales para equipos docentes */
.table-info {
    background-color: rgba(13, 110, 253, 0.1);
}

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

.border-left-secondary {
    border-left: 0.25rem solid #6c757d !important;
}
</style>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>