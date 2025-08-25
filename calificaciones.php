<?php
/**
 * calificaciones.php - Página de gestión de calificaciones con sistema de bloqueos - ACTUALIZADO PARA EQUIPOS DOCENTES
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * ACTUALIZADO: Soporte para equipos docentes (múltiples profesores por materia)
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir funciones auxiliares para equipos docentes
if (file_exists('funciones_equipos.php')) {
    require_once 'funciones_equipos.php';
}

// Incluir funciones de bloqueos
if (file_exists('bloqueos_helper.php')) {
    require_once 'bloqueos_helper.php';
} else {
    // Funciones auxiliares básicas si no existe el archivo
    function obtenerConfiguracionBloqueos($db, $cicloLectivoId) {
        try {
            $config = $db->fetchOne("SELECT * FROM bloqueos_calificaciones WHERE ciclo_lectivo_id = ?", [$cicloLectivoId]);
            if (!$config) {
                return [
                    'valoracion_1bim_bloqueada' => 0, 'desempeno_1bim_bloqueado' => 0, 'observaciones_1bim_bloqueadas' => 0,
                    'valoracion_3bim_bloqueada' => 0, 'desempeno_3bim_bloqueado' => 0, 'observaciones_3bim_bloqueadas' => 0,
                    'valoracion_1c_bloqueada' => 0, 'calificacion_1c_bloqueada' => 0, 'valoracion_2c_bloqueada' => 0,
                    'calificacion_2c_bloqueada' => 0, 'intensificacion_1c_bloqueada' => 0, 'calificacion_final_bloqueada' => 0,
                    'observaciones_cuatrimestrales_bloqueadas' => 0, 'bloqueo_general' => 0, 'observaciones' => ''
                ];
            }
            return $config;
        } catch (Exception $e) {
            return ['bloqueo_general' => 0];
        }
    }
    
    function estaColumnaBloqueada($configuracionBloqueos, $nombreCampo) {
        if ($configuracionBloqueos['bloqueo_general']) return true;
        return isset($configuracionBloqueos[$nombreCampo]) && $configuracionBloqueos[$nombreCampo];
    }
    
    function puedeEditarCalificaciones($tipoUsuario) {
        return in_array($tipoUsuario, ['admin', 'directivo']);
    }
    
    function generarAlertaBloqueos($configuracionBloqueos, $tipoUsuario) {
        $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
        $bloqueoGeneral = $configuracionBloqueos['bloqueo_general'];
        
        if ($bloqueoGeneral) {
            if ($puedeEditar) {
                return [
                    'tipo' => 'warning',
                    'mensaje' => '<i class="bi bi-shield-exclamation"></i> <strong>Bloqueo general activo:</strong> Los profesores no pueden editar calificaciones. Usted puede editar por ser ' . ($tipoUsuario === 'admin' ? 'administrador' : 'directivo') . '.',
                    'detalle' => $configuracionBloqueos['observaciones'] ?? ''
                ];
            } else {
                return [
                    'tipo' => 'danger',
                    'mensaje' => '<i class="bi bi-lock"></i> <strong>Acceso bloqueado:</strong> La carga de calificaciones está temporalmente deshabilitada.',
                    'detalle' => $configuracionBloqueos['observaciones'] ?? 'Contacte a la administración para más información.'
                ];
            }
        }
        return null;
    }
}

// Verificar permisos (solo admin, directivos y profesores)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo', 'profesor'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Verificar y crear columnas para múltiples profesores si no existen
if (function_exists('verificarColumnasMultiplesProfesores')) {
    verificarColumnasMultiplesProfesores($db);
} else {
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

// Obtener configuración de bloqueos para el ciclo activo
$configuracionBloqueos = obtenerConfiguracionBloqueos($db, $cicloLectivoId);

// Verificar si hay bloqueo general y el usuario no puede editarlo
if ($configuracionBloqueos['bloqueo_general'] && !puedeEditarCalificaciones($_SESSION['user_type'])) {
    require_once 'header.php';
    $alertaBloqueo = generarAlertaBloqueos($configuracionBloqueos, $_SESSION['user_type']);
    ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="alert alert-danger">
                <?= $alertaBloqueo['mensaje'] ?>
                <?php if ($alertaBloqueo['detalle']): ?>
                    <br><small><?= htmlspecialchars($alertaBloqueo['detalle']) ?></small>
                <?php endif; ?>
            </div>
            <div class="text-center">
                <a href="index.php" class="btn btn-secondary">
                    <i class="bi bi-house"></i> Volver al Inicio
                </a>
            </div>
        </div>
    </div>
    <?php
    require_once 'footer.php';
    exit;
}

// Obtener período actual configurado
$periodoActual = 'cuatrimestre'; // default
$nombrePeriodo = 'Cuatrimestre';
try {
    $periodo = $db->fetchOne("SELECT * FROM configuracion_periodos WHERE ciclo_lectivo_id = ? AND activo = 1", [$cicloLectivoId]);
    if ($periodo) {
        $periodoActual = (strpos($periodo['periodo_actual'], 'bim') !== false) ? 'valoracion' : 'cuatrimestre';
        $nombrePeriodo = $periodo['nombre_periodo'];
    }
} catch (Exception $e) {
    // Usar valores por defecto
}

// Permitir cambio manual del tipo de carga
$tipoCarga = isset($_GET['tipo']) ? $_GET['tipo'] : $periodoActual;

// Obtener bimestre seleccionado desde la URL
$bimestreSeleccionado = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 1;

// Obtener cursos según el tipo de usuario - ACTUALIZADO PARA EQUIPOS DOCENTES
$cursos = [];
try {
    if ($cicloLectivoId > 0) {
        if ($_SESSION['user_type'] == 'profesor') {
            // NUEVO: Buscar cursos donde el profesor esté asignado en cualquier posición del equipo
            $cursos = $db->fetchAll(
                "SELECT DISTINCT c.id, c.nombre, c.anio 
                 FROM cursos c
                 JOIN materias_por_curso mp ON c.id = mp.curso_id
                 WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                 AND c.ciclo_lectivo_id = ?
                 ORDER BY c.anio, c.nombre",
                [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $cicloLectivoId]
            );
        } else {
            $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
        }
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los cursos: ' . $e->getMessage() . '</div>';
}

// Procesar selección de curso
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;

// Variables para almacenar datos
$estudiantes = [];
$materias = [];
$calificaciones = [];
$observacionesPredefinidas = [];
$materiaInfo = null;
$equipoDocente = [];
$esEquipo = false;

// Obtener observaciones predefinidas
try {
    $observacionesPredefinidas = $db->fetchAll(
        "SELECT * FROM observaciones_predefinidas WHERE activo = 1 ORDER BY tipo, categoria, mensaje"
    );
} catch (Exception $e) {
    // Continuar sin observaciones predefinidas
}

// Si se seleccionó un curso
if ($cursoSeleccionado) {
    try {
        // Verificar permisos para profesores - ACTUALIZADO PARA EQUIPOS DOCENTES
        if ($_SESSION['user_type'] == 'profesor') {
            $cursoPermitido = $db->fetchOne(
                "SELECT COUNT(*) as count FROM materias_por_curso mp 
                 JOIN cursos c ON mp.curso_id = c.id
                 WHERE (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?) 
                 AND c.id = ? AND c.ciclo_lectivo_id = ?",
                [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $cursoSeleccionado, $cicloLectivoId]
            );
            
            if ($cursoPermitido['count'] == 0) {
                echo '<div class="alert alert-danger">No tiene permisos para acceder a este curso</div>';
                $cursoSeleccionado = null;
            }
        }
        
        if ($cursoSeleccionado) {
            // Obtener materias del curso - ACTUALIZADO PARA EQUIPOS DOCENTES
            if ($_SESSION['user_type'] == 'profesor') {
                $materias = $db->fetchAll(
                    "SELECT mp.id, m.nombre, m.codigo, mp.requiere_subgrupos,
                            mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                            (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                             CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                             CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores
                     FROM materias_por_curso mp 
                     JOIN materias m ON mp.materia_id = m.id 
                     WHERE mp.curso_id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)
                     ORDER BY m.nombre",
                    [$cursoSeleccionado, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
                );
            } else {
                $materias = $db->fetchAll(
                    "SELECT mp.id, m.nombre, m.codigo, mp.requiere_subgrupos,
                            mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                            (CASE WHEN mp.profesor_id IS NOT NULL THEN 1 ELSE 0 END +
                             CASE WHEN mp.profesor_id_2 IS NOT NULL THEN 1 ELSE 0 END +
                             CASE WHEN mp.profesor_id_3 IS NOT NULL THEN 1 ELSE 0 END) as total_profesores
                     FROM materias_por_curso mp 
                     JOIN materias m ON mp.materia_id = m.id 
                     WHERE mp.curso_id = ? 
                     ORDER BY m.nombre",
                    [$cursoSeleccionado]
                );
            }
        }
        
        // Si también se seleccionó una materia, obtener estudiantes y equipo docente
        if ($materiaSeleccionada) {
            // NUEVO: Verificar acceso a la materia específica para profesores
            if ($_SESSION['user_type'] == 'profesor') {
                $accesoMateria = function_exists('verificarAccesoMateria') 
                    ? verificarAccesoMateria($db, $_SESSION['user_id'], $materiaSeleccionada)
                    : $db->fetchOne(
                        "SELECT mp.id FROM materias_por_curso mp 
                         WHERE mp.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)",
                        [$materiaSeleccionada, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
                    );
                
                if (!$accesoMateria) {
                    echo '<div class="alert alert-danger">No tiene permisos para acceder a esta materia</div>';
                    $materiaSeleccionada = null;
                }
            }
            
            if ($materiaSeleccionada) {
                // Obtener información de la materia
                $materiaInfo = $db->fetchOne(
                    "SELECT mp.*, m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre
                     FROM materias_por_curso mp
                     JOIN materias m ON mp.materia_id = m.id
                     JOIN cursos c ON mp.curso_id = c.id
                     WHERE mp.id = ?",
                    [$materiaSeleccionada]
                );
                
                // NUEVO: Obtener información del equipo docente
                if (function_exists('obtenerEquipoDocente')) {
                    $equipoDocente = obtenerEquipoDocente($db, $materiaSeleccionada);
                    $esEquipo = count($equipoDocente) > 1;
                } else {
                    // Fallback básico
                    $equipoBasico = $db->fetchOne(
                        "SELECT mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                                p1.apellido as p1_apellido, p1.nombre as p1_nombre,
                                p2.apellido as p2_apellido, p2.nombre as p2_nombre,
                                p3.apellido as p3_apellido, p3.nombre as p3_nombre
                         FROM materias_por_curso mp
                         LEFT JOIN usuarios p1 ON mp.profesor_id = p1.id
                         LEFT JOIN usuarios p2 ON mp.profesor_id_2 = p2.id
                         LEFT JOIN usuarios p3 ON mp.profesor_id_3 = p3.id
                         WHERE mp.id = ?",
                        [$materiaSeleccionada]
                    );
                    
                    $equipoDocente = [];
                    if ($equipoBasico) {
                        if ($equipoBasico['profesor_id']) {
                            $equipoDocente[] = [
                                'id' => $equipoBasico['profesor_id'],
                                'posicion' => 1,
                                'nombre_completo' => $equipoBasico['p1_apellido'] . ', ' . $equipoBasico['p1_nombre']
                            ];
                        }
                        if ($equipoBasico['profesor_id_2']) {
                            $equipoDocente[] = [
                                'id' => $equipoBasico['profesor_id_2'],
                                'posicion' => 2,
                                'nombre_completo' => $equipoBasico['p2_apellido'] . ', ' . $equipoBasico['p2_nombre']
                            ];
                        }
                        if ($equipoBasico['profesor_id_3']) {
                            $equipoDocente[] = [
                                'id' => $equipoBasico['profesor_id_3'],
                                'posicion' => 3,
                                'nombre_completo' => $equipoBasico['p3_apellido'] . ', ' . $equipoBasico['p3_nombre']
                            ];
                        }
                    }
                    $esEquipo = count($equipoDocente) > 1;
                }
                
                // Obtener estudiantes según el tipo de materia
                $estudiantes = obtenerEstudiantesMateria($db, $cursoSeleccionado, $materiaSeleccionada, $cicloLectivoId, $materiaInfo);
                
                // Obtener calificaciones existentes
                foreach ($estudiantes as $estudiante) {
                    $calificacion = $db->fetchOne(
                        "SELECT * FROM calificaciones 
                         WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                        [$estudiante['id'], $materiaSeleccionada, $cicloLectivoId]
                    );
                    
                    if ($calificacion) {
                        $calificaciones[$estudiante['id']] = $calificacion;
                    }
                }
                
                // NUEVO: Registrar actividad si es parte de un equipo
                if (function_exists('registrarActividadEquipo') && $esEquipo) {
                    registrarActividadEquipo($db, $materiaSeleccionada, $_SESSION['user_id'], 'acceso_calificaciones', 'Acceso a calificaciones');
                }
            }
        }

    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

function obtenerEstudiantesMateria($db, $cursoId, $materiaCursoId, $cicloLectivoId, $materiaInfo) {
    $estudiantes = [];
    
    try {
        // Si la materia requiere subgrupos, obtener solo estudiantes asignados
        if ($materiaInfo && $materiaInfo['requiere_subgrupos']) {
            $estudiantes = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 
                        ep.subgrupo, ep.periodo_inicio, ep.periodo_fin,
                        'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN estudiantes_por_materia ep ON u.id = ep.estudiante_id
                 WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                 AND u.tipo = 'estudiante'
                 ORDER BY ep.subgrupo, u.apellido, u.nombre",
                [$materiaCursoId, $cicloLectivoId]
            );
        } else {
            // Estudiantes regulares del curso
            $estudiantesRegulares = $db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni, 
                        NULL as subgrupo, NULL as periodo_inicio, NULL as periodo_fin,
                        'regular' as tipo_matricula
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 ORDER BY u.apellido, u.nombre",
                [$cursoId]
            );
            
            // Estudiantes recursando esta materia específica
            $estudiantesRecursando = $db->fetchAll(
                "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni,
                        NULL as subgrupo, NULL as periodo_inicio, NULL as periodo_fin,
                        'recursando' as tipo_matricula
                 FROM usuarios u
                 JOIN materias_recursado mr ON u.id = mr.estudiante_id
                 WHERE mr.materia_curso_id = ? AND mr.estado = 'activo'
                 AND u.tipo = 'estudiante'
                 ORDER BY u.apellido, u.nombre",
                [$materiaCursoId]
            );
            
            // Combinar ambos grupos
            $estudiantes = array_merge($estudiantesRegulares, $estudiantesRecursando);
        }
        
        // Eliminar duplicados basándose en el ID del estudiante
        $estudiantesUnicos = [];
        $idsVistos = [];
        
        foreach ($estudiantes as $estudiante) {
            if (!in_array($estudiante['id'], $idsVistos)) {
                $estudiantesUnicos[] = $estudiante;
                $idsVistos[] = $estudiante['id'];
            }
        }
        
        return $estudiantesUnicos;
        
    } catch (Exception $e) {
        error_log("Error en obtenerEstudiantesMateria: " . $e->getMessage());
        return [];
    }
}

// Procesar formulario (actualizado para manejar bloqueos y equipos docentes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_datos']) && $cicloLectivoId > 0) {
    try {
        $tipoGuardado = $_POST['tipo_carga'];
        
        // NUEVO: Verificar acceso a la materia para profesores en equipos
        if ($_SESSION['user_type'] == 'profesor') {
            $accesoMateria = function_exists('verificarAccesoMateria') 
                ? verificarAccesoMateria($db, $_SESSION['user_id'], $materiaSeleccionada)
                : $db->fetchOne(
                    "SELECT mp.id FROM materias_por_curso mp 
                     WHERE mp.id = ? AND (mp.profesor_id = ? OR mp.profesor_id_2 = ? OR mp.profesor_id_3 = ?)",
                    [$materiaSeleccionada, $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
                );
            
            if (!$accesoMateria) {
                throw new Exception('No tiene permisos para modificar calificaciones de esta materia');
            }
        }
        
        $db->transaction(function($db) use ($cicloLectivoId, $materiaSeleccionada, $tipoGuardado, $configuracionBloqueos, $esEquipo, $equipoDocente) {
            foreach ($_POST['estudiantes'] as $estudianteId => $datos) {
                // Verificar si ya existe un registro
                $existe = $db->fetchOne(
                    "SELECT id FROM calificaciones 
                     WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                    [$estudianteId, $materiaSeleccionada, $cicloLectivoId]
                );
                
                if ($tipoGuardado === 'valoracion') {
                    // Guardar datos de valoración (bimestral) con validación de bloqueos
                    $bimestre = isset($_POST['bimestre']) ? $_POST['bimestre'] : '1';
                    $campo_valoracion = 'valoracion_' . $bimestre . 'bim';
                    $campo_desempeno = 'desempeno_' . $bimestre . 'bim';
                    $campo_observaciones = 'observaciones_' . $bimestre . 'bim';
                    
                    $valoracion = isset($datos['valoracion']) && in_array($datos['valoracion'], ['TEA', 'TEP', 'TED']) ? 
                                  $datos['valoracion'] : null;
                    $desempeno = isset($datos['desempeno']) ? $datos['desempeno'] : null;
                    
                    // Obtener observaciones del campo final procesado
                    $observaciones = null;
                    if (isset($_POST['estudiantes_observaciones_final'][$estudianteId])) {
                        $observaciones = $_POST['estudiantes_observaciones_final'][$estudianteId];
                    }
                    
                    if ($existe) {
                        $db->query(
                            "UPDATE calificaciones SET $campo_valoracion = ?, $campo_desempeno = ?, $campo_observaciones = ? WHERE id = ?",
                            [$valoracion, $desempeno, $observaciones, $existe['id']]
                        );
                    } else {
                        $db->query(
                            "INSERT INTO calificaciones 
                             (estudiante_id, materia_curso_id, ciclo_lectivo_id, $campo_valoracion, $campo_desempeno, $campo_observaciones)
                             VALUES (?, ?, ?, ?, ?, ?)",
                            [$estudianteId, $materiaSeleccionada, $cicloLectivoId, $valoracion, $desempeno, $observaciones]
                        );
                    }
                    
                    // Actualizar valoración preliminar correspondiente en cuatrimestre
                    if ($valoracion) {
                        $campoPreliminnar = ($bimestre == '1') ? 'valoracion_preliminar_1c' : 'valoracion_preliminar_2c';
                        if ($existe) {
                            $db->query(
                                "UPDATE calificaciones SET $campoPreliminnar = ? WHERE id = ?",
                                [$valoracion, $existe['id']]
                            );
                        } else {
                            // Ya se insertó arriba, pero actualizamos el campo preliminar
                            $ultimoId = $db->fetchOne("SELECT id FROM calificaciones WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ? ORDER BY id DESC LIMIT 1", [$estudianteId, $materiaSeleccionada, $cicloLectivoId])['id'];
                            $db->query(
                                "UPDATE calificaciones SET $campoPreliminnar = ? WHERE id = ?",
                                [$valoracion, $ultimoId]
                            );
                        }
                    }
                    
                } else {
                    // Guardar datos de cuatrimestre (lógica original con observaciones actualizadas)
                    $valoracion1c = isset($datos['valoracion_1c']) && in_array($datos['valoracion_1c'], ['TEA', 'TEP', 'TED']) ? 
                                    $datos['valoracion_1c'] : null;
                    $calificacion1c = isset($datos['calificacion_1c']) && is_numeric($datos['calificacion_1c']) ? 
                                      intval($datos['calificacion_1c']) : null;
                    $valoracion2c = isset($datos['valoracion_2c']) && in_array($datos['valoracion_2c'], ['TEA', 'TEP', 'TED']) ? 
                                    $datos['valoracion_2c'] : null;
                    $calificacion2c = isset($datos['calificacion_2c']) && is_numeric($datos['calificacion_2c']) ? 
                                      intval($datos['calificacion_2c']) : null;
                    $intensificacion1c = isset($datos['intensificacion_1c']) && is_numeric($datos['intensificacion_1c']) ? 
                                        intval($datos['intensificacion_1c']) : null;
                    $calificacionFinal = isset($datos['calificacion_final']) && is_numeric($datos['calificacion_final']) ? 
                                        intval($datos['calificacion_final']) : null;
                    $tipoCursada = isset($datos['tipo_cursada']) ? $datos['tipo_cursada'] : 'C';
                    
                    // Obtener observaciones del campo final procesado para cuatrimestre
                    $observaciones = null;
                    if (isset($_POST['estudiantes_observaciones_final'][$estudianteId])) {
                        $observaciones = $_POST['estudiantes_observaciones_final'][$estudianteId];
                    }
                    
                    $estadoFinal = ($calificacionFinal !== null && $calificacionFinal >= 4) ? 'aprobada' : 'pendiente';
                    
                    if ($existe) {
                        $db->query(
                            "UPDATE calificaciones SET 
                             valoracion_preliminar_1c = ?, calificacion_1c = ?,
                             valoracion_preliminar_2c = ?, calificacion_2c = ?,
                             intensificacion_1c = ?, calificacion_final = ?,
                             tipo_cursada = ?, observaciones = ?, estado_final = ?
                             WHERE id = ?",
                            [
                                $valoracion1c, $calificacion1c,
                                $valoracion2c, $calificacion2c,
                                $intensificacion1c, $calificacionFinal,
                                $tipoCursada, $observaciones, $estadoFinal,
                                $existe['id']
                            ]
                        );
                    } else {
                        $db->query(
                            "INSERT INTO calificaciones 
                             (estudiante_id, materia_curso_id, ciclo_lectivo_id,
                              valoracion_preliminar_1c, calificacion_1c,
                              valoracion_preliminar_2c, calificacion_2c,
                              intensificacion_1c, calificacion_final,
                              tipo_cursada, observaciones, estado_final)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $estudianteId, $materiaSeleccionada, $cicloLectivoId,
                                $valoracion1c, $calificacion1c,
                                $valoracion2c, $calificacion2c,
                                $intensificacion1c, $calificacionFinal,
                                $tipoCursada, $observaciones, $estadoFinal
                            ]
                        );
                    }
                }
            }
            
            // NUEVO: Registrar actividad de equipo docente si aplica
            if (function_exists('registrarActividadEquipo') && $esEquipo) {
                $cantidadEstudiantes = count($_POST['estudiantes']);
                $detalles = "Guardó calificaciones para $cantidadEstudiantes estudiantes ($tipoGuardado)";
                registrarActividadEquipo($db, $materiaSeleccionada, $_SESSION['user_id'], 'guardar_calificaciones', $detalles);
            }
        });
        
        $_SESSION['message'] = 'Calificaciones guardadas correctamente';
        $_SESSION['message_type'] = 'success';
        
        header("Location: calificaciones.php?curso=$cursoSeleccionado&materia=$materiaSeleccionada&tipo=$tipoGuardado");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al guardar los datos: ' . $e->getMessage() . '</div>';
    }
}

// Incluir el encabezado DESPUÉS del procesamiento
require_once 'header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">
                    <i class="bi bi-journal-check"></i> Gestión de Calificaciones - Ciclo Lectivo <?= isset($anioActivo) ? $anioActivo : date('Y') ?>
                    
                </h5>
            </div>
            <div class="card-body">
                <!-- Alerta de estado de bloqueos -->
                <?php 
                $alertaBloqueo = generarAlertaBloqueos($configuracionBloqueos, $_SESSION['user_type']);
                if ($alertaBloqueo): 
                ?>
                <div class="alert alert-<?= $alertaBloqueo['tipo'] ?> mb-3 d-none">
                    <?= $alertaBloqueo['mensaje'] ?>
                    <?php if ($alertaBloqueo['detalle']): ?>
                        <br><small><?= htmlspecialchars($alertaBloqueo['detalle']) ?></small>
                    <?php endif; ?>
                    
                    <?php if (puedeEditarCalificaciones($_SESSION['user_type'])): ?>
                    <div class="mt-2">
                        <a href="gestionar_bloqueos_calificaciones.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-gear"></i> Gestionar Bloqueos
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>                
                
                <!-- Selector de tipo de carga -->
                <div class="row mb-3">
                    <div class="col-md-12">
                       
                    <div class="btn-group" role="group" aria-label="Tipo de carga">
                            <input type="radio" class="btn-check" name="tipo_carga_radio" id="tipo_valoracion" 
                                   value="valoracion" <?= $tipoCarga === 'valoracion' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="tipo_valoracion">
                                <i class="bi bi-clipboard-check"></i> Valoración Preliminar (Bimestral)
                            </label>
                            
                            <input type="radio" class="btn-check" name="tipo_carga_radio" id="tipo_cuatrimestre" 
                                   value="cuatrimestre" <?= $tipoCarga === 'cuatrimestre' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-success" for="tipo_cuatrimestre">
                                <i class="bi bi-journal-text"></i> Calificaciones Cuatrimestrales
                            </label>
                        </div>
                        
                    </div>
                </div>
                
                <form method="GET" action="calificaciones.php" class="mb-4" id="form-seleccion">
                    <input type="hidden" name="tipo" id="hidden_tipo" value="<?= $tipoCarga ?>">
                    <div class="row align-items-end">
                        <div class="col-md-5">
                            <label for="curso" class="form-label">Seleccione Curso:</label>
                            <select name="curso" id="curso" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                    <?= $curso['nombre'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($cursoSeleccionado): ?>
                        <div class="col-md-5">
                            <label for="materia" class="form-label">Seleccione Materia:</label>
                            <select name="materia" id="materia" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleccione una materia --</option>
                                <?php foreach ($materias as $materia): ?>
                                <option value="<?= $materia['id'] ?>" <?= ($materiaSeleccionada == $materia['id']) ? 'selected' : '' ?>>
                                    <?= $materia['nombre'] ?> 
                                    <?php if ($materia['requiere_subgrupos']): ?>
                                        <span class="badge bg-info">Rotación</span>
                                    <?php endif; ?>
                                    <?php if ($materia['total_profesores'] > 1): ?>
                                        
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
                
                <!-- Información de la materia -->
                <?php if ($materiaInfo): ?>
                <div class="alert alert-info">
                    <strong>Materia:</strong> <?= htmlspecialchars($materiaInfo['materia_nombre']) ?> 
                    <br>
                    <strong>Curso:</strong> <?= htmlspecialchars($materiaInfo['curso_nombre']) ?>
                    <?php if ($materiaInfo['requiere_subgrupos']): ?>
                        <br><span class="badge bg-warning">Esta materia trabaja con rotación</span>
                    <?php endif; ?>
                    
                    <br>
                    <strong>Estudiantes encontrados:</strong> <?= count($estudiantes) ?>
                    <?php if (count($estudiantes) > 0): ?>
                        <?php 
                        $regulares = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'regular'; });
                        $recursando = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'recursando'; });
                        ?>
                        (<?= count($regulares) ?> regulares<?= count($recursando) > 0 ? ', ' . count($recursando) . ' recursando' : '' ?>)
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($cursoSeleccionado && $materiaSeleccionada && count($estudiantes) > 0): ?>
                
                <!-- Selector de bimestre para valoraciones -->
                <?php if ($tipoCarga === 'valoracion'): ?>
				<div class="row mb-3">
					<div class="col-md-12">
						<div class="btn-group" role="group" aria-label="Bimestre">
							<input type="radio" class="btn-check" name="bimestre_radio" id="bim1" value="1" 
								   <?= $bimestreSeleccionado == 1 ? 'checked' : '' ?>>
							<label class="btn btn-outline-info" for="bim1">1er Bimestre</label>
							
							<input type="radio" class="btn-check" name="bimestre_radio" id="bim3" value="3" 
								   <?= $bimestreSeleccionado == 3 ? 'checked' : '' ?>>
							<label class="btn btn-outline-info" for="bim3">3er Bimestre</label>
						</div>
					</div>
				</div>
				<?php endif; ?>
                
                <!-- Formulario principal -->
                <form method="POST" action="calificaciones.php?curso=<?= $cursoSeleccionado ?>&materia=<?= $materiaSeleccionada ?>&tipo=<?= $tipoCarga ?>">
                    <input type="hidden" name="tipo_carga" value="<?= $tipoCarga ?>">
                    <input type="hidden" name="bimestre" id="bimestre_hidden" value="<?= $bimestreSeleccionado ?>">
                    
                    <?php if ($tipoCarga === 'valoracion'): ?>
                        <!-- Vista de valoraciones preliminares -->
                        <?php include 'includes/vista_valoraciones_con_bloqueos.php'; ?>
                    <?php else: ?>
                        <!-- Vista de calificaciones cuatrimestrales -->
                        <?php include 'includes/vista_cuatrimestral_con_bloqueos.php'; ?>
                    <?php endif; ?>
                    
                    <div class="text-center mt-3">
                        <button type="submit" name="guardar_datos" class="btn btn-primary btn-lg">
                            <i class="bi bi-save"></i> Guardar Calificaciones
                            
                        </button>
                    </div>
                </form>
                
                <?php elseif ($cursoSeleccionado && $materiaSeleccionada): ?>
                <div class="alert alert-warning">
                    No se encontraron estudiantes matriculados en este curso para esta materia.
                    <?php if ($materiaInfo && $materiaInfo['requiere_subgrupos']): ?>
                    <br><small>Esta materia requiere rotació</nav>. <a href="gestionar_subgrupos.php?materia=<?= $materiaSeleccionada ?>">Configurar rotación</a></small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- NUEVO: Modal para ver equipo docente -->
<div class="modal fade" id="modalEquipoDocente" tabindex="-1" aria-labelledby="modalEquipoDocenteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEquipoDocenteLabel">
                    <i class="bi bi-people-fill"></i> Equipo Docente - Calificaciones
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

<style>
/* Estilos para campos bloqueados por el sistema */
.campo-bloqueado-sistema {
    background-color: #f8f9fa !important;
    border: 2px solid #dc3545 !important;
    color: #6c757d !important;
    cursor: not-allowed !important;
    opacity: 0.7;
}

