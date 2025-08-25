<?php
/**
 * vista_valoraciones_con_bloqueos.php - Vista completa para valoraciones con sistema de bloqueos integrado
 */

$bimestreActual = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 1;
$bimestreTexto = ($bimestreActual == 1) ? '1er' : '3er';

// Determinar campos de bloqueo según el bimestre
$campoValoracionBloqueada = ($bimestreActual == 1) ? 'valoracion_1bim_bloqueada' : 'valoracion_3bim_bloqueada';
$campoDesempenoBloqueado = ($bimestreActual == 1) ? 'desempeno_1bim_bloqueado' : 'desempeno_3bim_bloqueado';
$campoObservacionesBloqueadas = ($bimestreActual == 1) ? 'observaciones_1bim_bloqueadas' : 'observaciones_3bim_bloqueadas';

// Funciones auxiliares si no están disponibles (fallback)
if (!function_exists('estaColumnaBloqueada')) {
    function estaColumnaBloqueada($configuracionBloqueos, $nombreCampo) {
        if (isset($configuracionBloqueos['bloqueo_general']) && $configuracionBloqueos['bloqueo_general']) {
            return true;
        }
        return isset($configuracionBloqueos[$nombreCampo]) && $configuracionBloqueos[$nombreCampo];
    }
}

if (!function_exists('puedeEditarCalificaciones')) {
    function puedeEditarCalificaciones($tipoUsuario) {
        return in_array($tipoUsuario, ['admin', 'directivo']);
    }
}

if (!function_exists('generarIconoEstadoCampo')) {
    function generarIconoEstadoCampo($configuracionBloqueos, $nombreCampo, $tipoUsuario) {
        $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
        $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
        
        if (!$bloqueado) {
            return '<i class="bi bi-unlock text-success" title="Campo habilitado"></i>';
        }
        
        if ($puedeEditar) {
            return '<i class="bi bi-shield-exclamation text-warning" title="Bloqueado para profesores - Usted puede editar"></i>';
        } else {
            return '<i class="bi bi-lock text-danger" title="Campo bloqueado"></i>';
        }
    }
}

if (!function_exists('generarAtributosCampo')) {
    function generarAtributosCampo($configuracionBloqueos, $nombreCampo, $tipoUsuario, $valorActual = null) {
        $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
        $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
        
        $atributos = [];
        $clases = [];
        
        if ($puedeEditar) {
            if ($bloqueado) {
                $clases[] = 'campo-admin-override';
                $atributos['title'] = 'Campo bloqueado para profesores - Usted puede editarlo por ser ' . ($tipoUsuario === 'admin' ? 'administrador' : 'directivo');
            }
        } else {
            if ($bloqueado) {
                $atributos['disabled'] = 'disabled';
                $clases[] = 'campo-bloqueado-sistema';
                
                $motivo = $configuracionBloqueos['observaciones'] ?? 'Campo bloqueado por configuración del sistema';
                $atributos['title'] = "Bloqueado: $motivo";
            }
        }
        
        if (count($clases) > 0) {
            $atributos['class'] = implode(' ', $clases);
        }
        
        return $atributos;
    }
}

if (!function_exists('generarCampoOcultoSiBloqueado')) {
    function generarCampoOcultoSiBloqueado($configuracionBloqueos, $nombreCampo, $tipoUsuario, $nombreFormulario, $valorActual) {
        $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
        $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
        
        if ($bloqueado && !$puedeEditar && $valorActual !== null) {
            return "<input type=\"hidden\" name=\"$nombreFormulario\" value=\"" . htmlspecialchars($valorActual) . "\">";
        }
        
        return '';
    }
}

