<?php
/**
 * usuarios.php - Gestión de usuarios del sistema
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Iniciar buffer de salida al principio
ob_start();

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Verificar permisos (solo admin y directivos) ANTES de incluir header.php
// Verificar permisos con sistema de roles múltiples
$puedeAcceder = false;

// Verificar si tiene acceso como admin
if (isset($_SESSION['rol_activo']) && $_SESSION['rol_activo'] === 'admin') {
    $puedeAcceder = true;
} 
// Verificar si tiene acceso como directivo
elseif (isset($_SESSION['rol_activo']) && $_SESSION['rol_activo'] === 'directivo') {
    $puedeAcceder = true;
}
// Verificar roles en roles_disponibles (fallback)
elseif (isset($_SESSION['roles_disponibles'])) {
    $puedeAcceder = array_intersect(['admin', 'directivo'], $_SESSION['roles_disponibles']);
}
// Verificar rol tradicional (compatibilidad hacia atrás)
elseif (in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $puedeAcceder = true;
}

if (!$puedeAcceder) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Procesar acciones ANTES de incluir header.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    switch ($accion) {
        case 'crear_usuario':
            $resultado = crearUsuario($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'editar_usuario':
            $resultado = editarUsuario($db, $_POST);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'eliminar_usuario':
            $resultado = eliminarUsuario($db, $_POST['usuario_id']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'cambiar_estado':
            $resultado = cambiarEstadoUsuario($db, $_POST['usuario_id'], $_POST['nuevo_estado']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'resetear_password':
            $resultado = resetearPassword($db, $_POST['usuario_id']);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
            
        case 'eliminar_usuarios_ejemplo':
            $resultado = eliminarUsuariosEjemplo($db);
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = $resultado['type'];
            break;
    }
    
    // Variables para filtros
    $tipoUsuario = isset($_POST['tipo_original']) ? $_POST['tipo_original'] : '';
    $busqueda = isset($_POST['busqueda_original']) ? $_POST['busqueda_original'] : '';
    $paginaActual = isset($_POST['pagina_original']) ? $_POST['pagina_original'] : 1;
    
    // Redireccionar para evitar reenvío del formulario
    $queryParams = [];
    if (!empty($tipoUsuario)) $queryParams['tipo'] = $tipoUsuario;
    if (!empty($busqueda)) $queryParams['busqueda'] = $busqueda;
    if ($paginaActual > 1) $queryParams['pagina'] = $paginaActual;
    
    $redirectUrl = 'usuarios.php';
    if (!empty($queryParams)) {
        $redirectUrl .= '?' . http_build_query($queryParams);
    }
    
    header('Location: ' . $redirectUrl);
    exit;
}

// Variables para filtros y paginación
$tipoUsuario = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$paginaActual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$registrosPorPagina = 20;
$offset = ($paginaActual - 1) * $registrosPorPagina;

// AHORA incluir el encabezado después de procesar POST
require_once 'header.php';

// Construir consulta con filtros
$whereClause = 'WHERE 1=1';
$parametros = [];

if (!empty($tipoUsuario)) {
    $whereClause .= ' AND tipo = ?';
    $parametros[] = $tipoUsuario;
}

if (!empty($busqueda)) {
    $whereClause .= ' AND (nombre LIKE ? OR apellido LIKE ? OR dni LIKE ?)';
    $parametros[] = "%$busqueda%";
    $parametros[] = "%$busqueda%";
    $parametros[] = "%$busqueda%";
}

// Obtener total de registros para paginación
$totalRegistros = $db->fetchOne(
    "SELECT COUNT(*) as total FROM usuarios $whereClause",
    $parametros
)['total'];

$totalPaginas = ceil($totalRegistros / $registrosPorPagina);

// Verificar si la columna created_at existe
$columns = $db->fetchAll("PRAGMA table_info(usuarios)");
$hasCreatedAt = false;
foreach ($columns as $column) {
    if ($column['name'] === 'created_at') {
        $hasCreatedAt = true;
        break;
    }
}

// Construir consulta según las columnas disponibles
$selectFields = "id, nombre, apellido, dni, tipo, activo";
if ($hasCreatedAt) {
    $selectFields .= ", created_at";
}

// Obtener usuarios
$usuarios = $db->fetchAll(
    "SELECT $selectFields 
     FROM usuarios 
     $whereClause 
     ORDER BY apellido, nombre 
     LIMIT ? OFFSET ?",
    array_merge($parametros, [$registrosPorPagina, $offset])
);

// Obtener estadísticas generales
$estadisticas = $db->fetchAll(
    "SELECT tipo, 
            COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivos
     FROM usuarios 
     GROUP BY tipo 
     ORDER BY tipo"
);

// Obtener ciclo lectivo activo para mostrar matriculaciones
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Obtener cursos para el formulario de estudiantes
$cursos = [];
if ($cicloLectivoId > 0) {
    $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio, nombre", [$cicloLectivoId]);
}

/**
 * Función para crear un nuevo usuario
 */
