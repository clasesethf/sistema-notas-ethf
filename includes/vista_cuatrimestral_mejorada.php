<?php
/**
 * vista_cuatrimestral_mejorada.php - Vista mejorada para calificaciones cuatrimestrales
 * Incluye bloqueo de campos de valoración preliminar cuando provienen de valoraciones bimestrales
 */
?>

<div class="card">
    <div class="card-header bg-success text-white">
        <h6 class="card-title mb-0">
            <i class="bi bi-journal-text"></i> 
            Calificaciones Cuatrimestrales
        </h6>
    </div>
    <div class="card-body">
        <?php if (!empty($estudiantes)): ?>
        
        <!-- Información sobre los tipos de estudiantes -->
        <?php 
        $estudiantesRegulares = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'regular'; });
        $estudiantesRecursando = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'recursando'; });
        $estudiantesConSubgrupos = array_filter($estudiantes, function($e) { return !empty($e['subgrupo']); });
        ?>
        
        <?php if (count($estudiantesRecursando) > 0 || count($estudiantesConSubgrupos) > 0): ?>
        <div class="alert alert-info mb-3">
            <h6><i class="bi bi-info-circle"></i> Información de la lista:</h6>
            <ul class="mb-0">
                <?php if (count($estudiantesRegulares) > 0): ?>
                <li><strong><?= count($estudiantesRegulares) ?></strong> estudiantes regulares del curso</li>
                <?php endif; ?>
                <?php if (count($estudiantesRecursando) > 0): ?>
                <li><strong><?= count($estudiantesRecursando) ?></strong> estudiantes recursando esta materia</li>
                <?php endif; ?>
                <?php if (count($estudiantesConSubgrupos) > 0): ?>
                <li><strong><?= count($estudiantesConSubgrupos) ?></strong> estudiantes asignados a subgrupos</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Alerta sobre campos bloqueados -->
        <div class="alert alert-warning mb-3">
            <i class="bi bi-lock"></i> <strong>Campos protegidos:</strong> 
            Las valoraciones preliminares que provienen de calificaciones bimestrales están bloqueadas para preservar la integridad de los datos.
            <small class="d-block mt-1">Los campos con <i class="bi bi-shield-lock text-primary"></i> no pueden ser modificados.</small>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle" style="width: 3%">#</th>
                        <th rowspan="2" class="align-middle" style="width: 15%">Estudiante</th>
                        <th rowspan="2" class="align-middle" style="width: 8%">Tipo</th>
                        <?php if (count($estudiantesConSubgrupos) > 0): ?>
                        <th rowspan="2" class="align-middle" style="width: 8%">Subgrupo</th>
                        <?php endif; ?>
                        <th colspan="2" class="text-center">1° Cuatrimestre</th>
                        <th colspan="2" class="text-center">2° Cuatrimestre</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 8%">Intensif. 1°C</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 10%">Calif. Final</th>
                        <th rowspan="2" class="align-middle" style="width: 15%">Observaciones</th>
						<th rowspan="2" style="vertical-align: middle; width: 80px;">Acciones</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width: 8%">Valoración Preliminar</th>
                        <th class="text-center" style="width: 8%">Calificación</th>
                        <th class="text-center" style="width: 8%">Valoración Preliminar</th>
                        <th class="text-center" style="width: 8%">Calificación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contador = 1;
                    
                    // Agrupar estudiantes por subgrupo si existen
                    $estudiantesAgrupados = [];
                    if (count($estudiantesConSubgrupos) > 0) {
                        foreach ($estudiantes as $estudiante) {
                            $grupo = !empty($estudiante['subgrupo']) ? $estudiante['subgrupo'] : 'Sin subgrupo';
                            $estudiantesAgrupados[$grupo][] = $estudiante;
                        }
                        ksort($estudiantesAgrupados);
                    } else {
                        $estudiantesAgrupados['Todos'] = $estudiantes;
                    }
                    
                    foreach ($estudiantesAgrupados as $nombreGrupo => $estudiantesGrupo):
                    ?>
                    
                    <!-- Encabezado de grupo si hay subgrupos -->
                    <?php if (count($estudiantesConSubgrupos) > 0 && $nombreGrupo !== 'Todos'): ?>
                    <tr class="table-secondary">
                        <td colspan="<?= count($estudiantesConSubgrupos) > 0 ? '11' : '10' ?>" class="fw-bold">
                            <i class="bi bi-people"></i> <?= htmlspecialchars($nombreGrupo) ?> 
                            <span class="badge bg-info"><?= count($estudiantesGrupo) ?> estudiantes</span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php foreach ($estudiantesGrupo as $estudiante): ?>
                    <?php
                        $estudianteId = $estudiante['id'];
                        $calificacion = isset($calificaciones[$estudianteId]) ? $calificaciones[$estudianteId] : null;
                        
                        // Valores actuales
                        $valoracion1c = $calificacion['valoracion_preliminar_1c'] ?? null;
                        $calificacion1c = $calificacion['calificacion_1c'] ?? null;
                        $valoracion2c = $calificacion['valoracion_preliminar_2c'] ?? null;
                        $calificacion2c = $calificacion['calificacion_2c'] ?? null;
                        $intensificacion1c = $calificacion['intensificacion_1c'] ?? null;
                        $calificacionFinal = $calificacion['calificacion_final'] ?? null;
                        $observaciones = $calificacion['observaciones'] ?? null;
                        $tipoCursada = $calificacion['tipo_cursada'] ?? ($estudiante['tipo_matricula'] === 'recursando' ? 'R' : 'C');
                        
                        // Verificar si hay valoraciones bimestrales que bloquean la edición
                        $valoracion1bim = $calificacion['valoracion_1bim'] ?? null;
                        $valoracion3bim = $calificacion['valoracion_3bim'] ?? null;
                        
                        // Determinar si los campos están bloqueados
                        $bloquear1c = !empty($valoracion1bim);
                        $bloquear2c = !empty($valoracion3bim);
                        
                        // Si hay valoraciones bimestrales, usarlas como valor predeterminado
                        if ($valoracion1bim && !$valoracion1c) {
                            $valoracion1c = $valoracion1bim;
                        }
                        if ($valoracion3bim && !$valoracion2c) {
                            $valoracion2c = $valoracion3bim;
                        }
                    ?>
                    <tr class="<?= $estudiante['tipo_matricula'] === 'recursando' ? 'table-warning' : '' ?>">
                        <td><?= $contador++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                            <br>
                            <small class="text-muted">Matr.: <?= htmlspecialchars($estudiante['dni']) ?></small>
                            
                            <!-- Campo oculto para tipo de cursada -->
                            <input type="hidden" name="estudiantes[<?= $estudianteId ?>][tipo_cursada]" value="<?= $tipoCursada ?>">
                        </td>
                        <td>
                            <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                                <span class="badge bg-warning">R</span>
                                <small class="d-block">Recursando</small>
                            <?php else: ?>
                                <span class="badge bg-success">C</span>
                                <small class="d-block">Cursada</small>
                            <?php endif; ?>
                        </td>
                        <?php if (count($estudiantesConSubgrupos) > 0): ?>
                        <td>
                            <?php if (!empty($estudiante['subgrupo'])): ?>
                                <span class="badge bg-info"><?= htmlspecialchars($estudiante['subgrupo']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        
                        <!-- 1° Cuatrimestre - Valoración -->
                        <td>
                            <div class="position-relative">
                                <?php if ($bloquear1c): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm campo-bloqueado" disabled title="Campo protegido - Proviene de valoración bimestral">
                                        <option value="<?= $valoracion1c ?>" selected><?= $valoracion1c ?: '-' ?></option>
                                    </select>
                                    <!-- Campo oculto para enviar el valor -->
                                    <input type="hidden" name="estudiantes[<?= $estudianteId ?>][valoracion_1c]" value="<?= $valoracion1c ?>">
                                    <!-- Icono de bloqueo -->
                                    <i class="bi bi-shield-lock position-absolute top-50 end-0 translate-middle-y me-2 text-primary" title="Protegido por valoración bimestral"></i>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][valoracion_1c]" class="form-select form-select-sm">
                                        <option value="">-</option>
                                        <option value="TEA" <?= $valoracion1c === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                        <option value="TEP" <?= $valoracion1c === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                        <option value="TED" <?= $valoracion1c === 'TED' ? 'selected' : '' ?>>TED</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <?php if ($bloquear1c): ?>
                                <small class="text-info">Desde 1er bimestre</small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 1° Cuatrimestre - Calificación -->
                        <td>
                            <select name="estudiantes[<?= $estudianteId ?>][calificacion_1c]" 
                                    class="form-select form-select-sm text-center calificacion-numerica"
                                    data-estudiante="<?= $estudianteId ?>"
                                    data-periodo="1c">
                                <option value="">-</option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i ?>" 
                                        <?= $calificacion1c == $i ? 'selected' : '' ?>
                                        class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                    <?= $i ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Valoración -->
                        <td>
                            <div class="position-relative">
                                <?php if ($bloquear2c): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm campo-bloqueado" disabled title="Campo protegido - Proviene de valoración bimestral">
                                        <option value="<?= $valoracion2c ?>" selected><?= $valoracion2c ?: '-' ?></option>
                                    </select>
                                    <!-- Campo oculto para enviar el valor -->
                                    <input type="hidden" name="estudiantes[<?= $estudianteId ?>][valoracion_2c]" value="<?= $valoracion2c ?>">
                                    <!-- Icono de bloqueo -->
                                    <i class="bi bi-shield-lock position-absolute top-50 end-0 translate-middle-y me-2 text-primary" title="Protegido por valoración bimestral"></i>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][valoracion_2c]" class="form-select form-select-sm">
                                        <option value="">-</option>
                                        <option value="TEA" <?= $valoracion2c === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                        <option value="TEP" <?= $valoracion2c === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                        <option value="TED" <?= $valoracion2c === 'TED' ? 'selected' : '' ?>>TED</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <?php if ($bloquear2c): ?>
                                <small class="text-info">Desde 3er bimestre</small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Calificación -->
                        <td>
                            <select name="estudiantes[<?= $estudianteId ?>][calificacion_2c]" 
                                    class="form-select form-select-sm text-center calificacion-numerica"
                                    data-estudiante="<?= $estudianteId ?>"
                                    data-periodo="2c">
                                <option value="">-</option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i ?>" 
                                        <?= $calificacion2c == $i ? 'selected' : '' ?>
                                        class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                    <?= $i ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        
                        <!-- Intensificación 1° Cuatrimestre -->
                        <td>
                            <select name="estudiantes[<?= $estudianteId ?>][intensificacion_1c]" 
                                    class="form-select form-select-sm text-center calificacion-numerica"
                                    data-estudiante="<?= $estudianteId ?>"
                                    data-periodo="intensif">
                                <option value="">-</option>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i ?>" 
                                        <?= $intensificacion1c == $i ? 'selected' : '' ?>
                                        class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                    <?= $i ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        
                        <!-- Calificación Final -->
                        <td>
                            <div class="d-flex align-items-center">
                                <select name="estudiantes[<?= $estudianteId ?>][calificacion_final]" 
                                        class="form-select form-select-sm text-center calificacion-final me-1"
                                        data-estudiante="<?= $estudianteId ?>"
                                        style="flex: 1;">
                                    <option value="">-</option>
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" 
                                            <?= $calificacionFinal == $i ? 'selected' : '' ?>
                                            class="<?= $i < 4 ? 'text-danger fw-bold' : ($i < 7 ? 'text-warning fw-bold' : 'text-success fw-bold') ?>">
                                        <?= $i ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                                <button type="button"
                                        class="btn btn-outline-info btn-sm p-0"
                                        onclick="calcularPromedio(<?= $estudianteId ?>)"
                                        title="Calcular promedio automático"
                                        style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-calculator" style="font-size: 18px;"></i>
                                </button>
                            </div>
                        </td>
                        
                        <!-- Observaciones -->
                        <td>
                            <?php if (!empty($observacionesPredefinidas)): ?>
                            <div class="observaciones-container">
                                <!-- Botón para mostrar/ocultar panel de observaciones -->
                                <button type="button" 
                                        class="btn btn-outline-secondary btn-sm w-100 mb-2" 
                                        data-bs-toggle="collapse" 
                                        data-bs-target="#observaciones_panel_<?= $estudianteId ?>" 
                                        aria-expanded="false">
                                    <i class="bi bi-list-check"></i> Seleccionar Observaciones
                                </button>
                                
                                <!-- Panel colapsable con observaciones -->
                                <div class="collapse" id="observaciones_panel_<?= $estudianteId ?>">
                                    <div class="card card-body p-2" style="max-height: 200px; overflow-y: auto;">
                                        <?php 
                                        $categoriaActual = '';
                                        $observacionesSeleccionadas = !empty($observaciones) ? explode('. ', $observaciones) : [];
                                        
                                        foreach ($observacionesPredefinidas as $index => $obs): 
                                            if ($obs['categoria'] !== $categoriaActual): 
                                                if ($categoriaActual !== ''): ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="categoria-observaciones mb-2">
                                                    <h6 class="text-primary mb-1" style="font-size: 12px;">
                                                        <i class="bi bi-tag"></i> <?= htmlspecialchars($obs['categoria']) ?>
                                                    </h6>
                                                <?php $categoriaActual = $obs['categoria']; 
                                            endif; 
                                            
                                            $seleccionada = in_array(trim($obs['mensaje']), array_map('trim', $observacionesSeleccionadas));
                                            $checkboxId = "obs_{$estudianteId}_{$index}";
                                        ?>
                                        <div class="form-check form-check-sm">
                                            <input class="form-check-input observacion-checkbox" 
                                                   type="checkbox" 
                                                   id="<?= $checkboxId ?>" 
                                                   value="<?= htmlspecialchars($obs['mensaje']) ?>"
                                                   data-estudiante="<?= $estudianteId ?>"
                                                   data-categoria="<?= htmlspecialchars($obs['categoria']) ?>"
                                                   <?= $seleccionada ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="<?= $checkboxId ?>" style="font-size: 11px;">
                                                <?= htmlspecialchars($obs['mensaje']) ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if ($categoriaActual !== ''): ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Botones de acción rápida -->
                                        <div class="mt-2 pt-2 border-top">
                                            <div class="btn-group btn-group-sm w-100" role="group">
                                                <button type="button" 
                                                        class="btn btn-outline-success btn-sm"
                                                        onclick="seleccionarTodasObservaciones(<?= $estudianteId ?>)">
                                                    <i class="bi bi-check-all"></i> Todas
                                                </button>
                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm"
                                                        onclick="limpiarObservaciones(<?= $estudianteId ?>)">
                                                    <i class="bi bi-x-circle"></i> Ninguna
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
								
								
                                
                                <!-- Resumen de observaciones seleccionadas -->
                                <div class="observaciones-resumen mt-2" id="resumen_<?= $estudianteId ?>">
                                    <?php if (!empty($observaciones)): ?>
                                    <div class="alert alert-info alert-sm p-2 mb-0">
                                        <small><strong>Observaciones:</strong><br>
                                        <?= htmlspecialchars($observaciones) ?></small>
                                    </div>
                                    <?php else: ?>
                                    <small class="text-muted">Sin observaciones seleccionadas</small>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Campo oculto para almacenar el valor final -->
                                <input type="hidden" 
                                       name="estudiantes[<?= $estudianteId ?>][observaciones]" 
                                       id="observaciones_final_<?= $estudianteId ?>"
                                       value="<?= htmlspecialchars($observaciones ?? '') ?>">
                            </div>
                            
                            <?php else: ?>
                            <!-- Fallback si no hay observaciones predefinidas -->
                            <div class="alert alert-warning alert-sm p-2">
                                <small><i class="bi bi-exclamation-triangle"></i> No hay observaciones predefinidas configuradas</small>
                            </div>
                            <input type="hidden" name="estudiantes[<?= $estudianteId ?>][observaciones]" value="">
                            <?php endif; ?>
                        </td>
						<td class="text-center">
							<a href="detalle_calificaciones_contenidos.php?estudiante=<?= $estudiante['id'] ?>&materia=<?= $materiaSeleccionada ?>&origen=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
							   class="btn btn-sm btn-info" 
							   title="Ver detalle de calificaciones por contenido">
								<i class="bi bi-list-check"></i>
								<span class="d-none d-md-inline">Detalle</span>
							</a>
						</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Referencias y ayuda -->
        <div class="mt-3">
            <div class="row">
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Valoraciones:</h6>
                            <ul class="list-unstyled mb-0">
                                <li><span class="badge bg-success">TEA</span> Trayectoria Educativa Avanzada</li>
                                <li><span class="badge bg-warning">TEP</span> Trayectoria Educativa en Proceso</li>
                                <li><span class="badge bg-danger">TED</span> Trayectoria Educativa Discontinua</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Calificaciones:</h6>
                            <ul class="list-unstyled mb-0">
                                <li><strong>1-3:</strong> Desaprobado</li>
                                <li><strong>4-6:</strong> Aprobado</li>
                                <li><strong>7-10:</strong> Muy Bueno/Excelente</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            No se encontraron estudiantes para cargar calificaciones.
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Estilos para campos bloqueados */
.campo-bloqueado {
    background-color: #f8f9fa !important;
    border-color: #6c757d !important;
    color: #495057 !important;
    cursor: not-allowed !important;
}

