<?php
/**
 * crear_tabla_materias_pendientes_sqlite.php
 * Script para crear la tabla de materias pendientes de intensificación - VERSIÓN SQLITE
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * INSTRUCCIONES:
 * 1. Subir este archivo a tu servidor
 * 2. Acceder desde el navegador: http://tudominio.com/crear_tabla_materias_pendientes_sqlite.php
 * 3. Ejecutar el script
 * 4. ¡IMPORTANTE! Eliminar este archivo después de usarlo por seguridad
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar que sea un administrador (opcional, puedes comentar estas líneas si necesitas)
session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    die('<div style="color: red; font-family: Arial;">❌ Solo los administradores pueden ejecutar este script.</div>');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Tabla Materias Pendientes - Henry Ford (SQLite)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3><i class="bi bi-database-gear"></i> Crear Tabla - Materias Pendientes de Intensificación (SQLite)</h3>
                    </div>
                    <div class="card-body">

<?php
// Procesar la creación de la tabla
if (isset($_POST['crear_tabla'])) {
    try {
        $db = Database::getInstance();
        
        echo '<div class="alert alert-info">
                <i class="bi bi-gear"></i> <strong>Iniciando proceso de creación para SQLite...</strong>
              </div>';
        
        // SQL para crear la tabla - ADAPTADO PARA SQLITE
        $sqlTabla = "
        CREATE TABLE IF NOT EXISTS `materias_pendientes_intensificacion` (
          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
          `estudiante_id` INTEGER NOT NULL,
          `materia_curso_id` INTEGER NOT NULL,
          `ciclo_lectivo_id` INTEGER NOT NULL,
          `anio_cursada` INTEGER NOT NULL, -- Año en que cursó originalmente la materia
          `saberes_iniciales` TEXT DEFAULT NULL, -- Saberes pendientes al inicio del ciclo
          `marzo` TEXT DEFAULT NULL, -- Calificación período marzo
          `julio` TEXT DEFAULT NULL, -- Calificación período julio
          `agosto` TEXT DEFAULT NULL, -- Calificación período agosto
          `diciembre` TEXT DEFAULT NULL, -- Calificación período diciembre
          `febrero` TEXT DEFAULT NULL, -- Calificación período febrero
          `calificacion_final` REAL DEFAULT NULL, -- Calificación final de intensificación
          `saberes_cierre` TEXT DEFAULT NULL, -- Saberes pendientes al cierre del ciclo
          `observaciones` TEXT DEFAULT NULL, -- Observaciones adicionales
          `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `fecha_modificacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `estado` TEXT NOT NULL DEFAULT 'activo' CHECK(estado IN ('activo','inactivo')),
          UNIQUE(`estudiante_id`, `materia_curso_id`, `ciclo_lectivo_id`),
          FOREIGN KEY (`estudiante_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`materia_curso_id`) REFERENCES `materias_por_curso` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`ciclo_lectivo_id`) REFERENCES `ciclos_lectivos` (`id`) ON DELETE CASCADE
        )";
        
        // Ejecutar creación de tabla
        $db->query($sqlTabla);
        echo '<div class="alert alert-success">
                <i class="bi bi-check-circle"></i> ✅ Tabla <strong>materias_pendientes_intensificacion</strong> creada exitosamente.
              </div>';
        
        // Crear índices adicionales para SQLite
        $indices = [
            "CREATE INDEX IF NOT EXISTS `idx_estudiante` ON `materias_pendientes_intensificacion` (`estudiante_id`)",
            "CREATE INDEX IF NOT EXISTS `idx_materia_curso` ON `materias_pendientes_intensificacion` (`materia_curso_id`)",
            "CREATE INDEX IF NOT EXISTS `idx_ciclo_lectivo` ON `materias_pendientes_intensificacion` (`ciclo_lectivo_id`)",
            "CREATE INDEX IF NOT EXISTS `idx_anio_cursada` ON `materias_pendientes_intensificacion` (`anio_cursada`)",
            "CREATE INDEX IF NOT EXISTS `idx_estado` ON `materias_pendientes_intensificacion` (`estado`)",
            "CREATE INDEX IF NOT EXISTS `idx_fecha_creacion` ON `materias_pendientes_intensificacion` (`fecha_creacion`)"
        ];
        
        $indicesCreados = 0;
        foreach ($indices as $indiceSql) {
            try {
                $db->query($indiceSql);
                $indicesCreados++;
            } catch (Exception $e) {
                echo '<div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Advertencia índice: ' . htmlspecialchars($e->getMessage()) . '
                      </div>';
            }
        }
        
        if ($indicesCreados > 0) {
            echo '<div class="alert alert-success">
                    <i class="bi bi-search"></i> ✅ ' . $indicesCreados . ' índices creados para optimización.
                  </div>';
        }
        
        // Verificar que la tabla se creó correctamente (SQLite)
        $verificacion = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='materias_pendientes_intensificacion'");
        
        if ($verificacion) {
            // Mostrar estructura de la tabla (SQLite)
            $estructura = $db->fetchAll("PRAGMA table_info(materias_pendientes_intensificacion)");
            
            echo '<div class="alert alert-success">
                    <h5><i class="bi bi-check-circle-fill"></i> ¡TABLA CREADA EXITOSAMENTE!</h5>
                    <p>La tabla <strong>materias_pendientes_intensificacion</strong> ha sido creada con éxito en SQLite y está lista para usar.</p>
                  </div>';
            
            echo '<h5 class="mt-4"><i class="bi bi-table"></i> Estructura de la tabla:</h5>';
            echo '<div class="table-responsive">
                    <table class="table table-sm table-bordered">
                      <thead class="table-dark">
                        <tr>
                          <th>ID</th>
                          <th>Campo</th>
                          <th>Tipo</th>
                          <th>No Nulo</th>
                          <th>Por defecto</th>
                          <th>Clave Primaria</th>
                        </tr>
                      </thead>
                      <tbody>';
            
            foreach ($estructura as $campo) {
                echo '<tr>
                        <td>' . htmlspecialchars($campo['cid']) . '</td>
                        <td><code>' . htmlspecialchars($campo['name']) . '</code></td>
                        <td>' . htmlspecialchars($campo['type']) . '</td>
                        <td>' . ($campo['notnull'] ? 'SÍ' : 'NO') . '</td>
                        <td>' . htmlspecialchars($campo['dflt_value'] ?? 'NULL') . '</td>
                        <td>' . ($campo['pk'] ? 'SÍ' : 'NO') . '</td>
                      </tr>';
            }
            
            echo '    </tbody>
                    </table>
                  </div>';
            
            // Mostrar información de foreign keys (SQLite)
            try {
                $foreignKeys = $db->fetchAll("PRAGMA foreign_key_list(materias_pendientes_intensificacion)");
                if (!empty($foreignKeys)) {
                    echo '<h6 class="mt-3"><i class="bi bi-link-45deg"></i> Claves foráneas configuradas:</h6>';
                    echo '<div class="table-responsive">
                            <table class="table table-sm table-bordered">
                              <thead class="table-secondary">
                                <tr>
                                  <th>Campo</th>
                                  <th>Tabla referenciada</th>
                                  <th>Campo referenciado</th>
                                </tr>
                              </thead>
                              <tbody>';
                    
                    foreach ($foreignKeys as $fk) {
                        echo '<tr>
                                <td><code>' . htmlspecialchars($fk['from']) . '</code></td>
                                <td>' . htmlspecialchars($fk['table']) . '</td>
                                <td><code>' . htmlspecialchars($fk['to']) . '</code></td>
                              </tr>';
                    }
                    
                    echo '    </tbody>
                            </table>
                          </div>';
                }
            } catch (Exception $e) {
                // Ignorar errores de foreign keys
            }
            
            echo '<div class="alert alert-info mt-4">
                    <h6><i class="bi bi-info-circle"></i> Próximos pasos:</h6>
                    <ol>
                      <li>La tabla está lista para usar en SQLite</li>
                      <li>Puedes acceder a <strong>materias_pendientes.php</strong> para comenzar a asignar materias</li>
                      <li><span class="text-danger"><strong>¡IMPORTANTE!</strong> Elimina este archivo (<code>crear_tabla_materias_pendientes_sqlite.php</code>) por seguridad</span></li>
                    </ol>
                  </div>';
                  
            echo '<div class="alert alert-warning mt-3">
                    <h6><i class="bi bi-database"></i> Consideraciones SQLite:</h6>
                    <ul class="mb-0">
                      <li>Las foreign keys están definidas pero pueden necesitar activación con <code>PRAGMA foreign_keys = ON;</code></li>
                      <li>Los tipos de datos se han adaptado: INTEGER, TEXT, REAL, DATETIME</li>
                      <li>Los CHECK constraints reemplazan el tipo ENUM de MySQL</li>
                    </ul>
                  </div>';
                  
            echo '<div class="text-center mt-4">
                    <a href="materias_pendientes.php" class="btn btn-primary btn-lg">
                      <i class="bi bi-arrow-right-circle"></i> Ir a Materias Pendientes
                    </a>
                    <a href="index.php" class="btn btn-secondary btn-lg ms-2">
                      <i class="bi bi-house"></i> Ir al Inicio
                    </a>
                  </div>';
        } else {
            echo '<div class="alert alert-danger">
                    <i class="bi bi-x-circle"></i> ❌ Error: La tabla no se pudo verificar después de la creación.
                  </div>';
        }
        
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">
                <h5><i class="bi bi-exclamation-triangle"></i> Error al crear la tabla:</h5>
                <p><strong>Mensaje de error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
                <p><strong>Sugerencias para SQLite:</strong></p>
                <ul>
                  <li>Verifica que la conexión a la base de datos SQLite esté funcionando</li>
                  <li>Asegúrate de que el archivo de base de datos tenga permisos de escritura</li>
                  <li>Revisa que las tablas referenciadas (usuarios, materias_por_curso, ciclos_lectivos) existan</li>
                  <li>Confirma que las foreign keys estén habilitadas: <code>PRAGMA foreign_keys = ON;</code></li>
                </ul>
              </div>';
    }
    
} else {
    // Mostrar formulario inicial
?>
                        <div class="alert alert-warning">
                            <h5><i class="bi bi-exclamation-triangle"></i> ¡Atención!</h5>
                            <p>Este script creará la tabla <strong>materias_pendientes_intensificacion</strong> en tu base de datos SQLite.</p>
                            <ul>
                                <li>✅ Es seguro ejecutarlo (usa <code>CREATE TABLE IF NOT EXISTS</code>)</li>
                                <li>✅ No afectará datos existentes</li>
                                <li>✅ Si la tabla ya existe, no hará nada</li>
                                <li>⚠️ <strong>Elimina este archivo después de usarlo</strong></li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6><i class="bi bi-database"></i> Lo que se creará (SQLite):</h6>
                            <ul class="mb-0">
                                <li>Tabla <code>materias_pendientes_intensificacion</code></li>
                                <li>Campos adaptados para SQLite (INTEGER, TEXT, REAL, DATETIME)</li>
                                <li>Foreign keys integradas en la definición de tabla</li>
                                <li>Índices optimizados para consultas</li>
                                <li>Constraints CHECK para validar estados</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-info-circle"></i> Diferencias con MySQL:</h6>
                            <ul class="mb-0">
                                <li><code>year(4)</code> → <code>INTEGER</code></li>
                                <li><code>decimal(3,1)</code> → <code>REAL</code></li>
                                <li><code>enum</code> → <code>TEXT</code> con <code>CHECK</code></li>
                                <li><code>timestamp</code> → <code>DATETIME</code></li>
                                <li>Foreign keys definidas en la misma tabla</li>
                            </ul>
                        </div>
                        
                        <div class="text-center">
                            <form method="POST">
                                <button type="submit" name="crear_tabla" class="btn btn-primary btn-lg">
                                    <i class="bi bi-database-gear"></i> Crear Tabla SQLite
                                </button>
                            </form>
                        </div>
<?php 
} 
?>
                    </div>
                </div>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford<br>
                        <i class="bi bi-shield-check"></i> Basado en la Resolución N° 1650/24 - Versión SQLite
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
