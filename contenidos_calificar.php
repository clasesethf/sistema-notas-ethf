<?php
/**
 * contenidos_calificar.php - Calificación de contenidos por estudiante - VERSIÓN COMPLETA ACTUALIZADA
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * INCLUYE: Filtros por subgrupos, equipos docentes, acciones masivas completas
 * ÚLTIMA ACTUALIZACIÓN: Implementación completa con todos los sistemas integrados
 */

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir funciones auxiliares para equipos docentes
if (file_exists('funciones_equipos.php')) {
    require_once 'funciones_equipos.php';
}

// Verificar que el usuario esté logueado y sea profesor
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar parámetro de contenido
if (!isset($_GET['contenido']) || empty($_GET['contenido'])) {
    $_SESSION['message'] = 'Contenido no especificado';
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos.php');
    exit;
}

$contenidoId = intval($_GET['contenido']);
$profesorId = $_SESSION['user_id'];

// Obtener conexión a la base de datos
$db = Database::getInstance();

// VERIFICAR Y CREAR COLUMNAS PARA MÚLTIPLES PROFESORES SI NO EXISTEN
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

// FUNCIÓN CORREGIDA PARA OBTENER ESTUDIANTES (adaptada a tu estructura real)
function obtenerEstudiantesMateria($db, $materiaCursoId, $cicloLectivoId) {
    try {
        // Obtener información de la materia
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
        
        // 1. ESTUDIANTES REGULARES DEL CURSO (desde matriculas)
        try {
            $estudiantesRegulares = $db->fetchAll(
                "SELECT u.id, u.apellido, u.nombre, u.dni, 'regular' as tipo_matricula,
                        '' as subgrupo, '' as subgrupo_nombre
                 FROM matriculas m
                 JOIN usuarios u ON m.estudiante_id = u.id
                 WHERE m.curso_id = ? AND m.estado = 'activo' AND u.tipo = 'estudiante'
                 ORDER BY u.apellido, u.nombre",
                [$materiaInfo['curso_id']]
            );
            
            foreach ($estudiantesRegulares as $estudiante) {
                $estudiantes[] = $estudiante;
            }
        } catch (Exception $e) {
            error_log("Error obteniendo estudiantes regulares: " . $e->getMessage());
        }
        
        // 2. ESTUDIANTES RECURSANDO (desde materias_recursado si existe)
        try {
            $tablaRecursadoExiste = false;
            $tablasRecursado = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='materias_recursado'");
            $tablaRecursadoExiste = !empty($tablasRecursado);
            
            if ($tablaRecursadoExiste) {
                $estudiantesRecursando = $db->fetchAll(
                    "SELECT u.id, u.apellido, u.nombre, u.dni, 'recursando' as tipo_matricula,
                            '' as subgrupo, '' as subgrupo_nombre
                     FROM materias_recursado mr
                     JOIN usuarios u ON mr.estudiante_id = u.id
                     WHERE mr.materia_curso_id = ? AND u.tipo = 'estudiante'
                     ORDER BY u.apellido, u.nombre",
                    [$materiaCursoId]
                );
                
                foreach ($estudiantesRecursando as $estudiante) {
                    $estudiantes[] = $estudiante;
                }
            }
        } catch (Exception $e) {
            error_log("Error obteniendo estudiantes recursando: " . $e->getMessage());
        }
        
        // 3. ESTUDIANTES POR MATERIA (desde estudiantes_por_materia si existe)
        try {
            $tablaEstudiantesPorMateriaExiste = false;
            $tablasEPM = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='estudiantes_por_materia'");
            $tablaEstudiantesPorMateriaExiste = !empty($tablasEPM);
            
            if ($tablaEstudiantesPorMateriaExiste) {
                // La tabla existe, usar estructura conocida
                $sqlEstudiantesPorMateria = "SELECT u.id, u.apellido, u.nombre, u.dni, 
                                                   'regular' as tipo_matricula,
                                                   COALESCE(epm.subgrupo, '') as subgrupo,
                                                   COALESCE(epm.subgrupo, '') as subgrupo_nombre
                                            FROM estudiantes_por_materia epm
                                            JOIN usuarios u ON epm.estudiante_id = u.id
                                            WHERE epm.materia_curso_id = ? 
                                              AND epm.ciclo_lectivo_id = ?
                                              AND epm.activo = 1 
                                              AND u.tipo = 'estudiante'
                                            ORDER BY u.apellido, u.nombre";
                
                $estudiantesPorMateria = $db->fetchAll($sqlEstudiantesPorMateria, [$materiaCursoId, $cicloLectivoId]);
                
                // Agregar o reemplazar estudiantes
                foreach ($estudiantesPorMateria as $estudiante) {
                    // Buscar si ya existe en el array
                    $existe = false;
                    for ($i = 0; $i < count($estudiantes); $i++) {
                        if ($estudiantes[$i]['id'] == $estudiante['id']) {
                            // Reemplazar con la información más específica (incluye subgrupo)
                            $estudiantes[$i] = $estudiante;
                            $existe = true;
                            break;
                        }
                    }
                    
                    // Si no existe, agregarlo
                    if (!$existe) {
                        $estudiantes[] = $estudiante;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error obteniendo estudiantes por materia: " . $e->getMessage());
        }
        
        // Eliminar duplicados y ordenar
        $estudiantesUnicos = [];
        $idsVistos = [];
        
        foreach ($estudiantes as $estudiante) {
            if (!in_array($estudiante['id'], $idsVistos)) {
                $estudiantesUnicos[] = $estudiante;
                $idsVistos[] = $estudiante['id'];
            }
        }
        
        // Ordenar por apellido, nombre
        usort($estudiantesUnicos, function($a, $b) {
            return strcmp($a['apellido'] . ', ' . $a['nombre'], $b['apellido'] . ', ' . $b['nombre']);
        });
        
        return $estudiantesUnicos;
        
    } catch (Exception $e) {
        error_log("Error en obtenerEstudiantesMateria: " . $e->getMessage());
        return [];
    }
}

// PROCESAR FORMULARIOS ANTES DE INCLUIR EL HEADER

// Procesar botón "Acreditar Todos"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acreditar_todos'])) {
    try {
        // Verificación de permisos para equipos docentes
        $contenido = $db->fetchOne(
            "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3 FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
            [$contenidoId, $profesorId, $profesorId, $profesorId]
        );
        
        if (!$contenido) {
            throw new Exception('Contenido no encontrado o no tiene permisos');
        }
        
        // Obtener todos los estudiantes
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
        $estudiantes = obtenerEstudiantesMateria($db, $contenido['materia_curso_id'], $cicloLectivoId);
        
        $estudiantesActualizados = 0;
        
        foreach ($estudiantes as $estudiante) {
            // Verificar si ya existe calificación
            $calificacionExistente = $db->fetchOne(
                "SELECT id FROM contenidos_calificaciones WHERE estudiante_id = ? AND contenido_id = ?",
                [$estudiante['id'], $contenidoId]
            );
            
            if ($contenido['tipo_evaluacion'] === 'numerica') {
                $calificacionNumerica = 7;
                $calificacionCualitativa = null;
            } else {
                $calificacionNumerica = null;
                $calificacionCualitativa = 'Acreditado';
            }
            
            if ($calificacionExistente) {
                // Actualizar
                $db->query(
                    "UPDATE contenidos_calificaciones 
                     SET calificacion_numerica = ?, calificacion_cualitativa = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?",
                    [$calificacionNumerica, $calificacionCualitativa, $calificacionExistente['id']]
                );
            } else {
                // Insertar
                $db->query(
                    "INSERT INTO contenidos_calificaciones 
                     (estudiante_id, contenido_id, calificacion_numerica, calificacion_cualitativa, created_at, updated_at)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    [$estudiante['id'], $contenidoId, $calificacionNumerica, $calificacionCualitativa]
                );
            }
            
            $estudiantesActualizados++;
        }
        
        // Registrar actividad de equipo docente si aplica
        if (function_exists('registrarActividadEquipo')) {
            $totalProfesores = ($contenido['profesor_id'] ? 1 : 0) + 
                             ($contenido['profesor_id_2'] ? 1 : 0) + 
                             ($contenido['profesor_id_3'] ? 1 : 0);
            if ($totalProfesores > 1) {
                registrarActividadEquipo($db, $contenido['materia_curso_id'], $profesorId, 'acreditar_todos', "Acreditó todos los estudiantes del contenido ID: $contenidoId");
            }
        }
        
        $_SESSION['message'] = "Se acreditaron todos los estudiantes. Se actualizaron {$estudiantesActualizados} estudiantes.";
        $_SESSION['message_type'] = 'success';
        
        header('Location: contenidos_calificar.php?contenido=' . $contenidoId);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al acreditar estudiantes: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos_calificar.php?contenido=' . $contenidoId);
        exit;
    }
}

// Procesar botón "Limpiar Todos"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpiar_todos'])) {
    try {
        // Verificación de permisos para equipos docentes
        $contenido = $db->fetchOne(
            "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3 FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
            [$contenidoId, $profesorId, $profesorId, $profesorId]
        );
        
        if (!$contenido) {
            throw new Exception('Contenido no encontrado o no tiene permisos');
        }
        
        // Eliminar todas las calificaciones de este contenido
        $eliminadas = $db->query(
            "DELETE FROM contenidos_calificaciones WHERE contenido_id = ?",
            [$contenidoId]
        );
        
        // Registrar actividad de equipo docente si aplica
        if (function_exists('registrarActividadEquipo')) {
            $totalProfesores = ($contenido['profesor_id'] ? 1 : 0) + 
                             ($contenido['profesor_id_2'] ? 1 : 0) + 
                             ($contenido['profesor_id_3'] ? 1 : 0);
            if ($totalProfesores > 1) {
                registrarActividadEquipo($db, $contenido['materia_curso_id'], $profesorId, 'limpiar_calificaciones', "Limpió todas las calificaciones del contenido ID: $contenidoId");
            }
        }
        
        $_SESSION['message'] = "Se eliminaron todas las calificaciones del contenido.";
        $_SESSION['message_type'] = 'info';
        
        header('Location: contenidos_calificar.php?contenido=' . $contenidoId);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al limpiar calificaciones: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos_calificar.php?contenido=' . $contenidoId);
        exit;
    }
}

// Procesar formulario regular de calificaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_calificaciones'])) {
    try {
        // Obtener información del contenido para validación (incluye equipos docentes)
        $contenido = $db->fetchOne(
            "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3 FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
            [$contenidoId, $profesorId, $profesorId, $profesorId]
        );
        
        if (!$contenido) {
            throw new Exception('Contenido no encontrado o no tiene permisos');
        }
        
        $estudiantesActualizados = 0;
        
        // Procesar cada estudiante
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'calificacion_') === 0) {
                $estudianteId = intval(substr($key, 13)); // Remover 'calificacion_'
                
                if ($estudianteId > 0) {
                    $observaciones = $_POST["observaciones_$estudianteId"] ?? '';
                    
                    // Limpiar observaciones
                    $observaciones = trim($observaciones);
                    if (strlen($observaciones) > 500) {
                        $observaciones = substr($observaciones, 0, 500);
                    }
                    
                    // Determinar tipo de calificación
                    if ($contenido['tipo_evaluacion'] === 'numerica') {
                        $calificacionNumerica = !empty($value) ? intval($value) : null;
                        $calificacionCualitativa = null;
                        
                        // Validar rango para calificaciones numéricas
                        if ($calificacionNumerica !== null && ($calificacionNumerica < 1 || $calificacionNumerica > 10)) {
                            throw new Exception("Calificación inválida para estudiante ID $estudianteId");
                        }
                    } else {
                        $calificacionNumerica = null;
                        $calificacionCualitativa = !empty($value) ? $value : null;
                        
                        // Validar valores para calificaciones cualitativas
                        if ($calificacionCualitativa !== null && !in_array($calificacionCualitativa, ['Acreditado', 'No Acreditado', 'No Corresponde'])) {
                            throw new Exception("Calificación cualitativa inválida para estudiante ID $estudianteId");
                        }
                    }
                    
                    // Verificar si ya existe una calificación
                    $calificacionExistente = $db->fetchOne(
                        "SELECT id FROM contenidos_calificaciones WHERE estudiante_id = ? AND contenido_id = ?",
                        [$estudianteId, $contenidoId]
                    );
                    
                    if ($calificacionExistente) {
                        // Actualizar calificación existente
                        $db->query(
                            "UPDATE contenidos_calificaciones 
                             SET calificacion_numerica = ?, calificacion_cualitativa = ?, observaciones = ?, updated_at = CURRENT_TIMESTAMP
                             WHERE id = ?",
                            [$calificacionNumerica, $calificacionCualitativa, $observaciones, $calificacionExistente['id']]
                        );
                    } else {
                        // Solo insertar si hay alguna calificación o observación
                        if ($calificacionNumerica !== null || $calificacionCualitativa !== null || !empty($observaciones)) {
                            $db->query(
                                "INSERT INTO contenidos_calificaciones 
                                 (estudiante_id, contenido_id, calificacion_numerica, calificacion_cualitativa, observaciones, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                                [$estudianteId, $contenidoId, $calificacionNumerica, $calificacionCualitativa, $observaciones]
                            );
                        }
                    }
                    
                    $estudiantesActualizados++;
                }
            }
        }
        
        // Registrar actividad de equipo docente si aplica
        if (function_exists('registrarActividadEquipo')) {
            $totalProfesores = ($contenido['profesor_id'] ? 1 : 0) + 
                             ($contenido['profesor_id_2'] ? 1 : 0) + 
                             ($contenido['profesor_id_3'] ? 1 : 0);
            if ($totalProfesores > 1) {
                registrarActividadEquipo($db, $contenido['materia_curso_id'], $profesorId, 'actualizar_calificaciones', "Actualizó calificaciones del contenido ID: $contenidoId ($estudiantesActualizados estudiantes)");
            }
        }
        
        $_SESSION['message'] = "Calificaciones guardadas correctamente. Se actualizaron {$estudiantesActualizados} estudiantes.";
        $_SESSION['message_type'] = 'success';
        
        header('Location: contenidos_calificar.php?contenido=' . $contenidoId);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al guardar calificaciones: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos_calificar.php?contenido=' . $contenidoId);
        exit;
    }
}

