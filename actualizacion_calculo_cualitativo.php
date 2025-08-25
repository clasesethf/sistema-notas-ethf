<?php
/**
 * actualizacion_calculo_cualitativo.php
 * Actualización para el sistema de cálculo automático que maneja el nuevo sistema cualitativo
 * 
 * NUEVO SISTEMA CUALITATIVO:
 * - "Acreditado": El estudiante cumplió con el contenido
 * - NULL/"": No acreditado/pendiente (equivale al anterior "No Acreditado")
 * - "No Corresponde": El contenido no aplica para este estudiante
 * 
 * Esta actualización debe integrarse en sistema_calculo_automatico.php
 */

/**
 * Función actualizada para calcular calificaciones cualitativas
 * Debe reemplazar o complementar la función existente en CalculadorCalificaciones
 */
function calcularCalificacionCualitativaMejorada($contenidosCalificaciones) {
    if (empty($contenidosCalificaciones)) {
        return null;
    }
    
    $totalContenidos = 0;
    $contenidosAcreditados = 0;
    $contenidosNoCorresponde = 0;
    
    foreach ($contenidosCalificaciones as $cal) {
        if ($cal['calificacion_cualitativa'] === 'No Corresponde') {
            // No cuenta para el total ni como acreditado
            $contenidosNoCorresponde++;
            continue;
        }
        
        $totalContenidos++;
        
        if ($cal['calificacion_cualitativa'] === 'Acreditado') {
            $contenidosAcreditados++;
        }
        // Si es NULL o vacío, cuenta como "no acreditado" pero sí cuenta para el total
    }
    
    // Si todos los contenidos son "No Corresponde", retornar null
    if ($totalContenidos === 0) {
        return 'No Corresponde';
    }
    
    // Calcular porcentaje de acreditación
    $porcentajeAcreditado = ($contenidosAcreditados / $totalContenidos) * 100;
    
    // Criterio: Si tiene 70% o más de contenidos acreditados, está "Acreditado"
    return $porcentajeAcreditado >= 70 ? 'Acreditado' : 'No Acreditado';
}

/**
 * Función para obtener estadísticas detalladas de calificaciones cualitativas
 */
function obtenerEstadisticasCualitativas($contenidosCalificaciones) {
    $stats = [
        'total_contenidos' => 0,
        'acreditados' => 0,
        'no_acreditados' => 0,
        'no_corresponde' => 0,
        'sin_calificar' => 0,
        'porcentaje_acreditacion' => 0
    ];
    
    foreach ($contenidosCalificaciones as $cal) {
        if ($cal['calificacion_cualitativa'] === 'Acreditado') {
            $stats['acreditados']++;
            $stats['total_contenidos']++;
        } elseif ($cal['calificacion_cualitativa'] === 'No Corresponde') {
            $stats['no_corresponde']++;
        } elseif ($cal['calificacion_cualitativa'] === null || $cal['calificacion_cualitativa'] === '') {
            if ($cal['id']) { // Si existe el registro pero sin calificación
                $stats['sin_calificar']++;
                $stats['total_contenidos']++;
            }
        } else {
            // Cualquier otro valor se considera "no acreditado"
            $stats['no_acreditados']++;
            $stats['total_contenidos']++;
        }
    }
    
    if ($stats['total_contenidos'] > 0) {
        $stats['porcentaje_acreditacion'] = ($stats['acreditados'] / $stats['total_contenidos']) * 100;
    }
    
    return $stats;
}

/**
 * Función para generar texto descriptivo de la calificación cualitativa
 */
function obtenerDescripcionCualitativa($calificacion, $estadisticas = null) {
    switch ($calificacion) {
        case 'Acreditado':
            if ($estadisticas && $estadisticas['porcentaje_acreditacion'] >= 90) {
                return 'Acreditado (Excelente)';
            } elseif ($estadisticas && $estadisticas['porcentaje_acreditacion'] >= 80) {
                return 'Acreditado (Muy Bueno)';
            } else {
                return 'Acreditado';
            }
            
        case 'No Acreditado':
            return 'No Acreditado';
            
        case 'No Corresponde':
            return 'No Corresponde';
            
        default:
            return 'Sin Calificar';
    }
}

