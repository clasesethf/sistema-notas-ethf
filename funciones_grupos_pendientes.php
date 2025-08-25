<?php
/**
 * funciones_grupos_pendientes.php - Funciones para manejar agrupación en materias pendientes
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Funciones específicas para agrupar materias pendientes y calcular calificaciones de grupo
 */

/**
 * Agrupar materias pendientes por grupo para un estudiante
 */
function agruparMateriasPendientesPorGrupo($db, $estudianteId, $cicloLectivoId) {
    try {
        // Obtener todas las materias pendientes del estudiante
        $materiasPendientes = $db->fetchAll(
            "SELECT 
                mpi.*,
                m.nombre as materia_nombre,
                m.codigo as materia_codigo,
                c.anio as curso_anio,
                c.nombre as curso_nombre,
                gm.id as grupo_id,
                gm.nombre as grupo_nombre,
                gm.codigo as grupo_codigo,
                -- Si tiene grupo, mostrar el nombre del grupo, sino el nombre de la materia
                COALESCE(gm.nombre, m.nombre) as nombre_mostrar,
                COALESCE(gm.codigo, m.codigo) as codigo_mostrar,
                -- Indicador si es parte de un grupo
                CASE WHEN mg.grupo_id IS NOT NULL THEN 1 ELSE 0 END as es_parte_grupo,
                p1.apellido as profesor_apellido,
                p1.nombre as profesor_nombre
             FROM materias_pendientes_intensificacion mpi
             JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
             JOIN materias m ON mpc.materia_id = m.id
             JOIN cursos c ON mpc.curso_id = c.id
             LEFT JOIN materias_grupo mg ON mpc.id = mg.materia_curso_id AND mg.activo = 1
             LEFT JOIN grupos_materias gm ON mg.grupo_id = gm.id AND gm.activo = 1
             LEFT JOIN usuarios p1 ON mpc.profesor_id = p1.id
             WHERE mpi.estudiante_id = ? AND mpi.ciclo_lectivo_id = ? AND mpi.estado = 'activo'
             ORDER BY gm.orden_visualizacion, gm.nombre, m.nombre",
            [$estudianteId, $cicloLectivoId]
        );

        // Agrupar materias
        $grupos = [];
        $materiasIndividuales = [];

        foreach ($materiasPendientes as $materia) {
            if ($materia['es_parte_grupo'] == 1 && $materia['grupo_id']) {
                $grupoId = $materia['grupo_id'];
                
                if (!isset($grupos[$grupoId])) {
                    $grupos[$grupoId] = [
                        'grupo_id' => $grupoId,
                        'grupo_nombre' => $materia['grupo_nombre'],
                        'grupo_codigo' => $materia['grupo_codigo'],
                        'curso_anio' => $materia['curso_anio'],
                        'materias' => [],
                        'profesores' => [],
                        'es_grupo' => true
                    ];
                }
                
                $grupos[$grupoId]['materias'][] = $materia;
                
                // Agregar profesor único
                if ($materia['profesor_apellido'] && !in_array($materia['profesor_apellido'] . ', ' . $materia['profesor_nombre'], $grupos[$grupoId]['profesores'])) {
                    $grupos[$grupoId]['profesores'][] = $materia['profesor_apellido'] . ', ' . $materia['profesor_nombre'];
                }
            } else {
                // Materia individual (no pertenece a un grupo)
                $materiasIndividuales[] = array_merge($materia, [
                    'es_grupo' => false,
                    'materias' => [$materia],
                    'profesores' => $materia['profesor_apellido'] ? [$materia['profesor_apellido'] . ', ' . $materia['profesor_nombre']] : []
                ]);
            }
        }

        // Combinar grupos y materias individuales
        $resultado = array_merge(array_values($grupos), $materiasIndividuales);

        return $resultado;

    } catch (Exception $e) {
        error_log("Error en agruparMateriasPendientesPorGrupo: " . $e->getMessage());
        return [];
    }
}

/**
 * Calcular el estado de un grupo de materias pendientes
 */
