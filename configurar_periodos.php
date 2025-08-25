<?php
/**
 * configurar_periodos.php - Configuración de períodos académicos
 * Permite a los administradores configurar qué tipo de carga está activa
 */

require_once 'config.php';
require_once 'header.php';

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cicloLectivoId > 0) {
    try {
        $periodoId = intval($_POST['periodo_activo']);
        
        // Desactivar todos los períodos
        $db->query("UPDATE configuracion_periodos SET activo = 0 WHERE ciclo_lectivo_id = ?", [$cicloLectivoId]);
        
        // Activar el período seleccionado
        $db->query("UPDATE configuracion_periodos SET activo = 1 WHERE id = ?", [$periodoId]);
        
        $_SESSION['message'] = 'Período actualizado correctamente';
        $_SESSION['message_type'] = 'success';
        
        header('Location: configurar_periodos.php');
        exit;
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al actualizar período: ' . $e->getMessage() . '</div>';
    }
}

// Obtener períodos configurados
$periodos = [];
if ($cicloLectivoId > 0) {
    try {
        $periodos = $db->fetchAll(
            "SELECT * FROM configuracion_periodos WHERE ciclo_lectivo_id = ? ORDER BY id",
            [$cicloLectivoId]
        );
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener períodos: ' . $e->getMessage() . '</div>';
    }
}

