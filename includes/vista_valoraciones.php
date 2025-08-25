<?php
/**
 * vista_valoraciones.php - Vista para cargar valoraciones preliminares (bimestrales)
 * Incluir en calificaciones.php cuando $tipoCarga === 'valoracion'
 */

// Determinar bimestre actual
$bimestreActual = isset($_GET['bimestre']) ? $_GET['bimestre'] : '1';
?>

<div class="alert alert-info">
    <h6><i class="bi bi-info-circle"></i> Carga de Valoraciones Preliminares</h6>
    <p class="mb-0">
        Las valoraciones preliminares se registran en el <strong><?= $bimestreActual == '1' ? '1er' : '3er' ?> Bimestre</strong> 
        e incluyen la trayectoria educativa (TEA/TEP/TED), el desempeño académico y observaciones específicas.
    </p>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-hover valoraciones-table">
        <thead class="table-primary">
            <tr>
                <th rowspan="2" style="vertical-align: middle; width: 200px;">Estudiante</th>
                <th rowspan="2" style="vertical-align: middle; width: 80px;">N°</th>
                <th colspan="3" class="text-center">Valoración del <?= $bimestreActual == '1' ? '1er' : '3er' ?> Bimestre</th>
                <th rowspan="2" style="vertical-align: middle; width: 300px;">Observaciones</th>
                <th rowspan="2" style="vertical-align: middle; width: 150px;">Acciones</th>
            </tr>
            <tr>
                <th class="text-center" style="width: 100px;">Valoración Preliminar</th>
                <th class="text-center" style="width: 120px;">Desempeño Académico</th>
                <th style="width: 100px;">Estado Visual</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $contador = 1;
            foreach ($estudiantes as $estudiante): 
                $calificacion = isset($calificaciones[$estudiante['id']]) ? $calificaciones[$estudiante['id']] : null;
                
                // Obtener datos del bimestre actual
                $campo_valoracion = 'valoracion_' . $bimestreActual . 'bim';
                $campo_desempeno = 'desempeno_' . $bimestreActual . 'bim';
                $campo_observaciones = 'observaciones_' . $bimestreActual . 'bim';
                
                $valoracion = $calificacion ? ($calificacion[$campo_valoracion] ?? '') : '';
                $desempeno = $calificacion ? ($calificacion[$campo_desempeno] ?? '') : '';
                $observaciones = $calificacion ? ($calificacion[$campo_observaciones] ?? '') : '';
                
                // Determinar clase CSS según valoración
                $claseValoracion = '';
                $estadoVisual = '';
                switch($valoracion) {
                    case 'TEA':
                        $claseValoracion = 'table-success';
                        $estadoVisual = '<span class="badge bg-success">TEA</span>';
                        break;
                    case 'TEP':
                        $claseValoracion = 'table-warning';
                        $estadoVisual = '<span class="badge bg-warning text-dark">TEP</span>';
                        break;
                    case 'TED':
                        $claseValoracion = 'table-danger';
                        $estadoVisual = '<span class="badge bg-danger">TED</span>';
                        break;
                    default:
                        $claseValoracion = '';
                        $estadoVisual = '<span class="badge bg-secondary">Sin valorar</span>';
                }
            ?>
            <tr class="<?= $claseValoracion ?>" id="fila_<?= $estudiante['id'] ?>">
                <td>
                    <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                    <br><small class="text-muted">DNI: <?= htmlspecialchars($estudiante['dni']) ?></small>
                    <input type="hidden" name="estudiantes[<?= $estudiante['id'] ?>][id]" value="<?= $estudiante['id'] ?>">
                </td>
                <td class="text-center">
                    <strong><?= $contador ?></strong>
                </td>
                <td>
                    <select name="estudiantes[<?= $estudiante['id'] ?>][valoracion]" 
                            class="form-select valoracion-select" 
                            data-estudiante="<?= $estudiante['id'] ?>"
                            onchange="actualizarEstadoVisual(<?= $estudiante['id'] ?>, this.value)">
                        <option value="" <?= empty($valoracion) ? 'selected' : '' ?>>-- Seleccionar --</option>
                        <option value="TEA" <?= $valoracion == 'TEA' ? 'selected' : '' ?>>TEA</option>
                        <option value="TEP" <?= $valoracion == 'TEP' ? 'selected' : '' ?>>TEP</option>
                        <option value="TED" <?= $valoracion == 'TED' ? 'selected' : '' ?>>TED</option>
                    </select>
                </td>
                <td>
                    <select name="estudiantes[<?= $estudiante['id'] ?>][desempeno]" class="form-select">
                        <option value="" <?= empty($desempeno) ? 'selected' : '' ?>>-- Seleccionar --</option>
                        <option value="Regular" <?= $desempeno == 'Regular' ? 'selected' : '' ?>>Regular</option>
                        <option value="Bueno" <?= $desempeno == 'Bueno' ? 'selected' : '' ?>>Bueno</option>
                        <option value="Muy bueno" <?= $desempeno == 'Muy bueno' ? 'selected' : '' ?>>Muy bueno</option>
                        <option value="Excelente" <?= $desempeno == 'Excelente' ? 'selected' : '' ?>>Excelente</option>
                    </select>
                </td>
                <td class="text-center" id="estado_visual_<?= $estudiante['id'] ?>">
                    <?= $estadoVisual ?>
                </td>
                <td>
                    <textarea name="estudiantes[<?= $estudiante['id'] ?>][observaciones]" 
                              class="form-control" 
                              rows="2" 
                              placeholder="Observaciones específicas..."><?= htmlspecialchars($observaciones) ?></textarea>
                    <div class="mt-1">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-success btn-sm" 
                                    onclick="mostrarObservaciones(<?= $estudiante['id'] ?>, 'positiva')"
                                    title="Observaciones positivas">
                                <i class="bi bi-plus-circle"></i> +
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm" 
                                    onclick="mostrarObservaciones(<?= $estudiante['id'] ?>, 'mejora')"
                                    title="Aspectos a mejorar">
                                <i class="bi bi-exclamation-triangle"></i> !
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" 
                                    onclick="limpiarObservaciones(<?= $estudiante['id'] ?>)"
                                    title="Limpiar observaciones">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="btn-group-vertical" role="group">
                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                onclick="copiarFila(<?= $estudiante['id'] ?>)"
                                title="Copiar desde otro bimestre">
                            <i class="bi bi-copy"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" 
                                onclick="limpiarFila(<?= $estudiante['id'] ?>)"
                                title="Limpiar fila">
                            <i class="bi bi-eraser"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php 
            $contador++;
            endforeach; 
            ?>
        </tbody>
    </table>
