<?php
/**
 * asistencias.php - Página de registro y gestión de asistencias (VERSIÓN COMPLETA ACTUALIZADA)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * MEJORAS IMPLEMENTADAS:
 * - Agregado estado "1/4 de falta"
 * - Agregado estado "3/4 de falta"
 * - Agregado estado "no_computa" para salidas institucionales
 * - Campo motivo_falta con opciones predefinidas
 * - Sistema de períodos automáticos integrado
 * - Mejor interfaz de usuario
 * - Validación mejorada
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Incluir el sistema de períodos automáticos
require_once 'sistema_periodos_automaticos.php';

// Verificar permisos (solo admin, directivos y preceptores)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo', 'preceptor'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Definir motivos de ausencia justificada predefinidos
$motivosJustificados = [
    'enfermedad' => 'Enfermedad',
    'tramite_familiar' => 'Trámite familiar',
    'viaje_familiar' => 'Viaje familiar',
    'duelo_familiar' => 'Duelo familiar',
    'consulta_medica' => 'Consulta médica',
    'estudios_medicos' => 'Estudios médicos',
    'problema_transporte' => 'Problema de transporte',
    'emergencia_familiar' => 'Emergencia familiar',
    'actividad_deportiva' => 'Actividad deportiva',
    'nacimiento_hermano' => 'Nacimiento de hermano/a',
    'otro' => 'Otro motivo (especificar)'
];

// Definir motivos para "No computa"
$motivosNoComputa = [
    'salida_institucional' => 'Salida institucional',
    'actividad_escolar' => 'Actividad escolar oficial',
    'representacion_institucional' => 'Representación institucional',
    'acto_escolar' => 'Acto escolar',
    'suspension_clases' => 'Suspensión de clases',
    'feriado_especial' => 'Feriado especial',
    'otro_institucional' => 'Otro motivo institucional'
];

// Verificar si existen las columnas necesarias, si no existen agregarlas
try {
    $columnas = $db->fetchAll("PRAGMA table_info(asistencias)");
    $columnasMapa = [];
    foreach ($columnas as $columna) {
        $columnasMapa[$columna['name']] = true;
    }
    
    if (!isset($columnasMapa['motivo_falta'])) {
        $db->query("ALTER TABLE asistencias ADD COLUMN motivo_falta TEXT DEFAULT NULL");
    }
    
    if (!isset($columnasMapa['motivo_otro'])) {
        $db->query("ALTER TABLE asistencias ADD COLUMN motivo_otro TEXT DEFAULT NULL");
    }
    
    if (!isset($columnasMapa['motivo_no_computa'])) {
        $db->query("ALTER TABLE asistencias ADD COLUMN motivo_no_computa TEXT DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('Error al verificar/agregar columnas: ' . $e->getMessage());
}

// Obtener ciclo lectivo activo - Con verificación de errores
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
        $cicloLectivoId = 0;
        $anioActivo = date('Y');
        $cuatrimestreActual = 1;
        $periodoNombre = '1° Cuatrimestre';
    } else {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
        
        // Usar el sistema de períodos automáticos para determinar el cuatrimestre actual
        $fechaActual = date('Y-m-d');
        $periodoActual = SistemaPeriodos::detectarPeriodo($fechaActual, $anioActivo);
        $cuatrimestreActual = $periodoActual['cuatrimestre'];
        $periodoNombre = $periodoActual['periodo_nombre'];
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al conectar con la base de datos: ' . $e->getMessage() . '</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
    $cuatrimestreActual = 1;
    $periodoNombre = '1° Cuatrimestre';
}

// Obtener cursos
$cursos = [];
try {
    if ($cicloLectivoId > 0) {
        $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener los cursos: ' . $e->getMessage() . '</div>';
}

// Procesar selección de curso y fecha
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$fechaSeleccionada = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');

// Si la fecha seleccionada es anterior a la fecha de inicio del ciclo, establecer la fecha de inicio
if (isset($cicloActivo) && $cicloActivo && (new DateTime($fechaSeleccionada)) < (new DateTime($cicloActivo['fecha_inicio']))) {
    $fechaSeleccionada = $cicloActivo['fecha_inicio'];
}

// Variables para almacenar datos
$estudiantes = [];
$asistencias = [];

// Si se seleccionó un curso
if ($cursoSeleccionado) {
    try {
        // Obtener estudiantes matriculados en el curso
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni 
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
             ORDER BY u.apellido, u.nombre",
            [$cursoSeleccionado]
        );
        
        // Obtener asistencias existentes para la fecha y curso seleccionados
        if (!empty($estudiantes)) {
            $estudiantesIds = array_column($estudiantes, 'id');
            $placeholders = implode(',', array_fill(0, count($estudiantesIds), '?'));
            
            $params = $estudiantesIds;
            array_unshift($params, $fechaSeleccionada, $cursoSeleccionado);
            
            $asistenciasData = $db->fetchAll(
                "SELECT * FROM asistencias 
                 WHERE fecha = ? AND curso_id = ? AND estudiante_id IN ($placeholders)",
                $params
            );
            
            // Indexar asistencias por estudiante_id para fácil acceso
            foreach ($asistenciasData as $asistencia) {
                $asistencias[$asistencia['estudiante_id']] = $asistencia;
            }
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}

// Procesar formulario de registro de asistencias
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_asistencias']) && $cicloLectivoId > 0) {
    try {
        $db->transaction(function($db) use ($cursoSeleccionado, $fechaSeleccionada, $cuatrimestreActual) {
            foreach ($_POST['estudiantes'] as $estudianteId => $datos) {
                $estado = $datos['estado'] ?? 'presente';
                $observaciones = $datos['observaciones'] ?? '';
                $motivoFalta = $datos['motivo_falta'] ?? '';
                $motivoOtro = $datos['motivo_otro'] ?? '';
                $motivoNoComputa = $datos['motivo_no_computa'] ?? '';
                
                // Verificar si ya existe un registro para este estudiante, fecha y curso
                $existe = $db->fetchOne(
                    "SELECT id FROM asistencias 
                     WHERE estudiante_id = ? AND fecha = ? AND curso_id = ?",
                    [$estudianteId, $fechaSeleccionada, $cursoSeleccionado]
                );
                
                if ($existe) {
                    // Actualizar registro existente
                    $db->query(
                        "UPDATE asistencias SET 
                         estado = ?, cuatrimestre = ?, observaciones = ?, motivo_falta = ?, motivo_otro = ?, motivo_no_computa = ?
                         WHERE id = ?",
                        [$estado, $cuatrimestreActual, $observaciones, $motivoFalta, $motivoOtro, $motivoNoComputa, $existe['id']]
                    );
                } else {
                    // Crear nuevo registro
                    $db->query(
                        "INSERT INTO asistencias 
                         (estudiante_id, curso_id, fecha, estado, cuatrimestre, observaciones, motivo_falta, motivo_otro, motivo_no_computa)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$estudianteId, $cursoSeleccionado, $fechaSeleccionada, $estado, $cuatrimestreActual, $observaciones, $motivoFalta, $motivoOtro, $motivoNoComputa]
                    );
                }
            }
        });
        
        // Mensaje de éxito
        $_SESSION['message'] = 'Asistencias registradas correctamente';
        $_SESSION['message_type'] = 'success';
        
        // Recargar la página para mostrar los datos actualizados
        header("Location: asistencias.php?curso=$cursoSeleccionado&fecha=$fechaSeleccionada");
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al guardar las asistencias: ' . $e->getMessage() . '</div>';
    }
}

// Procesar filtrado de historial de asistencias
$mostrarHistorial = isset($_GET['historial']) && $_GET['historial'] == 1;
$historialEstudianteId = isset($_GET['estudiante_historial']) ? intval($_GET['estudiante_historial']) : 0;
$historialMes = isset($_GET['mes']) ? intval($_GET['mes']) : date('n'); // Mes actual por defecto
$historialAnio = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y'); // Año actual por defecto

$historialAsistencias = [];
if ($mostrarHistorial && $cursoSeleccionado && $historialEstudianteId > 0) {
    try {
        // Construir consulta para obtener historial de asistencias
        $primerDiaMes = sprintf('%04d-%02d-01', $historialAnio, $historialMes);
        $ultimoDiaMes = date('Y-m-t', strtotime($primerDiaMes));
        
        $historialAsistencias = $db->fetchAll(
            "SELECT a.*, strftime('%d/%m/%Y', a.fecha) as fecha_formateada 
             FROM asistencias a 
             WHERE a.estudiante_id = ? AND a.curso_id = ? 
             AND a.fecha BETWEEN ? AND ? 
             ORDER BY a.fecha DESC",
            [$historialEstudianteId, $cursoSeleccionado, $primerDiaMes, $ultimoDiaMes]
        );
        
        // Obtener datos del estudiante
        $datosEstudiante = $db->fetchOne(
            "SELECT nombre, apellido, dni FROM usuarios WHERE id = ?",
            [$historialEstudianteId]
        );
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener historial de asistencias: ' . $e->getMessage() . '</div>';
    }
}

// Función auxiliar para obtener texto del motivo justificado
function obtenerTextoMotivoJustificado($codigo, $textoOtro = '', $motivosJustificados = []) {
    if ($codigo === 'otro' && !empty($textoOtro)) {
        return $motivosJustificados[$codigo] . ': ' . $textoOtro;
    }
    
    return isset($motivosJustificados[$codigo]) ? $motivosJustificados[$codigo] : $codigo;
}

// Función auxiliar para obtener texto del motivo no computa
function obtenerTextoMotivoNoComputa($codigo, $motivosNoComputa = []) {
    return isset($motivosNoComputa[$codigo]) ? $motivosNoComputa[$codigo] : $codigo;
}
?>

<!-- Estilos adicionales para mejorar la interfaz -->
<style>
.motivo-falta-input, .motivo-otro-input, .motivo-no-computa-input {
    margin-top: 5px;
}

.estado-presente { background-color: #d4edda !important; }
.estado-ausente { background-color: #f8d7da !important; }
.estado-media_falta { background-color: #fff3cd !important; }
.estado-cuarto_falta { background-color: #e2e3e5 !important; }
.estado-tres_cuartos_falta { background-color: #ffeaa7 !important; }
.estado-justificada { background-color: #cce5ff !important; }
.estado-no_computa { background-color: #e7f3ff !important; }

.btn-group .btn-sm {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.estado-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

.estado-grid .btn {
    width: 100%;
}

@media (max-width: 768px) {
    .estado-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">
                    <i class="bi bi-calendar-check"></i> 
                    Registro de Asistencias - Ciclo Lectivo <?= isset($anioActivo) ? $anioActivo : date('Y') ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" action="asistencias.php" class="mb-4">
                    <div class="row align-items-end">
                        <div class="col-md-4 mb-3">
                            <label for="curso" class="form-label">Seleccione Curso:</label>
                            <select name="curso" id="curso" class="form-select" required>
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                    <?= $curso['nombre'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="fecha" class="form-label">Fecha:</label>
                            <input type="date" name="fecha" id="fecha" class="form-control" 
                                   value="<?= $fechaSeleccionada ?>" required>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-filter"></i> Filtrar
                            </button>
                            
                            <?php if ($cursoSeleccionado): ?>
                            <a href="asistencias.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= $fechaSeleccionada ?>&historial=1" 
                               class="btn btn-info ms-2 text-white">
                                <i class="bi bi-clock-history"></i> Ver Historial
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                
                <?php if ($cursoSeleccionado && !$mostrarHistorial && count($estudiantes) > 0): ?>
                <!-- Formulario para registrar asistencias -->
                <form method="POST" action="asistencias.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= $fechaSeleccionada ?>">
                    <div class="alert alert-info d-flex align-items-center">
                        <i class="bi bi-info-circle-fill me-2 fs-4"></i>
                        <div>
                            <strong>Registro de asistencia para el día <?= date('d/m/Y', strtotime($fechaSeleccionada)) ?></strong>
                            <br>Período actual: <?= $periodoNombre ?>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 25%;">Estudiante</th>
                                    <th style="width: 50%;">Estado de Asistencia</th>
                                    <th style="width: 25%;">Observaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes as $estudiante): ?>
                                <?php 
                                    $asistencia = isset($asistencias[$estudiante['id']]) ? $asistencias[$estudiante['id']] : null;
                                    $estado = $asistencia ? $asistencia['estado'] : 'presente';
                                    $observaciones = $asistencia ? $asistencia['observaciones'] : '';
                                    $motivoFalta = $asistencia ? ($asistencia['motivo_falta'] ?? '') : '';
                                    $motivoOtro = $asistencia ? ($asistencia['motivo_otro'] ?? '') : '';
                                    $motivoNoComputa = $asistencia ? ($asistencia['motivo_no_computa'] ?? '') : '';
                                ?>
                                <tr id="row_<?= $estudiante['id'] ?>" class="estado-<?= str_replace(' ', '_', $estado) ?>">
                                    <td>
                                        <strong><?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?></strong>
                                        <br><small class="text-muted">Matr.: <?= $estudiante['dni'] ?></small>
                                        <input type="hidden" name="estudiantes[<?= $estudiante['id'] ?>][id]" value="<?= $estudiante['id'] ?>">
                                    </td>
                                    <td>
                                        <div class="row">
                                            <div class="col-12 mb-2">
                                                <div class="estado-grid">
                                                    <!-- Primera fila de botones -->
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="presente_<?= $estudiante['id'] ?>" value="presente" 
                                                           <?= $estado == 'presente' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-success btn-sm" for="presente_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-check-circle"></i> Presente
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="cuarto_falta_<?= $estudiante['id'] ?>" value="cuarto_falta" 
                                                           <?= $estado == 'cuarto_falta' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-secondary btn-sm" for="cuarto_falta_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-clock"></i> 1/4 Falta
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="media_falta_<?= $estudiante['id'] ?>" value="media_falta" 
                                                           <?= $estado == 'media_falta' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-warning btn-sm" for="media_falta_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-slash-circle"></i> 1/2 Falta
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="tres_cuartos_falta_<?= $estudiante['id'] ?>" value="tres_cuartos_falta" 
                                                           <?= $estado == 'tres_cuartos_falta' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-warning btn-sm" for="tres_cuartos_falta_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-clock-history"></i> 3/4 Falta
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="ausente_<?= $estudiante['id'] ?>" value="ausente" 
                                                           <?= $estado == 'ausente' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-danger btn-sm" for="ausente_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-x-circle"></i> Ausente
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="justificada_<?= $estudiante['id'] ?>" value="justificada" 
                                                           <?= $estado == 'justificada' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-info btn-sm" for="justificada_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-file-earmark-text"></i> Justificada
                                                    </label>
                                                    
                                                    <input type="radio" class="btn-check" name="estudiantes[<?= $estudiante['id'] ?>][estado]" 
                                                           id="no_computa_<?= $estudiante['id'] ?>" value="no_computa" 
                                                           <?= $estado == 'no_computa' ? 'checked' : '' ?>
                                                           onchange="toggleCamposExtras(<?= $estudiante['id'] ?>)">
                                                    <label class="btn btn-outline-primary btn-sm" for="no_computa_<?= $estudiante['id'] ?>">
                                                        <i class="bi bi-shield-check"></i> No Computa
                                                    </label>
                                                </div>
                                            </div>
                                            
                                            <!-- Campo para motivo de falta justificada -->
                                            <div class="col-12">
                                                <select name="estudiantes[<?= $estudiante['id'] ?>][motivo_falta]" 
                                                        id="motivo_falta_<?= $estudiante['id'] ?>"
                                                        class="form-select form-select-sm motivo-falta-input" 
                                                        style="display: <?= $estado == 'justificada' ? 'block' : 'none' ?>;"
                                                        onchange="toggleMotivoOtro(<?= $estudiante['id'] ?>)">
                                                    <option value="">-- Motivo de la justificación --</option>
                                                    <?php foreach ($motivosJustificados as $codigo => $descripcion): ?>
                                                    <option value="<?= $codigo ?>" <?= ($motivoFalta == $codigo) ? 'selected' : '' ?>>
                                                        <?= $descripcion ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                
                                                <!-- Campo adicional para "Otro motivo" justificado -->
                                                <input type="text" 
                                                       name="estudiantes[<?= $estudiante['id'] ?>][motivo_otro]" 
                                                       id="motivo_otro_<?= $estudiante['id'] ?>"
                                                       class="form-control form-control-sm mt-2 motivo-otro-input" 
                                                       placeholder="Especifique el motivo de la justificación..."
                                                       style="display: <?= ($estado == 'justificada' && $motivoFalta == 'otro') ? 'block' : 'none' ?>;"
                                                       value="<?= htmlspecialchars($motivoOtro) ?>">
                                                
                                                <!-- Campo para motivo de "No computa" -->
                                                <select name="estudiantes[<?= $estudiante['id'] ?>][motivo_no_computa]" 
                                                        id="motivo_no_computa_<?= $estudiante['id'] ?>"
                                                        class="form-select form-select-sm motivo-no-computa-input" 
                                                        style="display: <?= $estado == 'no_computa' ? 'block' : 'none' ?>;">
                                                    <option value="">-- Motivo de "No computa" --</option>
                                                    <?php foreach ($motivosNoComputa as $codigo => $descripcion): ?>
                                                    <option value="<?= $codigo ?>" <?= ($motivoNoComputa == $codigo) ? 'selected' : '' ?>>
                                                        <?= $descripcion ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <textarea name="estudiantes[<?= $estudiante['id'] ?>][observaciones]" 
                                                  class="form-control form-control-sm" 
                                                  rows="3"
                                                  placeholder="Observaciones adicionales"><?= htmlspecialchars($observaciones) ?></textarea>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <strong>Referencias de Estados de Asistencia:</strong><br>
                                <div class="row">
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li><strong><i class="bi bi-check-circle text-success"></i> Presente:</strong> El estudiante asistió normalmente a clase.</li>
                                            <li><strong><i class="bi bi-clock text-secondary"></i> 1/4 Falta:</strong> Llegada tardía menor a 15 minutos o retiro temprano menor.</li>
                                            <li><strong><i class="bi bi-slash-circle text-warning"></i> 1/2 Falta:</strong> Llegada tardía o retiro temprano significativo.</li>
                                            <li><strong><i class="bi bi-clock-history text-warning"></i> 3/4 Falta:</strong> Falta mayor parte de la clase pero no completa.</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <ul class="mb-0">
                                            <li><strong><i class="bi bi-x-circle text-danger"></i> Ausente:</strong> El estudiante no asistió a clase.</li>
                                            <li><strong><i class="bi bi-file-earmark-text text-info"></i> Justificada:</strong> Inasistencia justificada (requiere motivo).</li>
                                            <li><strong><i class="bi bi-shield-check text-primary"></i> No Computa:</strong> Ausencia que no suma para el cálculo de regularidad (salidas institucionales, etc.).</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-3">
                        <button type="submit" name="guardar_asistencias" class="btn btn-primary btn-lg">
                            <i class="bi bi-save"></i> Guardar Asistencias
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" onclick="marcarTodosPresentes()">
                            <i class="bi bi-check-all"></i> Marcar Todos Presentes
                        </button>
                    </div>
                </form>
                
                <?php elseif ($mostrarHistorial && $cursoSeleccionado): ?>
                <!-- Sección de historial de asistencias -->
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title">
                                    <i class="bi bi-clock-history"></i> Historial de Asistencias
                                </h6>
                            </div>
                            <div class="card-body">
                                <form method="GET" action="asistencias.php" class="mb-3">
                                    <input type="hidden" name="curso" value="<?= $cursoSeleccionado ?>">
                                    <input type="hidden" name="fecha" value="<?= $fechaSeleccionada ?>">
                                    <input type="hidden" name="historial" value="1">
                                    
                                    <div class="row align-items-end">
                                        <div class="col-md-3">
                                            <label for="estudiante_historial" class="form-label">Estudiante:</label>
                                            <select name="estudiante_historial" id="estudiante_historial" class="form-select" required>
                                                <option value="">-- Seleccione un estudiante --</option>
                                                <?php foreach ($estudiantes as $estudiante): ?>
                                                <option value="<?= $estudiante['id'] ?>" <?= ($historialEstudianteId == $estudiante['id']) ? 'selected' : '' ?>>
                                                    <?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <label for="mes" class="form-label">Mes:</label>
                                            <select name="mes" id="mes" class="form-select">
                                                <?php 
                                                $mesesEspanol = [
                                                    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
                                                    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
                                                    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
                                                ];

                                                for ($m = 1; $m <= 12; $m++): 
                                                ?>
                                                <option value="<?= $m ?>" <?= ($historialMes == $m) ? 'selected' : '' ?>>
                                                    <?= $mesesEspanol[$m] ?>
                                                </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <label for="anio" class="form-label">Año:</label>
                                            <select name="anio" id="anio" class="form-select">
                                                <?php for ($a = $anioActivo - 1; $a <= $anioActivo + 1; $a++): ?>
                                                <option value="<?= $a ?>" <?= ($historialAnio == $a) ? 'selected' : '' ?>>
                                                    <?= $a ?>
                                                </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-search"></i> Buscar
                                            </button>
                                            <a href="asistencias.php?curso=<?= $cursoSeleccionado ?>&fecha=<?= $fechaSeleccionada ?>" 
                                               class="btn btn-secondary ms-2">
                                                <i class="bi bi-arrow-left"></i> Volver
                                            </a>
                                        </div>
                                    </div>
                                </form>
                                
                                <?php if ($historialEstudianteId > 0 && isset($datosEstudiante)): ?>
                                <div class="alert alert-info">
                                    <strong>Historial de:</strong> <?= $datosEstudiante['apellido'] ?>, <?= $datosEstudiante['nombre'] ?> 
                                    (Matr.: <?= $datosEstudiante['dni'] ?>)
                                    <br><strong>Período:</strong> <?= date('F Y', mktime(0, 0, 0, $historialMes, 1, $historialAnio)) ?>
                                </div>
                                
                                <?php if (count($historialAsistencias) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Estado</th>
                                                <th>Motivo</th>
                                                <th>Observaciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($historialAsistencias as $hist): ?>
                                            <tr class="estado-<?= str_replace(' ', '_', $hist['estado']) ?>">
                                                <td><?= date('d/m/Y', strtotime($hist['fecha'])) ?></td>
                                                <td>
                                                    <?php
                                                    $iconos = [
                                                        'presente' => '<i class="bi bi-check-circle text-success"></i> Presente',
                                                        'ausente' => '<i class="bi bi-x-circle text-danger"></i> Ausente',
                                                        'media_falta' => '<i class="bi bi-slash-circle text-warning"></i> 1/2 Falta',
                                                        'cuarto_falta' => '<i class="bi bi-clock text-secondary"></i> 1/4 Falta',
                                                        'tres_cuartos_falta' => '<i class="bi bi-clock-history text-warning"></i> 3/4 Falta',
                                                        'justificada' => '<i class="bi bi-file-earmark-text text-info"></i> Justificada',
                                                        'no_computa' => '<i class="bi bi-shield-check text-primary"></i> No Computa'
                                                    ];
                                                    echo $iconos[$hist['estado']] ?? $hist['estado'];
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($hist['estado'] === 'justificada' && !empty($hist['motivo_falta'])): ?>
                                                        <?= obtenerTextoMotivoJustificado($hist['motivo_falta'], $hist['motivo_otro'] ?? '', $motivosJustificados) ?>
                                                    <?php elseif ($hist['estado'] === 'no_computa' && !empty($hist['motivo_no_computa'])): ?>
                                                        <?= obtenerTextoMotivoNoComputa($hist['motivo_no_computa'], $motivosNoComputa) ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($hist['observaciones'] ?? '') ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-warning">
                                    No se encontraron registros de asistencia para el período seleccionado.
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($cursoSeleccionado && count($estudiantes) == 0): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    No se encontraron estudiantes matriculados en este curso.
                </div>
                <?php elseif (!$cursoSeleccionado): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Por favor, seleccione un curso para registrar asistencias.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Función para mostrar/ocultar los campos extras según el estado seleccionado
function toggleCamposExtras(estudianteId) {
    const motivoFaltaSelect = document.getElementById('motivo_falta_' + estudianteId);
    const motivoOtroInput = document.getElementById('motivo_otro_' + estudianteId);
    const motivoNoComputaSelect = document.getElementById('motivo_no_computa_' + estudianteId);
    const justificadaRadio = document.getElementById('justificada_' + estudianteId);
    const noComputaRadio = document.getElementById('no_computa_' + estudianteId);
    const row = document.getElementById('row_' + estudianteId);
    
    // Ocultar todos los campos extras primero
    motivoFaltaSelect.style.display = 'none';
    motivoOtroInput.style.display = 'none';
    motivoNoComputaSelect.style.display = 'none';
    
    // Limpiar requerimientos
    motivoFaltaSelect.required = false;
    motivoOtroInput.required = false;
    motivoNoComputaSelect.required = false;
    
    if (justificadaRadio.checked) {
        // Mostrar campo de motivo para justificada
        motivoFaltaSelect.style.display = 'block';
        motivoFaltaSelect.required = true;
        
        // Mostrar/ocultar campo "otro" según selección
        toggleMotivoOtro(estudianteId);
    } else if (noComputaRadio.checked) {
        // Mostrar campo de motivo para no computa
        motivoNoComputaSelect.style.display = 'block';
        motivoNoComputaSelect.required = true;
    } else {
        // Limpiar valores cuando no se necesitan
        motivoFaltaSelect.value = '';
        motivoOtroInput.value = '';
        motivoNoComputaSelect.value = '';
    }
    
    // Cambiar color de fondo de la fila según el estado
    const estadoSeleccionado = document.querySelector(`input[name="estudiantes[${estudianteId}][estado]"]:checked`);
    if (estadoSeleccionado) {
        row.className = row.className.replace(/estado-\w+/g, '');
        row.classList.add('estado-' + estadoSeleccionado.value.replace(' ', '_'));
    }
}

// Función para mostrar/ocultar campo "otro motivo"
function toggleMotivoOtro(estudianteId) {
    const motivoSelect = document.getElementById('motivo_falta_' + estudianteId);
    const motivoOtroInput = document.getElementById('motivo_otro_' + estudianteId);
    
    if (motivoSelect.value === 'otro') {
        motivoOtroInput.style.display = 'block';
        motivoOtroInput.required = true;
    } else {
        motivoOtroInput.style.display = 'none';
        motivoOtroInput.required = false;
        motivoOtroInput.value = '';
    }
}

// Función para marcar todos como presentes
function marcarTodosPresentes() {
    if (confirm('¿Está seguro de marcar a todos los estudiantes como presentes?')) {
        const presenteRadios = document.querySelectorAll('input[value="presente"]');
        presenteRadios.forEach(radio => {
            radio.checked = true;
            const estudianteId = radio.id.replace('presente_', '');
            toggleCamposExtras(estudianteId);
        });
    }
}

// Inicializar el estado de los campos al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar el estado de los campos según el estado seleccionado
    const todosLosRadios = document.querySelectorAll('input[type="radio"][name*="[estado]"]');
    todosLosRadios.forEach(radio => {
        if (radio.checked) {
            const estudianteId = radio.id.replace(/\w+_/, '');
            toggleCamposExtras(estudianteId);
        }
    });
    
    // Agregar listeners para cambios en los selectores de motivo
    const motivoSelects = document.querySelectorAll('[id^="motivo_falta_"]');
    motivoSelects.forEach(select => {
        select.addEventListener('change', function() {
            const estudianteId = this.id.replace('motivo_falta_', '');
            toggleMotivoOtro(estudianteId);
        });
    });
});
</script>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>
