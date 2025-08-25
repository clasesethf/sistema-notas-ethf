/**
 * cursos.js - Funciones JavaScript para la gestión de cursos (CORREGIDO)
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
    
    if (!cursoSelect || !materiaSelect) {
        console.error('Elementos no encontrados');
        return;
    }
    
    if (cursoSelect.value) {
        materiaSelect.innerHTML = '<option value="">Cargando...</option>';
        
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
    
    // Limpiar resumen
    const resumenDiv = document.getElementById('resumen_recursado');
    if (resumenDiv) {
        resumenDiv.style.display = 'none';
    }
}

// Función para cargar curso actual del estudiante seleccionado
function cargarCursoActualEstudiante() {
    const estudianteSelect = document.getElementById('recursado_estudiante_id');
    const materiaLiberadaSelect = document.getElementById('recursado_materia_liberada_id');
    
    if (!estudianteSelect || !materiaLiberadaSelect) {
        console.error('Elementos de estudiante/materia liberada no encontrados');
        return;
    }
    
    if (estudianteSelect.value) {
        materiaLiberadaSelect.innerHTML = '<option value="">Cargando...</option>';
        
        fetch('obtener_curso_estudiante.php?estudiante_id=' + estudianteSelect.value)
            .then(response => {
                // Verificar que la respuesta sea OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                // Verificar que el content-type sea JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    // Si no es JSON, obtener el texto para ver qué está devolviendo
                    return response.text().then(text => {
                        console.error('Respuesta no es JSON:', text);
                        throw new Error('El servidor no devolvió JSON válido. Posible error de PHP.');
                    });
                }
                
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    materiaLiberadaSelect.innerHTML = '<option value="">-- Seleccione materia a liberar --</option>';
                    
                    if (data.materias && Array.isArray(data.materias)) {
                        if (data.materias.length === 0) {
                            materiaLiberadaSelect.innerHTML = '<option value="">No hay materias disponibles para liberar</option>';
                        } else {
                            data.materias.forEach(materia => {
                                const option = document.createElement('option');
                                option.value = materia.id;
                                option.textContent = `${materia.materia_nombre} (${materia.materia_codigo})`;
                                materiaLiberadaSelect.appendChild(option);
                            });
                        }
                    }
                } else {
                    materiaLiberadaSelect.innerHTML = '<option value="">Error: ' + (data.message || 'Error desconocido') + '</option>';
                    console.error('Error del servidor:', data.message);
                    
                    // Mostrar alerta con más información
                    alert('Error al cargar materias: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error completo:', error);
                materiaLiberadaSelect.innerHTML = '<option value="">Error al cargar materias</option>';
                
                // Mostrar alerta con información del error
                if (error.message.includes('JSON')) {
                    alert('Error: El archivo obtener_curso_estudiante.php tiene errores de PHP.\n\nVerifica:\n1. Que el archivo existe\n2. Que no tiene errores de sintaxis\n3. Que config.php funciona correctamente');
                } else {
                    alert('Error de conexión: ' + error.message);
                }
            });
    } else {
        materiaLiberadaSelect.innerHTML = '<option value="">-- Primero seleccione un estudiante --</option>';
    }
    
    // Limpiar resumen
    const resumenDiv = document.getElementById('resumen_recursado');
    if (resumenDiv) {
        resumenDiv.style.display = 'none';
    }
}

// Función para mostrar resumen del recursado
function mostrarResumenRecursado() {
    const estudianteSelect = document.getElementById('recursado_estudiante_id');
    const materiaRecursarSelect = document.getElementById('recursado_materia_curso_id');
    const materiaLiberarSelect = document.getElementById('recursado_materia_liberada_id');
    const resumenDiv = document.getElementById('resumen_recursado');
    const resumenContenido = document.getElementById('resumen_contenido');
    
    if (!estudianteSelect || !materiaRecursarSelect || !materiaLiberarSelect || !resumenDiv || !resumenContenido) {
        return;
    }
    
    if (estudianteSelect.value && materiaRecursarSelect.value && materiaLiberarSelect.value) {
        const estudianteTexto = estudianteSelect.options[estudianteSelect.selectedIndex].text;
        const materiaRecursarTexto = materiaRecursarSelect.options[materiaRecursarSelect.selectedIndex].text;
        const materiaLiberarTexto = materiaLiberarSelect.options[materiaLiberarSelect.selectedIndex].text;
        
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
    const contenidoDiv = document.getElementById('contenidoEstudiantes');
    if (!contenidoDiv) return;
    
    contenidoDiv.innerHTML = `
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
                
                contenidoDiv.innerHTML = html;
            } else {
                contenidoDiv.innerHTML = `
                    <div class="alert alert-danger">
                        Error al cargar estudiantes: ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            contenidoDiv.innerHTML = `
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
        anioSelect.addEventListener('change', function() {
            const anio = this.value;
            if (anio) {
                nombreInput.value = anio + '° año';
            }
        });
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
                if (form.id !== 'formEliminarCurso') {
                    form.reset();
                    // Limpiar resumen de recursado
                    const resumenDiv = document.getElementById('resumen_recursado');
                    if (resumenDiv) {
                        resumenDiv.style.display = 'none';
                    }
                }
            });
        });
    });
    
    // Validar formulario de recursado antes de enviar
    const formRecursado = document.getElementById('formRecursado');
    if (formRecursado) {
        formRecursado.addEventListener('submit', function(e) {
            const estudianteId = document.getElementById('recursado_estudiante_id');
            const materiaRecursarId = document.getElementById('recursado_materia_curso_id');
            const materiaLiberarId = document.getElementById('recursado_materia_liberada_id');
            
            if (!estudianteId || !materiaRecursarId || !materiaLiberarId || 
                !estudianteId.value || !materiaRecursarId.value || !materiaLiberarId.value) {
                e.preventDefault();
                alert('Por favor complete todos los campos obligatorios');
                return false;
            }
        });
    }
    
    // Inicializar modales de Bootstrap manualmente para evitar errores
    try {
        const modalElements = document.querySelectorAll('.modal');
        modalElements.forEach(modalElement => {
            // Verificar si Bootstrap está disponible
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                new bootstrap.Modal(modalElement);
            }
        });
    } catch (error) {
        console.warn('Bootstrap Modal no está disponible, usando funcionalidad básica');
    }
});

// Función para validar formularios
function validarFormulario(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
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

// Función de fallback para abrir modal si Bootstrap falla
function abrirModalRecursado() {
    const modal = document.getElementById('modalAsignarRecursado');
    if (modal) {
        // Limpiar formulario
        const form = document.getElementById('formRecursado');
        if (form) form.reset();
        
        const resumen = document.getElementById('resumen_recursado');
        if (resumen) resumen.style.display = 'none';
        
        // Intentar usar Bootstrap Modal primero
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);
                modalInstance.show();
                return;
            }
        } catch (error) {
            console.warn('Bootstrap Modal falló, usando método alternativo');
        }
        
        // Método alternativo si Bootstrap falla
        modal.style.display = 'block';
        modal.classList.add('show');
        document.body.classList.add('modal-open');
        
        // Crear backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.id = 'modal-backdrop-recursado';
        document.body.appendChild(backdrop);
        
        // Cerrar con backdrop
        backdrop.addEventListener('click', function() {
            cerrarModalRecursado();
        });
    }
}

// Función de fallback para cerrar modal
function cerrarModalRecursado() {
    const modal = document.getElementById('modalAsignarRecursado');
    const backdrop = document.getElementById('modal-backdrop-recursado');
    
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.classList.remove('modal-open');
    }
    
    if (backdrop) {
        backdrop.remove();
    }
}

// Manejar tecla ESC para cerrar modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('modalAsignarRecursado');
        if (modal && (modal.classList.contains('show') || modal.style.display === 'block')) {
            cerrarModalRecursado();
        }
    }
});