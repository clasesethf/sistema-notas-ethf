<?php
/**
 * vista_previa_curso.php - Vista previa de todos los boletines de un curso ACTUALIZADO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * NUEVA FUNCIONALIDAD: Compatible con sistema de grupos de materias
 * ACTUALIZACIÓN 2025: Incluye valoraciones automáticas y lógica de promedio intval()
 * Basado en la Resolución N° 1650/24
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir las funciones de agrupación si están disponibles
if (file_exists('includes/funciones_agrupacion_materias.php')) {
    require_once 'includes/funciones_agrupacion_materias.php';
}

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar parámetros requeridos
if (!isset($_GET['curso']) || !isset($_GET['cuatrimestre'])) {
    $_SESSION['message'] = 'Parámetros incorrectos para la vista previa';
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Obtener parámetros
$cursoId = intval($_GET['curso']);
$cuatrimestre = intval($_GET['cuatrimestre']);

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $_SESSION['message'] = 'No hay un ciclo lectivo activo configurado en el sistema.';
        $_SESSION['message_type'] = 'danger';
        header('Location: boletines.php');
        exit;
    }
    
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al conectar con la base de datos: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Obtener información del curso
try {
    $cursoInfo = $db->fetchOne("SELECT * FROM cursos WHERE id = ? AND ciclo_lectivo_id = ?", [$cursoId, $cicloLectivoId]);
    
    if (!$cursoInfo) {
        $_SESSION['message'] = 'Curso no encontrado.';
        $_SESSION['message_type'] = 'danger';
        header('Location: boletines.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener información del curso: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// Obtener todos los estudiantes del curso
try {
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni, u.direccion, u.telefono
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
         ORDER BY u.apellido, u.nombre",
        [$cursoId]
    );
    
    if (count($estudiantes) == 0) {
        $_SESSION['message'] = 'No se encontraron estudiantes en este curso.';
        $_SESSION['message_type'] = 'warning';
        header('Location: boletines.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['message'] = 'Error al obtener estudiantes: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: boletines.php');
    exit;
}

// COPIAR LAS FUNCIONES DEL SISTEMA PRINCIPAL ACTUALIZADO
/**
 * FUNCIÓN REUTILIZADA: Obtener calificaciones combinadas (copiada desde generar_boletines_curso.php)
 */
function obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio) {
    try {
        $resultado = [];
        
        // Verificar si existen las funciones de agrupación del sistema principal
        $usarFuncionesAgrupacion = function_exists('obtenerGruposMaterias') && 
                                   function_exists('obtenerCalificacionesGruposEstudiante');
        
        if ($usarFuncionesAgrupacion) {
            // INTEGRACIÓN CON SISTEMA PRINCIPAL
            
            // Buscar el curso del estudiante
            $cursoEstudiante = $db->fetchOne(
                "SELECT c.* FROM cursos c
                 JOIN matriculas m ON c.id = m.curso_id
                 WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?",
                [$estudianteId, $cicloLectivoId]
            );
            
            if ($cursoEstudiante) {
                // Obtener grupos usando las funciones del sistema principal
                $grupos = obtenerGruposMaterias($db, $cursoEstudiante['id'], $cicloLectivoId);
                $calificacionesGrupos = obtenerCalificacionesGruposEstudiante($db, $estudianteId, $cicloLectivoId, $cursoEstudiante['id']);
                
                $materiasEnGrupos = [];
                
                foreach ($grupos as $grupo) {
                    $grupoId = $grupo['id'];
                    $calificacionesGrupo = $calificacionesGrupos[$grupoId] ?? [];
                    
                    // Registrar materias que están en grupos
                    if (isset($grupo['materias']) && is_array($grupo['materias'])) {
                        foreach ($grupo['materias'] as $materia) {
                            $materiasEnGrupos[] = $materia['materia_curso_id'];
                        }
                    }
                    
                    // Convertir datos del sistema principal al formato esperado por la vista
                    $grupo['calificaciones_calculadas'] = [
                        'valoracion_preliminar_1c' => null,
                        'calificacion_1c' => null,
                        'valoracion_preliminar_2c' => null,
                        'calificacion_2c' => null,
                        'intensificacion_1c' => null,
                        'intensificacion_diciembre' => null,
                        'intensificacion_febrero' => null,
                        'calificacion_final' => null,
                        'observaciones' => ''
                    ];
                    
                    // Extraer datos de cuatrimestres
                    if (isset($calificacionesGrupo['cuatrimestres'][1])) {
                        $cal1c = $calificacionesGrupo['cuatrimestres'][1];
                        $grupo['calificaciones_calculadas']['calificacion_1c'] = $cal1c['calificacion_final'];
                        
                        // Calcular valoración basada en calificación
                        if ($cal1c['calificacion_final']) {
                            if ($cal1c['calificacion_final'] >= 7) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_1c'] = 'TEA';
                            } elseif ($cal1c['calificacion_final'] >= 4) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_1c'] = 'TEP';
                            } else {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_1c'] = 'TED';
                            }
                        }
                    }
                    
                    if (isset($calificacionesGrupo['cuatrimestres'][2])) {
                        $cal2c = $calificacionesGrupo['cuatrimestres'][2];
                        $grupo['calificaciones_calculadas']['calificacion_2c'] = $cal2c['calificacion_final'];
                        
                        // Calcular valoración basada en calificación
                        if ($cal2c['calificacion_final']) {
                            if ($cal2c['calificacion_final'] >= 7) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_2c'] = 'TEA';
                            } elseif ($cal2c['calificacion_final'] >= 4) {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_2c'] = 'TEP';
                            } else {
                                $grupo['calificaciones_calculadas']['valoracion_preliminar_2c'] = 'TED';
                            }
                        }
                    }
                    
                    // Calcular calificación final si hay ambos cuatrimestres
                    if ($grupo['calificaciones_calculadas']['calificacion_1c'] && 
                        $grupo['calificaciones_calculadas']['calificacion_2c']) {
                        $promedio = ($grupo['calificaciones_calculadas']['calificacion_1c'] + 
                                    $grupo['calificaciones_calculadas']['calificacion_2c']) / 2;
                        $grupo['calificaciones_calculadas']['calificacion_final'] = intval($promedio);
                    }
                    
                    $grupo['es_grupo'] = true;
                    $resultado[] = $grupo;
                }
            } else {
                $materiasEnGrupos = [];
            }
        } else {
            $materiasEnGrupos = [];
        }
        
        // 2. OBTENER MATERIAS INDIVIDUALES CON TODAS LAS VALORACIONES
        $materiasIndividuales = $db->fetchAll(
            "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo, 
                    curso_materia.anio as materia_anio, mp.id as materia_curso_id,
                    -- CORRECCIÓN: Asegurar que se obtengan TODAS las valoraciones y calificaciones
                    c.valoracion_preliminar_1c, c.valoracion_preliminar_2c,
                    c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                    c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                    c.observaciones
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        foreach ($materiasIndividuales as $materia) {
            // Solo incluir si NO está en un grupo
            if (!in_array($materia['materia_curso_id'], $materiasEnGrupos)) {
                $materia['es_grupo'] = false;
                $materia['nombre'] = $materia['materia_nombre']; // Para compatibilidad
                $materia['codigo'] = $materia['materia_codigo'];
                
                // NUEVA FUNCIONALIDAD: Calcular valoraciones faltantes para materias individuales
                $materia = calcularValoracionesFaltantesMateriasIndividuales($materia);
                
                $resultado[] = $materia;
            }
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        throw new Exception("Error al obtener calificaciones combinadas: " . $e->getMessage());
    }
}

/**
 * FUNCIÓN REUTILIZADA: Calcular valoraciones faltantes (copiada desde generar_boletines_curso.php)
 */
