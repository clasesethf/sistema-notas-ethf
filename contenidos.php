<?php
/**
 * contenidos.php - Gestión de contenidos por materia - ACTUALIZADO PARA EQUIPOS DOCENTES
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Soporte para subgrupos de estudiantes y recursados con detección automática de períodos
 * NUEVO: Soporte para equipos docentes (múltiples profesores por materia)
 * CORREGIDO: Problemas de sesión y headers
 */

// IMPORTANTE: Iniciar buffer de salida y sesión ANTES de cualquier verificación
ob_start();

// Inicializar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificar que existe la sesión user_type ANTES de hacer cualquier verificación
if (!isset($_SESSION['user_type']) || !isset($_SESSION['user_id'])) {
    // Redirigir al login si no hay sesión válida
    header('Location: login.php');
    exit;
}

// AHORA SÍ verificar que el usuario sea profesor
if ($_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Incluir archivos necesarios DESPUÉS de las verificaciones de sesión
require_once 'config.php';
require_once 'sistema_periodos_automaticos.php';

// Incluir funciones auxiliares para equipos docentes
if (file_exists('funciones_equipos.php')) {
    require_once 'funciones_equipos.php';
}

$profesorId = $_SESSION['user_id'];
$db = Database::getInstance();

// NUEVO: Verificar y crear columnas para múltiples profesores si no existen
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
$error_message = '';
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $error_message = '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
        $cicloLectivoId = 0;
        $anioActivo = date('Y');
    } else {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
    }
} catch (Exception $e) {
    $error_message = '<div class="alert alert-danger">Error al conectar con la base de datos: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
}

// Procesar selección de materia
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;

// Procesar acciones POST ANTES de incluir header.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['accion'])) {
            switch ($_POST['accion']) {
                case 'crear_contenido':
                    $materiaCursoId = intval($_POST['materia_curso_id']);
                    $titulo = trim($_POST['titulo']);
                    $descripcion = trim($_POST['descripcion']);
                    $fechaClase = $_POST['fecha_clase'];
                    $tipoEvaluacion = $_POST['tipo_evaluacion'];
                    
                    // Validaciones
                    if (empty($titulo)) {
                        throw new Exception('El título es obligatorio');
                    }
                    if (!in_array($tipoEvaluacion, ['numerica', 'cualitativa'])) {
                        throw new Exception('Tipo de evaluación inválido');
                    }
                    
                    // ACTUALIZADO: Verificar que la materia pertenece al profesor (incluye equipos docentes)
                    $materiaVerificacion = $db->fetchOne(
                        "SELECT id FROM materias_por_curso 
                         WHERE id = ? AND (profesor_id = ? OR profesor_id_2 = ? OR profesor_id_3 = ?)",
                        [$materiaCursoId, $profesorId, $profesorId, $profesorId]
                    );
                    
                    if (!$materiaVerificacion) {
                        throw new Exception('No tiene permisos para esta materia');
                    }
                    
                    // Detectar período automáticamente usando la clase SistemaPeriodos
                    $periodoDetectado = SistemaPeriodos::detectarPeriodo($fechaClase, $anioActivo);
                    $bimestre = $periodoDetectado['bimestre'];
                    
                    // Si es período de intensificación, usar el último bimestre del cuatrimestre
                    if ($periodoDetectado['es_intensificacion']) {
                        $bimestre = ($periodoDetectado['cuatrimestre'] == 1) ? 2 : 4;
                    }
                    
                    // Crear contenido
                    $db->query(
                        "INSERT INTO contenidos (materia_curso_id, profesor_id, titulo, descripcion, bimestre, fecha_clase, tipo_evaluacion)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$materiaCursoId, $profesorId, $titulo, $descripcion, $bimestre, $fechaClase, $tipoEvaluacion]
                    );
                    
                    // NUEVO: Registrar actividad de equipo docente si aplica
                    if (function_exists('registrarActividadEquipo')) {
                        registrarActividadEquipo($db, $materiaCursoId, $profesorId, 'crear_contenido', "Creó contenido: $titulo");
                    }
                    
                    $_SESSION['message'] = 'Contenido creado correctamente';
                    $_SESSION['message_type'] = 'success';
                    break;
                    
                case 'eliminar_contenido':
                    $contenidoId = intval($_POST['contenido_id']);
                    
                    // ACTUALIZADO: Verificar que el contenido pertenece a una materia accesible por el profesor
                    $contenidoVerificacion = $db->fetchOne(
                        "SELECT c.id, c.titulo, c.materia_curso_id FROM contenidos c
                         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                         WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)",
                        [$contenidoId, $profesorId, $profesorId, $profesorId]
                    );
                    
                    if (!$contenidoVerificacion) {
                        throw new Exception('No tiene permisos para eliminar este contenido');
                    }
                    
                    // Eliminar calificaciones asociadas
                    $db->query("DELETE FROM contenidos_calificaciones WHERE contenido_id = ?", [$contenidoId]);
                    
                    // Marcar contenido como inactivo
                    $db->query("UPDATE contenidos SET activo = 0 WHERE id = ?", [$contenidoId]);
                    
                    // NUEVO: Registrar actividad de equipo docente si aplica
                    if (function_exists('registrarActividadEquipo')) {
                        registrarActividadEquipo($db, $contenidoVerificacion['materia_curso_id'], $profesorId, 'eliminar_contenido', "Eliminó contenido: {$contenidoVerificacion['titulo']}");
                    }
                    
                    $_SESSION['message'] = 'Contenido eliminado correctamente';
                    $_SESSION['message_type'] = 'success';
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    // Redireccionar para evitar reenvío del formulario
    header('Location: contenidos.php' . ($materiaSeleccionada ? '?materia=' . $materiaSeleccionada : ''));
    exit;
}

