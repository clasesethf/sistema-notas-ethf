<?php
/**
 * gestionar_subgrupos.php - Gestión MEJORADA de subgrupos con rotación automática
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * CORRECCIÓN: Headers movidos antes de cualquier output
 */

// IMPORTANTE: Iniciar buffer de salida al principio
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos) ANTES de incluir header.php
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Variable para controlar mensajes
$mensajeResultado = '';
$tipoMensaje = '';

// Procesar acciones ANTES de incluir header.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'configurar_subgrupos':
            $resultado = configurarSubgrupos($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'asignar_estudiantes':
            $resultado = asignarEstudiantes($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'eliminar_asignacion':
            $resultado = eliminarAsignacionIndividual($db, $_POST);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'eliminar_subgrupo_completo':
            $resultado = eliminarSubgrupoCompleto($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'limpiar_todas_asignaciones':
            $resultado = limpiarTodasAsignaciones($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'rotacion_automatica':
            $resultado = ejecutarRotacionAutomatica($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'auto_dividir_ciudadania':
            $resultado = ejecutarDivisionCiudadania($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'auto_detectar_materias':
            $resultado = detectarYConfigurarMaterias($db, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'restaurar_subgrupos':
            $resultado = restaurarSubgrupos($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
            
        case 'corregir_asignaciones_curso':
            $resultado = corregirAsignacionesPorCurso($db, $_POST, $cicloLectivoId);
            $mensajeResultado = $resultado['message'];
            $tipoMensaje = $resultado['type'];
            break;
    }
    
    // Usar variables de sesión en lugar de redireccionar inmediatamente
    $_SESSION['temp_message'] = $mensajeResultado;
    $_SESSION['temp_message_type'] = $tipoMensaje;
    
    // Preparar URL para redirección después de mostrar contenido
    $queryParams = [];
    if (isset($_POST['materia_curso_id']) && $_POST['materia_curso_id']) {
        $queryParams['materia'] = $_POST['materia_curso_id'];
    }
    $redirectUrl = 'gestionar_subgrupos.php';
    if (!empty($queryParams)) {
        $redirectUrl .= '?' . http_build_query($queryParams);
    }
}

// Recuperar mensajes temporales
if (isset($_SESSION['temp_message'])) {
    $mensajeResultado = $_SESSION['temp_message'];
    $tipoMensaje = $_SESSION['temp_message_type'];
    unset($_SESSION['temp_message'], $_SESSION['temp_message_type']);
}

// AHORA incluir el encabezado después de procesar POST
require_once 'header.php';

/**
 * FUNCIONES PRINCIPALES (mantener todas las funciones originales)
 */

function configurarSubgrupos($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        $tipoDiv = $datos['tipo_division'];
        $cantGrupos = intval($datos['cantidad_grupos']);
        $rotacion = isset($datos['rotacion_automatica']) ? 1 : 0;
        $periodoRotacion = $datos['periodo_rotacion'];
        $descripcion = $datos['descripcion'] ?? '';
        
        // Verificar si ya existe configuración
        $configExiste = $db->fetchOne(
            "SELECT id FROM configuracion_subgrupos WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        );
        
        if ($configExiste) {
            // Actualizar
            $db->query(
                "UPDATE configuracion_subgrupos 
                 SET tipo_division = ?, cantidad_grupos = ?, rotacion_automatica = ?, 
                     periodo_rotacion = ?, descripcion = ?
                 WHERE id = ?",
                [$tipoDiv, $cantGrupos, $rotacion, $periodoRotacion, $descripcion, $configExiste['id']]
            );
        } else {
            // Crear
            $db->query(
                "INSERT INTO configuracion_subgrupos 
                 (materia_curso_id, ciclo_lectivo_id, tipo_division, cantidad_grupos, 
                  rotacion_automatica, periodo_rotacion, descripcion)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$materiaCursoId, $cicloLectivoId, $tipoDiv, $cantGrupos, $rotacion, $periodoRotacion, $descripcion]
            );
        }
        
        return ['type' => 'success', 'message' => 'Configuración de subgrupos guardada correctamente'];
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al guardar configuración: ' . $e->getMessage()];
    }
}

function asignarEstudiantes($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        $subgrupo = $datos['subgrupo'];
        $periodoInicio = $datos['periodo_inicio'];
        $periodoFin = $datos['periodo_fin'] ?? null;
        $estudiantesSeleccionados = $datos['estudiantes'] ?? [];
        
        // VALIDACIÓN: Verificar que todos los estudiantes pertenezcan al mismo curso que la materia
        $cursoMateria = $db->fetchOne(
            "SELECT mp.curso_id, c.nombre as curso_nombre 
             FROM materias_por_curso mp 
             JOIN cursos c ON mp.curso_id = c.id 
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        if (!$cursoMateria) {
            throw new Exception("No se encontró la materia especificada");
        }
        
        // Verificar que todos los estudiantes seleccionados pertenezcan al curso correcto
        if (!empty($estudiantesSeleccionados)) {
            $estudiantesInvalidos = $db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, c.nombre as curso_nombre
                 FROM usuarios u
                 LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
                 LEFT JOIN cursos c ON m.curso_id = c.id
                 WHERE u.id IN (" . implode(',', array_map('intval', $estudiantesSeleccionados)) . ")
                 AND (m.curso_id != ? OR m.curso_id IS NULL)",
                [$cursoMateria['curso_id']]
            );
            
            if (!empty($estudiantesInvalidos)) {
                $nombresInvalidos = array_map(function($est) {
                    return $est['apellido'] . ', ' . $est['nombre'] . ' (' . ($est['curso_nombre'] ?? 'Sin curso') . ')';
                }, $estudiantesInvalidos);
                
                throw new Exception("Los siguientes estudiantes no pertenecen al curso '{$cursoMateria['curso_nombre']}': " . implode('; ', $nombresInvalidos));
            }
        }
        
        // Limpiar asignaciones anteriores para este período y subgrupo específico
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? AND periodo_inicio = ? AND subgrupo = ?",
            [$materiaCursoId, $cicloLectivoId, $periodoInicio, $subgrupo]
        );
        
        // Insertar nuevas asignaciones
        foreach ($estudiantesSeleccionados as $estudianteId) {
            $db->query(
                "INSERT INTO estudiantes_por_materia 
                 (materia_curso_id, estudiante_id, ciclo_lectivo_id, subgrupo, periodo_inicio, periodo_fin, activo)
                 VALUES (?, ?, ?, ?, ?, ?, 1)",
                [$materiaCursoId, $estudianteId, $cicloLectivoId, $subgrupo, $periodoInicio, $periodoFin]
            );
        }
        
        return ['type' => 'success', 'message' => 'Estudiantes asignados correctamente al subgrupo'];
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al asignar estudiantes: ' . $e->getMessage()];
    }
}

function detectarYConfigurarMaterias($db, $cicloLectivoId) {
    try {
        $materiasConfiguradas = 0;
        $logDeteccion = [];
        
        // 1. DETECTAR CONSTRUCCIÓN DE LA CIUDADANÍA (2° y 3° año)
        $materiasCiudadania = $db->fetchAll(
            "SELECT mp.id, m.nombre, m.codigo, c.anio, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ? 
             AND (
                 LOWER(m.nombre) LIKE '%construccion%ciudadania%' 
                 OR LOWER(m.nombre) LIKE '%constr%ciud%'
                 OR LOWER(m.nombre) LIKE '%construcción%ciudadanía%'
                 OR m.codigo IN ('CCE', 'CCM', 'CCM1', 'CIUD')
             )
             AND c.anio IN (2, 3)
             ORDER BY c.anio, m.nombre",
            [$cicloLectivoId]
        );
        
        foreach ($materiasCiudadania as $materia) {
            // Marcar como requiere subgrupos
            $db->query("UPDATE materias_por_curso SET requiere_subgrupos = 1 WHERE id = ?", [$materia['id']]);
            
            // Crear configuración SIN rotación (grupos fijos)
            $db->query(
                "INSERT OR REPLACE INTO configuracion_subgrupos 
                 (materia_curso_id, ciclo_lectivo_id, tipo_division, cantidad_grupos, 
                  rotacion_automatica, periodo_rotacion, descripcion)
                 VALUES (?, ?, 'tercio', 3, 0, 'anual', ?)",
                [$materia['id'], $cicloLectivoId, 
                 "Construcción de la Ciudadanía - {$materia['curso_nombre']} - Cada tercio con un profesor durante todo el ciclo lectivo"]
            );
            
            $materiasConfiguradas++;
            $logDeteccion[] = "✓ Ciudadanía: {$materia['nombre']} ({$materia['curso_nombre']}) - GRUPOS FIJOS";
        }
        
        // 2. DETECTAR MATERIAS DE TALLER (códigos específicos mejorados)
        $materiasTaller = $db->fetchAll(
            "SELECT mp.id, m.nombre, m.codigo, c.anio, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ? 
             AND (
                 -- Códigos que empiezan con número seguido de letras (patrón original)
                 (m.codigo REGEXP '^[1-7][A-Z]{2,}')
                 OR 
                 -- Códigos específicos identificados
                 m.codigo IN (
                     'LDT1', 'LDT2', 'LDT3', '4DA', '4DT',
                     '2FE', '4E', '4EB', '4IED2', '6DEE', '6L', '7CE',
                     '4M', '4M1', '4M2', '5M3', '6C2', '6CF', '6CT',
                     '3F', '3F1', '3IED1', '4PA', '4S', '4T',
                     '5C', '5C1', '5CF1', '5CF2', '5ED', '5ME', '5MTT', '5R',
                     '6LME', '6M', '6M1', '6MCI1', '6P', '6SC',
                     '7C3', '7CC', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI'
                 )
             )
             AND mp.requiere_subgrupos = 0
             ORDER BY c.anio, m.codigo",
            [$cicloLectivoId]
        );
        
        foreach ($materiasTaller as $materia) {
            // Marcar como requiere subgrupos
            $db->query("UPDATE materias_por_curso SET requiere_subgrupos = 1 WHERE id = ?", [$materia['id']]);
            
            // Determinar tipo de taller por código
            $tipoTaller = clasificarTipoTaller($materia['codigo']);
            
            // Crear configuración CON rotación trimestral
            $db->query(
                "INSERT OR REPLACE INTO configuracion_subgrupos 
                 (materia_curso_id, ciclo_lectivo_id, tipo_division, cantidad_grupos, 
                  rotacion_automatica, periodo_rotacion, descripcion)
                 VALUES (?, ?, 'tercio', 3, 1, 'trimestre', ?)",
                [$materia['id'], $cicloLectivoId, 
                 "{$tipoTaller} - {$materia['curso_nombre']} - Rotación automática trimestral entre talleres"]
            );
            
            $materiasConfiguradas++;
            $logDeteccion[] = "✓ Taller: {$materia['codigo']} - {$materia['nombre']} ({$materia['curso_nombre']}) - ROTACIÓN TRIMESTRAL";
        }
        
        // 3. VERIFICACIÓN FINAL Y ESTADÍSTICAS
        $totalSubgrupos = $db->fetchOne(
            "SELECT COUNT(*) as total FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ? AND mp.requiere_subgrupos = 1",
            [$cicloLectivoId]
        )['total'];
        
        return [
            'type' => 'success', 
            'message' => "Se detectaron y configuraron {$materiasConfiguradas} materias automáticamente. Total de materias con subgrupos: {$totalSubgrupos}"
        ];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al detectar materias: ' . $e->getMessage()];
    }
}

function clasificarTipoTaller($codigo) {
    // Mapeo de códigos a tipos de taller
    $tiposPorCodigo = [
        'LDT' => 'Laboratorio de Dibujo Técnico',
        'DA' => 'Dibujo Arquitectónico',
        'DT' => 'Dibujo Técnico',
        'FE' => 'Formación Eléctrica',
        'E' => 'Electricidad',
        'EB' => 'Electricidad Básica',
        'IED' => 'Instalaciones Eléctricas Domiciliarias',
        'DEE' => 'Dispositivos Eléctricos y Electrónicos',
        'L' => 'Laboratorio',
        'CE' => 'Control Eléctrico',
        'M' => 'Mecánica',
        'C' => 'Construcciones',
        'CF' => 'Construcciones con Formación',
        'CT' => 'Construcciones Técnicas',
        'F' => 'Fundición',
        'PA' => 'Producción Agropecuaria',
        'S' => 'Soldadura',
        'T' => 'Tornería',
        'ED' => 'Electrónica Digital',
        'ME' => 'Mediciones Eléctricas',
        'MTT' => 'Mantenimiento de Transmisiones y Tractores',
        'R' => 'Refrigeración',
        'LME' => 'Laboratorio de Mediciones Eléctricas',
        'MCI' => 'Máquinas, Comando e Instalaciones',
        'P' => 'Proyecto',
        'SC' => 'Sistemas de Control',
        'CC' => 'Control y Comando',
        'DE' => 'Dispositivos Electrónicos',
        'DP' => 'Diseño de Proyecto',
        'LMC' => 'Laboratorio de Máquinas y Comando',
        'MM' => 'Máquinas y Motores',
        'RI' => 'Redes Industriales'
    ];
    
    // Extraer prefijo del código
    foreach ($tiposPorCodigo as $prefijo => $tipo) {
        if (strpos($codigo, $prefijo) !== false) {
            return $tipo;
        }
    }
    
    return 'Taller Especializado';
}

function ejecutarDivisionCiudadania($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        
        // Obtener información del curso
        $materia = $db->fetchOne(
            "SELECT mp.*, m.nombre as materia_nombre, c.id as curso_id, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        if (!$materia) {
            throw new Exception("Materia no encontrada");
        }
        
        // Obtener estudiantes del curso
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.apellido, u.nombre
             FROM usuarios u
             JOIN matriculas m ON u.id = m.estudiante_id
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.activo = 1
             ORDER BY u.apellido, u.nombre",
            [$materia['curso_id']]
        );
        
        if (empty($estudiantes)) {
            throw new Exception("No se encontraron estudiantes en el curso");
        }
        
        $totalEstudiantes = count($estudiantes);
        
        // Limpiar asignaciones anteriores
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        );
        
        // Dividir estudiantes en 3 grupos (tercios)
        $grupos = [
            'Grupo 1 - Profesor A' => [],
            'Grupo 2 - Profesor B' => [],
            'Grupo 3 - Profesor C' => []
        ];
        
        $nombresGrupos = array_keys($grupos);
        
        // Distribuir estudiantes equitativamente
        foreach ($estudiantes as $index => $estudiante) {
            $grupoIndex = $index % 3;
            $grupos[$nombresGrupos[$grupoIndex]][] = $estudiante;
        }
        
        // Asignar estudiantes a los grupos para TODO EL AÑO (sin rotación)
        foreach ($grupos as $nombreGrupo => $estudiantesGrupo) {
            foreach ($estudiantesGrupo as $estudiante) {
                $db->query(
                    "INSERT INTO estudiantes_por_materia 
                     (materia_curso_id, estudiante_id, ciclo_lectivo_id, subgrupo, periodo_inicio, activo)
                     VALUES (?, ?, ?, ?, 'anual', 1)",
                    [$materiaCursoId, $estudiante['id'], $cicloLectivoId, $nombreGrupo]
                );
            }
        }
        
        return [
            'type' => 'success', 
            'message' => "División automática realizada. {$totalEstudiantes} estudiantes distribuidos en 3 grupos fijos para todo el año."
        ];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error en división automática: ' . $e->getMessage()];
    }
}

