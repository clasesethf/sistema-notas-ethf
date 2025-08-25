<?php
/**
 * asistencia_grilla_completa.php - Sistema de Asistencia Completo
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * CARACTERÍSTICAS AVANZADAS:
 * - Manejo de materias simultáneas (subgrupos)
 * - Estudiantes recursantes
 * - Materias liberadas por estudiante
 * - Materia especial "Liberados 7" sin horario
 * - Registro dual: día completo + materia/hora específica
 */

require_once 'config.php';
require_once 'header.php';
require_once 'sistema_periodos_automaticos.php';

// Verificar permisos
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'preceptor'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Crear tablas adicionales necesarias
try {
    // Tabla para subgrupos de materias
    $db->query("CREATE TABLE IF NOT EXISTS materias_subgrupos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        materia_principal_id INTEGER NOT NULL,
        nombre_subgrupo VARCHAR(100) NOT NULL,
        descripcion TEXT,
        activo BOOLEAN DEFAULT 1,
        FOREIGN KEY (materia_principal_id) REFERENCES materias(id),
        UNIQUE(materia_principal_id, nombre_subgrupo)
    )");
    
    // Tabla para asignación de estudiantes a subgrupos
    $db->query("CREATE TABLE IF NOT EXISTS estudiantes_subgrupos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estudiante_id INTEGER NOT NULL,
        subgrupo_id INTEGER NOT NULL,
        fecha_asignacion DATE DEFAULT CURRENT_DATE,
        activo BOOLEAN DEFAULT 1,
        FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
        FOREIGN KEY (subgrupo_id) REFERENCES materias_subgrupos(id),
        UNIQUE(estudiante_id, subgrupo_id)
    )");
    
    // Tabla para materias liberadas por estudiante
    $db->query("CREATE TABLE IF NOT EXISTS estudiantes_liberados (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estudiante_id INTEGER NOT NULL,
        materia_id INTEGER NOT NULL,
        fecha_liberacion DATE NOT NULL,
        motivo VARCHAR(200),
        observaciones TEXT,
        activo BOOLEAN DEFAULT 1,
        FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
        FOREIGN KEY (materia_id) REFERENCES materias(id),
        UNIQUE(estudiante_id, materia_id)
    )");
    
    // Tabla para estudiantes recursantes
    $db->query("CREATE TABLE IF NOT EXISTS estudiantes_recursantes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estudiante_id INTEGER NOT NULL,
        materia_id INTEGER NOT NULL,
        curso_origen_id INTEGER NOT NULL,
        curso_destino_id INTEGER NOT NULL,
        ciclo_lectivo_id INTEGER NOT NULL,
        fecha_asignacion DATE DEFAULT CURRENT_DATE,
        activo BOOLEAN DEFAULT 1,
        FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
        FOREIGN KEY (materia_id) REFERENCES materias(id),
        FOREIGN KEY (curso_origen_id) REFERENCES cursos(id),
        FOREIGN KEY (curso_destino_id) REFERENCES cursos(id),
        FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
    )");
    
    // Actualizar tabla de horarios para incluir subgrupos
    $db->query("ALTER TABLE horarios ADD COLUMN subgrupo_id INTEGER DEFAULT NULL");
    $db->query("ALTER TABLE horarios ADD COLUMN es_simultanea BOOLEAN DEFAULT 0");
    
    // Actualizar tabla de asistencias_materias para incluir subgrupos
    $db->query("ALTER TABLE asistencias_materias ADD COLUMN subgrupo_id INTEGER DEFAULT NULL");
    
} catch (Exception $e) {
    // Las columnas pueden ya existir, continuar
    error_log('Advertencia al crear/actualizar tablas: ' . $e->getMessage());
}

// Definir motivos de ausencia
$motivosAusencia = [
    'no_computa' => [
        'salida_educativa' => 'Salida educativa',
        'acto_academico' => 'Acto académico',
        'representacion_institucional' => 'Representación institucional',
        'suspension_clases' => 'Suspensión de clases',
        'feria_ciencias' => 'Feria de ciencias',
        'olimpiadas' => 'Olimpiadas/competencias',
        'actividad_institucional' => 'Actividad institucional',
        'campamento' => 'Campamento educativo',
        'liberados_7' => 'Período Liberados 7'
    ],
    'justificada' => [
        'enfermedad' => 'Enfermedad',
        'consulta_medica' => 'Consulta médica',
        'tramite_familiar' => 'Trámite familiar',
        'duelo_familiar' => 'Duelo familiar',
        'problema_transporte' => 'Problema de transporte',
        'emergencia_familiar' => 'Emergencia familiar',
        'retiro_temprano' => 'Retiro temprano',
        'llegada_tardia' => 'Llegada tardía',
        'otro_justificado' => 'Otro motivo justificado'
    ]
];

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    if (!$cicloActivo) {
        echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
    
    $fechaActual = date('Y-m-d');
    $periodoActual = SistemaPeriodos::detectarPeriodo($fechaActual, $anioActivo);
    $cuatrimestreActual = $periodoActual['cuatrimestre'];
    $periodoNombre = $periodoActual['periodo_nombre'];
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al conectar con la base de datos: ' . $e->getMessage() . '</div>';
    exit;
}

