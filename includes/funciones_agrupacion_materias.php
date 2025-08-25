<?php
/**
 * funciones_agrupacion_materias.php - Funciones para manejar agrupación de materias
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

/**
 * Obtener grupos de materias para un curso específico
 */
function obtenerGruposMaterias($db, $cursoId, $cicloLectivoId) {
    try {
        // Obtener el año del curso
        $curso = $db->fetchOne("SELECT anio FROM cursos WHERE id = ?", [$cursoId]);
        if (!$curso) return [];
        
        $grupos = $db->fetchAll(
            "SELECT gm.*, rc.tipo_calculo, rc.nota_minima_prevalece 
             FROM grupos_materias gm
             LEFT JOIN reglas_calculo_grupo rc ON gm.id = rc.grupo_id AND rc.activo = 1
             WHERE gm.curso_anio = ? AND gm.ciclo_lectivo_id = ? AND gm.activo = 1
             ORDER BY gm.orden_visualizacion, gm.nombre",
            [$curso['anio'], $cicloLectivoId]
        );
        
        // Obtener materias de cada grupo
        foreach ($grupos as &$grupo) {
            $grupo['materias'] = $db->fetchAll(
                "SELECT mg.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                        mp.id as materia_curso_id, mp.requiere_subgrupos
                 FROM materias_grupo mg
                 JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
                 JOIN materias m ON mp.materia_id = m.id
                 WHERE mg.grupo_id = ? AND mg.activo = 1
                 ORDER BY mg.tipo_duracion DESC, m.nombre", // Anuales primero, luego trimestrales
                [$grupo['id']]
            );
        }
        
        return $grupos;
    } catch (Exception $e) {
        error_log("Error en obtenerGruposMaterias: " . $e->getMessage());
        return [];
    }
}

/**
 * Calcular calificación de un grupo para un estudiante en un cuatrimestre
 * MODIFICADO: Ahora usa la parte entera del promedio (sin redondear hacia arriba)
 */
