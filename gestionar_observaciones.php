<?php
/**
 * gestionar_observaciones.php - Gestión de observaciones predefinidas
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos)
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Incluir el encabezado
require_once 'header.php';

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'crear_observacion':
            $tipo = $_POST['tipo'];
            $categoria = $_POST['categoria'];
            $mensaje = $_POST['mensaje'];
            
            try {
                $db->query(
                    "INSERT INTO observaciones_predefinidas (tipo, categoria, mensaje) VALUES (?, ?, ?)",
                    [$tipo, $categoria, $mensaje]
                );
                $_SESSION['message'] = 'Observación creada correctamente';
                $_SESSION['message_type'] = 'success';
            } catch (Exception $e) {
                $_SESSION['message'] = 'Error al crear la observación: ' . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
            }
            break;
            
        case 'editar_observacion':
            $id = intval($_POST['observacion_id']);
            $tipo = $_POST['tipo'];
            $categoria = $_POST['categoria'];
            $mensaje = $_POST['mensaje'];
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            try {
                $db->query(
                    "UPDATE observaciones_predefinidas SET tipo = ?, categoria = ?, mensaje = ?, activo = ? WHERE id = ?",
                    [$tipo, $categoria, $mensaje, $activo, $id]
                );
                $_SESSION['message'] = 'Observación actualizada correctamente';
                $_SESSION['message_type'] = 'success';
            } catch (Exception $e) {
                $_SESSION['message'] = 'Error al actualizar la observación: ' . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
            }
            break;
            
        case 'eliminar_observacion':
            $id = intval($_POST['observacion_id']);
            
            try {
                $db->query("DELETE FROM observaciones_predefinidas WHERE id = ?", [$id]);
                $_SESSION['message'] = 'Observación eliminada correctamente';
                $_SESSION['message_type'] = 'success';
            } catch (Exception $e) {
                $_SESSION['message'] = 'Error al eliminar la observación: ' . $e->getMessage();
                $_SESSION['message_type'] = 'danger';
            }
            break;
    }
    
    header('Location: gestionar_observaciones.php');
    exit;
}

// Obtener filtros
$tipoFiltro = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$categoriaFiltro = isset($_GET['categoria']) ? $_GET['categoria'] : '';

// Construir consulta
$whereClause = 'WHERE 1=1';
$parametros = [];

if (!empty($tipoFiltro)) {
    $whereClause .= ' AND tipo = ?';
    $parametros[] = $tipoFiltro;
}

if (!empty($categoriaFiltro)) {
    $whereClause .= ' AND categoria = ?';
    $parametros[] = $categoriaFiltro;
}

// Obtener observaciones
$observaciones = $db->fetchAll(
    "SELECT * FROM observaciones_predefinidas $whereClause ORDER BY tipo, categoria, mensaje",
    $parametros
);

// Obtener estadísticas
$estadisticas = $db->fetchAll(
    "SELECT tipo, COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activas
     FROM observaciones_predefinidas 
     GROUP BY tipo 
     ORDER BY tipo"
);

// Obtener categorías únicas
$categorias = $db->fetchAll("SELECT DISTINCT categoria FROM observaciones_predefinidas ORDER BY categoria");
?>

<div class="container-fluid mt-4">
    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Estadísticas de Observaciones</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($estadisticas as $stat): ?>
                        <div class="col-md-3 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="card-title"><?= ucfirst($stat['tipo']) ?></h5>
                                    <p class="card-text">
                                        <span class="badge bg-primary"><?= $stat['total'] ?> Total</span><br>
                                        <span class="badge bg-success"><?= $stat['activas'] ?> Activas</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros y acciones -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Gestión de Observaciones Predefinidas</h5>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearObservacion">
                        <i class="bi bi-plus-circle"></i> Nueva Observación
                    </button>
                </div>
                <div class="card-body">
                    <!-- Filtros -->
                    <form method="GET" action="gestionar_observaciones.php" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <label for="tipo" class="form-label">Tipo:</label>
                                <select name="tipo" id="tipo" class="form-select">
                                    <option value="">-- Todos --</option>
                                    <option value="positiva" <?= $tipoFiltro == 'positiva' ? 'selected' : '' ?>>Positiva</option>
                                    <option value="mejora" <?= $tipoFiltro == 'mejora' ? 'selected' : '' ?>>Mejora</option>
                                    <option value="neutra" <?= $tipoFiltro == 'neutra' ? 'selected' : '' ?>>Neutra</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="categoria" class="form-label">Categoría:</label>
                                <select name="categoria" id="categoria" class="form-select">
                                    <option value="">-- Todas --</option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['categoria'] ?>" <?= $categoriaFiltro == $cat['categoria'] ? 'selected' : '' ?>>
                                        <?= ucfirst($cat['categoria']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                                <a href="gestionar_observaciones.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Tabla de observaciones -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Tipo</th>
                                    <th>Categoría</th>
                                    <th>Mensaje</th>
                                    <th>Estado</th>
                                    <th>Fecha Creación</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($observaciones) > 0): ?>
                                    <?php foreach ($observaciones as $obs): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $obs['tipo'] == 'positiva' ? 'success' : 
                                                ($obs['tipo'] == 'mejora' ? 'warning' : 'info') 
                                            ?>">
                                                <?= ucfirst($obs['tipo']) ?>
                                            </span>
                                        </td>
                                        <td><?= ucfirst($obs['categoria']) ?></td>
                                        <td><?= htmlspecialchars($obs['mensaje']) ?></td>
                                        <td>
                                            <?php if ($obs['activo']): ?>
                                                <span class="badge bg-success">Activa</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactiva</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($obs['created_at']): ?>
                                                <?= date('d/m/Y', strtotime($obs['created_at'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        onclick="editarObservacion(<?= $obs['id'] ?>)"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditarObservacion">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="eliminarObservacion(<?= $obs['id'] ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No se encontraron observaciones</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Crear Observación -->
<div class="modal fade" id="modalCrearObservacion" tabindex="-1" aria-labelledby="modalCrearObservacionLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_observaciones.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearObservacionLabel">Crear Nueva Observación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_observacion">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="crear_tipo" class="form-label">Tipo *</label>
                            <select class="form-select" id="crear_tipo" name="tipo" required>
                                <option value="">-- Seleccione --</option>
                                <option value="positiva">Positiva</option>
                                <option value="mejora">Mejora</option>
                                <option value="neutra">Neutra</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="crear_categoria" class="form-label">Categoría *</label>
                            <input type="text" class="form-control" id="crear_categoria" name="categoria" 
                                   placeholder="ej: colaboracion, participacion, organizacion" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="crear_mensaje" class="form-label">Mensaje *</label>
                        <textarea class="form-control" id="crear_mensaje" name="mensaje" rows="3" 
                                  placeholder="Escriba el texto de la observación..." required></textarea>
                        <small class="form-text text-muted">Escriba un mensaje claro y constructivo que pueda aplicarse a diferentes estudiantes.</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Tipos de observaciones:</strong><br>
                        <ul class="mb-0">
                            <li><strong>Positiva:</strong> Destacan fortalezas y logros del estudiante</li>
                            <li><strong>Mejora:</strong> Sugieren áreas de crecimiento de manera constructiva</li>
                            <li><strong>Neutra:</strong> Comentarios generales sobre el progreso</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Observación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Observación -->
<div class="modal fade" id="modalEditarObservacion" tabindex="-1" aria-labelledby="modalEditarObservacionLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="gestionar_observaciones.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarObservacionLabel">Editar Observación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="editar_observacion">
                    <input type="hidden" name="observacion_id" id="editar_observacion_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editar_tipo" class="form-label">Tipo *</label>
                            <select class="form-select" id="editar_tipo" name="tipo" required>
                                <option value="positiva">Positiva</option>
                                <option value="mejora">Mejora</option>
                                <option value="neutra">Neutra</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editar_categoria" class="form-label">Categoría *</label>
                            <input type="text" class="form-control" id="editar_categoria" name="categoria" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editar_mensaje" class="form-label">Mensaje *</label>
                        <textarea class="form-control" id="editar_mensaje" name="mensaje" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="editar_activo" name="activo" checked>
                            <label class="form-check-label" for="editar_activo">Observación activa</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Observación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Formulario oculto para eliminar -->
<form id="formEliminarObservacion" method="POST" action="gestionar_observaciones.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_observacion">
    <input type="hidden" name="observacion_id" id="eliminar_observacion_id">
</form>

<script>
// Función para cargar datos en el modal de edición
function editarObservacion(observacionId) {
    // Hacer petición AJAX para obtener datos de la observación
    fetch('obtener_observacion.php?id=' + observacionId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editar_observacion_id').value = data.observacion.id;
                document.getElementById('editar_tipo').value = data.observacion.tipo;
                document.getElementById('editar_categoria').value = data.observacion.categoria;
                document.getElementById('editar_mensaje').value = data.observacion.mensaje;
                document.getElementById('editar_activo').checked = data.observacion.activo == 1;
            } else {
                alert('Error al cargar los datos de la observación');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Fallback: usar datos de la tabla actual
            const fila = event.target.closest('tr');
            const celdas = fila.querySelectorAll('td');
            
            document.getElementById('editar_observacion_id').value = observacionId;
            
            // Extraer tipo del badge
            const tipoBadge = celdas[0].querySelector('.badge').textContent.toLowerCase();
            document.getElementById('editar_tipo').value = tipoBadge;
            
            // Extraer categoría
            document.getElementById('editar_categoria').value = celdas[1].textContent.toLowerCase();
            
            // Extraer mensaje
            document.getElementById('editar_mensaje').value = celdas[2].textContent;
            
            // Extraer estado activo
            const estadoBadge = celdas[3].querySelector('.badge').textContent;
            document.getElementById('editar_activo').checked = (estadoBadge === 'Activa');
        });
}

// Función para eliminar observación
function eliminarObservacion(observacionId) {
    if (confirm('¿Está seguro de que desea eliminar esta observación? Esta acción no se puede deshacer.')) {
        document.getElementById('eliminar_observacion_id').value = observacionId;
        document.getElementById('formEliminarObservacion').submit();
    }
}

// Sugerencias de categorías
document.addEventListener('DOMContentLoaded', function() {
    const categoriaInput = document.getElementById('crear_categoria');
    const sugerenciasCategorias = [
        'colaboracion', 'participacion', 'responsabilidad', 'actitud', 
        'liderazgo', 'creatividad', 'autonomia', 'organizacion', 
        'estudio', 'atencion', 'entregas', 'general'
    ];
    
    if (categoriaInput) {
        categoriaInput.addEventListener('input', function() {
            const valor = this.value.toLowerCase();
            const sugerencias = sugerenciasCategorias.filter(cat => 
                cat.includes(valor) && cat !== valor
            );
            
            // Aquí podrías implementar un datalist o autocompletado
        });
    }
});
</script>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>