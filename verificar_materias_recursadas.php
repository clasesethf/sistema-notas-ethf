<?php
/**
 * verificar_materias_recursadas.php
 * Script para verificar y corregir el campo tipo_cursada en materias recursadas
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar sesión (opcional, puedes comentar estas líneas si quieres ejecutar el script directamente)
/*
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
*/

// Obtener conexión a la base de datos
$db = Database::getInstance();

echo "<h1>Verificador de Materias Recursadas</h1>";
echo "<style>
table { border-collapse: collapse; width: 100%; margin: 20px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
.error { background-color: #ffebee; }
.success { background-color: #e8f5e8; }
.warning { background-color: #fff3cd; }
</style>";

// Función para mostrar todas las calificaciones con análisis
function mostrarCalificaciones($db) {
    echo "<h2>📊 Análisis Completo de Calificaciones</h2>";
    
    $query = "
        SELECT 
            u.id as estudiante_id,
            u.nombre as estudiante_nombre,
            u.apellido as estudiante_apellido,
            curso_estudiante.anio as anio_curso_estudiante,
            m.nombre as materia_nombre,
            curso_materia.anio as anio_materia,
            c.tipo_cursada,
            c.id as calificacion_id,
            CASE 
                WHEN curso_estudiante.anio > curso_materia.anio THEN 'DEBERÍA SER R'
                WHEN curso_estudiante.anio = curso_materia.anio THEN 'DEBERÍA SER C'
                ELSE 'VERIFICAR'
            END as tipo_sugerido,
            CASE 
                WHEN curso_estudiante.anio > curso_materia.anio AND c.tipo_cursada != 'R' THEN 'ERROR'
                WHEN curso_estudiante.anio = curso_materia.anio AND c.tipo_cursada != 'C' THEN 'ERROR'
                ELSE 'OK'
            END as estado
        FROM calificaciones c
        JOIN usuarios u ON c.estudiante_id = u.id AND u.tipo = 'estudiante'
        JOIN matriculas mat ON u.id = mat.estudiante_id
        JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
        ORDER BY u.apellido, u.nombre, m.nombre
    ";
    
    try {
        $resultados = $db->fetchAll($query);
        
        if (empty($resultados)) {
            echo "<p>No se encontraron calificaciones.</p>";
            return;
        }
        
        echo "<table>";
        echo "<tr>
                <th>Estudiante</th>
                <th>Año Estudiante</th>
                <th>Materia</th>
                <th>Año Materia</th>
                <th>Tipo Actual</th>
                <th>Tipo Sugerido</th>
                <th>Estado</th>
                <th>ID Calificación</th>
              </tr>";
        
        $errores = 0;
        foreach ($resultados as $row) {
            $clase = '';
            if ($row['estado'] == 'ERROR') {
                $clase = 'error';
                $errores++;
            } elseif ($row['estado'] == 'OK') {
                $clase = 'success';
            }
            
            echo "<tr class='$clase'>";
            echo "<td>{$row['estudiante_apellido']}, {$row['estudiante_nombre']}</td>";
            echo "<td>{$row['anio_curso_estudiante']}°</td>";
            echo "<td>{$row['materia_nombre']}</td>";
            echo "<td>{$row['anio_materia']}°</td>";
            echo "<td><strong>{$row['tipo_cursada']}</strong></td>";
            echo "<td><strong>{$row['tipo_sugerido']}</strong></td>";
            echo "<td><strong>{$row['estado']}</strong></td>";
            echo "<td>{$row['calificacion_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p><strong>Resumen:</strong> Se encontraron <span style='color: red;'>$errores errores</span> que necesitan corrección.</p>";
        
        return $errores;
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error al consultar: " . $e->getMessage() . "</p>";
        return 0;
    }
}

// Función para corregir automáticamente los errores
function corregirErrores($db) {
    echo "<h2>🔧 Corrección Automática</h2>";
    
    // Query para actualizar materias que deberían ser recursadas (R)
    $queryActualizarR = "
        UPDATE calificaciones 
        SET tipo_cursada = 'R'
        WHERE id IN (
            SELECT c.id
            FROM calificaciones c
            JOIN usuarios u ON c.estudiante_id = u.id AND u.tipo = 'estudiante'
            JOIN matriculas mat ON u.id = mat.estudiante_id
            JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
            JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
            JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
            WHERE curso_estudiante.anio > curso_materia.anio 
              AND c.tipo_cursada != 'R'
        )
    ";
    
    // Query para actualizar materias que deberían ser cursadas (C)
    $queryActualizarC = "
        UPDATE calificaciones 
        SET tipo_cursada = 'C'
        WHERE id IN (
            SELECT c.id
            FROM calificaciones c
            JOIN usuarios u ON c.estudiante_id = u.id AND u.tipo = 'estudiante'
            JOIN matriculas mat ON u.id = mat.estudiante_id
            JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
            JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
            JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
            WHERE curso_estudiante.anio = curso_materia.anio 
              AND c.tipo_cursada != 'C'
        )
    ";
    
    try {
        // Corregir a 'R'
        $resultadoR = $db->execute($queryActualizarR);
        echo "<p style='color: green;'>✅ Se actualizaron las materias a tipo 'R' (recursadas).</p>";
        
        // Corregir a 'C' 
        $resultadoC = $db->execute($queryActualizarC);
        echo "<p style='color: green;'>✅ Se actualizaron las materias a tipo 'C' (cursadas).</p>";
        
        echo "<p><strong>Corrección completada.</strong></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error al corregir: " . $e->getMessage() . "</p>";
    }
}

// Función para mostrar solo los problemas
function mostrarSoloProblemas($db) {
    echo "<h2>⚠️ Solo Registros con Problemas</h2>";
    
    $query = "
        SELECT 
            u.nombre as estudiante_nombre,
            u.apellido as estudiante_apellido,
            curso_estudiante.anio as anio_curso_estudiante,
            m.nombre as materia_nombre,
            curso_materia.anio as anio_materia,
            c.tipo_cursada,
            c.id as calificacion_id,
            CASE 
                WHEN curso_estudiante.anio > curso_materia.anio THEN 'DEBERÍA SER R'
                WHEN curso_estudiante.anio = curso_materia.anio THEN 'DEBERÍA SER C'
            END as tipo_correcto
        FROM calificaciones c
        JOIN usuarios u ON c.estudiante_id = u.id AND u.tipo = 'estudiante'
        JOIN matriculas mat ON u.id = mat.estudiante_id
        JOIN cursos curso_estudiante ON mat.curso_id = curso_estudiante.id
        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
        WHERE (
            (curso_estudiante.anio > curso_materia.anio AND c.tipo_cursada != 'R') OR
            (curso_estudiante.anio = curso_materia.anio AND c.tipo_cursada != 'C')
        )
        ORDER BY u.apellido, u.nombre, m.nombre
    ";
    
    try {
        $problemas = $db->fetchAll($query);
        
        if (empty($problemas)) {
            echo "<p style='color: green;'>✅ ¡No se encontraron problemas! Todos los registros están correctos.</p>";
            return;
        }
        
        echo "<table>";
        echo "<tr>
                <th>Estudiante</th>
                <th>Año Estudiante</th>
                <th>Materia</th>
                <th>Año Materia</th>
                <th>Tipo Actual (INCORRECTO)</th>
                <th>Tipo Correcto</th>
                <th>ID</th>
              </tr>";
        
        foreach ($problemas as $row) {
            echo "<tr class='error'>";
            echo "<td>{$row['estudiante_apellido']}, {$row['estudiante_nombre']}</td>";
            echo "<td>{$row['anio_curso_estudiante']}°</td>";
            echo "<td>{$row['materia_nombre']}</td>";
            echo "<td>{$row['anio_materia']}°</td>";
            echo "<td><strong>{$row['tipo_cursada']}</strong></td>";
            echo "<td><strong>{$row['tipo_correcto']}</strong></td>";
            echo "<td>{$row['calificacion_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<p><strong>Se encontraron " . count($problemas) . " registros con problemas.</strong></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    }
}

// Procesar acciones
if (isset($_GET['accion'])) {
    switch ($_GET['accion']) {
        case 'mostrar_todo':
            $errores = mostrarCalificaciones($db);
            break;
        case 'mostrar_problemas':
            mostrarSoloProblemas($db);
            break;
        case 'corregir':
            corregirErrores($db);
            echo "<hr>";
            echo "<h3>Verificación después de la corrección:</h3>";
            mostrarSoloProblemas($db);
            break;
    }
} else {
    // Mostrar menú principal
    echo "<h2>🔍 Opciones de Verificación</h2>";
    echo "<p><a href='?accion=mostrar_problemas' style='background: #ff9800; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>📋 Ver Solo Problemas</a></p>";
    echo "<p><a href='?accion=mostrar_todo' style='background: #2196f3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>📊 Ver Análisis Completo</a></p>";
    echo "<p><a href='?accion=corregir' style='background: #4caf50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Corregir Automáticamente</a></p>";
    
    echo "<hr>";
    echo "<h3>Vista rápida de problemas:</h3>";
    mostrarSoloProblemas($db);
}

echo "<hr>";
echo "<p><a href='?' style='background: #9e9e9e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🏠 Volver al Menú</a></p>";
?>