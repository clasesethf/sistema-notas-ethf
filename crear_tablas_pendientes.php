<?php
/**
 * crear_tablas_pendientes.php - Script de instalación para materias pendientes
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * Ejecuta la creación de tablas necesarias para el sistema de materias pendientes
 * Diseñado para SQLite
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin puede ejecutar esto)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    $_SESSION['message'] = 'Solo los administradores pueden ejecutar este script.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();
$mensajes = [];
$errores = [];
$ejecutado = false;

// Procesar ejecución del script
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ejecutar'])) {
    $ejecutado = true;
    
    try {
        // Script SQL completo
        $sqlCommands = [
            // 1. Crear tabla materias_pendientes_intensificacion
            "CREATE TABLE IF NOT EXISTS materias_pendientes_intensificacion (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                estudiante_id INTEGER NOT NULL,
                materia_curso_id INTEGER NOT NULL,
                ciclo_lectivo_id INTEGER NOT NULL,
                
                -- Saberes pendientes al inicio del ciclo
                saberes_iniciales TEXT NOT NULL,
                
                -- Estados en cada período de intensificación
                marzo VARCHAR(3) CHECK (marzo IN ('AA', 'CCA', 'CSA') OR marzo IS NULL),
                julio VARCHAR(3) CHECK (julio IN ('AA', 'CCA', 'CSA') OR julio IS NULL),
                agosto VARCHAR(3) CHECK (agosto IN ('AA', 'CCA', 'CSA') OR agosto IS NULL),
                diciembre VARCHAR(3) CHECK (diciembre IN ('AA', 'CCA', 'CSA') OR diciembre IS NULL),
                febrero VARCHAR(3) CHECK (febrero IN ('AA', 'CCA', 'CSA') OR febrero IS NULL),
                
                -- Calificación final (4-10 si aprobó, 1-3 si no aprobó)
                calificacion_final INTEGER CHECK (calificacion_final >= 1 AND calificacion_final <= 10),
                
                -- Saberes pendientes al cierre (si no aprobó)
                saberes_cierre TEXT,
                
                -- Estado del registro
                estado VARCHAR(20) DEFAULT 'activo' CHECK (estado IN ('activo', 'eliminado', 'completado')),
                
                -- Control administrativo
                creado_por_admin INTEGER,
                modificado_por_admin INTEGER,
                
                -- Fechas de control
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                fecha_modificacion TIMESTAMP,
                
                FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id),
                FOREIGN KEY (creado_por_admin) REFERENCES usuarios(id),
                FOREIGN KEY (modificado_por_admin) REFERENCES usuarios(id)
            )",
            
            // 2. Crear tabla de historial
            "CREATE TABLE IF NOT EXISTS historial_modificaciones_pendientes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pendiente_id INTEGER NOT NULL,
                campo_modificado VARCHAR(50) NOT NULL,
                valor_anterior TEXT,
                valor_nuevo TEXT,
                modificado_por INTEGER NOT NULL,
                fecha_modificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (pendiente_id) REFERENCES materias_pendientes_intensificacion(id),
                FOREIGN KEY (modificado_por) REFERENCES usuarios(id)
            )",
            
            // 3. Crear tabla de contenidos (si no existe)
            "CREATE TABLE IF NOT EXISTS contenidos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                materia_curso_id INTEGER NOT NULL,
                profesor_id INTEGER NOT NULL,
                titulo VARCHAR(200) NOT NULL,
                descripcion TEXT,
                bimestre INTEGER NOT NULL CHECK (bimestre >= 1 AND bimestre <= 4),
                fecha_clase DATE NOT NULL,
                tipo_evaluacion VARCHAR(20) DEFAULT 'cualitativa' CHECK (tipo_evaluacion IN ('numerica', 'cualitativa')),
                orden INTEGER DEFAULT 0,
                activo BOOLEAN DEFAULT 1,
                fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (profesor_id) REFERENCES usuarios(id)
            )",
            
            // 4. Crear tabla de calificaciones de contenidos (si no existe)
            "CREATE TABLE IF NOT EXISTS contenidos_calificaciones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contenido_id INTEGER NOT NULL,
                estudiante_id INTEGER NOT NULL,
                calificacion VARCHAR(20),
                observaciones TEXT,
                fecha_calificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (contenido_id) REFERENCES contenidos(id),
                FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                UNIQUE(contenido_id, estudiante_id)
            )",
            
            // 5. Crear tabla para estudiantes por materia (subgrupos)
            "CREATE TABLE IF NOT EXISTS estudiantes_por_materia (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                estudiante_id INTEGER NOT NULL,
                materia_curso_id INTEGER NOT NULL,
                ciclo_lectivo_id INTEGER NOT NULL,
                subgrupo VARCHAR(50),
                activo BOOLEAN DEFAULT 1,
                fecha_asignacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id),
                UNIQUE(estudiante_id, materia_curso_id, ciclo_lectivo_id)
            )",
            
            // 6. Crear tabla para materias recursado
            "CREATE TABLE IF NOT EXISTS materias_recursado (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                estudiante_id INTEGER NOT NULL,
                materia_curso_id INTEGER NOT NULL,
                materia_liberada_id INTEGER,
                ciclo_lectivo_id INTEGER NOT NULL,
                estado VARCHAR(20) DEFAULT 'activo' CHECK (estado IN ('activo', 'completado', 'cancelado')),
                fecha_inicio DATE NOT NULL,
                fecha_fin DATE,
                observaciones TEXT,
                
                FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (materia_liberada_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
            )",
            
            // 7. Verificar columnas en materias_por_curso
            "PRAGMA table_info(materias_por_curso)",
            
            // 8-12. Crear índices
            "CREATE INDEX IF NOT EXISTS idx_materias_pendientes_estudiante ON materias_pendientes_intensificacion(estudiante_id)",
            "CREATE INDEX IF NOT EXISTS idx_materias_pendientes_materia ON materias_pendientes_intensificacion(materia_curso_id)",
            "CREATE INDEX IF NOT EXISTS idx_materias_pendientes_ciclo ON materias_pendientes_intensificacion(ciclo_lectivo_id)",
            "CREATE INDEX IF NOT EXISTS idx_materias_pendientes_estado ON materias_pendientes_intensificacion(estado)",
            "CREATE INDEX IF NOT EXISTS idx_historial_pendiente ON historial_modificaciones_pendientes(pendiente_id)",
            "CREATE INDEX IF NOT EXISTS idx_contenidos_materia ON contenidos(materia_curso_id)",
            "CREATE INDEX IF NOT EXISTS idx_contenidos_calificaciones ON contenidos_calificaciones(contenido_id, estudiante_id)",
            "CREATE INDEX IF NOT EXISTS idx_estudiantes_materia ON estudiantes_por_materia(estudiante_id, materia_curso_id)",
            "CREATE INDEX IF NOT EXISTS idx_materias_recursado ON materias_recursado(estudiante_id, materia_curso_id)"
        ];
        
        // Ejecutar comandos uno por uno
        foreach ($sqlCommands as $index => $sql) {
            try {
                if ($index === 6) {
                    // Verificar columnas existentes en materias_por_curso
                    $columns = $db->fetchAll($sql);
                    $hasProfesor2 = false;
                    $hasProfesor3 = false;
                    $hasRequiereSubgrupos = false;
                    
                    foreach ($columns as $column) {
                        if ($column['name'] === 'profesor_id_2') $hasProfesor2 = true;
                        if ($column['name'] === 'profesor_id_3') $hasProfesor3 = true;
                        if ($column['name'] === 'requiere_subgrupos') $hasRequiereSubgrupos = true;
                    }
                    
                    // Agregar columnas si no existen
                    if (!$hasProfesor2) {
                        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id_2 INTEGER");
                        $mensajes[] = "✓ Agregada columna profesor_id_2 a materias_por_curso";
                    } else {
                        $mensajes[] = "✓ Columna profesor_id_2 ya existe en materias_por_curso";
                    }
                    
                    if (!$hasProfesor3) {
                        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id_3 INTEGER");
                        $mensajes[] = "✓ Agregada columna profesor_id_3 a materias_por_curso";
                    } else {
                        $mensajes[] = "✓ Columna profesor_id_3 ya existe en materias_por_curso";
                    }
                    
                    if (!$hasRequiereSubgrupos) {
                        $db->query("ALTER TABLE materias_por_curso ADD COLUMN requiere_subgrupos BOOLEAN DEFAULT 0");
                        $mensajes[] = "✓ Agregada columna requiere_subgrupos a materias_por_curso";
                    } else {
                        $mensajes[] = "✓ Columna requiere_subgrupos ya existe en materias_por_curso";
                    }
                    
                } else {
                    $db->query($sql);
                    
                    // Mensajes específicos por comando
                    switch ($index) {
                        case 0:
                            $mensajes[] = "✓ Tabla 'materias_pendientes_intensificacion' creada correctamente";
                            break;
                        case 1:
                            $mensajes[] = "✓ Tabla 'historial_modificaciones_pendientes' creada correctamente";
                            break;
                        case 2:
                            $mensajes[] = "✓ Tabla 'contenidos' verificada/creada correctamente";
                            break;
                        case 3:
                            $mensajes[] = "✓ Tabla 'contenidos_calificaciones' verificada/creada correctamente";
                            break;
                        case 4:
                            $mensajes[] = "✓ Tabla 'estudiantes_por_materia' creada correctamente";
                            break;
                        case 5:
                            $mensajes[] = "✓ Tabla 'materias_recursado' creada correctamente";
                            break;
                        case 7:
                            $mensajes[] = "✓ Índice para estudiantes en materias pendientes creado";
                            break;
                        case 8:
                            $mensajes[] = "✓ Índice para materias pendientes creado";
                            break;
                        case 9:
                            $mensajes[] = "✓ Índice para ciclos lectivos en pendientes creado";
                            break;
                        case 10:
                            $mensajes[] = "✓ Índice para estados de pendientes creado";
                            break;
                        case 11:
                            $mensajes[] = "✓ Índice para historial de modificaciones creado";
                            break;
                        case 12:
                            $mensajes[] = "✓ Índice para contenidos por materia creado";
                            break;
                        case 13:
                            $mensajes[] = "✓ Índice para calificaciones de contenidos creado";
                            break;
                        case 14:
                            $mensajes[] = "✓ Índice para estudiantes por materia creado";
                            break;
                        case 15:
                            $mensajes[] = "✓ Índice para materias recursado creado";
                            break;
                    }
                }
            } catch (Exception $e) {
                $errores[] = "Error en comando " . ($index + 1) . ": " . $e->getMessage();
            }
        }
        
        // Verificar que las tablas se crearon correctamente
        try {
            $tablasEsperadas = [
                'materias_pendientes_intensificacion',
                'historial_modificaciones_pendientes',
                'contenidos',
                'contenidos_calificaciones',
                'estudiantes_por_materia',
                'materias_recursado'
            ];
            
            $tablasCreadas = 0;
            foreach ($tablasEsperadas as $nombreTabla) {
                $existe = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$nombreTabla]);
                if ($existe) {
                    $tablasCreadas++;
                }
            }
            
            if ($tablasCreadas >= 4) { // Al menos las principales
                $mensajes[] = "✓ Verificación: Sistema instalado correctamente ($tablasCreadas/" . count($tablasEsperadas) . " tablas)";
            } else {
                $errores[] = "⚠ Advertencia: Solo se crearon $tablasCreadas de " . count($tablasEsperadas) . " tablas esperadas";
            }
        } catch (Exception $e) {
            $errores[] = "Error en verificación: " . $e->getMessage();
        }
        
        // Crear datos de ejemplo si se solicitó
        if (isset($_POST['crear_ejemplos'])) {
            try {
                // Verificar que existan estudiantes y materias
                $estudiante = $db->fetchOne("SELECT id FROM usuarios WHERE tipo = 'estudiante' LIMIT 1");
                $materia = $db->fetchOne("SELECT id FROM materias_por_curso LIMIT 1");
                $ciclo = $db->fetchOne("SELECT id FROM ciclos_lectivos WHERE activo = 1");
                
                if ($estudiante && $materia && $ciclo) {
                    // Insertar ejemplo de materia pendiente
                    $existePendiente = $db->fetchOne(
                        "SELECT id FROM materias_pendientes_intensificacion 
                         WHERE estudiante_id = ? AND materia_curso_id = ? AND ciclo_lectivo_id = ?",
                        [$estudiante['id'], $materia['id'], $ciclo['id']]
                    );
                    
                    if (!$existePendiente) {
                        $db->query(
                            "INSERT INTO materias_pendientes_intensificacion 
                             (estudiante_id, materia_curso_id, ciclo_lectivo_id, saberes_iniciales, marzo, julio, estado, creado_por_admin)
                             VALUES (?, ?, ?, ?, ?, ?, 'activo', ?)",
                            [
                                $estudiante['id'], 
                                $materia['id'], 
                                $ciclo['id'],
                                'Funciones lineales y cuadráticas. Sistemas de ecuaciones. Geometría analítica básica.',
                                'CCA',
                                'AA',
                                $_SESSION['user_id']
                            ]
                        );
                        $mensajes[] = "✓ Ejemplo de materia pendiente creado";
                    }
                    
                    // Insertar ejemplo de contenido si existe la tabla
                    $profesor = $db->fetchOne("SELECT id FROM usuarios WHERE tipo = 'profesor' LIMIT 1");
                    if ($profesor) {
                        $existeContenido = $db->fetchOne(
                            "SELECT id FROM contenidos 
                             WHERE materia_curso_id = ? AND profesor_id = ? AND titulo = ?",
                            [$materia['id'], $profesor['id'], 'Ejemplo de contenido']
                        );
                        
                        if (!$existeContenido) {
                            $db->query(
                                "INSERT INTO contenidos 
                                 (materia_curso_id, profesor_id, titulo, descripcion, bimestre, fecha_clase, tipo_evaluacion)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $materia['id'],
                                    $profesor['id'],
                                    'Ejemplo de contenido',
                                    'Contenido de ejemplo para testing del sistema',
                                    1,
                                    date('Y-m-d'),
                                    'cualitativa'
                                ]
                            );
                            $mensajes[] = "✓ Ejemplo de contenido creado";
                        }
                    }
                    
                    $mensajes[] = "✓ Datos de ejemplo creados correctamente";
                } else {
                    $errores[] = "No se pudieron crear datos de ejemplo (faltan estudiantes, materias o ciclo lectivo)";
                }
            } catch (Exception $e) {
                $errores[] = "Error al crear datos de ejemplo: " . $e->getMessage();
            }
        }
        
    } catch (Exception $e) {
        $errores[] = "Error general: " . $e->getMessage();
    }
}

// Verificar estado actual de las tablas
$estadoTablas = [];
$estadoColumnas = [];

try {
    // Verificar tablas principales del sistema
    $tablasEsperadas = [
        'materias_pendientes_intensificacion' => 'Materias Pendientes',
        'historial_modificaciones_pendientes' => 'Historial de Cambios',
        'contenidos' => 'Contenidos Pedagógicos',
        'contenidos_calificaciones' => 'Calificaciones de Contenidos',
        'estudiantes_por_materia' => 'Estudiantes por Materia',
        'materias_recursado' => 'Materias Recursado'
    ];
    
    foreach ($tablasEsperadas as $nombreTabla => $descripcion) {
        $existe = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$nombreTabla]);
        if ($existe) {
            $count = $db->fetchOne("SELECT COUNT(*) as count FROM " . $nombreTabla);
            $estadoTablas[$nombreTabla] = [
                'existe' => true,
                'registros' => $count['count'],
                'descripcion' => $descripcion
            ];
        } else {
            $estadoTablas[$nombreTabla] = [
                'existe' => false,
                'registros' => 0,
                'descripcion' => $descripcion
            ];
        }
    }
    
    // Verificar columnas en materias_por_curso
    $columnasMPC = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
    $columnasImportantes = ['profesor_id_2', 'profesor_id_3', 'requiere_subgrupos'];
    
    foreach ($columnasImportantes as $columna) {
        $existe = false;
        foreach ($columnasMPC as $col) {
            if ($col['name'] === $columna) {
                $existe = true;
                break;
            }
        }
        $estadoColumnas[$columna] = $existe;
    }
    
} catch (Exception $e) {
    $estadoTablas = ['error' => $e->getMessage()];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación - Materias Pendientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-10">
            
            <!-- Encabezado -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0">
                        <i class="bi bi-database-gear"></i> 
                        Instalación del Sistema de Materias Pendientes
                    </h3>
                </div>
                <div class="card-body">
                    <p class="mb-0">
                        Este script creará las tablas necesarias para el sistema de gestión de materias pendientes de intensificación.
                        <br><strong>Base de datos:</strong> SQLite
                        <br><strong>Usuario:</strong> <?= htmlspecialchars($_SESSION['user_name'] ?? 'N/A') ?> (<?= $_SESSION['user_type'] ?>)
                    </p>
                </div>
            </div>

            <!-- Estado actual del sistema -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="bi bi-info-circle"></i> Estado Actual del Sistema</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($estadoTablas['error'])): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error al verificar el estado del sistema.
                            <br><small>Error: <?= htmlspecialchars($estadoTablas['error']) ?></small>
                        </div>
                    <?php else: ?>
                        <?php 
                        $tablasExistentes = count(array_filter($estadoTablas, function($tabla) { return $tabla['existe']; }));
                        $totalTablas = count($estadoTablas);
                        $sistemaCompleto = $tablasExistentes >= 4; // Al menos las tablas principales
                        ?>
                        
                        <div class="alert alert-<?= $sistemaCompleto ? 'success' : 'warning' ?>">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-<?= $sistemaCompleto ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                                <div>
                                    <strong>Estado del Sistema:</strong> 
                                    <?= $sistemaCompleto ? 'Instalado' : 'Instalación Incompleta' ?>
                                    (<?= $tablasExistentes ?>/<?= $totalTablas ?> tablas)
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tablas del sistema -->
                        <h6><i class="bi bi-table"></i> Tablas del Sistema:</h6>
                        <div class="row">
                            <?php foreach ($estadoTablas as $nombreTabla => $info): ?>
                            <div class="col-md-6 mb-2">
                                <div class="card border-<?= $info['existe'] ? 'success' : 'warning' ?>">
                                    <div class="card-body py-2">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-<?= $info['existe'] ? 'check-circle text-success' : 'x-circle text-warning' ?> me-2"></i>
                                            <div class="flex-grow-1">
                                                <strong style="font-size: 0.9em;"><?= htmlspecialchars($info['descripcion']) ?></strong>
                                                <br><small class="text-muted"><?= htmlspecialchars($nombreTabla) ?></small>
                                            </div>
                                            <div class="text-end">
                                                <?php if ($info['existe']): ?>
                                                    <span class="badge bg-success"><?= $info['registros'] ?> registros</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">No instalada</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Columnas adicionales -->
                        <h6 class="mt-3"><i class="bi bi-columns"></i> Columnas Adicionales:</h6>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="card border-info">
                                    <div class="card-body py-2">
                                        <strong>materias_por_curso:</strong>
                                        <?php foreach ($estadoColumnas as $columna => $existe): ?>
                                            <span class="badge bg-<?= $existe ? 'success' : 'warning' ?> ms-1">
                                                <?= $columna ?> <?= $existe ? '✓' : '✗' ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resultados de ejecución -->
            <?php if ($ejecutado): ?>
            <div class="card mb-4">
                <div class="card-header bg-<?= empty($errores) ? 'success' : 'warning' ?> text-white">
                    <h5><i class="bi bi-<?= empty($errores) ? 'check-circle' : 'exclamation-triangle' ?>"></i> 
                        Resultados de la Instalación
                    </h5>
                </div>
                <div class="card-body">
                    
                    <?php if (!empty($mensajes)): ?>
                    <div class="alert alert-success">
                        <h6><i class="bi bi-check-circle"></i> Operaciones Exitosas:</h6>
                        <ul class="mb-0">
                            <?php foreach ($mensajes as $mensaje): ?>
                            <li><?= htmlspecialchars($mensaje) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($errores)): ?>
                    <div class="alert alert-danger">
                        <h6><i class="bi bi-x-circle"></i> Errores Encontrados:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errores as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($errores)): ?>
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Instalación Completada</h6>
                        <p>El sistema de materias pendientes ha sido instalado correctamente. Ahora puedes:</p>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                            <a href="admin_materias_pendientes.php" class="btn btn-primary">
                                <i class="bi bi-shield-check"></i> Ir al Control Administrativo
                            </a>
                            <a href="calificar_materias_pendientes.php" class="btn btn-success">
                                <i class="bi bi-journal-medical"></i> Calificar Materias Pendientes
                            </a>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-house"></i> Volver al Inicio
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Formulario de instalación -->
            <?php if (!$ejecutado || !empty($errores)): ?>
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-play-circle"></i> Ejecutar Instalación</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <h6>¿Qué se va a instalar?</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary">Tablas Principales:</h6>
                                    <ul class="small">
                                        <li><strong>materias_pendientes_intensificacion:</strong> Registro de materias pendientes</li>
                                        <li><strong>historial_modificaciones_pendientes:</strong> Auditoría de cambios</li>
                                        <li><strong>contenidos:</strong> Contenidos pedagógicos</li>
                                        <li><strong>contenidos_calificaciones:</strong> Calificaciones de contenidos</li>
                                        <li><strong>estudiantes_por_materia:</strong> Sistema de subgrupos</li>
                                        <li><strong>materias_recursado:</strong> Gestión de recursado</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-success">Mejoras al Sistema:</h6>
                                    <ul class="small">
                                        <li><strong>Equipos docentes:</strong> Múltiples profesores por materia</li>
                                        <li><strong>Sistema de subgrupos:</strong> Para materias especiales</li>
                                        <li><strong>Control administrativo:</strong> Auditoría completa</li>
                                        <li><strong>Gestión de recursado:</strong> Con materias liberadas</li>
                                        <li><strong>Índices optimizados:</strong> Mejor rendimiento</li>
                                        <li><strong>Formato RITE:</strong> Cumple normativa oficial</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="crear_ejemplos" id="crear_ejemplos">
                                <label class="form-check-label" for="crear_ejemplos">
                                    Crear datos de ejemplo para testing
                                </label>
                                <div class="form-text">
                                    Esto creará algunos registros de ejemplo para probar el sistema
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Importante:</strong> Este script es seguro de ejecutar múltiples veces. 
                            Si las tablas ya existen, no se duplicarán.
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Cancelar
                            </a>
                            <button type="submit" name="ejecutar" class="btn btn-primary btn-lg">
                                <i class="bi bi-database-gear"></i> Ejecutar Instalación
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Información adicional -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6><i class="bi bi-info-circle"></i> Información Técnica</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Características del Sistema:</h6>
                            <ul class="small">
                                <li>Cumple Resolución N° 1650/24</li>
                                <li>Períodos: Marzo, Julio, Agosto, Diciembre, Febrero</li>
                                <li>Códigos: AA, CCA, CSA</li>
                                <li>Calificaciones: 1-10</li>
                                <li>Control administrativo completo</li>
                                <li>Auditoría de cambios</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Después de la Instalación:</h6>
                            <ul class="small">
                                <li>Los administradores pueden crear materias pendientes</li>
                                <li>Los profesores pueden calificar sus materias</li>
                                <li>Los directivos tienen acceso completo</li>
                                <li>Se registran todos los cambios</li>
                                <li>Compatible con equipos docentes</li>
                                <li>Formato oficial RITE</li>
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