.campo-bloqueado-sistema:disabled {
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 5px,
        rgba(220, 53, 69, 0.1) 5px,
        rgba(220, 53, 69, 0.1) 10px
    );
}

/* Estilos para campos que admin/directivos pueden editar a pesar del bloqueo */
.campo-admin-override {
    border: 2px solid #ffc107 !important;
    background-color: #fff3cd !important;
}

.campo-admin-override:focus {
    border-color: #ffb300 !important;
    box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25) !important;
}

/* Estilos específicos para equipos docentes */
.equipo-docente-highlight {
    background: linear-gradient(90deg, rgba(13, 110, 253, 0.1) 0%, rgba(255, 255, 255, 0) 100%);
    border-left: 3px solid #007bff;
}

.badge-equipo {
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
}

.badge-sm {
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
}

/* Indicadores visuales de estado */
.icono-bloqueo {
    position: absolute;
    top: 2px;
    right: 2px;
    z-index: 10;
}

.campo-con-bloqueo {
    position: relative;
}

/* Tooltip mejorado para campos bloqueados */
.campo-bloqueado-sistema[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #dc3545;
    color: white;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
}

.campo-admin-override[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #ffc107;
    color: #212529;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
}

/* Animación para campos desbloqueados recientemente */
@keyframes desbloquear {
    0% { 
        border-color: #dc3545; 
        background-color: #f8f9fa; 
    }
    50% { 
        border-color: #28a745; 
        background-color: #d4edda; 
    }
    100% { 
        border-color: #ced4da; 
        background-color: #fff; 
    }
}

