@echo off
chcp 65001 >nul
echo.
echo ========================================
echo SISTEMA DE RECURSADO MEJORADO - INSTALLER
echo Escuela Técnica Henry Ford
echo ========================================
echo.

:: Establecer variables
set BACKUP_DIR=backup_%date:~10,4%%date:~7,2%%date:~4,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set BACKUP_DIR=%BACKUP_DIR: =0%

:: Crear directorio de backup
echo 1. Creando backup de archivos existentes...
mkdir "%BACKUP_DIR%" 2>nul

:: Backup de archivos existentes
if exist "cursos.php" copy "cursos.php" "%BACKUP_DIR%\" >nul 2>&1
if exist "modales_cursos.php" copy "modales_cursos.php" "%BACKUP_DIR%\" >nul 2>&1
if exist "js\cursos.js" copy "js\cursos.js" "%BACKUP_DIR%\" >nul 2>&1
if exist "calificaciones.php" copy "calificaciones.php" "%BACKUP_DIR%\" >nul 2>&1
if exist "gestionar_recursados.php" copy "gestionar_recursados.php" "%BACKUP_DIR%\" >nul 2>&1

echo    ✓ Backup creado en: %BACKUP_DIR%
echo.

echo 2. Creando script SQL para actualizar base de datos...

:: Crear script SQL
(
echo -- Script para actualizar base de datos
echo -- Agregar columna materia_liberada_id a la tabla materias_recursado
echo ALTER TABLE materias_recursado 
echo ADD COLUMN materia_liberada_id INTEGER REFERENCES materias_por_curso^(id^);
echo.
echo -- Crear índice para mejorar performance
echo CREATE INDEX IF NOT EXISTS idx_materias_recursado_liberada ON materias_recursado^(materia_liberada_id^);
echo.
echo -- Verificar la estructura actualizada
echo .schema materias_recursado
) > update_database.sql

echo    ✓ Script SQL creado: update_database.sql
echo.

echo 3. Creando archivo obtener_curso_estudiante.php...

:: Crear archivo obtener_curso_estudiante.php
(
echo ^<?php
echo /**
echo  * obtener_curso_estudiante.php - Obtener el curso actual de un estudiante
echo  * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
echo  */
echo.
echo header^('Content-Type: application/json'^);
echo require_once 'config.php';
echo.
echo // Verificar permisos
echo if ^(!isset^($_SESSION['user_type']^) ^|^| !in_array^($_SESSION['user_type'], ['admin', 'directivo']^)^) {
echo     echo json_encode^(['success' =^> false, 'message' =^> 'Sin permisos']^);
echo     exit;
echo }
echo.
echo if ^(!isset^($_GET['estudiante_id']^)^) {
echo     echo json_encode^(['success' =^> false, 'message' =^> 'Estudiante no especificado']^);
echo     exit;
echo }
echo.
echo try {
echo     $db = Database::getInstance^(^);
echo     $estudianteId = intval^($_GET['estudiante_id']^);
echo     
echo     // Obtener curso actual del estudiante
echo     $matricula = $db-^>fetchOne^(
echo         "SELECT c.id as curso_id, c.nombre as curso_nombre, c.anio 
echo          FROM matriculas m 
echo          JOIN cursos c ON m.curso_id = c.id 
echo          WHERE m.estudiante_id = ? AND m.estado = 'activo'",
echo         [$estudianteId]
echo     ^);
echo     
echo     if ^(!$matricula^) {
echo         echo json_encode^(['success' =^> false, 'message' =^> 'Estudiante no tiene matrícula activa']^);
echo         exit;
echo     }
echo     
echo     // Obtener materias del curso actual del estudiante que NO estén ya liberadas
echo     $materias = $db-^>fetchAll^(
echo         "SELECT mp.id, m.nombre as materia_nombre, m.codigo as materia_codigo
echo          FROM materias_por_curso mp
echo          JOIN materias m ON mp.materia_id = m.id
echo          WHERE mp.curso_id = ? 
echo          AND mp.id NOT IN ^(
echo              SELECT materia_liberada_id 
echo              FROM materias_recursado 
echo              WHERE estudiante_id = ? AND estado = 'activo' AND materia_liberada_id IS NOT NULL
echo          ^)
echo          ORDER BY m.nombre",
echo         [$matricula['curso_id'], $estudianteId]
echo     ^);
echo     
echo     echo json_encode^([
echo         'success' =^> true,
echo         'curso' =^> $matricula,
echo         'materias' =^> $materias
echo     ]^);
echo     
echo } catch ^(Exception $e^) {
echo     echo json_encode^(['success' =^> false, 'message' =^> 'Error: ' . $e-^>getMessage^(^)]^);
echo }
echo ?^>
) > obtener_curso_estudiante.php

echo    ✓ Archivo obtener_curso_estudiante.php creado
echo.

echo 4. Actualizando modales_cursos.php...