.campo-bloqueado:disabled {
    opacity: 0.8;
}

/* Efecto visual para campos protegidos */
.position-relative .campo-bloqueado {
    background-image: linear-gradient(45deg, transparent 40%, rgba(108, 117, 125, 0.1) 50%, transparent 60%);
}

/* Estilos para observaciones con checkboxes */
.observaciones-container {
    min-width: 250px;
}

.categoria-observaciones {
    background-color: #f8f9fa;
    border-radius: 4px;
    padding: 6px;
    margin-bottom: 8px;
}

.categoria-observaciones h6 {
    margin-bottom: 6px !important;
    padding-bottom: 3px;
    border-bottom: 1px solid #dee2e6;
}

.form-check-sm .form-check-input {
    margin-top: 0.1rem;
    transform: scale(0.9);
}

.form-check-sm .form-check-label {
    line-height: 1.3;
    cursor: pointer;
}

.observaciones-resumen .alert-sm {
    font-size: 11px;
    line-height: 1.4;
}

/* Scroll personalizado para el panel */
.observaciones-container .card-body::-webkit-scrollbar {
    width: 6px;
}

.observaciones-container .card-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.observaciones-container .card-body::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.observaciones-container .card-body::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Animación suave para el colapso */
.collapse {
    transition: all 0.3s ease;
}
.calificacion-numerica {
    font-weight: 600;
}

