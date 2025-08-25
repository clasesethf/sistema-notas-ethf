<?php
/**
 * gestionar_permisos_calificaciones.php - Gestión de permisos para carga de calificaciones
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Iniciar buffer de salida
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_permisos'])) {
    try {
        $cicloLectivoId = intval($_POST['ciclo_lectivo_id']);
        $primerCuatrimestreActivo = isset($_POST['primer_cuatrimestre_activo']) ? 1 : 0;
        $segundoCuatrimestreActivo = isset($_POST['segundo_cuatrimestre_activo']) ? 1 : 0;
        $intensificacionActiva = isset($_POST['intensificacion_activa']) ? 1 : 0;
        $calificacionFinalActiva = isset($_POST['calificacion_final_activa']) ? 1 : 0;
        $observaciones = trim($_POST['observaciones'] ?? '');
        
        // Verificar si ya existe configuración para este ciclo
        $existe = $db->fetchOne(
            "SELECT id FROM permisos_calificaciones WHERE ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
        
        if ($existe) {
            // Actualizar configuración existente
            $db->query(
                "UPDATE permisos_calificaciones 
                 SET primer_cuatrimestre_activo = ?, segundo_cuatrimestre_activo = ?, 
                     intensificacion_activa = ?, calificacion_final_activa = ?,
                     observaciones = ?, fecha_actualizacion = CURRENT_TIMESTAMP, actualizado_por = ?
                 WHERE ciclo_lectivo_id = ?",
                [$primerCuatrimestreActivo, $segundoCuatrimestreActivo, $intensificacionActiva, 
                 $calificacionFinalActiva, $observaciones, $_SESSION['user_id'], $cicloLectivoId]
            );
        } else {
            // Crear nueva configuración
            $db->query(
                "INSERT INTO permisos_calificaciones 
                 (ciclo_lectivo_id, primer_cuatrimestre_activo, segundo_cuatrimestre_activo,
                  intensificacion_activa, calificacion_final_activa, observaciones, actualizado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$cicloLectivoId, $primerCuatrimestreActivo, $segundoCuatrimestreActivo, 
                 $intensificacionActiva, $calificacionFinalActiva, $observaciones, $_SESSION['user_id']]
            );
        }
        
        $_SESSION['message'] = 'Permisos de calificaciones actualizados correctamente';
        $_SESSION['message_type'] = 'success';
        
        header('Location: gestionar_permisos_calificaciones.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al actualizar permisos: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Incluir el encabezado
require_once 'header.php';

// Obtener ciclos lectivos
$ciclos = $db->fetchAll("SELECT * FROM ciclos_lectivos ORDER BY anio DESC");

// Obtener configuración actual para cada ciclo
$configuraciones = [];
foreach ($ciclos as $ciclo) {
    $config = $db->fetchOne(
        "SELECT pc.*, u.nombre as actualizado_por_nombre, u.apellido as actualizado_por_apellido
         FROM permisos_calificaciones pc
         LEFT JOIN usuarios u ON pc.actualizado_por = u.id
         WHERE pc.ciclo_lectivo_id = ?",
        [$ciclo['id']]
    );
    
    if (!$config) {
        // Configuración por defecto si no existe
        $config = [
            'primer_cuatrimestre_activo' => 1,
            'segundo_cuatrimestre_activo' => 0,
            'intensificacion_activa' => 0,
            'calificacion_final_activa' => 1,
            'observaciones' => '',
            'fecha_actualizacion' => null,
            'actualizado_por_nombre' => null,
            'actualizado_por_apellido' => null
        ];
    }
    
    $configuraciones[$ciclo['id']] = $config;
}
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Gestión de Permisos para Carga de Calificaciones</h5>
                    <a href="calificaciones.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a Calificaciones
                    </a>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Control de Acceso:</strong> Aquí puede habilitar o deshabilitar manualmente las columnas 
                        que los profesores pueden editar, independientemente de las fechas automáticas del sistema.
                    </div>
                    
                    <?php foreach ($ciclos as $ciclo): ?>
                        <?php $config = $configuraciones[$ciclo['id']]; ?>
                        
                        <div class="card mb-4 <?= $ciclo['activo'] ? 'border-success' : 'border-secondary' ?>">
                            <div class="card-header <?= $ciclo['activo'] ? 'bg-success text-white' : 'bg-light' ?>">
                                <h6 class="mb-0">
                                    Ciclo Lectivo <?= $ciclo['anio'] ?>
                                    <?php if ($ciclo['activo']): ?>
                                        <span class="badge bg-light text-success">ACTIVO</span>
                                    <?php endif; ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="ciclo_lectivo_id" value="<?= $ciclo['id'] ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="primer_cuatrimestre_activo" id="primer_c_<?= $ciclo['id'] ?>"
                                                       <?= $config['primer_cuatrimestre_activo'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="primer_c_<?= $ciclo['id'] ?>">
                                                    <strong>1° Cuatrimestre</strong><br>
                                                    <small class="text-muted">Valoración y calificación</small>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="segundo_cuatrimestre_activo" id="segundo_c_<?= $ciclo['id'] ?>"
                                                       <?= $config['segundo_cuatrimestre_activo'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="segundo_c_<?= $ciclo['id'] ?>">
                                                    <strong>2° Cuatrimestre</strong><br>
                                                    <small class="text-muted">Valoración y calificación</small>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="intensificacion_activa" id="intensif_<?= $ciclo['id'] ?>"
                                                       <?= $config['intensificacion_activa'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="intensif_<?= $ciclo['id'] ?>">
                                                    <strong>Intensificación</strong><br>
                                                    <small class="text-muted">1° Cuatrimestre</small>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="calificacion_final_activa" id="final_<?= $ciclo['id'] ?>"
                                                       <?= $config['calificacion_final_activa'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="final_<?= $ciclo['id'] ?>">
                                                    <strong>Calificación Final</strong><br>
                                                    <small class="text-muted">Nota definitiva</small>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-8">
                                            <label for="observaciones_<?= $ciclo['id'] ?>" class="form-label">Observaciones:</label>
                                            <textarea name="observaciones" id="observaciones_<?= $ciclo['id'] ?>" 
                                                      class="form-control" rows="2" 
                                                      placeholder="Motivo del cambio, período específico, etc."><?= htmlspecialchars($config['observaciones']) ?></textarea>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="submit" name="actualizar_permisos" class="btn btn-primary w-100">
                                                <i class="bi bi-save"></i> Actualizar Permisos
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <?php if ($config['fecha_actualizacion']): ?>
                                    <div class="row mt-2">
                                        <div class="col-md-12">
                                            <small class="text-muted">
                                                <i class="bi bi-clock"></i>
                                                Última actualización: <?= date('d/m/Y H:i', strtotime($config['fecha_actualizacion'])) ?>
                                                <?php if ($config['actualizado_por_nombre']): ?>
                                                    por <?= $config['actualizado_por_nombre'] ?> <?= $config['actualizado_por_apellido'] ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($ciclos)): ?>
                    <div class="alert alert-warning text-center">
                        <i class="bi bi-exclamation-triangle"></i>
                        <h5>No hay ciclos lectivos configurados</h5>
                        <p>Debe crear al menos un ciclo lectivo antes de configurar permisos.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Colorear switches según su estado
document.addEventListener('DOMContentLoaded', function() {
    const switches = document.querySelectorAll('.form-check-input');
    
    switches.forEach(function(switchEl) {
        function updateSwitch() {
            if (switchEl.checked) {
                switchEl.classList.remove('btn-outline-danger');
                switchEl.classList.add('btn-outline-success');
            } else {
                switchEl.classList.remove('btn-outline-success');
                switchEl.classList.add('btn-outline-danger');
            }
        }
        
        updateSwitch();
        switchEl.addEventListener('change', updateSwitch);
    });
});
</script>

<?php
require_once 'footer.php';
?>