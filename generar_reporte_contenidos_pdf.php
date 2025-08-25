<?php
/**
 * generar_reporte_contenidos_pdf.php - Genera PDF simplificado con calificaciones cualitativas
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Solo calificaciones cualitativas, sin fecha ni bimestre, más espacio para contenidos
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar permisos
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    $_SESSION['message'] = 'No tiene permisos para generar este reporte';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetros básicos
if (!isset($_GET['tipo']) || !isset($_GET['curso'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para generar el reporte';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Obtener parámetros
$tipoReporte = $_GET['tipo'];
$cursoId = intval($_GET['curso']);
$estudianteId = isset($_GET['estudiante']) ? intval($_GET['estudiante']) : null;
$materiaCursoId = isset($_GET['materia']) ? intval($_GET['materia']) : null;

// Validar tipo de reporte
if (!in_array($tipoReporte, ['alumno_materia', 'alumno_completo', 'materia_completa'])) {
    $_SESSION['message'] = 'Tipo de reporte no válido';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Validar parámetros según tipo
if ($tipoReporte == 'alumno_materia' && (!$estudianteId || !$materiaCursoId)) {
    $_SESSION['message'] = 'Faltan parámetros para el reporte por alumno y materia';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

if ($tipoReporte == 'alumno_completo' && !$estudianteId) {
    $_SESSION['message'] = 'Falta el parámetro estudiante para el reporte completo';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

if ($tipoReporte == 'materia_completa' && !$materiaCursoId) {
    $_SESSION['message'] = 'Falta el parámetro materia para el reporte de materia completa';
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
$anioActivo = $cicloActivo ? $cicloActivo['anio'] : date('Y');

try {
    // Verificar permisos del profesor si aplica
    if ($_SESSION['user_type'] == 'profesor') {
        if ($tipoReporte == 'alumno_materia' || $tipoReporte == 'materia_completa') {
            $verificacion = $db->fetchOne(
                "SELECT id FROM materias_por_curso WHERE id = ? AND profesor_id = ?",
                [$materiaCursoId, $_SESSION['user_id']]
            );
            
            if (!$verificacion) {
                throw new Exception('No tiene permisos para ver esta materia');
            }
        }
    }
    
    // Obtener datos según el tipo de reporte
    if ($tipoReporte == 'alumno_materia') {
        $datosReporte = obtenerDatosAlumnoMateria($db, $estudianteId, $materiaCursoId, $cursoId, $cicloLectivoId);
    } elseif ($tipoReporte == 'alumno_completo') {
        $datosReporte = obtenerDatosAlumnoCompleto($db, $estudianteId, $cursoId, $cicloLectivoId);
    } elseif ($tipoReporte == 'materia_completa') {
        $datosReporte = obtenerDatosMateriaCompleta($db, $materiaCursoId, $cursoId, $cicloLectivoId);
    }
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: reportes.php');
    exit;
}

// FUNCIONES PARA OBTENER DATOS

function obtenerDatosAlumnoMateria($db, $estudianteId, $materiaCursoId, $cursoId, $cicloLectivoId) {
    // Estudiante
    $estudiante = $db->fetchOne(
        "SELECT u.*, m.fecha_matriculacion
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         WHERE u.id = ? AND m.curso_id = ?",
        [$estudianteId, $cursoId]
    );
    
    // Materia
    $materiaInfo = $db->fetchOne(
        "SELECT m.*, c.nombre as curso_nombre, c.anio as curso_anio,
                (u.apellido || ', ' || u.nombre) as profesor_nombre
         FROM materias m
         JOIN materias_por_curso mp ON m.id = mp.materia_id
         JOIN cursos c ON mp.curso_id = c.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    // Contenidos
    $contenidos = $db->fetchAll(
        "SELECT c.*, 
                cc.calificacion_cualitativa,
                cc.observaciones as observaciones_calificacion
         FROM contenidos c
         LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                               AND cc.estudiante_id = ?
         WHERE c.materia_curso_id = ? AND c.activo = 1
         ORDER BY c.fecha_clase, c.bimestre",
        [$estudianteId, $materiaCursoId]
    );
    
    return [
        'tipo' => 'alumno_materia',
        'estudiante' => $estudiante,
        'materia' => $materiaInfo,
        'contenidos' => $contenidos
    ];
}

function obtenerDatosAlumnoCompleto($db, $estudianteId, $cursoId, $cicloLectivoId) {
    // Estudiante
    $estudiante = $db->fetchOne(
        "SELECT u.*, c.nombre as curso_nombre, c.anio as curso_anio
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         JOIN cursos c ON m.curso_id = c.id
         WHERE u.id = ? AND m.curso_id = ?",
        [$estudianteId, $cursoId]
    );
    
    // ✅ OBTENER MATERIAS CON INFORMACIÓN DE GRUPOS
    $profesorId = $_SESSION['user_type'] == 'profesor' ? $_SESSION['user_id'] : null;
    $filtroProfesor = $profesorId ? "AND mp.profesor_id = $profesorId" : "";
    
    $materias = $db->fetchAll(
        "SELECT mp.id as materia_curso_id, m.nombre as materia_nombre, m.codigo as materia_codigo,
                (u.apellido || ', ' || u.nombre) as profesor_nombre,
                -- ✅ INFORMACIÓN DE GRUPOS
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                CASE WHEN gm.nombre IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id
         -- ✅ JOIN PARA OBTENER GRUPOS
         LEFT JOIN materias_grupo mg ON mp.id = mg.materia_curso_id AND mg.activo = 1
         LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
         WHERE mp.curso_id = ? $filtroProfesor
         ORDER BY m.nombre",
        [$cursoId]
    );
    
    // ✅ ELIMINAR DUPLICADOS MEJORADO - Por nombre de materia Y grupo
    $materiasUnicas = [];
    $combinacionesVistas = [];
    
    foreach ($materias as $materia) {
        // Crear clave única basada en el nombre final que se mostrará
        if ($materia['es_parte_grupo'] && !empty($materia['grupo_nombre'])) {
            $claveUnica = $materia['grupo_nombre'] . '|' . $materia['materia_nombre'];
        } else {
            $claveUnica = $materia['materia_nombre'];
        }
        
        if (!in_array($claveUnica, $combinacionesVistas)) {
            $materiasUnicas[] = $materia;
            $combinacionesVistas[] = $claveUnica;
        }
    }
    
    $materias = $materiasUnicas;
    
    // Contenidos por materia
    foreach ($materias as &$materia) {
        // ✅ OBTENER SOLO CONTENIDOS QUE TIENEN CALIFICACIÓN
        $materia['contenidos'] = $db->fetchAll(
            "SELECT c.*, 
                    cc.calificacion_cualitativa,
                    cc.observaciones as observaciones_calificacion
             FROM contenidos c
             LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                                   AND cc.estudiante_id = ?
             WHERE c.materia_curso_id = ? AND c.activo = 1
             AND cc.calificacion_cualitativa IS NOT NULL 
             AND cc.calificacion_cualitativa != ''
             AND cc.calificacion_cualitativa != 'Sin calificar'
             ORDER BY c.fecha_clase, c.bimestre",
            [$estudianteId, $materia['materia_curso_id']]
        );
        
        // ✅ APLICAR FORMATO DE NOMBRES: Grupo (Materia) o solo Materia
        if ($materia['es_parte_grupo'] && !empty($materia['grupo_nombre'])) {
            $materia['nombre_mostrar'] = strtoupper($materia['grupo_nombre']) . ' (' . strtoupper($materia['materia_nombre']) . ')';
        } else {
            $materia['nombre_mostrar'] = strtoupper($materia['materia_nombre']);
        }
        
        // ✅ ESTADÍSTICAS CORREGIDAS
        $materia['total_contenidos'] = count($materia['contenidos']);
        $materia['contenidos_calificados'] = 0;
        $materia['contenidos_acreditados'] = 0;
        $materia['contenidos_no_acreditados'] = 0;
        
        foreach ($materia['contenidos'] as $contenido) {
            $calificacion = trim($contenido['calificacion_cualitativa'] ?? '');
            
            if (!empty($calificacion)) {
                $materia['contenidos_calificados']++;
                
                if ($calificacion === 'Acreditado') {
                    $materia['contenidos_acreditados']++;
                } elseif ($calificacion === 'No Acreditado') {
                    $materia['contenidos_no_acreditados']++;
                }
                // "No Corresponde" no cuenta como no acreditado
            }
        }
        
        // Calcular porcentajes
        if ($materia['total_contenidos'] > 0) {
            $materia['porcentaje_calificados'] = round(($materia['contenidos_calificados'] / $materia['total_contenidos']) * 100, 1);
            
            if ($materia['contenidos_calificados'] > 0) {
                $materia['porcentaje_acreditados'] = round(($materia['contenidos_acreditados'] / $materia['contenidos_calificados']) * 100, 1);
            } else {
                $materia['porcentaje_acreditados'] = 0;
            }
        } else {
            $materia['porcentaje_calificados'] = 0;
            $materia['porcentaje_acreditados'] = 0;
        }
    }
    
    // ✅ FILTRAR MATERIAS: Solo mostrar materias que tienen contenidos
    $materias = array_filter($materias, function($materia) {
        return !empty($materia['contenidos']);
    });
    
    // ✅ ORDENAMIENTO PERSONALIZADO POR GRUPOS Y LUEGO ALFABÉTICO
    $anioEstudiante = $estudiante['curso_anio'] ?? 1;
    $materias = ordenarMateriasPorGrupos($materias, $anioEstudiante);
    
    // ✅ ESTADÍSTICAS GENERALES CORREGIDAS
    $estadisticasGenerales = [
        'total_materias' => count($materias),
        'total_contenidos' => 0,
        'total_contenidos_calificados' => 0,
        'total_contenidos_acreditados' => 0,
        'total_contenidos_no_acreditados' => 0,
        'materias_con_contenidos' => count($materias),
        'materias_sin_contenidos' => 0
    ];
    
    foreach ($materias as $materia) {
        $estadisticasGenerales['total_contenidos'] += $materia['total_contenidos'];
        $estadisticasGenerales['total_contenidos_calificados'] += $materia['contenidos_calificados'];
        $estadisticasGenerales['total_contenidos_acreditados'] += $materia['contenidos_acreditados'];
        $estadisticasGenerales['total_contenidos_no_acreditados'] += $materia['contenidos_no_acreditados'];
    }
    
    // Calcular porcentajes generales
    if ($estadisticasGenerales['total_contenidos'] > 0) {
        $estadisticasGenerales['porcentaje_general_calificados'] = round(
            ($estadisticasGenerales['total_contenidos_calificados'] / $estadisticasGenerales['total_contenidos']) * 100, 1
        );
    } else {
        $estadisticasGenerales['porcentaje_general_calificados'] = 0;
    }
    
    if ($estadisticasGenerales['total_contenidos_calificados'] > 0) {
        $estadisticasGenerales['porcentaje_general_acreditados'] = round(
            ($estadisticasGenerales['total_contenidos_acreditados'] / $estadisticasGenerales['total_contenidos_calificados']) * 100, 1
        );
    } else {
        $estadisticasGenerales['porcentaje_general_acreditados'] = 0;
    }
    
    return [
        'tipo' => 'alumno_completo',
        'estudiante' => $estudiante,
        'materias' => $materias,
        'estadisticas_generales' => $estadisticasGenerales
    ];
}

// ✅ NUEVA FUNCIÓN: Ordenar materias por grupos específicos
function ordenarMateriasPorGrupos($materias, $anioEstudiante) {
    // Definir el orden de grupos para materias técnicas
    $ordenGrupos = [
        'LENGUAJES TECNOLÓGICOS' => 1,
        'SISTEMAS TECNOLÓGICOS' => 2,
        'PROCEDIMIENTOS TÉCNICOS' => 3
    ];
    
    // Obtener orden personalizado tradicional
    $ordenPersonalizadoTradicional = obtenerOrdenPersonalizadoMateriasReporte($anioEstudiante);
    $posicionesTradicionales = array_flip(array_map('strtoupper', $ordenPersonalizadoTradicional));
    
    // Separar materias por categorías
    $materiasConGrupoTecnico = [];
    $materiasTradicionalesConOrden = [];
    $materiasSinOrden = [];
    
    foreach ($materias as $materia) {
        $nombreGrupo = strtoupper(trim($materia['grupo_nombre'] ?? ''));
        $nombreMateria = strtoupper(trim($materia['materia_nombre'] ?? ''));
        
        if (!empty($nombreGrupo) && isset($ordenGrupos[$nombreGrupo])) {
            // Es una materia técnica con grupo específico
            $materia['orden_grupo'] = $ordenGrupos[$nombreGrupo];
            $materiasConGrupoTecnico[] = $materia;
        } else {
            // Es una materia tradicional - verificar si está en el orden personalizado
            $encontradoEnOrden = false;
            
            // Buscar por nombre exacto o equivalente
            if (isset($posicionesTradicionales[$nombreMateria])) {
                $materia['posicion_tradicional'] = $posicionesTradicionales[$nombreMateria];
                $materiasTradicionalesConOrden[] = $materia;
                $encontradoEnOrden = true;
            } else {
                // Buscar por equivalencias
                foreach ($posicionesTradicionales as $nombreOrden => $posicion) {
                    if (function_exists('sonNombresEquivalentesReporte') && 
                        sonNombresEquivalentesReporte($nombreMateria, $nombreOrden)) {
                        $materia['posicion_tradicional'] = $posicion;
                        $materiasTradicionalesConOrden[] = $materia;
                        $encontradoEnOrden = true;
                        break;
                    }
                }
            }
            
            if (!$encontradoEnOrden) {
                $materiasSinOrden[] = $materia;
            }
        }
    }
    
    // ✅ ORDENAR MATERIAS TRADICIONALES POR POSICIÓN
    usort($materiasTradicionalesConOrden, function($a, $b) {
        return $a['posicion_tradicional'] <=> $b['posicion_tradicional'];
    });
    
    // ✅ ORDENAR MATERIAS TÉCNICAS POR GRUPO Y LUEGO ALFABÉTICAMENTE
    usort($materiasConGrupoTecnico, function($a, $b) {
        // Primero por orden de grupo
        $comparacionGrupo = $a['orden_grupo'] <=> $b['orden_grupo'];
        
        if ($comparacionGrupo === 0) {
            // Si son del mismo grupo, ordenar alfabéticamente por nombre de materia
            return strcasecmp($a['materia_nombre'], $b['materia_nombre']);
        }
        
        return $comparacionGrupo;
    });
    
    // ✅ ORDENAR MATERIAS SIN ORDEN ALFABÉTICAMENTE
    usort($materiasSinOrden, function($a, $b) {
        return strcasecmp($a['materia_nombre'], $b['materia_nombre']);
    });
    
    // ✅ COMBINAR EN EL ORDEN CORRECTO:
    // 1. Materias tradicionales (con su orden personalizado)
    // 2. Materias técnicas agrupadas (LENGUAJES → SISTEMAS → PROCEDIMIENTOS)  
    // 3. Materias sin orden específico (alfabéticamente)
    
    return array_merge(
        $materiasTradicionalesConOrden,
        $materiasConGrupoTecnico,
        $materiasSinOrden
    );
}

function obtenerDatosMateriaCompleta($db, $materiaCursoId, $cursoId, $cicloLectivoId) {
    // ✅ MATERIA CON INFORMACIÓN DE GRUPO
    $materia = $db->fetchOne(
        "SELECT m.*, c.nombre as curso_nombre, c.anio as curso_anio,
                (u.apellido || ', ' || u.nombre) as profesor_nombre,
                mp.id as materia_curso_id,
                mp.requiere_subgrupos,
                mp.activo as materia_activa,
                -- ✅ INFORMACIÓN DE GRUPO
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                CASE WHEN gm.nombre IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo
         FROM materias m
         JOIN materias_por_curso mp ON m.id = mp.materia_id
         JOIN cursos c ON mp.curso_id = c.id
         LEFT JOIN usuarios u ON mp.profesor_id = u.id
         -- ✅ JOIN PARA OBTENER GRUPO
         LEFT JOIN materias_grupo mg ON mp.id = mg.materia_curso_id AND mg.activo = 1
         LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
         WHERE mp.id = ?",
        [$materiaCursoId]
    );
    
    if (!$materia) {
        throw new Exception("No se encontró la materia solicitada");
    }
    
    // ✅ APLICAR FORMATO DE NOMBRE
    if ($materia['es_parte_grupo'] && !empty($materia['grupo_nombre'])) {
        $materia['nombre_mostrar'] = strtoupper($materia['grupo_nombre']) . ' (' . strtoupper($materia['nombre']) . ')';
    } else {
        $materia['nombre_mostrar'] = strtoupper($materia['nombre']);
    }
    
    // Estudiantes del curso
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, u.email,
                m.fecha_matriculacion, m.estado as estado_matricula,
                m.numero_legajo
         FROM usuarios u
         JOIN matriculas m ON u.id = m.estudiante_id
         WHERE m.curso_id = ? AND m.estado = 'activo'
         ORDER BY u.apellido, u.nombre",
        [$cursoId]
    );
    
    usort($estudiantes, function($a, $b) {
        $comparacionApellido = strcasecmp(
            trim($a['apellido'] ?? ''), 
            trim($b['apellido'] ?? '')
        );
        
        if ($comparacionApellido === 0) {
            return strcasecmp(
                trim($a['nombre'] ?? ''), 
                trim($b['nombre'] ?? '')
            );
        }
        
        return $comparacionApellido;
    });
    
    // ✅ OBTENER SOLO CONTENIDOS CALIFICADOS
    $todosLosContenidos = $db->fetchAll(
        "SELECT c.id, c.titulo, c.descripcion, c.fecha_clase, c.bimestre,
                c.tipo_contenido, c.activo
         FROM contenidos c
         WHERE c.materia_curso_id = ? AND c.activo = 1
         ORDER BY c.fecha_clase, c.bimestre, c.titulo",
        [$materiaCursoId]
    );
    
    $estadisticasMateria = [
        'total_estudiantes' => count($estudiantes),
        'total_contenidos' => count($todosLosContenidos),
        'estudiantes_con_contenidos' => 0,
        'estudiantes_sin_contenidos' => 0,
        'promedio_contenidos_por_estudiante' => 0,
        'contenidos_por_bimestre' => [],
        'resumen_calificaciones' => [
            'total_calificaciones' => 0,
            'acreditados' => 0,
            'no_acreditados' => 0, // ✅ Solo "No Acreditado"
            'sin_calificar' => 0
        ]
    ];
    
    foreach ($todosLosContenidos as $contenido) {
        $bimestre = $contenido['bimestre'] ?? 'Sin bimestre';
        if (!isset($estadisticasMateria['contenidos_por_bimestre'][$bimestre])) {
            $estadisticasMateria['contenidos_por_bimestre'][$bimestre] = 0;
        }
        $estadisticasMateria['contenidos_por_bimestre'][$bimestre]++;
    }
    
    // ✅ CONTENIDOS POR ESTUDIANTE - SOLO CALIFICADOS
    foreach ($estudiantes as &$estudiante) {
        $estudiante['contenidos'] = $db->fetchAll(
            "SELECT c.*, 
                    cc.calificacion_cualitativa,
                    cc.observaciones as observaciones_calificacion,
                    cc.fecha_calificacion
             FROM contenidos c
             LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id 
                                                   AND cc.estudiante_id = ?
             WHERE c.materia_curso_id = ? AND c.activo = 1
             -- ✅ FILTRAR: Solo contenidos con calificación
             AND cc.calificacion_cualitativa IS NOT NULL 
             AND cc.calificacion_cualitativa != ''
             AND cc.calificacion_cualitativa != 'Sin calificar'
             ORDER BY c.fecha_clase, c.bimestre, c.titulo",
            [$estudiante['id'], $materiaCursoId]
        );
        
        // ✅ ESTADÍSTICAS CORREGIDAS POR ESTUDIANTE
        $estudiante['estadisticas'] = [
            'total_contenidos' => count($estudiante['contenidos']),
            'contenidos_calificados' => 0,
            'contenidos_acreditados' => 0,
            'contenidos_no_acreditados' => 0, // ✅ Solo "No Acreditado"
            'contenidos_sin_calificar' => 0,
            'porcentaje_calificados' => 0,
            'porcentaje_acreditados' => 0,
            'ultimo_contenido_fecha' => null,
            'primer_contenido_fecha' => null,
            'contenidos_por_bimestre' => []
        ];
        
        $fechas = [];
        
        foreach ($estudiante['contenidos'] as $contenido) {
            $calificacion = trim($contenido['calificacion_cualitativa'] ?? '');
            
            // ✅ CONTEO CORREGIDO
            if (!empty($calificacion)) {
                $estudiante['estadisticas']['contenidos_calificados']++;
                $estadisticasMateria['resumen_calificaciones']['total_calificaciones']++;
                
                if ($calificacion === 'Acreditado') {
                    $estudiante['estadisticas']['contenidos_acreditados']++;
                    $estadisticasMateria['resumen_calificaciones']['acreditados']++;
                } elseif ($calificacion === 'No Acreditado') {
                    // ✅ Solo "No Acreditado" cuenta aquí
                    $estudiante['estadisticas']['contenidos_no_acreditados']++;
                    $estadisticasMateria['resumen_calificaciones']['no_acreditados']++;
                }
                // ✅ "No Corresponde" no se cuenta como no acreditado
            }
            
            $bimestre = $contenido['bimestre'] ?? 'Sin bimestre';
            if (!isset($estudiante['estadisticas']['contenidos_por_bimestre'][$bimestre])) {
                $estudiante['estadisticas']['contenidos_por_bimestre'][$bimestre] = 0;
            }
            $estudiante['estadisticas']['contenidos_por_bimestre'][$bimestre]++;
            
            if (!empty($contenido['fecha_clase'])) {
                $fechas[] = $contenido['fecha_clase'];
            }
        }
        
        // Calcular porcentajes
        if ($estudiante['estadisticas']['total_contenidos'] > 0) {
            $estudiante['estadisticas']['porcentaje_calificados'] = round(
                ($estudiante['estadisticas']['contenidos_calificados'] / $estudiante['estadisticas']['total_contenidos']) * 100, 1
            );
            
            if ($estudiante['estadisticas']['contenidos_calificados'] > 0) {
                $estudiante['estadisticas']['porcentaje_acreditados'] = round(
                    ($estudiante['estadisticas']['contenidos_acreditados'] / $estudiante['estadisticas']['contenidos_calificados']) * 100, 1
                );
            }
        }
        
        if (!empty($fechas)) {
            sort($fechas);
            $estudiante['estadisticas']['primer_contenido_fecha'] = $fechas[0];
            $estudiante['estadisticas']['ultimo_contenido_fecha'] = end($fechas);
        }
        
        // Clasificar estudiante según progreso
        if ($estudiante['estadisticas']['total_contenidos'] > 0) {
            $estadisticasMateria['estudiantes_con_contenidos']++;
            
            $porcentajeCalif = $estudiante['estadisticas']['porcentaje_calificados'];
            $porcentajeAcred = $estudiante['estadisticas']['porcentaje_acreditados'];
            
            if ($porcentajeCalif >= 80 && $porcentajeAcred >= 70) {
                $estudiante['nivel_progreso'] = 'Excelente';
                $estudiante['color_progreso'] = 'success';
            } elseif ($porcentajeCalif >= 60 && $porcentajeAcred >= 50) {
                $estudiante['nivel_progreso'] = 'Bueno';
                $estudiante['color_progreso'] = 'info';
            } elseif ($porcentajeCalif >= 40) {
                $estudiante['nivel_progreso'] = 'Regular';
                $estudiante['color_progreso'] = 'warning';
            } else {
                $estudiante['nivel_progreso'] = 'Bajo';
                $estudiante['color_progreso'] = 'danger';
            }
        } else {
            $estadisticasMateria['estudiantes_sin_contenidos']++;
            $estudiante['nivel_progreso'] = 'Sin datos';
            $estudiante['color_progreso'] = 'secondary';
        }
        
        $estudiante['nombre_completo'] = trim($estudiante['apellido'] . ', ' . $estudiante['nombre']);
    }
    
    // ✅ FILTRAR ESTUDIANTES: Solo mostrar estudiantes que tienen contenidos
    $estudiantes = array_filter($estudiantes, function($estudiante) {
        return !empty($estudiante['contenidos']);
    });
    
    // Estadísticas finales
    if ($estadisticasMateria['total_estudiantes'] > 0) {
        $estadisticasMateria['promedio_contenidos_por_estudiante'] = round(
            $estadisticasMateria['resumen_calificaciones']['total_calificaciones'] / $estadisticasMateria['total_estudiantes'], 1
        );
        
        if ($estadisticasMateria['resumen_calificaciones']['total_calificaciones'] > 0) {
            $estadisticasMateria['porcentaje_general_acreditados'] = round(
                ($estadisticasMateria['resumen_calificaciones']['acreditados'] / $estadisticasMateria['resumen_calificaciones']['total_calificaciones']) * 100, 1
            );
        } else {
            $estadisticasMateria['porcentaje_general_acreditados'] = 0;
        }
    }
    
    // Top estudiantes (solo con datos)
    $estudiantesConDatos = array_filter($estudiantes, function($e) {
        return $e['estadisticas']['total_contenidos'] > 0;
    });
    
    usort($estudiantesConDatos, function($a, $b) {
        return $b['estadisticas']['porcentaje_acreditados'] <=> $a['estadisticas']['porcentaje_acreditados'];
    });
    
    $estadisticasMateria['top_estudiantes'] = array_slice($estudiantesConDatos, 0, 3);
    $estadisticasMateria['estudiantes_necesitan_apoyo'] = array_slice(array_reverse($estudiantesConDatos), 0, 3);
    
    $materia['nombre_normalizado'] = function_exists('normalizarNombreMateriaReporte') 
        ? normalizarNombreMateriaReporte($materia['nombre'])
        : strtoupper($materia['nombre']);
    
    return [
        'tipo' => 'materia_completa',
        'materia' => $materia,
        'estudiantes' => $estudiantes,
        'estadisticas_materia' => $estadisticasMateria,
        'contenidos_disponibles' => $todosLosContenidos,
        'fecha_generacion_reporte' => date('Y-m-d H:i:s')
    ];
}

// FUNCIÓN AUXILIAR PARA ORDENAR ARRAY DE MATERIAS
function ordenarMateriasPersonalizadoReporte($materias, $anioEstudiante) {
    $ordenPersonalizado = obtenerOrdenPersonalizadoMateriasReporte($anioEstudiante);
    
    if (empty($ordenPersonalizado)) {
        // Si no hay orden personalizado, usar orden alfabético
        usort($materias, function($a, $b) {
            $nombreA = $a['materia_nombre'] ?? $a['nombre'] ?? '';
            $nombreB = $b['materia_nombre'] ?? $b['nombre'] ?? '';
            return strcasecmp($nombreA, $nombreB);
        });
        return $materias;
    }
    
    // Crear un mapa de posiciones para el orden personalizado
    $posiciones = array_flip(array_map('strtoupper', $ordenPersonalizado));
    
    // Separar materias que están en el orden personalizado de las que no
    $materiasConOrden = [];
    $materiasSinOrden = [];
    
    foreach ($materias as $materia) {
        $nombreMateria = strtoupper($materia['materia_nombre'] ?? $materia['nombre'] ?? '');
        
        // Buscar coincidencia exacta o equivalente
        $encontrado = false;
        $posicionEncontrada = null;
        
        // Primero buscar coincidencia exacta
        if (isset($posiciones[$nombreMateria])) {
            $encontrado = true;
            $posicionEncontrada = $posiciones[$nombreMateria];
        } else {
            // Buscar coincidencias equivalentes
            foreach ($posiciones as $nombreOrden => $posicion) {
                if (sonNombresEquivalentesReporte($nombreMateria, $nombreOrden)) {
                    $encontrado = true;
                    $posicionEncontrada = $posicion;
                    break;
                }
            }
        }
        
        if ($encontrado) {
            $materiasConOrden[] = [
                'materia' => $materia,
                'posicion' => $posicionEncontrada
            ];
        } else {
            $materiasSinOrden[] = $materia;
        }
    }
    
    // Ordenar las materias que están en el orden personalizado
    usort($materiasConOrden, function($a, $b) {
        return $a['posicion'] <=> $b['posicion'];
    });
    
    // Ordenar alfabéticamente las materias que no están en el orden personalizado
    usort($materiasSinOrden, function($a, $b) {
        $nombreA = $a['materia_nombre'] ?? $a['nombre'] ?? '';
        $nombreB = $b['materia_nombre'] ?? $b['nombre'] ?? '';
        return strcasecmp($nombreA, $nombreB);
    });
    
    // Combinar: primero las del orden personalizado, luego las otras
    $resultado = [];
    
    // Agregar materias con orden personalizado
    foreach ($materiasConOrden as $materiaConOrden) {
        $resultado[] = $materiaConOrden['materia'];
    }
    
    // Agregar materias sin orden personalizado al final
    foreach ($materiasSinOrden as $materiaSinOrden) {
        $resultado[] = $materiaSinOrden;
    }
    
    return $resultado;
}

/**
 * FUNCIÓN COPIADA: Obtener orden personalizado de materias por año (igual que en boletín)
 */