function ejecutarRotacionAutomatica($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        
        // Obtener configuración de la materia
        $config = $db->fetchOne(
            "SELECT * FROM configuracion_subgrupos 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        );
        
        if (!$config) {
            throw new Exception("No se encontró configuración para esta materia");
        }
        
        // Obtener información del curso
        $materia = $db->fetchOne(
            "SELECT mp.*, m.nombre as materia_nombre, c.id as curso_id, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        // Obtener estudiantes del curso
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.apellido, u.nombre
             FROM usuarios u
             JOIN matriculas m ON u.id = m.estudiante_id
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.activo = 1
             ORDER BY u.apellido, u.nombre",
            [$materia['curso_id']]
        );
        
        if (empty($estudiantes)) {
            throw new Exception("No se encontraron estudiantes en el curso");
        }
        
        $totalEstudiantes = count($estudiantes);
        $cantidadGrupos = $config['cantidad_grupos'];
        
        // Definir períodos trimestales
        $periodos = ['1trim', '2trim', '3trim'];
        
        // Limpiar asignaciones anteriores
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        );
        
        // Dividir estudiantes en grupos base
        $grupos = [];
        for ($i = 0; $i < $cantidadGrupos; $i++) {
            $grupos[$i] = [];
        }
        
        // Distribuir estudiantes equitativamente en grupos base
        foreach ($estudiantes as $index => $estudiante) {
            $grupoIndex = $index % $cantidadGrupos;
            $grupos[$grupoIndex][] = $estudiante;
        }
        
        // Asignar estudiantes a períodos con rotación
        foreach ($periodos as $periodoIndex => $periodo) {
            for ($grupoNum = 0; $grupoNum < $cantidadGrupos; $grupoNum++) {
                // Calcular rotación: cada período, el grupo se desplaza al siguiente taller
                $tallerAsignado = ($grupoNum + $periodoIndex) % $cantidadGrupos;
                $nombreTaller = "Taller " . ($tallerAsignado + 1);
                
                foreach ($grupos[$grupoNum] as $estudiante) {
                    $db->query(
                        "INSERT INTO estudiantes_por_materia 
                         (materia_curso_id, estudiante_id, ciclo_lectivo_id, subgrupo, periodo_inicio, activo)
                         VALUES (?, ?, ?, ?, ?, 1)",
                        [$materiaCursoId, $estudiante['id'], $cicloLectivoId, $nombreTaller, $periodo]
                    );
                }
            }
        }
        
        return [
            'type' => 'success', 
            'message' => "Rotación automática ejecutada. {$totalEstudiantes} estudiantes distribuidos en {$cantidadGrupos} talleres con rotación trimestral."
        ];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error en rotación automática: ' . $e->getMessage()];
    }
}

// Funciones auxiliares (restaurar, corregir, etc.)
function restaurarSubgrupos($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        
        $materiaInfo = $db->fetchOne(
            "SELECT mp.*, m.nombre as materia_nombre, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        if (!$materiaInfo) {
            throw new Exception("Materia no encontrada");
        }
        
        $estudiantesAntes = $db->fetchOne(
            "SELECT COUNT(*) as count FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        )['count'];
        
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        );
        
        return [
            'type' => 'success', 
            'message' => "Subgrupos restaurados para '{$materiaInfo['materia_nombre']}'. Se eliminaron {$estudiantesAntes} asignaciones."
        ];
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al restaurar: ' . $e->getMessage()];
    }
}

function corregirAsignacionesPorCurso($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        
        $materiaInfo = $db->fetchOne(
            "SELECT mp.curso_id, m.nombre as materia_nombre, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoId]
        );
        
        if (!$materiaInfo) {
            throw new Exception("Materia no encontrada");
        }
        
        $estudiantesIncorrectos = $db->fetchAll(
            "SELECT ep.*, u.nombre, u.apellido, c.nombre as curso_estudiante
             FROM estudiantes_por_materia ep
             JOIN usuarios u ON ep.estudiante_id = u.id
             JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
             JOIN cursos c ON m.curso_id = c.id
             WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? 
             AND m.curso_id != ?",
            [$materiaCursoId, $cicloLectivoId, $materiaInfo['curso_id']]
        );
        
        $cantidadEliminados = count($estudiantesIncorrectos);
        
        if ($cantidadEliminados > 0) {
            $idsEliminar = array_map(function($est) { return $est['estudiante_id']; }, $estudiantesIncorrectos);
            $placeholders = implode(',', array_fill(0, count($idsEliminar), '?'));
            
            $db->query(
                "DELETE FROM estudiantes_por_materia 
                 WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? AND estudiante_id IN ($placeholders)",
                array_merge([$materiaCursoId, $cicloLectivoId], $idsEliminar)
            );
            
            return [
                'type' => 'success', 
                'message' => "Se eliminaron {$cantidadEliminados} estudiantes que no pertenecían al curso correcto."
            ];
        } else {
            return [
                'type' => 'info', 
                'message' => "No se encontraron asignaciones incorrectas."
            ];
        }
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al corregir: ' . $e->getMessage()];
    }
}

function eliminarAsignacionIndividual($db, $datos) {
    try {
        $estudianteMateriaSql = $datos['estudiante_materia_sql'] ?? '';
        
        if (empty($estudianteMateriaSql)) {
            throw new Exception("No se especificó la asignación a eliminar");
        }
        
        $params = json_decode(base64_decode($estudianteMateriaSql), true);
        if (!$params) {
            throw new Exception("Parámetros de asignación inválidos");
        }
        
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND estudiante_id = ? AND ciclo_lectivo_id = ? 
             AND subgrupo = ? AND periodo_inicio = ?",
            [$params['materia_curso_id'], $params['estudiante_id'], $params['ciclo_lectivo_id'], 
             $params['subgrupo'], $params['periodo_inicio']]
        );
        
        return ['type' => 'success', 'message' => 'Asignación eliminada correctamente'];
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar asignación: ' . $e->getMessage()];
    }
}

function eliminarSubgrupoCompleto($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        $subgrupo = $datos['subgrupo'];
        $periodoInicio = $datos['periodo_inicio'];
        
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ? AND subgrupo = ? AND periodo_inicio = ?",
            [$materiaCursoId, $cicloLectivoId, $subgrupo, $periodoInicio]
        );
        
        return ['type' => 'success', 'message' => "Subgrupo '$subgrupo' eliminado completamente"];
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar subgrupo: ' . $e->getMessage()];
    }
}

function limpiarTodasAsignaciones($db, $datos, $cicloLectivoId) {
    try {
        $materiaCursoId = intval($datos['materia_curso_id']);
        
        $db->query(
            "DELETE FROM estudiantes_por_materia 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaCursoId, $cicloLectivoId]
        );
        
        return ['type' => 'warning', 'message' => 'Todas las asignaciones han sido eliminadas'];
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al limpiar asignaciones: ' . $e->getMessage()];
    }
}

