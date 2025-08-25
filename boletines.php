<?php
/**
 * boletines.php - Generación de boletines (RITE) CON SISTEMA DE EMAILS DUAL
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * 
 * NUEVA FUNCIONALIDAD: Sistema de envío a email principal y secundario
 * - Envío individual por estudiante (ambos emails)
 * - Envío masivo por curso (todos los emails configurados)
 * - Historial de envíos
 * - Gestión de emails familiares duales
 */

// Incluir config.php para tener acceso a la clase Database
require_once 'config.php';

// Inicializar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir EmailManager si existe
$emailManager = null;
$emailError = null;
try {
    if (file_exists('EmailManager.php')) {
        require_once 'EmailManager.php';
        $emailManager = new EmailManager();
    }
} catch (Exception $e) {
    $emailError = "Error al inicializar sistema de emails: " . $e->getMessage();
}

// Obtener conexión a la base de datos
$db = Database::getInstance();

// Obtener ciclo lectivo activo
try {
    $cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
    
    if (!$cicloActivo) {
        $cicloLectivoId = 0;
        $anioActivo = date('Y');
    } else {
        $cicloLectivoId = $cicloActivo['id'];
        $anioActivo = $cicloActivo['anio'];
    }
} catch (Exception $e) {
    $cicloLectivoId = 0;
    $anioActivo = date('Y');
}

// Obtener cursos
$cursos = [];
try {
    if ($cicloLectivoId > 0) {
        $cursos = $db->fetchAll("SELECT * FROM cursos WHERE ciclo_lectivo_id = ? ORDER BY anio", [$cicloLectivoId]);
    }
} catch (Exception $e) {
    // Mantener array vacío
}

// Procesar selección
$cursoSeleccionado = isset($_GET['curso']) ? intval($_GET['curso']) : null;
$estudianteSeleccionado = isset($_GET['estudiante']) ? intval($_GET['estudiante']) : null;
$tipoBoletinSeleccionado = isset($_GET['tipo']) ? $_GET['tipo'] : 'cuatrimestre'; // 'cuatrimestre' o 'bimestre'
$bimestreSeleccionado = isset($_GET['bimestre']) ? intval($_GET['bimestre']) : 1; // 1 o 3
$cuatrimestreSeleccionado = isset($_GET['cuatrimestre']) ? intval($_GET['cuatrimestre']) : 1; // Solo para cuatrimestral

// Variables para almacenar datos
$estudiantes = [];
$datosEstudiante = null;
$materiasCursadas = [];
$materiasRecursadas = [];
$materiasPendientes = [];
$estadisticasPendientes = [];
$asistencias = [];
$asistenciasPorCuatrimestre = [];

// Función para verificar emails del estudiante
function verificarEmailsEstudiante($estudiante) {
    $emails = [];
    if (!empty($estudiante['email']) && filter_var($estudiante['email'], FILTER_VALIDATE_EMAIL)) {
        $emails[] = $estudiante['email'];
    }
    if (!empty($estudiante['email_secundario']) && filter_var($estudiante['email_secundario'], FILTER_VALIDATE_EMAIL)) {
        $emails[] = $estudiante['email_secundario'];
    }
    return $emails;
}

// Función para obtener estadísticas de emails del curso
function obtenerEstadisticasEmailsCurso($estudiantes) {
    $total = count($estudiantes);
    $con_email_principal = 0;
    $con_email_secundario = 0;
    $con_ambos_emails = 0;
    $con_algun_email = 0;
    
    foreach ($estudiantes as $estudiante) {
        $tiene_principal = !empty($estudiante['email']) && filter_var($estudiante['email'], FILTER_VALIDATE_EMAIL);
        $tiene_secundario = !empty($estudiante['email_secundario']) && filter_var($estudiante['email_secundario'], FILTER_VALIDATE_EMAIL);
        
        if ($tiene_principal) $con_email_principal++;
        if ($tiene_secundario) $con_email_secundario++;
        if ($tiene_principal && $tiene_secundario) $con_ambos_emails++;
        if ($tiene_principal || $tiene_secundario) $con_algun_email++;
    }
    
    return [
        'total' => $total,
        'con_email_principal' => $con_email_principal,
        'con_email_secundario' => $con_email_secundario,
        'con_ambos_emails' => $con_ambos_emails,
        'con_algun_email' => $con_algun_email,
        'sin_email' => $total - $con_algun_email,
        'porcentaje' => $total > 0 ? round(($con_algun_email / $total) * 100, 1) : 0
    ];
}