function obtenerOrdenPersonalizadoMateriasReporte($anio) {
    $ordenPorAnio = [
        1 => [
            'PRÁCTICAS DEL LENGUAJE',
            'CIENCIAS SOCIALES', 
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN FÍSICA',
            'EDUCACIÓN ARTÍSTICA',
            'INGLÉS',
            'MATEMÁTICA',
            'CIENCIAS NATURALES',
            'LENGUAJES TECNOLÓGICOS',
            'SISTEMAS TECNOLÓGICOS',
            'PROCEDIMIENTOS TÉCNICOS'
        ],
        2 => [
            'BIOLOGÍA',
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN ARTÍSTICA', 
            'EDUCACIÓN FÍSICA',
            'FÍSICO QUÍMICA',
            'GEOGRAFÍA',
            'HISTORIA',
            'INGLÉS',
            'MATEMÁTICA',
            'PRÁCTICAS DEL LENGUAJE',
            'PROCEDIMIENTOS TÉCNICOS',
            'LENGUAJES TECNOLÓGICOS',
            'SISTEMAS TECNOLÓGICOS'
        ],
        3 => [
            'BIOLOGÍA',
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN ARTÍSTICA',
            'EDUCACIÓN FÍSICA',
            'FÍSICO QUÍMICA',
            'GEOGRAFÍA', 
            'HISTORIA',
            'INGLÉS',
            'MATEMÁTICA',
            'PRÁCTICAS DEL LENGUAJE',
            'PROCEDIMIENTOS TÉCNICOS',
            'LENGUAJES TECNOLÓGICOS', 
            'SISTEMAS TECNOLÓGICOS'
        ],
        4 => [
            'LITERATURA',
            'INGLÉS',
            'EDUCACIÓN FÍSICA',
            'SALUD Y ADOLESCENCIA',
            'HISTORIA',
            'GEOGRAFÍA',
            'MATEMÁTICA',
            'FÍSICA',
            'QUÍMICA',
            'CONOCIMIENTO DE LOS MATERIALES',
            'DIBUJO TECNOLÓGICO',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        5 => [
            'LITERATURA',
            'INGLÉS',
            'EDUCACIÓN FÍSICA',
            'POLÍTICA Y CIUDADANÍA',
            'HISTORIA',
            'GEOGRAFÍA',
            'ANÁLISIS MATEMÁTICO',
            'MECÁNICA Y MECANISMOS',
            'ELECTROTECNIA',
            'RESISTENCIA Y ENSAYO DE MATERIALES',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        6 => [
            'LITERATURA',
            'INGLÉS', 
            'EDUCACIÓN FÍSICA',
            'FILOSOFÍA',
            'ARTE',
            'MATEMÁTICA APLICADA',
            'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
            'ELECTROTECNIA',
            'SISTEMAS MECÁNICOS',
            'DERECHOS DEL TRABAJO',
            'LABORATORIO DE MEDICIONES ELÉCTRICAS',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        7 => [
            'PRÁCTICAS PROFESIONALIZANTES',
            'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
            'ELECTRÓNICA INDUSTRIAL',
            'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
            'MÁQUINAS ELÉCTRICAS',
            'SISTEMAS MECÁNICOS',
            'LABORATORIO DE METROLOGÍA Y CONTROL DE CALIDAD', 
            'MANTENIMIENTO Y MONTAJE ELECTROMECÁNICO',
            'PROYECTO Y DISEÑO ELECTROMECÁNICO',
            'PROYECTO Y DISEÑO DE INSTALACIONES ELÉCTRICAS'
        ]
    ];
    
    return $ordenPorAnio[$anio] ?? [];
}

/**
 * FUNCIÓN COPIADA: Normalizar nombres de materias (igual que en boletín)
 */
function normalizarNombreMateriaReporte($nombreMateria) {
    // Convertir a mayúsculas para comparación
    $nombreUpper = strtoupper(trim($nombreMateria));
    
    // Reglas de normalización específicas
    $reglasNormalizacion = [
        // Construcción de Ciudadanía - TODAS las modalidades
        '/^CONSTR\.?\s*DE\s*CIUD\.?\s*-?\s*.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONSTRUCCION\s*DE\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONST\.?\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        
        // Materias técnicas de 4° año
        '/^DIBUJO\s*TECNOLOGICO$/i' => 'DIBUJO TECNOLÓGICO',
        '/^MAQUINAS\s*ELECTRICAS\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        '/^MAQ\.?\s*ELEC\.?\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        
        // Materias de 5° año
        '/^ANALISIS\s*MATEMATICO$/i' => 'ANÁLISIS MATEMÁTICO',
        '/^MECANICA\s*Y\s*MECANISMOS$/i' => 'MECÁNICA Y MECANISMOS',
        '/^RESISTENCIA\s*Y\s*ENSAYO\s*DE\s*MATERIALES$/i' => 'RESISTENCIA Y ENSAYO DE MATERIALES',
        '/^POLITICA\s*Y\s*CIUDADANIA$/i' => 'POLÍTICA Y CIUDADANÍA',
        
        // Materias de 6° año
        '/^TERMO\.?\s*Y\s*MAQ\.?\s*TÉRMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^TERMODINAMICA\s*Y\s*MAQUINAS\s*TERMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^SIST\.?\s*MECÁNICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^SISTEMAS\s*MECANICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^LAB\.?\s*DE\s*MED\.?\s*ELÉCTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^LABORATORIO\s*DE\s*MEDICIONES\s*ELECTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^DERECHOS\s*DEL\s*TRABAJO$/i' => 'DERECHOS DEL TRABAJO',
        '/^MATEMATICA\s*APLICADA$/i' => 'MATEMÁTICA APLICADA',
        
        // Materias de 7° año
        '/^PRACTICAS\s*PROFESIONALIZANTES$/i' => 'PRÁCTICAS PROFESIONALIZANTES',
        '/^EMPRENDIMIENTOS\s*PRODUCTIVOS\s*Y\s*DESARROLLO\s*LOCAL$/i' => 'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
        '/^ELECTRONICA\s*INDUSTRIAL$/i' => 'ELECTRÓNICA INDUSTRIAL',
        '/^SEGURIDAD,?\s*HIGIENE\s*Y\s*PROTECCION\s*DEL\s*MEDIO\s*AMBIENTE$/i' => 'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
        '/^LABORATORIO\s*DE\s*METROLOGIA\s*Y\s*CONTROL\s*DE\s*CALIDAD$/i' => 'LABORATORIO DE METROLOGÍA Y CONTROL DE CALIDAD',
        '/^MANTENIMIENTO\s*Y\s*MONTAJE\s*ELECTROMECANICO$/i' => 'MANTENIMIENTO Y MONTAJE ELECTROMECÁNICO',
        '/^PROYECTO\s*Y\s*DISEÑO\s*ELECTROMECANICO$/i' => 'PROYECTO Y DISEÑO ELECTROMECÁNICO',
        '/^PROYECTO\s*Y\s*DISEÑO\s*DE\s*INSTALACIONES\s*ELECTRICAS$/i' => 'PROYECTO Y DISEÑO DE INSTALACIONES ELÉCTRICAS',
        
        // Materias comunes
        '/^PRACTICAS\s*DEL\s*LENGUAJE$/i' => 'PRÁCTICAS DEL LENGUAJE',
        '/^EDUCACION\s*FISICA$/i' => 'EDUCACIÓN FÍSICA',
        '/^EDUCACION\s*ARTISTICA$/i' => 'EDUCACIÓN ARTÍSTICA',
        '/^FISICO\s*QUIMICA$/i' => 'FÍSICO QUÍMICA',
        '/^CIENCIAS\s*NAT\.?$/i' => 'CIENCIAS NATURALES',
        '/^CIENCIAS\s*SOC\.?$/i' => 'CIENCIAS SOCIALES',
        '/^LENG\.?\s*TECNOLOGICOS$/i' => 'LENGUAJES TECNOLÓGICOS',
        '/^SIST\.?\s*TECNOLOGICOS$/i' => 'SISTEMAS TECNOLÓGICOS',
        '/^PROC\.?\s*TECNICOS$/i' => 'PROCEDIMIENTOS TÉCNICOS',
        '/^MATEMATICA$/i' => 'MATEMÁTICA',
        '/^GEOGRAFIA$/i' => 'GEOGRAFÍA',
        '/^BIOLOGIA$/i' => 'BIOLOGÍA',
        '/^HISTORIA$/i' => 'HISTORIA',
        '/^INGLES$/i' => 'INGLÉS',
        '/^LITERATURA$/i' => 'LITERATURA',
        '/^FILOSOFIA$/i' => 'FILOSOFÍA',
        '/^FISICA$/i' => 'FÍSICA',
        '/^QUIMICA$/i' => 'QUÍMICA',
        '/^ELECTROTECNIA$/i' => 'ELECTROTECNIA',
        
        // Materias técnicas generales
        '/^DISEÑO\s*Y\s*PROC\.?\s*MECANICO$/i' => 'DISEÑO Y PROCESAMIENTO MECÁNICO',
        '/^DISEÑO\s*Y\s*PROCESAMIENTO\s*MEC\.?$/i' => 'DISEÑO Y PROCESAMIENTO MECÁNICO',
        '/^INST\.?\s*Y\s*APLIC\.?\s*ENERGIA$/i' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA',
        '/^INSTALACION\s*Y\s*APLIC\.?\s*DE\s*LA\s*ENERGIA$/i' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA',
        '/^INSTALACIONES\s*Y\s*APLICACIONES\s*DE\s*LA\s*ENERGIA$/i' => 'INSTALACIONES Y APLICACIONES DE LA ENERGÍA',
        '/^CONOCIMIENTO\s*DE\s*LOS\s*MATERIALES$/i' => 'CONOCIMIENTO DE LOS MATERIALES',
        '/^SALUD\s*Y\s*ADOLESCENCIA$/i' => 'SALUD Y ADOLESCENCIA',
    ];
    
    // Aplicar reglas de normalización
    foreach ($reglasNormalizacion as $patron => $reemplazo) {
        if (preg_match($patron, $nombreUpper)) {
            return $reemplazo;
        }
    }
    
    // Si no coincide con ninguna regla, devolver el nombre original en mayúsculas
    return convertirMayusculasConTildes($nombreMateria);
}

/**
 * FUNCIÓN COPIADA: Verificar si dos nombres de materias son equivalentes
 */
function sonNombresEquivalentesReporte($nombre1, $nombre2) {
    // Normalizar ambos nombres antes de comparar
    $nombre1Normalizado = normalizarNombreMateriaReporte($nombre1);
    $nombre2Normalizado = normalizarNombreMateriaReporte($nombre2);
    
    // Comparar nombres normalizados
    if ($nombre1Normalizado === $nombre2Normalizado) {
        return true;
    }
    
    // Convertir a mayúsculas y limpiar espacios para comparación adicional
    $nombre1 = trim(strtoupper($nombre1));
    $nombre2 = trim(strtoupper($nombre2));
    
    // Coincidencia exacta
    if ($nombre1 === $nombre2) {
        return true;
    }
    
    // Verificar si uno contiene al otro (para casos de abreviaciones)
    if (strpos($nombre1, $nombre2) !== false || strpos($nombre2, $nombre1) !== false) {
        return true;
    }
    
    return false;
}

/**
 * FUNCIÓN NUEVA: Ordenar contenidos por materia siguiendo el mismo criterio que el boletín
 */
function ordenarContenidosPorMateriaPersonalizado($contenidos, $anioEstudiante) {
    if (empty($contenidos)) {
        return $contenidos;
    }
    
    $ordenPersonalizado = obtenerOrdenPersonalizadoMateriasReporte($anioEstudiante);
    
    // Crear un mapa de posiciones para el orden personalizado
    $posiciones = array_flip(array_map('strtoupper', $ordenPersonalizado));
    
    // Separar contenidos que están en el orden personalizado de los que no
    $contenidosConOrden = [];
    $contenidosSinOrden = [];
    
    foreach ($contenidos as $contenido) {
        $nombreMateria = strtoupper($contenido['nombre'] ?? '');
        
        // Buscar coincidencia exacta o equivalente
        $encontrado = false;
        $posicionEncontrada = null;
        
        // Primero buscar coincidencia exacta
        if (isset($posiciones[$nombreMateria])) {
            $encontrado = true;
            $posicionEncontrada = $posiciones[$nombreMateria];
        } else {
            // Buscar coincidencias equivalentes
            foreach ($posiciones as $nombreOrden => $posicion) {
                if (sonNombresEquivalentesReporte($nombreMateria, $nombreOrden)) {
                    $encontrado = true;
                    $posicionEncontrada = $posicion;
                    break;
                }
            }
        }
        
        if ($encontrado) {
            $contenidosConOrden[] = [
                'contenido' => $contenido,
                'posicion' => $posicionEncontrada
            ];
        } else {
            $contenidosSinOrden[] = $contenido;
        }
    }
    
    // Ordenar los contenidos que están en el orden personalizado
    usort($contenidosConOrden, function($a, $b) {
        return $a['posicion'] <=> $b['posicion'];
    });
    
    // Ordenar alfabéticamente los contenidos que no están en el orden personalizado
    usort($contenidosSinOrden, function($a, $b) {
        $nombreA = $a['nombre'] ?? '';
        $nombreB = $b['nombre'] ?? '';
        return strcasecmp($nombreA, $nombreB);
    });
    
    // Combinar: primero los del orden personalizado, luego los otros
    $resultado = [];
    
    // Agregar contenidos con orden personalizado
    foreach ($contenidosConOrden as $contenidoConOrden) {
        $resultado[] = $contenidoConOrden['contenido'];
    }
    
    // Agregar contenidos sin orden personalizado al final
    foreach ($contenidosSinOrden as $contenidoSinOrden) {
        $resultado[] = $contenidoSinOrden;
    }
    
    return $resultado;
}

// Incluir FPDF UTF-8
require('lib/fpdf_utf8.php');

// Clase base simplificada para reportes
class ReporteSimplificadoPDF extends FPDF_UTF8 {
    protected $anioActivo;
    
    public function __construct($anioActivo) {
        parent::__construct('P', 'mm', 'A4');
        $this->anioActivo = $anioActivo;
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 20);
    }
    
    function Header() {
        // Logo
        if (file_exists('assets/img/logo.png')) {
            $this->Image('assets/img/logo.png', 15, 10, 25, 25);
        }
        
        // Título principal
        $this->SetFont('Arial', 'B', 18);
        $this->SetY(10);
        $this->Cell(0, 10, 'ESCUELA TÉCNICA HENRY FORD', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, $this->getTituloReporte(), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Ciclo Lectivo ' . $this->anioActivo, 0, 1, 'C');
        $this->Ln(5);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 10, $this->PageNo(), 0, 0, 'C');
    }
    
    function getTituloReporte() {
        return 'REPORTE DE CONTENIDOS Y CALIFICACIONES';
    }
    
    // ✅ FUNCIÓN CORREGIDA: Calcular estadísticas con lógica correcta
	function calcularEstadisticas($contenidos) {
    $stats = [
        'total' => count($contenidos),
        'calificados' => 0,
        'acreditados' => 0,
        'no_acreditados' => 0 // ✅ Solo "No Acreditado", no "No Corresponde"
    ];
    
    foreach ($contenidos as $contenido) {
        $calificacion = trim($contenido['calificacion_cualitativa'] ?? '');
        
        if (!empty($calificacion)) {
            $stats['calificados']++;
            
            if ($calificacion === 'Acreditado') {
                $stats['acreditados']++;
            } elseif ($calificacion === 'No Acreditado') {
                // ✅ SOLO "No Acreditado" cuenta aquí
                $stats['no_acreditados']++;
            }
            // ✅ "No Corresponde" NO se cuenta como no acreditado
        }
    }
    
    return $stats;
}
    
    function infoBasica($titulo, $datos) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(0, 8, $titulo, 1, 1, 'L', true);
        
        $this->SetFont('Arial', '', 10);
        foreach ($datos as $etiqueta => $valor) {
            $this->Cell(50, 6, $etiqueta . ':', 0, 0, 'L');
            $this->Cell(0, 6, convertirMayusculasConTildes($valor), 0, 1, 'L');
        }
        $this->Ln(3);
    }
    
    function tablaContenidos($contenidos, $mostrarEstudiante = false) {
        if (empty($contenidos)) {
            $this->SetFont('Arial', 'I', 10);
            $this->Cell(0, 6, 'No hay contenidos registrados.', 0, 1, 'C');
            return;
        }
        
        // Cabeceras simplificadas - sin fecha ni bimestre
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(200, 220, 255);
        
        if ($mostrarEstudiante) {
            $this->Cell(60, 7, 'Estudiante', 1, 0, 'C', true);
            $this->Cell(95, 7, 'Contenido', 1, 0, 'C', true);
            $this->Cell(30, 7, 'Calificación', 1, 1, 'C', true);
        } else {
            $this->Cell(150, 7, 'Contenido', 1, 0, 'C', true);
            $this->Cell(30, 7, 'Calificación', 1, 1, 'C', true);
        }
        
        // Datos
        $this->SetFont('Arial', '', 8);
        $fill = false;
        
        foreach ($contenidos as $contenido) {
            if ($this->GetY() > 260) {
                $this->AddPage();
                // Repetir cabeceras después de salto de página
                $this->SetFont('Arial', 'B', 9);
                $this->SetFillColor(200, 220, 255);
                if ($mostrarEstudiante) {
                    $this->Cell(60, 7, 'Estudiante', 1, 0, 'C', true);
                    $this->Cell(95, 7, 'Contenido', 1, 0, 'C', true);
                    $this->Cell(30, 7, 'Calificación', 1, 1, 'C', true);
                } else {
                    $this->Cell(150, 7, 'Contenido', 1, 0, 'C', true);
                    $this->Cell(30, 7, 'Calificación', 1, 1, 'C', true);
                }
                $this->SetFont('Arial', '', 8);
            }
            
            $this->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
            
            if ($mostrarEstudiante) {
                $estudiante = (isset($contenido['apellido']) ? $contenido['apellido'] . ', ' . $contenido['nombre'] : 'N/A');
                if (strlen($estudiante) > 35) {
                    $estudiante = substr($estudiante, 0, 32) . '...';
                }
                $this->Cell(60, 6, $estudiante, 1, 0, 'L', $fill);
                
                $titulo = convertirMayusculasConTildes($contenido['titulo']);
                 if (strlen($titulo) > 70) {
                     $titulo = substr($titulo, 0, 67) . '...';
                 }
                $this->Cell(95, 6, $titulo, 1, 0, 'L', $fill);
            } else {
                $titulo = convertirMayusculasConTildes($contenido['titulo']);
             if (strlen($titulo) > 100) {
                 $titulo = substr($titulo, 0, 97) . '...';
             }
                $this->Cell(150, 6, $titulo, 1, 0, 'L', $fill);
            }
            
            // Calificación con color
            $calificacion = $contenido['calificacion_cualitativa'] ?? 'Sin calificar';
            if ($calificacion == 'Acreditado') {
                $this->SetFillColor(144, 238, 144);
            } elseif ($calificacion == 'No Acreditado') {
                $this->SetFillColor(255, 182, 193);
            } else {
                $this->SetFillColor(240, 240, 240);
            }
            
            $this->Cell(30, 6, $calificacion, 1, 1, 'C', true);
            
            $fill = !$fill;
        }
    }
    
    
	    function resumenEstadisticas($stats, $titulo = 'RESUMEN') {
	    $this->Ln(3);
	    $this->SetFont('Arial', 'B', 10);
	    $this->SetFillColor(173, 216, 230);
	    
	    // ✅ CALCULAR PORCENTAJES CORREGIDOS
	    $porcentajeCalificados = $stats['total'] > 0 ? round(($stats['calificados'] / $stats['total']) * 100, 1) : 0;
	    $porcentajeAcreditados = $stats['calificados'] > 0 ? round(($stats['acreditados'] / $stats['calificados']) * 100, 1) : 0;
	    $porcentajeNoAcreditados = $stats['calificados'] > 0 ? round(($stats['no_acreditados'] / $stats['calificados']) * 100, 1) : 0;
	    
	    // ✅ CONSTRUIR RESUMEN CORREGIDO - SIN INCLUIR "NO CORRESPONDE" COMO NO ACREDITADO
	    $resumen = "$titulo: Total: {$stats['total']} | Calificados: {$stats['calificados']}";
	    
	    if ($stats['calificados'] > 0) {
	        $resumen .= " | Acreditados: {$stats['acreditados']} ({$porcentajeAcreditados}%)";
	        
	        // ✅ SOLO MOSTRAR "No Acreditados" SI HAY ALGUNO
	        if ($stats['no_acreditados'] > 0) {
	            $resumen .= " | No Acreditados: {$stats['no_acreditados']} ({$porcentajeNoAcreditados}%)";
	        }
	    }
	    
	    $this->Cell(0, 6, $resumen, 1, 1, 'L', true);
	    $this->Ln(2);
	}
}

// Clases específicas para cada tipo de reporte
class ReporteAlumnoMateriaPDF extends ReporteSimplificadoPDF {
    private $estudiante;
    private $materia;
    
    public function __construct($estudiante, $materia, $anioActivo) {
        parent::__construct($anioActivo);
        $this->estudiante = $estudiante;
        $this->materia = $materia;
    }
    
    function getTituloReporte() {
        return 'REPORTE INDIVIDUAL DE CONTENIDOS';
    }
    
    function generarContenido($contenidos) {
        // Información del estudiante y materia
        $this->infoBasica('INFORMACIÓN DEL ESTUDIANTE', [
               'Apellido y Nombre' => convertirMayusculasConTildes($this->estudiante['apellido'] . ', ' . $this->estudiante['nombre']),
               'Materia' => convertirMayusculasConTildes($this->materia['nombre']),
               'Profesor/a' => convertirMayusculasConTildes($this->materia['profesor_nombre']),
               'Curso' => convertirMayusculasConTildes($this->materia['curso_nombre'])
            ]);
        
        // Tabla de contenidos
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, 'CONTENIDOS Y CALIFICACIONES', 0, 1, 'L');
        $this->Ln(2);
        
        $this->tablaContenidos($contenidos);
        
        // Estadísticas
        $stats = $this->calcularEstadisticas($contenidos);
        $this->resumenEstadisticas($stats);
    }
}