// Obtener cursos
$cursos = [];
try {
    $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los cursos: ' . $e->getMessage() . '</div>';
}

// Procesar parámetros
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$fechaSeleccionada = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$mostrarLiberados = isset($_GET['liberados']) ? $_GET['liberados'] == '1' : false;

// Nombres de días
$nombresDias = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
    5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'
];

// Variables para datos
$estudiantes = [];
$horarioDia = [];
$asistenciasDiarias = [];
$asistenciasMaterias = [];
$estudiantesLiberados = [];
$estudiantesRecursantes = [];

// Función para obtener estudiantes del curso (incluyendo recursantes y excluyendo liberados)
function obtenerEstudiantesCurso($db, $cursoId, $fechaSeleccionada, $cicloLectivoId) {
    // Estudiantes regulares del curso
    $estudiantesRegulares = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, 'regular' as tipo_estudiante, ? as curso_asistencia_id
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
        [$cursoId, $cursoId]
    );
    
    // Estudiantes recursantes que van a este curso para alguna materia
    $estudiantesRecursantes = $db->fetchAll(
        "SELECT DISTINCT u.id, u.nombre, u.apellido, u.dni, 'recursante' as tipo_estudiante, er.curso_destino_id as curso_asistencia_id
         FROM usuarios u 
         JOIN estudiantes_recursantes er ON u.id = er.estudiante_id 
         WHERE er.curso_destino_id = ? AND er.ciclo_lectivo_id = ? AND er.activo = 1",
        [$cursoId, $cicloLectivoId]
    );
    
    // Combinar ambos grupos
    $todosEstudiantes = array_merge($estudiantesRegulares, $estudiantesRecursantes);
    
    return $todosEstudiantes;
}

// Función para verificar si un estudiante está liberado de una materia
function estaLiberado($db, $estudianteId, $materiaId, $fechaSeleccionada) {
    $liberado = $db->fetchOne(
        "SELECT id FROM estudiantes_liberados 
         WHERE estudiante_id = ? AND materia_id = ? AND activo = 1 
         AND fecha_liberacion <= ?",
        [$estudianteId, $materiaId, $fechaSeleccionada]
    );
    
    return $liberado !== null;
}

// Función para obtener horarios del día con subgrupos
function obtenerHorariosDia($db, $cursoId, $diaSemana) {
    return $db->fetchAll(
        "SELECT h.*, m.nombre as materia_nombre, m.id as materia_id,
                ms.nombre_subgrupo, ms.id as subgrupo_id,
                CASE WHEN ms.id IS NOT NULL THEN CONCAT(m.nombre, ' - ', ms.nombre_subgrupo) 
                     ELSE m.nombre END as nombre_completo
         FROM horarios h 
         JOIN materias m ON h.materia_id = m.id 
         LEFT JOIN materias_subgrupos ms ON h.subgrupo_id = ms.id
         WHERE h.curso_id = ? AND h.dia_semana = ? AND h.activo = 1 
         ORDER BY h.hora_inicio, m.nombre, ms.nombre_subgrupo",
        [$cursoId, $diaSemana]
    );
}

// Función para verificar si un estudiante pertenece a un subgrupo
function perteneceSubgrupo($db, $estudianteId, $subgrupoId) {
    if (!$subgrupoId) return true; // Si no hay subgrupo, aplica a todos
    
    $pertenece = $db->fetchOne(
        "SELECT id FROM estudiantes_subgrupos 
         WHERE estudiante_id = ? AND subgrupo_id = ? AND activo = 1",
        [$estudianteId, $subgrupoId]
    );
    
    return $pertenece !== null;
}

