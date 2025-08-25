<?php
/**
 * importar_profesores.php - Importación masiva de profesores
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Inicializar variables
$successMessage = '';
$errorMessage = '';
$previewData = [];
$profesoresTexto = '';

// Lista predefinida de profesores de la Escuela Técnica Henry Ford
$profesoresPredefinidos = [
    'Alzugaray, Matilde',
    'Arias, Gabriel',
    'Bálsamo, Rosana',
    'Bangert, Sergio',
    'Barrio, Daniel',
    'Belmonte, Jorge',
    'Cardalda, Brian',
    'Castro, Gonzalo',
    'Catalano, Marina',
    'Chacón, Martín',
    'Cid de Lopez, Marta',
    'Darget, Marcelo',
    'Dottori, Daniel',
    'Fernandez, Francisco',
    'Ferrari, Paula',
    'Galdeano, Federico',
    'Gendra, Alejandro',
    'Gómez, Maria Victoria',
    'Iglesias, Federico',
    'Iuorio, Daniela',
    'Kiahiayan, Juan Martín',
    'Kloster, Edgardo',
    'Lago, Ezequiel',
    'Lanfranco, Pablo',
    'Manniello, Alejandro',
    'Maristany, Cecilia',
    'Mellino, Javier',
    'Miño, Carlos',
    'Montoto, Diego',
    'Neto, Marcelo',
    'Ojeda, Daniel',
    'Paz, Marcela',
    'Pizzolato, Maximiliano',
    'Presentado, Dante',
    'Ricchini, Gerardo',
    'Romano, Carlos',
    'Rosalez, Mariana',
    'Sánchez, Candela'
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'importar_predefinidos') {
        // Importar profesores predefinidos
        $resultado = importarProfesoresPredefinidos($db, $profesoresPredefinidos);
        if ($resultado['status'] === 'success') {
            $successMessage = $resultado['message'];
        } else {
            $errorMessage = $resultado['message'];
        }
    } elseif ($accion === 'procesar_texto') {
        // Procesar texto ingresado
        $profesoresTexto = trim($_POST['profesores_texto']);
        if (!empty($profesoresTexto)) {
            $resultado = procesarTextoProfesores($profesoresTexto);
            if ($resultado['status'] === 'success') {
                $previewData = $resultado['data'];
            } else {
                $errorMessage = $resultado['message'];
            }
        }
    } elseif ($accion === 'confirmar_importacion') {
        // Confirmar importación desde vista previa
        $profesoresTexto = trim($_POST['profesores_texto']);
        if (!empty($profesoresTexto)) {
            $resultado = procesarTextoProfesores($profesoresTexto);
            if ($resultado['status'] === 'success') {
                $importResult = importarProfesoresDesdeArray($db, $resultado['data']);
                if ($importResult['status'] === 'success') {
                    $successMessage = $importResult['message'];
                } else {
                    $errorMessage = $importResult['message'];
                }
            }
        }
    }
}

/**
 * Función para procesar texto de profesores
 */
function procesarTextoProfesores($texto) {
    $lineas = explode("\n", $texto);
    $profesores = [];
    $errores = [];
    
    foreach ($lineas as $index => $linea) {
        $linea = trim($linea);
        if (empty($linea)) continue;
        
        // Buscar el patrón "Apellido, Nombre" o "Apellido Apellido, Nombre"
        if (preg_match('/^(.+),\s*(.+)$/', $linea, $matches)) {
            $apellido = trim($matches[1]);
            $nombre = trim($matches[2]);
            
            // Generar DNI único (temporal)
            $dni = '30' . str_pad(($index + 1), 6, '0', STR_PAD_LEFT);
            
            $profesores[] = [
                'apellido' => $apellido,
                'nombre' => $nombre,
                'dni' => $dni,
                'linea_original' => $linea
            ];
        } else {
            $errores[] = "Línea " . ($index + 1) . ": Formato incorrecto '$linea'. Use: Apellido, Nombre";
        }
    }
    
    if (count($errores) > 0) {
        return ['status' => 'error', 'message' => implode('<br>', $errores)];
    }
    
    if (count($profesores) === 0) {
        return ['status' => 'error', 'message' => 'No se encontraron profesores válidos en el texto'];
    }
    
    return ['status' => 'success', 'data' => $profesores];
}

/**
 * Función para importar profesores predefinidos
 */
function importarProfesoresPredefinidos($db, $profesoresList) {
    $profesoresArray = [];
    
    foreach ($profesoresList as $index => $profesor) {
        if (preg_match('/^(.+),\s*(.+)$/', $profesor, $matches)) {
            $apellido = trim($matches[1]);
            $nombre = trim($matches[2]);
            $dni = '30' . str_pad(($index + 1), 6, '0', STR_PAD_LEFT);
            
            $profesoresArray[] = [
                'apellido' => $apellido,
                'nombre' => $nombre,
                'dni' => $dni,
                'linea_original' => $profesor
            ];
        }
    }
    
    return importarProfesoresDesdeArray($db, $profesoresArray);
}

