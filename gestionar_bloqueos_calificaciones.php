<?php
/**
 * gestionar_bloqueos_calificaciones.php - Sistema avanzado de bloqueo de columnas
 * CORREGIDO - Procesa formularios antes de incluir header.php y maneja errores de ciclo lectivo
 */

require_once 'config.php';

// Verificar permisos ANTES de incluir header (solo admin y directivos)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();

// Obtener ciclo lectivo activo con manejo de errores
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;
    $anioActivo = $cicloActivo ? $cicloActivo['anio'] : date('Y');
} catch (Exception $e) {
    $cicloActivo = null;
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
    error_log("Error al obtener ciclo lectivo: " . $e->getMessage());
}

// PROCESAR FORMULARIO ANTES DE INCLUIR HEADER
$formularioProcesado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_bloqueos']) && $cicloLectivoId > 0) {
    try {
        $db->transaction(function($db) use ($cicloLectivoId) {
            // Obtener valores del formulario
            $bloqueos = [
                'valoracion_1bim_bloqueada' => isset($_POST['valoracion_1bim_bloqueada']) ? 1 : 0,
                'desempeno_1bim_bloqueado' => isset($_POST['desempeno_1bim_bloqueado']) ? 1 : 0,
                'observaciones_1bim_bloqueadas' => isset($_POST['observaciones_1bim_bloqueadas']) ? 1 : 0,
                'valoracion_3bim_bloqueada' => isset($_POST['valoracion_3bim_bloqueada']) ? 1 : 0,
                'desempeno_3bim_bloqueado' => isset($_POST['desempeno_3bim_bloqueado']) ? 1 : 0,
                'observaciones_3bim_bloqueadas' => isset($_POST['observaciones_3bim_bloqueadas']) ? 1 : 0,
                'valoracion_1c_bloqueada' => isset($_POST['valoracion_1c_bloqueada']) ? 1 : 0,
                'calificacion_1c_bloqueada' => isset($_POST['calificacion_1c_bloqueada']) ? 1 : 0,
                'valoracion_2c_bloqueada' => isset($_POST['valoracion_2c_bloqueada']) ? 1 : 0,
                'calificacion_2c_bloqueada' => isset($_POST['calificacion_2c_bloqueada']) ? 1 : 0,
                'intensificacion_1c_bloqueada' => isset($_POST['intensificacion_1c_bloqueada']) ? 1 : 0,
                'calificacion_final_bloqueada' => isset($_POST['calificacion_final_bloqueada']) ? 1 : 0,
                'observaciones_cuatrimestrales_bloqueadas' => isset($_POST['observaciones_cuatrimestrales_bloqueadas']) ? 1 : 0
            ];
            
            $observaciones = $_POST['observaciones_bloqueo'] ?? '';
            $bloqueoGeneral = isset($_POST['bloqueo_general']) ? 1 : 0;
            
            // Verificar si ya existe configuración
            $configExistente = $db->fetchOne(
                "SELECT id FROM bloqueos_calificaciones WHERE ciclo_lectivo_id = ?",
                [$cicloLectivoId]
            );
            
            if ($configExistente) {
                // Actualizar configuración existente
                $query = "UPDATE bloqueos_calificaciones SET ";
                $params = [];
                $sets = [];
                
                foreach ($bloqueos as $campo => $valor) {
                    $sets[] = "$campo = ?";
                    $params[] = $valor;
                }
                
                $sets[] = "observaciones = ?";
                $params[] = $observaciones;
                $sets[] = "bloqueo_general = ?";
                $params[] = $bloqueoGeneral;
                $sets[] = "fecha_actualizacion = CURRENT_TIMESTAMP";
                $sets[] = "actualizado_por = ?";
                $params[] = $_SESSION['user_id'];
                
                $query .= implode(', ', $sets) . " WHERE id = ?";
                $params[] = $configExistente['id'];
                
                $db->query($query, $params);
            } else {
                // Crear nueva configuración
                $campos = array_keys($bloqueos);
                $campos[] = 'ciclo_lectivo_id';
                $campos[] = 'observaciones';
                $campos[] = 'bloqueo_general';
                $campos[] = 'actualizado_por';
                
                $valores = array_values($bloqueos);
                $valores[] = $cicloLectivoId;
                $valores[] = $observaciones;
                $valores[] = $bloqueoGeneral;
                $valores[] = $_SESSION['user_id'];
                
                $placeholders = str_repeat('?,', count($campos) - 1) . '?';
                
                $db->query(
                    "INSERT INTO bloqueos_calificaciones (" . implode(',', $campos) . ") VALUES ($placeholders)",
                    $valores
                );
            }
        });
        
        $_SESSION['message'] = 'Configuración de bloqueos actualizada correctamente';
        $_SESSION['message_type'] = 'success';
        
        // Redirect para evitar reenvío del formulario
        header('Location: gestionar_bloqueos_calificaciones.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al actualizar la configuración: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        $formularioProcesado = true; // Marcar que hubo un error para no hacer redirect
    }
}

