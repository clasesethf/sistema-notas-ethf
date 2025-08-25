<?php
/**
 * importar.php - Importación de datos desde archivos CSV con formato especial
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
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
    $error = 'No hay un ciclo lectivo activo configurado en el sistema.';
} else {
    $cicloLectivoId = $cicloActivo['id'];
    $anioActivo = $cicloActivo['anio'];
}

// Inicializar variables
$successMessage = '';
$errorMessage = '';
$previewData = [];
$previewType = '';
$fileName = '';

// Procesar formulario de importación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_type'])) {
    $importType = $_POST['import_type'];
    
    // Verificar si se ha subido un archivo
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        
        if ($importType === 'alumnos') {
            // Leer y procesar archivo CSV con formato especial
            $processedData = procesarCSVAlumnos($file);
            
            if ($processedData['status'] === 'success') {
                $previewData = $processedData['data'];
                $previewType = $importType;
                
                // Si se ha enviado el formulario de confirmación, procesar la importación
                if (isset($_POST['confirm_import']) && $_POST['confirm_import'] === '1') {
                    $result = importarAlumnosEspecial($previewData, $db, $cicloLectivoId);
                    
                    if ($result['status'] === 'success') {
                        $successMessage = $result['message'];
                    } else {
                        $errorMessage = $result['message'];
                    }
                }
            } else {
                $errorMessage = $processedData['message'];
            }
        } else if ($importType === 'materias') {
            // Leer y procesar archivo CSV de materias con formato especial
            $processedData = procesarCSVMaterias($file);
            
            if ($processedData['status'] === 'success') {
                $previewData = $processedData['data'];
                $previewType = $importType;
                
                // Si se ha enviado el formulario de confirmación, procesar la importación
                if (isset($_POST['confirm_import']) && $_POST['confirm_import'] === '1') {
                    $result = importarMateriasEspecial($previewData, $db, $cicloLectivoId);
                    
                    if ($result['status'] === 'success') {
                        $successMessage = $result['message'];
                    } else {
                        $errorMessage = $result['message'];
                    }
                }
            } else {
                $errorMessage = $processedData['message'];
            }
        } else {
            $errorMessage = 'Tipo de importación no válido';
        }
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $errorMessage = 'Error al subir el archivo: ' . getUploadErrorMessage($_FILES['csv_file']['error']);
    }
}

/**
 * Función para procesar el CSV de alumnos con formato especial
 */
function procesarCSVAlumnos($file) {
    // Array para almacenar los datos procesados por curso
    $alumnos = [];
    
    // Abrir el archivo CSV
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Leer la primera línea (nombres de cursos)
        $headerLine = fgetcsv($handle, 0, ",");
        
        // Identificar las columnas de cada curso
        $cursos = [];
        $i = 0;
        while ($i < count($headerLine)) {
            if (strpos($headerLine[$i], 'AÑO') !== false) {
                $nombreCurso = trim($headerLine[$i]);
                $cursos[$i] = [
                    'nombre' => $nombreCurso,
                    'columnaNumero' => $i,
                    'columnaAlumno' => $i + 1,
                    'columnaDNI' => $i + 2
                ];
            }
            $i++;
        }
        
        // Saltar la segunda línea (cabeceras "Alumnos", "Matr", etc.)
        fgetcsv($handle, 0, ",");
        
        // Procesar las líneas de datos
        $lineNumber = 3; // Empezamos en la línea 3
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            // Procesar cada curso
            foreach ($cursos as $curso) {
                $numero = isset($data[$curso['columnaNumero']]) ? trim($data[$curso['columnaNumero']]) : '';
                $nombreCompleto = isset($data[$curso['columnaAlumno']]) ? trim($data[$curso['columnaAlumno']]) : '';
                $dni = isset($data[$curso['columnaDNI']]) ? trim($data[$curso['columnaDNI']]) : '';
                
                // Solo procesar si hay un nombre
                if (!empty($nombreCompleto) && is_numeric($numero)) {
                    // Añadir a la lista de alumnos
                    $alumnos[] = [
                        'numero' => $numero,
                        'nombre_completo' => $nombreCompleto,
                        'dni' => $dni,
                        'curso' => $curso['nombre']
                    ];
                }
            }
            $lineNumber++;
        }
        
        fclose($handle);
        
        if (count($alumnos) > 0) {
            return ['status' => 'success', 'data' => $alumnos];
        } else {
            return ['status' => 'error', 'message' => 'No se encontraron datos de alumnos en el archivo'];
        }
    } else {
        return ['status' => 'error', 'message' => 'Error al abrir el archivo'];
    }
}