function calcularEstadoGrupoPendiente($materiasDelGrupo) {
    $estados = [];
    $calificacionesFinals = [];
    $todosSinEvaluar = true;
    
    foreach ($materiasDelGrupo as $materia) {
        // Verificar períodos
        $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
        foreach ($periodos as $periodo) {
            if (!empty($materia[$periodo])) {
                $estados[$periodo][] = $materia[$periodo];
                $todosSinEvaluar = false;
            }
        }
        
        // Verificar calificación final
        if (!empty($materia['calificacion_final'])) {
            $calificacionesFinals[] = $materia['calificacion_final'];
        }
    }
    
    if ($todosSinEvaluar) {
        return [
            'estado_general' => 'sin_evaluar',
            'estado_texto' => 'Sin evaluar',
            'estado_badge' => 'secondary',
            'calificacion_final_grupo' => null,
            'aprobado' => false
        ];
    }
    
    // Determinar estado general basado en el último período evaluado
    $periodos = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo'];
    $ultimoEstado = '';
    $ultimoPeriodo = '';
    
    foreach ($periodos as $periodo) {
        if (isset($estados[$periodo]) && !empty($estados[$periodo])) {
            // Para que el grupo esté aprobado en un período, TODAS las materias deben estar AA
            $todasAA = count($estados[$periodo]) === count($materiasDelGrupo) && 
                       count(array_filter($estados[$periodo], function($e) { return $e === 'AA'; })) === count($materiasDelGrupo);
            
            if ($todasAA) {
                $ultimoEstado = 'AA';
                $ultimoPeriodo = ucfirst($periodo);
                break;
            } else {
                // Si hay al menos una CCA, el grupo está CCA
                if (in_array('CCA', $estados[$periodo])) {
                    $ultimoEstado = 'CCA';
                    $ultimoPeriodo = ucfirst($periodo);
                } else {
                    $ultimoEstado = 'CSA';
                    $ultimoPeriodo = ucfirst($periodo);
                }
            }
        }
    }
    
    // Calcular calificación final del grupo
    $calificacionFinalGrupo = null;
    if (!empty($calificacionesFinals)) {
        // Para que el grupo apruebe, TODAS las materias deben tener >= 4
        if (count($calificacionesFinals) === count($materiasDelGrupo)) {
            $todasAprobadas = count(array_filter($calificacionesFinals, function($c) { return $c >= 4; })) === count($calificacionesFinals);
            
            if ($todasAprobadas) {
                // Si todas aprobaron, tomar el promedio (parte entera, como en grupos regulares)
                $calificacionFinalGrupo = intval(array_sum($calificacionesFinals) / count($calificacionesFinals));
            } else {
                // Si alguna no aprobó, tomar la menor calificación
                $calificacionFinalGrupo = min($calificacionesFinals);
            }
        }
    }
    
    // Determinar estado final
    if ($calificacionFinalGrupo !== null) {
        if ($calificacionFinalGrupo >= 4) {
            return [
                'estado_general' => 'aprobado',
                'estado_texto' => 'APROBADO',
                'estado_badge' => 'success',
                'calificacion_final_grupo' => $calificacionFinalGrupo,
                'aprobado' => true
            ];
        } else {
            return [
                'estado_general' => 'no_aprobado',
                'estado_texto' => 'NO APROBADO',
                'estado_badge' => 'danger',
                'calificacion_final_grupo' => $calificacionFinalGrupo,
                'aprobado' => false
            ];
        }
    }
    
    // Estado basado en período
    switch ($ultimoEstado) {
        case 'AA':
            return [
                'estado_general' => 'en_proceso_aa',
                'estado_texto' => 'APROBÓ Y ACREDITÓ (' . $ultimoPeriodo . ')',
                'estado_badge' => 'success',
                'calificacion_final_grupo' => null,
                'aprobado' => false
            ];
        case 'CCA':
            return [
                'estado_general' => 'en_proceso_cca',
                'estado_texto' => 'CONTINÚA CON AVANCES (' . $ultimoPeriodo . ')',
                'estado_badge' => 'warning',
                'calificacion_final_grupo' => null,
                'aprobado' => false
            ];
        case 'CSA':
            return [
                'estado_general' => 'en_proceso_csa',
                'estado_texto' => 'CONTINÚA SIN AVANCES (' . $ultimoPeriodo . ')',
                'estado_badge' => 'danger',
                'calificacion_final_grupo' => null,
                'aprobado' => false
            ];
        default:
            return [
                'estado_general' => 'sin_evaluar',
                'estado_texto' => 'Sin evaluar',
                'estado_badge' => 'secondary',
                'calificacion_final_grupo' => null,
                'aprobado' => false
            ];
    }
}

/**
 * Obtener estadísticas de materias pendientes agrupadas
 */
function obtenerEstadisticasPendientesAgrupadas($materiasAgrupadas) {
    $total = count($materiasAgrupadas);
    $aprobadas = 0;
    $enProceso = 0;
    $sinEvaluar = 0;
    
    foreach ($materiasAgrupadas as $item) {
        if ($item['es_grupo']) {
            $estadoGrupo = calcularEstadoGrupoPendiente($item['materias']);
            if ($estadoGrupo['aprobado']) {
                $aprobadas++;
            } elseif ($estadoGrupo['estado_general'] === 'sin_evaluar') {
                $sinEvaluar++;
            } else {
                $enProceso++;
            }
        } else {
            // Materia individual
            $materia = $item['materias'][0];
            if (!empty($materia['calificacion_final'])) {
                if ($materia['calificacion_final'] >= 4) {
                    $aprobadas++;
                } else {
                    $enProceso++; // No aprobada pero evaluada
                }
            } else {
                // Verificar si tiene algún período evaluado
                $evaluada = false;
                $periodos = ['marzo', 'julio', 'agosto', 'diciembre', 'febrero'];
                foreach ($periodos as $periodo) {
                    if (!empty($materia[$periodo])) {
                        $evaluada = true;
                        break;
                    }
                }
                
                if ($evaluada) {
                    $enProceso++;
                } else {
                    $sinEvaluar++;
                }
            }
        }
    }
    
    return [
        'total' => $total,
        'aprobadas' => $aprobadas,
        'en_proceso' => $enProceso,
        'sin_evaluar' => $sinEvaluar
    ];
}

