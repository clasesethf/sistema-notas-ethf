<?php
/**
 * ajax_crear_contenido.php - Crear contenido via AJAX desde modal
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * ACTUALIZADO: Incluye señal para actualización automática de vista
 */

// Configurar headers para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Incluir archivos necesarios
require_once 'config.php';

// Incluir sistema de períodos si existe
if (file_exists('sistema_periodos_automaticos.php')) {
    require_once 'sistema_periodos_automaticos.php';
}

// Verificar sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'profesor') {
    echo json_encode(['success' => false, 'message' => 'No tiene permisos']);
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$profesorId = $_SESSION['user_id'];
$db = Database::getInstance();

try {
    // Validar datos requeridos
    $materiaCursoId = intval($_POST['materia_curso_id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fechaClase = $_POST['fecha_clase'] ?? '';
    $tipoEvaluacion = $_POST['tipo_evaluacion'] ?? '';
    
    if (empty($materiaCursoId) || empty($titulo) || empty($fechaClase) || empty($tipoEvaluacion)) {
        throw new Exception('Faltan datos requeridos');
    }
    
    // Verificar que el profesor tenga acceso a esta materia (incluye equipos docentes)
    $verificacion = $db->fetchOne(
        "SELECT id FROM materias_por_curso 
         WHERE id = ? AND (profesor_id = ? OR profesor_id_2 = ? OR profesor_id_3 = ?)",
        [$materiaCursoId, $profesorId, $profesorId, $profesorId]
    );
    
    if (!$verificacion) {
        throw new Exception('No tiene permisos para esta materia');
    }
    
    // Obtener ciclo lectivo activo
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    $anioActivo = $cicloActivo ? $cicloActivo['anio'] : date('Y');
    
    // Detectar período automáticamente
    $bimestre = 1; // Valor por defecto
    $cuatrimestre = 1; // Valor por defecto
    
    if (class_exists('SistemaPeriodos')) {
        try {
            $periodoDetectado = SistemaPeriodos::detectarPeriodo($fechaClase, $anioActivo);
            $bimestre = $periodoDetectado['bimestre'];
            
            // Si es período de intensificación, usar el último bimestre del cuatrimestre
            if ($periodoDetectado['es_intensificacion']) {
                $bimestre = ($periodoDetectado['cuatrimestre'] == 1) ? 2 : 4;
            }
            
            if (!$bimestre) {
                throw new Exception('La fecha no corresponde a un período válido de clases');
            }
        } catch (Exception $e) {
            // Si falla la detección automática, usar valores por defecto
            error_log("Error en detección de período: " . $e->getMessage());
            $bimestre = 1;
        }
    } else {
        // Detección manual básica si no existe la clase
        $mes = date('n', strtotime($fechaClase));
        if ($mes >= 3 && $mes <= 5) {
            $bimestre = 1;
        } elseif ($mes >= 6 && $mes <= 7) {
            $bimestre = 2;
        } elseif ($mes >= 8 && $mes <= 10) {
            $bimestre = 3;
        } else {
            $bimestre = 4;
        }
    }
    
    // Determinar cuatrimestre basado en bimestre
    $cuatrimestre = ($bimestre <= 2) ? 1 : 2;
    
    // Validar tipo de evaluación
    if (!in_array($tipoEvaluacion, ['numerica', 'cualitativa'])) {
        throw new Exception('Tipo de evaluación inválido');
    }
    
    // Obtener orden máximo actual
    $ordenMax = $db->fetchOne(
        "SELECT MAX(orden) as max_orden FROM contenidos 
         WHERE materia_curso_id = ? AND bimestre = ? AND activo = 1",
        [$materiaCursoId, $bimestre]
    )['max_orden'] ?? 0;
    
    // Insertar contenido
    $contenidoId = $db->insert(
        "INSERT INTO contenidos (materia_curso_id, profesor_id, titulo, descripcion, bimestre, 
                               fecha_clase, tipo_evaluacion, orden, activo) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
        [$materiaCursoId, $profesorId, $titulo, $descripcion, $bimestre, 
         $fechaClase, $tipoEvaluacion, $ordenMax + 1]
    );
    
    // Registrar actividad de equipo docente si aplica
    if (file_exists('funciones_equipos.php')) {
        require_once 'funciones_equipos.php';
        
        if (function_exists('registrarActividadEquipo')) {
            // Verificar si es equipo docente
            $equipoInfo = $db->fetchOne(
                "SELECT profesor_id, profesor_id_2, profesor_id_3 FROM materias_por_curso WHERE id = ?",
                [$materiaCursoId]
            );
            
            if ($equipoInfo) {
                $totalProfesores = ($equipoInfo['profesor_id'] ? 1 : 0) + 
                                 ($equipoInfo['profesor_id_2'] ? 1 : 0) + 
                                 ($equipoInfo['profesor_id_3'] ? 1 : 0);
                
                if ($totalProfesores > 1) {
                    registrarActividadEquipo($db, $materiaCursoId, $profesorId, 'crear_contenido_ajax', "Creó contenido vía modal: $titulo");
                }
            }
        }
    }
    
    // Obtener información del contenido creado
    $contenidoCreado = $db->fetchOne(
        "SELECT c.*, m.nombre as materia_nombre, cur.nombre as curso_nombre
         FROM contenidos c
         JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
         JOIN materias m ON mp.materia_id = m.id
         JOIN cursos cur ON mp.curso_id = cur.id
         WHERE c.id = ?",
        [$contenidoId]
    );
    
    // Respuesta exitosa con información para actualización automática
    echo json_encode([
        'success' => true,
        'message' => 'Contenido creado exitosamente',
        'contenido_id' => $contenidoId,
        'contenido' => $contenidoCreado,
        'bimestre_detectado' => $bimestre,
        'cuatrimestre' => $cuatrimestre,
        'materia_curso_id' => $materiaCursoId,
        // Señal para indicar que se debe actualizar la vista automáticamente
        'actualizar_vista' => true,
        'actualizar_tipo' => 'crear_contenido',
        'periodo_info' => [
            'bimestre' => $bimestre,
            'cuatrimestre' => $cuatrimestre,
            'fecha_clase' => $fechaClase
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error en ajax_crear_contenido.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>