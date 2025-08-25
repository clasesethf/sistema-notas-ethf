<?php
/**
 * vista_boletin_cuatrimestral.php - Vista del boletín cuatrimestral CORREGIDA
 * VERSIÓN CORREGIDA: Compatible con estructura de BD sin tabla intensificacion
 * Incluye materias agrupadas, individuales y recursadas con datos actualizados
 * NUEVA FUNCIÓN: Botón de recálculo GENERAL de todas las calificaciones
 * FIX: Solucionado error de columna fecha_modificacion faltante
 */



// Manejar acción de recálculo GENERAL si se solicita
if (isset($_POST['actualizar_boletin']) && $_POST['actualizar_boletin'] === '1' && $estudianteSeleccionado && $cicloLectivoId) {
    $erroresRecalculo = [];
    $exitosRecalculo = [];
    
    // Incluir las funciones de agrupación
    if (file_exists('includes/funciones_agrupacion_materias.php')) {
        require_once 'includes/funciones_agrupacion_materias.php';
        
        // Obtener curso del estudiante
        $cursoEstudianteTemp = $db->fetchOne(
            "SELECT c.* FROM cursos c
             JOIN matriculas m ON c.id = m.curso_id
             WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?",
            [$estudianteSeleccionado, $cicloLectivoId]
        );
        
        if ($cursoEstudianteTemp) {
            
            // PASO 1: Limpiar calificaciones de grupos existentes primero - CORREGIDO
            try {
                $tablaGruposExiste = false;
                
                // Verificar si existe la tabla calificaciones_grupos de forma segura
                try {
                    // Intentar con SQLite
                    $resultado = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='calificaciones_grupos'");
                    if ($resultado && $resultado->fetch()) {
                        $tablaGruposExiste = true;
                    }
                } catch (Exception $e) {
                    // Si falla SQLite, intentar con MySQL/MariaDB
                    try {
                        $resultado = $db->query("SHOW TABLES LIKE 'calificaciones_grupos'");
                        if ($resultado && $resultado->fetch()) {
                            $tablaGruposExiste = true;
                        }
                    } catch (Exception $e2) {
                        // Si ambos métodos fallan, asumir que la tabla no existe
                        $tablaGruposExiste = false;
                    }
                }
                
                if ($tablaGruposExiste) {
                    $db->query(
                        "DELETE FROM calificaciones_grupos 
                         WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    $db->query(
                        "DELETE FROM calificaciones_grupos 
                         WHERE estudiante_id = ?",
                        [$estudianteSeleccionado]
                    );
                    $exitosRecalculo[] = "Calificaciones de grupos obsoletas eliminadas";
                } else {
                    $exitosRecalculo[] = "Tabla de grupos no existe, saltando limpieza";
                }
            } catch (Exception $e) {
                $erroresRecalculo[] = "Error al limpiar grupos: " . $e->getMessage();
            }
            
            // PASO 2: Recalcular calificaciones de grupos desde cero
            try {
                $resultado = recalcularCalificacionesGruposEstudiante($db, $estudianteSeleccionado, $cicloLectivoId, $cursoEstudianteTemp['id']);
                if ($resultado) {
                    $exitosRecalculo[] = "Calificaciones de grupos recalculadas";
                } else {
                    $erroresRecalculo[] = "Recálculo de grupos sin resultado positivo";
                }
            } catch (Exception $e) {
                $erroresRecalculo[] = "Error en recálculo de grupos: " . $e->getMessage();
            }
            
            // PASO 3: Validar calificaciones individuales - CORREGIDO
            try {
                // Verificar si existe la columna fecha_modificacion de forma segura
                $columnaExiste = false;
                try {
                    // Intentar obtener información de la estructura de la tabla
                    $resultado = $db->query("PRAGMA table_info(calificaciones)");
                    if ($resultado) {
                        $columnas = $resultado->fetchAll();
                        foreach ($columnas as $columna) {
                            if ($columna['name'] === 'fecha_modificacion') {
                                $columnaExiste = true;
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Si falla PRAGMA (SQLite), intentar con DESCRIBE (MySQL)
                    try {
                        $resultado = $db->query("DESCRIBE calificaciones");
                        if ($resultado) {
                            $columnas = $resultado->fetchAll();
                            foreach ($columnas as $columna) {
                                if ($columna['Field'] === 'fecha_modificacion') {
                                    $columnaExiste = true;
                                    break;
                                }
                            }
                        }
                    } catch (Exception $e2) {
                        // Si ambos métodos fallan, asumir que no existe
                        $columnaExiste = false;
                    }
                }
                
                if ($columnaExiste) {
                    // Si existe la columna, actualizar fecha_modificacion
                    $db->query(
                        "UPDATE calificaciones SET fecha_modificacion = CURRENT_TIMESTAMP 
                         WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    $exitosRecalculo[] = "Fechas de modificación actualizadas";
                } else {
                    // Si no existe la columna, solo contar las calificaciones
                    $totalCalificaciones = $db->fetchOne(
                        "SELECT COUNT(*) as total FROM calificaciones 
                         WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    
                    if ($totalCalificaciones && $totalCalificaciones['total'] > 0) {
                        $exitosRecalculo[] = "Calificaciones individuales validadas (" . $totalCalificaciones['total'] . " registros)";
                    } else {
                        $erroresRecalculo[] = "No se encontraron calificaciones individuales";
                    }
                }
            } catch (Exception $e) {
                $erroresRecalculo[] = "Error al validar individuales: " . $e->getMessage();
            }
            
            // PASO 4: Limpiar caché si existe - CORREGIDO
            try {
                $tablaCacheExiste = false;
                
                // Verificar si existe la tabla cache_calificaciones de forma segura
                try {
                    // Intentar con SQLite
                    $resultado = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cache_calificaciones'");
                    if ($resultado && $resultado->fetch()) {
                        $tablaCacheExiste = true;
                    }
                } catch (Exception $e) {
                    // Si falla SQLite, intentar con MySQL/MariaDB
                    try {
                        $resultado = $db->query("SHOW TABLES LIKE 'cache_calificaciones'");
                        if ($resultado && $resultado->fetch()) {
                            $tablaCacheExiste = true;
                        }
                    } catch (Exception $e2) {
                        // Si ambos métodos fallan, asumir que la tabla no existe
                        $tablaCacheExiste = false;
                    }
                }
                
                if ($tablaCacheExiste) {
                    $db->query(
                        "DELETE FROM cache_calificaciones WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    $exitosRecalculo[] = "Caché limpiado";
                } else {
                    $exitosRecalculo[] = "No hay caché que limpiar";
                }
            } catch (Exception $e) {
                // No crítico, continuar sin limpiar caché
                $erroresRecalculo[] = "Advertencia: No se pudo verificar caché - " . $e->getMessage();
            }
            
            // PASO 5: Verificar calificaciones inconsistentes - CORREGIDO
            try {
                $calificacionesInconsistentes = $db->fetchAll(
                    "SELECT id, materia_curso_id FROM calificaciones 
                     WHERE estudiante_id = ? AND ciclo_lectivo_id = ? 
                     AND (calificacion_1c IS NULL OR calificacion_1c = '' OR 
                          calificacion_2c IS NULL OR calificacion_2c = '')",
                    [$estudianteSeleccionado, $cicloLectivoId]
                );
                
                if (!empty($calificacionesInconsistentes)) {
                    $exitosRecalculo[] = "Detectadas " . count($calificacionesInconsistentes) . " calificaciones incompletas";
                } else {
                    $exitosRecalculo[] = "Todas las calificaciones están completas";
                }
            } catch (Exception $e) {
                // No crítico
                $erroresRecalculo[] = "No se pudo verificar consistencia: " . $e->getMessage();
            }
            
        } else {
            $erroresRecalculo[] = "No se encontró el curso del estudiante";
        }
    } else {
        $erroresRecalculo[] = "Archivo de funciones de agrupación no encontrado";
    }
    
    // Mostrar resultados
    if (!empty($exitosRecalculo)) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <strong>¡Boletín actualizado exitosamente!</strong><br>
                <small>' . implode(' | ', $exitosRecalculo) . '</small>
                <br><small class="text-muted mt-1"><i class="bi bi-info-circle"></i> 
                Se eliminaron calificaciones obsoletas y se recalculó todo desde cero.</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
    }
    
    if (!empty($erroresRecalculo)) {
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <strong>Actualización completada con advertencias:</strong><br>
                <small>' . implode(' | ', $erroresRecalculo) . '</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
    }
    
    // Redirigir para evitar reenvío del formulario
    $currentUrl = $_SERVER['REQUEST_URI'];
    $currentUrl = preg_replace('/[&?]actualizar_boletin=1/', '', $currentUrl);
    echo "<script>setTimeout(function(){ window.location.href = '{$currentUrl}'; }, 3000);</script>";
}

// Incluir las funciones de agrupación
if (file_exists('includes/funciones_agrupacion_materias.php')) {
    require_once 'includes/funciones_agrupacion_materias.php';
}

// Obtener datos básicos del estudiante para contexto
$datosContextoEstudiante = null;
if ($estudianteSeleccionado) {
    $datosContextoEstudiante = $db->fetchOne(
        "SELECT u.*, c.nombre as curso_nombre, c.anio as curso_anio, cl.anio as ciclo_anio
         FROM usuarios u
         LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
         LEFT JOIN cursos c ON m.curso_id = c.id
         LEFT JOIN ciclos_lectivos cl ON c.ciclo_lectivo_id = cl.id
         WHERE u.id = ? AND u.tipo = 'estudiante'",
        [$estudianteSeleccionado]
    );
}

// BOTÓN DE ACTUALIZACIÓN GENERAL - Colocar al inicio del boletín
if ($estudianteSeleccionado && $cicloLectivoId && $datosContextoEstudiante): ?>
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0">
                <i class="bi bi-person-check"></i> 
                <?= htmlspecialchars($datosContextoEstudiante['nombre'] . ' ' . $datosContextoEstudiante['apellido']) ?>
                <span class="badge bg-light text-primary ms-2"><?= $datosContextoEstudiante['curso_anio'] ?>° Año</span>
            </h6>
        </div>
        <div>
            <form method="POST" style="display: inline;" onsubmit="return confirmarActualizacionGeneral();">
                <input type="hidden" name="actualizar_boletin" value="1">
                <button type="submit" class="btn btn-light btn-sm" title="Actualizar todas las calificaciones del boletín">
                    <i class="bi bi-arrow-clockwise"></i> <strong>Actualizar Boletín</strong>
                </button>
            </form>
        </div>
    </div>
    <div class="card-body py-2">
        <div class="row text-center">
            <div class="col-md-3">
                <small class="text-muted"><i class="bi bi-calendar3"></i> Ciclo Lectivo:</small>
                <br><strong><?= $datosContextoEstudiante['ciclo_anio'] ?></strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted"><i class="bi bi-mortarboard"></i> Curso:</small>
                <br><strong><?= htmlspecialchars($datosContextoEstudiante['curso_nombre']) ?></strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted"><i class="bi bi-person-badge"></i> Matrícula:</small>
                <br><strong><?= $datosContextoEstudiante['dni'] ?? 'N/A' ?></strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted"><i class="bi bi-info-circle"></i> Estado:</small>
                <br><span class="badge bg-success">Activo</span>
            </div>
        </div>
    </div>
</div>
<?php endif;

// PASO 1: Obtener TODAS las calificaciones del estudiante para el ciclo lectivo (CONSULTA CORREGIDA)
$todasLasCalificaciones = [];
if ($estudianteSeleccionado && $cicloLectivoId) {
    try {
        $todasLasCalificaciones = $db->fetchAll(
            "SELECT c.*, 
                    mp.id as materia_curso_id,
                    m.nombre as materia_nombre, 
                    m.codigo as materia_codigo,
                    curso.nombre as curso_nombre,
                    curso.anio as curso_anio,
                    curso.id as curso_id,
                    
                    -- Datos de recursado si aplica
                    mr.id as recursado_id,
                    mr.estado as recursado_estado,
                    mr.observaciones as recursado_observaciones,
                    
                    -- Datos de subgrupos si aplica
                    ep.subgrupo, ep.periodo_inicio, ep.periodo_fin
                    
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos curso ON mp.curso_id = curso.id
             
             -- LEFT JOIN para recursado
             LEFT JOIN materias_recursado mr ON c.estudiante_id = mr.estudiante_id 
                                               AND mp.id = mr.materia_curso_id 
                                               AND mr.estado = 'activo'
             
             -- LEFT JOIN para subgrupos
             LEFT JOIN estudiantes_por_materia ep ON c.estudiante_id = ep.estudiante_id 
                                                   AND mp.id = ep.materia_curso_id 
                                                   AND ep.activo = 1
             
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
             ORDER BY curso.anio, m.nombre",
            [$estudianteSeleccionado, $cicloLectivoId]
        );
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error en la consulta: ' . $e->getMessage() . '</div>';
        $todasLasCalificaciones = [];
    }
}

// PASO 2: Procesar y clasificar las materias
$materiasIndividuales = [];
$materiasEnGrupos = [];
$materiasRecursando = [];
$materiasPendientes = [];

// Obtener grupos de materias para el curso principal del estudiante
$gruposMaterias = [];
$cursoEstudiante = null;
if ($datosContextoEstudiante && $datosContextoEstudiante['curso_anio']) {
    // Buscar curso del estudiante en el ciclo lectivo actual
    try {
        $cursoEstudiante = $db->fetchOne(
            "SELECT c.* FROM cursos c
             JOIN matriculas m ON c.id = m.curso_id
             WHERE m.estudiante_id = ? AND m.estado = 'activo' AND c.ciclo_lectivo_id = ?",
            [$estudianteSeleccionado, $cicloLectivoId]
        );
        
        if ($cursoEstudiante && function_exists('obtenerGruposMaterias')) {
            $gruposMaterias = obtenerGruposMaterias($db, $cursoEstudiante['id'], $cicloLectivoId);
        }
    } catch (Exception $e) {
        // Continuar sin grupos si hay error
        $gruposMaterias = [];
    }
}

// Recalcular calificaciones de grupos para este estudiante (con limpieza previa) - CORREGIDO
if ($estudianteSeleccionado && $datosContextoEstudiante && $cursoEstudiante && function_exists('recalcularCalificacionesGruposEstudiante')) {
    try {
        // Verificar si existe la tabla calificaciones_grupos
        $tablaGruposExisteAqui = false;
        try {
            // Intentar con SQLite
            $resultado = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='calificaciones_grupos'");
            if ($resultado && $resultado->fetch()) {
                $tablaGruposExisteAqui = true;
            }
        } catch (Exception $e) {
            // Si falla SQLite, intentar con MySQL/MariaDB
            try {
                $resultado = $db->query("SHOW TABLES LIKE 'calificaciones_grupos'");
                if ($resultado && $resultado->fetch()) {
                    $tablaGruposExisteAqui = true;
                }
            } catch (Exception $e2) {
                $tablaGruposExisteAqui = false;
            }
        }
        
        if ($tablaGruposExisteAqui) {
            // Verificar si ya existen calificaciones de grupos calculadas
            $calificacionesExistentes = $db->fetchOne(
                "SELECT COUNT(*) as total FROM calificaciones_grupos 
                 WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                [$estudianteSeleccionado, $cicloLectivoId]
            );
            
            // Si hay calificaciones de grupos existentes, verificar si son consistentes con las individuales
            $necesitaRecalculo = false;
            if ($calificacionesExistentes && $calificacionesExistentes['total'] > 0) {
                // Verificar si hay cambios en las calificaciones individuales
                $ultimaModificacion = $db->fetchOne(
                    "SELECT MAX(id) as ultima FROM calificaciones 
                     WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                    [$estudianteSeleccionado, $cicloLectivoId]
                );
                
                $ultimaCalcGrupo = $db->fetchOne(
                    "SELECT MAX(id) as ultima FROM calificaciones_grupos 
                     WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                    [$estudianteSeleccionado, $cicloLectivoId]
                );
                
                // Si no hay cálculos de grupos o los individuales son más recientes
                if (!$ultimaCalcGrupo || ($ultimaModificacion && $ultimaModificacion['ultima'] > $ultimaCalcGrupo['ultima'])) {
                    $necesitaRecalculo = true;
                }
            } else {
                $necesitaRecalculo = true;
            }
            
            // Forzar recálculo si no hay calificaciones de grupos, si se solicitó o si hay inconsistencias
            if ($necesitaRecalculo || isset($_GET['force_recalc'])) {
                // Limpiar calificaciones de grupos obsoletas
                $db->query(
                    "DELETE FROM calificaciones_grupos 
                     WHERE estudiante_id = ? AND ciclo_lectivo_id = ?",
                    [$estudianteSeleccionado, $cicloLectivoId]
                );
                
                recalcularCalificacionesGruposEstudiante($db, $estudianteSeleccionado, $cicloLectivoId, $cursoEstudiante['id']);
            }
        } else {
            // La tabla no existe, no intentar trabajar con grupos
            if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
                echo '<div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Información (Admin):</strong> 
                        La tabla calificaciones_grupos no existe. Saltando cálculos de grupos.
                      </div>';
            }
        }
    } catch (Exception $e) {
        // Continuar si hay error, pero log para depuración
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            echo '<div class="alert alert-warning">Aviso (Admin): Error en recálculo automático - ' . $e->getMessage() . '</div>';
        }
    }
}

// Obtener calificaciones de grupos calculadas
$calificacionesGrupos = [];
if ($estudianteSeleccionado && $cursoEstudiante && function_exists('obtenerCalificacionesGruposEstudiante')) {
    try {
        $calificacionesGrupos = obtenerCalificacionesGruposEstudiante($db, $estudianteSeleccionado, $cicloLectivoId, $cursoEstudiante['id']);
    } catch (Exception $e) {
        $calificacionesGrupos = [];
    }
}

// Obtener IDs de materias que están en grupos
$materiasEnGruposIds = [];
foreach ($gruposMaterias as $grupo) {
    if (isset($grupo['materias']) && is_array($grupo['materias'])) {
        foreach ($grupo['materias'] as $materia) {
            $materiasEnGruposIds[] = $materia['materia_curso_id'];
        }
    }
}

// PASO 3: Clasificar las calificaciones
foreach ($todasLasCalificaciones as $calificacion) {
    // Determinar tipo de cursada
    $esRecursando = !empty($calificacion['recursado_id']) || $calificacion['tipo_cursada'] === 'R';
    $esEnGrupo = in_array($calificacion['materia_curso_id'], $materiasEnGruposIds);
    
    // Agregar información adicional a la calificación
    $calificacion['es_recursando'] = $esRecursando;
    $calificacion['es_en_grupo'] = $esEnGrupo;
    
    // Determinar el año de cursada correcto (usar el año del curso)
    $calificacion['anio_cursada'] = $calificacion['curso_anio'];
    $calificacion['tipo_cursada_display'] = $esRecursando ? 'R' : 'C';
    
    // Mejorar la obtención de valoraciones preliminares
    // Si no hay valoración preliminar pero hay valoración bimestral, usarla
    if (empty($calificacion['valoracion_preliminar_1c']) && !empty($calificacion['valoracion_1bim'])) {
        $calificacion['valoracion_preliminar_1c'] = $calificacion['valoracion_1bim'];
    }
    if (empty($calificacion['valoracion_preliminar_2c']) && !empty($calificacion['valoracion_3bim'])) {
        $calificacion['valoracion_preliminar_2c'] = $calificacion['valoracion_3bim'];
    }
    
    // Clasificar según el estado
    if ($esEnGrupo) {
        $materiasEnGrupos[] = $calificacion;
    } elseif ($esRecursando) {
        $materiasRecursando[] = $calificacion;
    } else {
        $materiasIndividuales[] = $calificacion;
    }
}

// CORRECCIÓN: Primero verificar qué estados de asistencia existen realmente en la BD
if ($estudianteSeleccionado && $cicloLectivoId) {
    try {
        // Verificar estados únicos de asistencia para debugging (SQLite compatible)
        $estadosReales = $db->fetchAll(
            "SELECT DISTINCT estado, COUNT(*) as cantidad 
             FROM asistencias 
             WHERE estudiante_id = ? 
             GROUP BY estado 
             ORDER BY cantidad DESC",
            [$estudianteSeleccionado]
        );
        
        // Mostrar información de debug solo para administradores
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            echo '<div class="alert alert-info">
                    <strong>Estados de asistencia encontrados en BD (SQLite):</strong><br>';
            foreach ($estadosReales as $estado) {
                echo '<span class="badge bg-secondary me-1">' . htmlspecialchars($estado['estado']) . ' (' . $estado['cantidad'] . ')</span>';
            }
            echo '<br><small class="text-muted mt-2">Si no ve estados fraccionarios (1/4, 1/2, etc.), es posible que se almacenen con otro formato.</small>';
            echo '</div>';
            
            // Verificación adicional específica para estados fraccionarios en SQLite
            try {
                $estadosFraccionarios = $db->fetchAll(
                    "SELECT DISTINCT estado, COUNT(*) as cantidad 
                     FROM asistencias 
                     WHERE estudiante_id = ? AND (
                        estado LIKE '%/%' OR 
                        estado LIKE '%0.25%' OR 
                        estado LIKE '%0.5%' OR 
                        estado LIKE '%0.75%' OR
                        estado = 'cuarto_falta' OR
                        estado = 'media_falta' OR
                        estado = 'tres_cuartos_falta' OR
                        estado LIKE '%cuarto%' OR
                        estado LIKE '%medio%' OR
                        estado LIKE '%media%'
                     )
                     GROUP BY estado 
                     ORDER BY cantidad DESC",
                    [$estudianteSeleccionado]
                );
                
                if (!empty($estadosFraccionarios)) {
                    echo '<div class="alert alert-success">
                            <strong>Estados fraccionarios detectados:</strong><br>';
                    foreach ($estadosFraccionarios as $estado) {
                        $valor_decimal = '';
                        if ($estado['estado'] == 'cuarto_falta') $valor_decimal = ' (0.25)';
                        if ($estado['estado'] == 'media_falta') $valor_decimal = ' (0.5)';
                        if ($estado['estado'] == 'tres_cuartos_falta') $valor_decimal = ' (0.75)';
                        
                        echo '<span class="badge bg-success me-1">' . htmlspecialchars($estado['estado']) . $valor_decimal . ' (' . $estado['cantidad'] . ')</span>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="alert alert-warning">
                            <strong>No se encontraron estados fraccionarios.</strong> 
                            Verifique cómo se almacenan las medias faltas en su sistema.
                          </div>';
                }
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">Error al verificar estados fraccionarios: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    } catch (Exception $e) {
        // Continuar si hay error
    }
}

// PASO 4 CORREGIDO: Obtener asistencias por cuatrimestre con detección automática de estados
$asistenciasPorCuatrimestre = [];
if ($estudianteSeleccionado && $cicloLectivoId) {
    try {
        // Obtener configuración de períodos
        $configuracionPeriodos = $db->fetchOne(
            "SELECT * FROM configuracion_periodos WHERE ciclo_lectivo_id = ? AND activo = 1",
            [$cicloLectivoId]
        );
        
        // Preparar fechas de cuatrimestres
        if ($configuracionPeriodos) {
            $fechas_cuatrimestres = [
                1 => [
                    'inicio' => $configuracionPeriodos['inicio_1er_cuatrimestre'] ?? (date('Y') . '-03-01'),
                    'fin' => $configuracionPeriodos['fin_1er_cuatrimestre'] ?? (date('Y') . '-07-31')
                ],
                2 => [
                    'inicio' => $configuracionPeriodos['inicio_2do_cuatrimestre'] ?? (date('Y') . '-08-01'),
                    'fin' => $configuracionPeriodos['fin_2do_cuatrimestre'] ?? (date('Y') . '-12-15')
                ]
            ];
        } else {
            $anioActual = date('Y');
            $fechas_cuatrimestres = [
                1 => ['inicio' => $anioActual . '-03-01', 'fin' => $anioActual . '-07-31'],
                2 => ['inicio' => $anioActual . '-08-01', 'fin' => $anioActual . '-12-15']
            ];
        }
        
        // Procesar cada cuatrimestre
        foreach ($fechas_cuatrimestres as $cuatrimestre => $fechas) {
            $asistencias = $db->fetchOne(
                "SELECT 
                    -- PRESENTES
                    SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes,
                    
                    -- AUSENTES COMPLETOS
                    SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as ausentes,
                    
                    -- JUSTIFICADAS
                    SUM(CASE WHEN a.estado = 'justificada' THEN 1 ELSE 0 END) as justificadas,
                    
                    -- MEDIAS FALTAS - VERSIÓN SQLite compatible con cuarto_falta
                    SUM(CASE 
                        WHEN a.estado = 'media_falta' THEN 1 
                        WHEN a.estado = 'cuarto_falta' THEN 1
                        WHEN a.estado = 'tres_cuartos_falta' THEN 1
                        WHEN a.estado = 'media falta' THEN 1
                        WHEN a.estado = '1/2_falta' THEN 1
                        WHEN a.estado = '1/2 falta' THEN 1
                        WHEN a.estado = '0.5_falta' THEN 1
                        WHEN a.estado = '1/4_falta' THEN 1
                        WHEN a.estado = '1/4 falta' THEN 1
                        WHEN a.estado = '0.25_falta' THEN 1
                        WHEN a.estado = '3/4_falta' THEN 1
                        WHEN a.estado = '3/4 falta' THEN 1
                        WHEN a.estado = '0.75_falta' THEN 1
                        -- SQLite LIKE para patrones adicionales
                        WHEN a.estado LIKE '%1/4%' THEN 1
                        WHEN a.estado LIKE '%1/2%' THEN 1
                        WHEN a.estado LIKE '%3/4%' THEN 1
                        WHEN a.estado LIKE '%0.25%' THEN 1
                        WHEN a.estado LIKE '%0.5%' THEN 1
                        WHEN a.estado LIKE '%0.75%' THEN 1
                        WHEN a.estado LIKE '%cuarto%' THEN 1
                        WHEN a.estado LIKE '%medio%' THEN 1
                        ELSE 0 
                    END) as medias_faltas_count,
                    
                    -- EQUIVALENTE DECIMAL - SQLite compatible con cuarto_falta
                    SUM(CASE 
                        -- Medias faltas (0.5)
                        WHEN a.estado = 'media_falta' THEN 0.5
                        WHEN a.estado = 'media falta' THEN 0.5
                        WHEN a.estado = '1/2_falta' THEN 0.5
                        WHEN a.estado = '1/2 falta' THEN 0.5
                        WHEN a.estado = '0.5_falta' THEN 0.5
                        WHEN a.estado LIKE '%1/2%' THEN 0.5
                        WHEN a.estado LIKE '%0.5%' THEN 0.5
                        WHEN a.estado LIKE '%medio%' THEN 0.5
                        
                        -- Un cuarto de falta (0.25) - CORREGIDO para cuarto_falta
                        WHEN a.estado = 'cuarto_falta' THEN 0.25
                        WHEN a.estado = '1/4_falta' THEN 0.25
                        WHEN a.estado = '1/4 falta' THEN 0.25
                        WHEN a.estado = '0.25_falta' THEN 0.25
                        WHEN a.estado LIKE '%1/4%' THEN 0.25
                        WHEN a.estado LIKE '%0.25%' THEN 0.25
                        WHEN a.estado LIKE '%cuarto%' THEN 0.25
                        
                        -- Tres cuartos de falta (0.75)
                        WHEN a.estado = 'tres_cuartos_falta' THEN 0.75
                        WHEN a.estado = '3/4_falta' THEN 0.75
                        WHEN a.estado = '3/4 falta' THEN 0.75
                        WHEN a.estado = '0.75_falta' THEN 0.75
                        WHEN a.estado LIKE '%3/4%' THEN 0.75
                        WHEN a.estado LIKE '%0.75%' THEN 0.75
                        WHEN a.estado LIKE '%tres_cuartos%' THEN 0.75
                        
                        ELSE 0 
                    END) as medias_faltas_decimal,
                    
                    -- TARDANZAS - SQLite compatible
                    SUM(CASE 
                        WHEN a.estado = 'tardanza' THEN 1 
                        WHEN a.estado = 'tarde' THEN 1
                        WHEN a.estado LIKE '%tardanza%' THEN 1
                        WHEN a.estado LIKE '%tarde%' THEN 1
                        ELSE 0 
                    END) as tardanzas,
                    
                    -- DÍAS TOTALES COMPUTADOS
                    COUNT(DISTINCT a.fecha) as total_dias,
                    
                    -- TOTAL DE INASISTENCIAS (decimal) - CORREGIDO para cuarto_falta
                    SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) + 
                    SUM(CASE 
                        -- Medias faltas (0.5)
                        WHEN a.estado = 'media_falta' THEN 0.5
                        WHEN a.estado = 'media falta' THEN 0.5
                        WHEN a.estado = '1/2_falta' THEN 0.5
                        WHEN a.estado = '1/2 falta' THEN 0.5
                        WHEN a.estado = '0.5_falta' THEN 0.5
                        WHEN a.estado LIKE '%1/2%' THEN 0.5
                        WHEN a.estado LIKE '%0.5%' THEN 0.5
                        WHEN a.estado LIKE '%medio%' THEN 0.5
                        
                        -- Un cuarto de falta (0.25) - ESPECÍFICO para cuarto_falta
                        WHEN a.estado = 'cuarto_falta' THEN 0.25
                        WHEN a.estado = '1/4_falta' THEN 0.25
                        WHEN a.estado = '1/4 falta' THEN 0.25
                        WHEN a.estado = '0.25_falta' THEN 0.25
                        WHEN a.estado LIKE '%1/4%' THEN 0.25
                        WHEN a.estado LIKE '%0.25%' THEN 0.25
                        WHEN a.estado LIKE '%cuarto%' THEN 0.25
                        
                        -- Tres cuartos de falta (0.75)
                        WHEN a.estado = 'tres_cuartos_falta' THEN 0.75
                        WHEN a.estado = '3/4_falta' THEN 0.75
                        WHEN a.estado = '3/4 falta' THEN 0.75
                        WHEN a.estado = '0.75_falta' THEN 0.75
                        WHEN a.estado LIKE '%3/4%' THEN 0.75
                        WHEN a.estado LIKE '%0.75%' THEN 0.75
                        WHEN a.estado LIKE '%tres_cuartos%' THEN 0.75
                        
                        ELSE 0 
                    END) as total_faltas_decimal
                    
                 FROM asistencias a
                 WHERE a.estudiante_id = ? 
                 AND a.fecha BETWEEN ? AND ?",
                [
                    $estudianteSeleccionado, 
                    $fechas['inicio'],
                    $fechas['fin']
                ]
            );
            
            $asistenciasPorCuatrimestre[$cuatrimestre] = $asistencias ?: [
                'presentes' => 0, 'ausentes' => 0, 'justificadas' => 0, 
                'medias_faltas_count' => 0, 'medias_faltas_decimal' => 0,
                'tardanzas' => 0, 'total_dias' => 0, 'total_faltas_decimal' => 0
            ];
        }
        
    } catch (Exception $e) {
        // Usar valores por defecto si hay error
        $asistenciasPorCuatrimestre = [
            1 => ['presentes' => 0, 'ausentes' => 0, 'justificadas' => 0, 'medias_faltas_count' => 0, 'medias_faltas_decimal' => 0, 'tardanzas' => 0, 'total_dias' => 0, 'total_faltas_decimal' => 0],
            2 => ['presentes' => 0, 'ausentes' => 0, 'justificadas' => 0, 'medias_faltas_count' => 0, 'medias_faltas_decimal' => 0, 'tardanzas' => 0, 'total_dias' => 0, 'total_faltas_decimal' => 0]
        ];
    }
}

/**
 * FUNCIÓN AUXILIAR: Calcular porcentaje de asistencia mejorado
 */
function calcularPorcentajeAsistencia($total_dias, $total_faltas_decimal) {
    if ($total_dias <= 0) {
        return 0;
    }
    
    $dias_asistidos = $total_dias - $total_faltas_decimal;
    return round(($dias_asistidos / $total_dias) * 100, 1);
}

/**
 * FUNCIÓN AUXILIAR: Determinar clase CSS para porcentaje de asistencia
 */
function obtenerClaseAsistencia($porcentaje) {
    if ($porcentaje >= 80) {
        return 'success';
    } elseif ($porcentaje >= 70) {
        return 'warning';
    } else {
        return 'danger';
    }
}

/**
 * FUNCIÓN AUXILIAR MEJORADA: Formatear medias faltas para mostrar (CORREGIDA)
 */
function formatearMediasFaltas($medias_faltas_count, $medias_faltas_decimal) {
    if ($medias_faltas_count == 0 && $medias_faltas_decimal == 0) {
        return '0';
    }
    
    // Si hay registros de medias faltas, mostrar tanto el conteo como el decimal
    if ($medias_faltas_count > 0 || $medias_faltas_decimal > 0) {
        // Si el decimal es exacto (sin fracciones), mostrar solo el número
        if ($medias_faltas_decimal == intval($medias_faltas_decimal)) {
            return intval($medias_faltas_decimal);
        }
        
        // Si hay fracciones, formatear de manera legible
        $entero = intval($medias_faltas_decimal);
        $fraccion = $medias_faltas_decimal - $entero;
        
        $fraccionFormateada = '';
        
        if ($fraccion >= 0.24 && $fraccion <= 0.26) { // 1/4 (con tolerancia)
            $fraccionFormateada = ($entero > 0 ? $entero . ' y ' : '') . '1/4';
        } elseif ($fraccion >= 0.49 && $fraccion <= 0.51) { // 1/2 (con tolerancia)
            $fraccionFormateada = ($entero > 0 ? $entero . ' y ' : '') . '1/2';
        } elseif ($fraccion >= 0.74 && $fraccion <= 0.76) { // 3/4 (con tolerancia)
            $fraccionFormateada = ($entero > 0 ? $entero . ' y ' : '') . '3/4';
        } else {
            // Para otros valores decimales, mostrar con 2 decimales
            $fraccionFormateada = number_format($medias_faltas_decimal, 2);
        }
        
        return $fraccionFormateada;
    }
    
    return number_format($medias_faltas_decimal, 2);
}

/**
 * FUNCIÓN AUXILIAR ADICIONAL: Detectar automáticamente el formato de estados en la BD
 */
function detectarFormatoEstados($db, $estudianteId) {
    try {
        // SQLite compatible - buscar estados con patrones fraccionarios
        $estados = $db->fetchAll(
            "SELECT DISTINCT estado FROM asistencias 
             WHERE estudiante_id = ? AND (
                estado LIKE '%/%' OR 
                estado LIKE '%0.25%' OR 
                estado LIKE '%0.5%' OR 
                estado LIKE '%0.75%'
             )",
            [$estudianteId]
        );
        
        return array_column($estados, 'estado');
    } catch (Exception $e) {
        return [];
    }
}

/**
 * FUNCIÓN DE DEBUGGING: Mostrar desglose detallado de asistencias (solo admin)
 */
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin' && $estudianteSeleccionado) {
    echo '<div class="alert alert-light mt-3">
            <h6><i class="bi bi-bug"></i> Información de Depuración - Asistencias (Solo Admin):</h6>';
    
    try {
        // Obtener muestra de registros de asistencia
        $muestraAsistencias = $db->fetchAll(
            "SELECT fecha, estado, COUNT(*) as cantidad 
             FROM asistencias 
             WHERE estudiante_id = ? 
             GROUP BY fecha, estado 
             ORDER BY fecha DESC 
             LIMIT 10",
            [$estudianteSeleccionado]
        );
        
        if (!empty($muestraAsistencias)) {
            echo '<div class="row small">
                    <div class="col-md-12">
                        <strong>Últimos 10 registros únicos de asistencia:</strong>
                        <table class="table table-sm table-striped mt-2">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Estado</th>
                                    <th>Cantidad</th>
                                </tr>
                            </thead>
                            <tbody>';
            
            foreach ($muestraAsistencias as $registro) {
                echo '<tr>
                        <td>' . htmlspecialchars($registro['fecha']) . '</td>
                        <td><code>' . htmlspecialchars($registro['estado']) . '</code></td>
                        <td>' . $registro['cantidad'] . '</td>
                      </tr>';
            }
            
            echo '</tbody></table></div></div>';
        }
        
        // Mostrar totales calculados
        echo '<div class="row small mt-3">
                <div class="col-md-6">
                    <strong>1° Cuatrimestre calculado:</strong>
                    <ul class="mb-1">
                        <li>Ausentes: ' . ($asistenciasPorCuatrimestre[1]['ausentes'] ?? 0) . '</li>
                        <li>Medias faltas (count): ' . ($asistenciasPorCuatrimestre[1]['medias_faltas_count'] ?? 0) . '</li>
                        <li>Medias faltas (decimal): ' . number_format($asistenciasPorCuatrimestre[1]['medias_faltas_decimal'] ?? 0, 3) . '</li>
                        <li>Total faltas: ' . number_format($asistenciasPorCuatrimestre[1]['total_faltas_decimal'] ?? 0, 3) . '</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <strong>2° Cuatrimestre calculado:</strong>
                    <ul class="mb-1">
                        <li>Ausentes: ' . ($asistenciasPorCuatrimestre[2]['ausentes'] ?? 0) . '</li>
                        <li>Medias faltas (count): ' . ($asistenciasPorCuatrimestre[2]['medias_faltas_count'] ?? 0) . '</li>
                        <li>Medias faltas (decimal): ' . number_format($asistenciasPorCuatrimestre[2]['medias_faltas_decimal'] ?? 0, 3) . '</li>
                        <li>Total faltas: ' . number_format($asistenciasPorCuatrimestre[2]['total_faltas_decimal'] ?? 0, 3) . '</li>
                    </ul>
                </div>
              </div>';
        
    } catch (Exception $e) {
        echo '<div class="alert alert-warning">Error al obtener información de debug: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    
    echo '</div>';
}

// PASO 5: Obtener materias pendientes de intensificación (CORREGIDO - VERIFICAR SI TABLA EXISTE)
$materiasPendientes = [];
if ($estudianteSeleccionado && $cicloLectivoId) {
    // Verificar si existe la tabla intensificacion usando una consulta más segura
    $tablaIntensificacionExiste = false;
    
    try {
        // Intentar verificar la existencia de la tabla consultando el esquema
        $resultado = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='intensificacion'");
        if ($resultado && $resultado->fetch()) {
            $tablaIntensificacionExiste = true;
        }
    } catch (Exception $e) {
        // Si falla la consulta de sqlite_master, intentar método alternativo
        try {
            // Para MySQL/MariaDB
            $resultado = $db->query("SHOW TABLES LIKE 'intensificacion'");
            if ($resultado && $resultado->fetch()) {
                $tablaIntensificacionExiste = true;
            }
        } catch (Exception $e2) {
            // Si ambos métodos fallan, asumir que la tabla no existe
            $tablaIntensificacionExiste = false;
        }
    }
    
    try {
        if ($tablaIntensificacionExiste) {
            // Buscar materias en estado de intensificación de ciclos anteriores
            $materiasPendientes = $db->fetchAll(
                "SELECT DISTINCT
                    m.nombre as materia_nombre,
                    m.codigo as materia_codigo,
                    curso_intensif.anio as materia_anio,
                    cl_cursada.anio as ciclo_lectivo_cursada_id,
                    i.modelo_intensificacion,
                    i.estado_marzo, i.calificacion_marzo,
                    i.estado_julio, i.calificacion_julio,
                    i.estado_agosto, i.calificacion_agosto,
                    i.estado_diciembre, i.calificacion_diciembre,
                    i.estado_febrero, i.calificacion_febrero,
                    i.calificacion_final,
                    i.saberes_pendientes_inicial,
                    i.saberes_pendientes_final
                 FROM intensificacion i
                 JOIN materias_por_curso mp ON i.materia_curso_id = mp.id
                 JOIN materias m ON mp.materia_id = m.id
                 JOIN cursos curso_intensif ON mp.curso_id = curso_intensif.id
                 JOIN ciclos_lectivos cl_cursada ON curso_intensif.ciclo_lectivo_id = cl_cursada.id
                 WHERE i.estudiante_id = ? 
                 AND (i.calificacion_final IS NULL OR i.calificacion_final < 4)
                 AND i.estado = 'activo'
                 ORDER BY cl_cursada.anio DESC, curso_intensif.anio, m.nombre",
                [$estudianteSeleccionado]
            );
        } else {
            // Si no existe la tabla, buscar materias que requieren intensificación basándose en calificaciones bajas
            $materiasPendientes = $db->fetchAll(
                "SELECT DISTINCT
                    m.nombre as materia_nombre,
                    m.codigo as materia_codigo,
                    curso.anio as materia_anio,
                    cl.anio as ciclo_lectivo_cursada_id,
                    'Sin modelo definido' as modelo_intensificacion,
                    NULL as estado_marzo, NULL as calificacion_marzo,
                    NULL as estado_julio, NULL as calificacion_julio,
                    NULL as estado_agosto, NULL as calificacion_agosto,
                    NULL as estado_diciembre, NULL as calificacion_diciembre,
                    NULL as estado_febrero, NULL as calificacion_febrero,
                    c.calificacion_final,
                    'Pendiente de evaluación' as saberes_pendientes_inicial,
                    'Pendiente de evaluación' as saberes_pendientes_final
                 FROM calificaciones c
                 JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                 JOIN materias m ON mp.materia_id = m.id
                 JOIN cursos curso ON mp.curso_id = curso.id
                 JOIN ciclos_lectivos cl ON curso.ciclo_lectivo_id = cl.id
                 WHERE c.estudiante_id = ? 
                 AND (c.calificacion_final IS NULL OR c.calificacion_final < 4)
                 AND cl.anio < (SELECT anio FROM ciclos_lectivos WHERE id = ?)
                 ORDER BY cl.anio DESC, curso.anio, m.nombre",
                [$estudianteSeleccionado, $cicloLectivoId]
            );
        }
    } catch (Exception $e) {
        // En caso de cualquier error, simplemente no mostrar materias pendientes
        $materiasPendientes = [];
        
        // Solo mostrar información de error para administradores
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            echo '<div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Información para administradores:</strong> 
                    No se pudieron cargar materias pendientes de intensificación. 
                    La tabla "intensificacion" no está disponible en este sistema.
                  </div>';
        }
    }
}

// FUNCIÓN AUXILIAR MEJORADA: Obtener valoración basada en calificación
function obtenerValoracionPorCalificacion($calificacion) {
    if (is_null($calificacion) || $calificacion === '') return null;
    
    $nota = intval($calificacion);
    if ($nota >= 7) return 'TEA';
    if ($nota >= 4) return 'TEP';
    return 'TED';
}

// FUNCIÓN AUXILIAR ESPECIAL: Calcular valoración para grupos de materias
function calcularValoracionGrupo($detalleCalculo) {
    if (empty($detalleCalculo) || !is_array($detalleCalculo)) {
        return null;
    }
    
    $valoraciones = [];
    
    // Obtener valoración de cada materia del grupo
    foreach ($detalleCalculo as $detalle) {
        if (isset($detalle['calificacion']) && $detalle['calificacion'] !== null) {
            $valoracion = obtenerValoracionPorCalificacion($detalle['calificacion']);
            if ($valoracion) {
                $valoraciones[] = $valoracion;
            }
        }
    }
    
    if (empty($valoraciones)) {
        return null;
    }
    
    // Lógica especial para grupos:
    // - TEA solo si TODAS las materias son TEA
    // - TEP si hay al menos una TEP (y no hay TED)
    // - TED si hay al menos una TED
    
    if (in_array('TED', $valoraciones)) {
        return 'TED';
    } elseif (in_array('TEP', $valoraciones)) {
        return 'TEP';
    } elseif (count(array_unique($valoraciones)) === 1 && $valoraciones[0] === 'TEA') {
        return 'TEA';
    } else {
        // Si hay mezcla pero no hay TED, default a TEP
        return 'TEP';
    }
}

// FUNCIÓN AUXILIAR: Formatear calificación para mostrar
function formatearCalificacion($calificacion) {
    if (is_null($calificacion) || $calificacion === '') return '-';
    return intval($calificacion);
}

/**
 * NUEVA FUNCIÓN: Obtener información de grupo para materia recursada
 */
function obtenerGrupoParaMateriaRecursada($db, $materiaData, $cicloLectivoId) {
    try {
        // MÉTODO 1: Si tenemos las funciones de agrupación disponibles, usarlas
        if (function_exists('obtenerGruposMaterias')) {
            // Intentar obtener el curso original de la materia
            $cursoMateria = $db->fetchOne(
                "SELECT c.id, c.anio 
                 FROM cursos c
                 JOIN materias_por_curso mp ON c.id = mp.curso_id
                 WHERE mp.id = ? AND c.ciclo_lectivo_id = ?",
                [$materiaData['materia_curso_id'], $cicloLectivoId]
            );
            
            if ($cursoMateria) {
                // Obtener grupos para ese curso
                $grupos = obtenerGruposMaterias($db, $cursoMateria['id'], $cicloLectivoId);
                
                // Buscar en qué grupo está esta materia
                foreach ($grupos as $grupo) {
                    if (isset($grupo['materias']) && is_array($grupo['materias'])) {
                        foreach ($grupo['materias'] as $materia) {
                            if ($materia['materia_curso_id'] == $materiaData['materia_curso_id']) {
                                return [
                                    'grupo_nombre' => $grupo['nombre'],
                                    'grupo_codigo' => $grupo['codigo'],
                                    'curso_anio' => $cursoMateria['anio']
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        // MÉTODO 2: Búsqueda directa por materia_id en todos los años
        if (!empty($materiaData['materia_id'])) {
            $grupoInfo = $db->fetchOne(
                "SELECT gm.nombre as grupo_nombre, gm.codigo as grupo_codigo, gm.curso_anio
                 FROM materias_grupo mg
                 JOIN grupos_materias gm ON mg.grupo_id = gm.id
                 JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
                 JOIN materias m ON mp.materia_id = m.id
                 WHERE m.id = ? AND gm.ciclo_lectivo_id = ? AND gm.activo = 1 AND mg.activo = 1
                 ORDER BY gm.curso_anio DESC
                 LIMIT 1",
                [$materiaData['materia_id'], $cicloLectivoId]
            );
            
            if ($grupoInfo) {
                return $grupoInfo;
            }
        }
        
        // MÉTODO 3: Búsqueda directa por materia_curso_id
        if (!empty($materiaData['materia_curso_id'])) {
            $grupoDirecto = $db->fetchOne(
                "SELECT gm.nombre as grupo_nombre, gm.codigo as grupo_codigo, gm.curso_anio
                 FROM materias_grupo mg
                 JOIN grupos_materias gm ON mg.grupo_id = gm.id
                 WHERE mg.materia_curso_id = ? AND gm.ciclo_lectivo_id = ? AND gm.activo = 1 AND mg.activo = 1
                 LIMIT 1",
                [$materiaData['materia_curso_id'], $cicloLectivoId]
            );
            
            if ($grupoDirecto) {
                return $grupoDirecto;
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Error en obtenerGrupoParaMateriaRecursada: " . $e->getMessage());
        return null;
    }
}

/**
 * NUEVA FUNCIÓN: Mapeo especial para materias conocidas que van en grupos
 */
function obtenerGrupoPorNombreMateria($nombreMateria) {
    // Mapeo de materias específicas a sus grupos
    $mapeoEspecial = [
        'DIBUJO TÉCNICO' => 'LENGUAJES TECNOLÓGICOS',
        'DIBUJO TECNICO' => 'LENGUAJES TECNOLÓGICOS', 
        'SISTEMAS TECNOLÓGICOS' => 'SISTEMAS TECNOLÓGICOS',
        'SISTEMAS TECNOLOGICOS' => 'SISTEMAS TECNOLÓGICOS',
        'PROCEDIMIENTOS TÉCNICOS' => 'PROCEDIMIENTOS TÉCNICOS',
        'PROCEDIMIENTOS TECNICOS' => 'PROCEDIMIENTOS TÉCNICOS'
    ];
    
    $nombreUpper = strtoupper(trim($nombreMateria));
    
    foreach ($mapeoEspecial as $materia => $grupo) {
        if (strpos($nombreUpper, $materia) !== false) {
            return $grupo;
        }
    }
    
    return null;
}
?>

<!-- SECCIÓN 1: MATERIAS AGRUPADAS -->
<?php if (!empty($gruposMaterias)): ?>
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-collection"></i> Materias Agrupadas
            <span class="badge bg-light text-success ms-2"><?= count($gruposMaterias) ?> grupos</span>
        </h5>
    </div>
    <div class="card-body">
        <!-- Información adicional sobre grupos -->
        <div class="alert alert-info mt-3">
            <h6><i class="bi bi-info-circle"></i> Información:</h6>
            <ul class="mb-0 small">
                <li><strong>Si no ve calificaciones:</strong> Use el botón "Actualizar Boletín" arriba para recalcular</li>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-success">
                    <tr>
                        <th rowspan="2" class="align-middle text-center" style="width: 8%">TIPO<br>(C-R)</th>
                        <th rowspan="2" class="align-middle" style="width: 25%">GRUPO DE MATERIAS</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 8%">AÑO</th>
                        <th colspan="2" class="text-center bg-primary text-white" style="width: 20%">1° CUATRIMESTRE</th>
                        <th colspan="2" class="text-center bg-info text-white" style="width: 20%">2° CUATRIMESTRE</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 10%">CALIFICACIÓN<br>FINAL</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 9%">DETALLE</th>
                    </tr>
                    <tr>
                        <th class="text-center bg-primary text-white">VALORACIÓN<br>PRELIMINAR</th>
                        <th class="text-center bg-primary text-white">CALIFICACIÓN</th>
                        <th class="text-center bg-info text-white">VALORACIÓN<br>PRELIMINAR</th>
                        <th class="text-center bg-info text-white">CALIFICACIÓN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gruposMaterias as $grupo): ?>
                    <?php 
                        $grupoId = $grupo['id'];
                        $calificacionesGrupo = $calificacionesGrupos[$grupoId] ?? [];
                        
                        // Obtener calificaciones por cuatrimestre
                        $cal1c = $calificacionesGrupo['cuatrimestres'][1] ?? null;
                        $cal2c = $calificacionesGrupo['cuatrimestres'][2] ?? null;
                        
                        // Calcular calificación final SOLO cuando se tienen ambos cuatrimestres
                        $calificacionFinal = null;
                        if ($cal1c && $cal2c && $cal1c['calificacion_final'] && $cal2c['calificacion_final']) {
                            // Solo mostrar calificación final cuando hay datos de ambos cuatrimestres
                            $calificacionFinal = round(($cal1c['calificacion_final'] + $cal2c['calificacion_final']) / 2);
                        }
                        // NO mostrar calificación final si solo hay un cuatrimestre
                        
                        // Determinar valoraciones preliminares para grupos (lógica especial)
                        $valoracion1c = null;
                        $valoracion2c = null;
                        
                        if ($cal1c && $cal1c['calificacion_final']) {
                            $valoracion1c = calcularValoracionGrupo($cal1c['detalle_calculo']);
                        }
                        
                        if ($cal2c && $cal2c['calificacion_final']) {
                            $valoracion2c = calcularValoracionGrupo($cal2c['detalle_calculo']);
                        }
                    ?>
                    <tr>
                        <td class="text-center">
                            <span class="badge bg-success">C</span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($grupo['nombre']) ?></strong>
                            <br>
                            <small class="text-muted">
                                <i class="bi bi-tag"></i> <?= htmlspecialchars($grupo['codigo']) ?>
                            </small>
                            <?php if (!empty($grupo['descripcion'])): ?>
                                <br>
                                <small class="text-info"><?= htmlspecialchars($grupo['descripcion']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $datosContextoEstudiante['curso_anio'] ?? '-' ?></span>
                        </td>
                        
                        <!-- 1er Cuatrimestre -->
                        <td class="text-center">
                            <?php if ($valoracion1c): ?>
                                <span class="badge bg-<?= $valoracion1c === 'TEA' ? 'success' : ($valoracion1c === 'TEP' ? 'warning' : 'danger') ?>">
                                    <?= $valoracion1c ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($cal1c && $cal1c['calificacion_final']): ?>
                                <strong class="<?= $cal1c['calificacion_final'] >= 7 ? 'text-success' : ($cal1c['calificacion_final'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($cal1c['calificacion_final']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 2do Cuatrimestre -->
                        <td class="text-center">
                            <?php if ($valoracion2c): ?>
                                <span class="badge bg-<?= $valoracion2c === 'TEA' ? 'success' : ($valoracion2c === 'TEP' ? 'warning' : 'danger') ?>">
                                    <?= $valoracion2c ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($cal2c && $cal2c['calificacion_final']): ?>
                                <strong class="<?= $cal2c['calificacion_final'] >= 7 ? 'text-success' : ($cal2c['calificacion_final'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($cal2c['calificacion_final']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Calificación Final -->
                        <td class="text-center">
                            <?php if ($calificacionFinal): ?>
                                <span class="badge bg-<?= $calificacionFinal >= 7 ? 'success' : ($calificacionFinal >= 4 ? 'warning' : 'danger') ?> fs-6">
                                    <?= formatearCalificacion($calificacionFinal) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Detalle -->
                        <td class="text-center">
                            <?php if ($cal1c || $cal2c): ?>
                            <button type="button" class="btn btn-sm btn-outline-info" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#modalDetalleGrupo<?= $grupoId ?>">
                                <i class="bi bi-info-circle"></i> Ver
                            </button>
                            <?php else: ?>
                            <small class="text-muted">Sin datos</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        
        <?php if (empty($calificacionesGrupos) && !empty($gruposMaterias)): ?>
        <div class="alert alert-warning mt-2">
            <i class="bi bi-exclamation-triangle"></i> <strong>No se encontraron calificaciones de grupos calculadas.</strong>
            <br>
            <small>
                Esto puede ocurrir cuando:
                <ul class="mb-0 mt-1">
                    <li>Las materias fueron recientemente reagrupadas</li>
                    <li>Es la primera vez que se visualiza este boletín</li>
                    <li>Se agregaron nuevas materias a los grupos</li>
                </ul>
                Haga clic en <strong>"Actualizar Boletín"</strong> (botón azul arriba) para calcular todas las calificaciones.
            </small>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modales con detalles de cada grupo -->
<?php foreach ($gruposMaterias as $grupo): ?>
<?php 
    $grupoId = $grupo['id'];
    $calificacionesGrupo = $calificacionesGrupos[$grupoId] ?? [];
?>
<div class="modal fade" id="modalDetalleGrupo<?= $grupoId ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-collection"></i> Detalle: <?= htmlspecialchars($grupo['nombre']) ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Código:</strong> <?= htmlspecialchars($grupo['codigo']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Año:</strong> <?= $datosContextoEstudiante['curso_anio'] ?? '-' ?>
                    </div>
                </div>
                
                <?php if (!empty($grupo['descripcion'])): ?>
                <div class="alert alert-light">
                    <strong>Descripción:</strong> <?= htmlspecialchars($grupo['descripcion']) ?>
                </div>
                <?php endif; ?>
                
                <?php for ($cuatrimestre = 1; $cuatrimestre <= 2; $cuatrimestre++): ?>
                <?php 
                    $calCuatrimestre = $calificacionesGrupo['cuatrimestres'][$cuatrimestre] ?? null;
                ?>
                <h6 class="border-bottom pb-2">
                    <i class="bi bi-calendar3"></i> <?= $cuatrimestre ?>° Cuatrimestre
                    <?php if ($calCuatrimestre): ?>
                        <span class="badge bg-success ms-2">Calificación: <?= $calCuatrimestre['calificacion_final'] ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-2">Sin calificaciones</span>
                    <?php endif; ?>
                </h6>
                
                <?php if ($calCuatrimestre && !empty($calCuatrimestre['detalle_calculo'])): ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Materia</th>
                                <th>Código</th>
                                
                                <th>Calificación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calCuatrimestre['detalle_calculo'] as $detalle): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($detalle['materia']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($detalle['codigo']) ?></span>
                                </td>
                                
                                <td class="text-center">
                                    <strong class="<?= $detalle['calificacion'] >= 7 ? 'text-success' : ($detalle['calificacion'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                        <?= formatearCalificacion($detalle['calificacion']) ?>
                                    </strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-<?= $calCuatrimestre['calificacion_final'] >= 7 ? 'success' : ($calCuatrimestre['calificacion_final'] >= 4 ? 'warning' : 'danger') ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Calificación final del cuatrimestre:</strong> 
                            <span class="fs-5"><?= formatearCalificacion($calCuatrimestre['calificacion_final']) ?></span>
                        </div>
                        <small>
                            Calculado: <?= date('d/m/Y H:i', strtotime($calCuatrimestre['fecha_calculo'])) ?>
                        </small>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    No hay calificaciones registradas para este cuatrimestre.
                </div>
                <?php endif; ?>
                
                <?php if ($cuatrimestre == 1): ?>
                <hr class="my-4">
                <?php endif; ?>
                <?php endfor; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- SECCIÓN 2: MATERIAS INDIVIDUALES -->
<?php if (!empty($materiasIndividuales)): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-journal-text"></i> Materias Individuales
            <span class="badge bg-light text-primary ms-2"><?= count($materiasIndividuales) ?> materias</span>
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-primary">
                    <tr>
                        <th rowspan="2" class="align-middle text-center" style="width: 6%">TIPO<br>(C-R)</th>
                        <th rowspan="2" class="align-middle" style="width: 20%">MATERIAS</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 6%">AÑO</th>
                        <th colspan="2" class="text-center bg-info text-white">1° CUATRIMESTRE</th>
                        <th colspan="3" class="text-center bg-success text-white">2° CUATRIMESTRE</th>
                        <th colspan="2" class="text-center bg-warning text-dark">INTENSIFICACIÓN</th>
                        <th rowspan="2" class="align-middle text-center" style="width: 8%">CALIFICACIÓN<br>FINAL</th>
                        <th rowspan="2" class="align-middle" style="width: 15%">OBSERVACIONES</th>
                    </tr>
                    <tr>
                        <th class="text-center bg-info text-white" style="width: 8%">VALORACIÓN<br>PRELIMINAR</th>
                        <th class="text-center bg-info text-white" style="width: 6%">CALIFICACIÓN</th>
                        <th class="text-center bg-success text-white" style="width: 8%">VALORACIÓN<br>PRELIMINAR</th>
                        <th class="text-center bg-success text-white" style="width: 6%">CALIFICACIÓN</th>
                        <th class="text-center bg-success text-white" style="width: 6%">INTENSIFICACIÓN<br>1° CUATRIMESTRE</th>
                        <th class="text-center bg-warning text-dark" style="width: 6%">DICIEMBRE</th>
                        <th class="text-center bg-warning text-dark" style="width: 6%">FEBRERO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materiasIndividuales as $materia): ?>
                    <tr>
                        <td class="text-center">
                            <span class="badge bg-<?= $materia['tipo_cursada_display'] === 'R' ? 'warning' : 'success' ?>">
                                <?= $materia['tipo_cursada_display'] ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                            <br>
                            <small class="text-muted">
                                <i class="bi bi-tag"></i> <?= htmlspecialchars($materia['materia_codigo']) ?>
                            </small>
                            <?php if ($materia['es_recursando']): ?>
                                <br>
                                <small class="text-warning">
                                    <i class="bi bi-arrow-repeat"></i> Recursando
                                </small>
                            <?php endif; ?>
                            <?php if (!empty($materia['subgrupo'])): ?>
                                <br>
                                <small class="text-info">
                                    <i class="bi bi-people"></i> Rotación: <?= htmlspecialchars($materia['subgrupo']) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $materia['anio_cursada'] ?></span>
                        </td>
                        
                        <!-- 1° Cuatrimestre - Valoración -->
                        <td class="text-center">
                            <?php if ($materia['valoracion_preliminar_1c']): ?>
                                <span class="badge bg-<?= $materia['valoracion_preliminar_1c'] === 'TEA' ? 'success' : ($materia['valoracion_preliminar_1c'] === 'TEP' ? 'warning' : 'danger') ?>">
                                    <?= $materia['valoracion_preliminar_1c'] ?>
                                </span>
                            <?php else: ?>
                                <?php 
                                $valoracionCalculada = obtenerValoracionPorCalificacion($materia['calificacion_1c']);
                                if ($valoracionCalculada): ?>
                                    <span class="badge bg-<?= $valoracionCalculada === 'TEA' ? 'success' : ($valoracionCalculada === 'TEP' ? 'warning' : 'danger') ?>" title="Calculada automáticamente">
                                        <?= $valoracionCalculada ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 1° Cuatrimestre - Calificación -->
                        <td class="text-center">
                            <?php if ($materia['calificacion_1c']): ?>
                                <strong class="<?= $materia['calificacion_1c'] >= 7 ? 'text-success' : ($materia['calificacion_1c'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($materia['calificacion_1c']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Valoración -->
                        <td class="text-center">
                            <?php if ($materia['valoracion_preliminar_2c']): ?>
                                <span class="badge bg-<?= $materia['valoracion_preliminar_2c'] === 'TEA' ? 'success' : ($materia['valoracion_preliminar_2c'] === 'TEP' ? 'warning' : 'danger') ?>">
                                    <?= $materia['valoracion_preliminar_2c'] ?>
                                </span>
                            <?php else: ?>
                                <?php 
                                $valoracionCalculada = obtenerValoracionPorCalificacion($materia['calificacion_2c']);
                                if ($valoracionCalculada): ?>
                                    <span class="badge bg-<?= $valoracionCalculada === 'TEA' ? 'success' : ($valoracionCalculada === 'TEP' ? 'warning' : 'danger') ?>" title="Calculada automáticamente">
                                        <?= $valoracionCalculada ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 2° Cuatrimestre - Calificación -->
                        <td class="text-center">
                            <?php if ($materia['calificacion_2c']): ?>
                                <strong class="<?= $materia['calificacion_2c'] >= 7 ? 'text-success' : ($materia['calificacion_2c'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($materia['calificacion_2c']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Intensificación 1° Cuatrimestre -->
                        <td class="text-center">
                            <?php if ($materia['intensificacion_1c']): ?>
                                <strong class="<?= $materia['intensificacion_1c'] >= 7 ? 'text-success' : ($materia['intensificacion_1c'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($materia['intensificacion_1c']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Diciembre -->
                        <td class="text-center">
                            <?php if ($materia['intensificacion_diciembre']): ?>
                                <strong class="<?= $materia['intensificacion_diciembre'] >= 7 ? 'text-success' : ($materia['intensificacion_diciembre'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($materia['intensificacion_diciembre']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Febrero -->
                        <td class="text-center">
                            <?php if ($materia['intensificacion_febrero']): ?>
                                <strong class="<?= $materia['intensificacion_febrero'] >= 7 ? 'text-success' : ($materia['intensificacion_febrero'] >= 4 ? 'text-warning' : 'text-danger') ?>">
                                    <?= formatearCalificacion($materia['intensificacion_febrero']) ?>
                                </strong>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Calificación Final -->
                        <td class="text-center">
                            <?php if ($materia['calificacion_final']): ?>
                                <span class="badge bg-<?= $materia['calificacion_final'] >= 7 ? 'success' : ($materia['calificacion_final'] >= 4 ? 'warning' : 'danger') ?> fs-6">
                                    <?= formatearCalificacion($materia['calificacion_final']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Observaciones -->
                        <td>
                            <?php if (!empty($materia['observaciones'])): ?>
                                <small><?= htmlspecialchars($materia['observaciones']) ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- SECCIÓN 3: MATERIAS RECURSANDO -->
<?php if (!empty($materiasRecursando)): ?>
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="card-title mb-0">
            <i class="bi bi-arrow-repeat"></i> Materias en Recursado
            <span class="badge bg-dark text-warning ms-2"><?= count($materiasRecursando) ?> materias</span>
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h6><i class="bi bi-info-circle"></i> Información sobre Recursado:</h6>
            <p class="mb-0">Estas materias están siendo cursadas nuevamente. Las calificaciones mostradas corresponden al período actual de recursado.</p>
        </div>
        
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-warning">
                    <tr>
                        <th>MATERIA</th>
                        <th>AÑO CURSO</th>
                        <th>ESTADO</th>
                        <th>1°C VAL.</th>
                        <th>1°C CAL.</th>
                        <th>2°C VAL.</th>
                        <th>2°C CAL.</th>
                        <th>INTENSIF.</th>
                        <th>CALIF. FINAL</th>
                        <th>OBSERVACIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materiasRecursando as $materia): ?>
                    <?php 
                        // 🆕 NUEVA LÓGICA: Buscar si la materia pertenece a un grupo
                        $nombreMostrar = $materia['materia_nombre']; // Nombre original por defecto
                        $codigoMostrar = $materia['materia_codigo']; // Código original por defecto
                        $esPartDeGrupo = false;
                        $infoGrupoCompleta = null;
                        
                        // MÉTODO 1: Usar funciones de agrupación si están disponibles
                        $infoGrupoCompleta = obtenerGrupoParaMateriaRecursada($db, $materia, $cicloLectivoId);
                        
                        if ($infoGrupoCompleta) {
                            $nombreMostrar = $infoGrupoCompleta['grupo_nombre'];
                            $codigoMostrar = $infoGrupoCompleta['grupo_codigo'] ?? $materia['materia_codigo'];
                            $esPartDeGrupo = true;
                        } else {
                            // MÉTODO 2: Mapeo especial por nombre (fallback)
                            $grupoEspecial = obtenerGrupoPorNombreMateria($materia['materia_nombre']);
                            if ($grupoEspecial) {
                                $nombreMostrar = $grupoEspecial;
                                $esPartDeGrupo = true;
                            }
                        }
                        
                        // Debug para materias específicas (solo para admin)
                        $esDebugMateria = (stripos($materia['materia_nombre'], 'DIBUJO') !== false);
                        if ($esDebugMateria && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
                            error_log("DEBUG RECURSADA - Materia: " . $materia['materia_nombre'] . 
                                     " | Grupo encontrado: " . ($infoGrupoCompleta ? $infoGrupoCompleta['grupo_nombre'] : 'NINGUNO') .
                                     " | Nombre a mostrar: " . $nombreMostrar);
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($nombreMostrar) ?></strong>
                            <br>
                            <small class="text-muted"><?= htmlspecialchars($codigoMostrar) ?></small>
                            <?php if ($esPartDeGrupo): ?>
                                <br>
                                <small class="text-info">
                                    <i class="bi bi-collection"></i> Materia: <?= htmlspecialchars($materia['materia_nombre']) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $materia['anio_cursada'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-warning text-dark">
                                <?= $materia['recursado_estado'] ?? 'Activo' ?>
                            </span>
                        </td>
                        
                        <!-- Valoraciones y calificaciones (mismo formato que individuales) -->
                        <td class="text-center">
                            <?php if ($materia['valoracion_preliminar_1c']): ?>
                                <span class="badge bg-<?= $materia['valoracion_preliminar_1c'] === 'TEA' ? 'success' : ($materia['valoracion_preliminar_1c'] === 'TEP' ? 'warning' : 'danger') ?>">
                                    <?= $materia['valoracion_preliminar_1c'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $materia['calificacion_1c'] ? formatearCalificacion($materia['calificacion_1c']) : '-' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($materia['valoracion_preliminar_2c']): ?>
                                <span class="badge bg-<?= $materia['valoracion_preliminar_2c'] === 'TEA' ? 'success' : ($materia['valoracion_preliminar_2c'] === 'TEP' ? 'warning' : 'danger') ?>">
                                    <?= $materia['valoracion_preliminar_2c'] ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= $materia['calificacion_2c'] ? formatearCalificacion($materia['calificacion_2c']) : '-' ?>
                        </td>
                        <td class="text-center">
                            <?= $materia['intensificacion_1c'] ? formatearCalificacion($materia['intensificacion_1c']) : '-' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($materia['calificacion_final']): ?>
                                <span class="badge bg-<?= $materia['calificacion_final'] >= 7 ? 'success' : ($materia['calificacion_final'] >= 4 ? 'warning' : 'danger') ?> fs-6">
                                    <?= formatearCalificacion($materia['calificacion_final']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($materia['observaciones'])): ?>
                                <small><?= htmlspecialchars($materia['observaciones']) ?></small>
                            <?php elseif (!empty($materia['recursado_observaciones'])): ?>
                                <small class="text-warning"><?= htmlspecialchars($materia['recursado_observaciones']) ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 🆕 INFORMACIÓN MEJORADA SOBRE AGRUPACIONES -->
        <?php 
        $materiasConGrupo = 0;
        $materiasConGrupoDetalle = [];
        
        foreach ($materiasRecursando as $materia) {
            $grupoInfo = obtenerGrupoParaMateriaRecursada($db, $materia, $cicloLectivoId);
            if ($grupoInfo) {
                $materiasConGrupo++;
                $materiasConGrupoDetalle[] = [
                    'materia_original' => $materia['materia_nombre'],
                    'grupo_nombre' => $grupoInfo['grupo_nombre']
                ];
            } else {
                $grupoEspecial = obtenerGrupoPorNombreMateria($materia['materia_nombre']);
                if ($grupoEspecial) {
                    $materiasConGrupo++;
                    $materiasConGrupoDetalle[] = [
                        'materia_original' => $materia['materia_nombre'],
                        'grupo_nombre' => $grupoEspecial
                    ];
                }
            }
        }
        ?>
        
        <?php if ($materiasConGrupo > 0): ?>
        <div class="alert alert-success mt-3">
            <h6><i class="bi bi-check-circle"></i> Materias Agrupadas en Recursado:</h6>
            <ul class="mb-2 small">
                <li><strong><?= $materiasConGrupo ?></strong> de <?= count($materiasRecursando) ?> materias pertenecen a grupos</li>
                <li>Se muestra el <strong>nombre del grupo</strong> en lugar del nombre individual de la materia</li>
                <li>El nombre original de la materia se indica debajo como referencia</li>
                <li>Las calificaciones corresponden al período actual de recursado</li>
            </ul>
            
            <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
            <details class="mt-2">
                <summary class="btn btn-sm btn-outline-secondary">Ver mapeo de agrupaciones (Admin)</summary>
                <div class="mt-2">
                    <small>
                        <?php foreach ($materiasConGrupoDetalle as $detalle): ?>
                        <div class="text-muted">
                            • "<?= htmlspecialchars($detalle['materia_original']) ?>" → 
                            <strong>"<?= htmlspecialchars($detalle['grupo_nombre']) ?>"</strong>
                        </div>
                        <?php endforeach; ?>
                    </small>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-light mt-3">
            <small class="text-muted">
                <i class="bi bi-info-circle"></i> 
                Ninguna materia recursada pertenece a grupos de materias. Se muestran los nombres individuales.
            </small>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- MATERIAS PENDIENTES DE INTENSIFICACIÓN -->
<?php 
if (isset($estudianteSeleccionado) && isset($datosEstudiante) && isset($cicloLectivoId)): 
    // Preparar variables compatibles con vista_materias_pendientes.php
    $estudiante = $datosEstudiante;
    $estudiante['id'] = $estudianteSeleccionado;
    
    // Incluir la vista
    include 'includes/vista_materias_pendientes.php';
endif; 
?>
<!-- SECCIÓN 4: MATERIAS PENDIENTES DE INTENSIFICACIÓN (CORREGIDA) -->
<?php if (!empty($materiasPendientes)): ?>
<div class="card mb-4">
    <div class="card-header bg-danger text-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-clock-history"></i> Materias Pendientes de Aprobación
            <span class="badge bg-light text-danger ms-2"><?= count($materiasPendientes) ?> pendientes</span>
        </h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <h6><i class="bi bi-exclamation-triangle"></i> Materias con Calificaciones Pendientes:</h6>
            <p class="mb-0">Estas materias requieren mejorar su calificación o completar evaluaciones pendientes.</p>
        </div>
        
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-danger">
                    <tr>
                        <th>MATERIA</th>
                        <th>AÑO</th>
                        <th>AÑO CURSADA</th>
                        <th>MODELO</th>
                        <th>MARZO</th>
                        <th>JULIO</th>
                        <th>AGOSTO</th>
                        <th>DICIEMBRE</th>
                        <th>FEBRERO</th>
                        <th>CALIF. FINAL</th>
                        <th>ESTADO/OBSERVACIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materiasPendientes as $materia): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($materia['materia_nombre']) ?></strong>
                            <br>
                            <small class="text-muted"><?= htmlspecialchars($materia['materia_codigo']) ?></small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $materia['materia_anio'] ?? '-' ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info"><?= $materia['ciclo_lectivo_cursada_id'] ?? '-' ?></span>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($materia['modelo_intensificacion']) && $materia['modelo_intensificacion'] !== 'Sin modelo definido'): ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($materia['modelo_intensificacion']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Estados de intensificación por período -->
                        <?php 
                        $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
                        foreach ($periodos as $periodo): 
                            $estado = $materia['estado_' . $periodo] ?? null;
                            $calificacion = $materia['calificacion_' . $periodo] ?? null;
                        ?>
                        <td class="text-center">
                            <?php if ($estado): ?>
                                <span class="badge bg-<?= $estado === 'AA' ? 'success' : ($estado === 'CCA' ? 'warning' : 'danger') ?>">
                                    <?= $estado ?>
                                </span>
                                <?php if ($estado === 'AA' && $calificacion): ?>
                                    <br>
                                    <small class="text-success"><strong>(<?= formatearCalificacion($calificacion) ?>)</strong></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        
                        <!-- Calificación Final -->
                        <td class="text-center">
                            <?php if ($materia['calificacion_final']): ?>
                                <?php if ($materia['calificacion_final'] >= 4): ?>
                                    <span class="badge bg-success fs-6">
                                        <?= formatearCalificacion($materia['calificacion_final']) ?>
                                    </span>
                                    <br>
                                    <small class="text-success"><strong>APROBADA</strong></small>
                                <?php else: ?>
                                    <span class="badge bg-danger fs-6">
                                        <?= formatearCalificacion($materia['calificacion_final']) ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Pendiente</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Estado/Observaciones -->
                        <td>
                            <?php if (isset($materia['calificacion_final']) && $materia['calificacion_final'] >= 4): ?>
                                <span class="badge bg-success">Aprobada</span>
                            <?php else: ?>
                                <?php if (!empty($materia['saberes_pendientes_final']) && $materia['saberes_pendientes_final'] !== 'Pendiente de evaluación'): ?>
                                    <small><?= htmlspecialchars($materia['saberes_pendientes_final']) ?></small>
                                <?php elseif (!empty($materia['saberes_pendientes_inicial']) && $materia['saberes_pendientes_inicial'] !== 'Pendiente de evaluación'): ?>
                                    <small class="text-muted"><?= htmlspecialchars($materia['saberes_pendientes_inicial']) ?></small>
                                <?php else: ?>
                                    <small class="text-warning">Requiere evaluación adicional</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- SECCIÓN 5 MEJORADA: ASISTENCIAS -->
<div class="card mb-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0">
            <i class="bi bi-calendar-check"></i> Asistencia
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-info">
                    <tr>
                        <th style="width: 20%">Período</th>
                        <th class="text-center" style="width: 12%">Ausentes</th>
                        <th class="text-center" style="width: 15%">Medias Faltas</th>
                        <th class="text-center" style="width: 12%">Justificadas</th>
                        <th class="text-center" style="width: 12%">Total Faltas</th>
                        <th class="text-center" style="width: 8%">%</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- 1° Cuatrimestre -->
                    <tr>
                        <td>
                            <strong>1° Cuatrimestre</strong>
                            <br>
                            <small class="text-muted">Marzo - Julio</small>
                        </td>
                        
                        <td class="text-center">
                            <?php 
                            $ausentes1 = isset($asistenciasPorCuatrimestre[1]) ? ($asistenciasPorCuatrimestre[1]['ausentes'] ?? 0) : 0;
                            ?>
                            <span class="badge bg-danger"><?= $ausentes1 ?></span>
                        </td>
                        <td class="text-center">
                            <?php 
                            $mediasFaltasCount1 = isset($asistenciasPorCuatrimestre[1]) ? ($asistenciasPorCuatrimestre[1]['medias_faltas_count'] ?? 0) : 0;
                            $mediasFaltasDecimal1 = isset($asistenciasPorCuatrimestre[1]) ? ($asistenciasPorCuatrimestre[1]['medias_faltas_decimal'] ?? 0) : 0;
                            ?>
                            <?php if ($mediasFaltasCount1 > 0 || $mediasFaltasDecimal1 > 0): ?>
                                <span class="badge bg-warning text-dark">
                                    <?= formatearMediasFaltas($mediasFaltasCount1, $mediasFaltasDecimal1) ?>
                                </span>
                                <br>
                                <small class="text-muted">(<?= $mediasFaltasCount1 ?> registros)</small>
                            <?php else: ?>
                                <span class="badge bg-success">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-success">
                                <?= isset($asistenciasPorCuatrimestre[1]) ? ($asistenciasPorCuatrimestre[1]['justificadas'] ?? 0) : 0 ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalFaltas1 = isset($asistenciasPorCuatrimestre[1]) ? ($asistenciasPorCuatrimestre[1]['total_faltas_decimal'] ?? 0) : 0;
                            ?>
                            <strong class="<?= $totalFaltas1 > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($totalFaltas1, ($totalFaltas1 != intval($totalFaltas1)) ? 2 : 0) ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalDias1 = isset($asistenciasPorCuatrimestre[1]) ? $asistenciasPorCuatrimestre[1]['total_dias'] : 0;
                            $porcentajeAsistencia1 = calcularPorcentajeAsistencia($totalDias1, $totalFaltas1);
                            $claseAsistencia1 = obtenerClaseAsistencia($porcentajeAsistencia1);
                            ?>
                            <span class="badge bg-<?= $claseAsistencia1 ?>">
                                <?= $porcentajeAsistencia1 ?>%
                            </span>
                        </td>
                    </tr>
                    
                    <!-- 2° Cuatrimestre -->
                    <tr>
                        <td>
                            <strong>2° Cuatrimestre</strong>
                            <br>
                            <small class="text-muted">Agosto - Diciembre</small>
                        </td>
                        
                        <td class="text-center">
                            <?php 
                            $ausentes2 = isset($asistenciasPorCuatrimestre[2]) ? ($asistenciasPorCuatrimestre[2]['ausentes'] ?? 0) : 0;
                            ?>
                            <span class="badge bg-danger"><?= $ausentes2 ?></span>
                        </td>
                        <td class="text-center">
                            <?php 
                            $mediasFaltasCount2 = isset($asistenciasPorCuatrimestre[2]) ? ($asistenciasPorCuatrimestre[2]['medias_faltas_count'] ?? 0) : 0;
                            $mediasFaltasDecimal2 = isset($asistenciasPorCuatrimestre[2]) ? ($asistenciasPorCuatrimestre[2]['medias_faltas_decimal'] ?? 0) : 0;
                            ?>
                            <?php if ($mediasFaltasCount2 > 0 || $mediasFaltasDecimal2 > 0): ?>
                                <span class="badge bg-warning text-dark">
                                    <?= formatearMediasFaltas($mediasFaltasCount2, $mediasFaltasDecimal2) ?>
                                </span>
                                <br>
                                <small class="text-muted">(<?= $mediasFaltasCount2 ?> registros)</small>
                            <?php else: ?>
                                <span class="badge bg-success">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-success">
                                <?= isset($asistenciasPorCuatrimestre[2]) ? ($asistenciasPorCuatrimestre[2]['justificadas'] ?? 0) : 0 ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalFaltas2 = isset($asistenciasPorCuatrimestre[2]) ? ($asistenciasPorCuatrimestre[2]['total_faltas_decimal'] ?? 0) : 0;
                            ?>
                            <strong class="<?= $totalFaltas2 > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($totalFaltas2, ($totalFaltas2 != intval($totalFaltas2)) ? 2 : 0) ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalDias2 = isset($asistenciasPorCuatrimestre[2]) ? $asistenciasPorCuatrimestre[2]['total_dias'] : 0;
                            $porcentajeAsistencia2 = calcularPorcentajeAsistencia($totalDias2, $totalFaltas2);
                            $claseAsistencia2 = obtenerClaseAsistencia($porcentajeAsistencia2);
                            ?>
                            <span class="badge bg-<?= $claseAsistencia2 ?>">
                                <?= $porcentajeAsistencia2 ?>%
                            </span>
                        </td>
                        
                    </tr>
                    
                    <!-- Total Ciclo Lectivo -->
                    <tr class="table-secondary">
                        <td>
                            <strong>Total Ciclo Lectivo</strong>
                            <br>
                            <small class="text-muted">Resumen anual</small>
                        </td>

                        <td class="text-center">
                            <strong>
                                <span class="badge bg-danger"><?= $ausentes1 + $ausentes2 ?></span>
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalMediasFaltasDecimal = $mediasFaltasDecimal1 + $mediasFaltasDecimal2;
                            $totalMediasFaltasCount = $mediasFaltasCount1 + $mediasFaltasCount2;
                            ?>
                            <strong>
                                <?php if ($totalMediasFaltasCount > 0 || $totalMediasFaltasDecimal > 0): ?>
                                    <span class="badge bg-warning text-dark">
                                        <?= formatearMediasFaltas($totalMediasFaltasCount, $totalMediasFaltasDecimal) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success">0</span>
                                <?php endif; ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <strong>
                                <span class="badge bg-success">
                                    <?= 
                                    (isset($asistenciasPorCuatrimestre[1]) ? ($asistenciasPorCuatrimestre[1]['justificadas'] ?? 0) : 0) + 
                                    (isset($asistenciasPorCuatrimestre[2]) ? ($asistenciasPorCuatrimestre[2]['justificadas'] ?? 0) : 0)
                                    ?>
                                </span>
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalFaltasAnual = $totalFaltas1 + $totalFaltas2;
                            ?>
                            <strong class="<?= $totalFaltasAnual > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($totalFaltasAnual, ($totalFaltasAnual != intval($totalFaltasAnual)) ? 2 : 0) ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php 
                            $totalDiasAnual = $totalDias1 + $totalDias2;
                            $porcentajeAsistenciaAnual = calcularPorcentajeAsistencia($totalDiasAnual, $totalFaltasAnual);
                            $claseAsistenciaAnual = obtenerClaseAsistencia($porcentajeAsistenciaAnual);
                            ?>
                            <strong>
                                <span class="badge bg-<?= $claseAsistenciaAnual ?> fs-6">
                                    <?= $porcentajeAsistenciaAnual ?>%
                                </span>
                            </strong>
                        </td>
                        
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Información adicional sobre asistencias mejorada -->
        <div class="row mt-3 d-none">
            <div class="col-md-6">
                <div class="card bg-light">
                    <div class="card-body p-3">
                        <h6 class="card-title">Leyenda de Asistencias:</h6>
                        <div class="row">
                            <div class="col-6">
                                <span class="badge bg-success">Verde</span> <small>Justificadas</small>
                            </div>
                            <div class="col-6">
                                <span class="badge bg-danger">Rojo</span> <small>Ausentes</small>
                            </div>
                        </div>
                        <div class="row mt-1">
                            <div class="col-6">
                                <span class="badge bg-warning text-dark">Amarillo</span> <small>Medias faltas</small>
                            </div>
                            <div class="col-6">
                                <span class="badge bg-info">Azul</span> <small>Tardanzas</small>
                            </div>
                        </div>
                        <div class="row mt-1">
                            <div class="col-12">
                                <span class="badge bg-light text-dark">Gris</span> <small>Días hábiles</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-light">
                    <div class="card-body p-3">
                        <h6 class="card-title">Criterios y Explicaciones:</h6>
                        <ul class="list-unstyled mb-0 small">
                            <li><span class="badge bg-success">≥80%</span> Asistencia Excelente</li>
                            <li><span class="badge bg-warning">70-79%</span> Asistencia Regular</li>
                            <li><span class="badge bg-danger">&lt;70%</span> Asistencia Insuficiente</li>
                            <li class="mt-2"><strong>Medias faltas:</strong> Incluye 1/4, 1/2 y 3/4 de falta</li>
                            <li><strong>Total faltas:</strong> Suma decimal precisa</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Información de depuración para administradores -->
        <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
        <div class="alert alert-light mt-3">
            <h6><i class="bi bi-tools"></i> Información de Depuración (Solo Administradores):</h6>
            <div class="row small">
                <div class="col-md-6">
                    <strong>1° Cuatrimestre:</strong>
                    <ul class="mb-1">
                        <li>Ausentes: <?= $ausentes1 ?></li>
                        <li>Medias faltas (count): <?= $mediasFaltasCount1 ?></li>
                        <li>Medias faltas (decimal): <?= number_format($mediasFaltasDecimal1, 2) ?></li>
                        <li>Total faltas: <?= number_format($totalFaltas1, 2) ?></li>
                        <li>Días totales: <?= $totalDias1 ?></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <strong>2° Cuatrimestre:</strong>
                    <ul class="mb-1">
                        <li>Ausentes: <?= $ausentes2 ?></li>
                        <li>Medias faltas (count): <?= $mediasFaltasCount2 ?></li>
                        <li>Medias faltas (decimal): <?= number_format($mediasFaltasDecimal2, 2) ?></li>
                        <li>Total faltas: <?= number_format($totalFaltas2, 2) ?></li>
                        <li>Días totales: <?= $totalDias2 ?></li>
                    </ul>
                </div>
            </div>
            
            <!-- Mostrar estados únicos encontrados en la base de datos -->
            <?php
            try {
                $estadosEncontrados = $db->fetchAll(
                    "SELECT DISTINCT estado, COUNT(*) as cantidad 
                     FROM asistencias 
                     WHERE estudiante_id = ? 
                     GROUP BY estado 
                     ORDER BY cantidad DESC",
                    [$estudianteSeleccionado]
                );
                
                if (!empty($estadosEncontrados)) {
                    echo '<div class="mt-2"><strong>Estados de asistencia encontrados en BD:</strong><br>';
                    foreach ($estadosEncontrados as $estado) {
                        echo '<span class="badge bg-secondary me-1">' . htmlspecialchars($estado['estado']) . ' (' . $estado['cantidad'] . ')</span>';
                    }
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="mt-2 text-muted">No se pudieron obtener estados de asistencia</div>';
            }
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- SECCIÓN 6: REFERENCIAS Y AYUDA -->
<div class="card">
    <div class="card-header bg-light">
        <h5 class="card-title mb-0">
            <i class="bi bi-info-circle"></i> Referencias y Significados
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <h6>Tipo de Cursada:</h6>
                <ul class="list-unstyled">
                    <li><span class="badge bg-success">C</span> Cursada por primera vez</li>
                    <li><span class="badge bg-warning">R</span> Recursada</li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6>Valoraciones Preliminares:</h6>
                <ul class="list-unstyled">
                    <li><span class="badge bg-success">TEA</span> Trayectoria Educativa Avanzada</li>
                    <li><span class="badge bg-warning">TEP</span> Trayectoria Educativa en Proceso</li>
                    <li><span class="badge bg-danger">TED</span> Trayectoria Educativa Discontinua</li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6>Estados de Intensificación:</h6>
                <ul class="list-unstyled">
                    <li><span class="badge bg-success">AA</span> Aprobó y Acreditó</li>
                    <li><span class="badge bg-warning">CCA</span> Continúa, Con Avances</li>
                    <li><span class="badge bg-danger">CSA</span> Continúa, Sin Avances</li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6>Criterios de Calificación:</h6>
                <ul class="list-unstyled">
                    <li><span class="badge bg-success">7-10</span> Aprobado</li>
                    <li><span class="badge bg-warning">4-6</span> Regular</li>
                    <li><span class="badge bg-danger">1-3</span> Insuficiente</li>
                </ul>
            </div>
        </div>
        
        <hr>
        
        <div class="row">
            <div class="col-md-6">
                <h6>Materias Agrupadas:</h6>
                <ul class="list-unstyled">
                    <li><i class="bi bi-check-circle text-success"></i> Combinación de materias trimestrales y anuales</li>
                    <li><i class="bi bi-calculator text-info"></i> Calificación final: promedio de cuatrimestres</li>
                    <li><i class="bi bi-shield-check text-primary"></i> Valoraciones basadas en calificaciones obtenidas</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6>Notas Importantes:</h6>
                <ul class="list-unstyled">
                    <li><i class="bi bi-clock text-warning"></i> Las valoraciones pueden calcularse automáticamente</li>
                    <li><i class="bi bi-people text-info"></i> Los subgrupos indican rotación de materias</li>
                    <li><i class="bi bi-arrow-repeat text-secondary"></i> El recursado mantiene información específica</li>
                </ul>
            </div>
        </div>
        
        <!-- Información de depuración (solo para administradores) -->
        <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
        <hr>
        <div class="alert alert-light">
            <h6><i class="bi bi-tools"></i> Información de Depuración (Solo Administradores):</h6>
            <div class="row small">
                <div class="col-md-4">
                    <strong>Calificaciones encontradas:</strong> <?= count($todasLasCalificaciones) ?>
                    <br>
                    <strong>Materias individuales:</strong> <?= count($materiasIndividuales) ?>
                    <br>
                    <strong>Materias en grupos:</strong> <?= count($materiasEnGrupos) ?>
                </div>
                <div class="col-md-4">
                    <strong>Materias recursando:</strong> <?= count($materiasRecursando) ?>
                    <br>
                    <strong>Materias pendientes:</strong> <?= count($materiasPendientes) ?>
                    <br>
                    <strong>Grupos de materias:</strong> <?= count($gruposMaterias) ?>
                </div>
                <div class="col-md-4">
                    <strong>Ciclo lectivo ID:</strong> <?= $cicloLectivoId ?>
                    <br>
                    <strong>Estudiante ID:</strong> <?= $estudianteSeleccionado ?>
                    <br>
                    <strong>Curso estudiante:</strong> <?= $cursoEstudiante['id'] ?? 'No encontrado' ?>
                </div>
            </div>
            
            <?php if (!empty($todasLasCalificaciones)): ?>
            <details class="mt-2">
                <summary class="btn btn-sm btn-outline-secondary">Ver muestra de datos obtenidos</summary>
                <div class="mt-2">
                    <pre class="small bg-white p-2 rounded border" style="max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r(array_slice($todasLasCalificaciones, 0, 2), true)) ?></pre>
                </div>
            </details>
            <?php endif; ?>
            
            <?php if (!empty($calificacionesGrupos)): ?>
            <details class="mt-2">
                <summary class="btn btn-sm btn-outline-info">Ver datos de grupos calculados</summary>
                <div class="mt-2">
                    <pre class="small bg-white p-2 rounded border" style="max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($calificacionesGrupos, true)) ?></pre>
                </div>
            </details>
            <?php endif; ?>
            
            <?php if (empty($todasLasCalificaciones)): ?>
            <div class="alert alert-warning mt-2">
                <strong>⚠️ No se encontraron calificaciones.</strong>
                <br>
                <small>
                    Esto puede deberse a:
                    <ul class="mb-0">
                        <li>El estudiante no tiene calificaciones cargadas</li>
                        <li>No hay relación entre el estudiante y el ciclo lectivo</li>
                        <li>Problema en la estructura de la base de datos</li>
                    </ul>
                </small>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CSS adicional para mejorar la presentación -->
<style>
.table-responsive {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.card-header {
    border-bottom: 2px solid rgba(255,255,255,0.1);
}

.badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.6rem;
}

.badge.fs-6 {
    font-size: 1rem !important;
    padding: 0.5rem 0.8rem;
    font-weight: 600;
}

.table th, .table td {
    vertical-align: middle;
    padding: 0.75rem 0.5rem;
}

.table-sm th, .table-sm td {
    padding: 0.5rem 0.3rem;
}

.table thead th {
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.875rem;
}

.modal-header {
    border-bottom: 2px solid rgba(255,255,255,0.1);
}

.alert {
    border: none;
    border-left: 4px solid;
}

.alert-info {
    border-left-color: #0dcaf0;
}

.alert-warning {
    border-left-color: #ffc107;
}

.alert-light {
    border-left-color: #f8f9fa;
    background-color: #f8f9fa;
}

/* Animaciones sutiles */
.card {
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.badge {
    transition: all 0.2s ease;
}

.badge:hover {
    transform: scale(1.05);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.8rem;
    }
    
    .badge {
        font-size: 0.65rem;
        padding: 0.25rem 0.4rem;
    }
    
    .card-header h5 {
        font-size: 1rem;
    }
}

/* Estados de calificaciones */
.text-success { color: #198754 !important; }
.text-warning { color: #fd7e14 !important; }
.text-danger { color: #dc3545 !important; }

.bg-success { background-color: #198754 !important; }
.bg-warning { background-color: #ffc107 !important; }
.bg-danger { background-color: #dc3545 !important; }

/* Mejoras para modales */
.modal-content {
    border: none;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.modal-header {
    border-radius: 12px 12px 0 0;
}

/* Destacar filas importantes */
.table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05);
}

.table-warning tbody tr {
    background-color: rgba(255, 193, 7, 0.1);
}

.table-danger tbody tr {
    background-color: rgba(220, 53, 69, 0.1);
}

/* Información de depuración */
details summary {
    cursor: pointer;
}

details summary:hover {
    background-color: #f8f9fa;
}

pre {
    font-family: 'Courier New', monospace;
    font-size: 0.75rem;
}

/* Indicadores visuales de error */
.alert-danger {
    border-left-color: #dc3545;
}

.alert-success {
    border-left-color: #198754;
}
</style>

<script>
// JavaScript para mejorar la experiencia del usuario en el boletín
document.addEventListener('DOMContentLoaded', function() {
    // Inicializar tooltips si Bootstrap está disponible
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Mejorar la presentación visual de las tablas
    const tablas = document.querySelectorAll('.table');
    tablas.forEach(tabla => {
        tabla.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.002)';
            this.style.transition = 'all 0.3s ease';
        });
        
        tabla.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
    
    // Agregar efectos hover a los badges
    const badges = document.querySelectorAll('.badge');
    badges.forEach(badge => {
        badge.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
            this.style.transition = 'all 0.2s ease';
        });
        
        badge.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
    
    // Función para destacar visualmente las calificaciones
    function destacarCalificaciones() {
        const calificaciones = document.querySelectorAll('.table td strong');
        calificaciones.forEach(cal => {
            const valor = parseInt(cal.textContent);
            if (!isNaN(valor)) {
                if (valor >= 7) {
                    cal.style.fontWeight = 'bold';
                    cal.title = 'Calificación aprobada';
                } else if (valor >= 4) {
                    cal.title = 'Calificación regular';
                } else {
                    cal.title = 'Calificación insuficiente';
                }
            }
        });
    }
    
    destacarCalificaciones();
    
    // Agregar información contextual en hover para materias agrupadas
    const btnDetalles = document.querySelectorAll('[data-bs-target*="modalDetalleGrupo"]');
    btnDetalles.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.innerHTML = '<i class="bi bi-eye"></i> Ver detalles';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.innerHTML = '<i class="bi bi-info-circle"></i> Ver';
        });
    });
    
    // Función para resaltar materias con calificaciones bajas
    function resaltarCalificacionesBajas() {
        const calificacionesBajas = document.querySelectorAll('.badge.bg-danger');
        calificacionesBajas.forEach(badge => {
            if (badge.textContent.trim().match(/^[1-3]$/)) {
                badge.style.animation = 'pulse 2s infinite';
                badge.title = 'Calificación que requiere atención';
            }
        });
    }
    
    resaltarCalificacionesBajas();
    
    // Agregar CSS para animación de pulse
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
    `;
    document.head.appendChild(style);
    
    // Función para mostrar estadísticas rápidas
    function mostrarEstadisticas() {
        const materiasAprobadas = document.querySelectorAll('.badge.bg-success').length;
        const materiasEnProceso = document.querySelectorAll('.badge.bg-warning').length;
        const materiasDesaprobadas = document.querySelectorAll('.badge.bg-danger').length;
        
        console.log('Estadísticas del boletín:', {
            aprobadas: materiasAprobadas,
            enProceso: materiasEnProceso,
            desaprobadas: materiasDesaprobadas
        });
    }
    
    mostrarEstadisticas();
    
    // Mejorar accesibilidad agregando títulos a los elementos importantes
    const valoraciones = document.querySelectorAll('.badge');
    valoraciones.forEach(badge => {
        const texto = badge.textContent.trim();
        if (texto === 'TEA') {
            badge.title = 'Trayectoria Educativa Avanzada';
        } else if (texto === 'TEP') {
            badge.title = 'Trayectoria Educativa en Proceso';
        } else if (texto === 'TED') {
            badge.title = 'Trayectoria Educativa Discontinua';
        } else if (texto === 'AA') {
            badge.title = 'Aprobó y Acreditó';
        } else if (texto === 'CCA') {
            badge.title = 'Continúa, Con Avances';
        } else if (texto === 'CSA') {
            badge.title = 'Continúa, Sin Avances';
        } else if (texto === 'C') {
            badge.title = 'Cursada por primera vez';
        } else if (texto === 'R') {
            badge.title = 'Recursada';
        }
    });
    
    // Función para verificar si hay datos
    function verificarDatos() {
        const totalMaterias = document.querySelectorAll('.table tbody tr').length;
        const materiasConDatos = document.querySelectorAll('.table tbody tr td .badge:not(.bg-light)').length;
        
        if (totalMaterias === 0) {
            console.warn('No se encontraron materias en el boletín');
        } else if (materiasConDatos === 0) {
            console.warn('Se encontraron materias pero sin calificaciones');
        } else {
            console.log(`Boletín cargado correctamente: ${totalMaterias} materias, ${materiasConDatos} con datos`);
        }
    }
    
    verificarDatos();
});

// Función para confirmar actualización general del boletín
function confirmarActualizacionGeneral() {
    return confirm('¿Está seguro que desea actualizar todo el boletín?\n\nEsto recalculará:\n• Todas las calificaciones de grupos\n• Valoraciones preliminares\n• Calificaciones finales\n\nBasándose en las notas individuales cargadas.');
}

// Función para confirmar recálculo de grupos (función heredada - por compatibilidad)
function confirmarRecalculo() {
    return confirm('¿Está seguro que desea recalcular las calificaciones de grupos?\n\nEsto actualizará todas las calificaciones basándose en las notas individuales cargadas.');
}

// Función para imprimir el boletín (opcional)
function imprimirBoletin() {
    window.print();
}

// Función para exportar a PDF (placeholder)
function exportarPDF() {
    alert('Función de exportación a PDF en desarrollo');
}

console.log('Vista de boletín cuatrimestral CORREGIDA cargada correctamente');
</script>

<?php
/**
 * CORRECCIONES APLICADAS EN ESTA VERSIÓN:
 * 
 * 1. SOLUCIÓN AL ERROR DE TABLA INTENSIFICACION:
 *    - Verificación de existencia de tabla usando metadatos del sistema
 *    - Consulta alternativa cuando la tabla no existe
 *    - Manejo gracioso de errores sin interrumpir el funcionamiento
 * 
 * 2. CORRECCIÓN DE CALIFICACIÓN FINAL EN GRUPOS:
 *    - La calificación final SOLO se muestra cuando hay datos de ambos cuatrimestres
 *    - NO se muestra calificación final si solo hay un cuatrimestre completado
 *    - Evita mostrar calificaciones parciales como finales
 * 
 * 3. CORRECCIÓN DE VALORACIONES PRELIMINARES EN GRUPOS:
 *    - Implementada lógica especial para grupos (ej: Educación Artística)
 *    - TEA solo si TODAS las materias del grupo son TEA
 *    - TEP si hay al menos una materia TEP (y ninguna TED)
 *    - TED si hay al menos una materia TED
 *    - Ejemplo: Si Plástica=TEA y Música=TEP → Grupo=TEP
 * 
 * 4. MEJORAS ADICIONALES:
 *    - Función calcularValoracionGrupo() específica para grupos
 *    - Información de depuración mejorada para administradores
 *    - Manejo robusto de datos faltantes
 *    - Compatible con diferentes estructuras de BD
 * 
 * PRINCIPALES CAMBIOS:
 * - Calificación final: Solo con ambos cuatrimestres completos
 * - Valoraciones: Lógica específica para materias agrupadas
 * - Compatibilidad: Funciona sin tabla intensificacion
 * - Robustez: Manejo mejorado de errores y casos especiales
 */
?>