// AHORA SÍ incluir el header después de procesar todo
require_once 'header.php';

// Mostrar errores si los hay
if (!empty($error_message)) {
    echo $error_message;
}

// ACTUALIZADO: Obtener materias del profesor (incluye equipos docentes)
$materias = [];
$equiposDocentes = [];
try {
    // Usar consulta idéntica a mis_materias.php
    $materiasBase = $db->fetchAll(
        "SELECT mp.id as materia_curso_id, m.nombre as materia_nombre, m.codigo,
                c.nombre as curso_nombre, c.anio as curso_anio, c.id as curso_id, 
                COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
           AND c.ciclo_lectivo_id = ?
         ORDER BY c.anio, m.nombre",
        [$profesorId, $profesorId, $profesorId, $cicloLectivoId]
    );

    // Procesar cada materia para agregar los campos que necesita contenidos.php
    foreach ($materiasBase as $materia) {
        // Calcular información de equipo docente
        $totalProfesores = 0;
        $posicionProfesor = 0;
        
        if ($materia['profesor_id']) {
            $totalProfesores++;
            if ($materia['profesor_id'] == $profesorId) $posicionProfesor = 1;
        }
        if ($materia['profesor_id_2']) {
            $totalProfesores++;
            if ($materia['profesor_id_2'] == $profesorId) $posicionProfesor = 2;
        }
        if ($materia['profesor_id_3']) {
            $totalProfesores++;
            if ($materia['profesor_id_3'] == $profesorId) $posicionProfesor = 3;
        }
        
        // Agregar campos adicionales que espera contenidos.php
        $materia['id'] = $materia['materia_curso_id']; // Para compatibilidad
        $materia['total_profesores'] = $totalProfesores;
        $materia['posicion_profesor'] = $posicionProfesor;
        $materia['es_equipo'] = $totalProfesores > 1;
        
        // Obtener información de equipos docentes si es necesario
        if ($materia['es_equipo']) {
            if (function_exists('obtenerEquipoDocente')) {
                $equiposDocentes[$materia['materia_curso_id']] = obtenerEquipoDocente($db, $materia['materia_curso_id']);
            } else {
                // Fallback básico
                $equipo = [];
                if ($materia['profesor_id']) {
                    $prof1 = $db->fetchOne("SELECT id, apellido, nombre FROM usuarios WHERE id = ?", [$materia['profesor_id']]);
                    if ($prof1) $equipo[] = ['id' => $prof1['id'], 'posicion' => 1, 'nombre_completo' => $prof1['apellido'] . ', ' . $prof1['nombre']];
                }
                if ($materia['profesor_id_2']) {
                    $prof2 = $db->fetchOne("SELECT id, apellido, nombre FROM usuarios WHERE id = ?", [$materia['profesor_id_2']]);
                    if ($prof2) $equipo[] = ['id' => $prof2['id'], 'posicion' => 2, 'nombre_completo' => $prof2['apellido'] . ', ' . $prof2['nombre']];
                }
                if ($materia['profesor_id_3']) {
                    $prof3 = $db->fetchOne("SELECT id, apellido, nombre FROM usuarios WHERE id = ?", [$materia['profesor_id_3']]);
                    if ($prof3) $equipo[] = ['id' => $prof3['id'], 'posicion' => 3, 'nombre_completo' => $prof3['apellido'] . ', ' . $prof3['nombre']];
                }
                $equiposDocentes[$materia['materia_curso_id']] = $equipo;
            }
        }
        
        $materias[] = $materia;
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener materias: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Variables para almacenar datos
$contenidos = [];
$materiaInfo = null;
$estudiantesMateria = [];
$equipoDocenteActual = [];

// Si se seleccionó una materia
if ($materiaSeleccionada) {
    try {
        // ACTUALIZADO: Obtener información de la materia con verificación de acceso para equipos
        $materiaInfo = $db->fetchOne(
            "SELECT mp.*, m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre, c.anio as curso_anio,
                    COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                    mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                    (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)",
            [$materiaSeleccionada, $profesorId, $profesorId, $profesorId]
        );
        
        if ($materiaInfo) {
            // NUEVO: Obtener equipo docente de la materia actual
            if (isset($equiposDocentes[$materiaSeleccionada])) {
                $equipoDocenteActual = $equiposDocentes[$materiaSeleccionada];
            } elseif (function_exists('obtenerEquipoDocente')) {
                $equipoDocenteActual = obtenerEquipoDocente($db, $materiaSeleccionada);
            }
            
            // Obtener TODOS los contenidos de la materia
            $contenidos = $db->fetchAll(
                "SELECT c.*, u.apellido as creador_apellido, u.nombre as creador_nombre 
                 FROM contenidos c
                 LEFT JOIN usuarios u ON c.profesor_id = u.id
                 WHERE c.materia_curso_id = ? AND c.activo = 1
                 ORDER BY c.bimestre, c.fecha_clase, c.orden, c.id",
                [$materiaSeleccionada]
            );

            // Obtener información del curso de la materia
            $cursoInfo = $db->fetchOne(
                "SELECT c.id as curso_id, c.nombre as curso_nombre, c.anio
                 FROM materias_por_curso mp
                 JOIN cursos c ON mp.curso_id = c.id
                 WHERE mp.id = ?",
                [$materiaSeleccionada]
            );

            // Obtener estudiantes según el tipo de materia (MANTENIDO IGUAL)
            if ($materiaInfo['requiere_subgrupos']) {
                // Para materias con subgrupos, obtener estudiantes asignados específicamente
                $estudiantesMateria = $db->fetchAll(
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
                    [$materiaSeleccionada, $cicloLectivoId]
                );
            } else {
                // Para materias regulares, obtener estudiantes del curso + recursantes
                
                // 1. Estudiantes regulares del curso
                $estudiantesRegulares = $db->fetchAll(
                    "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                            'regular' as tipo_matricula,
                            NULL as curso_origen,
                            NULL as subgrupo_nombre
                     FROM usuarios u 
                     JOIN matriculas m ON u.id = m.estudiante_id 
                     WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                     ORDER BY u.apellido, u.nombre",
                    [$cursoInfo['curso_id']]
                );

                // 2. Estudiantes recursando esta materia específica
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
                    [$materiaSeleccionada, $cicloLectivoId]
                );

                // 3. Combinar ambos grupos de estudiantes
                $estudiantesMateria = array_merge($estudiantesRegulares, $estudiantesRecursando);

                // 4. Filtrar estudiantes que tienen materias liberadas para recursado
                $estudiantesFiltrados = [];
                foreach ($estudiantesMateria as $estudiante) {
                    // Verificar si este estudiante tiene liberada esta materia para recursar otra
                    $materiaLiberada = $db->fetchOne(
                        "SELECT id FROM materias_recursado 
                         WHERE estudiante_id = ? AND materia_liberada_id = ? AND estado = 'activo'",
                        [$estudiante['id'], $materiaSeleccionada]
                    );
                    
                    // Si no tiene liberada esta materia, incluirlo en la lista
                    if (!$materiaLiberada) {
                        $estudiantesFiltrados[] = $estudiante;
                    }
                }

                $estudiantesMateria = $estudiantesFiltrados;
            }
            
            // NUEVO: Registrar actividad de acceso si es parte de un equipo
            if (function_exists('registrarActividadEquipo') && count($equipoDocenteActual) > 1) {
                registrarActividadEquipo($db, $materiaSeleccionada, $profesorId, 'acceso_contenidos', 'Acceso a gestión de contenidos');
            }
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . htmlspecialchars($e->getMessage()) . '</div>';
        $estudiantesMateria = [];
    }
}

// ACTUALIZADO: Obtener estadísticas de contenidos si hay materias - incluye equipos
$estadisticasContenidos = [];
if (!empty($materias)) {
    try {
        $materiasIds = array_column($materias, 'id');
        $placeholders = implode(',', array_fill(0, count($materiasIds), '?'));
        
        $estadisticasContenidos = $db->fetchAll(
            "SELECT c.id, c.titulo, c.descripcion, c.bimestre, c.fecha_clase, c.tipo_evaluacion,
                    c.materia_curso_id, c.profesor_id, m.nombre as materia_nombre, m.codigo, cur.nombre as curso_nombre, cur.anio,
                    COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                    mp.profesor_id as materia_profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                    u.apellido as creador_apellido, u.nombre as creador_nombre,
                    (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                     CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores,
                    COUNT(DISTINCT cc.estudiante_id) as estudiantes_calificados,
                    (SELECT COUNT(DISTINCT u.id) 
                     FROM usuarios u 
                     JOIN matriculas mat ON u.id = mat.estudiante_id
                     WHERE mat.curso_id = mp.curso_id AND mat.estado = 'activo' AND u.tipo = 'estudiante') as total_estudiantes_curso
             FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos cur ON mp.curso_id = cur.id
             LEFT JOIN usuarios u ON c.profesor_id = u.id
             LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id
             WHERE c.materia_curso_id IN ($placeholders) AND c.activo = 1
             GROUP BY c.id, c.titulo, c.descripcion, c.bimestre, c.fecha_clase, c.tipo_evaluacion,
                      c.materia_curso_id, c.profesor_id, m.nombre, m.codigo, cur.nombre, cur.anio, mp.requiere_subgrupos,
                      mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, u.apellido, u.nombre
             ORDER BY c.fecha_clase DESC, cur.anio, m.nombre",
            $materiasIds
        );
        
        // Para materias con subgrupos, calcular el total real de estudiantes asignados
        foreach ($estadisticasContenidos as &$contenido) {
            if ($contenido['requiere_subgrupos']) {
                $estudiantesSubgrupos = $db->fetchOne(
                    "SELECT COUNT(DISTINCT ep.estudiante_id) as total
                     FROM estudiantes_por_materia ep
                     WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1",
                    [$contenido['materia_curso_id'], $cicloLectivoId]
                );
                
                $contenido['total_estudiantes'] = $estudiantesSubgrupos ? $estudiantesSubgrupos['total'] : 0;
                $contenido['es_subgrupos'] = true;
            } else {
                $contenido['total_estudiantes'] = $contenido['total_estudiantes_curso'];
                $contenido['es_subgrupos'] = false;
            }
            
            // NUEVO: Marcar si es equipo docente
            $contenido['es_equipo'] = $contenido['total_profesores'] > 1;
            $contenido['creado_por_mi'] = $contenido['profesor_id'] == $profesorId;
        }
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener estadísticas: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// CORRECCIÓN: Limpiar materias para el select eliminando duplicados
$materiasParaSelect = [];
$idsUtilizados = [];

foreach ($materias as $materia) {
    $id = $materia['materia_curso_id'];
    if (!in_array($id, $idsUtilizados)) {
        $materiasParaSelect[] = $materia;
        $idsUtilizados[] = $id;
    }
}

// Agrupar materias limpias por año
$materiasPorAnio = [];
foreach ($materiasParaSelect as $materia) {
    $anio = $materia['curso_anio'];
    if (!isset($materiasPorAnio[$anio])) {
        $materiasPorAnio[$anio] = [];
    }
    $materiasPorAnio[$anio][] = $materia;
}
ksort($materiasPorAnio);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            
            <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show">
                <?= $_SESSION['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php 
            unset($_SESSION['message']);
            unset($_SESSION['message_type']);
            endif; ?>
            
            <!-- Encabezado -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1 text-gray-800">
                        <i class="bi bi-list-check"></i> Gestión de Contenidos
                        
                    </h1>
                    <p class="text-muted">Administre los contenidos pedagógicos de sus materias</p>
                </div>
                <div>
                    <?php if (!empty($materiasParaSelect)): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearContenido">
                        <i class="bi bi-plus-circle"></i> Nuevo Contenido
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- NUEVA: Información sobre equipos docentes -->
            
            
            <!-- Filtros de selección -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Seleccionar Materia</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-12">
                            <label for="materia" class="form-label">Materia</label>
                            <select name="materia" id="materia" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Seleccione una materia --</option>
                                <?php foreach ($materias as $materia): ?>
                                    <option value="<?= $materia['materia_curso_id'] ?>" 
                                            <?= $materiaSeleccionada == $materia['materia_curso_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($materia['materia_nombre']) ?> - 
                                        <?= htmlspecialchars($materia['curso_nombre']) ?>
                                        <?php if ($materia['requiere_subgrupos']): ?>
                                        (Con subgrupos)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Estadísticas rápidas -->
            <?php if (!empty($estadisticasContenidos)): ?>
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Total Contenidos
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($estadisticasContenidos) ?></div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-list-check text-primary" style="font-size: 2rem;"></i>
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
                                        Completamente Calificados
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?= count(array_filter($estadisticasContenidos, function($c) { 
                                            return $c['total_estudiantes'] > 0 && $c['estudiantes_calificados'] == $c['total_estudiantes']; 
                                        })) ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
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
                                        Pendientes
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?= count(array_filter($estadisticasContenidos, function($c) { 
                                            return $c['total_estudiantes'] > 0 && $c['estudiantes_calificados'] < $c['total_estudiantes']; 
                                        })) ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-clock text-warning" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>                
            </div>
            <?php endif; ?>
            
            <!-- Tabla de contenidos de la materia seleccionada -->
            <?php if ($materiaSeleccionada && $materiaInfo): ?>
            
            <!-- NUEVA: Información del equipo docente para la materia seleccionada -->
            <?php if (count($equipoDocenteActual) > 1): ?>
            <div class="alert alert-info mb-4 d-none">
                <h6 class="alert-heading">
                    <i class="bi bi-people-fill"></i> Equipo Docente - <?= htmlspecialchars($materiaInfo['materia_nombre']) ?>
                </h6>
                <div class="row">
                    <div class="col-md-8">
                        <p class="mb-1">Esta materia es gestionada por un equipo de <?= count($equipoDocenteActual) ?> profesores:</p>
                        <div class="row">
                            <?php foreach ($equipoDocenteActual as $profesor): ?>
                            <div class="col-md-6">
                                <small class="<?= $profesor['id'] == $profesorId ? 'fw-bold text-primary' : 'text-muted' ?>">
                                    <span class="badge bg-<?= $profesor['id'] == $profesorId ? 'primary' : 'secondary' ?> badge-sm me-1">
                                        <?= $profesor['posicion'] ?>
                                    </span>
                                    <?= htmlspecialchars($profesor['nombre_completo']) ?>
                                    <?= $profesor['id'] == $profesorId ? ' (Usted)' : '' ?>
                                </small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-outline-info btn-sm" 
                                onclick="verEquipoDocente(<?= $materiaSeleccionada ?>)"
                                data-bs-toggle="modal" data-bs-target="#modalEquipoDocente">
                            <i class="bi bi-people"></i> Ver Equipo Completo
                        </button>
                    </div>
                </div>
                <hr class="my-2">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> 
                    Todos los profesores del equipo pueden crear, editar y eliminar contenidos. 
                    Las acciones quedan registradas con el autor.
                </small>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Contenidos - <?= htmlspecialchars($materiaInfo['materia_nombre']) ?> 
                        (<?= htmlspecialchars($materiaInfo['curso_nombre']) ?>)
                        <?php if (count($contenidos) > 0): ?>
                        <span class="badge bg-secondary ms-2"><?= count($contenidos) ?> contenidos</span>
                        <?php endif; ?>
                        <?php if (count($equipoDocenteActual) > 1): ?>
                        <span class="badge bg-info ms-2">
                            <i class="bi bi-people-fill"></i> Equipo de <?= count($equipoDocenteActual) ?>
                        </span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (count($contenidos) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th width="80">Bimestre</th>
                                    <th width="100">Fecha</th>
                                    <th>Título</th>
                                    <th>Descripción</th>
                                    <th width="120">Tipo Evaluación</th>
                                    <?php if (count($equipoDocenteActual) > 1): ?>
                                    <th width="120">Creado por</th>
                                    <?php endif; ?>
                                    <th width="80">Estado</th>
                                    <th width="200" class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $contador = 0;
                                foreach ($contenidos as $contenido): 
                                    $contador++;
                                    // Alternar colores de fila
                                    $claseFilaColor = ($contador % 2 == 0) ? 'table-light' : '';
                                    
                                    // NUEVO: Marcar si fue creado por el profesor actual
                                    $creadoPorMi = $contenido['profesor_id'] == $profesorId;
                                    if ($creadoPorMi && count($equipoDocenteActual) > 1) {
                                        $claseFilaColor .= ' border-primary';
                                    }
                                    
                                    // Verificar si tiene calificaciones
                                    $tieneCalificaciones = $db->fetchOne(
                                        "SELECT COUNT(*) as total FROM contenidos_calificaciones WHERE contenido_id = ?",
                                        [$contenido['id']]
                                    )['total'] ?? 0;
                                ?>
                                <tr class="<?= $claseFilaColor ?>">
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= $contenido['bimestre'] ?>° Bim</span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars($contenido['titulo']) ?>
                                        <?php if (count($equipoDocenteActual) > 1 && $creadoPorMi): ?>
                                        <br><small class="text-primary">
                                            <i class="bi bi-person-check"></i> Creado por usted
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($contenido['descripcion'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge bg-<?= $contenido['tipo_evaluacion'] == 'numerica' ? 'primary' : 'success' ?>">
                                            <?= $contenido['tipo_evaluacion'] == 'numerica' ? 'Numérica' : 'Cualitativa' ?>
                                        </span>
                                    </td>
                                    <?php if (count($equipoDocenteActual) > 1): ?>
                                    <td>
                                        <?php if (!empty($contenido['creador_apellido'])): ?>
                                        <small class="<?= $creadoPorMi ? 'text-primary fw-bold' : 'text-muted' ?>">
                                            <?= htmlspecialchars($contenido['creador_apellido']) ?>, 
                                            <?= htmlspecialchars($contenido['creador_nombre']) ?>
                                            <?= $creadoPorMi ? ' (Usted)' : '' ?>
                                        </small>
                                        <?php else: ?>
                                        <small class="text-muted">No disponible</small>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="text-center">
                                        <?php if ($tieneCalificaciones > 0): ?>
                                            <span class="badge bg-success" title="<?= $tieneCalificaciones ?> calificaciones">
                                                <i class="bi bi-check-circle"></i> Calificado
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">
                                                <i class="bi bi-clock"></i> Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <a href="contenidos_calificar.php?contenido=<?= $contenido['id'] ?>" 
                                               class="btn btn-sm btn-success" title="Calificar">
                                                <i class="bi bi-check2-square"></i> Calificar
                                            </a>
                                            <button type="button" class="btn btn-sm btn-warning" 
                                                    onclick="editarContenido(<?= $contenido['id'] ?>)" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="eliminarContenido(<?= $contenido['id'] ?>, '<?= addslashes($contenido['titulo']) ?>')" title="Eliminar">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Resumen por bimestre -->
                    <div class="mt-3">
                        <h6>Resumen por bimestre:</h6>
                        <div class="row">
                            <?php 
                            // Contar contenidos por bimestre
                            $conteosPorBimestre = [];
                            foreach ($contenidos as $contenido) {
                                $bim = $contenido['bimestre'];
                                if (!isset($conteosPorBimestre[$bim])) {
                                    $conteosPorBimestre[$bim] = 0;
                                }
                                $conteosPorBimestre[$bim]++;
                            }
                            
                            for ($b = 1; $b <= 4; $b++): 
                                $cantidad = $conteosPorBimestre[$b] ?? 0;
                            ?>
                            <div class="col-md-3">
                                <div class="alert alert-<?= $cantidad > 0 ? 'info' : 'secondary' ?> text-center">
                                    <strong><?= $b ?>° Bimestre</strong><br>
                                    <?= $cantidad ?> contenido<?= $cantidad != 1 ? 's' : '' ?>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No hay contenidos cargados para esta materia.
                        <?php if (count($equipoDocenteActual) > 1): ?>
                        <br><small>Como parte del equipo docente, puede crear contenidos que serán visibles para todos los profesores del equipo.</small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECCIÓN: ESTUDIANTES DE LA MATERIA (REGULARES + RECURSANDO + SUBGRUPOS) -->
            <?php if ($materiaSeleccionada && !empty($estudiantesMateria)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-success text-white">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-people-fill"></i> 
                            Estudiantes de <?= htmlspecialchars($materiaInfo['materia_nombre']) ?>
                            <small class="badge bg-light text-dark ms-2"><?= count($estudiantesMateria) ?> estudiantes</small>
                            <?php if ($materiaInfo['requiere_subgrupos']): ?>
                            <small class="badge bg-info ms-2">
                                <i class="bi bi-people"></i> Con Subgrupos
                            </small>
                            <?php endif; ?>
                            <?php if (count($equipoDocenteActual) > 1): ?>
                            <small class="badge bg-warning ms-2">
                                <i class="bi bi-people-fill"></i> Equipo Docente
                            </small>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <!-- Indicadores de tipos de estudiantes -->
                        <?php 
                        $regulares = array_filter($estudiantesMateria, function($e) { return $e['tipo_matricula'] === 'regular'; });
                        $recursando = array_filter($estudiantesMateria, function($e) { return $e['tipo_matricula'] === 'recursando'; });
                        $subgrupos = array_filter($estudiantesMateria, function($e) { return $e['tipo_matricula'] === 'subgrupo'; });
                        ?>
                        
                        <?php if (count($recursando) > 0 || count($subgrupos) > 0): ?>
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i>
                            <?php if (count($subgrupos) > 0): ?>
                            <strong>Estudiantes en subgrupos:</strong> <?= count($subgrupos) ?> estudiantes asignados a esta materia.
                            <?php endif; ?>
                            <?php if (count($regulares) > 0): ?>
                            <strong>Estudiantes regulares:</strong> <?= count($regulares) ?> estudiantes del curso regular.
                            <?php endif; ?>
                            <?php if (count($recursando) > 0): ?>
                            <strong>Estudiantes recursando:</strong> <?= count($recursando) ?> estudiantes están recursando esta materia desde otros cursos.
                            <?php endif; ?>
                            <?php if (count($equipoDocenteActual) > 1): ?>
                            <br><strong>Equipo docente:</strong> Todos los profesores del equipo pueden gestionar estos estudiantes.
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estudiante</th>
                                        <th>Tipo</th>
                                        <th>Curso Origen</th>
                                        <?php if ($materiaInfo['requiere_subgrupos']): ?>
                                        <th>Subgrupo</th>
                                        <?php endif; ?>
                                        <th class="text-center">Contenidos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantesMateria as $estudiante): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-arrow-repeat"></i> Recursando
                                                </span>
                                            <?php elseif ($estudiante['tipo_matricula'] === 'subgrupo'): ?>
                                                <span class="badge bg-info">
                                                    <i class="bi bi-people"></i> Subgrupo
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">
                                                    <i class="bi bi-person-check"></i> Regular
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <i class="bi bi-building"></i>
                                                <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                                                    <?= htmlspecialchars($estudiante['curso_origen'] ?? 'No especificado') ?>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($cursoInfo['curso_nombre'] ?? $materiaInfo['curso_nombre']) ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <?php if ($materiaInfo['requiere_subgrupos']): ?>
                                        <td>
                                            <?php if (!empty($estudiante['subgrupo_nombre'])): ?>
                                                <span class="badge bg-secondary">
                                                    <?= htmlspecialchars($estudiante['subgrupo_nombre']) ?>
                                                </span>
                                            <?php else: ?>
                                                <small class="text-muted">Sin asignar</small>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-center">
                                            <a href="detalle_calificaciones_contenidos.php?estudiante=<?= $estudiante['id'] ?>&materia=<?= $materiaSeleccionada ?>&origen=<?= urlencode('contenidos.php?materia=' . $materiaSeleccionada) ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Ver contenidos del estudiante">
                                                <i class="bi bi-book"></i> Ver contenidos
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN INFORMATIVA SOBRE RECURSADOS -->
                <?php if (count($recursando) > 0): ?>
                <div class="card mt-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle"></i> 
                            Información sobre Recursados
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Estudiantes Recursando</h6>
                                <ul class="list-unstyled">
                                    <?php foreach ($recursando as $est): ?>
                                    <li class="mb-1">
                                        <i class="bi bi-person text-warning"></i>
                                        <?= htmlspecialchars($est['apellido']) ?>, <?= htmlspecialchars($est['nombre']) ?>
                                        <small class="text-muted">- desde <?= htmlspecialchars($est['curso_origen']) ?></small>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-light">
                                    <small>
                                        <strong>Nota:</strong> Los estudiantes recursando aparecen en esta lista porque:
                                        <ul class="mb-0 mt-1">
                                            <li>Están matriculados en otro curso</li>
                                            <li>Tienen asignado recursado activo para esta materia</li>
                                            <li>Pueden recibir contenidos y calificaciones normalmente</li>
                                            <?php if (count($equipoDocenteActual) > 1): ?>
                                            <li>Son gestionados por todo el equipo docente</li>
                                            <?php endif; ?>
                                        </ul>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($materiaSeleccionada): ?>
                <div class="alert alert-warning mt-4">
                    <i class="bi bi-exclamation-triangle"></i>
                    No hay estudiantes asignados a esta materia en el ciclo lectivo actual.
                </div>
            <?php endif; ?>
            
            <?php elseif (!empty($materiasParaSelect)): ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-arrow-up-circle display-4"></i>
                <h5 class="mt-3">Seleccione una materia</h5>
                <p>Utilice el filtro superior para seleccionar una materia y gestionar sus contenidos.</p>
            </div>
            
            <?php else: ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-info-circle display-4"></i>
                <h5 class="mt-3">No tienes materias asignadas</h5>
                <p>Contacta al administrador para que te asigne materias en el ciclo lectivo actual.</p>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- NUEVO: Modal para ver equipo docente -->
<div class="modal fade" id="modalEquipoDocente" tabindex="-1" aria-labelledby="modalEquipoDocenteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEquipoDocenteLabel">
                    <i class="bi bi-people-fill"></i> Equipo Docente - Contenidos
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoEquipoDocente">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para nuevo contenido -->
<div class="modal fade" id="modalCrearContenido" tabindex="-1" aria-labelledby="modalCrearContenidoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearContenidoLabel">
                        <i class="bi bi-plus-circle"></i> Crear Nuevo Contenido
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_contenido">
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="materia_curso_id" class="form-label">Materia: *</label>
                                <select name="materia_curso_id" id="materia_curso_id" class="form-select" required>
                                    <option value="">Seleccionar materia...</option>
                                    <?php foreach ($materiasPorAnio as $anio => $materiasDelAnio): 
                                        // Ordenar materias dentro del año
                                        usort($materiasDelAnio, function($a, $b) {
                                            $comparacion = strcmp($a['materia_nombre'], $b['materia_nombre']);
                                            if ($comparacion === 0) {
                                                return strcmp($a['curso_nombre'], $b['curso_nombre']);
                                            }
                                            return $comparacion;
                                        });
                                    ?>
                                        <optgroup label="<?= $anio ?>° Año">
                                            <?php foreach ($materiasDelAnio as $materia): ?>
                                            <option value="<?= $materia['id'] ?>" <?= $materiaSeleccionada == $materia['id'] ? 'selected' : '' ?>
                                                    data-es-equipo="<?= $materia['es_equipo'] ? '1' : '0' ?>"
                                                    data-total-profesores="<?= $materia['total_profesores'] ?>"
                                                    data-posicion="<?= $materia['posicion_profesor'] ?>">
                                                <?= htmlspecialchars($materia['materia_nombre']) ?> 
                                                <?php if ($materia['requiere_subgrupos']): ?>
                                                (Con Rotación)
                                                <?php endif; ?>
                                                
                                            </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <!-- NUEVO: Información sobre equipo docente -->
                                
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="fecha_clase" class="form-label">Fecha de Clase: *</label>
                                <input type="date" name="fecha_clase" id="fecha_clase" class="form-control" 
                                       value="<?= date('Y-m-d') ?>" required onchange="actualizarPeriodo(this.value)">
                                <div id="periodo_detectado" class="form-text"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="titulo" class="form-label">Título del Contenido: *</label>
                        <input type="text" name="titulo" id="titulo" class="form-control" 
                               placeholder="Ej: Introducción a las funciones lineales" maxlength="200" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="descripcion" class="form-label">Descripción:</label>
                        <textarea name="descripcion" id="descripcion" class="form-control" rows="4"
                                  placeholder="Descripción detallada del contenido, objetivos, actividades realizadas..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="tipo_evaluacion" class="form-label">Tipo de Evaluación: *</label>
                        <div class="row">
                            <div class="col-md-6 d-none">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_evaluacion" 
                                           id="tipo_numerica" value="numerica">
                                    <label class="form-check-label" for="tipo_numerica">
                                        <strong>Numérica</strong><br>
                                        <small class="text-muted">Calificaciones del 1 al 10</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_evaluacion" 
                                           id="tipo_cualitativa" value="cualitativa" checked>
                                    <label class="form-check-label" for="tipo_cualitativa">
                                        <strong>Cualitativa</strong><br>
                                        <small class="text-muted">Acreditado/No Acreditado</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Información adicional -->
                    <div class="mb-3 d-none">
                        <div class="alert alert-info">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle me-2"></i>
                                <div>
                                    <strong>Detección Automática de Bimestre</strong>
                                    <br><small>El bimestre se detecta automáticamente según la fecha de clase seleccionada.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Información adicional para materias con subgrupos -->
                    <div id="infoSubgrupos" class="alert alert-info" style="display: none;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-people-fill me-2"></i>
                            <div>
                                <strong>Materia con Sistema de Rotación</strong>
                                <br><small>Al calificar este contenido, solo aparecerán los estudiantes asignados a subgrupos para esta materia.</small>
                            </div>
                        </div>
                    </div>

                    <!-- NUEVA: Información para equipos docentes -->
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Crear Contenido
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Formulario oculto para eliminar contenidos -->
<form id="formEliminar" method="POST" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_contenido">
    <input type="hidden" name="contenido_id" id="contenidoIdEliminar">
</form>

<script>
function editarContenido(id) {
    // Aquí puedes implementar la lógica para editar un contenido
    // Por ahora, redirigimos a una página de edición
    window.location.href = 'contenidos_editar.php?id=' + id;
}

function eliminarContenido(contenidoId, titulo) {
    if (confirm(`¿Está seguro de que desea eliminar el contenido "${titulo}"?\n\nEsta acción también eliminará todas las calificaciones asociadas.`)) {
        document.getElementById('contenidoIdEliminar').value = contenidoId;
        document.getElementById('formEliminar').submit();
    }
}

// NUEVA: Función para ver equipo docente
function verEquipoDocente(materiaId) {
    // Mostrar loading
    document.getElementById('contenidoEquipoDocente').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>
    `;
    
    // Obtener información del equipo
    fetch('api_equipo_docente.php?materia_id=' + materiaId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                
                // Información de la materia
                html += `
                    <div class="alert alert-info">
                        <h6><i class="bi bi-book"></i> ${data.materia.nombre} - ${data.materia.curso}</h6>
                        <p class="mb-0">Equipo de ${data.equipo.length} profesores trabajando colaborativamente</p>
                    </div>
                `;
                
                // Lista de profesores
                html += '<div class="row">';
                data.equipo.forEach(profesor => {
                    const esActual = profesor.es_actual;
                    html += `
                        <div class="col-md-6 mb-3">
                            <div class="card ${esActual ? 'border-primary' : ''}">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-${esActual ? 'primary' : 'secondary'} me-2">
                                            ${profesor.posicion}
                                        </span>
                                        <div>
                                            <strong class="${esActual ? 'text-primary' : ''}">${profesor.nombre_completo}</strong>
                                            ${esActual ? '<br><small class="text-primary">Usted</small>' : ''}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                
                // Estadísticas de actividad si disponible
                if (data.estadisticas) {
                    html += `
                        <hr>
                        <h6>Actividad del Equipo</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="h5 text-primary">${data.estadisticas.total_contenidos}</div>
                                    <small class="text-muted">Contenidos Totales</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="h5 text-success">${data.estadisticas.contenidos_usuario_actual}</div>
                                    <small class="text-muted">Creados por Usted</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="h5 text-info">${data.estadisticas.contenidos_otros}</div>
                                    <small class="text-muted">Creados por Equipo</small>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                document.getElementById('contenidoEquipoDocente').innerHTML = html;
            } else {
                document.getElementById('contenidoEquipoDocente').innerHTML = `
                    <div class="alert alert-danger">
                        Error al cargar información del equipo: ${data.error}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoEquipoDocente').innerHTML = `
                <div class="alert alert-danger">
                    Error de conexión al cargar el equipo docente
                </div>
            `;
        });
}

// Función para detectar período automáticamente
function actualizarPeriodo(fecha) {
    if (!fecha) return;
    
    // Llamar a PHP para detectar el período
    fetch('api_detectar_periodo.php?fecha=' + fecha)
        .then(response => response.json())
        .then(data => {
            if (data.periodo_nombre) {
                document.getElementById('periodo_detectado').textContent = 
                    'Período detectado: ' + data.periodo_nombre;
                
                if (data.es_intensificacion) {
                    document.getElementById('periodo_detectado').innerHTML += 
                        '<br><span class="text-warning">⚠️ Período de intensificación</span>';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// ACTUALIZADO: Mostrar información según la materia seleccionada (incluye equipos)
document.addEventListener('DOMContentLoaded', function() {
    const materiaSelect = document.getElementById('materia_curso_id');
    const infoSubgrupos = document.getElementById('infoSubgrupos');
    const infoEquipoCrear = document.getElementById('infoEquipoCrear');
    const infoEquipoDocente = document.getElementById('infoEquipoDocente');
    
    // Datos de materias con subgrupos
    const materiasConSubgrupos = [
        <?php foreach ($materiasParaSelect as $materia): ?>
        <?php if ($materia['requiere_subgrupos']): ?>
        <?= $materia['id'] ?>,
        <?php endif; ?>
        <?php endforeach; ?>
    ];
    
    if (materiaSelect) {
        materiaSelect.addEventListener('change', function() {
            const materiaId = parseInt(this.value);
            const option = this.options[this.selectedIndex];
            
            // Mostrar info de subgrupos
            if (infoSubgrupos) {
                if (materiasConSubgrupos.includes(materiaId)) {
                    infoSubgrupos.style.display = 'block';
                } else {
                    infoSubgrupos.style.display = 'none';
                }
            }
            
            // NUEVO: Mostrar info de equipo docente
            const esEquipo = option && option.getAttribute('data-es-equipo') === '1';
            const totalProfesores = option ? option.getAttribute('data-total-profesores') : '1';
            
            if (infoEquipoCrear) {
                infoEquipoCrear.style.display = esEquipo ? 'block' : 'none';
            }
            if (infoEquipoDocente) {
                infoEquipoDocente.style.display = esEquipo ? 'block' : 'none';
            }
        });
    }
    
    // Detectar período al cargar la página
    const fechaInput = document.getElementById('fecha_clase');
    if (fechaInput && fechaInput.value) {
        actualizarPeriodo(fechaInput.value);
    }
});

// Validación del formulario
const form = document.querySelector('#modalCrearContenido form');
if (form) {
    form.addEventListener('submit', function(e) {
        const titulo = document.getElementById('titulo').value.trim();
        const materia = document.getElementById('materia_curso_id').value;
        const tipoEvaluacion = document.querySelector('input[name="tipo_evaluacion"]:checked');
        
        if (!titulo || !materia || !tipoEvaluacion) {
            e.preventDefault();
            alert('Por favor complete todos los campos obligatorios.');
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>