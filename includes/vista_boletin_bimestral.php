<?php
/**
 * vista_boletin_bimestral.php - Vista del boletín bimestral con valoraciones
 * Esta vista muestra las valoraciones preliminares del bimestre seleccionado
 */

include 'includes/vista_materias_pendientes.php';


$bimestreTexto = ($bimestreSeleccionado == 1) ? '1er' : '3er';
$cuatrimestreCorrespondiente = ($bimestreSeleccionado == 1) ? 1 : 2;
?>

<!-- Valoraciones Bimestrales -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title">Valoraciones del <?= $bimestreTexto ?> Bimestre (<?= $cuatrimestreCorrespondiente ?>° Cuatrimestre)</h5>
    </div>
    <div class="card-body">
        <?php if (!empty($materiasBimestrales)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%">#</th>
                        <th style="width: 25%">Materia</th>
                        <th style="width: 10%">Código</th>
                        <th style="width: 15%">Valoración Preliminar</th>
                        <th style="width: 15%">Desempeño Académico</th>
                        <th style="width: 30%">Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $contador = 1;
                    foreach ($materiasBimestrales as $materia): 
                        // Solo mostrar materias que tienen valoración para este bimestre
                        if (!empty($materia['valoracion_bimestral'])):
                    ?>
                    <tr class="<?= 
                        $materia['valoracion_bimestral'] === 'TEA' ? 'table-success' : 
                        ($materia['valoracion_bimestral'] === 'TEP' ? 'table-warning' : 
                        ($materia['valoracion_bimestral'] === 'TED' ? 'table-danger' : ''))
                    ?>">
                        <td><?= $contador++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?= htmlspecialchars($materia['materia_codigo']) ?></span>
                        </td>
                        <td class="text-center">
                            <?php
                            $valoracion = $materia['valoracion_bimestral'];
                            $badgeClass = 'secondary';
                            $descripcion = '';
                            
                            switch($valoracion) {
                                case 'TEA':
                                    $badgeClass = 'success';
                                    $descripcion = 'Trayectoria Educativa Avanzada';
                                    break;
                                case 'TEP':
                                    $badgeClass = 'warning';
                                    $descripcion = 'Trayectoria Educativa en Proceso';
                                    break;
                                case 'TED':
                                    $badgeClass = 'danger';
                                    $descripcion = 'Trayectoria Educativa Discontinua';
                                    break;
                            }
                            ?>
                            <span class="badge bg-<?= $badgeClass ?> p-2"><?= $valoracion ?></span>
                            <br>
                            <small class="text-muted"><?= $descripcion ?></small>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($materia['desempeno_bimestral'])): ?>
                                <?php
                                $desempeno = $materia['desempeno_bimestral'];
                                $desempenoBadge = 'secondary';
                                
                                switch($desempeno) {
                                    case 'Excelente':
                                        $desempenoBadge = 'success';
                                        break;
                                    case 'Muy Bueno':
                                        $desempenoBadge = 'primary';
                                        break;
                                    case 'Bueno':
                                        $desempenoBadge = 'info';
                                        break;
                                    case 'Regular':
                                        $desempenoBadge = 'warning';
                                        break;
                                    case 'Malo':
                                        $desempenoBadge = 'danger';
                                        break;
                                }
                                ?>
                                <span class="badge bg-<?= $desempenoBadge ?>"><?= htmlspecialchars($desempeno) ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($materia['observaciones_bimestrales'])): ?>
                                <?= htmlspecialchars($materia['observaciones_bimestrales']) ?>
                            <?php else: ?>
                                <span class="text-muted">Sin observaciones</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                    
                    <?php if ($contador == 1): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            <em>No hay valoraciones registradas para el <?= $bimestreTexto ?> bimestre</em>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            No hay materias registradas para este estudiante.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Resumen de Valoraciones -->
<?php if (!empty($materiasBimestrales)): ?>
<?php
// Contar valoraciones
$conteoValoraciones = ['TEA' => 0, 'TEP' => 0, 'TED' => 0, 'total' => 0];
foreach ($materiasBimestrales as $materia) {
    if (!empty($materia['valoracion_bimestral'])) {
        $conteoValoraciones[$materia['valoracion_bimestral']]++;
        $conteoValoraciones['total']++;
    }
}
?>