function calcularCalificacionGrupo($db, $estudianteId, $grupoId, $cuatrimestre, $cicloLectivoId) {
    try {
        // Obtener configuración del grupo
        $grupo = $db->fetchOne(
            "SELECT gm.*, rc.tipo_calculo, rc.nota_minima_prevalece 
             FROM grupos_materias gm
             LEFT JOIN reglas_calculo_grupo rc ON gm.id = rc.grupo_id AND rc.activo = 1
             WHERE gm.id = ?",
            [$grupoId]
        );
        
        if (!$grupo) return null;
        
        // Obtener materias del grupo
        $materias = $db->fetchAll(
            "SELECT mg.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                    mp.id as materia_curso_id
             FROM materias_grupo mg
             JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             WHERE mg.grupo_id = ? AND mg.activo = 1",
            [$grupoId]
        );
        
        $calificacionesConsideradas = [];
        $detalleCalculo = [];
        
        foreach ($materias as $materia) {
            // Determinar si esta materia debe considerarse en este cuatrimestre
            $debeConsiderar = false;
            
            if ($materia['tipo_duracion'] === 'anual') {
                // Las materias anuales siempre se consideran
                $debeConsiderar = true;
            } else {
                // Para materias trimestrales, verificar si corresponde al cuatrimestre
                $trimestreInicio = intval($materia['trimestre_inicio']);
                
                if ($cuatrimestre == 1) {
                    // 1er cuatrimestre: trimestres 1 y 2
                    $debeConsiderar = in_array($trimestreInicio, [1, 2]);
                } else {
                    // 2do cuatrimestre: trimestre 3
                    $debeConsiderar = ($trimestreInicio == 3);
                }
            }
            
            if ($debeConsiderar) {
                // Verificar si el estudiante está asignado a esta materia (para subgrupos)
                $estaAsignado = verificarAsignacionEstudiante($db, $estudianteId, $materia['materia_curso_id'], $cicloLectivoId);
                
                if ($estaAsignado) {
                    // Obtener calificación
                    $calificacion = obtenerCalificacionMateria($db, $estudianteId, $materia['materia_curso_id'], $cuatrimestre, $cicloLectivoId);
                    
                    if ($calificacion !== null) {
                        $calificacionesConsideradas[] = $calificacion;
                        $detalleCalculo[] = [
                            'materia' => $materia['materia_nombre'],
                            'codigo' => $materia['materia_codigo'],
                            'tipo' => $materia['tipo_duracion'],
                            'trimestre' => $materia['trimestre_inicio'],
                            'calificacion' => $calificacion
                        ];
                    }
                }
            }
        }
        
        if (empty($calificacionesConsideradas)) {
            return null;
        }
        
        // Aplicar regla de cálculo
        $tipoCalculo = $grupo['tipo_calculo'] ?? 'promedio_con_minima';
        $notaMinimaPrevalece = floatval($grupo['nota_minima_prevalece'] ?? 6.0);
        
        $calificacionFinal = null;
        
        switch ($tipoCalculo) {
            case 'promedio_con_minima':
                // Verificar si hay alguna nota <= nota_minima_prevalece
                $notasDebajo = array_filter($calificacionesConsideradas, function($nota) use ($notaMinimaPrevalece) {
                    return $nota <= $notaMinimaPrevalece;
                });
                
                if (!empty($notasDebajo)) {
                    // Si hay notas por debajo del mínimo, tomar la menor
                    $calificacionFinal = min($notasDebajo);
                } else {
                    // Si no, calcular promedio SIN REDONDEAR (solo la parte entera)
                    $promedio = array_sum($calificacionesConsideradas) / count($calificacionesConsideradas);
                    $calificacionFinal = intval($promedio); // CAMBIO: usa intval() en lugar de round()
                }
                break;
                
            case 'promedio':
                // Calcular promedio SIN REDONDEAR (solo la parte entera)
                $promedio = array_sum($calificacionesConsideradas) / count($calificacionesConsideradas);
                $calificacionFinal = intval($promedio); // CAMBIO: usa intval() en lugar de round()
                break;
                
            case 'minima':
                $calificacionFinal = min($calificacionesConsideradas);
                break;
        }
        
        // Guardar o actualizar en la base de datos
        guardarCalificacionGrupo($db, $estudianteId, $grupoId, $cuatrimestre, $cicloLectivoId, $calificacionFinal, $detalleCalculo);
        
        return [
            'calificacion_final' => $calificacionFinal,
            'detalle_calculo' => $detalleCalculo,
            'tipo_calculo_aplicado' => $tipoCalculo,
            'nota_minima_prevalece' => $notaMinimaPrevalece
        ];
        
    } catch (Exception $e) {
        error_log("Error en calcularCalificacionGrupo: " . $e->getMessage());
        return null;
    }
}

/**
 * Verificar si un estudiante está asignado a una materia (considerando subgrupos)
 */
