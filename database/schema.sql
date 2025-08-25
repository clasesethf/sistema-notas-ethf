-- schema.sql
-- Esquema de base de datos para el Sistema de Gestión de Calificaciones ETHS
-- Basado en la Resolución N° 1650/24

-- Eliminar tablas si existen para facilitar la recreación
DROP TABLE IF EXISTS notificaciones;
DROP TABLE IF EXISTS intensificaciones;
DROP TABLE IF EXISTS calificaciones;
DROP TABLE IF EXISTS asistencias;
DROP TABLE IF EXISTS matriculas;
DROP TABLE IF EXISTS materias_por_curso;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS materias;
DROP TABLE IF EXISTS cursos;
DROP TABLE IF EXISTS ciclos_lectivos;

-- Tabla de ciclos lectivos
CREATE TABLE ciclos_lectivos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    anio INTEGER NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    activo BOOLEAN DEFAULT 0,
    UNIQUE(anio)
);

-- Tabla de cursos (años escolares)
CREATE TABLE cursos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre VARCHAR(50) NOT NULL,
    anio INTEGER NOT NULL, -- 1 a 7
    ciclo_lectivo_id INTEGER NOT NULL,
    FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id),
    UNIQUE(anio, ciclo_lectivo_id)
);

-- Tabla de materias
CREATE TABLE materias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre VARCHAR(100) NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    UNIQUE(codigo)
);

-- Tabla de relación entre materias y cursos
CREATE TABLE materias_por_curso (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    materia_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    FOREIGN KEY (materia_id) REFERENCES materias(id),
    FOREIGN KEY (curso_id) REFERENCES cursos(id),
    UNIQUE(materia_id, curso_id)
);

-- Tabla de usuarios
CREATE TABLE usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    dni VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    telefono VARCHAR(50),
    direccion TEXT,
    contrasena VARCHAR(255) NOT NULL,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('admin', 'directivo', 'profesor', 'preceptor', 'estudiante', 'responsable')),
    activo BOOLEAN DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(dni, tipo)
);

-- Tabla de matrículas (relación entre estudiantes y cursos)
CREATE TABLE matriculas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    fecha_matriculacion DATE NOT NULL,
    estado VARCHAR(20) DEFAULT 'activo' CHECK (estado IN ('activo', 'baja', 'egresado')),
    FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
    FOREIGN KEY (curso_id) REFERENCES cursos(id),
    UNIQUE(estudiante_id, curso_id)
);

-- Tabla de asistencias
CREATE TABLE asistencias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER NOT NULL,
    curso_id INTEGER NOT NULL,
    fecha DATE NOT NULL,
    estado VARCHAR(20) NOT NULL CHECK (estado IN ('presente', 'ausente', 'media_falta', 'justificada')),
    cuatrimestre INTEGER NOT NULL CHECK (cuatrimestre IN (1, 2)),
    observaciones TEXT,
    FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
    FOREIGN KEY (curso_id) REFERENCES cursos(id),
    UNIQUE(estudiante_id, curso_id, fecha)
);

-- Tabla de calificaciones
CREATE TABLE calificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER NOT NULL,
    materia_curso_id INTEGER NOT NULL,
    ciclo_lectivo_id INTEGER NOT NULL,
    
    -- Valoración preliminar 1er cuatrimestre
    valoracion_preliminar_1c VARCHAR(20) CHECK (valoracion_preliminar_1c IN ('TEA', 'TEP', 'TED')),
    
    -- Calificación 1er cuatrimestre (1-10)
    calificacion_1c INTEGER CHECK (calificacion_1c >= 1 AND calificacion_1c <= 10),
    
    -- Valoración preliminar 2do cuatrimestre
    valoracion_preliminar_2c VARCHAR(20) CHECK (valoracion_preliminar_2c IN ('TEA', 'TEP', 'TED')),
    
    -- Calificación 2do cuatrimestre (1-10)
    calificacion_2c INTEGER CHECK (calificacion_2c >= 1 AND calificacion_2c <= 10),
    
    -- Intensificación 1er cuatrimestre
    intensificacion_1c INTEGER CHECK (intensificacion_1c >= 1 AND intensificacion_1c <= 10),
    
    -- Calificación final (1-10)
    calificacion_final INTEGER CHECK (calificacion_final >= 1 AND calificacion_final <= 10),
    
    -- Tipo de cursada (C: cursada por primera vez, R: recursada)
    tipo_cursada VARCHAR(1) DEFAULT 'C' CHECK (tipo_cursada IN ('C', 'R')),
    
    -- Observaciones sobre saberes pendientes
    observaciones TEXT,
    
    -- Estado final de la materia
    estado_final VARCHAR(20) DEFAULT 'pendiente' CHECK (estado_final IN ('aprobada', 'pendiente')),
    
    FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
    FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
    FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id),
    UNIQUE(estudiante_id, materia_curso_id, ciclo_lectivo_id)
);

