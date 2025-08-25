<?php
/**
 * contenidos_duplicar.php - Funcionalidad para duplicar contenidos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * NUEVO: Permite duplicar contenidos en la misma materia o en otra materia del profesor
 */

require_once 'config.php';
require_once 'sistema_periodos_automaticos.php';

// Verificar que el usuario sea profesor
if ($_SESSION['user_type'] !== 'profesor') {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
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

$profesorId = $_SESSION['user_id'];
$db = Database::getInstance();

// Procesar duplicación
if (isset($_POST['accion']) && $_POST['accion'] === 'duplicar_contenido') {
    try {
        $contenidoOriginalId = intval($_POST['contenido_original_id']);
        $materiaCursoDestino = intval($_POST['materia_curso_destino']);
        $nuevaFechaClase = $_POST['nueva_fecha_clase'];
        $nuevoTitulo = trim($_POST['nuevo_titulo']);
        
        // Validaciones
        if (empty($contenidoOriginalId) || empty($materiaCursoDestino) || 
            empty($nuevaFechaClase) || empty($nuevoTitulo)) {
            throw new Exception('Faltan datos requeridos para la duplicación');
        }
        
        // Verificar que el contenido original pertenece al profesor
        $contenidoOriginal = $db->fetchOne(
            "SELECT c.*, mp.profesor_id 
             FROM contenidos c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             WHERE c.id = ? AND mp.profesor_id = ? AND c.activo = 1",
            [$contenidoOriginalId, $profesorId]
        );
        
        if (!$contenidoOriginal) {
            throw new Exception('No tiene permisos para duplicar este contenido');
        }
        
        // Verificar que la materia destino pertenece al profesor
        $materiaDestino = $db->fetchOne(
            "SELECT id FROM materias_por_curso WHERE id = ? AND profesor_id = ?",
            [$materiaCursoDestino, $profesorId]
        );
        
        if (!$materiaDestino) {
            throw new Exception('No tiene permisos para la materia de destino');
        }
        
        // Obtener ciclo lectivo activo para detectar bimestre
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        $anioActivo = $cicloActivo ? $cicloActivo['anio'] : date('Y');
        
        // Detectar bimestre automáticamente usando la nueva fecha
        $periodoDetectado = SistemaPeriodos::detectarPeriodo($nuevaFechaClase, $anioActivo);
        $nuevoBimestre = $periodoDetectado['bimestre'];
        
        // Si es período de intensificación, usar el último bimestre del cuatrimestre
        if ($periodoDetectado['es_intensificacion']) {
            $nuevoBimestre = ($periodoDetectado['cuatrimestre'] == 1) ? 2 : 4;
        }
        
        if (!$nuevoBimestre) {
            throw new Exception('La fecha seleccionada no corresponde a un período válido de clases');
        }
        
        // Obtener el orden máximo actual para el nuevo bimestre en la materia destino
        $ordenMax = $db->fetchOne(
            "SELECT MAX(orden) as max_orden FROM contenidos 
             WHERE materia_curso_id = ? AND bimestre = ? AND activo = 1",
            [$materiaCursoDestino, $nuevoBimestre]
        )['max_orden'] ?? 0;
        
        // Crear el contenido duplicado
        $db->insert(
            "INSERT INTO contenidos (materia_curso_id, profesor_id, bimestre, titulo, descripcion, 
                                   fecha_clase, tipo_evaluacion, orden, activo) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [
                $materiaCursoDestino,
                $profesorId,
                $nuevoBimestre,
                $nuevoTitulo,
                $contenidoOriginal['descripcion'] ?? '',
                $nuevaFechaClase,
                $contenidoOriginal['tipo_evaluacion'],
                $ordenMax + 1
            ]
        );
        
        // Obtener información de las materias para el mensaje
        $infoMateriaOriginal = $db->fetchOne(
            "SELECT m.nombre as materia_nombre, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$contenidoOriginal['materia_curso_id']]
        );
        
        $infoMateriaDestino = $db->fetchOne(
            "SELECT m.nombre as materia_nombre, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE mp.id = ?",
            [$materiaCursoDestino]
        );
        
        // Mensaje de éxito
        if ($contenidoOriginal['materia_curso_id'] == $materiaCursoDestino) {
            $_SESSION['message'] = "Contenido duplicado exitosamente en la misma materia para el {$nuevoBimestre}° bimestre";
        } else {
            $_SESSION['message'] = "Contenido duplicado exitosamente desde '{$infoMateriaOriginal['materia_nombre']}' hacia '{$infoMateriaDestino['materia_nombre']}'";
        }
        $_SESSION['message_type'] = 'success';
        
        // Redireccionar a la materia destino
        header('Location: contenidos.php?materia=' . $materiaCursoDestino);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al duplicar contenido: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
        
        // Redireccionar de vuelta
        $redirigir = isset($_POST['origen_materia']) ? $_POST['origen_materia'] : '';
        header('Location: contenidos.php' . ($redirigir ? '?materia=' . $redirigir : ''));
        exit;
    }
}

// Si llegamos aquí sin POST, redireccionar
$_SESSION['message'] = 'Solicitud inválida';
$_SESSION['message_type'] = 'danger';
header('Location: contenidos.php');
exit;
?>