// Continuar con la lógica principal del archivo...
// Obtener materias que requieren subgrupos
$materiasConSubgrupos = [];
if ($cicloLectivoId > 0) {
    $materiasConSubgrupos = $db->fetchAll(
        "SELECT mp.id, mp.requiere_subgrupos, m.nombre as materia_nombre, m.codigo,
                c.nombre as curso_nombre, c.anio,
                cs.tipo_division, cs.cantidad_grupos, cs.rotacion_automatica, cs.periodo_rotacion,
                COUNT(DISTINCT ep.id) as estudiantes_asignados
         FROM materias_por_curso mp
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos c ON mp.curso_id = c.id
         LEFT JOIN configuracion_subgrupos cs ON mp.id = cs.materia_curso_id AND cs.ciclo_lectivo_id = ?
         LEFT JOIN estudiantes_por_materia ep ON mp.id = ep.materia_curso_id AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
         WHERE c.ciclo_lectivo_id = ? AND mp.requiere_subgrupos = 1
         GROUP BY mp.id, mp.requiere_subgrupos, m.nombre, m.codigo, c.nombre, c.anio, cs.tipo_division, cs.cantidad_grupos, cs.rotacion_automatica, cs.periodo_rotacion
         ORDER BY c.anio, m.nombre",
        [$cicloLectivoId, $cicloLectivoId, $cicloLectivoId]
    );
}

// Variables para el formulario
$materiaSeleccionada = isset($_GET['materia']) ? intval($_GET['materia']) : null;
$estudiantesDelCurso = [];
$estudiantesAsignados = [];

if ($materiaSeleccionada) {
    // Obtener estudiantes del curso
    $infoCurso = $db->fetchOne(
        "SELECT c.id as curso_id, c.nombre as curso_nombre
         FROM materias_por_curso mp
         JOIN cursos c ON mp.curso_id = c.id
         WHERE mp.id = ?",
        [$materiaSeleccionada]
    );
    
    if ($infoCurso) {
        $estudiantesDelCurso = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni
             FROM usuarios u
             JOIN matriculas m ON u.id = m.estudiante_id
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' AND u.activo = 1
             ORDER BY u.apellido, u.nombre",
            [$infoCurso['curso_id']]
        );
        
        // Obtener estudiantes ya asignados a subgrupos
        $estudiantesAsignados = $db->fetchAll(
            "SELECT ep.*, u.nombre, u.apellido, u.dni,
                    c.nombre as curso_estudiante, c.id as curso_estudiante_id,
                    c2.nombre as curso_materia, c2.id as curso_materia_id
             FROM estudiantes_por_materia ep
             JOIN usuarios u ON ep.estudiante_id = u.id
             JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
             JOIN cursos c ON m.curso_id = c.id
             JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
             JOIN cursos c2 ON mp.curso_id = c2.id
             WHERE ep.materia_curso_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
             ORDER BY ep.periodo_inicio, ep.subgrupo, u.apellido, u.nombre",
            [$materiaSeleccionada, $cicloLectivoId]
        );
    }
}
?>

