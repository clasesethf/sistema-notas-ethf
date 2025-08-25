<?php
/**
 * setup_valoraciones.php - Script para configurar el sistema de valoraciones
 * Ejecutar una sola vez para configurar las nuevas funcionalidades
 */

require_once 'config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Configuración del Sistema de Valoraciones</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "</head><body class='container mt-4'>";

echo "<h2>Configuración del Sistema de Valoraciones</h2>";

$db = Database::getInstance();
$errores = [];
$exitos = [];

try {
    echo "<div class='alert alert-info'>Iniciando configuración...</div>";
    
    // 1. Verificar y crear columnas en tabla calificaciones
    echo "<h4>1. Actualizando tabla de calificaciones...</h4>";
    
    $columnas = $db->fetchAll("PRAGMA table_info(calificaciones)");
    $columnasExistentes = array_column($columnas, 'name');
    
    $nuevasColumnas = [
        'valoracion_1bim' => 'TEXT',
        'desempeno_1bim' => 'TEXT', 
        'observaciones_1bim' => 'TEXT',
        'valoracion_3bim' => 'TEXT',
        'desempeno_3bim' => 'TEXT',
        'observaciones_3bim' => 'TEXT'
    ];
    
    foreach ($nuevasColumnas as $columna => $tipo) {
        if (!in_array($columna, $columnasExistentes)) {
            try {
                $db->query("ALTER TABLE calificaciones ADD COLUMN $columna $tipo");
                $exitos[] = "Columna '$columna' agregada correctamente";
            } catch (Exception $e) {
                $errores[] = "Error al agregar columna '$columna': " . $e->getMessage();
            }
        } else {
            $exitos[] = "Columna '$columna' ya existe";
        }
    }
    
    // 2. Crear tabla de observaciones predefinidas
    echo "<h4>2. Creando tabla de observaciones predefinidas...</h4>";
    
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS observaciones_predefinidas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tipo TEXT NOT NULL,
                categoria TEXT NOT NULL,
                mensaje TEXT NOT NULL,
                activo INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $exitos[] = "Tabla 'observaciones_predefinidas' creada correctamente";
        
        // Verificar si ya tiene datos
        $count = $db->fetchOne("SELECT COUNT(*) as count FROM observaciones_predefinidas")['count'];
        
        if ($count == 0) {
            echo "<h5>Insertando observaciones predefinidas...</h5>";
            
            $observaciones = [
                // Observaciones positivas
                ['positiva', 'colaboracion', 'Trabaja de manera colaborativa y constructiva en grupo.'],
                ['positiva', 'participacion', 'Demuestra gran interés y participación en clase.'],
                ['positiva', 'responsabilidad', 'Cumple con todas las tareas y trabajos asignados.'],
                ['positiva', 'actitud', 'Mantiene una actitud positiva hacia el aprendizaje.'],
                ['positiva', 'liderazgo', 'Muestra habilidades de liderazgo en actividades grupales.'],
                ['positiva', 'creatividad', 'Aporta soluciones creativas e innovadoras.'],
                ['positiva', 'autonomia', 'Trabaja de manera autónoma y organizada.'],
                ['positiva', 'esfuerzo', 'Demuestra constante esfuerzo y dedicación.'],
                ['positiva', 'progreso', 'Muestra un progreso constante y sostenido.'],
                ['positiva', 'ayuda', 'Colabora activamente ayudando a sus compañeros.'],
                
                // Observaciones de mejora
                ['mejora', 'organizacion', 'Podría beneficiarse de una mayor organización de sus materiales.'],
                ['mejora', 'participacion', 'Se sugiere mayor participación en las actividades de clase.'],
                ['mejora', 'estudio', 'Necesita dedicar más tiempo al estudio de los contenidos.'],
                ['mejora', 'atencion', 'Debe mejorar la atención y concentración en clase.'],
                ['mejora', 'entregas', 'Es importante mejorar la puntualidad en la entrega de trabajos.'],
                ['mejora', 'colaboracion', 'Debe trabajar en mejorar su participación en trabajos grupales.'],
                ['mejora', 'actitud', 'Se sugiere una actitud más proactiva hacia las actividades.'],
                ['mejora', 'asistencia', 'Debe mejorar la regularidad en la asistencia a clases.'],
                ['mejora', 'consultas', 'Se recomienda realizar más consultas ante las dudas.'],
                ['mejora', 'compromiso', 'Necesita demostrar mayor compromiso con su aprendizaje.'],
                
                // Observaciones neutras/generales
                ['neutra', 'general', 'Continúa con el esfuerzo demostrado.'],
                ['neutra', 'general', 'En proceso de alcanzar los objetivos propuestos.'],
                ['neutra', 'general', 'Demuestra avances progresivos en la materia.'],
                ['neutra', 'general', 'Mantiene un desempeño estable en la materia.'],
                ['neutra', 'general', 'Desarrolla las actividades propuestas adecuadamente.']
            ];
            
            foreach ($observaciones as $obs) {
                $db->query(
                    "INSERT INTO observaciones_predefinidas (tipo, categoria, mensaje) VALUES (?, ?, ?)",
                    $obs
                );
            }
            
            $exitos[] = count($observaciones) . " observaciones predefinidas insertadas";
        } else {
            $exitos[] = "La tabla ya contiene " . $count . " observaciones predefinidas";
        }
        
    } catch (Exception $e) {
        $errores[] = "Error al crear tabla de observaciones: " . $e->getMessage();
    }
    
    // 3. Crear tabla de configuración de períodos
    echo "<h4>3. Creando tabla de configuración de períodos...</h4>";
    
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS configuracion_periodos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ciclo_lectivo_id INTEGER NOT NULL,
                periodo_actual TEXT NOT NULL,
                nombre_periodo TEXT NOT NULL,
                fecha_inicio DATE,
                fecha_fin DATE,
                activo INTEGER DEFAULT 0,
                FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
            )
        ");
        $exitos[] = "Tabla 'configuracion_periodos' creada correctamente";
        
        // Insertar configuración inicial si no existe
        $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
        if ($cicloActivo) {
            $countPeriodos = $db->fetchOne(
                "SELECT COUNT(*) as count FROM configuracion_periodos WHERE ciclo_lectivo_id = ?",
                [$cicloActivo['id']]
            )['count'];
            
            if ($countPeriodos == 0) {
                $periodos = [
                    ['1bim', '1er Bimestre', 0],
                    ['1cuat', '1er Cuatrimestre', 1], // Activo por defecto
                    ['3bim', '3er Bimestre', 0],
                    ['2cuat', '2do Cuatrimestre', 0]
                ];
                
                foreach ($periodos as $periodo) {
                    $db->query(
                        "INSERT INTO configuracion_periodos (ciclo_lectivo_id, periodo_actual, nombre_periodo, activo) VALUES (?, ?, ?, ?)",
                        [$cicloActivo['id'], $periodo[0], $periodo[1], $periodo[2]]
                    );
                }
                
                $exitos[] = "Configuración inicial de períodos insertada";
            } else {
                $exitos[] = "Ya existe configuración de períodos para el ciclo activo";
            }
        }
        
    } catch (Exception $e) {
        $errores[] = "Error al crear tabla de períodos: " . $e->getMessage();
    }
    
    // 4. Crear directorios necesarios
    echo "<h4>4. Creando directorios necesarios...</h4>";
    
    $directorios = ['includes', 'pdfs'];
    
    foreach ($directorios as $dir) {
        if (!file_exists($dir)) {
            if (mkdir($dir, 0755, true)) {
                $exitos[] = "Directorio '$dir' creado correctamente";
            } else {
                $errores[] = "Error al crear directorio '$dir'";
            }
        } else {
            $exitos[] = "Directorio '$dir' ya existe";
        }
    }
    
    // 5. Verificar archivos necesarios
    echo "<h4>5. Verificando archivos necesarios...</h4>";
    
    $archivos = [
        'lib/fpdf_utf8.php' => 'Biblioteca FPDF con soporte UTF-8'
    ];
    
    foreach ($archivos as $archivo => $descripcion) {
        if (file_exists($archivo)) {
            $exitos[] = "$descripcion encontrada: $archivo";
        } else {
            $errores[] = "$descripcion NO encontrada: $archivo - Debe descargar e instalar FPDF";
        }
    }
    
} catch (Exception $e) {
    $errores[] = "Error general: " . $e->getMessage();
}

