<?php
/**
 * verificar_profesores_ajax.php - Verificar profesores asignados para importación de contenidos
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

header('Content-Type: application/json');

require_once 'config.php';

// Verificar que el usuario esté logueado y tenga permisos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Leer datos JSON del request
$input = json_decode(file_get_contents('php://input'), true);

$cursoId = intval($input['curso_id'] ?? 0);
$anio = intval($input['anio'] ?? 0);

if ($cursoId <= 0 || $anio <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
    exit;
}

/**
 * Mapeos de materias por año - MISMO QUE importar_contenidos.php
 */
function obtenerMapeoMateriasVerificacion($anio) {
    switch ($anio) {
        case 1:
            return [
                1 => 'CIENCIAS NATURALES',
                2 => 'CIENCIAS SOCIALES',
                3 => 'EDUCACIÓN ARTÍSTICA - Plástica',
                4 => 'EDUCACIÓN ARTÍSTICA - Música',
                5 => 'EDUCACIÓN FÍSICA',
                6 => 'INGLÉS',
                7 => 'MATEMÁTICA',
                8 => 'PRÁCTICAS DEL LENGUAJE',
                9 => 'CONSTRUCCIÓN DE CIUDADANÍA',
                10 => 'Metales 1',
                11 => 'Maderas 1',
                12 => 'Tecnología 1',
                13 => 'Informática 1',
                14 => 'Impresión 3D',
                15 => 'Dibujo Técnico 1',
                16 => 'Robótica 1',
                17 => 'Diseño Tecnológico',
                18 => 'Proyecto Tecnológico 1'
            ];
        case 2:
            return [
                1 => 'BIOLOGÍA',
                2 => 'CONSTR. DE CIUD. - Maderas',
                3 => 'CONSTR. DE CIUD. - Metales',
                4 => 'CONSTR. DE CIUD. - Electricidad',
                5 => 'EDUCACIÓN ARTÍSTICA - Plástica',
                6 => 'EDUCACIÓN ARTÍSTICA - Música',
                7 => 'EDUCACIÓN FÍSICA',
                8 => 'FÍSICO QUÍMICA',
                9 => 'GEOGRAFÍA',
                10 => 'HISTORIA',
                11 => 'INGLÉS',
                12 => 'MATEMÁTICA',
                13 => 'PRÁCTICAS DEL LENGUAJE',
                14 => 'Metales 2',
                15 => 'Maderas 2',
                16 => 'Tecnología 2',
                17 => 'Dibujo Técnico 2',
                18 => 'Informática 2',
                19 => 'Robótica 2',
                20 => 'Fundamentos de Electricidad',
                21 => 'Proyecto Tecnológico 2'
            ];
        case 3:
            return [
                1 => 'BIOLOGÍA',
                2 => 'CONSTR. DE CIUD. - Maderas',
                3 => 'CONSTR. DE CIUD. - Metales',
                4 => 'CONSTR. DE CIUD. - Electricidad',
                5 => 'EDUCACIÓN ARTÍSTICA - Plástica',
                6 => 'EDUCACIÓN ARTÍSTICA - Música',
                7 => 'EDUCACIÓN FÍSICA',
                8 => 'FÍSICO QUÍMICA',
                9 => 'GEOGRAFÍA',
                10 => 'HISTORIA',
                11 => 'INGLÉS',
                12 => 'MATEMÁTICA',
                13 => 'PRÁCTICAS DEL LENGUAJE',
                14 => 'Metales 3',
                15 => 'Fundición',
                16 => 'Tecnología 3',
                17 => 'Dibujo Técnico 3',
                18 => 'Informática 3',
                19 => 'Inst. Eléctr. Domicililarias 1',
                20 => 'Fluidos',
                21 => 'Proyecto Tecnológico 3',
                22 => 'Robótica 3'
            ];
        case 4:
            return [
                1 => 'LITERATURA',
                2 => 'INGLÉS',
                3 => 'EDUCACIÓN FÍSICA',
                4 => 'SALUD Y ADOLESCENCIA',
                5 => 'HISTORIA',
                6 => 'GEOGRAFÍA',
                7 => 'MATEMÁTICA',
                8 => 'FÍSICA',
                9 => 'QUÍMICA',
                10 => 'CONOCIMIENTO DE LOS MATERIALES',
                11 => 'Dibujo Técnológico',
                12 => 'Dibujo con Autocad',
                13 => 'Electrónica Básica',
                14 => 'Transformadores',
                15 => 'Principios de Automatización',
                16 => 'Metrología',
                17 => 'Mecanizado 1',
                18 => 'Mecanizado 2',
                19 => 'Instalaciones Eléctricas Domicililarias 2',
                20 => 'Soldadura',
                21 => 'Electrotecnia'
            ];
        case 5:
            return [
                1 => 'LITERATURA',
                2 => 'INGLÉS',
                3 => 'EDUCACIÓN FÍSICA',
                4 => 'POLÍTICA Y CIUDADANÍA',
                5 => 'HISTORIA',
                6 => 'GEOGRAFÍA',
                7 => 'ANALISIS MATEMÁTICO',
                8 => 'MECÁNICA Y MECANISMOS',
                9 => 'ELECTROTECNIA',
                10 => 'RESISTENCIA Y ENSAYO DE MATERIALES',
                11 => 'Contactores',
                12 => 'Electrónica Digital',
                13 => 'Motores Eléctricos',
                14 => 'Mecanizado 3',
                15 => 'Metalografía y Tratamientos Térmicos',
                16 => 'CAD 1',
                17 => 'Control de Fluidos 1',
                18 => 'Control de Fluidos 2',
                19 => 'Refrigeración'
            ];
        case 6:
            return [
                1 => 'LITERATURA',
                2 => 'INGLÉS',
                3 => 'EDUCACIÓN FÍSICA',
                4 => 'FILOSOFÍA',
                5 => 'ARTE',
                6 => 'MATEMÁTICA APLICADA',
                7 => 'ELECTROTECNIA',
                8 => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
                9 => 'SISTEMAS MECÁNICOS',
                10 => 'DERECHOS DEL TRABAJO',
                11 => 'Laboratorio de Mediciones Eléctricas',
                12 => 'Microcontroladores',
                13 => 'PLC',
                14 => 'Sistemas de Control',
                15 => 'CNC Torno',
                16 => 'CAD 2',
                17 => 'Fresadora',
                18 => 'Motores de Combustión Interna 1',
                19 => 'Luminotecnia',
                20 => 'Distribución de Energía Eléctrica',
                21 => 'MASTER'
            ];
        case 7:
            return [
                1 => 'PRÁCTICAS PROFESIONALIZANTES',
                2 => 'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
                3 => 'ELECTRÓNICA INDUSTRIAL',
                4 => 'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
                5 => 'MÁQUINAS ELÉCTRICAS',
                6 => 'SISTEMAS MECÁNICOS',
                7 => 'Laboratorio de Metrología y Control de Calidad',
                8 => 'Mantenimiento Mecánico',
                9 => 'Mantenimiento Edilicio',
                10 => 'Mantenimiento Eléctrico',
                11 => 'CAD CAM',
                12 => 'Desafío ECO',
                13 => 'Diseño de Procesos',
                14 => 'Motores de Combustión Interna 2',
                15 => 'Robótica Industrial',
                16 => 'CAD 3',
                17 => 'Centrales Eléctricas'
            ];
        default:
            return [];
    }
}

