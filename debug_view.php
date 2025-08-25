?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Boletín</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .debug-item { border: 1px solid #ccc; margin: 10px 0; padding: 15px; background: #f9f9f9; }
        .grupo { background: #e7f3ff; border-color: #0066cc; }
        .materia { background: #fff7e6; border-color: #ff9900; }
        .field { margin: 5px 0; }
        .null { color: #999; font-style: italic; }
        .value { font-weight: bold; color: #333; }
    </style>
</head>
<body>
    <h1>🔍 Debug Boletín PDF</h1>
    
    <?php
    require_once 'config.php';
    
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo "<p>❌ Solo administradores pueden ver esta página.</p>";
        exit;
    }
    
    if (isset($_SESSION['debug_boletin'])) {
        $debug = $_SESSION['debug_boletin'];
        
        echo "<div class='debug-item'>";
        echo "<h2>📊 Resumen General</h2>";
        echo "<p><strong>Timestamp:</strong> " . $debug['timestamp'] . "</p>";
        echo "<p><strong>Total items:</strong> " . $debug['total_items'] . "</p>";
        echo "<p><strong>Año estudiante:</strong> " . $debug['estudiante_anio'] . "</p>";
        echo "</div>";
        
        echo "<h2>🔍 Primeros Items Obtenidos:</h2>";
        
        foreach ($debug['primeros_items'] as $index => $item) {
            $esGrupo = isset($item['es_grupo']) && $item['es_grupo'];
            $clase = $esGrupo ? 'grupo' : 'materia';
            
            echo "<div class='debug-item $clase'>";
            echo "<h3>Item $index " . ($esGrupo ? '(GRUPO)' : '(MATERIA)') . "</h3>";
            
            echo "<div class='field'>";
            echo "<strong>nombre:</strong> ";
            echo "<span class='" . (isset($item['nombre']) && !empty($item['nombre']) ? 'value' : 'null') . "'>";
            echo ($item['nombre'] ?? 'NULL');
            echo "</span></div>";
            
            echo "<div class='field'>";
            echo "<strong>materia_nombre:</strong> ";
            echo "<span class='" . (isset($item['materia_nombre']) && !empty($item['materia_nombre']) ? 'value' : 'null') . "'>";
            echo ($item['materia_nombre'] ?? 'NULL');
            echo "</span></div>";
            
            echo "<div class='field'>";
            echo "<strong>anio_curso:</strong> ";
            echo "<span class='" . (isset($item['anio_curso']) && !empty($item['anio_curso']) ? 'value' : 'null') . "'>";
            echo ($item['anio_curso'] ?? 'NULL');
            echo "</span></div>";
            
            echo "<div class='field'>";
            echo "<strong>materia_anio:</strong> ";
            echo "<span class='" . (isset($item['materia_anio']) && !empty($item['materia_anio']) ? 'value' : 'null') . "'>";
            echo ($item['materia_anio'] ?? 'NULL');
            echo "</span></div>";
            
            echo "<div class='field'>";
            echo "<strong>Todos los campos:</strong> " . implode(', ', array_keys($item));
            echo "</div>";
            
            echo "</div>";
        }
        
        unset($_SESSION['debug_boletin']); // Limpiar debug después de mostrar
    } else {
        echo "<p>❌ No hay datos de debug disponibles.</p>";
        echo "<p>Ve a un boletín y agrega <code>?debug_session=1</code> a la URL</p>";
    }
    ?>
    
    <p><a href="boletines.php">🔙 Volver a Boletines</a></p>
</body>
</html>

<?php