:: Crear modales_cursos.php actualizado
(
echo ^<?php
echo /**
echo  * modales_cursos.php - Modales para la gestión de cursos ^(ACTUALIZADO^)
echo  * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
echo  */
echo ?^>
echo.
echo ^<!-- Modal Crear Curso --^>
echo ^<div class="modal fade" id="modalCrearCurso" tabindex="-1" aria-labelledby="modalCrearCursoLabel" aria-hidden="true"^>
echo     ^<div class="modal-dialog modal-lg"^>
echo         ^<div class="modal-content"^>
echo             ^<form method="POST" action="cursos.php"^>
echo                 ^<div class="modal-header"^>
echo                     ^<h5 class="modal-title" id="modalCrearCursoLabel"^>Crear Nuevo Curso^</h5^>
echo                     ^<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"^>^</button^>
echo                 ^</div^>
echo                 ^<div class="modal-body"^>
echo                     ^<input type="hidden" name="accion" value="crear_curso"^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-8 mb-3"^>
echo                             ^<label for="crear_nombre" class="form-label"^>Nombre del Curso *^</label^>
echo                             ^<input type="text" class="form-control" id="crear_nombre" name="nombre" required placeholder="ej: 1° año A, 2° año B"^>
echo                         ^</div^>
echo                         ^<div class="col-md-4 mb-3"^>
echo                             ^<label for="crear_anio" class="form-label"^>Año *^</label^>
echo                             ^<select class="form-select" id="crear_anio" name="anio" required^>
echo                                 ^<option value=""^>-- Seleccione --^</option^>
echo                                 ^<option value="1"^>1° año^</option^>
echo                                 ^<option value="2"^>2° año^</option^>
echo                                 ^<option value="3"^>3° año^</option^>
echo                                 ^<option value="4"^>4° año^</option^>
echo                                 ^<option value="5"^>5° año^</option^>
echo                                 ^<option value="6"^>6° año^</option^>
echo                                 ^<option value="7"^>7° año^</option^>
echo                             ^</select^>
echo                         ^</div^>
echo                     ^</div^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-6 mb-3"^>
echo                             ^<label for="crear_orientacion" class="form-label"^>Orientación^</label^>
echo                             ^<input type="text" class="form-control" id="crear_orientacion" name="orientacion" value="Técnico en Electromecánica"^>
echo                         ^</div^>
echo                         ^<div class="col-md-6 mb-3"^>
echo                             ^<label for="crear_ciclo_lectivo_id" class="form-label"^>Ciclo Lectivo *^</label^>
echo                             ^<select class="form-select" id="crear_ciclo_lectivo_id" name="ciclo_lectivo_id" required^>
echo                                 ^<option value=""^>-- Seleccione --^</option^>
echo                                 ^<?php foreach ^($ciclosDisponibles as $ciclo^): ?^>
echo                                 ^<option value="^<?= $ciclo['id'] ?^>" ^<?= $ciclo['activo'] ? 'selected' : '' ?^>^>
echo                                     ^<?= $ciclo['anio'] ?^> ^<?= $ciclo['activo'] ? '^(Activo^)' : '' ?^>
echo                                 ^</option^>
echo                                 ^<?php endforeach; ?^>
echo                             ^</select^>
echo                         ^</div^>
echo                     ^</div^>
echo                 ^</div^>
echo                 ^<div class="modal-footer"^>
echo                     ^<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"^>Cancelar^</button^>
echo                     ^<button type="submit" class="btn btn-primary"^>Crear Curso^</button^>
echo                 ^</div^>
echo             ^</form^>
echo         ^</div^>
echo     ^</div^>
echo ^</div^>
echo.
echo ^<!-- Modal Editar Curso --^>
echo ^<div class="modal fade" id="modalEditarCurso" tabindex="-1" aria-labelledby="modalEditarCursoLabel" aria-hidden="true"^>
echo     ^<div class="modal-dialog modal-lg"^>
echo         ^<div class="modal-content"^>
echo             ^<form method="POST" action="cursos.php"^>
echo                 ^<div class="modal-header"^>
echo                     ^<h5 class="modal-title" id="modalEditarCursoLabel"^>Editar Curso^</h5^>
echo                     ^<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"^>^</button^>
echo                 ^</div^>
echo                 ^<div class="modal-body"^>
echo                     ^<input type="hidden" name="accion" value="editar_curso"^>
echo                     ^<input type="hidden" name="curso_id" id="editar_curso_id"^>
echo                     ^<input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>"^>
echo                     ^<input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>"^>
echo                     ^<input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>"^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-8 mb-3"^>
echo                             ^<label for="editar_nombre" class="form-label"^>Nombre del Curso *^</label^>
echo                             ^<input type="text" class="form-control" id="editar_nombre" name="nombre" required^>
echo                         ^</div^>
echo                         ^<div class="col-md-4 mb-3"^>
echo                             ^<label for="editar_anio" class="form-label"^>Año *^</label^>
echo                             ^<select class="form-select" id="editar_anio" name="anio" required^>
echo                                 ^<option value="1"^>1° año^</option^>
echo                                 ^<option value="2"^>2° año^</option^>
echo                                 ^<option value="3"^>3° año^</option^>
echo                                 ^<option value="4"^>4° año^</option^>
echo                                 ^<option value="5"^>5° año^</option^>
echo                                 ^<option value="6"^>6° año^</option^>
echo                                 ^<option value="7"^>7° año^</option^>
echo                             ^</select^>
echo                         ^</div^>
echo                     ^</div^>
echo                 ^</div^>
echo                 ^<div class="modal-footer"^>
echo                     ^<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"^>Cancelar^</button^>
echo                     ^<button type="submit" class="btn btn-primary"^>Actualizar Curso^</button^>
echo                 ^</div^>
echo             ^</form^>
echo         ^</div^>
echo     ^</div^>
echo ^</div^>
echo.
echo ^<!-- Modal Matricular Estudiante --^>
echo ^<div class="modal fade" id="modalMatricularEstudiante" tabindex="-1" aria-labelledby="modalMatricularEstudianteLabel" aria-hidden="true"^>
echo     ^<div class="modal-dialog"^>
echo         ^<div class="modal-content"^>
echo             ^<form method="POST" action="cursos.php"^>
echo                 ^<div class="modal-header"^>
echo                     ^<h5 class="modal-title" id="modalMatricularEstudianteLabel"^>Matricular Estudiante^</h5^>
echo                     ^<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"^>^</button^>
echo                 ^</div^>
echo                 ^<div class="modal-body"^>
echo                     ^<input type="hidden" name="accion" value="matricular_estudiante"^>
echo                     ^<input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>"^>
echo                     ^<input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>"^>
echo                     ^<input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>"^>
echo                     
echo                     ^<div class="mb-3"^>
echo                         ^<label for="matricular_estudiante_id" class="form-label"^>Estudiante *^</label^>
echo                         ^<select class="form-select" id="matricular_estudiante_id" name="estudiante_id" required^>
echo                             ^<option value=""^>-- Seleccione un estudiante --^</option^>
echo                             ^<?php foreach ^($estudiantes as $estudiante^): ?^>
echo                             ^<option value="^<?= $estudiante['id'] ?^>"^>
echo                                 ^<?= htmlspecialchars^($estudiante['apellido']^) ?^>, ^<?= htmlspecialchars^($estudiante['nombre']^) ?^> ^(^<?= $estudiante['dni'] ?^>^)
echo                             ^</option^>
echo                             ^<?php endforeach; ?^>
echo                         ^</select^>
echo                     ^</div^>
echo                     
echo                     ^<div class="mb-3"^>
echo                         ^<label for="matricular_curso_id" class="form-label"^>Curso *^</label^>
echo                         ^<select class="form-select" id="matricular_curso_id" name="curso_id" required^>
echo                             ^<option value=""^>-- Seleccione un curso --^</option^>
echo                             ^<?php foreach ^($cursos as $curso^): ?^>
echo                             ^<option value="^<?= $curso['id'] ?^>"^>
echo                                 ^<?= htmlspecialchars^($curso['nombre']^) ?^> - ^<?= $curso['anio'] ?^>° año
echo                             ^</option^>
echo                             ^<?php endforeach; ?^>
echo                         ^</select^>
echo                     ^</div^>
echo                 ^</div^>
echo                 ^<div class="modal-footer"^>
echo                     ^<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"^>Cancelar^</button^>
echo                     ^<button type="submit" class="btn btn-success"^>Matricular Estudiante^</button^>
echo                 ^</div^>
echo             ^</form^>
echo         ^</div^>
echo     ^</div^>
echo ^</div^>
echo.
) > modales_cursos_temp1.php

