<?php
/**
 * importar_materias_profesores.php - Importar materias y asignaciones de profesores
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 */

// Incluir config.php para la conexión a la base de datos
require_once 'config.php';

// Incluir el encabezado
require_once 'header.php';

// Verificar permisos (solo admin y directivos)
if (!in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
if (!$cicloActivo) {
    echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
} else {
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
}

// Inicializar variables
$successMessage = '';
$errorMessage = '';

// Función para generar código de materia con verificación de unicidad
function generarCodigoMateria($nombreMateria, $db) {
    // Remover caracteres especiales y espacios extra
    $nombre = trim($nombreMateria);
    $nombre = preg_replace('/[^a-zA-Z0-9\s]/', '', $nombre);
    
    // Si contiene números al final (como "1", "2", "3"), preservarlos
    if (preg_match('/(\d+)$/', $nombre, $matches)) {
        $numero = $matches[1];
        $nombre = trim(str_replace($numero, '', $nombre));
    } else {
        $numero = '';
    }
    
    $palabras = explode(' ', $nombre);
    $codigo = '';
    
    // Tomar la primera letra de cada palabra, máximo 4 letras
    $count = 0;
    foreach ($palabras as $palabra) {
        if ($count >= 4) break;
        
        // Excluir algunas palabras como "de", "la", etc.
        if (strlen($palabra) > 1 && !in_array(strtolower($palabra), ['de', 'la', 'el', 'los', 'las', 'del', 'con', 'por', 'para', 'en'])) {
            $codigo .= strtoupper(substr($palabra, 0, 1));
            $count++;
        }
    }
    
    // Si el código es muy corto, añadir más letras de la primera palabra
    if (strlen($codigo) < 2 && isset($palabras[0])) {
        $codigo .= strtoupper(substr($palabras[0], 1, 3 - strlen($codigo)));
    }
    
    $codigoBase = $codigo . $numero;
    $codigoFinal = $codigoBase;
    $contador = 1;
    
    // Verificar si el código ya existe en la base de datos
    while (true) {
        $codigoExistente = $db->fetchOne("SELECT id FROM materias WHERE codigo = ?", [$codigoFinal]);
        if (!$codigoExistente) {
            break; // Código no existe, podemos usarlo
        }
        
        // Si existe, añadir un número al final
        $codigoFinal = $codigoBase . $contador;
        $contador++;
    }
    
    return $codigoFinal;
}

// Datos de materias por año según el PDF
$materiasPorAnio = [
    1 => [
        ['nombre' => 'CIENCIAS NATURALES', 'profesor' => 'CID DE LOPEZ, Marta'],
        ['nombre' => 'CIENCIAS SOCIALES', 'profesor' => 'IGLESIAS, Federico'],
        ['nombre' => 'EDUCACIÓN ARTÍSTICA - Plástica', 'profesor' => 'MARISTANY, Cecilia'],
        ['nombre' => 'EDUCACIÓN ARTÍSTICA - Música', 'profesor' => 'GENDRA, Alejandro'],
        ['nombre' => 'EDUCACIÓN FÍSICA', 'profesor' => 'DOTTORI, Daniel'],
        ['nombre' => 'INGLÉS', 'profesor' => 'FERRARI, Paula'],
        ['nombre' => 'MATEMÁTICA', 'profesor' => 'CHACÓN, Martín'],
        ['nombre' => 'PRÁCTICAS DEL LENGUAJE', 'profesor' => 'GÓMEZ, María Victoria'],
        ['nombre' => 'CONSTRUCCIÓN DE CIUDADANÍA', 'profesor' => 'GALDEANO, Federico'],
        ['nombre' => '1PT1 - Metales 1', 'profesor' => 'CARDALDA, Brian'],
        ['nombre' => '1PT2 - Maderas 1', 'profesor' => 'KLOSTER, Edgardo'],
        ['nombre' => '1PT3 - Tecnología 1', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '1LT1 - Informática 1', 'profesor' => 'MONTOTO, Diego'],
        ['nombre' => '1LT2 - Impresión 3D', 'profesor' => 'MONTOTO, Diego'],
        ['nombre' => '1LT3 - Dibujo Técnico 1', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '1ST1 - Robótica 1', 'profesor' => 'LANFRANCO, Pablo'],
        ['nombre' => '1ST2 - Diseño Tecnológico', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '1ST3 - Proyecto Tecnológico 1', 'profesor' => 'ROMANO, Carlos']
    ],
    2 => [
        ['nombre' => 'BIOLOGÍA', 'profesor' => 'CID DE LOPEZ, Marta'],
        ['nombre' => 'CONSTR. DE CIUD. - Maderas', 'profesor' => 'KLOSTER, Edgardo'],
        ['nombre' => 'CONSTR. DE CIUD. - Metales', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => 'CONSTR. DE CIUD. - Electricidad', 'profesor' => 'BANGERT, Sergio'],
        ['nombre' => 'EDUCACIÓN ARTÍSTICA - Plástica', 'profesor' => 'MARISTANY, Cecilia'],
        ['nombre' => 'EDUCACIÓN ARTÍSTICA - Música', 'profesor' => 'GENDRA, Alejandro'],
        ['nombre' => 'EDUCACIÓN FÍSICA', 'profesor' => 'DOTTORI, Daniel'],
        ['nombre' => 'FÍSICO QUÍMICA', 'profesor' => 'ROMANO, Carlos'],
        ['nombre' => 'GEOGRAFÍA', 'profesor' => 'BÁLSAMO, Rosana'],
        ['nombre' => 'HISTORIA', 'profesor' => 'IGLESIAS, Federico'],
        ['nombre' => 'INGLÉS', 'profesor' => 'MANNIELLO, Alejandro'],
        ['nombre' => 'MATEMÁTICA', 'profesor' => 'CHACÓN, Martín'],
        ['nombre' => 'PRÁCTICAS DEL LENGUAJE', 'profesor' => 'GÓMEZ, María Victoria'],
        ['nombre' => '2PT1 - Metales 2', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '2PT2 - Maderas 2', 'profesor' => 'KLOSTER, Edgardo'],
        ['nombre' => '2PT3 - Tecnología 2', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '2LT1 - Dibujo Técnico 2', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '2LT2 - Informática 2', 'profesor' => 'MONTOTO, Diego'],
        ['nombre' => '2ST1 - Robótica 2', 'profesor' => 'LANFRANCO, Pablo'],
        ['nombre' => '2ST2 - Fundamentos de Electricidad', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => '2ST3 - Proyecto Tecnológico 2', 'profesor' => 'BELMONTE, Jorge']
    ],
    3 => [
        ['nombre' => 'BIOLOGÍA', 'profesor' => 'CID DE LOPEZ, Marta'],
        ['nombre' => 'CONSTR. DE CIUD. - Maderas', 'profesor' => 'KLOSTER, Edgardo'],
        ['nombre' => 'CONSTR. DE CIUD. - Metales', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => 'CONSTR. DE CIUD. - Electricidad', 'profesor' => 'BANGERT, Sergio'],
        ['nombre' => 'EDUCACIÓN ARTÍSTICA - Plástica', 'profesor' => 'MARISTANY, Cecilia'],
        ['nombre' => 'EDUCACIÓN ARTÍSTICA - Música', 'profesor' => 'GENDRA, Alejandro'],
        ['nombre' => 'EDUCACIÓN FÍSICA', 'profesor' => 'DOTTORI, Daniel'],
        ['nombre' => 'FÍSICO QUÍMICA', 'profesor' => 'CATALANO, Marina'],
        ['nombre' => 'GEOGRAFÍA', 'profesor' => 'BÁLSAMO, Rosana'],
        ['nombre' => 'HISTORIA', 'profesor' => 'IGLESIAS, Federico'],
        ['nombre' => 'INGLÉS', 'profesor' => 'FERRARI, Paula'],
        ['nombre' => 'MATEMÁTICA', 'profesor' => 'CASTRO, Gonzalo'],
        ['nombre' => 'PRÁCTICAS DEL LENGUAJE', 'profesor' => 'GÓMEZ, María Victoria'],
        ['nombre' => '3PT1 - Metales 3', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '3PT2 - Fundición', 'profesor' => 'RICCHINI, Gerardo'],
        ['nombre' => '3PT3 - Tecnología 3', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '3LT1 - Dibujo Técnico 3', 'profesor' => 'SÁNCHEZ, Candela'],
        ['nombre' => '3LT2 - Informática 3', 'profesor' => 'MONTOTO, Diego'],
        ['nombre' => '3ST1 - Inst. Eléctr. Domicililarias 1', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => '3ST2 - Fluidos', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => '3ST3 - Proyecto Tecnológico 3', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '3ST4 - Robótica 3', 'profesor' => 'LANFRANCO, Pablo']
    ],
    4 => [
        ['nombre' => 'LITERATURA', 'profesor' => 'GÓMEZ, María Victoria'],
        ['nombre' => 'INGLÉS', 'profesor' => 'MANNIELLO, Alejandro'],
        ['nombre' => 'EDUCACIÓN FÍSICA', 'profesor' => 'DOTTORI, Daniel'],
        ['nombre' => 'SALUD Y ADOLESCENCIA', 'profesor' => 'PAZ, Marcela'],
        ['nombre' => 'HISTORIA', 'profesor' => 'IGLESIAS, Federico'],
        ['nombre' => 'GEOGRAFÍA', 'profesor' => 'BÁLSAMO, Rosana'],
        ['nombre' => 'MATEMÁTICA', 'profesor' => 'CASTRO, Gonzalo'],
        ['nombre' => 'FÍSICA', 'profesor' => 'ROMANO, Carlos'],
        ['nombre' => 'QUÍMICA', 'profesor' => 'CATALANO, Marina'],
        ['nombre' => 'CONOCIMIENTO DE LOS MATERIALES', 'profesor' => 'CATALANO, Marina'],
        ['nombre' => '4DT1 - Dibujo Técnológico', 'profesor' => 'SÁNCHEZ, Candela'],
        ['nombre' => '4DT2 - Dibujo con Autocad', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => '4MEA1 - Electrónica Básica', 'profesor' => 'BANGERT, Sergio'],
        ['nombre' => '4MEA2 - Transformadores', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => '4MEA3 - Principios de Automatización', 'profesor' => 'BANGERT, Sergio'],
        ['nombre' => '4DPM1 - Metrología', 'profesor' => 'CARDALDA, Brian'],
        ['nombre' => '4DPM2 - Mecanizado 1', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '4DPM3 - Mecanizado 2', 'profesor' => 'PRESENTADO, Dante'],
        ['nombre' => '4IAE1 - Instalaciones Eléctricas Domicililarias 2', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => '4IAE2 - Soldadura', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => '4IAE3 - Electrotecnia', 'profesor' => 'BANGERT, Sergio']
    ],
    5 => [
        ['nombre' => 'LITERATURA', 'profesor' => 'GÓMEZ, María Victoria'],
        ['nombre' => 'INGLÉS', 'profesor' => 'FERRARI, Paula'],
        ['nombre' => 'EDUCACIÓN FÍSICA', 'profesor' => 'DOTTORI, Daniel'],
        ['nombre' => 'POLÍTICA Y CIUDADANÍA', 'profesor' => 'FERNÁNDEZ, Francisco'],
        ['nombre' => 'HISTORIA', 'profesor' => 'IGLESIAS, Federico'],
        ['nombre' => 'GEOGRAFÍA', 'profesor' => 'BÁLSAMO, Rosana'],
        ['nombre' => 'ANALISIS MATEMÁTICO', 'profesor' => 'CHACÓN, Martín'],
        ['nombre' => 'MECÁNICA Y MECANISMOS', 'profesor' => 'ROMANO, Carlos'],
        ['nombre' => 'ELECTROTECNIA', 'profesor' => 'BARRIO, Daniel'],
        ['nombre' => 'RESISTENCIA Y ENSAYO DE MATERIALES', 'profesor' => 'DARGET, Marcelo'],
        ['nombre' => '5MEA1 - Contactores', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => '5MEA2 - Electrónica Digital', 'profesor' => 'BARRIO, Daniel'],
        ['nombre' => '5MEA3 - Motores Eléctricos', 'profesor' => 'CARDALDA, Brian'],
        ['nombre' => '5DPM1 - Mecanizado 3', 'profesor' => 'RICCHINI, Gerardo'],
        ['nombre' => '5DPM2 - Metalografía y Tratamientos Térmicos', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '5DPM3 - CAD 1', 'profesor' => 'MELLINO, Javier'],
        ['nombre' => '5IAE1 - Control de Fluidos 1', 'profesor' => 'ARIAS, Gabriel'],
        ['nombre' => '5IAE2 - Control de Fluidos 2', 'profesor' => 'ARIAS, Gabriel'],
        ['nombre' => '5IAE3 - Refrigeración', 'profesor' => 'LAGO, Ezequiel']
    ],
    6 => [
        ['nombre' => 'LITERATURA', 'profesor' => 'GÓMEZ, María Victoria'],
        ['nombre' => 'INGLÉS', 'profesor' => 'MANNIELLO, Alejandro'],
        ['nombre' => 'EDUCACIÓN FÍSICA', 'profesor' => 'DOTTORI, Daniel'],
        ['nombre' => 'FILOSOFÍA', 'profesor' => 'FERNÁNDEZ, Francisco'],
        ['nombre' => 'ARTE', 'profesor' => 'IGLESIAS, Federico'],
        ['nombre' => 'MATEMÁTICA APLICADA', 'profesor' => 'CHACÓN, Martín'],
        ['nombre' => 'ELECTROTECNIA', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => 'SISTEMAS MECÁNICOS', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => 'DERECHOS DEL TRABAJO', 'profesor' => 'GALDEANO, Federico'],
        ['nombre' => '6LME - Laboratorio de Mediciones Eléctricas', 'profesor' => 'BARRIO, Daniel'],
        ['nombre' => '6MEA1 - Microcontroladores', 'profesor' => 'LANFRANCO, Pablo'],
        ['nombre' => '6MEA2 - PLC', 'profesor' => 'BANGERT, Sergio'],
        ['nombre' => '6MEA3 - Sistemas de Control', 'profesor' => 'BANGERT, Sergio'],
        ['nombre' => '6DPM1 - CNC Torno', 'profesor' => 'LAGO, Ezequiel'],
        ['nombre' => '6DPM2 - CAD 2', 'profesor' => 'MELLINO, Javier'],
        ['nombre' => '6DPM3 - CNC Fresadora', 'profesor' => 'LAGO, Ezequiel'],
        ['nombre' => '6IAE1 - Motores de Combustión Interna 1', 'profesor' => 'NETO, Marcelo'],
        ['nombre' => '6IAE2 - Luminotecnia', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => '6IAE3 - Distribución de Energía Eléctrica', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => '6IAE4 - MASTER', 'profesor' => 'NETO, Marcelo']
    ],
    7 => [
        ['nombre' => 'PRÁCTICAS PROFESIONALIZANTES', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => 'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL', 'profesor' => 'DARGET, Marcelo'],
        ['nombre' => 'ELECTRÓNICA INDUSTRIAL', 'profesor' => 'BARRIO, Daniel'],
        ['nombre' => 'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE', 'profesor' => 'GALDEANO, Federico'],
        ['nombre' => 'MÁQUINAS ELÉCTRICAS', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => 'SISTEMAS MECÁNICOS', 'profesor' => 'PIZZOLATO, Maximiliano'],
        ['nombre' => '7LMCC - Laboratorio de Metrología y Control de Calidad', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '7MME1 - Mantenimiento Mecánico', 'profesor' => 'LAGO, Ezequiel'],
        ['nombre' => '7MME2 - Mantenimiento Edilicio', 'profesor' => 'KLOSTER, Edgardo'],
        ['nombre' => '7MME3 - Mantenimiento Eléctrico', 'profesor' => 'OJEDA, Daniel'],
        ['nombre' => '7PDE1 - CAD CAM', 'profesor' => 'PIZZOLATO, Maximiliano'],
        ['nombre' => '7PDE2 - Desafío ECO', 'profesor' => 'KIAHIAYAN, Juan Martín'],
        ['nombre' => '7PDE3 - Diseño de Procesos', 'profesor' => 'BELMONTE, Jorge'],
        ['nombre' => '7PDIE1 - Motores de Combustión Interna 2', 'profesor' => 'NETO, Marcelo'],
        ['nombre' => '7PDIE2 - Robótica Industrial', 'profesor' => 'CARDALDA, Brian'],
        ['nombre' => '7PDIE3 - CAD 3', 'profesor' => 'MELLINO, Javier'],
        ['nombre' => '7PDIE4 - Centrales Eléctricas', 'profesor' => 'BARRIO, Daniel']
    ]
];

// Procesar importación automática
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importar_automatico'])) {
    try {
        $db->transaction(function($db) use ($materiasPorAnio, $cicloLectivoId, &$successMessage, &$errorMessage) {
            $materiasCreadas = 0;
            $profesoresCreados = 0;
            $asignacionesCreadas = 0;
            $errores = [];
            
            foreach ($materiasPorAnio as $anio => $materias) {
                // Verificar si existe el curso para este año
                $curso = $db->fetchOne(
                    "SELECT id FROM cursos WHERE anio = ? AND ciclo_lectivo_id = ?",
                    [$anio, $cicloLectivoId]
                );
                
                if (!$curso) {
                    // Crear curso si no existe
                    $nombreCurso = $anio . "° Año " . ($cicloLectivoId ? $db->fetchOne("SELECT anio FROM ciclos_lectivos WHERE id = ?", [$cicloLectivoId])['anio'] : date('Y'));
                    $cursoId = $db->insert(
                        "INSERT INTO cursos (nombre, anio, ciclo_lectivo_id) VALUES (?, ?, ?)",
                        [$nombreCurso, $anio, $cicloLectivoId]
                    );
                } else {
                    $cursoId = $curso['id'];
                }
                
                foreach ($materias as $materiaData) {
                    $nombreMateria = $materiaData['nombre'];
                    $nombreProfesor = $materiaData['profesor'];
                    
                    // Generar código único para la materia
                    $codigoMateria = generarCodigoMateria($nombreMateria, $db);
                    
                    // Verificar si la materia ya existe
                    $materiaExistente = $db->fetchOne(
                        "SELECT id FROM materias WHERE nombre = ?",
                        [$nombreMateria]
                    );
                    
                    if (!$materiaExistente) {
                        // Crear materia
                        $materiaId = $db->insert(
                            "INSERT INTO materias (nombre, codigo) VALUES (?, ?)",
                            [$nombreMateria, $codigoMateria]
                        );
                        $materiasCreadas++;
                    } else {
                        $materiaId = $materiaExistente['id'];
                    }
                    
                    // Procesar profesor
                    $profesorId = null;
                    if (!empty($nombreProfesor)) {
                        // Dividir apellido y nombre
                        $nombrePartes = explode(',', $nombreProfesor);
                        if (count($nombrePartes) == 2) {
                            $apellido = trim($nombrePartes[0]);
                            $nombre = trim($nombrePartes[1]);
                        } else {
                            // Si no hay coma, tomar la primera palabra como apellido
                            $palabras = explode(' ', $nombreProfesor);
                            $apellido = $palabras[0];
                            $nombre = implode(' ', array_slice($palabras, 1));
                        }
                        
                        // Buscar profesor existente
                        $profesorExistente = $db->fetchOne(
                            "SELECT id FROM usuarios WHERE apellido = ? AND nombre = ? AND tipo = 'profesor'",
                            [$apellido, $nombre]
                        );
                        
                        if (!$profesorExistente) {
                            // Crear profesor
                            $dniTemporal = str_pad($materiasCreadas + $profesoresCreados, 8, '0', STR_PAD_LEFT);
                            $profesorId = $db->insert(
                                "INSERT INTO usuarios (nombre, apellido, dni, contrasena, tipo, activo) VALUES (?, ?, ?, ?, 'profesor', 1)",
                                [$nombre, $apellido, $dniTemporal, 'profesor123']
                            );
                            $profesoresCreados++;
                        } else {
                            $profesorId = $profesorExistente['id'];
                        }
                    }
                    
                    // Verificar si ya existe la asignación materia-curso
                    $asignacionExistente = $db->fetchOne(
                        "SELECT id FROM materias_por_curso WHERE materia_id = ? AND curso_id = ?",
                        [$materiaId, $cursoId]
                    );
                    
                    if (!$asignacionExistente) {
                        // Crear asignación materia-curso-profesor
                        $db->insert(
                            "INSERT INTO materias_por_curso (materia_id, curso_id, profesor_id) VALUES (?, ?, ?)",
                            [$materiaId, $cursoId, $profesorId]
                        );
                        $asignacionesCreadas++;
                    } else {
                        // Actualizar profesor si es diferente
                        $db->query(
                            "UPDATE materias_por_curso SET profesor_id = ? WHERE id = ?",
                            [$profesorId, $asignacionExistente['id']]
                        );
                    }
                }
            }
            
            $successMessage = "Importación completada exitosamente:<br>";
            $successMessage .= "- Materias creadas: $materiasCreadas<br>";
            $successMessage .= "- Profesores creados: $profesoresCreados<br>";
            $successMessage .= "- Asignaciones creadas/actualizadas: $asignacionesCreadas";
        });
        
    } catch (Exception $e) {
        $errorMessage = "Error durante la importación: " . $e->getMessage();
    }
}