</div>

<!-- Referencias y ayuda -->
<div class="row mt-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Referencias de Valoración</h6>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li><span class="badge bg-success">TEA</span> <strong>Trayectoria Educativa Avanzada:</strong> Estudiante que alcanzó los aprendizajes y mantiene buena vinculación pedagógica.</li>
                    <li><span class="badge bg-warning text-dark">TEP</span> <strong>Trayectoria Educativa en Proceso:</strong> No alcanzó suficientemente los aprendizajes, pero mantiene buena vinculación.</li>
                    <li><span class="badge bg-danger">TED</span> <strong>Trayectoria Educativa Discontinua:</strong> No alcanzó los aprendizajes y tuvo escasa vinculación pedagógica.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Desempeño Académico</h6>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li><strong>Regular:</strong> Cumple con los requisitos básicos</li>
                    <li><strong>Bueno:</strong> Desempeño satisfactorio y constante</li>
                    <li><strong>Muy bueno:</strong> Desempeño destacado en la mayoría de aspectos</li>
                    <li><strong>Excelente:</strong> Desempeño sobresaliente en todos los aspectos</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Modal para observaciones predefinidas -->
<div class="modal fade" id="modalObservaciones" tabindex="-1" aria-labelledby="modalObservacionesLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalObservacionesLabel">Observaciones Predefinidas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoObservaciones">
                    <!-- Se carga dinámicamente -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Variables globales para observaciones
let observacionesPredefinidas = <?= json_encode($observacionesPredefinidas) ?>;
let estudianteActual = null;

