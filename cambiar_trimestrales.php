<?php
/**
 * Script para cambiar todas las materias trimestrales a anuales
 * CON DETECCIÓN AUTOMÁTICA DE ESTRUCTURA DE TABLA
 * Ejecutar desde navegador web - SQLite
 * 
 * IMPORTANTE: Hacer backup de la base de datos antes de ejecutar
 */

// Configuración de la base de datos (ajustar según tu configuración)
$db_path = 'database.db'; // Cambia por la ruta real de tu base de datos SQLite

// Verificar si existe config.php y usarlo
if (file_exists('config.php')) {
    require_once 'config.php';
    // Si tienes una clase Database como en los archivos mostrados
    if (class_exists('Database')) {
        $db = Database::getInstance();
    }
} else {
    // Conexión directa a SQLite
    try {
        $db_connection = new PDO("sqlite:$db_path");
        $db_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("Error de conexión: " . $e->getMessage());
    }
}

// Función para ejecutar consultas (adaptada para ambos tipos de conexión)
function ejecutarConsulta($consulta, $parametros = []) {
    global $db, $db_connection;
    
    try {
        if (isset($db) && method_exists($db, 'query')) {
            // Usando la clase Database personalizada
            return $db->query($consulta, $parametros);
        } else {
            // Usando PDO directo
            $stmt = $db_connection->prepare($consulta);
            return $stmt->execute($parametros);
        }
    } catch (Exception $e) {
        throw new Exception("Error en la consulta: " . $e->getMessage() . " - SQL: " . $consulta);
    }
}

