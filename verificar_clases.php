<?php
/**
 * verificar_clases.php - Verificar conflictos de clases
 */

echo "<h2>Verificación de archivos y clases</h2>";

// Verificar si las clases ya están definidas
echo "<h3>Estado de clases:</h3>";
echo "Clase CalculadorCalificaciones: " . (class_exists('CalculadorCalificaciones') ? 'YA DEFINIDA' : 'No definida') . "<br>";
echo "Clase SistemaPeriodos: " . (class_exists('SistemaPeriodos') ? 'YA DEFINIDA' : 'No definida') . "<br>";

// Verificar contenido de archivos
echo "<h3>Verificando archivos:</h3>";

// Verificar sistema_calculo_automatico.php
if (file_exists('sistema_calculo_automatico.php')) {
    echo "<h4>sistema_calculo_automatico.php:</h4>";
    $contenido = file_get_contents('sistema_calculo_automatico.php');
    
    // Buscar declaraciones de clase
    if (preg_match('/class\s+(\w+)/i', $contenido, $matches)) {
        echo "Declara clase: " . $matches[1] . "<br>";
    }
    
    // Verificar si incluye otros archivos
    if (preg_match_all('/require(_once)?\s*[\'"]([^\'"]+)[\'"]/i', $contenido, $matches)) {
        echo "Incluye archivos: " . implode(', ', $matches[2]) . "<br>";
    }
} else {
    echo "sistema_calculo_automatico.php NO EXISTE<br>";
}

// Verificar sistema_periodos_automaticos.php
if (file_exists('sistema_periodos_automaticos.php')) {
    echo "<h4>sistema_periodos_automaticos.php:</h4>";
    $contenido = file_get_contents('sistema_periodos_automaticos.php');
    
    // Buscar declaraciones de clase
    if (preg_match_all('/class\s+(\w+)/i', $contenido, $matches)) {
        echo "Declara clases: " . implode(', ', $matches[1]) . "<br>";
        
        // Si declara CalculadorCalificaciones, mostrar las primeras líneas
        if (in_array('CalculadorCalificaciones', $matches[1])) {
            echo "<p style='color: red;'>¡PROBLEMA ENCONTRADO! Este archivo declara CalculadorCalificaciones</p>";
            $lineas = explode("\n", $contenido);
            echo "<pre style='background: #f0f0f0; padding: 10px;'>";
            for ($i = 0; $i < min(20, count($lineas)); $i++) {
                echo htmlspecialchars(($i+1) . ": " . $lineas[$i]) . "\n";
            }
            echo "</pre>";
        }
    }
} else {
    echo "sistema_periodos_automaticos.php NO EXISTE<br>";
}

// Verificar contenidos_guardar.php
if (file_exists('contenidos_guardar.php')) {
    echo "<h4>contenidos_guardar.php:</h4>";
    $contenido = file_get_contents('contenidos_guardar.php');
    
    // Verificar includes
    if (preg_match_all('/require(_once)?\s*[\'"]([^\'"]+)[\'"]/i', $contenido, $matches)) {
        echo "Incluye archivos: " . implode(', ', $matches[2]) . "<br>";
    }
}

echo "<hr>";
echo "<p><strong>Solución recomendada:</strong></p>";
echo "<ol>";
echo "<li>Usar siempre require_once en lugar de require para evitar inclusiones duplicadas</li>";
echo "<li>Verificar que cada archivo declare solo las clases que le corresponden</li>";
echo "<li>Si hay código duplicado, eliminarlo del archivo incorrecto</li>";
echo "</ol>";
?>