<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="card-title">Resumen de Valoraciones - <?= $bimestreTexto ?> Bimestre</h5>
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body">
                        <h3 class="text-success"><?= $conteoValoraciones['TEA'] ?></h3>
                        <p class="card-text">TEA</p>
                        <small class="text-muted">Trayectoria Avanzada</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body">
                        <h3 class="text-warning"><?= $conteoValoraciones['TEP'] ?></h3>
                        <p class="card-text">TEP</p>
                        <small class="text-muted">Trayectoria en Proceso</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-danger">
                    <div class="card-body">
                        <h3 class="text-danger"><?= $conteoValoraciones['TED'] ?></h3>
                        <p class="card-text">TED</p>
                        <small class="text-muted">Trayectoria Discontinua</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-primary">
                    <div class="card-body">
                        <h3 class="text-primary"><?= $conteoValoraciones['total'] ?></h3>
                        <p class="card-text">Total</p>
                        <small class="text-muted">Materias Valoradas</small>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($conteoValoraciones['total'] > 0): ?>
        <div class="mt-3">
            <div class="progress" style="height: 30px;">
                <?php 
                $porcentajeTEA = ($conteoValoraciones['TEA'] / $conteoValoraciones['total']) * 100;
                $porcentajeTEP = ($conteoValoraciones['TEP'] / $conteoValoraciones['total']) * 100;
                $porcentajeTED = ($conteoValoraciones['TED'] / $conteoValoraciones['total']) * 100;
                ?>
                
                <?php if ($porcentajeTEA > 0): ?>
                <div class="progress-bar bg-success" role="progressbar" 
                     style="width: <?= $porcentajeTEA ?>%;" 
                     aria-valuenow="<?= $porcentajeTEA ?>" aria-valuemin="0" aria-valuemax="100">
                    TEA <?= round($porcentajeTEA, 1) ?>%
                </div>
                <?php endif; ?>
                
                <?php if ($porcentajeTEP > 0): ?>
                <div class="progress-bar bg-warning" role="progressbar" 
                     style="width: <?= $porcentajeTEP ?>%;" 
                     aria-valuenow="<?= $porcentajeTEP ?>" aria-valuemin="0" aria-valuemax="100">
                    TEP <?= round($porcentajeTEP, 1) ?>%
                </div>
                <?php endif; ?>
                
                <?php if ($porcentajeTED > 0): ?>
                <div class="progress-bar bg-danger" role="progressbar" 
                     style="width: <?= $porcentajeTED ?>%;" 
                     aria-valuenow="<?= $porcentajeTED ?>" aria-valuemin="0" aria-valuemax="100">
                    TED <?= round($porcentajeTED, 1) ?>%
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Asistencias del Período -->
<?php if (isset($asistenciasBimestre) && $asistenciasBimestre): ?>
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title">Asistencia - <?= $cuatrimestreCorrespondiente ?>° Cuatrimestre</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-info">
                            <tr>
                                <th>Período</th>
                                <th>Días Registrados</th>
                                <th>Ausentes</th>
                                <th>Medias Faltas</th>
                                <th>Justificadas</th>
                                <th>Total Faltas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?= $cuatrimestreCorrespondiente ?>° Cuatrimestre</td>
                                <td class="text-center"><?= $asistenciasBimestre['total_dias'] ?></td>
                                <td class="text-center"><?= $asistenciasBimestre['ausentes'] ?></td>
                                <td class="text-center"><?= $asistenciasBimestre['medias_faltas'] ?></td>
                                <td class="text-center"><?= $asistenciasBimestre['justificadas'] ?></td>
                                <td class="text-center">
                                    <?= $asistenciasBimestre['ausentes'] + ($asistenciasBimestre['medias_faltas'] * 0.5) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-4">
                <?php 
                $totalDias = $asistenciasBimestre['total_dias'];
                $totalFaltas = $asistenciasBimestre['ausentes'] + ($asistenciasBimestre['medias_faltas'] * 0.5);
                $porcentajeAsistencia = $totalDias > 0 ? (($totalDias - $totalFaltas) / $totalDias) * 100 : 0;
                ?>
                <div class="text-center">
                    <h3 class="<?= $porcentajeAsistencia >= 75 ? 'text-success' : 'text-danger' ?>">
                        <?= round($porcentajeAsistencia, 1) ?>%
                    </h3>
                    <p>Porcentaje de Asistencia</p>
                    <?php if ($porcentajeAsistencia < 75): ?>
                    <div class="alert alert-danger p-2">
                        <small><i class="bi bi-exclamation-triangle"></i> Por debajo del mínimo requerido (75%)</small>
                    </div>
                    <?php elseif ($porcentajeAsistencia < 85): ?>
                    <div class="alert alert-warning p-2">
                        <small><i class="bi bi-info-circle"></i> Cerca del límite mínimo</small>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success p-2">
                        <small><i class="bi bi-check-circle"></i> Asistencia satisfactoria</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Referencias para Boletín Bimestral -->
<div class="card">
    <div class="card-header bg-light">
        <h5 class="card-title">Referencias del Boletín Bimestral</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Valoraciones Preliminares:</h6>
                <ul class="list-unstyled">
                    <li><span class="badge bg-success">TEA</span> <strong>Trayectoria Educativa Avanzada:</strong> El estudiante supera las expectativas del año</li>
                    <li><span class="badge bg-warning">TEP</span> <strong>Trayectoria Educativa en Proceso:</strong> El estudiante está en camino de alcanzar las expectativas</li>
                    <li><span class="badge bg-danger">TED</span> <strong>Trayectoria Educativa Discontinua:</strong> El estudiante presenta dificultades para alcanzar las expectativas</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6>Desempeño Académico:</h6>
                <ul class="list-unstyled">
                    <li><span class="badge bg-success">Excelente:</span> Supera ampliamente las expectativas</li>
                    <li><span class="badge bg-primary">Muy Bueno:</span> Supera las expectativas</li>
                    <li><span class="badge bg-info">Bueno:</span> Alcanza las expectativas</li>
                    <li><span class="badge bg-warning">Regular:</span> Se acerca a las expectativas</li>
                    <li><span class="badge bg-danger">Malo:</span> No alcanza las expectativas</li>
                </ul>
            </div>
        </div>
        
        <div class="mt-3">
            <div class="alert alert-info">
                <h6><i class="bi bi-info-circle"></i> Información Importante:</h6>
                <p class="mb-1">Este boletín muestra las valoraciones preliminares correspondientes al <strong><?= $bimestreTexto ?> bimestre</strong> del <?= $cuatrimestreCorrespondiente ?>° cuatrimestre.</p>
                <p class="mb-1">Las valoraciones preliminares son una evaluación del progreso del estudiante en cada materia durante el período evaluado.</p>
                <p class="mb-0">Para obtener las calificaciones finales y el RITE completo, consulte el boletín cuatrimestral.</p>
            </div>
        </div>
    </div>
</div>