function calcularValoracionesFaltantesMateriasIndividuales($materia) {
    // PRIORIDAD 1: Si ya existe valoración preliminar 1° cuatrimestre, NO la sobreescribir
    if (empty($materia['valoracion_preliminar_1c']) && !empty($materia['calificacion_1c']) && is_numeric($materia['calificacion_1c'])) {
        $calificacion1c = intval($materia['calificacion_1c']);
        if ($calificacion1c >= 7) {
            $materia['valoracion_preliminar_1c'] = 'TEA';
        } elseif ($calificacion1c >= 4) {
            $materia['valoracion_preliminar_1c'] = 'TEP';
        } else {
            $materia['valoracion_preliminar_1c'] = 'TED';
        }
    }
    
    // PRIORIDAD 1: Si ya existe valoración preliminar 2° cuatrimestre, NO la sobreescribir
    if (empty($materia['valoracion_preliminar_2c']) && !empty($materia['calificacion_2c']) && is_numeric($materia['calificacion_2c'])) {
        $calificacion2c = intval($materia['calificacion_2c']);
        if ($calificacion2c >= 7) {
            $materia['valoracion_preliminar_2c'] = 'TEA';
        } elseif ($calificacion2c >= 4) {
            $materia['valoracion_preliminar_2c'] = 'TEP';
        } else {
            $materia['valoracion_preliminar_2c'] = 'TED';
        }
    }
    
    return $materia;
}

