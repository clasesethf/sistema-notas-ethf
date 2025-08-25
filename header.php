<?php
/**
 * header.php - Plantilla de encabezado con permisos actualizados
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Definir constantes del sistema directamente en este archivo si config.php no lo ha hecho
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Sistema de Gestión de Calificaciones');
    define('APP_VERSION', '1.0.0');
    define('SCHOOL_NAME', 'Escuela Técnica Henry Ford');
}

// Inicializar sesión (si no está ya iniciada)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redireccionar a login si no hay sesión activa y no estamos en login.php
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header('Location: login.php');
    exit;
}

// ===== AGREGAR ESTAS FUNCIONES ANTES DEL HTML =====

/**
 * Inicializar sistema de múltiples roles para SQLite
 */
function inicializarRolesMultiples($db) {
    if (!isset($_SESSION['user_id'])) return;
    
    try {
        $usuario = $db->fetchOne(
            "SELECT tipo, roles_secundarios FROM usuarios WHERE id = ?", 
            [$_SESSION['user_id']]
        );
        
        if (!$usuario) return;
        
        $roles = [$usuario['tipo']]; // Rol principal
        
        if (!empty($usuario['roles_secundarios'])) {
            $rolesSecundarios = explode(',', $usuario['roles_secundarios']);
            $roles = array_merge($roles, array_map('trim', $rolesSecundarios));
        }
        
        $roles = array_unique(array_filter($roles));
        
        if (count($roles) > 1) {
            $_SESSION['roles_disponibles'] = $roles;
            $_SESSION['rol_principal'] = $usuario['tipo'];
            
            // Si no hay rol activo definido, usar el principal
            if (!isset($_SESSION['rol_activo'])) {
                $_SESSION['rol_activo'] = $usuario['tipo'];
            }
        }
        
    } catch (Exception $e) {
        error_log("Error al inicializar roles múltiples: " . $e->getMessage());
    }
}

/**
 * Verificar si el usuario actual puede acceder con el rol especificado
 */
function puedeAccederConRol($rolRequerido) {
    if (!isset($_SESSION['user_id'])) return false;
    
    // Si no hay cambio de rol activo, usar el rol principal
    $rolActual = $_SESSION['rol_activo'] ?? $_SESSION['user_type'];
    
    return $rolActual === $rolRequerido || 
           (isset($_SESSION['roles_disponibles']) && 
            in_array($rolRequerido, $_SESSION['roles_disponibles']));
}

/**
 * Obtener el rol activo actual
 */
function obtenerRolActivo() {
    return $_SESSION['rol_activo'] ?? $_SESSION['user_type'];
}

/**
 * Verificar si el usuario tiene múltiples roles
 */
function tieneMultiplesRoles() {
    return isset($_SESSION['roles_disponibles']) && count($_SESSION['roles_disponibles']) > 1;
}

