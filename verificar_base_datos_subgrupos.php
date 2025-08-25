<?php
/**
 * verificar_base_datos_subgrupos.php
 * Script para verificar y crear las tablas necesarias para subgrupos
 */

require_once 'config.php';

$db = Database::getInstance();

echo "<h2>Verificando estructura de base de datos para subgrupos...</h2>";

// Verificar tabla estudiantes_por_materia
try {
    $result = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='estudiantes_por_materia'");
    if (!$result) {
        echo "<p style='color: red;'>❌ Tabla 'estudiantes_por_materia' no existe. Creando...</p>";
        
        $db->query("
            CREATE TABLE estudiantes_por_materia (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                materia_curso_id INTEGER NOT NULL,
                estudiante_id INTEGER NOT NULL,
                ciclo_lectivo_id INTEGER NOT NULL,
                subgrupo TEXT,
                periodo_inicio TEXT,
                periodo_fin TEXT,
                activo INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
            )
        ");
        echo "<p style='color: green;'>✅ Tabla 'estudiantes_por_materia' creada correctamente</p>";
    } else {
        echo "<p style='color: green;'>✅ Tabla 'estudiantes_por_materia' existe</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error con tabla estudiantes_por_materia: " . $e->getMessage() . "</p>";
}

// Verificar tabla configuracion_subgrupos
try {
    $result = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='configuracion_subgrupos'");
    if (!$result) {
        echo "<p style='color: red;'>❌ Tabla 'configuracion_subgrupos' no existe. Creando...</p>";
        
        $db->query("
            CREATE TABLE configuracion_subgrupos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                materia_curso_id INTEGER NOT NULL,
                ciclo_lectivo_id INTEGER NOT NULL,
                tipo_division TEXT DEFAULT 'tercio',
                cantidad_grupos INTEGER DEFAULT 3,
                rotacion_automatica INTEGER DEFAULT 0,
                periodo_rotacion TEXT DEFAULT 'anual',
                descripcion TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (materia_curso_id) REFERENCES materias_por_curso(id),
                FOREIGN KEY (ciclo_lectivo_id) REFERENCES ciclos_lectivos(id)
            )
        ");
        echo "<p style='color: green;'>✅ Tabla 'configuracion_subgrupos' creada correctamente</p>";
    } else {
        echo "<p style='color: green;'>✅ Tabla 'configuracion_subgrupos' existe</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error con tabla configuracion_subgrupos: " . $e->getMessage() . "</p>";
}

// Verificar campo requiere_subgrupos en materias_por_curso
try {
    $columns = $db->fetchAll("PRAGMA table_info(materias_por_curso)");
    $tieneRequiereSubgrupos = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'requiere_subgrupos') {
            $tieneRequiereSubgrupos = true;
            break;
        }
    }
    
    if (!$tieneRequiereSubgrupos) {
        echo "<p style='color: red;'>❌ Campo 'requiere_subgrupos' no existe en materias_por_curso. Agregando...</p>";
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN requiere_subgrupos INTEGER DEFAULT 0");
        echo "<p style='color: green;'>✅ Campo 'requiere_subgrupos' agregado correctamente</p>";
    } else {
        echo "<p style='color: green;'>✅ Campo 'requiere_subgrupos' existe en materias_por_curso</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error verificando campo requiere_subgrupos: " . $e->getMessage() . "</p>";
}

// Mostrar estadísticas
try {
    $materiasConSubgrupos = $db->fetchOne("SELECT COUNT(*) as count FROM materias_por_curso WHERE requiere_subgrupos = 1");
    $materiasConSubgruposCount = $materiasConSubgrupos ? $materiasConSubgrupos['count'] : 0;
    
    $asignacionesActivas = $db->fetchOne("SELECT COUNT(*) as count FROM estudiantes_por_materia WHERE activo = 1");
    $asignacionesActivasCount = $asignacionesActivas ? $asignacionesActivas['count'] : 0;
    
    $configuracionesActivas = $db->fetchOne("SELECT COUNT(*) as count FROM configuracion_subgrupos");
    $configuracionesActivasCount = $configuracionesActivas ? $configuracionesActivas['count'] : 0;
    
    echo "<h3>Estadísticas actuales:</h3>";
    echo "<p>📊 Materias configuradas para subgrupos: <strong>$materiasConSubgruposCount</strong></p>";
    echo "<p>👥 Asignaciones de estudiantes activas: <strong>$asignacionesActivasCount</strong></p>";
    echo "<p>⚙️ Configuraciones de subgrupos: <strong>$configuracionesActivasCount</strong></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error obteniendo estadísticas: " . $e->getMessage() . "</p>";
}

// Verificar si existe el archivo gestionar_subgrupos.php y si tiene las nuevas funciones
echo "<h3>Verificando archivo gestionar_subgrupos.php:</h3>";
$archivoSubgrupos = 'gestionar_subgrupos.php';
if (file_exists($archivoSubgrupos)) {
    $contenido = file_get_contents($archivoSubgrupos);
    
    $funcionesNecesarias = [
        'restaurar_subgrupos' => "function restaurarSubgrupos",
        'corregir_asignaciones_curso' => "function corregirAsignacionesPorCurso",
        'case_restaurar' => "case 'restaurar_subgrupos'",
        'case_corregir' => "case 'corregir_asignaciones_curso'"
    ];
    
    foreach ($funcionesNecesarias as $nombre => $buscar) {
        if (strpos($contenido, $buscar) !== false) {
            echo "<p style='color: green;'>✅ Función '$nombre' encontrada</p>";
        } else {
            echo "<p style='color: red;'>❌ Función '$nombre' NO encontrada</p>";
        }
    }
    
    if (strpos($contenido, 'restaurar_subgrupos') === false) {
        echo "<div style='background: #ffeeee; padding: 10px; border: 1px solid red; margin: 10px 0;'>";
        echo "<strong>⚠️ TU ARCHIVO gestionar_subgrupos.php NO ESTÁ ACTUALIZADO</strong><br>";
        echo "No se encontraron las nuevas funciones. Necesitas reemplazar el archivo con la versión actualizada.";
        echo "</div>";
    } else {
        echo "<div style='background: #eeffee; padding: 10px; border: 1px solid green; margin: 10px 0;'>";
        echo "<strong>✅ Tu archivo gestionar_subgrupos.php parece estar actualizado</strong>";
        echo "</div>";
    }
} else {
    echo "<p style='color: red;'>❌ Archivo gestionar_subgrupos.php no encontrado</p>";
}

echo "<hr>";
echo "<p><strong>Si todo aparece en verde (✅), tu base de datos está lista para usar subgrupos.</strong></p>";
echo "<p><a href='gestionar_subgrupos.php'>← Volver a Gestión de Subgrupos</a></p>";
?>