.campo-desbloqueado {
    animation: desbloquear 1s ease-in-out;
}

/* Estilos responsivos para equipos */
@media (max-width: 768px) {
    .badge-equipo {
        font-size: 0.6rem;
        padding: 0.1rem 0.3rem;
    }
    
    .equipo-docente-highlight {
        border-left-width: 2px;
    }
}
</style>

<script>
// JavaScript mejorado para manejar el cambio de tipo de carga y bloqueos - ACTUALIZADO PARA EQUIPOS DOCENTES
document.addEventListener('DOMContentLoaded', function() {
    // Configuración de bloqueos desde PHP
    const configuracionBloqueos = <?= json_encode($configuracionBloqueos) ?>;
    const tipoUsuario = '<?= $_SESSION['user_type'] ?>';
    const esAdmin = ['admin', 'directivo'].includes(tipoUsuario);
    const esEquipo = <?= $esEquipo ? 'true' : 'false' ?>;
    const equipoDocente = <?= json_encode($equipoDocente) ?>;
    
    // Establecer el valor inicial del bimestre desde PHP
    const bimestreInicial = <?= $bimestreSeleccionado ?>;
    const bimestreInput = document.getElementById('bimestre_hidden');
    if (bimestreInput) {
        bimestreInput.value = bimestreInicial;
    }
    
    // NUEVO: Mostrar información adicional para equipos docentes
    if (esEquipo) {
        console.log('Trabajando en equipo docente:', equipoDocente);
        
        // Agregar indicador visual en formularios
        const formulario = document.querySelector('form[method="POST"]');
        if (formulario) {
            formulario.classList.add('equipo-docente-highlight');
        }
    }
    
    // Manejar cambio de tipo de carga
    document.querySelectorAll('input[name="tipo_carga_radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const tipoInput = document.getElementById('hidden_tipo');
            if (tipoInput) {
                tipoInput.value = this.value;
                document.getElementById('form-seleccion').submit();
            }
        });
    });
    
    // Manejar cambio de bimestre
    document.querySelectorAll('input[name="bimestre_radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const bimestreSeleccionado = this.value;
            const bimestreInput = document.getElementById('bimestre_hidden');
            if (bimestreInput) {
                bimestreInput.value = bimestreSeleccionado;
            }
            
            // Construir URL con parámetros actuales
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('bimestre', bimestreSeleccionado);
            
            // Mantener otros parámetros
            const cursoSelect = document.getElementById('curso');
            const materiaSelect = document.getElementById('materia');
            const tipoInput = document.getElementById('hidden_tipo');
            
            if (cursoSelect && cursoSelect.value) currentUrl.searchParams.set('curso', cursoSelect.value);
            if (materiaSelect && materiaSelect.value) currentUrl.searchParams.set('materia', materiaSelect.value);
            if (tipoInput && tipoInput.value) currentUrl.searchParams.set('tipo', tipoInput.value);
            
            // Recargar la página
            window.location.href = currentUrl.toString();
        });
    });
    
    // Función para verificar si un campo está bloqueado
    function estaColumnaBloqueada(nombreCampo) {
        if (configuracionBloqueos.bloqueo_general) {
            return true;
        }
        return configuracionBloqueos[nombreCampo] == 1;
    }
    
    // Inicializar tooltips para campos bloqueados
    const camposBloqueados = document.querySelectorAll('.campo-bloqueado-sistema, .campo-admin-override');
    camposBloqueados.forEach(function(campo) {
        // Mejorar feedback visual al hacer hover
        campo.addEventListener('mouseenter', function() {
            if (this.classList.contains('campo-bloqueado-sistema')) {
                this.style.borderColor = '#721c24';
            } else if (this.classList.contains('campo-admin-override')) {
                this.style.borderColor = '#d39e00';
            }
        });
        
        campo.addEventListener('mouseleave', function() {
            if (this.classList.contains('campo-bloqueado-sistema')) {
                this.style.borderColor = '#dc3545';
            } else if (this.classList.contains('campo-admin-override')) {
                this.style.borderColor = '#ffc107';
            }
        });
        
        // Prevenir edición para profesores en campos bloqueados
        if (this.classList.contains('campo-bloqueado-sistema') && !esAdmin) {
            campo.addEventListener('focus', function(e) {
                e.preventDefault();
                this.blur();
                mostrarMensajeBloqueo();
            });
            
            campo.addEventListener('click', function(e) {
                e.preventDefault();
                mostrarMensajeBloqueo();
            });
        }
    });
    
    // Función para mostrar mensaje de bloqueo (mejorada para equipos)
    function mostrarMensajeBloqueo() {
        if (document.querySelector('.mensaje-bloqueo-temporal')) {
            return; // Ya hay un mensaje visible
        }
        
        const mensaje = document.createElement('div');
        mensaje.className = 'alert alert-warning alert-dismissible fade show mensaje-bloqueo-temporal position-fixed';
        mensaje.style.top = '20px';
        mensaje.style.right = '20px';
        mensaje.style.zIndex = '9999';
        mensaje.style.maxWidth = '400px';
        
        const motivoBloqueo = configuracionBloqueos.observaciones || 'Campo bloqueado por configuración del sistema';
        const mensajeEquipo = esEquipo ? '<br><small><i class="bi bi-people"></i> Este bloqueo aplica a todo el equipo docente.</small>' : '';
        
        mensaje.innerHTML = `
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <h6><i class="bi bi-lock"></i> Campo Bloqueado</h6>
            <p class="mb-1">Este campo está temporalmente deshabilitado para edición.</p>
            <small class="text-muted">${motivoBloqueo}</small>
            ${mensajeEquipo}
        `;
        
        document.body.appendChild(mensaje);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (mensaje.parentNode) {
                mensaje.remove();
            }
        }, 5000);
    }
    
    // Validación mejorada del formulario considerando bloqueos y equipos
    const formulario = document.querySelector('form[method="POST"]');
    if (formulario) {
        formulario.addEventListener('submit', function(e) {
            // Verificar si hay campos obligatorios bloqueados sin valor
            let camposProblematicos = [];
            
            // Para admin/directivos, advertir sobre campos bloqueados que están editando
            if (esAdmin) {
                const camposAdminOverride = this.querySelectorAll('.campo-admin-override');
                if (camposAdminOverride.length > 0) {
                    let mensaje = `Está editando ${camposAdminOverride.length} campo(s) que están bloqueados para profesores.`;
                    if (esEquipo) {
                        mensaje += `\n\nEsta materia tiene un equipo docente de ${equipoDocente.length} profesores. Sus cambios serán visibles para todo el equipo.`;
                    }
                    mensaje += `\n\n¿Confirma que desea guardar estos cambios?`;
                    
                    const confirmar = confirm(mensaje);
                    
                    if (!confirmar) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
            
            // NUEVO: Confirmación adicional para equipos docentes
            if (esEquipo && !configuracionBloqueos.bloqueo_general) {
                const cambios = this.querySelectorAll('input[type="text"]:not([readonly]), select:not([disabled]), textarea:not([readonly])');
                let tieneCambios = false;
                
                cambios.forEach(campo => {
                    if (campo.value && campo.value.trim() !== '') {
                        tieneCambios = true;
                    }
                });
                
                if (tieneCambios) {
                    const profesorActual = equipoDocente.find(p => p.id == <?= $_SESSION['user_id'] ?>);
                    const otrosProfesores = equipoDocente.filter(p => p.id != <?= $_SESSION['user_id'] ?>);
                    
                    if (otrosProfesores.length > 0) {
                        const nombresOtros = otrosProfesores.map(p => p.nombre_completo).join(', ');
                        const confirmarEquipo = confirm(
                            `Está guardando calificaciones como parte del equipo docente.\n\n` +
                            `Sus compañeros de equipo: ${nombresOtros}\n` +
                            `También tendrán acceso a estas calificaciones.\n\n` +
                            `¿Confirma que desea guardar?`
                        );
                        
                        if (!confirmarEquipo) {
                            e.preventDefault();
                            return false;
                        }
                    }
                }
            }
            
            // Verificar que hay al menos algunos datos para guardar
            const todosBloqueados = verificarSiTodoEstaBloqueado();
            if (todosBloqueados && !esAdmin) {
                e.preventDefault();
                alert('No se pueden guardar datos: todos los campos están bloqueados.');
                return false;
            }
        });
    }
    
    // Función para verificar si todos los campos están bloqueados
    function verificarSiTodoEstaBloqueado() {
        if (configuracionBloqueos.bloqueo_general) {
            return true;
        }
        
        // Contar campos bloqueados vs total
        let totalCampos = 0;
        let camposBloqueados = 0;
        
        for (const campo in configuracionBloqueos) {
            if (campo.includes('_bloqueada') || campo.includes('_bloqueado')) {
                totalCampos++;
                if (configuracionBloqueos[campo] == 1) {
                    camposBloqueados++;
                }
            }
        }
        
        return camposBloqueados === totalCampos;
    }
    
    // NUEVA: Detectar cambios en formularios para equipos docentes
    if (esEquipo) {
        let cambiosRealizados = false;
        
        formulario.addEventListener('input', function(e) {
            if (!cambiosRealizados && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA')) {
                cambiosRealizados = true;
                
                // Agregar indicador visual sutil
                const header = document.querySelector('.card-header h5');
                if (header && !header.querySelector('.badge-cambios')) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-warning ms-2 badge-cambios';
                    badge.innerHTML = '<i class="bi bi-pencil"></i> Editando';
                    header.appendChild(badge);
                }
            }
        });
        
        // Advertencia al salir sin guardar para equipos
        window.addEventListener('beforeunload', function(e) {
            if (cambiosRealizados) {
                e.preventDefault();
                e.returnValue = 'Tiene cambios sin guardar que serían visibles para todo el equipo docente. ¿Está seguro de salir?';
                return e.returnValue;
            }
        });
        
        // Remover advertencia al enviar formulario
        formulario.addEventListener('submit', function() {
            cambiosRealizados = false;
        });
    }
});