// ===== INICIALIZAR ROLES MÚLTIPLES DESPUÉS DE VERIFICAR SESIÓN =====
if (isset($_SESSION['user_id'])) {
    try {
        require_once 'config.php';
        $db = Database::getInstance();
        inicializarRolesMultiples($db);
    } catch (Exception $e) {
        error_log("Error al obtener roles: " . $e->getMessage());
    }
}
	
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford</title>
    
    <!-- Favicon - Logo de la escuela en la pestaña del navegador -->
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/logo.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/logo.png">
    <link rel="shortcut icon" href="assets/img/logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/logo.png">
    
    <!-- Bootstrap CSS desde CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons desde CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Chart.js - Incluido aquí para evitar problemas con document.write -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Estilos personalizados inline (para pruebas rápidas) -->
    <style>
        /* Estilos generales */
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Estilos para el login */
        .login-page {
            display: flex;
            align-items: center;
            justify-content: center;
            padding-top: 40px;
            padding-bottom: 40px;
            background-color: #f5f5f5;
            height: 100vh;
        }

        .form-signin {
            width: 100%;
            max-width: 330px;
            padding: 15px;
            margin: auto;
        }

        .form-signin .form-floating:focus-within {
            z-index: 2;
        }

        /* Estilos para el sidebar con toggle */
        #sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 0;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            max-height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            width: 320px; /* Ancho expandido más amplio */
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #4472c4 100%);
            transition: all 0.3s ease;
        }

        #sidebar.collapsed {
            width: 80px; /* Ancho cuando está colapsado - más amplio */
        }

        /* Botón toggle del sidebar */
        .sidebar-toggle {
            position: absolute;
            top: 15px;
            right: -15px;
            width: 30px;
            height: 30px;
            background-color: #2a5298;
            border: 2px solid white;
            border-radius: 50%;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1001;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .sidebar-toggle:hover {
            background-color: #1e3c72;
            transform: scale(1.1);
        }

        .sidebar-toggle i {
            font-size: 14px;
            transition: transform 0.3s ease;
        }

        #sidebar.collapsed .sidebar-toggle i {
            transform: rotate(180deg);
        }

        /* Logo y encabezado */
        .sidebar-header {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 10px;
            color: white;
        }

        .logo-img {
            max-width: 100%;
            height: auto;
            max-height: 100px;
            transition: all 0.3s ease;
        }

        #sidebar.collapsed .logo-img {
            max-height: 60px;
        }

        .logo-text {
            color: white;
            font-size: 0.8rem;
            margin-top: 5px;
            opacity: 1;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        #sidebar.collapsed .logo-text {
            opacity: 0;
        }

        /* Enlaces del menú */
        #sidebar .nav-link {
            color: rgba(255,255,255,0.8) !important;
            padding: 12px 20px;
            margin: 2px 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        #sidebar .nav-link:hover {
            background-color: rgba(255,255,255,0.1);
            color: white !important;
            transform: translateX(5px);
        }

        #sidebar .nav-link.active {
            background-color: rgba(255,255,255,0.2);
            color: white !important;
        }

        /* Iconos del menú */
        #sidebar .nav-link i {
            font-size: 18px;
            width: 30px;
            text-align: center;
            transition: all 0.3s ease;
        }

        /* Texto del menú */
        .nav-text {
            margin-left: 15px;
            opacity: 1;
            transition: all 0.3s ease;
        }

        #sidebar.collapsed .nav-text {
            opacity: 0;
        }

        /* Submenús */
        .nav-submenu {
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
            background-color: rgba(0,0,0,0.1);
            margin: 0 8px;
            border-radius: 8px;
        }

        .nav-submenu.show {
            max-height: 500px;
        }

        .nav-submenu .nav-link {
            padding: 8px 15px 8px 50px;
            font-size: 0.9rem;
        }

        /* Chevron para submenús */
        .chevron {
            margin-left: auto;
            transition: transform 0.3s ease;
            opacity: 1;
        }

        #sidebar.collapsed .chevron {
            opacity: 0;
        }

        .chevron.rotated {
            transform: rotate(180deg);
        }

        /* Contenido principal */
        main {
            padding-bottom: 60px; /* Espacio para el footer */
            margin-left: 320px; /* Ancho del sidebar expandido */
            transition: margin-left 0.3s ease;
            width: calc(100% - 320px); /* NUEVO: Ancho dinámico */
            min-height: 100vh;
            box-sizing: border-box; /* NUEVO: Incluir padding en el cálculo */
        }

        #sidebar.collapsed + .container-fluid main {
            margin-left: 80px; /* Ancho del sidebar colapsado */
            width: calc(100% - 80px); /* NUEVO: Ancho dinámico */
        }

        /* NUEVO: Ajustar containers para que ocupen todo el ancho disponible */
        .container-fluid {
            width: 100% !important;
            max-width: none !important;
            padding-right: 15px;
            padding-left: 15px;
        }

        /* NUEVO: Asegurar que las tablas y cards se ajusten al contenedor */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .card {
            width: 100%;
        }

        /* NUEVO: Ajustar elementos específicos que podrían mantener ancho fijo */
        .row {
            width: 100%;
            margin-right: 0;
            margin-left: 0;
        }

        .col-12, .col-md-12 {
            padding-right: 15px;
            padding-left: 15px;
        }

        /* Tooltip personalizado para iconos */
        .nav-tooltip {
            position: absolute;
            left: 75px;
            background-color: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 1001;
        }

        #sidebar.collapsed .nav-link:hover .nav-tooltip {
            opacity: 1;
        }

        /* Usuario info en sidebar */
        .sidebar-user {
            padding: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
            color: white;
            text-align: center;
        }

        .sidebar-user .user-text {
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        #sidebar.collapsed .sidebar-user .user-text {
            opacity: 0;
        }

        /* Botón cerrar sesión */
        .sidebar-logout {
            background-color: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            transition: all 0.3s ease;
        }

        .sidebar-logout:hover {
            background-color: rgba(255,255,255,0.2);
            color: white;
        }

        /* Estilos para las tarjetas de estadísticas */
        .border-left-primary {
            border-left: 0.25rem solid #4e73df !important;
        }

        .border-left-success {
            border-left: 0.25rem solid #1cc88a !important;
        }

        .border-left-info {
            border-left: 0.25rem solid #36b9cc !important;
        }

        .border-left-warning {
            border-left: 0.25rem solid #f6c23e !important;
        }

        /* Estilos para el footer */
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            height: 60px;
            line-height: 60px;
            background-color: #f5f5f5;
            z-index: 99;
        }

        /* Badge para nuevas funcionalidades */
        .badge-new {
            font-size: 0.6rem;
            vertical-align: super;
        }

        /* Estilos específicos para elementos de bloqueo */
        .nav-link-bloqueo {
            position: relative;
        }

        .nav-link-bloqueo .badge-bloqueo {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 0.5rem;
            padding: 0.2rem 0.4rem;
        }

        /* Indicador de estado en el menú */
        .menu-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-left: 5px;
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        #sidebar.collapsed .menu-indicator {
            opacity: 0;
        }

        .menu-indicator.activo {
            background-color: #28a745;
        }

        .menu-indicator.bloqueado {
            background-color: #dc3545;
        }

        .menu-indicator.parcial {
            background-color: #ffc107;
        }

        /* Estilos para el botón de Landing */
        .btn-landing {
            background: linear-gradient(45deg, #007bff, #6610f2);
            border: none;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .btn-landing:hover {
            background: linear-gradient(45deg, #0056b3, #520dc2);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .btn-landing:focus {
            color: white;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }

        /* Estilo especial para el enlace de landing en el sidebar */
        .nav-link-landing {
            background: linear-gradient(45deg, #007bff, #6610f2) !important;
            color: white !important;
            border-radius: 0.5rem !important;
            margin: 0.5rem 0 !important;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .nav-link-landing:hover {
            background: linear-gradient(45deg, #0056b3, #520dc2) !important;
            color: white !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .nav-link-landing i {
            margin-right: 0.5rem;
        }

        /* Ajuste para pantallas pequeñas */
        @media (max-width: 767.98px) {
            #sidebar {
                transform: translateX(-100%);
                width: 320px;
            }
            
            #sidebar.show {
                transform: translateX(0);
            }
            
            main {
                margin-left: 0 !important;
                width: 100% !important; /* NUEVO: Ancho completo en móvil */
            }
            
            .footer {
                position: static;
            }
            
            .mobile-toggle {
                position: fixed;
                top: 15px;
                left: 15px;
                z-index: 1001;
                background-color: #2a5298;
                border: none;
                color: white;
                border-radius: 8px;
                padding: 10px;
            }

            /* Notificaciones responsive */
            .notificaciones-container {
                top: 60px !important;
                right: 10px !important;
                left: 10px !important;
                max-width: none !important;
            }
            
            .notificacion {
                margin-bottom: 8px !important;
                padding: 12px 16px !important;
                font-size: 13px !important;
                min-height: 50px !important;
            }

            .notificacion-icono {
                font-size: 18px !important;
                width: 24px !important;
                height: 24px !important;
                margin-right: 10px !important;
            }
        }

        /* NUEVO: Estilos adicionales para mejor responsividad */
        @media (min-width: 768px) {
            .container-fluid {
                padding-right: 20px;
                padding-left: 20px;
            }
        }

        /* NUEVO: Asegurar que elementos grandes no causen overflow */
        .table-responsive {
            border: none;
        }

        .table {
            margin-bottom: 0;
        }

        /* NUEVO: Ajustar gráficos y elementos que puedan ser grandes */
        canvas, .chart-container {
            max-width: 100% !important;
            height: auto !important;
        }

        /* ===== ESTILOS MEJORADOS PARA NOTIFICACIONES FLOTANTES ===== */
        
        /* Contenedor de notificaciones flotantes */
        .notificaciones-container {
            position: fixed;
            top: 80px; /* Posición más baja para evitar interfering con header */
            right: 20px;
            z-index: 10500; /* Más alto que modals */
            max-width: 380px;
            pointer-events: none;
        }

        /* Estilos para cada notificación */
        .notificacion {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15), 0 4px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 12px;
            padding: 18px 22px;
            border-left: 5px solid;
            pointer-events: auto;
            animation: slideInBounce 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            position: relative;
            min-height: 70px;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            max-width: 100%;
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        /* Colores mejorados según el tipo */
        .notificacion.success {
            border-left-color: #28a745;
            background: linear-gradient(135deg, rgba(212, 237, 218, 0.95) 0%, rgba(195, 230, 203, 0.95) 100%);
            color: #155724;
        }

        .notificacion.danger {
            border-left-color: #dc3545;
            background: linear-gradient(135deg, rgba(248, 215, 218, 0.95) 0%, rgba(241, 174, 181, 0.95) 100%);
            color: #721c24;
        }

        .notificacion.warning {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, rgba(255, 243, 205, 0.95) 0%, rgba(255, 234, 167, 0.95) 100%);
            color: #856404;
        }

        .notificacion.info {
            border-left-color: #17a2b8;
            background: linear-gradient(135deg, rgba(209, 236, 241, 0.95) 0%, rgba(190, 229, 235, 0.95) 100%);
            color: #0c5460;
        }

        /* Icono de la notificación mejorado */
        .notificacion-icono {
            font-size: 24px;
            margin-right: 15px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(5px);
        }

        .notificacion.success .notificacion-icono:before {
            content: "✓";
            color: #28a745;
            font-weight: bold;
            font-size: 20px;
        }

        .notificacion.danger .notificacion-icono:before {
            content: "✖";
            color: #dc3545;
            font-weight: bold;
            font-size: 18px;
        }

        .notificacion.warning .notificacion-icono:before {
            content: "⚠";
            color: #ffc107;
            font-weight: bold;
            font-size: 20px;
        }

        .notificacion.info .notificacion-icono:before {
            content: "ℹ";
            color: #17a2b8;
            font-weight: bold;
            font-size: 20px;
        }

        /* Contenido del mensaje */
        .notificacion-contenido {
            flex: 1;
            padding-right: 35px;
            font-weight: 500;
            font-size: 14px;
            line-height: 1.4;
        }

        /* Botón de cerrar mejorado */
        .notificacion-cerrar {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.1);
            border: none;
            font-size: 16px;
            cursor: pointer;
            opacity: 0.6;
            transition: all 0.2s ease;
            padding: 6px;
            line-height: 1;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notificacion-cerrar:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.2);
            transform: scale(1.1);
        }

        /* Barra de progreso para auto-cierre mejorada */
        .notificacion-progreso {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 4px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 0 0 12px 12px;
            animation: progreso 5s linear;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Animaciones mejoradas */
        @keyframes slideInBounce {
            0% {
                transform: translateX(100%) scale(0.8);
                opacity: 0;
            }
            50% {
                transform: translateX(-8%) scale(1.05);
            }
            100% {
                transform: translateX(0) scale(1);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0) scale(1);
                opacity: 1;
            }
            to {
                transform: translateX(100%) scale(0.8);
                opacity: 0;
            }
        }

        @keyframes progreso {
            from { width: 100%; }
            to { width: 0%; }
        }

        /* Estado de salida */
        .notificacion.saliendo {
            animation: slideOut 0.4s ease-in forwards;
        }

        /* Hover effect mejorado */
        .notificacion:hover {
            transform: translateX(-8px) scale(1.02);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2), 0 6px 15px rgba(0, 0, 0, 0.15);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .notificacion:hover .notificacion-progreso {
            animation-play-state: paused;
        }

        /* Efecto cuando hay múltiples notificaciones */
        .notificacion:nth-child(2) {
            animation-delay: 0.1s;
        }

        .notificacion:nth-child(3) {
            animation-delay: 0.2s;
        }

        .notificacion:nth-child(4) {
            animation-delay: 0.3s;
        }

        /* Prevención de scroll automático */
        html, body {
            scroll-behavior: auto !important;
        }

        .modal {
            scroll-behavior: auto !important;
        }
    </style>
    
    <!-- Scripts: Bootstrap y JavaScript personalizado al final para mejor rendimiento -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
    
    <!-- NUEVO: Script inline para aplicar estado del sidebar ANTES de que se muestre la página -->
    <script>
        // Aplicar estado del sidebar inmediatamente, antes de que se renderice
        (function() {
            const savedState = localStorage.getItem('sidebarCollapsed');
            const isCollapsed = savedState === 'true';
            
            if (isCollapsed) {
                // Agregar clase CSS para estado colapsado antes del renderizado
                document.documentElement.classList.add('sidebar-collapsed-init');
            }
        })();
    </script>
    
    <!-- NUEVO: CSS inline para aplicar estilos inmediatamente -->
    <style id="sidebar-initial-state">
        /* Aplicar estilos de sidebar colapsado inmediatamente si está guardado así */
        html.sidebar-collapsed-init #sidebar {
            width: 80px !important;
        }
        
        html.sidebar-collapsed-init main {
            margin-left: 80px !important;
            width: calc(100% - 80px) !important;
        }
        
        html.sidebar-collapsed-init .nav-text,
        html.sidebar-collapsed-init .logo-text,
        html.sidebar-collapsed-init .chevron,
        html.sidebar-collapsed-init .menu-indicator,
        html.sidebar-collapsed-init .status-indicator,
        html.sidebar-collapsed-init .user-text {
            opacity: 0 !important;
        }
        
        html.sidebar-collapsed-init .logo-img {
            max-height: 60px !important;
        }
        
        html.sidebar-collapsed-init .sidebar-toggle i {
            transform: rotate(180deg) !important;
        }
    </style>
</head>

	<!-- CSS adicional para selector de roles -->
<style>
.rol-selector-container {
    border-top: 1px solid rgba(255,255,255,0.1);
    margin-top: 10px;
    padding-top: 10px;
}

.dropdown-menu-dark {
    background-color: rgba(0,0,0,0.9);
    border: 1px solid rgba(255,255,255,0.2);
    backdrop-filter: blur(10px);
}

.dropdown-menu-dark .dropdown-item {
    color: white;
    transition: all 0.2s ease;
    padding: 8px 16px;
}

.dropdown-menu-dark .dropdown-item:hover {
    background-color: rgba(255,255,255,0.1);
    color: #ffc107;
}

.dropdown-menu-dark .dropdown-item i {
    margin-right: 8px;
    width: 16px;
}

.rol-selector-container .btn-outline-light {
    border-color: rgba(255,255,255,0.3);
    transition: all 0.2s ease;
}

.rol-selector-container .btn-outline-light:hover {
    background-color: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.5);
    color: white;
}