function verificarAsignacionEstudiante($db, $estudianteId, $materiaCursoId, $cicloLectivoId) {
    try {
        // Verificar si la materia requiere subgrupos
        $materiaInfo = $db->fetchOne(
            "SELECT requiere_subgrupos FROM materias_por_curso WHERE id = ?",
            [$materiaCursoId]
        );
        
        if (!$materiaInfo) return false;
        
        if ($materiaInfo['requiere_subgrupos'] == 1) {
            // Verificar asignación en subgrupos
            $asignacion = $db->fetchOne(
                "SELECT id FROM estudiantes_por_materia 
                 WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ? AND activo = 1",
                [$estudianteId, $materiaCursoId, $cicloLectivoId]
            );
            return $asignacion !== false;
        } else {
            // Para materias sin subgrupos, verificar matrícula en el curso
            $matricula = $db->fetchOne(
                "SELECT m.id 
                 FROM matriculas m
                 JOIN materias_por_curso mp ON m.curso_id = mp.curso_id
                 WHERE m.estudiante_id = ? AND mp.id = ? AND m.estado = 'activo'",
                [$estudianteId, $materiaCursoId]
            );
            return $matricula !== false;
        }
    } catch (Exception $e) {
        error_log("Error en verificarAsignacionEstudiante: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtener calificación de una materia para un cuatrimestre
 */
function obtenerCalificacionMateria($db, $estudianteId, $materiaCursoId, $cuatrimestre, $cicloLectivoId) {
    try {
        $campo = ($cuatrimestre == 1) ? 'calificacion_1c' : 'calificacion_2c';
        
        $calificacion = $db->fetchOne(
            "SELECT $campo as calificacion
             FROM calificaciones 
             WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$estudianteId, $materiaCursoId, $cicloLectivoId]
        );
        
        if ($calificacion && $calificacion['calificacion'] !== null && $calificacion['calificacion'] !== '') {
            return floatval($calificacion['calificacion']);
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error en obtenerCalificacionMateria: " . $e->getMessage());
        return null;
    }
}

/**
 * Guardar calificación calculada del grupo
 */
function guardarCalificacionGrupo($db, $estudianteId, $grupoId, $cuatrimestre, $cicloLectivoId, $calificacionFinal, $detalleCalculo) {
    try {
        $existe = $db->fetchOne(
            "SELECT id FROM calificaciones_grupos 
             WHERE estudiante_id = ? AND grupo_id = ? AND cuatrimestre = ? AND ciclo_lectivo_id = ?",
            [$estudianteId, $grupoId, $cuatrimestre, $cicloLectivoId]
        );
        
        $detalleJson = json_encode($detalleCalculo, JSON_UNESCAPED_UNICODE);
        
        if ($existe) {
            $db->query(
                "UPDATE calificaciones_grupos 
                 SET calificacion_final = ?, detalle_calculo = ?, fecha_calculo = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [$calificacionFinal, $detalleJson, $existe['id']]
            );
        } else {
            $db->query(
                "INSERT INTO calificaciones_grupos 
                 (estudiante_id, grupo_id, ciclo_lectivo_id, cuatrimestre, calificacion_final, detalle_calculo)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$estudianteId, $grupoId, $cicloLectivoId, $cuatrimestre, $calificacionFinal, $detalleJson]
            );
        }
    } catch (Exception $e) {
        error_log("Error en guardarCalificacionGrupo: " . $e->getMessage());
    }
}

/**
 * Obtener todas las calificaciones de grupos para un estudiante
 */
function obtenerCalificacionesGruposEstudiante($db, $estudianteId, $cicloLectivoId, $cursoId) {
    try {
        // Obtener año del curso
        $curso = $db->fetchOne("SELECT anio FROM cursos WHERE id = ?", [$cursoId]);
        if (!$curso) return [];
        
        $calificaciones = $db->fetchAll(
            "SELECT cg.*, gm.nombre as grupo_nombre, gm.codigo as grupo_codigo
             FROM calificaciones_grupos cg
             JOIN grupos_materias gm ON cg.grupo_id = gm.id
             WHERE cg.estudiante_id = ? AND cg.ciclo_lectivo_id = ? 
             AND gm.curso_anio = ? AND gm.activo = 1
             ORDER BY gm.orden_visualizacion, cg.cuatrimestre",
            [$estudianteId, $cicloLectivoId, $curso['anio']]
        );
        
        $resultado = [];
        foreach ($calificaciones as $cal) {
            $grupoId = $cal['grupo_id'];
            if (!isset($resultado[$grupoId])) {
                $resultado[$grupoId] = [
                    'grupo_nombre' => $cal['grupo_nombre'],
                    'grupo_codigo' => $cal['grupo_codigo'],
                    'cuatrimestres' => []
                ];
            }
            
            $resultado[$grupoId]['cuatrimestres'][$cal['cuatrimestre']] = [
                'calificacion_final' => $cal['calificacion_final'],
                'detalle_calculo' => json_decode($cal['detalle_calculo'], true),
                'fecha_calculo' => $cal['fecha_calculo']
            ];
        }
        
        return $resultado;
    } catch (Exception $e) {
        error_log("Error en obtenerCalificacionesGruposEstudiante: " . $e->getMessage());
        return [];
    }
}

/**
 * Recalcular todas las calificaciones de grupos para un estudiante
 */
function recalcularCalificacionesGruposEstudiante($db, $estudianteId, $cicloLectivoId, $cursoId) {
    try {
        $grupos = obtenerGruposMaterias($db, $cursoId, $cicloLectivoId);
        
        foreach ($grupos as $grupo) {
            for ($cuatrimestre = 1; $cuatrimestre <= 2; $cuatrimestre++) {
                calcularCalificacionGrupo($db, $estudianteId, $grupo['id'], $cuatrimestre, $cicloLectivoId);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error en recalcularCalificacionesGruposEstudiante: " . $e->getMessage());
        return false;
    }
}
?>