/**
 * Formatear nombre de materia/grupo para mostrar
 */
function formatearNombreMateriaGrupo($item, $incluirHTML = true) {
    if ($item['es_grupo']) {
        $nombre = $incluirHTML ? htmlspecialchars($item['grupo_nombre']) : $item['grupo_nombre'];
        if ($item['grupo_codigo'] && $incluirHTML) {
            $nombre .= ' <span class="badge bg-primary ms-1">' . htmlspecialchars($item['grupo_codigo']) . '</span>';
        } elseif ($item['grupo_codigo']) {
            $nombre .= ' (' . $item['grupo_codigo'] . ')';
        }
        
        if ($incluirHTML) {
            $nombre .= '<br><small class="text-muted">';
            $materiasNombres = array_map(function($m) { return htmlspecialchars($m['materia_nombre']); }, $item['materias']);
            $nombre .= '<i class="bi bi-arrow-right"></i> ' . implode(', ', $materiasNombres);
            $nombre .= '</small>';
        } else {
            $materiasNombres = array_map(function($m) { return $m['materia_nombre']; }, $item['materias']);
            $nombre .= ' → ' . implode(', ', $materiasNombres);
        }
    } else {
        $materia = $item['materias'][0];
        $nombre = $incluirHTML ? htmlspecialchars($materia['nombre_mostrar']) : $materia['nombre_mostrar'];
        if ($materia['codigo_mostrar'] && $incluirHTML) {
            $nombre .= ' <span class="badge bg-secondary ms-1">' . htmlspecialchars($materia['codigo_mostrar']) . '</span>';
        } elseif ($materia['codigo_mostrar']) {
            $nombre .= ' (' . $materia['codigo_mostrar'] . ')';
        }
    }
    
    return $nombre;
}

/**
 * Obtener materias pendientes agrupadas para todos los estudiantes de un curso
 */
function obtenerMateriasPendientesAgrupadasPorCurso($db, $cursoId, $cicloLectivoId) {
    try {
        // Obtener estudiantes del curso
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni 
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE m.curso_id = ? AND m.estado = 'activo'
             ORDER BY u.apellido, u.nombre",
            [$cursoId]
        );

        $resultado = [];
        
        foreach ($estudiantes as $estudiante) {
            $materiasAgrupadas = agruparMateriasPendientesPorGrupo($db, $estudiante['id'], $cicloLectivoId);
            
            if (!empty($materiasAgrupadas)) {
                $resultado[] = [
                    'estudiante' => $estudiante,
                    'materias_agrupadas' => $materiasAgrupadas,
                    'estadisticas' => obtenerEstadisticasPendientesAgrupadas($materiasAgrupadas)
                ];
            }
        }

        return $resultado;

    } catch (Exception $e) {
        error_log("Error en obtenerMateriasPendientesAgrupadasPorCurso: " . $e->getMessage());
        return [];
    }
}

/**
 * Verificar si un grupo puede actualizarse automáticamente
 */
function puedeActualizarseAutomaticamente($materiasDelGrupo) {
    // Un grupo puede actualizarse automáticamente si todas sus materias tienen
    // calificación final o todas están en el mismo período con el mismo estado
    
    $calificacionesFinals = [];
    $ultimosEstados = [];
    
    foreach ($materiasDelGrupo as $materia) {
        if (!empty($materia['calificacion_final'])) {
            $calificacionesFinals[] = $materia['calificacion_final'];
        }
        
        // Obtener último estado evaluado
        $periodos = ['febrero', 'diciembre', 'agosto', 'julio', 'marzo'];
        foreach ($periodos as $periodo) {
            if (!empty($materia[$periodo])) {
                $ultimosEstados[] = $materia[$periodo];
                break;
            }
        }
    }
    
    // Si todas tienen calificación final
    if (count($calificacionesFinals) === count($materiasDelGrupo)) {
        return true;
    }
    
    // Si todas están en el mismo estado en el último período
    if (count($ultimosEstados) === count($materiasDelGrupo) && count(array_unique($ultimosEstados)) === 1) {
        return true;
    }
    
    return false;
}
?>