// Mostrar resultados
echo "<div class='mt-4'>";

if (count($exitos) > 0) {
    echo "<div class='alert alert-success'>";
    echo "<h5>Operaciones exitosas:</h5>";
    echo "<ul>";
    foreach ($exitos as $exito) {
        echo "<li>✅ $exito</li>";
    }
    echo "</ul></div>";
}

if (count($errores) > 0) {
    echo "<div class='alert alert-danger'>";
    echo "<h5>Errores encontrados:</h5>";
    echo "<ul>";
    foreach ($errores as $error) {
        echo "<li>❌ $error</li>";
    }
    echo "</ul></div>";
}

if (count($errores) == 0) {
    echo "<div class='alert alert-info'>";
    echo "<h5>🎉 Configuración completada exitosamente!</h5>";
    echo "<p>El sistema de valoraciones ha sido configurado correctamente. Ahora puede:</p>";
    echo "<ul>";
    echo "<li>Acceder a <strong>calificaciones.php</strong> para cargar valoraciones preliminares</li>";
    echo "<li>Generar informes de valoración en formato PDF</li>";
    echo "<li>Usar observaciones predefinidas para agilizar la carga</li>";
    echo "</ul>";
    echo "<p><a href='calificaciones.php' class='btn btn-primary'>Ir a Calificaciones</a></p>";
    echo "</div>";
    
    echo "<div class='alert alert-warning'>";
    echo "<h6>Próximos pasos recomendados:</h6>";
    echo "<ol>";
    echo "<li>Crear los archivos de vista en el directorio 'includes/':</li>";
    echo "<ul><li>vista_valoraciones.php</li><li>vista_cuatrimestral.php</li></ul>";
    echo "<li>Reemplazar el archivo calificaciones.php con la versión actualizada</li>";
    echo "<li>Crear el archivo generar_informe_valoracion.php</li>";
    echo "<li>Configurar los períodos según el calendario escolar</li>";
    echo "</ol>";
    echo "</div>";
}

echo "</div>";

echo "<div class='mt-4'>";
echo "<a href='index.php' class='btn btn-secondary'>Volver al inicio</a>";
echo "</div>";

echo "</body></html>";
?>