function crearUsuario($db, $datos) {
    // Verificar si la columna created_at existe
    $columns = $db->fetchAll("PRAGMA table_info(usuarios)");
    $hasCreatedAt = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'created_at') {
            $hasCreatedAt = true;
            break;
        }
    }
    
    try {
        $nombre = trim($datos['nombre']);
        $apellido = trim($datos['apellido']);
        $dni = trim($datos['dni']);
        $tipo = $datos['tipo'];
        $direccion = trim($datos['direccion'] ?? '');
        $telefono = trim($datos['telefono'] ?? '');
        $cursoId = isset($datos['curso_id']) ? intval($datos['curso_id']) : null;
        
        // Validaciones
        if (empty($nombre) || empty($apellido) || empty($dni) || empty($tipo)) {
            return ['type' => 'danger', 'message' => 'Todos los campos obligatorios deben completarse'];
        }
        
        // MODIFICACIÓN: Nueva validación para DNI alfanumérico
        if (!preg_match('/^[a-zA-Z0-9]+$/', $dni) || strlen($dni) < 4) {
            return ['type' => 'danger', 'message' => 'El DNI debe contener solo letras y números, y tener al menos 4 caracteres'];
        }
        
        if (!in_array($tipo, ['admin', 'directivo', 'profesor', 'preceptor', 'estudiante'])) {
            return ['type' => 'danger', 'message' => 'Tipo de usuario no válido'];
        }
        
        // Verificar si el DNI ya existe (case insensitive)
        $usuarioExistente = $db->fetchOne("SELECT id FROM usuarios WHERE LOWER(dni) = LOWER(?)", [$dni]);
        if ($usuarioExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe un usuario con ese DNI'];
        }
        
        // Generar contraseña por defecto
        // $passwordDefault = $tipo . '123';
        $passwordDefault = ''; // Contraseña vacia -> contraseña desactivada, solo se puede ingresar por LDAP

        $db->transaction(function($db) use ($nombre, $apellido, $dni, $tipo, $direccion, $telefono, $passwordDefault, $cursoId, $hasCreatedAt) {
            // Crear usuario
            if ($hasCreatedAt) {
                $usuarioId = $db->insert(
                    "INSERT INTO usuarios (nombre, apellido, dni, tipo, direccion, telefono, contrasena, activo, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, datetime('now'))",
                    [$nombre, $apellido, $dni, $tipo, $direccion, $telefono, $passwordDefault]
                );
            } else {
                $usuarioId = $db->insert(
                    "INSERT INTO usuarios (nombre, apellido, dni, tipo, direccion, telefono, contrasena, activo) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                    [$nombre, $apellido, $dni, $tipo, $direccion, $telefono, $passwordDefault]
                );
            }
            
            // Si es estudiante y se especificó un curso, matricularlo
            if ($tipo === 'estudiante' && $cursoId) {
                $db->query(
                    "INSERT INTO matriculas (estudiante_id, curso_id, fecha_matriculacion, estado) 
                     VALUES (?, ?, date('now'), 'activo')",
                    [$usuarioId, $cursoId]
                );
            }
        });
        
        return ['type' => 'success', 'message' => "Usuario creado correctamente. Contraseña temporal: $passwordDefault"];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al crear usuario: ' . $e->getMessage()];
    }
}

