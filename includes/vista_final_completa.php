<?php
/**
 * vista_final_completa.php - Vista final completa idéntica a la anterior
 * INCLUYE: Todas las columnas del sistema completo (valoración, desempeño, calificaciones, intensificación, final)
 */
?>

<div class="card">
    <div class="card-header bg-success text-white">
        <h6 class="card-title mb-0">
            <i class="bi bi-trophy"></i> 
            Calificaciones Cuatrimestrales - Vista Completa
            
            <!-- Indicadores de bloqueo en el encabezado -->
            <?php if (estaColumnaBloqueada($configuracionBloqueos, 'valoracion_1c_bloqueada') || estaColumnaBloqueada($configuracionBloqueos, 'calificacion_1c_bloqueada')): ?>
                <span class="badge bg-danger ms-2">
                    <i class="bi bi-lock"></i> 1°C Bloqueado
                </span>
            <?php endif; ?>
            <?php if (estaColumnaBloqueada($configuracionBloqueos, 'valoracion_2c_bloqueada') || estaColumnaBloqueada($configuracionBloqueos, 'calificacion_2c_bloqueada')): ?>
                <span class="badge bg-warning">
                    <i class="bi bi-lock"></i> 2°C Bloqueado
                </span>
            <?php endif; ?>
            <?php if (estaColumnaBloqueada($configuracionBloqueos, 'calificacion_final_bloqueada')): ?>
                <span class="badge bg-info">
                    <i class="bi bi-lock"></i> Final Bloqueada
                </span>
            <?php endif; ?>
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

        <!-- Panel de estado de campos y alertas -->
        <div class="row mb-3">
            <!-- Alerta sobre campos protegidos -->
            <div class="col-md-8">
                <div class="alert alert-warning">
                    <i class="bi bi-lock"></i> <strong>Campos con restricciones:</strong> 
                    Los campos marcados con <i class="bi bi-shield-lock text-primary"></i> pueden estar bloqueados según la configuración del sistema.
                    <small class="d-block mt-1">Las valoraciones y desempeños que provienen de calificaciones bimestrales también están protegidos.</small>
                </div>
            </div>
            
            <!-- Panel de estado de campos -->
            <div class="col-md-4">
                <div class="card bg-light border-0">
                    <div class="card-body p-3">
                        <h6 class="card-title mb-2">Estado de Campos:</h6>
                        <div class="row">
                            <div class="col-6">
                                <div class="d-flex flex-column gap-1 small">
                                    <div class="d-flex justify-content-between">
                                        <span>Val. 1°C:</span>
                                        <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type']) ?>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Cal. 1°C:</span>
                                        <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Val. 2°C:</span>
                                        <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type']) ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="d-flex flex-column gap-1 small">
                                    <div class="d-flex justify-content-between">
                                        <span>Cal. 2°C:</span>
                                        <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type']) ?>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Intensif.:</span>
                                        <?= generarIconoEstadoCampo($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Final:</span>
                                        <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type']) ?>
                                    </div>
                                </div>
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
                        <th rowspan="2" class="align-middle" style="width: 3%">#</th>
                        <th rowspan="2" class="align-middle" style="width: 12%">Estudiante</th>
                        <th rowspan="2" class="align-middle" style="width: 6%">Tipo</th>
                        <?php if (count($estudiantesConSubgrupos) > 0): ?>
                        <th rowspan="2" class="align-middle" style="width: 6%">Subgrupo</th>
                        <?php endif; ?>
                        <th colspan="3" class="text-center">
                            1° Cuatrimestre
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type']) ?>
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                        </th>
                        <th colspan="3" class="text-center">
                            2° Cuatrimestre
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type']) ?>
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type']) ?>
                        </th>
                        <th rowspan="2" class="align-middle text-center" style="width: 6%">
                            Intensif. 1°C
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                        </th>
                        <th rowspan="2" class="align-middle text-center" style="width: 8%">
                            Calif. Final
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type']) ?>
                        </th>
                        <th rowspan="2" class="align-middle" style="width: 12%">
                            Observaciones
                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'observaciones_cuatrimestrales_bloqueadas', $_SESSION['user_type']) ?>
                        </th>
                        <th rowspan="2" style="vertical-align: middle; width: 60px;">Acciones</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width: 6%">Valoración</th>
                        <th class="text-center" style="width: 6%">Desempeño</th>
                        <th class="text-center" style="width: 6%">Calificación</th>
                        <th class="text-center" style="width: 6%">Valoración</th>
                        <th class="text-center" style="width: 6%">Desempeño</th>
                        <th class="text-center" style="width: 6%">Calificación</th>
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
                        <td colspan="<?= count($estudiantesConSubgrupos) > 0 ? '14' : '13' ?>" class="fw-bold">
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
                        
                        // Obtener desempeño académico de los bimestres
                        $desempeno1bim = $calificacion['desempeno_1bim'] ?? null;
                        $desempeno3bim = $calificacion['desempeno_3bim'] ?? null;
                        
                        // Verificar si hay valoraciones bimestrales que bloquean la edición (protección adicional)
                        $valoracion1bim = $calificacion['valoracion_1bim'] ?? null;
                        $valoracion3bim = $calificacion['valoracion_3bim'] ?? null;
                        
                        // Determinar si los campos están bloqueados por valoraciones bimestrales
                        $bloquear1cPorBimestre = !empty($valoracion1bim);
                        $bloquear2cPorBimestre = !empty($valoracion3bim);
                        
                        // Si hay valoraciones bimestrales, usarlas como valor predeterminado
                        if ($valoracion1bim && !$valoracion1c) {
                            $valoracion1c = $valoracion1bim;
                        }
                        if ($valoracion3bim && !$valoracion2c) {
                            $valoracion2c = $valoracion3bim;
                        }
                        
                        // Generar atributos para campos según estado de bloqueo
                        $atributosVal1c = generarAtributosCampo($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type'], $valoracion1c);
                        $atributosCal1c = generarAtributosCampo($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type'], $calificacion1c);
                        $atributosVal2c = generarAtributosCampo($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type'], $valoracion2c);
                        $atributosCal2c = generarAtributosCampo($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type'], $calificacion2c);
                        $atributosIntensif = generarAtributosCampo($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type'], $intensificacion1c);
                        $atributosFinal = generarAtributosCampo($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type'], $calificacionFinal);
                        $atributosObs = generarAtributosCampo($configuracionBloqueos, 'observaciones_cuatrimestrales_bloqueadas', $_SESSION['user_type'], $observaciones);
                        
                        // Modificar atributos si están bloqueados por bimestre
                        if ($bloquear1cPorBimestre) {
                            $atributosVal1c['disabled'] = 'disabled';
                            $atributosVal1c['class'] = ($atributosVal1c['class'] ?? '') . ' campo-bloqueado';
                            $atributosVal1c['title'] = 'Campo protegido - Proviene de valoración bimestral';
                        }
                        if ($bloquear2cPorBimestre) {
                            $atributosVal2c['disabled'] = 'disabled';
                            $atributosVal2c['class'] = ($atributosVal2c['class'] ?? '') . ' campo-bloqueado';
                            $atributosVal2c['title'] = 'Campo protegido - Proviene de valoración bimestral';
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
                            <div class="position-relative campo-valoracion">
                                <?php if ($atributosVal1c['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado con estilo badge -->
                                    <div class="badge-valoracion <?= obtenerClaseValoracion($valoracion1c) ?>" 
                                         title="<?= $atributosVal1c['title'] ?? '' ?>">
                                        <div class="valoracion-valor"><?= $valoracion1c ?: '-' ?></div>
                                        <?php if ($bloquear1cPorBimestre): ?>
                                            <i class="bi bi-shield-lock valoracion-icono" title="Protegido por valoración bimestral"></i>
                                        <?php else: ?>
                                            <i class="bi bi-lock valoracion-icono" title="Bloqueado por configuración"></i>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Campo oculto para enviar el valor -->
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][valoracion_1c]", $valoracion1c) ?>
                                    <?php if ($bloquear1cPorBimestre): ?>
                                        <small class="text-info d-block mt-1 text-center">Desde 1er bimestre</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Campo editable con estilo mejorado -->
                                    <select name="estudiantes[<?= $estudianteId ?>][valoracion_1c]" 
                                            class="form-select form-select-sm valoracion-select <?= $atributosVal1c['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            title="<?= $atributosVal1c['title'] ?? '' ?>">
                                        <option value="">-</option>
                                        <option value="TEA" <?= $valoracion1c === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                        <option value="TEP" <?= $valoracion1c === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                        <option value="TED" <?= $valoracion1c === 'TED' ? 'selected' : '' ?>>TED</option>
                                    </select>
                                    <?php if ($bloquear1cPorBimestre): ?>
                                        <small class="text-info d-block mt-1 text-center">Desde 1er bimestre</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- 1° Cuatrimestre - Desempeño -->
                        <td>
                            <div class="position-relative campo-desempeno">
                                <?php if ($desempeno1bim): ?>
                                    <!-- Desempeño desde bimestre con estilo mejorado -->
                                    <div class="badge-desempeno <?= obtenerClaseDesempeno($desempeno1bim) ?>" 
                                         title="Desempeño del 1er bimestre">
                                        <div class="desempeno-valor"><?= htmlspecialchars($desempeno1bim) ?></div>
                                        <i class="bi bi-shield-lock desempeno-icono" title="Protegido por valoración bimestral"></i>
                                    </div>
                                    <small class="text-info d-block mt-1 text-center">Desde 1er bim.</small>
                                <?php else: ?>
                                    <div class="badge-desempeno badge-sin-dato">
                                        <div class="desempeno-valor">Sin dato</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- 1° Cuatrimestre - Calificación -->
                        <td>
                            <?php if ($atributosCal1c['disabled'] ?? false): ?>
                                <!-- Campo bloqueado -->
                                <select class="form-select form-select-sm text-center <?= $atributosCal1c['class'] ?? '' ?>" 
                                        disabled
                                        title="<?= $atributosCal1c['title'] ?? '' ?>">
                                    <option value="<?= $calificacion1c ?>" selected>
                                        <?= $calificacion1c ?: '-' ?>
                                    </option>
                                </select>
                                <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_1c]", $calificacion1c) ?>
                            <?php else: ?>
                                <!-- Campo editable -->
                                <select name="estudiantes[<?= $estudianteId ?>][calificacion_1c]" 
                                        class="form-select form-select-sm text-center calificacion-numerica <?= $atributosCal1c['class'] ?? '' ?>"
                                        data-estudiante="<?= $estudianteId ?>"
                                        data-periodo="1c"
                                        title="<?= $atributosCal1c['title'] ?? '' ?>">
                                    <option value="">-</option>
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" 
                                            <?= $calificacion1c == $i ? 'selected' : '' ?>
                                            class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                        <?= $i ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Valoración -->
                        <td>
                            <div class="position-relative campo-valoracion">
                                <?php if ($atributosVal2c['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado con estilo badge -->
                                    <div class="badge-valoracion <?= obtenerClaseValoracion($valoracion2c) ?>" 
                                         title="<?= $atributosVal2c['title'] ?? '' ?>">
                                        <div class="valoracion-valor"><?= $valoracion2c ?: '-' ?></div>
                                        <?php if ($bloquear2cPorBimestre): ?>
                                            <i class="bi bi-shield-lock valoracion-icono" title="Protegido por valoración bimestral"></i>
                                        <?php else: ?>
                                            <i class="bi bi-lock valoracion-icono" title="Bloqueado por configuración"></i>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Campo oculto para enviar el valor -->
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][valoracion_2c]", $valoracion2c) ?>
                                    <?php if ($bloquear2cPorBimestre): ?>
                                        <small class="text-info d-block mt-1 text-center">Desde 3er bimestre</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Campo editable con estilo mejorado -->
                                    <select name="estudiantes[<?= $estudianteId ?>][valoracion_2c]" 
                                            class="form-select form-select-sm valoracion-select <?= $atributosVal2c['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            title="<?= $atributosVal2c['title'] ?? '' ?>">
                                        <option value="">-</option>
                                        <option value="TEA" <?= $valoracion2c === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                        <option value="TEP" <?= $valoracion2c === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                        <option value="TED" <?= $valoracion2c === 'TED' ? 'selected' : '' ?>>TED</option>
                                    </select>
                                    <?php if ($bloquear2cPorBimestre): ?>
                                        <small class="text-info d-block mt-1 text-center">Desde 3er bimestre</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Desempeño -->
                        <td>
                            <div class="position-relative campo-desempeno">
                                <?php if ($desempeno3bim): ?>
                                    <!-- Desempeño desde bimestre con estilo mejorado -->
                                    <div class="badge-desempeno <?= obtenerClaseDesempeno($desempeno3bim) ?>" 
                                         title="Desempeño del 3er bimestre">
                                        <div class="desempeno-valor"><?= htmlspecialchars($desempeno3bim) ?></div>
                                        <i class="bi bi-shield-lock desempeno-icono" title="Protegido por valoración bimestral"></i>
                                    </div>
                                    <small class="text-info d-block mt-1 text-center">Desde 3er bim.</small>
                                <?php else: ?>
                                    <div class="badge-desempeno badge-sin-dato">
                                        <div class="desempeno-valor">Sin dato</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Calificación -->
                        <td>
                            <?php if ($atributosCal2c['disabled'] ?? false): ?>
                                <!-- Campo bloqueado -->
                                <select class="form-select form-select-sm text-center <?= $atributosCal2c['class'] ?? '' ?>" 
                                        disabled
                                        title="<?= $atributosCal2c['title'] ?? '' ?>">
                                    <option value="<?= $calificacion2c ?>" selected>
                                        <?= $calificacion2c ?: '-' ?>
                                    </option>
                                </select>
                                <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_2c]", $calificacion2c) ?>
                            <?php else: ?>
                                <!-- Campo editable -->
                                <select name="estudiantes[<?= $estudianteId ?>][calificacion_2c]" 
                                        class="form-select form-select-sm text-center calificacion-numerica <?= $atributosCal2c['class'] ?? '' ?>"
                                        data-estudiante="<?= $estudianteId ?>"
                                        data-periodo="2c"
                                        title="<?= $atributosCal2c['title'] ?? '' ?>">
                                    <option value="">-</option>
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" 
                                            <?= $calificacion2c == $i ? 'selected' : '' ?>
                                            class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                        <?= $i ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Intensificación 1° Cuatrimestre -->
                        <td>
                            <?php if ($atributosIntensif['disabled'] ?? false): ?>
                                <!-- Campo bloqueado -->
                                <select class="form-select form-select-sm text-center <?= $atributosIntensif['class'] ?? '' ?>" 
                                        disabled
                                        title="<?= $atributosIntensif['title'] ?? '' ?>">
                                    <option value="<?= $intensificacion1c ?>" selected>
                                        <?= $intensificacion1c ?: '-' ?>
                                    </option>
                                </select>
                                <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][intensificacion_1c]", $intensificacion1c) ?>
                            <?php else: ?>
                                <!-- Campo editable -->
                                <select name="estudiantes[<?= $estudianteId ?>][intensificacion_1c]" 
                                        class="form-select form-select-sm text-center calificacion-numerica <?= $atributosIntensif['class'] ?? '' ?>"
                                        data-estudiante="<?= $estudianteId ?>"
                                        data-periodo="intensif"
                                        title="<?= $atributosIntensif['title'] ?? '' ?>">
                                    <option value="">-</option>
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" 
                                            <?= $intensificacion1c == $i ? 'selected' : '' ?>
                                            class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                        <?= $i ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Calificación Final -->
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if ($atributosFinal['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm text-center <?= $atributosFinal['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosFinal['title'] ?? '' ?>"
                                            style="flex: 1;">
                                        <option value="<?= $calificacionFinal ?>" selected>
                                            <?= $calificacionFinal ?: '-' ?>
                                        </option>
                                    </select>
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_final]", $calificacionFinal) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][calificacion_final]" 
                                            class="form-select form-select-sm text-center calificacion-final me-1 <?= $atributosFinal['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            style="flex: 1;"
                                            title="<?= $atributosFinal['title'] ?? '' ?>">
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
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <!-- Observaciones -->
                        <td>
                            <?php if (!empty($observacionesPredefinidas)): ?>
                            <div class="observaciones-container">
                                <?php if ($atributosObs['disabled'] ?? false): ?>
                                    <!-- Observaciones bloqueadas -->
                                    <div class="alert alert-secondary alert-sm p-2 mb-0 <?= $atributosObs['class'] ?? '' ?>" 
                                         title="<?= $atributosObs['title'] ?? '' ?>">
                                        <small>
                                            <i class="bi bi-lock"></i> 
                                            <strong>Observaciones bloqueadas</strong>
                                            <?php if ($observaciones): ?>
                                                <br><?= htmlspecialchars($observaciones) ?>
                                            <?php else: ?>
                                                <br>Sin observaciones registradas
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'observaciones_cuatrimestrales_bloqueadas', $_SESSION['user_type'], "estudiantes[$estudianteId][observaciones]", $observaciones) ?>
                                    
                                <?php else: ?>
                                    <!-- Observaciones editables -->
                                    <button type="button" 
                                            class="btn btn-outline-secondary btn-sm w-100 mb-2 <?= $atributosObs['class'] ?? '' ?>" 
                                            data-bs-toggle="collapse" 
                                            data-bs-target="#observaciones_panel_<?= $estudianteId ?>" 
                                            aria-expanded="false"
                                            title="<?= $atributosObs['title'] ?? '' ?>">
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
                                <?php endif; ?>
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
                            <h6 class="card-title">Desempeño Académico:</h6>
                            <ul class="list-unstyled mb-0">
                                <li><i class="bi bi-shield-lock text-primary"></i> <small>Los datos de desempeño provienen de las valoraciones bimestrales</small></li>
                                <li><i class="bi bi-info-circle text-info"></i> <small>Se muestran solo como referencia informativa</small></li>
                                <li><i class="bi bi-eye text-muted"></i> <small>Ayudan a contextualizar las calificaciones cuatrimestrales</small></li>
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