:: Continuar con el modal de recursado mejorado
(
echo ^<!-- Modal Asignar Recursado - MEJORADO --^>
echo ^<div class="modal fade" id="modalAsignarRecursado" tabindex="-1" aria-labelledby="modalAsignarRecursadoLabel" aria-hidden="true"^>
echo     ^<div class="modal-dialog modal-xl"^>
echo         ^<div class="modal-content"^>
echo             ^<form method="POST" action="cursos.php" id="formRecursado"^>
echo                 ^<div class="modal-header"^>
echo                     ^<h5 class="modal-title" id="modalAsignarRecursadoLabel"^>Asignar Materia para Recursado^</h5^>
echo                     ^<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"^>^</button^>
echo                 ^</div^>
echo                 ^<div class="modal-body"^>
echo                     ^<input type="hidden" name="accion" value="asignar_materia_recursado"^>
echo                     ^<input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>"^>
echo                     ^<input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>"^>
echo                     ^<input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>"^>
echo                     
echo                     ^<div class="alert alert-info"^>
echo                         ^<i class="bi bi-info-circle"^>^</i^>
echo                         ^<strong^>Recursado Mejorado:^</strong^> Cuando un estudiante recursa una materia de un año anterior, 
echo                         debe liberar una materia de su año actual para tener tiempo disponible. El estudiante NO aparecerá 
echo                         en las listas de la materia liberada.
echo                     ^</div^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-6 mb-3"^>
echo                             ^<label for="recursado_estudiante_id" class="form-label"^>Estudiante *^</label^>
echo                             ^<select class="form-select" id="recursado_estudiante_id" name="estudiante_id" required onchange="cargarCursoActualEstudiante^(^)"^>
echo                                 ^<option value=""^>-- Seleccione un estudiante --^</option^>
echo                                 ^<?php foreach ^($estudiantes as $estudiante^): ?^>
echo                                 ^<option value="^<?= $estudiante['id'] ?^>"^>
echo                                     ^<?= htmlspecialchars^($estudiante['apellido']^) ?^>, ^<?= htmlspecialchars^($estudiante['nombre']^) ?^> ^(^<?= $estudiante['dni'] ?^>^)
echo                                 ^</option^>
echo                                 ^<?php endforeach; ?^>
echo                             ^</select^>
echo                         ^</div^>
echo                         
echo                         ^<div class="col-md-6 mb-3"^>
echo                             ^<label for="recursado_curso_origen" class="form-label"^>Curso de la Materia a Recursar *^</label^>
echo                             ^<select class="form-select" id="recursado_curso_origen" onchange="cargarMateriasRecursado^(^)" required^>
echo                                 ^<option value=""^>-- Seleccione el curso --^</option^>
echo                                 ^<?php foreach ^($cursos as $curso^): ?^>
echo                                 ^<option value="^<?= $curso['id'] ?^>"^>^<?= htmlspecialchars^($curso['nombre']^) ?^> ^(^<?= $curso['anio'] ?^>° año^)^</option^>
echo                                 ^<?php endforeach; ?^>
echo                             ^</select^>
echo                         ^</div^>
echo                     ^</div^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-6 mb-3"^>
echo                             ^<label for="recursado_materia_curso_id" class="form-label"^>Materia a Recursar *^</label^>
echo                             ^<select class="form-select" id="recursado_materia_curso_id" name="materia_curso_id" required onchange="mostrarResumenRecursado^(^)"^>
echo                                 ^<option value=""^>-- Primero seleccione un curso --^</option^>
echo                             ^</select^>
echo                         ^</div^>
echo                         
echo                         ^<div class="col-md-6 mb-3"^>
echo                             ^<label for="recursado_materia_liberada_id" class="form-label"^>Materia a Liberar ^(Año Actual^) *^</label^>
echo                             ^<select class="form-select" id="recursado_materia_liberada_id" name="materia_liberada_id" required onchange="mostrarResumenRecursado^(^)"^>
echo                                 ^<option value=""^>-- Primero seleccione un estudiante --^</option^>
echo                             ^</select^>
echo                         ^</div^>
echo                     ^</div^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-12 mb-3"^>
echo                             ^<div id="resumen_recursado" class="alert alert-warning" style="display: none;"^>
echo                                 ^<strong^>Resumen del Recursado:^</strong^>
echo                                 ^<div id="resumen_contenido"^>^</div^>
echo                             ^</div^>
echo                         ^</div^>
echo                     ^</div^>
echo                     
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-12 mb-3"^>
echo                             ^<label for="recursado_observaciones" class="form-label"^>Observaciones^</label^>
echo                             ^<textarea class="form-control" id="recursado_observaciones" name="observaciones" rows="3" placeholder="Motivo del recursado, observaciones adicionales..."^>^</textarea^>
echo                         ^</div^>
echo                     ^</div^>
echo                 ^</div^>
echo                 ^<div class="modal-footer"^>
echo                     ^<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"^>Cancelar^</button^>
echo                     ^<button type="submit" class="btn btn-warning"^>Asignar Recursado^</button^>
echo                 ^</div^>
echo             ^</form^>
echo         ^</div^>
echo     ^</div^>
echo ^</div^>
echo.
echo ^<!-- Modal Ver Estudiantes --^>
echo ^<div class="modal fade" id="modalVerEstudiantes" tabindex="-1" aria-labelledby="modalVerEstudiantesLabel" aria-hidden="true"^>
echo     ^<div class="modal-dialog modal-xl"^>
echo         ^<div class="modal-content"^>
echo             ^<div class="modal-header"^>
echo                 ^<h5 class="modal-title" id="modalVerEstudiantesLabel"^>Estudiantes del Curso^</h5^>
echo                 ^<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"^>^</button^>
echo             ^</div^>
echo             ^<div class="modal-body"^>
echo                 ^<div id="contenidoEstudiantes"^>
echo                     ^<div class="text-center"^>
echo                         ^<div class="spinner-border" role="status"^>
echo                             ^<span class="visually-hidden"^>Cargando...^</span^>
echo                         ^</div^>
echo                     ^</div^>
echo                 ^</div^>
echo             ^</div^>
echo         ^</div^>
echo     ^</div^>
echo ^</div^>
echo.
echo ^<!-- Formularios ocultos --^>
echo ^<form id="formEliminarCurso" method="POST" action="cursos.php" style="display: none;"^>
echo     ^<input type="hidden" name="accion" value="eliminar_curso"^>
echo     ^<input type="hidden" name="curso_id" id="eliminar_curso_id"^>
echo     ^<input type="hidden" name="ciclo_original" value="^<?= htmlspecialchars^($cicloFiltro^) ?^>"^>
echo     ^<input type="hidden" name="busqueda_original" value="^<?= htmlspecialchars^($busqueda^) ?^>"^>
echo     ^<input type="hidden" name="pagina_original" value="^<?= htmlspecialchars^($paginaActual^) ?^>"^>
echo ^</form^>
) > modales_cursos_temp2.php