// Función para actualizar estado visual
function actualizarEstadoVisual(estudianteId, valoracion) {
    const estadoDiv = document.getElementById('estado_visual_' + estudianteId);
    const fila = document.getElementById('fila_' + estudianteId);
    
    // Limpiar clases anteriores
    fila.classList.remove('table-success', 'table-warning', 'table-danger');
    
    let badge = '';
    switch(valoracion) {
        case 'TEA':
            badge = '<span class="badge bg-success">TEA</span>';
            fila.classList.add('table-success');
            break;
        case 'TEP':
            badge = '<span class="badge bg-warning text-dark">TEP</span>';
            fila.classList.add('table-warning');
            break;
        case 'TED':
            badge = '<span class="badge bg-danger">TED</span>';
            fila.classList.add('table-danger');
            break;
        default:
            badge = '<span class="badge bg-secondary">Sin valorar</span>';
    }
    
    estadoDiv.innerHTML = badge;
}

// Función para mostrar observaciones predefinidas
function mostrarObservaciones(estudianteId, tipo) {
    estudianteActual = estudianteId;
    
    const observacionesFiltradas = observacionesPredefinidas.filter(obs => obs.tipo === tipo);
    
    let contenido = `<h6>Observaciones ${tipo === 'positiva' ? 'Positivas' : 'para Mejorar'}</h6>`;
    contenido += '<div class="list-group">';
    
    observacionesFiltradas.forEach(obs => {
        contenido += `
            <button type="button" class="list-group-item list-group-item-action" 
                    onclick="insertarObservacion(${estudianteId}, '${obs.mensaje.replace(/'/g, "\\'")}')">
                <div class="d-flex w-100 justify-content-between">
                    <small class="text-muted">${obs.categoria.toUpperCase()}</small>
                </div>
                <p class="mb-1">${obs.mensaje}</p>
            </button>
        `;
    });
    
    contenido += '</div>';
    
    document.getElementById('contenidoObservaciones').innerHTML = contenido;
    
    const modal = new bootstrap.Modal(document.getElementById('modalObservaciones'));
    modal.show();
}

// Función para insertar observación
function insertarObservacion(estudianteId, mensaje) {
    const textarea = document.querySelector(`textarea[name="estudiantes[${estudianteId}][observaciones]"]`);
    if (textarea) {
        if (textarea.value.trim() !== '') {
            textarea.value += '. ' + mensaje;
        } else {
            textarea.value = mensaje;
        }
    }
    
    // Cerrar modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalObservaciones'));
    modal.hide();
}

// Función para limpiar observaciones
function limpiarObservaciones(estudianteId) {
    const textarea = document.querySelector(`textarea[name="estudiantes[${estudianteId}][observaciones]"]`);
    if (textarea) {
        textarea.value = '';
    }
}

// Función para limpiar fila completa
function limpiarFila(estudianteId) {
    if (confirm('¿Está seguro de que desea limpiar todos los datos de este estudiante?')) {
        // Limpiar valoración
        const selectValoracion = document.querySelector(`select[name="estudiantes[${estudianteId}][valoracion]"]`);
        if (selectValoracion) {
            selectValoracion.value = '';
            actualizarEstadoVisual(estudianteId, '');
        }
        
        // Limpiar desempeño
        const selectDesempeno = document.querySelector(`select[name="estudiantes[${estudianteId}][desempeno]"]`);
        if (selectDesempeno) {
            selectDesempeno.value = '';
        }
        
        // Limpiar observaciones
        limpiarObservaciones(estudianteId);
    }
}

// Función para copiar desde otro bimestre (placeholder)
function copiarFila(estudianteId) {
    alert('Funcionalidad de copia en desarrollo. Próximamente podrá copiar datos desde el otro bimestre.');
}
</script>

<style>
.valoraciones-table {
    font-size: 0.9rem;
}

.valoraciones-table th {
    background-color: #e3f2fd;
    font-weight: bold;
    text-align: center;
    vertical-align: middle;
}

.table-success {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.table-warning {
    background-color: rgba(255, 193, 7, 0.1) !important;
}

.table-danger {
    background-color: rgba(220, 53, 69, 0.1) !important;
}

.btn-group-vertical .btn {
    margin-bottom: 2px;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}
</style>