// INCLUIR EL HEADER DESPUÉS DE PROCESAR FORMULARIOS
require_once 'header.php';

// OBTENER INFORMACIÓN DEL CONTENIDO PARA MOSTRAR
try {
    $contenido = $db->fetchOne(
        "SELECT c.*, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3, mp.curso_id, 
                COALESCE(mp.requiere_subgrupos, 0) as requiere_subgrupos,
                m.nombre as materia_nombre, cur.nombre as curso_nombre, cur.anio as curso_anio,
                (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                 CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                 CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         WHERE c.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) AND c.activo = 1",
        [$contenidoId, $profesorId, $profesorId, $profesorId]
    );
    
    if (!$contenido) {
        $_SESSION['message'] = 'Contenido no encontrado o no tiene permisos';
        $_SESSION['message_type'] = 'danger';
        header('Location: contenidos.php');
        exit;
    }
    
    // Obtener información del equipo docente si aplica
    $equipoDocente = [];
    $esEquipoDocente = $contenido['total_profesores'] > 1;
    
    if ($esEquipoDocente) {
        if (function_exists('obtenerEquipoDocente')) {
            $equipoDocente = obtenerEquipoDocente($db, $contenido['materia_curso_id']);
        } else {
            // Fallback básico
            if ($contenido['profesor_id']) {
                $prof1 = $db->fetchOne("SELECT id, apellido, nombre FROM usuarios WHERE id = ?", [$contenido['profesor_id']]);
                if ($prof1) $equipoDocente[] = ['id' => $prof1['id'], 'posicion' => 1, 'nombre_completo' => $prof1['apellido'] . ', ' . $prof1['nombre']];
            }
            if ($contenido['profesor_id_2']) {
                $prof2 = $db->fetchOne("SELECT id, apellido, nombre FROM usuarios WHERE id = ?", [$contenido['profesor_id_2']]);
                if ($prof2) $equipoDocente[] = ['id' => $prof2['id'], 'posicion' => 2, 'nombre_completo' => $prof2['apellido'] . ', ' . $prof2['nombre']];
            }
            if ($contenido['profesor_id_3']) {
                $prof3 = $db->fetchOne("SELECT id, apellido, nombre FROM usuarios WHERE id = ?", [$contenido['profesor_id_3']]);
                if ($prof3) $equipoDocente[] = ['id' => $prof3['id'], 'posicion' => 3, 'nombre_completo' => $prof3['apellido'] . ', ' . $prof3['nombre']];
            }
        }
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    
    // Obtener estudiantes usando la función actualizada
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
    
    // Clasificar estudiantes para los filtros
    $estudiantesRegulares = array_filter($estudiantes, function($est) {
        return ($est['tipo_matricula'] ?? 'regular') === 'regular';
    });
    
    $estudiantesRecursando = array_filter($estudiantes, function($est) {
        return ($est['tipo_matricula'] ?? 'regular') === 'recursando';
    });
    
    $estudiantesConSubgrupos = array_filter($estudiantes, function($est) {
        return !empty($est['subgrupo']);
    });
    
    $estudiantesSinSubgrupo = array_filter($estudiantes, function($est) {
        return empty($est['subgrupo']);
    });
    
    // Obtener subgrupos disponibles para filtros
    $subgruposDisponibles = [];
    if (!empty($estudiantesConSubgrupos)) {
        $subgruposEncontrados = array_unique(array_filter(array_column($estudiantes, 'subgrupo')));
        foreach ($subgruposEncontrados as $subgrupo) {
            if (!empty($subgrupo)) {
                $subgruposDisponibles[] = $subgrupo;
            }
        }
        sort($subgruposDisponibles);
    }
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos.php');
    exit;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="contenidos.php">Contenidos</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Calificar</li>
                </ol>
            </nav>
            
            <h1 class="h3 mb-4 text-gray-800">
                <i class="bi bi-check2-square"></i> Calificar Contenido
                <?php if ($esEquipoDocente): ?>
                <span class="badge bg-info ms-2">
                    <i class="bi bi-people"></i> Equipo Docente
                </span>
                <?php endif; ?>
            </h1>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?= $_SESSION['message_type'] ?> alert-dismissible fade show" role="alert">
                    <?= $_SESSION['message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>
            
            <!-- INFORMACIÓN DEL EQUIPO DOCENTE -->
            <?php if ($esEquipoDocente): ?>
            <div class="alert alert-info mb-4">
                <h6 class="alert-heading">
                    <i class="bi bi-people-fill"></i> Equipo Docente - Calificación Colaborativa
                </h6>
                <div class="row">
                    <div class="col-md-8">
                        <p class="mb-1">Esta materia es gestionada por un equipo de <?= count($equipoDocente) ?> profesores:</p>
                        <div class="row">
                            <?php foreach ($equipoDocente as $profesor): ?>
                            <div class="col-md-6">
                                <small class="<?= $profesor['id'] == $profesorId ? 'fw-bold text-primary' : 'text-muted' ?>">
                                    <span class="badge bg-<?= $profesor['id'] == $profesorId ? 'primary' : 'secondary' ?> badge-sm me-1">
                                        Prof. <?= $profesor['posicion'] ?>
                                    </span>
                                    <?= htmlspecialchars($profesor['nombre_completo']) ?>
                                    <?= $profesor['id'] == $profesorId ? ' (Usted)' : '' ?>
                                </small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <small class="text-muted">
                            <i class="bi bi-clock"></i> 
                            Todas las acciones quedan registradas para el equipo
                        </small>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- INFORMACIÓN DEL CONTENIDO -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="m-0 font-weight-bold">
                                <i class="bi bi-book"></i> 
                                <?= htmlspecialchars($contenido['titulo']) ?>
                            </h6>
                            <small class="mt-1 d-block opacity-75">
                                <?= htmlspecialchars($contenido['materia_nombre']) ?> - 
                                <?= htmlspecialchars($contenido['curso_nombre']) ?> <?= $contenido['curso_anio'] ?>° Año
                            </small>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group" role="group">
                                <span class="badge bg-light text-primary me-2">
                                    <i class="bi bi-calendar3"></i> 
                                    <?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?>
                                </span>
                                <span class="badge bg-<?= $contenido['tipo_evaluacion'] === 'numerica' ? 'warning' : 'info' ?>">
                                    <i class="bi bi-<?= $contenido['tipo_evaluacion'] === 'numerica' ? 'hash' : 'check-circle' ?>"></i>
                                    <?= ucfirst($contenido['tipo_evaluacion']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($contenido['descripcion'])): ?>
                    <div class="mb-3">
                        <h6>Descripción:</h6>
                        <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($contenido['descripcion'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Total de estudiantes:</strong> <?= count($estudiantes) ?>
                            <br>
                            <small class="text-muted">
                                <?= count($estudiantesRegulares) ?> regulares
                                <?php if (count($estudiantesRecursando) > 0): ?>
                                + <?= count($estudiantesRecursando) ?> recursando
                                <?php endif; ?>
                                <?php if (count($estudiantesConSubgrupos) > 0): ?>
                                + <?= count($estudiantesConSubgrupos) ?> con subgrupos
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-md-6">
                            <strong>Calificados:</strong> 
                            <?php 
                            $calificados = 0;
                            foreach ($estudiantes as $est) {
                                if (isset($calificaciones[$est['id']])) {
                                    $cal = $calificaciones[$est['id']];
                                    if ($cal['calificacion_numerica'] !== null || $cal['calificacion_cualitativa'] !== null) {
                                        $calificados++;
                                    }
                                }
                            }
                            ?>
                            <span class="badge bg-<?= $calificados == count($estudiantes) ? 'success' : 'warning' ?>">
                                <?= $calificados ?> / <?= count($estudiantes) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTROS POR SUBGRUPOS -->
            <?php if (!empty($subgruposDisponibles)): ?>
            <div class="card mb-3" id="filtros-subgrupos-contenidos">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-funnel"></i> Filtrar por Subgrupo/Rotación
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <label for="filtro-subgrupo-contenidos" class="form-label mb-1">
                                <strong>Seleccionar:</strong>
                            </label>
                            <select id="filtro-subgrupo-contenidos" class="form-select">
                                <option value="todos">Todos los estudiantes</option>
                                <?php foreach ($subgruposDisponibles as $subgrupo): ?>
                                    <option value="<?= htmlspecialchars($subgrupo) ?>">
                                        <?= htmlspecialchars($subgrupo) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (count($estudiantesSinSubgrupo) > 0): ?>
                                    <option value="sin-subgrupo">Sin subgrupo asignado</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label mb-1"><strong>Acceso rápido:</strong></label>
                            <div class="btn-group-sm d-flex flex-wrap" role="group">
                                <button type="button" class="btn btn-outline-secondary btn-sm me-1 mb-1" 
                                        onclick="seleccionarSubgrupoContenidos('todos')">
                                    <i class="bi bi-people"></i> Todos
                                </button>
                                <?php foreach ($subgruposDisponibles as $subgrupo): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1 mb-1" 
                                            onclick="seleccionarSubgrupoContenidos('<?= htmlspecialchars($subgrupo) ?>')">
                                        <?= htmlspecialchars($subgrupo) ?>
                                    </button>
                                <?php endforeach; ?>
                                <?php if (count($estudiantesSinSubgrupo) > 0): ?>
                                    <button type="button" class="btn btn-outline-warning btn-sm me-1 mb-1" 
                                            onclick="seleccionarSubgrupoContenidos('sin-subgrupo')">
                                        <i class="bi bi-question-circle"></i> Sin asignar
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div id="info-filtro-contenidos" class="alert alert-light mb-0 p-2">
                                <small>
                                    <strong>Mostrando:</strong><br>
                                    <span id="total-mostrados-contenidos"><?= count($estudiantes) ?></span> estudiantes
                                    (<span id="regulares-mostrados-contenidos"><?= count($estudiantesRegulares) ?></span> reg. + 
                                    <span id="recursando-mostrados-contenidos"><?= count($estudiantesRecursando) ?></span> rec.)
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($estudiantes)): ?>
            
            <!-- FORMULARIO DE CALIFICACIONES -->
            <form method="POST" action="">
                <div class="card">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold">
                                <i class="bi bi-pencil-square"></i> Calificaciones por Estudiante
                            </h6>
                            <div class="btn-group" role="group">
                                <button type="submit" name="acreditar_todos" class="btn btn-success btn-sm" 
                                        onclick="return confirm('¿Está seguro de acreditar a todos los estudiantes?')">
                                    <i class="bi bi-check-circle"></i> 
                                    <?= $contenido['tipo_evaluacion'] === 'numerica' ? 'Aprobar Todos (7)' : 'Acreditar Todos' ?>
                                </button>
                                
                                <button type="button" class="btn btn-warning btn-sm" 
                                        onclick="ejecutarAccionMasiva('no_acreditar_todos', <?= $contenidoId ?>, '<?= htmlspecialchars($contenido['titulo']) ?>')">
                                    <i class="bi bi-x-circle"></i> 
                                    <?= $contenido['tipo_evaluacion'] === 'numerica' ? 'Reprobar Todos (1)' : 'No Acreditar Todos' ?>
                                </button>
                                
                                <button type="button" class="btn btn-info btn-sm" 
                                        onclick="ejecutarAccionMasiva('no_corresponde_todos', <?= $contenidoId ?>, '<?= htmlspecialchars($contenido['titulo']) ?>')">
                                    <i class="bi bi-dash-circle"></i> No Corresponde Todos
                                </button>
                                
                                <button type="button" class="btn btn-outline-danger btn-sm" 
                                        onclick="ejecutarAccionMasiva('sin_calificar_todos', <?= $contenidoId ?>, '<?= htmlspecialchars($contenido['titulo']) ?>')">
                                    <i class="bi bi-eraser"></i> Sin Calificar Todos
                                </button>
                                
                                <button type="submit" name="limpiar_todos" class="btn btn-outline-secondary btn-sm" 
                                        onclick="return confirm('¿Está seguro de eliminar todas las calificaciones? Esta acción no se puede deshacer.')">
                                    <i class="bi bi-arrow-clockwise"></i> Limpiar Todos (Legacy)
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="30" class="text-center">#</th>
                                        <?php if (!empty($subgruposDisponibles)): ?>
                                        <th width="80" class="text-center columna-subgrupo-contenidos">Subgrupo</th>
                                        <?php endif; ?>
                                        <th>Estudiante</th>
                                        <th width="60" class="text-center">Tipo</th>
                                        <th width="150" class="text-center">Calificación</th>
                                        <th>Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes as $index => $estudiante): ?>
                                        <?php 
                                        $calExistente = $calificaciones[$estudiante['id']] ?? null;
                                        $valorActual = '';
                                        if ($calExistente) {
                                            if ($contenido['tipo_evaluacion'] === 'numerica') {
                                                $valorActual = $calExistente['calificacion_numerica'];
                                            } else {
                                                $valorActual = $calExistente['calificacion_cualitativa'];
                                            }
                                        }
                                        $observacionesActuales = $calExistente['observaciones'] ?? '';
                                        ?>
                                        <tr class="fila-estudiante-contenidos" 
                                            data-estudiante-id="<?= $estudiante['id'] ?>"
                                            data-subgrupo="<?= htmlspecialchars($estudiante['subgrupo'] ?? '') ?>"
                                            data-tipo-estudiante="<?= htmlspecialchars($estudiante['tipo_matricula'] ?? 'regular') ?>"
                                            data-tiene-calificacion="<?= $calExistente ? '1' : '0' ?>">
                                            
                                            <td class="numero-fila-contenidos text-center">
                                                <small class="text-muted"><?= $index + 1 ?></small>
                                            </td>
                                            
                                            <?php if (!empty($subgruposDisponibles)): ?>
                                            <td class="text-center columna-subgrupo-contenidos">
                                                <?php if (!empty($estudiante['subgrupo'])): ?>
                                                    <span class="badge bg-primary"><?= htmlspecialchars($estudiante['subgrupo']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Sin asignar</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            
                                            <td>
                                                <strong><?= htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']) ?></strong>
                                                <br>
                                                <small class="text-muted">DNI: <?= htmlspecialchars($estudiante['dni']) ?></small>
                                            </td>
                                            
                                            <td class="text-center">
                                                <span class="badge bg-<?= $estudiante['tipo_matricula'] === 'recursando' ? 'warning' : 'primary' ?>">
                                                    <?= $estudiante['tipo_matricula'] === 'recursando' ? 'Rec.' : 'Reg.' ?>
                                                </span>
                                            </td>
                                            
                                            <td class="text-center">
                                                <?php if ($contenido['tipo_evaluacion'] === 'numerica'): ?>
                                                    <select name="calificacion_<?= $estudiante['id'] ?>" class="form-select form-select-sm calificacion-input" style="width: 80px;">
                                                        <option value="">---</option>
                                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                                            <option value="<?= $i ?>" <?= $valorActual == $i ? 'selected' : '' ?>>
                                                                <?= $i ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <select name="calificacion_<?= $estudiante['id'] ?>" class="form-select form-select-sm calificacion-input">
                                                        <option value="">---</option>
                                                        <option value="Acreditado" <?= $valorActual === 'Acreditado' ? 'selected' : '' ?>>Acreditado</option>
                                                        <option value="No Acreditado" <?= $valorActual === 'No Acreditado' ? 'selected' : '' ?>>No Acreditado</option>
                                                        <option value="No Corresponde" <?= $valorActual === 'No Corresponde' ? 'selected' : '' ?>>No Corresponde</option>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td>
                                                <input type="text" 
                                                       name="observaciones_<?= $estudiante['id'] ?>" 
                                                       class="form-control form-control-sm" 
                                                       placeholder="Observaciones (opcional)"
                                                       value="<?= htmlspecialchars($observacionesActuales) ?>"
                                                       maxlength="500">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="contenidos.php?materia=<?= $contenido['materia_curso_id'] ?>" 
                               class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Volver
                            </a>
                            <button type="submit" name="guardar_calificaciones" class="btn btn-primary">
                                <i class="bi bi-save"></i> Guardar Calificaciones
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <?php else: ?>
            
            <!-- NO HAY ESTUDIANTES -->
            <div class="card">
                <div class="card-body text-center">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle fa-2x mb-3"></i>
                        <h5>No hay estudiantes para calificar</h5>
                        <p class="mb-2">
                            No se encontraron estudiantes matriculados para esta materia en el ciclo lectivo actual.
                        </p>
                        <br><small>
                            <?php if ($contenido['requiere_subgrupos']): ?>
                            Esta materia requiere subgrupos. Verifique que los estudiantes estén asignados a subgrupos para esta materia.
                            <?php else: ?>
                            Esto puede ocurrir si todos los estudiantes tienen liberada esta materia o no hay matriculados.
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="mt-3">
                        <a href="contenidos.php?materia=<?= $contenido['materia_curso_id'] ?>" 
                           class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<!-- JAVASCRIPT PARA FILTROS DE SUBGRUPOS -->
<script>
// Variables globales para contenidos_calificar
window.subgrupoActualContenidos = 'todos';

// Función principal para filtrar por subgrupo en contenidos_calificar
function filtrarPorSubgrupoContenidos() {
    const select = document.getElementById('filtro-subgrupo-contenidos');
    if (!select) {
        console.error('No se encontró el select de filtro de subgrupos');
        return;
    }
    
    const subgrupoSeleccionado = select.value;
    window.subgrupoActualContenidos = subgrupoSeleccionado;
    
    console.log('Filtrando contenidos por subgrupo:', subgrupoSeleccionado);
    
    // Obtener todas las filas de estudiantes
    const filasEstudiantes = document.querySelectorAll('.fila-estudiante-contenidos');
    
    let estudiantesMostrados = 0;
    let regularesMostrados = 0;
    let recursandoMostrados = 0;
    
    filasEstudiantes.forEach(fila => {
        const subgrupoEstudiante = fila.getAttribute('data-subgrupo') || '';
        const tipoEstudiante = fila.getAttribute('data-tipo-estudiante') || 'regular';
        
        let mostrarFila = false;
        
        // Lógica de filtrado
        switch (subgrupoSeleccionado) {
            case 'todos':
                mostrarFila = true;
                break;
            case 'sin-subgrupo':
                mostrarFila = (subgrupoEstudiante === '' || subgrupoEstudiante === null);
                break;
            default:
                mostrarFila = (subgrupoEstudiante === subgrupoSeleccionado);
                break;
        }
        
        // Mostrar/ocultar fila
        if (mostrarFila) {
            fila.style.display = '';
            fila.classList.remove('fila-oculta-contenidos');
            
            estudiantesMostrados++;
            
            // Actualizar número de fila
            const numeroFila = fila.querySelector('.numero-fila-contenidos');
            if (numeroFila) {
                numeroFila.innerHTML = `<small class="text-muted">${estudiantesMostrados}</small>`;
            }
            
            // Contar por tipo
            if (tipoEstudiante === 'recursando') {
                recursandoMostrados++;
            } else {
                regularesMostrados++;
            }
            
        } else {
            fila.style.display = 'none';
            fila.classList.add('fila-oculta-contenidos');
        }
    });
    
    // Actualizar información del filtro
    actualizarInfoFiltroContenidos(subgrupoSeleccionado, estudiantesMostrados, regularesMostrados, recursandoMostrados);
    
    // Actualizar botones de acceso rápido
    actualizarBotonesAccesoRapidoContenidos(subgrupoSeleccionado);
    
    // Mostrar/ocultar columna de subgrupo según sea necesario
    alternarColumnaSubgrupoContenidos(subgrupoSeleccionado);
    
    // Aplicar animación
    aplicarAnimacionFiltradoContenidos();
}

// Función para seleccionar subgrupo desde botones de acceso rápido
function seleccionarSubgrupoContenidos(subgrupo) {
    const select = document.getElementById('filtro-subgrupo-contenidos');
    if (select) {
        select.value = subgrupo;
        filtrarPorSubgrupoContenidos();
        
        // Efecto visual en el botón seleccionado
        const botones = document.querySelectorAll('[onclick*="seleccionarSubgrupoContenidos"]');
        botones.forEach(btn => {
            if (btn.onclick.toString().includes(`'${subgrupo}'`)) {
                btn.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    btn.style.transform = 'scale(1)';
                }, 150);
            }
        });
    }
}

// Función para actualizar información del filtro
function actualizarInfoFiltroContenidos(subgrupo, total, regulares, recursando) {
    const elementos = {
        total: document.getElementById('total-mostrados-contenidos'),
        regulares: document.getElementById('regulares-mostrados-contenidos'),
        recursando: document.getElementById('recursando-mostrados-contenidos')
    };
    
    if (elementos.total) elementos.total.textContent = total;
    if (elementos.regulares) elementos.regulares.textContent = regulares;
    if (elementos.recursando) elementos.recursando.textContent = recursando;
    
    // Cambiar color del info según el subgrupo
    const infoContainer = document.getElementById('info-filtro-contenidos');
    if (infoContainer) {
        infoContainer.className = 'alert mb-0 p-2 ';
        
        switch (subgrupo) {
            case 'todos':
                infoContainer.className += 'alert-light';
                break;
            case 'sin-subgrupo':
                infoContainer.className += 'alert-warning';
                break;
            default:
                infoContainer.className += 'alert-info';
                break;
        }
    }
}

// Función para actualizar botones de acceso rápido
function actualizarBotonesAccesoRapidoContenidos(subgrupoSeleccionado) {
    const botones = document.querySelectorAll('[onclick*="seleccionarSubgrupoContenidos"]');
    
    botones.forEach(boton => {
        // Remover clases anteriores
        boton.classList.remove('btn-outline-primary', 'btn-outline-secondary', 'btn-outline-warning', 'btn-primary', 'btn-secondary', 'btn-warning');
        
        const onclick = boton.getAttribute('onclick');
        const valorBoton = onclick.match(/seleccionarSubgrupoContenidos\('([^']+)'\)/)?.[1];
        
        if (valorBoton === subgrupoSeleccionado) {
            // Botón activo
            switch (subgrupoSeleccionado) {
                case 'todos':
                    boton.classList.add('btn-secondary');
                    break;
                case 'sin-subgrupo':
                    boton.classList.add('btn-warning');
                    break;
                default:
                    boton.classList.add('btn-primary');
                    break;
            }
        } else {
            // Botón inactivo
            switch (valorBoton) {
                case 'todos':
                    boton.classList.add('btn-outline-secondary');
                    break;
                case 'sin-subgrupo':
                    boton.classList.add('btn-outline-warning');
                    break;
                default:
                    boton.classList.add('btn-outline-primary');
                    break;
            }
        }
    });
}

// Función para mostrar/ocultar la columna de subgrupo
function alternarColumnaSubgrupoContenidos(subgrupoSeleccionado) {
    const tabla = document.querySelector('table');
    if (!tabla) return;
    
    // Buscar la columna de subgrupo
    const headers = tabla.querySelectorAll('thead th');
    let columnaSubgrupoIndex = -1;
    
    headers.forEach((header, index) => {
        const textoHeader = header.textContent.toLowerCase();
        if (textoHeader.includes('subgrupo') || textoHeader.includes('rotación')) {
            columnaSubgrupoIndex = index + 1; // nth-child es 1-based
        }
    });
    
    if (columnaSubgrupoIndex > 0) {
        const ocultarColumnaSubgrupo = subgrupoSeleccionado !== 'todos' && subgrupoSeleccionado !== 'sin-subgrupo';
        
        // Ocultar/mostrar la columna de subgrupo
        const columnasSubgrupo = tabla.querySelectorAll(`th:nth-child(${columnaSubgrupoIndex}), td:nth-child(${columnaSubgrupoIndex})`);
        
        columnasSubgrupo.forEach(element => {
            if (ocultarColumnaSubgrupo) {
                element.style.display = 'none';
            } else {
                element.style.display = '';
            }
        });
    }
}

// Función para aplicar animación al filtrado
function aplicarAnimacionFiltradoContenidos() {
    const tabla = document.querySelector('.table-responsive table');
    if (tabla) {
        tabla.style.opacity = '0.7';
        tabla.style.transform = 'scale(0.98)';
        tabla.style.transition = 'all 0.2s ease';
        
        setTimeout(() => {
            tabla.style.opacity = '1';
            tabla.style.transform = 'scale(1)';
        }, 150);
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    const selectFiltro = document.getElementById('filtro-subgrupo-contenidos');
    if (selectFiltro) {
        selectFiltro.addEventListener('change', filtrarPorSubgrupoContenidos);
        console.log('Listener de filtro de subgrupos para contenidos cargado');
    }
});

// Función para integrar con acciones masivas (si las tienes)
function obtenerEstudiantesFiltradosContenidos() {
    const filasVisibles = document.querySelectorAll('.fila-estudiante-contenidos:not(.fila-oculta-contenidos)');
    const estudiantesFiltrados = [];
    
    filasVisibles.forEach(fila => {
        const estudianteId = fila.getAttribute('data-estudiante-id');
        if (estudianteId) {
            estudiantesFiltrados.push(parseInt(estudianteId));
        }
    });
    
    return {
        filtroActual: window.subgrupoActualContenidos,
        estudiantesVisibles: estudiantesFiltrados,
        totalVisibles: filasVisibles.length
    };
}

console.log('Sistema de filtros para contenidos_calificar cargado correctamente');

// ========== FUNCIONES PARA ACCIONES MASIVAS CON SOPORTE DE FILTROS ==========

// Variables globales
window.contenidoActual = <?= $contenidoId ?>;

// Función principal para ejecutar acciones masivas respetando filtros
function ejecutarAccionMasiva(accion, contenidoId, tituloContenido) {
    if (!contenidoId) {
        mostrarAlerta('warning', 'ID de contenido no especificado');
        return;
    }
    
    // Detectar si hay filtro activo y obtener estudiantes filtrados
    const filtroActual = window.subgrupoActualContenidos || 'todos';
    let estudiantesFiltrados = [];
    let aplicarFiltro = false;
    let mensajeConfirmacion = '';
    
    if (filtroActual !== 'todos') {
        // Obtener IDs de estudiantes visibles (no ocultos)
        const filasVisibles = document.querySelectorAll('.fila-estudiante-contenidos:not(.fila-oculta-contenidos)');
        
        filasVisibles.forEach(fila => {
            const estudianteId = fila.getAttribute('data-estudiante-id');
            if (estudianteId) {
                estudiantesFiltrados.push(parseInt(estudianteId));
            }
        });
        
        aplicarFiltro = estudiantesFiltrados.length > 0;
        
        if (aplicarFiltro) {
            let textoFiltro = '';
            switch (filtroActual) {
                case 'sin-subgrupo':
                    textoFiltro = 'estudiantes sin subgrupo asignado';
                    break;
                default:
                    textoFiltro = `estudiantes del ${filtroActual}`;
                    break;
            }
            
            mensajeConfirmacion = `¿Está seguro de aplicar "${getAccionTexto(accion)}" a los ${estudiantesFiltrados.length} ${textoFiltro}?\n\n`;
        }
    }
    
    if (!aplicarFiltro) {
        const totalEstudiantes = document.querySelectorAll('.fila-estudiante-contenidos').length;
        mensajeConfirmacion = `¿Está seguro de aplicar "${getAccionTexto(accion)}" a TODOS los ${totalEstudiantes} estudiantes?\n\n`;
    }
    
    // Personalizar mensaje según la acción
    mensajeConfirmacion += getMensajeConfirmacion(accion, aplicarFiltro);
    
    if (!confirm(mensajeConfirmacion)) {
        return;
    }
    
    // Mostrar indicador de carga
    mostrarIndicadorCarga();
    
    // Crear formulario para envío
    const formData = new FormData();
    formData.append('accion', accion);
    formData.append('contenido_id', contenidoId);
    
    // Enviar información del filtro si está activo
    if (aplicarFiltro && estudiantesFiltrados.length > 0) {
        formData.append('aplicar_filtro', '1');
        formData.append('estudiantes_filtrados', JSON.stringify(estudiantesFiltrados));
        
        console.log('ENVIANDO CON FILTRO:', {
            accion: accion,
            contenido_id: contenidoId,
            aplicar_filtro: true,
            estudiantes_filtrados: estudiantesFiltrados,
            total_filtrados: estudiantesFiltrados.length,
            filtro_actual: filtroActual
        });
    } else {
        console.log('ENVIANDO SIN FILTRO:', {
            accion: accion,
            contenido_id: contenidoId,
            aplicar_filtro: false
        });
    }
    
    // Enviar solicitud AJAX
    fetch('ajax_acciones_masivas_contenidos.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Respuesta recibida:', response);
        
        const contentType = response.headers.get('content-type');
        console.log('Content-Type:', contentType);
        
        if (!contentType || !contentType.includes('application/json')) {
            // Si no es JSON, intentar obtener el texto para debuggear
            return response.text().then(text => {
                console.error('Respuesta no-JSON recibida:', text.substring(0, 500));
                throw new Error('La respuesta no es JSON válido. Ver consola para detalles.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('Datos procesados:', data);
        ocultarIndicadorCarga();
        
        if (data.success) {
            let mensaje = data.message;
            
            // Personalizar mensaje según la acción
            if (aplicarFiltro) {
                mensaje = `${getAccionTexto(accion)}: Se procesaron ${data.estudiantes_procesados} estudiantes filtrados`;
            } else {
                mensaje = `${getAccionTexto(accion)}: Se procesaron ${data.estudiantes_procesados} estudiantes`;
            }
            
            const tipoAlerta = getTipoAlerta(accion);
            mostrarAlerta(tipoAlerta, mensaje);
            
            // Recargar la página para ver los cambios
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            mostrarAlerta('danger', data.message || 'Error al procesar la solicitud');
        }
    })
    .catch(error => {
        console.error('Error completo:', error);
        ocultarIndicadorCarga();
        
        let mensajeError = 'Error de conexión al procesar estudiantes';
        
        if (error.message.includes('Failed to fetch')) {
            mensajeError = 'Error de red: No se pudo conectar al servidor';
        } else if (error.message.includes('JSON')) {
            mensajeError = 'Error de formato: El servidor no devolvió datos válidos';
        }
        
        mostrarAlerta('danger', mensajeError);
    });
}

// Función auxiliar para obtener texto descriptivo de la acción
function getAccionTexto(accion) {
    switch (accion) {
        case 'acreditar_todos':
            return 'Acreditar todos';
        case 'no_acreditar_todos':
            return 'No acreditar todos';
        case 'no_corresponde_todos':
            return 'No corresponde todos';
        case 'sin_calificar_todos':
            return 'Sin calificar todos';
        default:
            return 'Acción desconocida';
    }
}

// Función auxiliar para obtener mensaje de confirmación específico
function getMensajeConfirmacion(accion, aplicarFiltro) {
    let mensaje = '';
    
    switch (accion) {
        case 'acreditar_todos':
            mensaje = 'Esta acción:\n• Pondrá calificación 7 (o "Acreditado")\n• Sobrescribirá calificaciones existentes';
            break;
        case 'no_acreditar_todos':
            mensaje = 'Esta acción:\n• Pondrá calificación 1 (o "No Acreditado")\n• Sobrescribirá calificaciones existentes';
            break;
        case 'no_corresponde_todos':
            mensaje = 'Esta acción:\n• Pondrá "No Corresponde"\n• Sobrescribirá calificaciones existentes';
            break;
        case 'sin_calificar_todos':
            mensaje = 'Esta acción:\n• ELIMINARÁ todas las calificaciones\n• Esta acción es IRREVERSIBLE';
            break;
    }
    
    if (aplicarFiltro) {
        mensaje += '\n• Aplicará solo a los estudiantes filtrados mostrados';
    } else {
        mensaje += '\n• Aplicará a TODOS los estudiantes de la lista';
    }
    
    mensaje += '\n\n¿Continuar?';
    
    return mensaje;
}

// Función auxiliar para obtener tipo de alerta
function getTipoAlerta(accion) {
    switch (accion) {
        case 'acreditar_todos':
            return 'success';
        case 'no_acreditar_todos':
            return 'warning';
        case 'no_corresponde_todos':
            return 'info';
        case 'sin_calificar_todos':
            return 'danger';
        default:
            return 'info';
    }
}

// Funciones de utilidad para mostrar indicadores
function mostrarIndicadorCarga() {
    // Crear overlay de carga si no existe
    if (!document.getElementById('loading-overlay')) {
        const overlay = document.createElement('div');
        overlay.id = 'loading-overlay';
        overlay.innerHTML = `
            <div class="d-flex justify-content-center align-items-center h-100">
                <div class="text-center text-white">
                    <div class="spinner-border mb-3" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <div>Procesando estudiantes...</div>
                </div>
            </div>
        `;
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
        `;
        document.body.appendChild(overlay);
    }
}

function ocultarIndicadorCarga() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

function mostrarAlerta(tipo, mensaje) {
    // Crear alerta Bootstrap
    const alerta = document.createElement('div');
    alerta.className = `alert alert-${tipo} alert-dismissible fade show position-fixed`;
    alerta.style.cssText = `
        top: 70px; 
        right: 20px; 
        z-index: 9999; 
        max-width: 400px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    `;
    alerta.innerHTML = `
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <strong>${getIconoAlerta(tipo)}</strong> ${mensaje}
    `;
    
    document.body.appendChild(alerta);
    
    // Auto-remover después de 5 segundos
    setTimeout(() => {
        if (alerta.parentNode) {
            alerta.remove();
        }
    }, 5000);
}

function getIconoAlerta(tipo) {
    switch (tipo) {
        case 'success':
            return '<i class="bi bi-check-circle-fill"></i>';
        case 'warning':
            return '<i class="bi bi-exclamation-triangle-fill"></i>';
        case 'danger':
            return '<i class="bi bi-x-circle-fill"></i>';
        case 'info':
            return '<i class="bi bi-info-circle-fill"></i>';
        default:
            return '<i class="bi bi-info-circle"></i>';
    }
}

console.log('Sistema de acciones masivas para contenidos_calificar cargado correctamente');
</script>

<!-- CSS PARA MEJORAR LA PRESENTACIÓN -->
<style>
/* Estilos para el filtro de subgrupos en contenidos */
#filtros-subgrupos-contenidos {
    border-left: 4px solid #17a2b8;
}

#filtros-subgrupos-contenidos .card-header {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
}

/* Estilos para las filas filtradas */
.fila-estudiante-contenidos {
    transition: all 0.3s ease;
}

.fila-estudiante-contenidos.fila-oculta-contenidos {
    opacity: 0;
    transform: scale(0.95);
}

/* Estilos para botones de acceso rápido */
.btn-group-sm .btn {
    transition: all 0.2s ease;
}

.btn-group-sm .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Estilos para la columna de subgrupo */
.columna-subgrupo-contenidos {
    transition: all 0.3s ease;
}

/* Animación para la tabla */
.table-responsive table {
    transition: all 0.2s ease;
}

/* Estilos para badges de subgrupo */
.badge {
    font-size: 0.7rem;
    padding: 0.3em 0.6em;
}

/* Info del filtro */
#info-filtro-contenidos {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    transition: all 0.3s ease;
}

#info-filtro-contenidos.alert-info {
    background-color: #d1ecf1;
    border-color: #bee5eb;
    color: #0c5460;
}

#info-filtro-contenidos.alert-warning {
    background-color: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
}

/* Estilo para inputs de calificación */
.calificacion-input {
    transition: all 0.2s ease;
}

.calificacion-input:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

/* Mejorar el aspecto de la tabla */
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

/* ESTILOS PARA BOTONES DE ACCIONES MASIVAS */
.btn-group .btn {
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.btn-group .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn-group .btn:active {
    transform: translateY(0);
}

/* Botón "Sin Calificar Todos" con estilo especial */
.btn-outline-danger {
    border: 2px solid #dc3545;
    color: #dc3545;
    font-weight: 600;
}

.btn-outline-danger:hover {
    background-color: #dc3545;
    color: white;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
}

.btn-outline-danger::before {
    content: '⚠️ ';
    opacity: 0;
    transition: opacity 0.3s ease;
}

.btn-outline-danger:hover::before {
    opacity: 1;
}

/* Efectos para botones de acción específicos */
.btn-success:hover {
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
}

.btn-warning:hover {
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.4);
}

.btn-info:hover {
    box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
}

/* Animación de pulso para botón peligroso */
@keyframes pulso-peligro {
    0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
    100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
}

.btn-outline-danger:focus {
    animation: pulso-peligro 1.5s infinite;
}

/* Responsive para dispositivos móviles */
@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
        gap: 5px;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
        font-size: 0.875rem;
        padding: 0.5rem 1rem;
    }
    
    .btn-group-sm .btn {
        margin-bottom: 5px;
        font-size: 0.75rem;
    }
    
    #filtros-subgrupos-contenidos .card-body {
        padding: 1rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    /* Ocultar textos largos en móviles */
    .btn .d-none.d-md-inline {
        display: none !important;
    }
}

/* Mejoras para accesibilidad */
.btn:focus {
    outline: 2px solid #007bff;
    outline-offset: 2px;
}

/* Indicador de carga personalizado */
#loading-overlay {
    backdrop-filter: blur(3px);
}

#loading-overlay .spinner-border {
    width: 3rem;
    height: 3rem;
}

/* Alertas personalizadas */
.alert.position-fixed {
    animation: slideInRight 0.3s ease-out;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
</style>

<?php require_once 'footer.php'; ?>