<div class="container-fluid mt-4">
    
    <?php if ($mensajeResultado): ?>
    <!-- Mostrar mensaje de resultado -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="alert alert-<?= $tipoMensaje ?> alert-dismissible fade show">
                <i class="bi bi-<?= $tipoMensaje === 'success' ? 'check' : ($tipoMensaje === 'warning' ? 'exclamation-triangle' : 'x') ?>-circle"></i>
                <?= htmlspecialchars($mensajeResultado) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Detección automática de materias -->
    <div class="row mb-4">
        <div class="col-md-12 d-none">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-magic"></i> Configuración Automática de Materias
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h6><i class="bi bi-gear-fill"></i> Sistema Inteligente de Detección</h6>
                            <p>El sistema detectará y configurará automáticamente:</p>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card border-info mb-3">
                                        <div class="card-body">
                                            <h6 class="text-info"><i class="bi bi-people-fill"></i> Construcción de la Ciudadanía</h6>
                                            <ul class="small mb-0">
                                                <li>2° y 3° año únicamente</li>
                                                <li>División en <strong>tercios fijos</strong></li>
                                                <li>Cada tercio con <strong>un profesor todo el año</strong></li>
                                                <li>Sin rotación</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-warning mb-3">
                                        <div class="card-body">
                                            <h6 class="text-warning"><i class="bi bi-tools"></i> Materias de Taller</h6>
                                            <ul class="small mb-0">
                                                <li>Códigos: 1PT, 2LT, 3ST, 4MEA, etc.</li>
                                                <li>División en <strong>tercios</strong></li>
                                                <li><strong>Rotación trimestral</strong> automática</li>
                                                <li>Distribución equitativa entre talleres</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <form method="POST">
                                <input type="hidden" name="accion" value="auto_detectar_materias">
                                <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
                                    <i class="bi bi-magic"></i> 
                                    Detectar y Configurar Automáticamente
                                </button>
                            </form>
                            <div class="alert alert-info small">
                                <i class="bi bi-info-circle"></i>
                                <strong>¿Qué hace esta función?</strong><br>
                                • Identifica materias por nombre y código<br>
                                • Configura subgrupos automáticamente<br>
                                • Establece rotación según el tipo<br>
                                • No afecta asignaciones existentes
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen de materias con subgrupos -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-check"></i> Materias con Sistema de Subgrupos
                        <span class="badge bg-secondary ms-2"><?= count($materiasConSubgrupos) ?> materias</span>
                    </h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#filtrosAvanzados">
                            <i class="bi bi-funnel"></i> Filtros
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="window.print()">
                            <i class="bi bi-printer"></i> Imprimir
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    
                    <!-- Filtros avanzados colapsables -->
                    <div class="collapse mb-3" id="filtrosAvanzados">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <select class="form-select form-select-sm" id="filtroTipo">
                                            <option value="">Todos los tipos</option>
                                            <option value="ciudadania">Construcción Ciudadanía</option>
                                            <option value="taller">Talleres</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select form-select-sm" id="filtroAnio">
                                            <option value="">Todos los años</option>
                                            <option value="1">1° Año</option>
                                            <option value="2">2° Año</option>
                                            <option value="3">3° Año</option>
                                            <option value="4">4° Año</option>
                                            <option value="5">5° Año</option>
                                            <option value="6">6° Año</option>
                                            <option value="7">7° Año</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select form-select-sm" id="filtroConfiguracion">
                                            <option value="">Estado de configuración</option>
                                            <option value="configurado">Configurados</option>
                                            <option value="sin_configurar">Sin configurar</option>
                                            <option value="con_estudiantes">Con estudiantes</option>
                                            <option value="sin_estudiantes">Sin estudiantes</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control form-control-sm" id="filtroBusqueda" placeholder="Buscar materia...">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($materiasConSubgrupos)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="tablaMaterias">
                            <thead class="table-light">
                                <tr>
                                    <th>Materia</th>
                                    <th>Curso</th>
                                    <th>Tipo</th>
                                    <th>Configuración</th>
                                    <th>Estudiantes</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materiasConSubgrupos as $materia): ?>
                                <?php
                                    // Detectar tipo de materia
                                    $esCiudadania = (stripos($materia['materia_nombre'], 'ciudadania') !== false || 
                                                    stripos($materia['materia_nombre'], 'construccion') !== false ||
                                                    in_array($materia['codigo'], ['CCE', 'CCM', 'CCM1', 'CIUD']));
                                    $esTaller = !$esCiudadania && (preg_match('/^[1-7][A-Z]+/', $materia['codigo']) || 
                                               in_array($materia['codigo'], ['LDT1', 'LDT2', 'LDT3', '4DA', '4DT', '2FE', '4E', '4EB']));
                                    
                                    $tipoMateria = $esCiudadania ? 'ciudadania' : ($esTaller ? 'taller' : 'otra');
                                    $tipoNombre = $esCiudadania ? 'Ciudadanía' : ($esTaller ? 'Taller' : 'Normal');
                                    $tipoColor = $esCiudadania ? 'info' : ($esTaller ? 'warning' : 'secondary');
                                    
                                    $estaConfigurado = !empty($materia['tipo_division']);
                                    $tieneEstudiantes = $materia['estudiantes_asignados'] > 0;
                                ?>
                                <tr data-tipo="<?= $tipoMateria ?>" data-anio="<?= $materia['anio'] ?>" data-configurado="<?= $estaConfigurado ? 'si' : 'no' ?>" data-estudiantes="<?= $tieneEstudiantes ? 'si' : 'no' ?>">
                                    <td>
                                        <div>
                                            <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($materia['codigo']) ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?= $materia['anio'] ?>°</span>
                                        <br><small><?= htmlspecialchars($materia['curso_nombre']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $tipoColor ?>">
                                            <i class="bi bi-<?= $esCiudadania ? 'people' : ($esTaller ? 'tools' : 'book') ?>"></i>
                                            <?= $tipoNombre ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($estaConfigurado): ?>
                                            <div class="small">
                                                <span class="badge bg-success">
                                                    <i class="bi bi-gear"></i> Configurado
                                                </span>
                                                <br>
                                                <span class="text-muted">
                                                    <?= ucfirst($materia['tipo_division']) ?> - <?= $materia['cantidad_grupos'] ?> grupos
                                                </span>
                                                <?php if ($materia['rotacion_automatica']): ?>
                                                <br><span class="badge bg-warning text-dark">
                                                    <i class="bi bi-arrow-repeat"></i> Rotación <?= $materia['periodo_rotacion'] ?>
                                                </span>
                                                <?php else: ?>
                                                <br><span class="badge bg-info">
                                                    <i class="bi bi-lock"></i> Grupos fijos
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-exclamation-triangle"></i> Sin configurar
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $tieneEstudiantes ? 'success' : 'secondary' ?>">
                                            <i class="bi bi-people-fill"></i>
                                            <?= $materia['estudiantes_asignados'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($estaConfigurado && $tieneEstudiantes): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Operativo
                                            </span>
                                        <?php elseif ($estaConfigurado): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-exclamation-circle"></i> Sin estudiantes
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-x-circle"></i> Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary btn-sm"
                                                    onclick="configurarSubgrupos(<?= $materia['id'] ?>)"
                                                    data-bs-toggle="modal" data-bs-target="#modalConfigurarSubgrupos"
                                                    title="Configurar subgrupos">
                                                <i class="bi bi-gear"></i>
                                            </button>
                                            <button type="button" class="btn btn-success btn-sm" 
                                                    onclick="abrirModalEstudiantes(<?= $materia['id'] ?>)"
                                                    data-bs-toggle="modal" data-bs-target="#modalGestionarEstudiantes"
                                                    title="Gestionar estudiantes">
                                                <i class="bi bi-people"></i>
                                            </button>
                                            
                                            <?php if ($esCiudadania): ?>
                                            <button type="button" class="btn btn-outline-info btn-sm"
                                                    onclick="dividirCiudadania(<?= $materia['id'] ?>)"
                                                    title="División automática en tercios">
                                                <i class="bi bi-pie-chart"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle display-4 text-muted"></i>
                        <h5 class="mt-3">No se encontraron materias configuradas para subgrupos</h5>
                        <p>Use el botón "Detectar y Configurar Automáticamente" para configurar las materias estándar.</p>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="accion" value="auto_detectar_materias">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-magic"></i> Configurar Ahora
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Gestión de asignaciones para materia específica -->
    <?php if ($materiaSeleccionada && !empty($estudiantesDelCurso)): ?>
    <?php 
        $materiaInfo = $db->fetchOne(
            "SELECT mp.*, m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaSeleccionada]
        );
        
        $configSubgrupo = $db->fetchOne(
            "SELECT * FROM configuracion_subgrupos 
             WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$materiaSeleccionada, $cicloLectivoId]
        );
        
        $esCiudadania = stripos($materiaInfo['materia_nombre'], 'ciudadania') !== false;
        $esTaller = preg_match('/^[1-7][A-Z]+/', $materiaInfo['codigo']) || stripos($materiaInfo['materia_nombre'], 'taller') !== false;
        
        // Detectar problemas de curso
        $hayProblemasCurso = false;
        $estudiantesProblematicos = [];
        foreach ($estudiantesAsignados as $asignado) {
            if ($asignado['curso_estudiante_id'] !== $asignado['curso_materia_id']) {
                $hayProblemasCurso = true;
                $estudiantesProblematicos[] = $asignado;
            }
        }
    ?>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-<?= $esCiudadania ? 'info' : ($esTaller ? 'warning' : 'secondary') ?>">
                <div class="card-header bg-<?= $esCiudadania ? 'info' : ($esTaller ? 'warning' : 'secondary') ?> text-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-<?= $esCiudadania ? 'people' : ($esTaller ? 'tools' : 'book') ?>"></i>
                        Gestión de Subgrupos: <?= htmlspecialchars($materiaInfo['materia_nombre']) ?>
                        <small class="opacity-75">(<?= htmlspecialchars($materiaInfo['curso_nombre']) ?>)</small>
                    </h5>
                    <div class="btn-group">
                        <?php if (!empty($estudiantesAsignados)): ?>
                        <button type="button" class="btn btn-light btn-sm" 
                                onclick="restaurarSubgrupos(<?= $materiaSeleccionada ?>)"
                                title="Restaurar subgrupos">
                            <i class="bi bi-arrow-clockwise"></i> Restaurar
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm" 
                                onclick="corregirAsignacionesCurso(<?= $materiaSeleccionada ?>)"
                                title="Corregir asignaciones por curso">
                            <i class="bi bi-funnel"></i> Corregir
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                onclick="limpiarTodasAsignaciones(<?= $materiaSeleccionada ?>)"
                                title="Limpiar todas las asignaciones">
                            <i class="bi bi-trash"></i> Limpiar
                        </button>
                        <?php endif; ?>
                        <a href="gestionar_subgrupos.php" class="btn btn-light btn-sm">
                            <i class="bi bi-arrow-left"></i> Volver
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    
                    <!-- ALERTA DE PROBLEMAS DE CURSO -->
                    <?php if ($hayProblemasCurso): ?>
                    <div class="alert alert-danger border-start border-5 border-danger">
                        <div class="d-flex align-items-start">
                            <div class="flex-shrink-0">
                                <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="alert-heading"><strong>¡PROBLEMA DETECTADO!</strong></h6>
                                <p>Se encontraron estudiantes asignados que <strong>NO pertenecen al curso de esta materia</strong>:</p>
                                <ul class="mb-3">
                                    <?php foreach ($estudiantesProblematicos as $problemático): ?>
                                    <li>
                                        <strong><?= htmlspecialchars($problemático['apellido']) ?>, <?= htmlspecialchars($problemático['nombre']) ?></strong>
                                        - Pertenece a: <span class="text-warning"><?= htmlspecialchars($problemático['curso_estudiante']) ?></span>
                                        - Materia es de: <span class="text-info"><?= htmlspecialchars($problemático['curso_materia']) ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-info btn-sm" 
                                            onclick="corregirAsignacionesCurso(<?= $materiaSeleccionada ?>)">
                                        <i class="bi bi-funnel"></i> Corregir Automáticamente
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="restaurarSubgrupos(<?= $materiaSeleccionada ?>)">
                                        <i class="bi bi-arrow-clockwise"></i> Restaurar Todo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Información de configuración -->
                    <?php if ($configSubgrupo): ?>
                    <div class="alert alert-<?= $esCiudadania ? 'info' : 'warning' ?> border-start border-5">
                        <div class="row">
                            <div class="col-md-8">
                                <h6><i class="bi bi-gear-fill"></i> <strong>Configuración actual:</strong></h6>
                                <ul class="mb-0">
                                    <li><strong>División:</strong> <?= ucfirst($configSubgrupo['tipo_division']) ?> en <?= $configSubgrupo['cantidad_grupos'] ?> grupos</li>
                                    <li><strong>Rotación:</strong> 
                                        <?php if ($configSubgrupo['rotacion_automatica']): ?>
                                        <span class="text-warning"><i class="bi bi-arrow-repeat"></i> Automática cada <?= $configSubgrupo['periodo_rotacion'] ?></span>
                                        <?php else: ?>
                                        <span class="text-info"><i class="bi bi-lock"></i> Grupos fijos (sin rotación)</span>
                                        <?php endif; ?>
                                    </li>
                                    <li><strong>Estudiantes:</strong> <?= count($estudiantesDelCurso) ?> en el curso, <?= count($estudiantesAsignados) ?> asignados</li>
                                </ul>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($esCiudadania): ?>
                                <div class="badge bg-info fs-6 p-2">
                                    <i class="bi bi-people"></i> Construcción de la Ciudadanía<br>
                                    <small>Grupos fijos todo el año</small>
                                </div>
                                <?php elseif ($esTaller): ?>
                                <div class="badge bg-warning text-dark fs-6 p-2">
                                    <i class="bi bi-tools"></i> Materia de Taller<br>
                                    <small>Rotación trimestral</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- División automática para Construcción de la Ciudadanía -->
                    <?php if ($esCiudadania): ?>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card bg-info text-white">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-people-fill"></i> División Automática - Construcción de la Ciudadanía
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <p class="mb-2">Esta materia se divide en <strong>tercios fijos</strong>. Cada tercio del curso permanece con el mismo profesor durante todo el ciclo lectivo.</p>
                                            <ul class="small mb-3">
                                                <li>División equitativa en 3 grupos</li>
                                                <li>Asignación alfabética por apellido</li>
                                                <li>Sin rotación durante el año</li>
                                                <li>Cada grupo con un profesor específico</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-4 text-center">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="accion" value="auto_dividir_ciudadania">
                                                <input type="hidden" name="materia_curso_id" value="<?= $materiaSeleccionada ?>">
                                                <button type="submit" class="btn btn-light btn-lg" 
                                                        onclick="return confirm('¿Dividir automáticamente en tercios?\n\nEsto asignará cada estudiante a un profesor para todo el año.\n\n⚠️ Se eliminarán las asignaciones anteriores.')">
                                                    <i class="bi bi-pie-chart-fill"></i><br>
                                                    <strong>Dividir en Tercios</strong>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="alert alert-light mt-3 mb-0">
                                        <small>
                                            <strong>Resultado esperado:</strong> 
                                            Grupo 1 - Profesor A (~<?= ceil(count($estudiantesDelCurso)/3) ?> estudiantes), 
                                            Grupo 2 - Profesor B (~<?= ceil(count($estudiantesDelCurso)/3) ?> estudiantes), 
                                            Grupo 3 - Profesor C (~<?= floor(count($estudiantesDelCurso)/3) ?> estudiantes)
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Rotación automática para materias de taller -->
                    <?php if ($esTaller && $configSubgrupo && $configSubgrupo['rotacion_automatica']): ?>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card bg-warning text-dark">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-arrow-repeat"></i> Rotación Automática - Materia de Taller
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <p class="mb-2">Esta materia tiene configurada <strong>rotación automática trimestral</strong>. Los estudiantes se dividen en tercios y rotan cada trimestre entre los diferentes talleres.</p>
                                            <ul class="small mb-3">
                                                <li><strong>1er Trimestre:</strong> Distribución inicial en talleres</li>
                                                <li><strong>2do Trimestre:</strong> Rotación al siguiente taller</li>
                                                <li><strong>3er Trimestre:</strong> Rotación al tercer taller</li>
                                                <li>Cada estudiante pasa por los 3 talleres durante el año</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-4 text-center">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="accion" value="rotacion_automatica">
                                                <input type="hidden" name="materia_curso_id" value="<?= $materiaSeleccionada ?>">
                                                <input type="hidden" name="tipo_rotacion" value="trimestral">
                                                <button type="submit" class="btn btn-dark btn-lg" 
                                                        onclick="return confirm('¿Ejecutar la rotación automática?\n\nEsto redistribuirá todos los estudiantes en los 3 trimestres.\n\n⚠️ Se eliminarán las asignaciones anteriores.')">
                                                    <i class="bi bi-arrow-repeat"></i><br>
                                                    <strong>Ejecutar Rotación</strong>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="alert alert-dark mt-3 mb-0">
                                        <small>
                                            <strong>Resultado esperado:</strong> 
                                            Taller 1, Taller 2, Taller 3 - Los estudiantes (~<?= ceil(count($estudiantesDelCurso)/3) ?> por taller) rotan cada trimestre
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Asignación manual y vista de asignaciones actuales -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-people"></i> Estudiantes del Curso 
                                        <span class="badge bg-primary"><?= count($estudiantesDelCurso) ?></span>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="gestionar_subgrupos.php?materia=<?= $materiaSeleccionada ?>" id="formAsignarEstudiantes">
                                        <input type="hidden" name="accion" value="asignar_estudiantes">
                                        <input type="hidden" name="materia_curso_id" value="<?= $materiaSeleccionada ?>">
                                        
                                        <div class="mb-3">
                                            <label for="subgrupo_manual" class="form-label">
                                                <i class="bi bi-tag"></i> Nombre del Subgrupo: *
                                            </label>
                                            <input type="text" name="subgrupo" id="subgrupo_manual" class="form-control" 
                                                   placeholder="<?= $esCiudadania ? 'ej: Grupo 1 - Profesor García' : 'ej: Taller 1 - Electricidad' ?>" required>
                                            <small class="form-text text-muted">
                                                <?= $esCiudadania ? 'Use nombres descriptivos para identificar al profesor' : 'Identifique claramente el taller o especialidad' ?>
                                            </small>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-12">
                                                <label for="periodo_inicio_manual" class="form-label">
                                                    <i class="bi bi-calendar"></i> Período: *
                                                </label>
                                                <select name="periodo_inicio" id="periodo_inicio_manual" class="form-select" required>
                                                    <?php if ($esCiudadania): ?>
                                                    <option value="anual">Todo el Año (recomendado para Ciudadanía)</option>
                                                    <?php else: ?>
                                                    <option value="1trim">1er Trimestre</option>
                                                    <option value="2trim">2do Trimestre</option>
                                                    <option value="3trim">3er Trimestre</option>
                                                    <option value="1cuatri">1er Cuatrimestre</option>
                                                    <option value="2cuatri">2do Cuatrimestre</option>
                                                    <option value="anual">Todo el Año</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">
                                                <i class="bi bi-person-check"></i> Seleccionar Estudiantes:
                                            </label>
                                            <div class="form-check mb-2">
                                                <input type="checkbox" class="form-check-input" id="selectAllStudents">
                                                <label class="form-check-label" for="selectAllStudents">
                                                    <strong>Seleccionar todos (<?= count($estudiantesDelCurso) ?> estudiantes)</strong>
                                                </label>
                                            </div>
                                            <hr>
                                            <div class="estudiantes-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.75rem;">
                                                <?php if (!empty($estudiantesDelCurso)): ?>
                                                    <?php foreach ($estudiantesDelCurso as $index => $estudiante): ?>
                                                    <div class="form-check mb-1">
                                                        <input type="checkbox" class="form-check-input student-checkbox" 
                                                               name="estudiantes[]" value="<?= $estudiante['id'] ?>" 
                                                               id="est_<?= $estudiante['id'] ?>">
                                                        <label class="form-check-label d-flex justify-content-between align-items-center" for="est_<?= $estudiante['id'] ?>">
                                                            <span>
                                                                <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                                                                <small class="text-muted">(DNI: <?= htmlspecialchars($estudiante['dni']) ?>)</small>
                                                            </span>
                                                            <span class="badge bg-light text-dark"><?= $index + 1 ?></span>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="text-center text-muted py-3">
                                                        <i class="bi bi-person-x"></i>
                                                        <p>No hay estudiantes matriculados en este curso</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-check-circle"></i> Asignar Estudiantes al Subgrupo
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="limpiarSeleccion()">
                                                <i class="bi bi-x-circle"></i> Limpiar Selección
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0">
                                        <i class="bi bi-list-check"></i> Asignaciones Actuales
                                    </h6>
                                    <?php if (!empty($estudiantesAsignados)): ?>
                                    <span class="badge bg-success"><?= count($estudiantesAsignados) ?> asignados</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($estudiantesAsignados)): ?>
                                        <?php
                                        // Agrupar por período y subgrupo
                                        $asignacionesPorPeriodo = [];
                                        foreach ($estudiantesAsignados as $asignado) {
                                            $periodo = $asignado['periodo_inicio'] ?? 'Sin período';
                                            $subgrupo = $asignado['subgrupo'] ?? 'Sin subgrupo';
                                            $asignacionesPorPeriodo[$periodo][$subgrupo][] = $asignado;
                                        }
                                        
                                        // Definir orden de períodos
                                        $ordenPeriodos = ['anual', '1trim', '2trim', '3trim', '1cuatri', '2cuatri'];
                                        uksort($asignacionesPorPeriodo, function($a, $b) use ($ordenPeriodos) {
                                            $posA = array_search($a, $ordenPeriodos);
                                            $posB = array_search($b, $ordenPeriodos);
                                            if ($posA === false) $posA = 999;
                                            if ($posB === false) $posB = 999;
                                            return $posA - $posB;
                                        });
                                        ?>
                                        
                                        <div class="accordion" id="accordionAsignaciones">
                                            <?php foreach ($asignacionesPorPeriodo as $periodo => $subgrupos): ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="heading<?= md5($periodo) ?>">
                                                    <button class="accordion-button <?= $periodo !== 'anual' && $periodo !== '1trim' ? 'collapsed' : '' ?>" type="button" 
                                                            data-bs-toggle="collapse" data-bs-target="#collapse<?= md5($periodo) ?>" 
                                                            aria-expanded="<?= $periodo === 'anual' || $periodo === '1trim' ? 'true' : 'false' ?>" 
                                                            aria-controls="collapse<?= md5($periodo) ?>">
                                                        <div class="d-flex justify-content-between align-items-center w-100 me-3">
                                                            <span>
                                                                <i class="bi bi-calendar-event"></i>
                                                                <strong>
                                                                    <?php
                                                                    $nombresPeriodos = [
                                                                        'anual' => 'Todo el Año',
                                                                        '1trim' => '1er Trimestre',
                                                                        '2trim' => '2do Trimestre', 
                                                                        '3trim' => '3er Trimestre',
                                                                        '1cuatri' => '1er Cuatrimestre',
                                                                        '2cuatri' => '2do Cuatrimestre'
                                                                    ];
                                                                    echo $nombresPeriodos[$periodo] ?? ucfirst($periodo);
                                                                    ?>
                                                                </strong>
                                                            </span>
                                                            <span class="badge bg-primary"><?= count($subgrupos) ?> subgrupos</span>
                                                        </div>
                                                    </button>
                                                </h2>
                                                <div id="collapse<?= md5($periodo) ?>" 
                                                     class="accordion-collapse collapse <?= $periodo === 'anual' || $periodo === '1trim' ? 'show' : '' ?>" 
                                                     aria-labelledby="heading<?= md5($periodo) ?>" 
                                                     data-bs-parent="#accordionAsignaciones">
                                                    <div class="accordion-body p-2">
                                                        <?php foreach ($subgrupos as $nombreSubgrupo => $estudiantes): ?>
                                                        <div class="card mb-2 border-start border-3 <?= $esCiudadania ? 'border-info' : 'border-warning' ?>">
                                                            <div class="card-header py-2 bg-light">
                                                                <div class="d-flex justify-content-between align-items-center">
                                                                    <div>
                                                                        <strong><?= htmlspecialchars($nombreSubgrupo) ?></strong>
                                                                        <span class="badge bg-<?= $esCiudadania ? 'info' : 'warning' ?> ms-2">
                                                                            <?= count($estudiantes) ?> estudiantes
                                                                        </span>
                                                                    </div>
                                                                    <button type="button" class="btn btn-outline-danger btn-sm"
                                                                            onclick="eliminarSubgrupoCompleto(<?= $materiaSeleccionada ?>, '<?= addslashes($nombreSubgrupo) ?>', '<?= $periodo ?>')"
                                                                            title="Eliminar todo el subgrupo">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                            <div class="card-body py-2">
                                                                <div class="row">
                                                                    <?php foreach ($estudiantes as $est): ?>
                                                                    <?php 
                                                                        $esProblematico = $est['curso_estudiante_id'] !== $est['curso_materia_id'];
                                                                        $parametrosAsignacion = base64_encode(json_encode([
                                                                            'materia_curso_id' => $est['materia_curso_id'],
                                                                            'estudiante_id' => $est['estudiante_id'],
                                                                            'ciclo_lectivo_id' => $est['ciclo_lectivo_id'],
                                                                            'subgrupo' => $est['subgrupo'],
                                                                            'periodo_inicio' => $est['periodo_inicio']
                                                                        ]));
                                                                    ?>
                                                                    <div class="col-md-6 col-lg-4 mb-1">
                                                                        <div class="d-flex justify-content-between align-items-center small <?= $esProblematico ? 'text-danger' : '' ?>">
                                                                            <span>
                                                                                <?= htmlspecialchars($est['apellido']) ?>, <?= htmlspecialchars($est['nombre']) ?>
                                                                                <?php if ($esProblematico): ?>
                                                                                <i class="bi bi-exclamation-triangle text-danger" title="Estudiante de otro curso"></i>
                                                                                <?php endif; ?>
                                                                            </span>
                                                                            <button type="button" class="btn btn-outline-danger btn-sm ms-1 p-0" style="font-size: 0.7rem; width: 20px; height: 20px;"
                                                                                    onclick="eliminarAsignacionIndividual('<?= $parametrosAsignacion ?>')"
                                                                                    title="Eliminar estudiante del subgrupo">
                                                                                <i class="bi bi-x"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-inbox display-4 text-muted"></i>
                                            <h6 class="text-muted mt-2">No hay estudiantes asignados</h6>
                                            <p class="text-muted small">Use el formulario de la izquierda para asignar estudiantes a subgrupos</p>
                                            <?php if ($esCiudadania): ?>
                                            <button type="button" class="btn btn-info btn-sm" onclick="dividirCiudadania(<?= $materiaSeleccionada ?>)">
                                                <i class="bi bi-pie-chart"></i> División Automática
                                            </button>
                                            <?php elseif ($esTaller): ?>
                                            <button type="button" class="btn btn-warning btn-sm" onclick="ejecutarRotacion(<?= $materiaSeleccionada ?>)">
                                                <i class="bi bi-arrow-repeat"></i> Rotación Automática
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($materiaSeleccionada): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="alert alert-warning border-start border-5 border-warning">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle fs-4 me-3"></i>
                    <div>
                        <h6 class="alert-heading">No se encontraron estudiantes</h6>
                        <p class="mb-2">No se encontraron estudiantes matriculados en el curso para esta materia.</p>
                        <a href="gestionar_subgrupos.php" class="btn btn-outline-warning">
                            <i class="bi bi-arrow-left"></i> Volver a la lista
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Configurar Subgrupos -->
<div class="modal fade" id="modalConfigurarSubgrupos" tabindex="-1" aria-labelledby="modalConfigurarSubgruposLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="formConfigurarSubgrupos">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalConfigurarSubgruposLabel">
                        <i class="bi bi-gear"></i> Configurar Subgrupos
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="configurar_subgrupos">
                    <input type="hidden" name="materia_curso_id" id="config_materia_curso_id">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Configuración de subgrupos:</strong> Define cómo se dividirán los estudiantes y si habrá rotación entre grupos.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="tipo_division" class="form-label">
                                    <i class="bi bi-pie-chart"></i> Tipo de División: *
                                </label>
                                <select name="tipo_division" id="tipo_division" class="form-select" required>
                                    <option value="tercio">En Tercios (divide en 3 grupos)</option>
                                    <option value="mitad">En Mitades (divide en 2 grupos)</option>
                                    <option value="manual">Manual (asignación libre)</option>
                                </select>
                                <small class="form-text text-muted">Recomendado: Tercios para la mayoría de materias</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="cantidad_grupos" class="form-label">
                                    <i class="bi bi-people"></i> Cantidad de Grupos: *
                                </label>
                                <input type="number" name="cantidad_grupos" id="cantidad_grupos" 
                                       class="form-control" min="2" max="5" value="3" required>
                                <small class="form-text text-muted">Número de subgrupos a crear</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="rotacion_automatica" name="rotacion_automatica">
                                    <label class="form-check-label" for="rotacion_automatica">
                                        <strong>Rotación automática de grupos</strong>
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    <strong>Activar para:</strong> Materias de taller<br>
                                    <strong>Desactivar para:</strong> Construcción de la Ciudadanía
                                </small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="periodo_rotacion" class="form-label">
                                    <i class="bi bi-calendar-event"></i> Período de Rotación:
                                </label>
                                <select name="periodo_rotacion" id="periodo_rotacion" class="form-select">
                                    <option value="trimestre">Cada Trimestre (recomendado para talleres)</option>
                                    <option value="cuatrimestre">Cada Cuatrimestre</option>
                                    <option value="anual">Anual - Sin rotación (para Ciudadanía)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="descripcion" class="form-label">
                            <i class="bi bi-journal-text"></i> Descripción/Observaciones:
                        </label>
                        <textarea name="descripcion" id="descripcion" class="form-control" rows="3"
                                  placeholder="Información adicional sobre la configuración de subgrupos (ej: nombres de profesores, especialidades de talleres, etc.)"></textarea>
                    </div>
                    
                    <!-- Ejemplos de configuración -->
                    <div class="border rounded p-3 bg-light">
                        <h6><i class="bi bi-lightbulb"></i> Ejemplos de configuración:</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card border-info">
                                    <div class="card-body p-2">
                                        <h6 class="card-title text-info">Construcción de la Ciudadanía</h6>
                                        <ul class="small mb-0">
                                            <li>Tipo: Tercios</li>
                                            <li>Grupos: 3</li>
                                            <li>Rotación: NO</li>
                                            <li>Período: Anual</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-warning">
                                    <div class="card-body p-2">
                                        <h6 class="card-title text-warning">Materias de Taller</h6>
                                        <ul class="small mb-0">
                                            <li>Tipo: Tercios</li>
                                            <li>Grupos: 3</li>
                                            <li>Rotación: SÍ</li>
                                            <li>Período: Trimestre</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Guardar Configuración
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Gestionar Estudiantes -->
<div class="modal fade" id="modalGestionarEstudiantes" tabindex="-1" aria-labelledby="modalGestionarEstudiantesLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl"> <!-- modal-xl para que sea más grande -->
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalGestionarEstudiantesLabel">
                    <i class="bi bi-people"></i> Gestionar Estudiantes por Subgrupos
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Loader inicial -->
                <div id="loadingEstudiantes" class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2">Cargando estudiantes...</p>
                </div>
                
                <!-- Contenido que se cargará dinámicamente -->
                <div id="contenidoEstudiantes" style="display: none;">
                    <!-- Aquí se cargará el contenido -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-primary" id="guardarCambiosEstudiantes" style="display: none;">
                    <i class="bi bi-save"></i> Guardar Cambios
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variables globales
let currentMateriaId = null;