-- Tabla de intensificaciones para materias pendientes
CREATE TABLE intensificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER NOT NULL,
    materia_id INTEGER NOT NULL,
    ciclo_lectivo_id INTEGER NOT NULL,
    ciclo_lectivo_cursada_id INTEGER NOT NULL, -- Ciclo en que se cursó originalmente
    
    -- Modelo de intensificación (1, 2, 3, 4)
    modelo_intensificacion INTEGER NOT NULL CHECK (modelo_intensificacion >= 1 AND modelo_intensificacion <= 4),
    
    -- Estados en cada período de intensificación
    estado_marzo VARCHAR(3) CHECK (estado_marzo IN ('AA', 'CCA', 'CSA')),
    calificacion_marzo INTEGER CHECK (calificacion_marzo >= 4 AND calificacion_marzo <= 10),
    
    estado_julio VARCHAR(3) CHECK (estado_julio IN ('AA', 'CCA', 'CSA')),
    calificacion_julio INTEGER CHECK (calificacion_julio >= 4 AND calificacion_julio <= 10),
    
    estado_agosto VARCHAR(3) CHECK (estado_agosto IN ('AA', 'CCA', 'CSA')),
    calificacion_agosto INTEGER CHECK (calificacion_agosto >= 4 AND calificacion_agosto <= 10),
    
    estado_diciembre VARCHAR(3) CHECK (estado_diciembre IN ('AA', 'CCA', 'CSA')),
    calificacion_diciembre INTEGER CHECK (calificacion_diciembre >= 4 AND calificacion_diciembre <= 10),
    
    estado_febrero VARCHAR(3) CHECK (estado_febrero IN ('AA', 'CCA', 'CSA')),
    calificacion_febrero INTEGER CHECK (calificacion_febrero >= 4 AND calificacion_febrero <= 10),
    
    -- Calificación final (4-10 si aprobó, 1-3 si no aprobó)
    calificacion_final INTEGER CHECK (calificacion_final >= 1 AND calificacion_final <= 10),
    
    -- Saberes pendientes al inicio
    saberes_pendientes_inicial TEXT NOT NULL,
    
    -- Saberes pendientes al cierre (si no aprobó)
    saberes_pendientes_final TEXT,
    
    FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
    FOREIGN KEY (materia_id) REFERENCES materias(id),
    FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id),
    FOREIGN KEY (ciclo_lectivo_cursada_id) REFERENCES ciclos_lectivos(id),
    UNIQUE(estudiante_id, materia_id, ciclo_lectivo_id)
);

-- Tabla de notificaciones de boletines
CREATE TABLE notificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    estudiante_id INTEGER NOT NULL,
    ciclo_lectivo_id INTEGER NOT NULL,
    cuatrimestre INTEGER NOT NULL CHECK (cuatrimestre IN (1, 2)),
    fecha_generacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_notificacion TIMESTAMP,
    firma_responsable BOOLEAN DEFAULT 0,
    firma_estudiante BOOLEAN DEFAULT 0,
    observaciones TEXT,
    FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
    FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id),
    UNIQUE(estudiante_id, ciclo_lectivo_id, cuatrimestre)
);