// Obtener estadísticas de uso
$estadisticas = [];
if ($cicloLectivoId > 0) {
    try {
        $estadisticas = $db->fetchOne(
            "SELECT 
                COUNT(CASE WHEN valoracion_1bim IS NOT NULL OR desempeno_1bim IS NOT NULL THEN 1 END) as valoraciones_1bim,
                COUNT(CASE WHEN valoracion_3bim IS NOT NULL OR desempeno_3bim IS NOT NULL THEN 1 END) as valoraciones_3bim,
                COUNT(CASE WHEN calificacion_1c IS NOT NULL OR valoracion_preliminar_1c IS NOT NULL THEN 1 END) as calificaciones_1c,
                COUNT(CASE WHEN calificacion_2c IS NOT NULL OR valoracion_preliminar_2c IS NOT NULL THEN 1 END) as calificaciones_2c
             FROM calificaciones 
             WHERE ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
    } catch (Exception $e) {
        $estadisticas = [
            'valoraciones_1bim' => 0,
            'valoraciones_3bim' => 0,
            'calificaciones_1c' => 0,
            'calificaciones_2c' => 0
        ];
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Configuración de Períodos Académicos</h5>
                    <p class="card-text text-muted mb-0">
                        Gestione qué tipo de carga está activo actualmente para todos los profesores
                    </p>
                </div>
                <div class="card-body">
                    <?php if ($cicloLectivoId > 0 && count($periodos) > 0): ?>
                    
                    <form method="POST" action="configurar_periodos.php">
                        <div class="mb-4">
                            <label class="form-label"><strong>Seleccione el período activo:</strong></label>
                            
                            <?php foreach ($periodos as $periodo): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="periodo_activo" 
                                       id="periodo_<?= $periodo['id'] ?>" value="<?= $periodo['id'] ?>"
                                       <?= $periodo['activo'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="periodo_<?= $periodo['id'] ?>">
                                    <strong><?= htmlspecialchars($periodo['nombre_periodo']) ?></strong>
                                    <span class="badge bg-<?= strpos($periodo['periodo_actual'], 'bim') !== false ? 'info' : 'success' ?>">
                                        <?= strpos($periodo['periodo_actual'], 'bim') !== false ? 'Valoración' : 'Calificación' ?>
                                    </span>
                                    
                                    <?php if ($periodo['activo']): ?>
                                    <span class="badge bg-primary">ACTIVO</span>
                                    <?php endif; ?>
                                    
                                    <br>
                                    <small class="text-muted">
                                        <?php if (strpos($periodo['periodo_actual'], 'bim') !== false): ?>
                                            Permite cargar valoraciones preliminares (TEA/TEP/TED), desempeño académico y observaciones
                                        <?php else: ?>
                                            Permite cargar calificaciones numéricas, valoraciones cuatrimestrales e intensificación
                                        <?php endif; ?>
                                    </small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Actualizar Período Activo
                            </button>
                        </div>
                    </form>
                    
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <h6><i class="bi bi-exclamation-triangle"></i> Configuración Pendiente</h6>
                        <p>No se encontraron períodos configurados para el ciclo lectivo activo.</p>
                        
                        <?php if ($cicloLectivoId == 0): ?>
                        <p>Primero debe activar un ciclo lectivo en el sistema.</p>
                        <?php else: ?>
                        <p>Ejecute el script de configuración para inicializar los períodos:</p>
                        <a href="setup_valoraciones.php" class="btn btn-outline-primary">
                            <i class="bi bi-gear"></i> Ejecutar Configuración
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <!-- Estadísticas de uso -->
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title">Estadísticas de Uso</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="border rounded p-3">
                                <h4 class="text-info"><?= $estadisticas['valoraciones_1bim'] ?? 0 ?></h4>
                                <small class="text-muted">Valoraciones<br>1er Bimestre</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-3">
                                <h4 class="text-info"><?= $estadisticas['valoraciones_3bim'] ?? 0 ?></h4>
                                <small class="text-muted">Valoraciones<br>3er Bimestre</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-3">
                                <h4 class="text-success"><?= $estadisticas['calificaciones_1c'] ?? 0 ?></h4>
                                <small class="text-muted">Calificaciones<br>1er Cuatrimestre</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-3">
                                <h4 class="text-success"><?= $estadisticas['calificaciones_2c'] ?? 0 ?></h4>
                                <small class="text-muted">Calificaciones<br>2do Cuatrimestre</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Información del sistema -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="card-title">Información del Sistema</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <strong>Ciclo Lectivo Activo:</strong><br>
                        <span class="badge bg-primary"><?= $cicloActivo ? $cicloActivo['anio'] : 'No configurado' ?></span>
                    </div>
                    
                    <?php if ($cicloActivo): ?>
                    <div class="mb-2">
                        <strong>Período:</strong><br>
                        <?= date('d/m/Y', strtotime($cicloActivo['fecha_inicio'])) ?> - 
                        <?= date('d/m/Y', strtotime($cicloActivo['fecha_fin'])) ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-2">
                        <strong>Estado del Sistema:</strong><br>
                        <span class="badge bg-success">Operativo</span>
                    </div>
                </div>
            </div>
            
            <!-- Ayuda -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="card-title">Ayuda</h6>
                </div>
                <div class="card-body">
                    <h6>Tipos de Período:</h6>
                    <ul class="list-unstyled small">
                        <li><span class="badge bg-info">Valoración</span> <strong>Bimestral:</strong> Para cargar TEA/TEP/TED con desempeño y observaciones detalladas</li>
                        <li><span class="badge bg-success">Calificación</span> <strong>Cuatrimestral:</strong> Para cargar calificaciones numéricas e intensificación</li>
                    </ul>
                    
                    <hr>
                    
                    <h6>Recomendaciones:</h6>
                    <ul class="small">
                        <li>Configure <strong>1er Bimestre</strong> al inicio del año</li>
                        <li>Cambie a <strong>1er Cuatrimestre</strong> para calificaciones</li>
                        <li>Configure <strong>3er Bimestre</strong> en el segundo semestre</li>
                        <li>Finalice con <strong>2do Cuatrimestre</strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Confirmación antes de cambiar período
document.querySelector('form').addEventListener('submit', function(e) {
    const checked = document.querySelector('input[name="periodo_activo"]:checked');
    if (checked) {
        const label = document.querySelector(`label[for="${checked.id}"]`).textContent;
        if (!confirm(`¿Está seguro de que desea activar "${label.split('\n')[0]}"?\n\nEsto afectará la vista de carga para todos los profesores.`)) {
            e.preventDefault();
        }
    }
});
</script>

<?php require_once 'footer.php'; ?>