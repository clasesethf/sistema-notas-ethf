<?php
/**
 * config.php - Archivo de configuración para la aplicación
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * Basado en la Resolución N° 1650/24
 * CORREGIDO: Eliminada salida HTML para compatibilidad con AJAX
 */

// Configurar locale y timezone
date_default_timezone_set('America/Argentina/Buenos_Aires');
setlocale(LC_TIME, 'es_ES.UTF-8');
mb_internal_encoding('UTF-8');

// Configuración de la base de datos
define('DB_TYPE', 'sqlite');
define('DB_FILE', __DIR__ . '/database/calificaciones.db');

// Configuración general de la aplicación
define('APP_NAME', 'Sistema de Gestión de Calificaciones');
define('APP_VERSION', '1.0.0');
define('SCHOOL_NAME', 'Escuela Técnica Henry Ford');

// Configuración de sesiones
// Estas configuraciones deben hacerse antes de iniciar la sesión
if (session_status() == PHP_SESSION_NONE) {
    // Configurar duración de sesión (1 hora)
    ini_set('session.gc_maxlifetime', 3600);
    session_set_cookie_params(3600);
    
    // Iniciar sesión
    session_start();
}

// Zonas horarias
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Códigos de valoraciones preliminares
define('TEA', 'Trayectoria Educativa Avanzada');
define('TEP', 'Trayectoria Educativa en Proceso');
define('TED', 'Trayectoria Educativa Discontinua');

// Códigos de estados de intensificación
define('AA', 'Aprobó y Acreditó');
define('CCA', 'Continúa, Con Avances');
define('CSA', 'Continúa, Sin Avances');

// Meses de intensificación
define('INTENSIFICACION_MESES', [
    'marzo',
    'julio',
    'agosto',
    'diciembre',
    'febrero'
]);

// Clase para gestionar la conexión a la base de datos
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            // Aseguramos que exista el directorio de la base de datos
            $dbDir = dirname(DB_FILE);
            if (!file_exists($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            
            // Crear la conexión SQLite
            $this->conn = new PDO(DB_TYPE . ':' . DB_FILE);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Habilitar claves foráneas
            $this->conn->exec('PRAGMA foreign_keys = ON;');
        } catch (PDOException $e) {
            // CORREGIDO: Log del error en lugar de die() con salida HTML
            error_log("Error de conexión a la base de datos: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
        }
    }
    
    // Patrón Singleton para asegurar una sola instancia de conexión
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Ejecutar consultas SQL
    public function query($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // CORREGIDO: Log del error en lugar de die() con salida HTML
            error_log("Error en la consulta: " . $e->getMessage() . " - SQL: " . $sql);
            throw new Exception("Error en la consulta SQL");
        }
    }
    
    // Obtener una fila
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    // Obtener todas las filas
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    // Insertar registros y devolver el ID
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->conn->lastInsertId();
    }
    
    // Ejecutar una transacción
    public function transaction(callable $callback) {
        try {
            $this->conn->beginTransaction();
            $result = $callback($this);
            $this->conn->commit();
            return $result;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}

// Función para inicializar la base de datos si no existe
function initializeDatabase() {
    $dbFile = DB_FILE;
    
    // Si el archivo de base de datos ya existe, no hacemos nada
    if (file_exists($dbFile)) {
        return;
    }
    
    try {
        // Crear la conexión
        $db = Database::getInstance();
        
        // Ejecutar el script schema.sql
        $schemaFile = __DIR__ . '/database/schema.sql';
        if (file_exists($schemaFile)) {
            $schema = file_get_contents($schemaFile);
            
            // Separar consultas por punto y coma
            $queries = explode(';', $schema);
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    try {
                        $db->query($query);
                    } catch (PDOException $e) {
                        // CORREGIDO: Solo loggear errores, no mostrar HTML
                        error_log("Error en consulta de inicialización: " . $e->getMessage());
                        error_log("Consulta: " . $query);
                    }
                }
            }
            
            // CORREGIDO: Solo loggear, no hacer echo
            error_log("Base de datos inicializada correctamente.");
        } else {
            // CORREGIDO: Loggear error en lugar de die() con HTML
            error_log("Error: No se encontró el archivo schema.sql");
            throw new Exception("No se encontró el archivo schema.sql");
        }
    } catch (Exception $e) {
        error_log("Error al inicializar la base de datos: " . $e->getMessage());
        // No lanzar excepción para no interrumpir la carga del sitio
    }
}

// Función mejorada para gestionar errores (CORREGIDA)
function handleError($errno, $errstr, $errfile, $errline) {
    // Crear el directorio de logs si no existe y se puede escribir
    $logDir = __DIR__ . '/logs';
    $canWriteLog = false;
    
    if (!file_exists($logDir)) {
        // Intentar crear el directorio de logs
        if (@mkdir($logDir, 0755, true)) {
            $canWriteLog = true;
        }
    } else {
        // Verificar si se puede escribir en el directorio
        $canWriteLog = is_writable($logDir);
    }
    
    // Preparar el mensaje de error
    $error = date("Y-m-d H:i:s") . " - Error: [$errno] $errstr - $errfile:$errline\n";
    
    // Solo intentar escribir el log si tenemos permisos
    if ($canWriteLog) {
        $logFile = $logDir . '/error.log';
        @error_log($error, 3, $logFile);
    }
    
    // CORREGIDO: Nunca mostrar errores HTML en respuestas AJAX
    // Solo loggear al error log del sistema
    error_log("PHP Error: [$errno] $errstr in $errfile:$errline");
    
    // No devolver true para permitir que PHP maneje el error normalmente
    return false;
}

// Función alternativa para logging que no interrumpe la ejecución
function logError($message, $context = '') {
    $logDir = __DIR__ . '/logs';
    
    // Solo intentar loggear si el directorio existe y es escribible
    if (file_exists($logDir) && is_writable($logDir)) {
        $timestamp = date("Y-m-d H:i:s");
        $logEntry = "[$timestamp] $message";
        if ($context) {
            $logEntry .= " - Context: $context";
        }
        $logEntry .= "\n";
        
        @file_put_contents($logDir . '/app.log', $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    // También loggear al error log del sistema
    error_log($message . ($context ? " - Context: $context" : ""));
}

// Registrar el manejador de errores solo para errores críticos
// Evitamos manejar warnings y notices que pueden causar problemas con headers
set_error_handler('handleError', E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

// Crear directorio de logs de forma segura
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    // Intentar crear el directorio
    if (@mkdir($logDir, 0755, true)) {
        // Crear archivo .htaccess para proteger los logs
        $htaccessContent = "Order Deny,Allow\nDeny from all";
        @file_put_contents($logDir . '/.htaccess', $htaccessContent);
    }
}

// Inicializar base de datos si es necesario
initializeDatabase();
?>