-- Insertar ciclo lectivo actual
INSERT INTO ciclos_lectivos (anio, fecha_inicio, fecha_fin, activo)
VALUES (2025, '2025-03-03', '2025-12-20', 1);

-- Insertar algunos cursos para el ciclo lectivo actual
INSERT INTO cursos (nombre, anio, ciclo_lectivo_id)
VALUES 
('1° Año', 1, 1),
('2° Año', 2, 1),
('3° Año', 3, 1),
('4° Año', 4, 1),
('5° Año', 5, 1),
('6° Año', 6, 1),
('7° Año', 7, 1);

-- Insertar algunas materias comunes
INSERT INTO materias (nombre, codigo)
VALUES 
('Matemática', 'MATAA'),
('Lengua y Literatura', 'LENAA'),
('Historia', 'HISAA'),
('Geografía', 'GEOAA'),
('Biología', 'BIOAA'),
('Física', 'FISAA'),
('Química', 'QUIAA'),
('Educación Física', 'EFIAA'),
('Educación Tecnológica', 'TECAA'),
('Inglés', 'INGAA');

-- Vincular materias a cursos
-- 1° Año
INSERT INTO materias_por_curso (materia_id, curso_id)
VALUES 
(1, 1), -- Matemática en 1° Año
(2, 1), -- Lengua en 1° Año
(3, 1), -- Historia en 1° Año
(4, 1), -- Geografía en 1° Año
(5, 1), -- Biología en 1° Año
(8, 1), -- Educación Física en 1° Año
(9, 1), -- Educación Tecnológica en 1° Año
(10, 1); -- Inglés en 1° Año

-- 2° Año (solo algunas materias como ejemplo)
INSERT INTO materias_por_curso (materia_id, curso_id)
VALUES 
(1, 2), -- Matemática en 2° Año
(2, 2), -- Lengua en 2° Año
(3, 2), -- Historia en 2° Año
(4, 2), -- Geografía en 2° Año
(5, 2), -- Biología en 2° Año
(8, 2), -- Educación Física en 2° Año
(9, 2), -- Educación Tecnológica en 2° Año
(10, 2); -- Inglés en 2° Año

-- Insertar usuario administrador
INSERT INTO usuarios (nombre, apellido, dni, email, contrasena, tipo)
VALUES ('Admin', 'Sistema', '00000000', 'admin@escuela.edu.ar', 'admin123', 'admin');

-- Insertar algunos estudiantes de ejemplo
INSERT INTO usuarios (nombre, apellido, dni, email, telefono, direccion, contrasena, tipo)
VALUES 
('Juan', 'Pérez', '40123456', 'juan@estudiante.edu.ar', '1155667788', 'Av. Siempreviva 123', 'estudiante123', 'estudiante'),
('Ana', 'Gómez', '41234567', 'ana@estudiante.edu.ar', '1155889900', 'Calle Falsa 123', 'estudiante123', 'estudiante'),
('Carlos', 'López', '42345678', 'carlos@estudiante.edu.ar', '1155001122', 'Av. Libertador 1234', 'estudiante123', 'estudiante');

-- Matricular estudiantes en cursos
INSERT INTO matriculas (estudiante_id, curso_id, fecha_matriculacion)
VALUES 
(2, 1, '2025-03-01'), -- Juan en 1° Año
(3, 2, '2025-03-01'), -- Ana en 2° Año
(4, 3, '2025-03-01'); -- Carlos en 3° Año

-- Insertar algunos profesores de ejemplo
INSERT INTO usuarios (nombre, apellido, dni, email, contrasena, tipo)
VALUES 
('María', 'Rodríguez', '30123456', 'maria@profesor.edu.ar', 'profesor123', 'profesor'),
('Roberto', 'García', '31234567', 'roberto@profesor.edu.ar', 'profesor123', 'profesor');

-- Insertar un preceptor
INSERT INTO usuarios (nombre, apellido, dni, email, contrasena, tipo)
VALUES ('Laura', 'Fernández', '35123456', 'laura@preceptor.edu.ar', 'preceptor123', 'preceptor');