// Asegurar que configuracionBloqueos esté disponible
if (!isset($configuracionBloqueos)) {
    $configuracionBloqueos = [
        'bloqueo_general' => 0,
        'valoracion_1bim_bloqueada' => 0,
        'desempeno_1bim_bloqueado' => 0,
        'observaciones_1bim_bloqueadas' => 0,
        'valoracion_3bim_bloqueada' => 0,
        'desempeno_3bim_bloqueado' => 0,
        'observaciones_3bim_bloqueadas' => 0,
        'observaciones' => ''
    ];
}
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">
                <i class="bi bi-clipboard-check"></i> 
                Valoraciones Preliminares - <?= $bimestreTexto ?> Bimestre
            </h6>
            
            <!-- Indicadores de bloqueo en el encabezado -->
            <div class="d-flex gap-2">
                <?php if (estaColumnaBloqueada($configuracionBloqueos, $campoValoracionBloqueada)): ?>
                    <span class="badge bg-danger">
                        <i class="bi bi-lock"></i> Valoración Bloqueada
                    </span>
                <?php endif; ?>
                <?php if (estaColumnaBloqueada($configuracionBloqueos, $campoDesempenoBloqueado)): ?>
                    <span class="badge bg-warning">
                        <i class="bi bi-lock"></i> Desempeño Bloqueado
                    </span>
                <?php endif; ?>
                <?php if (estaColumnaBloqueada($configuracionBloqueos, $campoObservacionesBloqueadas)): ?>
                    <span class="badge bg-info">
                        <i class="bi bi-lock"></i> Observaciones Bloqueadas
                    </span>
                <?php endif; ?>
            </div>
        </div>
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

        <!-- Panel de estado de campos y alertas -->
        <div class="row mb-3">
            <!-- Alerta sobre observaciones y bloqueos -->
            <div class="col-md-8">
                <?php if (!empty($observacionesPredefinidas)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-list-check"></i> <strong>Observaciones estandarizadas:</strong> 
                    Las observaciones se seleccionan únicamente de la lista predefinida para garantizar consistencia en los registros.
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Observaciones no disponibles:</strong> 
                    No se han configurado observaciones predefinidas en el sistema. Contacte al administrador.
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Panel de estado de campos -->
            <div class="col-md-4">
                <div class="card bg-light border-0">
                    <div class="card-body p-3">
                        <h6 class="card-title mb-2">Estado de Campos:</h6>
                        <div class="d-flex flex-column gap-1">
                            <div class="d-flex justify-content-between align-items-center">
                                <small>Valoración:</small>
                                <?= generarIconoEstadoCampo($configuracionBloqueos, $campoValoracionBloqueada, $_SESSION['user_type']) ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small>Desempeño:</small>
                                <?= generarIconoEstadoCampo($configuracionBloqueos, $campoDesempenoBloqueado, $_SESSION['user_type']) ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small>Observaciones:</small>
                                <?= generarIconoEstadoCampo($configuracionBloqueos, $campoObservacionesBloqueadas, $_SESSION['user_type']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%">#</th>
                        <th style="width: 25%">Estudiante</th>
                        <th style="width: 10%">Tipo</th>
                        <?php if (count($estudiantesConSubgrupos) > 0): ?>
                        <th style="width: 10%">Subgrupo</th>
                        <?php endif; ?>
                        <th style="width: 15%">
                            Valoración
                            <?= generarIconoEstadoCampo($configuracionBloqueos, $campoValoracionBloqueada, $_SESSION['user_type']) ?>
                        </th>
                        <th style="width: 15%">
                            Desempeño Académico
                            <?= generarIconoEstadoCampo($configuracionBloqueos, $campoDesempenoBloqueado, $_SESSION['user_type']) ?>
                        </th>
                        <th style="width: 20%">
                            Observaciones
                            <?= generarIconoEstadoCampo($configuracionBloqueos, $campoObservacionesBloqueadas, $_SESSION['user_type']) ?>
                        </th>
                        <th style="width: 20%">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contador = 1;
                    $bimestre_campo = $bimestreActual;
                    
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
                        <td colspan="<?= count($estudiantesConSubgrupos) > 0 ? '8' : '7' ?>" class="fw-bold">
                            <i class="bi bi-people"></i> <?= htmlspecialchars($nombreGrupo) ?> 
                            <span class="badge bg-info"><?= count($estudiantesGrupo) ?> estudiantes</span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php foreach ($estudiantesGrupo as $estudiante): ?>
                    <?php
                        $estudianteId = $estudiante['id'];
                        $calificacion = isset($calificaciones[$estudianteId]) ? $calificaciones[$estudianteId] : null;
                        
                        // Determinar campos según bimestre
                        $valoracionActual = null;
                        $desempenoActual = null;
                        $observacionesActuales = null;
                        
                        if ($calificacion) {
                            $valoracionActual = $calificacion['valoracion_' . $bimestre_campo . 'bim'] ?? null;
                            $desempenoActual = $calificacion['desempeno_' . $bimestre_campo . 'bim'] ?? null;
                            $observacionesActuales = $calificacion['observaciones_' . $bimestre_campo . 'bim'] ?? null;
                        }
                        
                        // Determinar clase CSS para fila según valoración
                        $claseFilaValoracion = '';
                        if ($valoracionActual === 'TEP' || $valoracionActual === 'TED') {
                            $claseFilaValoracion = 'table-danger';
                        } elseif ($valoracionActual === 'TEA') {
                            $claseFilaValoracion = 'table-success';
                        }
                        
                        // Combinar clases
                        $clasesFilaCompletas = trim(($estudiante['tipo_matricula'] === 'recursando' ? 'table-warning' : '') . ' ' . $claseFilaValoracion);
                        
                        // Generar atributos para campos según estado de bloqueo
                        $atributosValoracion = generarAtributosCampo($configuracionBloqueos, $campoValoracionBloqueada, $_SESSION['user_type'], $valoracionActual);
                        $atributosDesempeno = generarAtributosCampo($configuracionBloqueos, $campoDesempenoBloqueado, $_SESSION['user_type'], $desempenoActual);
                        $atributosObservaciones = generarAtributosCampo($configuracionBloqueos, $campoObservacionesBloqueadas, $_SESSION['user_type'], $observacionesActuales);
                    ?>
                    <tr class="<?= $clasesFilaCompletas ?>" id="fila-estudiante-<?= $estudianteId ?>">
                        <td><?= $contador++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                            <br>
                            <small class="text-muted">Matr.: <?= htmlspecialchars($estudiante['dni']) ?></small>
                        </td>
                        <td>
                            <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                                <span class="badge bg-warning">Recursando</span>
                            <?php else: ?>
                                <span class="badge bg-success">Regular</span>
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
                        
                        <!-- Valoración -->
                        <td>
                            <div class="campo-con-bloqueo">
                                <?php if (isset($atributosValoracion['disabled']) && $atributosValoracion['disabled']): ?>
                                    <!-- Campo bloqueado para profesores -->
                                    <select class="form-select form-select-sm <?= $atributosValoracion['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosValoracion['title'] ?? '' ?>">
                                        <option value="<?= $valoracionActual ?>" selected>
                                            <?= $valoracionActual ?: '-- Seleccione --' ?>
                                        </option>
                                    </select>
                                    <!-- Campo oculto para mantener el valor -->
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, $campoValoracionBloqueada, $_SESSION['user_type'], "estudiantes[$estudianteId][valoracion]", $valoracionActual) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][valoracion]" 
                                            class="form-select form-select-sm valoracion-select <?= $atributosValoracion['class'] ?? '' ?>" 
                                            data-estudiante="<?= $estudianteId ?>"
                                            title="<?= $atributosValoracion['title'] ?? '' ?>">
                                        <option value="">-- Seleccione --</option>
                                        <option value="TEA" <?= $valoracionActual === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                        <option value="TEP" <?= $valoracionActual === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                        <option value="TED" <?= $valoracionActual === 'TED' ? 'selected' : '' ?>>TED</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- Desempeño -->
                        <td>
                            <div class="campo-con-bloqueo">
                                <?php if (isset($atributosDesempeno['disabled']) && $atributosDesempeno['disabled']): ?>
                                    <!-- Campo bloqueado para profesores -->
                                    <select class="form-select form-select-sm <?= $atributosDesempeno['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosDesempeno['title'] ?? '' ?>">
                                        <option value="<?= $desempenoActual ?>" selected>
                                            <?= $desempenoActual ?: '-- Seleccione --' ?>
                                        </option>
                                    </select>
                                    <!-- Campo oculto para mantener el valor -->
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, $campoDesempenoBloqueado, $_SESSION['user_type'], "estudiantes[$estudianteId][desempeno]", $desempenoActual) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][desempeno]" 
                                            class="form-select form-select-sm <?= $atributosDesempeno['class'] ?? '' ?>"
                                            title="<?= $atributosDesempeno['title'] ?? '' ?>">
                                        <option value="">-- Seleccione --</option>
                                        <option value="Excelente" <?= $desempenoActual === 'Excelente' ? 'selected' : '' ?>>Excelente</option>
                                        <option value="Muy Bueno" <?= $desempenoActual === 'Muy Bueno' ? 'selected' : '' ?>>Muy Bueno</option>
                                        <option value="Bueno" <?= $desempenoActual === 'Bueno' ? 'selected' : '' ?>>Bueno</option>
                                        <option value="Regular" <?= $desempenoActual === 'Regular' ? 'selected' : '' ?>>Regular</option>
                                        <option value="Malo" <?= $desempenoActual === 'Malo' ? 'selected' : '' ?>>Malo</option>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- Observaciones -->
                        <td>
                            <?php if (!empty($observacionesPredefinidas)): ?>
                            <div class="observaciones-container">
                                <!-- Verificar si las observaciones están bloqueadas -->
                                <?php if (isset($atributosObservaciones['disabled']) && $atributosObservaciones['disabled']): ?>
                                    <!-- Observaciones bloqueadas -->
                                    <div class="alert alert-secondary alert-sm p-2 mb-0 <?= $atributosObservaciones['class'] ?? '' ?>" 
                                         title="<?= $atributosObservaciones['title'] ?? '' ?>">
                                        <small>
                                            <i class="bi bi-lock"></i> 
                                            <strong>Observaciones bloqueadas</strong>
                                            <?php if ($observacionesActuales): ?>
                                                <br><?= htmlspecialchars($observacionesActuales) ?>
                                            <?php else: ?>
                                                <br>Sin observaciones registradas
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <!-- Campo oculto para mantener el valor -->
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, $campoObservacionesBloqueadas, $_SESSION['user_type'], "estudiantes[$estudianteId][observaciones]", $observacionesActuales) ?>
                                    
                                <?php else: ?>
                                    <!-- Observaciones editables -->
                                    <button type="button" 
                                            class="btn btn-outline-secondary btn-sm w-100 mb-2 <?= $atributosObservaciones['class'] ?? '' ?>" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#observaciones_bim_panel_<?= $estudianteId ?>" 
                                            aria-expanded="false"
                                            title="<?= $atributosObservaciones['title'] ?? '' ?>">
                                        <i class="bi bi-list-check"></i> Seleccionar Observaciones
                                    </button>
                                    
                                    <!-- Panel colapsable con observaciones -->
                                    <div class="collapse" id="observaciones_bim_panel_<?= $estudianteId ?>">
                                        <div class="card card-body p-2" style="max-height: 200px; overflow-y: auto;">
                                            <?php 
                                            $categoriaActual = '';
                                            $observacionesSeleccionadas = !empty($observacionesActuales) ? explode('. ', $observacionesActuales) : [];
                                            
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
                                                $checkboxId = "obs_bim_{$estudianteId}_{$index}";
                                            ?>
                                            <div class="form-check form-check-sm">
                                                <input class="form-check-input observacion-checkbox-bim" 
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
                                                            onclick="seleccionarTodasObservacionesBim(<?= $estudianteId ?>)">
                                                        <i class="bi bi-check-all"></i> Todas
                                                    </button>
                                                    <button type="button" 
                                                            class="btn btn-outline-danger btn-sm"
                                                            onclick="limpiarObservacionesBim(<?= $estudianteId ?>)">
                                                        <i class="bi bi-x-circle"></i> Ninguna
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Resumen de observaciones seleccionadas -->
                                    <div class="observaciones-resumen mt-2" id="resumen_bim_<?= $estudianteId ?>">
                                        <?php if (!empty($observacionesActuales)): ?>
                                        <div class="alert alert-info alert-sm p-2 mb-0">
                                            <small><strong>Observaciones:</strong><br>
                                            <?= htmlspecialchars($observacionesActuales) ?></small>
                                        </div>
                                        <?php else: ?>
                                        <small class="text-muted">Sin observaciones seleccionadas</small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Campo oculto para almacenar el valor final -->
                                    <input type="hidden" 
                                           name="estudiantes[<?= $estudianteId ?>][observaciones]" 
                                           id="observaciones_bim_final_<?= $estudianteId ?>"
                                           value="<?= htmlspecialchars($observacionesActuales ?? '') ?>">
                                <?php endif; ?>
                            </div>
                            
                            <?php else: ?>
                            <!-- Fallback si no hay observaciones predefinidas -->
                            <div class="alert alert-warning alert-sm p-2">
                                <small><i class="bi bi-exclamation-triangle"></i> No hay observaciones predefinidas</small>
                            </div>
                            <input type="hidden" name="estudiantes[<?= $estudianteId ?>][observaciones]" value="">
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="detalle_calificaciones_contenidos.php?estudiante=<?= $estudiante['id'] ?>&materia=<?= $materiaSeleccionada ?>&origen=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
                               class="btn btn-sm btn-info" 
                               title="Ver detalle de calificaciones por contenido">
                                <i class="bi bi-list-check"></i>
                                <span class="d-none d-lg-inline">Detalle</span>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Referencias -->
        <div class="mt-3">
            <div class="row">
                <div class="col-md-8">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Referencias de Valoración:</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <span class="badge bg-success">TEA</span> 
                                    <strong>Trayectoria Educativa Avanzada:</strong>
                                    <small>El estudiante supera las expectativas del año</small>
                                </div>
                                <div class="col-md-4">
                                    <span class="badge bg-warning">TEP</span> 
                                    <strong>Trayectoria Educativa en Proceso:</strong>
                                    <small>El estudiante está en camino de alcanzar las expectativas</small>
                                </div>
                                <div class="col-md-4">
                                    <span class="badge bg-danger">TED</span> 
                                    <strong>Trayectoria Educativa Discontinua:</strong>
                                    <small>El estudiante presenta dificultades para alcanzar las expectativas</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6 class="card-title">Estado de Campos:</h6>
                            <ul class="list-unstyled mb-0 small">
                                <li><i class="bi bi-unlock text-success"></i> Campo habilitado</li>
                                <li><i class="bi bi-shield-exclamation text-warning"></i> Bloqueado para profesores</li>
                                <li><i class="bi bi-lock text-danger"></i> Campo bloqueado</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            No se encontraron estudiantes para cargar valoraciones.
        </div>
        <?php endif; ?>
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

