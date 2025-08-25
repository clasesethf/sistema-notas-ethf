/**
 * cursos.js - Funciones JavaScript para la gestión de cursos (ACTUALIZADO)
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Función para cargar datos en el modal de edición
function editarCurso(cursoId) {
    fetch('obtener_curso.php?id=' + cursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editar_curso_id').value = data.curso.id;
                document.getElementById('editar_nombre').value = data.curso.nombre;
                document.getElementById('editar_anio').value = data.curso.anio;
                document.getElementById('editar_ciclo_lectivo_id').value = data.curso.ciclo_lectivo_id;
            } else {
                alert('Error al cargar los datos del curso');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar los datos del curso');
        });
}

// Función para eliminar curso
function eliminarCurso(cursoId) {
    if (confirm('¿Está seguro de que desea eliminar este curso?\n\nEsta acción no se puede deshacer.\n\nNota: No se puede eliminar si tiene estudiantes matriculados o materias asignadas.')) {
        document.getElementById('eliminar_curso_id').value = cursoId;
        document.getElementById('formEliminarCurso').submit();
    }
}

// Función para cargar materias según el curso seleccionado en recursado
function cargarMateriasRecursado() {
    const cursoSelect = document.getElementById('recursado_curso_origen');
    const materiaSelect = document.getElementById('recursado_materia_curso_id');
ECHO está desactivado.
    if (cursoSelect.value) {
        materiaSelect.innerHTML = '<option value="">Cargando...</option>';
ECHO está desactivado.
        fetch('obtener_materias_curso.php?curso_id=' + cursoSelect.value)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    materiaSelect.innerHTML = '<option value="">-- Seleccione una materia --</option>';
ECHO está desactivado.
                    data.materias.forEach(materia => {
                        const option = document.createElement('option');
                        option.value = materia.id;
                        option.textContent = `${materia.materia_nombre} (${materia.materia_codigo})`;
                        if (materia.profesor_nombre ^&^& materia.profesor_nombre !== 'Sin asignar') {
                            option.textContent += ` - Prof. ${materia.profesor_nombre}`;
                        }
                        materiaSelect.appendChild(option);
                    });
                } else {
                    materiaSelect.innerHTML = '<option value="">Error al cargar materias</option>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                materiaSelect.innerHTML = '<option value="">Error al cargar materias</option>';
            });
    } else {
        materiaSelect.innerHTML = '<option value="">-- Primero seleccione un curso --</option>';
    }
ECHO está desactivado.
    document.getElementById('resumen_recursado').style.display = 'none';
}

// NUEVA FUNCIÓN: Cargar curso actual del estudiante seleccionado
function cargarCursoActualEstudiante() {
    const estudianteSelect = document.getElementById('recursado_estudiante_id');
    const materiaLiberadaSelect = document.getElementById('recursado_materia_liberada_id');
ECHO está desactivado.
    if (estudianteSelect.value) {
        materiaLiberadaSelect.innerHTML = '<option value="">Cargando...</option>';
ECHO está desactivado.
        fetch('obtener_curso_estudiante.php?estudiante_id=' + estudianteSelect.value)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    materiaLiberadaSelect.innerHTML = '<option value="">-- Seleccione materia a liberar --</option>';
ECHO está desactivado.
                    data.materias.forEach(materia => {
                        const option = document.createElement('option');
                        option.value = materia.id;
                        option.textContent = `${materia.materia_nombre} (${materia.materia_codigo})`;
                        materiaLiberadaSelect.appendChild(option);
                    });
                } else {
                    materiaLiberadaSelect.innerHTML = '<option value="">Error: ' + data.message + '</option>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                materiaLiberadaSelect.innerHTML = '<option value="">Error al cargar materias</option>';
            });
    } else {
        materiaLiberadaSelect.innerHTML = '<option value="">-- Primero seleccione un estudiante --</option>';
    }
ECHO está desactivado.
    document.getElementById('resumen_recursado').style.display = 'none';
}

// NUEVA FUNCIÓN: Mostrar resumen del recursado
function mostrarResumenRecursado() {
    const estudianteSelect = document.getElementById('recursado_estudiante_id');
    const materiaRecursarSelect = document.getElementById('recursado_materia_curso_id');
    const materiaLiberarSelect = document.getElementById('recursado_materia_liberada_id');
    const resumenDiv = document.getElementById('resumen_recursado');
    const resumenContenido = document.getElementById('resumen_contenido');
ECHO está desactivado.
    if (estudianteSelect.value ^&^& materiaRecursarSelect.value ^&^& materiaLiberarSelect.value) {
        const estudianteTexto = estudianteSelect.options[estudianteSelect.selectedIndex].text;
        const materiaRecursarTexto = materiaRecursarSelect.options[materiaRecursarSelect.selectedIndex].text;
        const materiaLiberarTexto = materiaLiberarSelect.options[materiaLiberarSelect.selectedIndex].text;
ECHO está desactivado.
        resumenContenido.innerHTML = `
            <ul class="mb-0">
                <li><strong>Estudiante:</strong> ${estudianteTexto}</li>
                <li><strong>Materia a recursar:</strong> ${materiaRecursarTexto}</li>
                <li><strong>Materia liberada:</strong> ${materiaLiberarTexto}</li>
                <li class="text-danger"><strong>Efecto:</strong> El estudiante NO aparecerá en las listas de ${materiaLiberarTexto}</li>
            </ul>
        `;
        resumenDiv.style.display = 'block';
    } else {
        resumenDiv.style.display = 'none';
    }
}

// Función para ver estudiantes de un curso
function verEstudiantes(cursoId) {
    document.getElementById('contenidoEstudiantes').innerHTML = `
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
        </div>
    `;
ECHO está desactivado.
    fetch('obtener_estudiantes_curso.php?curso_id=' + cursoId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `
                    <div class="row">
                        <div class="col-md-8">
                            <h6>Curso: ${data.curso.nombre} (${data.curso.anio}° año)</h6>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge bg-primary">${data.estudiantes_regulares.length} Regulares</span>
                            <span class="badge bg-warning">${data.estudiantes_recursando.length} Recursando</span>
                        </div>
                    </div>
                    <hr>
                `;
ECHO está desactivado.
                if (data.estudiantes_regulares.length > 0 || data.estudiantes_recursando.length > 0) {
                    html += `<div class="row"><div class="col-md-6"><h6>Estudiantes Regulares</h6>`;
ECHO está desactivado.
                    if (data.estudiantes_regulares.length > 0) {
                        html += `<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Apellido y Nombre</th><th>DNI</th><th>Estado</th></tr></thead><tbody>`;
ECHO está desactivado.
                        data.estudiantes_regulares.forEach(estudiante => {
                            html += `<tr><td>${estudiante.apellido}, ${estudiante.nombre}</td><td>${estudiante.dni}</td><td><span class="badge bg-${estudiante.estado === 'activo' ? 'success' : 'secondary'}">${estudiante.estado}</span></td></tr>`;
                        });
ECHO está desactivado.
                        html += `</tbody></table></div>`;
                    } else {
                        html += '<div class="alert alert-info">No hay estudiantes regulares</div>';
                    }
ECHO está desactivado.
                    html += `</div><div class="col-md-6"><h6>Estudiantes Recursando Materias</h6>`;
ECHO está desactivado.
                    if (data.estudiantes_recursando.length > 0) {
                        html += `<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Apellido y Nombre</th><th>Materia</th><th>Año Actual</th></tr></thead><tbody>`;
ECHO está desactivado.
                        data.estudiantes_recursando.forEach(estudiante => {
                            html += `<tr><td>${estudiante.apellido}, ${estudiante.nombre}</td><td>${estudiante.materia_nombre}</td><td>${estudiante.anio_actual}°</td></tr>`;
                        });
ECHO está desactivado.
                        html += `</tbody></table></div>`;
                    } else {
                        html += '<div class="alert alert-info">No hay estudiantes recursando</div>';
                    }
ECHO está desactivado.
                    html += `</div></div>`;
                } else {
                    html += `<div class="alert alert-info"><i class="bi bi-info-circle"></i> Este curso no tiene estudiantes matriculados.</div>`;
                }
ECHO está desactivado.
                document.getElementById('contenidoEstudiantes').innerHTML = html;
            } else {
                document.getElementById('contenidoEstudiantes').innerHTML = `<div class="alert alert-danger">Error al cargar estudiantes: ${data.message}</div>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoEstudiantes').innerHTML = `<div class="alert alert-danger">Error al cargar estudiantes.</div>`;
        });
}

// Función para generar nombre automático del curso
function generarNombreCurso() {
    const anioSelect = document.getElementById('crear_anio');
    const nombreInput = document.getElementById('crear_nombre');
ECHO está desactivado.
    if (anioSelect ^&^& nombreInput) {
        anioSelect.addEventListener('change', actualizarNombre);
ECHO está desactivado.
        function actualizarNombre() {
            const anio = anioSelect.value;
            if (anio) {
                let nombre = anio + '° año';
                nombreInput.value = nombre;
            }
        }
    }
}

// Inicializar eventos cuando se carga la página
document.addEventListener('DOMContentLoaded', function() {
    generarNombreCurso();
ECHO está desactivado.
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function () {
            const forms = this.querySelectorAll('form');
            forms.forEach(form => {
                if (form.id !== 'formEliminarCurso') {
                    form.reset();
                    const resumenDiv = document.getElementById('resumen_recursado');
                    if (resumenDiv) resumenDiv.style.display = 'none';
                }
            });
        });
    });
ECHO está desactivado.
    const formRecursado = document.getElementById('formRecursado');
    if (formRecursado) {
        formRecursado.addEventListener('submit', function(e) {
            const estudianteId = document.getElementById('recursado_estudiante_id').value;
            const materiaRecursarId = document.getElementById('recursado_materia_curso_id').value;
            const materiaLiberarId = document.getElementById('recursado_materia_liberada_id').value;
ECHO está desactivado.
            if (!estudianteId || !materiaRecursarId || !materiaLiberarId) {
                e.preventDefault();
                alert('Por favor complete todos los campos obligatorios');
                return false;
            }
        });
    }
});

function validarFormulario(formId) {
    const form = document.getElementById(formId);
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
ECHO está desactivado.
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
ECHO está desactivado.
    return isValid;
}
