<?php
/**
 * import_emails_csv.php - Importador masivo de emails desde CSV
 * VERSIÓN SUPER SIMPLE - Solo requiere PHP básico
 * Procesa archivos CSV exportados desde Excel
 */

require_once 'config.php';

// Solo permitir a administradores
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('<h1>⛔ Acceso denegado</h1><p>Solo administradores pueden ejecutar la importación.</p>');
}

$db = Database::getInstance();
$mensajes = [];
$errores = [];
$estadisticas = [
    'procesados' => 0,
    'actualizados' => 0,
    'no_encontrados' => 0,
    'emails_vacios' => 0,
    'errores' => 0,
    'archivos_procesados' => 0
];

function agregarMensaje($texto, $esError = false) {
    global $mensajes, $errores;
    if ($esError) {
        $errores[] = $texto;
    } else {
        $mensajes[] = $texto;
    }
}

function validarEmail($email) {
    $email = trim($email);
    if (empty($email) || $email === '-' || $email === 'N/A') {
        return null;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function procesarArchivoCSV($rutaArchivo, $nombreArchivo) {
    global $estadisticas;
    
    $estudiantes = [];
    
    agregarMensaje("📋 Procesando archivo: $nombreArchivo");
    
    // Leer archivo CSV
    if (($handle = fopen($rutaArchivo, "r")) !== FALSE) {
        $numeroLinea = 0;
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $numeroLinea++;
            
            // Asegurar que tenemos al menos 6 columnas
            while (count($data) < 6) {
                $data[] = '';
            }
            
            $orden = isset($data[0]) ? trim($data[0]) : '';
            $legajo = isset($data[1]) ? trim($data[1]) : '';
            $apellidos = isset($data[2]) ? trim($data[2]) : '';
            $nombres = isset($data[3]) ? trim($data[3]) : '';
            $email1 = isset($data[4]) ? trim($data[4]) : '';
            $email2 = isset($data[5]) ? trim($data[5]) : '';
            
            // Verificar si es un LEGAJO válido (número mayor a 1000)
            if (is_numeric($legajo) && $legajo > 1000) {
                // Solo procesar si tiene apellidos y nombres
                if (!empty($apellidos) && !empty($nombres)) {
                    $estudiantes[] = [
                        'archivo' => $nombreArchivo,
                        'linea' => $numeroLinea,
                        'orden' => $orden,
                        'dni' => intval($legajo), // LEGAJO = DNI
                        'apellidos' => $apellidos,
                        'nombres' => $nombres,
                        'email1' => $email1,
                        'email2' => $email2
                    ];
                }
            }
        }
        fclose($handle);
    } else {
        throw new Exception("No se pudo abrir el archivo: $nombreArchivo");
    }
    
    agregarMensaje("   ✅ Encontrados " . count($estudiantes) . " estudiantes en $nombreArchivo");
    
    return $estudiantes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importar'])) {
    
    agregarMensaje("🚀 Iniciando importación desde CSV...");
    
    try {
        // Verificar que existe el campo email_secundario
        $columnas = $db->fetchAll("PRAGMA table_info(usuarios)");
        $tieneEmailSecundario = false;
        
        foreach ($columnas as $columna) {
            if ($columna['name'] === 'email_secundario') {
                $tieneEmailSecundario = true;
                break;
            }
        }
        
        if (!$tieneEmailSecundario) {
            throw new Exception("Debe ejecutar primero agregar_email_secundario.php");
        }
        
        // Verificar que se subieron archivos
        if (!isset($_FILES['archivos_csv']) || empty($_FILES['archivos_csv']['name'][0])) {
            throw new Exception("Debe subir al menos un archivo CSV");
        }
        
        $todosLosEstudiantes = [];
        
        // Procesar cada archivo CSV subido
        $totalArchivos = count($_FILES['archivos_csv']['name']);
        
        for ($i = 0; $i < $totalArchivos; $i++) {
            if ($_FILES['archivos_csv']['error'][$i] === UPLOAD_ERR_OK) {
                $nombreArchivo = $_FILES['archivos_csv']['name'][$i];
                $archivoTemporal = $_FILES['archivos_csv']['tmp_name'][$i];
                
                // Verificar que sea un archivo CSV
                $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
                if ($extension !== 'csv') {
                    agregarMensaje("⚠️ Archivo ignorado (no es CSV): $nombreArchivo", true);
                    continue;
                }
                
                $estudiantesArchivo = procesarArchivoCSV($archivoTemporal, $nombreArchivo);
                $todosLosEstudiantes = array_merge($todosLosEstudiantes, $estudiantesArchivo);
                $estadisticas['archivos_procesados']++;
            }
        }
        
        agregarMensaje("📈 Total de estudiantes encontrados: " . count($todosLosEstudiantes));
        agregarMensaje("📁 Archivos procesados: " . $estadisticas['archivos_procesados']);
        agregarMensaje("🔄 Iniciando actualización de base de datos...");
        
        // Procesar cada estudiante
        foreach ($todosLosEstudiantes as $estudiante) {
            $estadisticas['procesados']++;
            
            $dni = $estudiante['dni'];
            $apellidos = $estudiante['apellidos'];
            $nombres = $estudiante['nombres'];
            $email1 = validarEmail($estudiante['email1']);
            $email2 = validarEmail($estudiante['email2']);
            
            // Buscar estudiante por DNI
            $estudianteBD = $db->fetchOne(
                "SELECT id, nombre, apellido, email, email_secundario FROM usuarios 
                 WHERE dni = ? AND tipo = 'estudiante'",
                [$dni]
            );
            
            if (!$estudianteBD) {
                $estadisticas['no_encontrados']++;
                agregarMensaje("⚠️ No encontrado en BD: DNI $dni - $apellidos, $nombres ({$estudiante['archivo']})", true);
                continue;
            }
            
            // Verificar si tiene emails para actualizar
            if (!$email1 && !$email2) {
                $estadisticas['emails_vacios']++;
                continue;
            }
            
            // Actualizar emails
            try {
                $db->query(
                    "UPDATE usuarios SET email = ?, email_secundario = ? WHERE id = ?",
                    [$email1, $email2, $estudianteBD['id']]
                );
                
                $estadisticas['actualizados']++;
                agregarMensaje("✅ Actualizado: {$estudianteBD['apellido']}, {$estudianteBD['nombre']} (DNI: $dni)");
                agregarMensaje("   📧 Email1: " . ($email1 ?: 'vacío') . " | Email2: " . ($email2 ?: 'vacío'));
                
            } catch (Exception $e) {
                $estadisticas['errores']++;
                agregarMensaje("❌ Error actualizando DNI $dni: " . $e->getMessage(), true);
            }
        }
        
        agregarMensaje("🎉 Importación completada!");
        agregarMensaje("📊 Estadísticas finales:");
        agregarMensaje("   • Archivos procesados: {$estadisticas['archivos_procesados']}");
        agregarMensaje("   • Registros procesados: {$estadisticas['procesados']}");
        agregarMensaje("   • Estudiantes actualizados: {$estadisticas['actualizados']}");
        agregarMensaje("   • No encontrados en BD: {$estadisticas['no_encontrados']}");
        agregarMensaje("   • Sin emails: {$estadisticas['emails_vacios']}");
        agregarMensaje("   • Errores: {$estadisticas['errores']}");
        
    } catch (Exception $e) {
        agregarMensaje("❌ Error durante la importación: " . $e->getMessage(), true);
    }
}

// Verificar estado actual
$columnas = $db->fetchAll("PRAGMA table_info(usuarios)");
$tieneEmailSecundario = false;

foreach ($columnas as $columna) {
    if ($columna['name'] === 'email_secundario') {
        $tieneEmailSecundario = true;
        break;
    }
}

// Estadísticas actuales
$estadisticasActuales = [];
if ($tieneEmailSecundario) {
    $estadisticasActuales = [
        'total' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante'")['count'],
        'con_email1' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND email IS NOT NULL AND email != ''")['count'],
        'con_email2' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND email_secundario IS NOT NULL AND email_secundario != ''")['count'],
        'sin_emails' => $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo='estudiante' AND (email IS NULL OR email = '') AND (email_secundario IS NULL OR email_secundario = '')")['count']
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Emails desde CSV - Escuela Técnica Henry Ford</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-10">
                
                <div class="card">
                    <div class="card-header bg-success text-white text-center">
                        <h1><i class="bi bi-filetype-csv"></i> Importar Emails desde CSV</h1>
                        <p class="mb-0">VERSIÓN SUPER SIMPLE - Sin dependencias externas</p>
                    </div>
                    
                    <div class="card-body">
                        
                        <!-- Verificación previa -->
                        <?php if (!$tieneEmailSecundario): ?>
                        <div class="alert alert-danger">
                            <h5><i class="bi bi-exclamation-triangle"></i> Campo faltante</h5>
                            <p>Debe ejecutar primero <code>agregar_email_secundario.php</code> para agregar el campo necesario.</p>
                            <a href="agregar_email_secundario.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Agregar Campo Email Secundario
                            </a>
                        </div>
                        <?php else: ?>
                        
                        <!-- Alerta de versión CSV -->
                        <div class="alert alert-success">
                            <h5><i class="bi bi-lightning-fill"></i> ¡Versión CSV Super Simple!</h5>
                            <p><strong>Ventajas de usar CSV:</strong></p>
                            <ul class="mb-0">
                                <li>✅ No requiere dependencias externas</li>
                                <li>✅ Compatible con cualquier versión de PHP</li>
                                <li>✅ Procesamiento ultrarrápido</li>
                                <li>✅ Permite múltiples archivos (todos los cursos)</li>
                                <li>✅ Formato: Orden, LEGAJO, Apellidos, Nombres, Email1, Email2</li>
                            </ul>
                        </div>
                        
                        <!-- Estadísticas actuales -->
                        <h3><i class="bi bi-graph-up"></i> Estado Actual</h3>
                        
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card text-white bg-primary">
                                    <div class="card-body text-center">
                                        <h4><?= $estadisticasActuales['total'] ?></h4>
                                        <p class="card-text">Total Estudiantes</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white bg-success">
                                    <div class="card-body text-center">
                                        <h4><?= $estadisticasActuales['con_email1'] ?></h4>
                                        <p class="card-text">Con Email Principal</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white bg-info">
                                    <div class="card-body text-center">
                                        <h4><?= $estadisticasActuales['con_email2'] ?></h4>
                                        <p class="card-text">Con Email Secundario</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-white bg-warning">
                                    <div class="card-body text-center">
                                        <h4><?= $estadisticasActuales['sin_emails'] ?></h4>
                                        <p class="card-text">Sin Emails</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Instrucciones de exportación -->
                        <div class="alert alert-info">
                            <h5><i class="bi bi-info-circle"></i> Cómo exportar desde Excel a CSV</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Paso a paso:</h6>
                                    <ol>
                                        <li>Abra su archivo Excel</li>
                                        <li>Seleccione cada hoja (1er año, 2do año, etc.)</li>
                                        <li>Archivo → Guardar como...</li>
                                        <li>Tipo: <strong>CSV (separado por comas)</strong></li>
                                        <li>Guarde cada hoja con nombre descriptivo</li>
                                    </ol>
                                </div>
                                <div class="col-md-6">
                                    <h6>Formato esperado:</h6>
                                    <div class="bg-light p-2 rounded">
                                        <small>
                                            Orden,LEGAJO,Apellidos,Nombres,Email1,Email2<br>
                                            1,2087,Acosta Anaya,Alma Sofia,email1@...,email2@...<br>
                                            2,2088,Alitta,Renzo,email1@...,email2@...
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Formulario de importación -->
                        <h3><i class="bi bi-upload"></i> Importar Archivos CSV</h3>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="archivos_csv" class="form-label">Seleccionar Archivos CSV *</label>
                                <input type="file" class="form-control" id="archivos_csv" name="archivos_csv[]" 
                                       accept=".csv" multiple required>
                                <small class="form-text text-muted">
                                    Puede seleccionar múltiples archivos CSV (uno por cada curso)
                                </small>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="bi bi-check-circle"></i> Esta versión permite:</h6>
                                <ul class="mb-0">
                                    <li>📁 Subir múltiples archivos CSV de una vez</li>
                                    <li>🔍 Detecta automáticamente el LEGAJO como DNI</li>
                                    <li>📧 Valida automáticamente formato de emails</li>
                                    <li>⚡ Procesamiento muy rápido</li>
                                    <li>📊 Estadísticas detalladas por archivo</li>
                                </ul>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="importar" class="btn btn-success btn-lg">
                                    <i class="bi bi-upload"></i> Importar Emails desde CSV
                                </button>
                            </div>
                        </form>
                        
                        <?php endif; ?>
                        
                        <!-- Resultados de importación -->
                        <?php if (!empty($mensajes) || !empty($errores)): ?>
                        <hr>
                        <h4><i class="bi bi-terminal"></i> Resultados de la Importación</h4>
                        
                        <div class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($mensajes as $mensaje): ?>
                                <div class="text-success"><?= htmlspecialchars($mensaje) ?></div>
                            <?php endforeach; ?>
                            
                            <?php foreach ($errores as $error): ?>
                                <div class="text-warning"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($estadisticas['actualizados'] > 0): ?>
                        <div class="alert alert-success mt-3">
                            <h5><i class="bi bi-check-circle"></i> ¡Importación exitosa!</h5>
                            <p>Se actualizaron <?= $estadisticas['actualizados'] ?> estudiantes con nuevos emails desde <?= $estadisticas['archivos_procesados'] ?> archivos CSV.</p>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                                <a href="gestionar_emails.php" class="btn btn-primary">
                                    <i class="bi bi-eye"></i> Ver Emails Importados
                                </a>
                                <a href="boletines.php" class="btn btn-success">
                                    <i class="bi bi-envelope"></i> Probar Envío de Boletines
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                        <!-- Ayuda -->
                        <hr>
                        <h4><i class="bi bi-question-circle"></i> Ventajas de la versión CSV</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>✅ Sin problemas de dependencias:</h6>
                                <ul>
                                    <li>No requiere PHPSpreadsheet</li>
                                    <li>No requiere extensión GD</li>
                                    <li>No requiere extensión ZIP</li>
                                    <li>Funciona con PHP básico</li>
                                    <li>Compatible con cualquier servidor</li>
                                </ul>
                            </div>
                            
                            <div class="col-md-6">
                                <h6>⚡ Ventajas adicionales:</h6>
                                <ul>
                                    <li>Procesamiento ultrarrápido</li>
                                    <li>Múltiples archivos simultáneos</li>
                                    <li>Manejo automático de errores</li>
                                    <li>Estadísticas detalladas</li>
                                    <li>Formato universal (CSV)</li>
                                </ul>
                            </div>
                        </div>
                        
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