/**
 * Normalizar nombre de materia para comparación
 */
function normalizarNombreMateriaVerificacion($nombre) {
    $nombre = strtoupper($nombre);
    
    $reemplazos = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'DOMICILIARIAS' => 'DOMICILILARIAS',
        'ELECT.' => 'ELÉCTR.',
        'INST.' => 'INST',
        ' - ' => ' ',
        '  ' => ' '
    ];
    
    $nombre = str_replace(array_keys($reemplazos), array_values($reemplazos), $nombre);
    $nombre = preg_replace('/[^A-Z0-9\s]/', '', $nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    
    return trim($nombre);
}

try {
    $db = Database::getInstance();
    
    // Obtener mapeo de materias según el año
    $mapeoMaterias = obtenerMapeoMateriasVerificacion($anio);
    
    if (empty($mapeoMaterias)) {
        echo json_encode(['success' => false, 'message' => 'Mapeo de materias no disponible para este año']);
        exit;
    }
    
    // Obtener todas las materias del curso
    $materiasDisponibles = $db->fetchAll(
        "SELECT mp.id, m.nombre, m.codigo, mp.profesor_id, mp.profesor_id_2, mp.profesor_id_3,
                u1.apellido as prof1_apellido, u1.nombre as prof1_nombre,
                u2.apellido as prof2_apellido, u2.nombre as prof2_nombre,
                u3.apellido as prof3_apellido, u3.nombre as prof3_nombre
         FROM materias_por_curso mp 
         JOIN materias m ON mp.materia_id = m.id 
         LEFT JOIN usuarios u1 ON mp.profesor_id = u1.id
         LEFT JOIN usuarios u2 ON mp.profesor_id_2 = u2.id
         LEFT JOIN usuarios u3 ON mp.profesor_id_3 = u3.id
         WHERE mp.curso_id = ? 
         ORDER BY m.nombre",
        [$cursoId]
    );
    
    // Crear índice de materias por nombre para búsqueda rápida
    $indiceMaterias = [];
    foreach ($materiasDisponibles as $materia) {
        $nombreNormalizado = normalizarNombreMateriaVerificacion($materia['nombre']);
        $indiceMaterias[$nombreNormalizado] = $materia;
        
        if (!empty($materia['codigo'])) {
            $indiceMaterias[strtoupper($materia['codigo'])] = $materia;
        }
    }
    
    // Verificar cada materia del mapeo
    $materiasConProfesor = [];
    $materiasSinProfesor = [];
    $materiasNoEncontradas = [];
    
    foreach ($mapeoMaterias as $numero => $nombreMateria) {
        $nombreNormalizado = normalizarNombreMateriaVerificacion($nombreMateria);
        
        if (isset($indiceMaterias[$nombreNormalizado])) {
            $materia = $indiceMaterias[$nombreNormalizado];
            
            // Verificar si tiene al menos un profesor asignado
            if (!empty($materia['profesor_id']) || !empty($materia['profesor_id_2']) || !empty($materia['profesor_id_3'])) {
                $profesores = [];
                if (!empty($materia['profesor_id'])) {
                    $profesores[] = $materia['prof1_apellido'] . ', ' . $materia['prof1_nombre'];
                }
                if (!empty($materia['profesor_id_2'])) {
                    $profesores[] = $materia['prof2_apellido'] . ', ' . $materia['prof2_nombre'];
                }
                if (!empty($materia['profesor_id_3'])) {
                    $profesores[] = $materia['prof3_apellido'] . ', ' . $materia['prof3_nombre'];
                }
                
                $materiasConProfesor[] = [
                    'numero' => $numero,
                    'nombre' => $materia['nombre'],
                    'profesores' => $profesores
                ];
            } else {
                $materiasSinProfesor[] = [
                    'numero' => $numero,
                    'nombre' => $materia['nombre']
                ];
            }
        } else {
            $materiasNoEncontradas[] = [
                'numero' => $numero,
                'nombre' => $nombreMateria
            ];
        }
    }
    
    // Preparar respuesta
    $totalMaterias = count($mapeoMaterias);
    $totalConProfesor = count($materiasConProfesor);
    $totalSinProfesor = count($materiasSinProfesor);
    $totalNoEncontradas = count($materiasNoEncontradas);
    
    // Crear lista de materias problemáticas
    $materiasProblematicas = [];
    
    foreach ($materiasSinProfesor as $materia) {
        $materiasProblematicas[] = "({$materia['numero']}) {$materia['nombre']} - SIN PROFESOR";
    }
    
    foreach ($materiasNoEncontradas as $materia) {
        $materiasProblematicas[] = "({$materia['numero']}) {$materia['nombre']} - NO ENCONTRADA EN EL CURSO";
    }
    
    echo json_encode([
        'success' => true,
        'total_materias' => $totalMaterias,
        'total_con_profesor' => $totalConProfesor,
        'total_sin_profesor' => $totalSinProfesor + $totalNoEncontradas,
        'materias_sin_profesor' => $materiasProblematicas,
        'detalle' => [
            'con_profesor' => $materiasConProfesor,
            'sin_profesor' => $materiasSinProfesor,
            'no_encontradas' => $materiasNoEncontradas
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>