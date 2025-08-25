<?php
/**
 * crear_tablas_contenidos.php - Script para crear las tablas de contenidos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Ejecutar este archivo una sola vez para crear las tablas necesarias
 */

require_once 'config.php';

// Verificar que el usuario sea administrador
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('Solo los administradores pueden ejecutar este script');
}

$db = Database::getInstance();

echo "<h2>Creando tablas para el sistema de contenidos...</h2>";

try {
    // Crear tabla contenidos
    $sql1 = "CREATE TABLE IF NOT EXISTS contenidos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        materia_curso_id INTEGER NOT NULL,
        profesor_id INTEGER NOT NULL,
        bimestre INTEGER NOT NULL CHECK(bimestre BETWEEN 1 AND 4),
        titulo TEXT NOT NULL,
        descripcion TEXT,
        fecha_clase DATE,
        tipo_evaluacion TEXT DEFAULT 'numerica' CHECK(tipo_evaluacion IN ('numerica', 'cualitativa')),
        orden INTEGER DEFAULT 0,
        activo BOOLEAN DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
        FOREIGN KEY (profesor_id) REFERENCES usuarios(id)
    )";
    
    $db->query($sql1);
    echo "<p style='color: green;'>✓ Tabla 'contenidos' creada correctamente</p>";
    
    // Crear tabla contenidos_calificaciones
    $sql2 = "CREATE TABLE IF NOT EXISTS contenidos_calificaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contenido_id INTEGER NOT NULL,
        estudiante_id INTEGER NOT NULL,
        calificacion_numerica DECIMAL(4,2),
        calificacion_cualitativa TEXT CHECK(calificacion_cualitativa IN ('Acreditado', 'No Acreditado', NULL)),
        observaciones TEXT,
        fecha_evaluacion DATE DEFAULT CURRENT_DATE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (contenido_id) REFERENCES contenidos(id) ON DELETE CASCADE,
        FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
        UNIQUE(contenido_id, estudiante_id)
    )";
    
    $db->query($sql2);
    echo "<p style='color: green;'>✓ Tabla 'contenidos_calificaciones' creada correctamente</p>";
    
    // Crear índices
    $indices = [
        "CREATE INDEX IF NOT EXISTS idx_contenidos_materia_bimestre ON contenidos(materia_curso_id, bimestre)",
        "CREATE INDEX IF NOT EXISTS idx_contenidos_profesor ON contenidos(profesor_id)",
        "CREATE INDEX IF NOT EXISTS idx_contenidos_calificaciones_contenido ON contenidos_calificaciones(contenido_id)",
        "CREATE INDEX IF NOT EXISTS idx_contenidos_calificaciones_estudiante ON contenidos_calificaciones(estudiante_id)"
    ];
    
    foreach ($indices as $index) {
        $db->query($index);
    }
    echo "<p style='color: green;'>✓ Índices creados correctamente</p>";
    
    // Crear triggers para updated_at
    $trigger1 = "CREATE TRIGGER IF NOT EXISTS update_contenidos_timestamp 
                 AFTER UPDATE ON contenidos
                 BEGIN
                     UPDATE contenidos SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
                 END";
    
    $db->query($trigger1);
    echo "<p style='color: green;'>✓ Trigger para contenidos creado correctamente</p>";
    
    $trigger2 = "CREATE TRIGGER IF NOT EXISTS update_contenidos_calificaciones_timestamp 
                 AFTER UPDATE ON contenidos_calificaciones
                 BEGIN
                     UPDATE contenidos_calificaciones SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
                 END";
    
    $db->query($trigger2);
    echo "<p style='color: green;'>✓ Trigger para contenidos_calificaciones creado correctamente</p>";
    
    echo "<h3 style='color: green;'>¡Todas las tablas fueron creadas exitosamente!</h3>";
    echo "<p>Ahora puedes <a href='contenidos.php'>ir a la sección de contenidos</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>

<br>
<a href="index.php" class="btn btn-primary">Volver al inicio</a>