/**
 * Función para editar un usuario
 */
function editarUsuario($db, $datos) {
    try {
        $usuarioId = intval($datos['usuario_id']);
        $nombre = trim($datos['nombre']);
        $apellido = trim($datos['apellido']);
        $dni = trim($datos['dni']);
        $tipo = $datos['tipo'];
        $direccion = trim($datos['direccion'] ?? '');
        $telefono = trim($datos['telefono'] ?? '');
        
        // Validaciones
        if (empty($nombre) || empty($apellido) || empty($dni) || empty($tipo)) {
            return ['type' => 'danger', 'message' => 'Todos los campos obligatorios deben completarse'];
        }
        
        // MODIFICACIÓN: Nueva validación para DNI alfanumérico
        if (!preg_match('/^[a-zA-Z0-9]+$/', $dni) || strlen($dni) < 4) {
            return ['type' => 'danger', 'message' => 'El DNI debe contener solo letras y números, y tener al menos 4 caracteres'];
        }
        
        // Verificar si el DNI ya existe (excluyendo el usuario actual, case insensitive)
        $usuarioExistente = $db->fetchOne("SELECT id FROM usuarios WHERE LOWER(dni) = LOWER(?) AND id != ?", [$dni, $usuarioId]);
        if ($usuarioExistente) {
            return ['type' => 'danger', 'message' => 'Ya existe otro usuario con ese DNI'];
        }
        
        // Actualizar usuario
        $db->query(
            "UPDATE usuarios 
             SET nombre = ?, apellido = ?, dni = ?, tipo = ?, direccion = ?, telefono = ?
             WHERE id = ?",
            [$nombre, $apellido, $dni, $tipo, $direccion, $telefono, $usuarioId]
        );
        
        return ['type' => 'success', 'message' => 'Usuario actualizado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al actualizar usuario: ' . $e->getMessage()];
    }
}

/**
 * Función para eliminar un usuario
 */
function eliminarUsuario($db, $usuarioId) {
    try {
        $usuarioId = intval($usuarioId);
        
        // Verificar que el usuario existe
        $usuario = $db->fetchOne("SELECT * FROM usuarios WHERE id = ?", [$usuarioId]);
        if (!$usuario) {
            return ['type' => 'danger', 'message' => 'Usuario no encontrado'];
        }
        
        // No permitir eliminar al usuario actual
        if ($usuarioId == $_SESSION['user_id']) {
            return ['type' => 'danger', 'message' => 'No puede eliminar su propio usuario'];
        }
        
        $db->transaction(function($db) use ($usuarioId) {
            // Eliminar registros relacionados
            $db->query("DELETE FROM matriculas WHERE estudiante_id = ?", [$usuarioId]);
            $db->query("DELETE FROM calificaciones WHERE estudiante_id = ?", [$usuarioId]);
            $db->query("DELETE FROM asistencias WHERE estudiante_id = ?", [$usuarioId]);
            $db->query("DELETE FROM intensificaciones WHERE estudiante_id = ?", [$usuarioId]);
            $db->query("DELETE FROM notificaciones WHERE estudiante_id = ?", [$usuarioId]);
            
            // Actualizar materias_por_curso para quitar profesor asignado
            $db->query("UPDATE materias_por_curso SET profesor_id = NULL WHERE profesor_id = ?", [$usuarioId]);
            
            // Eliminar usuario
            $db->query("DELETE FROM usuarios WHERE id = ?", [$usuarioId]);
        });
        
        return ['type' => 'success', 'message' => 'Usuario eliminado correctamente'];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar usuario: ' . $e->getMessage()];
    }
}

/**
 * Función para cambiar el estado activo/inactivo de un usuario
 */