function obtenerRegistros($consulta, $parametros = []) {
    global $db, $db_connection;
    
    try {
        if (isset($db) && method_exists($db, 'fetchAll')) {
            return $db->fetchAll($consulta, $parametros);
        } else {
            $stmt = $db_connection->prepare($consulta);
            $stmt->execute($parametros);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        throw new Exception("Error en la consulta: " . $e->getMessage() . " - SQL: " . $consulta);
    }
}

// Función para detectar la estructura de la base de datos
function detectarEstructura() {
    // Obtener todas las tablas
    $tablas = obtenerRegistros("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    
    $estructura = [
        'tablas_encontradas' => [],
        'tabla_objetivo' => null,
        'columnas' => [],
        'datos_muestra' => []
    ];
    
    foreach ($tablas as $tabla) {
        $nombre_tabla = $tabla['name'];
        $estructura['tablas_encontradas'][] = $nombre_tabla;
        
        // Buscar tablas que podrían contener los datos que necesitamos
        if (strpos($nombre_tabla, 'grupo') !== false || 
            strpos($nombre_tabla, 'materia') !== false ||
            strpos($nombre_tabla, 'asignatura') !== false) {
            
            // Obtener información de columnas de esta tabla
            $info_columnas = obtenerRegistros("PRAGMA table_info($nombre_tabla)");
            $columnas_tabla = [];
            
            foreach ($info_columnas as $col) {
                $columnas_tabla[] = $col['name'];
            }
            
            // Verificar si tiene columnas que parecen las que necesitamos
            $tiene_tipo_duracion = false;
            $tiene_trimestre = false;
            $columna_tipo = null;
            $columna_trimestre = null;
            
            foreach ($columnas_tabla as $col) {
                if (strpos($col, 'tipo') !== false && (strpos($col, 'duracion') !== false || strpos($col, 'duration') !== false)) {
                    $tiene_tipo_duracion = true;
                    $columna_tipo = $col;
                }
                if (strpos($col, 'trimestre') !== false || strpos($col, 'quarter') !== false) {
                    $tiene_trimestre = true;
                    $columna_trimestre = $col;
                }
            }
            
            // Si esta tabla parece ser la correcta, obtener datos de muestra
            if ($tiene_tipo_duracion || count($columnas_tabla) >= 7) { // Al menos 7 columnas como en tu ejemplo
                try {
                    $muestra = obtenerRegistros("SELECT * FROM $nombre_tabla LIMIT 5");
                    
                    $estructura['tabla_objetivo'] = $nombre_tabla;
                    $estructura['columnas'] = $columnas_tabla;
                    $estructura['datos_muestra'] = $muestra;
                    $estructura['columna_tipo'] = $columna_tipo;
                    $estructura['columna_trimestre'] = $columna_trimestre;
                    
                    // Si encontramos datos que coinciden con el patrón, parar aquí
                    if (!empty($muestra)) {
                        $primer_registro = $muestra[0];
                        foreach ($primer_registro as $key => $value) {
                            if ($value === 'trimestral' || $value === 'anual') {
                                $estructura['columna_tipo'] = $key;
                                break 2; // Salir de ambos loops
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Si hay error con esta tabla, continuar con la siguiente
                    continue;
                }
            }
        }
    }
    
    return $estructura;
}

// Verificar parámetros
$detectar = !isset($_GET['tabla']) || $_GET['tabla'] === '';
$mostrar_vista_previa = isset($_GET['vista_previa']) && $_GET['vista_previa'] === '1';
$ejecutar = isset($_GET['ejecutar']) && $_GET['ejecutar'] === 'confirmar';
$tabla_seleccionada = $_GET['tabla'] ?? '';
$columna_tipo = $_GET['col_tipo'] ?? '';
$columna_trimestre = $_GET['col_trimestre'] ?? '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Materias Trimestrales a Anuales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .warning-box {
            border-left: 5px solid #ffc107;
            background: #fff3cd;
            padding: 20px;
            margin: 20px 0;
        }
        .success-box {
            border-left: 5px solid #28a745;
            background: #d4edda;
            padding: 20px;
            margin: 20px 0;
        }
        .danger-box {
            border-left: 5px solid #dc3545;
            background: #f8d7da;
            padding: 20px;
            margin: 20px 0;
        }
        .info-box {
            border-left: 5px solid #17a2b8;
            background: #d1ecf1;
            padding: 20px;
            margin: 20px 0;
        }
        .table-preview {
            max-height: 400px;
            overflow-y: auto;
        }
        .table-responsive-sm {
            font-size: 0.85rem;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="bi bi-database-gear"></i> 
                            Script: Cambiar Materias Trimestrales a Anuales
                        </h4>
                    </div>
                    <div class="card-body">
                        
                        <?php if ($detectar): ?>
                        <!-- Detección automática de estructura -->
                        <div class="info-box">
                            <h5><i class="bi bi-search"></i> Detectando Estructura de la Base de Datos</h5>
                            <p>Analizando las tablas para encontrar los datos correctos...</p>
                        </div>

                        <?php
                        try {
                            $estructura = detectarEstructura();
                            
                            echo '<div class="warning-box">';
                            echo '<h6>Tablas Encontradas en la Base de Datos:</h6>';
                            echo '<div class="row">';
                            foreach ($estructura['tablas_encontradas'] as $tabla) {
                                $clase = ($tabla === $estructura['tabla_objetivo']) ? 'bg-success' : 'bg-secondary';
                                echo "<div class='col-md-3 mb-2'><span class='badge $clase'>$tabla</span></div>";
                            }
                            echo '</div>';
                            echo '</div>';
                            
                            if ($estructura['tabla_objetivo']) {
                                echo '<div class="success-box">';
                                echo '<h6><i class="bi bi-check-circle"></i> Tabla Candidata Encontrada: <code>' . $estructura['tabla_objetivo'] . '</code></h6>';
                                echo '<p><strong>Columnas detectadas:</strong></p>';
                                echo '<div class="row">';
                                foreach ($estructura['columnas'] as $col) {
                                    $clase = 'bg-info';
                                    if ($col === $estructura['columna_tipo']) $clase = 'bg-warning';
                                    if ($col === $estructura['columna_trimestre']) $clase = 'bg-primary';
                                    echo "<div class='col-md-2 mb-1'><span class='badge $clase'>$col</span></div>";
                                }
                                echo '</div>';
                                
                                if ($estructura['columna_tipo']) {
                                    echo "<p><span class='badge bg-warning'>Columna tipo detectada:</span> {$estructura['columna_tipo']}</p>";
                                }
                                if ($estructura['columna_trimestre']) {
                                    echo "<p><span class='badge bg-primary'>Columna trimestre detectada:</span> {$estructura['columna_trimestre']}</p>";
                                }
                                echo '</div>';
                                
                                // Mostrar datos de muestra
                                if (!empty($estructura['datos_muestra'])) {
                                    echo '<div class="info-box">';
                                    echo '<h6>Datos de Muestra (primeros 5 registros):</h6>';
                                    echo '<div class="table-responsive table-preview">';
                                    echo '<table class="table table-sm table-striped">';
                                    echo '<thead class="table-dark"><tr>';
                                    foreach ($estructura['columnas'] as $col) {
                                        echo "<th>$col</th>";
                                    }
                                    echo '</tr></thead><tbody>';
                                    
                                    foreach ($estructura['datos_muestra'] as $fila) {
                                        echo '<tr>';
                                        foreach ($fila as $valor) {
                                            $clase = '';
                                            if ($valor === 'trimestral') $clase = 'class="bg-warning"';
                                            if ($valor === 'anual') $clase = 'class="bg-success"';
                                            echo "<td $clase>" . htmlspecialchars($valor) . "</td>";
                                        }
                                        echo '</tr>';
                                    }
                                    echo '</tbody></table>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                                
                                // Formulario para confirmar la detección o corregir
                                echo '<form method="GET" class="mt-4">';
                                echo '<div class="row">';
                                echo '<div class="col-md-4">';
                                echo '<label class="form-label">Tabla a modificar:</label>';
                                echo '<select name="tabla" class="form-select" required>';
                                foreach ($estructura['tablas_encontradas'] as $tabla) {
                                    $selected = ($tabla === $estructura['tabla_objetivo']) ? 'selected' : '';
                                    echo "<option value='$tabla' $selected>$tabla</option>";
                                }
                                echo '</select>';
                                echo '</div>';
                                
                                echo '<div class="col-md-4">';
                                echo '<label class="form-label">Columna de tipo de duración:</label>';
                                echo '<select name="col_tipo" class="form-select" required>';
                                foreach ($estructura['columnas'] as $col) {
                                    $selected = ($col === $estructura['columna_tipo']) ? 'selected' : '';
                                    echo "<option value='$col' $selected>$col</option>";
                                }
                                echo '</select>';
                                echo '</div>';
                                
                                echo '<div class="col-md-4">';
                                echo '<label class="form-label">Columna de trimestre:</label>';
                                echo '<select name="col_trimestre" class="form-select" required>';
                                foreach ($estructura['columnas'] as $col) {
                                    $selected = ($col === $estructura['columna_trimestre']) ? 'selected' : '';
                                    echo "<option value='$col' $selected>$col</option>";
                                }
                                echo '</select>';
                                echo '</div>';
                                
                                echo '</div>';
                                echo '<div class="d-grid gap-2 d-md-flex justify-content-md-center mt-3">';
                                echo '<button type="submit" name="vista_previa" value="1" class="btn btn-info btn-lg">';
                                echo '<i class="bi bi-eye"></i> Continuar con Vista Previa';
                                echo '</button>';
                                echo '</div>';
                                echo '</form>';
                                
                            } else {
                                echo '<div class="danger-box">';
                                echo '<h6><i class="bi bi-exclamation-triangle"></i> No se pudo detectar automáticamente la tabla correcta</h6>';
                                echo '<p>Por favor, seleccione manualmente:</p>';
                                
                                echo '<form method="GET">';
                                echo '<div class="row">';
                                echo '<div class="col-md-6">';
                                echo '<label class="form-label">Seleccione la tabla:</label>';
                                echo '<select name="tabla" class="form-select" required>';
                                echo '<option value="">-- Seleccionar tabla --</option>';
                                foreach ($estructura['tablas_encontradas'] as $tabla) {
                                    echo "<option value='$tabla'>$tabla</option>";
                                }
                                echo '</select>';
                                echo '</div>';
                                echo '<div class="col-md-6">';
                                echo '<button type="submit" name="vista_previa" value="1" class="btn btn-primary">';
                                echo 'Analizar Tabla Seleccionada';
                                echo '</button>';
                                echo '</div>';
                                echo '</div>';
                                echo '</form>';
                                echo '</div>';
                            }
                            
                        } catch (Exception $e) {
                            echo '<div class="danger-box">';
                            echo '<h6><i class="bi bi-exclamation-triangle"></i> Error al detectar estructura</h6>';
                            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
                            echo '<p>Por favor verifique la configuración de la base de datos.</p>';
                            echo '</div>';
                        }
                        ?>

                        <?php elseif ($mostrar_vista_previa && $tabla_seleccionada): ?>
                        <!-- Vista previa de cambios -->
                        <div class="warning-box">
                            <h5><i class="bi bi-eye"></i> Vista Previa de Cambios</h5>
                            <p><strong>Tabla:</strong> <code><?= htmlspecialchars($tabla_seleccionada) ?></code></p>
                            <p><strong>Columna tipo:</strong> <code><?= htmlspecialchars($columna_tipo) ?></code></p>
                            <p><strong>Columna trimestre:</strong> <code><?= htmlspecialchars($columna_trimestre) ?></code></p>
                        </div>

                        <?php
                        try {
                            // Verificar que la tabla y columnas existan
                            $info_tabla = obtenerRegistros("PRAGMA table_info($tabla_seleccionada)");
                            $columnas_existentes = array_column($info_tabla, 'name');
                            
                            if (!in_array($columna_tipo, $columnas_existentes)) {
                                throw new Exception("La columna '$columna_tipo' no existe en la tabla '$tabla_seleccionada'");
                            }
                            
                            if (!in_array($columna_trimestre, $columnas_existentes)) {
                                throw new Exception("La columna '$columna_trimestre' no existe en la tabla '$tabla_seleccionada'");
                            }
                            
                            // Obtener registros que serán modificados
                            $registros_a_cambiar = obtenerRegistros("
                                SELECT * FROM $tabla_seleccionada 
                                WHERE $columna_tipo = 'trimestral'
                                ORDER BY id
                            ");

                            if (!empty($registros_a_cambiar)): ?>
                            <div class="success-box">
                                <h6>Se encontraron <strong><?= count($registros_a_cambiar) ?></strong> registros para modificar</h6>
                            </div>
                            
                            <div class="table-preview">
                                <table class="table table-striped table-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <?php foreach ($columnas_existentes as $col): ?>
                                            <th><?= htmlspecialchars($col) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($registros_a_cambiar, 0, 10) as $registro): ?>
                                        <tr>
                                            <?php foreach ($registro as $key => $valor): ?>
                                            <td class="<?= 
                                                ($key === $columna_tipo && $valor === 'trimestral') ? 'bg-warning' : 
                                                ($key === $columna_trimestre ? 'bg-info' : '') 
                                            ?>">
                                                <?= htmlspecialchars($valor) ?>
                                                <?php if ($key === $columna_tipo && $valor === 'trimestral'): ?>
                                                    <br><small class="text-success">→ anual</small>
                                                <?php elseif ($key === $columna_trimestre && $valor != '1'): ?>
                                                    <br><small class="text-primary">→ 1</small>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (count($registros_a_cambiar) > 10): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                Mostrando los primeros 10 de <?= count($registros_a_cambiar) ?> registros que serán modificados.
                            </div>
                            <?php endif; ?>

                            <div class="info-box">
                                <h6>Cambios que se aplicarán:</h6>
                                <ul>
                                    <li><code><?= htmlspecialchars($columna_tipo) ?></code>: "trimestral" → "anual"</li>
                                    <li><code><?= htmlspecialchars($columna_trimestre) ?></code>: (cualquier valor) → 1</li>
                                    <li>Todas las demás columnas permanecen sin cambios</li>
                                </ul>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <a href="?" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </a>
                                <a href="?ejecutar=confirmar&tabla=<?= urlencode($tabla_seleccionada) ?>&col_tipo=<?= urlencode($columna_tipo) ?>&col_trimestre=<?= urlencode($columna_trimestre) ?>" 
                                   class="btn btn-danger btn-lg" 
                                   onclick="return confirm('¿ESTÁ SEGURO de ejecutar estos cambios?\n\n<?= count($registros_a_cambiar) ?> registros serán modificados PERMANENTEMENTE.\n\n¿Ha hecho un backup de la base de datos?\n\nEsta acción NO se puede deshacer.')">
                                    <i class="bi bi-database-gear"></i> EJECUTAR CAMBIOS
                                </a>
                            </div>

                            <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                No se encontraron registros con <?= htmlspecialchars($columna_tipo) ?> = 'trimestral' para modificar.
                            </div>
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <a href="?" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Volver
                                </a>
                            </div>
                            <?php endif; ?>

                        <?php
                        } catch (Exception $e) {
                            echo '<div class="danger-box">';
                            echo '<h6><i class="bi bi-exclamation-triangle"></i> Error en Vista Previa</h6>';
                            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
                            echo '</div>';
                            echo '<div class="d-grid gap-2 d-md-flex justify-content-md-center">';
                            echo '<a href="?" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Volver</a>';
                            echo '</div>';
                        }

                        elseif ($ejecutar && $tabla_seleccionada):
                        ?>
                        <!-- Ejecución de cambios -->
                        <div class="success-box">
                            <h5><i class="bi bi-gear"></i> Ejecutando Cambios...</h5>
                            <p><strong>Tabla:</strong> <?= htmlspecialchars($tabla_seleccionada) ?></p>
                        </div>

                        <?php
                        try {
                            // Contar registros antes del cambio
                            $antes = obtenerRegistros("
                                SELECT COUNT(*) as total 
                                FROM $tabla_seleccionada 
                                WHERE $columna_tipo = 'trimestral'
                            ");
                            $total_antes = $antes[0]['total'] ?? 0;

                            if ($total_antes > 0) {
                                // Ejecutar la actualización
                                $sql_update = "
                                    UPDATE $tabla_seleccionada 
                                    SET $columna_tipo = 'anual', 
                                        $columna_trimestre = 1 
                                    WHERE $columna_tipo = 'trimestral'
                                ";
                                
                                $resultado = ejecutarConsulta($sql_update);

                                // Verificar después del cambio
                                $despues = obtenerRegistros("
                                    SELECT COUNT(*) as total 
                                    FROM $tabla_seleccionada 
                                    WHERE $columna_tipo = 'trimestral'
                                ");
                                $total_despues = $despues[0]['total'] ?? 0;

                                // Verificar registros anuales
                                $anuales = obtenerRegistros("
                                    SELECT COUNT(*) as total 
                                    FROM $tabla_seleccionada 
                                    WHERE $columna_tipo = 'anual'
                                ");
                                $total_anuales = $anuales[0]['total'] ?? 0;

                                if ($resultado && $total_despues == 0) {
                                    echo '<div class="success-box">';
                                    echo '<h5><i class="bi bi-check-circle"></i> ¡Cambios Ejecutados Exitosamente!</h5>';
                                    echo '<ul>';
                                    echo "<li><strong>$total_antes</strong> registros fueron modificados</li>";
                                    echo "<li>Registros trimestrales restantes: <strong>$total_despues</strong></li>";
                                    echo "<li>Total de registros anuales ahora: <strong>$total_anuales</strong></li>";
                                    echo '<li>SQL ejecutado: <code>' . htmlspecialchars($sql_update) . '</code></li>';
                                    echo '</ul>';
                                    echo '</div>';

                                    // Mostrar muestra de registros modificados
                                    $muestra = obtenerRegistros("
                                        SELECT * FROM $tabla_seleccionada 
                                        WHERE $columna_tipo = 'anual' AND $columna_trimestre = 1
                                        ORDER BY id 
                                        LIMIT 5
                                    ");

                                    if (!empty($muestra)) {
                                        echo '<div class="info-box">';
                                        echo '<h6>Muestra de Registros Modificados:</h6>';
                                        echo '<div class="table-responsive">';
                                        echo '<table class="table table-sm">';
                                        echo '<thead><tr>';
                                        foreach (array_keys($muestra[0]) as $col) {
                                            echo "<th>$col</th>";
                                        }
                                        echo '</tr></thead><tbody>';
                                        foreach ($muestra as $reg) {
                                            echo "<tr>";
                                            foreach ($reg as $key => $value) {
                                                $clase = '';
                                                if ($key === $columna_tipo) $clase = 'class="bg-success"';
                                                if ($key === $columna_trimestre) $clase = 'class="bg-primary text-white"';
                                                echo "<td $clase>" . htmlspecialchars($value) . "</td>";
                                            }
                                            echo "</tr>";
                                        }
                                        echo '</tbody></table>';
                                        echo '</div>';
                                        echo '</div>';
                                    }

                                } else {
                                    echo '<div class="danger-box">';
                                    echo '<h5><i class="bi bi-exclamation-triangle"></i> Resultado Inesperado</h5>';
                                    echo "<ul>";
                                    echo "<li>Registros antes: $total_antes</li>";
                                    echo "<li>Registros después: $total_despues</li>";
                                    echo "<li>Total anuales: $total_anuales</li>";
                                    echo "<li>SQL ejecutado: <code>" . htmlspecialchars($sql_update) . "</code></li>";
                                    echo "</ul>";
                                    echo '</div>';
                                }

                            } else {
                                echo '<div class="alert alert-info">';
                                echo '<i class="bi bi-info-circle"></i> ';
                                echo 'No se encontraron registros trimestral para modificar.';
                                echo '</div>';
                            }

                        } catch (Exception $e) {
                            echo '<div class="danger-box">';
                            echo '<h5><i class="bi bi-exclamation-triangle"></i> Error Durante la Ejecución</h5>';
                            echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
                            echo '</div>';
                        }
                        ?>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-center mt-4">
                            <a href="?" class="btn btn-primary">
                                <i class="bi bi-arrow-clockwise"></i> Volver al Inicio
                            </a>
                        </div>

                        <?php endif; ?>

                    </div>
                    <div class="card-footer bg-light">
                        <small class="text-muted">
                            <i class="bi bi-clock"></i> 
                            Ejecutado el: <?= date('d/m/Y H:i:s') ?> | 
                            <i class="bi bi-database"></i> 
                            Base de datos: SQLite | 
                            <i class="bi bi-table"></i>
                            Tabla actual: <?= htmlspecialchars($tabla_seleccionada ?: 'Detectando...') ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>