/**
 * Función para procesar el CSV de materias con formato especial
 */
function procesarCSVMaterias($file) {
    // Array para almacenar los datos procesados por curso
    $materias = [];
    
    // Abrir el archivo CSV
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Leer la primera línea (nombres de cursos)
        $headerLine = fgetcsv($handle, 0, ",");
        
        // Identificar las columnas de cada curso
        $cursos = [];
        for ($i = 1; $i < count($headerLine); $i++) {
            if (!empty($headerLine[$i]) && strpos($headerLine[$i], 'AÑO') !== false) {
                $nombreCurso = trim($headerLine[$i]);
                $cursoColumna = $i;
                
                // Buscar las siguientes columnas (Materias, Profesor)
                $colMateria = $i + 1;
                $colProfesor = $i + 2;
                
                $cursos[] = [
                    'nombre' => $nombreCurso,
                    'col_curso' => $cursoColumna,
                    'col_materia' => $colMateria,
                    'col_profesor' => $colProfesor
                ];
                
                // Avanzar al siguiente curso (saltar 4 columnas)
                $i += 4;
            }
        }
        
        // Debug info
        error_log("Cursos detectados: " . print_r($cursos, true));
        
        // Leer la segunda línea (cabeceras)
        $headersLine = fgetcsv($handle, 0, ",");
        
        // Verificar si las cabeceras coinciden con nuestras expectativas
        foreach ($cursos as &$curso) {
            $colMateria = $curso['col_materia'];
            $colProfesor = $curso['col_profesor'];
            
            // Verificar que las columnas de materia y profesor correspondan a las cabeceras
            if (isset($headersLine[$colMateria]) && strtoupper(trim($headersLine[$colMateria])) === 'MATERIAS') {
                // Cabecera de materia correcta
                error_log("Cabecera 'Materias' encontrada en columna $colMateria para curso " . $curso['nombre']);
            } else {
                error_log("ADVERTENCIA: Cabecera 'Materias' no encontrada en columna $colMateria para curso " . $curso['nombre']);
            }
            
            if (isset($headersLine[$colProfesor]) && strtoupper(trim($headersLine[$colProfesor])) === 'PROFESOR') {
                // Cabecera de profesor correcta
                error_log("Cabecera 'Profesor' encontrada en columna $colProfesor para curso " . $curso['nombre']);
            } else {
                error_log("ADVERTENCIA: Cabecera 'Profesor' no encontrada en columna $colProfesor para curso " . $curso['nombre']);
            }
        }
        
        // Procesar las líneas de datos (empezando desde la tercera línea)
        $lineNumber = 3;
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            // Procesar solo líneas no vacías
            if (empty(array_filter($data, function($value) { return !empty(trim($value)); }))) {
                continue; // Saltar líneas completamente vacías
            }
            
            // Procesar cada curso definido
            foreach ($cursos as $curso) {
                $numeroMateria = isset($data[1]) && is_numeric(trim($data[1])) ? trim($data[1]) : '';
                $colMateria = $curso['col_materia'];
                $colProfesor = $curso['col_profesor'];
                
                // Verificar si hay datos en la columna de materia
                if (!empty($numeroMateria) && isset($data[$colMateria]) && !empty(trim($data[$colMateria]))) {
                    $nombreMateria = trim($data[$colMateria]);
                    $nombreProfesor = isset($data[$colProfesor]) ? trim($data[$colProfesor]) : '';
                    
                    // Generar código de materia
                    $codigoMateria = generarCodigoMateria($nombreMateria);
                    
                    // Añadir a la lista de materias
                    $materias[] = [
                        'numero' => $numeroMateria,
                        'nombre_materia' => $nombreMateria,
                        'codigo_materia' => $codigoMateria,
                        'nombre_profesor' => $nombreProfesor,
                        'curso' => $curso['nombre']
                    ];
                    
                    error_log("Materia detectada: Curso=" . $curso['nombre'] . ", Materia=$nombreMateria, Profesor=$nombreProfesor");
                }
            }
            
            $lineNumber++;
        }
        
        fclose($handle);
        
        error_log("Total de materias procesadas: " . count($materias));
        
        if (count($materias) > 0) {
            return ['status' => 'success', 'data' => $materias];
        } else {
            return ['status' => 'error', 'message' => 'No se encontraron datos de materias en el archivo. Verifica que el formato del CSV sea correcto.'];
        }
    } else {
        return ['status' => 'error', 'message' => 'Error al abrir el archivo'];
    }
}