function cambiarEstadoUsuario($db, $usuarioId, $nuevoEstado) {
    try {
        $usuarioId = intval($usuarioId);
        $nuevoEstado = intval($nuevoEstado);
        
        // No permitir desactivar al usuario actual
        if ($usuarioId == $_SESSION['user_id'] && $nuevoEstado == 0) {
            return ['type' => 'danger', 'message' => 'No puede desactivar su propio usuario'];
        }
        
        $db->query("UPDATE usuarios SET activo = ? WHERE id = ?", [$nuevoEstado, $usuarioId]);
        
        $estadoTexto = $nuevoEstado ? 'activado' : 'desactivado';
        return ['type' => 'success', 'message' => "Usuario $estadoTexto correctamente"];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al cambiar estado: ' . $e->getMessage()];
    }
}

/**
 * Función para resetear la contraseña de un usuario
 */
function resetearPassword($db, $usuarioId) {
    try {
        $usuarioId = intval($usuarioId);
        
        $usuario = $db->fetchOne("SELECT tipo FROM usuarios WHERE id = ?", [$usuarioId]);
        if (!$usuario) {
            return ['type' => 'danger', 'message' => 'Usuario no encontrado'];
        }
        
        $nuevaPassword = $usuario['tipo'] . '123';
        
        $db->query("UPDATE usuarios SET contrasena = ? WHERE id = ?", [$nuevaPassword, $usuarioId]);
        
        return ['type' => 'success', 'message' => "Contraseña reseteada correctamente. Nueva contraseña: $nuevaPassword"];
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al resetear contraseña: ' . $e->getMessage()];
    }
}

/**
 * Función para eliminar usuarios de ejemplo
 */