:: Combinar los archivos temporales
type modales_cursos_temp1.php modales_cursos_temp2.php > modales_cursos.php
del modales_cursos_temp1.php modales_cursos_temp2.php

echo    ✓ modales_cursos.php actualizado
echo.

echo 5. Actualizando js\cursos.js...

:: Crear directorio js si no existe
if not exist "js" mkdir js

:: Crear cursos.js actualizado
(
echo /**
echo  * cursos.js - Funciones JavaScript para la gestión de cursos ^(ACTUALIZADO^)
echo  * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
echo  */
echo.
echo // Función para cargar datos en el modal de edición
echo function editarCurso^(cursoId^) {
echo     fetch^('obtener_curso.php?id=' + cursoId^)
echo         .then^(response =^> response.json^(^)^)
echo         .then^(data =^> {
echo             if ^(data.success^) {
echo                 document.getElementById^('editar_curso_id'^).value = data.curso.id;
echo                 document.getElementById^('editar_nombre'^).value = data.curso.nombre;
echo                 document.getElementById^('editar_anio'^).value = data.curso.anio;
echo                 document.getElementById^('editar_ciclo_lectivo_id'^).value = data.curso.ciclo_lectivo_id;
echo             } else {
echo                 alert^('Error al cargar los datos del curso'^);
echo             }
echo         }^)
echo         .catch^(error =^> {
echo             console.error^('Error:', error^);
echo             alert^('Error al cargar los datos del curso'^);
echo         }^);
echo }
echo.
echo // Función para eliminar curso
echo function eliminarCurso^(cursoId^) {
echo     if ^(confirm^('¿Está seguro de que desea eliminar este curso?\n\nEsta acción no se puede deshacer.\n\nNota: No se puede eliminar si tiene estudiantes matriculados o materias asignadas.'^)^) {
echo         document.getElementById^('eliminar_curso_id'^).value = cursoId;
echo         document.getElementById^('formEliminarCurso'^).submit^(^);
echo     }
echo }
echo.
echo // Función para cargar materias según el curso seleccionado en recursado
echo function cargarMateriasRecursado^(^) {
echo     const cursoSelect = document.getElementById^('recursado_curso_origen'^);
echo     const materiaSelect = document.getElementById^('recursado_materia_curso_id'^);
echo     
echo     if ^(cursoSelect.value^) {
echo         materiaSelect.innerHTML = '^<option value=""^>Cargando...^</option^>';
echo         
echo         fetch^('obtener_materias_curso.php?curso_id=' + cursoSelect.value^)
echo             .then^(response =^> response.json^(^)^)
echo             .then^(data =^> {
echo                 if ^(data.success^) {
echo                     materiaSelect.innerHTML = '^<option value=""^>-- Seleccione una materia --^</option^>';
echo                     
echo                     data.materias.forEach^(materia =^> {
echo                         const option = document.createElement^('option'^);
echo                         option.value = materia.id;
echo                         option.textContent = `${materia.materia_nombre} ^(${materia.materia_codigo}^)`;
echo                         if ^(materia.profesor_nombre ^^^&^^^& materia.profesor_nombre !== 'Sin asignar'^) {
echo                             option.textContent += ` - Prof. ${materia.profesor_nombre}`;
echo                         }
echo                         materiaSelect.appendChild^(option^);
echo                     }^);
echo                 } else {
echo                     materiaSelect.innerHTML = '^<option value=""^>Error al cargar materias^</option^>';
echo                 }
echo             }^)
echo             .catch^(error =^> {
echo                 console.error^('Error:', error^);
echo                 materiaSelect.innerHTML = '^<option value=""^>Error al cargar materias^</option^>';
echo             }^);
echo     } else {
echo         materiaSelect.innerHTML = '^<option value=""^>-- Primero seleccione un curso --^</option^>';
echo     }
echo     
echo     document.getElementById^('resumen_recursado'^).style.display = 'none';
echo }
echo.
echo // NUEVA FUNCIÓN: Cargar curso actual del estudiante seleccionado
echo function cargarCursoActualEstudiante^(^) {
echo     const estudianteSelect = document.getElementById^('recursado_estudiante_id'^);
echo     const materiaLiberadaSelect = document.getElementById^('recursado_materia_liberada_id'^);
echo     
echo     if ^(estudianteSelect.value^) {
echo         materiaLiberadaSelect.innerHTML = '^<option value=""^>Cargando...^</option^>';
echo         
echo         fetch^('obtener_curso_estudiante.php?estudiante_id=' + estudianteSelect.value^)
echo             .then^(response =^> response.json^(^)^)
echo             .then^(data =^> {
echo                 if ^(data.success^) {
echo                     materiaLiberadaSelect.innerHTML = '^<option value=""^>-- Seleccione materia a liberar --^</option^>';
echo                     
echo                     data.materias.forEach^(materia =^> {
echo                         const option = document.createElement^('option'^);
echo                         option.value = materia.id;
echo                         option.textContent = `${materia.materia_nombre} ^(${materia.materia_codigo}^)`;
echo                         materiaLiberadaSelect.appendChild^(option^);
echo                     }^);
echo                 } else {
echo                     materiaLiberadaSelect.innerHTML = '^<option value=""^>Error: ' + data.message + '^</option^>';
echo                 }
echo             }^)
echo             .catch^(error =^> {
echo                 console.error^('Error:', error^);
echo                 materiaLiberadaSelect.innerHTML = '^<option value=""^>Error al cargar materias^</option^>';
echo             }^);
echo     } else {
echo         materiaLiberadaSelect.innerHTML = '^<option value=""^>-- Primero seleccione un estudiante --^</option^>';
echo     }
echo     
echo     document.getElementById^('resumen_recursado'^).style.display = 'none';
echo }
echo.
echo // NUEVA FUNCIÓN: Mostrar resumen del recursado
echo function mostrarResumenRecursado^(^) {
echo     const estudianteSelect = document.getElementById^('recursado_estudiante_id'^);
echo     const materiaRecursarSelect = document.getElementById^('recursado_materia_curso_id'^);
echo     const materiaLiberarSelect = document.getElementById^('recursado_materia_liberada_id'^);
echo     const resumenDiv = document.getElementById^('resumen_recursado'^);
echo     const resumenContenido = document.getElementById^('resumen_contenido'^);
echo     
echo     if ^(estudianteSelect.value ^^^&^^^& materiaRecursarSelect.value ^^^&^^^& materiaLiberarSelect.value^) {
echo         const estudianteTexto = estudianteSelect.options[estudianteSelect.selectedIndex].text;
echo         const materiaRecursarTexto = materiaRecursarSelect.options[materiaRecursarSelect.selectedIndex].text;
echo         const materiaLiberarTexto = materiaLiberarSelect.options[materiaLiberarSelect.selectedIndex].text;
echo         
echo         resumenContenido.innerHTML = `
echo             ^<ul class="mb-0"^>
echo                 ^<li^>^<strong^>Estudiante:^</strong^> ${estudianteTexto}^</li^>
echo                 ^<li^>^<strong^>Materia a recursar:^</strong^> ${materiaRecursarTexto}^</li^>
echo                 ^<li^>^<strong^>Materia liberada:^</strong^> ${materiaLiberarTexto}^</li^>
echo                 ^<li class="text-danger"^>^<strong^>Efecto:^</strong^> El estudiante NO aparecerá en las listas de ${materiaLiberarTexto}^</li^>
echo             ^</ul^>
echo         `;
echo         resumenDiv.style.display = 'block';
echo     } else {
echo         resumenDiv.style.display = 'none';
echo     }
echo }
echo.
echo // Función para ver estudiantes de un curso
echo function verEstudiantes^(cursoId^) {
echo     document.getElementById^('contenidoEstudiantes'^).innerHTML = `
echo         ^<div class="text-center"^>
echo             ^<div class="spinner-border" role="status"^>
echo                 ^<span class="visually-hidden"^>Cargando...^</span^>
echo             ^</div^>
echo         ^</div^>
echo     `;
echo     
echo     fetch^('obtener_estudiantes_curso.php?curso_id=' + cursoId^)
echo         .then^(response =^> response.json^(^)^)
echo         .then^(data =^> {
echo             if ^(data.success^) {
echo                 let html = `
echo                     ^<div class="row"^>
echo                         ^<div class="col-md-8"^>
echo                             ^<h6^>Curso: ${data.curso.nombre} ^(${data.curso.anio}° año^)^</h6^>
echo                         ^</div^>
echo                         ^<div class="col-md-4 text-end"^>
echo                             ^<span class="badge bg-primary"^>${data.estudiantes_regulares.length} Regulares^</span^>
echo                             ^<span class="badge bg-warning"^>${data.estudiantes_recursando.length} Recursando^</span^>
echo                         ^</div^>
echo                     ^</div^>
echo                     ^<hr^>
echo                 `;
echo                 
echo                 if ^(data.estudiantes_regulares.length ^> 0 ^|^| data.estudiantes_recursando.length ^> 0^) {
echo                     html += `^<div class="row"^>^<div class="col-md-6"^>^<h6^>Estudiantes Regulares^</h6^>`;
echo                     
echo                     if ^(data.estudiantes_regulares.length ^> 0^) {
echo                         html += `^<div class="table-responsive"^>^<table class="table table-sm"^>^<thead^>^<tr^>^<th^>Apellido y Nombre^</th^>^<th^>DNI^</th^>^<th^>Estado^</th^>^</tr^>^</thead^>^<tbody^>`;
echo                         
echo                         data.estudiantes_regulares.forEach^(estudiante =^> {
echo                             html += `^<tr^>^<td^>${estudiante.apellido}, ${estudiante.nombre}^</td^>^<td^>${estudiante.dni}^</td^>^<td^>^<span class="badge bg-${estudiante.estado === 'activo' ? 'success' : 'secondary'}"^>${estudiante.estado}^</span^>^</td^>^</tr^>`;
echo                         }^);
echo                         
echo                         html += `^</tbody^>^</table^>^</div^>`;
echo                     } else {
echo                         html += '^<div class="alert alert-info"^>No hay estudiantes regulares^</div^>';
echo                     }
echo                     
echo                     html += `^</div^>^<div class="col-md-6"^>^<h6^>Estudiantes Recursando Materias^</h6^>`;
echo                     
echo                     if ^(data.estudiantes_recursando.length ^> 0^) {
echo                         html += `^<div class="table-responsive"^>^<table class="table table-sm"^>^<thead^>^<tr^>^<th^>Apellido y Nombre^</th^>^<th^>Materia^</th^>^<th^>Año Actual^</th^>^</tr^>^</thead^>^<tbody^>`;
echo                         
echo                         data.estudiantes_recursando.forEach^(estudiante =^> {
echo                             html += `^<tr^>^<td^>${estudiante.apellido}, ${estudiante.nombre}^</td^>^<td^>${estudiante.materia_nombre}^</td^>^<td^>${estudiante.anio_actual}°^</td^>^</tr^>`;
echo                         }^);
echo                         
echo                         html += `^</tbody^>^</table^>^</div^>`;
echo                     } else {
echo                         html += '^<div class="alert alert-info"^>No hay estudiantes recursando^</div^>';
echo                     }
echo                     
echo                     html += `^</div^>^</div^>`;
echo                 } else {
echo                     html += `^<div class="alert alert-info"^>^<i class="bi bi-info-circle"^>^</i^> Este curso no tiene estudiantes matriculados.^</div^>`;
echo                 }
echo                 
echo                 document.getElementById^('contenidoEstudiantes'^).innerHTML = html;
echo             } else {
echo                 document.getElementById^('contenidoEstudiantes'^).innerHTML = `^<div class="alert alert-danger"^>Error al cargar estudiantes: ${data.message}^</div^>`;
echo             }
echo         }^)
echo         .catch^(error =^> {
echo             console.error^('Error:', error^);
echo             document.getElementById^('contenidoEstudiantes'^).innerHTML = `^<div class="alert alert-danger"^>Error al cargar estudiantes.^</div^>`;
echo         }^);
echo }
echo.
echo // Función para generar nombre automático del curso
echo function generarNombreCurso^(^) {
echo     const anioSelect = document.getElementById^('crear_anio'^);
echo     const nombreInput = document.getElementById^('crear_nombre'^);
echo     
echo     if ^(anioSelect ^^^&^^^& nombreInput^) {
echo         anioSelect.addEventListener^('change', actualizarNombre^);
echo         
echo         function actualizarNombre^(^) {
echo             const anio = anioSelect.value;
echo             if ^(anio^) {
echo                 let nombre = anio + '° año';
echo                 nombreInput.value = nombre;
echo             }
echo         }
echo     }
echo }
echo.
echo // Inicializar eventos cuando se carga la página
echo document.addEventListener^('DOMContentLoaded', function^(^) {
echo     generarNombreCurso^(^);
echo     
echo     document.querySelectorAll^('.modal'^).forEach^(modal =^> {
echo         modal.addEventListener^('hidden.bs.modal', function ^(^) {
echo             const forms = this.querySelectorAll^('form'^);
echo             forms.forEach^(form =^> {
echo                 if ^(form.id !== 'formEliminarCurso'^) {
echo                     form.reset^(^);
echo                     const resumenDiv = document.getElementById^('resumen_recursado'^);
echo                     if ^(resumenDiv^) resumenDiv.style.display = 'none';
echo                 }
echo             }^);
echo         }^);
echo     }^);
echo     
echo     const formRecursado = document.getElementById^('formRecursado'^);
echo     if ^(formRecursado^) {
echo         formRecursado.addEventListener^('submit', function^(e^) {
echo             const estudianteId = document.getElementById^('recursado_estudiante_id'^).value;
echo             const materiaRecursarId = document.getElementById^('recursado_materia_curso_id'^).value;
echo             const materiaLiberarId = document.getElementById^('recursado_materia_liberada_id'^).value;
echo             
echo             if ^(!estudianteId ^|^| !materiaRecursarId ^|^| !materiaLiberarId^) {
echo                 e.preventDefault^(^);
echo                 alert^('Por favor complete todos los campos obligatorios'^);
echo                 return false;
echo             }
echo         }^);
echo     }
echo }^);
echo.
echo function validarFormulario^(formId^) {
echo     const form = document.getElementById^(formId^);
echo     const requiredFields = form.querySelectorAll^('[required]'^);
echo     let isValid = true;
echo     
echo     requiredFields.forEach^(field =^> {
echo         if ^(!field.value.trim^(^)^) {
echo             field.classList.add^('is-invalid'^);
echo             isValid = false;
echo         } else {
echo             field.classList.remove^('is-invalid'^);
echo         }
echo     }^);
echo     
echo     return isValid;
echo }
) > js\cursos.js