// NUEVA: Función para ver equipo docente en calificaciones
function verEquipoDocente(materiaCursoId) {
    document.getElementById('contenidoEquipoDocente').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>
    `;
    
    fetch('obtener_equipo_docente.php?materia_curso_id=' + materiaCursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `
                    <h6>Materia: ${data.materia.nombre} (${data.materia.codigo})</h6>
                    <p class="text-muted">Curso: ${data.materia.curso}</p>
                    <hr>
                `;
                
                if (data.equipo && data.equipo.length > 0) {
                    html += `
                        <div class="alert alert-info">
                            <h6 class="alert-heading">
                                <i class="bi bi-info-circle"></i> Gestión de Calificaciones en Equipo
                            </h6>
                            <ul class="mb-0">
                                <li>Todos los profesores pueden ver y modificar calificaciones</li>
                                <li>Los cambios son inmediatos y visibles para todo el equipo</li>
                                <li>Se recomienda coordinar criterios de evaluación</li>
                                <li>Cualquier profesor puede guardar calificaciones</li>
                            </ul>
                        </div>
                        <div class="row">
                    `;
                    
                    data.equipo.forEach((profesor, index) => {
                        const esActual = profesor.es_actual;
                        const cardClass = esActual ? 'border-primary' : 'border-secondary';
                        const badgeClass = esActual ? 'bg-primary' : 'bg-secondary';
                        
                        html += `
                            <div class="col-md-4 mb-3">
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
                                        ${profesor.telefono ? `<small class="text-muted">Tel: ${profesor.telefono}</small>` : ''}
                                        ${esActual ? '<div class="mt-2"><span class="badge bg-success">Sesión Actual</span></div>' : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += `
                        </div>
                        <div class="alert alert-warning mt-3">
                            <h6 class="alert-heading">Recomendaciones para Equipos Docentes</h6>
                            <ul class="mb-0">
                                <li><strong>Comunicación:</strong> Coordine con sus colegas antes de realizar cambios importantes</li>
                                <li><strong>Consistencia:</strong> Mantenga criterios de evaluación coherentes entre todo el equipo</li>
                                <li><strong>Responsabilidad:</strong> Todos los cambios quedan registrados con fecha y hora</li>
                                <li><strong>Acceso:</strong> Cada profesor puede trabajar de forma independiente cuando sea necesario</li>
                            </ul>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            No se encontró información del equipo docente.
                        </div>
                    `;
                }
                
                document.getElementById('contenidoEquipoDocente').innerHTML = html;
            } else {
                document.getElementById('contenidoEquipoDocente').innerHTML = `
                    <div class="alert alert-danger">
                        Error al cargar la información: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoEquipoDocente').innerHTML = `
                <div class="alert alert-danger">
                    Error al cargar la información del equipo docente.
                </div>
            `;
        });
}

// Función para cargar datos del bimestre seleccionado (actualizada)
function cargarDatosBimestre(bimestre) {
    console.log('cargarDatosBimestre llamada con bimestre:', bimestre);
}

// Función para insertar observación predefinida
function insertarObservacion(estudianteId, mensaje) {
    const textarea = document.querySelector(`textarea[name="estudiantes[${estudianteId}][observaciones]"]`);
    if (textarea) {
        if (textarea.value.trim() !== '') {
            textarea.value += '. ' + mensaje;
        } else {
            textarea.value = mensaje;
        }
        
        // NUEVO: Si es equipo docente, mostrar advertencia
        const esEquipo = <?= $esEquipo ? 'true' : 'false' ?>;
        if (esEquipo) {
            textarea.style.borderColor = '#0d6efd';
            textarea.style.backgroundColor = 'rgba(13, 110, 253, 0.1)';
            
            setTimeout(() => {
                textarea.style.borderColor = '';
                textarea.style.backgroundColor = '';
            }, 2000);
        }
    }
}

// Función para validar formulario antes de enviar (mejorada para equipos)
function validarFormulario() {
    let valido = true;
    const campos = document.querySelectorAll('input[required], select[required]');
    
    campos.forEach(function(campo) {
        if (!campo.value.trim()) {
            campo.classList.add('is-invalid');
            valido = false;
        } else {
            campo.classList.remove('is-invalid');
        }
    });
    
    if (!valido) {
        const esEquipo = <?= $esEquipo ? 'true' : 'false' ?>;
        let mensaje = 'Por favor complete todos los campos obligatorios';
        if (esEquipo) {
            mensaje += '\n\nRecuerde que está trabajando en equipo docente.';
        }
        alert(mensaje);
    }
    
    return valido;
}

// NUEVA: Función para mostrar notificación de equipo docente
function mostrarNotificacionEquipo(mensaje, tipo = 'info') {
    const esEquipo = <?= $esEquipo ? 'true' : 'false' ?>;
    if (!esEquipo) return;
    
    const notificacion = document.createElement('div');
    notificacion.className = `alert alert-${tipo} alert-dismissible fade show position-fixed`;
    notificacion.style.top = '80px';
    notificacion.style.right = '20px';
    notificacion.style.zIndex = '9998';
    notificacion.style.maxWidth = '350px';
    
    notificacion.innerHTML = `
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <h6><i class="bi bi-people-fill"></i> Equipo Docente</h6>
        <p class="mb-0">${mensaje}</p>
    `;
    
    document.body.appendChild(notificacion);
    
    setTimeout(() => {
        if (notificacion.parentNode) {
            notificacion.remove();
        }
    }, 4000);
}
</script>

<?php
// Limpiar el buffer de salida y enviarlo
ob_end_flush();

// Incluir el pie de página
require_once 'footer.php';
?>

<!-- MODALES PARA GESTIÓN DE CONTENIDOS EN CALIFICACIONES -->

<!-- Modal para crear contenido desde calificaciones -->
<div class="modal fade" id="modalCrearContenidoCalif" tabindex="-1" aria-labelledby="modalCrearContenidoCalifLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalCrearContenidoCalifLabel">
                    <i class="bi bi-plus-circle"></i> Crear Nuevo Contenido
                    <?php if ($esEquipo ?? false): ?>
                    <span class="badge bg-info ms-2">
                        <i class="bi bi-people-fill"></i> Equipo Docente
                    </span>
                    <?php endif; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <!-- Formulario de creación de contenido -->
                <form id="formCrearContenidoCalif" method="POST">
                    <input type="hidden" name="accion" value="crear_contenido">
                    <input type="hidden" name="materia_curso_id" id="contenido_materia_id" value="<?= $materiaSeleccionada ?>">
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="contenido_titulo" class="form-label">Título del Contenido: *</label>
                                <input type="text" name="titulo" id="contenido_titulo" class="form-control" 
                                       placeholder="Ej: Introducción a las funciones lineales" maxlength="200" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="contenido_fecha" class="form-label">Fecha de Clase: *</label>
                                <input type="date" name="fecha_clase" id="contenido_fecha" class="form-control" 
                                       value="<?= date('Y-m-d') ?>" required>
                                <div id="periodo_detectado_calif" class="form-text"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="contenido_descripcion" class="form-label">Descripción:</label>
                        <textarea name="descripcion" id="contenido_descripcion" class="form-control" rows="3"
                                  placeholder="Descripción detallada del contenido, objetivos, actividades realizadas..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tipo de Evaluación: *</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_evaluacion" 
                                           id="tipo_cualitativa_calif" value="cualitativa" checked>
                                    <label class="form-check-label" for="tipo_cualitativa_calif">
                                        <strong>Cualitativa</strong><br>
                                        <small class="text-muted">Acreditado/No Acreditado/No Corresponde</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 d-none">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_evaluacion" 
                                           id="tipo_numerica_calif" value="numerica">
                                    <label class="form-check-label" for="tipo_numerica_calif">
                                        <strong>Numérica</strong><br>
                                        <small class="text-muted">Calificaciones del 1 al 10</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
               </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
                
                <button type="button" class="btn btn-success" onclick="crearContenidoSolo()">
                    <i class="bi bi-check-circle"></i> Crear
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para calificar contenido existente -->
<div class="modal fade" id="modalCalificarContenido" tabindex="-1" aria-labelledby="modalCalificarContenidoLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalCalificarContenidoLabel">
                    <i class="bi bi-check2-square"></i> Calificar Contenido
                    <?php if ($esEquipo ?? false): ?>
                    <span class="badge bg-info ms-2">
                        <i class="bi bi-people-fill"></i> Colaborativo
                    </span>
                    <?php endif; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div id="contenidoCalificarModal">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2">Cargando contenido...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cerrar
                </button>
                <button type="button" class="btn btn-primary" id="btnGuardarCalificaciones" style="display: none;">
                    <i class="bi bi-save"></i> Guardar Calificaciones
                    
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Botón flotante para crear contenido -->
<?php if ($cursoSeleccionado && $materiaSeleccionada): ?>
<div class="position-fixed" style="bottom: 20px; right: 20px; z-index: 1050;">
    <div class="btn-group-vertical" role="group">
        <button type="button" class="btn btn-primary btn-lg shadow" 
                onclick="abrirModalCrearContenido()" 
                title="Crear nuevo contenido"
                style="border-radius: 50px; width: 60px; height: 60px;">
            <i class="bi bi-plus-circle fs-4"></i>
        </button>
        <div class="mt-2 text-center">
            <small class="text-muted d-none d-lg-block">Nuevo<br>Contenido</small>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// FUNCIONES JAVASCRIPT PARA GESTIÓN DE CONTENIDOS EN CALIFICACIONES

// Configuración global
const materiaSeleccionada = <?= $materiaSeleccionada ?? 'null' ?>;
const esEquipoDocente = <?= ($esEquipo ?? false) ? 'true' : 'false' ?>;

// NUEVA FUNCIÓN: Detectar período automáticamente para fechas
function actualizarPeriodoDetectado(fecha) {
    if (!fecha) return;
    
    fetch('api_detectar_periodo.php?fecha=' + fecha)
        .then(response => response.json())
        .then(data => {
            const elemento = document.getElementById('periodo_detectado_calif');
            if (elemento && data.periodo_nombre) {
                elemento.textContent = 'Período detectado: ' + data.periodo_nombre;
                
                if (data.es_intensificacion) {
                    elemento.innerHTML += '<br><span class="text-warning">⚠️ Período de intensificación</span>';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

// NUEVA FUNCIÓN: Crear contenido y calificar
// FUNCIÓN CORREGIDA: Crear contenido y calificar
function crearYCalificarContenido() {
    const form = document.getElementById('formCrearContenidoCalif');
    const formData = new FormData(form);
    
    // Validar formulario
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Mostrar loading
    const btnCrear = document.querySelector('button[onclick="crearYCalificarContenido()"]');
    const textoOriginal = btnCrear.innerHTML;
    btnCrear.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creando...';
    btnCrear.disabled = true;
    
    fetch('ajax_crear_contenido.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Cerrar modal de creación
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalCrearContenidoCalif'));
            modal.hide();
            
            // Mostrar mensaje de éxito
            mostrarAlerta('success', 'Contenido creado exitosamente');
            
            // PRIMERO: Abrir modal de calificación inmediatamente
            // Esto es lo más importante para el usuario
            setTimeout(() => {
                abrirModalCalificar(data.contenido_id);
            }, 300);
            
            // SEGUNDO: Actualizar la vista en segundo plano
            // Esto ocurre mientras el usuario está calificando
            if (data.actualizar_vista) {
                console.log('Actualizando vista en segundo plano...');
                
                setTimeout(() => {
                    if (typeof actualizarVistaCalificaciones === 'function') {
                        console.log('Usando actualizarVistaCalificaciones()');
                        actualizarVistaCalificaciones();
                    } else if (typeof actualizarTablaContenidos === 'function') {
                        console.log('Usando actualizarTablaContenidos()');
                        actualizarTablaContenidos();
                    }
                    // NO recargamos la página aquí porque el usuario está en el modal
                }, 1000);
            }
            
        } else {
            mostrarAlerta('danger', 'Error al crear contenido: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('danger', 'Error de conexión al crear contenido');
    })
    .finally(() => {
        btnCrear.innerHTML = textoOriginal;
        btnCrear.disabled = false;
    });
}

// NUEVA FUNCIÓN: Crear contenido solo (sin calificar)
// NUEVA FUNCIÓN: Crear contenido solo (sin calificar) - ACTUALIZADA CON AUTO-REFRESH
function crearContenidoSolo() {
    const form = document.getElementById('formCrearContenidoCalif');
    const formData = new FormData(form);
    
    // Validar formulario
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const btnCrear = document.querySelector('button[onclick="crearContenidoSolo()"]');
    const textoOriginal = btnCrear.innerHTML;
    btnCrear.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creando...';
    btnCrear.disabled = true;
    
    fetch('ajax_crear_contenido.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalCrearContenidoCalif'));
            modal.hide();
            
            mostrarAlerta('success', 'Contenido creado exitosamente');
            
            // NUEVO: Verificar si debe actualizar automáticamente
            if (data.actualizar_vista) {
                console.log('Actualizando vista automáticamente...');
                
                // Intentar actualizar dinámicamente primero
                if (typeof actualizarVistaCalificaciones === 'function') {
                    console.log('Usando actualizarVistaCalificaciones()');
                    actualizarVistaCalificaciones();
                } else if (typeof actualizarTablaContenidos === 'function') {
                    console.log('Usando actualizarTablaContenidos()');
                    actualizarTablaContenidos();
                } else {
                    console.log('Fallback: recargando página completa');
                    // Fallback: recargar la página después de un breve delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                }
            } else {
                // Fallback original si no viene la señal de actualización
                if (typeof actualizarTablaContenidos === 'function') {
                    actualizarTablaContenidos();
                } else {
                    // Recargar la página manteniendo los parámetros actuales
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                }
            }
        } else {
            mostrarAlerta('danger', 'Error al crear contenido: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('danger', 'Error de conexión al crear contenido');
    })
    .finally(() => {
        btnCrear.innerHTML = textoOriginal;
        btnCrear.disabled = false;
    });
}

// NUEVA FUNCIÓN: Crear contenido y calificar - ACTUALIZADA CON AUTO-REFRESH
function crearYCalificarContenido() {
    const form = document.getElementById('formCrearContenidoCalif');
    const formData = new FormData(form);
    
    // Validar formulario
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Mostrar loading
    const btnCrear = document.querySelector('button[onclick="crearYCalificarContenido()"]');
    const textoOriginal = btnCrear.innerHTML;
    btnCrear.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creando...';
    btnCrear.disabled = true;
    
    fetch('ajax_crear_contenido.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Cerrar modal de creación
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalCrearContenidoCalif'));
            modal.hide();
            
            // Mostrar mensaje de éxito
            mostrarAlerta('success', 'Contenido creado exitosamente');
            
            // NUEVO: Verificar si debe actualizar automáticamente ANTES de abrir modal
            if (data.actualizar_vista) {
                console.log('Actualizando vista automáticamente...');
                
                // Intentar actualizar dinámicamente primero
                if (typeof actualizarVistaCalificaciones === 'function') {
                    console.log('Usando actualizarVistaCalificaciones()');
                    actualizarVistaCalificaciones();
                } else if (typeof actualizarTablaContenidos === 'function') {
                    console.log('Usando actualizarTablaContenidos()');
                    actualizarTablaContenidos();
                } else {
                    console.log('Fallback: recargando página después de calificar');
                    // En este caso, abrimos el modal primero y luego recargamos
                    setTimeout(() => {
                        abrirModalCalificar(data.contenido_id);
                    }, 500);
                    
                    // Programar recarga después del modal
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                    return; // Salir aquí para evitar el código de abajo
                }
            }
            
            // Abrir modal de calificación con el nuevo contenido
            setTimeout(() => {
                abrirModalCalificar(data.contenido_id);
            }, 500);
            
        } else {
            mostrarAlerta('danger', 'Error al crear contenido: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('danger', 'Error de conexión al crear contenido');
    })
    .finally(() => {
        btnCrear.innerHTML = textoOriginal;
        btnCrear.disabled = false;
    });
}

// FUNCIÓN MEJORADA: Actualizar solo la sección de contenidos/columnas
// FUNCIÓN AVANZADA: Actualizar solo la sección de contenidos/columnas
function actualizarVistaCalificaciones() {
    // Obtener parámetros actuales de la URL
    const urlParams = new URLSearchParams(window.location.search);
    const cuatrimestre = urlParams.get('cuatrimestre') || '1';
    const materiaId = urlParams.get('materia');
    
    if (!materiaId) {
        console.error('No se puede actualizar: falta ID de materia');
        // Fallback: recargar página
        window.location.reload();
        return;
    }
    
    console.log('Actualizando vista para materia:', materiaId, 'cuatrimestre:', cuatrimestre);
    
    // Hacer petición AJAX para obtener la vista actualizada
    fetch(`vista_cuatrimestral_con_bloqueos.php?materia=${materiaId}&cuatrimestre=${cuatrimestre}&ajax=1`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(html => {
        // Buscar la tabla de calificaciones y actualizarla
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Buscar la tabla de calificaciones en el HTML recibido
        const nuevaTabla = doc.querySelector('.table-calificaciones, .table-responsive');
        const tablaActual = document.querySelector('.table-calificaciones, .table-responsive');
        
        if (nuevaTabla && tablaActual) {
            // Reemplazar solo el contenido de la tabla
            tablaActual.innerHTML = nuevaTabla.innerHTML;
            console.log('Tabla actualizada correctamente');
        } else {
            console.log('No se encontró tabla, recargando página completa');
            window.location.reload();
        }
    })
    .catch(error => {
        console.error('Error al actualizar vista:', error);
        // Fallback: recargar página
        window.location.reload();
    });
}

// FUNCIÓN AUXILIAR: Mostrar notificación sutil de actualización
function mostrarNotificacionActualizacion() {
    const notificacion = document.createElement('div');
    notificacion.className = 'alert alert-success alert-dismissible fade show position-fixed';
    notificacion.style.cssText = `
        top: 70px; 
        right: 20px; 
        z-index: 9999; 
        max-width: 300px; 
        opacity: 0.9;
        font-size: 0.85rem;
        padding: 0.5rem 1rem;
    `;
    notificacion.innerHTML = `
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        <i class="bi bi-check-circle me-2"></i>Columnas actualizadas
    `;
    
    document.body.appendChild(notificacion);
    
    // Auto-remover después de 2 segundos
    setTimeout(() => {
        if (notificacion.parentNode) {
            notificacion.remove();
        }
    }, 2000);
}

// NUEVA FUNCIÓN: Abrir modal para calificar contenido
function abrirModalCalificar(contenidoId) {
    const modal = new bootstrap.Modal(document.getElementById('modalCalificarContenido'));
    
    // Limpiar contenido anterior
    document.getElementById('contenidoCalificarModal').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="mt-2">Cargando contenido para calificar...</p>
        </div>
    `;
    
    modal.show();
    
    // Cargar contenido de calificación via AJAX
    fetch(`ajax_cargar_calificacion.php?contenido=${contenidoId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('contenidoCalificarModal').innerHTML = html;
            document.getElementById('btnGuardarCalificaciones').style.display = 'inline-block';
            
            // Inicializar eventos del formulario de calificación
            inicializarFormularioCalificacion();
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoCalificarModal').innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    Error al cargar el contenido para calificar.
                </div>
            `;
        });
}

