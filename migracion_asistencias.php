<?php
/**
 * migracion_asistencias.php - Script de migración para mejorar el sistema de asistencias
 * Ejecutar una sola vez para agregar las nuevas funcionalidades
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión y permisos
session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die("Solo los administradores pueden ejecutar este script de migración.");
}

$db = Database::getInstance();
$resultados = [];
$errores = [];

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Migración Sistema de Asistencias</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body>
<div class='container mt-5'>
    <h2>Migración del Sistema de Asistencias</h2>
    <p class='text-muted'>Implementando mejoras: estado '1/4 de falta' y campo 'motivo_falta'</p>
    <hr>";

// Función para ejecutar SQL de forma segura
function ejecutarSQL($db, $sql, $descripcion) {
    global $resultados, $errores;
    
    try {
        $db->query($sql);
        $resultados[] = "✓ $descripcion";
        echo "<div class='alert alert-success'>✓ $descripcion</div>";
        return true;
    } catch (Exception $e) {
        $errores[] = "✗ Error en $descripcion: " . $e->getMessage();
        echo "<div class='alert alert-danger'>✗ Error en $descripcion: " . $e->getMessage() . "</div>";
        return false;
    }
}

// 1. Verificar estructura actual de la tabla asistencias
echo "<h4>1. Verificando estructura actual de la tabla asistencias</h4>";

try {
    $columnas = $db->fetchAll("PRAGMA table_info(asistencias)");
    $columnasExistentes = array_column($columnas, 'name');
    
    echo "<div class='alert alert-info'>";
    echo "<strong>Columnas actuales:</strong> " . implode(', ', $columnasExistentes);
    echo "</div>";
    
    // Verificar si ya existe la columna motivo_falta
    $motivoFaltaExiste = in_array('motivo_falta', $columnasExistentes);
    
    if ($motivoFaltaExiste) {
        echo "<div class='alert alert-warning'>La columna 'motivo_falta' ya existe en la tabla.</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error al verificar estructura: " . $e->getMessage() . "</div>";
    exit;
}

// 2. Agregar columna motivo_falta si no existe
echo "<h4>2. Agregando columna motivo_falta</h4>";

if (!$motivoFaltaExiste) {
    ejecutarSQL(
        $db, 
        "ALTER TABLE asistencias ADD COLUMN motivo_falta TEXT DEFAULT NULL",
        "Columna 'motivo_falta' agregada exitosamente"
    );
} else {
    echo "<div class='alert alert-info'>La columna 'motivo_falta' ya existe, omitiendo...</div>";
}

// 3. Crear índices para optimizar consultas
echo "<h4>3. Creando índices para optimizar consultas</h4>";

ejecutarSQL(
    $db,
    "CREATE INDEX IF NOT EXISTS idx_asistencias_fecha_curso ON asistencias(fecha, curso_id)",
    "Índice por fecha y curso creado"
);

ejecutarSQL(
    $db,
    "CREATE INDEX IF NOT EXISTS idx_asistencias_estudiante_fecha ON asistencias(estudiante_id, fecha)",
    "Índice por estudiante y fecha creado"
);

ejecutarSQL(
    $db,
    "CREATE INDEX IF NOT EXISTS idx_asistencias_estado ON asistencias(estado)",
    "Índice por estado creado"
);

// 4. Verificar estados existentes en la tabla
echo "<h4>4. Verificando estados de asistencia existentes</h4>";

try {
    $estadosExistentes = $db->fetchAll(
        "SELECT DISTINCT estado, COUNT(*) as cantidad FROM asistencias GROUP BY estado ORDER BY cantidad DESC"
    );
    
    if (!empty($estadosExistentes)) {
        echo "<div class='alert alert-info'>";
        echo "<strong>Estados actualmente en uso:</strong><br>";
        foreach ($estadosExistentes as $estado) {
            echo "- {$estado['estado']}: {$estado['cantidad']} registros<br>";
        }
        echo "</div>";
    } else {
        echo "<div class='alert alert-info'>No hay registros de asistencia en la base de datos.</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-warning'>No se pudieron verificar los estados existentes: " . $e->getMessage() . "</div>";
}

// 5. Crear función de actualización para archivos PHP (solo mostrar instrucciones)
echo "<h4>5. Archivos que deben ser actualizados</h4>";

$archivosActualizar = [
    'asistencias.php' => 'Formulario principal de registro de asistencias',
    'dashboard_asistencias.php' => 'Panel de estadísticas (actualizar gráficos)',
    'estadisticas_asistencia.php' => 'Clase de estadísticas (soporte para cuarto_falta)',
    'generar_reporte_asistencia.php' => 'Generador de reportes PDF individuales',
    'generar_reporte_asistencia_curso.php' => 'Generador de reportes PDF por curso'
];

echo "<div class='alert alert-warning'>";
echo "<strong>IMPORTANTE:</strong> Los siguientes archivos PHP deben ser reemplazados con las versiones mejoradas:<br><br>";
foreach ($archivosActualizar as $archivo => $descripcion) {
    echo "- <strong>$archivo</strong>: $descripcion<br>";
}
echo "<br>Los archivos mejorados han sido generados y deben ser copiados manualmente.";
echo "</div>";

// 6. Verificar configuración final
echo "<h4>6. Verificación final</h4>";

try {
    $columnasFinales = $db->fetchAll("PRAGMA table_info(asistencias)");
    $columnasNombres = array_column($columnasFinales, 'name');
    
    $columnasRequeridas = ['id', 'estudiante_id', 'curso_id', 'fecha', 'estado', 'cuatrimestre', 'observaciones', 'motivo_falta'];
    $columnasFaltantes = array_diff($columnasRequeridas, $columnasNombres);
    
    if (empty($columnasFaltantes)) {
        echo "<div class='alert alert-success'>";
        echo "<strong>✓ Migración completada exitosamente!</strong><br>";
        echo "La tabla 'asistencias' ahora incluye todas las columnas necesarias.";
        echo "</div>";
    } else {
        echo "<div class='alert alert-danger'>";
        echo "<strong>✗ Migración incompleta</strong><br>";
        echo "Faltan las siguientes columnas: " . implode(', ', $columnasFaltantes);
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error en verificación final: " . $e->getMessage() . "</div>";
}

// 7. Documentación de los nuevos estados
echo "<h4>7. Documentación de Estados de Asistencia</h4>";

echo "<div class='card'>";
echo "<div class='card-body'>";
echo "<h5>Estados soportados después de la migración:</h5>";
echo "<ul>";
echo "<li><strong>presente</strong>: El estudiante asistió normalmente a clase (0 faltas)</li>";
echo "<li><strong>cuarto_falta</strong>: Llegada tardía menor o retiro temprano menor (0.25 faltas) - <span class='badge bg-success'>NUEVO</span></li>";
echo "<li><strong>media_falta</strong>: Llegada tardía mayor o retiro temprano significativo (0.5 faltas)</li>";
echo "<li><strong>ausente</strong>: El estudiante no asistió a clase (1 falta completa)</li>";
echo "<li><strong>justificada</strong>: Inasistencia justificada con motivo (requiere motivo_falta)</li>";
echo "</ul>";

echo "<h5>Nuevos campos:</h5>";
echo "<ul>";
echo "<li><strong>motivo_falta</strong>: Campo de texto para especificar el motivo cuando el estado es 'justificada' - <span class='badge bg-success'>NUEVO</span></li>";
echo "</ul>";
echo "</div>";
echo "</div>";

// Resumen final
echo "<h4>Resumen de la Migración</h4>";

if (count($errores) == 0) {
    echo "<div class='alert alert-success'>";
    echo "<strong>🎉 Migración completada exitosamente!</strong><br>";
    echo "Total de operaciones realizadas: " . count($resultados) . "<br>";
    echo "Errores: 0<br><br>";
    echo "<strong>Próximos pasos:</strong><br>";
    echo "1. Reemplazar los archivos PHP con las versiones mejoradas<br>";
    echo "2. Probar el registro de asistencias con los nuevos estados<br>";
    echo "3. Verificar que los reportes incluyan las nuevas opciones<br>";
    echo "4. Capacitar al personal sobre los nuevos estados de asistencia";
    echo "</div>";
} else {
    echo "<div class='alert alert-danger'>";
    echo "<strong>⚠️ Migración completada con errores</strong><br>";
    echo "Operaciones exitosas: " . count($resultados) . "<br>";
    echo "Errores encontrados: " . count($errores) . "<br><br>";
    echo "<strong>Errores:</strong><br>";
    foreach ($errores as $error) {
        echo "- $error<br>";
    }
    echo "</div>";
}

echo "<div class='mt-4'>";
echo "<a href='asistencias.php' class='btn btn-primary'>Ir al Sistema de Asistencias</a> ";
echo "<a href='index.php' class='btn btn-secondary'>Volver al Inicio</a>";
echo "</div>";

echo "</div>";
echo "</body>";
echo "</html>";
?>