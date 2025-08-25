<?php
/**
 * import_emails_csv_flexible.php - Importador masivo de emails desde CSV FLEXIBLE
 * VERSIÓN MEJORADA - Detecta automáticamente la estructura del CSV
 * Procesa archivos CSV exportados desde Excel con diferentes formatos
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

function detectarEstructuraCSV($primeraLinea) {
    // Convertir a minúsculas para comparación
    $columnas = array_map('strtolower', array_map('trim', $primeraLinea));
    
    $estructura = [
        'tiene_headers' => false,
        'columna_legajo' => -1,
        'columna_apellidos' => -1,
        'columna_nombres' => -1,
        'columnas_emails' => []
    ];
    
    // Detectar si la primera línea son headers
    $posiblesHeaders = ['orden', 'legajo', 'dni', 'apellido', 'nombre', 'email', 'mail', 'correo'];
    foreach ($columnas as $columna) {
        foreach ($posiblesHeaders as $header) {
            if (strpos($columna, $header) !== false) {
                $estructura['tiene_headers'] = true;
                break 2;
            }
        }
    }
    
    // Si no tiene headers, asumir estructura por posición
    if (!$estructura['tiene_headers']) {
        agregarMensaje("📋 No se detectaron headers, usando estructura por posición");
        // Estructura clásica: Orden, LEGAJO, Apellidos, Nombres, Email1, Email2, ...
        if (count($columnas) >= 4) {
            $estructura['columna_legajo'] = 1;
            $estructura['columna_apellidos'] = 2;
            $estructura['columna_nombres'] = 3;
            
            // Buscar todas las columnas que parezcan emails (posición 4 en adelante)
            for ($i = 4; $i < count($columnas); $i++) {
                $estructura['columnas_emails'][] = $i;
            }
        }
    } else {
        agregarMensaje("📋 Headers detectados, analizando estructura...");
        
        // Buscar columnas por nombre
        foreach ($columnas as $index => $columna) {
            // LEGAJO/DNI
            if (strpos($columna, 'legajo') !== false || strpos($columna, 'dni') !== false) {
                $estructura['columna_legajo'] = $index;
            }
            // Apellidos
            elseif (strpos($columna, 'apellido') !== false) {
                $estructura['columna_apellidos'] = $index;
            }
            // Nombres
            elseif (strpos($columna, 'nombre') !== false && strpos($columna, 'apellido') === false) {
                $estructura['columna_nombres'] = $index;
            }
            // Emails
            elseif (strpos($columna, 'email') !== false || strpos($columna, 'mail') !== false || strpos($columna, 'correo') !== false) {
                $estructura['columnas_emails'][] = $index;
            }
        }
    }
    
    return $estructura;
}

function extraerEmails($data, $columnasEmails) {
    $emails = [];
    
    foreach ($columnasEmails as $columna) {
        if (isset($data[$columna])) {
            $email = validarEmail($data[$columna]);
            if ($email) {
                $emails[] = $email;
            }
        }
    }
    
    return $emails;
}

function procesarArchivoCSVFlexible($rutaArchivo, $nombreArchivo) {
    global $estadisticas;
    
    $estudiantes = [];
    
    agregarMensaje("📋 Procesando archivo: $nombreArchivo");
    
    // Leer archivo CSV
    if (($handle = fopen($rutaArchivo, "r")) !== FALSE) {
        $numeroLinea = 0;
        $estructura = null;
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $numeroLinea++;
            
            // Primera línea: detectar estructura
            if ($numeroLinea === 1) {
                $estructura = detectarEstructuraCSV($data);
                
                agregarMensaje("   🔍 Estructura detectada:");
                agregarMensaje("     - Headers: " . ($estructura['tiene_headers'] ? 'SÍ' : 'NO'));
                agregarMensaje("     - Columna LEGAJO: " . ($estructura['columna_legajo'] >= 0 ? $estructura['columna_legajo'] : 'NO ENCONTRADA'));
                agregarMensaje("     - Columna Apellidos: " . ($estructura['columna_apellidos'] >= 0 ? $estructura['columna_apellidos'] : 'NO ENCONTRADA'));
                agregarMensaje("     - Columna Nombres: " . ($estructura['columna_nombres'] >= 0 ? $estructura['columna_nombres'] : 'NO ENCONTRADA'));
                agregarMensaje("     - Columnas Emails: " . (count($estructura['columnas_emails']) > 0 ? implode(', ', $estructura['columnas_emails']) : 'NO ENCONTRADAS'));
                
                // Si tiene headers, saltar esta línea
                if ($estructura['tiene_headers']) {
                    continue;
                }
            }
            
            // Validar que tenemos la estructura mínima
            if ($estructura['columna_legajo'] < 0 || $estructura['columna_apellidos'] < 0 || $estructura['columna_nombres'] < 0) {
                if ($numeroLinea === 2 || !$estructura['tiene_headers']) { // Solo mostrar error una vez
                    agregarMensaje("⚠️ No se pudo detectar la estructura completa del archivo $nombreArchivo", true);
                }
                continue;
            }
            
            // Asegurar que tenemos suficientes columnas
            $maxColumna = max($estructura['columna_legajo'], $estructura['columna_apellidos'], $estructura['columna_nombres']);
            if (!empty($estructura['columnas_emails'])) {
                $maxColumna = max($maxColumna, max($estructura['columnas_emails']));
            }
            
            while (count($data) <= $maxColumna) {
                $data[] = '';
            }
            
            $legajo = isset($data[$estructura['columna_legajo']]) ? trim($data[$estructura['columna_legajo']]) : '';
            $apellidos = isset($data[$estructura['columna_apellidos']]) ? trim($data[$estructura['columna_apellidos']]) : '';
            $nombres = isset($data[$estructura['columna_nombres']]) ? trim($data[$estructura['columna_nombres']]) : '';
            
            // Extraer todos los emails
            $emails = extraerEmails($data, $estructura['columnas_emails']);
            
            // Verificar si es un LEGAJO válido (número mayor a 1000)
            if (is_numeric($legajo) && $legajo > 1000) {
                // Solo procesar si tiene apellidos y nombres
                if (!empty($apellidos) && !empty($nombres)) {
                    $estudiantes[] = [
                        'archivo' => $nombreArchivo,
                        'linea' => $numeroLinea,
                        'dni' => intval($legajo), // LEGAJO = DNI
                        'apellidos' => $apellidos,
                        'nombres' => $nombres,
                        'emails' => $emails
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
                
                $estudiantesArchivo = procesarArchivoCSVFlexible($archivoTemporal, $nombreArchivo);
                $todosLosEstudiantes = array_merge($todosLosEstudiantes, $estudiantesArchivo);
                $estadisticas['archivos_procesados']++;
            }
        }
        
        agregarMensaje("📈 Total de estudiantes encontrados: " . count($todosLosEstudiantes));
        agregarMensaje("📁 Archivos procesados: " . $estadisticas['archivos_procesados']);
        agregarMensaje("💾 Iniciando actualización de base de datos...");
        
        // Procesar cada estudiante
        foreach ($todosLosEstudiantes as $estudiante) {
            $estadisticas['procesados']++;
            
            $dni = $estudiante['dni'];
            $apellidos = $estudiante['apellidos'];
            $nombres = $estudiante['nombres'];
            $emails = $estudiante['emails'];
            
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
            if (empty($emails)) {
                $estadisticas['emails_vacios']++;
                continue;
            }
            
            // Asignar emails (máximo 2)
            $email1 = isset($emails[0]) ? $emails[0] : null;
            $email2 = isset($emails[1]) ? $emails[1] : null;
            
            // Actualizar emails
            try {
                $db->query(
                    "UPDATE usuarios SET email = ?, email_secundario = ? WHERE id = ?",
                    [$email1, $email2, $estudianteBD['id']]
                );
                
                $estadisticas['actualizados']++;
                agregarMensaje("✅ Actualizado: {$estudianteBD['apellido']}, {$estudianteBD['nombre']} (DNI: $dni)");
                
                $emailsTexto = [];
                if ($email1) $emailsTexto[] = "Email1: $email1";
                if ($email2) $emailsTexto[] = "Email2: $email2";
                if (count($emails) > 2) $emailsTexto[] = "(" . (count($emails) - 2) . " emails adicionales ignorados)";
                
                agregarMensaje("   📧 " . implode(" | ", $emailsTexto));
                
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
    <title>Importar Emails CSV Flexible - Escuela Técnica Henry Ford</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-10">
                
                <div class="card">
                    <div class="card-header bg-success text-white text-center">
                        <h1><i class="bi bi-filetype-csv"></i> Importar Emails CSV - VERSIÓN FLEXIBLE</h1>
                        <p class="mb-0">Detecta automáticamente la estructura de cada archivo CSV</p>
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
                        
                        <!-- Alerta de versión flexible -->
                        <div class="alert alert-success">
                            <h5><i class="bi bi-cpu-fill"></i> ¡Versión Flexible Inteligente!</h5>
                            <p><strong>Nuevas características:</strong></p>
                            <ul class="mb-0">
                                <li>🔍 <strong>Detección automática de estructura</strong> - No importa el orden de columnas</li>
                                <li>📋 <strong>Compatible con headers o sin headers</strong></li>
                                <li>📧 <strong>Detecta múltiples columnas de emails</strong> automáticamente</li>
                                <li>🏷️ <strong>Busca por nombres de columnas</strong>: legajo, dni, apellido, nombre, email, mail, correo</li>
                                <li>⚡ <strong>Mantiene velocidad ultrarrápida</strong></li>
                                <li>📊 <strong>Reportes detallados por archivo</strong></li>
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
                        
                        <!-- Instrucciones flexibles -->
                        <div class="alert alert-info">
                            <h5><i class="bi bi-info-circle"></i> Formatos CSV Compatibles</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>✅ Con Headers (Recomendado):</h6>
                                    <div class="bg-light p-2 rounded">
                                        <small>
                                            Legajo,Apellidos,Nombres,Email1,Email2<br>
                                            2087,Acosta,Sofia,email1@...,email2@...<br><br>
                                            DNI,Apellido,Nombre,Mail,Correo<br>
                                            2088,Alitta,Renzo,email1@...,email2@...
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6>✅ Sin Headers (Clásico):</h6>
                                    <div class="bg-light p-2 rounded">
                                        <small>
                                            1,2087,Acosta,Sofia,email1@...,email2@...<br>
                                            2,2088,Alitta,Renzo,email1@...,email2@...<br><br>
                                            <strong>Posiciones:</strong><br>
                                            Col 1: Legajo/DNI<br>
                                            Col 2: Apellidos<br>
                                            Col 3: Nombres<br>
                                            Col 4+: Emails
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
                                    Puede subir archivos con diferentes estructuras - se detectarán automáticamente
                                </small>
                            </div>
                            
                            <div class="alert alert-success">
                                <h6><i class="bi bi-check-circle"></i> Esta versión maneja automáticamente:</h6>
                                <ul class="mb-0">
                                    <li>🔍 <strong>Cualquier orden de columnas</strong> - busca por nombre o posición</li>
                                    <li>📋 <strong>Con o sin headers</strong> - detecta automáticamente</li>
                                    <li>📧 <strong>Múltiples emails por estudiante</strong> - los encuentra todos</li>
                                    <li>🏷️ <strong>Diferentes nombres de columnas</strong>: legajo/dni, apellido/apellidos, etc.</li>
                                    <li>📊 <strong>Reporte detallado de estructura</strong> por cada archivo</li>
                                </ul>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="importar" class="btn btn-success btn-lg">
                                    <i class="bi bi-upload"></i> Importar con Detección Automática
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
                            <p>Se actualizaron <?= $estadisticas['actualizados'] ?> estudiantes con emails desde <?= $estadisticas['archivos_procesados'] ?> archivos CSV.</p>
                            
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
                        
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
