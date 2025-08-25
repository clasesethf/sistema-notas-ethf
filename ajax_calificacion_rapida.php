<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'guardar_calificacion_rapida') {
        $contenido_id = $_POST['contenido_id'] ?? 0;
        $estudiante_id = $_POST['estudiante_id'] ?? 0;
        $calificacion_cualitativa = $_POST['calificacion_cualitativa'] ?? '';
        $calificacion_numerica = $_POST['calificacion_numerica'] ?? null;
        
        try {
            // Verificar si ya existe una calificación
            $existente = $db->fetchOne(
                "SELECT id FROM contenidos_calificaciones WHERE contenido_id = ? AND estudiante_id = ?",
                [$contenido_id, $estudiante_id]
            );
            
            if ($existente) {
                // Actualizar
                $db->query(
                    "UPDATE contenidos_calificaciones 
                     SET calificacion_cualitativa = ?, calificacion_numerica = ?, fecha_modificacion = NOW() 
                     WHERE contenido_id = ? AND estudiante_id = ?",
                    [$calificacion_cualitativa, $calificacion_numerica, $contenido_id, $estudiante_id]
                );
            } else {
                // Insertar
                $db->query(
                    "INSERT INTO contenidos_calificaciones 
                     (contenido_id, estudiante_id, calificacion_cualitativa, calificacion_numerica, fecha_creacion) 
                     VALUES (?, ?, ?, ?, NOW())",
                    [$contenido_id, $estudiante_id, $calificacion_cualitativa, $calificacion_numerica]
                );
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Calificación guardada correctamente'
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
}
?>