/**
 * Función para generar un código de materia a partir de su nombre
 */
function generarCodigoMateria($nombreMateria) {
    $palabras = explode(' ', $nombreMateria);
    $codigo = '';
    
    // Tomar la primera letra de cada palabra, máximo 4 letras
    $count = 0;
    foreach ($palabras as $palabra) {
        if ($count >= 4) break;
        
        // Excluir algunas palabras como "de", "la", etc.
        if (strlen($palabra) > 2 && !in_array(strtolower($palabra), ['de', 'la', 'el', 'los', 'las', 'del'])) {
            $codigo .= strtoupper(substr($palabra, 0, 1));
            $count++;
        }
    }
    
    // Si el código es muy corto, añadir más letras de la primera palabra
    if (strlen($codigo) < 2 && isset($palabras[0])) {
        $codigo .= strtoupper(substr($palabras[0], 1, 3 - strlen($codigo)));
    }
    
    return $codigo;
}

/**
 * Función para importar alumnos con el formato especial procesado
 */
function importarAlumnosEspecial($alumnos, $db, $cicloLectivoId) {
    $totalImported = 0;
    $errors = [];
    
    try {
        // Iniciar transacción
        $db->transaction(function($db) use ($alumnos, $cicloLectivoId, &$totalImported, &$errors) {
            // Obtener todos los cursos del ciclo lectivo
            $cursos = $db->fetchAll("SELECT id, nombre, anio FROM cursos WHERE ciclo_lectivo_id = ?", [$cicloLectivoId]);
            $cursosMap = [];
            $aniosMap = [];
            
            foreach ($cursos as $curso) {
                $cursosMap[$curso['nombre']] = $curso['id'];
                $aniosMap[$curso['anio']] = $curso['id'];
            }
            
            // Crear cursos que no existan
            foreach ($alumnos as $alumno) {
                $cursoNombre = $alumno['curso'];
                
                // Si el curso no existe por nombre, buscar por año
                if (!isset($cursosMap[$cursoNombre])) {
                    // Extraer el año del nombre del curso (ej: "1° AÑO 2025" -> 1)
                    preg_match('/(\d+)[°|er|do|ro|to|vo|mo]/', $cursoNombre, $matches);
                    $anio = isset($matches[1]) ? intval($matches[1]) : 1;
                    
                    // Verificar si ya existe un curso con el mismo año
                    if (isset($aniosMap[$anio])) {
                        // Usar el curso existente con el mismo año
                        $cursosMap[$cursoNombre] = $aniosMap[$anio];
                    } else {
                        try {
                            // No existe un curso con ese año, intentar crearlo
                            $cursoId = $db->insert(
                                "INSERT INTO cursos (nombre, anio, ciclo_lectivo_id) VALUES (?, ?, ?)",
                                [$cursoNombre, $anio, $cicloLectivoId]
                            );
                            
                            $cursosMap[$cursoNombre] = $cursoId;
                            $aniosMap[$anio] = $cursoId;
                        } catch (Exception $e) {
                            // Si falla por restricción de unicidad, buscar de nuevo el curso
                            $cursoExistente = $db->fetchOne(
                                "SELECT id FROM cursos WHERE anio = ? AND ciclo_lectivo_id = ?",
                                [$anio, $cicloLectivoId]
                            );
                            
                            if ($cursoExistente) {
                                $cursosMap[$cursoNombre] = $cursoExistente['id'];
                                $aniosMap[$anio] = $cursoExistente['id'];
                            } else {
                                throw $e; // Si realmente no existe, propagar el error
                            }
                        }
                    }
                }
            }
            
            // Procesar cada alumno
            foreach ($alumnos as $index => $alumno) {
                try {
                    $numeroLista = $alumno['numero'];
                    $nombreCompleto = $alumno['nombre_completo'];
                    $dni = $alumno['dni'];
                    $cursoNombre = $alumno['curso'];
                    
                    // Verificar que el curso exista en el mapa
                    if (!isset($cursosMap[$cursoNombre])) {
                        $errors[] = "Alumno #$index: El curso '$cursoNombre' no existe y no se pudo crear";
                        continue;
                    }
                    
                    $cursoId = $cursosMap[$cursoNombre];
                    
                    // Dividir nombre completo en apellido y nombre
                    $nombrePartes = explode(',', $nombreCompleto);
                    if (count($nombrePartes) != 2) {
                        $errors[] = "Alumno #$index: Formato de nombre incorrecto. Debe ser 'Apellido, Nombre'";
                        continue;
                    }
                    
                    $apellido = trim($nombrePartes[0]);
                    $nombre = trim($nombrePartes[1]);
                    
                    // Verificar si el estudiante ya existe
                    $estudianteExistente = $db->fetchOne(
                        "SELECT id FROM usuarios WHERE dni = ? AND tipo = 'estudiante'",
                        [$dni]
                    );
                    
                    $estudianteId = null;
                    if (!$estudianteExistente) {
                        // Crear nuevo estudiante
                        $estudianteId = $db->insert(
                            "INSERT INTO usuarios (nombre, apellido, dni, contrasena, tipo) 
                             VALUES (?, ?, ?, ?, 'estudiante')",
                            [$nombre, $apellido, $dni, 'estudiante123']
                        );
                    } else {
                        $estudianteId = $estudianteExistente['id'];
                        
                        // Actualizar nombre y apellido por si han cambiado
                        $db->query(
                            "UPDATE usuarios SET nombre = ?, apellido = ? WHERE id = ?",
                            [$nombre, $apellido, $estudianteId]
                        );
                    }
                    
                    // Verificar si ya está matriculado
                    $matriculaExistente = $db->fetchOne(
                        "SELECT id FROM matriculas WHERE estudiante_id = ? AND curso_id = ?",
                        [$estudianteId, $cursoId]
                    );
                    
                    if (!$matriculaExistente) {
                        // Matricular al estudiante
                        $db->query(
                            "INSERT INTO matriculas (estudiante_id, curso_id, fecha_matriculacion, estado) 
                             VALUES (?, ?, CURRENT_DATE, 'activo')",
                            [$estudianteId, $cursoId]
                        );
                    }
                    
                    $totalImported++;
                } catch (Exception $e) {
                    $errors[] = "Alumno #$index: " . $e->getMessage();
                }
            }
        });
        
        // Preparar mensaje de resultado
        if ($totalImported > 0) {
            $message = "Se importaron $totalImported estudiantes correctamente.";
            if (count($errors) > 0) {
                $message .= " Se encontraron " . count($errors) . " errores:<br>" . implode("<br>", $errors);
                return ['status' => 'warning', 'message' => $message];
            }
            return ['status' => 'success', 'message' => $message];
        } else {
            return ['status' => 'error', 'message' => "No se pudo importar ningún estudiante. Errores:<br>" . implode("<br>", $errors)];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => "Error en la importación: " . $e->getMessage()];
    }
}