// NUEVA FUNCIÓN: Inicializar eventos del formulario de calificación
function inicializarFormularioCalificacion() {
    // Eventos para guardar calificaciones
    const btnGuardar = document.getElementById('btnGuardarCalificaciones');
    if (btnGuardar) {
        // Remover listeners anteriores
        btnGuardar.replaceWith(btnGuardar.cloneNode(true));
        document.getElementById('btnGuardarCalificaciones').addEventListener('click', function() {
            guardarCalificacionesModal();
        });
    }
}

// NUEVA FUNCIÓN: Guardar calificaciones desde el modal
// FUNCIÓN CORREGIDA: Guardar calificaciones desde el modal
function guardarCalificacionesModal() {
    const form = document.getElementById('formCalificarModal');
    if (!form) {
        mostrarAlerta('danger', 'Formulario no encontrado');
        return;
    }
    
    const formData = new FormData(form);
    
    const btnGuardar = document.getElementById('btnGuardarCalificaciones');
    const textoOriginal = btnGuardar.innerHTML;
    btnGuardar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';
    btnGuardar.disabled = true;
    
    fetch('ajax_guardar_calificaciones.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            mostrarAlerta('success', `Calificaciones guardadas: ${data.estudiantes_actualizados} estudiantes actualizados`);
            
            // Cerrar modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalCalificarContenido'));
            modal.hide();
            
            // SIMPLE: Solo recargar página para asegurar que se vean las calificaciones
            setTimeout(() => {
                window.location.reload();
            }, 1000);
            
        } else {
            mostrarAlerta('danger', 'Error al guardar: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarAlerta('danger', 'Error de conexión al guardar calificaciones');
    })
    .finally(() => {
        btnGuardar.innerHTML = textoOriginal;
        btnGuardar.disabled = false;
    });
}
// NUEVA FUNCIÓN: Mostrar alertas temporales
function mostrarAlerta(tipo, mensaje) {
    const alertaId = 'alerta-' + Date.now();
    const alerta = document.createElement('div');
    alerta.id = alertaId;
    alerta.className = `alert alert-${tipo} alert-dismissible fade show position-fixed`;
    alerta.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    alerta.innerHTML = `
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <i class="bi bi-${tipo === 'success' ? 'check-circle' : tipo === 'danger' ? 'exclamation-triangle' : 'info-circle'}"></i>
        ${mensaje}
    `;
    
    document.body.appendChild(alerta);
    
    // Auto-remover después de 5 segundos
    setTimeout(() => {
        const elemento = document.getElementById(alertaId);
        if (elemento) {
            elemento.remove();
        }
    }, 5000);
}