// Procesar envío de email individual
if (isset($_POST['enviar_email_individual']) && $estudianteSeleccionado && $emailManager) {
    try {
        $resultado = $emailManager->enviarBoletinIndividual(
            $estudianteSeleccionado, 
            $cursoSeleccionado, 
            $cicloLectivoId, 
            $tipoBoletinSeleccionado, 
            ($tipoBoletinSeleccionado === 'cuatrimestre') ? $cuatrimestreSeleccionado : $bimestreSeleccionado,
            $_SESSION['user_id']
        );
        
        if ($resultado['success']) {
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = $resultado['message'];
            $_SESSION['message_type'] = 'danger';
        }
        
        // Usar JavaScript para redireccionar en lugar de header()
        $params = http_build_query([
            'curso' => $cursoSeleccionado,
            'estudiante' => $estudianteSeleccionado,
            'tipo' => $tipoBoletinSeleccionado,
            'bimestre' => $bimestreSeleccionado,
            'cuatrimestre' => $cuatrimestreSeleccionado
        ]);
        echo "<script>window.location.href = 'boletines.php?$params';</script>";
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al enviar email: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Procesar envío masivo
if (isset($_POST['enviar_emails_masivo']) && $cursoSeleccionado && $emailManager) {
    try {
        $resultados = $emailManager->enviarBoletinesMasivos(
            $cursoSeleccionado, 
            $cicloLectivoId, 
            $tipoBoletinSeleccionado, 
            ($tipoBoletinSeleccionado === 'cuatrimestre') ? $cuatrimestreSeleccionado : $bimestreSeleccionado,
            $_SESSION['user_id']
        );
        
        $_SESSION['message'] = "Envío masivo completado. Exitosos: {$resultados['exitosos']}, Errores: {$resultados['errores']}";
        $_SESSION['message_type'] = ($resultados['errores'] === 0) ? 'success' : 'warning';
        $_SESSION['detalles_envio'] = $resultados['detalles'];
        
        // Usar JavaScript para redireccionar en lugar de header()
        $params = http_build_query([
            'curso' => $cursoSeleccionado,
            'tipo' => $tipoBoletinSeleccionado,
            'bimestre' => $bimestreSeleccionado,
            'cuatrimestre' => $cuatrimestreSeleccionado
        ]);
        echo "<script>window.location.href = 'boletines.php?$params';</script>";
        exit;
        
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error en envío masivo: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Procesar registro de notificación (mantener lógica original)
if (isset($_POST['registrar_notificacion']) && $estudianteSeleccionado) {
    try {
        $firmaResponsable = isset($_POST['firma_responsable']) ? 1 : 0;
        $firmaEstudiante = isset($_POST['firma_estudiante']) ? 1 : 0;
        $observaciones = $_POST['observaciones'] ?? '';
        
        // Para bimestrales, usar bimestre; para cuatrimestrales, usar cuatrimestre
        $periodo = ($tipoBoletinSeleccionado === 'bimestre') ? $bimestreSeleccionado : $cuatrimestreSeleccionado;
        $tipoPeriodo = ($tipoBoletinSeleccionado === 'bimestre') ? 'bimestre' : 'cuatrimestre';
        
        $notificacionExistente = $db->fetchOne(
            "SELECT id FROM notificaciones 
             WHERE estudiante_id = ? AND ciclo_lectivo_id = ? AND cuatrimestre = ? AND tipo_periodo = ?",
            [$estudianteSeleccionado, $cicloLectivoId, $periodo, $tipoPeriodo]
        );
        
        if ($notificacionExistente) {
            $db->query(
                "UPDATE notificaciones 
                 SET fecha_notificacion = CURRENT_TIMESTAMP, 
                     firma_responsable = ?, 
                     firma_estudiante = ?, 
                     observaciones = ?
                 WHERE id = ?",
                [$firmaResponsable, $firmaEstudiante, $observaciones, $notificacionExistente['id']]
            );
        } else {
            $db->query(
                "INSERT INTO notificaciones 
                 (estudiante_id, ciclo_lectivo_id, cuatrimestre, tipo_periodo,
                  fecha_notificacion, firma_responsable, firma_estudiante, observaciones)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)",
                [$estudianteSeleccionado, $cicloLectivoId, $periodo, $tipoPeriodo,
                 $firmaResponsable, $firmaEstudiante, $observaciones]
            );
        }
        
        $_SESSION['message'] = 'Notificación registrada correctamente.';
        $_SESSION['message_type'] = 'success';
        
        // Usar JavaScript para redireccionar en lugar de header()
        $params = http_build_query([
            'curso' => $cursoSeleccionado,
            'estudiante' => $estudianteSeleccionado,
            'tipo' => $tipoBoletinSeleccionado,
            'bimestre' => $bimestreSeleccionado,
            'cuatrimestre' => $cuatrimestreSeleccionado
        ]);
        
        echo "<script>window.location.href = 'boletines.php?$params';</script>";
        exit;
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error al registrar la notificación: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
}

// Incluir el encabezado DESPUÉS del procesamiento
require_once 'header.php';

// Mostrar errores de configuración si los hay
if ($cicloLectivoId == 0) {
    echo '<div class="alert alert-danger">No hay un ciclo lectivo activo configurado en el sistema.</div>';
}

// Si se seleccionó un curso
if ($cursoSeleccionado) {
    try {
        // Obtener estudiantes del curso con ambos emails
        $estudiantes = $db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni, u.email, u.email_secundario
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
             ORDER BY u.apellido, u.nombre",
            [$cursoSeleccionado]
        );
        
        // Si se seleccionó un estudiante específico
        if ($estudianteSeleccionado) {
            // Obtener datos del estudiante con ambos emails
            $datosEstudiante = $db->fetchOne(
                "SELECT u.id, u.nombre, u.apellido, u.dni, u.direccion, u.telefono, u.email, u.email_secundario,
                        c.nombre as curso_nombre, c.anio as curso_anio
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 JOIN cursos c ON m.curso_id = c.id
                 WHERE u.id = ? AND m.curso_id = ?",
                [$estudianteSeleccionado, $cursoSeleccionado]
            );
            
            if ($datosEstudiante) {
                // Obtener materias pendientes de intensificación
                try {
                    $materiasPendientes = $db->fetchAll(
                        "SELECT 
                            mpi.*,
                            m.nombre as materia_nombre,
                            m.codigo as materia_codigo,
                            c.anio as curso_anio_pendiente,
                            c.nombre as curso_nombre_pendiente,
                            p1.apellido as profesor_apellido,
                            p1.nombre as profesor_nombre
                         FROM materias_pendientes_intensificacion mpi
                         JOIN materias_por_curso mpc ON mpi.materia_curso_id = mpc.id
                         JOIN materias m ON mpc.materia_id = m.id
                         JOIN cursos c ON mpc.curso_id = c.id
                         LEFT JOIN usuarios p1 ON mpc.profesor_id = p1.id
                         WHERE mpi.estudiante_id = ? AND mpi.ciclo_lectivo_id = ? AND mpi.estado = 'activo'
                         ORDER BY c.anio, m.nombre",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    
                    // Analizar el estado de las materias pendientes
                    $estadisticasPendientes = [
                        'total' => count($materiasPendientes),
                        'aprobadas' => 0,
                        'en_proceso' => 0,
                        'sin_evaluar' => 0
                    ];
                    
                    foreach ($materiasPendientes as $pendiente) {
                        if (!empty($pendiente['calificacion_final'])) {
                            if ($pendiente['calificacion_final'] >= 4) {
                                $estadisticasPendientes['aprobadas']++;
                            } else {
                                $estadisticasPendientes['en_proceso']++;
                            }
                        } else {
                            $estadisticasPendientes['sin_evaluar']++;
                        }
                    }
                    
                } catch (Exception $e) {
                    $materiasPendientes = [];
                    $estadisticasPendientes = ['total' => 0, 'aprobadas' => 0, 'en_proceso' => 0, 'sin_evaluar' => 0];
                }
                
                if ($tipoBoletinSeleccionado === 'cuatrimestre') {
                    // MATERIAS CURSADAS (primera vez) - tipo C
                    $materiasCursadas = $db->fetchAll(
                        "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                                cu.anio as anio_cursada,
                                c.valoracion_1bim, c.desempeno_1bim, c.observaciones_1bim,
                                c.valoracion_3bim, c.desempeno_3bim, c.observaciones_3bim
                        FROM calificaciones c
                        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                        JOIN materias m ON mp.materia_id = m.id
                        JOIN cursos cu ON mp.curso_id = cu.id
                        WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ? 
                        AND c.tipo_cursada = 'C'
                        AND NOT EXISTS (
                            SELECT 1 FROM materias_recursado mr 
                            WHERE mr.estudiante_id = c.estudiante_id 
                            AND mr.materia_curso_id = c.materia_curso_id 
                            AND mr.ciclo_lectivo_id = c.ciclo_lectivo_id 
                            AND mr.estado = 'activo'
                        )
                        ORDER BY m.nombre",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    
                    // MATERIAS RECURSADAS - tipo R
                    $materiasRecursadas = $db->fetchAll(
                        "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                                cu_orig.anio as anio_cursada,
                                c.valoracion_1bim, c.desempeno_1bim, c.observaciones_1bim,
                                c.valoracion_3bim, c.desempeno_3bim, c.observaciones_3bim,
                                'R' as tipo_cursada_forzado
                        FROM materias_recursado mr
                        JOIN calificaciones c ON mr.estudiante_id = c.estudiante_id 
                                                AND mr.materia_curso_id = c.materia_curso_id 
                                                AND mr.ciclo_lectivo_id = c.ciclo_lectivo_id
                        JOIN materias_por_curso mp ON mr.materia_curso_id = mp.id
                        JOIN materias m ON mp.materia_id = m.id
                        JOIN cursos cu_orig ON mp.curso_id = cu_orig.id
                        WHERE mr.estudiante_id = ? AND mr.ciclo_lectivo_id = ? 
                        AND mr.estado = 'activo'
                        ORDER BY m.nombre",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    
                    // Materias recursadas SIN calificación
                    $materiasRecursadasSinCalif = $db->fetchAll(
                        "SELECT mr.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                                cu_orig.anio as anio_cursada,
                                'R' as tipo_cursada_forzado,
                                NULL as valoracion_preliminar_1c, NULL as calificacion_1c,
                                NULL as valoracion_preliminar_2c, NULL as calificacion_2c,
                                NULL as intensificacion_1c, NULL as calificacion_final,
                                NULL as observaciones, NULL as estado_final,
                                NULL as valoracion_1bim, NULL as desempeno_1bim, NULL as observaciones_1bim,
                                NULL as valoracion_3bim, NULL as desempeno_3bim, NULL as observaciones_3bim
                        FROM materias_recursado mr
                        JOIN materias_por_curso mp ON mr.materia_curso_id = mp.id
                        JOIN materias m ON mp.materia_id = m.id
                        JOIN cursos cu_orig ON mp.curso_id = cu_orig.id
                        WHERE mr.estudiante_id = ? AND mr.ciclo_lectivo_id = ? 
                        AND mr.estado = 'activo'
                        AND NOT EXISTS (
                            SELECT 1 FROM calificaciones c 
                            WHERE c.estudiante_id = mr.estudiante_id 
                            AND c.materia_curso_id = mr.materia_curso_id 
                            AND c.ciclo_lectivo_id = mr.ciclo_lectivo_id
                        )
                        ORDER BY m.nombre",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                    
                    // Combinar materias recursadas
                    $materiasRecursadas = array_merge($materiasRecursadas, $materiasRecursadasSinCalif);
                    
                } else {
                    // Para boletines bimestrales
                    $campo_valoracion = 'valoracion_' . $bimestreSeleccionado . 'bim';
                    $campo_desempeno = 'desempeno_' . $bimestreSeleccionado . 'bim';
                    $campo_observaciones = 'observaciones_' . $bimestreSeleccionado . 'bim';
                    
                    $materiasBimestrales = $db->fetchAll(
                        "SELECT c.*, m.nombre as materia_nombre, m.codigo as materia_codigo,
                                cu.anio as anio_cursada,
                                c.$campo_valoracion as valoracion_bimestral,
                                c.$campo_desempeno as desempeno_bimestral,
                                c.$campo_observaciones as observaciones_bimestrales,
                                CASE 
                                    WHEN mr.id IS NOT NULL THEN 'R'
                                    ELSE COALESCE(c.tipo_cursada, 'C')
                                END as tipo_cursada_calculado
                        FROM calificaciones c
                        JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                        JOIN materias m ON mp.materia_id = m.id
                        JOIN cursos cu ON mp.curso_id = cu.id
                        LEFT JOIN materias_recursado mr ON mp.id = mr.materia_curso_id 
                                                        AND mr.estudiante_id = c.estudiante_id
                                                        AND mr.ciclo_lectivo_id = c.ciclo_lectivo_id
                                                        AND mr.estado = 'activo'
                        WHERE c.estudiante_id = ? AND c.ciclo_lectivo_id = ?
                        ORDER BY m.nombre",
                        [$estudianteSeleccionado, $cicloLectivoId]
                    );
                }
                
                // Obtener asistencias por cuatrimestre
                $asistencias = $db->fetchAll(
                    "SELECT cuatrimestre, 
                            COUNT(CASE WHEN estado = 'ausente' THEN 1 END) as ausentes,
                            COUNT(CASE WHEN estado = 'media_falta' THEN 1 END) as medias_faltas,
                            COUNT(CASE WHEN estado = 'justificada' THEN 1 END) as justificadas,
                            COUNT(*) as total_dias
                     FROM asistencias
                     WHERE estudiante_id = ? AND curso_id = ?
                     GROUP BY cuatrimestre",
                    [$estudianteSeleccionado, $cursoSeleccionado]
                );
                
                // Formatear asistencias
                $asistenciasPorCuatrimestre = [];
                foreach ($asistencias as $asistencia) {
                    $asistenciasPorCuatrimestre[$asistencia['cuatrimestre']] = $asistencia;
                }
            }
        }
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Error al obtener datos: ' . $e->getMessage() . '</div>';
    }
}
?>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">Generación de Registro Institucional de Trayectorias Educativas (RITE) - Ciclo Lectivo <?= isset($anioActivo) ? $anioActivo : date('Y') ?></h5>
            </div>
            <div class="card-body">
                <!-- Selector de tipo de boletín -->
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="btn-group" role="group" aria-label="Tipo de boletín">
                            <input type="radio" class="btn-check" name="tipo_boletin_radio" id="tipo_bimestre" 
                                   value="bimestre" <?= $tipoBoletinSeleccionado === 'bimestre' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="tipo_bimestre">
                                <i class="bi bi-clipboard-check"></i> Boletín Bimestral (Valoraciones)
                            </label>
							
							<input type="radio" class="btn-check" name="tipo_boletin_radio" id="tipo_cuatrimestre" 
                                   value="cuatrimestre" <?= $tipoBoletinSeleccionado === 'cuatrimestre' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-success" for="tipo_cuatrimestre">
                                <i class="bi bi-journal-text"></i> Boletín Cuatrimestral (RITE Completo)
                            </label>
                        </div>
                        <small class="form-text text-muted d-none">
                            Seleccione el tipo de boletín que desea generar
                        </small>
                    </div>
                </div>
                
                <form method="GET" action="boletines.php" class="mb-4" id="form-seleccion">
                    <input type="hidden" name="tipo" id="hidden_tipo" value="<?= $tipoBoletinSeleccionado ?>">
                    <div class="row align-items-end">
                        <div class="col-md-3">
                            <label for="curso" class="form-label">Seleccione Curso:</label>
                            <select name="curso" id="curso" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleccione un curso --</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?= $curso['id'] ?>" <?= ($cursoSeleccionado == $curso['id']) ? 'selected' : '' ?>>
                                    <?= $curso['nombre'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($cursoSeleccionado && count($estudiantes) > 0): ?>
                        <div class="col-md-3">
                            <label for="estudiante" class="form-label">Seleccione Estudiante:</label>
                            <select name="estudiante" id="estudiante" class="form-select" onchange="this.form.submit()">
                                <option value="">-- Ver todo el curso --</option>
                                <?php foreach ($estudiantes as $estudiante): ?>
                                <option value="<?= $estudiante['id'] ?>" <?= ($estudianteSeleccionado == $estudiante['id']) ? 'selected' : '' ?>>
                                    <?= $estudiante['apellido'] ?>, <?= $estudiante['nombre'] ?> (<?= $estudiante['dni'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                        <div class="col-md-3">
                            <label for="cuatrimestre" class="form-label">Cuatrimestre:</label>
                            <select name="cuatrimestre" id="cuatrimestre" class="form-select" required onchange="this.form.submit()">
                                <option value="1" <?= ($cuatrimestreSeleccionado == 1) ? 'selected' : '' ?>>1° Cuatrimestre</option>
                                <option value="2" <?= ($cuatrimestreSeleccionado == 2) ? 'selected' : '' ?>>2° Cuatrimestre</option>
                            </select>
                        </div>
                        <?php else: ?>
                        <div class="col-md-3">
                            <label for="bimestre" class="form-label">Bimestre:</label>
                            <select name="bimestre" id="bimestre" class="form-select" required onchange="this.form.submit()">
                                <option value="1" <?= ($bimestreSeleccionado == 1) ? 'selected' : '' ?>>1er Bimestre</option>
                                <option value="3" <?= ($bimestreSeleccionado == 3) ? 'selected' : '' ?>>3er Bimestre</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
                
                <!-- Opciones de generación masiva -->
                <?php if ($cursoSeleccionado && !$estudianteSeleccionado && count($estudiantes) > 0): ?>
                <div class="alert alert-info">
                    <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Generación de Boletines por Curso</h6>
                    <p>Ha seleccionado el curso completo. Puede generar boletines individuales o todos a la vez.</p>
                    
                    <!-- Estadísticas de emails mejoradas -->
                    <?php if ($emailManager): ?>
                        <?php
                        $estadisticasEmail = obtenerEstadisticasEmailsCurso($estudiantes);
                        ?>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="row">
                                    <div class="col-md-2">
                                        <div class="alert alert-primary mb-2">
                                            <strong>Total:</strong> <?= $estadisticasEmail['total'] ?> estudiantes
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="alert alert-success mb-2">
                                            <strong>Email Principal:</strong> <?= $estadisticasEmail['con_email_principal'] ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="alert alert-info mb-2">
                                            <strong>Email Secundario:</strong> <?= $estadisticasEmail['con_email_secundario'] ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="alert alert-dark mb-2">
                                            <strong>Ambos Emails:</strong> <?= $estadisticasEmail['con_ambos_emails'] ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="alert alert-warning mb-2">
                                            <strong>Sin Email:</strong> <?= $estadisticasEmail['sin_email'] ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="alert alert-secondary mb-2">
                                            <strong>Cobertura:</strong> <?= $estadisticasEmail['porcentaje'] ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-success">
                            <i class="bi bi-envelope-check"></i> 
                            <strong>Envío dual:</strong> Se enviarán copias a ambos emails cuando estén configurados. 
                            Total de emails de destino: <?= ($estadisticasEmail['con_email_principal'] + $estadisticasEmail['con_email_secundario']) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Estudiantes en el curso:</strong> <?= count($estudiantes) ?></p>
                            <p><strong>Tipo de boletín:</strong> <?= $tipoBoletinSeleccionado === 'cuatrimestre' ? 'Cuatrimestral' : 'Bimestral' ?></p>
                            <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                            <p><strong>Período:</strong> <?= $cuatrimestreSeleccionado ?>° cuatrimestre</p>
                            <?php else: ?>
                            <p><strong>Período:</strong> <?= $bimestreSeleccionado ?>° bimestre (<?= $bimestreSeleccionado == 1 ? '1er' : '3er' ?> bimestre)</p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="d-grid gap-2">
                                <?php
                                $params = [
                                    'curso' => $cursoSeleccionado,
                                    'tipo' => $tipoBoletinSeleccionado
                                ];
                                if ($tipoBoletinSeleccionado === 'cuatrimestre') {
                                    $params['cuatrimestre'] = $cuatrimestreSeleccionado;
                                } else {
                                    $params['bimestre'] = $bimestreSeleccionado;
                                }
                                $queryString = http_build_query($params);
                                ?>
                                
                                <!-- Botón para generar todos los boletines en ZIP -->
                                <a href="generar_boletin_ajax.php?<?= $queryString ?>" 
                                   class="btn btn-success btn-lg d-none" 
                                   onclick="mostrarCargando(this)">
                                    <i class="bi bi-download"></i> Descargar Todos los Boletines (ZIP)
                                </a>
                                
                                <!-- NUEVO: Botón de envío masivo por email -->
                                <?php if ($emailManager): ?>
                                    <button type="button" class="btn btn-primary btn-lg" 
                                            data-bs-toggle="modal" data-bs-target="#modalEnvioMasivo">
                                        <i class="bi bi-envelope"></i> Enviar Todos por Email
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg" disabled 
                                            title="Sistema de emails no disponible">
                                        <i class="bi bi-envelope-x"></i> Email No Disponible
                                    </button>
                                    <?php if ($emailError): ?>
                                        <small class="text-danger"><?= htmlspecialchars($emailError) ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Enlaces adicionales -->
                                <?php if ($emailManager): ?>
                                    <a href="gestionar_emails.php?curso=<?= $cursoSeleccionado ?>" class="btn btn-outline-info">
                                        <i class="bi bi-envelope-gear"></i> Gestionar Emails del Curso
                                    </a>
                                <?php endif; ?>
                                
                                <small class="text-muted">
                                    <?php if ($emailManager): ?>
                                        Se enviarán copias a todos los emails configurados por estudiante.
                                    <?php else: ?>
                                        Para envío por email, debe instalar y configurar el sistema de emails.
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista de estudiantes del curso con información de emails -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title">Estudiantes del Curso - <?php 
                            $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoSeleccionado]);
                            echo htmlspecialchars($cursoInfo['nombre']);
                        ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Estudiante</th>
                                        <th>Matrícula</th>
                                        <th>Email Principal</th>
                                        <th>Email Secundario</th>
                                        <th class="text-center">Estado Email</th>
                                        <th class="text-center">Mat. Pendientes</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $contador = 1; ?>
                                    <?php foreach ($estudiantes as $estudiante): ?>
                                    <?php
                                        // Verificar si tiene datos según el tipo de boletín
                                        if ($tipoBoletinSeleccionado === 'cuatrimestre') {
                                            $tieneDatos = $db->fetchOne(
                                                "SELECT COUNT(*) as count FROM calificaciones c
                                                 JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                                                 JOIN cursos cu ON mp.curso_id = cu.id
                                                 WHERE c.estudiante_id = ? AND cu.id = ? AND c.ciclo_lectivo_id = ?",
                                                [$estudiante['id'], $cursoSeleccionado, $cicloLectivoId]
                                            )['count'];
                                        } else {
                                            $campo_valoracion = 'valoracion_' . $bimestreSeleccionado . 'bim';
                                            $tieneDatos = $db->fetchOne(
                                                "SELECT COUNT(*) as count FROM calificaciones c
                                                 JOIN materias_por_curso mp ON c.materia_curso_id = mp.id
                                                 JOIN cursos cu ON mp.curso_id = cu.id
                                                 WHERE c.estudiante_id = ? AND cu.id = ? AND c.ciclo_lectivo_id = ? 
                                                 AND c.$campo_valoracion IS NOT NULL",
                                                [$estudiante['id'], $cursoSeleccionado, $cicloLectivoId]
                                            )['count'];
                                        }
                                        
                                        // Verificar materias pendientes
                                        $materiasPendientesCount = 0;
                                        try {
                                            $materiasPendientesCount = $db->fetchOne(
                                                "SELECT COUNT(*) as count FROM materias_pendientes_intensificacion mpi
                                                 WHERE mpi.estudiante_id = ? AND mpi.ciclo_lectivo_id = ? AND mpi.estado = 'activo'",
                                                [$estudiante['id'], $cicloLectivoId]
                                            )['count'];
                                        } catch (Exception $e) {
                                            // Si la tabla no existe, mantener en 0
                                        }
                                        
                                        // Verificar emails
                                        $emailsEstudiante = verificarEmailsEstudiante($estudiante);
                                        $tieneEmailPrincipal = !empty($estudiante['email']) && filter_var($estudiante['email'], FILTER_VALIDATE_EMAIL);
                                        $tieneEmailSecundario = !empty($estudiante['email_secundario']) && filter_var($estudiante['email_secundario'], FILTER_VALIDATE_EMAIL);
                                    ?>
                                    <tr>
                                        <td><?= $contador++ ?></td>
                                        <td><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></td>
                                        <td><?= htmlspecialchars($estudiante['dni']) ?></td>
                                        <td>
                                            <?php if ($tieneEmailPrincipal): ?>
                                                <small class="text-success"><?= htmlspecialchars($estudiante['email']) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Sin email principal</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($tieneEmailSecundario): ?>
                                                <small class="text-info"><?= htmlspecialchars($estudiante['email_secundario']) ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Sin email secundario</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($tieneEmailPrincipal && $tieneEmailSecundario): ?>
                                                <span class="badge bg-success" title="Ambos emails configurados">✓✓</span>
                                            <?php elseif ($tieneEmailPrincipal || $tieneEmailSecundario): ?>
                                                <span class="badge bg-warning" title="Solo un email configurado">✓</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger" title="Sin emails">✗</span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted"><?= count($emailsEstudiante) ?> email(s)</small>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($materiasPendientesCount > 0): ?>
                                                <span class="badge bg-warning"><?= $materiasPendientesCount ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <?php
                                                $paramsIndividual = [
                                                    'curso' => $cursoSeleccionado,
                                                    'estudiante' => $estudiante['id'],
                                                    'tipo' => $tipoBoletinSeleccionado
                                                ];
                                                if ($tipoBoletinSeleccionado === 'cuatrimestre') {
                                                    $paramsIndividual['cuatrimestre'] = $cuatrimestreSeleccionado;
                                                } else {
                                                    $paramsIndividual['bimestre'] = $bimestreSeleccionado;
                                                }
                                                $queryIndividual = http_build_query($paramsIndividual);
                                                ?>
                                                <a href="boletines.php?<?= $queryIndividual ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Ver boletín individual">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (count($emailsEstudiante) > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                            title="<?= count($emailsEstudiante) ?> email(s) configurado(s)">
                                                        <i class="bi bi-envelope-check"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                            title="Sin emails configurados">
                                                        <i class="bi bi-envelope-x"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <?php elseif ($datosEstudiante): ?>
                <!-- Vista individual del boletín -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>
                        <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                            REGISTRO INSTITUCIONAL DE TRAYECTORIAS EDUCATIVAS (RITE)
                        <?php else: ?>
                            BOLETÍN DE VALORACIONES BIMESTRALES
                        <?php endif; ?>
                    </h4>
                    <div>
                        <?php
                        $paramsGenerar = [
                            'curso' => $cursoSeleccionado,
                            'estudiante' => $estudianteSeleccionado,
                            'tipo' => $tipoBoletinSeleccionado
                        ];
                        if ($tipoBoletinSeleccionado === 'cuatrimestre') {
                            $paramsGenerar['cuatrimestre'] = $cuatrimestreSeleccionado;
                        } else {
                            $paramsGenerar['bimestre'] = $bimestreSeleccionado;
                        }
                        $queryGenerar = http_build_query($paramsGenerar);
                        ?>
						<?php
                        // Determinar qué archivo de generación usar según el tipo de boletín
						$archivoGeneracion = ($tipoBoletinSeleccionado === 'bimestre') ? 'generar_valoraciones_pdf.php' : 'generar_boletin_pdf.php';
						?>
						<a href="<?= $archivoGeneracion ?>?<?= $queryGenerar ?>" 
						   class="btn btn-primary" target="_blank">
						    <i class="bi bi-file-pdf"></i> Generar PDF
						</a>
                        
                        <!-- NUEVO: Botón de envío por email individual -->
                        <?php if ($emailManager): ?>
                            <button type="button" class="btn btn-info" 
                                    data-bs-toggle="modal" data-bs-target="#modalEnvioIndividual">
                                <i class="bi bi-envelope"></i> Enviar por Email
                            </button>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNotificacion">
                            <i class="bi bi-check-circle"></i> Registrar Notificación
                        </button>
                        <?php
                        $paramsVolver = [
                            'curso' => $cursoSeleccionado,
                            'tipo' => $tipoBoletinSeleccionado
                        ];
                        if ($tipoBoletinSeleccionado === 'cuatrimestre') {
                            $paramsVolver['cuatrimestre'] = $cuatrimestreSeleccionado;
                        } else {
                            $paramsVolver['bimestre'] = $bimestreSeleccionado;
                        }
                        $queryVolver = http_build_query($paramsVolver);
                        ?>
                        <a href="boletines.php?<?= $queryVolver ?>" 
                           class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Volver al Curso
                        </a>
                    </div>
                </div>
                
                <!-- Datos del estudiante con información de emails -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title">Datos del Estudiante</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Estudiante:</strong> <?= $datosEstudiante['apellido'] ?>, <?= $datosEstudiante['nombre'] ?></p>
                                <p><strong>Matr.:</strong> <?= $datosEstudiante['dni'] ?></p>
                                <p><strong>Curso:</strong> <?= $datosEstudiante['curso_nombre'] ?></p>
                                <p><strong>Ciclo Lectivo:</strong> <?= $anioActivo ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-envelope"></i> Emails de Contacto:</h6>
                                <?php
                                $emailsConfigurados = verificarEmailsEstudiante($datosEstudiante);
                                ?>
                                <?php if (!empty($datosEstudiante['email'])): ?>
                                    <p><strong>Email Principal:</strong> 
                                        <?php if (filter_var($datosEstudiante['email'], FILTER_VALIDATE_EMAIL)): ?>
                                            <span class="text-success"><?= htmlspecialchars($datosEstudiante['email']) ?></span>
                                        <?php else: ?>
                                            <span class="text-warning"><?= htmlspecialchars($datosEstudiante['email']) ?> (formato inválido)</span>
                                        <?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <p><strong>Email Principal:</strong> <span class="text-muted">No configurado</span></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($datosEstudiante['email_secundario'])): ?>
                                    <p><strong>Email Secundario:</strong> 
                                        <?php if (filter_var($datosEstudiante['email_secundario'], FILTER_VALIDATE_EMAIL)): ?>
                                            <span class="text-info"><?= htmlspecialchars($datosEstudiante['email_secundario']) ?></span>
                                        <?php else: ?>
                                            <span class="text-warning"><?= htmlspecialchars($datosEstudiante['email_secundario']) ?> (formato inválido)</span>
                                        <?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <p><strong>Email Secundario:</strong> <span class="text-muted">No configurado</span></p>
                                <?php endif; ?>
                                
                                <div class="alert alert-sm <?= count($emailsConfigurados) > 0 ? 'alert-success' : 'alert-warning' ?>">
                                    <small>
                                        <i class="bi bi-info-circle"></i> 
                                        <?php if (count($emailsConfigurados) > 0): ?>
                                            Se enviarán copias a <?= count($emailsConfigurados) ?> dirección(es) de email.
                                        <?php else: ?>
                                            Sin emails válidos configurados para envío.
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                    <!-- Vista cuatrimestral (lógica original) -->
                    <?php include 'includes/vista_boletin_cuatrimestral.php'; ?>
                    
                    <!-- Mostrar materias pendientes después del contenido cuatrimestral -->
                    <?php include 'includes/vista_materias_pendientes.php'; ?>
                <?php else: ?>
                    <!-- Vista bimestral (nueva) -->
                    <?php include 'includes/vista_boletin_bimestral.php'; ?>
                    
                    <!-- Para boletines bimestrales, incluir materias pendientes al final -->
                    <?php include 'includes/vista_materias_pendientes.php'; ?>
                <?php endif; ?>

                <!-- Modal para registrar notificación -->
                <div class="modal fade" id="modalNotificacion" tabindex="-1" aria-labelledby="modalNotificacionLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="boletines.php?<?= $queryGenerar ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="modalNotificacionLabel">Registrar Notificación del Boletín</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <p>Estudiante: <strong><?= $datosEstudiante['apellido'] ?>, <?= $datosEstudiante['nombre'] ?></strong></p>
                                    <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                                    <p>Período: <strong><?= $cuatrimestreSeleccionado ?>° Cuatrimestre</strong></p>
                                    <?php else: ?>
                                    <p>Período: <strong><?= $bimestreSeleccionado ?>° Bimestre</strong></p>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="firma_responsable" name="firma_responsable">
                                            <label class="form-check-label" for="firma_responsable">Firma del Responsable</label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="firma_estudiante" name="firma_estudiante">
                                            <label class="form-check-label" for="firma_estudiante">Firma del Estudiante</label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="observaciones" class="form-label">Observaciones:</label>
                                        <textarea class="form-control" id="observaciones" name="observaciones" rows="3"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" name="registrar_notificacion" class="btn btn-primary">Registrar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Modal para envío individual mejorado -->
                <?php if ($emailManager): ?>
                <div class="modal fade" id="modalEnvioIndividual" tabindex="-1" aria-labelledby="modalEnvioIndividualLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="boletines.php?<?= $queryGenerar ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="modalEnvioIndividualLabel">Enviar Boletín por Email</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <p><strong>Estudiante:</strong> <?= $datosEstudiante['apellido'] ?>, <?= $datosEstudiante['nombre'] ?></p>
                                    <p><strong>Matr.:</strong> <?= $datosEstudiante['dni'] ?></p>
                                    <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                                        <p><strong>Período:</strong> <?= $cuatrimestreSeleccionado ?>° Cuatrimestre</p>
                                    <?php else: ?>
                                        <p><strong>Período:</strong> <?= $bimestreSeleccionado ?>° Bimestre</p>
                                    <?php endif; ?>
                                    
                                    <!-- Verificar emails configurados -->
                                    <?php
                                    $emailsConfigurados = verificarEmailsEstudiante($datosEstudiante);
                                    ?>
                                    
                                    <?php if (count($emailsConfigurados) > 0): ?>
                                        <div class="alert alert-info">
                                            <i class="bi bi-envelope"></i> 
                                            <strong>Emails de destino:</strong>
                                            <ul class="mb-0 mt-2">
                                                <?php foreach ($emailsConfigurados as $email): ?>
                                                    <li><?= htmlspecialchars($email) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                        
                                        <p>Se enviarán <strong><?= count($emailsConfigurados) ?> copia(s)</strong> del boletín en formato PDF adjunto.</p>
                                        
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i> 
                                            <strong>Sin emails configurados</strong><br>
                                            No se encontraron emails válidos para este estudiante. 
                                            <a href="gestionar_emails.php?curso=<?= $cursoSeleccionado ?>" target="_blank">Configure emails aquí</a> antes de enviar el boletín.
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <?php if (count($emailsConfigurados) > 0): ?>
                                        <button type="submit" name="enviar_email_individual" class="btn btn-primary">
                                            <i class="bi bi-envelope"></i> Enviar Email (<?= count($emailsConfigurados) ?> destinatario<?= count($emailsConfigurados) > 1 ? 's' : '' ?>)
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" disabled>
                                            No se puede enviar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php elseif ($cursoSeleccionado && count($estudiantes) == 0): ?>
                <div class="alert alert-warning">
                    No se encontraron estudiantes matriculados en este curso.
                </div>
                <?php endif; ?>
                
                <!-- Modal para envío masivo mejorado -->
                <?php if ($emailManager && $cursoSeleccionado && !$estudianteSeleccionado && count($estudiantes) > 0): ?>
                <div class="modal fade" id="modalEnvioMasivo" tabindex="-1" aria-labelledby="modalEnvioMasivoLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" action="boletines.php?<?= $queryString ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="modalEnvioMasivoLabel">Envío Masivo de Boletines por Email</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info">
                                        <h6><i class="bi bi-info-circle"></i> Información del Envío</h6>
                                        <p><strong>Curso:</strong> <?php 
                                            $cursoInfo = $db->fetchOne("SELECT nombre FROM cursos WHERE id = ?", [$cursoSeleccionado]);
                                            echo htmlspecialchars($cursoInfo['nombre']);
                                        ?></p>
                                        <p><strong>Estudiantes:</strong> <?= count($estudiantes) ?></p>
                                        <p><strong>Tipo:</strong> <?= $tipoBoletinSeleccionado === 'cuatrimestre' ? 'Cuatrimestral' : 'Bimestral' ?></p>
                                        <?php if ($tipoBoletinSeleccionado === 'cuatrimestre'): ?>
                                            <p><strong>Período:</strong> <?= $cuatrimestreSeleccionado ?>° cuatrimestre</p>
                                        <?php else: ?>
                                            <p><strong>Período:</strong> <?= $bimestreSeleccionado ?>° bimestre</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Verificar emails configurados para envío masivo -->
                                    <?php
                                    $estudiantesConEmailPrincipal = 0;
                                    $estudiantesConEmailSecundario = 0;
                                    $estudiantesConAmbosEmails = 0;
                                    $estudiantesSinEmail = [];
                                    $totalEmails = 0;
                                    
                                    foreach ($estudiantes as $estudiante) {
                                        $emailsEstudiante = verificarEmailsEstudiante($estudiante);
                                        $tieneEmailPrincipal = !empty($estudiante['email']) && filter_var($estudiante['email'], FILTER_VALIDATE_EMAIL);
                                        $tieneEmailSecundario = !empty($estudiante['email_secundario']) && filter_var($estudiante['email_secundario'], FILTER_VALIDATE_EMAIL);
                                        
                                        if ($tieneEmailPrincipal) $estudiantesConEmailPrincipal++;
                                        if ($tieneEmailSecundario) $estudiantesConEmailSecundario++;
                                        if ($tieneEmailPrincipal && $tieneEmailSecundario) $estudiantesConAmbosEmails++;
                                        
                                        $totalEmails += count($emailsEstudiante);
                                        
                                        if (count($emailsEstudiante) === 0) {
                                            $estudiantesSinEmail[] = $estudiante['apellido'] . ', ' . $estudiante['nombre'];
                                        }
                                    }
                                    
                                    $estudiantesConAlgunEmail = count($estudiantes) - count($estudiantesSinEmail);
                                    ?>
                                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="alert alert-success">
                                                <strong>Email Principal:</strong> <?= $estudiantesConEmailPrincipal ?> estudiantes
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="alert alert-info">
                                                <strong>Email Secundario:</strong> <?= $estudiantesConEmailSecundario ?> estudiantes
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="alert alert-dark">
                                                <strong>Ambos Emails:</strong> <?= $estudiantesConAmbosEmails ?> estudiantes
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="alert <?= count($estudiantesSinEmail) > 0 ? 'alert-warning' : 'alert-success' ?>">
                                                <strong>Sin Email:</strong> <?= count($estudiantesSinEmail) ?> estudiantes
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-primary">
                                        <h6><i class="bi bi-envelope-check"></i> Resumen de Envío</h6>
                                        <p><strong>Estudiantes que recibirán email:</strong> <?= $estudiantesConAlgunEmail ?> de <?= count($estudiantes) ?></p>
                                        <p><strong>Total de emails a enviar:</strong> <?= $totalEmails ?> copias</p>
                                        <p><small>Se enviarán copias individuales a cada email configurado por estudiante.</small></p>
                                    </div>
                                    
                                    <?php if (count($estudiantesSinEmail) > 0): ?>
                                        <div class="alert alert-warning">
                                            <h6><i class="bi bi-exclamation-triangle"></i> Estudiantes sin email configurado:</h6>
                                            <ul class="mb-0">
                                                <?php foreach (array_slice($estudiantesSinEmail, 0, 10) as $estudiante): ?>
                                                    <li><?= htmlspecialchars($estudiante) ?></li>
                                                <?php endforeach; ?>
                                                <?php if (count($estudiantesSinEmail) > 10): ?>
                                                    <li><em>... y <?= count($estudiantesSinEmail) - 10 ?> más</em></li>
                                                <?php endif; ?>
                                            </ul>
                                            <small class="text-muted">
                                                Estos estudiantes no recibirán el boletín por email. 
                                                <a href="gestionar_emails.php?curso=<?= $cursoSeleccionado ?>" target="_blank">Configure emails aquí</a>.
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="alert alert-info">
                                        <h6><i class="bi bi-clock"></i> Tiempo Estimado</h6>
                                        <p>El envío masivo puede tomar varios minutos (aproximadamente 1-2 segundos por email). 
                                        Se enviarán <strong><?= $totalEmails ?> emails</strong> en total.
                                        Por favor, mantenga la página abierta durante el proceso.</p>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <?php if ($estudiantesConAlgunEmail > 0): ?>
                                        <button type="submit" name="enviar_emails_masivo" class="btn btn-primary">
                                            <i class="bi bi-envelope"></i> Enviar <?= $totalEmails ?> Emails a <?= $estudiantesConAlgunEmail ?> Familias
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" disabled>
                                            No hay emails para enviar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Mostrar detalles de envío masivo si existen -->
                <?php if (isset($_SESSION['detalles_envio'])): ?>
                    <div class="modal fade show" id="modalResultadosEnvio" tabindex="-1" style="display: block;">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Resultados del Envío Masivo</h5>
                                    <button type="button" class="btn-close" onclick="cerrarModalResultados()"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Estudiante</th>
                                                    <th>Matr.</th>
                                                    <th>Email</th>
                                                    <th>Tipo</th>
                                                    <th>Estado</th>
                                                    <th>Detalle</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($_SESSION['detalles_envio'] as $detalle): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($detalle['estudiante']) ?></td>
                                                        <td><?= htmlspecialchars($detalle['dni']) ?></td>
                                                        <td><small><?= htmlspecialchars($detalle['email'] ?? 'Sin email') ?></small></td>
                                                        <td>
                                                            <?php if (isset($detalle['tipo_email'])): ?>
                                                                <span class="badge bg-<?= $detalle['tipo_email'] === 'principal' ? 'success' : 'info' ?>">
                                                                    <?= ucfirst($detalle['tipo_email']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($detalle['resultado']['success']): ?>
                                                                <span class="badge bg-success">Enviado</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">Error</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <small><?= htmlspecialchars($detalle['resultado']['message']) ?></small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" onclick="cerrarModalResultados()">Cerrar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-backdrop fade show"></div>
                    
                    <script>
                    function cerrarModalResultados() {
                        document.getElementById('modalResultadosEnvio').style.display = 'none';
                        document.querySelector('.modal-backdrop').remove();
                        // Limpiar la sesión
                        fetch('limpiar_detalles_envio.php', {method: 'POST'});
                    }
                    </script>
                    
                    <?php unset($_SESSION['detalles_envio']); ?>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>

<script>
// JavaScript para manejar el cambio de tipo de boletín
document.addEventListener('DOMContentLoaded', function() {
    // Manejar cambio de tipo de boletín
    document.querySelectorAll('input[name="tipo_boletin_radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.getElementById('hidden_tipo').value = this.value;
            document.getElementById('form-seleccion').submit();
        });
    });
});

function mostrarCargando(boton) {
    // Cambiar el texto del botón para mostrar que está procesando
    const textoOriginal = boton.innerHTML;
    boton.innerHTML = '<i class="bi bi-hourglass-split"></i> Generando boletines...';
    boton.classList.add('disabled');
    boton.style.pointerEvents = 'none';
    
    // Mostrar mensaje de información
    const alertaInfo = document.createElement('div');
    alertaInfo.className = 'alert alert-info alert-dismissible fade show mt-3';
    alertaInfo.innerHTML = `
        <i class="bi bi-info-circle"></i> 
        <strong>Generando boletines...</strong> 
        Este proceso puede tomar varios minutos dependiendo de la cantidad de estudiantes. 
        Por favor, no cierre esta ventana hasta que se complete la descarga.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insertar la alerta después del botón
    boton.parentNode.parentNode.insertAdjacentElement('afterend', alertaInfo);
    
    // Restaurar el botón después de un tiempo (en caso de error)
    setTimeout(function() {
        boton.innerHTML = textoOriginal;
        boton.classList.remove('disabled');
        boton.style.pointerEvents = 'auto';
    }, 300000); // 5 minutos
    
    // No prevenir el comportamiento por defecto del enlace
    return true;
}

// JavaScript adicional para mejorar la experiencia de usuario
document.addEventListener('DOMContentLoaded', function() {
    // Deshabilitar botones durante envío masivo para evitar doble envío
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn && submitBtn.name === 'enviar_emails_masivo') {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enviando...';
                
                // Mostrar mensaje de progreso
                const modal = submitBtn.closest('.modal-content');
                const progressDiv = document.createElement('div');
                progressDiv.className = 'alert alert-info mt-3';
                progressDiv.innerHTML = '<i class="bi bi-clock"></i> Enviando emails... Este proceso puede tomar varios minutos.';
                modal.querySelector('.modal-body').appendChild(progressDiv);
            }
        });
    });
});
</script>

<?php
require_once 'footer.php';
?>