/**
 * Función para importar materias con el formato especial procesado
 */
function importarMateriasEspecial($materias, $db, $cicloLectivoId) {
    $totalImported = 0;
    $errors = [];
    
    try {
        // Debug: Mostrar datos que se van a procesar
        error_log("Materias a procesar: " . count($materias));
        
        // Iniciar transacción
        $db->transaction(function($db) use ($materias, $cicloLectivoId, &$totalImported, &$errors) {
            // Obtener todos los cursos del ciclo lectivo
            $cursos = $db->fetchAll("SELECT id, nombre, anio FROM cursos WHERE ciclo_lectivo_id = ?", [$cicloLectivoId]);
            $cursosMap = [];
            $aniosMap = [];
            
            foreach ($cursos as $curso) {
                $cursosMap[$curso['nombre']] = $curso['id'];
                $aniosMap[$curso['anio']] = $curso['id'];
            }
            
            // Debug: Cursos existentes
            error_log("Cursos existentes: " . count($cursosMap));
            
            // Crear cursos que no existan
            $cursosProcesados = [];
            foreach ($materias as $materia) {
                $cursoNombre = $materia['curso'];
                
                // Evitar procesar el mismo curso varias veces
                if (isset($cursosProcesados[$cursoNombre])) {
                    continue;
                }
                
                // Si el curso no existe por nombre, buscar por año
                if (!isset($cursosMap[$cursoNombre])) {
                    // Extraer el año del nombre del curso (ej: "1er AÑO" -> 1)
                    preg_match('/(\d+)[°|er|do|ro|to|vo|mo]/', $cursoNombre, $matches);
                    $anio = isset($matches[1]) ? intval($matches[1]) : 1;
                    
                    // Debug: Año extraído
                    error_log("Extrayendo año de '$cursoNombre': $anio");
                    
                    // Verificar si ya existe un curso con el mismo año
                    if (isset($aniosMap[$anio])) {
                        // Usar el curso existente con el mismo año
                        $cursosMap[$cursoNombre] = $aniosMap[$anio];
                        error_log("Usando curso existente para '$cursoNombre': " . $aniosMap[$anio]);
                    } else {
                        try {
                            // No existe un curso con ese año, intentar crearlo
                            $cursoId = $db->insert(
                                "INSERT INTO cursos (nombre, anio, ciclo_lectivo_id) VALUES (?, ?, ?)",
                                [$cursoNombre, $anio, $cicloLectivoId]
                            );
                            
                            $cursosMap[$cursoNombre] = $cursoId;
                            $aniosMap[$anio] = $cursoId;
                            error_log("Curso creado para '$cursoNombre': $cursoId");
                        } catch (Exception $e) {
                            // Si falla por restricción de unicidad, buscar de nuevo el curso
                            $cursoExistente = $db->fetchOne(
                                "SELECT id FROM cursos WHERE anio = ? AND ciclo_lectivo_id = ?",
                                [$anio, $cicloLectivoId]
                            );
                            
                            if ($cursoExistente) {
                                $cursosMap[$cursoNombre] = $cursoExistente['id'];
                                $aniosMap[$anio] = $cursoExistente['id'];
                                error_log("Curso existente encontrado después de error para '$cursoNombre': " . $cursoExistente['id']);
                            } else {
                                error_log("Error al crear curso '$cursoNombre': " . $e->getMessage());
                                throw $e; // Si realmente no existe, propagar el error
                            }
                        }
                    }
                }
                
                $cursosProcesados[$cursoNombre] = true;
            }
            
            // Procesar cada materia
            foreach ($materias as $index => $materia) {
                try {
                    $numero = $materia['numero'];
                    $nombreMateria = $materia['nombre_materia'];
                    $codigoMateria = $materia['codigo_materia'];
                    $nombreProfesor = $materia['nombre_profesor'];
                    $cursoNombre = $materia['curso'];
                    
                    // Debug: Procesando materia
                    error_log("Procesando materia #$index: $nombreMateria, Curso: $cursoNombre");
                    
                    // Verificar que el curso exista en el mapa
                    if (!isset($cursosMap[$cursoNombre])) {
                        $errors[] = "Materia #$index: El curso '$cursoNombre' no existe y no se pudo crear";
                        error_log("Error: Curso '$cursoNombre' no encontrado en el mapa");
                        continue;
                    }
                    
                    $cursoId = $cursosMap[$cursoNombre];
                    
                    // Verificar si la materia ya existe
                    $materiaExistente = $db->fetchOne(
                        "SELECT id FROM materias WHERE nombre = ?",
                        [$nombreMateria]
                    );
                    
                    $materiaId = null;
                    if (!$materiaExistente) {
                        // Crear nueva materia
                        $materiaId = $db->insert(
                            "INSERT INTO materias (nombre, codigo) VALUES (?, ?)",
                            [$nombreMateria, $codigoMateria]
                        );
                        error_log("Materia creada: $nombreMateria (ID: $materiaId)");
                    } else {
                        $materiaId = $materiaExistente['id'];
                        
                        // Actualizar código por si ha cambiado
                        $db->query(
                            "UPDATE materias SET codigo = ? WHERE id = ?",
                            [$codigoMateria, $materiaId]
                        );
                        error_log("Materia existente: $nombreMateria (ID: $materiaId)");
                    }
                    
                    // Procesar datos del profesor
                    $profesorId = null;
                    if (!empty($nombreProfesor)) {
                        // Limpiar el nombre del profesor (eliminar comillas)
                        $nombreProfesor = str_replace('"', '', $nombreProfesor);
                        
                        // Intentar primero con el formato "APELLIDO, Nombre"
                        $nombrePartes = explode(',', $nombreProfesor);
                        
                        if (count($nombrePartes) > 1) {
                            $profesorApellido = trim($nombrePartes[0]);
                            $profesorNombre = trim($nombrePartes[1]);
                        } else {
                            // Si no hay coma, intentar separar por espacio (APELLIDO Nombre)
                            $palabras = explode(' ', $nombreProfesor);
                            $profesorApellido = $palabras[0];
                            $profesorNombre = implode(' ', array_slice($palabras, 1));
                        }
                        
                        // Debug: Nombre de profesor procesado
                        error_log("Profesor procesado: Apellido='$profesorApellido', Nombre='$profesorNombre'");
                        
                        // Verificar si el profesor ya existe
                        $profesorExistente = $db->fetchOne(
                            "SELECT id FROM usuarios WHERE apellido = ? AND tipo = 'profesor'",
                            [$profesorApellido]
                        );
                        
                        if (!$profesorExistente) {
                            // Crear nuevo profesor
                            $profesorId = $db->insert(
                                "INSERT INTO usuarios (nombre, apellido, dni, contrasena, tipo) 
                                 VALUES (?, ?, ?, ?, 'profesor')",
                                [$profesorNombre, $profesorApellido, '00000000', 'profesor123']
                            );
                            error_log("Profesor creado: $profesorApellido, $profesorNombre (ID: $profesorId)");
                        } else {
                            $profesorId = $profesorExistente['id'];
                            
                            // Actualizar nombre por si ha cambiado
                            $db->query(
                                "UPDATE usuarios SET nombre = ? WHERE id = ?",
                                [$profesorNombre, $profesorId]
                            );
                            error_log("Profesor existente: $profesorApellido (ID: $profesorId)");
                        }
                    }
                    
                    // Verificar si la materia ya está asignada al curso
                    $materiaCursoExistente = $db->fetchOne(
                        "SELECT id FROM materias_por_curso WHERE materia_id = ? AND curso_id = ?",
                        [$materiaId, $cursoId]
                    );
                    
                    if (!$materiaCursoExistente) {
                        // Asignar materia al curso
                        $db->query(
                            "INSERT INTO materias_por_curso (materia_id, curso_id, profesor_id) VALUES (?, ?, ?)",
                            [$materiaId, $cursoId, $profesorId]
                        );
                        error_log("Materia asignada a curso: Materia $materiaId, Curso $cursoId, Profesor $profesorId");
                    } else {
                        // Actualizar profesor asignado
                        $db->query(
                            "UPDATE materias_por_curso SET profesor_id = ? WHERE id = ?",
                            [$profesorId, $materiaCursoExistente['id']]
                        );
                        error_log("Asignación existente actualizada: ID " . $materiaCursoExistente['id']);
                    }
                    
                    $totalImported++;
                } catch (Exception $e) {
                    $errors[] = "Materia #$index: " . $e->getMessage();
                    error_log("Error procesando materia #$index: " . $e->getMessage());
                }
            }
        });
        
        // Preparar mensaje de resultado
        if ($totalImported > 0) {
            $message = "Se importaron $totalImported materias correctamente.";
            if (count($errors) > 0) {
                $message .= " Se encontraron " . count($errors) . " errores:<br>" . implode("<br>", $errors);
                return ['status' => 'warning', 'message' => $message];
            }
            return ['status' => 'success', 'message' => $message];
        } else {
            return ['status' => 'error', 'message' => "No se pudo importar ninguna materia. Errores:<br>" . implode("<br>", $errors)];
        }
    } catch (Exception $e) {
        error_log("Error general en importación: " . $e->getMessage());
        return ['status' => 'error', 'message' => "Error en la importación: " . $e->getMessage()];
    }
}

