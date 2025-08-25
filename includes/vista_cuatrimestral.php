<?php
/**
 * vista_cuatrimestral.php - Vista para cargar calificaciones cuatrimestrales
 * Incluir en calificaciones.php cuando $tipoCarga === 'cuatrimestre'
 */
?>

<div class="alert alert-success">
    <h6><i class="bi bi-journal-text"></i> Carga de Calificaciones Cuatrimestrales</h6>
    <p class="mb-0">
        Registro de calificaciones y valoraciones por cuatrimestre según la Resolución N° 1650/24.
        Incluye valoraciones preliminares, calificaciones numéricas e intensificación.
    </p>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th rowspan="2" style="vertical-align: middle;">Estudiante</th>
                <th rowspan="2" style="vertical-align: middle;">Tipo</th>
                <th colspan="2" class="text-center">1° Cuatrimestre</th>
                <th colspan="2" class="text-center">2° Cuatrimestre</th>
                <th rowspan="2" style="vertical-align: middle;">Int. 1° Cuat.</th>
                <th rowspan="2" style="vertical-align: middle;">Calif. Final</th>
                <th rowspan="2" style="vertical-align: middle;">Observaciones</th>
            </tr>
            <tr>
                <th class="text-center">Valoración</th>
                <th class="text-center">Calif.</th>
                <th class="text-center">Valoración</th>
                <th class="text-center">Calif.</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($estudiantes as $estudiante): ?>
            <?php 
                $calificacion = isset($calificaciones[$estudiante['id']]) ? $calificaciones[$estudiante['id']] : null;
                $valoracion1c = $calificacion ? $calificacion['valoracion_preliminar_1c'] : '';
                $calificacion1c = $calificacion ? $calificacion['calificacion_1c'] : '';
                $valoracion2c = $calificacion ? $calificacion['valoracion_preliminar_2c'] : '';
                $calificacion2c = $calificacion ? $calificacion['calificacion_2c'] : '';
                $intensificacion1c = $calificacion ? $calificacion['intensificacion_1c'] : '';
                $calificacionFinal = $calificacion ? $calificacion['calificacion_final'] : '';
                $observaciones = $calificacion ? $calificacion['observaciones'] : '';
                $tipoCursada = $calificacion ? $calificacion['tipo_cursada'] : 'C';
                
                // Verificar si es profesor para bloquear el campo tipo_cursada
                $isProfesor = $_SESSION['user_type'] == 'profesor';
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                    <br><small class="text-muted">DNI: <?= htmlspecialchars($estudiante['dni']) ?></small>
                    <input type="hidden" name="estudiantes[<?= $estudiante['id'] ?>][id]" value="<?= $estudiante['id'] ?>">
                </td>
                <td>
                    <?php if ($isProfesor): ?>
                        <!-- Campo de solo lectura para profesores -->
                        <span class="badge bg-<?= $tipoCursada == 'R' ? 'warning' : 'primary' ?>"><?= $tipoCursada ?></span>
                        <input type="hidden" name="estudiantes[<?= $estudiante['id'] ?>][tipo_cursada]" value="<?= $tipoCursada ?>">
                    <?php else: ?>
                        <!-- Campo editable para admin y directivos -->
                        <select name="estudiantes[<?= $estudiante['id'] ?>][tipo_cursada]" class="form-select form-select-sm">
                            <option value="C" <?= $tipoCursada == 'C' ? 'selected' : '' ?>>C</option>
                            <option value="R" <?= $tipoCursada == 'R' ? 'selected' : '' ?>>R</option>
                        </select>
                    <?php endif; ?>
                </td>
                <td>
                    <select name="estudiantes[<?= $estudiante['id'] ?>][valoracion_1c]" class="form-select form-select-sm">
                        <option value="" <?= empty($valoracion1c) ? 'selected' : '' ?>>--</option>
                        <option value="TEA" <?= $valoracion1c == 'TEA' ? 'selected' : '' ?>>TEA</option>
                        <option value="TEP" <?= $valoracion1c == 'TEP' ? 'selected' : '' ?>>TEP</option>
                        <option value="TED" <?= $valoracion1c == 'TED' ? 'selected' : '' ?>>TED</option>
                    </select>
                </td>
                <td>
                    <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][calificacion_1c]" 
                           id="calificacion_1c_<?= $estudiante['id'] ?>"
                           class="form-control form-control-sm" 
                           min="1" max="10" value="<?= $calificacion1c ?>">
                </td>
                <td>
                    <select name="estudiantes[<?= $estudiante['id'] ?>][valoracion_2c]" class="form-select form-select-sm">
                        <option value="" <?= empty($valoracion2c) ? 'selected' : '' ?>>--</option>
                        <option value="TEA" <?= $valoracion2c == 'TEA' ? 'selected' : '' ?>>TEA</option>
                        <option value="TEP" <?= $valoracion2c == 'TEP' ? 'selected' : '' ?>>TEP</option>
                        <option value="TED" <?= $valoracion2c == 'TED' ? 'selected' : '' ?>>TED</option>
                    </select>
                </td>
                <td>
                    <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][calificacion_2c]" 
                           id="calificacion_2c_<?= $estudiante['id'] ?>"
                           class="form-control form-control-sm" 
                           min="1" max="10" value="<?= $calificacion2c ?>">
                </td>
                <td>
                    <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][intensificacion_1c]" 
                           id="intensificacion_1c_<?= $estudiante['id'] ?>"
                           class="form-control form-control-sm" 
                           min="1" max="10" value="<?= $intensificacion1c ?>">
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" name="estudiantes[<?= $estudiante['id'] ?>][calificacion_final]" 
                               id="calificacion_final_<?= $estudiante['id'] ?>"
                               class="form-control form-control-sm" 
                               min="1" max="10" value="<?= $calificacionFinal ?>">
                        <button type="button" class="btn btn-outline-secondary btn-sm" 
                                onclick="calcularCalificacionFinal(<?= $estudiante['id'] ?>)" 
                                title="Calcular promedio">
                            <i class="bi bi-calculator"></i>
                        </button>
                    </div>
                </td>
                <td>
                    <textarea name="estudiantes[<?= $estudiante['id'] ?>][observaciones]" 
                              class="form-control form-control-sm" 
                              rows="2" placeholder="Observaciones generales..."><?= htmlspecialchars($observaciones) ?></textarea>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Guía de ayuda para calificaciones cuatrimestrales -->