// FUNCIÓN ACTUALIZADA: Obtener datos completos de un estudiante
function obtenerDatosEstudianteActualizado($db, $estudianteId, $cursoId, $cicloLectivoId, $cursoAnio) {
    $datos = [];
    
    // NUEVA LÓGICA: Usar sistema combinado de grupos + materias individuales
    try {
        $datos['calificaciones_combinadas'] = obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio);
    } catch (Exception $e) {
        // Si falla, usar lógica fallback
        $datos['calificaciones_combinadas'] = [];
        
        // FALLBACK: Obtener materias individuales como antes
        $datos['materias_cursadas'] = $db->fetchAll(
            "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                    c.valoracion_preliminar_1c, c.valoracion_preliminar_2c,
                    c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                    c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                    c.observaciones
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ? AND c.tipo_cursada = 'C'
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        // Aplicar valoraciones automáticas a las materias individuales
        foreach ($datos['materias_cursadas'] as &$materia) {
            $materia = calcularValoracionesFaltantesMateriasIndividuales($materia);
            $materia['es_grupo'] = false;
            $materia['nombre'] = $materia['materia_nombre'];
            $materia['codigo'] = $materia['materia_codigo'];
            $datos['calificaciones_combinadas'][] = $materia;
        }
    }
    
    // Obtener materias recursadas (mantener lógica original)
    $datos['materias_recursadas'] = $db->fetchAll(
        "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                c.valoracion_preliminar_1c, c.valoracion_preliminar_2c,
                c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                c.observaciones
         FROM calificaciones c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ? AND c.tipo_cursada = 'R'
         ORDER BY m.nombre",
        [$estudianteId, $cicloLectivoId]
    );
    
    // Aplicar valoraciones automáticas a recursadas también
    foreach ($datos['materias_recursadas'] as &$materia) {
        $materia = calcularValoracionesFaltantesMateriasIndividuales($materia);
    }
    
    // Obtener materias pendientes (verificar si tabla existe)
    try {
        $datos['materias_pendientes'] = $db->fetchAll(
            "SELECT i.*, m.nombre as materia_nombre, m.codigo as materia_codigo 
             FROM intensificaciones i
             JOIN materias m ON i.materia_id = m.id
             WHERE i.estudiante_id = ? AND i.ciclo_lectivo_id = ?
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
    } catch (Exception $e) {
        // Si la tabla no existe, array vacío
        $datos['materias_pendientes'] = [];
    }
    
    // Obtener asistencias (mantener igual)
    $asistencias = $db->fetchAll(
        "SELECT cuatrimestre, 
                COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas,
                COUNT(CASE WHEN estado = 'justificada' THEN 1 END) as justificadas,
                COUNT(*) as total_dias
         FROM asistencias
         WHERE estudiante_id = ? AND curso_id = ?
         GROUP BY cuatrimestre",
        [$estudianteId, $cursoId]
    );
    
    $datos['asistencias'] = [];
    foreach ($asistencias as $asistencia) {
        $datos['asistencias'][$asistencia['cuatrimestre']] = $asistencia;
    }
    
    return $datos;
}

// Incluir el encabezado
require_once 'header.php';
?>

<style>
    .boletin {
        page-break-after: always;
        margin-bottom: 30px;
        border: 1px solid #ddd;
        padding: 20px;
        background: white;
    }
    
    .boletin:last-child {
        page-break-after: auto;
    }
    
    .header-boletin {
        text-align: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #007bff;
        padding-bottom: 15px;
    }
    
    .datos-estudiante {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    .tabla-calificaciones {
        font-size: 12px;
    }
    
    .tabla-calificaciones th {
        background-color: #007bff;
        color: white;
        text-align: center;
        vertical-align: middle;
        font-size: 10px;
        padding: 5px 2px;
    }
    
    .tabla-calificaciones td {
        text-align: center;
        vertical-align: middle;
        padding: 5px 2px;
        font-size: 11px;
    }
    
    .tabla-asistencias {
        margin-top: 15px;
    }
    
    .referencias {
        margin-top: 20px;
        font-size: 12px;
    }
    
    .firmas {
        margin-top: 30px;
        display: flex;
        justify-content: space-around;
        text-align: center;
    }
    
    .firma-item {
        width: 150px;
    }
    
    .linea-firma {
        border-bottom: 1px solid #000;
        width: 100%;
        height: 1px;
        margin-bottom: 5px;
    }
    
    /* NUEVOS ESTILOS PARA GRUPOS DE MATERIAS */
    .grupo-materias {
        background-color: #e3f2fd;
        font-weight: bold;
    }
    
    .materia-individual {
        background-color: #f8f9fa;
    }
    
    .tipo-grupo {
        color: #1976d2;
        font-weight: bold;
    }
    
    .tipo-individual {
        color: #424242;
    }
    
    @media print {
        .no-print {
            display: none !important;
        }
        
        .boletin {
            border: none;
            margin: 0;
            padding: 15px;
        }
        
        body {
            font-size: 12px;
        }
        
        .grupo-materias {
            background-color: #f0f0f0 !important;
        }
    }
</style>

<div class="container-fluid no-print mb-4">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            Vista Previa - Boletines del Curso: <?= htmlspecialchars($cursoInfo['nombre']) ?>
                        </h5>
                        <div>
                            <button onclick="window.print()" class="btn btn-primary">
                                <i class="bi bi-printer"></i> Imprimir Todo
                            </button>
                            <a href="generar_boletines_curso.php?curso=<?= $cursoId ?>&cuatrimestre=<?= $cuatrimestre ?>" 
                               class="btn btn-success" target="_blank">
                                <i class="bi bi-file-pdf"></i> Generar PDF
                            </a>
                            <a href="boletines.php?curso=<?= $cursoId ?>&cuatrimestre=<?= $cuatrimestre ?>" 
                               class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Volver
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>Información:</strong> 
                        Ciclo Lectivo <?= $anioActivo ?> - <?= $cuatrimestre ?>° Cuatrimestre - 
                        Total de estudiantes: <?= count($estudiantes) ?>
                        <br><small><strong>Sistema actualizado:</strong> Compatible con grupos de materias y valoraciones automáticas</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Generar vista previa para cada estudiante
$estudiantesConDatos = 0;
foreach ($estudiantes as $estudiante) {
    // NUEVA LÓGICA: Obtener datos completos usando sistema combinado
    $datosCompletos = obtenerDatosEstudianteActualizado($db, $estudiante['id'], $cursoId, $cicloLectivoId, $cursoInfo['anio']);
    
    // Solo mostrar boletín si tiene al menos algunas calificaciones
    if (count($datosCompletos['calificaciones_combinadas']) > 0) {
        $estudiantesConDatos++;
?>

<div class="container-fluid boletin">
    <!-- Encabezado del boletín -->
    <div class="header-boletin">
        <h4>ESCUELA TÉCNICA HENRY FORD</h4>
        <h5>REGISTRO INSTITUCIONAL DE TRAYECTORIAS EDUCATIVAS (RITE)</h5>
        <p><strong>Ciclo Lectivo <?= $anioActivo ?> - <?= $cuatrimestre ?>° Cuatrimestre</strong></p>
    </div>

    <!-- Datos del estudiante -->
    <div class="datos-estudiante">
        <div class="row">
            <div class="col-md-6">
                <p><strong>Estudiante:</strong> <?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></p>
                <p><strong>DNI:</strong> <?= htmlspecialchars($estudiante['dni']) ?></p>
                <p><strong>Domicilio:</strong> <?= htmlspecialchars($estudiante['direccion'] ?? 'No registrado') ?></p>
            </div>
            <div class="col-md-6">
                <p><strong>Teléfono:</strong> <?= htmlspecialchars($estudiante['telefono'] ?? 'No registrado') ?></p>
                <p><strong>Curso:</strong> <?= htmlspecialchars($cursoInfo['nombre']) ?></p>
                <p><strong>Año:</strong> <?= htmlspecialchars($cursoInfo['anio']) ?></p>
            </div>
        </div>
    </div>

    <!-- NUEVA TABLA DE CALIFICACIONES COMBINADAS -->
    <div class="table-responsive">
        <table class="table table-bordered tabla-calificaciones">
            <thead>
                <tr>
                    <th rowspan="2">TIPO<br>(C-R)</th>
                    <th rowspan="2">MATERIAS / GRUPOS</th>
                    <th rowspan="2">AÑO</th>
                    <th colspan="2">1° CUATRIMESTRE</th>
                    <th colspan="2">2° CUATRIMESTRE</th>
                    <th colspan="3">INTENSIFICACIÓN</th>
                    <th rowspan="2">CALIF.<br>FINAL</th>
                    <th rowspan="2">OBSERVACIONES</th>
                </tr>
                <tr>
                    <th>1° VAL. PREL.</th>
                    <th>CALIF.</th>
                    <th>2° VAL. PREL.</th>
                    <th>CALIF.</th>
                    <th>INT. 1° CUAT.</th>
                    <th>DICIEMBRE</th>
                    <th>FEBRERO</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($datosCompletos['calificaciones_combinadas']) > 0): ?>
                    <?php foreach ($datosCompletos['calificaciones_combinadas'] as $item): ?>
                    <?php
                    // Determinar el tipo de fila (grupo o individual)
                    $claseFilaEspecial = '';
                    $claseTipo = 'tipo-individual';
                    $tipoCursada = 'C';
                    $anioItem = $cursoInfo['anio'];
                    
                    if ($item['es_grupo']) {
                        // Es un grupo de materias
                        $cal = $item['calificaciones_calculadas'];
                        $claseFilaEspecial = 'grupo-materias';
                        $claseTipo = 'tipo-grupo';
                        $nombreItem = strtoupper($item['nombre']) . ' (' . strtoupper($item['codigo']) . ')';
                    } else {
                        // Es una materia individual
                        $claseFilaEspecial = 'materia-individual';
                        
                        // Determinar el tipo de cursada correcto
                        $anioEstudiante = $cursoInfo['anio'];
                        $anioMateria = $item['materia_anio'] ?? $anioEstudiante;
                        
                        // Si el estudiante está en un año superior al de la materia, es recursada
                        $tipoCursada = ($anioEstudiante > $anioMateria) ? 'R' : 'C';
                        $anioItem = $anioMateria;
                        
                        $nombreItem = strtoupper($item['materia_nombre']) . ' (' . strtoupper($item['materia_codigo']) . ')';
                        $cal = $item; // Para materias individuales, los datos están en el mismo nivel
                    }
                    ?>
                    <tr class="<?= $claseFilaEspecial ?>">
                        <td class="<?= $claseTipo ?>"><?= htmlspecialchars($tipoCursada) ?></td>
                        <td style="text-align: left; font-size: 10px;">
                            <?= htmlspecialchars(substr($nombreItem, 0, 40)) ?>
                            <?php if ($item['es_grupo']): ?>
                                <small class="text-muted"> [GRUPO]</small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($anioItem) ?></td>
                        <td><?= htmlspecialchars($cal['valoracion_preliminar_1c'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['calificacion_1c'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['valoracion_preliminar_2c'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['calificacion_2c'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['intensificacion_1c'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['intensificacion_diciembre'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['intensificacion_febrero'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($cal['calificacion_final'] ?? '-') ?></td>
                        <td style="text-align: left; font-size: 9px;">
                            <?= htmlspecialchars(substr($cal['observaciones'] ?? '-', 0, 25)) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12">No hay materias cursadas registradas.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Materias pendientes (si las hay) -->
    <?php if (!empty($datosCompletos['materias_pendientes'])): ?>
    <div class="mt-4">
        <h6><strong>Materias Pendientes de Aprobación - Intensificación</strong></h6>
        <div class="table-responsive">
            <table class="table table-bordered tabla-calificaciones">
                <thead>
                    <tr>
                        <th>MATERIA</th>
                        <th>MARZO</th>
                        <th>JULIO</th>
                        <th>AGOSTO</th>
                        <th>DICIEMBRE</th>
                        <th>FEBRERO</th>
                        <th>CALIF. FINAL</th>
                        <th>SABERES PENDIENTES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($datosCompletos['materias_pendientes'] as $materia): ?>
                    <tr>
                        <td style="text-align: left; font-size: 10px;">
                            <?= htmlspecialchars(strtoupper($materia['materia_nombre'])) ?> 
                            (<?= htmlspecialchars(strtoupper($materia['materia_codigo'])) ?>)
                        </td>
                        <td><?= htmlspecialchars($materia['estado_marzo'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($materia['estado_julio'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($materia['estado_agosto'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($materia['estado_diciembre'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($materia['estado_febrero'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($materia['calificacion_final'] ?? '-') ?></td>
                        <td style="text-align: left; font-size: 9px;">
                            <?php
                            if (isset($materia['calificacion_final']) && $materia['calificacion_final'] >= 4) {
                                echo 'Aprobada';
                            } else {
                                echo htmlspecialchars(substr($materia['saberes_pendientes_final'] ?? 
                                    ($materia['saberes_pendientes_inicial'] ?? '-'), 0, 30));
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Asistencias -->
    <div class="tabla-asistencias mt-4">
        <h6><strong>Asistencia</strong></h6>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Período</th>
                        <th>Días Hábiles</th>
                        <th>Inasistencias</th>
                        <th>Justificadas</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // 1° Cuatrimestre
                    $ausentes1 = isset($datosCompletos['asistencias'][1]) ? ($datosCompletos['asistencias'][1]['ausentes'] ?? 0) : 0;
                    $mediasFaltas1 = isset($datosCompletos['asistencias'][1]) && isset($datosCompletos['asistencias'][1]['medias_faltas']) ? 
                                     $datosCompletos['asistencias'][1]['medias_faltas'] * 0.5 : 0;
                    $justificadas1 = isset($datosCompletos['asistencias'][1]) ? ($datosCompletos['asistencias'][1]['justificadas'] ?? 0) : 0;
                    $totalInasistencias1 = $ausentes1 + $mediasFaltas1;
                    ?>
                    <tr>
                        <td>1° Cuatrimestre</td>
                        <td><?= isset($datosCompletos['asistencias'][1]) ? $datosCompletos['asistencias'][1]['total_dias'] : 0 ?></td>
                        <td><?= $ausentes1 ?> + <?= $mediasFaltas1 ?></td>
                        <td><?= $justificadas1 ?></td>
                        <td><?= $totalInasistencias1 ?></td>
                    </tr>
                    
                    <?php
                    // 2° Cuatrimestre
                    $ausentes2 = isset($datosCompletos['asistencias'][2]) ? ($datosCompletos['asistencias'][2]['ausentes'] ?? 0) : 0;
                    $mediasFaltas2 = isset($datosCompletos['asistencias'][2]) && isset($datosCompletos['asistencias'][2]['medias_faltas']) ? 
                                     $datosCompletos['asistencias'][2]['medias_faltas'] * 0.5 : 0;
                    $justificadas2 = isset($datosCompletos['asistencias'][2]) ? ($datosCompletos['asistencias'][2]['justificadas'] ?? 0) : 0;
                    $totalInasistencias2 = $ausentes2 + $mediasFaltas2;
                    ?>
                    <tr>
                        <td>2° Cuatrimestre</td>
                        <td><?= isset($datosCompletos['asistencias'][2]) ? $datosCompletos['asistencias'][2]['total_dias'] : 0 ?></td>
                        <td><?= $ausentes2 ?> + <?= $mediasFaltas2 ?></td>
                        <td><?= $justificadas2 ?></td>
                        <td><?= $totalInasistencias2 ?></td>
                    </tr>
                    
                    <?php
                    // Total Ciclo Lectivo
                    $totalDias = (isset($datosCompletos['asistencias'][1]) ? ($datosCompletos['asistencias'][1]['total_dias'] ?? 0) : 0) + 
                                (isset($datosCompletos['asistencias'][2]) ? ($datosCompletos['asistencias'][2]['total_dias'] ?? 0) : 0);
                    $totalAusentes = $ausentes1 + $ausentes2;
                    $totalMediasFaltas = $mediasFaltas1 + $mediasFaltas2;
                    $totalJustificadas = $justificadas1 + $justificadas2;
                    $totalInasistencias = $totalAusentes + $totalMediasFaltas;
                    ?>
                    <tr class="table-secondary">
                        <td><strong>Total Ciclo Lectivo</strong></td>
                        <td><strong><?= $totalDias ?></strong></td>
                        <td><strong><?= $totalAusentes ?> + <?= $totalMediasFaltas ?></strong></td>
                        <td><strong><?= $totalJustificadas ?></strong></td>
                        <td><strong><?= $totalInasistencias ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Referencias -->
    <div class="referencias">
        <h6><strong>Referencias</strong></h6>
        <div class="row">
            <div class="col-md-4">
                <p><strong>Tipo de Cursada:</strong></p>
                <p><small>C: Cursada por primera vez<br>R: Recursada</small></p>
                <p><strong>Organización:</strong></p>
                <p><small>[GRUPO]: Grupo de materias<br>Individual: Materia individual</small></p>
            </div>
            <div class="col-md-4">
                <p><strong>Valoraciones Preliminares:</strong></p>
                <p><small>TEA: Trayectoria Educativa Avanzada<br>
                TEP: Trayectoria Educativa en Proceso<br>
                TED: Trayectoria Educativa Discontinua</small></p>
            </div>
            <div class="col-md-4">
                <p><strong>Estados de Intensificación:</strong></p>
                <p><small>AA: Aprobó y Acreditó<br>
                CCA: Continúa, Con Avances<br>
                CSA: Continúa, Sin Avances</small></p>
            </div>
        </div>
    </div>

    <!-- Firmas -->
    <div class="firmas">
        <div class="firma-item">
            <div class="linea-firma"></div>
            <small>Firma del Directivo</small>
        </div>
        <div class="firma-item">
            <div class="linea-firma"></div>
            <small>Firma del Responsable</small>
        </div>
        <div class="firma-item">
            <div class="linea-firma"></div>
            <small>Firma del Estudiante</small>
        </div>
    </div>
</div>

<?php
    }
}

// Si no hay estudiantes con datos
if ($estudiantesConDatos == 0) {
?>
<div class="container-fluid">
    <div class="alert alert-warning">
        <h5>No hay boletines para mostrar</h5>
        <p>No se encontraron estudiantes con calificaciones registradas en este curso para el cuatrimestre seleccionado.</p>
        <a href="boletines.php?curso=<?= $cursoId ?>" class="btn btn-primary">Volver a Boletines</a>
    </div>
</div>
<?php
}
?>

<?php require_once 'footer.php'; ?>