// NUEVA FUNCIÓN: Botón para crear contenido desde la vista de calificaciones
function abrirModalCrearContenido() {
    // Limpiar formulario
    const form = document.getElementById('formCrearContenidoCalif');
    if (form) {
        form.reset();
    }
    
    // Establecer materia actual
    const materiaInput = document.getElementById('contenido_materia_id');
    if (materiaInput && materiaSeleccionada) {
        materiaInput.value = materiaSeleccionada;
    }
    
    // Establecer fecha actual
    const fechaInput = document.getElementById('contenido_fecha');
    if (fechaInput) {
        fechaInput.value = '<?= date('Y-m-d') ?>';
        
        // Detectar período para la fecha actual
        actualizarPeriodoDetectado('<?= date('Y-m-d') ?>');
    }
    
    // Mostrar modal
    const modal = new bootstrap.Modal(document.getElementById('modalCrearContenidoCalif'));
    modal.show();
}

// NUEVA FUNCIÓN: Actualizar tabla de contenidos dinámicamente
function actualizarTablaContenidos() {
    // Esta función se puede llamar después de crear/editar contenidos
    // Para recargar solo la sección de contenidos sin recargar toda la página
    const currentUrl = window.location.href;
    if (currentUrl.includes('cuatrimestre=1') || currentUrl.includes('cuatrimestre=2')) {
        setTimeout(() => {
            window.location.reload();
        }, 1000);
    }
}

// Inicializar eventos al cargar el DOM
document.addEventListener('DOMContentLoaded', function() {
    // Evento para detectar período al cambiar fecha
    const fechaInput = document.getElementById('contenido_fecha');
    if (fechaInput) {
        fechaInput.addEventListener('change', function() {
            actualizarPeriodoDetectado(this.value);
        });
    }
    
    // Mostrar información sobre equipo docente en modales
    if (esEquipoDocente) {
        console.log('Funcionando en modo equipo docente colaborativo');
    }
    
    // Atajos de teclado
    document.addEventListener('keydown', function(e) {
        // Ctrl + N para nuevo contenido (solo si hay materia seleccionada)
        if (e.ctrlKey && e.key === 'n' && materiaSeleccionada) {
            e.preventDefault();
            abrirModalCrearContenido();
        }
        
        // Escape para cerrar modales
        if (e.key === 'Escape') {
            const modalesAbiertos = document.querySelectorAll('.modal.show');
            modalesAbiertos.forEach(modal => {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            });
        }
    });
});

// Mejorar la experiencia visual del botón flotante
document.addEventListener('DOMContentLoaded', function() {
    const botonFlotante = document.querySelector('.position-fixed .btn-primary');
    if (botonFlotante) {
        // Efecto de pulso sutil
        botonFlotante.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
            this.style.transition = 'all 0.2s ease';
        });
        
        botonFlotante.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    }
});

// Variables globales para el sistema de subgrupos en modales
window.subgrupoActualModal = 'todos';

// Función principal para filtrar por subgrupo en modal
window.filtrarPorSubgrupoModal = function() {
    const select = document.getElementById('filtro-subgrupo-modal');
    if (!select) {
        console.error('No se encontró el select filtro-subgrupo-modal');
        return;
    }
    
    const subgrupoSeleccionado = select.value;
    window.subgrupoActualModal = subgrupoSeleccionado;
    
    console.log('Filtrando por subgrupo en modal:', subgrupoSeleccionado);
    
    // Obtener todas las filas de estudiantes en el modal
    const filasEstudiantes = document.querySelectorAll('.fila-estudiante-modal');
    let estudiantesMostrados = 0;
    let regularesMostrados = 0;
    let recursandoMostrados = 0;
    let calificadosMostrados = 0;
    
    filasEstudiantes.forEach((fila, index) => {
        const subgrupoEstudiante = fila.getAttribute('data-subgrupo') || '';
        const tipoEstudiante = fila.getAttribute('data-tipo') || '';
        
        // Verificar si tiene calificación
        const inputCalificacion = fila.querySelector('.calificacion-input');
        const tieneCalificacion = inputCalificacion && inputCalificacion.value !== '';
        
        let mostrarFila = false;
        
        switch (subgrupoSeleccionado) {
            case 'todos':
                mostrarFila = true;
                break;
            case 'sin-subgrupo':
                mostrarFila = !subgrupoEstudiante || subgrupoEstudiante === '';
                break;
            default:
                mostrarFila = subgrupoEstudiante === subgrupoSeleccionado;
                break;
        }
        
        if (mostrarFila) {
            fila.style.display = '';
            fila.classList.remove('fila-oculta-modal');
            estudiantesMostrados++;
            
            // Actualizar numeración
            const numeroFila = fila.querySelector('.numero-fila');
            if (numeroFila) {
                numeroFila.textContent = estudiantesMostrados;
            }
            
            // Contar por tipo
            switch (tipoEstudiante) {
                case 'recursando':
                    recursandoMostrados++;
                    break;
                default:
                    regularesMostrados++;
                    break;
            }
            
            if (tieneCalificacion) {
                calificadosMostrados++;
            }
            
        } else {
            fila.style.display = 'none';
            fila.classList.add('fila-oculta-modal');
        }
    });
    
    // Actualizar información
    if (typeof window.actualizarInfoFiltroModal === 'function') {
        window.actualizarInfoFiltroModal(subgrupoSeleccionado, estudiantesMostrados, regularesMostrados, recursandoMostrados, calificadosMostrados);
    }
    
    // Actualizar botones de acceso rápido
    if (typeof window.actualizarBotonesAccesoRapidoModal === 'function') {
        window.actualizarBotonesAccesoRapidoModal(subgrupoSeleccionado);
    }
    
    // Mostrar/ocultar columna de subgrupo según sea necesario
    if (typeof window.alternarColumnaSubgrupoModal === 'function') {
        window.alternarColumnaSubgrupoModal(subgrupoSeleccionado);
    }
    
    // Mostrar información de filtrado activo
    if (typeof window.mostrarInfoFiltradoActivo === 'function') {
        window.mostrarInfoFiltradoActivo(subgrupoSeleccionado);
    }
};

// Función para seleccionar subgrupo desde botones
window.seleccionarSubgrupoModal = function(subgrupo) {
    const select = document.getElementById('filtro-subgrupo-modal');
    if (select) {
        select.value = subgrupo;
        window.filtrarPorSubgrupoModal();
    } else {
        console.warn('Select de filtro no encontrado, guardando valor para cuando se cargue');
        window.subgrupoActualModal = subgrupo;
    }
};

// Función para actualizar información del filtro
window.actualizarInfoFiltroModal = function(subgrupo, total, regulares, recursando, calificados) {
    const elementos = {
        total: document.getElementById('total-mostrados-modal'),
        regulares: document.getElementById('regulares-mostrados-modal'),
        recursando: document.getElementById('recursando-mostrados-modal'),
        calificados: document.getElementById('calificados-mostrados-modal')
    };
    
    if (elementos.total) elementos.total.textContent = total;
    if (elementos.regulares) elementos.regulares.textContent = regulares;
    if (elementos.recursando) elementos.recursando.textContent = recursando;
    if (elementos.calificados) elementos.calificados.textContent = calificados;
    
    // Cambiar color del info según el subgrupo
    const infoContainer = document.getElementById('info-filtro-modal');
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
};