// Función para configurar subgrupos
function configurarSubgrupos(materiaCursoId) {
    currentMateriaId = materiaCursoId;
    document.getElementById('config_materia_curso_id').value = materiaCursoId;
    
    // Resetear formulario
    document.getElementById('formConfigurarSubgrupos').reset();
    document.getElementById('cantidad_grupos').value = 3;
}

// Función para ejecutar rotación
function ejecutarRotacion(materiaCursoId) {
    if (confirm('¿Ejecutar la rotación automática?\n\nEsto redistribuirá todos los estudiantes en los diferentes talleres por trimestre.\n\n⚠️ Se eliminarán las asignaciones anteriores.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="rotacion_automatica">
            <input type="hidden" name="materia_curso_id" value="${materiaCursoId}">
            <input type="hidden" name="tipo_rotacion" value="trimestral">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Función para dividir ciudadanía
function dividirCiudadania(materiaCursoId) {
    if (confirm('¿Ejecutar la división automática de Construcción de la Ciudadanía?\n\nEsto dividirá a los estudiantes en tercios fijos para todo el año.\n\n⚠️ Se eliminarán las asignaciones anteriores.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="auto_dividir_ciudadania">
            <input type="hidden" name="materia_curso_id" value="${materiaCursoId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Funciones para gestión de asignaciones
function eliminarAsignacionIndividual(parametrosAsignacion) {
    if (confirm('¿Eliminar esta asignación individual?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="eliminar_asignacion">
            <input type="hidden" name="estudiante_materia_sql" value="${parametrosAsignacion}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function eliminarSubgrupoCompleto(materiaCursoId, subgrupo, periodoInicio) {
    if (confirm(`¿Eliminar COMPLETAMENTE el subgrupo "${subgrupo}" del período "${periodoInicio}"?\n\nEsto eliminará todas las asignaciones de este subgrupo.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="eliminar_subgrupo_completo">
            <input type="hidden" name="materia_curso_id" value="${materiaCursoId}">
            <input type="hidden" name="subgrupo" value="${subgrupo}">
            <input type="hidden" name="periodo_inicio" value="${periodoInicio}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function limpiarTodasAsignaciones(materiaCursoId) {
    if (confirm('⚠️ ¿ELIMINAR TODAS las asignaciones de esta materia?\n\nEsta acción NO se puede deshacer y eliminará todos los subgrupos y estudiantes asignados.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="limpiar_todas_asignaciones">
            <input type="hidden" name="materia_curso_id" value="${materiaCursoId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function restaurarSubgrupos(materiaCursoId) {
    if (confirm('🔄 ¿RESTAURAR los subgrupos de esta materia?\n\nEsto eliminará todas las asignaciones de estudiantes pero mantendrá la configuración de subgrupos.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="restaurar_subgrupos">
            <input type="hidden" name="materia_curso_id" value="${materiaCursoId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function corregirAsignacionesCurso(materiaCursoId) {
    if (confirm('🔧 ¿CORREGIR las asignaciones por curso?\n\nEsto eliminará SOLO los estudiantes que no pertenecen al curso correcto de esta materia.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="accion" value="corregir_asignaciones_curso">
            <input type="hidden" name="materia_curso_id" value="${materiaCursoId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Event listeners para el formulario
document.addEventListener('DOMContentLoaded', function() {
    // Seleccionar todos los estudiantes
    const selectAllCheckbox = document.getElementById('selectAllStudents');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            studentCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }
    
    // Actualizar el estado del checkbox "seleccionar todos"
    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allChecked = Array.from(studentCheckboxes).every(cb => cb.checked);
            const noneChecked = Array.from(studentCheckboxes).every(cb => !cb.checked);
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
            }
        });
    });
    
    // Configurar rotación automática checkbox
    const rotacionCheckbox = document.getElementById('rotacion_automatica');
    const periodoSelect = document.getElementById('periodo_rotacion');
    
    if (rotacionCheckbox && periodoSelect) {
        rotacionCheckbox.addEventListener('change', function() {
            if (this.checked) {
                periodoSelect.value = 'trimestre';
            } else {
                periodoSelect.value = 'anual';
            }
        });
    }
    
    // Filtros de tabla
    const filtros = {
        tipo: document.getElementById('filtroTipo'),
        anio: document.getElementById('filtroAnio'),
        configuracion: document.getElementById('filtroConfiguracion'),
        busqueda: document.getElementById('filtroBusqueda')
    };
    
    // Aplicar filtros
    function aplicarFiltros() {
        const filas = document.querySelectorAll('#tablaMaterias tbody tr');
        
        filas.forEach(fila => {
            let mostrar = true;
            
            // Filtro por tipo
            if (filtros.tipo.value && fila.dataset.tipo !== filtros.tipo.value) {
                mostrar = false;
            }
            
            // Filtro por año
            if (filtros.anio.value && fila.dataset.anio !== filtros.anio.value) {
                mostrar = false;
            }
            
            // Filtro por configuración
            if (filtros.configuracion.value) {
                const configurado = fila.dataset.configurado === 'si';
                const tieneEstudiantes = fila.dataset.estudiantes === 'si';
                
                switch (filtros.configuracion.value) {
                    case 'configurado':
                        if (!configurado) mostrar = false;
                        break;
                    case 'sin_configurar':
                        if (configurado) mostrar = false;
                        break;
                    case 'con_estudiantes':
                        if (!tieneEstudiantes) mostrar = false;
                        break;
                    case 'sin_estudiantes':
                        if (tieneEstudiantes) mostrar = false;
                        break;
                }
            }
            
            // Filtro por búsqueda
            if (filtros.busqueda.value) {
                const texto = fila.textContent.toLowerCase();
                const busqueda = filtros.busqueda.value.toLowerCase();
                if (!texto.includes(busqueda)) {
                    mostrar = false;
                }
            }
            
            fila.style.display = mostrar ? '' : 'none';
        });
    }
    
    // Event listeners para filtros
    Object.values(filtros).forEach(filtro => {
        if (filtro) {
            filtro.addEventListener('change', aplicarFiltros);
            filtro.addEventListener('input', aplicarFiltros);
        }
    });
    
    // Limpiar mensajes después de 5 segundos
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        });
    }, 5000);
});

function limpiarSeleccion() {
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    const selectAll = document.getElementById('selectAllStudents');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
}

// Función para imprimir
window.addEventListener('beforeprint', function() {
    // Expandir todos los acordeones antes de imprimir
    const accordions = document.querySelectorAll('.accordion-collapse');
    accordions.forEach(accordion => {
        accordion.classList.add('show');
    });
});

window.addEventListener('afterprint', function() {
    // Restaurar estado original después de imprimir
    const accordions = document.querySelectorAll('.accordion-collapse');
    accordions.forEach((accordion, index) => {
        if (index > 0) { // Mantener el primero abierto
            accordion.classList.remove('show');
        }
    });
});

let materiaCursoActual = null;

/**
 * Función para abrir el modal y cargar estudiantes
 */
function abrirModalEstudiantes(materiaCursoId) {
    materiaCursoActual = materiaCursoId;
    
    // Mostrar loader y ocultar contenido
    document.getElementById('loadingEstudiantes').style.display = 'block';
    document.getElementById('contenidoEstudiantes').style.display = 'none';
    document.getElementById('guardarCambiosEstudiantes').style.display = 'none';
    
    // Cargar contenido vía AJAX
    cargarEstudiantesModal(materiaCursoId);
}

/**
 * Función para cargar estudiantes vía AJAX
 */
function cargarEstudiantesModal(materiaCursoId) {
    fetch('cargar_estudiantes_modal.php?materia_curso_id=' + materiaCursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostrarEstudiantesEnModal(data);
            } else {
                mostrarErrorEnModal(data.message || 'Error al cargar estudiantes');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            mostrarErrorEnModal('Error de conexión al cargar estudiantes');
        });
}

/**
 * Función para mostrar estudiantes en el modal (versión robusta)
 */
function mostrarEstudiantesEnModal(data) {
    try {
        // Validar datos de entrada
        if (!data || !data.materia || !data.configuracion || !data.estudiantes || !data.estudiantes_asignados) {
            throw new Error('Datos incompletos recibidos del servidor');
        }
        
        const materia = data.materia;
        const configuracion = data.configuracion;
        const estudiantes = data.estudiantes;
        const estudiantesAsignados = data.estudiantes_asignados;
        
        console.log('Mostrando estudiantes en modal:', {
            materia: materia.nombre,
            total_estudiantes: estudiantes.length,
            estudiantes_asignados: estudiantesAsignados.length
        });
        
        // Calcular estudiantes no asignados PRIMERO
        const estudiantesAsignadosIds = estudiantesAsignados.map(ea => parseInt(ea.estudiante_id));
        const estudiantesNoAsignados = estudiantes.filter(estudiante => 
            !estudiantesAsignadosIds.includes(parseInt(estudiante.id))
        );
        
        console.log('Estudiantes disponibles para asignar:', estudiantesNoAsignados.length);
        
        let html = `
            <div class="row mb-3">
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <strong>Materia:</strong> ${materia.nombre} (${materia.codigo})<br>
                        <strong>Curso:</strong> ${materia.curso_nombre}<br>
                        <strong>Configuración:</strong> ${configuracion.tipo_division} en ${configuracion.cantidad_grupos} grupos
                        ${configuracion.rotacion_automatica ? '<br><strong>Rotación:</strong> ' + configuracion.periodo_rotacion : ''}
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="bi bi-people"></i> Estudiantes Disponibles (${estudiantes.length} total)</h6>
                    <div class="border rounded p-3" style="height: 400px; overflow-y: auto;">
                        <div class="list-group" id="estudiantesDisponibles">
        `;
        
        // Controles de selección múltiple (solo si hay estudiantes disponibles)
        if (estudiantesNoAsignados.length > 0) {
            html += `
                <div class="mb-3 border-bottom pb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="selectAllDisponibles">
                            <label class="form-check-label fw-bold" for="selectAllDisponibles">
                                Seleccionar todos (${estudiantesNoAsignados.length})
                            </label>
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-primary" id="asignarSeleccionados" disabled>
                                <i class="bi bi-arrow-right"></i> Asignar Seleccionados (<span id="contadorSeleccionados">0</span>)
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Lista de estudiantes disponibles
            estudiantesNoAsignados.forEach((estudiante, index) => {
                html += `
                    <div class="list-group-item" data-estudiante-id="${estudiante.id}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="form-check flex-grow-1">
                                <input type="checkbox" class="form-check-input estudiante-checkbox" 
                                       id="est_disp_${estudiante.id}" value="${estudiante.id}">
                                <label class="form-check-label flex-grow-1" for="est_disp_${estudiante.id}">
                                    <div>
                                        <strong>${estudiante.apellido}, ${estudiante.nombre}</strong>
                                        <small class="text-muted d-block">DNI: ${estudiante.dni}</small>
                                    </div>
                                </label>
                            </div>
                            <div class="ms-2">
                                <span class="badge bg-light text-dark">${index + 1}</span>
                                <button type="button" class="btn btn-sm btn-outline-primary ms-1" 
                                        onclick="asignarEstudianteIndividual(${estudiante.id})"
                                        title="Asignar solo este estudiante">
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
        } else {
            html += '<p class="text-muted text-center py-3">Todos los estudiantes están asignados</p>';
        }
        
        html += `
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <h6><i class="bi bi-diagram-3"></i> Estudiantes Asignados por Subgrupo (${estudiantesAsignados.length} asignados)</h6>
                    <div class="border rounded p-3" style="height: 400px; overflow-y: auto;">
                        <div id="estudiantesAsignados">
        `;
        
        // Agrupar estudiantes asignados por subgrupo
        const estudiantesPorSubgrupo = {};
        console.log('Estudiantes asignados recibidos:', estudiantesAsignados);
        
        estudiantesAsignados.forEach(ea => {
            console.log('Procesando estudiante:', ea.apellido, ea.nombre, 'Subgrupo:', ea.subgrupo);
            
            // Normalizar el nombre del subgrupo para agrupar correctamente
            let claveSubgrupo = ea.subgrupo;
            
            // Si el subgrupo contiene números, extraer el número
            const match = ea.subgrupo.match(/(\d+)/);
            if (match) {
                claveSubgrupo = parseInt(match[1]);
            }
            
            if (!estudiantesPorSubgrupo[claveSubgrupo]) {
                estudiantesPorSubgrupo[claveSubgrupo] = [];
            }
            estudiantesPorSubgrupo[claveSubgrupo].push(ea);
        });
        
        console.log('Estudiantes agrupados:', estudiantesPorSubgrupo);
        
        // Mostrar subgrupos
        for (let i = 1; i <= configuracion.cantidad_grupos; i++) {
            // Buscar estudiantes para este subgrupo usando diferentes posibles claves
            let estudiantesEnSubgrupo = estudiantesPorSubgrupo[i] || 
                                       estudiantesPorSubgrupo[`Subgrupo ${i}`] || 
                                       estudiantesPorSubgrupo[`Grupo ${i}`] || 
                                       [];
            
            html += `
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-2">
                            <span class="badge bg-primary">Subgrupo ${i}</span>
                            <small class="text-muted">(${estudiantesEnSubgrupo.length} estudiantes)</small>
                        </h6>
                    </div>
                    <div class="subgrupo-container" data-subgrupo="${i}">
            `;
            
            if (estudiantesEnSubgrupo.length > 0) {
                estudiantesEnSubgrupo.forEach(ea => {
                    html += `
                        <div class="alert alert-light d-flex justify-content-between align-items-center py-2 mb-1" 
                             data-asignacion-id="${ea.id}">
                            <div>
                                <strong>${ea.apellido}, ${ea.nombre}</strong><br>
                                <small class="text-muted">DNI: ${ea.dni} | Período: ${ea.periodo_inicio}</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                    onclick="desasignarEstudiante(${ea.id})">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    `;
                });
            } else {
                html += '<p class="text-muted small">Sin estudiantes asignados</p>';
            }
            
            html += `
                    </div>
                </div>
            `;
        }
        
        html += `
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Selector de subgrupo para asignaciones -->
            <div class="row mt-3" id="selectorSubgrupo" style="display: none;">
                <div class="col-md-12">
                    <div class="alert alert-warning">
                        <h6>Seleccione subgrupo para el estudiante:</h6>
                        <div class="btn-group" role="group">
        `;
        
        for (let i = 1; i <= configuracion.cantidad_grupos; i++) {
            html += `
                <button type="button" class="btn btn-outline-primary" 
                        onclick="confirmarAsignacion(${i})">
                    Subgrupo ${i}
                </button>
            `;
        }
        
        html += `
                        </div>
                        <button type="button" class="btn btn-secondary ms-2" onclick="cancelarAsignacion()">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Mostrar contenido y ocultar loader
        document.getElementById('contenidoEstudiantes').innerHTML = html;
        document.getElementById('loadingEstudiantes').style.display = 'none';
        document.getElementById('contenidoEstudiantes').style.display = 'block';
        document.getElementById('guardarCambiosEstudiantes').style.display = 'block';
        
        // Guardar configuración para uso global
        window.configuracionActual = configuracion;
        
        // Configurar eventos de selección múltiple
        setTimeout(() => {
            try {
                configurarSeleccionMultiple();
                console.log('Selección múltiple configurada correctamente');
            } catch (error) {
                console.error('Error al configurar selección múltiple:', error);
            }
        }, 100);
        
    } catch (error) {
        console.error('Error en mostrarEstudiantesEnModal:', error);
        mostrarErrorEnModal('Error al mostrar estudiantes: ' + error.message);
    }
}

// Variables para la selección múltiple
let estudiantesSeleccionados = [];
let modoSeleccionMultiple = false;

/**
 * Función para configurar eventos de selección múltiple
 */
function configurarSeleccionMultiple() {
    // Checkbox "Seleccionar todos"
    const selectAllCheckbox = document.getElementById('selectAllDisponibles');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.estudiante-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            actualizarContadorSeleccionados();
        });
    }
    
    // Checkboxes individuales
    const checkboxes = document.querySelectorAll('.estudiante-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            actualizarContadorSeleccionados();
            
            // Actualizar estado del "Seleccionar todos"
            if (selectAllCheckbox) {
                const totalCheckboxes = checkboxes.length;
                const checkedCheckboxes = document.querySelectorAll('.estudiante-checkbox:checked').length;
                
                selectAllCheckbox.checked = checkedCheckboxes === totalCheckboxes;
                selectAllCheckbox.indeterminate = checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes;
            }
        });
    });
    
    // Botón "Asignar Seleccionados"
    const btnAsignarSeleccionados = document.getElementById('asignarSeleccionados');
    if (btnAsignarSeleccionados) {
        btnAsignarSeleccionados.addEventListener('click', function() {
            const seleccionados = obtenerEstudiantesSeleccionados();
            if (seleccionados.length > 0) {
                asignarEstudiantesMultiples(seleccionados);
            }
        });
    }
}

/**
 * Función para actualizar el contador de seleccionados
 */
function actualizarContadorSeleccionados() {
    const checkboxes = document.querySelectorAll('.estudiante-checkbox:checked');
    const contador = checkboxes.length;
    
    const contadorElement = document.getElementById('contadorSeleccionados');
    const btnAsignarSeleccionados = document.getElementById('asignarSeleccionados');
    
    if (contadorElement) {
        contadorElement.textContent = contador;
    }
    
    if (btnAsignarSeleccionados) {
        btnAsignarSeleccionados.disabled = contador === 0;
        
        if (contador > 0) {
            btnAsignarSeleccionados.innerHTML = `<i class="bi bi-arrow-right"></i> Asignar ${contador} Estudiante${contador > 1 ? 's' : ''}`;
        } else {
            btnAsignarSeleccionados.innerHTML = `<i class="bi bi-arrow-right"></i> Asignar Seleccionados (0)`;
        }
    }
}

/**
 * Función para obtener estudiantes seleccionados
 */
function obtenerEstudiantesSeleccionados() {
    const checkboxes = document.querySelectorAll('.estudiante-checkbox:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.value));
}

/**
 * Función para asignar múltiples estudiantes
 */
function asignarEstudiantesMultiples(estudiantesIds) {
    if (estudiantesIds.length === 0) {
        mostrarMensajeTemporal('No hay estudiantes seleccionados', 'warning');
        return;
    }
    
    modoSeleccionMultiple = true;
    estudiantesSeleccionados = estudiantesIds;
    
    // Mostrar selector de subgrupo con información de cantidad
    mostrarSelectorSubgrupoMultiple(estudiantesIds.length);
}

/**
 * Función para mostrar selector de subgrupo para múltiples estudiantes
 */
function mostrarSelectorSubgrupoMultiple(cantidad) {
    const selectorSubgrupo = document.getElementById('selectorSubgrupo');
    if (selectorSubgrupo) {
        // Actualizar el contenido del selector
        const alertDiv = selectorSubgrupo.querySelector('.alert');
        if (alertDiv) {
            alertDiv.innerHTML = `
                <h6><i class="bi bi-people-fill"></i> Asignar ${cantidad} estudiantes al subgrupo:</h6>
                <div class="mb-3">
                    <div class="alert alert-info">
                        <small>
                            <i class="bi bi-info-circle"></i>
                            Se asignarán <strong>${cantidad} estudiantes</strong> al subgrupo seleccionado.
                        </small>
                    </div>
                </div>
                <div class="btn-group" role="group" id="botonesSubgrupos">
                    <!-- Se llenará dinámicamente -->
                </div>
                <button type="button" class="btn btn-secondary ms-2" onclick="cancelarAsignacionMultiple()">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
            `;
            
            // Recrear botones de subgrupos
            const botonesContainer = alertDiv.querySelector('#botonesSubgrupos');
            if (botonesContainer && window.configuracionActual) {
                for (let i = 1; i <= window.configuracionActual.cantidad_grupos; i++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-outline-primary me-2';
                    btn.onclick = () => confirmarAsignacionMultiple(i);
                    btn.innerHTML = `
                        <i class="bi bi-people"></i> 
                        Subgrupo ${i}
                    `;
                    botonesContainer.appendChild(btn);
                }
            }
        }
        
        selectorSubgrupo.style.display = 'block';
        selectorSubgrupo.scrollIntoView({ behavior: 'smooth' });
    }
}

/**
 * Función para confirmar asignación múltiple
 */
function confirmarAsignacionMultiple(subgrupo) {
    if (!estudiantesSeleccionados || estudiantesSeleccionados.length === 0) {
        mostrarMensajeTemporal('No hay estudiantes seleccionados', 'warning');
        return;
    }
    
    // Mostrar loading
    const btnContainer = document.getElementById('botonesSubgrupos');
    if (btnContainer) {
        btnContainer.innerHTML = `
            <div class="text-center">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Asignando ${estudiantesSeleccionados.length} estudiantes...
            </div>
        `;
    }
    
    // Asignar estudiantes uno por uno
    asignarEstudiantesEnLote(estudiantesSeleccionados, subgrupo, 0);
}

/**
 * Función para asignar estudiantes en lote (recursiva)
 */
function asignarEstudiantesEnLote(estudiantesIds, subgrupo, indice) {
    if (indice >= estudiantesIds.length) {
        // Todos asignados, recargar modal
        mostrarMensajeTemporal(`${estudiantesIds.length} estudiantes asignados correctamente al Subgrupo ${subgrupo}`, 'success');
        cancelarAsignacionMultiple();
        cargarEstudiantesModal(materiaCursoActual);
        return;
    }
    
    const estudianteId = estudiantesIds[indice];
    
    const data = {
        accion: 'asignar_estudiante',
        estudiante_id: estudianteId,
        materia_curso_id: materiaCursoActual,
        subgrupo: subgrupo
    };
    
    fetch('procesar_estudiantes_modal.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // Continuar con el siguiente estudiante
            asignarEstudiantesEnLote(estudiantesIds, subgrupo, indice + 1);
        } else {
            mostrarMensajeTemporal(`Error al asignar estudiante ${indice + 1}: ${result.message}`, 'danger');
            cancelarAsignacionMultiple();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarMensajeTemporal(`Error de conexión al asignar estudiante ${indice + 1}`, 'danger');
        cancelarAsignacionMultiple();
    });
}

/**
 * Función para cancelar asignación múltiple
 */
function cancelarAsignacionMultiple() {
    modoSeleccionMultiple = false;
    estudiantesSeleccionados = [];
    
    const selectorSubgrupo = document.getElementById('selectorSubgrupo');
    if (selectorSubgrupo) {
        selectorSubgrupo.style.display = 'none';
    }
    
    // Limpiar selecciones
    const checkboxes = document.querySelectorAll('.estudiante-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    
    const selectAll = document.getElementById('selectAllDisponibles');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
    
    actualizarContadorSeleccionados();
}

/**
 * Función para asignación individual (mantener funcionalidad original)
 */
function asignarEstudianteIndividual(estudianteId) {
    // Asignar un solo estudiante (funcionalidad original)
    asignarEstudiante(estudianteId);
}

/**
 * Función para asignar estudiante individual (renombrada para compatibilidad)
 */
function asignarEstudiante(estudianteId) {
    if (modoSeleccionMultiple) {
        // Si estamos en modo múltiple, ignorar clics individuales
        return;
    }
    
    estudianteEnAsignacion = estudianteId;
    document.getElementById('selectorSubgrupo').style.display = 'block';
    
    // Highlight del estudiante seleccionado
    document.querySelectorAll('[data-estudiante-id]').forEach(el => {
        el.classList.remove('bg-warning');
    });
    document.querySelector(`[data-estudiante-id="${estudianteId}"]`).classList.add('bg-warning');
    
    // Actualizar selector para un solo estudiante
    const alertDiv = document.querySelector('#selectorSubgrupo .alert');
    if (alertDiv) {
        alertDiv.innerHTML = `
            <h6>Seleccione subgrupo para el estudiante:</h6>
            <div class="btn-group" role="group" id="botonesSubgrupos">
                <!-- Se llenará dinámicamente -->
            </div>
            <button type="button" class="btn btn-secondary ms-2" onclick="cancelarAsignacion()">
                Cancelar
            </button>
        `;
        
        // Recrear botones de subgrupos
        const botonesContainer = alertDiv.querySelector('#botonesSubgrupos');
        if (botonesContainer && window.configuracionActual) {
            for (let i = 1; i <= window.configuracionActual.cantidad_grupos; i++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-primary';
                btn.onclick = () => confirmarAsignacion(i);
                btn.textContent = `Subgrupo ${i}`;
                botonesContainer.appendChild(btn);
            }
        }
    }
}

/**
 * Función para cargar estudiantes vía AJAX con debug mejorado
 */
function cargarEstudiantesModal(materiaCursoId) {
    console.log('Cargando estudiantes para materia_curso_id:', materiaCursoId);
    
    // Validar parámetro
    if (!materiaCursoId || isNaN(materiaCursoId)) {
        console.error('ID de materia curso inválido:', materiaCursoId);
        mostrarErrorEnModal('ID de materia inválido');
        return;
    }
    
    fetch('cargar_estudiantes_modal.php?materia_curso_id=' + materiaCursoId)
        .then(response => {
            console.log('Respuesta recibida:', response.status, response.statusText);
            
            // Verificar si la respuesta es exitosa
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            // Verificar content-type
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('Respuesta no es JSON:', contentType);
                return response.text().then(text => {
                    console.error('Respuesta texto:', text);
                    throw new Error('La respuesta del servidor no es JSON válido');
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Datos recibidos:', data);
            
            if (data.success) {
                // Validar estructura de datos
                if (!data.materia || !data.configuracion || !Array.isArray(data.estudiantes) || !Array.isArray(data.estudiantes_asignados)) {
                    throw new Error('Estructura de datos inválida en la respuesta');
                }
                
                mostrarEstudiantesEnModal(data);
            } else {
                console.error('Error en respuesta:', data.message);
                mostrarErrorEnModal(data.message || 'Error al cargar estudiantes');
            }
        })
        .catch(error => {
            console.error('Error de conexión:', error);
            
            // Mostrar error más específico
            let mensajeError = 'Error de conexión al cargar estudiantes';
            if (error.message) {
                mensajeError += ': ' + error.message;
            }
            
            mostrarErrorEnModal(mensajeError);
        });
}

/**
 * Función para mostrar error en el modal
 */
function mostrarErrorEnModal(mensaje) {
    document.getElementById('contenidoEstudiantes').innerHTML = `
        <div class="alert alert-danger">
            <h6><i class="bi bi-exclamation-triangle"></i> Error</h6>
            <p>${mensaje}</p>
        </div>
    `;
    
    document.getElementById('loadingEstudiantes').style.display = 'none';
    document.getElementById('contenidoEstudiantes').style.display = 'block';
}

// Variables para la asignación temporal
let estudianteEnAsignacion = null;

/**
 * Función para iniciar asignación de estudiante
 */
function asignarEstudiante(estudianteId) {
    estudianteEnAsignacion = estudianteId;
    document.getElementById('selectorSubgrupo').style.display = 'block';
    
    // Highlight del estudiante seleccionado
    document.querySelectorAll('[data-estudiante-id]').forEach(el => {
        el.classList.remove('bg-warning');
    });
    document.querySelector(`[data-estudiante-id="${estudianteId}"]`).classList.add('bg-warning');
}

/**
 * Función para confirmar asignación
 */
function confirmarAsignacion(subgrupo) {
    if (!estudianteEnAsignacion) return;
    
    const data = {
        accion: 'asignar_estudiante',
        estudiante_id: estudianteEnAsignacion,
        materia_curso_id: materiaCursoActual,
        subgrupo: subgrupo
    };
    
    enviarCambio(data, () => {
        // Recargar contenido del modal
        cargarEstudiantesModal(materiaCursoActual);
        cancelarAsignacion();
    });
}

/**
 * Función para desasignar estudiante
 */
function desasignarEstudiante(asignacionId) {
    if (!confirm('¿Está seguro de que desea quitar este estudiante del subgrupo?')) {
        return;
    }
    
    const data = {
        accion: 'desasignar_estudiante',
        asignacion_id: asignacionId
    };
    
    enviarCambio(data, () => {
        // Recargar contenido del modal
        cargarEstudiantesModal(materiaCursoActual);
    });
}

/**
 * Función para cancelar asignación
 */
function cancelarAsignacion() {
    estudianteEnAsignacion = null;
    document.getElementById('selectorSubgrupo').style.display = 'none';
    
    // Quitar highlight
    document.querySelectorAll('[data-estudiante-id]').forEach(el => {
        el.classList.remove('bg-warning');
    });
}

/**
 * Función para enviar cambios vía AJAX
 */
function enviarCambio(data, callback) {
    fetch('procesar_estudiantes_modal.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            // Mostrar mensaje de éxito temporal
            mostrarMensajeTemporal('Cambio guardado correctamente', 'success');
            if (callback) callback();
        } else {
            mostrarMensajeTemporal(result.message || 'Error al guardar', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarMensajeTemporal('Error de conexión', 'danger');
    });
}

/**
 * Función para mostrar mensajes temporales
 */
function mostrarMensajeTemporal(mensaje, tipo) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alertDiv.innerHTML = `
        ${mensaje}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Auto-eliminar después de 3 segundos
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.parentNode.removeChild(alertDiv);
        }
    }, 3000);
}

// Event listener para el botón guardar cambios
document.getElementById('guardarCambiosEstudiantes').addEventListener('click', function() {
    // Cerrar modal y recargar página para ver cambios
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalGestionarEstudiantes'));
    modal.hide();
    
    // Opcional: recargar la página o actualizar la sección específica
    location.reload();
});

// Función para manejar redirección después de procesar formularios
document.addEventListener('DOMContentLoaded', function() {
    // Verificar si hay un formulario enviado que necesita redirección
    const urlParams = new URLSearchParams(window.location.search);
    const redirectAfterPost = '<?= isset($redirectUrl) ? $redirectUrl : "" ?>';
    
    // Si se procesó un POST y hay una URL de redirección, redirigir después de un delay
    if (redirectAfterPost && redirectAfterPost !== window.location.href) {
        setTimeout(function() {
            // Solo redirigir si no estamos ya en la URL correcta
            if (window.location.href !== redirectAfterPost) {
                window.location.href = redirectAfterPost;
            }
        }, 2000); // Dar tiempo para que el usuario vea el mensaje
    }
    
    // Manejo mejorado de formularios para evitar problemas de headers
    const forms = document.querySelectorAll('form[method="POST"]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Agregar indicador de carga
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                const originalText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="bi bi-spinner-border spinner-border-sm"></i> Procesando...';
                
                // Restaurar botón después de un tiempo en caso de error
                setTimeout(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }, 10000);
            }
        });
    });
    
    // Auto-ocultar mensajes de éxito/error después de 5 segundos
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(alert => {
            if (bootstrap && bootstrap.Alert) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }
        });
    }, 5000);
});

// Debug tools (incluir el código del artifact debug_modal_js)
</script>

<style>
/* Estilos específicos para el sistema de subgrupos */
.estudiantes-container {
    background-color: #f8f9fa;
}

.estudiantes-container .form-check:hover {
    background-color: rgba(0,123,255,0.1);
    border-radius: 0.25rem;
    padding: 0.1rem;
}

.accordion-button:not(.collapsed) {
    background-color: rgba(var(--bs-primary-rgb), 0.1);
}

.card.border-start {
    border-left-width: 4px !important;
}

.badge {
    font-size: 0.8em;
}

@media print {
    .btn, .modal, .alert-dismissible .btn-close {
        display: none !important;
    }
    
    .accordion-collapse {
        display: block !important;
    }
    
    .card {
        break-inside: avoid;
        margin-bottom: 1rem;
    }
}

/* Animaciones suaves */
.card, .alert {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Mejoras visuales */
.border-start {
    border-left: 4px solid !important;
}

.fs-6 {
    font-size: 0.875rem !important;
}

.opacity-75 {
    opacity: 0.75 !important;
}

/* Estilos para selección múltiple de estudiantes */
.estudiante-checkbox:checked + label {
    background-color: rgba(13, 110, 253, 0.1);
    border-radius: 0.375rem;
    padding: 0.5rem;
}

.list-group-item:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.list-group-item.bg-warning {
    animation: pulseWarning 2s infinite;
}

@keyframes pulseWarning {
    0% { background-color: #fff3cd; }
    50% { background-color: #ffeaa7; }
    100% { background-color: #fff3cd; }
}

#asignarSeleccionados:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

#asignarSeleccionados:not(:disabled) {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.02); }
    100% { transform: scale(1); }
}

.form-check-input:indeterminate {
    background-color: #6c757d;
    border-color: #6c757d;
}

.spinner-border-sm {
    width: 1rem;
    height: 1rem;
}

/* Efecto visual para estudiantes seleccionados */
.estudiante-checkbox:checked + label .form-check-label {
    font-weight: 600;
    color: #0d6efd;
}

/* Mejorar contraste de badges */
.badge.bg-light.text-dark {
    background-color: #e9ecef !important;
    border: 1px solid #dee2e6;
}

/* Animación para el selector de subgrupos */
#selectorSubgrupo {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Estilos responsivos */
@media (max-width: 768px) {
    #asignarSeleccionados {
        font-size: 0.875rem;
        padding: 0.375rem 0.75rem;
    }
    
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        margin-bottom: 0.5rem;
        border-radius: 0.375rem !important;
    }
}
</style>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>