function eliminarUsuariosEjemplo($db) {
    try {
        $usuariosEjemplo = [
            '12345678', '23456789', '34567890', '45678901', '56789012', // Estudiantes de ejemplo
            '87654321', '76543210', '65432109', '54321098', '43210987',
            '11111111', '22222222', '33333333', '44444444', '55555555'
        ];
        
        $contadorEliminados = 0;
        
        $db->transaction(function($db) use ($usuariosEjemplo, &$contadorEliminados) {
            foreach ($usuariosEjemplo as $dni) {
                $usuario = $db->fetchOne("SELECT id FROM usuarios WHERE dni = ?", [$dni]);
                
                if ($usuario) {
                    $usuarioId = $usuario['id'];
                    
                    // No eliminar si es el usuario actual
                    if ($usuarioId != $_SESSION['user_id']) {
                        // Eliminar registros relacionados
                        $db->query("DELETE FROM matriculas WHERE estudiante_id = ?", [$usuarioId]);
                        $db->query("DELETE FROM calificaciones WHERE estudiante_id = ?", [$usuarioId]);
                        $db->query("DELETE FROM asistencias WHERE estudiante_id = ?", [$usuarioId]);
                        $db->query("DELETE FROM intensificaciones WHERE estudiante_id = ?", [$usuarioId]);
                        $db->query("DELETE FROM notificaciones WHERE estudiante_id = ?", [$usuarioId]);
                        $db->query("UPDATE materias_por_curso SET profesor_id = NULL WHERE profesor_id = ?", [$usuarioId]);
                        
                        // Eliminar usuario
                        $db->query("DELETE FROM usuarios WHERE id = ?", [$usuarioId]);
                        $contadorEliminados++;
                    }
                }
            }
        });
        
        if ($contadorEliminados > 0) {
            return ['type' => 'success', 'message' => "Se eliminaron $contadorEliminados usuarios de ejemplo"];
        } else {
            return ['type' => 'info', 'message' => 'No se encontraron usuarios de ejemplo para eliminar'];
        }
        
    } catch (Exception $e) {
        return ['type' => 'danger', 'message' => 'Error al eliminar usuarios de ejemplo: ' . $e->getMessage()];
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Estadísticas -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Estadísticas de Usuarios</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($estadisticas as $stat): ?>
                        <div class="col-md-2 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <h5 class="card-title"><?= ucfirst($stat['tipo']) ?></h5>
                                    <p class="card-text">
                                        <span class="badge bg-primary"><?= $stat['total'] ?> Total</span><br>
                                        <span class="badge bg-success"><?= $stat['activos'] ?> Activos</span><br>
                                        <span class="badge bg-secondary"><?= $stat['inactivos'] ?> Inactivos</span>
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

    // En usuarios.php, agregar después de las estadísticas:
<?php if (tieneMultiplesRoles()): ?>
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-person-badge"></i>
            <strong>Modo Multi-Rol Activo:</strong> 
            Está viendo esta página como <strong><?= ucfirst(obtenerRolActivo()) ?></strong>.
            <?php if (obtenerRolActivo() !== $_SESSION['rol_principal']): ?>
                Su rol principal es <strong><?= ucfirst($_SESSION['rol_principal']) ?></strong>.
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

    <!-- Filtros y acciones -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Gestión de Usuarios</h5>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
                            <i class="bi bi-plus-circle"></i> Nuevo Usuario
                        </button>
                        <button type="button" class="btn btn-warning d-none" data-bs-toggle="modal" data-bs-target="#modalEliminarEjemplo">
                            <i class="bi bi-trash"></i> Eliminar Usuarios de Ejemplo
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filtros -->
                    <form method="GET" action="usuarios.php" class="mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <label for="tipo" class="form-label">Tipo de Usuario:</label>
                                <select name="tipo" id="tipo" class="form-select">
                                    <option value="">-- Todos --</option>
                                    <option value="admin" <?= $tipoUsuario == 'admin' ? 'selected' : '' ?>>Administrador</option>
                                    <option value="directivo" <?= $tipoUsuario == 'directivo' ? 'selected' : '' ?>>Directivo</option>
                                    <option value="profesor" <?= $tipoUsuario == 'profesor' ? 'selected' : '' ?>>Profesor</option>
                                    <option value="preceptor" <?= $tipoUsuario == 'preceptor' ? 'selected' : '' ?>>Preceptor</option>
                                    <option value="estudiante" <?= $tipoUsuario == 'estudiante' ? 'selected' : '' ?>>Estudiante</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="busqueda" class="form-label">Buscar:</label>
                                <input type="text" name="busqueda" id="busqueda" class="form-control" 
                                       placeholder="Nombre, apellido o Usuario" value="<?= htmlspecialchars($busqueda) ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Buscar
                                </button>
                                <a href="usuarios.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </form>

                    <!-- Tabla de usuarios -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Usuario</th>
                                    <th>Apellido y Nombre</th>
                                    <th>Tipo</th>
                                    <th>Estado</th>
                                    <th>Fecha Creación</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($usuarios) > 0): ?>
                                    <?php foreach ($usuarios as $usuario): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($usuario['dni']) ?></td>
                                        <td><?= htmlspecialchars($usuario['apellido']) ?>, <?= htmlspecialchars($usuario['nombre']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= 
                                                $usuario['tipo'] == 'admin' ? 'danger' : 
                                                ($usuario['tipo'] == 'directivo' ? 'warning' : 
                                                ($usuario['tipo'] == 'profesor' ? 'info' : 
                                                ($usuario['tipo'] == 'preceptor' ? 'success' : 'secondary'))) 
                                            ?>">
                                                <?= ucfirst($usuario['tipo']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($usuario['activo']): ?>
                                                <span class="badge bg-success">Activo</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($usuario['created_at']) && $usuario['created_at']): ?>
                                                <?= date('d/m/Y', strtotime($usuario['created_at'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        onclick="editarUsuario(<?= $usuario['id'] ?>)"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditarUsuario">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                
                                                <?php if ($usuario['activo']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-warning" 
                                                            onclick="cambiarEstado(<?= $usuario['id'] ?>, 0)">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                            onclick="cambiarEstado(<?= $usuario['id'] ?>, 1)">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                        onclick="resetearPassword(<?= $usuario['id'] ?>)">
                                                    <i class="bi bi-key"></i>
                                                </button>
                                                
                                                <?php if ($usuario['id'] != $_SESSION['user_id']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="eliminarUsuario(<?= $usuario['id'] ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No se encontraron usuarios</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginación de usuarios">
                        <ul class="pagination justify-content-center">
                            <?php if ($paginaActual > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual - 1])) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++): ?>
                                <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($paginaActual < $totalPaginas): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual + 1])) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    
                    <div class="text-center">
                        <small class="text-muted">
                            Mostrando <?= $offset + 1 ?> - <?= min($offset + $registrosPorPagina, $totalRegistros) ?> 
                            de <?= $totalRegistros ?> usuarios
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Crear Usuario -->
<div class="modal fade" id="modalCrearUsuario" tabindex="-1" aria-labelledby="modalCrearUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="usuarios.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearUsuarioLabel">Crear Nuevo Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="crear_usuario">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="crear_nombre" class="form-label">Nombre *</label>
                            <input type="text" class="form-control" id="crear_nombre" name="nombre" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="crear_apellido" class="form-label">Apellido *</label>
                            <input type="text" class="form-control" id="crear_apellido" name="apellido" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="crear_dni" class="form-label">DNI/Usuario *</label>
                            <input type="text" class="form-control" id="crear_dni" name="dni" required 
                                placeholder="Ej: 12345678 o ffernandez" 
                                pattern="[a-zA-Z0-9]+" 
                                title="Solo se permiten letras y números, sin espacios ni caracteres especiales">
                            <div class="form-text">Puede usar números (DNI tradicional) o letras y números (usuario alfanumérico)</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="crear_tipo" class="form-label">Tipo de Usuario *</label>
                            <select class="form-select" id="crear_tipo" name="tipo" required onchange="toggleCursoField('crear')">
                                <option value="">-- Seleccione --</option>
                                <option value="admin">Administrador</option>
                                <option value="directivo">Directivo</option>
                                <option value="profesor">Profesor</option>
                                <option value="preceptor">Preceptor</option>
                                <option value="estudiante">Estudiante</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="crear_direccion" class="form-label">Dirección</label>
                            <input type="text" class="form-control" id="crear_direccion" name="direccion">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="crear_telefono" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="crear_telefono" name="telefono">
                        </div>
                    </div>
                    
                    <div class="row" id="crear_curso_field" style="display: none;">
                        <div class="col-md-12 mb-3">
                            <label for="crear_curso_id" class="form-label">Curso (para estudiantes)</label>
                            <select class="form-select" id="crear_curso_id" name="curso_id">
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>"><?= htmlspecialchars($curso['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <small>
                            <strong>Nota:</strong> El usuario creado trandrá que acceder utilizando usuario y contraseña en red (igual que la de windows).
                            Se puede establecer una contraseña alternativa.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Usuario -->
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-labelledby="modalEditarUsuarioLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="usuarios.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarUsuarioLabel">Editar Usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="editar_usuario">
                    <input type="hidden" name="usuario_id" id="editar_usuario_id">
                    <!-- Campos ocultos para preservar filtros -->
                    <input type="hidden" name="tipo_original" value="<?= htmlspecialchars($tipoUsuario) ?>">
                    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
                    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editar_nombre" class="form-label">Nombre *</label>
                            <input type="text" class="form-control" id="editar_nombre" name="nombre" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editar_apellido" class="form-label">Apellido *</label>
                            <input type="text" class="form-control" id="editar_apellido" name="apellido" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editar_dni" class="form-label">DNI/Usuario *</label>
                            <input type="text" class="form-control" id="editar_dni" name="dni" required 
                                placeholder="Ej: 12345678 o cbarrionuevo"
                                pattern="[a-zA-Z0-9]+" 
                                title="Solo se permiten letras y números, sin espacios ni caracteres especiales">
                            <div class="form-text">Puede usar números (DNI tradicional) o letras y números (usuario alfanumérico)</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editar_tipo" class="form-label">Tipo de Usuario *</label>
                            <select class="form-select" id="editar_tipo" name="tipo" required>
                                <option value="admin">Administrador</option>
                                <option value="directivo">Directivo</option>
                                <option value="profesor">Profesor</option>
                                <option value="preceptor">Preceptor</option>
                                <option value="estudiante">Estudiante</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editar_direccion" class="form-label">Dirección</label>
                            <input type="text" class="form-control" id="editar_direccion" name="direccion">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editar_telefono" class="form-label">Teléfono</label>
                            <input type="text" class="form-control" id="editar_telefono" name="telefono">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Eliminar Usuarios de Ejemplo -->
<div class="modal fade" id="modalEliminarEjemplo" tabindex="-1" aria-labelledby="modalEliminarEjemploLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="usuarios.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEliminarEjemploLabel">Eliminar Usuarios de Ejemplo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="eliminar_usuarios_ejemplo">
                    <!-- Campos ocultos para preservar filtros -->
                    <input type="hidden" name="tipo_original" value="<?= htmlspecialchars($tipoUsuario) ?>">
                    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
                    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>¡Atención!</strong> Esta acción eliminará todos los usuarios de ejemplo del sistema, 
                        incluyendo sus calificaciones, asistencias y matriculaciones.
                    </div>
                    
                    <p>Los usuarios con los siguientes DNI serán eliminados:</p>
                    <ul class="small">
                        <li>12345678, 23456789, 34567890, 45678901, 56789012 (Estudiantes)</li>
                        <li>87654321, 76543210, 65432109, 54321098, 43210987 (Estudiantes)</li>
                        <li>11111111, 22222222, 33333333, 44444444, 55555555 (Otros tipos)</li>
                    </ul>
                    
                    <div class="alert alert-info">
                        <small>
                            <strong>Nota:</strong> Su usuario actual no será eliminado, 
                            aunque tenga uno de estos DNI.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Eliminar Usuarios de Ejemplo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Formularios ocultos para acciones -->
<form id="formCambiarEstado" method="POST" action="usuarios.php" style="display: none;">
    <input type="hidden" name="accion" value="cambiar_estado">
    <input type="hidden" name="usuario_id" id="cambiar_estado_usuario_id">
    <input type="hidden" name="nuevo_estado" id="cambiar_estado_nuevo_estado">
    <!-- Campos ocultos para preservar filtros -->
    <input type="hidden" name="tipo_original" value="<?= htmlspecialchars($tipoUsuario) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
</form>

<form id="formResetearPassword" method="POST" action="usuarios.php" style="display: none;">
    <input type="hidden" name="accion" value="resetear_password">
    <input type="hidden" name="usuario_id" id="resetear_password_usuario_id">
    <!-- Campos ocultos para preservar filtros -->
    <input type="hidden" name="tipo_original" value="<?= htmlspecialchars($tipoUsuario) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
</form>

<form id="formEliminarUsuario" method="POST" action="usuarios.php" style="display: none;">
    <input type="hidden" name="accion" value="eliminar_usuario">
    <input type="hidden" name="usuario_id" id="eliminar_usuario_id">
    <!-- Campos ocultos para preservar filtros -->
    <input type="hidden" name="tipo_original" value="<?= htmlspecialchars($tipoUsuario) ?>">
    <input type="hidden" name="busqueda_original" value="<?= htmlspecialchars($busqueda) ?>">
    <input type="hidden" name="pagina_original" value="<?= htmlspecialchars($paginaActual) ?>">
</form>

<script>
// Función para mostrar/ocultar campo de curso según el tipo de usuario
function toggleCursoField(tipo) {
    const tipoSelect = document.getElementById(tipo + '_tipo');
    const cursoField = document.getElementById(tipo + '_curso_field');
    
    if (tipoSelect.value === 'estudiante') {
        cursoField.style.display = 'block';
    } else {
        cursoField.style.display = 'none';
    }
}

// Función para cargar datos en el modal de edición
function editarUsuario(usuarioId) {
    // Hacer petición AJAX para obtener datos del usuario
    fetch('obtener_usuario.php?id=' + usuarioId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editar_usuario_id').value = data.usuario.id;
                document.getElementById('editar_nombre').value = data.usuario.nombre;
                document.getElementById('editar_apellido').value = data.usuario.apellido;
                document.getElementById('editar_dni').value = data.usuario.dni;
                document.getElementById('editar_tipo').value = data.usuario.tipo;
                document.getElementById('editar_direccion').value = data.usuario.direccion || '';
                document.getElementById('editar_telefono').value = data.usuario.telefono || '';
            } else {
                alert('Error al cargar los datos del usuario');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar los datos del usuario');
        });
}

