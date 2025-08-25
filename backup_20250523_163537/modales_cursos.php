<?php
/**
 * modales_cursos.php - Modales para la gestión de cursos (ACTUALIZADO)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */
?>

<!-- Modal Crear Curso -->
<div class="modal fade" id="modalCrearCurso" tabindex="-1" aria-labelledby="modalCrearCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="cursos.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearCursoLabel">Crear Nuevo Curso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_curso">
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="crear_nombre" class="form-label">Nombre del Curso *</label>
                            <input type="text" class="form-control" id="crear_nombre" name="nombre" required placeholder="ej: 1° año A, 2° año B">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="crear_anio" class="form-label">Año *</label>
                            <select class="form-select" id="crear_anio" name="anio" required>
                                <option value="">-- Seleccione --</option>
                                <option value="1">1° año</option>
                                <option value="2">2° año</option>
                                <option value="3">3° año</option>
                                <option value="4">4° año</option>
                                <option value="5">5° año</option>
                                <option value="6">6° año</option>
                                <option value="7">7° año</option>
                            </select>
                        </div>
                    </div>
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="crear_orientacion" class="form-label">Orientación</label>
                            <input type="text" class="form-control" id="crear_orientacion" name="orientacion" value="Técnico en Electromecánica">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="crear_ciclo_lectivo_id" class="form-label">Ciclo Lectivo *</label>
                            <select class="form-select" id="crear_ciclo_lectivo_id" name="ciclo_lectivo_id" required>
                                <option value="">-- Seleccione --</option>
                                <?php foreach ($ciclosDisponibles as $ciclo): ?>
                                <option value="^<?= $ciclo['id'] ?^>" <?= $ciclo['activo'] ? 'selected' : '' ?>>
                                    <?= $ciclo['anio'] ?> <?= $ciclo['activo'] ? '(Activo)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Curso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Curso -->
<div class="modal fade" id="modalEditarCurso" tabindex="-1" aria-labelledby="modalEditarCursoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="cursos.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarCursoLabel">Editar Curso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="editar_curso">
                    <input type="hidden" name="curso_id" id="editar_curso_id">
                    <input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>">
                    <input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>">
                    <input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>">
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="editar_nombre" class="form-label">Nombre del Curso *</label>
                            <input type="text" class="form-control" id="editar_nombre" name="nombre" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="editar_anio" class="form-label">Año *</label>
                            <select class="form-select" id="editar_anio" name="anio" required>
                                <option value="1">1° año</option>
                                <option value="2">2° año</option>
                                <option value="3">3° año</option>
                                <option value="4">4° año</option>
                                <option value="5">5° año</option>
                                <option value="6">6° año</option>
                                <option value="7">7° año</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Curso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Matricular Estudiante -->
<div class="modal fade" id="modalMatricularEstudiante" tabindex="-1" aria-labelledby="modalMatricularEstudianteLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="cursos.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalMatricularEstudianteLabel">Matricular Estudiante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="matricular_estudiante">
                    <input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>">
                    <input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>">
                    <input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>">
ECHO está desactivado.
                    <div class="mb-3">
                        <label for="matricular_estudiante_id" class="form-label">Estudiante *</label>
                        <select class="form-select" id="matricular_estudiante_id" name="estudiante_id" required>
                            <option value="">-- Seleccione un estudiante --</option>
                            <?php foreach ($estudiantes as $estudiante): ?>
                            <option value="^<?= $estudiante['id'] ?^>">
                                <?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?> (<?= $estudiante['dni'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
ECHO está desactivado.
                    <div class="mb-3">
                        <label for="matricular_curso_id" class="form-label">Curso *</label>
                        <select class="form-select" id="matricular_curso_id" name="curso_id" required>
                            <option value="">-- Seleccione un curso --</option>
                            <?php foreach ($cursos as $curso): ?>
                            <option value="^<?= $curso['id'] ?^>">
                                <?= htmlspecialchars($curso['nombre']) ?> - <?= $curso['anio'] ?>° año
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Matricular Estudiante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Asignar Recursado - MEJORADO -->
<div class="modal fade" id="modalAsignarRecursado" tabindex="-1" aria-labelledby="modalAsignarRecursadoLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" action="cursos.php" id="formRecursado">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalAsignarRecursadoLabel">Asignar Materia para Recursado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="asignar_materia_recursado">
                    <input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>">
                    <input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>">
                    <input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>">
ECHO está desactivado.
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Recursado Mejorado:</strong> Cuando un estudiante recursa una materia de un año anterior, 
                        debe liberar una materia de su año actual para tener tiempo disponible. El estudiante NO aparecerá 
                        en las listas de la materia liberada.
                    </div>
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="recursado_estudiante_id" class="form-label">Estudiante *</label>
                            <select class="form-select" id="recursado_estudiante_id" name="estudiante_id" required onchange="cargarCursoActualEstudiante^(^)">
                                <option value="">-- Seleccione un estudiante --</option>
                                <?php foreach ($estudiantes as $estudiante): ?>
                                <option value="^<?= $estudiante['id'] ?^>">
                                    <?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?> (<?= $estudiante['dni'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
ECHO está desactivado.
                        <div class="col-md-6 mb-3">
                            <label for="recursado_curso_origen" class="form-label">Curso de la Materia a Recursar *</label>
                            <select class="form-select" id="recursado_curso_origen" onchange="cargarMateriasRecursado^(^)" required>
                                <option value="">-- Seleccione el curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="^<?= $curso['id'] ?^>"><?= htmlspecialchars($curso['nombre']) ?> (<?= $curso['anio'] ?>° año)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="recursado_materia_curso_id" class="form-label">Materia a Recursar *</label>
                            <select class="form-select" id="recursado_materia_curso_id" name="materia_curso_id" required onchange="mostrarResumenRecursado^(^)">
                                <option value="">-- Primero seleccione un curso --</option>
                            </select>
                        </div>
ECHO está desactivado.
                        <div class="col-md-6 mb-3">
                            <label for="recursado_materia_liberada_id" class="form-label">Materia a Liberar (Año Actual) *</label>
                            <select class="form-select" id="recursado_materia_liberada_id" name="materia_liberada_id" required onchange="mostrarResumenRecursado^(^)">
                                <option value="">-- Primero seleccione un estudiante --</option>
                            </select>
                        </div>
                    </div>
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <div id="resumen_recursado" class="alert alert-warning" style="display: none;">
                                <strong>Resumen del Recursado:</strong>
                                <div id="resumen_contenido"></div>
                            </div>
                        </div>
                    </div>
ECHO está desactivado.
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="recursado_observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control" id="recursado_observaciones" name="observaciones" rows="3" placeholder="Motivo del recursado, observaciones adicionales..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Asignar Recursado</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ver Estudiantes -->
<div class="modal fade" id="modalVerEstudiantes" tabindex="-1" aria-labelledby="modalVerEstudiantesLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalVerEstudiantesLabel">Estudiantes del Curso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="contenidoEstudiantes">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formularios ocultos -->
<form id="formEliminarCurso" method="POST" action="cursos.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_curso">
    <input type="hidden" name="curso_id" id="eliminar_curso_id">
    <input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>">
    <input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>">
    <input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>">
</form>
