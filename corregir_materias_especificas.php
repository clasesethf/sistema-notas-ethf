<?php
/**
 * corregir_materias_especificas.php
 * Script para corregir las materias específicas con códigos y nombres correctos
 */

require_once 'config.php';

// Verificar permisos (solo admin)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('Acceso denegado. Solo administradores pueden ejecutar este script.');
}

$db = Database::getInstance();

echo "<h1>Corrección de Materias Específicas</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
    .btn-danger { background: #dc3545; }
    .preview { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0; }
</style>";

// Array con todas las materias a corregir
$materiasCorrectas = [
    '1PT1' => 'Metales 1',
    '1PT2' => 'Maderas 1',
    '1PT3' => 'Tecnología 1',
    '1LT1' => 'Informática 1',
    '1LT2' => 'Impresión 3D',
    '1LT3' => 'Dibujo Técnico 1',
    '1ST1' => 'Robótica 1',
    '1ST2' => 'Diseño Tecnológico',
    '1ST3' => 'Proyecto Tecnológico 1',
    '2PT1' => 'Metales 2',
    '2PT2' => 'Maderas 2',
    '2PT3' => 'Tecnología 2',
    '2LT1' => 'Dibujo Técnico 2',
    '2LT2' => 'Informática 2',
    '2ST1' => 'Robótica 2',
    '2ST2' => 'Fundamentos de Electricidad',
    '2ST3' => 'Proyecto Tecnológico 2',
    '3PT1' => 'Metales 3',
    '3PT2' => 'Fundición',
    '3PT3' => 'Tecnología 3',
    '3LT1' => 'Dibujo Técnico 3',
    '3LT2' => 'Informática 3',
    '3ST1' => 'Inst. Eléctr. Domicililarias 1',
    '3ST2' => 'Fluidos',
    '3ST3' => 'Proyecto Tecnológico 3',
    '3ST4' => 'Robótica 3',
    '4DT1' => 'Dibujo Técnológico',
    '4DT2' => 'Dibujo con Autocad',
    '4MEA1' => 'Electrónica Básica',
    '4MEA2' => 'Transformadores',
    '4MEA3' => 'Principios de Automatización',
    '4DPM1' => 'Metrología',
    '4DPM2' => 'Mecanizado 1',
    '4DPM3' => 'Mecanizado 2',
    '4IAE1' => 'Instalaciones Eléctricas Domicililarias 2',
    '4IAE2' => 'Soldadura',
    '4IAE3' => 'Electrotecnia',
    '5MEA1' => 'Contactores',
    '5MEA2' => 'Electrónica Digital',
    '5MEA3' => 'Motores Eléctricos',
    '5DPM1' => 'Mecanizado 3',
    '5DPM2' => 'Metalografía y Tratamientos Térmicos',
    '5DPM3' => 'CAD 1',
    '5IAE1' => 'Control de Fluidos 1',
    '5IAE2' => 'Control de Fluidos 2',
    '5IAE3' => 'Refrigeración',
    '6LME' => 'Laboratorio de Mediciones Eléctricas',
    '6MEA1' => 'Microcontroladores',
    '6MEA2' => 'PLC',
    '6MEA3' => 'Sistemas de Control',
    '6DPM1' => 'CNC Torno',
    '6DPM2' => 'CAD 2',
    '6DPM3' => 'CNC Fresadora',
    '6IAE1' => 'Motores de Combustión Interna 1',
    '6IAE2' => 'Luminotecnia',
    '6IAE3' => 'Distribución de Energía Eléctrica',
    '6IAE4' => 'MASTER',
    '7LMCC' => 'Laboratorio de Metrología y Control de Calidad',
    '7MME1' => 'Mantenimiento Mecánico',
    '7MME2' => 'Mantenimiento Edilicio',
    '7MME3' => 'Mantenimiento Eléctrico',
    '7PDE1' => 'CAD CAM',
    '7PDE2' => 'Desafío ECO',
    '7PDE3' => 'Diseño de Procesos',
    '7PDIE1' => 'Motores de Combustión Interna 2',
    '7PDIE2' => 'Robótica Industrial',
    '7PDIE3' => 'CAD 3',
    '7PDIE4' => 'Centrales Eléctricas'
];

// 1. Mostrar estado actual de las materias
echo "<h2>1. Estado actual de las materias en la base de datos</h2>";
$materiasActuales = $db->fetchAll("SELECT id, codigo, nombre FROM materias ORDER BY codigo");

echo "<table>";
echo "<tr><th>ID</th><th>Código Actual</th><th>Nombre Actual</th><th>Estado</th></tr>";

$materiasACorregir = [];
$materiasCorrectas_encontradas = 0;

foreach ($materiasActuales as $materia) {
    $necesitaCorreccion = false;
    $codigoCorrecto = null;
    $nombreCorrecto = null;
    
    // Buscar si esta materia necesita corrección
    foreach ($materiasCorrectas as $codigo => $nombre) {
        // Verificar por coincidencia de nombre parcial o código parcial
        if (stripos($materia['nombre'], $nombre) !== false || 
            stripos($materia['codigo'], $codigo) !== false ||
            stripos($materia['nombre'], $codigo) !== false) {
            $necesitaCorreccion = true;
            $codigoCorrecto = $codigo;
            $nombreCorrecto = $nombre;
            break;
        }
    }
    
    echo "<tr>";
    echo "<td>{$materia['id']}</td>";
    echo "<td>{$materia['codigo']}</td>";
    echo "<td>{$materia['nombre']}</td>";
    
    if ($necesitaCorreccion) {
        echo "<td class='warning'>Necesita corrección → <strong>$codigoCorrecto</strong>: <strong>$nombreCorrecto</strong></td>";
        $materiasACorregir[] = [
            'id' => $materia['id'],
            'codigo_actual' => $materia['codigo'],
            'nombre_actual' => $materia['nombre'],
            'codigo_correcto' => $codigoCorrecto,
            'nombre_correcto' => $nombreCorrecto
        ];
    } else {
        echo "<td class='success'>✓ Correcto</td>";
        $materiasCorrectas_encontradas++;
    }
    echo "</tr>";
}
echo "</table>";

echo "<p><strong>Resumen:</strong></p>";
echo "<ul>";
echo "<li>Materias correctas: $materiasCorrectas_encontradas</li>";
echo "<li>Materias que necesitan corrección: " . count($materiasACorregir) . "</li>";
echo "<li>Total de materias esperadas: " . count($materiasCorrectas) . "</li>";
echo "</ul>";

// 2. Mostrar vista previa de las correcciones
if (!empty($materiasACorregir)) {
    echo "<h2>2. Vista previa de correcciones</h2>";
    echo "<div class='preview'>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Cambio de Código</th><th>Cambio de Nombre</th></tr>";
    
    foreach ($materiasACorregir as $correccion) {
        echo "<tr>";
        echo "<td>{$correccion['id']}</td>";
        echo "<td><span style='color: red;'>{$correccion['codigo_actual']}</span> → <span style='color: green;'>{$correccion['codigo_correcto']}</span></td>";
        echo "<td><span style='color: red;'>{$correccion['nombre_actual']}</span> → <span style='color: green;'>{$correccion['nombre_correcto']}</span></td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
}

// 3. Ejecutar correcciones
if (isset($_POST['ejecutar_correcciones'])) {
    echo "<h2>3. Ejecutando correcciones...</h2>";
    
    $correccionesExitosas = 0;
    $errores = 0;
    $erroresDetalle = [];
    
    // Verificar primero todos los códigos para evitar conflictos
    foreach ($materiasACorregir as $correccion) {
        $codigoExistente = $db->fetchOne(
            "SELECT id FROM materias WHERE codigo = ? AND id != ?",
            [$correccion['codigo_correcto'], $correccion['id']]
        );
        
        if ($codigoExistente) {
            $erroresDetalle[] = "El código '{$correccion['codigo_correcto']}' ya está en uso por la materia ID {$codigoExistente['id']}";
            $errores++;
        }
    }
    
    // Si hay errores de códigos duplicados, no continuar
    if ($errores > 0) {
        echo "<div class='error'><h3>⚠️ Errores detectados - No se ejecutaron cambios:</h3>";
        foreach ($erroresDetalle as $error) {
            echo "<p>❌ $error</p>";
        }
        echo "</div>";
    } else {
        // Ejecutar las correcciones una por una
        foreach ($materiasACorregir as $correccion) {
            try {
                // Ejecutar la actualización
                $resultado = $db->query(
                    "UPDATE materias SET codigo = ?, nombre = ? WHERE id = ?",
                    [$correccion['codigo_correcto'], $correccion['nombre_correcto'], $correccion['id']]
                );
                
                if ($resultado !== false) {
                    echo "<div class='success'>✅ Materia ID {$correccion['id']}: '{$correccion['codigo_actual']}' → '{$correccion['codigo_correcto']}' | '{$correccion['nombre_actual']}' → '{$correccion['nombre_correcto']}'</div>";
                    $correccionesExitosas++;
                } else {
                    echo "<div class='error'>❌ Error al actualizar materia ID {$correccion['id']}</div>";
                    $errores++;
                }
            } catch (Exception $e) {
                echo "<div class='error'>❌ Error al actualizar materia ID {$correccion['id']}: " . $e->getMessage() . "</div>";
                $errores++;
            }
        }
        
        if ($errores > 0) {
            echo "<div class='warning'><h3>⚠️ Se completó con $errores errores y $correccionesExitosas correcciones exitosas.</h3></div>";
        } else {
            echo "<div class='success'><h3>🎉 ¡Correcciones completadas exitosamente!</h3></div>";
            echo "<p>Se corrigieron <strong>$correccionesExitosas</strong> materias sin errores.</p>";
        }
    }
    
    // Mostrar estado final
    echo "<h3>Estado final:</h3>";
    $materiasFinales = $db->fetchAll("SELECT id, codigo, nombre FROM materias ORDER BY codigo");
    echo "<table>";
    echo "<tr><th>ID</th><th>Código</th><th>Nombre</th></tr>";
    foreach ($materiasFinales as $materia) {
        $esCorrect = isset($materiasCorrectas[$materia['codigo']]) && $materiasCorrectas[$materia['codigo']] === $materia['nombre'];
        $clase = $esCorrect ? 'success' : '';
        echo "<tr class='$clase'>";
        echo "<td>{$materia['id']}</td>";
        echo "<td>{$materia['codigo']}</td>";
        echo "<td>{$materia['nombre']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} else if (!empty($materiasACorregir)) {
    // Mostrar formulario de confirmación
    echo "<h2>3. ¿Ejecutar correcciones?</h2>";
    echo "<div class='warning'>";
    echo "<p><strong>⚠️ IMPORTANTE:</strong></p>";
    echo "<ul>";
    echo "<li>Se van a modificar " . count($materiasACorregir) . " materias</li>";
    echo "<li>Esta acción es permanente</li>";
    echo "<li>Se recomienda hacer un backup de la base de datos antes de continuar</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<form method='POST'>";
    echo "<button type='submit' name='ejecutar_correcciones' class='btn'>✅ SÍ, ejecutar correcciones</button>";
    echo "<a href='materias.php' class='btn btn-danger'>❌ Cancelar y volver</a>";
    echo "</form>";
}

// 4. Crear materias faltantes
$materiasEnBD = [];
foreach ($materiasActuales as $materia) {
    $materiasEnBD[$materia['codigo']] = $materia['nombre'];
}

$materiasFaltantes = [];
foreach ($materiasCorrectas as $codigo => $nombre) {
    if (!isset($materiasEnBD[$codigo])) {
        $materiasFaltantes[$codigo] = $nombre;
    }
}

if (!empty($materiasFaltantes)) {
    echo "<h2>4. Materias faltantes (no encontradas en BD)</h2>";
    echo "<p class='warning'>Las siguientes materias no fueron encontradas en la base de datos:</p>";
    echo "<table>";
    echo "<tr><th>Código</th><th>Nombre</th><th>Acción</th></tr>";
    foreach ($materiasFaltantes as $codigo => $nombre) {
        echo "<tr>";
        echo "<td>$codigo</td>";
        echo "<td>$nombre</td>";
        echo "<td><em>Crear manualmente desde el panel de materias</em></td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<p><a href='materias.php' class='btn'>← Volver a gestión de materias</a></p>";
?>