/**
 * Función para importar profesores desde array
 */
function importarProfesoresDesdeArray($db, $profesores) {
    $totalImportados = 0;
    $totalActualizados = 0;
    $errores = [];
    
    try {
        // Verificar si existe la columna created_at
        $columns = $db->fetchAll("PRAGMA table_info(usuarios)");
        $hasCreatedAt = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'created_at') {
                $hasCreatedAt = true;
                break;
            }
        }
        
        $db->transaction(function($db) use ($profesores, &$totalImportados, &$totalActualizados, &$errores, $hasCreatedAt) {
            foreach ($profesores as $index => $profesor) {
                try {
                    $apellido = $profesor['apellido'];
                    $nombre = $profesor['nombre'];
                    $dni = $profesor['dni'];
                    $contrasena = 'profesor123';
                    
                    // Verificar si el profesor ya existe por apellido y nombre
                    $profesorExistente = $db->fetchOne(
                        "SELECT id, dni FROM usuarios WHERE apellido = ? AND nombre = ? AND tipo = 'profesor'",
                        [$apellido, $nombre]
                    );
                    
                    if ($profesorExistente) {
                        // Actualizar datos si es necesario
                        $totalActualizados++;
                    } else {
                        // Verificar si el DNI ya existe
                        $dniExistente = $db->fetchOne(
                            "SELECT id FROM usuarios WHERE dni = ?",
                            [$dni]
                        );
                        
                        if ($dniExistente) {
                            // Generar nuevo DNI si ya existe
                            do {
                                $dni = '30' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                                $dniExistente = $db->fetchOne("SELECT id FROM usuarios WHERE dni = ?", [$dni]);
                            } while ($dniExistente);
                        }
                        
                        // Crear nuevo profesor
                        if ($hasCreatedAt) {
                            $db->insert(
                                "INSERT INTO usuarios (nombre, apellido, dni, tipo, contrasena, activo, created_at) 
                                 VALUES (?, ?, ?, 'profesor', ?, 1, datetime('now'))",
                                [$nombre, $apellido, $dni, $contrasena]
                            );
                        } else {
                            $db->insert(
                                "INSERT INTO usuarios (nombre, apellido, dni, tipo, contrasena, activo) 
                                 VALUES (?, ?, ?, 'profesor', ?, 1)",
                                [$nombre, $apellido, $dni, $contrasena]
                            );
                        }
                        
                        $totalImportados++;
                    }
                } catch (Exception $e) {
                    $errores[] = "Profesor #" . ($index + 1) . " (" . $profesor['linea_original'] . "): " . $e->getMessage();
                }
            }
        });
        
        // Preparar mensaje de resultado
        $mensaje = '';
        if ($totalImportados > 0) {
            $mensaje .= "Se importaron $totalImportados profesores nuevos. ";
        }
        if ($totalActualizados > 0) {
            $mensaje .= "Se encontraron $totalActualizados profesores existentes. ";
        }
        
        if (empty($mensaje)) {
            $mensaje = "No se procesaron profesores. ";
        }
        
        if (count($errores) > 0) {
            $mensaje .= "Errores encontrados: " . count($errores) . "<br>" . implode("<br>", array_slice($errores, 0, 5));
            if (count($errores) > 5) {
                $mensaje .= "<br>... y " . (count($errores) - 5) . " errores más.";
            }
            return ['status' => 'warning', 'message' => $mensaje];
        }
        
        return ['status' => 'success', 'message' => $mensaje . "Contraseña por defecto: profesor123"];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Error en la importación: ' . $e->getMessage()];
    }
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Importación Masiva de Profesores</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errorMessage)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= $errorMessage ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($successMessage)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= $successMessage ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Opción 1: Importar profesores predefinidos -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="card-title mb-0">Opción 1: Importar Profesores de la Escuela Técnica Henry Ford</h6>
                                </div>
                                <div class="card-body">
                                    <p>Esta opción importará automáticamente todos los profesores de la lista oficial de la escuela.</p>
                                    <p><strong>Total de profesores a importar:</strong> <?= count($profesoresPredefinidos) ?></p>
                                    
                                    <form method="POST" action="importar_profesores.php">
                                        <input type="hidden" name="accion" value="importar_predefinidos">
                                        <button type="submit" class="btn btn-primary" onclick="return confirm('¿Confirma la importación de <?= count($profesoresPredefinidos) ?> profesores?')">
                                            <i class="bi bi-upload"></i> Importar Profesores Predefinidos
                                        </button>
                                    </form>
                                    
                                    <!-- Mostrar lista de profesores -->
                                    <div class="mt-3">
                                        <button class="btn btn-outline-info btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#listaProfesores" aria-expanded="false">
                                            <i class="bi bi-eye"></i> Ver lista de profesores
                                        </button>
                                        <div class="collapse mt-2" id="listaProfesores">
                                            <div class="card card-body">
                                                <div class="row">
                                                    <?php foreach (array_chunk($profesoresPredefinidos, ceil(count($profesoresPredefinidos) / 3)) as $chunk): ?>
                                                    <div class="col-md-4">
                                                        <ul class="list-unstyled small">
                                                            <?php foreach ($chunk as $profesor): ?>
                                                            <li><?= htmlspecialchars($profesor) ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Opción 2: Importar desde texto -->
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card border-success">
                                <div class="card-header bg-success text-white">
                                    <h6 class="card-title mb-0">Opción 2: Importar desde Texto Personalizado</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($previewData)): ?>
                                        <form method="POST" action="importar_profesores.php">
                                            <input type="hidden" name="accion" value="procesar_texto">
                                            
                                            <div class="mb-3">
                                                <label for="profesores_texto" class="form-label">Lista de Profesores:</label>
                                                <textarea name="profesores_texto" id="profesores_texto" class="form-control" rows="10" 
                                                          placeholder="Ingrese la lista de profesores, uno por línea, en formato:&#10;Apellido, Nombre&#10;&#10;Ejemplo:&#10;García, Juan&#10;Pérez, María&#10;López Martínez, Carlos"><?= htmlspecialchars($profesoresTexto) ?></textarea>
                                            </div>
                                            
                                            <div class="alert alert-info">
                                                <h6 class="alert-heading">Formato requerido:</h6>
                                                <ul class="mb-0">
                                                    <li>Un profesor por línea</li>
                                                    <li>Formato: <code>Apellido, Nombre</code></li>
                                                    <li>Se pueden usar apellidos compuestos: <code>López Martínez, Carlos</code></li>
                                                    <li>Se generarán DNI automáticamente (temporales para identificación)</li>
                                                    <li>Contraseña por defecto: <code>profesor123</code></li>
                                                </ul>
                                            </div>
                                            
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-search"></i> Procesar y Previsualizar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Vista previa -->
                                        <h5>Previsualización de Profesores</h5>
                                        <p class="text-muted">Se procesaron <?= count($previewData) ?> profesores. Revise los datos y confirme la importación.</p>
                                        
                                        <div class="table-responsive">
                                            <table class="table table-striped table-bordered">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>#</th>
                                                        <th>Apellido</th>
                                                        <th>Nombre</th>
                                                        <th>DNI (generado)</th>
                                                        <th>Línea Original</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($previewData as $index => $profesor): ?>
                                                    <tr>
                                                        <td><?= $index + 1 ?></td>
                                                        <td><?= htmlspecialchars($profesor['apellido']) ?></td>
                                                        <td><?= htmlspecialchars($profesor['nombre']) ?></td>
                                                        <td><?= htmlspecialchars($profesor['dni']) ?></td>
                                                        <td><small><?= htmlspecialchars($profesor['linea_original']) ?></small></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <form method="POST" action="importar_profesores.php" class="mt-3">
                                            <input type="hidden" name="accion" value="confirmar_importacion">
                                            <textarea name="profesores_texto" style="display: none;"><?= htmlspecialchars($profesoresTexto) ?></textarea>
                                            
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-check-circle"></i> Confirmar Importación
                                                </button>
                                                
                                                <a href="importar_profesores.php" class="btn btn-secondary">
                                                    <i class="bi bi-x-circle"></i> Cancelar
                                                </a>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Información adicional -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white">
                                    <h6 class="card-title mb-0">Información Importante</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Datos que se crearán:</h6>
                                            <ul>
                                                <li>Tipo de usuario: <strong>Profesor</strong></li>
                                                <li>Estado: <strong>Activo</strong></li>
                                                <li>Contraseña: <strong>profesor123</strong></li>
                                                <li>DNI: Se genera automáticamente (formato: 30XXXXXX)</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Consideraciones:</h6>
                                            <ul>
                                                <li>Los profesores existentes no se duplicarán</li>
                                                <li>Se verifica por apellido y nombre</li>
                                                <li>Los DNI se pueden modificar después en "Gestión de Usuarios"</li>
                                                <li>Las contraseñas se pueden resetear individualmente</li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-warning mt-3">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>Recordatorio:</strong> Después de la importación, los profesores pueden actualizar sus datos personales y cambiar sus contraseñas desde el sistema.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>