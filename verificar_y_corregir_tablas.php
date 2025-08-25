<?php
/**
 * verificar_y_corregir_tablas.php - Verificación y corrección de tablas
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    $_SESSION['message'] = 'Solo los administradores pueden verificar la base de datos';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$ejecutarCorreccion = isset($_POST['ejecutar_correccion']) && $_POST['ejecutar_correccion'] === 'SI_CORREGIR';
$resultados = [];
$errores = [];
$verificacion = [];

$db = Database::getInstance();

// Función para obtener estructura de tabla
function obtenerEstructuraTabla($db, $tabla) {
    try {
        $columnas = $db->fetchAll("PRAGMA table_info($tabla)");
        return $columnas;
    } catch (Exception $e) {
        return false;
    }
}

// Función para verificar si tabla existe
function tablaExiste($db, $tabla) {
    try {
        $resultado = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$tabla]);
        return $resultado !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Función para ejecutar SQL de forma segura
function ejecutarSQL($db, $sql, $descripcion) {
    global $resultados, $errores;
    
    try {
        $db->query($sql);
        $resultados[] = "✓ $descripcion";
        return true;
    } catch (Exception $e) {
        $errores[] = "✗ Error en $descripcion: " . $e->getMessage();
        return false;
    }
}

// ========================================
// VERIFICACIÓN DE TABLAS Y COLUMNAS
// ========================================

// 1. Verificar tabla materias_recursado
if (tablaExiste($db, 'materias_recursado')) {
    $estructura = obtenerEstructuraTabla($db, 'materias_recursado');
    $columnas = array_column($estructura, 'name');
    
    $verificacion['materias_recursado'] = [
        'existe' => true,
        'columnas' => $columnas,
        'columnas_requeridas' => [
            'id', 'estudiante_id', 'materia_curso_id', 'materia_liberada_id', 
            'ciclo_lectivo_id', 'fecha_asignacion', 'observaciones', 'estado'
        ]
    ];
    
    $faltantes = array_diff($verificacion['materias_recursado']['columnas_requeridas'], $columnas);
    $verificacion['materias_recursado']['columnas_faltantes'] = $faltantes;
} else {
    $verificacion['materias_recursado'] = [
        'existe' => false,
        'columnas_faltantes' => ['toda la tabla']
    ];
}

// 2. Verificar otras tablas críticas
$tablasRequeridas = [
    'configuracion_subgrupos' => [
        'id', 'materia_curso_id', 'ciclo_lectivo_id', 'tipo_division', 
        'cantidad_grupos', 'activo'
    ],
    'estudiantes_por_materia' => [
        'id', 'materia_curso_id', 'estudiante_id', 'ciclo_lectivo_id', 
        'subgrupo', 'activo'
    ],
    'observaciones_predefinidas' => [
        'id', 'tipo', 'categoria', 'mensaje', 'activo'
    ],
    'notificaciones' => [
        'id', 'estudiante_id', 'ciclo_lectivo_id', 'cuatrimestre'
    ]
];

foreach ($tablasRequeridas as $tabla => $columnasReq) {
    if (tablaExiste($db, $tabla)) {
        $estructura = obtenerEstructuraTabla($db, $tabla);
        $columnas = array_column($estructura, 'name');
        
        $verificacion[$tabla] = [
            'existe' => true,
            'columnas' => $columnas,
            'columnas_requeridas' => $columnasReq,
            'columnas_faltantes' => array_diff($columnasReq, $columnas)
        ];
    } else {
        $verificacion[$tabla] = [
            'existe' => false,
            'columnas_faltantes' => ['toda la tabla']
        ];
    }
}

// 3. Verificar columnas en tabla calificaciones
if (tablaExiste($db, 'calificaciones')) {
    $estructura = obtenerEstructuraTabla($db, 'calificaciones');
    $columnas = array_column($estructura, 'name');
    
    $columnasNuevas = [
        'valoracion_1bim', 'desempeno_1bim', 'observaciones_1bim',
        'valoracion_3bim', 'desempeno_3bim', 'observaciones_3bim',
        'intensificacion_diciembre', 'intensificacion_febrero', 'tipo_cursada'
    ];
    
    $verificacion['calificaciones'] = [
        'existe' => true,
        'columnas' => $columnas,
        'columnas_nuevas_requeridas' => $columnasNuevas,
        'columnas_faltantes' => array_diff($columnasNuevas, $columnas)
    ];
}

// 4. Verificar columna en materias_por_curso
if (tablaExiste($db, 'materias_por_curso')) {
    $estructura = obtenerEstructuraTabla($db, 'materias_por_curso');
    $columnas = array_column($estructura, 'name');
    
    $verificacion['materias_por_curso'] = [
        'existe' => true,
        'columnas' => $columnas,
        'requiere_subgrupos' => in_array('requiere_subgrupos', $columnas)
    ];
}

// ========================================
// CORRECCIÓN AUTOMÁTICA
// ========================================

if ($ejecutarCorreccion) {
    
    // 1. Crear/corregir tabla materias_recursado
    if (!$verificacion['materias_recursado']['existe'] || !empty($verificacion['materias_recursado']['columnas_faltantes'])) {
        
        // Eliminar tabla si existe pero está incompleta
        if ($verificacion['materias_recursado']['existe']) {
            ejecutarSQL($db, "DROP TABLE materias_recursado", "Eliminada tabla materias_recursado incompleta");
        }
        
        // Crear tabla completa
        $sql_recursado = "
        CREATE TABLE materias_recursado (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            estudiante_id INTEGER NOT NULL,
            materia_curso_id INTEGER NOT NULL,
            materia_liberada_id INTEGER,
            ciclo_lectivo_id INTEGER NOT NULL,
            fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_finalizacion DATETIME,
            observaciones TEXT,
            estado TEXT DEFAULT 'activo' CHECK(estado IN ('activo', 'finalizado', 'cancelado')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
            FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
            FOREIGN KEY (materia_liberada_id) REFERENCES materias_por_curso(id),
            FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
        )";
        
        ejecutarSQL($db, $sql_recursado, "Creada tabla materias_recursado completa");
    }
    
    // 2. Crear tabla configuracion_subgrupos si no existe
    if (!$verificacion['configuracion_subgrupos']['existe']) {
        $sql_subgrupos = "
        CREATE TABLE configuracion_subgrupos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            materia_curso_id INTEGER NOT NULL,
            ciclo_lectivo_id INTEGER NOT NULL,
            tipo_division TEXT NOT NULL CHECK(tipo_division IN ('tercio', 'mitad', 'manual')),
            cantidad_grupos INTEGER DEFAULT 2 CHECK(cantidad_grupos BETWEEN 2 AND 6),
            rotacion_automatica BOOLEAN DEFAULT 0,
            periodo_rotacion TEXT CHECK(periodo_rotacion IN ('trimestre', 'cuatrimestre', 'bimestre')),
            descripcion TEXT,
            activo BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
            FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
        )";
        
        ejecutarSQL($db, $sql_subgrupos, "Creada tabla configuracion_subgrupos");
    }
    
    // 3. Crear tabla estudiantes_por_materia si no existe
    if (!$verificacion['estudiantes_por_materia']['existe']) {
        $sql_estudiantes = "
        CREATE TABLE estudiantes_por_materia (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            materia_curso_id INTEGER NOT NULL,
            estudiante_id INTEGER NOT NULL,
            ciclo_lectivo_id INTEGER NOT NULL,
            subgrupo TEXT,
            periodo_inicio TEXT,
            periodo_fin TEXT,
            fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
            activo BOOLEAN DEFAULT 1,
            
            FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
            FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
            FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
        )";
        
        ejecutarSQL($db, $sql_estudiantes, "Creada tabla estudiantes_por_materia");
    }
    
    // 4. Crear tabla observaciones_predefinidas si no existe
    if (!$verificacion['observaciones_predefinidas']['existe']) {
        $sql_observaciones = "
        CREATE TABLE observaciones_predefinidas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT NOT NULL CHECK(tipo IN ('valoracion', 'calificacion', 'general')),
            categoria TEXT NOT NULL,
            mensaje TEXT NOT NULL,
            activo BOOLEAN DEFAULT 1,
            orden INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        ejecutarSQL($db, $sql_observaciones, "Creada tabla observaciones_predefinidas");
        
        // Insertar observaciones básicas
        $observaciones = [
            ['valoracion', 'Desempeño', 'Demuestra comprensión avanzada'],
            ['valoracion', 'Desempeño', 'Participa activamente'],
            ['valoracion', 'Desempeño', 'Necesita refuerzo'],
            ['calificacion', 'Evaluaciones', 'Superó las expectativas'],
            ['calificacion', 'Evaluaciones', 'Alcanzó los objetivos'],
            ['general', 'Actitudinal', 'Demuestra responsabilidad']
        ];
        
        foreach ($observaciones as $obs) {
            try {
                $db->query("INSERT INTO observaciones_predefinidas (tipo, categoria, mensaje) VALUES (?, ?, ?)", $obs);
            } catch (Exception $e) {
                // Continuar
            }
        }
        $resultados[] = "✓ Insertadas observaciones predefinidas básicas";
    }
    
    // 5. Crear tabla notificaciones si no existe
    if (!$verificacion['notificaciones']['existe']) {
        $sql_notificaciones = "
        CREATE TABLE notificaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            estudiante_id INTEGER NOT NULL,
            ciclo_lectivo_id INTEGER NOT NULL,
            cuatrimestre INTEGER NOT NULL CHECK(cuatrimestre IN (1, 2)),
            fecha_notificacion DATETIME DEFAULT CURRENT_TIMESTAMP,
            firma_responsable BOOLEAN DEFAULT 0,
            firma_estudiante BOOLEAN DEFAULT 0,
            observaciones TEXT,
            
            FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
            FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
        )";
        
        ejecutarSQL($db, $sql_notificaciones, "Creada tabla notificaciones");
    }
    
    // 6. Agregar campo requiere_subgrupos si no existe
    if (!$verificacion['materias_por_curso']['requiere_subgrupos']) {
        ejecutarSQL($db, 
            "ALTER TABLE materias_por_curso ADD COLUMN requiere_subgrupos BOOLEAN DEFAULT 0",
            "Agregado campo requiere_subgrupos"
        );
    }
    
    // 7. Agregar columnas faltantes en calificaciones
    if (!empty($verificacion['calificaciones']['columnas_faltantes'])) {
        $columnasDef = [
            'valoracion_1bim' => "TEXT CHECK(valoracion_1bim IN ('TEA', 'TEP', 'TED'))",
            'desempeno_1bim' => "TEXT",
            'observaciones_1bim' => "TEXT",
            'valoracion_3bim' => "TEXT CHECK(valoracion_3bim IN ('TEA', 'TEP', 'TED'))",
            'desempeno_3bim' => "TEXT",
            'observaciones_3bim' => "TEXT",
            'intensificacion_diciembre' => "INTEGER CHECK(intensificacion_diciembre BETWEEN 1 AND 10)",
            'intensificacion_febrero' => "INTEGER CHECK(intensificacion_febrero BETWEEN 1 AND 10)",
            'tipo_cursada' => "TEXT DEFAULT 'C' CHECK(tipo_cursada IN ('C', 'R', 'L'))"
        ];
        
        foreach ($verificacion['calificaciones']['columnas_faltantes'] as $columna) {
            if (isset($columnasDef[$columna])) {
                ejecutarSQL($db,
                    "ALTER TABLE calificaciones ADD COLUMN $columna " . $columnasDef[$columna],
                    "Agregada columna $columna a calificaciones"
                );
            }
        }
    }
    
    $_SESSION['message'] = 'Corrección completada. Total: ' . count($resultados) . ' operaciones, ' . count($errores) . ' errores';
    $_SESSION['message_type'] = count($errores) == 0 ? 'success' : 'warning';
}

// Incluir el encabezado
require_once 'header.php';
?>

<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-search"></i> 
                        Verificación y Corrección de Base de Datos
                    </h5>
                </div>
                <div class="card-body">
                    
                    <?php if (!$ejecutarCorreccion): ?>
                    <!-- Mostrar verificación -->
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Estado actual de la base de datos</h6>
                        <p>Esta herramienta verifica que todas las tablas y columnas necesarias existan correctamente.</p>
                    </div>
                    
                    <div class="row">
                        <?php foreach ($verificacion as $tabla => $info): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <?php if ($info['existe']): ?>
                                            <i class="bi bi-check-circle text-success"></i>
                                        <?php else: ?>
                                            <i class="bi bi-x-circle text-danger"></i>
                                        <?php endif; ?>
                                        <?= $tabla ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($info['existe']): ?>
                                        <p class="text-success mb-1">✓ Tabla existe</p>
                                        
                                        <?php if (!empty($info['columnas_faltantes'])): ?>
                                            <p class="text-warning mb-1">⚠ Columnas faltantes:</p>
                                            <ul class="list-unstyled mb-2">
                                                <?php foreach ($info['columnas_faltantes'] as $col): ?>
                                                <li><small class="text-danger">• <?= $col ?></small></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-success mb-1">✓ Todas las columnas presentes</p>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($info['columnas'])): ?>
                                        <details>
                                            <summary class="text-muted">Ver columnas existentes (<?= count($info['columnas']) ?>)</summary>
                                            <small class="text-muted">
                                                <?= implode(', ', $info['columnas']) ?>
                                            </small>
                                        </details>
                                        <?php endif; ?>
                                        
                                    <?php else: ?>
                                        <p class="text-danger">✗ Tabla no existe</p>
                                        <p class="text-muted"><small>Necesita ser creada completamente</small></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php 
                    $necesitaCorreccion = false;
                    foreach ($verificacion as $info) {
                        if (!$info['existe'] || !empty($info['columnas_faltantes'])) {
                            $necesitaCorreccion = true;
                            break;
                        }
                    }
                    ?>
                    
                    <?php if ($necesitaCorreccion): ?>
                    <div class="alert alert-warning">
                        <h6><i class="bi bi-exclamation-triangle"></i> Se encontraron problemas</h6>
                        <p>Algunas tablas o columnas necesarias no existen. Se recomienda ejecutar la corrección automática.</p>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('¿Está seguro de ejecutar la corrección automática?\n\nEsto creará/corregirá las tablas faltantes.')">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmar_correccion" required>
                            <label class="form-check-label" for="confirmar_correccion">
                                <strong>Confirmo que deseo corregir la estructura de la base de datos</strong>
                            </label>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <input type="hidden" name="ejecutar_correccion" value="SI_CORREGIR">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-tools"></i> 
                                EJECUTAR CORRECCIÓN AUTOMÁTICA
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> 
                                Volver al Inicio
                            </a>
                        </div>
                    </form>
                    
                    <?php else: ?>
                    <div class="alert alert-success">
                        <h6><i class="bi bi-check-circle"></i> Base de datos correcta</h6>
                        <p>Todas las tablas y columnas necesarias están presentes. El sistema debería funcionar correctamente.</p>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                        <a href="gestionar_recursados_mejorado.php" class="btn btn-primary">
                            <i class="bi bi-arrow-repeat"></i> Probar Gestión de Recursados
                        </a>
                        <a href="calificaciones.php" class="btn btn-success">
                            <i class="bi bi-journal-check"></i> Ir a Calificaciones
                        </a>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="bi bi-house"></i> Volver al Inicio
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <!-- Mostrar resultados de la corrección -->
                    
                    <?php if (!empty($errores)): ?>
                    <div class="alert alert-danger">
                        <h6><i class="bi bi-x-circle"></i> Errores durante la corrección:</h6>
                        <ul class="mb-0">
                            <?php foreach ($errores as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($resultados)): ?>
                    <div class="alert alert-<?= count($errores) == 0 ? 'success' : 'warning' ?>">
                        <h6><i class="bi bi-check-circle"></i> Corrección completada</h6>
                        <p>Se realizaron <?= count($resultados) ?> operaciones correctivas.</p>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">Operaciones realizadas:</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($resultados as $resultado): ?>
                                <li class="list-group-item py-1">
                                    <small><?= htmlspecialchars($resultado) ?></small>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-4 d-grid gap-2 d-md-flex justify-content-md-center">
                        <a href="gestionar_recursados_mejorado.php" class="btn btn-primary">
                            <i class="bi bi-arrow-repeat"></i> Probar Gestión de Recursados
                        </a>
                        <a href="calificaciones.php" class="btn btn-success">
                            <i class="bi bi-journal-check"></i> Probar Calificaciones
                        </a>
                        <a href="verificar_y_corregir_tablas.php" class="btn btn-info">
                            <i class="bi bi-arrow-clockwise"></i> Verificar Nuevamente
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>