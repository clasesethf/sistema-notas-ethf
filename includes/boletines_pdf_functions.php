<?php
/**
 * boletines_pdf_functions.php
 * Funciones y clases para la generación de PDFs de boletines RITE
 * Extraído de generar_boletin_pdf.php para uso en AJAX
 */

// Funciones de conversión de texto
function convertirMayusculasConTildes($texto) {
    $mapeo = array(
        'á' => 'Á', 'à' => 'À', 'ä' => 'Ä', 'â' => 'Â',
        'é' => 'É', 'è' => 'È', 'ë' => 'Ë', 'ê' => 'Ê',
        'í' => 'Í', 'ì' => 'Ì', 'ï' => 'Ï', 'î' => 'Î',
        'ó' => 'Ó', 'ò' => 'Ò', 'ö' => 'Ö', 'ô' => 'Ô',
        'ú' => 'Ú', 'ù' => 'Ù', 'ü' => 'Ü', 'û' => 'Û',
        'ñ' => 'Ñ', 'ç' => 'Ç'
    );
    
    $textoMayuscula = strtoupper($texto);
    
    foreach ($mapeo as $minuscula => $mayuscula) {
        $textoMayuscula = str_replace($minuscula, $mayuscula, $textoMayuscula);
    }
    
    return $textoMayuscula;
}

function normalizarNombreMateria($nombreMateria) {
    $nombreUpper = strtoupper(trim($nombreMateria));
    
    $reglasNormalizacion = [
        // CONSTRUCCIÓN DE CIUDADANÍA
        '/^CONSTR\.?\s*DE\s*CIUD\.?\s*-?\s*.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONSTRUCCION\s*DE\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        '/^CONST\.?\s*CIUDADANIA.*$/i' => 'CONSTRUCCIÓN DE CIUDADANÍA',
        
        // MATERIAS TÉCNICAS de 4° año
        '/^DIBUJO\s*TECNOLOGICO$/i' => 'DIBUJO TECNOLÓGICO',
        '/^MAQUINAS\s*ELECTRICAS\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        '/^MAQ\.?\s*ELEC\.?\s*Y\s*AUTOMATISMOS$/i' => 'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
        
        // MATERIAS de 5° año
        '/^ANALISIS\s*MATEMATICO$/i' => 'ANÁLISIS MATEMÁTICO',
        '/^MECANICA\s*Y\s*MECANISMOS$/i' => 'MECÁNICA Y MECANISMOS',
        '/^RESISTENCIA\s*Y\s*ENSAYO\s*DE\s*MATERIALES$/i' => 'RESISTENCIA Y ENSAYO DE MATERIALES',
        '/^POLITICA\s*Y\s*CIUDADANIA$/i' => 'POLÍTICA Y CIUDADANÍA',
        
        // MATERIAS de 6° año
        '/^TERMO\.?\s*Y\s*MAQ\.?\s*TÉRMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^TERMODINAMICA\s*Y\s*MAQUINAS\s*TERMICAS.*$/i' => 'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
        '/^SIST\.?\s*MECÁNICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^SISTEMAS\s*MECANICOS.*$/i' => 'SISTEMAS MECÁNICOS',
        '/^LAB\.?\s*DE\s*MED\.?\s*ELÉCTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^LABORATORIO\s*DE\s*MEDICIONES\s*ELECTRICAS.*$/i' => 'LABORATORIO DE MEDICIONES ELÉCTRICAS',
        '/^DERECHOS\s*DEL\s*TRABAJO$/i' => 'DERECHOS DEL TRABAJO',
        
        // MATERIAS COMUNES
        '/^PRACTICAS\s*DEL\s*LENGUAJE$/i' => 'PRÁCTICAS DEL LENGUAJE',
        '/^EDUCACION\s*FISICA$/i' => 'EDUCACIÓN FÍSICA',
        '/^EDUCACION\s*ARTISTICA$/i' => 'EDUCACIÓN ARTÍSTICA',
        '/^FISICO\s*QUIMICA$/i' => 'FÍSICO QUÍMICA',
        '/^MATEMATICA$/i' => 'MATEMÁTICA',
        '/^MATEMATICA\s*APLICADA$/i' => 'MATEMÁTICA APLICADA',
        '/^GEOGRAFIA$/i' => 'GEOGRAFÍA',
        '/^BIOLOGIA$/i' => 'BIOLOGÍA',
        '/^HISTORIA$/i' => 'HISTORIA',
        '/^INGLES$/i' => 'INGLÉS',
        '/^LITERATURA$/i' => 'LITERATURA',
        '/^FILOSOFIA$/i' => 'FILOSOFÍA',
        '/^FISICA$/i' => 'FÍSICA',
        '/^QUIMICA$/i' => 'QUÍMICA',
    ];
    
    foreach ($reglasNormalizacion as $patron => $reemplazo) {
        if (preg_match($patron, $nombreUpper)) {
            return $reemplazo;
        }
    }
    
    return convertirMayusculasConTildes($nombreMateria);
}

// Funciones de datos
function obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId) {
    try {
        return $db->fetchAll(
            "SELECT materia_liberada_id 
             FROM materias_recursado 
             WHERE estudiante_id = ? AND ciclo_lectivo_id = ? AND materia_liberada_id IS NOT NULL",
            [$estudianteId, $cicloLectivoId]
        );
    } catch (Exception $e) {
        error_log("Error al obtener materias liberadas: " . $e->getMessage());
        return [];
    }
}

function tieneAlgunDato($item, $esGrupo = false) {
    if ($esGrupo) {
        $cal = $item['calificaciones_calculadas'];
        return !empty($cal['valoracion_preliminar_1c']) || 
               !empty($cal['calificacion_1c']) || 
               !empty($cal['valoracion_preliminar_2c']) || 
               !empty($cal['calificacion_2c']) || 
               !empty($cal['intensificacion_1c']) || 
               !empty($cal['intensificacion_diciembre']) || 
               !empty($cal['intensificacion_febrero']) || 
               !empty($cal['calificacion_final']) ||
               !empty($cal['observaciones']);
    } else {
        return !empty(trim($item['valoracion_preliminar_1c'] ?? '')) || 
               !empty($item['calificacion_1c']) || 
               !empty(trim($item['valoracion_preliminar_2c'] ?? '')) || 
               !empty($item['calificacion_2c']) || 
               !empty($item['intensificacion_1c']) || 
               !empty($item['intensificacion_diciembre']) || 
               !empty($item['intensificacion_febrero']) || 
               !empty($item['calificacion_final']) ||
               !empty($item['observaciones']);
    }
}