/**
 * Función para validar calificaciones cualitativas
 */
function validarCalificacionCualitativa($calificacion) {
    $valoresValidos = ['Acreditado', 'No Corresponde', null, ''];
    return in_array($calificacion, $valoresValidos, true);
}

/**
 * Ejemplo de integración en la clase CalculadorCalificaciones existente
 * Esta función debe agregarse o reemplazar la lógica existente
 */
/*
class CalculadorCalificaciones {
    // ... código existente ...
    
    private function calcularPromedioGeneral($estudiante, $materiaId, $cicloLectivoId) {
        // Obtener todos los contenidos de la materia
        $contenidos = $this->db->fetchAll(
            "SELECT c.*, cc.calificacion_numerica, cc.calificacion_cualitativa
             FROM contenidos c
             LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id AND cc.estudiante_id = ?
             WHERE c.materia_curso_id = ? AND c.activo = 1
             ORDER BY c.bimestre, c.fecha_clase",
            [$estudiante['id'], $materiaId]
        );
        
        if (empty($contenidos)) {
            return null;
        }
        
        // Separar por tipo de evaluación
        $numericos = array_filter($contenidos, function($c) { return $c['tipo_evaluacion'] === 'numerica'; });
        $cualitativos = array_filter($contenidos, function($c) { return $c['tipo_evaluacion'] === 'cualitativa'; });
        
        $promedioNumerico = null;
        $promedioCualitativo = null;
        
        // Calcular promedio numérico (lógica existente)
        if (!empty($numericos)) {
            $calificacionesNumericas = array_filter($numericos, function($c) { 
                return $c['calificacion_numerica'] !== null; 
            });
            
            if (!empty($calificacionesNumericas)) {
                $suma = array_sum(array_column($calificacionesNumericas, 'calificacion_numerica'));
                $promedioNumerico = $suma / count($calificacionesNumericas);
            }
        }
        
        // Calcular promedio cualitativo (NUEVA LÓGICA)
        if (!empty($cualitativos)) {
            $promedioCualitativo = $this->calcularCalificacionCualitativaMejorada($cualitativos);
        }
        
        // Combinar ambos promedios según la lógica de negocio
        return $this->combinarPromedios($promedioNumerico, $promedioCualitativo);
    }
    
    private function calcularCalificacionCualitativaMejorada($contenidosCalificaciones) {
        // Usar la función definida arriba
        return calcularCalificacionCualitativaMejorada($contenidosCalificaciones);
    }
    
    private function combinarPromedios($numerico, $cualitativo) {
        // Lógica para combinar promedios numéricos y cualitativos
        // Esto depende de las reglas de negocio específicas
        
        if ($numerico !== null && $cualitativo !== null) {
            // Si ambos existen, dar prioridad al numérico pero considerar el cualitativo
            if ($cualitativo === 'No Acreditado') {
                return min($numerico, 6); // Limitar la nota si no está acreditado cualitativamente
            }
            return $numerico;
        } elseif ($numerico !== null) {
            return $numerico;
        } elseif ($cualitativo !== null) {
            // Convertir cualitativo a numérico para sistemas mixtos
            switch ($cualitativo) {
                case 'Acreditado': return 7; // Nota mínima de acreditación
                case 'No Acreditado': return 4;
                case 'No Corresponde': return null;
                default: return null;
            }
        }
        
        return null;
    }
}
*/

/**
 * Script SQL para migrar datos existentes (opcional)
 * Ejecutar solo si necesitas convertir "No Acreditado" existentes
 */
/*
-- Migración opcional: convertir "No Acreditado" a NULL para el nuevo sistema
UPDATE contenidos_calificaciones 
SET calificacion_cualitativa = NULL 
WHERE calificacion_cualitativa = 'No Acreditado';

-- Verificar la migración
SELECT 
    calificacion_cualitativa,
    COUNT(*) as cantidad
FROM contenidos_calificaciones 
WHERE calificacion_cualitativa IS NOT NULL OR calificacion_cualitativa = ''
GROUP BY calificacion_cualitativa;
*/
?>