.calificacion-numerica option {
    padding: 5px;
    font-weight: 600;
}

/* Estilos específicos para calificación final */
.calificacion-final {
    font-weight: 700;
    font-size: 14px;
}

.calificacion-final option {
    font-weight: 700;
    padding: 6px;
}

/* Colores para diferentes rangos de calificaciones */
.calificacion-numerica.border-success,
.calificacion-final.border-success {
    border-color: #28a745 !important;
    border-width: 2px !important;
}

.calificacion-numerica.border-warning,
.calificacion-final.border-warning {
    border-color: #ffc107 !important;
    border-width: 2px !important;
}

.calificacion-numerica.border-danger,
.calificacion-final.border-danger {
    border-color: #dc3545 !important;
    border-width: 2px !important;
}

/* Hover effects para selects de calificación */
.calificacion-numerica:hover,
.calificacion-final:hover {
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    transition: box-shadow 0.15s ease-in-out;
}

/* Tooltip mejorado para campos bloqueados */
.campo-bloqueado[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: white;
    padding: 5px 8px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
}
</style>

<script>
// Validación y mejoras para calificaciones cuatrimestrales con campos bloqueados
document.addEventListener('DOMContentLoaded', function() {
    
    // Función para calcular promedio automático (adaptada para selects)
    window.calcularPromedio = function(estudianteId) {
        const cal1c = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_1c]"]`);
        const cal2c = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_2c]"]`);
        const intensif = document.querySelector(`select[name="estudiantes[${estudianteId}][intensificacion_1c]"]`);
        const final = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_final]"]`);
        
        if (!cal1c || !cal2c || !final) {
            console.error('No se encontraron los campos necesarios para el estudiante', estudianteId);
            return;
        }
        
        const valor1 = parseFloat(cal1c.value) || 0;
        const valor2 = parseFloat(cal2c.value) || 0;
        const valorIntensif = parseFloat(intensif.value) || 0;
        
        let promedio = 0;
        let calculoDetalle = '';
        
        // Lógica de cálculo según la Resolución N° 1650/24
        if (valor1 > 0 && valor2 > 0) {
            if (valorIntensif > 0) {
                const promedioNormal = (valor1 + valor2) / 2;
                promedio = Math.max(promedioNormal, valorIntensif);
                
                if (promedio === valorIntensif) {
                    calculoDetalle = `Intensificación: ${valorIntensif} (mayor que promedio ${promedioNormal.toFixed(1)})`;
                } else {
                    calculoDetalle = `Promedio: (${valor1} + ${valor2}) / 2 = ${promedio.toFixed(1)}`;
                }
            } else {
                promedio = (valor1 + valor2) / 2;
                calculoDetalle = `Promedio: (${valor1} + ${valor2}) / 2 = ${promedio.toFixed(1)}`;
            }
            
            promedio = Math.round(promedio);
            
            const mensaje = `Cálculo automático:\n${calculoDetalle}\nResultado final: ${promedio}\n\n¿Desea aplicar esta calificación?`;
            
            if (confirm(mensaje)) {
                final.value = promedio;
                actualizarEstiloCalificacion(final);
                
                // Efecto visual de confirmación
                final.style.backgroundColor = '#d4edda';
                final.style.transition = 'background-color 0.3s';
                
                setTimeout(() => {
                    final.style.backgroundColor = '';
                }, 1500);
                
                final.title = `Calculado automáticamente: ${calculoDetalle}`;
            }
            
        } else if (valor1 > 0 && valor2 === 0) {
            alert('Falta la calificación del 2° cuatrimestre para calcular el promedio.');
            cal2c.focus();
        } else if (valor1 === 0 && valor2 > 0) {
            alert('Falta la calificación del 1° cuatrimestre para calcular el promedio.');
            cal1c.focus();
        } else {
            alert('Debe ingresar las calificaciones de ambos cuatrimestres para calcular el promedio.');
            cal1c.focus();
        }
    };
    
    // Destacar calificaciones según el valor (adaptado para selects)
    function actualizarEstiloCalificacion(select) {
        const valor = parseInt(select.value);
        select.classList.remove('border-success', 'border-warning', 'border-danger');
        
        if (valor >= 7) {
            select.classList.add('border-success');
        } else if (valor >= 4) {
            select.classList.add('border-warning');
        } else if (valor >= 1) {
            select.classList.add('border-danger');
        }
    }
    
    // Aplicar estilos a todas las calificaciones numéricas
    document.querySelectorAll('.calificacion-numerica, .calificacion-final').forEach(function(select) {
        select.addEventListener('change', function() {
            actualizarEstiloCalificacion(this);
            
            // Actualizar sugerencia de promedio en tiempo real
            if (this.classList.contains('calificacion-numerica')) {
                const matches = this.name.match(/estudiantes\[(\d+)\]/);
                if (matches) {
                    mostrarSugerenciaPromedio(matches[1]);
                }
            }
        });
        
        // Aplicar estilo inicial
        if (select.value) {
            actualizarEstiloCalificacion(select);
        }
    });
    
    // Función para mostrar sugerencia de promedio
    function mostrarSugerenciaPromedio(estudianteId) {
        const cal1c = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_1c]"]`);
        const cal2c = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_2c]"]`);
        const intensif = document.querySelector(`select[name="estudiantes[${estudianteId}][intensificacion_1c]"]`);
        const final = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_final]"]`);
        
        if (cal1c && cal2c && final && !final.value) {
            const valor1 = parseFloat(cal1c.value) || 0;
            const valor2 = parseFloat(cal2c.value) || 0;
            const valorIntensif = parseFloat(intensif.value) || 0;
            
            if (valor1 > 0 && valor2 > 0) {
                let promedio = (valor1 + valor2) / 2;
                
                if (valorIntensif > 0) {
                    promedio = Math.max(promedio, valorIntensif);
                }
                
                promedio = Math.round(promedio);
                
                // Actualizar la opción vacía con la sugerencia
                const opcionVacia = final.querySelector('option[value=""]');
                if (opcionVacia) {
                    opcionVacia.textContent = `Sugerido: ${promedio}`;
                    opcionVacia.style.fontStyle = 'italic';
                    opcionVacia.style.color = '#007bff';
                }
                
                final.style.backgroundColor = '#f8f9fa';
                
                // Cambiar color del botón calculadora
                const botonCalculadora = final.parentElement.querySelector('.btn-outline-info');
                if (botonCalculadora) {
                    botonCalculadora.classList.remove('btn-outline-info');
                    botonCalculadora.classList.add('btn-outline-success');
                    botonCalculadora.title = `Calcular promedio: ${promedio}`;
                }
            } else {
                // Limpiar sugerencia
                const opcionVacia = final.querySelector('option[value=""]');
                if (opcionVacia) {
                    opcionVacia.textContent = '-';
                    opcionVacia.style.fontStyle = '';
                    opcionVacia.style.color = '';
                }
                
                final.style.backgroundColor = '';
                
                const botonCalculadora = final.parentElement.querySelector('.btn-outline-success');
                if (botonCalculadora) {
                    botonCalculadora.classList.remove('btn-outline-success');
                    botonCalculadora.classList.add('btn-outline-info');
                    botonCalculadora.title = 'Calcular promedio automático';
                }
            }
        }
    }
    
    // Validar que las calificaciones estén en el rango correcto
    document.querySelectorAll('input[type="number"]').forEach(function(input) {
        input.addEventListener('input', function() {
            const valor = parseInt(this.value);
            
            if (this.value && (valor < 1 || valor > 10)) {
                this.classList.add('is-invalid');
                this.setCustomValidity('La calificación debe estar entre 1 y 10');
            } else {
                this.classList.remove('is-invalid');
                this.setCustomValidity('');
            }
        });
    });
    
    
    // Manejo de observaciones con checkboxes
    document.querySelectorAll('.observacion-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const estudianteId = this.dataset.estudiante;
            actualizarObservacionesCheckbox(estudianteId);
        });
    });
    
    // Función para actualizar observaciones desde checkboxes
    function actualizarObservacionesCheckbox(estudianteId) {
        const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox:checked`);
        const campoFinal = document.getElementById(`observaciones_final_${estudianteId}`);
        const resumen = document.getElementById(`resumen_${estudianteId}`);
        
        if (campoFinal && resumen) {
            const observacionesSeleccionadas = Array.from(checkboxes).map(cb => cb.value);
            const textoFinal = observacionesSeleccionadas.join('. ');
            
            // Actualizar campo oculto
            campoFinal.value = textoFinal;
            
            // Actualizar resumen visual
            if (observacionesSeleccionadas.length > 0) {
                resumen.innerHTML = `
                    <div class="alert alert-info alert-sm p-2 mb-0">
                        <small><strong>Observaciones (${observacionesSeleccionadas.length}):</strong><br>
                        ${textoFinal}</small>
                    </div>
                `;
            } else {
                resumen.innerHTML = '<small class="text-muted">Sin observaciones seleccionadas</small>';
            }
            
            // Actualizar el texto del botón
            const boton = document.querySelector(`button[data-bs-target="#observaciones_panel_${estudianteId}"]`);
            if (boton) {
                const numSeleccionadas = observacionesSeleccionadas.length;
                if (numSeleccionadas > 0) {
                    boton.innerHTML = `<i class="bi bi-list-check"></i> Observaciones (${numSeleccionadas})`;
                    boton.classList.remove('btn-outline-secondary');
                    boton.classList.add('btn-outline-primary');
                } else {
                    boton.innerHTML = '<i class="bi bi-list-check"></i> Seleccionar Observaciones';
                    boton.classList.remove('btn-outline-primary');
                    boton.classList.add('btn-outline-secondary');
                }
            }
        }
    }
    
    // Funciones para botones de acción rápida
    window.seleccionarTodasObservaciones = function(estudianteId) {
        const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox`);
        checkboxes.forEach(cb => {
            cb.checked = true;
        });
        actualizarObservacionesCheckbox(estudianteId);
    };
    
    window.limpiarObservaciones = function(estudianteId) {
        const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox`);
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
        actualizarObservacionesCheckbox(estudianteId);
    };
    
    // Inicializar estado de observaciones al cargar
    document.querySelectorAll('.observacion-checkbox').forEach(function(checkbox) {
        const estudianteId = checkbox.dataset.estudiante;
        // Solo actualizar una vez por estudiante
        if (!checkbox.dataset.inicializado) {
            actualizarObservacionesCheckbox(estudianteId);
            // Marcar todos los checkboxes de este estudiante como inicializados
            document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox`).forEach(cb => {
                cb.dataset.inicializado = 'true';
            });
        }
    });
    document.querySelectorAll('.campo-bloqueado').forEach(function(campo) {
        campo.addEventListener('mouseenter', function() {
            this.style.cursor = 'not-allowed';
        });
        
        // Mostrar mensaje informativo al intentar hacer clic
        campo.addEventListener('click', function(e) {
            e.preventDefault();
            const tooltip = document.createElement('div');
            tooltip.className = 'alert alert-info alert-dismissible fade show position-fixed';
            tooltip.style.top = '20px';
            tooltip.style.right = '20px';
            tooltip.style.zIndex = '9999';
            tooltip.style.maxWidth = '300px';
            tooltip.innerHTML = `
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <strong>Campo protegido</strong><br>
                Esta valoración proviene de una calificación bimestral y no puede ser modificada desde aquí.
                <br><small>Para cambiarla, modifique la valoración bimestral correspondiente.</small>
            `;
            
            document.body.appendChild(tooltip);
            
            // Auto-remover después de 5 segundos
            setTimeout(() => {
                if (tooltip.parentNode) {
                    tooltip.remove();
                }
            }, 5000);
        });
    });
    
    // Validación final del formulario
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            let hayDatos = false;
            let errores = [];
            
            // Verificar que haya al menos una calificación cargada
            document.querySelectorAll('select.calificacion-numerica, select.calificacion-final').forEach(function(select) {
                if (select.value) {
                    hayDatos = true;
                }
            });
            
            // También verificar valoraciones (incluyendo las bloqueadas)
            document.querySelectorAll('select[name*="[valoracion"], input[type="hidden"][name*="[valoracion"]').forEach(function(campo) {
                if (campo.value) {
                    hayDatos = true;
                }
            });
            document.querySelectorAll('select[name*="[valoracion"], input[type="hidden"][name*="[valoracion"]').forEach(function(campo) {
                if (campo.value) {
                    hayDatos = true;
                }
            });
            
            if (!hayDatos) {
                e.preventDefault();
                alert('Debe cargar al menos una calificación o valoración antes de guardar.');
                return false;
            }
            
            if (errores.length > 0) {
                e.preventDefault();
                alert('Errores encontrados:\n' + errores.join('\n'));
                return false;
            }
            
            // Confirmar envío si hay campos bloqueados
            const camposBloqueados = document.querySelectorAll('.campo-bloqueado').length;
            if (camposBloqueados > 0) {
                const confirmar = confirm(`Se guardarán las calificaciones.\n\nNota: ${camposBloqueados} valoración(es) preliminar(es) están protegidas y mantendrán sus valores actuales.\n\n¿Continuar?`);
                if (!confirmar) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
});
</script>