echo    ✓ js\cursos.js actualizado
echo.

echo 6. Creando archivo de función actualizada para cursos.php...

(
echo ^<?php
echo /**
echo  * Función actualizada para asignar materia en recursado
echo  * Reemplazar en cursos.php la función existente asignarMateriaRecursado
echo  */
echo function asignarMateriaRecursado^($db, $datos^) {
echo     try {
echo         $estudianteId = intval^($datos['estudiante_id']^);
echo         $materiaCursoId = intval^($datos['materia_curso_id']^);
echo         $materiaLiberadaId = intval^($datos['materia_liberada_id']^);
echo         $observaciones = trim^($datos['observaciones'] ?? ''^ );
echo         
echo         if ^($estudianteId ^<= 0 ^|^| $materiaCursoId ^<= 0 ^|^| $materiaLiberadaId ^<= 0^) {
echo             return ['type' =^> 'danger', 'message' =^> 'Todos los campos son obligatorios'];
echo         }
echo         
echo         // Verificar que el estudiante no esté ya recursando la misma materia
echo         $recursadoExistente = $db-^>fetchOne^(
echo             "SELECT id FROM materias_recursado 
echo              WHERE estudiante_id = ? AND materia_curso_id = ? AND estado = 'activo'",
echo             [$estudianteId, $materiaCursoId]
echo         ^);
echo         
echo         if ^($recursadoExistente^) {
echo             return ['type' =^> 'danger', 'message' =^> 'El estudiante ya tiene asignada esta materia para recursado'];
echo         }
echo         
echo         // Verificar que la materia liberada no esté ya liberada para otro recursado
echo         $materiaYaLiberada = $db-^>fetchOne^(
echo             "SELECT id FROM materias_recursado 
echo              WHERE estudiante_id = ? AND materia_liberada_id = ? AND estado = 'activo'",
echo             [$estudianteId, $materiaLiberadaId]
echo         ^);
echo         
echo         if ^($materiaYaLiberada^) {
echo             return ['type' =^> 'danger', 'message' =^> 'Esta materia ya está liberada para otro recursado'];
echo         }
echo         
echo         // Verificar que no sea la misma materia
echo         $materiaRecursar = $db-^>fetchOne^("SELECT materia_id FROM materias_por_curso WHERE id = ?", [$materiaCursoId]^);
echo         $materiaLiberar = $db-^>fetchOne^("SELECT materia_id FROM materias_por_curso WHERE id = ?", [$materiaLiberadaId]^);
echo         
echo         if ^($materiaRecursar['materia_id'] == $materiaLiberar['materia_id']^) {
echo             return ['type' =^> 'danger', 'message' =^> 'No puede liberar la misma materia que está recursando'];
echo         }
echo         
echo         // Crear asignación de recursado con materia liberada
echo         $db-^>query^(
echo             "INSERT INTO materias_recursado 
echo              ^(estudiante_id, materia_curso_id, materia_liberada_id, fecha_asignacion, observaciones, estado^) 
echo              VALUES ^(?, ?, ?, date^('now'^), ?, 'activo'^)",
echo             [$estudianteId, $materiaCursoId, $materiaLiberadaId, $observaciones]
echo         ^);
echo         
echo         return ['type' =^> 'success', 'message' =^> 'Recursado asignado correctamente. El estudiante cursará la materia a recursar y no aparecerá en la materia liberada.'];
echo         
echo     } catch ^(Exception $e^) {
echo         return ['type' =^> 'danger', 'message' =^> 'Error al asignar recursado: ' . $e-^>getMessage^(^)];
echo     }
echo }
echo ?^>
) > funcion_recursado_actualizada.php