// AHORA SÍ INCLUIR EL HEADER
require_once 'header.php';

// Obtener configuración actual
$configuracionActual = [];
if ($cicloLectivoId > 0) {
    try {
        $config = $db->fetchOne(
            "SELECT bc.*, u.nombre as actualizado_por_nombre, u.apellido as actualizado_por_apellido
             FROM bloqueos_calificaciones bc
             LEFT JOIN usuarios u ON bc.actualizado_por = u.id
             WHERE bc.ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
        
        if ($config) {
            $configuracionActual = $config;
        } else {
            // Configuración por defecto (todo desbloqueado)
            $configuracionActual = [
                'valoracion_1bim_bloqueada' => 0,
                'desempeno_1bim_bloqueado' => 0,
                'observaciones_1bim_bloqueadas' => 0,
                'valoracion_3bim_bloqueada' => 0,
                'desempeno_3bim_bloqueado' => 0,
                'observaciones_3bim_bloqueadas' => 0,
                'valoracion_1c_bloqueada' => 0,
                'calificacion_1c_bloqueada' => 0,
                'valoracion_2c_bloqueada' => 0,
                'calificacion_2c_bloqueada' => 0,
                'intensificacion_1c_bloqueada' => 0,
                'calificacion_final_bloqueada' => 0,
                'observaciones_cuatrimestrales_bloqueadas' => 0,
                'observaciones' => '',
                'bloqueo_general' => 0,
                'fecha_actualizacion' => null,
                'actualizado_por_nombre' => null,
                'actualizado_por_apellido' => null
            ];
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener configuración: ' . $e->getMessage() . '</div>';
    }
}

// Obtener estadísticas de profesores activos
$estadisticasProfesores = [];
if ($cicloLectivoId > 0) {
    try {
        $estadisticasProfesores = $db->fetchOne(
            "SELECT 
                COUNT(DISTINCT mp.profesor_id) as profesores_activos,
                COUNT(DISTINCT mp.curso_id) as cursos_con_asignaciones,
                COUNT(*) as total_asignaciones
             FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
    } catch (Exception $e) {
        $estadisticasProfesores = [
            'profesores_activos' => 0,
            'cursos_con_asignaciones' => 0,
            'total_asignaciones' => 0
        ];
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-shield-lock"></i> 
                        Gestión de Bloqueos de Calificaciones
                    </h5>
                    <div>
                        <a href="calificaciones.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-journal-text"></i> Ver Calificaciones
                        </a>
                        <a href="configurar_periodos.php" class="btn btn-outline-primary">
                            <i class="bi bi-calendar-check"></i> Configurar Períodos
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($cicloLectivoId == 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>No hay ciclo lectivo activo.</strong> 
                        <?php if (!$cicloActivo): ?>
                            No se encontró ningún ciclo lectivo configurado en el sistema.
                            <br>
                            <small class="text-muted">
                                Para configurar bloqueos, primero debe crear y activar un ciclo lectivo.
                            </small>
                        <?php else: ?>
                            Debe activar un ciclo lectivo antes de configurar los bloqueos.
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <a href="index.php" class="btn btn-outline-primary">
                                <i class="bi bi-house"></i> Volver al Panel Principal
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    
                    <!-- Información del ciclo -->
                    <div class="alert alert-info mb-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="mb-1">
                                    <i class="bi bi-calendar3"></i> 
                                    Ciclo Lectivo: <strong><?= htmlspecialchars($anioActivo) ?></strong>
                                </h6>
                                <small class="text-muted">
                                    Configurando bloqueos para todos los profesores en este ciclo lectivo
                                    <?php if ($cicloActivo && isset($cicloActivo['fecha_inicio'], $cicloActivo['fecha_fin'])): ?>
                                        <br>Período: <?= date('d/m/Y', strtotime($cicloActivo['fecha_inicio'])) ?> - <?= date('d/m/Y', strtotime($cicloActivo['fecha_fin'])) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="d-flex justify-content-end gap-3">
                                    <div class="text-center">
                                        <div class="fs-4 fw-bold text-primary"><?= $estadisticasProfesores['profesores_activos'] ?? 0 ?></div>
                                        <small class="text-muted">Profesores</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="fs-4 fw-bold text-success"><?= $estadisticasProfesores['total_asignaciones'] ?? 0 ?></div>
                                        <small class="text-muted">Asignaciones</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario principal -->
                    <form method="POST" action="gestionar_bloqueos_calificaciones.php" id="form-bloqueos">
                        <!-- Bloqueo general -->
                        <div class="card mb-4 border-danger">
                            <div class="card-header bg-danger text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Control General del Sistema
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="bloqueo_general" 
                                           name="bloqueo_general" <?= $configuracionActual['bloqueo_general'] ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-danger" for="bloqueo_general">
                                        Bloqueo General Completo
                                    </label>
                                    <div class="form-text">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>ATENCIÓN:</strong> Esta opción bloquea completamente el acceso a la carga de calificaciones 
                                        para todos los profesores. Use solo en situaciones especiales (cierre de período, auditoría, etc.)
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Configuración de valoraciones bimestrales -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-clipboard-check"></i>
                                    Valoraciones Preliminares (Bimestrales)
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- 1er Bimestre -->
                                    <div class="col-md-6">
                                        <h6 class="text-primary">1er Bimestre</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="valoracion_1bim_bloqueada" name="valoracion_1bim_bloqueada"
                                                       <?= $configuracionActual['valoracion_1bim_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="valoracion_1bim_bloqueada">
                                                    <i class="bi bi-award"></i> Bloquear Valoración (TEA/TEP/TED)
                                                </label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="desempeno_1bim_bloqueado" name="desempeno_1bim_bloqueado"
                                                       <?= $configuracionActual['desempeno_1bim_bloqueado'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="desempeno_1bim_bloqueado">
                                                    <i class="bi bi-graph-up"></i> Bloquear Desempeño Académico
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="observaciones_1bim_bloqueadas" name="observaciones_1bim_bloqueadas"
                                                       <?= $configuracionActual['observaciones_1bim_bloqueadas'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="observaciones_1bim_bloqueadas">
                                                    <i class="bi bi-chat-text"></i> Bloquear Observaciones
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- 3er Bimestre -->
                                    <div class="col-md-6">
                                        <h6 class="text-primary">3er Bimestre</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="valoracion_3bim_bloqueada" name="valoracion_3bim_bloqueada"
                                                       <?= $configuracionActual['valoracion_3bim_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="valoracion_3bim_bloqueada">
                                                    <i class="bi bi-award"></i> Bloquear Valoración (TEA/TEP/TED)
                                                </label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="desempeno_3bim_bloqueado" name="desempeno_3bim_bloqueado"
                                                       <?= $configuracionActual['desempeno_3bim_bloqueado'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="desempeno_3bim_bloqueado">
                                                    <i class="bi bi-graph-up"></i> Bloquear Desempeño Académico
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="observaciones_3bim_bloqueadas" name="observaciones_3bim_bloqueadas"
                                                       <?= $configuracionActual['observaciones_3bim_bloqueadas'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="observaciones_3bim_bloqueadas">
                                                    <i class="bi bi-chat-text"></i> Bloquear Observaciones
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Configuración de calificaciones cuatrimestrales -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-journal-text"></i>
                                    Calificaciones Cuatrimestrales
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- 1er Cuatrimestre -->
                                    <div class="col-md-4">
                                        <h6 class="text-success">1er Cuatrimestre</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="valoracion_1c_bloqueada" name="valoracion_1c_bloqueada"
                                                       <?= $configuracionActual['valoracion_1c_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="valoracion_1c_bloqueada">
                                                    <i class="bi bi-award"></i> Valoración Preliminar
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="calificacion_1c_bloqueada" name="calificacion_1c_bloqueada"
                                                       <?= $configuracionActual['calificacion_1c_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="calificacion_1c_bloqueada">
                                                    <i class="bi bi-123"></i> Calificación Numérica
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- 2do Cuatrimestre -->
                                    <div class="col-md-4">
                                        <h6 class="text-success">2do Cuatrimestre</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="valoracion_2c_bloqueada" name="valoracion_2c_bloqueada"
                                                       <?= $configuracionActual['valoracion_2c_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="valoracion_2c_bloqueada">
                                                    <i class="bi bi-award"></i> Valoración Preliminar
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="calificacion_2c_bloqueada" name="calificacion_2c_bloqueada"
                                                       <?= $configuracionActual['calificacion_2c_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="calificacion_2c_bloqueada">
                                                    <i class="bi bi-123"></i> Calificación Numérica
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Campos finales -->
                                    <div class="col-md-4">
                                        <h6 class="text-success">Calificaciones Finales</h6>
                                        <div class="border rounded p-3 bg-light">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="intensificacion_1c_bloqueada" name="intensificacion_1c_bloqueada"
                                                       <?= $configuracionActual['intensificacion_1c_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="intensificacion_1c_bloqueada">
                                                    <i class="bi bi-arrow-up-circle"></i> Intensificación 1°C
                                                </label>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="calificacion_final_bloqueada" name="calificacion_final_bloqueada"
                                                       <?= $configuracionActual['calificacion_final_bloqueada'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="calificacion_final_bloqueada">
                                                    <i class="bi bi-trophy"></i> Calificación Final
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input campo-bloqueable" type="checkbox" 
                                                       id="observaciones_cuatrimestrales_bloqueadas" name="observaciones_cuatrimestrales_bloqueadas"
                                                       <?= $configuracionActual['observaciones_cuatrimestrales_bloqueadas'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="observaciones_cuatrimestrales_bloqueadas">
                                                    <i class="bi bi-chat-text"></i> Observaciones
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Observaciones administrativas -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-sticky"></i>
                                    Observaciones Administrativas
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="observaciones_bloqueo" class="form-label">
                                        Motivo del bloqueo (opcional):
                                    </label>
                                    <textarea class="form-control" id="observaciones_bloqueo" name="observaciones_bloqueo" 
                                              rows="3" placeholder="Ej: Cierre del período académico para auditoría..."><?= htmlspecialchars($configuracionActual['observaciones'] ?? '') ?></textarea>
                                    <div class="form-text">
                                        Esta observación será visible para los profesores cuando vean campos bloqueados.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Botones de acción -->
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <button type="button" class="btn btn-outline-success me-2" onclick="desbloquearTodo()">
                                    <i class="bi bi-unlock"></i> Desbloquear Todo
                                </button>
                                <button type="button" class="btn btn-outline-danger" onclick="bloquearTodo()">
                                    <i class="bi bi-lock"></i> Bloquear Todo
                                </button>
                            </div>
                            
                            <div>
                                <button type="submit" name="actualizar_bloqueos" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save"></i> Guardar Configuración
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Información de última actualización -->
                    <?php if (isset($configuracionActual['fecha_actualizacion']) && $configuracionActual['fecha_actualizacion']): ?>
                    <div class="mt-4 pt-3 border-top">
                        <small class="text-muted">
                            <i class="bi bi-clock"></i>
                            Última actualización: <?= date('d/m/Y H:i', strtotime($configuracionActual['fecha_actualizacion'])) ?>
                            <?php if (isset($configuracionActual['actualizado_por_nombre'], $configuracionActual['actualizado_por_apellido'])): ?>
                                por <?= htmlspecialchars($configuracionActual['actualizado_por_nombre'] . ' ' . $configuracionActual['actualizado_por_apellido']) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Manejar bloqueo general
    const bloqueoGeneral = document.getElementById('bloqueo_general');
    const camposBloqueable = document.querySelectorAll('.campo-bloqueable');
    
    function actualizarEstadoCampos() {
        const bloqueado = bloqueoGeneral.checked;
        
        camposBloqueable.forEach(function(campo) {
            campo.disabled = bloqueado;
            if (bloqueado) {
                campo.checked = true;
            }
        });
        
        // Actualizar estilos visuales - verificar que exista el elemento
        const cardBody = document.querySelector('.card-body');
        if (cardBody) {
            if (bloqueado) {
                cardBody.style.opacity = '0.6';
            } else {
                cardBody.style.opacity = '1';
            }
        }
    }
    
    if (bloqueoGeneral) {
        bloqueoGeneral.addEventListener('change', actualizarEstadoCampos);
        // Inicializar estado
        actualizarEstadoCampos();
    }
    
    // Funciones para botones de acción rápida
    window.desbloquearTodo = function() {
        if (confirm('¿Está seguro de que desea desbloquear TODOS los campos?\n\nEsto permitirá a los profesores editar todas las calificaciones.')) {
            if (bloqueoGeneral) {
                bloqueoGeneral.checked = false;
            }
            camposBloqueable.forEach(function(campo) {
                campo.checked = false;
                campo.disabled = false;
            });
            actualizarEstadoCampos();
        }
    };
    
    window.bloquearTodo = function() {
        if (confirm('¿Está seguro de que desea bloquear TODOS los campos?\n\nEsto impedirá que los profesores editen cualquier calificación.')) {
            if (bloqueoGeneral) {
                bloqueoGeneral.checked = true;
            }
            camposBloqueable.forEach(function(campo) {
                campo.checked = true;
                campo.disabled = true;
            });
            actualizarEstadoCampos();
        }
    };
    
    // Validación del formulario
    const formBloqueos = document.getElementById('form-bloqueos');
    if (formBloqueos) {
        formBloqueos.addEventListener('submit', function(e) {
            if (bloqueoGeneral && bloqueoGeneral.checked) {
                const confirmar = confirm(
                    'ATENCIÓN: Está activando el BLOQUEO GENERAL COMPLETO.\n\n' +
                    'Esto impedirá que TODOS los profesores puedan cargar calificaciones.\n\n' +
                    '¿Está seguro de continuar?'
                );
                
                if (!confirmar) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Mostrar confirmación de cambios
            const camposBloqueados = Array.from(camposBloqueable).filter(c => c.checked).length;
            const totalCampos = camposBloqueable.length;
            
            if (camposBloqueados > 0) {
                const mensaje = `Se bloquearán ${camposBloqueados} de ${totalCampos} campos disponibles.\n\n¿Continuar?`;
                if (!confirm(mensaje)) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
    
    // Feedback visual en tiempo real
    camposBloqueable.forEach(function(campo) {
        campo.addEventListener('change', function() {
            const label = this.nextElementSibling;
            if (label) {
                if (this.checked) {
                    label.classList.add('text-danger', 'fw-bold');
                } else {
                    label.classList.remove('text-danger', 'fw-bold');
                }
            }
        });
        
        // Aplicar estado inicial
        if (campo.checked) {
            const label = campo.nextElementSibling;
            if (label) {
                label.classList.add('text-danger', 'fw-bold');
            }
        }
    });
});
</script>

<style>
.campo-bloqueable:disabled {
    opacity: 0.5;
    pointer-events: none;
}

.form-check-input:checked + .form-check-label {
    font-weight: 600;
}

.card-header {
    border-bottom: 2px solid rgba(0,0,0,0.1);
}

.border-danger {
    border-color: #dc3545 !important;
    border-width: 2px !important;
}

.bg-light {
    background-color: #f8f9fa !important;
}

/* Animaciones suaves */
.form-check-input {
    transition: all 0.2s ease;
}

.form-check-label {
    transition: all 0.2s ease;
}

/* Tooltip personalizado */
.form-text {
    font-size: 0.875rem;
    margin-top: 0.25rem;
}
</style>

<?php require_once 'footer.php'; ?>