<?php
/**
 * verificar_asignaciones_carabajal.php - Verificar el estado actual exacto
 */

require_once 'config.php';
$db = Database::getInstance();

$estudianteId = 52; // Carabajal
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo['id'];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Verificar Asignaciones de Carabajal</title>
    <meta charset='utf-8'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        .correcto { background: #d1e7dd; border-left: 4px solid #198754; }
        .problema { background: #fff3cd; border-left: 4px solid #ffc107; }
        .solucion { background: #cff4fc; border-left: 4px solid #0dcaf0; }
    </style>
</head>
<body>
<div class='container mt-4'>
<h1>🔍 Estado Actual de Carabajal</h1>
<hr>";

// ============================================================================
// 1. ASIGNACIONES ACTUALES EN CONSTRUCCIÓN DE LA CIUDADANÍA
// ============================================================================
echo "<h2>📝 1. Asignaciones ACTUALES en Construcción de la Ciudadanía</h2>";

$asignacionesActuales = $db->fetchAll(
    "SELECT ep.id, ep.materia_curso_id, ep.subgrupo, ep.periodo_inicio, ep.activo,
            m.nombre as materia_nombre, m.codigo as materia_codigo
     FROM estudiantes_por_materia ep
     JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
     JOIN materias m ON mp.materia_id = m.id
     WHERE ep.estudiante_id = ? AND ep.ciclo_lectivo_id = ?
     AND LOWER(m.nombre) LIKE '%constr%ciud%'
     ORDER BY ep.activo DESC, m.nombre",
    [$estudianteId, $cicloLectivoId]
);

if (!empty($asignacionesActuales)) {
    echo "<div class='alert correcto'><strong>✅ ASIGNACIONES ENCONTRADAS:</strong> " . count($asignacionesActuales) . "</div>";
    
    echo "<table class='table'>
            <tr><th>ID Asig.</th><th>Materia Curso ID</th><th>Materia</th><th>Código</th><th>Subgrupo</th><th>Período</th><th>Activo</th></tr>";
    
    foreach ($asignacionesActuales as $asig) {
        $estadoClass = $asig['activo'] ? 'table-success' : 'table-warning';
        $estadoTexto = $asig['activo'] ? '✅ Activo' : '❌ Inactivo';
        
        echo "<tr class='{$estadoClass}'>
                <td>{$asig['id']}</td>
                <td><strong>{$asig['materia_curso_id']}</strong></td>
                <td>{$asig['materia_nombre']}</td>
                <td>{$asig['materia_codigo']}</td>
                <td><strong>{$asig['subgrupo']}</strong></td>
                <td>{$asig['periodo_inicio']}</td>
                <td>{$estadoTexto}</td>
              </tr>";
    }
    echo "</table>";
    
    // Obtener solo las activas
    $activas = array_filter($asignacionesActuales, function($a) { return $a['activo']; });
    if (count($activas) > 1) {
        echo "<div class='alert problema'>
                <strong>⚠️ PROBLEMA:</strong> Carabajal tiene " . count($activas) . " asignaciones ACTIVAS. Debería tener solo 1.
              </div>";
    } elseif (count($activas) == 1) {
        $asigActiva = $activas[0];
        echo "<div class='alert correcto'>
                <strong>✅ CORRECTO:</strong> Solo 1 asignación activa: {$asigActiva['materia_nombre']} - {$asigActiva['subgrupo']}
              </div>";
    }
    
} else {
    echo "<div class='alert problema'><strong>⚠️ PROBLEMA:</strong> No se encontraron asignaciones</div>";
}

// ============================================================================
// 2. CALIFICACIONES EN CONSTRUCCIÓN DE LA CIUDADANÍA
// ============================================================================
echo "<h2>📊 2. Calificaciones en Construcción de la Ciudadanía</h2>";

$calificacionesCiudadania = $db->fetchAll(
    "SELECT c.id, c.materia_curso_id, c.calificacion_1c, c.valoracion_1bim,
            m.nombre as materia_nombre, m.codigo as materia_codigo
     FROM calificaciones c
     JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
     JOIN materias m ON mp.materia_id = m.id
     WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
     AND LOWER(m.nombre) LIKE '%constr%ciud%'
     ORDER BY m.nombre",
    [$estudianteId, $cicloLectivoId]
);

if (!empty($calificacionesCiudadania)) {
    echo "<div class='alert problema'><strong>📊 CALIFICACIONES ENCONTRADAS:</strong> " . count($calificacionesCiudadania) . "</div>";
    
    echo "<table class='table'>
            <tr><th>ID Cal.</th><th>Materia Curso ID</th><th>Materia</th><th>Código</th><th>1°C</th><th>Val 1B</th><th>¿Asignado?</th></tr>";
    
    foreach ($calificacionesCiudadania as $cal) {
        // Verificar si está asignado a esta materia
        $estaAsignado = false;
        foreach ($asignacionesActuales as $asig) {
            if ($asig['materia_curso_id'] == $cal['materia_curso_id'] && $asig['activo']) {
                $estaAsignado = true;
                break;
            }
        }
        
        $estadoClass = $estaAsignado ? 'table-success' : 'table-danger';
        $estadoTexto = $estaAsignado ? '✅ SÍ' : '❌ NO - HUÉRFANA';
        
        echo "<tr class='{$estadoClass}'>
                <td>{$cal['id']}</td>
                <td><strong>{$cal['materia_curso_id']}</strong></td>
                <td>{$cal['materia_nombre']}</td>
                <td>{$cal['materia_codigo']}</td>
                <td>" . ($cal['calificacion_1c'] ?: '-') . "</td>
                <td>" . ($cal['valoracion_1bim'] ?: '-') . "</td>
                <td><strong>{$estadoTexto}</strong></td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<div class='alert alert-warning'>No se encontraron calificaciones de Construcción de la Ciudadanía</div>";
}

// ============================================================================
// 3. CONSULTA ACTUAL DE BOLETINES.PHP (PROBLEMÁTICA)
// ============================================================================
echo "<h2>🚨 3. Lo que VE actualmente boletines.php</h2>";

echo "<div class='alert problema'>
        <strong>CONSULTA PROBLEMÁTICA ACTUAL:</strong><br>
        boletines.php hace JOIN desde calificaciones, no desde asignaciones
      </div>";

// Simular la consulta problemática de boletines.php
$consultaProblematica = $db->fetchAll(
    "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
            cu.anio as anio_cursada
    FROM calificaciones c
    JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
    JOIN materias m ON mp.materia_id = m.id
    JOIN cursos cu ON mp.curso_id = cu.id
    WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
    AND c.tipo_cursada = 'C'
    AND LOWER(m.nombre) LIKE '%constr%ciud%'
    ORDER BY m.nombre",
    [$estudianteId, $cicloLectivoId]
);

echo "<div class='alert problema'><strong>🚨 RESULTADO PROBLEMÁTICO:</strong> " . count($consultaProblematica) . " materias aparecen en el boletín</div>";

if (!empty($consultaProblematica)) {
    echo "<table class='table table-danger'>
            <tr><th>Materia</th><th>Código</th><th>1°C</th><th>¿Debería aparecer?</th></tr>";
    
    foreach ($consultaProblematica as $mat) {
        // Verificar si debería aparecer
        $deberiaAparecer = false;
        foreach ($asignacionesActuales as $asig) {
            if ($asig['materia_curso_id'] == $mat['materia_curso_id'] && $asig['activo']) {
                $deberiaAparecer = true;
                break;
            }
        }
        
        $deberia = $deberiaAparecer ? '✅ SÍ' : '❌ NO';
        $rowClass = $deberiaAparecer ? '' : 'table-danger';
        
        echo "<tr class='{$rowClass}'>
                <td><strong>{$mat['materia_nombre']}</strong></td>
                <td>{$mat['materia_codigo']}</td>
                <td>" . ($mat['calificacion_1c'] ?: '-') . "</td>
                <td><strong>{$deberia}</strong></td>
              </tr>";
    }
    echo "</table>";
}

// ============================================================================
// 4. CONSULTA CORREGIDA (LA SOLUCIÓN)
// ============================================================================
echo "<h2>✅ 4. Lo que DEBERÍA ver boletines.php (Consulta Corregida)</h2>";

echo "<div class='alert solucion'>
        <strong>CONSULTA CORREGIDA:</strong><br>
        Empezar desde estudiantes_por_materia (asignaciones) y hacer JOIN a calificaciones
      </div>";

$consultaCorregida = $db->fetchAll(
    "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
            cu.anio as anio_cursada, ep.subgrupo
    FROM estudiantes_por_materia ep
    JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
    JOIN materias m ON mp.materia_id = m.id
    JOIN cursos cu ON mp.curso_id = cu.id
    LEFT JOIN calificaciones c ON ep.estudiante_id = c.estudiante_id 
                                AND ep.materia_curso_id = c.materia_curso_id 
                                AND ep.ciclo_lectivo_id = c.ciclo_lectivo_id
    WHERE ep.estudiante_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
    AND LOWER(m.nombre) LIKE '%constr%ciud%'
    ORDER BY m.nombre",
    [$estudianteId, $cicloLectivoId]
);

echo "<div class='alert solucion'><strong>✅ RESULTADO CORRECTO:</strong> " . count($consultaCorregida) . " materia(s) (solo las asignadas)</div>";

if (!empty($consultaCorregida)) {
    echo "<table class='table table-success'>
            <tr><th>Materia</th><th>Código</th><th>Subgrupo</th><th>1°C</th></tr>";
    
    foreach ($consultaCorregida as $mat) {
        echo "<tr>
                <td><strong>{$mat['materia_nombre']}</strong></td>
                <td>{$mat['materia_codigo']}</td>
                <td><strong>{$mat['subgrupo']}</strong></td>
                <td>" . ($mat['calificacion_1c'] ?: '-') . "</td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay materias asignadas</p>";
}

// ============================================================================
// 5. PLAN DE SOLUCIÓN ESPECÍFICO
// ============================================================================
echo "<h2>🛠️ 5. Plan de Solución</h2>";

$calificacionesHuerfanas = array_filter($calificacionesCiudadania, function($cal) use ($asignacionesActuales) {
    foreach ($asignacionesActuales as $asig) {
        if ($asig['materia_curso_id'] == $cal['materia_curso_id'] && $asig['activo']) {
            return false; // Tiene asignación, no es huérfana
        }
    }
    return true; // No tiene asignación, es huérfana
});

if (!empty($calificacionesHuerfanas)) {
    echo "<div class='alert problema'>
            <h5>🎯 PROBLEMA IDENTIFICADO:</h5>
            <p>Carabajal tiene " . count($calificacionesHuerfanas) . " calificaciones de Construcción de la Ciudadanía SIN asignación activa correspondiente.</p>
            <ul>";
    
    foreach ($calificacionesHuerfanas as $huer) {
        echo "<li><strong>{$huer['materia_nombre']}</strong> (ID cal: {$huer['id']}, ID materia_curso: {$huer['materia_curso_id']})</li>";
    }
    echo "</ul></div>";
    
    echo "<div class='alert solucion'>
            <h5>🔧 SOLUCIÓN DOBLE:</h5>
            <ol>
                <li><strong>INMEDIATO:</strong> Eliminar las calificaciones huérfanas (botón abajo)</li>
                <li><strong>DEFINITIVO:</strong> Corregir las consultas en boletines.php y vista_boletin_cuatrimestral.php</li>
            </ol>
          </div>";
    
    // Botón para eliminar calificaciones huérfanas
    if (isset($_POST['eliminar_huerfanas_especificas'])) {
        echo "<div class='alert alert-warning'><strong>🔄 ELIMINANDO CALIFICACIONES HUÉRFANAS...</strong></div>";
        
        try {
            $idsHuerfanas = array_column($calificacionesHuerfanas, 'id');
            $placeholders = implode(',', array_fill(0, count($idsHuerfanas), '?'));
            
            $db->query("DELETE FROM calificaciones WHERE id IN ($placeholders)", $idsHuerfanas);
            
            echo "<div class='alert alert-success'>
                    <strong>✅ ÉXITO:</strong> Se eliminaron " . count($idsHuerfanas) . " calificaciones huérfanas.
                    <br><strong>Resultado:</strong> Carabajal ya NO debería aparecer en 'Constr. de Ciud. - Metales' en los boletines.
                    <br><strong>Siguiente:</strong> Actualizar las consultas de boletines.php para evitar que vuelva a pasar.
                  </div>";
            
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'><strong>❌ ERROR:</strong> " . $e->getMessage() . "</div>";
        }
    } else {
        $idsHuerfanas = array_column($calificacionesHuerfanas, 'id');
        $listaIds = implode(', ', $idsHuerfanas);
        
        echo "<form method='POST' onsubmit='return confirm(\"¿ELIMINAR las " . count($calificacionesHuerfanas) . " calificaciones huérfanas?\\n\\nIDs: {$listaIds}\\n\\nEsto solucionará el problema inmediatamente.\")'>
                <button type='submit' name='eliminar_huerfanas_especificas' class='btn btn-danger'>
                    🗑️ Eliminar " . count($calificacionesHuerfanas) . " Calificaciones Huérfanas
                </button>
              </form>";
    }
    
} else {
    echo "<div class='alert solucion'>
            <h5>✅ SIN CALIFICACIONES HUÉRFANAS</h5>
            <p>El problema debe estar únicamente en las consultas de boletines.php</p>
          </div>";
}

echo "</div></body></html>";
?>
