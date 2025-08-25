<?php
// Iniciar output buffering para evitar problemas de headers
ob_start();

session_start();
require_once 'config.php';
require_once 'header.php';

// Verificar sesión y permisos
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Limpiar cualquier salida accidental del buffer
$buffer_content = ob_get_clean();
if (!empty(trim($buffer_content))) {
    error_log("Salida inesperada capturada en generar_boletin_ajax.php: " . $buffer_content);
}

// Obtener parámetros
$cursoId = isset($_GET['curso']) ? intval($_GET['curso']) : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'cuatrimestre';
$cuatrimestre = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 1;
$bimestre = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 1;

if (!$cursoId) {
    echo '<div class="alert alert-danger">Curso no especificado</div>';
    exit;
}

// Obtener información del curso y estudiantes
$db = Database::getInstance();

try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    if (!$cicloActivo) {
        throw new Exception('No hay un ciclo lectivo activo');
    }
    
    $curso = $db->fetchOne("SELECT * FROM cursos WHERE id = ?", [$cursoId]);
    if (!$curso) {
        throw new Exception('Curso no encontrado');
    }
    
    $estudiantes = $db->fetchAll(
        "SELECT u.id, u.nombre, u.apellido, u.dni 
         FROM usuarios u 
         JOIN matriculas m ON u.id = m.estudiante_id 
         WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
         ORDER BY u.apellido, u.nombre",
        [$cursoId]
    );
    
    if (empty($estudiantes)) {
        throw new Exception('No se encontraron estudiantes en este curso');
    }
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generación de Boletines - Sistema RITE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .progress-container {
            margin: 20px 0;
        }
        .student-list {
            max-height: 450px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background: #f8f9fa;
        }
        .student-item {
            padding: 12px;
            margin: 6px 0;
            border-radius: 8px;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .student-item.processing {
            background: #fff3cd;
            border-left-color: #ffc107;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.2);
        }
        .student-item.completed {
            background: #d4edda;
            border-left-color: #28a745;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }
        .student-item.error {
            background: #f8d7da;
            border-left-color: #dc3545;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
        }
        .student-item.pending {
            background: #ffffff;
            border-left-color: #6c757d;
        }
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.2em;
        }
        .log-container {
            max-height: 250px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .stats-card {
            text-align: center;
            padding: 20px 15px;
            border-radius: 12px;
            margin-bottom: 15px;
            transition: transform 0.2s ease;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .stats-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
        }
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .overlay.active {
            display: flex;
        }
        .modal-progress {
            background: white;
            padding: 40px;
            border-radius: 15px;
            min-width: 500px;
            max-width: 90%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .btn-control {
            margin: 5px;
            min-width: 140px;
        }
        .info-badge {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .process-status {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="bi bi-file-earmark-pdf"></i> 
                            Generación Masiva de Boletines RITE - <?php echo htmlspecialchars($curso['nombre']); ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Información del proceso -->
                        <div class="info-badge">
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Curso:</strong> <?php echo htmlspecialchars($curso['nombre']); ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Tipo:</strong> <?php echo $tipo === 'cuatrimestre' ? 'RITE Cuatrimestral' : 'Bimestral'; ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Período:</strong> <?php echo $tipo === 'cuatrimestre' ? $cuatrimestre . '° Cuatrimestre' : $bimestre . '° Bimestre'; ?>
                                </div>
                                <div class="col-md-3">
                                    <strong>Estudiantes:</strong> <?php echo count($estudiantes); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Estado del proceso -->
                        <div class="process-status" id="processStatus">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle text-info me-2"></i>
                                <span id="statusText">Listo para iniciar la generación de boletines</span>
                                <div class="spinner-border spinner-border-sm ms-2 d-none" id="statusSpinner"></div>
                            </div>
                        </div>

                        <!-- Controles -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <button id="btnIniciar" class="btn btn-success btn-lg btn-control" onclick="iniciarGeneracion()">
                                    <i class="bi bi-play-circle"></i> Iniciar Generación
                                </button>
                                <button id="btnPausar" class="btn btn-warning btn-lg btn-control" onclick="pausarGeneracion()" style="display:none;">
                                    <i class="bi bi-pause-circle"></i> Pausar
                                </button>
                                <button id="btnReanudar" class="btn btn-info btn-lg btn-control" onclick="reanudarGeneracion()" style="display:none;">
                                    <i class="bi bi-play-circle"></i> Reanudar
                                </button>
                                <button id="btnCancelar" class="btn btn-danger btn-lg btn-control" onclick="cancelarGeneracion()" style="display:none;">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </button>
                            </div>
                            <div class="col-md-4 text-end">
                                <button id="btnDescargar" class="btn btn-primary btn-lg btn-control" onclick="descargarZIP()" style="display:none;">
                                    <i class="bi bi-download"></i> Descargar ZIP
                                </button>
                            </div>
                        </div>

                        <!-- Barra de progreso -->
                        <div class="progress-container">
                            <div class="d-flex justify-content-between mb-2">
                                <span><strong>Progreso:</strong> <span id="progresoTexto">0 / <?php echo count($estudiantes); ?></span></span>
                                <span><strong id="porcentaje">0%</strong></span>
                            </div>
                            <div class="progress" style="height: 25px;">
                                <div id="barraProgreso" class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                                     role="progressbar" style="width: 0%">
                                    <span id="porcentajeBarra">0%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Estadísticas -->
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="stats-card bg-light">
                                    <h3 id="statsProcesados" class="text-primary">0</h3>
                                    <p class="mb-0"><i class="bi bi-gear"></i> Procesados</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card bg-success text-white">
                                    <h3 id="statsCompletados">0</h3>
                                    <p class="mb-0"><i class="bi bi-check-circle"></i> Completados</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card bg-warning text-white">
                                    <h3 id="statsSinDatos">0</h3>
                                    <p class="mb-0"><i class="bi bi-exclamation-triangle"></i> Sin Datos</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card bg-danger text-white">
                                    <h3 id="statsErrores">0</h3>
                                    <p class="mb-0"><i class="bi bi-x-circle"></i> Errores</p>
                                </div>
                            </div>
                        </div>

                        <!-- Lista de estudiantes -->
                        <div class="mt-4">
                            <h5><i class="bi bi-people"></i> Estado por Estudiante:</h5>
                            <div class="student-list">
                                <?php foreach ($estudiantes as $index => $estudiante): ?>
                                <div class="student-item pending" id="student-<?php echo $estudiante['id']; ?>" 
                                     data-index="<?php echo $index; ?>"
                                     data-id="<?php echo $estudiante['id']; ?>"
                                     data-nombre="<?php echo htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']); ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>
                                            <strong><?php echo sprintf('%03d', $index + 1); ?>.</strong> 
                                            <?php echo htmlspecialchars($estudiante['apellido'] . ', ' . $estudiante['nombre']); ?>
                                            <?php if ($estudiante['dni']): ?>
                                                <small class="text-muted">(Matr.: <?php echo $estudiante['dni']; ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                        <span class="status-icon">
                                            <i class="bi bi-clock text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Log de proceso -->
                        <div class="log-container" id="logContainer" style="display:none;">
                            <div id="logContent"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlay de procesamiento -->
    <div class="overlay" id="overlay">
        <div class="modal-progress">
            <h4 class="mb-4">
                <i class="bi bi-archive text-primary"></i> 
                Finalizando proceso...
            </h4>
            <div class="text-center mb-3">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Procesando...</span>
                </div>
            </div>
            <p class="text-center mb-0">Generando archivo ZIP con todos los boletines...</p>
            <small class="text-muted">Por favor, no cierre esta ventana</small>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
// Variables globales
const estudiantes = <?php echo json_encode($estudiantes); ?>;
const cursoId = <?php echo $cursoId; ?>;
const tipo = '<?php echo $tipo; ?>';
const cuatrimestre = <?php echo $cuatrimestre; ?>;
const bimestre = <?php echo $bimestre; ?>;
const cicloId = <?php echo $cicloActivo['id']; ?>;

let indiceActual = 0;
let procesando = false;
let pausado = false;
let completados = 0;
let errores = 0;
let sinDatos = 0;
let pdfGenerados = [];
let sessionId = '';
let tiempoInicio = null;

// Verificar que todas las variables estén definidas
console.log('Variables inicializadas:', {
    estudiantes: estudiantes.length + ' estudiantes',
    cursoId: cursoId,
    tipo: tipo,
    cuatrimestre: cuatrimestre,
    bimestre: bimestre,
    cicloId: cicloId
});

// Verificar si alguna variable es undefined o null
const variablesRequeridas = { cursoId, tipo, cuatrimestre, bimestre, cicloId };
for (const [nombre, valor] of Object.entries(variablesRequeridas)) {
    if (valor === undefined || valor === null || valor === '') {
        console.error(`Variable requerida faltante: ${nombre}`, valor);
        alert(`Error: Variable ${nombre} no está definida correctamente`);
    }
}

// Función de inicialización corregida
function iniciarGeneracion() {
    if (procesando) return;
    
    if (!confirm(`¿Está seguro de iniciar la generación de ${estudiantes.length} boletines?`)) {
        return;
    }
    
    // Verificar que tenemos todos los parámetros necesarios
    if (!cursoId || !cicloId) {
        alert('Error: Faltan parámetros necesarios (cursoId o cicloId)');
        console.error('Parámetros faltantes:', { cursoId, cicloId, tipo, cuatrimestre, bimestre });
        return;
    }
    
    // Generar ID de sesión único
    sessionId = 'ses_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    tiempoInicio = Date.now();
    
    // Resetear variables
    procesando = true;
    pausado = false;
    indiceActual = 0;
    completados = 0;
    errores = 0;
    sinDatos = 0;
    pdfGenerados = [];
    
    // Actualizar UI
    actualizarUI('iniciando');
    actualizarEstado('Inicializando sesión de generación...');
    mostrarLog();
    agregarLog('=== INICIO DE GENERACIÓN DE BOLETINES RITE ===');
    agregarLog(`Curso: ${document.querySelector('.info-badge .col-md-3:first-child').textContent.split(': ')[1]}`);
    agregarLog(`Total de estudiantes: ${estudiantes.length}`);
    agregarLog(`ID de sesión: ${sessionId}`);
    
    // Log de parámetros para debug
    console.log('Parámetros de inicialización:', {
        sessionId,
        cursoId,
        tipo,
        cuatrimestre,
        bimestre,
        cicloId
    });
    
    // Inicializar sesión en el servidor
    fetch('ajax_generar_pdf.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=init&sessionId=${sessionId}&cursoId=${cursoId}`
    })
    .then(response => {
        console.log('Init response status:', response.status);
        return response.text().then(text => {
            console.log('Init response text:', text);
            
            if (!text || text.trim() === '') {
                throw new Error('Respuesta vacía del servidor en inicialización');
            }
            
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Error parsing init JSON:', e);
                console.error('Init response text was:', text);
                throw new Error('Respuesta inválida en inicialización: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        console.log('Init parsed data:', data);
        
        if (data.success) {
            agregarLog('✓ Sesión inicializada correctamente');
            actualizarEstado('Procesando estudiantes...');
            procesarSiguiente();
        } else {
            throw new Error(data.error || 'Error al inicializar sesión');
        }
    })
    .catch(error => {
        console.error('Init error:', error);
        agregarLog('✗ Error al inicializar: ' + error.message);
        actualizarEstado('Error en la inicialización');
        procesando = false;
        actualizarUI('error');
    });
}

// Función de procesamiento corregida
function procesarSiguiente() {
    if (!procesando || pausado || indiceActual >= estudiantes.length) {
        if (indiceActual >= estudiantes.length && procesando) {
            finalizarProceso();
        }
        return;
    }
    
    const estudiante = estudiantes[indiceActual];
    const elemento = document.getElementById('student-' + estudiante.id);
    
    // Actualizar UI del estudiante
    elemento.classList.remove('pending');
    elemento.classList.add('processing');
    elemento.querySelector('.status-icon').innerHTML = '<div class="spinner-border spinner-border-sm text-warning"></div>';
    
    // Scroll al elemento actual
    elemento.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    const nombreCompleto = `${estudiante.apellido}, ${estudiante.nombre}`;
    agregarLog(`[${indiceActual + 1}/${estudiantes.length}] Procesando: ${nombreCompleto}`);
    actualizarEstado(`Generando boletín para: ${nombreCompleto}`);
    
    // CORREGIDO: Enviar TODOS los parámetros necesarios
    const formData = new URLSearchParams({
        action: 'generate',
        sessionId: sessionId,
        estudianteId: estudiante.id,
        cursoId: cursoId,           // ✅ Parámetro necesario
        tipo: tipo,                 // ✅ Parámetro necesario  
        cuatrimestre: cuatrimestre, // ✅ Parámetro necesario
        bimestre: bimestre,         // ✅ Parámetro necesario
        cicloId: cicloId,           // ✅ Parámetro necesario
        index: indiceActual + 1
    });
    
    // Log para debug
    console.log('Enviando parámetros:', {
        action: 'generate',
        sessionId: sessionId,
        estudianteId: estudiante.id,
        cursoId: cursoId,
        tipo: tipo,
        cuatrimestre: cuatrimestre,
        bimestre: bimestre,
        cicloId: cicloId
    });
    
    fetch('ajax_generar_pdf.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        // Verificar si la respuesta está vacía
        return response.text().then(text => {
            console.log('Response text:', text);
            
            if (!text || text.trim() === '') {
                throw new Error('Respuesta vacía del servidor');
            }
            
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Error parsing JSON:', e);
                console.error('Response text was:', text);
                throw new Error('Respuesta inválida del servidor: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        console.log('Parsed data:', data);
        
        if (data.success) {
            elemento.classList.remove('processing');
            elemento.classList.add('completed');
            elemento.querySelector('.status-icon').innerHTML = '<i class="bi bi-check-circle text-success"></i>';
            completados++;
            
            if (data.archivo) {
                pdfGenerados.push(data.archivo);
                agregarLog(`  ✓ PDF generado: ${data.archivo} (${formatBytes(data.size || 0)})`);
            }
        } else if (data.sinDatos) {
            elemento.classList.remove('processing');
            elemento.classList.add('error');
            elemento.querySelector('.status-icon').innerHTML = '<i class="bi bi-dash-circle text-warning"></i>';
            sinDatos++;
            agregarLog(`  ⚠ Sin datos: ${nombreCompleto} - No tiene calificaciones registradas`);
        } else {
            elemento.classList.remove('processing');
            elemento.classList.add('error');
            elemento.querySelector('.status-icon').innerHTML = '<i class="bi bi-x-circle text-danger"></i>';
            errores++;
            agregarLog(`  ✗ Error: ${data.error || 'Error desconocido'}`);
        }
        
        indiceActual++;
        actualizarProgreso();
        
        // Procesar siguiente con un pequeño delay
        setTimeout(() => procesarSiguiente(), 200);
    })
    .catch(error => {
        console.error('Fetch error:', error);
        
        elemento.classList.remove('processing');
        elemento.classList.add('error');
        elemento.querySelector('.status-icon').innerHTML = '<i class="bi bi-x-circle text-danger"></i>';
        errores++;
        agregarLog(`  ✗ Error de conexión: ${error.message}`);
        
        indiceActual++;
        actualizarProgreso();
        setTimeout(() => procesarSiguiente(), 500);
    });
}

function pausarGeneracion() {
    pausado = true;
    actualizarUI('pausado');
    actualizarEstado('Proceso pausado por el usuario');
    agregarLog('ⸯ Generación pausada');
}

function reanudarGeneracion() {
    pausado = false;
    actualizarUI('procesando');
    actualizarEstado('Reanudando proceso...');
    agregarLog('▶ Generación reanudada');
    procesarSiguiente();
}

function cancelarGeneracion() {
    if (!confirm('¿Está seguro de cancelar la generación? Se perderán todos los PDFs generados.')) {
        return;
    }
    
    procesando = false;
    pausado = false;
    
    // Limpiar archivos temporales
    fetch('ajax_generar_pdf.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=cleanup&sessionId=${sessionId}`
    }).then(() => {
        agregarLog('🗑 Archivos temporales limpiados');
    });
    
    actualizarUI('cancelado');
    actualizarEstado('Proceso cancelado por el usuario');
    agregarLog('⌦ Generación cancelada');
}

function finalizarProceso() {
    procesando = false;
    document.getElementById('overlay').classList.add('active');
    
    actualizarEstado('Creando archivo ZIP...');
    agregarLog('📦 Creando archivo ZIP con todos los boletines...');
    
    // Crear ZIP con todos los PDFs
    fetch('ajax_generar_pdf.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=createZip&sessionId=${sessionId}&cursoId=${cursoId}`
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('overlay').classList.remove('active');
        
        if (data.success) {
            agregarLog(`✓ ZIP creado exitosamente: ${data.archivo}`);
            agregarLog(`  Archivos incluidos: ${data.archivosIncluidos}`);
            agregarLog(`  Tamaño total: ${formatBytes(data.size || 0)}`);
            
            // Mostrar resumen final
            const tiempoTotal = Math.round((Date.now() - tiempoInicio) / 1000);
            agregarLog('=== RESUMEN FINAL ===');
            agregarLog(`✓ Boletines completados: ${completados}`);
            agregarLog(`⚠ Sin datos: ${sinDatos}`);
            agregarLog(`✗ Errores: ${errores}`);
            agregarLog(`⏱ Tiempo total: ${tiempoTotal} segundos`);
            agregarLog('=== PROCESO FINALIZADO ===');
            
            // Mostrar botón de descarga
            document.getElementById('btnDescargar').style.display = 'inline-block';
            document.getElementById('btnDescargar').setAttribute('data-archivo', data.archivo);
            
            actualizarUI('completado');
            actualizarEstado('¡Proceso completado exitosamente!');
            
            // Mostrar resumen en modal
            mostrarResumenFinal(data.archivo);
        } else {
            agregarLog(`✗ Error al crear ZIP: ${data.error}`);
            actualizarEstado('Error al finalizar el proceso');
            alert('Error al crear el archivo ZIP: ' + (data.error || 'Error desconocido'));
        }
    })
    .catch(error => {
        document.getElementById('overlay').classList.remove('active');
        agregarLog(`✗ Error de conexión al crear ZIP: ${error.message}`);
        actualizarEstado('Error al finalizar el proceso');
        alert('Error al finalizar el proceso: ' + error.message);
    });
}

// Función de descarga actualizada
function descargarZIP() {
    const archivo = document.getElementById('btnDescargar').getAttribute('data-archivo');
    if (archivo) {
        agregarLog('⬇ Iniciando descarga...');
        
        // Crear un enlace temporal para la descarga
        const link = document.createElement('a');
        link.href = `ajax_generar_pdf.php?action=download&file=${encodeURIComponent(archivo)}`;
        link.download = archivo;
        link.style.display = 'none';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Opcional: limpiar archivos después de la descarga
        setTimeout(() => {
            if (confirm('¿Desea limpiar los archivos temporales del servidor?')) {
                fetch('ajax_generar_pdf.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=cleanup&sessionId=${sessionId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        agregarLog('🗑 Archivos temporales limpiados del servidor');
                    }
                });
            }
        }, 2000);
    }
}

function actualizarProgreso() {
    const procesados = indiceActual;
    const total = estudiantes.length;
    const porcentaje = Math.round((procesados / total) * 100);
    
    // Actualizar textos y barra
    document.getElementById('progresoTexto').textContent = `${procesados} / ${total}`;
    document.getElementById('porcentaje').textContent = `${porcentaje}%`;
    document.getElementById('porcentajeBarra').textContent = `${porcentaje}%`;
    document.getElementById('barraProgreso').style.width = `${porcentaje}%`;
    
    // Actualizar estadísticas
    document.getElementById('statsProcesados').textContent = procesados;
    document.getElementById('statsCompletados').textContent = completados;
    document.getElementById('statsSinDatos').textContent = sinDatos;
    document.getElementById('statsErrores').textContent = errores;
}

function actualizarUI(estado) {
    const btnIniciar = document.getElementById('btnIniciar');
    const btnPausar = document.getElementById('btnPausar');
    const btnReanudar = document.getElementById('btnReanudar');
    const btnCancelar = document.getElementById('btnCancelar');
    const btnDescargar = document.getElementById('btnDescargar');
    const spinner = document.getElementById('statusSpinner');
    
    // Ocultar todos los botones primero
    [btnIniciar, btnPausar, btnReanudar, btnCancelar].forEach(btn => {
        btn.style.display = 'none';
    });
    
    switch(estado) {
        case 'iniciando':
            btnCancelar.style.display = 'inline-block';
            spinner.classList.remove('d-none');
            break;
        case 'procesando':
            btnPausar.style.display = 'inline-block';
            btnCancelar.style.display = 'inline-block';
            spinner.classList.remove('d-none');
            break;
        case 'pausado':
            btnReanudar.style.display = 'inline-block';
            btnCancelar.style.display = 'inline-block';
            spinner.classList.add('d-none');
            break;
        case 'completado':
            btnDescargar.style.display = 'inline-block';
            spinner.classList.add('d-none');
            break;
        case 'cancelado':
        case 'error':
            btnIniciar.style.display = 'inline-block';
            spinner.classList.add('d-none');
            break;
    }
}

function actualizarEstado(mensaje) {
    document.getElementById('statusText').textContent = mensaje;
}

function mostrarLog() {
    document.getElementById('logContainer').style.display = 'block';
}

function agregarLog(mensaje) {
    const log = document.getElementById('logContent');
    const hora = new Date().toLocaleTimeString();
    log.innerHTML += `[${hora}] ${mensaje}\n`;
    log.parentElement.scrollTop = log.parentElement.scrollHeight;
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Función mejorada para mostrar el resumen final
function mostrarResumenFinal(archivo) {
    const tiempoTotal = Math.round((Date.now() - tiempoInicio) / 1000);
    const minutos = Math.floor(tiempoTotal / 60);
    const segundos = tiempoTotal % 60;
    const tiempoFormateado = minutos > 0 ? `${minutos}m ${segundos}s` : `${segundos}s`;
    
    const resumen = `¡Proceso completado exitosamente!

📊 RESUMEN DE GENERACIÓN:
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ Boletines generados: ${completados}
⚠️ Sin datos: ${sinDatos}
❌ Errores: ${errores}
📁 Archivo ZIP: ${archivo}
⏱️ Tiempo total: ${tiempoFormateado}

${completados > 0 ? '🎉 ¡Ya puede descargar el archivo ZIP con todos los boletines!\n\n📝 Los nombres de archivo incluyen apellido y nombre de cada estudiante.' : '⚠️ No se generaron boletines. Verifique que los estudiantes tengan calificaciones.'}`;

    alert(resumen);
}

// Prevenir cierre accidental durante procesamiento
window.addEventListener('beforeunload', function (e) {
    if (procesando && !pausado) {
        e.preventDefault();
        e.returnValue = '';
        return 'La generación está en progreso. ¿Está seguro de salir?';
    }
});

// Limpiar sesión al cerrar/recargar página
window.addEventListener('beforeunload', function() {
    if (sessionId) {
        navigator.sendBeacon('ajax_generar_pdf.php', 
            new URLSearchParams({action: 'cleanup', sessionId: sessionId})
        );
    }
});

// Inicialización al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    actualizarProgreso();
    agregarLog('Sistema listo para generar boletines RITE');
    agregarLog(`Total de estudiantes en curso: ${estudiantes.length}`);
});
</script>

    <?php require_once 'footer.php'; ?>
</body>
</html>