#rol-actual {
    font-size: 0.9rem;
    padding: 6px 10px;
    background: linear-gradient(45deg, rgba(255,193,7,0.3), rgba(255,193,7,0.1));
    border-radius: 6px;
    border: 1px solid rgba(255,193,7,0.4);
    text-align: center;
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* Indicador visual en el header principal */
.header-rol-indicator {
    background: linear-gradient(45deg, #ffc107, #fd7e14);
    color: #000;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 15px;
    display: inline-block;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    animation: pulseRol 2s infinite;
}

@keyframes pulseRol {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* Animación de carga */
.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Responsive para el selector */
@media (max-width: 767.98px) {
    .rol-selector-container {
        padding: 10px;
    }
    
    #rol-actual {
        font-size: 0.8rem;
        padding: 4px 8px;
    }
    
    .header-rol-indicator {
        font-size: 0.7rem;
        padding: 2px 8px;
        margin-left: 8px;
    }
}
</style>
	
<body>
    <!-- CONTENEDOR PARA NOTIFICACIONES FLOTANTES -->
    <div id="notificaciones-container" class="notificaciones-container"></div>

    <!-- Botón para mobile -->
    <button class="mobile-toggle d-md-none" onclick="toggleMobileSidebar()">
        <i class="bi bi-list"></i>
    </button>

    <div class="container-fluid">
        <div class="row">
            <!-- Barra lateral (solo para usuarios logueados) -->
            <?php if (isset($_SESSION['user_id'])): ?>
            <nav id="sidebar">
                <!-- Botón toggle -->
                <div class="sidebar-toggle" onclick="toggleSidebar()">
                    <i class="bi bi-chevron-left"></i>
                </div>

                <div class="position-sticky pt-3">
                    <div class="sidebar-header">
                        <!-- Logo de la escuela (versión local) -->
                        <img src="assets/img/logo.png" alt="Logo Escuela" class="logo-img mb-3">
                        <div class="logo-text">
                            <h6>Escuela Técnica Henry Ford</h6>
                        </div>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="index.php">
                                <i class="bi bi-house-door"></i>
                                <span class="nav-text">Inicio</span>
                                <div class="nav-tooltip">Inicio</div>
                            </a>
                        </li>
                        
                        <!-- MENÚ PARA ADMINISTRADORES Y DIRECTIVOS -->
                        <?php if ($_SESSION['user_type'] == 'admin' || $_SESSION['user_type'] == 'directivo'): ?>
                        
                            <!-- Dashboard de Seguimiento (NUEVO) -->
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard_seguimiento.php' ? 'active' : '' ?>" href="dashboard_seguimiento.php">
                                <i class="bi bi-graph-up"></i>
                                <span class="nav-text">Seguimiento</span>
                                <div class="nav-tooltip">Dashboard de Seguimiento</div>
                            </a>
                        </li>
						
						<li class="nav-item d-none">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'importar_calificaciones.php' ? 'active' : '' ?>" href="importar_calificaciones.php">
                                <i class="bi bi-arrow-down d-none"></i>
                                <span class="nav-text d-none">Importar Calificaciones</span>
                                <div class="nav-tooltip">Importar Calificaciones</div>
                            </a>
                        </li>
						
						<li class="nav-item d-none">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'importar_contenidos.php' ? 'active' : '' ?>" href="importar_contenidos.php">
                                <i class="bi bi-arrow-down d-none"></i>
                                <span class="nav-text d-none">Importar Contenidos</span>
                                <div class="nav-tooltip">Importar Contenidos</div>
                            </a>
                        </li>
                        
                            <!-- Gestión de Usuarios -->
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'usuarios.php' ? 'active' : '' ?>" href="usuarios.php">
                                <i class="bi bi-people"></i>
                                <span class="nav-text">Usuarios</span>
                                <div class="nav-tooltip">Gestión de Usuarios</div>
                            </a>
                        </li>
                        
                        <!-- Gestión Académica -->
                        <li class="nav-item">
                            <a class="nav-link submenu-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['cursos.php', 'materias.php', 'gestionar_subgrupos.php']) ? 'active' : '' ?>" 
                               href="#" role="button" 
                               onclick="toggleSubmenu(event, 'menuAcademico')">
                                <i class="bi bi-journal-text"></i>
                                <span class="nav-text">Gestión Académica</span>
                                <i class="bi bi-chevron-down chevron"></i>
                                <div class="nav-tooltip">Gestión Académica</div>
                            </a>
                            <div class="nav-submenu <?= in_array(basename($_SERVER['PHP_SELF']), ['cursos.php', 'materias.php', 'gestionar_subgrupos.php']) ? 'show' : '' ?>" id="menuAcademico">
                                <ul class="nav flex-column">
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'cursos.php' ? 'active' : '' ?>" href="cursos.php">
                                            <i class="bi bi-book"></i>
                                            <span class="nav-text">Cursos</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'materias.php' ? 'active' : '' ?>" href="materias.php">
                                            <i class="bi bi-journal-text"></i>
                                            <span class="nav-text">Materias</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'gestionar_subgrupos.php' ? 'active' : '' ?>" href="gestionar_subgrupos.php">
                                            <i class="bi bi-people-fill"></i>
                                            <span class="nav-text">Rotaciones</span>
                                            <span class="badge bg-info badge-new d-none">Nuevo</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'materias_pendientes.php' ? 'active' : '' ?>" href="materias_pendientes.php">
                                            <i class="bi bi-people-fill"></i>
                                            <span class="nav-text">Materias Pendientes</span>
                                            <span class="badge bg-info badge-new d-none">Nuevo</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        
						<li class="nav-item">
							<a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'contenidos.php' || basename($_SERVER['PHP_SELF']) == 'contenidos_admin.php' ? 'active' : '' ?>" 
							   href="contenidos_admin.php">
								<i class="bi bi-list-check"></i>
                                <span class="nav-text">Contenidos</span>
                                <div class="nav-tooltip">Gestión de Contenidos</div>
							</a>
						</li>
						
                        <!-- Calificaciones y Evaluación (ACTUALIZADO) -->
                        <li class="nav-item">
                            <a class="nav-link submenu-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['calificaciones.php', 'gestionar_observaciones.php', 'gestionar_bloqueos_calificaciones.php', 'configurar_periodos.php']) ? 'active' : '' ?>" 
                               href="#" role="button" 
                               onclick="toggleSubmenu(event, 'menuCalificaciones')">
                                <i class="bi bi-pencil-square"></i>
                                <span class="nav-text">Calificaciones</span>
                                <?php
                                // Mostrar indicador de estado de bloqueos
                                if (isset($_SESSION['user_id'])) {
                                    try {
                                        require_once 'config.php';
                                        $db = Database::getInstance();
                                        $cicloActivo = $db->fetchOne("SELECT id FROM ciclos_lectivos WHERE activo = 1");
                                        if ($cicloActivo) {
                                            $bloqueos = $db->fetchOne("SELECT bloqueo_general, 
                                                valoracion_1bim_bloqueada + desempeno_1bim_bloqueado + observaciones_1bim_bloqueadas +
                                                valoracion_3bim_bloqueada + desempeno_3bim_bloqueado + observaciones_3bim_bloqueadas +
                                                valoracion_1c_bloqueada + calificacion_1c_bloqueada + valoracion_2c_bloqueada +
                                                calificacion_2c_bloqueada + intensificacion_1c_bloqueada + calificacion_final_bloqueada +
                                                observaciones_cuatrimestrales_bloqueadas as total_bloqueados
                                                FROM bloqueos_calificaciones WHERE ciclo_lectivo_id = ?", [$cicloActivo['id']]);
                                            
                                            if ($bloqueos) {
                                                if ($bloqueos['bloqueo_general']) {
                                                    echo '<span class="menu-indicator bloqueado" title="Bloqueo general activo"></span>';
                                                } elseif ($bloqueos['total_bloqueados'] > 0) {
                                                    echo '<span class="menu-indicator parcial" title="Algunos campos bloqueados"></span>';
                                                } else {
                                                    echo '<span class="menu-indicator activo" title="Todos los campos habilitados"></span>';
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        // Si hay error, no mostrar indicador
                                    }
                                }
                                ?>
                                <i class="bi bi-chevron-down chevron"></i>
                                <div class="nav-tooltip">Calificaciones y Evaluación</div>
                            </a>
                            <div class="nav-submenu <?= in_array(basename($_SERVER['PHP_SELF']), ['calificaciones.php', 'gestionar_observaciones.php', 'gestionar_bloqueos_calificaciones.php', 'configurar_periodos.php']) ? 'show' : '' ?>" id="menuCalificaciones">
                                <ul class="nav flex-column">
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'calificaciones.php' ? 'active' : '' ?>" href="calificaciones.php">
                                            <i class="bi bi-calculator"></i>
                                            <span class="nav-text">Cargar Notas</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'admin_materias_pendientes.php' ? 'active' : '' ?>" href="admin_materias_pendientes.php">
                                            <i class="bi bi-calculator"></i>
                                            <span class="nav-text">Control Materias Pendientes</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link nav-link-bloqueo <?= basename($_SERVER['PHP_SELF']) == 'gestionar_bloqueos_calificaciones.php' ? 'active' : '' ?>" href="gestionar_bloqueos_calificaciones.php">
                                            <i class="bi bi-shield-lock"></i>
                                            <span class="nav-text">Gestionar Bloqueos</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'configurar_periodos.php' ? 'active' : '' ?>" href="configurar_periodos.php">
                                            <i class="bi bi-calendar-range"></i>
                                            <span class="nav-text">Configurar Períodos</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'gestionar_observaciones.php' ? 'active' : '' ?>" href="gestionar_observaciones.php">
                                            <i class="bi bi-chat-text"></i>
                                            <span class="nav-text">Observaciones</span>
                                            <span class="badge bg-success badge-new d-none">Nuevo</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        					
                        <!-- Asistencias -->
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'asistencias.php' ? 'active' : '' ?>" href="asistencias.php">
                                <i class="bi bi-calendar-check"></i>
                                <span class="nav-text">Asistencias</span>
                                <div class="nav-tooltip">Control de Asistencias</div>
                            </a>
                        </li>
                        
                        <!-- Comunicaciones y Emails (NUEVO MENÚ) -->
                        <li class="nav-item">
                            <a class="nav-link submenu-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['config_emails.php', 'gestionar_emails.php']) ? 'active' : '' ?>" 
                               href="#" role="button" 
                               onclick="toggleSubmenu(event, 'menuComunicaciones')">
                                <i class="bi bi-envelope"></i>
                                <span class="nav-text">Comunicaciones</span>
                                <i class="bi bi-chevron-down chevron"></i>
                                <div class="nav-tooltip">Gestión de Emails</div>
                            </a>
                            <div class="nav-submenu <?= in_array(basename($_SERVER['PHP_SELF']), ['config_emails.php', 'gestionar_emails.php']) ? 'show' : '' ?>" id="menuComunicaciones">
                                <ul class="nav flex-column">
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'config_emails.php' ? 'active' : '' ?>" href="config_emails.php">
                                            <i class="bi bi-gear"></i>
                                            <span class="nav-text">Configurar Email</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'gestionar_emails.php' ? 'active' : '' ?>" href="gestionar_emails.php">
                                            <i class="bi bi-people"></i>
                                            <span class="nav-text">Emails Familias</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        
                        <!-- Boletines y Documentos -->
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'boletines.php' ? 'active' : '' ?>" href="boletines.php">
                                <i class="bi bi-file-text"></i>
                                <span class="nav-text">Boletines (RITE)</span>
                                <div class="nav-tooltip">Boletines (RITE)</div>
                            </a>
                        </li>
						
						<li class="nav-item">
							<a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reporte_contenidos_alumno.php' ? 'active' : '' ?>" 
							   href="reporte_contenidos_alumno.php">
								<i class="bi bi-file-earmark-person"></i>
                                <span class="nav-text">Informe de Contenidos</span>
                                <div class="nav-tooltip">Informe de Contenidos</div>
							</a>
						</li>
                        
                        <!-- Reportes y Estadísticas -->
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reportes.php' ? 'active' : '' ?>" href="reportes.php">
                                <i class="bi bi-bar-chart"></i>
                                <span class="nav-text">Informes</span>
                                <div class="nav-tooltip">Informes y Estadísticas</div>
                            </a>
                        </li>
                        
                        <?php endif; ?>
                        
                        <!-- MENÚ PARA PRECEPTORES -->
                        <?php if ($_SESSION['user_type'] == 'preceptor'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'asistencias.php' ? 'active' : '' ?>" href="asistencias.php">
                                <i class="bi bi-calendar-check"></i>
                                <span class="nav-text">Asistencias</span>
                                <div class="nav-tooltip">Control de Asistencias</div>
                            </a>
                        </li>
                        
                        <!-- Comunicaciones para Preceptores (NUEVO) -->
                        <li class="nav-item">
                            <a class="nav-link submenu-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['config_emails.php', 'gestionar_emails.php']) ? 'active' : '' ?>" 
                               href="#" role="button" 
                               onclick="toggleSubmenu(event, 'menuComunicacionesPreceptor')">
                                <i class="bi bi-envelope"></i>
                                <span class="nav-text">Comunicaciones</span>
                                <i class="bi bi-chevron-down chevron"></i>
                                <div class="nav-tooltip">Gestión de Emails</div>
                            </a>
                            <div class="nav-submenu <?= in_array(basename($_SERVER['PHP_SELF']), ['config_emails.php', 'gestionar_emails.php']) ? 'show' : '' ?>" id="menuComunicacionesPreceptor">
                                <ul class="nav flex-column">
                                    <li class="nav-item">
                                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'gestionar_emails.php' ? 'active' : '' ?>" href="gestionar_emails.php">
                                            <i class="bi bi-people"></i>
                                            <span class="nav-text">Emails Familias</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'boletines.php' ? 'active' : '' ?>" href="boletines.php">
                                <i class="bi bi-file-text"></i>
                                <span class="nav-text">Boletines (RITE)</span>
                                <div class="nav-tooltip">Boletines (RITE)</div>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reportes.php' ? 'active' : '' ?>" href="reportes.php">
                                <i class="bi bi-bar-chart"></i>
                                <span class="nav-text">Informes</span>
                                <div class="nav-tooltip">Informes y Estadísticas</div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <!-- MENÚ PARA PROFESORES -->
                        <?php if ($_SESSION['user_type'] == 'profesor'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mis_materias.php' ? 'active' : '' ?>" href="mis_materias.php">
                                <i class="bi bi-journal-bookmark"></i>
                                <span class="nav-text">Mis Materias</span>
                                <div class="nav-tooltip">Mis Materias Asignadas</div>
                            </a>
                        </li>
						<li class="nav-item">
							<a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'contenidos.php' ? 'active' : '' ?>" href="contenidos.php">
								<i class="bi bi-list-check"></i>
                                <span class="nav-text">Contenidos</span>
                                <div class="nav-tooltip">Gestión de Contenidos</div>
							</a>
						</li>							
						<li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'calificaciones.php' ? 'active' : '' ?>" href="calificaciones.php">
                                <i class="bi bi-pencil-square"></i>
                                <span class="nav-text">Cargar Calificaciones</span>
                                <?php
                                // Mostrar indicador para profesores también
                                if (isset($_SESSION['user_id'])) {
                                    try {
                                        require_once 'config.php';
                                        $db = Database::getInstance();
                                        $cicloActivo = $db->fetchOne("SELECT id FROM ciclos_lectivos WHERE activo = 1");
                                        if ($cicloActivo) {
                                            $bloqueos = $db->fetchOne("SELECT bloqueo_general FROM bloqueos_calificaciones WHERE ciclo_lectivo_id = ?", [$cicloActivo['id']]);
                                            
                                            if ($bloqueos && $bloqueos['bloqueo_general']) {
                                                echo '<span class="menu-indicator bloqueado" title="Acceso bloqueado"></span>';
                                            }
                                        }
                                    } catch (Exception $e) {
                                        // Si hay error, no mostrar indicador
                                    }
                                }
                                ?>
                                <div class="nav-tooltip">Cargar Calificaciones</div>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'calificar_materias_pendientes.php' ? 'active' : '' ?>" href="calificar_materias_pendientes.php">
                                <i class="bi bi-pencil-square"></i>
                                <span class="nav-text">Materias Pendientes</span>
                                <div class="nav-tooltip">Cargar Calificaciones a Materias Pendientes</div>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reportes.php' ? 'active' : '' ?>" href="reportes.php">
                                <i class="bi bi-bar-chart"></i>
                                <span class="nav-text">Informes</span>
                                <div class="nav-tooltip">Informes y Estadísticas</div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <!-- MENÚ PARA ESTUDIANTES -->
                        <?php if ($_SESSION['user_type'] == 'estudiante'): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mi_curso.php' ? 'active' : '' ?>" href="mi_curso.php">
                                <i class="bi bi-book"></i>
                                <span class="nav-text">Mi Curso</span>
                                <div class="nav-tooltip">Mi Curso</div>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mis_calificaciones.php' ? 'active' : '' ?>" href="mis_calificaciones.php">
                                <i class="bi bi-journal-check"></i>
                                <span class="nav-text">Mis Calificaciones</span>
                                <div class="nav-tooltip">Mis Calificaciones</div>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'reportes.php' ? 'active' : '' ?>" href="reportes.php">
                                <i class="bi bi-bar-chart"></i>
                                <span class="nav-text">Informes</span>
                                <div class="nav-tooltip">Informes y Estadísticas</div>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                    
                    <!-- Usuario info y logout -->
                    <div class="sidebar-user mt-auto">
                        <div class="user-text">
                            <div class="fw-bold"><?= $_SESSION['user_name'] ?></div>
                            <small><?= ucfirst($_SESSION['user_type']) ?></small>
                        </div>
                        <div class="mt-2">
                            <a href="logout.php" class="btn sidebar-logout btn-sm w-100">
                                <i class="bi bi-box-arrow-right"></i>
                                <span class="nav-text">Cerrar sesión</span>
                            </a>
                        </div>
                    </div>
					<!-- Selector de rol (solo si tiene múltiples roles) -->
<?php if (tieneMultiplesRoles()): ?>
<div class="rol-selector-container">
    <div class="user-text mb-3">
        
        <div class="fw-bold text-warning" id="rol-actual">
            <?= ucfirst(obtenerRolActivo()) ?>
        </div>
    </div>
                </div>
    
    <div class="dropdown w-100">
        <button class="btn btn-outline-light btn-sm dropdown-toggle w-100" 
                type="button" 
                id="selectorRol"
                data-bs-toggle="dropdown" 
                aria-expanded="false">
            <i class="bi bi-arrow-repeat"></i>
            <span class="nav-text">Cambiar Rol</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-dark w-100" aria-labelledby="selectorRol">
            <?php foreach ($_SESSION['roles_disponibles'] as $rol): ?>
                <?php if ($rol !== obtenerRolActivo()): ?>
                <li>
                    <a class="dropdown-item" href="#" onclick="cambiarRolUsuario('<?= htmlspecialchars($rol) ?>', event)">
                        <i class="bi bi-person-badge"></i>
                        <?= ucfirst($rol) ?>
                        <?php if ($rol === $_SESSION['rol_principal']): ?>
                            <small class="text-muted">(Principal)</small>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>
            </nav>
            <?php endif; ?>
            
            <!-- Contenido principal -->
            <main class="<?= isset($_SESSION['user_id']) ? '' : 'col-12' ?>">
                <?php if (isset($_SESSION['user_id'])): ?>
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
						<?php if (tieneMultiplesRoles() && obtenerRolActivo() !== $_SESSION['rol_principal']): ?>
    <span class="header-rol-indicator">
        <i class="bi bi-person-badge"></i>
        Vista: <?= ucfirst(obtenerRolActivo()) ?>
    </span>
<?php endif; ?>
                        <?php
                        // Título de la página basado en el nombre del archivo
                        $current_page = basename($_SERVER['PHP_SELF'], '.php');
                        switch ($current_page) {
                            case 'index':
                                echo 'Panel Principal';
                                break;
                            case 'usuarios':
                                echo 'Gestión de Usuarios';
                                break;
                            case 'importar':
                                echo 'Importar Datos';
                                break;
                            case 'importar_materias_profesores':
                                echo 'Cargar Materias y Profesores';
                                break;
                            case 'cursos':
                                echo 'Gestión de Cursos';
                                break;
                            case 'materias':
                                echo 'Gestión de Materias';
                                break;
                            case 'gestionar_subgrupos':
                                echo 'Gestión de Rotaciones';
                                break;
                            case 'calificaciones':
                                echo 'Gestión de Calificaciones';
                                break;
                            case 'gestionar_bloqueos_calificaciones':
                                echo 'Gestión de Bloqueos de Calificaciones';
                                break;
                            case 'configurar_periodos':
                                echo 'Configuración de Períodos Académicos';
                                break;
                            case 'gestionar_observaciones':
                                echo 'Gestión de Observaciones';
                                break;
                            case 'asistencias':
                                echo 'Control de Asistencias';
                                break;
                            case 'config_emails':
                                echo 'Configuración de Email';
                                break;
                            case 'gestionar_emails':
                                echo 'Gestión de Emails de Familias';
                                break;
                            case 'boletines':
                                echo 'Boletines (RITE)';
                                break;
                            case 'reportes':
                                echo 'Informes y Estadísticas';
                                break;
                            case 'mis_materias':
                                echo 'Mis Materias Asignadas';
                                break;
                            case 'mi_curso':
                                echo 'Mi Curso';
                                break;
                            case 'mis_calificaciones':
                                echo 'Mis Calificaciones';
                                break;
                            case 'actualizar_bd_subgrupos':
                                echo 'Actualización de Base de Datos';
                                break;
                            case 'actualizar_base_datos':
                                echo 'Actualización del Sistema';
                                break;
                            case 'actualizar_bd_bloqueos':
                                echo 'Actualización del Sistema de Bloqueos';
                                break;
                            default:
                                echo 'Sistema de Gestión de Calificaciones';
                        }
                        ?>
                    </h1>
                    
                    <!-- Botón Landing ETHF en el header principal (alternativa) -->
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="https://landing.henryford.edu.ar/" target="_blank" class="btn btn-landing btn-sm">
                                <i class="bi bi-globe2"></i> Landing ETHF
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- NOTIFICACIONES FLOTANTES MEJORADAS -->
                <?php if (isset($_SESSION['message'])): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // PRESERVAR posición de scroll antes de mostrar notificación
                    const currentScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
                    
                    // Pequeño delay para asegurar que la página esté cargada
                    setTimeout(function() {
                        // Mantener scroll durante la notificación
                        window.scrollTo(0, currentScrollPosition);
                        
                        mostrarNotificacion(
                            '<?= addslashes($_SESSION['message']) ?>', 
                            '<?= $_SESSION['message_type'] ?>'
                        );
                        
                        // Restaurar scroll después de mostrar notificación
                        setTimeout(() => window.scrollTo(0, currentScrollPosition), 50);
                    }, 100);
                });
                </script>
                <?php 
                    unset($_SESSION['message']);
                    unset($_SESSION['message_type']);
                endif; ?>
                
                <!-- Aquí se cargará el contenido específico de cada página -->

    <!-- JAVASCRIPT MEJORADO -->
    <script>
        // ===== VARIABLES GLOBALES =====
        let contadorNotificaciones = 0;
        let scrollPositionGuardada = 0;

        // ===== FUNCIONES DEL SIDEBAR =====
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const main = document.querySelector('main');
            sidebar.classList.toggle('collapsed');
            
            // Guardar estado en localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            
            // Ajustar margen y ancho del contenido principal
            if (isCollapsed) {
                main.style.marginLeft = '80px';
                main.style.width = 'calc(100% - 80px)';
            } else {
                main.style.marginLeft = '320px';
                main.style.width = 'calc(100% - 320px)';
            }
        }

        // FUNCIÓN CORREGIDA PARA SUBMENÚS
        function toggleSubmenu(event, submenuId) {
            event.preventDefault();
            event.stopPropagation();
            
            const submenu = document.getElementById(submenuId);
            const chevron = event.currentTarget.querySelector('.chevron');
            const link = event.currentTarget;
            
            // Cerrar todos los otros submenús
            document.querySelectorAll('.nav-submenu').forEach(otherSubmenu => {
                if (otherSubmenu.id !== submenuId && otherSubmenu.classList.contains('show')) {
                    otherSubmenu.classList.remove('show');
                    // Encontrar el chevron correspondiente y rotarlo
                    const otherChevron = document.querySelector(`[onclick*="${otherSubmenu.id}"] .chevron`);
                    if (otherChevron) {
                        otherChevron.classList.remove('rotated');
                    }
                }
            });
            
            // Toggle del submenú actual
            if (submenu.classList.contains('show')) {
                submenu.classList.remove('show');
                chevron.classList.remove('rotated');
            } else {
                submenu.classList.add('show');
                chevron.classList.add('rotated');
            }
            
            return false;
        }

        // Función para dispositivos móviles
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }

        // ===== SISTEMA DE NOTIFICACIONES MEJORADO =====
        function mostrarNotificacion(mensaje, tipo = 'info', duracion = 5000) {
            // GUARDAR posición actual ANTES de hacer cualquier cosa
            scrollPositionGuardada = window.pageYOffset || document.documentElement.scrollTop;
            
            const container = document.getElementById('notificaciones-container');
            if (!container) {
                console.error('Container de notificaciones no encontrado');
                return;
            }
            
            // Crear ID único para esta notificación
            const id = 'notif-' + (++contadorNotificaciones);
            
            // Crear elemento de notificación
            const notificacion = document.createElement('div');
            notificacion.id = id;
            notificacion.className = `notificacion ${tipo}`;
            
            notificacion.innerHTML = `
                <div class="notificacion-icono"></div>
                <div class="notificacion-contenido">${mensaje}</div>
                <button class="notificacion-cerrar" onclick="cerrarNotificacion('${id}')" title="Cerrar">&times;</button>
                <div class="notificacion-progreso"></div>
            `;
            
            // PREVENIR scroll automático durante la inserción
            const scrollY = window.scrollY;
            
            // Agregar al container
            container.appendChild(notificacion);
            
            // RESTAURAR posición inmediatamente después de agregar
            window.scrollTo(0, scrollY);
            
            // Auto-cerrar después de la duración especificada
            if (duracion > 0) {
                setTimeout(() => {
                    cerrarNotificacion(id);
                }, duracion);
            }
            
            // Hacer que la notificación sea clicable para cerrar (SIN afectar scroll)
            notificacion.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (e.target.classList.contains('notificacion-cerrar')) return;
                
                const currentScroll = window.pageYOffset;
                cerrarNotificacion(id);
                // Mantener scroll después del click
                setTimeout(() => window.scrollTo(0, currentScroll), 10);
            });
            
            // ASEGURAR que no hay scroll después de todo
            setTimeout(() => {
                window.scrollTo(0, scrollPositionGuardada);
            }, 100);
            
            return id;
        }

        function cerrarNotificacion(id) {
            // GUARDAR posición antes de cerrar
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            
            const notificacion = document.getElementById(id);
            if (!notificacion) return;
            
            // Agregar clase de salida
            notificacion.classList.add('saliendo');
            
            // Remover del DOM después de la animación
            setTimeout(() => {
                if (notificacion.parentNode) {
                    notificacion.parentNode.removeChild(notificacion);
                }
                // RESTAURAR posición después de remover
                window.scrollTo(0, currentScroll);
            }, 400);
        }

        function cerrarTodasLasNotificaciones() {
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            
            const notificaciones = document.querySelectorAll('.notificacion');
            notificaciones.forEach(notif => {
                cerrarNotificacion(notif.id);
            });
            
            // Mantener scroll después de cerrar todas
            setTimeout(() => window.scrollTo(0, currentScroll), 450);
        }

        // Función para mostrar notificaciones programáticamente CON preservación de scroll
        function notificar(mensaje, tipo = 'success') {
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            
            mostrarNotificacion(mensaje, tipo);
            
            // Triple verificación de posición de scroll
            setTimeout(() => window.scrollTo(0, currentScroll), 50);
            setTimeout(() => window.scrollTo(0, currentScroll), 150);
            setTimeout(() => window.scrollTo(0, currentScroll), 300);
        }

        // Función avanzada: notificaciones con acciones
        function mostrarNotificacionConAccion(mensaje, tipo, accionTexto, accionCallback) {
            const container = document.getElementById('notificaciones-container');
            const id = 'notif-' + (++contadorNotificaciones);
            
            const notificacion = document.createElement('div');
            notificacion.id = id;
            notificacion.className = `notificacion ${tipo}`;
            
            notificacion.innerHTML = `
                <div class="notificacion-icono"></div>
                <div class="notificacion-contenido">
                    ${mensaje}
                    <div style="margin-top: 10px;">
                        <button onclick="${accionCallback}; cerrarNotificacion('${id}')" 
                                class="btn btn-sm btn-outline-primary">${accionTexto}</button>
                    </div>
                </div>
                <button class="notificacion-cerrar" onclick="cerrarNotificacion('${id}')">&times;</button>
            `;
            
            container.appendChild(notificacion);
            
            // No auto-cerrar notificaciones con acciones
            return id;
        }

        // ===== EVENT LISTENERS Y CONFIGURACIÓN INICIAL =====
        
        // Cerrar sidebar en móvil al hacer clic fuera
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.querySelector('.mobile-toggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !toggleBtn.contains(event.target)) {
                sidebar.classList.remove('show');
            }
        });

        // Manejar redimensionamiento de ventana
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const main = document.querySelector('main');
            
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
                // Restablecer márgenes y anchos en desktop
                if (sidebar.classList.contains('collapsed')) {
                    main.style.marginLeft = '80px';
                    main.style.width = 'calc(100% - 80px)';
                } else {
                    main.style.marginLeft = '320px';
                    main.style.width = 'calc(100% - 320px)';
                }
            } else {
                main.style.marginLeft = '0';
                main.style.width = '100%';
            }
        });

        // ===== INICIALIZACIÓN =====
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const main = document.querySelector('main');
            
            // Recuperar estado guardado
            const savedState = localStorage.getItem('sidebarCollapsed');
            const isCollapsed = savedState === 'true';
            
            // Remover la clase temporal del HTML
            document.documentElement.classList.remove('sidebar-collapsed-init');
            
            // Remover el CSS temporal
            const tempStyle = document.getElementById('sidebar-initial-state');
            if (tempStyle) {
                tempStyle.remove();
            }
            
            if (window.innerWidth > 768) {
                if (isCollapsed) {
                    sidebar.classList.add('collapsed');
                    main.style.marginLeft = '80px';
                    main.style.width = 'calc(100% - 80px)';
                } else {
                    sidebar.classList.remove('collapsed');
                    main.style.marginLeft = '320px';
                    main.style.width = 'calc(100% - 320px)';
                }
            } else {
                main.style.marginLeft = '0';
                main.style.width = '100%';
            }

            // ===== RESTAURAR SCROLL SI HAY PARÁMETRO =====
            const urlParams = new URLSearchParams(window.location.search);
            const scrollPosition = urlParams.get('scroll');
            
            if (scrollPosition && scrollPosition > 0) {
                setTimeout(() => {
                    window.scrollTo({
                        top: parseInt(scrollPosition),
                        behavior: 'auto' // Sin animación para ser instantáneo
                    });
                    
                    // Limpiar el parámetro scroll de la URL
                    const newUrl = new URL(window.location);
                    newUrl.searchParams.delete('scroll');
                    window.history.replaceState({}, '', newUrl);
                }, 200);
            }

            // ===== PREVENIR SCROLL AUTOMÁTICO =====
            
            // Prevenir scroll automático por focus
            document.addEventListener('focusin', function(e) {
                const currentScroll = window.pageYOffset;
                setTimeout(() => {
                    if (window.pageYOffset !== currentScroll) {
                        window.scrollTo(0, currentScroll);
                    }
                }, 10);
            });
            
            // Prevenir scroll por cambios dinámicos en el DOM
            const observer = new MutationObserver(function(mutations) {
                const scrollBeforeChange = window.pageYOffset;
                setTimeout(() => {
                    if (Math.abs(window.pageYOffset - scrollBeforeChange) > 5) {
                        window.scrollTo(0, scrollBeforeChange);
                    }
                }, 10);
            });
            
            // Observar solo el container de notificaciones
            const container = document.getElementById('notificaciones-container');
            if (container) {
                observer.observe(container, {
                    childList: true,
                    subtree: true
                });
            }

            // ===== INICIALIZAR SUBMENÚS CORRECTAMENTE =====
            
            // Manejar clicks en enlaces de submenú que no deberían abrir/cerrar
            document.querySelectorAll('.nav-submenu .nav-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    // No prevenir - dejar que navegue normalmente
                    e.stopPropagation();
                });
            });

            // Asegurar que los submenús activos estén expandidos
            document.querySelectorAll('.nav-submenu.show').forEach(submenu => {
                const parentLink = document.querySelector(`[onclick*="${submenu.id}"]`);
                if (parentLink) {
                    const chevron = parentLink.querySelector('.chevron');
                    if (chevron) {
                        chevron.classList.add('rotated');
                    }
                }
            });
        });

        // ===== INTERCEPTAR REDIRECCIONES =====
        window.addEventListener('beforeunload', function() {
            // Guardar posición en sessionStorage como backup
            sessionStorage.setItem('scrollPosition', window.pageYOffset.toString());
        });

        window.addEventListener('load', function() {
            // Restaurar posición si existe
            const savedPosition = sessionStorage.getItem('scrollPosition');
            if (savedPosition) {
                window.scrollTo(0, parseInt(savedPosition));
                sessionStorage.removeItem('scrollPosition');
            }
        });

        // ===== FUNCIONES DE UTILIDAD =====
        
        // Función para testing de notificaciones (desarrollo)
        function testearNotificaciones() {
            notificar('Operación exitosa', 'success');
            setTimeout(() => notificar('Información importante', 'info'), 500);
            setTimeout(() => notificar('Advertencia de prueba', 'warning'), 1000);
            setTimeout(() => notificar('Error de ejemplo', 'danger'), 1500);
        }

        // Función para mostrar notificación con posición específica
        function notificarEnPosicion(mensaje, tipo = 'success', posicion = 'top-right') {
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            
            // Guardar posición del container original
            const container = document.getElementById('notificaciones-container');
            const originalTop = container.style.top;
            const originalRight = container.style.right;
            const originalLeft = container.style.left;
            
            // Ajustar posición según parámetro
            switch(posicion) {
                case 'top-left':
                    container.style.top = '80px';
                    container.style.left = '20px';
                    container.style.right = 'auto';
                    break;
                case 'bottom-right':
                    container.style.top = 'auto';
                    container.style.bottom = '20px';
                    container.style.right = '20px';
                    container.style.left = 'auto';
                    break;
                case 'bottom-left':
                    container.style.top = 'auto';
                    container.style.bottom = '20px';
                    container.style.left = '20px';
                    container.style.right = 'auto';
                    break;
                default: // top-right
                    container.style.top = '80px';
                    container.style.right = '20px';
                    container.style.left = 'auto';
                    container.style.bottom = 'auto';
            }
            
            mostrarNotificacion(mensaje, tipo);
            
            // Restaurar posición original después de 100ms
            setTimeout(() => {
                container.style.top = originalTop || '80px';
                container.style.right = originalRight || '20px';
                container.style.left = originalLeft || 'auto';
                container.style.bottom = 'auto';
            }, 100);
            
            // Mantener scroll
            setTimeout(() => window.scrollTo(0, currentScroll), 50);
        }

        // ===== ATAJOS DE TECLADO =====
        document.addEventListener('keydown', function(e) {
            // Ctrl + Alt + T para toggle del sidebar
            if (e.ctrlKey && e.altKey && e.key === 't') {
                e.preventDefault();
                toggleSidebar();
            }
            
            // Ctrl + Alt + N para notificación de prueba
            if (e.ctrlKey && e.altKey && e.key === 'n') {
                e.preventDefault();
                notificar('Notificación de prueba desde teclado', 'info');
            }
            
            // Escape para cerrar todas las notificaciones
            if (e.key === 'Escape') {
                cerrarTodasLasNotificaciones();
            }
        });

		/**
 * Cambiar rol del usuario actual
 */