/**
 * Función para obtener mensaje de error de subida de archivo
 */
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return 'El archivo excede el tamaño máximo permitido en php.ini';
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo excede el tamaño máximo permitido en el formulario';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subió parcialmente';
        case UPLOAD_ERR_NO_FILE:
            return 'No se seleccionó ningún archivo';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Falta la carpeta temporal';
        case UPLOAD_ERR_CANT_WRITE:
            return 'No se pudo escribir el archivo en el disco';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extensión PHP detuvo la subida del archivo';
        default:
            return 'Error desconocido';
    }
}

// Verificar si se necesita agregar la columna profesor_id a materias_por_curso
try {
    $result = $db->query("PRAGMA table_info(materias_por_curso)");
    $columns = $result->fetchAll();
    
    $hasProfesorIdColumn = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'profesor_id') {
            $hasProfesorIdColumn = true;
            break;
        }
    }
    
    if (!$hasProfesorIdColumn) {
        $db->query("ALTER TABLE materias_por_curso ADD COLUMN profesor_id INTEGER");
    }
} catch (Exception $e) {
    $errorMessage = "Error al verificar la estructura de la base de datos: " . $e->getMessage();
}
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Importar Datos</h5>
                </div>
                <div class="card-body">
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
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <?= $error ?>
                        </div>
                    <?php else: ?>
                        <?php if (empty($previewData)): ?>
                            <form method="POST" enctype="multipart/form-data" class="mb-4">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="import_type" class="form-label">Tipo de importación:</label>
                                        <select name="import_type" id="import_type" class="form-select" required>
                                            <option value="">-- Seleccione tipo --</option>
                                            <option value="alumnos">Alumnos</option>
                                            <option value="materias">Materias</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="csv_file" class="form-label">Archivo CSV:</label>
                                        <input type="file" name="csv_file" id="csv_file" class="form-control" required accept=".csv">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading">Instrucciones:</h6>
                                        <p class="mb-0">
                                            Suba su archivo CSV con el formato especial donde todas las columnas de los cursos están en una misma fila. El sistema procesará automáticamente los datos y los organizará por curso.
                                        </p>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload"></i> Subir y Previsualizar
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="mb-4">
                                <h5>Previsualización de datos: <?= htmlspecialchars($fileName) ?></h5>
                                <p class="text-muted">Se muestran los datos procesados del archivo. Confirme para proceder con la importación.</p>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped table-bordered">
                                        <thead class="table-light">
                                            <?php if ($previewType === 'alumnos'): ?>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Número</th>
                                                    <th>Apellido y Nombre</th>
                                                    <th>DNI</th>
                                                    <th>Curso</th>
                                                </tr>
                                            <?php elseif ($previewType === 'materias'): ?>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Número</th>
                                                    <th>Materia</th>
                                                    <th>Código</th>
                                                    <th>Profesor</th>
                                                    <th>Curso</th>
                                                </tr>
                                            <?php endif; ?>
                                        </thead>
                                        <tbody>
                                            <?php if ($previewType === 'alumnos'): ?>
                                                <?php foreach ($previewData as $index => $alumno): ?>
                                                    <?php if ($index < 30): // Mostrar solo los primeros 30 registros para no sobrecargar la página ?>
                                                    <tr>
                                                        <td><?= $index + 1 ?></td>
                                                        <td><?= htmlspecialchars($alumno['numero']) ?></td>
                                                        <td><?= htmlspecialchars($alumno['nombre_completo']) ?></td>
                                                        <td><?= htmlspecialchars($alumno['dni']) ?></td>
                                                        <td><?= htmlspecialchars($alumno['curso']) ?></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (count($previewData) > 30): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center">... y <?= count($previewData) - 30 ?> registros más</td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php elseif ($previewType === 'materias'): ?>
                                                <?php foreach ($previewData as $index => $materia): ?>
                                                    <?php if ($index < 30): // Mostrar solo los primeros 30 registros ?>
                                                    <tr>
                                                        <td><?= $index + 1 ?></td>
                                                        <td><?= htmlspecialchars($materia['numero']) ?></td>
                                                        <td><?= htmlspecialchars($materia['nombre_materia']) ?></td>
                                                        <td><?= htmlspecialchars($materia['codigo_materia']) ?></td>
                                                        <td><?= htmlspecialchars($materia['nombre_profesor']) ?></td>
                                                        <td><?= htmlspecialchars($materia['curso']) ?></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <?php if (count($previewData) > 30): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center">... y <?= count($previewData) - 30 ?> registros más</td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <p>Total de registros encontrados: <strong><?= count($previewData) ?></strong></p>
                                </div>
                                
                                <form method="POST" enctype="multipart/form-data" class="mt-3">
                                    <input type="hidden" name="import_type" value="<?= htmlspecialchars($previewType) ?>">
                                    <input type="hidden" name="confirm_import" value="1">
                                    
                                    <!-- Re-adjuntar el archivo (no se puede mantener entre requests) -->
                                    <div class="mb-3">
                                        <label for="csv_file" class="form-label">Vuelva a seleccionar el mismo archivo:</label>
                                        <input type="file" name="csv_file" id="csv_file" class="form-control" required accept=".csv">
                                    </div>
                                    
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-check-circle"></i> Confirmar Importación
                                        </button>
                                        
                                        <a href="importar.php" class="btn btn-secondary">
                                            <i class="bi bi-x-circle"></i> Cancelar
                                        </a>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title">Información sobre el formato CSV especial</h5>
                </div>
                <div class="card-body">
                    <p>El sistema está configurado para procesar archivos CSV con un formato especial donde todos los cursos están en columnas paralelas, como el siguiente ejemplo:</p>
                    <pre class="bg-light p-2">