// Si se seleccionó un curso y fecha
if ($cursoSeleccionado && $fechaSeleccionada) {
    try {
        // Obtener estudiantes del curso (regulares + recursantes)
        $estudiantes = obtenerEstudiantesCurso($db, $cursoSeleccionado, $fechaSeleccionada, $cicloLectivoId);
        
        // Obtener día de la semana
        $diaSemana = date('N', strtotime($fechaSeleccionada)); // 1=lunes, 7=domingo
        
        // Obtener horarios del día con subgrupos
        $horarioDia = obtenerHorariosDia($db, $cursoSeleccionado, $diaSemana);
        
        // Si se solicita mostrar "Liberados 7", agregar entrada especial
        if ($mostrarLiberados) {
            // Verificar si existe la materia "Liberados 7"
            $materiaLiberados = $db->fetchOne(
                "SELECT id, nombre FROM materias WHERE nombre LIKE '%Liberados%' OR codigo = 'LIB7'"
            );
            
            if (!$materiaLiberados) {
                // Crear materia "Liberados 7" si no existe
                $db->query(
                    "INSERT INTO materias (nombre, codigo, curso_id, activa) VALUES (?, ?, ?, 1)",
                    ['Liberados 7', 'LIB7', $cursoSeleccionado]
                );
                $materiaLiberadosId = $db->lastInsertId();
                $materiaLiberados = ['id' => $materiaLiberadosId, 'nombre' => 'Liberados 7'];
            }
            
            // Agregar "Liberados 7" al horario
            $horarioDia[] = [
                'id' => 'liberados_7',
                'materia_id' => $materiaLiberados['id'],
                'materia_nombre' => $materiaLiberados['nombre'],
                'nombre_completo' => 'Liberados 7',
                'hora_inicio' => '00:00',
                'hora_fin' => '23:59',
                'subgrupo_id' => null,
                'aula' => 'Aula de Estudio',
                'es_simultanea' => 0
            ];
        }
        
        // Obtener asistencias existentes si hay estudiantes
        if (!empty($estudiantes)) {
            $estudiantesIds = array_column($estudiantes, 'id');
            $placeholders = implode(',', array_fill(0, count($estudiantesIds), '?'));
            
            // Asistencias diarias
            $asistenciasDiariasData = $db->fetchAll(
                "SELECT * FROM asistencias_diarias 
                 WHERE fecha = ? AND curso_id = ? AND estudiante_id IN ($placeholders)",
                array_merge([$fechaSeleccionada, $cursoSeleccionado], $estudiantesIds)
            );
            
            foreach ($asistenciasDiariasData as $asistencia) {
                $asistenciasDiarias[$asistencia['estudiante_id']] = $asistencia;
            }
            
            // Asistencias por materia
            $asistenciasMateriasData = $db->fetchAll(
                "SELECT * FROM asistencias_materias 
                 WHERE fecha = ? AND curso_id = ? AND estudiante_id IN ($placeholders)",
                array_merge([$fechaSeleccionada, $cursoSeleccionado], $estudiantesIds)
            );
            
            foreach ($asistenciasMateriasData as $asistencia) {
                $key = $asistencia['estudiante_id'] . '_' . $asistencia['materia_id'] . '_' . $asistencia['hora_inicio'] . '_' . ($asistencia['subgrupo_id'] ?? '0');
                $asistenciasMaterias[$key] = $asistencia;
            }
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_asistencias'])) {
    try {
        $db->transaction(function($db) use ($cursoSeleccionado, $fechaSeleccionada, $cuatrimestreActual, $horarioDia) {
            foreach ($_POST['estudiantes'] as $estudianteId => $datos) {
                // Procesar asistencia diaria
                $estadoDiario = $datos['estado_diario'] ?? 'presente';
                $motivoDiario = $datos['motivo_diario'] ?? '';
                $motivoDetalleDiario = $datos['motivo_detalle_diario'] ?? '';
                $computaFaltaDiaria = isset($datos['computa_falta_diaria']) ? 1 : 0;
                $observacionesDiarias = $datos['observaciones_diarias'] ?? '';
                
                // Guardar/actualizar asistencia diaria
                $existeDiaria = $db->fetchOne(
                    "SELECT id FROM asistencias_diarias WHERE estudiante_id = ? AND fecha = ?",
                    [$estudianteId, $fechaSeleccionada]
                );
                
                if ($existeDiaria) {
                    $db->query(
                        "UPDATE asistencias_diarias SET 
                         estado = ?, motivo = ?, motivo_detalle = ?, computa_falta = ?, 
                         observaciones = ?, cuatrimestre = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?",
                        [$estadoDiario, $motivoDiario, $motivoDetalleDiario, $computaFaltaDiaria, 
                         $observacionesDiarias, $cuatrimestreActual, $existeDiaria['id']]
                    );
                } else {
                    $db->query(
                        "INSERT INTO asistencias_diarias 
                         (estudiante_id, curso_id, fecha, estado, motivo, motivo_detalle, 
                          computa_falta, observaciones, cuatrimestre)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$estudianteId, $cursoSeleccionado, $fechaSeleccionada, $estadoDiario, 
                         $motivoDiario, $motivoDetalleDiario, $computaFaltaDiaria, 
                         $observacionesDiarias, $cuatrimestreActual]
                    );
                }
                
                // Procesar asistencias por materia
                if (isset($datos['materias'])) {
                    foreach ($datos['materias'] as $horarioKey => $datosMateria) {
                        // Buscar información del horario
                        $horarioInfo = null;
                        foreach ($horarioDia as $h) {
                            $key = is_numeric($h['id']) ? $h['id'] : $horarioKey;
                            if ($key == $horarioKey) {
                                $horarioInfo = $h;
                                break;
                            }
                        }
                        
                        if (!$horarioInfo) continue;
                        
                        $estadoMateria = $datosMateria['estado'] ?? 'presente';
                        $motivoMateria = $datosMateria['motivo'] ?? '';
                        $motivoDetalleMateria = $datosMateria['motivo_detalle'] ?? '';
                        $computaFaltaMateria = isset($datosMateria['computa_falta']) ? 1 : 0;
                        $observacionesMateria = $datosMateria['observaciones'] ?? '';
                        
                        // Guardar/actualizar asistencia por materia
                        $existeMateria = $db->fetchOne(
                            "SELECT id FROM asistencias_materias 
                             WHERE estudiante_id = ? AND materia_id = ? AND fecha = ? AND hora_inicio = ? AND (subgrupo_id = ? OR (subgrupo_id IS NULL AND ? IS NULL))",
                            [$estudianteId, $horarioInfo['materia_id'], $fechaSeleccionada, $horarioInfo['hora_inicio'], $horarioInfo['subgrupo_id'], $horarioInfo['subgrupo_id']]
                        );
                        
                        if ($existeMateria) {
                            $db->query(
                                "UPDATE asistencias_materias SET 
                                 estado = ?, motivo = ?, motivo_detalle = ?, computa_falta = ?, 
                                 observaciones = ?, cuatrimestre = ?, updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?",
                                [$estadoMateria, $motivoMateria, $motivoDetalleMateria, $computaFaltaMateria, 
                                 $observacionesMateria, $cuatrimestreActual, $existeMateria['id']]
                            );
                        } else {
                            $db->query(
                                "INSERT INTO asistencias_materias 
                                 (estudiante_id, curso_id, materia_id, fecha, hora_inicio, hora_fin, 
                                  estado, motivo, motivo_detalle, computa_falta, observaciones, cuatrimestre, subgrupo_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [$estudianteId, $cursoSeleccionado, $horarioInfo['materia_id'], $fechaSeleccionada, 
                                 $horarioInfo['hora_inicio'], $horarioInfo['hora_fin'], $estadoMateria, $motivoMateria, 
                                 $motivoDetalleMateria, $computaFaltaMateria, $observacionesMateria, $cuatrimestreActual, $horarioInfo['subgrupo_id']]
                            );
                        }
                    }
                }
            }
        });
        
        $_SESSION['message'] = 'Asistencias guardadas correctamente';
        $_SESSION['message_type'] = 'success';
        
        $liberadosParam = $mostrarLiberados ? '&liberados=1' : '';
        header("Location: asistencia_grilla_completa.php?curso=$cursoSeleccionado&fecha=$fechaSeleccionada$liberadosParam");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al guardar asistencias: ' . $e->getMessage() . '</div>';
    }
}
?>

<style>
.grilla-asistencia {
    font-size: 0.8rem;
}

.btn-estado {
    padding: 0.2rem 0.4rem;
    font-size: 0.7rem;
    margin: 1px;
}

.estado-presente { background-color: #d4edda; border-color: #c3e6cb; }
.estado-ausente { background-color: #f8d7da; border-color: #f5c6cb; }
.estado-justificada { background-color: #cce5ff; border-color: #b8daff; }
.estado-no_computa { background-color: #e7f3ff; border-color: #b8daff; }

.materia-cell {
    min-width: 120px;
    max-width: 150px;
    padding: 0.4rem;
    border: 1px solid #dee2e6;
    text-align: center;
    vertical-align: middle;
}

.estudiante-cell {
    position: sticky;
    left: 0;
    background-color: #f8f9fa;
    border-right: 2px solid #dee2e6;
    min-width: 200px;
    max-width: 200px;
    z-index: 10;
}

.estado-dia-cell {
    position: sticky;
    left: 200px;
    background-color: #f8f9fa;
    border-right: 2px solid #dee2e6;
    min-width: 120px;
    max-width: 120px;
    z-index: 9;
}

.horario-header {
    position: sticky;
    top: 0;
    background-color: #e9ecef;
    z-index: 20;
}

.grilla-container {
    overflow-x: auto;
    max-height: 80vh;
    border: 1px solid #dee2e6;
}

.estudiante-recursante {
    background-color: #fff3cd !important;
    border-left: 4px solid #ffc107;
}

.estudiante-liberado {
    background-color: #f0f0f0 !important;
    color: #6c757d;
}

.materia-liberados {
    background-color: #e7f3ff !important;
    border: 2px dashed #0066cc;
}

.subgrupo-badge {
    font-size: 0.6rem;
    padding: 0.1rem 0.3rem;
}

.btn-group-vertical .btn {
    border-radius: 0;
    font-size: 0.65rem;
    padding: 0.15rem 0.3rem;
}

.campo-extra {
    margin-top: 0.3rem;
}

.campo-extra input, .campo-extra select {
    font-size: 0.7rem;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">
                        <i class="bi bi-grid"></i> 
                        Sistema de Asistencia Completo - Ciclo Lectivo <?= $anioActivo ?>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Filtros -->
                    <form method="GET" action="asistencia_grilla_completa.php" class="row g-3 mb-4">
                        <div class="col-md-5">
                            <label for="curso" class="form-label">Curso:</label>
                            <select name="curso" id="curso" class="form-select" required>
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                    <?= $curso['nombre'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="fecha" class="form-label">Fecha:</label>
                            <input type="date" name="fecha" id="fecha" class="form-control" 
                                   value="<?= $fechaSeleccionada ?>" required>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Opciones:</label>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="liberados" name="liberados" value="1" 
                                       <?= $mostrarLiberados ? 'checked' : '' ?>>
                                <label class="form-check-label" for="liberados">
                                    Mostrar Liberados 7
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Cargar
                            </button>
                        </div>
                    </form>
                    
                    <?php if ($cursoSeleccionado && $fechaSeleccionada && !empty($estudiantes) && !empty($horarioDia)): ?>
                    
                    <!-- Información del contexto -->
                    <?php 
                    $nombreCurso = '';
                    foreach ($cursos as $curso) {
                        if ($curso['id'] == $cursoSeleccionado) {
                            $nombreCurso = $curso['nombre'];
                            break;
                        }
                    }
                    
                    $diaSemana = date('N', strtotime($fechaSeleccionada));
                    
                    // Contar tipos de estudiantes
                    $regulares = array_filter($estudiantes, function($e) { return $e['tipo_estudiante'] == 'regular'; });
                    $recursantes = array_filter($estudiantes, function($e) { return $e['tipo_estudiante'] == 'recursante'; });
                    ?>
                    
                    <div class="alert alert-info mb-4">
                        <div class="row">
                            <div class="col-md-8">
                                <strong>Registro de asistencia:</strong>
                                <?= $nombreCurso ?> - <?= $nombresDias[$diaSemana] ?> <?= date('d/m/Y', strtotime($fechaSeleccionada)) ?>
                                | Período: <?= $periodoNombre ?>
                                <br><small>
                                    Estudiantes regulares: <?= count($regulares) ?> | 
                                    Recursantes: <?= count($recursantes) ?> | 
                                    Materias del día: <?= count($horarioDia) ?>
                                    <?= $mostrarLiberados ? ' (incluyendo Liberados 7)' : '' ?>
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="gestion_subgrupos.php?curso=<?= $cursoSeleccionado ?>" class="btn btn-outline-warning">
                                        <i class="bi bi-people"></i> Gestionar Subgrupos
                                    </a>
                                    <a href="gestion_liberados.php?curso=<?= $cursoSeleccionado ?>" class="btn btn-outline-info">
                                        <i class="bi bi-shield-check"></i> Gestionar Liberados
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Formulario principal -->
                    <form method="POST" action="asistencia_grilla_completa.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= $fechaSeleccionada ?><?= $mostrarLiberados ? '&liberados=1' : '' ?>">
                        
                        <!-- Botones de acción rápida -->
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="btn-toolbar" role="toolbar">
                                    <div class="btn-group me-2" role="group">
                                        <button type="button" class="btn btn-success btn-sm" onclick="marcarTodosPresentes()">
                                            <i class="bi bi-check-all"></i> Todos Presentes
                                        </button>
                                        <button type="button" class="btn btn-warning btn-sm" onclick="marcarTodosAusentes()">
                                            <i class="bi bi-x-circle"></i> Todos Ausentes
                                        </button>
                                        <button type="button" class="btn btn-info btn-sm" onclick="copiarEstadoDia()">
                                            <i class="bi bi-arrow-right"></i> Copiar Estado del Día
                                        </button>
                                    </div>
                                    <div class="btn-group" role="group">
                                        <button type="submit" name="guardar_asistencias" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Guardar Asistencias
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Grilla de asistencia -->
                        <div class="grilla-container">
                            <table class="table table-sm grilla-asistencia mb-0">
                                <thead class="table-dark horario-header">
                                    <tr>
                                        <th class="estudiante-cell">
                                            Estudiante
                                            <br><small>
                                                <span class="badge bg-success">Regular</span>
                                                <span class="badge bg-warning text-dark">Recursante</span>
                                            </small>
                                        </th>
                                        <th class="estado-dia-cell">Estado del Día</th>
                                        <?php foreach ($horarioDia as $horario): ?>
                                        <th class="materia-cell <?= $horario['id'] === 'liberados_7' ? 'materia-liberados' : '' ?>">
                                            <div><strong><?= $horario['nombre_completo'] ?></strong></div>
                                            <?php if ($horario['id'] !== 'liberados_7'): ?>
                                            <small><?= $horario['hora_inicio'] ?> - <?= $horario['hora_fin'] ?></small>
                                            <?php if ($horario['aula']): ?>
                                            <br><small class="text-muted">Aula: <?= $horario['aula'] ?></small>
                                            <?php endif; ?>
                                            <?php if ($horario['subgrupo_id']): ?>
                                            <br><span class="badge bg-info subgrupo-badge"><?= $horario['nombre_subgrupo'] ?></span>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <small class="text-primary">Sin horario fijo</small>
                                            <?php endif; ?>
                                        </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estudiantes as $estudiante): ?>
                                    <?php 
                                    $asistenciaDiaria = isset($asistenciasDiarias[$estudiante['id']]) ? $asistenciasDiarias[$estudiante['id']] : null;
                                    $estadoDiario = $asistenciaDiaria ? $asistenciaDiaria['estado'] : 'presente';
                                    ?>
                                    <tr id="row_<?= $estudiante['id'] ?>" class="<?= $estudiante['tipo_estudiante'] === 'recursante' ? 'estudiante-recursante' : '' ?>">
                                        <!-- Columna del estudiante -->
                                        <td class="estudiante-cell">
                                            <strong><?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?></strong>
                                            <br><small class="text-muted">DNI: <?= $estudiante['dni'] ?></small>
                                            <?php if ($estudiante['tipo_estudiante'] === 'recursante'): ?>
                                            <br><span class="badge bg-warning text-dark">Recursante</span>
                                            <?php else: ?>
                                            <br><span class="badge bg-success">Regular</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Columna del estado del día -->
                                        <td class="estado-dia-cell">
                                            <div class="btn-group-vertical w-100" role="group">
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][estado_diario]" 
                                                       id="dia_presente_<?= $estudiante['id'] ?>" value="presente"
                                                       <?= $estadoDiario == 'presente' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-success btn-estado" 
                                                       for="dia_presente_<?= $estudiante['id'] ?>">Presente</label>
                                                
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][estado_diario]" 
                                                       id="dia_ausente_<?= $estudiante['id'] ?>" value="ausente"
                                                       <?= $estadoDiario == 'ausente' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-danger btn-estado" 
                                                       for="dia_ausente_<?= $estudiante['id'] ?>">Ausente</label>
                                                
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][estado_diario]" 
                                                       id="dia_justificada_<?= $estudiante['id'] ?>" value="justificada"
                                                       <?= $estadoDiario == 'justificada' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-info btn-estado" 
                                                       for="dia_justificada_<?= $estudiante['id'] ?>">Justificada</label>
                                                
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][estado_diario]" 
                                                       id="dia_no_computa_<?= $estudiante['id'] ?>" value="no_computa"
                                                       <?= $estadoDiario == 'no_computa' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-primary btn-estado" 
                                                       for="dia_no_computa_<?= $estudiante['id'] ?>">No Computa</label>
                                            </div>
                                            
                                            <!-- Campos adicionales para el día -->
                                            <div class="campo-extra">
                                                <select name="estudiantes[<?= $estudiante['id'] ?>][motivo_diario]" 
                                                        class="form-select form-select-sm mb-1">
                                                    <option value="">-- Motivo --</option>
                                                    <optgroup label="No computa">
                                                        <?php foreach ($motivosAusencia['no_computa'] as $codigo => $descripcion): ?>
                                                        <option value="<?= $codigo ?>" 
                                                                <?= ($asistenciaDiaria && $asistenciaDiaria['motivo'] == $codigo) ? 'selected' : '' ?>>
                                                            <?= $descripcion ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                    <optgroup label="Justificada">
                                                        <?php foreach ($motivosAusencia['justificada'] as $codigo => $descripcion): ?>
                                                        <option value="<?= $codigo ?>"
                                                                <?= ($asistenciaDiaria && $asistenciaDiaria['motivo'] == $codigo) ? 'selected' : '' ?>>
                                                            <?= $descripcion ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                </select>
                                                
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" 
                                                           name="estudiantes[<?= $estudiante['id'] ?>][computa_falta_diaria]" 
                                                           id="computa_dia_<?= $estudiante['id'] ?>"
                                                           <?= ($asistenciaDiaria && $asistenciaDiaria['computa_falta']) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="computa_dia_<?= $estudiante['id'] ?>">
                                                        <small>Computa falta</small>
                                                    </label>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- Columnas de materias por hora -->
                                        <?php foreach ($horarioDia as $horario): ?>
                                        <?php 
                                        $horarioKey = is_numeric($horario['id']) ? $horario['id'] : $horario['id'];
                                        $asistenciaKey = $estudiante['id'] . '_' . $horario['materia_id'] . '_' . $horario['hora_inicio'] . '_' . ($horario['subgrupo_id'] ?? '0');
                                        $asistenciaMateria = isset($asistenciasMaterias[$asistenciaKey]) ? 
                                                           $asistenciasMaterias[$asistenciaKey] : null;
                                        $estadoMateria = $asistenciaMateria ? $asistenciaMateria['estado'] : 'presente';
                                        
                                        // Verificar si el estudiante está liberado de esta materia
                                        $estaLiberadoMateria = estaLiberado($db, $estudiante['id'], $horario['materia_id'], $fechaSeleccionada);
                                        
                                        // Verificar si pertenece al subgrupo (si aplica)
                                        $perteneceAlSubgrupo = perteneceSubgrupo($db, $estudiante['id'], $horario['subgrupo_id']);
                                        
                                        // Si no pertenece al subgrupo o está liberado, mostrar celda deshabilitada
                                        $celdaDeshabilitada = !$perteneceAlSubgrupo || $estaLiberadoMateria;
                                        ?>
                                        <td class="materia-cell <?= $celdaDeshabilitada ? 'estudiante-liberado' : '' ?> <?= $horario['id'] === 'liberados_7' ? 'materia-liberados' : '' ?>">
                                            <?php if ($celdaDeshabilitada): ?>
                                                <div class="text-center">
                                                    <?php if ($estaLiberadoMateria): ?>
                                                    <small class="text-muted">LIBERADO</small>
                                                    <?php else: ?>
                                                    <small class="text-muted">OTRO SUBGRUPO</small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                            <div class="btn-group-vertical w-100" role="group">
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][materias][<?= $horarioKey ?>][estado]" 
                                                       id="mat_presente_<?= $estudiante['id'] ?>_<?= $horarioKey ?>" value="presente"
                                                       <?= $estadoMateria == 'presente' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-success btn-estado" 
                                                       for="mat_presente_<?= $estudiante['id'] ?>_<?= $horarioKey ?>">P</label>
                                                
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][materias][<?= $horarioKey ?>][estado]" 
                                                       id="mat_ausente_<?= $estudiante['id'] ?>_<?= $horarioKey ?>" value="ausente"
                                                       <?= $estadoMateria == 'ausente' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-danger btn-estado" 
                                                       for="mat_ausente_<?= $estudiante['id'] ?>_<?= $horarioKey ?>">A</label>
                                                
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][materias][<?= $horarioKey ?>][estado]" 
                                                       id="mat_justificada_<?= $estudiante['id'] ?>_<?= $horarioKey ?>" value="justificada"
                                                       <?= $estadoMateria == 'justificada' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-info btn-estado" 
                                                       for="mat_justificada_<?= $estudiante['id'] ?>_<?= $horarioKey ?>">J</label>
                                                
                                                <input type="radio" class="btn-check" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][materias][<?= $horarioKey ?>][estado]" 
                                                       id="mat_no_computa_<?= $estudiante['id'] ?>_<?= $horarioKey ?>" value="no_computa"
                                                       <?= $estadoMateria == 'no_computa' ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-primary btn-estado" 
                                                       for="mat_no_computa_<?= $estudiante['id'] ?>_<?= $horarioKey ?>">NC</label>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Leyenda y ayuda -->
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="alert alert-light">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <strong>Leyenda de Estados:</strong><br>
                                            <span class="badge bg-success">P = Presente</span>
                                            <span class="badge bg-danger">A = Ausente</span>
                                            <span class="badge bg-info">J = Justificada</span>
                                            <span class="badge bg-primary">NC = No Computa</span>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Tipos de Estudiante:</strong><br>
                                            <span class="badge bg-success">Regular</span> Estudiante del curso<br>
                                            <span class="badge bg-warning text-dark">Recursante</span> Recursa materias en este curso
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Estados Especiales:</strong><br>
                                            <span class="text-muted">LIBERADO</span> = Materia liberada<br>
                                            <span class="text-muted">OTRO SUBGRUPO</span> = No pertenece al subgrupo<br>
                                            <span class="border border-primary border-2">Liberados 7</span> = Sin horario fijo
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </form>
                    
                    <?php elseif ($cursoSeleccionado && $fechaSeleccionada && empty($horarioDia)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No hay horarios configurados para este curso en este día de la semana.
                        <a href="gestion_horarios.php?curso=<?= $cursoSeleccionado ?>" class="alert-link">
                            <i class="bi bi-gear"></i> Configurar horarios
                        </a>
                    </div>
                    
                    <?php elseif ($cursoSeleccionado && $fechaSeleccionada && empty($estudiantes)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No se encontraron estudiantes matriculados en este curso.
                    </div>
                    
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        Seleccione un curso y fecha para comenzar el registro de asistencias.
                    </div>
                    <?php endif; ?>
                    
                    <!-- Enlaces adicionales -->
                    <div class="text-center mt-4">
                        <div class="btn-group" role="group">
                            <a href="gestion_horarios.php" class="btn btn-outline-secondary">
                                <i class="bi bi-calendar3"></i> Gestionar Horarios
                            </a>
                            <a href="gestion_subgrupos.php" class="btn btn-outline-warning">
                                <i class="bi bi-people"></i> Gestionar Subgrupos
                            </a>
                            <a href="gestion_liberados.php" class="btn btn-outline-info">
                                <i class="bi bi-shield-check"></i> Gestionar Liberados
                            </a>
                            <a href="dashboard_asistencias.php" class="btn btn-outline-primary">
                                <i class="bi bi-graph-up"></i> Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function marcarTodosPresentes() {
    if (confirm('¿Marcar a todos los estudiantes activos como presentes?')) {
        // Marcar estado del día como presente (solo estudiantes activos)
        document.querySelectorAll('input[value="presente"][id*="dia_presente_"]').forEach(input => {
            const row = input.closest('tr');
            if (!row.classList.contains('estudiante-liberado')) {
                input.checked = true;
            }
        });
        
        // Marcar todas las materias como presente (solo celdas activas)
        document.querySelectorAll('input[value="presente"][id*="mat_presente_"]').forEach(input => {
            const cell = input.closest('td');
            if (!cell.classList.contains('estudiante-liberado')) {
                input.checked = true;
            }
        });
    }
}

function marcarTodosAusentes() {
    if (confirm('¿Marcar a todos los estudiantes activos como ausentes?')) {
        // Marcar estado del día como ausente (solo estudiantes activos)
        document.querySelectorAll('input[value="ausente"][id*="dia_ausente_"]').forEach(input => {
            const row = input.closest('tr');
            if (!row.classList.contains('estudiante-liberado')) {
                input.checked = true;
            }
        });
        
        // Marcar todas las materias como ausente (solo celdas activas)
        document.querySelectorAll('input[value="ausente"][id*="mat_ausente_"]').forEach(input => {
            const cell = input.closest('td');
            if (!cell.classList.contains('estudiante-liberado')) {
                input.checked = true;
            }
        });
    }
}

function copiarEstadoDia() {
    if (confirm('¿Copiar el estado del día a todas las materias activas de cada estudiante?')) {
        // Para cada estudiante, copiar su estado del día a todas sus materias activas
        document.querySelectorAll('input[name*="[estado_diario]"]:checked').forEach(radioEstadoDia => {
            const estudianteId = radioEstadoDia.name.match(/\[(\d+)\]/)[1];
            const estadoDia = radioEstadoDia.value;
            
            // Buscar todos los radios de materias para este estudiante (solo celdas activas)
            document.querySelectorAll(`input[name*="estudiantes[${estudianteId}][materias]"][name*="[estado]"]`).forEach(radioMateria => {
                const cell = radioMateria.closest('td');
                if (!cell.classList.contains('estudiante-liberado') && radioMateria.value === estadoDia) {
                    radioMateria.checked = true;
                }
            });
        });
    }
}

// Sincronizar estado del día con materias individuales (solo celdas activas)
document.addEventListener('change', function(e) {
    if (e.target.name && e.target.name.includes('[estado_diario]')) {
        const estudianteId = e.target.name.match(/\[(\d+)\]/)[1];
        const estadoDiario = e.target.value;
        
        // Cambiar todas las materias activas del estudiante al mismo estado
        document.querySelectorAll(`input[name*="estudiantes[${estudianteId}][materias]"][name*="[estado]"]`).forEach(input => {
            const cell = input.closest('td');
            if (!cell.classList.contains('estudiante-liberado') && input.value === estadoDiario) {
                input.checked = true;
            }
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
