<?php
// reporte_contenido_curso.php

// IMPORTANTE: Ajusta estas rutas según tu configuración
// Opción 1: Ruta absoluta
// $database_path = '/home/usuario/proyecto/database/database.sqlite';

// Opción 2: Ruta relativa desde el script actual
// $database_path = '../database/database.sqlite';

// Opción 3: Para Laravel (ajusta según tu estructura)
$database_path = __DIR__ . '/../database/calificaciones.db';

// Verificar si el archivo existe y es accesible
if (!file_exists($database_path)) {
    die("Error: El archivo de base de datos no existe en: $database_path");
}

if (!is_readable($database_path)) {
    die("Error: No se puede leer el archivo de base de datos. Verifica los permisos.");
}

if (!is_writable($database_path)) {
    echo "Advertencia: El archivo de base de datos no es escribible. Solo podrás leer datos.<br>";
}

// Verificar permisos del directorio
$db_directory = dirname($database_path);
if (!is_writable($db_directory)) {
    echo "Advertencia: El directorio de la base de datos no es escribible.<br>";
}

try {
    // Conectar a SQLite con opciones adicionales
    $pdo = new PDO("sqlite:$database_path");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Mostrar información de debug
    echo "<p>Conexión exitosa a la base de datos.</p>";
    echo "<p>Ruta de la base de datos: " . realpath($database_path) . "</p>";
    
    // Obtener el curso_id
    $curso_id = isset($_GET['curso_id']) ? $_GET['curso_id'] : 1;
    
    // Consulta corregida para SQLite
    $sql = "SELECT mp.id, m.nombre, m.codigo, 
                   u.apellido || ', ' || u.nombre as profesor 
            FROM materias_por_curso mp 
            JOIN materias m ON mp.materia_id = m.id 
            LEFT JOIN usuarios u ON mp.profesor_id = u.id 
            WHERE mp.curso_id = ? 
            ORDER BY m.nombre";
    
    // Preparar y ejecutar la consulta
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$curso_id]);
    
    // Obtener los resultados
    $materias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generar el reporte
    echo "<h1>Reporte de Contenido del Curso</h1>";
    echo "<p>Curso ID: " . htmlspecialchars($curso_id) . "</p>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Materia</th>";
    echo "<th>Código</th>";
    echo "<th>Profesor</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    if (count($materias) > 0) {
        foreach ($materias as $materia) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($materia['id']) . "</td>";
            echo "<td>" . htmlspecialchars($materia['nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($materia['codigo']) . "</td>";
            echo "<td>" . htmlspecialchars($materia['profesor'] ?? 'Sin asignar') . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='4'>No se encontraron materias para este curso.</td></tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
} catch (PDOException $e) {
    echo "Error en la consulta: " . $e->getMessage() . "<br>";
    echo "Código de error: " . $e->getCode() . "<br>";
    
    // Información adicional de debug
    echo "<h3>Información de Debug:</h3>";
    echo "<ul>";
    echo "<li>Ruta intentada: $database_path</li>";
    echo "<li>Ruta absoluta: " . realpath($database_path) . "</li>";
    echo "<li>Directorio actual: " . getcwd() . "</li>";
    echo "<li>Usuario PHP: " . get_current_user() . "</li>";
    echo "</ul>";
}

// Script para diagnosticar problemas de permisos
echo "<h3>Diagnóstico de Permisos:</h3>";
echo "<pre>";
if (file_exists($database_path)) {
    $perms = fileperms($database_path);
    echo "Permisos del archivo: " . substr(sprintf('%o', $perms), -4) . "\n";
    echo "Propietario: " . fileowner($database_path) . "\n";
    echo "Grupo: " . filegroup($database_path) . "\n";
} else {
    echo "El archivo no existe en la ruta especificada.\n";
}
echo "</pre>";

// Para Laravel - usar la configuración de la base de datos
/*
// En tu controlador de Laravel
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

public function reporteContenido($cursoId)
{
    try {
        // Obtener la ruta de la base de datos desde la configuración
        $database_path = Config::get('database.connections.sqlite.database');
        
        if (!file_exists($database_path)) {
            return back()->with('error', 'No se encuentra el archivo de base de datos');
        }
        
        $materias = DB::select("
            SELECT mp.id, m.nombre, m.codigo, 
                   u.apellido || ', ' || u.nombre as profesor 
            FROM materias_por_curso mp 
            JOIN materias m ON mp.materia_id = m.id 
            LEFT JOIN usuarios u ON mp.profesor_id = u.id 
            WHERE mp.curso_id = ? 
            ORDER BY m.nombre
        ", [$cursoId]);
        
        return view('reportes.contenido_curso', [
            'materias' => $materias,
            'curso_id' => $cursoId
        ]);
        
    } catch (\Exception $e) {
        return back()->with('error', 'Error al generar el reporte: ' . $e->getMessage());
    }
}
*/

// Función para encontrar automáticamente el archivo database.sqlite
function buscarBaseDatos() {
    $posibles_rutas = [
        __DIR__ . '/database.sqlite',
        __DIR__ . '/../database.sqlite',
        __DIR__ . '/../database/database.sqlite',
        __DIR__ . '/../../database/database.sqlite',
        $_SERVER['DOCUMENT_ROOT'] . '/database/database.sqlite',
        dirname($_SERVER['SCRIPT_FILENAME']) . '/database.sqlite',
    ];
    
    foreach ($posibles_rutas as $ruta) {
        if (file_exists($ruta)) {
            return realpath($ruta);
        }
    }
    
    return false;
}

// Intentar encontrar la base de datos automáticamente
/*
$database_path = buscarBaseDatos();
if (!$database_path) {
    die("No se pudo encontrar el archivo database.sqlite en las rutas comunes.");
}
*/
?>

