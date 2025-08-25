<?php
/**
 * EmailManager.php - Gestión de envío de emails institucionales
 * VERSIÓN COMPLETA PARA SQLITE Y PHPMAILER CON COMPOSER
 * CON SOPORTE PARA EMAIL DUAL (PRINCIPAL Y SECUNDARIO)
 * Escuela Técnica Henry Ford
 */

// Cargar PHPMailer via Composer
require_once 'vendor/autoload.php';
require_once 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailManager {
    private $db;
    private $configuracion;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->verificarTablas();
        $this->cargarConfiguracion();
    }
    
    /**
     * Verificar y crear solo las tablas nuevas necesarias
     */
    private function verificarTablas() {
        try {
            // Verificar si la tabla envios_boletines existe
            $tablaEnvios = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='envios_boletines'");
            if (!$tablaEnvios) {
                $this->db->query("
                    CREATE TABLE envios_boletines (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        estudiante_id INTEGER NOT NULL,
                        curso_id INTEGER NOT NULL,
                        ciclo_lectivo_id INTEGER NOT NULL,
                        tipo_boletin TEXT NOT NULL CHECK (tipo_boletin IN ('cuatrimestre', 'bimestre')),
                        periodo INTEGER NOT NULL,
                        email_destinatario TEXT NOT NULL,
                        tipo_email TEXT NOT NULL CHECK (tipo_email IN ('principal', 'secundario')),
                        fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
                        estado TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('enviado', 'error', 'pendiente')),
                        mensaje_error TEXT NULL,
                        nombre_archivo TEXT NOT NULL,
                        usuario_enviador INTEGER NOT NULL,
                        FOREIGN KEY (estudiante_id) REFERENCES usuarios(id),
                        FOREIGN KEY (curso_id) REFERENCES cursos(id),
                        FOREIGN KEY (usuario_enviador) REFERENCES usuarios(id)
                    )
                ");
            } else {
                // Verificar si ya tiene la columna tipo_email
                $columnas = $this->db->fetchAll("PRAGMA table_info(envios_boletines)");
                $tieneTipoEmail = false;
                foreach ($columnas as $columna) {
                    if ($columna['name'] === 'tipo_email') {
                        $tieneTipoEmail = true;
                        break;
                    }
                }
                
                if (!$tieneTipoEmail) {
                    $this->db->query("ALTER TABLE envios_boletines ADD COLUMN tipo_email TEXT DEFAULT 'principal' CHECK (tipo_email IN ('principal', 'secundario'))");
                }
            }
            
            // Verificar si la tabla configuracion_email existe
            $tablaConfig = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='configuracion_email'");
            if (!$tablaConfig) {
                $this->db->query("
                    CREATE TABLE configuracion_email (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        smtp_servidor TEXT NOT NULL DEFAULT 'smtp.office365.com',
                        smtp_puerto INTEGER NOT NULL DEFAULT 587,
                        smtp_seguridad TEXT NOT NULL DEFAULT 'tls' CHECK (smtp_seguridad IN ('tls', 'ssl')),
                        email_institucional TEXT NOT NULL,
                        password_email TEXT NOT NULL,
                        nombre_remitente TEXT NOT NULL DEFAULT 'Escuela Técnica Henry Ford',
                        activo INTEGER DEFAULT 1 CHECK (activo IN (0, 1)),
                        fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
                        fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP
                    )
                ");
                
                // Insertar configuración por defecto
                $this->db->query("
                    INSERT INTO configuracion_email 
                    (email_institucional, password_email, nombre_remitente) 
                    VALUES ('ffernandez@henryford.edu.ar', 'CAMBIAR_PASSWORD', 'Escuela Técnica Henry Ford')
                ");
            }
            
        } catch (Exception $e) {
            error_log("Error verificando tablas: " . $e->getMessage());
            throw new Exception("Error al preparar base de datos para emails: " . $e->getMessage());
        }
    }
    
    /**
     * Cargar configuración de email desde la base de datos
     */
    private function cargarConfiguracion() {
        try {
            $this->configuracion = $this->db->fetchOne(
                "SELECT * FROM configuracion_email WHERE activo = 1 ORDER BY id DESC LIMIT 1"
            );
            
            if (!$this->configuracion) {
                throw new Exception("No se encontró configuración de email activa. Configure primero el email institucional.");
            }
            
            if ($this->configuracion['password_email'] === 'CAMBIAR_PASSWORD') {
                throw new Exception("Debe configurar la contraseña del email institucional en config_emails.php");
            }
            
        } catch (Exception $e) {
            error_log("Error al cargar configuración de email: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Configurar PHPMailer para Outlook/Office365
     */
    private function configurarPHPMailer() {
        $mail = new PHPMailer(true);
        
        try {
            // Configuración del servidor
            $mail->isSMTP();
            $mail->Host = $this->configuracion['smtp_servidor'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->configuracion['email_institucional'];
            $mail->Password = $this->configuracion['password_email'];
            $mail->SMTPSecure = $this->configuracion['smtp_seguridad'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $this->configuracion['smtp_puerto'];
            
            // Configuración de charset y encoding
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            
            // Remitente
            $mail->setFrom(
                $this->configuracion['email_institucional'], 
                $this->configuracion['nombre_remitente']
            );
            
            // Configuración adicional para Outlook
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            return $mail;
            
        } catch (Exception $e) {
            error_log("Error al configurar PHPMailer: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Enviar boletín individual por email (VERSIÓN DUAL)
     */
    public function enviarBoletinIndividual($estudianteId, $cursoId, $cicloLectivoId, $tipoBoletin, $periodo, $usuarioEnviador) {
        try {
            // Obtener datos del estudiante con ambos emails
            $datosEstudiante = $this->obtenerDatosEstudiante($estudianteId, $cursoId);
            if (!$datosEstudiante) {
                throw new Exception("No se encontraron datos del estudiante");
            }
            
            // Obtener todos los emails de destino
            $emailsDestino = $this->obtenerEmailsDestino($datosEstudiante);
            if (empty($emailsDestino)) {
                throw new Exception("No se encontraron emails de contacto para: " . $datosEstudiante['apellido'] . ", " . $datosEstudiante['nombre']);
            }
            
            // Generar PDF del boletín (una sola vez)
            $rutaPDF = $this->generarPDFBoletin($estudianteId, $cursoId, $tipoBoletin, $periodo);
            if (!$rutaPDF || !file_exists($rutaPDF)) {
                throw new Exception("Error al generar el PDF del boletín");
            }
            
            $nombreArchivo = basename($rutaPDF);
            $enviosExitosos = 0;
            $erroresEnvio = [];
            
            // Enviar a cada email configurado
            foreach ($emailsDestino as $tipoEmail => $email) {
                try {
                    // Configurar email
                    $mail = $this->configurarPHPMailer();
                    
                    // Destinatario
                    $nombreCompleto = $datosEstudiante['apellido'] . ', ' . $datosEstudiante['nombre'];
                    $mail->addAddress($email, "Familia " . $nombreCompleto);
                    
                    // Asunto y contenido
                    $asunto = $this->generarAsunto($tipoBoletin, $periodo, $datosEstudiante);
                    $contenido = $this->generarContenidoEmail($tipoBoletin, $periodo, $datosEstudiante);
                    
                    $mail->Subject = $asunto;
                    $mail->isHTML(true);
                    $mail->Body = $contenido;
                    
                    // Adjuntar PDF
                    $mail->addAttachment($rutaPDF, $nombreArchivo);
                    
                    // Enviar email
                    $resultado = $mail->send();
                    
                    if ($resultado) {
                        // Registrar envío exitoso
                        $this->registrarEnvio($estudianteId, $cursoId, $cicloLectivoId, $tipoBoletin, $periodo, 
                                            $email, $tipoEmail, 'enviado', null, $nombreArchivo, $usuarioEnviador);
                        $enviosExitosos++;
                    } else {
                        throw new Exception("Error al enviar el email a $email");
                    }
                    
                } catch (Exception $e) {
                    // Registrar error para este email específico
                    $this->registrarEnvio($estudianteId, $cursoId, $cicloLectivoId, $tipoBoletin, $periodo, 
                                        $email, $tipoEmail, 'error', $e->getMessage(), 
                                        $nombreArchivo, $usuarioEnviador);
                    $erroresEnvio[] = "Error enviando a $email ($tipoEmail): " . $e->getMessage();
                }
            }
            
            // Limpiar archivo temporal
            if (file_exists($rutaPDF)) {
                unlink($rutaPDF);
            }
            
            // Evaluar resultado general
            if ($enviosExitosos > 0) {
                $mensaje = "Boletín enviado correctamente";
                if ($enviosExitosos > 1) {
                    $mensaje .= " a $enviosExitosos direcciones de email";
                } else {
                    $emailsLista = array_values($emailsDestino);
                    $mensaje .= " a " . $emailsLista[0];
                }
                $mensaje .= " ({$datosEstudiante['apellido']}, {$datosEstudiante['nombre']})";
                
                if (!empty($erroresEnvio)) {
                    $mensaje .= ". ADVERTENCIA: " . count($erroresEnvio) . " email(s) fallaron";
                }
                
                return [
                    'success' => true, 
                    'message' => $mensaje,
                    'envios_exitosos' => $enviosExitosos,
                    'errores' => $erroresEnvio
                ];
            } else {
                return [
                    'success' => false, 
                    'message' => 'Error al enviar boletín: ' . implode('; ', $erroresEnvio)
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false, 
                'message' => 'Error al enviar boletín: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Enviar boletines masivos para un curso completo (VERSIÓN DUAL)
     */
    public function enviarBoletinesMasivos($cursoId, $cicloLectivoId, $tipoBoletin, $periodo, $usuarioEnviador) {
        $resultados = [
            'exitosos' => 0,
            'errores' => 0,
            'total_emails_enviados' => 0,
            'detalles' => []
        ];
        
        try {
            // Obtener todos los estudiantes del curso con ambos emails
            $estudiantes = $this->db->fetchAll(
                "SELECT u.id, u.nombre, u.apellido, u.dni, u.email, u.email_secundario
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo' 
                 ORDER BY u.apellido, u.nombre",
                [$cursoId]
            );
            
            foreach ($estudiantes as $estudiante) {
                // Verificar si tiene al menos un email
                $emailsEstudiante = $this->obtenerEmailsDestino($estudiante);
                
                if (empty($emailsEstudiante)) {
                    // Estudiante sin emails
                    $resultados['detalles'][] = [
                        'estudiante' => $estudiante['apellido'] . ', ' . $estudiante['nombre'],
                        'dni' => $estudiante['dni'],
                        'email' => 'Sin email configurado',
                        'tipo_email' => 'ninguno',
                        'resultado' => [
                            'success' => false,
                            'message' => 'No tiene emails configurados'
                        ]
                    ];
                    $resultados['errores']++;
                    continue;
                }
                
                // Enviar a este estudiante
                $resultado = $this->enviarBoletinIndividual(
                    $estudiante['id'], $cursoId, $cicloLectivoId, 
                    $tipoBoletin, $periodo, $usuarioEnviador
                );
                
                if ($resultado['success']) {
                    $resultados['exitosos']++;
                    $resultados['total_emails_enviados'] += $resultado['envios_exitosos'] ?? 1;
                    
                    // Agregar detalle por cada email enviado
                    foreach ($emailsEstudiante as $tipoEmail => $email) {
                        $resultados['detalles'][] = [
                            'estudiante' => $estudiante['apellido'] . ', ' . $estudiante['nombre'],
                            'dni' => $estudiante['dni'],
                            'email' => $email,
                            'tipo_email' => $tipoEmail,
                            'resultado' => ['success' => true, 'message' => 'Enviado correctamente']
                        ];
                    }
                } else {
                    $resultados['errores']++;
                    
                    // Agregar detalles de errores
                    foreach ($emailsEstudiante as $tipoEmail => $email) {
                        $resultados['detalles'][] = [
                            'estudiante' => $estudiante['apellido'] . ', ' . $estudiante['nombre'],
                            'dni' => $estudiante['dni'],
                            'email' => $email,
                            'tipo_email' => $tipoEmail,
                            'resultado' => $resultado
                        ];
                    }
                }
                
                // Pausa pequeña entre envíos para no sobrecargar el servidor SMTP
                sleep(1);
            }
            
        } catch (Exception $e) {
            error_log("Error en envío masivo: " . $e->getMessage());
            throw $e;
        }
        
        return $resultados;
    }
    
    /**
     * Obtener datos completos del estudiante con ambos emails
     */
    private function obtenerDatosEstudiante($estudianteId, $cursoId) {
        return $this->db->fetchOne(
            "SELECT u.id, u.nombre, u.apellido, u.dni, u.email, u.email_secundario,
                    c.nombre as curso_nombre, c.anio as curso_anio
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             JOIN cursos c ON m.curso_id = c.id
             WHERE u.id = ? AND m.curso_id = ?",
            [$estudianteId, $cursoId]
        );
    }
    
    /**
     * Obtener emails de destino (principal y secundario) NUEVA FUNCIÓN
     */
    private function obtenerEmailsDestino($datosEstudiante) {
        $emails = [];
        
        // Verificar email principal
        if (!empty($datosEstudiante['email']) && filter_var($datosEstudiante['email'], FILTER_VALIDATE_EMAIL)) {
            $emails['principal'] = $datosEstudiante['email'];
        }
        
        // Verificar email secundario
        if (!empty($datosEstudiante['email_secundario']) && filter_var($datosEstudiante['email_secundario'], FILTER_VALIDATE_EMAIL)) {
            $emails['secundario'] = $datosEstudiante['email_secundario'];
        }
        
        return $emails;
    }
    
    /**
     * Generar PDF del boletín (reutilizar lógica existente)
     */
    private function generarPDFBoletin($estudianteId, $cursoId, $tipoBoletin, $periodo) {
        // Usar directorio temporal del sistema
        $directorioTemporal = sys_get_temp_dir();
        
        // Configurar variables globales para el generador de PDF
        $GLOBALS['generar_archivo_modo'] = true;
        $GLOBALS['directorio_salida'] = $directorioTemporal;
        
        // Simular GET parameters para el generador existente
        $_GET_backup = $_GET; // Guardar GET actual
        $_GET['estudiante'] = $estudianteId;
        $_GET['curso'] = $cursoId;
        $_GET['tipo'] = $tipoBoletin;
        
        if ($tipoBoletin === 'cuatrimestre') {
            $_GET['cuatrimestre'] = $periodo;
        } else {
            $_GET['bimestre'] = $periodo;
        }
        
        try {
            // Capturar output del generador
            ob_start();
            include 'generar_boletin_pdf.php';
            ob_end_clean();
            
            // Restaurar GET original
            $_GET = $_GET_backup;
            
            // Buscar el archivo generado más reciente
            $archivos = glob($directorioTemporal . '/*.pdf');
            if (!empty($archivos)) {
                // Ordenar por fecha de modificación y tomar el más reciente
                usort($archivos, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                return $archivos[0];
            }
            
            return null;
            
        } catch (Exception $e) {
            $_GET = $_GET_backup; // Restaurar GET en caso de error
            error_log("Error generando PDF: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generar asunto del email
     */
    private function generarAsunto($tipoBoletin, $periodo, $datosEstudiante) {
        $anio = date('Y');
        
        if ($tipoBoletin === 'cuatrimestre') {
            return "RITE {$periodo}° Cuatrimestre {$anio} - {$datosEstudiante['apellido']}, {$datosEstudiante['nombre']}";
        } else {
            $bimestreTexto = ($periodo == 1) ? '1er' : '3er';
            return "Boletín {$bimestreTexto} Bimestre {$anio} - {$datosEstudiante['apellido']}, {$datosEstudiante['nombre']}";
        }
    }
    
    /**
     * Generar contenido HTML del email
     */
    private function generarContenidoEmail($tipoBoletin, $periodo, $datosEstudiante) {
        $anio = date('Y');
        $fecha = date('d/m/Y');
        
        if ($tipoBoletin === 'cuatrimestre') {
            $tipoDocumento = "RITE (Registro Institucional de Trayectorias Educativas)";
            $periodoTexto = "{$periodo}° cuatrimestre";
        } else {
            $tipoDocumento = "Boletín de Valoraciones Bimestrales";
            $bimestreTexto = ($periodo == 1) ? '1er' : '3er';
            $periodoTexto = "{$bimestreTexto} bimestre";
        }
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background-color: #49ADD7; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .footer { background-color: #f8f9fa; padding: 15px; font-size: 12px; color: #666; }
                .highlight { background-color: #e7f3ff; padding: 10px; border-left: 4px solid #49ADD7; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>ESCUELA TÉCNICA HENRY FORD</h2>
                <p>Registro Institucional de Trayectorias Educativas</p>
            </div>
            
            <div class='content'>
                <p><strong>Estimada familia:</strong></p>
                
                <p>Nos dirigimos a ustedes para enviarles el <strong>{$tipoDocumento}</strong> correspondiente al <strong>{$periodoTexto}</strong> del ciclo lectivo <strong>{$anio}</strong>.</p>
                
                <div class='highlight'>
                    <strong>Estudiante:</strong> {$datosEstudiante['apellido']}, {$datosEstudiante['nombre']}<br>
                    <strong>Matrícula:</strong> {$datosEstudiante['dni']}<br>
                    <strong>Curso:</strong> {$datosEstudiante['curso_nombre']}<br>
                    <strong>Período:</strong> {$periodoTexto}<br>
                </div>
                
                <p>El documento se encuentra adjunto a este correo electrónico en formato PDF. Les solicitamos:</p>
                
                <ul>
                    <li><strong>Revisar detenidamente</strong> toda la información contenida en el boletín</li>
                    <li><strong>Conservar</strong> este documento para sus registros</li>
                    <li><strong>Contactarnos</strong> ante cualquier consulta</li>
                </ul>
                
                <p>Saludamos cordialmente y agradecemos su colaboración en el proceso educativo.</p>
                
                <p><strong>Dirección Escuela Técnica Henry Ford</strong></p>
            </div>
            
            <div class='footer'>
                <p><em>Este es un mensaje automático del Sistema de Gestión Académica. Por favor, no responda directamente a este correo.</em></p>
                <p><strong>Escuela Técnica Henry Ford</strong></p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Registrar envío en la base de datos (ACTUALIZADA PARA DUAL)
     */
    private function registrarEnvio($estudianteId, $cursoId, $cicloLectivoId, $tipoBoletin, $periodo, 
                                   $emailDestino, $tipoEmail, $estado, $mensajeError, $nombreArchivo, $usuarioEnviador) {
        try {
            $this->db->query(
                "INSERT INTO envios_boletines 
                 (estudiante_id, curso_id, ciclo_lectivo_id, tipo_boletin, periodo, 
                  email_destinatario, tipo_email, estado, mensaje_error, nombre_archivo, usuario_enviador)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$estudianteId, $cursoId, $cicloLectivoId, $tipoBoletin, $periodo, 
                 $emailDestino, $tipoEmail, $estado, $mensajeError, $nombreArchivo, $usuarioEnviador]
            );
        } catch (Exception $e) {
            error_log("Error al registrar envío: " . $e->getMessage());
        }
    }
    
    /**
     * Obtener estadísticas de emails configurados en el curso (ACTUALIZADA PARA DUAL)
     */
    public function obtenerEstadisticasEmailsCurso($cursoId) {
        try {
            $total = $this->db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'",
                [$cursoId]
            )['count'];
            
            $conEmailPrincipal = $this->db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 AND u.email IS NOT NULL AND u.email != ''",
                [$cursoId]
            )['count'];
            
            $conEmailSecundario = $this->db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 AND u.email_secundario IS NOT NULL AND u.email_secundario != ''",
                [$cursoId]
            )['count'];
            
            $conAmbosEmails = $this->db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 AND u.email IS NOT NULL AND u.email != ''
                 AND u.email_secundario IS NOT NULL AND u.email_secundario != ''",
                [$cursoId]
            )['count'];
            
            $conAlgunEmail = $this->db->fetchOne(
                "SELECT COUNT(*) as count 
                 FROM usuarios u 
                 JOIN matriculas m ON u.id = m.estudiante_id 
                 WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
                 AND ((u.email IS NOT NULL AND u.email != '') OR (u.email_secundario IS NOT NULL AND u.email_secundario != ''))",
                [$cursoId]
            )['count'];
            
            $sinEmail = $total - $conAlgunEmail;
            $porcentaje = $total > 0 ? round(($conAlgunEmail / $total) * 100, 1) : 0;
            
            return [
                'total' => $total,
                'con_email_principal' => $conEmailPrincipal,
                'con_email_secundario' => $conEmailSecundario,
                'con_ambos_emails' => $conAmbosEmails,
                'con_algun_email' => $conAlgunEmail,
                'sin_email' => $sinEmail,
                'porcentaje' => $porcentaje
            ];
            
        } catch (Exception $e) {
            error_log("Error obteniendo estadísticas: " . $e->getMessage());
            return [
                'total' => 0, 
                'con_email_principal' => 0, 
                'con_email_secundario' => 0,
                'con_ambos_emails' => 0,
                'con_algun_email' => 0,
                'sin_email' => 0, 
                'porcentaje' => 0
            ];
        }
    }
    
    /**
     * Obtener estudiantes sin email del curso (ACTUALIZADA PARA DUAL)
     */
    public function obtenerEstudiantesSinEmail($cursoId) {
        return $this->db->fetchAll(
            "SELECT u.id, u.nombre, u.apellido, u.dni
             FROM usuarios u 
             JOIN matriculas m ON u.id = m.estudiante_id 
             WHERE m.curso_id = ? AND u.tipo = 'estudiante' AND m.estado = 'activo'
             AND (u.email IS NULL OR u.email = '')
             AND (u.email_secundario IS NULL OR u.email_secundario = '')
             ORDER BY u.apellido, u.nombre",
            [$cursoId]
        );
    }
    
    /**
     * Obtener historial de envíos (ACTUALIZADA PARA DUAL)
     */
    public function obtenerHistorialEnvios($filtros = []) {
        $where = "WHERE 1=1";
        $params = [];
        
        if (isset($filtros['estudiante_id'])) {
            $where .= " AND eb.estudiante_id = ?";
            $params[] = $filtros['estudiante_id'];
        }
        
        if (isset($filtros['curso_id'])) {
            $where .= " AND eb.curso_id = ?";
            $params[] = $filtros['curso_id'];
        }
        
        if (isset($filtros['tipo_boletin'])) {
            $where .= " AND eb.tipo_boletin = ?";
            $params[] = $filtros['tipo_boletin'];
        }
        
        if (isset($filtros['tipo_email'])) {
            $where .= " AND eb.tipo_email = ?";
            $params[] = $filtros['tipo_email'];
        }
        
        return $this->db->fetchAll(
            "SELECT eb.*, 
                    u.apellido, u.nombre, u.dni,
                    c.nombre as curso_nombre,
                    ue.nombre as enviador_nombre, ue.apellido as enviador_apellido
             FROM envios_boletines eb
             JOIN usuarios u ON eb.estudiante_id = u.id
             JOIN cursos c ON eb.curso_id = c.id
             JOIN usuarios ue ON eb.usuario_enviador = ue.id
             {$where}
             ORDER BY eb.fecha_envio DESC
             LIMIT 100",
            $params
        );
    }
    
    /**
     * Obtener resumen de envíos por estudiante (NUEVA FUNCIÓN)
     */
    public function obtenerResumenEnviosPorEstudiante($estudianteId, $cicloLectivoId) {
        return $this->db->fetchAll(
            "SELECT 
                tipo_boletin,
                periodo,
                COUNT(*) as total_envios,
                SUM(CASE WHEN estado = 'enviado' THEN 1 ELSE 0 END) as envios_exitosos,
                SUM(CASE WHEN estado = 'error' THEN 1 ELSE 0 END) as envios_fallidos,
                GROUP_CONCAT(DISTINCT tipo_email) as tipos_email,
                MAX(fecha_envio) as ultimo_envio
             FROM envios_boletines
             WHERE estudiante_id = ? AND ciclo_lectivo_id = ?
             GROUP BY tipo_boletin, periodo
             ORDER BY ultimo_envio DESC",
            [$estudianteId, $cicloLectivoId]
        );
    }
}
?>
