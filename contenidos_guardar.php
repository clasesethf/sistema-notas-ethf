<?php
/**
 * contenidos_guardar.php - Procesamiento de operaciones CRUD para contenidos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * ACTUALIZADO: Incluye cálculo automático de calificaciones
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';
require_once 'sistema_calculo_automatico.php';
require_once 'sistema_periodos_automaticos.php';

// Verificar sesión
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para realizar esta acción';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['message'] = 'Método no permitido';
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener acción
$accion = $_POST['accion'] ?? '';
$profesorId = $_SESSION['user_id'];

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT id FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

try {
    switch ($accion) {
        case 'crear':
            // Validar datos requeridos
            if (empty($_POST['materia_curso_id']) || empty($_POST['titulo']) || 
                empty($_POST['fecha_clase']) || empty($_POST['tipo_evaluacion'])) {
                throw new Exception('Faltan datos requeridos');
            }
            
            $materiaCursoId = intval($_POST['materia_curso_id']);
            $titulo = trim($_POST['titulo']);
            $descripcion = trim($_POST['descripcion'] ?? '');
            $fechaClase = $_POST['fecha_clase'];
            $tipoEvaluacion = $_POST['tipo_evaluacion'];
            
            // Detectar bimestre automáticamente según la fecha
            $periodo = SistemaPeriodos::detectarPeriodo($fechaClase, $cicloActivo['anio'] ?? date('Y'));
            $bimestre = $periodo['bimestre'];
            
            if (!$bimestre) {
                throw new Exception('La fecha no corresponde a un período válido de clases');
            }
            
            // Verificar que el profesor tenga acceso a esta materia
            $verificacion = $db->fetchOne(
                "SELECT id FROM materias_por_curso WHERE id = ? AND profesor_id = ?",
                [$materiaCursoId, $profesorId]
            );
            
            if (!$verificacion) {
                throw new Exception('No tiene permisos para esta materia');
            }
            
            // Validar bimestre
            if ($bimestre < 1 || $bimestre > 4) {
                throw new Exception('Bimestre inválido');
            }
            
            // Validar tipo de evaluación
            if (!in_array($tipoEvaluacion, ['numerica', 'cualitativa'])) {
                throw new Exception('Tipo de evaluación inválido');
            }
            
            // Obtener el orden máximo actual
            $ordenMax = $db->fetchOne(
                "SELECT MAX(orden) as max_orden FROM contenidos WHERE materia_curso_id = ? AND bimestre = ?",
                [$materiaCursoId, $bimestre]
            )['max_orden'] ?? 0;
            
            // Insertar contenido
            $db->insert(
                "INSERT INTO contenidos (materia_curso_id, profesor_id, bimestre, titulo, descripcion, 
                                       fecha_clase, tipo_evaluacion, orden, activo) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [$materiaCursoId, $profesorId, $bimestre, $titulo, $descripcion, 
                 $fechaClase, $tipoEvaluacion, $ordenMax + 1]
            );
            
            $_SESSION['message'] = 'Contenido creado exitosamente';
            $_SESSION['message_type'] = 'success';
            header('Location: contenidos.php?materia=' . $materiaCursoId . '&bimestre=' . $bimestre);
            exit;
            
        case 'editar':
            // Validar datos requeridos
            if (empty($_POST['contenido_id']) || empty($_POST['titulo']) || 
                empty($_POST['fecha_clase']) || empty($_POST['tipo_evaluacion'])) {
                throw new Exception('Faltan datos requeridos');
            }
            
            $contenidoId = intval($_POST['contenido_id']);
            $titulo = trim($_POST['titulo']);
            $descripcion = trim($_POST['descripcion'] ?? '');
            $fechaClase = $_POST['fecha_clase'];
            $tipoEvaluacion = $_POST['tipo_evaluacion'];
            
            // Verificar que el contenido pertenece al profesor
            $contenido = $db->fetchOne(
                "SELECT c.*, mp.profesor_id 
                 FROM contenidos c
                 JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                 WHERE c.id = ? AND mp.profesor_id = ?",
                [$contenidoId, $profesorId]
            );
            
            if (!$contenido) {
                throw new Exception('No tiene permisos para editar este contenido');
            }
            
            // Actualizar contenido
            $db->query(
                "UPDATE contenidos SET titulo = ?, descripcion = ?, fecha_clase = ?, tipo_evaluacion = ?
                 WHERE id = ?",
                [$titulo, $descripcion, $fechaClase, $tipoEvaluacion, $contenidoId]
            );
            
            // Si cambió el tipo de evaluación, eliminar calificaciones existentes
            if ($contenido['tipo_evaluacion'] != $tipoEvaluacion) {
                $db->query("DELETE FROM contenidos_calificaciones WHERE contenido_id = ?", [$contenidoId]);
                $_SESSION['message'] = 'Contenido actualizado. Las calificaciones fueron eliminadas debido al cambio de tipo de evaluación.';
            } else {
                $_SESSION['message'] = 'Contenido actualizado exitosamente';
            }
            
            $_SESSION['message_type'] = 'success';
            header('Location: contenidos.php?materia=' . $contenido['materia_curso_id'] . '&bimestre=' . $contenido['bimestre']);
            exit;
            
        case 'eliminar':
            // Validar datos requeridos
            if (empty($_POST['contenido_id'])) {
                throw new Exception('Falta el ID del contenido');
            }
            
            $contenidoId = intval($_POST['contenido_id']);
            
            // Verificar que el contenido pertenece al profesor
            $contenido = $db->fetchOne(
                "SELECT c.*, mp.profesor_id 
                 FROM contenidos c
                 JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                 WHERE c.id = ? AND mp.profesor_id = ?",
                [$contenidoId, $profesorId]
            );
            
            if (!$contenido) {
                throw new Exception('No tiene permisos para eliminar este contenido');
            }
            
            // Marcar como inactivo en lugar de eliminar físicamente
            $db->query("UPDATE contenidos SET activo = 0 WHERE id = ?", [$contenidoId]);
            
            // Eliminar las calificaciones asociadas al contenido eliminado
            $db->query("DELETE FROM contenidos_calificaciones WHERE contenido_id = ?", [$contenidoId]);
            
            // Actualizar calificaciones automáticas de todos los estudiantes afectados
            if ($cicloLectivoId) {
                $calculador = new CalculadorCalificaciones();
                $estudiantesActualizados = $calculador->actualizarCalificacionesMateria($contenido['materia_curso_id'], $cicloLectivoId);
                
                // Forzar recálculo para estudiantes que ya no tienen contenidos calificados
                $db->query(
                    "UPDATE calificaciones 
                     SET valoracion_1bim = NULL, valoracion_3bim = NULL,
                         valoracion_preliminar_1c = NULL, valoracion_preliminar_2c = NULL,
                         calificacion_1c = NULL, calificacion_2c = NULL,
                         observaciones_automaticas = 'Recalculado por eliminación de contenido el ' || date('now')
                     WHERE materia_curso_id = ? AND ciclo_lectivo_id = ?
                     AND estudiante_id NOT IN (
                         SELECT DISTINCT cc.estudiante_id 
                         FROM contenidos_calificaciones cc
                         JOIN contenidos c ON cc.contenido_id = c.id
                         WHERE c.materia_curso_id = ? AND c.activo = 1
                     )",
                    [$contenido['materia_curso_id'], $cicloLectivoId, $contenido['materia_curso_id']]
                );
                
                $_SESSION['message'] = "Contenido eliminado exitosamente. Se actualizaron las calificaciones de $estudiantesActualizados estudiantes.";
            } else {
                $_SESSION['message'] = 'Contenido eliminado exitosamente.';
            }
            
            $_SESSION['message_type'] = 'success';
            header('Location: contenidos.php?materia=' . $contenido['materia_curso_id'] . '&bimestre=' . $contenido['bimestre']);
            exit;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error: ' . $e->getMessage();
    $_SESSION['message_type'] = 'danger';
    header('Location: contenidos.php');
    exit;
}
?>