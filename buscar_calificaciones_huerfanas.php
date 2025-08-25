<?php
/**
 * buscar_calificaciones_huerfanas.php - Encontrar las calificaciones que causan el problema
 * 
 * SITUACIÓN ACTUAL:
 * - Carabajal NO tiene asignaciones activas en Construcción de la Ciudadanía
 * - PERO aparece en los boletines y página de calificaciones
 * - Esto significa que hay calificaciones SIN asignación correspondiente
 */

require_once 'config.php';

$db = Database::getInstance();

// Datos de Carabajal
$estudianteSeleccionado = 52;
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo['id'];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Buscar Calificaciones Huérfanas - Carabajal</title>
    <meta charset='utf-8'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        .huerfana { background: #f8d7da; border-left: 4px solid #dc3545; }
        .solucion { background: #d1e7dd; border-left: 4px solid #198754; }
        .info { background: #cff4fc; border-left: 4px solid #0dcaf0; }
    </style>
</head>
<body>
<div class='container mt-4'>
<h1>🔍 Buscar Calificaciones Huérfanas de Carabajal</h1>
<p><strong>Estudiante:</strong> Carabajal (ID: {$estudianteSeleccionado}) | <strong>Ciclo:</strong> {$cicloActivo['anio']}</p>
<hr>";

// ============================================================================
// 1. BUSCAR TODAS LAS CALIFICACIONES DE CARABAJAL
// ============================================================================
echo "<h2>📊 1. TODAS las Calificaciones de Carabajal</h2>";

$todasCalificaciones = $db->fetchAll(
    "SELECT c.id, c.materia_curso_id, c.estudiante_id, c.ciclo_lectivo_id,
            c.calificacion_1c, c.calificacion_2c, c.calificacion_final, c.tipo_cursada,
            c.valoracion_1bim, c.valoracion_3bim,
            m.nombre as materia_nombre, m.codigo as materia_codigo,
            curso.nombre as curso_nombre, curso.anio as curso_anio,
            c.observaciones
     FROM calificaciones c
     JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
     JOIN materias m ON mp.materia_id = m.id
     JOIN cursos curso ON mp.curso_id = curso.id
     WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
     ORDER BY m.nombre",
    [$estudianteSeleccionado, $cicloLectivoId]
);

if (!empty($todasCalificaciones)) {
    echo "<div class='alert info'><strong>📋 CALIFICACIONES ENCONTRADAS:</strong> " . count($todasCalificaciones) . "</div>";
    
    echo "<table class='table table-sm'>
            <tr><th>ID</th><th>Materia</th><th>Código</th><th>Curso</th><th>1°C</th><th>2°C</th><th>Final</th><th>Val 1B</th><th>Val 3B</th><th>Tipo</th></tr>";
    
    foreach ($todasCalificaciones as $cal) {
        echo "<tr>
                <td>{$cal['id']}</td>
                <td><strong>{$cal['materia_nombre']}</strong></td>
                <td>{$cal['materia_codigo']}</td>
                <td>{$cal['curso_nombre']}</td>
                <td>" . ($cal['calificacion_1c'] ?: '-') . "</td>
                <td>" . ($cal['calificacion_2c'] ?: '-') . "</td>
                <td>" . ($cal['calificacion_final'] ?: '-') . "</td>
                <td>" . ($cal['valoracion_1bim'] ?: '-') . "</td>
                <td>" . ($cal['valoracion_3bim'] ?: '-') . "</td>
                <td>" . ($cal['tipo_cursada'] ?: 'C') . "</td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<div class='alert alert-success'><strong>✅ PERFECTO:</strong> No hay calificaciones registradas</div>";
}

// ============================================================================
// 2. CALIFICACIONES DE CONSTRUCCIÓN DE LA CIUDADANÍA ESPECÍFICAMENTE
// ============================================================================
echo "<h2>🎯 2. Calificaciones de Construcción de la Ciudadanía</h2>";

$calificacionesCiudadania = $db->fetchAll(
    "SELECT c.id, c.materia_curso_id, 
            c.calificacion_1c, c.calificacion_2c, c.calificacion_final, c.tipo_cursada,
            c.valoracion_1bim, c.valoracion_3bim,
            m.nombre as materia_nombre, m.codigo as materia_codigo,
            curso.nombre as curso_nombre, curso.anio as curso_anio
     FROM calificaciones c
     JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
     JOIN materias m ON mp.materia_id = m.id
     JOIN cursos curso ON mp.curso_id = curso.id
     WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
     AND LOWER(m.nombre) LIKE '%construccion%ciudadania%'
     ORDER BY m.nombre",
    [$estudianteSeleccionado, $cicloLectivoId]
);

if (!empty($calificacionesCiudadania)) {
    echo "<div class='alert huerfana'><strong>🚨 CALIFICACIONES HUÉRFANAS ENCONTRADAS:</strong> " . count($calificacionesCiudadania) . "</div>";
    
    echo "<p><strong>ESTAS son las calificaciones que causan que Carabajal aparezca en los boletines:</strong></p>";
    
    echo "<table class='table table-danger'>
            <tr><th>ID Cal.</th><th>Materia Curso ID</th><th>Materia</th><th>Código</th><th>1°C</th><th>2°C</th><th>Final</th></tr>";
    
    foreach ($calificacionesCiudadania as $cal) {
        echo "<tr>
                <td><strong>{$cal['id']}</strong></td>
                <td><strong>{$cal['materia_curso_id']}</strong></td>
                <td>{$cal['materia_nombre']}</td>
                <td>{$cal['materia_codigo']}</td>
                <td>" . ($cal['calificacion_1c'] ?: '-') . "</td>
                <td>" . ($cal['calificacion_2c'] ?: '-') . "</td>
                <td>" . ($cal['calificacion_final'] ?: '-') . "</td>
              </tr>";
    }
    echo "</table>";
    
    echo "<div class='alert huerfana'>
            <h5>🎯 EXPLICACIÓN DEL PROBLEMA:</h5>
            <p>Estas calificaciones existen en la base de datos pero Carabajal NO está asignado a ningún subgrupo de estas materias.</p>
            <p>Las consultas actuales de boletines.php las encuentran porque hacen JOIN desde calificaciones, no desde asignaciones.</p>
          </div>";
    
} else {
    echo "<div class='alert alert-success'><strong>✅ PERFECTO:</strong> No hay calificaciones de Construcción de la Ciudadanía</div>";
}

// ============================================================================
// 3. VERIFICAR ASIGNACIONES HISTÓRICAS (INACTIVAS)
// ============================================================================
echo "<h2>📜 3. Asignaciones Históricas (Inactivas)</h2>";

$asignacionesInactivas = $db->fetchAll(
    "SELECT ep.*, m.nombre as materia_nombre, m.codigo, c.nombre as curso_nombre,
            ep.activo
     FROM estudiantes_por_materia ep
     JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
     JOIN materias m ON mp.materia_id = m.id
     JOIN cursos c ON mp.curso_id = c.id
     WHERE ep.estudiante_id = ? AND ep.ciclo_lectivo_id = ?
     AND LOWER(m.nombre) LIKE '%construccion%ciudadania%'
     ORDER BY ep.id DESC",
    [$estudianteSeleccionado, $cicloLectivoId]
);

if (!empty($asignacionesInactivas)) {
    echo "<div class='alert info'><strong>📜 HISTORIAL DE ASIGNACIONES:</strong> " . count($asignacionesInactivas) . "</div>";
    
    echo "<table class='table table-info'>
            <tr><th>ID</th><th>Materia</th><th>Subgrupo</th><th>Período</th><th>Activo</th></tr>";
    
    foreach ($asignacionesInactivas as $asig) {
        $estadoClass = $asig['activo'] ? 'table-success' : 'table-warning';
        $estadoTexto = $asig['activo'] ? '✅ Activo' : '❌ Inactivo';
        
        echo "<tr class='{$estadoClass}'>
                <td>{$asig['id']}</td>
                <td>{$asig['materia_nombre']}</td>
                <td><strong>{$asig['subgrupo']}</strong></td>
                <td>{$asig['periodo_inicio']}</td>
                <td>{$estadoTexto}</td>
              </tr>";
    }
    echo "</table>";
    
    echo "<div class='alert info'>
            <p><strong>💡 POSIBLE CAUSA:</strong> Las asignaciones fueron eliminadas/desactivadas pero las calificaciones quedaron huérfanas.</p>
          </div>";
    
} else {
    echo "<div class='alert alert-warning'><strong>⚠️ EXTRAÑO:</strong> No se encontró historial de asignaciones</div>";
}

// ============================================================================
// 4. COMPROBAR QUE CONSULTA USA BOLETINES.PHP ACTUALMENTE
// ============================================================================
echo "<h2>🔬 4. Simular Consulta Actual de boletines.php</h2>";

// Esta es la consulta que probablemente usa boletines.php actualmente
$simulacionBoletines = $db->fetchAll(
    "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
            cu.anio as anio_cursada
    FROM calificaciones c
    JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
    JOIN materias m ON mp.materia_id = m.id
    JOIN cursos cu ON mp.curso_id = cu.id
    WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
    AND c.tipo_cursada = 'C'
    AND NOT EXISTS (
        SELECT 1 FROM materias_recursado mr 
        WHERE mr.estudiante_id = c.estudiante_id 
        AND mr.materia_curso_id = c.materia_curso_id 
        AND mr.ciclo_lectivo_id = c.ciclo_lectivo_id 
        AND mr.estado = 'activo'
    )
    ORDER BY m.nombre",
    [$estudianteSeleccionado, $cicloLectivoId]
);

echo "<div class='alert huerfana'><strong>🚨 ESTO ES LO QUE VE boletines.php:</strong> " . count($simulacionBoletines) . " materias</div>";

if (!empty($simulacionBoletines)) {
    echo "<table class='table table-danger'>
            <tr><th>Materia</th><th>Código</th><th>Año</th><th>¿Por qué aparece?</th></tr>";
    
    foreach ($simulacionBoletines as $mat) {
        echo "<tr>
                <td><strong>{$mat['materia_nombre']}</strong></td>
                <td>{$mat['materia_codigo']}</td>
                <td>{$mat['anio_cursada']}</td>
                <td><em>Calificación existe sin asignación</em></td>
              </tr>";
    }
    echo "</table>";
}

// ============================================================================
// 5. SOLUCIÓN: ELIMINAR CALIFICACIONES HUÉRFANAS
// ============================================================================
echo "<h2>🛠️ 5. SOLUCIÓN</h2>";

if (!empty($calificacionesCiudadania)) {
    echo "<div class='alert solucion'>
            <h5>🔧 PASOS PARA SOLUCIONAR:</h5>
            <ol>
                <li><strong>INMEDIATO:</strong> Eliminar las calificaciones huérfanas (botón abajo)</li>
                <li><strong>DEFINITIVO:</strong> Modificar las consultas en boletines.php y vista_boletin_cuatrimestral.php</li>
            </ol>
          </div>";
    
    // Botón para eliminar calificaciones huérfanas
    if (isset($_POST['eliminar_huerfanas'])) {
        echo "<div class='alert alert-warning'><strong>🔄 ELIMINANDO CALIFICACIONES HUÉRFANAS...</strong></div>";
        
        try {
            $idsAEliminar = array_column($calificacionesCiudadania, 'id');
            $placeholders = implode(',', array_fill(0, count($idsAEliminar), '?'));
            
            $resultado = $db->query(
                "DELETE FROM calificaciones WHERE id IN ($placeholders)",
                $idsAEliminar
            );
            
            echo "<div class='alert alert-success'>
                    <strong>✅ ÉXITO:</strong> Se eliminaron " . count($idsAEliminar) . " calificaciones huérfanas.
                    <br><strong>Resultado:</strong> Carabajal ya NO debería aparecer en Construcción de la Ciudadanía en los boletines.
                    <br><strong>Siguiente paso:</strong> Verificar que el problema esté resuelto.
                  </div>";
            
            echo "<p><a href='boletines.php' class='btn btn-primary'>🔗 Ir a Boletines para Verificar</a></p>";
            
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'><strong>❌ ERROR:</strong> " . $e->getMessage() . "</div>";
        }
    } else {
        $idsAEliminar = array_column($calificacionesCiudadania, 'id');
        $listaIds = implode(', ', $idsAEliminar);
        
        echo "<div class='alert alert-warning'>
                <p><strong>⚠️ CALIFICACIONES A ELIMINAR:</strong></p>
                <p>IDs: {$listaIds}</p>
              </div>";
        
        echo "<form method='POST' onsubmit='return confirm(\"¿ELIMINAR las calificaciones huérfanas de Carabajal?\\n\\nEsto solucionará el problema pero se perderán las calificaciones registradas.\\n\\nIDs a eliminar: {$listaIds}\")'>
                <button type='submit' name='eliminar_huerfanas' class='btn btn-danger'>
                    🗑️ Eliminar Calificaciones Huérfanas
                </button>
              </form>";
    }
} else {
    echo "<div class='alert alert-success'>
            <h5>✅ NO HAY CALIFICACIONES HUÉRFANAS</h5>
            <p>Si Carabajal sigue apareciendo en los boletines, el problema está en las consultas de los archivos PHP.</p>
          </div>";
}

echo "</div></body></html>";
?>