/**
 * Función para registrar acciones con información de rol
 */
function logAccionConRol($accion, $detalles = '', $db = null) {
    if (!$db) {
        try {
            $db = Database::getInstance();
        } catch (Exception $e) {
            return false;
        }
    }
    
    $rolActivo = $_SESSION['rol_activo'] ?? $_SESSION['user_type'];
    $rolPrincipal = $_SESSION['rol_principal'] ?? $_SESSION['user_type'];
    
    $mensaje = "Usuario {$_SESSION['user_name']} (ID: {$_SESSION['user_id']}) ";
    $mensaje .= "realizó: $accion ";
    
    if ($rolActivo !== $rolPrincipal) {
        $mensaje .= "[Rol activo: $rolActivo, Rol principal: $rolPrincipal] ";
    } else {
        $mensaje .= "[Rol: $rolActivo] ";
    }
    
    if (!empty($detalles)) {
        $mensaje .= "- Detalles: $detalles";
    }
    
    error_log($mensaje);
    
    // También se puede guardar en base de datos si tienes tabla de logs
    return true;
}

// Función para cambiar estado del usuario
function cambiarEstado(usuarioId, nuevoEstado) {
    const accion = nuevoEstado ? 'activar' : 'desactivar';
    
    if (confirm(`¿Está seguro de que desea ${accion} este usuario?`)) {
        document.getElementById('cambiar_estado_usuario_id').value = usuarioId;
        document.getElementById('cambiar_estado_nuevo_estado').value = nuevoEstado;
        document.getElementById('formCambiarEstado').submit();
    }
}