/* Estilos para observaciones con checkboxes en valoraciones */
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
</style>

<script>
// JavaScript específico para valoraciones con bloqueos integrado
document.addEventListener('DOMContentLoaded', function() {
    // Destacar campos obligatorios
    document.querySelectorAll('select[name*="[valoracion]"]').forEach(function(select) {
        select.addEventListener('change', function() {
            if (this.value) {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
                
                // Cambiar color de fila según valoración
                actualizarColorFila(this.dataset.estudiante, this.value);
            } else {
                this.classList.remove('is-valid');
            }
        });
        
        // Aplicar color inicial si ya tiene valor
        if (select.value) {
            actualizarColorFila(select.dataset.estudiante, select.value);
        }
    });
    
    // Función para actualizar color de fila según valoración
    function actualizarColorFila(estudianteId, valoracion) {
        const fila = document.getElementById('fila-estudiante-' + estudianteId);
        if (fila) {
            // Remover clases de valoración anteriores
            fila.classList.remove('table-success', 'table-danger');
            
            // Aplicar nueva clase según valoración
            if (valoracion === 'TEA') {
                fila.classList.add('table-success');
            } else if (valoracion === 'TEP' || valoracion === 'TED') {
                fila.classList.add('table-danger');
            }
        }
    }
    
    // Destacar automáticamente desempeño según valoración
    document.querySelectorAll('.valoracion-select').forEach(function(valoracionSelect) {
        valoracionSelect.addEventListener('change', function() {
            const estudianteId = this.dataset.estudiante;
            const desempenoSelect = document.querySelector(`select[name="estudiantes[${estudianteId}][desempeno]"]`);
            
            if (desempenoSelect && this.value) {
                // Sugerir desempeño según valoración
                let sugerencia = '';
                switch(this.value) {
                    case 'TEA':
                        sugerencia = 'Excelente';
                        break;
                    case 'TEP':
                        sugerencia = 'Bueno';
                        break;
                    case 'TED':
                        sugerencia = 'Regular';
                        break;
                }
                
                // Solo aplicar sugerencia si no hay valor seleccionado
                if (!desempenoSelect.value && sugerencia) {
                    desempenoSelect.value = sugerencia;
                    desempenoSelect.style.backgroundColor = '#e3f2fd';
                    
                    // Quitar el highlighting después de unos segundos
                    setTimeout(() => {
                        desempenoSelect.style.backgroundColor = '';
                    }, 2000);
                }
            }
        });
    });
    
    // Manejo de observaciones con checkboxes para valoraciones bimestrales
    document.querySelectorAll('.observacion-checkbox-bim').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const estudianteId = this.dataset.estudiante;
            actualizarObservacionesCheckboxBim(estudianteId);
        });
    });
    
    // Función para actualizar observaciones desde checkboxes bimestrales
    function actualizarObservacionesCheckboxBim(estudianteId) {
        const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox-bim:checked`);
        const campoFinal = document.getElementById(`observaciones_bim_final_${estudianteId}`);
        const resumen = document.getElementById(`resumen_bim_${estudianteId}`);
        
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
            const boton = document.querySelector(`button[data-bs-target="#observaciones_bim_panel_${estudianteId}"]`);
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
    
    // Funciones para botones de acción rápida bimestrales
    window.seleccionarTodasObservacionesBim = function(estudianteId) {
        const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox-bim`);
        checkboxes.forEach(cb => {
            cb.checked = true;
        });
        actualizarObservacionesCheckboxBim(estudianteId);
    };
    
    window.limpiarObservacionesBim = function(estudianteId) {
        const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox-bim`);
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
        actualizarObservacionesCheckboxBim(estudianteId);
    };
    
    // Inicializar estado de observaciones al cargar
    document.querySelectorAll('.observacion-checkbox-bim').forEach(function(checkbox) {
        const estudianteId = checkbox.dataset.estudiante;
        // Solo actualizar una vez por estudiante
        if (!checkbox.dataset.inicializado) {
            actualizarObservacionesCheckboxBim(estudianteId);
            // Marcar todos los checkboxes de este estudiante como inicializados
            document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox-bim`).forEach(cb => {
                cb.dataset.inicializado = 'true';
            });
        }
    });
    
    // Validar que al menos una valoración esté cargada antes de enviar
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const valoraciones = document.querySelectorAll('select[name*="[valoracion]"]');
            let hayValoraciones = false;
            let valoracionesIncompletas = 0;
            
            valoraciones.forEach(function(select) {
                if (select.value) {
                    hayValoraciones = true;
                    
                    // Verificar que tenga desempeño también
                    const estudianteId = select.dataset.estudiante;
                    const desempenoSelect = document.querySelector(`select[name="estudiantes[${estudianteId}][desempeno]"]`);
                    
                    if (!desempenoSelect || !desempenoSelect.value) {
                        valoracionesIncompletas++;
                        if (desempenoSelect) {
                            desempenoSelect.classList.add('is-invalid');
                        }
                    }
                }
            });
            
            if (!hayValoraciones) {
                e.preventDefault();
                alert('Debe cargar al menos una valoración antes de guardar.');
                return false;
            }
            
            if (valoracionesIncompletas > 0) {
                e.preventDefault();
                alert(`Hay ${valoracionesIncompletas} valoración(es) sin desempeño académico seleccionado.\n\nPor favor complete todos los campos de desempeño para las valoraciones cargadas.`);
                return false;
            }
        });
    }
    
    // Mejorar feedback visual para desempeño
    document.querySelectorAll('select[name*="[desempeno]"]').forEach(function(select) {
        select.addEventListener('change', function() {
            this.classList.remove('is-invalid');
            
            // Aplicar color según el desempeño
            this.classList.remove('border-success', 'border-warning', 'border-danger');
            
            switch(this.value) {
                case 'Excelente':
                case 'Muy Bueno':
                    this.classList.add('border-success');
                    break;
                case 'Bueno':
                    this.classList.add('border-warning');
                    break;
                case 'Regular':
                case 'Malo':
                    this.classList.add('border-danger');
                    break;
            }
        });
        
        // Aplicar color inicial
        if (select.value) {
            select.dispatchEvent(new Event('change'));
        }
    });
});
</script>