1er AÑO 2025,,,,2do AÑO 2025,,,,3er AÑO 2025,,
,Alumnos,Matr,,,Alumnos,Matr,,,Alumnos,Matr
1,"ACOSTA ANAYA, Alma Sofia",2087,,1,"ALTOMARE, Sebastian",2054,,1,"ALMARAZ IGLESIAS, Lola",2021
2,"ALITTA, Renzo",2088,,2,"BALLESTER, Estanislao Felipe",2055,,2,"ANTONIO, Felix",2022
3,"ALONSO HESSEL, Benjamin",2089,,3,"BESSONE, Santiago",2056,,3,"BOGAO, Joaquin Ezequiel",2023</pre>
                    
                    <div class="alert alert-info mt-3">
                        <h6 class="alert-heading">Recomendaciones para el archivo CSV:</h6>
                        <ul class="mb-0">
                            <li>Use codificación UTF-8 para mantener correctamente los caracteres especiales</li>
                            <li>Verifique que los nombres de los cursos incluyan la palabra "AÑO" para su correcta identificación</li>
                            <li>Para los alumnos, asegúrese de que el formato de nombre sea "APELLIDO, Nombre"</li>
                            <li>Para las materias, si el CSV no incluye códigos, el sistema los generará automáticamente</li>
                        </ul>
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