// Obtener estadísticas actuales
$stats = [];
try {
    $stats['materias'] = $db->fetchOne("SELECT COUNT(*) as count FROM materias")['count'];
    $stats['profesores'] = $db->fetchOne("SELECT COUNT(*) as count FROM usuarios WHERE tipo = 'profesor'")['count'];
    $stats['asignaciones'] = $db->fetchOne("SELECT COUNT(*) as count FROM materias_por_curso")['count'];
    $stats['cursos'] = $db->fetchOne("SELECT COUNT(*) as count FROM cursos WHERE ciclo_lectivo_id = ?", [$cicloLectivoId])['count'];
} catch (Exception $e) {
    // Silenciar errores de estadísticas
}
?>

<div class="container-fluid mt-4">
    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $errorMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $successMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Estadísticas actuales -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Estado Actual del Sistema</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="card border-primary">
                                <div class="card-body">
                                    <h3 class="text-primary"><?= $stats['materias'] ?? 0 ?></h3>
                                    <p class="card-text">Materias registradas</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-success">
                                <div class="card-body">
                                    <h3 class="text-success"><?= $stats['profesores'] ?? 0 ?></h3>
                                    <p class="card-text">Profesores registrados</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h3 class="text-info"><?= $stats['asignaciones'] ?? 0 ?></h3>
                                    <p class="card-text">Asignaciones creadas</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-warning">
                                <div class="card-body">
                                    <h3 class="text-warning"><?= $stats['cursos'] ?? 0 ?></h3>
                                    <p class="card-text">Cursos del ciclo</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Importación automática -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">Importación Automática de Materias y Profesores</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6 class="alert-heading">¿Qué hace esta importación?</h6>
                        <ul class="mb-0">
                            <li>Crea automáticamente todas las materias de 1° a 7° año según el plan de estudios</li>
                            <li>Registra a todos los profesores mencionados en el documento</li>
                            <li>Asigna cada materia a su profesor correspondiente</li>
                            <li>Crea los cursos por año si no existen</li>
                            <li>Genera códigos automáticos <strong>únicos</strong> para cada materia</li>
                        </ul>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <h6>Resumen de lo que se importará:</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Año</th>
                                            <th>Cantidad de Materias</th>
                                            <th>Profesores Únicos</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalMaterias = 0;
                                        $profesoresUnicos = [];
                                        foreach ($materiasPorAnio as $anio => $materias): 
                                            $totalMaterias += count($materias);
                                            $profesoresDelAnio = [];
                                            foreach ($materias as $materia) {
                                                if (!empty($materia['profesor'])) {
                                                    $profesoresUnicos[$materia['profesor']] = true;
                                                    $profesoresDelAnio[$materia['profesor']] = true;
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?= $anio ?>° Año</td>
                                            <td><?= count($materias) ?></td>
                                            <td><?= count($profesoresDelAnio) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-primary">
                                            <td><strong>TOTAL</strong></td>
                                            <td><strong><?= $totalMaterias ?></strong></td>
                                            <td><strong><?= count($profesoresUnicos) ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <h6>Información importante:</h6>
                            <ul class="small">
                                <li>Los profesores se crean con DNI temporal</li>
                                <li>La contraseña inicial será "profesor123"</li>
                                <li>Si ya existen materias o profesores, se actualizarán las asignaciones</li>
                                <li>Los códigos de materia se generan automáticamente y de forma única</li>
                                <li>Esta operación es segura y se puede ejecutar múltiples veces</li>
                            </ul>
                        </div>
                    </div>
                    
                    <?php if ($cicloLectivoId > 0): ?>
                    <form method="POST" action="" class="mt-3">
                        <div class="text-center">
                            <button type="submit" name="importar_automatico" class="btn btn-primary btn-lg" 
                                    onclick="return confirm('¿Está seguro de que desea importar todas las materias y profesores? Esta operación puede tomar unos momentos.')">
                                <i class="bi bi-download"></i> Importar Materias y Profesores
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No hay un ciclo lectivo activo. No se puede realizar la importación.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Vista previa de algunas materias -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Vista Previa - Materias por Año</h5>
                </div>
                <div class="card-body">
                    <div class="accordion" id="accordionMaterias">
                        <?php foreach ($materiasPorAnio as $anio => $materias): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading<?= $anio ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapse<?= $anio ?>" aria-expanded="false" aria-controls="collapse<?= $anio ?>">
                                    <?= $anio ?>° Año - <?= count($materias) ?> materias
                                </button>
                            </h2>
                            <div id="collapse<?= $anio ?>" class="accordion-collapse collapse" 
                                 aria-labelledby="heading<?= $anio ?>" data-bs-parent="#accordionMaterias">
                                <div class="accordion-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Materia</th>
                                                    <th>Código (ejemplo)</th>
                                                    <th>Profesor</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $contador = 1; ?>
                                                <?php foreach ($materias as $materia): ?>
                                                <tr>
                                                    <td><?= $contador++ ?></td>
                                                    <td><?= htmlspecialchars($materia['nombre']) ?></td>
                                                    <td>
                                                        <span class="badge bg-info">
                                                            <?php
                                                            $codigoEjemplo = '';
                                                            $materiaExistente = $db->fetchOne("SELECT codigo FROM materias WHERE nombre = ?", [$materia['nombre']]);
                                                            if ($materiaExistente) {
                                                                $codigoEjemplo = $materiaExistente['codigo'];
                                                            } else {
                                                                // Esta es solo una muestra, por lo que usamos una versión simplificada
                                                                $nombre = trim($materia['nombre']);
                                                                $palabras = explode(' ', $nombre);
                                                                $codigo = '';
                                                                foreach (array_slice($palabras, 0, 4) as $palabra) {
                                                                    if (strlen($palabra) > 1) {
                                                                        $codigo .= strtoupper(substr($palabra, 0, 1));
                                                                    }
                                                                }
                                                                $codigoEjemplo = $codigo;
                                                            }
                                                            echo $codigoEjemplo;
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars($materia['profesor']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista de profesores únicos -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Profesores que se registrarán</h5>
                </div>
                <div class="card-body">
                    <?php 
                    $profesoresUnicos = [];
                    foreach ($materiasPorAnio as $anio => $materias) {
                        foreach ($materias as $materia) {
                            if (!empty($materia['profesor']) && !isset($profesoresUnicos[$materia['profesor']])) {
                                $profesoresUnicos[$materia['profesor']] = [
                                    'nombre' => $materia['profesor'],
                                    'materias' => []
                                ];
                            }
                            if (!empty($materia['profesor'])) {
                                $profesoresUnicos[$materia['profesor']]['materias'][] = $anio . "° - " . $materia['nombre'];
                            }
                        }
                    }
                    ksort($profesoresUnicos);
                    ?>
                    
                    <div class="row">
                        <?php $contador = 0; ?>
                        <?php foreach ($profesoresUnicos as $profesor => $data): ?>
                        <?php if ($contador % 2 == 0 && $contador > 0): ?>
                        </div><div class="row">
                        <?php endif; ?>
                        
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="bi bi-person"></i> <?= htmlspecialchars($profesor) ?>
                                    </h6>
                                    <small class="text-muted">
                                        Materias asignadas: <?= count($data['materias']) ?>
                                    </small>
                                    <div class="mt-2">
                                        <?php foreach (array_slice($data['materias'], 0, 3) as $materia): ?>
                                        <span class="badge bg-light text-dark me-1 mb-1"><?= htmlspecialchars($materia) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($data['materias']) > 3): ?>
                                        <span class="badge bg-secondary">+<?= count($data['materias']) - 3 ?> más</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php $contador++; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enlaces de navegación -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Acciones Relacionadas</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="usuarios.php?tipo=profesor" class="btn btn-outline-primary w-100">
                                <i class="bi bi-people"></i><br>
                                Ver Profesores
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="materias.php" class="btn btn-outline-info w-100">
                                <i class="bi bi-journal-text"></i><br>
                                Gestionar Materias
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="cursos.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-book"></i><br>
                                Ver Cursos
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="importar.php" class="btn btn-outline-warning w-100">
                                <i class="bi bi-upload"></i><br>
                                Importar Estudiantes
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Incluir el pie de página
require_once 'footer.php';
?>