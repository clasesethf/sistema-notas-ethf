/**
 * cursos.js - Funciones JavaScript para la gestión de cursos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Función para cargar datos en el modal de edición
function editarCurso(cursoId) {
    // Hacer petición AJAX para obtener datos del curso
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
    
    if (cursoSelect.value) {
        // Limpiar select de materias
        materiaSelect.innerHTML = '<option value="">Cargando...</option>';
        
        // Hacer petición AJAX para obtener materias del curso
        fetch('obtener_materias_curso.php?curso_id=' + cursoSelect.value)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    materiaSelect.innerHTML = '<option value="">-- Seleccione una materia --</option>';
                    
                    data.materias.forEach(materia => {
                        const option = document.createElement('option');
                        option.value = materia.id;
                        option.textContent = `${materia.materia_nombre} (${materia.materia_codigo})`;
                        if (materia.profesor_nombre && materia.profesor_nombre !== 'Sin asignar') {
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
                
                if (data.estudiantes_regulares.length > 0 || data.estudiantes_recursando.length > 0) {
                    html += `
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Estudiantes Regulares</h6>
                    `;
                    
                    if (data.estudiantes_regulares.length > 0) {
                        html += `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Apellido y Nombre</th>
                                                <th>DNI</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        data.estudiantes_regulares.forEach(estudiante => {
                            html += `
                                <tr>
                                    <td>${estudiante.apellido}, ${estudiante.nombre}</td>
                                    <td>${estudiante.dni}</td>
                                    <td><span class="badge bg-${estudiante.estado === 'activo' ? 'success' : 'secondary'}">${estudiante.estado}</span></td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                        </tbody>
                                    </table>
                                </div>
                        `;
                    } else {
                        html += '<div class="alert alert-info">No hay estudiantes regulares</div>';
                    }
                    
                    html += `
                            </div>
                            <div class="col-md-6">
                                <h6>Estudiantes Recursando Materias</h6>
                    `;
                    
                    if (data.estudiantes_recursando.length > 0) {
                        html += `
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Apellido y Nombre</th>
                                                <th>Materia</th>
                                                <th>Año Actual</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                        `;
                        
                        data.estudiantes_recursando.forEach(estudiante => {
                            html += `
                                <tr>
                                    <td>${estudiante.apellido}, ${estudiante.nombre}</td>
                                    <td>${estudiante.materia_nombre}</td>
                                    <td>${estudiante.anio_actual}°</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="eliminarRecursadoRapido('${estudiante.id}', '${estudiante.nombre}', '${estudiante.apellido}', '${estudiante.materia_nombre}')"
                                                title="Eliminar recursado">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                        </tbody>
                                    </table>
                                </div>
                        `;
                    } else {
                        html += '<div class="alert alert-info">No hay estudiantes recursando</div>';
                    }
                    
                    html += `
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Este curso no tiene estudiantes matriculados.
                        </div>
                    `;
                }
                
                document.getElementById('contenidoEstudiantes').innerHTML = html;
            } else {
                document.getElementById('contenidoEstudiantes').innerHTML = `
                    <div class="alert alert-danger">
                        Error al cargar estudiantes: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('contenidoEstudiantes').innerHTML = `
                <div class="alert alert-danger">
                    Error al cargar estudiantes.
                </div>
            `;
        });
}

// Función para generar nombre automático del curso
function generarNombreCurso() {
    const anioSelect = document.getElementById('crear_anio');
    const nombreInput = document.getElementById('crear_nombre');
    
    if (anioSelect && nombreInput) {
        anioSelect.addEventListener('change', actualizarNombre);
        
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
    // Generar nombre automático del curso
    generarNombreCurso();
    
    // Limpiar formularios cuando se cierran los modales
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function () {
            const forms = this.querySelectorAll('form');
            forms.forEach(form => {
                if (form.id !== 'formEliminarCurso') { // No limpiar formularios ocultos
                    form.reset();
                }
            });
        });
    });
});

// Función para eliminar recursado rápidamente
function eliminarRecursadoRapido(recursadoId, nombre, apellido, materia) {
    if (confirm(`¿Eliminar el recursado de ${apellido}, ${nombre} en ${materia}?`)) {
        // Crear formulario temporal
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'gestionar_recursados.php';
        form.style.display = 'none';
        
        const accionInput = document.createElement('input');
        accionInput.type = 'hidden';
        accionInput.name = 'accion';
        accionInput.value = 'eliminar_recursado';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'recursado_id';
        idInput.value = recursadoId;
        
        form.appendChild(accionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        
        form.submit();
    }
}

// Función para validar formularios
function validarFormulario(formId) {
    const form = document.getElementById(formId);
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    return isValid;
}