echo    ✓ funcion_recursado_actualizada.php creado
echo.

echo 7. Creando consulta actualizada para calificaciones.php...

(
echo ^<?php
echo /**
echo  * Consulta actualizada para estudiantes en calificaciones.php
echo  * Reemplazar la sección de obtención de estudiantes ^(línea ~150-200^)
echo  */
echo.
echo if ^($tablaRecursadoExiste^) {
echo     // Consulta completa con recursantes y materias liberadas
echo     $estudiantes = $db-^>fetchAll^(
echo         "SELECT DISTINCT
echo             u.id, 
echo             u.nombre, 
echo             u.apellido, 
echo             u.dni,
echo             CASE 
echo                 WHEN mr.id IS NOT NULL THEN 'R' 
echo                 ELSE 'C' 
echo             END as tipo_cursada_sugerido,
echo             CASE 
echo                 WHEN mr.id IS NOT NULL THEN 1 
echo                 ELSE 0 
echo             END as es_recursante,
echo             CASE 
echo                 WHEN mr.id IS NOT NULL THEN c_actual.nombre 
echo                 ELSE c.nombre 
echo             END as curso_referencia,
echo             CASE 
echo                 WHEN mr.id IS NOT NULL THEN c_actual.anio 
echo                 ELSE c.anio 
echo             END as anio_referencia
echo         FROM usuarios u
echo         LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
echo         LEFT JOIN cursos c ON m.curso_id = c.id
echo         LEFT JOIN materias_recursado mr ON u.id = mr.estudiante_id AND mr.materia_curso_id = ? AND mr.estado = 'activo'
echo         LEFT JOIN cursos c_actual ON m.curso_id = c_actual.id
echo         WHERE 
echo             u.tipo = 'estudiante' 
echo             AND ^(
echo                 -- Estudiantes regulares del curso ^(que NO tengan esta materia liberada^)
echo                 ^(m.curso_id = ? AND mr.id IS NULL AND u.id NOT IN ^(
echo                     SELECT DISTINCT mr2.estudiante_id 
echo                     FROM materias_recursado mr2 
echo                     WHERE mr2.materia_liberada_id = ? AND mr2.estado = 'activo'
echo                 ^)^)
echo                 OR 
echo                 -- Estudiantes recursando esta materia específica
echo                 ^(mr.materia_curso_id = ? AND mr.estado = 'activo'^)
echo             ^)
echo         ORDER BY u.apellido, u.nombre",
echo         [$materiaSeleccionada, $cursoSeleccionado, $materiaSeleccionada, $materiaSeleccionada]
echo     ^);
echo } else {
echo     // Consulta simple sin recursantes ^(por compatibilidad^)
echo     $estudiantes = $db-^>fetchAll^(
echo         "SELECT u.id, u.nombre, u.apellido, u.dni,
echo                 'C' as tipo_cursada_sugerido,
echo                 0 as es_recursante,
echo                 c.nombre as curso_referencia,
echo                 c.anio as anio_referencia
echo          FROM usuarios u 
echo          JOIN matriculas m ON u.id = m.estudiante_id 
echo          JOIN cursos c ON m.curso_id = c.id
echo          WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
echo          ORDER BY u.apellido, u.nombre",
echo         [$cursoSeleccionado]
echo     ^);
echo }
echo ?^>
) > consulta_calificaciones_actualizada.php