// Función para resetear contraseña
function resetearPassword(usuarioId) {
    if (confirm('¿Está seguro de que desea resetear la contraseña de este usuario?')) {
        document.getElementById('resetear_password_usuario_id').value = usuarioId;
        document.getElementById('formResetearPassword').submit();
    }
}

// Función para eliminar usuario
function eliminarUsuario(usuarioId) {
    if (confirm('¿Está seguro de que desea eliminar este usuario? Esta acción no se puede deshacer.')) {
        document.getElementById('eliminar_usuario_id').value = usuarioId;
        document.getElementById('formEliminarUsuario').submit();
    }
}

// Inicializar eventos al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Configurar eventos para mostrar/ocultar campo de curso
    document.getElementById('crear_tipo').addEventListener('change', function() {
        toggleCursoField('crear');
    });
});

// Agregar validación en tiempo real para el DNI
document.addEventListener('DOMContentLoaded', function() {
    // Función para validar DNI alfanumérico
    function validarDNI(input) {
        const valor = input.value;
        const regex = /^[a-zA-Z0-9]+$/;
        
        if (valor.length > 0 && (!regex.test(valor) || valor.length < 4)) {
            input.setCustomValidity('El DNI debe contener solo letras y números, y tener al menos 4 caracteres');
        } else {
            input.setCustomValidity('');
        }
    }
    
    // Aplicar validación a los campos de DNI
    const dniInputs = document.querySelectorAll('#crear_dni, #editar_dni');
    dniInputs.forEach(input => {
        input.addEventListener('input', function() {
            validarDNI(this);
        });
        
        input.addEventListener('blur', function() {
            validarDNI(this);
        });
    });
    
    // Configurar eventos para mostrar/ocultar campo de curso
    document.getElementById('crear_tipo').addEventListener('change', function() {
        toggleCursoField('crear');
    });
});
</script>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>
