<?php
/**
 * sistema_calculo_automatico.php - Sistema de cálculo automático de calificaciones
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * NUEVA LÓGICA:
 * - Bimestres 1 y 3: Generan valoraciones preliminares (TEA/TEP/TED)
 * - Bimestres 2 y 4: Generan calificaciones numéricas del cuatrimestre
 */

class CalculadorCalificaciones {
    
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Calcula y actualiza las calificaciones de un estudiante en una materia
     * basándose en los contenidos calificados
     */
    public function actualizarCalificacionesEstudiante($estudianteId, $materiaCursoId, $cicloLectivoId) {
        try {
            // Primero verificar si hay contenidos calificados
            if ($this->limpiarCalificacionesSinContenidos($estudianteId, $materiaCursoId, $cicloLectivoId)) {
                // No hay contenidos, se limpiaron las calificaciones
                return true;
            }
            
            // Obtener todas las calificaciones de contenidos del estudiante para esta materia
            $calificacionesPorBimestre = $this->obtenerCalificacionesPorBimestre($estudianteId, $materiaCursoId);
            
            // Si no hay calificaciones, limpiar
            if (empty($calificacionesPorBimestre)) {
                $this->limpiarCalificacionesSinContenidos($estudianteId, $materiaCursoId, $cicloLectivoId);
                return true;
            }
            
            // Variables para almacenar resultados
            $valoraciones = [];
            $notasCuatrimestrales = [];
            
            // PRIMER CUATRIMESTRE
            // Bimestre 1: genera valoración preliminar
            if (isset($calificacionesPorBimestre[1]) && count($calificacionesPorBimestre[1]) > 0) {
                $resultado = $this->calcularValoracionBimestre($calificacionesPorBimestre[1]);
                $valoraciones[1] = $resultado['valoracion'];
            }
            
            // Bimestre 2: genera calificación numérica del 1° cuatrimestre
            if (isset($calificacionesPorBimestre[2]) && count($calificacionesPorBimestre[2]) > 0) {
                $resultado = $this->calcularNotaCuatrimestre($calificacionesPorBimestre[2]);
                $notasCuatrimestrales[1] = $resultado['nota'];
            }
            
            // SEGUNDO CUATRIMESTRE
            // Bimestre 3: genera valoración preliminar
            if (isset($calificacionesPorBimestre[3]) && count($calificacionesPorBimestre[3]) > 0) {
                $resultado = $this->calcularValoracionBimestre($calificacionesPorBimestre[3]);
                $valoraciones[3] = $resultado['valoracion'];
            }
            
            // Bimestre 4: genera calificación numérica del 2° cuatrimestre
            if (isset($calificacionesPorBimestre[4]) && count($calificacionesPorBimestre[4]) > 0) {
                $resultado = $this->calcularNotaCuatrimestre($calificacionesPorBimestre[4]);
                $notasCuatrimestrales[2] = $resultado['nota'];
            }
            
            // Las valoraciones preliminares vienen de bimestres 1 y 3
            $valoracionPreliminar1C = $valoraciones[1] ?? null;
            $valoracionPreliminar2C = $valoraciones[3] ?? null;
            
            // Las notas cuatrimestrales vienen de bimestres 2 y 4
            $nota1C = $notasCuatrimestrales[1] ?? null;
            $nota2C = $notasCuatrimestrales[2] ?? null;
            
            // Actualizar en la tabla de calificaciones
            $this->actualizarCalificacionesEnBD(
                $estudianteId, 
                $materiaCursoId, 
                $cicloLectivoId,
                $valoraciones,
                $valoracionPreliminar1C,
                $valoracionPreliminar2C,
                $nota1C,
                $nota2C
            );
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error en actualizarCalificacionesEstudiante: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene todas las calificaciones de contenidos organizadas por bimestre
     */
    private function obtenerCalificacionesPorBimestre($estudianteId, $materiaCursoId) {
        $sql = "SELECT c.bimestre, c.tipo_evaluacion, cc.calificacion_numerica, cc.calificacion_cualitativa
                FROM contenidos c
                JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id
                WHERE cc.estudiante_id = ? AND c.materia_curso_id = ? AND c.activo = 1
                ORDER BY c.bimestre, c.fecha_clase";
        
        $resultados = $this->db->fetchAll($sql, [$estudianteId, $materiaCursoId]);
        
        $calificacionesPorBimestre = [];
        foreach ($resultados as $cal) {
            $calificacionesPorBimestre[$cal['bimestre']][] = $cal;
        }
        
        return $calificacionesPorBimestre;
    }
    
    /**
     * Limpia las calificaciones si no hay contenidos calificados
     */
    public function limpiarCalificacionesSinContenidos($estudianteId, $materiaCursoId, $cicloLectivoId) {
        // Verificar si hay contenidos calificados activos
        $contenidosCalificados = $this->db->fetchOne(
            "SELECT COUNT(*) as total
             FROM contenidos_calificaciones cc
             JOIN contenidos c ON cc.contenido_id = c.id
             WHERE cc.estudiante_id = ? AND c.materia_curso_id = ? AND c.activo = 1",
            [$estudianteId, $materiaCursoId]
        );
        
        if ($contenidosCalificados['total'] == 0) {
            // No hay contenidos calificados, limpiar las calificaciones automáticas
            $this->db->query(
                "UPDATE calificaciones 
                 SET valoracion_1bim = NULL, valoracion_3bim = NULL,
                     valoracion_preliminar_1c = NULL, valoracion_preliminar_2c = NULL,
                     calificacion_1c = NULL, calificacion_2c = NULL,
                     observaciones_automaticas = 'Sin contenidos calificados - ' || datetime('now')
                 WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                [$estudianteId, $materiaCursoId, $cicloLectivoId]
            );
            return true;
        }
        return false;
    }
    
    /**
     * Calcula la valoración de un bimestre (para bimestres 1 y 3)
     * Reglas:
     * - TEA: Todos los contenidos acreditados o con nota >= 7
     * - TEP: Al menos un contenido no acreditado o con nota <= 6
     * - TED: Mayoría de contenidos no acreditados o con notas bajas
     */
    private function calcularValoracionBimestre($calificaciones) {
        $todasAprobadas = true;
        $todasDesaprobadas = true;
        $contadorAprobadas = 0;
        $contadorDesaprobadas = 0;
        $totalContenidos = count($calificaciones);
        
        foreach ($calificaciones as $cal) {
            if ($cal['tipo_evaluacion'] == 'numerica') {
                $nota = floatval($cal['calificacion_numerica']);
                if ($nota >= 7) {
                    $contadorAprobadas++;
                    $todasDesaprobadas = false;
                } else {
                    $contadorDesaprobadas++;
                    $todasAprobadas = false;
                }
            } else {
                // Evaluación cualitativa
                if ($cal['calificacion_cualitativa'] == 'Acreditado') {
                    $contadorAprobadas++;
                    $todasDesaprobadas = false;
                } else if ($cal['calificacion_cualitativa'] == 'No Acreditado') {
                    $contadorDesaprobadas++;
                    $todasAprobadas = false;
                }
            }
        }
        
        // Determinar valoración
        if ($todasAprobadas) {
            $valoracion = 'TEA';
        } else if ($todasDesaprobadas || $contadorDesaprobadas > $contadorAprobadas) {
            $valoracion = 'TED';
        } else {
            $valoracion = 'TEP';
        }
        
        return ['valoracion' => $valoracion];
    }
    
    /**
     * Calcula la nota del cuatrimestre (para bimestres 2 y 4)
     * Reglas:
     * - Si todo está acreditado y notas >= 7: promedio
     * - Si hay algún no acreditado o nota <= 6: nota más baja (mínimo 6 si hay no acreditado)
     */
    private function calcularNotaCuatrimestre($calificaciones) {
        $notas = [];
        $notaMasBaja = 10;
        $hayNoAcreditado = false;
        $hayNotasBajas = false;
        
        foreach ($calificaciones as $cal) {
            if ($cal['tipo_evaluacion'] == 'numerica') {
                $nota = floatval($cal['calificacion_numerica']);
                if ($nota >= 1 && $nota <= 10) {
                    $notas[] = $nota;
                    
                    if ($nota < $notaMasBaja) {
                        $notaMasBaja = $nota;
                    }
                    
                    if ($nota <= 6) {
                        $hayNotasBajas = true;
                    }
                }
            } else {
                // Evaluación cualitativa
                if ($cal['calificacion_cualitativa'] == 'No Acreditado') {
                    $hayNoAcreditado = true;
                    // Si hay un no acreditado, considerar como nota 6
                    if (6 < $notaMasBaja) {
                        $notaMasBaja = 6;
                    }
                }
            }
        }
        
        // Calcular nota final
        $notaFinal = null;
        
        // Si hay algún "No Acreditado", la nota máxima es 6
        if ($hayNoAcreditado) {
            // Si todas las notas numéricas son >= 7 pero hay un no acreditado, poner 6
            if (!$hayNotasBajas && count($notas) > 0 && min($notas) >= 7) {
                $notaFinal = 6;
            } else {
                // Si hay notas bajas, usar la más baja (pero nunca menos de lo que ya calculamos)
                $notaFinal = min($notaMasBaja, 6);
            }
        } else if ($hayNotasBajas) {
            // Si hay notas <= 6, usar la más baja
            $notaFinal = $notaMasBaja;
        } else if (count($notas) > 0) {
            // Si todas las notas son >= 7 y todo está acreditado, promediar
            $notaFinal = round(array_sum($notas) / count($notas), 2);
        }
        
        // Asegurar que la nota esté en el rango válido
        if ($notaFinal !== null && ($notaFinal < 1 || $notaFinal > 10)) {
            $notaFinal = null;
        }
        
        return ['nota' => $notaFinal];
    }
    
    /**
     * Actualiza las calificaciones en la base de datos
     */
    private function actualizarCalificacionesEnBD($estudianteId, $materiaCursoId, $cicloLectivoId, 
                                                  $valoraciones, $valoracion1C, $valoracion2C, 
                                                  $nota1C, $nota2C) {
        
        // Verificar si ya existe una calificación
        $calificacionExistente = $this->db->fetchOne(
            "SELECT id FROM calificaciones 
             WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
            [$estudianteId, $materiaCursoId, $cicloLectivoId]
        );
        
        if ($calificacionExistente) {
            // Actualizar calificación existente
            $sql = "UPDATE calificaciones SET ";
            $campos = [];
            $valores = [];
            
            // Actualizar valoraciones bimestrales (solo 1 y 3)
            if (isset($valoraciones[1])) {
                $campos[] = "valoracion_1bim = ?";
                $valores[] = $valoraciones[1];
            }
            if (isset($valoraciones[3])) {
                $campos[] = "valoracion_3bim = ?";
                $valores[] = $valoraciones[3];
            }
            
            // Actualizar valoraciones preliminares
            if ($valoracion1C !== null) {
                $campos[] = "valoracion_preliminar_1c = ?";
                $valores[] = $valoracion1C;
            }
            if ($valoracion2C !== null) {
                $campos[] = "valoracion_preliminar_2c = ?";
                $valores[] = $valoracion2C;
            }
            
            // Actualizar notas cuatrimestrales
            if ($nota1C !== null) {
                $campos[] = "calificacion_1c = ?";
                $valores[] = $nota1C;
            }
            if ($nota2C !== null) {
                $campos[] = "calificacion_2c = ?";
                $valores[] = $nota2C;
            }
            
            // Agregar observación automática explicativa
            $campos[] = "observaciones_automaticas = ?";
            $observacion = "Cálculo automático: ";
            $observacion .= "Valoraciones (TEA/TEP/TED) del 1° y 3° bimestre. ";
            $observacion .= "Calificaciones numéricas del 2° y 4° bimestre. ";
            $observacion .= "Actualizado: " . date('d/m/Y H:i');
            $valores[] = $observacion;
            
            if (count($campos) > 0) {
                $sql .= implode(", ", $campos) . " WHERE id = ?";
                $valores[] = $calificacionExistente['id'];
                
                $this->db->query($sql, $valores);
            }
            
        } else {
            // Crear nueva calificación
            $this->db->insert(
                "INSERT INTO calificaciones (
                    estudiante_id, materia_curso_id, ciclo_lectivo_id,
                    valoracion_1bim, valoracion_3bim,
                    valoracion_preliminar_1c, valoracion_preliminar_2c,
                    calificacion_1c, calificacion_2c,
                    observaciones_automaticas
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $estudianteId, $materiaCursoId, $cicloLectivoId,
                    $valoraciones[1] ?? null, $valoraciones[3] ?? null,
                    $valoracion1C, $valoracion2C,
                    $nota1C, $nota2C,
                    "Calificación calculada automáticamente desde contenidos el " . date('d/m/Y H:i')
                ]
            );
        }
    }
    
    /**
     * Actualiza las calificaciones de todos los estudiantes de una materia
     */
    public function actualizarCalificacionesMateria($materiaCursoId, $cicloLectivoId) {
        // Obtener todos los estudiantes de la materia
        $estudiantes = $this->db->fetchAll(
            "SELECT DISTINCT u.id 
             FROM usuarios u
             JOIN matriculas m ON u.id = m.estudiante_id
             JOIN materias_por_curso mp ON m.curso_id = mp.curso_id
             WHERE mp.id = ? AND m.estado = 'activo'",
            [$materiaCursoId]
        );
        
        $actualizados = 0;
        foreach ($estudiantes as $estudiante) {
            if ($this->actualizarCalificacionesEstudiante($estudiante['id'], $materiaCursoId, $cicloLectivoId)) {
                $actualizados++;
            }
        }
        
        return $actualizados;
    }
}
?>