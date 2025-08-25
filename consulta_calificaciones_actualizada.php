<?php
/**
 * Consulta actualizada para estudiantes en calificaciones.php
 * Reemplazar la sección de obtención de estudiantes (línea ~150-200)
 */

if ($tablaRecursadoExiste) {
    // Consulta completa con recursantes y materias liberadas
    $estudiantes = $db->fetchAll(
        "SELECT DISTINCT
            u.id, 
            u.nombre, 
            u.apellido, 
            u.dni,
            CASE 
                WHEN mr.id IS NOT NULL THEN 'R' 
                ELSE 'C' 
            END as tipo_cursada_sugerido,
            CASE 
                WHEN mr.id IS NOT NULL THEN 1 
                ELSE 0 
            END as es_recursante,
            CASE 
                WHEN mr.id IS NOT NULL THEN c_actual.nombre 
                ELSE c.nombre 
            END as curso_referencia,
            CASE 
                WHEN mr.id IS NOT NULL THEN c_actual.anio 
                ELSE c.anio 
            END as anio_referencia
        FROM usuarios u
        LEFT JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
        LEFT JOIN cursos c ON m.curso_id = c.id
        LEFT JOIN materias_recursado mr ON u.id = mr.estudiante_id AND mr.materia_curso_id = ? AND mr.estado = 'activo'
        LEFT JOIN cursos c_actual ON m.curso_id = c_actual.id
        WHERE 
            u.tipo = 'estudiante' 
            AND (
                -- Estudiantes regulares del curso (que NO tengan esta materia liberada)
                (m.curso_id = ? AND mr.id IS NULL AND u.id NOT IN (
                    SELECT DISTINCT mr2.estudiante_id 
                    FROM materias_recursado mr2 
                    WHERE mr2.materia_liberada_id = ? AND mr2.estado = 'activo'
                ))
                OR 
                -- Estudiantes recursando esta materia específica
                (mr.materia_curso_id = ? AND mr.estado = 'activo')
            )
        ORDER BY u.apellido, u.nombre",
        [$materiaSeleccionada, $cursoSeleccionado, $materiaSeleccionada, $materiaSeleccionada]
    );
} else {
    // Consulta simple sin recursantes (por compatibilidad)
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni,
                'C' as tipo_cursada_sugerido,
                0 as es_recursante,
                c.nombre as curso_referencia,
                c.anio as anio_referencia
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         JOIN cursos c ON m.curso_id = c.id
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
         ORDER BY u.apellido, u.nombre",
        [$cursoSeleccionado]
    );
}
?>