class ReporteAlumnoCompletoPDF extends ReporteSimplificadoPDF {
    private $estudiante;
    
    public function __construct($estudiante, $anioActivo) {
        parent::__construct($anioActivo);
        $this->estudiante = $estudiante;
    }
    
    function getTituloReporte() {
        return 'REPORTE COMPLETO DEL ESTUDIANTE';
    }
    
    function generarContenido($materias) {
        // Información del estudiante
        $this->infoBasica('INFORMACIÓN DEL ESTUDIANTE', [
            'Apellido y Nombre' => convertirMayusculasConTildes($this->estudiante['apellido'] . ', ' . $this->estudiante['nombre']),
            'Curso' => convertirMayusculasConTildes($this->estudiante['curso_nombre'])
        ]);
        
        // Tablas individuales por materia
        $statsGenerales = ['total' => 0, 'calificados' => 0, 'acreditados' => 0, 'no_acreditados' => 0];
        
        foreach ($materias as $indice => $materia) {
            if (!empty($materia['contenidos'])) {
                // ✅ USAR EL NOMBRE CORRECTO (GRUPO + MATERIA SI APLICA)
                $nombreMostrar = isset($materia['nombre_mostrar']) ? $materia['nombre_mostrar'] : $materia['materia_nombre'];
                
                // Título de la materia
                $this->SetFont('Arial', 'B', 12);
                $this->SetFillColor(230, 240, 250);
                $this->Cell(0, 7, convertirMayusculasConTildes($nombreMostrar), 1, 1, 'L', true);
                
                // Cabeceras de la tabla
                $this->SetFont('Arial', 'B', 9);
                $this->SetFillColor(200, 220, 255);
                $this->Cell(150, 6, 'Contenido', 1, 0, 'C', true);
                $this->Cell(30, 6, 'Calificación', 1, 1, 'C', true);
                
                // Contenidos de esta materia
                $this->SetFont('Arial', '', 8);
                $fill = false;
                
                foreach ($materia['contenidos'] as $contenido) {
                    // Verificar si necesita nueva página
                    if ($this->GetY() > 250) {
                        $this->AddPage();
                        // Repetir título y cabeceras
                        $this->SetFont('Arial', 'B', 11);
                        $this->SetFillColor(230, 240, 250);
                        $this->Cell(0, 7, convertirMayusculasConTildes($nombreMostrar) . ' - Continuación', 1, 1, 'L', true);
                        
                        $this->SetFont('Arial', 'B', 9);
                        $this->SetFillColor(200, 220, 255);
                        $this->Cell(150, 6, 'Contenido', 1, 0, 'C', true);
                        $this->Cell(30, 6, 'Calificación', 1, 1, 'C', true);
                        $this->SetFont('Arial', '', 8);
                    }
                    
                    $this->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
                    
                    $titulo = convertirMayusculasConTildes($contenido['titulo']);
                    if (strlen($titulo) > 100) {
                        $titulo = substr($titulo, 0, 97) . '...';
                    }
                    $this->Cell(150, 6, $titulo, 1, 0, 'L', $fill);
                    
                    // Calificación con color
                    $calificacion = $contenido['calificacion_cualitativa'] ?? 'Sin calificar';
                    if ($calificacion == 'Acreditado') {
                        $this->SetFillColor(144, 238, 144);
                    } elseif ($calificacion == 'No Acreditado') {
                        $this->SetFillColor(255, 182, 193);
                    } else {
                        $this->SetFillColor(240, 240, 240);
                    }
                    
                    $this->Cell(30, 6, $calificacion, 1, 1, 'C', true);
                    
                    $fill = !$fill;
                }
                
                // ✅ ESTADÍSTICAS CORREGIDAS DE ESTA MATERIA
                $stats = $this->calcularEstadisticas($materia['contenidos']);
                $statsGenerales['total'] += $stats['total'];
                $statsGenerales['calificados'] += $stats['calificados'];
                $statsGenerales['acreditados'] += $stats['acreditados'];
                $statsGenerales['no_acreditados'] += $stats['no_acreditados'];
                
                $this->SetFont('Arial', 'B', 8);
                $this->SetFillColor(245, 245, 245);
                $porcentajeCalificados = $stats['total'] > 0 ? round(($stats['calificados'] / $stats['total']) * 100, 1) : 0;
                $porcentajeAcreditados = $stats['calificados'] > 0 ? round(($stats['acreditados'] / $stats['calificados']) * 100, 1) : 0;
                $porcentajeNoAcreditados = $stats['calificados'] > 0 ? round(($stats['no_acreditados'] / $stats['calificados']) * 100, 1) : 0;

                $resumen = "Resumen: Total: {$stats['total']} | Calificados: {$stats['calificados']}";
                if ($stats['calificados'] > 0) {
                    $resumen .= " | Acreditados: {$stats['acreditados']} ({$porcentajeAcreditados}%) | No Acreditados: {$stats['no_acreditados']} ({$porcentajeNoAcreditados}%)";
                }
                $this->Cell(0, 5, $resumen, 1, 1, 'L', true);
                
                // Espacio entre materias (solo una fila)
                if ($indice < count($materias) - 1) {
                    $this->Ln(6);
                }
            }
        }
        
        // Estadísticas generales
        $this->Ln(3);
        $this->resumenEstadisticas($statsGenerales, 'RESUMEN GENERAL');
    }
}
    
    function tablaContenidosConMateria($contenidos) {
        if (empty($contenidos)) {
            $this->SetFont('Arial', 'I', 10);
            $this->Cell(0, 6, 'No hay contenidos registrados.', 0, 1, 'C');
            return;
        }
        
        // Cabeceras simplificadas - sin fecha ni bimestre
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(200, 220, 255);
        $this->Cell(55, 7, 'Materia', 1, 0, 'C', true);
        $this->Cell(95, 7, 'Contenido', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Calificación', 1, 1, 'C', true);
        
        // Datos
        $this->SetFont('Arial', '', 8);
        $fill = false;
        
        foreach ($contenidos as $contenido) {
            if ($this->GetY() > 260) {
                $this->AddPage();
                // Repetir cabeceras
                $this->SetFont('Arial', 'B', 9);
                $this->SetFillColor(200, 220, 255);
                $this->Cell(55, 7, 'Materia', 1, 0, 'C', true);
                $this->Cell(95, 7, 'Contenido', 1, 0, 'C', true);
                $this->Cell(30, 7, 'Calificación', 1, 1, 'C', true);
                $this->SetFont('Arial', '', 8);
            }
            
            $this->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
            
            $materia = $contenido['materia_info'];
            if (strlen($materia) > 35) {
                $materia = substr($materia, 0, 32) . '...';
            }
            $this->Cell(55, 6, $materia, 1, 0, 'L', $fill);
            
            $titulo = $contenido['titulo'];
            if (strlen($titulo) > 70) {
                $titulo = substr($titulo, 0, 67) . '...';
            }
            $this->Cell(95, 6, $titulo, 1, 0, 'L', $fill);
            
            // Calificación con color
            $calificacion = $contenido['calificacion_cualitativa'] ?? 'Sin calificar';
            if ($calificacion == 'Acreditado') {
                $this->SetFillColor(144, 238, 144);
            } elseif ($calificacion == 'No Acreditado') {
                $this->SetFillColor(255, 182, 193);
            } else {
                $this->SetFillColor(240, 240, 240);
            }
            
            $this->Cell(30, 6, $calificacion, 1, 1, 'C', true);
            
            $fill = !$fill;
        }
    }

class ReporteMateriaCompletaPDF extends ReporteSimplificadoPDF {
    private $materia;
    
    public function __construct($materia, $anioActivo) {
        parent::__construct($anioActivo);
        $this->materia = $materia;
    }
    
    function getTituloReporte() {
        return 'REPORTE COMPLETO DE MATERIA';
    }
    
    function generarContenido($estudiantes) {
        // ✅ USAR EL NOMBRE CORRECTO DE LA MATERIA
        $nombreMateriaCompleto = isset($this->materia['nombre_mostrar']) ? $this->materia['nombre_mostrar'] : $this->materia['nombre'];
        
        // Información de la materia
        $this->infoBasica('INFORMACIÓN DE LA MATERIA', [
            'Materia' => convertirMayusculasConTildes($nombreMateriaCompleto),
            'Profesor' => convertirMayusculasConTildes($this->materia['profesor_nombre']),
            'Curso' => convertirMayusculasConTildes($this->materia['curso_nombre']),
            'Total de Estudiantes' => count($estudiantes)
        ]);
        
        // Tablas individuales por estudiante
        $statsGenerales = ['total' => 0, 'calificados' => 0, 'acreditados' => 0, 'no_acreditados' => 0];
        
        foreach ($estudiantes as $indice => $estudiante) {
            if (!empty($estudiante['contenidos'])) {
                // Título del estudiante
                $this->SetFont('Arial', 'B', 11);
                $this->SetFillColor(230, 240, 250);
                $this->Cell(0, 7, convertirMayusculasConTildes($estudiante['apellido'] . ', ' . $estudiante['nombre']), 1, 1, 'L', true);
                
                // Cabeceras de la tabla
                $this->SetFont('Arial', 'B', 9);
                $this->SetFillColor(200, 220, 255);
                $this->Cell(150, 6, 'Contenido', 1, 0, 'C', true);
                $this->Cell(30, 6, 'Calificación', 1, 1, 'C', true);
                
                // Contenidos de este estudiante
                $this->SetFont('Arial', '', 8);
                $fill = false;
                
                foreach ($estudiante['contenidos'] as $contenido) {
                    // Verificar si necesita nueva página
                    if ($this->GetY() > 250) {
                        $this->AddPage();
                        // Repetir título y cabeceras
                        $this->SetFont('Arial', 'B', 11);
                        $this->SetFillColor(230, 240, 250);
                        $this->Cell(0, 7, convertirMayusculasConTildes($estudiante['apellido'] . ', ' . $estudiante['nombre']) . ' - Continuación', 1, 1, 'L', true);
                        
                        $this->SetFont('Arial', 'B', 9);
                        $this->SetFillColor(200, 220, 255);
                        $this->Cell(150, 6, 'Contenido', 1, 0, 'C', true);
                        $this->Cell(30, 6, 'Calificación', 1, 1, 'C', true);
                        $this->SetFont('Arial', '', 8);
                    }
                    
                    $this->SetFillColor($fill ? 250 : 255, $fill ? 250 : 255, $fill ? 250 : 255);
                    
                    $titulo = convertirMayusculasConTildes($contenido['titulo']);
                    if (strlen($titulo) > 100) {
                        $titulo = substr($titulo, 0, 97) . '...';
                    }
                    $this->Cell(150, 6, $titulo, 1, 0, 'L', $fill);
                    
                    // Calificación con color
                    $calificacion = $contenido['calificacion_cualitativa'] ?? 'Sin calificar';
                    if ($calificacion == 'Acreditado') {
                        $this->SetFillColor(144, 238, 144);
                    } elseif ($calificacion == 'No Acreditado') {
                        $this->SetFillColor(255, 182, 193);
                    } else {
                        $this->SetFillColor(240, 240, 240);
                    }
                    
                    $this->Cell(30, 6, $calificacion, 1, 1, 'C', true);
                    
                    $fill = !$fill;
                }
                
                // ✅ ESTADÍSTICAS CORREGIDAS DE ESTE ESTUDIANTE
                $stats = $this->calcularEstadisticas($estudiante['contenidos']);
                $statsGenerales['total'] += $stats['total'];
                $statsGenerales['calificados'] += $stats['calificados'];
                $statsGenerales['acreditados'] += $stats['acreditados'];
                $statsGenerales['no_acreditados'] += $stats['no_acreditados'];
                
                $this->SetFont('Arial', 'B', 8);
                $this->SetFillColor(245, 245, 245);
                $porcentajeCalificados = $stats['total'] > 0 ? round(($stats['calificados'] / $stats['total']) * 100, 1) : 0;
                $porcentajeAcreditados = $stats['calificados'] > 0 ? round(($stats['acreditados'] / $stats['calificados']) * 100, 1) : 0;
                $porcentajeNoAcreditados = $stats['calificados'] > 0 ? round(($stats['no_acreditados'] / $stats['calificados']) * 100, 1) : 0;
                
                $resumen = "Resumen: Total: {$stats['total']} | Calificados: {$stats['calificados']} ({$porcentajeCalificados}%)";
                if ($stats['calificados'] > 0) {
                    $resumen .= " | Acreditados: {$stats['acreditados']} ({$porcentajeAcreditados}%) | No Acreditados: {$stats['no_acreditados']} ({$porcentajeNoAcreditados}%)";
                }
                $this->Cell(0, 5, $resumen, 1, 1, 'L', true);
                
                // Espacio entre estudiantes (solo una fila)
                if ($indice < count($estudiantes) - 1) {
                    $this->Ln(6);
                }
            }
        }
        
        // Estadísticas generales
        $this->Ln(3);
        $this->resumenEstadisticas($statsGenerales, 'RESUMEN GENERAL');
    }
}

// Función para limpiar nombre de archivo
function limpiarNombreArchivo($texto) {
    $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ', ' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|');
    $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N', '_', '_', '_', '_', '_', '_', '_', '_', '_', '_');
    return str_replace($buscar, $reemplazar, $texto);
}

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

// Crear PDF según el tipo
if ($datosReporte['tipo'] == 'alumno_materia') {
    $pdf = new ReporteAlumnoMateriaPDF($datosReporte['estudiante'], $datosReporte['materia'], $anioActivo);
    $pdf->SetTitle('Reporte Individual - ' . $datosReporte['estudiante']['apellido'] . ', ' . $datosReporte['estudiante']['nombre']);
    $pdf->AddPage();
    $pdf->generarContenido($datosReporte['contenidos']);
    
    $nombreArchivo = 'Reporte_Individual_' . 
                     limpiarNombreArchivo($datosReporte['estudiante']['apellido']) . '_' . 
                     limpiarNombreArchivo($datosReporte['estudiante']['nombre']) . '_' . 
                     limpiarNombreArchivo($datosReporte['materia']['codigo']) . '_' . 
                     date('Ymd') . '.pdf';

} elseif ($datosReporte['tipo'] == 'alumno_completo') {
    $pdf = new ReporteAlumnoCompletoPDF($datosReporte['estudiante'], $anioActivo);
    $pdf->SetTitle('Reporte Completo - ' . $datosReporte['estudiante']['apellido'] . ', ' . $datosReporte['estudiante']['nombre']);
    $pdf->AddPage();
    $pdf->generarContenido($datosReporte['materias']);
    
    $nombreArchivo = 'Reporte_Completo_' . 
                     limpiarNombreArchivo($datosReporte['estudiante']['apellido']) . '_' . 
                     limpiarNombreArchivo($datosReporte['estudiante']['nombre']) . '_' . 
                     date('Ymd') . '.pdf';

} elseif ($datosReporte['tipo'] == 'materia_completa') {
    $pdf = new ReporteMateriaCompletaPDF($datosReporte['materia'], $anioActivo);
    $pdf->SetTitle('Reporte de Materia - ' . $datosReporte['materia']['nombre']);
    $pdf->AddPage();
    $pdf->generarContenido($datosReporte['estudiantes']);
    
    $nombreArchivo = 'Reporte_Materia_' . 
                     limpiarNombreArchivo($datosReporte['materia']['nombre']) . '_' . 
                     limpiarNombreArchivo($datosReporte['materia']['codigo']) . '_' . 
                     date('Ymd') . '.pdf';
}

// Limpiar el buffer
ob_clean();

// Salida del PDF
$pdf->Output('D', $nombreArchivo);
exit;
?>



