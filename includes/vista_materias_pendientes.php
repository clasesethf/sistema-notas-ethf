<?php
/**
 * Vista de Materias Pendientes Agrupadas para Boletines
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Muestra las materias pendientes de intensificación agrupadas por grupos de materias
 * MEJORADO: Agrupa materias del mismo grupo en una sola fila
 */

// Incluir funciones de agrupación
require_once 'funciones_grupos_pendientes.php';

// Agrupar materias pendientes
$materiasAgrupadasPendientes = agruparMateriasPendientesPorGrupo($db, $estudiante['id'], $cicloLectivoId);
$estadisticasPendientesAgrupadas = obtenerEstadisticasPendientesAgrupadas($materiasAgrupadasPendientes);

// Solo mostrar si hay materias pendientes
if (!empty($materiasAgrupadasPendientes)): ?>

<!-- Sección de Materias Pendientes de Intensificación -->
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark">
        <h6 class="card-title mb-0">
            <i class="bi bi-exclamation-triangle"></i> 
            MATERIAS PENDIENTES DE INTENSIFICACIÓN
            <span class="badge bg-dark ms-2"><?= $estadisticasPendientesAgrupadas['total'] ?> materia(s)/grupo(s)</span>
        </h6>
    </div>
    <div class="card-body">
        <!-- Información introductoria -->
        <div class="alert alert-info mb-3">
            <div class="row">
                <div class="col-md-8">
                    <small>
                        <strong>Materias y grupos de materias de años anteriores que requieren intensificación para su aprobación y acreditación.</strong><br>
                        Los períodos de intensificación son: Marzo, Julio, Agosto, Diciembre y Febrero.<br>
                        <span class="text-muted">Para grupos de materias: debe aprobar TODAS las materias del grupo para aprobar el grupo completo.</span>
                    </small>
                </div>
                <div class="col-md-4">
                    <div class="row text-center">
                        <div class="col">
                            <span class="badge bg-success"><?= $estadisticasPendientesAgrupadas['aprobadas'] ?></span><br>
                            <small>Aprobadas</small>
                        </div>
                        <div class="col">
                            <span class="badge bg-warning"><?= $estadisticasPendientesAgrupadas['en_proceso'] ?></span><br>
                            <small>En Proceso</small>
                        </div>
                        <div class="col">
                            <span class="badge bg-secondary"><?= $estadisticasPendientesAgrupadas['sin_evaluar'] ?></span><br>
                            <small>Sin Evaluar</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de materias pendientes agrupadas en formato RITE -->
        <div class="table-responsive">
            <table class="table table-bordered table-sm" style="font-size: 0.85em;">
                <thead>
                    <tr class="table-warning text-center">
                        <th rowspan="2" class="align-middle" style="min-width: 180px;">
                            <strong>MATERIA/GRUPO</strong><br>
                            <small>(Año de cursada)</small>
                        </th>
                        <th rowspan="2" class="align-middle" style="min-width: 120px;">
                            <strong>PROFESOR(ES)</strong>
                        </th>
                        <th colspan="5" class="text-center">
                            <strong>PERÍODOS DE INTENSIFICACIÓN</strong>
                        </th>
                        <th rowspan="2" class="align-middle">
                            <strong>CALIFICACIÓN<br>FINAL</strong>
                        </th>
                        <th rowspan="2" class="align-middle" style="min-width: 100px;">
                            <strong>ESTADO</strong>
                        </th>
                    </tr>
                    <tr class="table-warning text-center">
                        <th><strong>MAR</strong></th>
                        <th><strong>JUL</strong></th>
                        <th><strong>AGO</strong></th>
                        <th><strong>DIC</strong></th>
                        <th><strong>FEB</strong></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materiasAgrupadasPendientes as $index => $item): ?>
                    <?php 
                    if ($item['es_grupo']) {
                        $estadoGrupo = calcularEstadoGrupoPendiente($item['materias']);
                        $primeraMateria = $item['materias'][0];
                    } else {
                        $primeraMateria = $item['materias'][0];
                        $estadoGrupo = null;
                    }
                    ?>
                    <tr class="<?= $index % 2 == 0 ? 'table-light' : '' ?> <?= $item['es_grupo'] ? 'grupo-row' : 'materia-individual-row' ?>">
                        <!-- Materia/Grupo y año -->
                        <td>
                            <?php if ($item['es_grupo']): ?>
                                <!-- Grupo de materias -->
                                <div class="grupo-header">
                                    <span class="badge bg-primary me-1">GRUPO</span>
                                    <strong><?= htmlspecialchars($item['grupo_nombre']) ?></strong>
                                    <?php if ($item['grupo_codigo']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($item['grupo_codigo']) ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Materias del grupo -->
                                <div class="materias-grupo mt-2">
                                    <?php foreach ($item['materias'] as $matDelGrupo): ?>
                                    <div class="materia-del-grupo">
                                        <small class="text-muted">
                                            <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($matDelGrupo['materia_nombre']) ?>
                                            <?php if ($matDelGrupo['materia_codigo']): ?>
                                                (<?= htmlspecialchars($matDelGrupo['materia_codigo']) ?>)
                                            <?php endif; ?>
                                            
                                            <!-- Estado individual de cada materia del grupo -->
                                            <?php if (!empty($matDelGrupo['calificacion_final'])): ?>
                                                <span class="badge bg-<?= $matDelGrupo['calificacion_final'] >= 4 ? 'success' : 'danger' ?> ms-1" style="font-size: 0.6em;">
                                                    <?= $matDelGrupo['calificacion_final'] ?>
                                                </span>
                                            <?php else: ?>
                                                <?php 
                                                $ultimoEstadoIndividual = '';
                                                $periodos = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo'];
                                                foreach ($periodos as $periodo) {
                                                    if (!empty($matDelGrupo[$periodo])) {
                                                        $ultimoEstadoIndividual = $matDelGrupo[$periodo];
                                                        break;
                                                    }
                                                }
                                                if ($ultimoEstadoIndividual): ?>
                                                    <span class="badge bg-<?= $ultimoEstadoIndividual === 'AA' ? 'success' : ($ultimoEstadoIndividual === 'CCA' ? 'warning' : 'danger') ?> ms-1" style="font-size: 0.6em;">
                                                        <?= $ultimoEstadoIndividual ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <!-- Materia individual -->
                                <strong><?= htmlspecialchars($primeraMateria['nombre_mostrar']) ?></strong>
                                <?php if ($primeraMateria['codigo_mostrar']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($primeraMateria['codigo_mostrar']) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <br><span class="badge bg-info"><?= $primeraMateria['curso_anio'] ?>° Año</span>
                        </td>
                        
                        <!-- Profesor(es) -->
                        <td>
                            <?php if (!empty($item['profesores'])): ?>
                                <?php foreach ($item['profesores'] as $profesor): ?>
                                    <small><?= htmlspecialchars($profesor) ?></small><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <small class="text-muted">Sin asignar</small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Períodos de intensificación - Mostrar estado del grupo o materia individual -->
                        <?php if ($item['es_grupo']): ?>
                            <!-- Para grupos: mostrar estado consolidado -->
                            <?php 
                            $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
                            foreach ($periodos as $periodo): 
                                // Verificar si TODAS las materias del grupo tienen el mismo estado en este período
                                $estadosPeriodo = [];
                                $todasTienenEstado = true;
                                
                                foreach ($item['materias'] as $matGrupo) {
                                    if (!empty($matGrupo[$periodo])) {
                                        $estadosPeriodo[] = $matGrupo[$periodo];
                                    } else {
                                        $todasTienenEstado = false;
                                    }
                                }
                                
                                $estadoConsolidado = '';
                                if ($todasTienenEstado && count($estadosPeriodo) === count($item['materias'])) {
                                    // Si todas están AA, el grupo está AA
                                    if (count(array_filter($estadosPeriodo, function($e) { return $e === 'AA'; })) === count($estadosPeriodo)) {
                                        $estadoConsolidado = 'AA';
                                    } 
                                    // Si hay al menos una CCA, el grupo está CCA
                                    elseif (in_array('CCA', $estadosPeriodo)) {
                                        $estadoConsolidado = 'CCA';
                                    }
                                    // Si hay CSA, el grupo está CSA
                                    else {
                                        $estadoConsolidado = 'CSA';
                                    }
                                }
                            ?>
                            <td class="text-center">
                                <?php if ($estadoConsolidado): ?>
                                    <span class="badge bg-<?= $estadoConsolidado === 'AA' ? 'success' : ($estadoConsolidado === 'CCA' ? 'warning' : 'danger') ?>">
                                        <?= $estadoConsolidado ?>
                                    </span>
                                    <br><small class="text-muted" style="font-size: 0.7em;"><?= count($estadosPeriodo) ?>/<?= count($item['materias']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php if (count($estadosPeriodo) > 0): ?>
                                    <br><small class="text-warning" style="font-size: 0.7em;"><?= count($estadosPeriodo) ?>/<?= count($item['materias']) ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Para materias individuales: mostrar como antes -->
                            <td class="text-center">
                                <?php if ($primeraMateria['marzo']): ?>
                                    <span class="badge bg-<?= $primeraMateria['marzo'] === 'AA' ? 'success' : ($primeraMateria['marzo'] === 'CCA' ? 'warning' : 'danger') ?>">
                                        <?= $primeraMateria['marzo'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <?php if ($primeraMateria['julio']): ?>
                                    <span class="badge bg-<?= $primeraMateria['julio'] === 'AA' ? 'success' : ($primeraMateria['julio'] === 'CCA' ? 'warning' : 'danger') ?>">
                                        <?= $primeraMateria['julio'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <?php if ($primeraMateria['agosto']): ?>
                                    <span class="badge bg-<?= $primeraMateria['agosto'] === 'AA' ? 'success' : ($primeraMateria['agosto'] === 'CCA' ? 'warning' : 'danger') ?>">
                                        <?= $primeraMateria['agosto'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <?php if ($primeraMateria['diciembre']): ?>
                                    <span class="badge bg-<?= $primeraMateria['diciembre'] === 'AA' ? 'success' : ($primeraMateria['diciembre'] === 'CCA' ? 'warning' : 'danger') ?>">
                                        <?= $primeraMateria['diciembre'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <?php if ($primeraMateria['febrero']): ?>
                                    <span class="badge bg-<?= $primeraMateria['febrero'] === 'AA' ? 'success' : ($primeraMateria['febrero'] === 'CCA' ? 'warning' : 'danger') ?>">
                                        <?= $primeraMateria['febrero'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        
                        <!-- Calificación final -->
                        <td class="text-center">
                            <?php if ($item['es_grupo'] && $estadoGrupo): ?>
                                <!-- Calificación del grupo -->
                                <?php if ($estadoGrupo['calificacion_final_grupo'] !== null): ?>
                                    <span class="badge bg-<?= $estadoGrupo['calificacion_final_grupo'] >= 7 ? 'success' : ($estadoGrupo['calificacion_final_grupo'] >= 4 ? 'warning' : 'danger') ?>" style="font-size: 1em;">
                                        <?= $estadoGrupo['calificacion_final_grupo'] ?>
                                    </span>
                                    <br><small class="text-muted" style="font-size: 0.7em;">GRUPO</small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <!-- Mostrar calificaciones individuales si las hay -->
                                    <?php 
                                    $calificacionesIndividuales = array_filter($item['materias'], function($m) { return !empty($m['calificacion_final']); });
                                    if (!empty($calificacionesIndividuales)): 
                                    ?>
                                    <br><small class="text-info" style="font-size: 0.7em;">
                                        <?= count($calificacionesIndividuales) ?>/<?= count($item['materias']) ?> calif.
                                    </small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Calificación de materia individual -->
                                <?php if ($primeraMateria['calificacion_final']): ?>
                                    <span class="badge bg-<?= $primeraMateria['calificacion_final'] >= 7 ? 'success' : ($primeraMateria['calificacion_final'] >= 4 ? 'warning' : 'danger') ?>" style="font-size: 1em;">
                                        <?= $primeraMateria['calificacion_final'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Estado -->
                        <td class="text-center">
                            <?php if ($item['es_grupo'] && $estadoGrupo): ?>
                                <span class="badge bg-<?= $estadoGrupo['estado_badge'] ?>"><?= $estadoGrupo['estado_texto'] ?></span>
                            <?php else: ?>
                                <?php
                                // Estado de materia individual (código original)
                                if (!empty($primeraMateria['calificacion_final'])) {
                                    if ($primeraMateria['calificacion_final'] >= 4) {
                                        echo '<span class="badge bg-success">APROBADA</span>';
                                    } else {
                                        echo '<span class="badge bg-danger">NO APROBADA</span>';
                                    }
                                } else {
                                    // Verificar último período evaluado
                                    $periodos = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo'];
                                    $ultimoEstado = '';
                                    foreach ($periodos as $periodo) {
                                        if (!empty($primeraMateria[$periodo])) {
                                            $ultimoEstado = $primeraMateria[$periodo];
                                            break;
                                        }
                                    }
                                    
                                    if ($ultimoEstado === 'AA') {
                                        echo '<span class="badge bg-success">EN PROCESO</span>';
                                    } elseif ($ultimoEstado === 'CCA') {
                                        echo '<span class="badge bg-warning">CON AVANCES</span>';
                                    } elseif ($ultimoEstado === 'CSA') {
                                        echo '<span class="badge bg-danger">SIN AVANCES</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">SIN EVALUAR</span>';
                                    }
                                }
                                ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Leyenda de códigos actualizada -->
        <div class="row mt-3">
            <div class="col-md-6">
                <h6 class="text-muted">Códigos de Intensificación:</h6>
                <ul class="list-unstyled small">
                    <li><span class="badge bg-success">AA</span> = Aprobó y Acreditó</li>
                    <li><span class="badge bg-warning">CCA</span> = Continúa, Con Avances</li>
                    <li><span class="badge bg-danger">CSA</span> = Continúa, Sin Avances</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted">Estados Finales:</h6>
                <ul class="list-unstyled small">
                    <li><span class="badge bg-success">APROBADA</span> = Calificación ≥ 4</li>
                    <li><span class="badge bg-danger">NO APROBADA</span> = Calificación < 4</li>
                    <li><span class="badge bg-warning">EN PROCESO</span> = Sin calificación final</li>
                    <li><small class="text-muted"><strong>Grupos:</strong> Debe aprobar TODAS las materias del grupo</small></li>
                </ul>
            </div>
        </div>
        
        <!-- Información adicional si hay saberes pendientes -->
        <?php 
        $conSaberesPendientes = [];
        foreach ($materiasAgrupadasPendientes as $item) {
            foreach ($item['materias'] as $materia) {
                if (!empty($materia['saberes_cierre'])) {
                    $conSaberesPendientes[] = [
                        'nombre' => $item['es_grupo'] ? $item['grupo_nombre'] : $materia['nombre_mostrar'],
                        'saberes' => $materia['saberes_cierre'],
                        'es_grupo' => $item['es_grupo'],
                        'materia_especifica' => $item['es_grupo'] ? $materia['materia_nombre'] : null
                    ];
                }
            }
        }
        
        if (!empty($conSaberesPendientes)): 
        ?>
        <div class="alert alert-warning mt-3">
            <h6><i class="bi bi-info-circle"></i> Saberes Pendientes al Cierre del Ciclo Lectivo:</h6>
            <?php foreach ($conSaberesPendientes as $pendiente): ?>
                <div class="mb-2">
                    <strong><?= htmlspecialchars($pendiente['nombre']) ?>:</strong>
                    <?php if ($pendiente['es_grupo'] && $pendiente['materia_especifica']): ?>
                        <small class="text-muted">(<?= htmlspecialchars($pendiente['materia_especifica']) ?>)</small>
                    <?php endif; ?>
                    <br><small><?= nl2br(htmlspecialchars($pendiente['saberes'])) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<style>
/* Estilos específicos para materias pendientes agrupadas */
.grupo-row {
    background-color: #f8f9fa !important;
    border-left: 4px solid #007bff;
}

.materia-individual-row {
    border-left: 2px solid #6c757d;
}

.grupo-header {
    font-weight: 600;
    color: #0d6efd;
}

.materias-grupo {
    background-color: rgba(13, 110, 253, 0.1);
    border-radius: 4px;
    padding: 8px;
    margin: 4px 0;
}

.materia-del-grupo {
    margin-bottom: 4px;
}

.materia-del-grupo:last-child {
    margin-bottom: 0;
}

/* Mejoras para la tabla */
.table-bordered th, .table-bordered td {
    border: 1px solid #dee2e6;
    vertical-align: middle;
}

.table th {
    background-color: #fff3cd;
    font-weight: 600;
}

.badge {
    font-size: 0.75em;
}

/* Responsive */
@media (max-width: 768px) {
    .materias-grupo {
        font-size: 0.8em;
    }
    
    .badge {
        font-size: 0.7em;
    }
}

/* Mejoras para impresión */
@media print {
    .card-header {
        background-color: #fff3cd !important;
        color: #000 !important;
    }
    
    .table th {
        background-color: #fff3cd !important;
        color: #000 !important;
    }
    
    .badge {
        border: 1px solid #000;
        color: #000 !important;
    }
    
    .grupo-row {
        background-color: #f8f9fa !important;
        border-left: 4px solid #000 !important;
    }
    
    .materias-grupo {
        background-color: #f5f5f5 !important;
    }
}
</style>