// Función para actualizar botones de acceso rápido
window.actualizarBotonesAccesoRapidoModal = function(subgrupoSeleccionado) {
    const botones = document.querySelectorAll('[onclick*="seleccionarSubgrupoModal"]');
    
    botones.forEach(boton => {
        const onclick = boton.getAttribute('onclick');
        const valorBoton = onclick.match(/seleccionarSubgrupoModal\('([^']+)'\)/)?.[1];
        
        // Remover clases de estado anterior
        boton.classList.remove('btn-primary', 'btn-success', 'btn-warning', 'btn-secondary');
        boton.classList.remove('btn-outline-primary', 'btn-outline-success', 'btn-outline-warning', 'btn-outline-secondary');
        
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
};

// Función para mostrar/ocultar columna de subgrupo
window.alternarColumnaSubgrupoModal = function(subgrupoSeleccionado) {
    const columnaHeader = document.getElementById('columna-subgrupo-modal');
    const celdasSubgrupo = document.querySelectorAll('.celda-subgrupo');
    
    // Si mostramos un subgrupo específico, podemos ocultar la columna
    const ocultarColumna = subgrupoSeleccionado !== 'todos' && subgrupoSeleccionado !== 'sin-subgrupo';
    
    if (columnaHeader) {
        columnaHeader.style.display = ocultarColumna ? 'none' : '';
    }
    
    celdasSubgrupo.forEach(celda => {
        celda.style.display = ocultarColumna ? 'none' : '';
    });
};

// Función para mostrar información de filtrado activo
window.mostrarInfoFiltradoActivo = function(subgrupo) {
    const infoFiltrado = document.getElementById('info-filtrado-activo');
    const textoFiltro = document.getElementById('texto-filtro-activo');
    
    if (subgrupo === 'todos') {
        if (infoFiltrado) infoFiltrado.style.display = 'none';
    } else {
        if (infoFiltrado) infoFiltrado.style.display = 'block';
        if (textoFiltro) {
            let texto = '';
            switch (subgrupo) {
                case 'sin-subgrupo':
                    texto = 'Estudiantes sin subgrupo asignado';
                    break;
                default:
                    texto = `Subgrupo ${subgrupo}`;
                    break;
            }
            textoFiltro.textContent = texto;
        }
    }
};

// Función para mostrar estadísticas de subgrupos en modal
window.mostrarEstadisticasSubgruposModal = function() {
    const modalStats = document.getElementById('modalEstadisticasSubgruposModal');
    if (modalStats) {
        const modal = new bootstrap.Modal(modalStats);
        modal.show();
    } else {
        console.warn('Modal de estadísticas no encontrado');
    }
};

// Función para seleccionar subgrupo desde modal de estadísticas y cerrar
window.seleccionarSubgrupoModalYCerrar = function(subgrupo) {
    // Cerrar modal de estadísticas
    const modalStats = document.getElementById('modalEstadisticasSubgruposModal');
    if (modalStats) {
        const modal = bootstrap.Modal.getInstance(modalStats);
        if (modal) {
            modal.hide();
        }
    }
    
    // Seleccionar subgrupo
    setTimeout(() => {
        window.seleccionarSubgrupoModal(subgrupo);
    }, 300);
};

// Función para buscar estudiante en modal
window.buscarEstudianteEnModal = function() {
    const termino = prompt('Buscar estudiante (nombre o apellido):');
    if (!termino) return;
    
    const filasVisibles = document.querySelectorAll('.fila-estudiante-modal:not(.fila-oculta-modal)');
    let encontrado = false;
    
    filasVisibles.forEach(fila => {
        const textoFila = fila.textContent.toLowerCase();
        if (textoFila.includes(termino.toLowerCase())) {
            fila.scrollIntoView({ behavior: 'smooth', block: 'center' });
            fila.style.backgroundColor = '#fff3cd';
            
            setTimeout(() => {
                fila.style.backgroundColor = '';
            }, 3000);
            
            encontrado = true;
        }
    });
    
    if (!encontrado) {
        alert(`No se encontró "${termino}" en los estudiantes mostrados.`);
    }
};

// Función para limpiar formulario del modal
window.limpiarFormularioModal = function() {
    if (confirm('¿Está seguro de limpiar todas las calificaciones?')) {
        const form = document.getElementById('formCalificarModal');
        if (form) {
            form.reset();
            mostrarAlerta('info', 'Formulario limpiado');
        }
    }
};

console.log('Funciones globales de subgrupos cargadas correctamente');

// ========== FUNCIONES CENTRALIZADAS PARA ACCIONES MASIVAS CON FILTROS ==========
// AGREGAR AL FINAL del JavaScript en calificaciones.php

// Variables globales
window.materiaCursoId = <?= $materiaSeleccionada ?? 'null' ?>;
window.subgrupoActualModal = 'todos';

// Función genérica CORREGIDA para ejecutar acciones masivas respetando filtros
function ejecutarAccionMasivaGlobal(accion, contenidoId, tituloContenido, accionTexto, tipoAlerta) {
    if (!contenidoId) {
        mostrarAlerta('warning', 'ID de contenido no especificado');
        return;
    }
    
    // Detectar si hay filtro activo y obtener estudiantes filtrados
    const filtroActual = window.subgrupoActualModal || 'todos';
    let estudiantesFiltrados = [];
    let aplicarFiltro = false;
    let mensajeConfirmacion = '';
    
    // Verificar si estamos en un modal con filtros
    const modalActivo = document.querySelector('.modal.show');
    const hayFiltroEnModal = modalActivo && document.getElementById('filtro-subgrupo-modal');
    
    if (hayFiltroEnModal && filtroActual !== 'todos') {
        // Obtener IDs de estudiantes visibles (no ocultos) en el modal
        const filasVisibles = modalActivo.querySelectorAll('.fila-estudiante-modal:not(.fila-oculta-modal)');
        
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
            
            mensajeConfirmacion = `¿Está seguro de ${accionTexto} los ${estudiantesFiltrados.length} ${textoFiltro} en "${tituloContenido}"?\n\n`;
        }
    }
    
    if (!aplicarFiltro) {
        mensajeConfirmacion = `¿Está seguro de ${accionTexto} todos los estudiantes en "${tituloContenido}"?\n\n`;
    }
    
    // Personalizar mensaje según la acción
    if (accion === 'sin_calificar_todos_contenido') {
        mensajeConfirmacion += `Esta acción:\n• ${aplicarFiltro ? 'Eliminará las calificaciones solo de los estudiantes filtrados' : 'Eliminará TODAS las calificaciones de este contenido'}\n• Las calificaciones se perderán permanentemente\n• Esta acción NO SE PUEDE DESHACER\n\n¿Continuar?`;
    } else {
        mensajeConfirmacion += `Esta acción:\n• ${aplicarFiltro ? 'Aplicará la calificación solo a los estudiantes filtrados' : 'Aplicará la calificación a TODOS los estudiantes'}\n• Sobrescribirá calificaciones existentes\n• No se puede deshacer fácilmente\n\n¿Continuar?`;
    }
    
    if (!confirm(mensajeConfirmacion)) {
        return;
    }
    
    // Mostrar indicador de carga global
    mostrarIndicadorCarga();
    
    // Crear formulario para envío
    const formData = new FormData();
    formData.append(accion, '1');
    formData.append('contenido_id', contenidoId);
    
    // Enviar información del filtro si está activo
    if (aplicarFiltro && estudiantesFiltrados.length > 0) {
        formData.append('aplicar_filtro', '1');
        formData.append('estudiantes_filtrados', JSON.stringify(estudiantesFiltrados));
        
        console.log('ENVIANDO FILTRO:', {
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
    
    // Enviar solicitud
    fetch('ajax_acreditar_todos.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Respuesta recibida:', response);
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('La respuesta no es JSON válido. Posible error de PHP.');
        }
        
        return response.json();
    })
    .then(data => {
        console.log('Datos procesados:', data);
        ocultarIndicadorCarga();
        
        if (data.success) {
            let mensaje = data.message;
            
            // Personalizar mensaje para la acción "sin calificar"
            if (accion === 'sin_calificar_todos_contenido') {
                if (aplicarFiltro) {
                    mensaje = `Se eliminaron las calificaciones de ${data.estudiantes_procesados} estudiantes filtrados`;
                } else {
                    mensaje = `Se eliminaron todas las calificaciones del contenido (${data.estudiantes_procesados} estudiantes)`;
                }
            }
            
            mostrarAlerta(tipoAlerta, mensaje);
            
            // Actualizar el formulario del modal
            if (typeof actualizarFormularioModal === 'function') {
                setTimeout(() => {
                    actualizarFormularioModal(contenidoId);
                }, 500);
            }
            
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

console.log('Función "Sin Calificar Todos" cargada correctamente');

// REEMPLAZAR la función actualizarFormularioModal existente
function actualizarFormularioModal(contenidoId) {
    console.log('Actualizando formulario del modal para contenido:', contenidoId);
    
    // Hacer petición AJAX para obtener los datos actualizados
    fetch(`ajax_cargar_calificacion.php?contenido=${contenidoId}&actualizar=1`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(html => {
        const modal = document.getElementById('modalCalificarContenido');
        if (modal && modal.classList.contains('show')) {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                // Guardar el filtro actual antes de actualizar
                const filtroActual = document.getElementById('filtro-subgrupo-modal')?.value || 'todos';
                
                // Actualizar contenido
                modalBody.innerHTML = html;
                
                // Restaurar filtro después de actualizar
                setTimeout(() => {
                    const nuevoFiltro = document.getElementById('filtro-subgrupo-modal');
                    if (nuevoFiltro && filtroActual !== 'todos') {
                        nuevoFiltro.value = filtroActual;
                        // Actualizar variable global
                        window.subgrupoActualModal = filtroActual;
                        if (typeof window.filtrarPorSubgrupoModal === 'function') {
                            window.filtrarPorSubgrupoModal();
                        }
                    }
                    
                    // Reinicializar eventos del formulario
                    if (typeof inicializarFormularioCalificacion === 'function') {
                        inicializarFormularioCalificacion();
                    }
                    
                    // Mostrar mensaje de actualización
                    mostrarAlerta('info', 'Calificaciones actualizadas en el formulario');
                }, 200);
            }
        }
    })
    .catch(error => {
        console.error('Error al actualizar formulario:', error);
        mostrarAlerta('warning', 'No se pudo actualizar el formulario automáticamente');
    });
}

// FUNCIONES GLOBALES ACTUALIZADAS (reemplazar las existentes)
window.acreditarTodosContenido = function(contenidoId, tituloContenido) {
    const tituloLimpio = (tituloContenido || 'contenido seleccionado').replace(/['"\\]/g, '');
    ejecutarAccionMasivaGlobal('acreditar_todos_contenido', contenidoId, tituloLimpio, 'ACREDITAR', 'success');
};

window.noAcreditarTodosContenido = function(contenidoId, tituloContenido) {
    const tituloLimpio = (tituloContenido || 'contenido seleccionado').replace(/['"\\]/g, '');
    ejecutarAccionMasivaGlobal('no_acreditar_todos_contenido', contenidoId, tituloLimpio, 'NO ACREDITAR', 'warning');
};

window.noCorrespondeTodosContenido = function(contenidoId, tituloContenido) {
    const tituloLimpio = (tituloContenido || 'contenido seleccionado').replace(/['"\\]/g, '');
    ejecutarAccionMasivaGlobal('no_corresponde_todos_contenido', contenidoId, tituloLimpio, 'marcar como NO CORRESPONDE', 'info');
};

// NUEVA FUNCIÓN GLOBAL: Sin calificar todos
window.sinCalificarTodosContenido = function(contenidoId, tituloContenido) {
    const tituloLimpio = (tituloContenido || 'contenido seleccionado').replace(/['"\\]/g, '');
    ejecutarAccionMasivaGlobal('sin_calificar_todos_contenido', contenidoId, tituloLimpio, 'ELIMINAR todas las calificaciones de', 'danger');
};

// FUNCIÓN DE DEBUG (temporal para verificar filtros)
window.debugFiltros = function() {
    console.log('=== DEBUG FILTROS ===');
    console.log('Filtro actual modal:', window.subgrupoActualModal);
    
    const modalActivo = document.querySelector('.modal.show');
    if (!modalActivo) {
        console.log('No hay modal activo');
        return;
    }
    
    const filasVisibles = modalActivo.querySelectorAll('.fila-estudiante-modal:not(.fila-oculta-modal)');
    const estudiantesFiltrados = [];
    
    console.log('Total filas en modal:', modalActivo.querySelectorAll('.fila-estudiante-modal').length);
    console.log('Filas visibles:', filasVisibles.length);
    
    filasVisibles.forEach((fila, index) => {
        const estudianteId = fila.getAttribute('data-estudiante-id');
        const subgrupo = fila.getAttribute('data-subgrupo');
        estudiantesFiltrados.push({
            index: index + 1,
            id: parseInt(estudianteId),
            subgrupo: subgrupo
        });
    });
    
    console.log('Estudiantes filtrados:', estudiantesFiltrados);
    console.log('IDs para enviar:', estudiantesFiltrados.map(e => e.id));
    
    return {
        filtroActual: window.subgrupoActualModal,
        estudiantesVisibles: estudiantesFiltrados,
        totalVisibles: filasVisibles.length,
        idsParaEnviar: estudiantesFiltrados.map(e => e.id)
    };
};

// ========== SISTEMA DE FILTRADO PARA VISTA PRINCIPAL ==========

// Función para filtrar por subgrupo en la vista principal (NO en modal)
function filtrarPorSubgrupo() {
    const select = document.getElementById('filtro-subgrupo');
    if (!select) {
        console.error('No se encontró el select filtro-subgrupo de la vista principal');
        return;
    }
    
    const subgrupoSeleccionado = select.value;
    console.log('Filtrando vista principal por subgrupo:', subgrupoSeleccionado);
    
    // CRÍTICO: SIEMPRE mantener visibles las filas de contenidos
    const filasContenidos = document.querySelectorAll('tr');
    
    filasContenidos.forEach(fila => {
        const celdas = fila.querySelectorAll('td, th');
        let esContenido = false;
        
        // Verificar si es una fila de contenido
        celdas.forEach(celda => {
            const texto = celda.textContent.toLowerCase();
            if (texto.includes('tp ') || 
                texto.includes('trabajo') || 
                texto.includes('interior de una computadora') ||
                texto.includes('anagrama') ||
                texto.includes('muro colaborativo') ||
                texto.includes('infografía') ||
                texto.includes('título') || 
                texto.includes('fecha') || 
                texto.includes('bimestre') ||
                celda.querySelector('.btn[title*="Calificar"]') ||
                celda.querySelector('.btn[title*="Editar"]')) {
                esContenido = true;
            }
        });
        
        if (esContenido) {
            // FORZAR que las filas de contenido siempre sean visibles
            fila.style.display = '';
            fila.style.visibility = 'visible';
            fila.classList.remove('fila-oculta');
            return; // No aplicar filtro a contenidos
        }
    });
    
    // Filtrar SOLO filas de estudiantes
    const filasEstudiantes = document.querySelectorAll('tbody tr');
    let estudiantesMostrados = 0;
    let regularesMostrados = 0;
    let recursandoMostrados = 0;
    
    filasEstudiantes.forEach(fila => {
        // Verificar si es fila de estudiante (tiene nombre y matrícula)
        const celdaNombre = fila.querySelector('td:nth-child(2)');
        if (!celdaNombre) {
            fila.style.display = ''; // Mantener visible
            return;
        }
        
        const textoNombre = celdaNombre.textContent.trim();
        const esFilaEstudiante = textoNombre.includes(',') && 
                                (textoNombre.includes('Matr.:') || celdaNombre.querySelector('strong'));
        
        if (!esFilaEstudiante) {
            fila.style.display = ''; // Mantener visible si no es estudiante
            return;
        }
        
        // ES fila de estudiante, aplicar filtro
        const esRecursando = fila.classList.contains('table-warning');
        
        // Buscar subgrupo del estudiante
        let subgrupoEstudiante = '';
        const badges = fila.querySelectorAll('.badge');
        badges.forEach(badge => {
            const texto = badge.textContent.trim();
            if (texto.includes('Subgrupo') || /^Subgrupo\s+\d+$/.test(texto)) {
                subgrupoEstudiante = texto;
            }
        });
        
        // Aplicar filtro
        let mostrarFila = false;
        
        switch (subgrupoSeleccionado) {
            case 'todos':
                mostrarFila = true;
                break;
            case 'sin-subgrupo':
                mostrarFila = !subgrupoEstudiante;
                break;
            default:
                mostrarFila = subgrupoEstudiante === subgrupoSeleccionado;
                break;
        }
        
        if (mostrarFila) {
            fila.style.display = '';
            fila.classList.remove('fila-oculta');
            estudiantesMostrados++;
            
            if (esRecursando) {
                recursandoMostrados++;
            } else {
                regularesMostrados++;
            }
        } else {
            fila.style.display = 'none';
            fila.classList.add('fila-oculta');
        }
    });
    
    console.log('Estudiantes mostrados:', estudiantesMostrados);
    
    // Actualizar información si las funciones existen
    if (typeof actualizarInfoSubgrupo === 'function') {
        actualizarInfoSubgrupo(subgrupoSeleccionado, estudiantesMostrados, regularesMostrados, recursandoMostrados);
    }
    
    if (typeof actualizarBotonesAccesoRapido === 'function') {
        actualizarBotonesAccesoRapido(subgrupoSeleccionado);
    }
}

// Función para seleccionar subgrupo desde botones
function seleccionarSubgrupo(subgrupo) {
    const select = document.getElementById('filtro-subgrupo');
    if (select) {
        select.value = subgrupo;
        filtrarPorSubgrupo();
    }
}

// Interceptar cambios en el filtro
document.addEventListener('DOMContentLoaded', function() {
    const selectFiltro = document.getElementById('filtro-subgrupo');
    if (selectFiltro) {
        selectFiltro.addEventListener('change', filtrarPorSubgrupo);
        console.log('Listener de filtro de subgrupo principal agregado');
    }
    
    // Interceptar clics en botones de subgrupo
    document.addEventListener('click', function(e) {
        if (e.target.onclick && e.target.onclick.toString().includes('seleccionarSubgrupo')) {
            setTimeout(() => {
                // Forzar visibilidad de contenidos después de clic en botón
                const filasContenidos = document.querySelectorAll('tr');
                filasContenidos.forEach(fila => {
                    const celdas = fila.querySelectorAll('td, th');
                    let esContenido = false;
                    
                    celdas.forEach(celda => {
                        const texto = celda.textContent.toLowerCase();
                        if (texto.includes('tp ') || texto.includes('interior de una computadora') ||
                            texto.includes('título') || texto.includes('fecha')) {
                            esContenido = true;
                        }
                    });
                    
                    if (esContenido) {
                        fila.style.display = '';
                    }
                });
            }, 100);
        }
    });
});

console.log('Sistema de filtrado de vista principal inicializado');

// AGREGAR después del código anterior en calificaciones.php

// ========== FUNCIONES PARA BOTONES SELECCIONADOS ==========

// Función para actualizar información del subgrupo seleccionado
function actualizarInfoSubgrupo(subgrupo, total, regulares, recursando) {
    const infoContainer = document.getElementById('info-subgrupo-seleccionado');
    const totalSpan = document.getElementById('total-estudiantes-mostrados');
    
    if (totalSpan) {
        totalSpan.textContent = total;
    }
    
    // Actualizar badges si existen
    if (infoContainer) {
        const badgeRegulares = infoContainer.querySelector('.badge.bg-success');
        const badgeRecursando = infoContainer.querySelector('.badge.bg-warning');
        
        if (badgeRegulares) badgeRegulares.textContent = regulares;
        if (badgeRecursando) badgeRecursando.textContent = recursando;
        
        // Cambiar color del contenedor según el subgrupo
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

// Función para actualizar botones de acceso rápido (pintarlos cuando están activos)
function actualizarBotonesAccesoRapido(subgrupoSeleccionado) {
    // Buscar todos los botones de subgrupo
    const botones = document.querySelectorAll('[onclick*="seleccionarSubgrupo"]');
    
    botones.forEach(boton => {
        const onclick = boton.getAttribute('onclick');
        const valorBoton = onclick.match(/seleccionarSubgrupo\('([^']+)'\)/)?.[1];
        
        // Remover todas las clases de estado anterior
        boton.classList.remove('btn-primary', 'btn-success', 'btn-warning', 'btn-secondary');
        boton.classList.remove('btn-outline-primary', 'btn-outline-success', 'btn-outline-warning', 'btn-outline-secondary');
        
        if (valorBoton === subgrupoSeleccionado) {
            // Botón ACTIVO/SELECCIONADO - usar colores sólidos
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
            
            // Agregar efecto visual adicional para el botón activo
            boton.style.transform = 'scale(1.05)';
            boton.style.boxShadow = '0 4px 8px rgba(0,0,0,0.2)';
            boton.style.fontWeight = 'bold';
            
        } else {
            // Botón INACTIVO - usar colores outline
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
            
            // Remover efectos visuales del botón inactivo
            boton.style.transform = '';
            boton.style.boxShadow = '';
            boton.style.fontWeight = '';
        }
    });
}

// Función mejorada para seleccionar subgrupo con efectos visuales
function seleccionarSubgrupo(subgrupo) {
    console.log('Seleccionando subgrupo:', subgrupo);
    
    const select = document.getElementById('filtro-subgrupo');
    if (select) {
        select.value = subgrupo;
        
        // Aplicar filtro
        filtrarPorSubgrupo();
        
        // Actualizar botones inmediatamente
        actualizarBotonesAccesoRapido(subgrupo);
        
        // Efecto visual en el select también
        select.style.borderColor = '#007bff';
        select.style.boxShadow = '0 0 0 0.2rem rgba(0, 123, 255, 0.25)';
        
        setTimeout(() => {
            select.style.borderColor = '';
            select.style.boxShadow = '';
        }, 1000);
    }
}

// Función para mostrar estadísticas de subgrupos (si tienes el modal)
function mostrarEstadisticasSubgrupos() {
    const modal = document.getElementById('modalEstadisticasSubgrupos');
    if (modal) {
        const modalInstance = new bootstrap.Modal(modal);
        modalInstance.show();
    } else {
        console.warn('Modal de estadísticas no encontrado');
    }
}

// Función para aplicar animación al filtrado
function aplicarAnimacionFiltrado() {
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

// Mejorar el event listener del select para que también actualice botones
document.addEventListener('DOMContentLoaded', function() {
    const selectFiltro = document.getElementById('filtro-subgrupo');
    if (selectFiltro) {
        // Remover listener anterior si existe
        selectFiltro.removeEventListener('change', filtrarPorSubgrupo);
        
        // Agregar listener mejorado
        selectFiltro.addEventListener('change', function() {
            const subgrupoSeleccionado = this.value;
            console.log('Select cambiado a:', subgrupoSeleccionado);
            
            // Aplicar filtro
            filtrarPorSubgrupo();
            
            // Actualizar botones
            actualizarBotonesAccesoRapido(subgrupoSeleccionado);
        });
        
        // Establecer estado inicial de los botones
        const valorInicial = selectFiltro.value || 'todos';
        actualizarBotonesAccesoRapido(valorInicial);
        
        console.log('Listener mejorado de filtro agregado, valor inicial:', valorInicial);
    }
});

// CSS adicional para mejorar los efectos visuales de los botones
const estilosAdicionales = document.createElement('style');
estilosAdicionales.textContent = `
    .btn-group .btn {
        transition: all 0.2s ease;
        position: relative;
    }
    
    .btn-group .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .btn-group .btn.btn-primary,
    .btn-group .btn.btn-secondary,
    .btn-group .btn.btn-warning {
        position: relative;
    }
    
    .btn-group .btn.btn-primary::after,
    .btn-group .btn.btn-secondary::after,
    .btn-group .btn.btn-warning::after {
        content: "✓";
        position: absolute;
        top: -2px;
        right: -2px;
        background: #28a745;
        color: white;
        border-radius: 50%;
        width: 16px;
        height: 16px;
        font-size: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }
    
    @media (max-width: 768px) {
        .btn-group .btn {
            margin-bottom: 2px;
        }
    }
`;
document.head.appendChild(estilosAdicionales);

console.log('Sistema de botones seleccionados inicializado');

</script>

<style>
/* Estilos para el botón "Sin Calificar Todos" */
#btnSinCalificarTodosModal {
    border: 2px solid #dc3545;
    transition: all 0.3s ease;
}

#btnSinCalificarTodosModal:hover {
    background-color: #dc3545;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
}

#btnSinCalificarTodosModal:active {
    transform: translateY(0);
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
}

/* Animación para indicar acción peligrosa */
#btnSinCalificarTodosModal::before {
    content: '⚠️ ';
    opacity: 0;
    transition: opacity 0.3s ease;
}

#btnSinCalificarTodosModal:hover::before {
    opacity: 1;
}
/* Estilos para los modales de contenidos */
.modal-xl .modal-body {
    padding: 1.5rem;
}

.modal-xl .table-responsive {
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.modal-xl .table-sm th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.75rem;
}

.modal-xl .table-sm td {
    vertical-align: middle;
    font-size: 0.85rem;
}

/* Estilo para el botón flotante */
.position-fixed .btn-primary {
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.position-fixed .btn-primary:hover {
    box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    transform: translateY(-2px);
}

/* Mejorar alertas */
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

/* Estilos para formularios en modales */
.modal .form-control:focus,
.modal .form-select:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.modal .btn-group-sm .btn {
    font-size: 0.775rem;
    padding: 0.25rem 0.5rem;
}

/* Responsive para modales */
@media (max-width: 1200px) {
    .modal-xl {
        max-width: 95%;
    }
}

@media (max-width: 768px) {
    .modal-xl .modal-body {
        padding: 1rem;
    }
    
    .position-fixed {
        bottom: 15px !important;
        right: 15px !important;
    }
    
    .position-fixed .btn-primary {
        width: 50px !important;
        height: 50px !important;
    }
}
</style>

<?php
/**
 * INSTRUCCIONES FINALES DE IMPLEMENTACIÓN:
 * 
 * 1. Agregar este código completo AL FINAL de calificaciones.php, 
 *    DESPUÉS de require_once 'footer.php' pero ANTES del último ?>
 * 
 * 2. Crear los archivos AJAX:
 *    - ajax_crear_contenido.php
 *    - ajax_cargar_calificacion.php
 *    - ajax_guardar_calificaciones.php
 * 
 * 3. Verificar que existe api_detectar_periodo.php
 * 
 * 4. Aplicar las modificaciones en vista_cuatrimestral_con_bloqueos.php
 *    según las instrucciones del archivo anterior
 * 
 * 5. Probar la funcionalidad:
 *    - El cuatrimestre activo debe estar seleccionado automáticamente
 *    - El botón flotante debe aparecer cuando hay materia seleccionada
 *    - Los modales deben funcionar correctamente
 *    - La creación y calificación debe ser fluida
 * 
 * CARACTERÍSTICAS PRINCIPALES:
 * - Detección automática del período activo
 * - Creación de contenidos sin salir de calificaciones
 * - Calificación inmediata después de crear contenido
 * - Botón flotante para acceso rápido
 * - Soporte completo para equipos docentes
 * - Interfaz responsive y moderna
 * - Alertas informativas mejoradas
 * - Atajos de teclado (Ctrl+N para nuevo contenido)
 */
?>
?>
