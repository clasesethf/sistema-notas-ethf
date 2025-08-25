<?php
/**
 * ajax_verificar_contenidos_no_acreditados.php
 * Verifica si un estudiante tiene contenidos "No Acreditados" en una materia
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Configurar headers para JSON
header('Content-Type: application/json');

// Incluir archivos necesarios
require_once 'config.php';

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
    // Obtener parámetros
    $estudianteId = intval($_POST['estudiante_id'] ?? 0);
    $materiaCursoId = intval($_POST['materia_curso_id'] ?? 0);
    
    if (!$estudianteId || !$materiaCursoId) {
        throw new Exception('Parámetros faltantes');
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
    
    // Obtener todos los contenidos de la materia con sus calificaciones para este estudiante
    $contenidosConCalificaciones = $db->fetchAll(
        "SELECT c.id, c.titulo, c.descripcion, c.bimestre, c.fecha_clase, c.tipo_evaluacion,
                cc.calificacion_numerica, cc.calificacion_cualitativa,
                CASE 
                    WHEN c.tipo_evaluacion = 'numerica' THEN 
                        CASE WHEN cc.calificacion_numerica >= 7 THEN 'Acreditado'
                             WHEN cc.calificacion_numerica < 7 AND cc.calificacion_numerica IS NOT NULL THEN 'No Acreditado'
                             ELSE 'Sin Calificar'
                        END
                    WHEN c.tipo_evaluacion = 'cualitativa' THEN 
                        COALESCE(cc.calificacion_cualitativa, 'Sin Calificar')
                    ELSE 'Sin Calificar'
                END as estado_evaluacion
         FROM contenidos c
         LEFT JOIN contenidos_calificaciones cc ON c.id = cc.contenido_id AND cc.estudiante_id = ?
         WHERE c.materia_curso_id = ? AND c.activo = 1
         ORDER BY c.bimestre, c.fecha_clase, c.orden, c.id",
        [$estudianteId, $materiaCursoId]
    );
    
    // Filtrar contenidos específicamente "No Acreditados"
    $contenidosNoAcreditados = [];
    $detallesNoAcreditados = [];
    
    foreach ($contenidosConCalificaciones as $contenido) {
        if ($contenido['estado_evaluacion'] === 'No Acreditado') {
            $contenidosNoAcreditados[] = [
                'id' => $contenido['id'],
                'titulo' => $contenido['titulo'],
                'fecha' => date('d/m/Y', strtotime($contenido['fecha_clase'])),
                'bimestre' => $contenido['bimestre'],
                'tipo_evaluacion' => $contenido['tipo_evaluacion']
            ];
            
            $detallesNoAcreditados[] = [
                'id' => $contenido['id'],
                'titulo' => $contenido['titulo'],
                'descripcion' => $contenido['descripcion'],
                'fecha' => date('d/m/Y', strtotime($contenido['fecha_clase'])),
                'bimestre' => $contenido['bimestre'],
                'tipo_evaluacion' => $contenido['tipo_evaluacion'],
                'calificacion' => $contenido['tipo_evaluacion'] === 'numerica' 
                    ? $contenido['calificacion_numerica'] 
                    : $contenido['calificacion_cualitativa']
            ];
        }
    }
    
    // Estadísticas adicionales
    $totalContenidos = count($contenidosConCalificaciones);
    $contenidosAcreditados = count(array_filter($contenidosConCalificaciones, function($c) {
        return $c['estado_evaluacion'] === 'Acreditado';
    }));
    $contenidosNoCorresponde = count(array_filter($contenidosConCalificaciones, function($c) {
        return $c['estado_evaluacion'] === 'No Corresponde';
    }));
    $contenidosSinCalificar = count(array_filter($contenidosConCalificaciones, function($c) {
        return $c['estado_evaluacion'] === 'Sin Calificar';
    }));
    
    // Información adicional para debugging y contexto
    $informacionAdicional = [
        'total_contenidos' => $totalContenidos,
        'acreditados' => $contenidosAcreditados,
        'no_acreditados' => count($contenidosNoAcreditados),
        'no_corresponde' => $contenidosNoCorresponde,
        'sin_calificar' => $contenidosSinCalificar,
        'porcentaje_acreditacion' => $totalContenidos > 0 ? round(($contenidosAcreditados / $totalContenidos) * 100, 1) : 0
    ];
    
    // Respuesta
    echo json_encode([
        'success' => true,
        'tiene_no_acreditados' => count($contenidosNoAcreditados) > 0,
        'contenidos_no_acreditados' => $contenidosNoAcreditados,
        'detalles' => $detallesNoAcreditados,
        'total_contenidos' => $totalContenidos,
        'estadisticas' => $informacionAdicional,
        'mensaje' => count($contenidosNoAcreditados) > 0 
            ? "El estudiante tiene " . count($contenidosNoAcreditados) . " contenido(s) no acreditado(s)"
            : "Todos los contenidos están acreditados o no corresponden",
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Log del error para debugging
    error_log("Error en ajax_verificar_contenidos_no_acreditados.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'tiene_no_acreditados' => false,
        'contenidos_no_acreditados' => [],
        'detalles' => [],
        'total_contenidos' => 0,
        'estadisticas' => [],
        'error_details' => [
            'file' => __FILE__,
            'line' => $e->getLine(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>