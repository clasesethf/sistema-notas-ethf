-- Script para actualizar base de datos
-- Agregar columna materia_liberada_id a la tabla materias_recursado
ALTER TABLE materias_recursado 
ADD COLUMN materia_liberada_id INTEGER REFERENCES materias_por_curso(id);

-- Crear índice para mejorar performance
CREATE INDEX IF NOT EXISTS idx_materias_recursado_liberada ON materias_recursado(materia_liberada_id);

-- Verificar la estructura actualizada
.schema materias_recursado