function calcularValoracionesFaltantesMateriasIndividuales($materia) {
    $esING = (strtoupper($materia['codigo'] ?? '') === 'ING' || 
              strtoupper($materia['materia_codigo'] ?? '') === 'ING' || 
              strpos(strtoupper($materia['nombre'] ?? ''), 'INGLÉS') !== false);
    
    $valoracion1c_existente = isset($materia['valoracion_preliminar_1c']) ? trim($materia['valoracion_preliminar_1c']) : '';
    $valoracion2c_existente = isset($materia['valoracion_preliminar_2c']) ? trim($materia['valoracion_preliminar_2c']) : '';
    
    $valoracion1cEsValida = in_array($valoracion1c_existente, ['TEA', 'TEP', 'TED']);
    $valoracion2cEsValida = in_array($valoracion2c_existente, ['TEA', 'TEP', 'TED']);
    
    if (!$valoracion1cEsValida) {
        if (!empty($materia['calificacion_1c']) && is_numeric($materia['calificacion_1c'])) {
            $calificacion1c = intval($materia['calificacion_1c']);
            if ($calificacion1c >= 7) {
                $materia['valoracion_preliminar_1c'] = 'TEA';
            } elseif ($calificacion1c >= 4) {
                $materia['valoracion_preliminar_1c'] = 'TEP';
            } else {
                $materia['valoracion_preliminar_1c'] = 'TED';
            }
        }
    }
    
    if (!$valoracion2cEsValida) {
        if (!empty($materia['calificacion_2c']) && is_numeric($materia['calificacion_2c'])) {
            $calificacion2c = intval($materia['calificacion_2c']);
            if ($calificacion2c >= 7) {
                $materia['valoracion_preliminar_2c'] = 'TEA';
            } elseif ($calificacion2c >= 4) {
                $materia['valoracion_preliminar_2c'] = 'TEP';
            } else {
                $materia['valoracion_preliminar_2c'] = 'TED';
            }
        }
    }
    
    return $materia;
}

function obtenerCalificacionesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio) {
    try {
        $resultado = [];
        
        // Obtener materias liberadas
        $materiasLiberadas = obtenerMateriasLiberadas($db, $estudianteId, $cicloLectivoId);
        $materiasLiberadasIds = array_column($materiasLiberadas, 'materia_liberada_id');
        
        // Obtener materias individuales
        $materiasIndividuales = $db->fetchAll(
            "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo, 
                    curso_materia.anio as materia_anio, mp.id as materia_curso_id,
                    COALESCE(c.valoracion_preliminar_1c, c.valoracion_1bim) as valoracion_preliminar_1c,
                    COALESCE(c.valoracion_preliminar_2c, c.valoracion_3bim) as valoracion_preliminar_2c,
                    c.calificacion_1c, c.calificacion_2c, c.calificacion_final,
                    c.intensificacion_1c, c.intensificacion_diciembre, c.intensificacion_febrero,
                    c.observaciones, c.tipo_cursada, m.id as materia_id
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos curso_materia ON mp.curso_id = curso_materia.id
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
             AND (c.tipo_cursada IS NULL OR c.tipo_cursada IN ('C', 'R'))
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        foreach ($materiasIndividuales as $materia) {
            // Verificar si es una materia liberada individual
            $materia['es_liberada'] = in_array($materia['materia_curso_id'], $materiasLiberadasIds);
            $materia['es_grupo'] = false;
            $materia['nombre'] = $materia['materia_nombre'];
            $materia['codigo'] = $materia['materia_codigo'];
            
            $materia = calcularValoracionesFaltantesMateriasIndividuales($materia);
            $resultado[] = $materia;
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        error_log("ERROR en obtenerCalificacionesCombinadas: " . $e->getMessage());
        throw new Exception("Error al obtener calificaciones combinadas: " . $e->getMessage());
    }
}

function obtenerValoracionesBimestralesCombinadas($db, $estudianteId, $cicloLectivoId, $cursoAnio, $bimestre) {
    try {
        $campo_valoracion = 'valoracion_' . $bimestre . 'bim';
        $campo_desempeno = 'desempeno_' . $bimestre . 'bim';
        $campo_observaciones = 'observaciones_' . $bimestre . 'bim';
        
        $resultado = [];
        
        // Obtener materias individuales
        $materiasIndividuales = $db->fetchAll(
            "SELECT c.$campo_valoracion as valoracion_bimestral,
                    c.$campo_desempeno as desempeno_bimestral,
                    c.$campo_observaciones as observaciones_bimestrales,
                    m.nombre as materia_nombre, m.codigo as materia_codigo,
                    mp.id as materia_curso_id
             FROM calificaciones c
             JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
             JOIN materias m ON mp.materia_id = m.id
             WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ? 
             ORDER BY m.nombre",
            [$estudianteId, $cicloLectivoId]
        );
        
        foreach ($materiasIndividuales as $materia) {
            if (!empty(trim($materia['valoracion_bimestral']))) {
                $materia['es_grupo'] = false;
                $materia['nombre'] = $materia['materia_nombre'];
                $materia['codigo'] = $materia['materia_codigo'];
                $resultado[] = $materia;
            }
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        throw new Exception("Error al obtener valoraciones bimestrales combinadas: " . $e->getMessage());
    }
}

// Funciones de ordenamiento
function obtenerOrdenPersonalizadoMaterias($anio) {
    $ordenPorAnio = [
        1 => [
            'PRÁCTICAS DEL LENGUAJE',
            'CIENCIAS SOCIALES', 
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN FÍSICA',
            'EDUCACIÓN ARTÍSTICA',
            'INGLÉS',
            'MATEMÁTICA',
            'CIENCIAS NATURALES',
            'LENGUAJES TECNOLÓGICOS',
            'SISTEMAS TECNOLÓGICOS',
            'PROCEDIMIENTOS TÉCNICOS'
        ],
        2 => [
            'BIOLOGÍA',
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN ARTÍSTICA', 
            'EDUCACIÓN FÍSICA',
            'FÍSICO QUÍMICA',
            'GEOGRAFÍA',
            'HISTORIA',
            'INGLÉS',
            'MATEMÁTICA',
            'PRÁCTICAS DEL LENGUAJE',
            'PROCEDIMIENTOS TÉCNICOS',
            'LENGUAJES TECNOLÓGICOS',
            'SISTEMAS TECNOLÓGICOS'
        ],
        3 => [
            'BIOLOGÍA',
            'CONSTRUCCIÓN DE CIUDADANÍA',
            'EDUCACIÓN ARTÍSTICA',
            'EDUCACIÓN FÍSICA',
            'FÍSICO QUÍMICA',
            'GEOGRAFÍA', 
            'HISTORIA',
            'INGLÉS',
            'MATEMÁTICA',
            'PRÁCTICAS DEL LENGUAJE',
            'PROCEDIMIENTOS TÉCNICOS',
            'LENGUAJES TECNOLÓGICOS', 
            'SISTEMAS TECNOLÓGICOS'
        ],
        4 => [
            'LITERATURA',
            'INGLÉS',
            'EDUCACIÓN FÍSICA',
            'SALUD Y ADOLESCENCIA',
            'HISTORIA',
            'GEOGRAFÍA',
            'MATEMÁTICA',
            'FÍSICA',
            'QUÍMICA',
            'CONOCIMIENTO DE LOS MATERIALES',
            'DIBUJO TECNOLÓGICO',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        5 => [
            'LITERATURA',
            'INGLÉS',
            'EDUCACIÓN FÍSICA',
            'POLÍTICA Y CIUDADANÍA',
            'HISTORIA',
            'GEOGRAFÍA',
            'ANÁLISIS MATEMÁTICO',
            'MECÁNICA Y MECANISMOS',
            'ELECTROTECNIA',
            'RESISTENCIA Y ENSAYO DE MATERIALES',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        6 => [
            'LITERATURA',
            'INGLÉS', 
            'EDUCACIÓN FÍSICA',
            'FILOSOFÍA',
            'ARTE',
            'MATEMÁTICA APLICADA',
            'TERMODINÁMICA Y MÁQUINAS TÉRMICAS',
            'ELECTROTECNIA',
            'SISTEMAS MECÁNICOS',
            'DERECHOS DEL TRABAJO',
            'LABORATORIO DE MEDICIONES ELÉCTRICAS',
            'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS',
            'DISEÑO Y PROCESAMIENTO MECÁNICO',
            'INSTALACIONES Y APLICACIONES DE LA ENERGÍA'
        ],
        7 => [
            'PRÁCTICAS PROFESIONALIZANTES',
            'EMPRENDIMIENTOS PRODUCTIVOS Y DESARROLLO LOCAL',
            'ELECTRÓNICA INDUSTRIAL',
            'SEGURIDAD, HIGIENE Y PROTECCIÓN DEL MEDIO AMBIENTE',
            'MÁQUINAS ELÉCTRICAS',
            'SISTEMAS MECÁNICOS',
            'LABORATORIO DE METROLOGÍA Y CONTROL DE CALIDAD', 
            'MANTENIMIENTO Y MONTAJE ELECTROMECÁNICO',
            'PROYECTO Y DISEÑO ELECTROMECÁNICO',
            'PROYECTO Y DISEÑO DE INSTALACIONES ELÉCTRICAS'
        ]
    ];
    
    return $ordenPorAnio[$anio] ?? [];
}

function sonNombresEquivalentes($nombre1, $nombre2) {
    $nombre1Normalizado = normalizarNombreMateria($nombre1);
    $nombre2Normalizado = normalizarNombreMateria($nombre2);
    
    if ($nombre1Normalizado === $nombre2Normalizado) {
        return true;
    }
    
    $nombre1 = trim(strtoupper($nombre1));
    $nombre2 = trim(strtoupper($nombre2));
    
    if ($nombre1 === $nombre2) {
        return true;
    }
    
    // Mapeo de equivalencias
    $equivalencias = [
        'CONSTRUCCIÓN DE CIUDADANÍA' => [
            'CONSTR. DE CIUD. - MADERAS', 
            'CONSTR. DE CIUD. - METALES', 
            'CONSTR. DE CIUD. - ELECTRICIDAD', 
            'CONSTRUCCION DE CIUDADANIA',
            'CONST. CIUDADANIA',
            'CONSTR. DE CIUDADANIA'
        ],
        'FÍSICO QUÍMICA' => ['FISICO QUIMICA', 'FÍSICO-QUÍMICA', 'FISICOQUIMICA'],
        'PRÁCTICAS DEL LENGUAJE' => ['PRACTICAS DEL LENGUAJE', 'PRACTICAS LENGUAJE', 'LENGUA'],
        'EDUCACIÓN FÍSICA' => ['EDUCACION FISICA', 'ED. FISICA', 'ED FISICA'],
        'EDUCACIÓN ARTÍSTICA' => ['EDUCACION ARTISTICA', 'ED. ARTISTICA', 'ED ARTISTICA'],
        'MÁQUINAS ELÉCTRICAS Y AUTOMATISMOS' => [
            'MAQUINAS ELECTRICAS Y AUTOMATISMOS', 
            'MAQ. ELEC. Y AUTOMATISMOS', 
            'MAQUINAS ELEC. Y AUTOMATISMOS'
        ]
    ];
    
    foreach ($equivalencias as $principal => $variantes) {
        if ($nombre1 === $principal || in_array($nombre1, $variantes)) {
            if ($nombre2 === $principal || in_array($nombre2, $variantes)) {
                return true;
            }
        }
    }
    
    if (strpos($nombre1, $nombre2) !== false || strpos($nombre2, $nombre1) !== false) {
        return true;
    }
    
    return false;
}

function ordenarGrupoMaterias($materias, $ordenPersonalizado) {
    if (empty($ordenPersonalizado)) {
        usort($materias, function($a, $b) {
            $nombreA = $a['es_grupo'] ? $a['nombre'] : $a['materia_nombre'];
            $nombreB = $b['es_grupo'] ? $b['nombre'] : $b['materia_nombre'];
            return strcasecmp($nombreA, $nombreB);
        });
        return $materias;
    }
    
    $posiciones = array_flip(array_map('strtoupper', $ordenPersonalizado));
    
    $materiasConOrden = [];
    $materiasSinOrden = [];
    
    foreach ($materias as $item) {
        $nombreMateria = strtoupper($item['es_grupo'] ? $item['nombre'] : $item['materia_nombre']);
        
        $encontrado = false;
        $posicionEncontrada = null;
        
        if (isset($posiciones[$nombreMateria])) {
            $encontrado = true;
            $posicionEncontrada = $posiciones[$nombreMateria];
        } else {
            foreach ($posiciones as $nombreOrden => $posicion) {
                if (sonNombresEquivalentes($nombreMateria, $nombreOrden)) {
                    $encontrado = true;
                    $posicionEncontrada = $posicion;
                    break;
                }
            }
        }
        
        if ($encontrado) {
            $materiasConOrden[] = [
                'item' => $item,
                'posicion' => $posicionEncontrada
            ];
        } else {
            $materiasSinOrden[] = $item;
        }
    }
    
    usort($materiasConOrden, function($a, $b) {
        return $a['posicion'] <=> $b['posicion'];
    });
    
    usort($materiasSinOrden, function($a, $b) {
        $nombreA = $a['es_grupo'] ? $a['nombre'] : $a['materia_nombre'];
        $nombreB = $b['es_grupo'] ? $b['nombre'] : $b['materia_nombre'];
        return strcasecmp($nombreA, $nombreB);
    });
    
    $resultado = [];
    
    foreach ($materiasConOrden as $materiaConOrden) {
        $resultado[] = $materiaConOrden['item'];
    }
    
    foreach ($materiasSinOrden as $materiaSinOrden) {
        $resultado[] = $materiaSinOrden;
    }
    
    return $resultado;
}

function esMateriaPorRecursar($item, $anioEstudiante) {
    if ($item['es_grupo']) {
        return false;
    } else {
        $anioMateria = $item['materia_anio'] ?? $anioEstudiante;
        return ($anioEstudiante > $anioMateria);
    }
}

function ordenarMateriasPersonalizado($calificacionesCombinadas, $anioEstudiante) {
    $ordenPersonalizado = obtenerOrdenPersonalizadoMaterias($anioEstudiante);
    
    $materiasActuales = [];
    $materiasRecursando = [];
    
    foreach ($calificacionesCombinadas as $item) {
        if (esMateriaPorRecursar($item, $anioEstudiante)) {
            $materiasRecursando[] = $item;
        } else {
            $materiasActuales[] = $item;
        }
    }
    
    $materiasActualesOrdenadas = ordenarGrupoMaterias($materiasActuales, $ordenPersonalizado);
    
    usort($materiasRecursando, function($a, $b) {
        $anioA = $a['materia_anio'] ?? 0;
        $anioB = $b['materia_anio'] ?? 0;
        
        if ($anioA !== $anioB) {
            return $anioA <=> $anioB;
        }
        
        $nombreA = $a['es_grupo'] ? $a['nombre'] : $a['materia_nombre'];
        $nombreB = $b['es_grupo'] ? $b['nombre'] : $b['materia_nombre'];
        return strcasecmp($nombreA, $nombreB);
    });
    
    return array_merge($materiasActualesOrdenadas, $materiasRecursando);
}

// Clase BoletinPDF
class BoletinPDF extends FPDF_UTF8 {
    private $tipoBoletín;
    private $bimestre;
    private $cuatrimestre;
    private $materiasLiberadasIds;
    
    public function __construct($tipoBoletín = 'cuatrimestre', $bimestre = 1, $cuatrimestre = 1, $materiasLiberadasIds = []) {
        parent::__construct('L', 'mm', 'A4');
        $this->tipoBoletín = $tipoBoletín;
        $this->bimestre = $bimestre;
        $this->cuatrimestre = $cuatrimestre;
        $this->materiasLiberadasIds = $materiasLiberadasIds;
        
        $this->SetMargins(10, 5, 10);
        $this->SetAutoPageBreak(true, 5);
    }
    
    function Header() {
        $anchoPagina = $this->GetPageWidth();
        $logoAncho = 16;
        $logoAlto = 16;
        
        if (file_exists('assets/img/logo.png')) {
            $this->Image('assets/img/logo.png', 10, 8, $logoAncho, $logoAlto);
        }
        
        $inicioTextoX = 5 + $logoAncho + 2;
        $anchoTextoDisponible = $anchoPagina - $inicioTextoX - 5;
        
        $this->SetXY($inicioTextoX, 5);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($anchoTextoDisponible, 7, 'ESCUELA TÉCNICA HENRY FORD', 0, 1, 'C');
        
        $this->SetX($inicioTextoX);
        $this->SetFont('Arial', 'B', 11);
        if ($this->tipoBoletín === 'cuatrimestre') {
            $this->Cell($anchoTextoDisponible, 7, 'REGISTRO INSTITUCIONAL DE TRAYECTORIAS EDUCATIVAS (RITE)', 0, 1, 'C');
        } else {
            $bimestreTexto = ($this->bimestre == 1) ? '1er' : '3er';
            $this->Cell($anchoTextoDisponible, 7, 'BOLETÍN DE VALORACIONES BIMESTRALES' , 0, 1, 'C');
        }
        
        $this->Ln(4);
    }

    function TablaCalificacionesCombinadas($calificacionesCombinadas, $datosEstudiante) {
        $calificacionesCombinadas = ordenarMateriasPersonalizado($calificacionesCombinadas, $datosEstudiante['curso_anio']);
        
        // Cabeceras
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 7);
        
        // Primera fila de cabeceras
        $this->Cell(10, 7, 'TIPO', 1, 0, 'C', true);
        $this->Cell(75, 7, 'MATERIAS', 1, 0, 'C', true);
        $this->Cell(10, 7, 'AÑO', 1, 0, 'C', true);
        $this->Cell(30, 7, '1° CUATRIMESTRE', 1, 0, 'C', true);
        $this->Cell(50, 7, '2° CUATRIMESTRE', 1, 0, 'C', true);
        $this->Cell(30, 7, 'INTENSIFICACIÓN', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(45, 7, 'OBSERVACIONES', 1, 0, 'C', true);
        $this->Ln();
        
        // Segunda fila de cabeceras
        $this->Cell(10, 7, '(C-R)', 1, 0, 'C', true);
        $this->Cell(75, 7, '', 1, 0, 'C', true);
        $this->Cell(10, 7, '', 1, 0, 'C', true);
        $this->Cell(15, 7, '1° VAL. PR.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(15, 7, '2° VAL. PR.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(20, 7, 'INT. 1° CUAT.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'DIC.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'FEB.', 1, 0, 'C', true);
        $this->Cell(15, 7, 'FINAL', 1, 0, 'C', true);
        $this->Cell(45, 7, '', 1, 0, 'C', true);
        $this->Ln();
        
        $this->SetFont('Arial', '', 7);
        
        // Datos
        $fill = false;
        foreach($calificacionesCombinadas as $item) {
            
            $esLiberada = false;
            if ($item['es_grupo']) {
                $esLiberada = isset($item['tiene_materias_liberadas']) && $item['tiene_materias_liberadas'];
            } else {
                $esLiberada = isset($item['es_liberada']) && $item['es_liberada'];
                if (!isset($item['es_liberada']) && isset($item['materia_curso_id'])) {
                    $esLiberada = in_array($item['materia_curso_id'], $this->materiasLiberadasIds);
                }
            }
            
            // Colores de fila
            if ($esLiberada) {
                $colorFilaR = 255; $colorFilaG = 255; $colorFilaB = 150;
            } elseif ($fill) {
                $colorFilaR = 224; $colorFilaG = 235; $colorFilaB = 255;
            } else {
                $colorFilaR = 255; $colorFilaG = 255; $colorFilaB = 255;
            }
            
            if ($item['es_grupo']) {
                // Procesamiento de grupos (simplificado)
                $cal = $item['calificaciones_calculadas'];
                $tipoCursada = $esLiberada ? '' : 'C';
                
                $anioItem = $item['anio_curso'] ?? $datosEstudiante['curso_anio'] ?? '?';
                $nombreItem = isset($item['nombre']) ? normalizarNombreMateria($item['nombre']) : 'Grupo sin nombre';
                
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
                
                $this->Cell(10, 6, $tipoCursada, 1, 0, 'C', true);
                $this->Cell(75, 6, $nombreItem, 1, 0, 'L', true);
                $this->Cell(10, 6, $anioItem, 1, 0, 'C', true);
                
                // Resto de columnas (simplificado)
                $this->Cell(15, 6, !empty($cal['valoracion_preliminar_1c']) ? $cal['valoracion_preliminar_1c'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($cal['calificacion_1c']) ? $cal['calificacion_1c'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($cal['valoracion_preliminar_2c']) ? $cal['valoracion_preliminar_2c'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($cal['calificacion_2c']) ? $cal['calificacion_2c'] : '-', 1, 0, 'C', true);
                $this->Cell(20, 6, !empty($cal['intensificacion_1c']) ? $cal['intensificacion_1c'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($cal['intensificacion_diciembre']) ? $cal['intensificacion_diciembre'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($cal['intensificacion_febrero']) ? $cal['intensificacion_febrero'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($cal['calificacion_final']) ? $cal['calificacion_final'] : '-', 1, 0, 'C', true);
                
                $observaciones = $esLiberada ? 'Se pospone la cursada de la materia' : (!empty($cal['observaciones']) ? $cal['observaciones'] : '-');
                $this->Cell(45, 6, $observaciones, 1, 0, 'L', true);
                
            } else {
                // Procesamiento de materias individuales
                $anioEstudiante = $datosEstudiante['curso_anio'];
                $anioMateria = $item['materia_anio'] ?? $anioEstudiante ?? '?';
                
                $tipoCursada = $esLiberada ? '' : (($anioEstudiante > $anioMateria) ? 'R' : 'C');
                $nombreItem = isset($item['materia_nombre']) ? normalizarNombreMateria($item['materia_nombre']) : 'Materia sin nombre';
                
                $this->SetFillColor($colorFilaR, $colorFilaG, $colorFilaB);
                $this->SetTextColor(0, 0, 0);
                
                $this->Cell(10, 6, $tipoCursada, 1, 0, 'C', true);
                $this->Cell(75, 6, $nombreItem, 1, 0, 'L', true);
                $this->Cell(10, 6, $anioMateria, 1, 0, 'C', true);
                
                // Valoraciones y calificaciones
                $val1c = trim($item['valoracion_preliminar_1c'] ?? '');
                $cal1c = $item['calificacion_1c'];
                $val2c = trim($item['valoracion_preliminar_2c'] ?? '');
                $cal2c = $item['calificacion_2c'];
                
                $this->Cell(15, 6, (!empty($val1c) && $val1c !== '') ? $val1c : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, (!empty($cal1c) && is_numeric($cal1c)) ? $cal1c : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, (!empty($val2c) && $val2c !== '') ? $val2c : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, (!empty($cal2c) && is_numeric($cal2c)) ? $cal2c : '-', 1, 0, 'C', true);
                
                // Intensificaciones
                $this->Cell(20, 6, !empty($item['intensificacion_1c']) ? $item['intensificacion_1c'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($item['intensificacion_diciembre']) ? $item['intensificacion_diciembre'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($item['intensificacion_febrero']) ? $item['intensificacion_febrero'] : '-', 1, 0, 'C', true);
                $this->Cell(15, 6, !empty($item['calificacion_final']) ? $item['calificacion_final'] : '-', 1, 0, 'C', true);
                
                // Observaciones
                $observaciones = $esLiberada ? 'Se pospone la cursada de la materia' : (!empty($item['observaciones']) ? $item['observaciones'] : '-');
                $this->Cell(45, 6, $observaciones, 1, 0, 'L', true);
            }
            
            $this->Ln();
            $fill = !$fill;
        }
    }

    function TablaMateriasesPendientes($materiasAgrupadasPendientes) {
        if (empty($materiasAgrupadasPendientes)) {
            return;
        }
        
        // Cabeceras
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 7);
        
        // Primera fila de cabeceras
        $this->Cell(85, 7, 'MATERIAS', 1, 0, 'C', true);
        $this->Cell(10, 7, 'AÑO', 1, 0, 'C', true);
        $this->Cell(50, 7, 'PERÍODOS DE INTENSIFICACIÓN', 1, 0, 'C', true);
        $this->Cell(15, 7, 'CALIF.', 1, 0, 'C', true);
        $this->Cell(20, 7, 'ESTADO', 1, 0, 'C', true);
        $this->Cell(85, 7, 'OBSERVACIONES', 1, 0, 'C', true);
        $this->Ln();
        
        // Segunda fila de cabeceras
        $this->Cell(85, 7, '', 1, 0, 'C', true);
        $this->Cell(10, 7, '', 1, 0, 'C', true);
        $this->Cell(10, 7, 'MAR', 1, 0, 'C', true);
        $this->Cell(10, 7, 'JUL', 1, 0, 'C', true);
        $this->Cell(10, 7, 'AGO', 1, 0, 'C', true);
        $this->Cell(10, 7, 'DIC', 1, 0, 'C', true);
        $this->Cell(10, 7, 'FEB', 1, 0, 'C', true);
        $this->Cell(15, 7, 'FINAL', 1, 0, 'C', true);
        $this->Cell(20, 7, '', 1, 0, 'C', true);
        $this->Cell(85, 7, '', 1, 0, 'C', true);
        $this->Ln();
        
        // Datos (versión simplificada)
        $this->SetFont('Arial', '', 7);
        $fill = false;
        
        foreach($materiasAgrupadasPendientes as $item) {
            if ($fill) {
                $this->SetFillColor(224, 235, 255);
            } else {
                $this->SetFillColor(255, 255, 255);
            }
            $this->SetTextColor(0, 0, 0);
            
            if ($item['es_grupo']) {
                $nombreCompleto = !empty($item['grupo_nombre']) ? normalizarNombreMateria($item['grupo_nombre']) : 'Grupo sin nombre';
                $anioMostrar = !empty($item['curso_anio']) ? $item['curso_anio'] : '?';
            } else {
                $materia = $item['materias'][0];
                $nombreCompleto = !empty($materia['materia_nombre']) ? normalizarNombreMateria($materia['materia_nombre']) : 'Materia sin nombre';
                $anioMostrar = !empty($materia['curso_anio']) ? $materia['curso_anio'] : '?';
            }
            
            $this->Cell(85, 6, $nombreCompleto, 1, 0, 'L', true);
            $this->Cell(10, 6, $anioMostrar, 1, 0, 'C', true);
            
            // Períodos simplificados
            $this->Cell(10, 6, '-', 1, 0, 'C', true);
            $this->Cell(10, 6, '-', 1, 0, 'C', true);
            $this->Cell(10, 6, '-', 1, 0, 'C', true);
            $this->Cell(10, 6, '-', 1, 0, 'C', true);
            $this->Cell(10, 6, '-', 1, 0, 'C', true);
            
            $this->Cell(15, 6, '-', 1, 0, 'C', true);
            $this->Cell(20, 6, '', 1, 0, 'C', true);
            $this->Cell(85, 6, '', 1, 0, 'L', true);
            
            $this->Ln();
            $fill = !$fill;
        }
        
        // Leyenda
        $this->Ln(2);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, 'Códigos: AA=Aprobó y Acreditó, CCA=Continúa Con Avances, CSA=Continúa Sin Avances', 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }
    
    function TablaValoracionesBimestralesCombinadas($valoracionesCombinadas, $bimestre) {
        $bimestreTexto = ($bimestre == 1) ? '1er' : '3er';
        
        // Título
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'Valoraciones del ' . $bimestreTexto . ' Bimestre', 0, 1, 'C');
        $this->Ln(5);
        
        // Cabeceras
        $this->SetFillColor(54, 162, 235);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 9);
        
        $this->Cell(70, 8, 'MATERIAS / GRUPOS', 1, 0, 'C', true);
        $this->Cell(30, 8, 'VALORACIÓN', 1, 0, 'C', true);
        $this->Cell(30, 8, 'DESEMPEÑO', 1, 0, 'C', true);
        $this->Cell(120, 8, 'OBSERVACIONES', 1, 0, 'C', true);
        $this->Ln();
        
        // Datos
        $this->SetFillColor(240, 248, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 8);
        
        $contador = 0;
        foreach($valoracionesCombinadas as $item) {
            
            if ($item['es_grupo']) {
                $val = $item['valoracion_consolidada'];
                if (!empty($val['valoracion_bimestral']) && trim($val['valoracion_bimestral']) !== '') {
                    $contador++;
                    
                    if ($val['valoracion_bimestral'] === 'TEA') {
                        $this->SetFillColor(220, 255, 220);
                    } elseif ($val['valoracion_bimestral'] === 'TEP') {
                        $this->SetFillColor(255, 255, 220);
                    } elseif ($val['valoracion_bimestral'] === 'TED') {
                        $this->SetFillColor(255, 220, 220);
                    } else {
                        $this->SetFillColor(240, 248, 255);
                    }
                    
                    $nombreItem = normalizarNombreMateria($item['nombre']);
                    $this->Cell(70, 6, $nombreItem, 1, 0, 'L', true);
                    $this->Cell(30, 6, $val['valoracion_bimestral'], 1, 0, 'C', true);
                    $this->Cell(30, 6, !empty($val['desempeno_bimestral']) ? $val['desempeno_bimestral'] : '-', 1, 0, 'C', true);
                    
                    $observaciones = !empty($val['observaciones_bimestrales']) ? $val['observaciones_bimestrales'] : '-';
                    if (strlen($observaciones) > 120) {
                        $observaciones = substr($observaciones, 0, 117) . '...';
                    }
                    $this->Cell(120, 6, $observaciones, 1, 0, 'L', true);
                    $this->Ln();
                }
            } else {
                if (!empty($item['valoracion_bimestral']) && trim($item['valoracion_bimestral']) !== '') {
                    $contador++;
                    
                    if ($item['valoracion_bimestral'] === 'TEA') {
                        $this->SetFillColor(220, 255, 220);
                    } elseif ($item['valoracion_bimestral'] === 'TEP') {
                        $this->SetFillColor(255, 255, 220);
                    } elseif ($item['valoracion_bimestral'] === 'TED') {
                        $this->SetFillColor(255, 220, 220);
                    } else {
                        $this->SetFillColor(240, 248, 255);
                    }
                    
                    $nombreItem = normalizarNombreMateria($item['nombre']);
                    
                    $this->Cell(70, 6, $nombreItem, 1, 0, 'L', true);
                    $this->Cell(30, 6, $item['valoracion_bimestral'], 1, 0, 'C', true);
                    $this->Cell(30, 6, !empty($item['desempeno_bimestral']) ? $item['desempeno_bimestral'] : '-', 1, 0, 'C', true);
                    
                    $observaciones = !empty($item['observaciones_bimestrales']) ? $item['observaciones_bimestrales'] : '-';
                    if (strlen($observaciones) > 120) {
                        $observaciones = substr($observaciones, 0, 117) . '...';
                    }
                    $this->Cell(120, 6, $observaciones, 1, 0, 'L', true);
                    $this->Ln();
                }
            }
        }
        
        if ($contador === 0) {
            $this->SetFillColor(255, 255, 255);
            $this->Cell(250, 6, 'No hay valoraciones registradas para este bimestre', 1, 0, 'C', true);
            $this->Ln();
        }
    }
    
    function TablaAsistenciasConFirmas($asistenciasPorCuatrimestre) {
        $inicioY = $this->GetY();
        
        // Tabla de asistencias
        $this->SetFont('Arial', 'B', 10);
        
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 7);
        
        $this->Cell(25, 7, '', 1, 0, 'C', true);
        $this->Cell(30, 7, '1° CUATRIM.', 1, 0, 'C', true);
        $this->Cell(30, 7, '2° CUATRIM.', 1, 0, 'C', true);
        $this->Cell(25, 7, 'TOTAL', 1, 0, 'C', true);
        $this->Ln();
        
        // Preparar datos
        $datos1c = isset($asistenciasPorCuatrimestre[1]) ? $asistenciasPorCuatrimestre[1] : [
            'total_dias' => 0, 'ausentes' => 0, 'medias_faltas' => 0, 'justificadas' => 0
        ];
        $datos2c = isset($asistenciasPorCuatrimestre[2]) ? $asistenciasPorCuatrimestre[2] : [
            'total_dias' => 0, 'ausentes' => 0, 'medias_faltas' => 0, 'justificadas' => 0
        ];
        
        // Calcular totales
        $totalDias = ($datos1c['total_dias'] ?? 0) + ($datos2c['total_dias'] ?? 0);
        $totalAusentes = ($datos1c['ausentes'] ?? 0) + ($datos2c['ausentes'] ?? 0);
        $totalMediasFaltas = ($datos1c['medias_faltas'] ?? 0) + ($datos2c['medias_faltas'] ?? 0);
        $totalJustificadas = ($datos1c['justificadas'] ?? 0) + ($datos2c['justificadas'] ?? 0);
        $totalInasistencias = $totalAusentes + ($totalMediasFaltas * 0.5);
        
        // FILA: INASISTENCIAS
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(25, 6, 'INASISTENCIAS', 1, 0, 'L', true);
        
        $this->SetFillColor(240, 248, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 7);
        
        $ausentes1 = $datos1c['ausentes'] ?? 0;
        $mediasFaltas1 = ($datos1c['medias_faltas'] ?? 0) * 0.5;
        $inasistencias1c = $ausentes1 + $mediasFaltas1;
        
        $ausentes2 = $datos2c['ausentes'] ?? 0;
        $mediasFaltas2 = ($datos2c['medias_faltas'] ?? 0) * 0.5;
        $inasistencias2c = $ausentes2 + $mediasFaltas2;
        
        $this->Cell(30, 6, $inasistencias1c, 1, 0, 'C', true);
        $this->Cell(30, 6, $inasistencias2c, 1, 0, 'C', true);
        
        $this->SetFillColor(220, 235, 255);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(25, 6, $totalInasistencias, 1, 0, 'C', true);
        
        // FIRMAS (lado derecho)
        $margenEntreTablaYFirmas = 15;
        $xFirmas = 25 + 30 + 30 + 25 + $margenEntreTablaYFirmas;
        
        $yFilaInasistencias = $this->GetY();
        
        $this->SetXY($xFirmas, $yFilaInasistencias - 5);
        
        $this->SetX($xFirmas);
        $this->Ln(2);
        
        $anchoDisponibleFirmas = $this->GetPageWidth() - $xFirmas - 10;
        $anchoFirmaPorColumna = $anchoDisponibleFirmas / 3;
        
        // Líneas para firmas
        $this->SetX($xFirmas);
        $this->SetFont('Arial', '', 8);
        $this->Cell($anchoFirmaPorColumna, 3, '____________________', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 3, '____________________', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 3, '____________________', 0, 1, 'C');
        
        $this->Ln(2);
        
        // Información de firmas
        $this->SetX($xFirmas);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($anchoFirmaPorColumna, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Estudiante', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Responsable', 0, 1, 'C');
        
        $this->SetX($xFirmas);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($anchoFirmaPorColumna, 4, 'DIRECTORA', 0, 0, 'C');
        $this->SetFont('Arial', '', 7);
        $this->Cell($anchoFirmaPorColumna, 4, '', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
        
        $this->SetY($yFilaInasistencias + 6);
        $this->Ln(2);
    }
    
    function TablaAsistenciasBimestreConFirmas($asistenciasBimestre, $bimestre) {
        if (!$asistenciasBimestre) {
            $this->FirmasUnicamenteBimestre();
            return;
        }
        
        $bimestreTexto = ($bimestre == 1) ? '1er' : '3er';
        $cuatrimestreCorrespondiente = ($bimestre == 1) ? 1 : 2;
        
        $inicioY = $this->GetY();
        
        // Título
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 8, 'Asistencia - ' . $cuatrimestreCorrespondiente . '° Cuatrimestre (' . $bimestreTexto . ' Bimestre)', 0, 1, 'L');
        
        // Cabeceras
        $this->SetFillColor(73, 173, 245);
        $this->SetTextColor(255);
        $this->SetDrawColor(128, 128, 128);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 7);
        
        $this->Cell(35, 7, 'Período', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Días Reg.', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Ausentes', 1, 0, 'C', true);
        $this->Cell(20, 7, 'M. Faltas', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Justif.', 1, 0, 'C', true);
        $this->Cell(20, 7, 'Total F.', 1, 0, 'C', true);
        $this->Cell(20, 7, '% Asist.', 1, 0, 'C', true);
        $this->Ln();
        
        // Datos
        $this->SetFillColor(240, 248, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 7);
        
        $totalDias = isset($asistenciasBimestre['total_dias']) ? $asistenciasBimestre['total_dias'] : 0;
        $ausentes = isset($asistenciasBimestre['ausentes']) ? $asistenciasBimestre['ausentes'] : 0;
        $mediasFaltas = isset($asistenciasBimestre['medias_faltas']) ? $asistenciasBimestre['medias_faltas'] : 0;
        $justificadas = isset($asistenciasBimestre['justificadas']) ? $asistenciasBimestre['justificadas'] : 0;
        
        $totalFaltas = $ausentes + ($mediasFaltas * 0.5);
        $porcentajeAsistencia = $totalDias > 0 ? round((($totalDias - $totalFaltas) / $totalDias) * 100, 1) : 0;
        
        $yFilaDatos = $this->GetY();
        
        $this->Cell(35, 7, $cuatrimestreCorrespondiente . '° Cuatrimestre', 1, 0, 'L', true);
        $this->Cell(20, 7, $totalDias, 1, 0, 'C', true);
        $this->Cell(20, 7, $ausentes, 1, 0, 'C', true);
        $this->Cell(20, 7, $mediasFaltas, 1, 0, 'C', true);
        $this->Cell(20, 7, $justificadas, 1, 0, 'C', true);
        $this->Cell(20, 7, $totalFaltas, 1, 0, 'C', true);
        $this->Cell(20, 7, $porcentajeAsistencia . '%', 1, 0, 'C', true);
        
        // FIRMAS
        $margenEntreTablaYFirmas = 10;
        $xFirmas = 155 + $margenEntreTablaYFirmas;
        
        $this->SetXY($xFirmas, $inicioY);
        
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->Cell(0, 5, 'FIRMAS', 0, 1, 'L');
        
        $this->SetX($xFirmas);
        $this->Ln(5);
        
        $anchoDisponibleFirmas = $this->GetPageWidth() - $xFirmas - 10;
        $anchoFirmaPorColumna = $anchoDisponibleFirmas / 3;
        
        // Líneas para firmas
        $this->SetX($xFirmas);
        $this->SetFont('Arial', '', 8);
        $this->Cell($anchoFirmaPorColumna, 6, '________________', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 6, '________________', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 6, '________________', 0, 1, 'C');
        
        $this->Ln(2);
        
        // Información de firmas
        $this->SetX($xFirmas);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($anchoFirmaPorColumna, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Estudiante', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 4, 'Firma del Responsable', 0, 1, 'C');
        
        $this->SetX($xFirmas);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell($anchoFirmaPorColumna, 4, 'DIRECTORA', 0, 0, 'C');
        $this->SetFont('Arial', '', 7);
        $this->Cell($anchoFirmaPorColumna, 4, '', 0, 0, 'C');
        $this->Cell($anchoFirmaPorColumna, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
        
        $this->SetY($yFilaDatos + 7);
        
        // Observación sobre asistencia
        $this->Ln(3);
        $this->SetFont('Arial', '', 8);
        if ($porcentajeAsistencia < 75) {
            $this->SetTextColor(220, 53, 69);
            $this->Cell(155, 5, 'ATENCIÓN: El porcentaje de asistencia está por debajo del mínimo requerido (75%)', 0, 1, 'L');
        } elseif ($porcentajeAsistencia < 85) {
            $this->SetTextColor(255, 193, 7);
            $this->Cell(155, 5, 'ADVERTENCIA: El porcentaje de asistencia está cerca del límite mínimo', 0, 1, 'L');
        } else {
            $this->SetTextColor(40, 167, 69);
            $this->Cell(155, 5, 'El porcentaje de asistencia es satisfactorio', 0, 1, 'L');
        }
        $this->SetTextColor(0);
    }
    
    function FirmasUnicamenteBimestre() {
        $this->Ln(10);
        
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 8, 'FIRMAS', 0, 1, 'C');
        $this->Ln(5);
        
        $this->SetFont('Arial', '', 10);
        $anchoTotal = 90 * 3;
        $inicioX = ($this->GetPageWidth() - $anchoTotal) / 2;
        
        $this->SetX($inicioX);
        $this->Cell(90, 6, '________________________', 0, 0, 'C');
        $this->Cell(90, 6, '________________________', 0, 0, 'C');
        $this->Cell(90, 6, '________________________', 0, 1, 'C');
        
        $this->Ln(2);
        
        $this->SetX($inicioX);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(90, 4, 'Lic. SUSANA A. AMBROSONI', 0, 0, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(90, 4, 'Firma del Estudiante', 0, 0, 'C');
        $this->Cell(90, 4, 'Firma del Responsable', 0, 1, 'C');
        
        $this->SetX($inicioX);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(90, 4, 'DIRECTORA', 0, 0, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(90, 4, '', 0, 0, 'C');
        $this->Cell(90, 4, '(Padre/Madre/Tutor)', 0, 1, 'C');
    }
}

if (!function_exists('limpiarNombreArchivo')) {
    function limpiarNombreArchivo($texto) {
        if (function_exists('transliterator_transliterate')) {
            $textoSinAcentos = transliterator_transliterate('Any-Latin; Latin-ASCII', $texto);
            return $textoSinAcentos;
        } else {
            $buscar = array('á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ');
            $reemplazar = array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N');
            return str_replace($buscar, $reemplazar, $texto);
        }
    }
}
?>