<div class="row mt-3">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Guía para el Registro de Calificaciones según Resolución N° 1650/24</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Valoraciones Preliminares:</h6>
                        <ul class="list-unstyled">
                            <li><strong>TEA (Trayectoria Educativa Avanzada):</strong> Estudiantes que han alcanzado los aprendizajes correspondientes y sostuvieron una buena vinculación pedagógica.</li>
                            <li><strong>TEP (Trayectoria Educativa en Proceso):</strong> Estudiantes que no han alcanzado de forma suficiente los aprendizajes correspondientes, pero que mantienen una buena vinculación pedagógica.</li>
                            <li><strong>TED (Trayectoria Educativa Discontinua):</strong> Estudiantes que no han alcanzado los aprendizajes correspondientes y que tuvieron una escasa vinculación pedagógica.</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Calificación del cuatrimestre:</h6>
                        <p>La calificación de cierre de cada cuatrimestre resultará de la ponderación de las valoraciones parciales cualitativas y cuantitativas obtenidas por el estudiante, en la escala de uno (1) a diez (10).</p>
                        
                        <h6>Intensificación:</h6>
                        <p>En caso de que el estudiante durante el segundo cuatrimestre intensifique contenidos del primero, la calificación correspondiente se consignará en esta columna.</p>
                        
                        <h6>Calificación final:</h6>
                        <p>Se registrará al momento en que la materia haya sido aprobada y acreditada o al cierre del ciclo lectivo, sea que la materia haya sido aprobada o quede pendiente de aprobación y acreditación.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Función para calcular calificación final
function calcularCalificacionFinal(estudianteId) {
    // Obtener valores
    const cal1c = document.getElementById('calificacion_1c_' + estudianteId);
    const cal2c = document.getElementById('calificacion_2c_' + estudianteId);
    const int1c = document.getElementById('intensificacion_1c_' + estudianteId);
    const calFinal = document.getElementById('calificacion_final_' + estudianteId);
    
    let valores = [];
    
    // Agregar calificaciones válidas
    if (cal1c && cal1c.value && parseInt(cal1c.value) >= 1) {
        valores.push(parseInt(cal1c.value));
    }
    
    if (cal2c && cal2c.value && parseInt(cal2c.value) >= 1) {
        valores.push(parseInt(cal2c.value));
    }
    
    // Si hay intensificación, usar ese valor en lugar del 1er cuatrimestre
    if (int1c && int1c.value && parseInt(int1c.value) >= 1) {
        // Reemplazar la primera calificación si existe
        if (valores.length > 0) {
            valores[0] = parseInt(int1c.value);
        } else {
            valores.push(parseInt(int1c.value));
        }
    }
    
    // Calcular promedio
    if (valores.length > 0) {
        const promedio = valores.reduce((a, b) => a + b, 0) / valores.length;
        const promedioRedondeado = Math.round(promedio);
        
        if (calFinal) {
            calFinal.value = promedioRedondeado;
        }
        
        // Mostrar feedback visual
        if (promedioRedondeado >= 4) {
            calFinal.style.backgroundColor = '#d4edda';
            calFinal.style.color = '#155724';
        } else {
            calFinal.style.backgroundColor = '#f8d7da';
            calFinal.style.color = '#721c24';
        }
        
        // Quitar colores después de un tiempo
        setTimeout(() => {
            calFinal.style.backgroundColor = '';
            calFinal.style.color = '';
        }, 2000);
    } else {
        alert('No hay calificaciones válidas para calcular el promedio');
    }
}
</script>
