<?php
/**
 * verificar_bd.php - Script para verificar y actualizar estructura de base de datos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Solo ejecutar desde línea de comandos o admin
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'admin') {
    die('Solo administradores pueden ejecutar este script');
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

echo "=== VERIFICACIÓN Y ACTUALIZACIÓN DE BASE DE DATOS ===\n\n";

try {
    // Verificar si existe la columna profesor_id en materias_por_curso
    $columns = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
    $hasProfesorId = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'profesor_id') {
            $hasProfesorId = true;
            break;
        }
    }
    
    if (!$hasProfesorId) {
        echo "Agregando columna profesor_id a materias_por_curso...\n";
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id INTEGER");
        echo "✓ Columna profesor_id agregada exitosamente\n\n";
    } else {
        echo "✓ Columna profesor_id ya existe en materias_por_curso\n\n";
    }
    
    // Verificar si existe la columna created_at en usuarios
    $userColumns = $db->fetchAll("PRAGMA table_info(usuarios)");
    $hasCreatedAt = false;
    
    foreach ($userColumns as $column) {
        if ($column['name'] === 'created_at') {
            $hasCreatedAt = true;
            break;
        }
    }
    
    if (!$hasCreatedAt) {
        echo "Agregando columna created_at a usuarios...\n";
        $db->query("ALTER TABLE usuarios ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
        echo "✓ Columna created_at agregada exitosamente\n\n";
    } else {
        echo "✓ Columna created_at ya existe en usuarios\n\n";
    }
    
    // Verificar índices importantes
    echo "Verificando índices...\n";
    
    // Índice para materias_por_curso
    try {
        $db->query("CREATE INDEX IF NOT EXISTS idx_materias_por_curso_profesor ON materias_por_curso(profesor_id)");
        echo "✓ Índice idx_materias_por_curso_profesor creado/verificado\n";
    } catch (Exception $e) {
        echo "⚠ Error creando índice: " . $e->getMessage() . "\n";
    }
    
    // Índice para calificaciones
    try {
        $db->query("CREATE INDEX IF NOT EXISTS idx_calificaciones_estudiante_materia ON calificaciones(estudiante_id, materia_curso_id)");
        echo "✓ Índice idx_calificaciones_estudiante_materia creado/verificado\n";
    } catch (Exception $e) {
        echo "⚠ Error creando índice: " . $e->getMessage() . "\n";
    }
    
    // Verificar que existe el usuario admin por defecto
    echo "\nVerificando usuario administrador...\n";
    $adminUser = $db->fetchOne("SELECT * FROM usuarios WHERE tipo = 'admin' AND dni = '00000000'");
    
    if (!$adminUser) {
        echo "Creando usuario administrador por defecto...\n";
        $db->query(
            "INSERT INTO usuarios (nombre, apellido, dni, contrasena, tipo, activo) VALUES (?, ?, ?, ?, ?, ?)",
            ['Administrador', 'Sistema', '00000000', 'admin123', 'admin', 1]
        );
        echo "✓ Usuario administrador creado: DNI 00000000, contraseña: admin123\n";
    } else {
        echo "✓ Usuario administrador ya existe\n";
    }
    
    // Verificar ciclo lectivo activo
    echo "\nVerificando ciclo lectivo...\n";
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        echo "Creando ciclo lectivo para " . date('Y') . "...\n";
        $db->query(
            "INSERT INTO ciclos_lectivos (anio, fecha_inicio, fecha_fin, activo) VALUES (?, ?, ?, ?)",
            [date('Y'), date('Y') . '-03-01', date('Y') . '-12-31', 1]
        );
        echo "✓ Ciclo lectivo " . date('Y') . " creado exitosamente\n";
    } else {
        echo "✓ Ciclo lectivo activo encontrado: " . $cicloActivo['anio'] . "\n";
    }
    
    // Mostrar estadísticas actuales
    echo "\n=== ESTADÍSTICAS ACTUALES ===\n";
    $stats = [
        'usuarios' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios")['count'],
        'profesores' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo = 'profesor'")['count'],
        'estudiantes' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo = 'estudiante'")['count'],
        'materias' => $db->fetchOne("SELECT COUNT(*) as count FROM materias")['count'],
        'cursos' => $db->fetchOne("SELECT COUNT(*) as count FROM cursos")['count'],
        'asignaciones' => $db->fetchOne("SELECT COUNT(*) as count FROM materias_por_curso")['count']
    ];
    
    foreach ($stats as $tipo => $cantidad) {
        echo "- " . ucfirst($tipo) . ": $cantidad\n";
    }
    
    echo "\n✅ Verificación completada exitosamente\n";
    
} catch (Exception $e) {
    echo "❌ Error durante la verificación: " . $e->getMessage() . "\n";
}

// Si se ejecuta desde web, mostrar mensaje
if (isset($_SERVER['HTTP_HOST'])) {
    echo "<br><a href='index.php'>Volver al inicio</a>";
}
?>