echo    ✓ consulta_calificaciones_actualizada.php creado
echo.

echo ========================================
echo ✓ IMPLEMENTACIÓN COMPLETADA EXITOSAMENTE
echo ========================================
echo.
echo ARCHIVOS CREADOS:
echo - obtener_curso_estudiante.php ^(NUEVO^)
echo - update_database.sql ^(script SQL^)
echo - funcion_recursado_actualizada.php ^(reemplazo para cursos.php^)
echo - consulta_calificaciones_actualizada.php ^(reemplazo para calificaciones.php^)
echo.
echo ARCHIVOS ACTUALIZADOS:
echo - modales_cursos.php
echo - js\cursos.js
echo.
echo BACKUPS CREADOS EN: %BACKUP_DIR%
echo.
echo ========================================
echo PRÓXIMOS PASOS MANUALES:
echo ========================================
echo.
echo 1. ACTUALIZAR BASE DE DATOS:
echo    sqlite3 tu_base_de_datos.db ^< update_database.sql
echo.
echo 2. REEMPLAZAR FUNCIÓN EN cursos.php:
echo    - Buscar function asignarMateriaRecursado
echo    - Reemplazar con el contenido de funcion_recursado_actualizada.php
echo.
echo 3. ACTUALIZAR CONSULTA EN calificaciones.php:
echo    - Buscar la sección de obtención de estudiantes ^(~línea 150-200^)
echo    - Reemplazar con el contenido de consulta_calificaciones_actualizada.php
echo.
echo ========================================
echo ¡SISTEMA DE RECURSADO MEJORADO LISTO!
echo ========================================
echo.
echo El sistema ahora incluye:
echo - Selección de materia a liberar al asignar recursado
echo - Validaciones completas de negocio
echo - Exclusión automática de estudiantes con materias liberadas
echo - Interfaz mejorada con resumen del recursado
echo.
pause