function cambiarRolUsuario(nuevoRol, event) {
    event.preventDefault();
    event.stopPropagation();
    
    // Mostrar loading en el botón
    const boton = document.getElementById('selectorRol');
    const textoOriginal = boton.innerHTML;
    boton.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> <span class="nav-text">Cambiando...</span>';
    boton.disabled = true;
    
    // Cerrar dropdown
    const dropdown = bootstrap.Dropdown.getInstance(boton);
    if (dropdown) dropdown.hide();
    
    // Realizar petición
    fetch('cambiar_rol.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'rol=' + encodeURIComponent(nuevoRol)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Error en la respuesta del servidor');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Actualizar interfaz inmediatamente
            const rolActualElement = document.getElementById('rol-actual');
            if (rolActualElement) {
                rolActualElement.textContent = nuevoRol.charAt(0).toUpperCase() + nuevoRol.slice(1);
            }
            
            // Mostrar notificación
            if (typeof mostrarNotificacion === 'function') {
                mostrarNotificacion(data.mensaje || 'Rol cambiado exitosamente', 'success', 3000);
            } else {
                alert(data.mensaje || 'Rol cambiado exitosamente');
            }
            
            // Recargar página después de 1.5 segundos para aplicar cambios de menú
            setTimeout(() => {
                // Preservar scroll position
                const scrollPos = window.pageYOffset || document.documentElement.scrollTop;
                const url = new URL(window.location);
                if (scrollPos > 0) {
                    url.searchParams.set('scroll', scrollPos);
                }
                window.location.href = url.toString();
            }, 1500);
        } else {
            throw new Error(data.error || 'Error desconocido al cambiar rol');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        
        // Mostrar error
        if (typeof mostrarNotificacion === 'function') {
            mostrarNotificacion('Error al cambiar rol: ' + error.message, 'danger', 5000);
        } else {
            alert('Error al cambiar rol: ' + error.message);
        }
        
        // Restaurar botón
        boton.innerHTML = textoOriginal;
        boton.disabled = false;
    });
    
    return false;
}

// Tooltip para rol activo cuando está colapsado
document.addEventListener('DOMContentLoaded', function() {
    const rolSelector = document.querySelector('.rol-selector-container');
    if (rolSelector) {
        // Agregar tooltip cuando el sidebar está colapsado
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                        updateRolTooltip();
                    }
                });
            });
            
            observer.observe(sidebar, { attributes: true });
            updateRolTooltip(); // Aplicar inmediatamente
        }
    }
});

function updateRolTooltip() {
    const sidebar = document.getElementById('sidebar');
    const rolSelector = document.getElementById('selectorRol');
    
    if (sidebar && rolSelector) {
        if (sidebar.classList.contains('collapsed')) {
            rolSelector.setAttribute('title', 'Rol activo: ' + (document.getElementById('rol-actual')?.textContent || 'N/A'));
        } else {
            rolSelector.removeAttribute('title');
        }
    }
}
    </script>
</body>
</html>
