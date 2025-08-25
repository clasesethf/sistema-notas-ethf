<?php
/**
 * limpieza_masiva_construccion.php - Limpieza masiva del problema de Construcción de la Ciudadanía
 * 
 * PROBLEMA: Múltiples estudiantes tienen calificaciones en materias de Construcción
 * de la Ciudadanía donde no están asignados debido a rotación automática incorrecta
 */

require_once 'config.php';
$db = Database::getInstance();

$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo['id'];

// Lista de estudiantes afectados - ACTUALIZADA con nuevos estudiantes
$estudiantesAfectados = [
    // Estudiantes originales
    'CARABAJAL', 'ALMARAZ IGLESIAS', 'ANTONIO', 'BOGAO', 'BRIZIO TRÍAS', 
    'CACERES GARCIA', 'CALABRO', 'DI FIORE', 'DIAZ', 'DONDO CIFUENTES', 
    'ESTEYBAR MOLLO',
    // Nuevos estudiantes agregados
    'NUÑEZ ZAYAS', 'PRIETO', 'PUGLISI', 'RIVA', 'SAENZ BRIONES',
    'TCHERECHANSKY', 'TORRES AGRELO', 'VAGNI AGUIRRE', 'VENTOSO',
    'VENTURELLI', 'VERGARA'
];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Limpieza Masiva - Construcción de la Ciudadanía</title>
    <meta charset='utf-8'>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        .problema { background: #fff3cd; border-left: 4px solid #ffc107; }
        .solucion { background: #d1e7dd; border-left: 4px solid #198754; }
        .critico { background: #f8d7da; border-left: 4px solid #dc3545; }
        .info { background: #cff4fc; border-left: 4px solid #0dcaf0; }
        .nuevo { background: #e7f3ff; border-left: 4px solid #0066cc; }
    </style>
</head>
<body>
<div class='container mt-4'>
<h1>🧹 Limpieza Masiva - Construcción de la Ciudadanía</h1>
<p><strong>Ciclo Lectivo:</strong> {$cicloActivo['anio']} | <strong>Estudiantes Afectados:</strong> " . count($estudiantesAfectados) . "</p>

<div class='alert nuevo'>
    <h5>🆕 Lista Actualizada de Estudiantes</h5>
    <p><strong>Total:</strong> " . count($estudiantesAfectados) . " estudiantes (11 originales + 11 nuevos)</p>
    <div class='row'>
        <div class='col-md-6'>
            <h6>Estudiantes Originales:</h6>
            <ul class='small'>
                <li>ALMARAZ IGLESIAS, Lola</li>
                <li>ANTONIO, Felix</li>
                <li>BOGAO, Joaquin Ezequiel</li>
                <li>BRIZIO TRÍAS, Gregorio</li>
                <li>CACERES GARCIA, Marcelo Alejandro</li>
                <li>CALABRO, Mauro Valentino</li>
                <li>CARABAJAL, Bruno Nicolas</li>
                <li>DI FIORE, Catalina</li>
                <li>DIAZ, Alan Ivan</li>
                <li>DONDO CIFUENTES, Simon</li>
                <li>ESTEYBAR MOLLO, Santiago Jose</li>
            </ul>
        </div>
        <div class='col-md-6'>
            <h6>Estudiantes Nuevos Agregados:</h6>
            <ul class='small'>
                <li>NUÑEZ ZAYAS, Julieta</li>
                <li>PRIETO, Valentin Emmanuel</li>
                <li>PUGLISI, Francisco</li>
                <li>RIVA, Manuel</li>
                <li>SAENZ BRIONES, Felipe</li>
                <li>TCHERECHANSKY, Santino Nicolas</li>
                <li>TORRES AGRELO, Matias</li>
                <li>VAGNI AGUIRRE, Micaela</li>
                <li>VENTOSO, Mariano</li>
                <li>VENTURELLI, Luca</li>
                <li>VERGARA, Santiago Augusto</li>
            </ul>
        </div>
    </div>
</div>
<hr>";

// ============================================================================
// 1. IDENTIFICAR TODOS LOS ESTUDIANTES AFECTADOS
// ============================================================================
echo "<h2>🔍 1. Identificar Estudiantes Afectados</h2>";

// Buscar todos los estudiantes afectados
$placeholdersAfectados = implode(',', array_fill(0, count($estudiantesAfectados), '?'));
$condicionAfectados = [];
foreach ($estudiantesAfectados as $apellido) {
    $condicionAfectados[] = "u.apellido LIKE '%$apellido%'";
}
$condicionSQL = '(' . implode(' OR ', $condicionAfectados) . ')';

$estudiantesEncontrados = $db->fetchAll(
    "SELECT u.id, u.nombre, u.apellido, u.dni, c.nombre as curso_nombre, c.anio
     FROM usuarios u
     JOIN matriculas m ON u.id = m.estudiante_id AND m.estado = 'activo'
     JOIN cursos c ON m.curso_id = c.id
     WHERE $condicionSQL AND u.tipo = 'estudiante' AND u.activo = 1
     AND c.anio = 3 AND c.ciclo_lectivo_id = ?
     ORDER BY u.apellido, u.nombre",
    [$cicloLectivoId]
);

echo "<div class='alert info'><strong>📋 ESTUDIANTES ENCONTRADOS:</strong> " . count($estudiantesEncontrados) . "</div>";

if (!empty($estudiantesEncontrados)) {
    echo "<table class='table table-sm'>
            <tr><th>ID</th><th>Apellido, Nombre</th><th>DNI</th><th>Curso</th><th>Estado</th></tr>";
    
    $estudiantesOriginales = ['CARABAJAL', 'ALMARAZ IGLESIAS', 'ANTONIO', 'BOGAO', 'BRIZIO TRÍAS', 
                             'CACERES GARCIA', 'CALABRO', 'DI FIORE', 'DIAZ', 'DONDO CIFUENTES', 'ESTEYBAR MOLLO'];
    
    foreach ($estudiantesEncontrados as $est) {
        $esOriginal = false;
        foreach ($estudiantesOriginales as $orig) {
            if (strpos($est['apellido'], $orig) !== false) {
                $esOriginal = true;
                break;
            }
        }
        
        $clase = $esOriginal ? '' : 'table-info';
        $badge = $esOriginal ? '' : '<span class="badge bg-primary">NUEVO</span>';
        
        echo "<tr class='$clase'>
                <td>{$est['id']}</td>
                <td><strong>{$est['apellido']}, {$est['nombre']}</strong> $badge</td>
                <td>{$est['dni']}</td>
                <td>{$est['curso_nombre']}</td>
                <td>" . ($esOriginal ? 'Original' : 'Nuevo') . "</td>
              </tr>";
    }
    echo "</table>";
}

// ============================================================================
// 2. ANALIZAR EL PROBLEMA COMPLETO
// ============================================================================
echo "<h2>🔬 2. Análisis del Problema</h2>";

$idsEstudiantes = array_column($estudiantesEncontrados, 'id');
$placeholdersIds = implode(',', array_fill(0, count($idsEstudiantes), '?'));

if (!empty($idsEstudiantes)) {
    // Calificaciones huérfanas (sin asignación activa)
    $calificacionesHuerfanas = $db->fetchAll(
        "SELECT c.id as calificacion_id, c.materia_curso_id, 
                u.apellido, u.nombre, u.id as estudiante_id,
                m.nombre as materia_nombre, m.codigo as materia_codigo
        FROM calificaciones c
        JOIN usuarios u ON c.estudiante_id = u.id
        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        WHERE c.estudiante_id IN ($placeholdersIds) 
        AND c.ciclo_lectivo_id = ?
        AND LOWER(m.nombre) LIKE '%constr%ciud%'
        AND NOT EXISTS (
            SELECT 1 FROM estudiantes_por_materia ep 
            WHERE ep.estudiante_id = c.estudiante_id 
            AND ep.materia_curso_id = c.materia_curso_id 
            AND ep.ciclo_lectivo_id = c.ciclo_lectivo_id
            AND ep.activo = 1
        )
        ORDER BY u.apellido, m.nombre",
        array_merge($idsEstudiantes, [$cicloLectivoId])
    );
    
    // Asignaciones múltiples (más de 1 asignación activa por estudiante) - Compatible SQLite
    $asignacionesMultiples = $db->fetchAll(
        "SELECT u.apellido, u.nombre, u.id as estudiante_id, COUNT(*) as total_asignaciones
        FROM estudiantes_por_materia ep
        JOIN usuarios u ON ep.estudiante_id = u.id
        JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
        JOIN materias m ON mp.materia_id = m.id
        WHERE ep.estudiante_id IN ($placeholdersIds)
        AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
        AND LOWER(m.nombre) LIKE '%constr%ciud%'
        GROUP BY u.id, u.apellido, u.nombre
        HAVING total_asignaciones > 1
        ORDER BY u.apellido",
        array_merge($idsEstudiantes, [$cicloLectivoId])
    );
    
    // Obtener las materias asignadas por separado
    foreach ($asignacionesMultiples as &$mult) {
        $materiasAsignadas = $db->fetchAll(
            "SELECT m.nombre as materia_nombre
            FROM estudiantes_por_materia ep
            JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
            JOIN materias m ON mp.materia_id = m.id
            WHERE ep.estudiante_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
            AND LOWER(m.nombre) LIKE '%constr%ciud%'",
            [$mult['estudiante_id'], $cicloLectivoId]
        );
        
        $mult['materias_asignadas'] = implode(' | ', array_column($materiasAsignadas, 'materia_nombre'));
    }
    
    echo "<div class='row'>
            <div class='col-md-6'>
                <div class='alert critico'>
                    <h5>🚨 Calificaciones Huérfanas</h5>
                    <p><strong>" . count($calificacionesHuerfanas) . "</strong> calificaciones sin asignación activa</p>
                </div>
            </div>
            <div class='col-md-6'>
                <div class='alert problema'>
                    <h5>⚠️ Asignaciones Múltiples</h5>
                    <p><strong>" . count($asignacionesMultiples) . "</strong> estudiantes con múltiples asignaciones</p>
                </div>
            </div>
          </div>";
    
    // Mostrar calificaciones huérfanas
    if (!empty($calificacionesHuerfanas)) {
        echo "<h3>🚨 Calificaciones Huérfanas por Estudiante</h3>";
        
        $porEstudiante = [];
        foreach ($calificacionesHuerfanas as $cal) {
            $key = $cal['apellido'] . ', ' . $cal['nombre'];
            if (!isset($porEstudiante[$key])) {
                $porEstudiante[$key] = [];
            }
            $porEstudiante[$key][] = $cal;
        }
        
        echo "<table class='table table-danger table-sm'>
                <tr><th>Estudiante</th><th>Materias con Calificaciones Huérfanas</th><th>IDs Calificaciones</th></tr>";
        
        foreach ($porEstudiante as $estudiante => $califs) {
            $materias = array_map(function($c) { return $c['materia_nombre']; }, $califs);
            $ids = array_map(function($c) { return $c['calificacion_id']; }, $califs);
            
            echo "<tr>
                    <td><strong>$estudiante</strong></td>
                    <td>" . implode('<br>', $materias) . "</td>
                    <td>" . implode(', ', $ids) . "</td>
                  </tr>";
        }
        echo "</table>";
    }
    
    // Mostrar asignaciones múltiples
    if (!empty($asignacionesMultiples)) {
        echo "<h3>⚠️ Estudiantes con Múltiples Asignaciones</h3>";
        
        echo "<table class='table table-warning table-sm'>
                <tr><th>Estudiante</th><th>Total</th><th>Materias Asignadas</th></tr>";
        
        foreach ($asignacionesMultiples as $mult) {
            echo "<tr>
                    <td><strong>{$mult['apellido']}, {$mult['nombre']}</strong></td>
                    <td>{$mult['total_asignaciones']}</td>
                    <td>" . str_replace(' | ', '<br>', $mult['materias_asignadas']) . "</td>
                  </tr>";
        }
        echo "</table>";
    }
}

// ============================================================================
// 3. CORRECCIÓN AUTOMÁTICA
// ============================================================================
echo "<h2>🛠️ 3. Corrección Automática</h2>";

if (isset($_POST['ejecutar_limpieza_masiva'])) {
    echo "<div class='alert alert-warning'><strong>🔄 EJECUTANDO LIMPIEZA MASIVA...</strong></div>";
    
    $resultados = [];
    
    try {
        // 1. Eliminar calificaciones huérfanas
        if (!empty($calificacionesHuerfanas)) {
            $idsCalificacionesHuerfanas = array_column($calificacionesHuerfanas, 'calificacion_id');
            $placeholdersCalif = implode(',', array_fill(0, count($idsCalificacionesHuerfanas), '?'));
            
            $eliminadas = $db->query(
                "DELETE FROM calificaciones WHERE id IN ($placeholdersCalif)",
                $idsCalificacionesHuerfanas
            );
            
            $resultados[] = "✅ Eliminadas " . count($idsCalificacionesHuerfanas) . " calificaciones huérfanas";
        }
        
        // 2. Corregir asignaciones múltiples (mantener solo 1 por estudiante)
        foreach ($asignacionesMultiples as $mult) {
            // Obtener todas las asignaciones de este estudiante en Construcción de la Ciudadanía
            $asignacionesEstudiante = $db->fetchAll(
                "SELECT ep.id, ep.materia_curso_id, m.nombre as materia_nombre,
                        ep.subgrupo, ep.periodo_inicio
                FROM estudiantes_por_materia ep
                JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
                JOIN materias m ON mp.materia_id = m.id
                WHERE ep.estudiante_id = ? AND ep.ciclo_lectivo_id = ? AND ep.activo = 1
                AND LOWER(m.nombre) LIKE '%constr%ciud%'
                ORDER BY ep.id ASC",
                [$mult['estudiante_id'], $cicloLectivoId]
            );
            
            if (count($asignacionesEstudiante) > 1) {
                // Mantener la primera asignación, desactivar las demás
                for ($i = 1; $i < count($asignacionesEstudiante); $i++) {
                    $db->query(
                        "UPDATE estudiantes_por_materia SET activo = 0 WHERE id = ?",
                        [$asignacionesEstudiante[$i]['id']]
                    );
                }
                
                $mantenida = $asignacionesEstudiante[0]['materia_nombre'];
                $desactivadas = count($asignacionesEstudiante) - 1;
                $resultados[] = "✅ {$mult['apellido']}: Mantenida '$mantenida', desactivadas $desactivadas asignaciones";
            }
        }
        
        // 3. Verificar resultado final
        $verificacionFinal = $db->fetchOne(
            "SELECT COUNT(*) as total FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             WHERE c.estudiante_id IN ($placeholdersIds)
             AND c.ciclo_lectivo_id = ?
             AND LOWER(m.nombre) LIKE '%constr%ciud%'
             AND NOT EXISTS (
                 SELECT 1 FROM estudiantes_por_materia ep 
                 WHERE ep.estudiante_id = c.estudiante_id 
                 AND ep.materia_curso_id = c.materia_curso_id 
                 AND ep.ciclo_lectivo_id = c.ciclo_lectivo_id
                 AND ep.activo = 1
             )",
            array_merge($idsEstudiantes, [$cicloLectivoId])
        );
        
        $resultados[] = "🔍 Verificación: " . $verificacionFinal['total'] . " calificaciones huérfanas restantes";
        
        echo "<div class='alert solucion'>
                <h5>✅ LIMPIEZA COMPLETADA</h5>
                <ul>";
        foreach ($resultados as $resultado) {
            echo "<li>$resultado</li>";
        }
        echo "</ul>
                <p><strong>Próximo paso:</strong> Actualizar las consultas en boletines.php para evitar que vuelva a pasar.</p>
              </div>";
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'><strong>❌ ERROR:</strong> " . $e->getMessage() . "</div>";
    }
    
} else {
    // Mostrar botón de limpieza
    $totalProblemas = count($calificacionesHuerfanas) + count($asignacionesMultiples);
    
    if ($totalProblemas > 0) {
        echo "<div class='alert problema'>
                <h5>📊 Resumen de Problemas:</h5>
                <ul>
                    <li><strong>" . count($calificacionesHuerfanas) . "</strong> calificaciones huérfanas a eliminar</li>
                    <li><strong>" . count($asignacionesMultiples) . "</strong> estudiantes con asignaciones múltiples a corregir</li>
                </ul>
              </div>";
        
        echo "<form method='POST' onsubmit='return confirm(\"¿EJECUTAR LIMPIEZA MASIVA?\\n\\n• Se eliminarán " . count($calificacionesHuerfanas) . " calificaciones huérfanas\\n• Se corregirán " . count($asignacionesMultiples) . " asignaciones múltiples\\n\\n¿Continuar?\")'>
                <button type='submit' name='ejecutar_limpieza_masiva' class='btn btn-danger btn-lg'>
                    🧹 Ejecutar Limpieza Masiva (22 estudiantes)
                </button>
              </form>";
        
        echo "<p><small><strong>⚠️ IMPORTANTE:</strong> Esta acción eliminará calificaciones y modificará asignaciones. Asegúrate de tener un backup.</small></p>";
    } else {
        echo "<div class='alert solucion'>
                <h5>✅ NO HAY PROBLEMAS</h5>
                <p>No se detectaron calificaciones huérfanas ni asignaciones múltiples en los estudiantes especificados.</p>
              </div>";
    }
}

// ============================================================================
// 4. CORRECCIÓN DEFINITIVA DE CÓDIGO
// ============================================================================
echo "<h2>💻 4. Corrección Definitiva - Actualizar Código</h2>";

echo "<div class='alert solucion'>
        <h5>🔧 Después de la limpieza, actualizar estos archivos:</h5>
        <ol>
            <li><strong>boletines.php</strong> - Cambiar consultas para usar asignaciones primero</li>
            <li><strong>vista_boletin_cuatrimestral.php</strong> - Corregir consulta principal</li>
            <li><strong>Configuración de Construcción Ciudadanía</strong> - Desactivar rotación automática</li>
        </ol>
      </div>";

echo "<div class='alert info'>
        <h6>📋 Cambio Principal Necesario:</h6>
        <p><strong>CAMBIAR DE:</strong></p>
        <pre>FROM calificaciones c
JOIN materias_por_curso mp ON c.materia_curso_id = mp.id</pre>
        
        <p><strong>CAMBIAR A:</strong></p>
        <pre>FROM estudiantes_por_materia ep
JOIN materias_por_curso mp ON ep.materia_curso_id = mp.id
LEFT JOIN calificaciones c ON ep.estudiante_id = c.estudiante_id AND ...</pre>
      </div>";

echo "</div></body></html>";
?>
