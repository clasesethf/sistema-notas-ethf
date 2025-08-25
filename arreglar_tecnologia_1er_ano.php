<?php
/**
 * arreglar_tecnologia_1er_ano_web.php - Versión WEB para agregar Tecnología 1 al grupo Procedimientos Técnicos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * VERSIÓN PARA NAVEGADOR WEB - No requiere terminal
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('<div class="alert alert-danger">❌ ERROR: Solo administradores pueden ejecutar este script.</div>');
}

// Inicializar variables
$paso = isset($_GET['paso']) ? intval($_GET['paso']) : 1;
$materiaSeleccionada = isset($_POST['materia_seleccionada']) ? intval($_POST['materia_seleccionada']) : null;
$recalcular = isset($_POST['recalcular']) ? $_POST['recalcular'] === 'si' : false;
$ejecutar = isset($_POST['ejecutar']) ? true : false;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corregir Agrupación - Tecnología 1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .log-success { color: #198754; }
        .log-warning { color: #fd7e14; }
        .log-error { color: #dc3545; }
        .log-info { color: #0dcaf0; }
        .console-output { 
            background: #f8f9fa; 
            border: 1px solid #dee2e6; 
            border-radius: 0.375rem; 
            padding: 1rem; 
            font-family: monospace; 
            max-height: 500px; 
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <h2><i class="bi bi-tools"></i> Corregir Agrupación - Tecnología 1</h2>
                <p class="text-muted">Agregar Tecnología 1 al grupo Procedimientos Técnicos de 1er año</p>
                <hr>

                <div class="console-output mb-4">
<?php
if ($ejecutar) {
    try {
        // Obtener conexión a la base de datos
        $db = Database::getInstance();
        
        echo "<div class='log-info'>🔧 CORRIGIENDO AGRUPACIÓN DE TECNOLOGÍA 1 EN 1ER AÑO</div>\n";
        echo "<div class='log-info'>===================================================</div>\n\n";
        
        // 1. OBTENER CICLO LECTIVO ACTIVO
        echo "<div class='log-info'>📅 1. Obteniendo ciclo lectivo activo...</div>\n";
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        
        if (!$cicloActivo) {
            throw new Exception("No hay un ciclo lectivo activo configurado");
        }
        
        $cicloLectivoId = $cicloActivo['id'];
        echo "<div class='log-success'>   ✅ Ciclo lectivo activo: {$cicloActivo['anio']} (ID: {$cicloLectivoId})</div>\n\n";
        
        // 2. BUSCAR EL GRUPO PROCEDIMIENTOS TÉCNICOS DE 1ER AÑO
        echo "<div class='log-info'>🔍 2. Buscando grupo 'PROCEDIMIENTOS TÉCNICOS' de 1er año...</div>\n";
        $grupoPT = $db->fetchOne(
            "SELECT * FROM grupos_materias 
             WHERE curso_anio = 1 AND ciclo_lectivo_id = ? 
             AND (UPPER(nombre) LIKE '%PROCEDIMIENTOS TÉCNICOS%' OR 
                  UPPER(nombre) LIKE '%PROCEDIMIENTOS TECNICOS%' OR 
                  codigo = 'PT' OR codigo = '1PT')
             AND activo = 1",
            [$cicloLectivoId]
        );
        
        if (!$grupoPT) {
            echo "<div class='log-warning'>   ⚠️ No se encontró el grupo 'PROCEDIMIENTOS TÉCNICOS' para 1er año.</div>\n";
            echo "<div class='log-info'>   🔄 Creando el grupo...</div>\n";
            
            $grupoPTId = $db->insert(
                "INSERT INTO grupos_materias (nombre, codigo, curso_anio, ciclo_lectivo_id, orden_visualizacion, activo) 
                 VALUES ('PROCEDIMIENTOS TÉCNICOS', 'PT', 1, ?, 1, 1)",
                [$cicloLectivoId]
            );
            
            // Crear regla de cálculo
            $db->query(
                "INSERT INTO reglas_calculo_grupo (grupo_id, tipo_calculo, nota_minima_prevalece, activo) 
                 VALUES (?, 'promedio_con_minima', 6.0, 1)",
                [$grupoPTId]
            );
            
            echo "<div class='log-success'>   ✅ Grupo creado: PROCEDIMIENTOS TÉCNICOS (PT) - ID: {$grupoPTId}</div>\n";
        } else {
            $grupoPTId = $grupoPT['id'];
            echo "<div class='log-success'>   ✅ Grupo encontrado: {$grupoPT['nombre']} ({$grupoPT['codigo']}) - ID: {$grupoPTId}</div>\n";
        }
        echo "\n";
        
        // 3. BUSCAR LA MATERIA TECNOLOGÍA 1 CON CÓDIGO 1PT3
        echo "<div class='log-info'>📖 3. Buscando materia 'Tecnología 1' (código 1PT3)...</div>\n";
        $tecnologia1 = null;
        
        if ($materiaSeleccionada) {
            // Usar materia seleccionada por el usuario
            $tecnologia1 = $db->fetchOne(
                "SELECT m.*, mp.id as materia_curso_id, c.anio as curso_anio
                 FROM materias m
                 JOIN materias_por_curso mp ON m.id = mp.materia_id
                 JOIN cursos c ON mp.curso_id = c.id
                 WHERE mp.id = ? AND c.anio = 1 AND c.ciclo_lectivo_id = ?",
                [$materiaSeleccionada, $cicloLectivoId]
            );
        } else {
            // Búsqueda automática
            $tecnologia1 = $db->fetchOne(
                "SELECT m.*, mp.id as materia_curso_id, c.anio as curso_anio
                 FROM materias m
                 JOIN materias_por_curso mp ON m.id = mp.materia_id
                 JOIN cursos c ON mp.curso_id = c.id
                 WHERE (m.codigo = '1PT3' OR 
                        UPPER(m.nombre) LIKE '%TECNOLOGÍA 1%' OR 
                        UPPER(m.nombre) LIKE '%TECNOLOGIA 1%')
                 AND c.anio = 1 AND c.ciclo_lectivo_id = ?",
                [$cicloLectivoId]
            );
        }
        
        if (!$tecnologia1) {
            echo "<div class='log-error'>   ❌ No se pudo encontrar la materia especificada.</div>\n";
            throw new Exception("Materia no encontrada");
        }
        
        echo "<div class='log-success'>   ✅ Materia encontrada: {$tecnologia1['nombre']} ({$tecnologia1['codigo']}) - ID: {$tecnologia1['materia_curso_id']}</div>\n\n";
        
        // 4. VERIFICAR SI YA ESTÁ EN ALGÚN GRUPO
        echo "<div class='log-info'>🔍 4. Verificando asignaciones actuales...</div>\n";
        $asignacionActual = $db->fetchOne(
            "SELECT mg.*, gm.nombre as grupo_nombre, gm.codigo as grupo_codigo
             FROM materias_grupo mg
             JOIN grupos_materias gm ON mg.grupo_id = gm.id
             WHERE mg.materia_curso_id = ? AND mg.activo = 1",
            [$tecnologia1['materia_curso_id']]
        );
        
        if ($asignacionActual) {
            if ($asignacionActual['grupo_id'] == $grupoPTId) {
                echo "<div class='log-info'>   ℹ️ La materia ya está asignada al grupo correcto: {$asignacionActual['grupo_nombre']}</div>\n";
                echo "<div class='log-success'>   ✅ No se requiere ningún cambio.</div>\n\n";
            } else {
                echo "<div class='log-warning'>   ⚠️ La materia está asignada a otro grupo: {$asignacionActual['grupo_nombre']} ({$asignacionActual['grupo_codigo']})</div>\n";
                echo "<div class='log-info'>   🔄 Moviendo al grupo 'PROCEDIMIENTOS TÉCNICOS'...</div>\n";
                
                // Desactivar asignación actual
                $db->query(
                    "UPDATE materias_grupo SET activo = 0 WHERE id = ?",
                    [$asignacionActual['id']]
                );
                echo "<div class='log-success'>   ✅ Asignación anterior desactivada.</div>\n";
            }
        } else {
            echo "<div class='log-info'>   ℹ️ La materia no está asignada a ningún grupo.</div>\n";
        }
        
        // 5. ASIGNAR AL GRUPO PROCEDIMIENTOS TÉCNICOS
        if (!$asignacionActual || $asignacionActual['grupo_id'] != $grupoPTId) {
            echo "<div class='log-info'>🔗 5. Asignando 'Tecnología 1' al grupo 'PROCEDIMIENTOS TÉCNICOS'...</div>\n";
            
            $db->query(
                "INSERT INTO materias_grupo (grupo_id, materia_curso_id, tipo_duracion, trimestre_inicio, activo) 
                 VALUES (?, ?, 'anual', 1, 1)",
                [$grupoPTId, $tecnologia1['materia_curso_id']]
            );
            
            echo "<div class='log-success'>   ✅ Materia asignada correctamente al grupo como materia ANUAL.</div>\n\n";
        }
        
        // 6. VERIFICAR ESTRUCTURA COMPLETA DEL GRUPO
        echo "<div class='log-info'>🔍 6. Verificando estructura del grupo 'PROCEDIMIENTOS TÉCNICOS'...</div>\n";
        
        $materiasDelGrupo = $db->fetchAll(
            "SELECT m.nombre, m.codigo, mg.tipo_duracion, mg.trimestre_inicio, mp.requiere_subgrupos
             FROM materias_grupo mg
             JOIN materias_por_curso mp ON mg.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             WHERE mg.grupo_id = ? AND mg.activo = 1
             ORDER BY mg.tipo_duracion DESC, mg.trimestre_inicio, m.codigo",
            [$grupoPTId]
        );
        
        echo "<div class='log-info'>   📋 Materias en el grupo 'PROCEDIMIENTOS TÉCNICOS':</div>\n";
        foreach ($materiasDelGrupo as $materia) {
            $subgrupos = $materia['requiere_subgrupos'] ? ' [REQUIERE SUBGRUPOS]' : '';
            echo "<div class='log-info'>      • {$materia['nombre']} ({$materia['codigo']}) - {$materia['tipo_duracion']} (T{$materia['trimestre_inicio']}){$subgrupos}</div>\n";
        }
        
        if (count($materiasDelGrupo) >= 3) {
            echo "<div class='log-success'>   ✅ Estructura correcta: " . count($materiasDelGrupo) . " materias en el grupo.</div>\n";
        } else {
            echo "<div class='log-warning'>   ⚠️ Posible estructura incompleta: solo " . count($materiasDelGrupo) . " materias en el grupo.</div>\n";
            echo "<div class='log-info'>      Se esperan típicamente 3 materias: Metales 1 (1PT1), Maderas 1 (1PT2), y Tecnología 1 (1PT3).</div>\n";
        }
        echo "\n";
        
        // 7. RECALCULAR CALIFICACIONES SI SE SOLICITÓ
        if ($recalcular) {
            echo "<div class='log-info'>🔄 7. Recalculando calificaciones de grupo...</div>\n";
            
            // Obtener todos los estudiantes de 1er año con calificaciones
            $estudiantes = $db->fetchAll(
                "SELECT DISTINCT c.estudiante_id, u.nombre, u.apellido
                 FROM calificaciones c
                 JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                 JOIN cursos curso ON mp.curso_id = curso.id
                 JOIN usuarios u ON c.estudiante_id = u.id
                 WHERE curso.anio = 1 AND curso.ciclo_lectivo_id = ?",
                [$cicloLectivoId]
            );
            
            $curso1ro = $db->fetchOne(
                "SELECT id FROM cursos WHERE anio = 1 AND ciclo_lectivo_id = ?",
                [$cicloLectivoId]
            );
            
            if ($curso1ro && !empty($estudiantes)) {
                // Incluir funciones de agrupación si están disponibles
                if (file_exists('includes/funciones_agrupacion_materias.php')) {
                    require_once 'includes/funciones_agrupacion_materias.php';
                    
                    foreach ($estudiantes as $estudiante) {
                        recalcularCalificacionesGruposEstudiante($db, $estudiante['estudiante_id'], $cicloLectivoId, $curso1ro['id']);
                        echo "<div class='log-success'>      ✅ Recalculado: {$estudiante['nombre']} {$estudiante['apellido']}</div>\n";
                    }
                    echo "<div class='log-success'>   ✅ Calificaciones recalculadas para " . count($estudiantes) . " estudiantes.</div>\n";
                } else {
                    echo "<div class='log-warning'>   ⚠️ Archivo de funciones de agrupación no encontrado.</div>\n";
                    echo "<div class='log-info'>      Las calificaciones se recalcularán automáticamente cuando se visualicen los boletines.</div>\n";
                }
            } else {
                echo "<div class='log-info'>   ℹ️ No se encontraron estudiantes con calificaciones para recalcular.</div>\n";
            }
        }
        echo "\n";
        
        // 8. RESULTADO FINAL
        echo "<div class='log-success'>🎉 ¡CORRECCIÓN COMPLETADA EXITOSAMENTE!</div>\n";
        echo "<div class='log-success'>======================================</div>\n\n";
        
        echo "<div class='log-info'>📋 RESUMEN DE CAMBIOS:</div>\n";
        echo "<div class='log-info'>• Materia 'Tecnología 1' asignada al grupo 'PROCEDIMIENTOS TÉCNICOS'</div>\n";
        echo "<div class='log-info'>• Configuración como materia ANUAL (todo el año)</div>\n";
        echo "<div class='log-info'>• Grupo 'PROCEDIMIENTOS TÉCNICOS' de 1er año actualizado</div>\n\n";
        
        echo "<div class='log-success'>✅ VERIFICACIONES RECOMENDADAS:</div>\n";
        echo "<div class='log-info'>1. Revisar boletines de estudiantes de 1er año</div>\n";
        echo "<div class='log-info'>2. Verificar que las calificaciones de Tecnología 1 aparezcan en el grupo</div>\n";
        echo "<div class='log-info'>3. Comprobar que las valoraciones preliminares se calculen correctamente</div>\n";
        echo "<div class='log-info'>4. Asegurar que la calificación final del grupo incluya Tecnología 1</div>\n\n";
        
        echo "<div class='log-success'>✅ El problema de agrupación ha sido solucionado.</div>\n";
        
    } catch (Exception $e) {
        echo "<div class='log-error'>❌ ERROR: " . $e->getMessage() . "</div>\n";
        echo "<div class='log-error'>La corrección no se completó. Revise el error y vuelva a intentar.</div>\n";
    }
} else {
    // Mostrar formulario de configuración
    try {
        $db = Database::getInstance();
        
        // Obtener ciclo lectivo activo
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        if (!$cicloActivo) {
            throw new Exception("No hay un ciclo lectivo activo configurado");
        }
        $cicloLectivoId = $cicloActivo['id'];
        
        // Buscar materia automáticamente
        $tecnologiaAuto = $db->fetchOne(
            "SELECT m.*, mp.id as materia_curso_id
             FROM materias m
             JOIN materias_por_curso mp ON m.id = mp.materia_id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE (m.codigo = '1PT3' OR 
                    UPPER(m.nombre) LIKE '%TECNOLOGÍA 1%' OR 
                    UPPER(m.nombre) LIKE '%TECNOLOGIA 1%')
             AND c.anio = 1 AND c.ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
        
        // Obtener todas las materias de 1er año por si no encuentra automáticamente
        $materiasDisponibles = $db->fetchAll(
            "SELECT m.nombre, m.codigo, mp.id as materia_curso_id
             FROM materias m
             JOIN materias_por_curso mp ON m.id = mp.materia_id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.anio = 1 AND c.ciclo_lectivo_id = ?
             ORDER BY m.codigo, m.nombre",
            [$cicloLectivoId]
        );
        
        if ($tecnologiaAuto) {
            echo "<div class='alert alert-success'>";
            echo "<i class='bi bi-check-circle'></i> <strong>Materia encontrada automáticamente:</strong><br>";
            echo "📖 {$tecnologiaAuto['nombre']} ({$tecnologiaAuto['codigo']}) - ID: {$tecnologiaAuto['materia_curso_id']}";
            echo "</div>";
        } else {
            echo "<div class='alert alert-warning'>";
            echo "<i class='bi bi-exclamation-triangle'></i> <strong>No se encontró automáticamente 'Tecnología 1' (1PT3)</strong><br>";
            echo "Deberá seleccionar manualmente la materia correcta.";
            echo "</div>";
        }
?>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="ejecutar" value="1">
                    
                    <?php if (!$tecnologiaAuto && !empty($materiasDisponibles)): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-warning text-dark">
                            <i class="bi bi-list-ul"></i> Seleccionar Materia
                        </div>
                        <div class="card-body">
                            <p>Seleccione la materia que corresponde a <strong>"Tecnología 1"</strong>:</p>
                            <div class="row">
                                <?php foreach ($materiasDisponibles as $materia): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="materia_seleccionada" 
                                               value="<?= $materia['materia_curso_id'] ?>" id="materia_<?= $materia['materia_curso_id'] ?>">
                                        <label class="form-check-label" for="materia_<?= $materia['materia_curso_id'] ?>">
                                            <strong><?= htmlspecialchars($materia['nombre']) ?></strong><br>
                                            <small class="text-muted">Código: <?= htmlspecialchars($materia['codigo']) ?> | ID: <?= $materia['materia_curso_id'] ?></small>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="materia_seleccionada" value="<?= $tecnologiaAuto['materia_curso_id'] ?>">
                    <?php endif; ?>
                    
                    <div class="card mb-3">
                        <div class="card-header bg-info text-white">
                            <i class="bi bi-gear"></i> Opciones de Configuración
                        </div>
                        <div class="card-body">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="recalcular" value="si" id="recalcular">
                                <label class="form-check-label" for="recalcular">
                                    <strong>Recalcular calificaciones existentes</strong><br>
                                    <small class="text-muted">Actualiza los boletines de estudiantes que ya tengan calificaciones cargadas</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-play-circle"></i> Ejecutar Corrección
                        </button>
                        <a href="javascript:history.back()" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver
                        </a>
                    </div>
                </form>

<?php
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>";
        echo "<i class='bi bi-exclamation-triangle'></i> <strong>Error:</strong> " . $e->getMessage();
        echo "</div>";
    }
}
?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>