<?php
/**
 * vista_cuatrimestral_con_bloqueos.php - Vista cuatrimestral con sistema de bloqueos integrado
 * ACTUALIZADO: Sistema de 3 vistas separadas (1°C, 2°C, Final) con contenidos detallados
 * VERSIÓN COMPLETA CORREGIDA - Sin errores de redeclaración ni variables indefinidas
 */

// Obtener el cuatrimestre seleccionado (por defecto: final)
// Función para detectar período actual automáticamente
function detectarPeriodoActual($anioActivo) {
    $fechaActual = new DateTime();
    $año = $anioActivo;
    
    // Definir períodos del año académico
    $periodos = [
        'primer_cuatrimestre' => [
            'inicio' => new DateTime($año . '-03-03'),
            'fin' => new DateTime($año . '-07-15'),
            'intensificacion_inicio' => new DateTime($año . '-07-16'),
            'intensificacion_fin' => new DateTime($año . '-07-30')
        ],
        'segundo_cuatrimestre' => [
            'inicio' => new DateTime($año . '-08-01'),
            'fin' => new DateTime($año . '-12-07'),
            'intensificacion_dic_inicio' => new DateTime($año . '-12-09'),
            'intensificacion_dic_fin' => new DateTime($año . '-12-20'),
            'intensificacion_feb_inicio' => new DateTime(($año + 1) . '-02-10'),
            'intensificacion_feb_fin' => new DateTime(($año + 1) . '-02-28')
        ]
    ];
    
    // Determinar cuatrimestre actual
    if ($fechaActual >= $periodos['primer_cuatrimestre']['inicio'] && 
        $fechaActual <= $periodos['primer_cuatrimestre']['intensificacion_fin']) {
        return '1';
    } elseif ($fechaActual >= $periodos['segundo_cuatrimestre']['inicio'] && 
              $fechaActual <= $periodos['segundo_cuatrimestre']['intensificacion_dic_fin']) {
        return '2';
    } elseif ($fechaActual >= $periodos['segundo_cuatrimestre']['intensificacion_feb_inicio'] && 
              $fechaActual <= $periodos['segundo_cuatrimestre']['intensificacion_feb_fin']) {
        return '2'; // Intensificación febrero del ciclo anterior
    } else {
        // Por defecto, considerar primer cuatrimestre si estamos entre períodos
        return '1';
    }
}

// Detectar cuatrimestre activo automáticamente
$cuatrimestreActivo = detectarPeriodoActual($anioActivo);

// Obtener cuatrimestre seleccionado desde URL o usar el activo
$cuatrimestreVista = $_GET['cuatrimestre'] ?? $cuatrimestreActivo;
if (!in_array($cuatrimestreVista, ['1', '2', 'final'])) {
    $cuatrimestreVista = $cuatrimestreActivo;
}

// Definir variables necesarias para todas las vistas ANTES de usarlas
$estudiantesRegulares = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'regular'; });
$estudiantesRecursando = array_filter($estudiantes, function($e) { return $e['tipo_matricula'] === 'recursando'; });
$estudiantesConSubgrupos = array_filter($estudiantes, function($e) { return !empty($e['subgrupo']); });

// Obtener lista única de subgrupos disponibles
$subgruposDisponibles = [];
if (count($estudiantesConSubgrupos) > 0) {
    foreach ($estudiantes as $estudiante) {
        if (!empty($estudiante['subgrupo'])) {
            $subgrupo = trim($estudiante['subgrupo']);
            if (!in_array($subgrupo, $subgruposDisponibles)) {
                $subgruposDisponibles[] = $subgrupo;
            }
        }
    }
    // Ordenar subgrupos alfanuméricamente
    sort($subgruposDisponibles);
}

// Estadísticas por subgrupo
$estadisticasSubgrupos = [];
if (!empty($subgruposDisponibles)) {
    foreach ($subgruposDisponibles as $subgrupo) {
        $estudiantesSubgrupo = array_filter($estudiantes, function($e) use ($subgrupo) {
            return trim($e['subgrupo']) === $subgrupo;
        });
        
        $regulares = array_filter($estudiantesSubgrupo, function($e) {
            return $e['tipo_matricula'] === 'regular';
        });
        
        $recursando = array_filter($estudiantesSubgrupo, function($e) {
            return $e['tipo_matricula'] === 'recursando';
        });
        
        $estadisticasSubgrupos[$subgrupo] = [
            'total' => count($estudiantesSubgrupo),
            'regulares' => count($regulares),
            'recursando' => count($recursando)
        ];
    }
}

// Obtener contenidos si es vista de cuatrimestre específico
$contenidosCuatrimestre = [];
if ($cuatrimestreVista !== 'final' && $materiaSeleccionada) {
    $bimestresMap = [
        1 => [1, 2], // 1er cuatrimestre: bimestres 1 y 2
        2 => [3, 4]  // 2do cuatrimestre: bimestres 3 y 4
    ];
    
    $bimestres = $bimestresMap[intval($cuatrimestreVista)] ?? [];
    if (!empty($bimestres)) {
        $placeholders = implode(',', array_fill(0, count($bimestres), '?'));
        $params = array_merge([$materiaSeleccionada], $bimestres);
        
        $contenidosCuatrimestre = $db->fetchAll(
            "SELECT c.id, c.titulo, c.bimestre, c.fecha_clase, c.tipo_evaluacion, c.orden
             FROM contenidos c 
             WHERE c.materia_curso_id = ? AND c.bimestre IN ($placeholders) AND c.activo = 1
             ORDER BY c.bimestre, c.fecha_clase, c.orden, c.id",
            $params
        );
    }
}

// Verificar si la materia seleccionada existe para el sistema de restricciones
if (!isset($materiaSeleccionada) || !$materiaSeleccionada) {
    // Si no hay materia seleccionada, el sistema de restricciones no se activará
    $materiaSeleccionada = 0;
}

// Función para obtener calificaciones de contenidos por estudiante (inline)
function obtenerCalificacionesContenidosInline($db, $estudianteId, $contenidosIds) {
    if (empty($contenidosIds)) return [];
    
    $placeholders = implode(',', array_fill(0, count($contenidosIds), '?'));
    $params = array_merge([$estudianteId], $contenidosIds);
    
    $calificaciones = $db->fetchAll(
        "SELECT contenido_id, calificacion_numerica, calificacion_cualitativa
         FROM contenidos_calificaciones 
         WHERE estudiante_id = ? AND contenido_id IN ($placeholders)",
        $params
    );
    
    $resultado = [];
    foreach ($calificaciones as $cal) {
        $resultado[$cal['contenido_id']] = $cal;
    }
    
    return $resultado;
}
?>

<style>

/* CSS para el Sistema de Restricciones de Calificación (Tonos Rojos) */

/* Estilos para selects con restricciones */
.calificacion-restringida {
    border: 2px solid #ffc107 !important;
background: linear-gradient(135deg, rgba(178, 34, 34, 0.1), rgba(255, 100, 100, 0.9)) !important;
    position: relative;
}

.calificacion-restringida:focus {
    border-color:rgb(255, 51, 0) !important;
    box-shadow: 0 0 0 0.2rem rgba(255, 99, 71, 0.25) !important;
}

/* Opciones bloqueadas en el select */
.calificacion-restringida option.opcion-bloqueada {
    background-color: #f8f9fa !important;
    color:rgb(172, 10, 37) !important;
    text-decoration: line-through;
}

.calificacion-restringida option.opcion-bloqueada:before {
    content: "🔒 ";
}

/* Información de restricción */
.restriccion-info {
    font-size: 0.75rem;
    margin-top: 2px;
}

.restriccion-info .btn-xs {
    font-size: 0.6rem;
    padding: 0.1rem 0.3rem;
    border-width: 1px;
}

/* Animación para notificaciones */
@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Estilos para alertas de restricción */
.alert-restriccion {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    border-color: #ffc107;
    color: #856404;
    position: relative;
    overflow: hidden;
}

.alert-restriccion::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: #ffc107;
}

/* Iconos de restricción */
.icono-restriccion {
    color: #ffc107;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% {
        opacity: 1;
    }
    50% {
        opacity: 0.6;
    }
    100% {
        opacity: 1;
    }
}

/* Tooltip mejorado para campos restringidos */
.calificacion-restringida[title]:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    background: #ffc107;
    color: #212529;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    margin-bottom: 5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

/* Estilos para modal de detalles */
.modal-detalle-restriccion .table-sm th,
.modal-detalle-restriccion .table-sm td {
    padding: 0.4rem;
    font-size: 0.85rem;
}

.modal-detalle-restriccion .badge {
    font-size: 0.7rem;
}

/* Estilos para estados de contenidos en la tabla */
.estado-no-acreditado {
    background-color: #f8d7da;
    color: #721c24;
    border-color: #f5c6cb;
}

.estado-acreditado {
    background-color: #d1e7dd;
    color: #0f5132;
    border-color: #badbcc;
}

.estado-no-corresponde {
    background-color: #e2e3e5;
    color: #41464b;
    border-color: #d3d6db;
}

.estado-sin-calificar {
    background-color: #fff3cd;
    color: #856404;
    border-color: #ffeaa7;
}

/* Efectos visuales para campos con restricciones activas */
.calificacion-restringida {
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 10px,
        rgba(255, 193, 7, 0.1) 10px,
        rgba(255, 193, 7, 0.1) 20px
    );
}

/* Hover effects mejorados */
.calificacion-restringida:hover {
    border-color: #ff8c00 !important;
    transform: scale(1.02);
    transition: all 0.2s ease;
}

/* Indicador visual de restricción activa */
.calificacion-restringida::before {
    content: '🔒';
    position: absolute;
    top: 2px;
    right: 2px;
    font-size: 10px;
    z-index: 10;
    background: rgba(255, 193, 7, 0.9);
    border-radius: 50%;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Estilos responsive */
@media (max-width: 768px) {
    .restriccion-info {
        font-size: 0.7rem;
    }
    
    .restriccion-info .btn-xs {
        font-size: 0.55rem;
        padding: 0.05rem 0.2rem;
    }
    
    .calificacion-restringida::before {
        font-size: 8px;
        width: 12px;
        height: 12px;
    }
}

/* Animación de entrada para elementos de restricción */
.restriccion-info {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Estilos para botones de acción en restricciones */
.btn-ver-detalles-restriccion {
    font-size: 0.65rem;
    padding: 0.1rem 0.4rem;
    border-radius: 3px;
    line-height: 1.2;
}

/* Loading state para verificación de contenidos */
.verificando-contenidos {
    opacity: 0.7;
    pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23ffc107' d='M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,19a8,8,0,1,1,8-8A8,8,0,0,1,12,20Z' opacity='.25'/%3E%3Cpath fill='%23ffc107' d='M12,4a8,8,0,0,1,7.89,6.7A1.53,1.53,0,0,0,21.38,12h0a1.5,1.5,0,0,0,1.48-1.75,11,11,0,0,0-21.72,0A1.5,1.5,0,0,0,2.62,12h0a1.53,1.53,0,0,0,1.49-1.3A8,8,0,0,1,12,4Z'%3E%3CanimateTransform attributeName='transform' dur='0.75s' repeatCount='indefinite' type='rotate' values='0 12 12;360 12 12'/%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 16px;
}

/* Estilos para contadores en restricciones */
.contador-restricciones {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
    color: white;
    border-radius: 50%;
    font-size: 0.6rem;
    font-weight: bold;
    padding: 2px 6px;
    min-width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: 4px;
}

/* Efectos de hover para elementos interactivos */
.restriccion-info button:hover {
    transform: scale(1.1);
    transition: transform 0.1s ease;
}

/* Estilos para alertas de sistema en restricciones */
.alert-sistema-restriccion {
    border-left: 4px solid #ffc107;
    background: linear-gradient(90deg, rgba(255, 193, 7, 0.1), transparent);
}

/* Dark mode support (opcional) */
@media (prefers-color-scheme: dark) {
    .calificacion-restringida {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(33, 37, 41, 0.9)) !important;
        border-color: #ffc107 !important;
        color: #fff !important;
    }
    
    .restriccion-info {
        color: #ffc107 !important;
    }
    
    .calificacion-restringida option.opcion-bloqueada {
        background-color: #343a40 !important;
        color: #868e96 !important;
    }
}

/* Animación del icono de colapso de tabla */
#icono-tabla-toggle-<?= $cuatrimestreVista ?> {
    transition: transform 0.3s ease;
}

.collapsed #icono-tabla-toggle-<?= $cuatrimestreVista ?> {
    transform: rotate(180deg);
}

/* Estilos para filas de contenido */
.fila-contenido {
    transition: all 0.2s ease;
}

.fila-contenido:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

.contenido-ok {
    border-left: 3px solid #28a745;
}

.contenido-pendiente {
    border-left: 3px solid #ffc107;
}

/* Filtros de estado */
.btn-check:checked + .btn-outline-secondary {
    background-color: #6c757d;
    border-color: #6c757d;
}

.btn-check:checked + .btn-outline-success {
    background-color: #28a745;
    border-color: #28a745;
}

.btn-check:checked + .btn-outline-warning {
    background-color: #ffc107;
    border-color: #ffc107;
    color: #000;
}

/* Animación para ocultar filas */
.fila-contenido.oculta {
    opacity: 0.3;
    transform: scale(0.95);
}
.contenido-item {
    transition: all 0.2s ease;
    border: 1px solid #dee2e6 !important;
}

.contenido-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-color: #007bff !important;
}

.badge-sm {
    font-size: 0.65rem;
}

/* Animación del icono de colapso */
#icono-toggle-<?= $cuatrimestreVista ?> {
    transition: transform 0.3s ease;
}

.collapsed #icono-toggle-<?= $cuatrimestreVista ?> {
    transform: rotate(180deg);
}

/* Animación suave del colapso */
.collapse {
    transition: all 0.35s ease;
}
/* Estilos para badges de desempeño académico */
.campo-desempeno {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 60px;
}

.badge-desempeno {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 80px;
    min-height: 35px;
    padding: 8px 12px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.75rem;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
    border: 1px solid transparent;
    cursor: help;
}

.desempeno-valor {
    margin-right: 18px;
    line-height: 1.2;
}

.desempeno-icono {
    position: absolute;
    top: 2px;
    right: 3px;
    font-size: 10px;
    opacity: 0.7;
}

/* Estilos para badges de valoración (TEA/TEP/TED) */
.campo-valoracion {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 60px;
}

.badge-valoracion {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 80px;
    min-height: 35px;
    padding: 8px 12px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.8rem;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
    border: 1px solid transparent;
    cursor: help;
    letter-spacing: 0.5px;
}

.valoracion-valor {
    margin-right: 18px;
    line-height: 1.2;
    font-weight: 700;
}

.valoracion-icono {
    position: absolute;
    top: 2px;
    right: 3px;
    font-size: 10px;
    opacity: 0.8;
}

/* Colores específicos para valoraciones */
.badge-valoracion-tea {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border-color: #1e7e34;
}

.badge-valoracion-tep {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
    color: #212529;
    border-color: #d39e00;
}

.badge-valoracion-ted {
    background: linear-gradient(135deg, #dc3545, #bd2130);
    color: white;
    border-color: #bd2130;
}

.badge-valoracion-vacio {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    color: #6c757d;
    border: 1px dashed #ced4da;
    font-style: italic;
    opacity: 0.7;
}

/* Colores específicos para cada tipo de desempeño */
.badge-desempeno-excelente {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
    border-color: #1e7e34;
}

.badge-desempeno-muy-bueno {
    background: linear-gradient(135deg, #17a2b8, #6f42c1);
    color: white;
    border-color: #138496;
}

.badge-desempeno-bueno {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
    border-color: #0056b3;
}

.badge-desempeno-regular {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
    color: #212529;
    border-color: #d39e00;
}

.badge-desempeno-malo {
    background: linear-gradient(135deg, #dc3545, #bd2130);
    color: white;
    border-color: #bd2130;
}

.badge-desempeno-neutro {
    background: linear-gradient(135deg, #6c757d, #5a6268);
    color: white;
    border-color: #545b62;
}

.badge-sin-dato {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    color: #6c757d;
    border: 1px dashed #ced4da;
    font-style: italic;
    opacity: 0.7;
}

/* Estilos para selects de valoración cuando están habilitados */
.valoracion-select {
    border-radius: 8px;
    border-width: 2px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.select-tea {
    border-color: #28a745;
    background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(32, 201, 151, 0.1));
    color: #1e7e34;
}

.select-tep {
    border-color: #ffc107;
    background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(253, 126, 20, 0.1));
    color: #856404;
}

.select-ted {
    border-color: #dc3545;
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(189, 33, 48, 0.1));
    color: #721c24;
}

.select-vacio {
    border-color: #ced4da;
    background: #f8f9fa;
    color: #6c757d;
}

/* Estilos para badges de contenidos */
.badge-contenido {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.25rem 0.4rem;
    border-radius: 4px;
    min-width: 25px;
    display: inline-block;
    text-align: center;
    cursor: help;
    transition: all 0.2s ease;
}

.contenido-cell {
    min-width: 35px;
    padding: 0.25rem !important;
}

.badge-contenido:hover {
    transform: scale(1.1);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Navegación de pestañas mejorada */
.nav-pills .nav-link {
    color: #6c757d;
    border-radius: 8px;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.nav-pills .nav-link:hover {
    background-color: #f8f9fa;
    color: #495057;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.nav-pills .nav-link.active {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
    border-color: #0056b3;
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.nav-pills .nav-link.active:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
    transform: translateY(-2px);
}

.nav-pills .nav-link small {
    font-size: 0.7rem;
    opacity: 0.8;
}

/* Responsive para móviles */
@media (max-width: 768px) {
    .badge-desempeno,
    .badge-valoracion {
        min-width: 70px;
        min-height: 30px;
        padding: 6px 8px;
        font-size: 0.7rem;
    }
    
    .desempeno-valor,
    .valoracion-valor {
        margin-right: 15px;
    }
    
    .nav-pills .nav-link small {
        display: none;
    }
}

/* Animaciones */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card.mb-3 {
    animation: slideIn 0.3s ease-out;
}

/* Efectos hover para badges */
.badge-valoracion:hover,
.badge-desempeno:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

/* Focus states para selects */
.valoracion-select:focus {
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.select-tea:focus {
    box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
}

.select-tep:focus {
    box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25);
}

.select-ted:focus {
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

/* Texto informativo debajo del badge */
.campo-desempeno small,
.campo-valoracion small {
    font-size: 0.65rem;
    font-weight: 500;
    margin-top: 4px;
}

/* Estilos para la tabla responsive */
.table-responsive {
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.table-sm th, .table-sm td {
    padding: 0.4rem 0.3rem;
    font-size: 0.85rem;
}

.table-sm th {
    font-size: 0.75rem;
    font-weight: 600;
}

/* Tooltips mejorados para contenidos */
th[title] {
    cursor: help;
    position: relative;
}

th[title]:hover {
    background-color: #e9ecef !important;
}

/* Mejoras adicionales para coherencia visual */
.badge-valoracion-tea,
.badge-desempeno-excelente {
    background: linear-gradient(135deg, #28a745, #20c997);
}

.badge-valoracion-tep,
.badge-desempeno-regular {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
}

.badge-valoracion-ted,
.badge-desempeno-malo {
    background: linear-gradient(135deg, #dc3545, #bd2130);
}

/* Mejoras para badges de contenidos */
.badge-contenido.bg-success {
    background: linear-gradient(135deg, #28a745, #20c997) !important;
}

.badge-contenido.bg-danger {
    background: linear-gradient(135deg, #dc3545, #bd2130) !important;
}

.badge-contenido.bg-secondary {
    background: linear-gradient(135deg, #6c757d, #5a6268) !important;
}
</style>

<!-- Navegación por pestañas -->
<div class="card mb-3">
    <div class="card-body p-3">
        <ul class="nav nav-pills nav-fill" id="cuatrimestreTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $cuatrimestreVista === '1' ? 'active' : '' ?>"
                   href="?curso=<?= $cursoSeleccionado ?>&materia=<?= $materiaSeleccionada ?>&tipo=<?= $tipoCarga ?>&cuatrimestre=1">
                    <i class=""></i> 1° Cuatrimestre
                    <small class="d-block">Contenidos + Calificación</small>
                    <span class="badge bg-success ms-1" style="<?= $cuatrimestreActivo === '1' ? '' : 'visibility: hidden;' ?>">Actual</span>
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $cuatrimestreVista === '2' ? 'active' : '' ?>"
                   href="?curso=<?= $cursoSeleccionado ?>&materia=<?= $materiaSeleccionada ?>&tipo=<?= $tipoCarga ?>&cuatrimestre=2">
                    <i class=""></i> 2° Cuatrimestre
                    <small class="d-block">Contenidos + Calificación</small>
                    <span class="badge bg-success ms-1" style="<?= $cuatrimestreActivo === '2' ? '' : 'visibility: hidden;' ?>">Actual</span>
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $cuatrimestreVista === 'final' ? 'active' : '' ?>"
                   href="?curso=<?= $cursoSeleccionado ?>&materia=<?= $materiaSeleccionada ?>&tipo=<?= $tipoCarga ?>&cuatrimestre=final">
                    <i class="bi bi-trophy"></i> Calificación Final
                    <small class="d-block">Vista Completa</small>
                    <span class="badge bg-success ms-1" style="visibility: hidden;">Actual</span>
                </a>
            </li>
        </ul>
    </div>
</div>

<?php if ($cuatrimestreVista === 'final'): ?>
    <!-- VISTA FINAL: Vista completa de calificaciones cuatrimestrales -->
    
    <div class="card">
        <div class="card-header bg-success text-white">
            <h6 class="card-title mb-0">
                <i class="bi bi-journal-text"></i> 
                Calificaciones Cuatrimestrales
                
                <!-- Indicadores de bloqueo en el encabezado -->
                <?php if (estaColumnaBloqueada($configuracionBloqueos, 'valoracion_1c_bloqueada') || estaColumnaBloqueada($configuracionBloqueos, 'calificacion_1c_bloqueada')): ?>
                    <span class="badge bg-danger ms-2">
                        <i class="bi bi-lock"></i> 1°C Bloqueado
                    </span>
                <?php endif; ?>
                <?php if (estaColumnaBloqueada($configuracionBloqueos, 'valoracion_2c_bloqueada') || estaColumnaBloqueada($configuracionBloqueos, 'calificacion_2c_bloqueada')): ?>
                    <span class="badge bg-warning">
                        <i class="bi bi-lock"></i> 2°C Bloqueado
                    </span>
                <?php endif; ?>
                <?php if (estaColumnaBloqueada($configuracionBloqueos, 'calificacion_final_bloqueada')): ?>
                    <span class="badge bg-info">
                        <i class="bi bi-lock"></i> Final Bloqueada
                    </span>
                <?php endif; ?>
            </h6>
        </div>
        <div class="card-body">
            <?php if (!empty($estudiantes)): ?>
            
            <!-- Información sobre los tipos de estudiantes -->
            <?php if (count($estudiantesRecursando) > 0 || count($estudiantesConSubgrupos) > 0): ?>
            <div class="alert alert-info mb-3">
                <h6><i class="bi bi-info-circle"></i> Información de la lista:</h6>
                <ul class="mb-0">
                    <?php if (count($estudiantesRegulares) > 0): ?>
                    <li><strong><?= count($estudiantesRegulares) ?></strong> estudiantes regulares del curso</li>
                    <?php endif; ?>
                    <?php if (count($estudiantesRecursando) > 0): ?>
                    <li><strong><?= count($estudiantesRecursando) ?></strong> estudiantes recursando esta materia</li>
                    <?php endif; ?>
                    <?php if (count($estudiantesConSubgrupos) > 0): ?>
                    <li><strong><?= count($estudiantesConSubgrupos) ?></strong> estudiantes asignados a subgrupos</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Panel de estado de campos y alertas -->
            <div class="row mb-3">
                <!-- Alerta sobre campos protegidos -->
                <div class="col-md-8">
                    <div class="alert alert-warning">
                        <i class="bi bi-lock"></i> <strong>Campos con restricciones:</strong> 
                        Los campos marcados con <i class="bi bi-shield-lock text-primary"></i> pueden estar bloqueados según la configuración del sistema.
                        <small class="d-block mt-1">Las valoraciones y desempeños que provienen de calificaciones bimestrales también están protegidos.</small>
                    </div>
                </div>
                
                <!-- Panel de estado de campos -->
                <div class="col-md-4">
                    <div class="card bg-light border-0">
                        <div class="card-body p-3">
                            <h6 class="card-title mb-2">Estado de Campos:</h6>
                            <div class="row">
                                <div class="col-6">
                                    <div class="d-flex flex-column gap-1 small">
                                        <div class="d-flex justify-content-between">
                                            <span>Val. 1°C:</span>
                                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type']) ?>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Cal. 1°C:</span>
                                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Val. 2°C:</span>
                                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type']) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex flex-column gap-1 small">
                                        <div class="d-flex justify-content-between">
                                            <span>Cal. 2°C:</span>
                                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type']) ?>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Intensif.:</span>
                                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Final:</span>
                                            <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" class="align-middle" style="width: 3%">#</th>
                            <th rowspan="2" class="align-middle" style="width: 12%">Estudiante</th>
                            <th rowspan="2" class="align-middle" style="width: 6%">Tipo</th>
                            <?php if (count($estudiantesConSubgrupos) > 0): ?>
                            <th rowspan="2" class="align-middle" style="width: 6%">Rotación</th>
                            <?php endif; ?>
                            <th colspan="3" class="text-center">
                                1° Cuatrimestre
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type']) ?>
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                            </th>
                            <th colspan="3" class="text-center">
                                2° Cuatrimestre
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type']) ?>
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type']) ?>
                            </th>
                            <th rowspan="2" class="align-middle text-center" style="width: 6%">
                                Intensif. 1°C
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type']) ?>
                            </th>
                            <th rowspan="2" class="align-middle text-center" style="width: 8%">
                                Calif. Final
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type']) ?>
                            </th>
                            <th rowspan="2" class="align-middle" style="width: 12%">
                                Observaciones
                                <?= generarIconoEstadoCampo($configuracionBloqueos, 'observaciones_cuatrimestrales_bloqueadas', $_SESSION['user_type']) ?>
                            </th>
                            <th rowspan="2" style="vertical-align: middle; width: 60px;">Acciones</th>
                        </tr>
                        <tr>
                            <th class="text-center" style="width: 6%">Valoración</th>
                            <th class="text-center" style="width: 6%">Desempeño</th>
                            <th class="text-center" style="width: 6%">Calificación</th>
                            <th class="text-center" style="width: 6%">Valoración</th>
                            <th class="text-center" style="width: 6%">Desempeño</th>
                            <th class="text-center" style="width: 6%">Calificación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $contador = 1;
                        foreach ($estudiantes as $estudiante): ?>
                        <?php
                            $estudianteId = $estudiante['id'];
                            $calificacion = isset($calificaciones[$estudianteId]) ? $calificaciones[$estudianteId] : null;
                            
                            // Valores actuales
                            $valoracion1c = $calificacion['valoracion_preliminar_1c'] ?? null;
                            $calificacion1c = $calificacion['calificacion_1c'] ?? null;
                            $valoracion2c = $calificacion['valoracion_preliminar_2c'] ?? null;
                            $calificacion2c = $calificacion['calificacion_2c'] ?? null;
                            $intensificacion1c = $calificacion['intensificacion_1c'] ?? null;
                            $calificacionFinal = $calificacion['calificacion_final'] ?? null;
                            $observaciones = $calificacion['observaciones'] ?? null;
                            $tipoCursada = $calificacion['tipo_cursada'] ?? ($estudiante['tipo_matricula'] === 'recursando' ? 'R' : 'C');
                            
                            // Obtener desempeño académico de los bimestres
                            $desempeno1bim = $calificacion['desempeno_1bim'] ?? null;
                            $desempeno3bim = $calificacion['desempeno_3bim'] ?? null;
                            
                            // Verificar si hay valoraciones bimestrales que bloquean la edición
                            $valoracion1bim = $calificacion['valoracion_1bim'] ?? null;
                            $valoracion3bim = $calificacion['valoracion_3bim'] ?? null;
                            
                            // Determinar si los campos están bloqueados por valoraciones bimestrales
                            $bloquear1cPorBimestre = !empty($valoracion1bim);
                            $bloquear2cPorBimestre = !empty($valoracion3bim);
                            
                            // Si hay valoraciones bimestrales, usarlas como valor predeterminado
                            if ($valoracion1bim && !$valoracion1c) {
                                $valoracion1c = $valoracion1bim;
                            }
                            if ($valoracion3bim && !$valoracion2c) {
                                $valoracion2c = $valoracion3bim;
                            }
                            
                            // Generar atributos para campos según estado de bloqueo
                            $atributosVal1c = generarAtributosCampo($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type'], $valoracion1c);
                            $atributosCal1c = generarAtributosCampo($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type'], $calificacion1c);
                            $atributosVal2c = generarAtributosCampo($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type'], $valoracion2c);
                            $atributosCal2c = generarAtributosCampo($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type'], $calificacion2c);
                            $atributosIntensif = generarAtributosCampo($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type'], $intensificacion1c);
                            $atributosFinal = generarAtributosCampo($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type'], $calificacionFinal);
                            $atributosObs = generarAtributosCampo($configuracionBloqueos, 'observaciones_cuatrimestrales_bloqueadas', $_SESSION['user_type'], $observaciones);
                            
                            // Modificar atributos si están bloqueados por bimestre
                            if ($bloquear1cPorBimestre) {
                                $atributosVal1c['disabled'] = 'disabled';
                                $atributosVal1c['class'] = ($atributosVal1c['class'] ?? '') . ' campo-bloqueado';
                                $atributosVal1c['title'] = 'Campo protegido - Proviene de valoración bimestral';
                            }
                            if ($bloquear2cPorBimestre) {
                                $atributosVal2c['disabled'] = 'disabled';
                                $atributosVal2c['class'] = ($atributosVal2c['class'] ?? '') . ' campo-bloqueado';
                                $atributosVal2c['title'] = 'Campo protegido - Proviene de valoración bimestral';
                            }
                        ?>
                        <tr class="<?= $estudiante['tipo_matricula'] === 'recursando' ? 'table-warning' : '' ?>">
                            <td><?= $contador++ ?></td>
                            <td>
                                <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                                <br>
                                
                                
                                <!-- Campo oculto para tipo de cursada -->
                                <input type="hidden" name="estudiantes[<?= $estudianteId ?>][tipo_cursada]" value="<?= $tipoCursada ?>">
                            </td>
                            <td>
                                <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                                    <span class="badge bg-warning">R</span>
                                <?php else: ?>
                                    <span class="badge bg-success">C</span>
                                <?php endif; ?>
                            </td>
                            <?php if (count($estudiantesConSubgrupos) > 0): ?>
                            <td>
                                <?php if (!empty($estudiante['subgrupo'])): ?>
                                    <span class="badge bg-info"><?= htmlspecialchars($estudiante['subgrupo']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            
                            <!-- 1° Cuatrimestre - Valoración -->
                            <td>
                                <div class="position-relative campo-valoracion">
                                    <?php if ($atributosVal1c['disabled'] ?? false): ?>
                                        <!-- Campo bloqueado con estilo badge -->
                                        <div class="badge-valoracion <?= obtenerClaseValoracion($valoracion1c) ?>" 
                                             title="<?= $atributosVal1c['title'] ?? '' ?>">
                                            <div class="valoracion-valor"><?= $valoracion1c ?: '-' ?></div>
                                            <?php if ($bloquear1cPorBimestre): ?>
                                                <i class="bi bi-shield-lock valoracion-icono" title="Protegido por valoración bimestral"></i>
                                            <?php else: ?>
                                                <i class="bi bi-lock valoracion-icono" title="Bloqueado por configuración"></i>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Campo oculto para enviar el valor -->
                                        <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'valoracion_1c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][valoracion_1c]", $valoracion1c) ?>
                                        <?php if ($bloquear1cPorBimestre): ?>
                                            
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Campo editable con estilo mejorado -->
                                        <select name="estudiantes[<?= $estudianteId ?>][valoracion_1c]" 
                                                class="form-select form-select-sm valoracion-select <?= $atributosVal1c['class'] ?? '' ?>"
                                                data-estudiante="<?= $estudianteId ?>"
                                                title="<?= $atributosVal1c['title'] ?? '' ?>">
                                            <option value="">-</option>
                                            <option value="TEA" <?= $valoracion1c === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                            <option value="TEP" <?= $valoracion1c === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                            <option value="TED" <?= $valoracion1c === 'TED' ? 'selected' : '' ?>>TED</option>
                                        </select>
                                        <?php if ($bloquear1cPorBimestre): ?>
                                            
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- 1° Cuatrimestre - Desempeño -->
                            <td>
                                <div class="position-relative campo-desempeno">
                                    <?php if ($desempeno1bim): ?>
                                        <!-- Desempeño desde bimestre con estilo mejorado -->
                                        <div class="badge-desempeno <?= obtenerClaseDesempeno($desempeno1bim) ?>" 
                                             title="Desempeño del 1er bimestre">
                                            <div class="desempeno-valor"><?= htmlspecialchars($desempeno1bim) ?></div>
                                            <i class="bi bi-shield-lock desempeno-icono" title="Protegido por valoración bimestral"></i>
                                        </div>
                                        <small class="text-info d-block mt-1 text-center">Desde 1er bim.</small>
                                    <?php else: ?>
                                        <div class="badge-desempeno badge-sin-dato">
                                            <div class="desempeno-valor">Sin dato</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- 1° Cuatrimestre - Calificación -->
                            <td>
                                <?php if ($atributosCal1c['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm text-center <?= $atributosCal1c['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosCal1c['title'] ?? '' ?>">
                                        <option value="<?= $calificacion1c ?>" selected>
                                            <?= $calificacion1c ?: '-' ?>
                                        </option>
                                    </select>
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'calificacion_1c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_1c]", $calificacion1c) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][calificacion_1c]" 
                                            class="form-select form-select-sm text-center calificacion-numerica <?= $atributosCal1c['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            data-periodo="1c"
                                            title="<?= $atributosCal1c['title'] ?? '' ?>">
                                        <option value="">-</option>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?= $i ?>" 
                                                <?= $calificacion1c == $i ? 'selected' : '' ?>
                                                class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                            <?= $i ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            
                            <!-- 2° Cuatrimestre - Valoración -->
                            <td>
                                <div class="position-relative campo-valoracion">
                                    <?php if ($atributosVal2c['disabled'] ?? false): ?>
                                        <!-- Campo bloqueado con estilo badge -->
                                        <div class="badge-valoracion <?= obtenerClaseValoracion($valoracion2c) ?>" 
                                             title="<?= $atributosVal2c['title'] ?? '' ?>">
                                            <div class="valoracion-valor"><?= $valoracion2c ?: '-' ?></div>
                                            <?php if ($bloquear2cPorBimestre): ?>
                                                <i class="bi bi-shield-lock valoracion-icono" title="Protegido por valoración bimestral"></i>
                                            <?php else: ?>
                                                <i class="bi bi-lock valoracion-icono" title="Bloqueado por configuración"></i>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Campo oculto para enviar el valor -->
                                        <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'valoracion_2c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][valoracion_2c]", $valoracion2c) ?>
                                        <?php if ($bloquear2cPorBimestre): ?>
                                            <small class="text-info d-block mt-1 text-center">Desde 3er bimestre</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Campo editable con estilo mejorado -->
                                        <select name="estudiantes[<?= $estudianteId ?>][valoracion_2c]" 
                                                class="form-select form-select-sm valoracion-select <?= $atributosVal2c['class'] ?? '' ?>"
                                                data-estudiante="<?= $estudianteId ?>"
                                                title="<?= $atributosVal2c['title'] ?? '' ?>">
                                            <option value="">-</option>
                                            <option value="TEA" <?= $valoracion2c === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                            <option value="TEP" <?= $valoracion2c === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                            <option value="TED" <?= $valoracion2c === 'TED' ? 'selected' : '' ?>>TED</option>
                                        </select>
                                        <?php if ($bloquear2cPorBimestre): ?>
                                            <small class="text-info d-block mt-1 text-center">Desde 3er bimestre</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- 2° Cuatrimestre - Desempeño -->
                            <td>
                                <div class="position-relative campo-desempeno">
                                    <?php if ($desempeno3bim): ?>
                                        <!-- Desempeño desde bimestre con estilo mejorado -->
                                        <div class="badge-desempeno <?= obtenerClaseDesempeno($desempeno3bim) ?>" 
                                             title="Desempeño del 3er bimestre">
                                            <div class="desempeno-valor"><?= htmlspecialchars($desempeno3bim) ?></div>
                                            <i class="bi bi-shield-lock desempeno-icono" title="Protegido por valoración bimestral"></i>
                                        </div>
                                        <small class="text-info d-block mt-1 text-center">Desde 3er bim.</small>
                                    <?php else: ?>
                                        <div class="badge-desempeno badge-sin-dato">
                                            <div class="desempeno-valor">Sin dato</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- 2° Cuatrimestre - Calificación -->
                            <td>
                                <?php if ($atributosCal2c['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm text-center <?= $atributosCal2c['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosCal2c['title'] ?? '' ?>">
                                        <option value="<?= $calificacion2c ?>" selected>
                                            <?= $calificacion2c ?: '-' ?>
                                        </option>
                                    </select>
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'calificacion_2c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_2c]", $calificacion2c) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][calificacion_2c]" 
                                            class="form-select form-select-sm text-center calificacion-numerica <?= $atributosCal2c['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            data-periodo="2c"
                                            title="<?= $atributosCal2c['title'] ?? '' ?>">
                                        <option value="">-</option>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?= $i ?>" 
                                                <?= $calificacion2c == $i ? 'selected' : '' ?>
                                                class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                            <?= $i ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Intensificación 1° Cuatrimestre -->
                            <td>
                                <?php if ($atributosIntensif['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm text-center <?= $atributosIntensif['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosIntensif['title'] ?? '' ?>">
                                        <option value="<?= $intensificacion1c ?>" selected>
                                            <?= $intensificacion1c ?: '-' ?>
                                        </option>
                                    </select>
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'intensificacion_1c_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][intensificacion_1c]", $intensificacion1c) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][intensificacion_1c]" 
                                            class="form-select form-select-sm text-center calificacion-numerica <?= $atributosIntensif['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            data-periodo="intensif"
                                            title="<?= $atributosIntensif['title'] ?? '' ?>">
                                        <option value="">-</option>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?= $i ?>" 
                                                <?= $intensificacion1c == $i ? 'selected' : '' ?>
                                                class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                            <?= $i ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Calificación Final -->
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if ($atributosFinal['disabled'] ?? false): ?>
                                        <!-- Campo bloqueado -->
                                        <select class="form-select form-select-sm text-center <?= $atributosFinal['class'] ?? '' ?>" 
                                                disabled
                                                title="<?= $atributosFinal['title'] ?? '' ?>"
                                                style="flex: 1;">
                                            <option value="<?= $calificacionFinal ?>" selected>
                                                <?= $calificacionFinal ?: '-' ?>
                                            </option>
                                        </select>
                                        <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'calificacion_final_bloqueada', $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_final]", $calificacionFinal) ?>
                                    <?php else: ?>
                                        <!-- Campo editable -->
                                        <select name="estudiantes[<?= $estudianteId ?>][calificacion_final]" 
                                                class="form-select form-select-sm text-center calificacion-final me-1 <?= $atributosFinal['class'] ?? '' ?>"
                                                data-estudiante="<?= $estudianteId ?>"
                                                style="flex: 1;"
                                                title="<?= $atributosFinal['title'] ?? '' ?>">
                                            <option value="">-</option>
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <option value="<?= $i ?>" 
                                                    <?= $calificacionFinal == $i ? 'selected' : '' ?>
                                                    class="<?= $i < 4 ? 'text-danger fw-bold' : ($i < 7 ? 'text-warning fw-bold' : 'text-success fw-bold') ?>">
                                                <?= $i ?>
                                            </option>
                                            <?php endfor; ?>
                                        </select>
                                        <button type="button"
                                                class="btn btn-outline-info btn-sm p-0"
                                                onclick="calcularPromedio(<?= $estudianteId ?>)"
                                                title="Calcular promedio automático"
                                                style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-calculator" style="font-size: 18px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Observaciones -->
                            <td>
                                <?php if (!empty($observacionesPredefinidas)): ?>
                                <div class="observaciones-container">
                                    <?php if ($atributosObs['disabled'] ?? false): ?>
                                        <!-- Observaciones bloqueadas -->
                                        <div class="alert alert-secondary alert-sm p-2 mb-0 <?= $atributosObs['class'] ?? '' ?>" 
                                             title="<?= $atributosObs['title'] ?? '' ?>">
                                            <small>
                                                <i class="bi bi-lock"></i> 
                                                <strong>Observaciones bloqueadas</strong>
                                                <?php if ($observaciones): ?>
                                                    <br><?= htmlspecialchars($observaciones) ?>
                                                <?php else: ?>
                                                    <br>Sin observaciones registradas
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, 'observaciones_cuatrimestrales_bloqueadas', $_SESSION['user_type'], "estudiantes[$estudianteId][observaciones]", $observaciones) ?>
                                        
                                    <?php else: ?>
                                        <!-- Observaciones editables -->
                                        <button type="button" 
                                                class="btn btn-outline-secondary btn-sm w-100 mb-2 <?= $atributosObs['class'] ?? '' ?>" 
                                                data-bs-toggle="collapse" 
                                                data-bs-target="#observaciones_panel_<?= $estudianteId ?>" 
                                                aria-expanded="false"
                                                title="<?= $atributosObs['title'] ?? '' ?>">
                                            <i class="bi bi-list-check"></i> Seleccionar Observaciones
                                        </button>
                                        
                                        <!-- Panel colapsable con observaciones -->
                                        <div class="collapse" id="observaciones_panel_<?= $estudianteId ?>">
                                            <div class="card card-body p-2" style="max-height: 200px; overflow-y: auto;">
                                                <?php 
                                                $categoriaActual = '';
                                                $observacionesSeleccionadas = !empty($observaciones) ? explode('. ', $observaciones) : [];
                                                
                                                foreach ($observacionesPredefinidas as $index => $obs): 
                                                    if ($obs['categoria'] !== $categoriaActual): 
                                                        if ($categoriaActual !== ''): ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="categoria-observaciones mb-2">
                                                            <h6 class="text-primary mb-1" style="font-size: 12px;">
                                                                <i class="bi bi-tag"></i> <?= htmlspecialchars($obs['categoria']) ?>
                                                            </h6>
                                                        <?php $categoriaActual = $obs['categoria']; 
                                                    endif; 
                                                    
                                                    $seleccionada = in_array(trim($obs['mensaje']), array_map('trim', $observacionesSeleccionadas));
                                                    $checkboxId = "obs_{$estudianteId}_{$index}";
                                                ?>
                                                <div class="form-check form-check-sm">
                                                    <input class="form-check-input observacion-checkbox" 
                                                           type="checkbox" 
                                                           id="<?= $checkboxId ?>" 
                                                           value="<?= htmlspecialchars($obs['mensaje']) ?>"
                                                           data-estudiante="<?= $estudianteId ?>"
                                                           data-categoria="<?= htmlspecialchars($obs['categoria']) ?>"
                                                           <?= $seleccionada ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="<?= $checkboxId ?>" style="font-size: 11px;">
                                                        <?= htmlspecialchars($obs['mensaje']) ?>
                                                    </label>
                                                </div>
                                                <?php endforeach; ?>
                                                <?php if ($categoriaActual !== ''): ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <!-- Botones de acción rápida -->
                                                <div class="mt-2 pt-2 border-top">
                                                    <div class="btn-group btn-group-sm w-100" role="group">
                                                        <button type="button" 
                                                                class="btn btn-outline-success btn-sm"
                                                                onclick="seleccionarTodasObservaciones(<?= $estudianteId ?>)">
                                                            <i class="bi bi-check-all"></i> Todas
                                                        </button>
                                                        <button type="button" 
                                                                class="btn btn-outline-danger btn-sm"
                                                                onclick="limpiarObservaciones(<?= $estudianteId ?>)">
                                                            <i class="bi bi-x-circle"></i> Ninguna
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Resumen de observaciones seleccionadas -->
                                        <div class="observaciones-resumen mt-2" id="resumen_<?= $estudianteId ?>">
                                            <?php if (!empty($observaciones)): ?>
                                            <div class="alert alert-info alert-sm p-2 mb-0">
                                                <small><strong>Observaciones:</strong><br>
                                                <?= htmlspecialchars($observaciones) ?></small>
                                            </div>
                                            <?php else: ?>
                                            <small class="text-muted">Sin observaciones seleccionadas</small>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Campo oculto para almacenar el valor final -->
                                        <input type="hidden" 
                                               name="estudiantes[<?= $estudianteId ?>][observaciones]" 
                                               id="observaciones_final_<?= $estudianteId ?>"
                                               value="<?= htmlspecialchars($observaciones ?? '') ?>">
                                    <?php endif; ?>
                                </div>
                                
                                <?php else: ?>
                                <!-- Fallback si no hay observaciones predefinidas -->
                                <div class="alert alert-warning alert-sm p-2">
                                    <small><i class="bi bi-exclamation-triangle"></i> No hay observaciones predefinidas configuradas</small>
                                </div>
                                <input type="hidden" name="estudiantes[<?= $estudianteId ?>][observaciones]" value="">
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="detalle_calificaciones_contenidos.php?estudiante=<?= $estudiante['id'] ?>&materia=<?= $materiaSeleccionada ?>&origen=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
                                   class="btn btn-sm btn-info" 
                                   title="Ver detalle de calificaciones por contenido">
                                    <i class="bi bi-list-check"></i>
                                    <span class="d-none d-md-inline">Detalle</span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Referencias y ayuda -->
            <div class="mt-3">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Valoraciones:</h6>
                                <ul class="list-unstyled mb-0">
                                    <li><span class="badge bg-success">TEA</span> Trayectoria Educativa Avanzada</li>
                                    <li><span class="badge bg-warning">TEP</span> Trayectoria Educativa en Proceso</li>
                                    <li><span class="badge bg-danger">TED</span> Trayectoria Educativa Discontinua</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Desempeño Académico:</h6>
                                <ul class="list-unstyled mb-0">
                                    <li><i class="bi bi-shield-lock text-primary"></i> <small>Los datos de desempeño provienen de las valoraciones bimestrales</small></li>
                                    <li><i class="bi bi-info-circle text-info"></i> <small>Se muestran solo como referencia informativa</small></li>
                                    <li><i class="bi bi-eye text-muted"></i> <small>Ayudan a contextualizar las calificaciones cuatrimestrales</small></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                No se encontraron estudiantes para cargar calificaciones.
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- VISTA DE CUATRIMESTRE ESPECÍFICO: 1°C o 2°C con contenidos -->
    
    <div class="card">
        <div class="card-header bg-<?= $cuatrimestreVista === '1' ? 'primary' : 'success' ?> text-white">
            <h6 class="card-title mb-0">
                <i class=""></i> 
                <?= $cuatrimestreVista === '1' ? 'Primer' : 'Segundo' ?> Cuatrimestre - Detalle por Contenidos
                
                <!-- Indicadores de bloqueo -->
                <?php 
                $campoValoracion = 'valoracion_' . $cuatrimestreVista . 'c_bloqueada';
                $campoCalificacion = 'calificacion_' . $cuatrimestreVista . 'c_bloqueada';
                ?>
                <?php if (estaColumnaBloqueada($configuracionBloqueos, $campoValoracion) || estaColumnaBloqueada($configuracionBloqueos, $campoCalificacion)): ?>
                    <span class="badge bg-danger ms-2">
                        <i class="bi bi-lock"></i> <?= $cuatrimestreVista ?>°C Bloqueado
                    </span>
                <?php endif; ?>
            </h6>
        </div>
        <div class="card-body">
            <?php if (!empty($estudiantes)): ?>
            
            <!-- Información sobre los tipos de estudiantes -->
            <?php if (count($estudiantesRecursando) > 0 || count($estudiantesConSubgrupos) > 0): ?>
            <div class="alert alert-info mb-3">
                <h6><i class="bi bi-info-circle"></i> Información de la lista:</h6>
                <ul class="mb-0">
                    <?php if (count($estudiantesRegulares) > 0): ?>
                    <li><strong><?= count($estudiantesRegulares) ?></strong> estudiantes regulares del curso</li>
                    <?php endif; ?>
                    <?php if (count($estudiantesRecursando) > 0): ?>
                    <li><strong><?= count($estudiantesRecursando) ?></strong> estudiantes recursando esta materia</li>
                    <?php endif; ?>
                    <?php if (count($estudiantesConSubgrupos) > 0): ?>
                    <li><strong><?= count($estudiantesConSubgrupos) ?></strong> estudiantes asignados a subgrupos</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
			
			<?php if (!empty($subgruposDisponibles)): ?>
<div class="card mb-3" id="selector-subgrupos-container">
    <div class="card-header bg-info text-white">
        <h6 class="card-title mb-0">
            <i class="bi bi-funnel"></i> Filtrar por Rotación
        </h6>
    </div>
    <div class="card-body p-3">
        <div class="row align-items-center">
            <!-- Selector principal -->
            <div class="col-md-4">
                <label for="filtro-subgrupo" class="form-label fw-bold">Mostrar Rotación:</label>
                <select id="filtro-subgrupo" class="form-select" onchange="filtrarPorSubgrupo()">
                    <option value="todos">📋 Todos las rotaciones</option>
                    <option value="sin-subgrupo">👥 Sin Rotación asignada</option>
                    <?php foreach ($subgruposDisponibles as $subgrupo): ?>
                    <option value="<?= htmlspecialchars($subgrupo) ?>">
                        🔸 <?= htmlspecialchars($subgrupo) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            
        
        <!-- Botones de acceso rápido -->
        <div class="mt-2">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary" onclick="seleccionarSubgrupo('todos')">
                    <i class="bi bi-list"></i> Todos
                </button>
                <?php foreach ($subgruposDisponibles as $subgrupo): ?>
                <button type="button" class="btn btn-outline-primary" onclick="seleccionarSubgrupo('<?= htmlspecialchars($subgrupo) ?>')">
                    <?= htmlspecialchars($subgrupo) ?>
                    <span class="badge bg-primary ms-1"><?= $estadisticasSubgrupos[$subgrupo]['total'] ?></span>
                </button>
                <?php endforeach; ?>
                <?php if (count($estudiantes) > count($estudiantesConSubgrupos)): ?>
                <button type="button" class="btn btn-outline-warning" onclick="seleccionarSubgrupo('sin-subgrupo')">
                    Sin Rotación
                    <span class="badge bg-warning ms-1"><?= count($estudiantes) - count($estudiantesConSubgrupos) ?></span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

            
            <!-- Información sobre contenidos del cuatrimestre -->
            <?php if (!empty($contenidosCuatrimestre)): ?>
<div class="alert alert-info mb-3 d-none">
    <!-- Encabezado con botón desplegable -->
    <div class="d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="bi bi-list-check"></i> Contenidos del <?= $cuatrimestreVista ?>° Cuatrimestre:
            <span class="badge bg-primary ms-2"><?= count($contenidosCuatrimestre) ?> contenidos</span>
        </h6>
        <button class="btn btn-sm btn-outline-primary" 
                type="button" 
                data-bs-toggle="collapse" 
                data-bs-target="#contenidos-lista-<?= $cuatrimestreVista ?>" 
                aria-expanded="true" 
                aria-controls="contenidos-lista-<?= $cuatrimestreVista ?>"
                id="btn-toggle-contenidos-<?= $cuatrimestreVista ?>"
                title="Mostrar/Ocultar lista de contenidos">
            <i class="bi bi-chevron-up" id="icono-toggle-<?= $cuatrimestreVista ?>"></i>
            <span class="d-none d-md-inline ms-1">Contraer</span>
        </button>
    </div>
    
    <!-- Contenido desplegable -->
    <div class="collapse show mt-3" id="contenidos-lista-<?= $cuatrimestreVista ?>">
        <div class="row">
            <?php foreach ($contenidosCuatrimestre as $index => $contenido): ?>
            <div class="col-md-3 mb-2">
                <div class="contenido-item p-2 bg-white rounded border">
                    <small>
                        <div class="d-flex align-items-center mb-1">
                            <span class="badge bg-secondary me-2"><?= $index + 1 ?></span>
                            <strong class="flex-grow-1">
                                <?= htmlspecialchars(substr($contenido['titulo'], 0, 25)) ?><?= strlen($contenido['titulo']) > 25 ? '...' : '' ?>
                            </strong>
                        </div>
                        <div class="text-muted d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-calendar3"></i> 
                                <?= date('d/m', strtotime($contenido['fecha_clase'])) ?>
                            </span>
                            <span class="badge bg-light text-dark">
                                <?= $contenido['bimestre'] ?>° Bim.
                            </span>
                        </div>
                        <?php if (!empty($contenido['tipo_evaluacion'])): ?>
                        <div class="mt-1">
                            <span class="badge bg-<?= $contenido['tipo_evaluacion'] === 'numerica' ? 'info' : 'success' ?> badge-sm">
                                <?= $contenido['tipo_evaluacion'] === 'numerica' ? 'Numérica' : 'Cualitativa' ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Información adicional -->
        <div class="mt-3 pt-2 border-top">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i> 
                        <strong>Distribución por bimestre:</strong>
                        <?php 
                        $bimestres = array_count_values(array_column($contenidosCuatrimestre, 'bimestre'));
                        foreach ($bimestres as $bim => $cantidad):
                        ?>
                        <span class="badge bg-secondary ms-1"><?= $bim ?>° Bim: <?= $cantidad ?></span>
                        <?php endforeach; ?>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        <i class="bi bi-clock"></i> 
                        Último contenido: <?= date('d/m/Y', strtotime(end($contenidosCuatrimestre)['fecha_clase'])) ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
            <?php else: ?>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-exclamation-triangle"></i> 
                No hay contenidos cargados para este cuatrimestre.
            </div>
            <?php endif; ?>

            <?php if ($materiaSeleccionada && !empty($contenidosCuatrimestre) && $cuatrimestreVista !== 'final'): ?>
<div class="card mt-1">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h6 class="mb-0 me-3">
                <i class="bi bi-list-check"></i> Contenidos del <?= $cuatrimestreVista ?>° Cuatrimestre
                <span class="badge bg-light text-dark ms-2"><?= count($contenidosCuatrimestre) ?> contenidos</span>
            </h6>
            
            <!-- Botón de colapso -->
            <button class="btn btn-sm btn-outline-light" 
                    type="button" 
                    data-bs-toggle="collapse" 
                    data-bs-target="#tabla-contenidos-<?= $cuatrimestreVista ?>" 
                    aria-expanded="true" 
                    aria-controls="tabla-contenidos-<?= $cuatrimestreVista ?>"
                    id="btn-toggle-tabla-<?= $cuatrimestreVista ?>"
                    title="Mostrar/Ocultar tabla de contenidos">
                <i class="bi bi-chevron-up" id="icono-tabla-toggle-<?= $cuatrimestreVista ?>"></i>
                <span class="d-none d-lg-inline ms-1">Contraer</span>
            </button>
        </div>
        
        <div class="d-flex gap-2">
            <!-- Información rápida de estado -->
            <div class="d-none d-md-flex align-items-center me-3">
                <?php 
                $conCalificaciones = 0;
                $sinCalificaciones = 0;
                foreach ($contenidosCuatrimestre as $contenido) {
                    $tieneCalif = $db->fetchOne(
                        "SELECT COUNT(*) as total FROM contenidos_calificaciones WHERE contenido_id = ?",
                        [$contenido['id']]
                    )['total'] ?? 0;
                    
                    if ($tieneCalif > 0) {
                        $conCalificaciones++;
                    } else {
                        $sinCalificaciones++;
                    }
                }
                ?>
                <small class="me-2">
                    <span class="badge bg-success"><?= $conCalificaciones ?> OK</span>
                    <span class="badge bg-warning"><?= $sinCalificaciones ?> Pend.</span>
                </small>
            </div>
            
            <button type="button" class="btn btn-light btn-sm" onclick="abrirModalCrearContenido()">
                <i class="bi bi-plus-circle"></i> 
                <span class="d-none d-md-inline">Nuevo Contenido</span>
            </button>
        </div>
    </div>
    
    <!-- Contenido desplegable -->
    <div class="collapse show" id="tabla-contenidos-<?= $cuatrimestreVista ?>">
        <div class="card-body">
            <!-- Filtros rápidos (opcional) -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Filtros rápidos">
                        <input type="radio" class="btn-check" name="filtro-estado" id="filtro-todos-<?= $cuatrimestreVista ?>" value="todos" checked>
                        <label class="btn btn-outline-secondary" for="filtro-todos-<?= $cuatrimestreVista ?>">
                            <i class="bi bi-list"></i> Todos
                        </label>
                        
                        <input type="radio" class="btn-check" name="filtro-estado" id="filtro-ok-<?= $cuatrimestreVista ?>" value="ok">
                        <label class="btn btn-outline-success" for="filtro-ok-<?= $cuatrimestreVista ?>">
                            <i class="bi bi-check-circle"></i> Con calificaciones
                        </label>
                        
                        <input type="radio" class="btn-check" name="filtro-estado" id="filtro-pendiente-<?= $cuatrimestreVista ?>" value="pendiente">
                        <label class="btn btn-outline-warning" for="filtro-pendiente-<?= $cuatrimestreVista ?>">
                            <i class="bi bi-clock"></i> Pendientes
                        </label>
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        <i class="bi bi-info-circle"></i> 
                        Última actualización: <?= date('d/m/Y H:i') ?>
                    </small>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-sm table-hover" id="tabla-contenidos-main-<?= $cuatrimestreVista ?>">
                    <thead class="table-light">
                        <tr>
                            <th width="30">#</th>
                            <th>Título</th>
                            <th width="80">Fecha</th>
                            <th width="80">Bimestre</th>
                            <th width="100">Tipo</th>
                            <th width="80">Estado</th>
                            <th width="160" class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contenidosCuatrimestre as $index => $contenido): ?>
                        <?php 
                        // Verificar calificaciones
                        $tieneCalificaciones = $db->fetchOne(
                            "SELECT COUNT(*) as total FROM contenidos_calificaciones WHERE contenido_id = ?",
                            [$contenido['id']]
                        )['total'] ?? 0;
                        
                        $estadoClase = $tieneCalificaciones > 0 ? 'contenido-ok' : 'contenido-pendiente';
                        ?>
                        <tr class="fila-contenido <?= $estadoClase ?>" data-estado="<?= $tieneCalificaciones > 0 ? 'ok' : 'pendiente' ?>">
                            <td>
                                <span class="badge bg-light text-dark"><?= $index + 1 ?></span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if ($tieneCalificaciones > 0): ?>
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    <?php else: ?>
                                        <i class="bi bi-clock text-warning me-2"></i>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= htmlspecialchars($contenido['titulo']) ?></strong>
                                        <?php if (!empty($contenido['descripcion'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($contenido['descripcion'], 0, 60)) ?><?= strlen($contenido['descripcion']) > 60 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <i class="bi bi-calendar3"></i>
                                    <?= date('d/m', strtotime($contenido['fecha_clase'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= $contenido['bimestre'] ?>° Bim</span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $contenido['tipo_evaluacion'] === 'numerica' ? 'primary' : 'success' ?>">
                                    <i class="bi bi-<?= $contenido['tipo_evaluacion'] === 'numerica' ? 'hash' : 'text-paragraph' ?>"></i>
                                    <?= $contenido['tipo_evaluacion'] === 'numerica' ? 'Numérica' : 'Cualitativa' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($tieneCalificaciones > 0): ?>
                                    <span class="badge bg-success" title="<?= $tieneCalificaciones ?> calificaciones registradas">
                                        <i class="bi bi-check-circle"></i> Completo
                                        <span class="badge bg-light text-success ms-1"><?= $tieneCalificaciones ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning">
                                        <i class="bi bi-clock"></i> Pendiente
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-success" 
                                            onclick="abrirModalCalificar(<?= $contenido['id'] ?>)"
                                            title="Calificar contenido">
                                        <i class="bi bi-check2-square"></i>
                                    </button>
                                    <a href="contenidos_editar.php?id=<?= $contenido['id'] ?>" 
                                       class="btn btn-outline-warning" title="Editar contenido" target="_blank">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="confirmarEliminarContenido(<?= $contenido['id'] ?>, '<?= addslashes($contenido['titulo']) ?>')"
                                            title="Eliminar contenido">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

            <div class="table-responsive mt-4">
    <table class="table table-striped table-hover table-sm">
        <thead class="table-light">
            <tr>
                <th style="width: 0.5%">#</th>
                <th style="width: 5%">Estudiante</th>
                <th style="width: 0.5%">Tipo</th>
                <?php if (count($estudiantesConSubgrupos) > 0): ?>
                <th style="width: 0.5%; text-align: center;">Rotación</th>
                <?php endif; ?>
                <th style="width: 1%; text-align: center;">
                    Valoración
                    <?= generarIconoEstadoCampo($configuracionBloqueos, $campoValoracion, $_SESSION['user_type']) ?>
                </th>
                <th style="width: 1%; text-align: center;">
                    Desempeño
                </th>
                
                <!-- Columnas de contenidos -->
                <?php foreach ($contenidosCuatrimestre as $index => $contenido): ?>
                <th style="width: 1%; text-align: center;" 
                    title="<?= htmlspecialchars($contenido['titulo']) ?> (<?= date('d/m/Y', strtotime($contenido['fecha_clase'])) ?>)"
                    data-bs-toggle="tooltip">
                    <small><?= $index + 1 ?></small>
                </th>
                <?php endforeach; ?>
                
                <th style="width: 2%; text-align: center;">
                    Calificación
                    <?= generarIconoEstadoCampo($configuracionBloqueos, $campoCalificacion, $_SESSION['user_type']) ?>
                </th>
                <th style="width: 2%; text-align: center;">Acciones
                </th>
            </tr>
        </thead>
        <tbody>
                        <?php 
                        $contador = 1;
                        foreach ($estudiantes as $estudiante): ?>
                        <?php
                            $estudianteId = $estudiante['id'];
                            $calificacion = isset($calificaciones[$estudianteId]) ? $calificaciones[$estudianteId] : null;
                            
                            // Valores actuales para este cuatrimestre
                            $valoracionActual = $calificacion['valoracion_preliminar_' . $cuatrimestreVista . 'c'] ?? null;
                            $calificacionActual = $calificacion['calificacion_' . $cuatrimestreVista . 'c'] ?? null;
                            $tipoCursada = $calificacion['tipo_cursada'] ?? ($estudiante['tipo_matricula'] === 'recursando' ? 'R' : 'C');
                            
                            // Desempeño del bimestre correspondiente
                            $bimestreDesempeno = $cuatrimestreVista === '1' ? '1bim' : '3bim';
                            $desempenoActual = $calificacion['desempeno_' . $bimestreDesempeno] ?? null;
                            
                            // Verificar si hay valoraciones bimestrales que bloquean la edición
                            $valoracionBim = $calificacion['valoracion_' . $bimestreDesempeno] ?? null;
                            $bloquearPorBimestre = !empty($valoracionBim);
                            
                            // Si hay valoración bimestral, usarla como valor predeterminado
                            if ($valoracionBim && !$valoracionActual) {
                                $valoracionActual = $valoracionBim;
                            }
                            
                            // Generar atributos para campos según estado de bloqueo
                            $atributosVal = generarAtributosCampo($configuracionBloqueos, $campoValoracion, $_SESSION['user_type'], $valoracionActual);
                            $atributosCal = generarAtributosCampo($configuracionBloqueos, $campoCalificacion, $_SESSION['user_type'], $calificacionActual);
                            
                            // Modificar atributos si están bloqueados por bimestre
                            if ($bloquearPorBimestre) {
                                $atributosVal['disabled'] = 'disabled';
                                $atributosVal['class'] = ($atributosVal['class'] ?? '') . ' campo-bloqueado';
                                $atributosVal['title'] = 'Campo protegido - Proviene de valoración bimestral';
                            }
                            
                            // Obtener calificaciones de contenidos para este estudiante
                            $contenidosIds = array_column($contenidosCuatrimestre, 'id');
                            $calificacionesContenidos = obtenerCalificacionesContenidosInline($db, $estudianteId, $contenidosIds);
                        ?>
                        <tr class="<?= $estudiante['tipo_matricula'] === 'recursando' ? 'table-warning' : '' ?>">
                            <td><?= $contador++ ?></td>
                            <td>
                                <strong><?= htmlspecialchars($estudiante['apellido']) ?>, <?= htmlspecialchars($estudiante['nombre']) ?></strong>
                                <br>
                                <small class="text-muted">Matr.: <?= htmlspecialchars($estudiante['dni']) ?></small>
                                
                                <!-- Campo oculto para tipo de cursada -->
                                <input type="hidden" name="estudiantes[<?= $estudianteId ?>][tipo_cursada]" value="<?= $tipoCursada ?>">
                            </td>
                            <td>
                                <?php if ($estudiante['tipo_matricula'] === 'recursando'): ?>
                                    <span class="badge bg-warning">R</span>
                                    <small class="d-block">Recursando</small>
                                <?php else: ?>
                                    <span class="badge bg-success">C</span>
                                    <small class="d-block">Cursada</small>
                                <?php endif; ?>
                            </td>
                            <?php if (count($estudiantesConSubgrupos) > 0): ?>
                            <td>
                                <?php if (!empty($estudiante['subgrupo'])): ?>
                                    <span class="badge bg-info"><?= htmlspecialchars($estudiante['subgrupo']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            
                            <!-- Valoración -->
                            <td>
                                <div class="position-relative campo-valoracion">
                                    <?php if ($atributosVal['disabled'] ?? false): ?>
                                        <!-- Campo bloqueado con estilo badge -->
                                        <div class="badge-valoracion <?= obtenerClaseValoracion($valoracionActual) ?>" 
                                             title="<?= $atributosVal['title'] ?? '' ?>">
                                            <div class="valoracion-valor"><?= $valoracionActual ?: '-' ?></div>
                                            <?php if ($bloquearPorBimestre): ?>
                                                <i class="bi bi-shield-lock valoracion-icono" title="Protegido por valoración bimestral"></i>
                                            <?php else: ?>
                                                <i class="bi bi-lock valoracion-icono" title="Bloqueado por configuración"></i>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Campo oculto para enviar el valor -->
                                        <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, $campoValoracion, $_SESSION['user_type'], "estudiantes[$estudianteId][valoracion_{$cuatrimestreVista}c]", $valoracionActual) ?>
                                        <?php if ($bloquearPorBimestre): ?>
                                            <small class="text-info d-block mt-1 text-center">Desde <?= $cuatrimestreVista === '1' ? '1er' : '3er' ?> bimestre</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Campo editable con estilo mejorado -->
                                        <select name="estudiantes[<?= $estudianteId ?>][valoracion_<?= $cuatrimestreVista ?>c]" 
                                                class="form-select form-select-sm valoracion-select <?= $atributosVal['class'] ?? '' ?>"
                                                data-estudiante="<?= $estudianteId ?>"
                                                title="<?= $atributosVal['title'] ?? '' ?>">
                                            <option value="">-</option>
                                            <option value="TEA" <?= $valoracionActual === 'TEA' ? 'selected' : '' ?>>TEA</option>
                                            <option value="TEP" <?= $valoracionActual === 'TEP' ? 'selected' : '' ?>>TEP</option>
                                            <option value="TED" <?= $valoracionActual === 'TED' ? 'selected' : '' ?>>TED</option>
                                        </select>
                                        <?php if ($bloquearPorBimestre): ?>
                                            <small class="text-info d-block mt-1 text-center">Desde <?= $cuatrimestreVista === '1' ? '1er' : '3er' ?> bimestre</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Desempeño -->
                            <td>
                                <div class="position-relative campo-desempeno">
                                    <?php if ($desempenoActual): ?>
                                        <!-- Desempeño desde bimestre con estilo mejorado -->
                                        <div class="badge-desempeno <?= obtenerClaseDesempeno($desempenoActual) ?>" 
                                             title="Desempeño del <?= $cuatrimestreVista === '1' ? '1er' : '3er' ?> bimestre">
                                            <div class="desempeno-valor"><?= htmlspecialchars($desempenoActual) ?></div>
                                            <i class="bi bi-shield-lock desempeno-icono" title="Protegido por valoración bimestral"></i>
                                        </div>
                                        <small class="text-info d-block mt-1 text-center">Desde <?= $cuatrimestreVista === '1' ? '1er' : '3er' ?> bim.</small>
                                    <?php else: ?>
                                        <div class="badge-desempeno badge-sin-dato">
                                            <div class="desempeno-valor">Sin dato</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Columnas de contenidos -->
                            <?php foreach ($contenidosCuatrimestre as $contenido): ?>
                            <td class="text-center contenido-cell">
                                <?php 
                                $calContenido = $calificacionesContenidos[$contenido['id']] ?? null;
                                $estado = null;
                                
                                if ($calContenido) {
                                    if ($contenido['tipo_evaluacion'] === 'numerica') {
                                        $nota = $calContenido['calificacion_numerica'];
                                        if ($nota >= 7) {
                                            $estado = 'A'; // Acreditado
                                        } else {
                                            $estado = 'N/A'; // No Acreditado
                                        }
                                    } else {
                                        $cualitativa = $calContenido['calificacion_cualitativa'];
                                        if ($cualitativa === 'Acreditado') {
                                            $estado = 'A';
                                        } elseif ($cualitativa === 'No Acreditado') {
                                            $estado = 'N/A';
                                        } elseif ($cualitativa === 'No Corresponde') {
                                            $estado = 'N/C';
                                        }
                                    }
                                }
                                ?>
                                
                                <?php if ($estado): ?>
                                    <span class="badge badge-contenido bg-<?= 
                                        $estado === 'A' ? 'success' : 
                                        ($estado === 'N/A' ? 'danger' : 'secondary') 
                                    ?>" title="<?= htmlspecialchars($contenido['titulo']) ?>">
                                        <?= $estado ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" title="<?= htmlspecialchars($contenido['titulo']) ?>">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            
                            <!-- Calificación final del cuatrimestre -->
                            <td>
                                <?php if ($atributosCal['disabled'] ?? false): ?>
                                    <!-- Campo bloqueado -->
                                    <select class="form-select form-select-sm text-center <?= $atributosCal['class'] ?? '' ?>" 
                                            disabled
                                            title="<?= $atributosCal['title'] ?? '' ?>">
                                        <option value="<?= $calificacionActual ?>" selected>
                                            <?= $calificacionActual ?: '-' ?>
                                        </option>
                                    </select>
                                    <?= generarCampoOcultoSiBloqueado($configuracionBloqueos, $campoCalificacion, $_SESSION['user_type'], "estudiantes[$estudianteId][calificacion_{$cuatrimestreVista}c]", $calificacionActual) ?>
                                <?php else: ?>
                                    <!-- Campo editable -->
                                    <select name="estudiantes[<?= $estudianteId ?>][calificacion_<?= $cuatrimestreVista ?>c]" 
                                            class="form-select form-select-sm text-center calificacion-numerica <?= $atributosCal['class'] ?? '' ?>"
                                            data-estudiante="<?= $estudianteId ?>"
                                            data-periodo="<?= $cuatrimestreVista ?>c"
                                            title="<?= $atributosCal['title'] ?? '' ?>">
                                        <option value="">-</option>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?= $i ?>" 
                                                <?= $calificacionActual == $i ? 'selected' : '' ?>
                                                class="<?= $i < 4 ? 'text-danger' : ($i < 7 ? 'text-warning' : 'text-success') ?>">
                                            <?= $i ?>
                                        </option>
                                        <?php endfor; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            
                            <td class="text-center">
                                <a href="detalle_calificaciones_contenidos.php?estudiante=<?= $estudiante['id'] ?>&materia=<?= $materiaSeleccionada ?>&origen=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
                                   class="btn btn-sm btn-info" 
                                   title="Ver detalle de calificaciones por contenido">
                                    <i class="bi bi-list-check"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Referencias -->
            <div class="mt-3">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Referencias de Contenidos:</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <span class="badge bg-success">A</span> 
                                        <small>Acreditado</small>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="badge bg-danger">N/A</span> 
                                        <small>No Acreditado</small>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="badge bg-secondary">N/C</span> 
                                        <small>No Corresponde</small>
                                    </div>
                                </div>
                                <hr>
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i> 
                                    Pase el mouse sobre el número de la columna para ver el título completo del contenido
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">Valoraciones:</h6>
                                <ul class="list-unstyled mb-0">
                                    <li><span class="badge bg-success">TEA</span> Trayectoria Educativa Avanzada</li>
                                    <li><span class="badge bg-warning">TEP</span> Trayectoria Educativa en Proceso</li>
                                    <li><span class="badge bg-danger">TED</span> Trayectoria Educativa Discontinua</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                No se encontraron estudiantes para cargar calificaciones.
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<script>

// JavaScript específico para vista cuatrimestral con bloqueos y desempeño académico

// Función mejorada para calcular promedio considerando desempeño
function calcularPromedio(estudianteId) {
    const cal1c = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_1c]"]`);
    const cal2c = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_2c]"]`);
    const intensif = document.querySelector(`select[name="estudiantes[${estudianteId}][intensificacion_1c]"]`);
    const final = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_final]"]`);
    
    if (cal1c && cal2c && final) {
        const nota1 = parseFloat(cal1c.value) || 0;
        const nota2 = parseFloat(cal2c.value) || 0;
        const intensificacion = parseFloat(intensif ? intensif.value : 0) || 0;
        
        if (nota1 > 0 && nota2 > 0) {
            let promedio;
            
            // Si hay intensificación, reemplazar la menor nota
            if (intensificacion > 0) {
                if (nota1 < nota2 && intensificacion > nota1) {
                    promedio = Math.round((intensificacion + nota2) / 2);
                } else if (nota2 < nota1 && intensificacion > nota2) {
                    promedio = Math.round((nota1 + intensificacion) / 2);
                } else {
                    promedio = Math.round((nota1 + nota2) / 2);
                }
            } else {
                promedio = Math.round((nota1 + nota2) / 2);
            }
            
            // Limitar entre 1 y 10
            promedio = Math.max(1, Math.min(10, promedio));
            
            final.value = promedio;
            
            // Aplicar color según el resultado
            final.classList.remove('border-success', 'border-warning', 'border-danger');
            if (promedio >= 7) {
                final.classList.add('border-success');
            } else if (promedio >= 4) {
                final.classList.add('border-warning');
            } else {
                final.classList.add('border-danger');
            }
            
            // Destacar temporalmente
            final.style.backgroundColor = '#e3f2fd';
            setTimeout(() => {
                final.style.backgroundColor = '';
            }, 2000);
            
            // Mostrar información adicional si hay desempeño disponible
            mostrarInfoDesempeno(estudianteId, promedio);
        } else {
            alert('Necesita al menos las dos calificaciones cuatrimestrales para calcular el promedio.');
        }
    }
}

// Función para mostrar información contextual del desempeño
function mostrarInfoDesempeno(estudianteId, calificacionFinal) {
    // Buscar información de desempeño disponible
    const fila = document.querySelector(`tr:has(input[name="estudiantes[${estudianteId}][tipo_cursada]"])`);
    if (!fila) return;
    
    const desempeno1 = fila.querySelector('td:nth-child(5) .desempeno-valor')?.textContent?.trim();
    const desempeno2 = fila.querySelector('td:nth-child(7) .desempeno-valor')?.textContent?.trim();
    
    if (desempeno1 && desempeno1 !== 'Sin dato' || desempeno2 && desempeno2 !== 'Sin dato') {
        let mensaje = `Calificación final calculada: ${calificacionFinal}\n\n`;
        mensaje += 'Desempeño académico registrado:\n';
        
        if (desempeno1 && desempeno1 !== 'Sin dato') {
            mensaje += `• 1er Cuatrimestre: ${desempeno1}\n`;
        }
        if (desempeno2 && desempeno2 !== 'Sin dato') {
            mensaje += `• 2do Cuatrimestre: ${desempeno2}\n`;
        }
        
        // Sugerir coherencia
        const desempenoEsperado = calificacionFinal >= 7 ? 'Excelente/Muy Bueno' : 
                                 calificacionFinal >= 4 ? 'Bueno/Regular' : 'Regular/Malo';
        mensaje += `\nDesempeño esperado para esta calificación: ${desempenoEsperado}`;
        
        // Crear una notificación temporal
        mostrarNotificacionDesempeno(mensaje, estudianteId);
    }
}

// Función para mostrar notificación sobre desempeño
function mostrarNotificacionDesempeno(mensaje, estudianteId) {
    const notificacion = document.createElement('div');
    notificacion.className = 'alert alert-info alert-sm position-absolute';
    notificacion.style.cssText = `
        top: 0;
        right: 0;
        z-index: 1000;
        max-width: 300px;
        font-size: 11px;
        margin: 5px;
        padding: 8px;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    `;
    
    notificacion.innerHTML = `
        <button type="button" class="btn-close btn-close-sm" style="font-size: 10px;" onclick="this.parentElement.remove()"></button>
        <h6 style="font-size: 12px; margin-bottom: 4px;"><i class="bi bi-info-circle"></i> Contexto de Desempeño</h6>
        <div style="white-space: pre-line; font-size: 10px;">${mensaje}</div>
    `;
    
    // Buscar la celda de calificación final para posicionar la notificación
    const celdaFinal = document.querySelector(`select[name="estudiantes[${estudianteId}][calificacion_final]"]`)?.closest('td');
    if (celdaFinal) {
        celdaFinal.style.position = 'relative';
        celdaFinal.appendChild(notificacion);
        
        // Auto-remover después de 8 segundos
        setTimeout(() => {
            if (notificacion.parentNode) {
                notificacion.remove();
            }
        }, 8000);
    }
}

// Función para actualizar estilo de select según valoración
function actualizarEstiloSelect(select) {
    const valor = select.value;
    
    // Remover clases anteriores
    select.classList.remove('select-tea', 'select-tep', 'select-ted', 'select-vacio');
    
    // Aplicar nueva clase según el valor
    switch(valor) {
        case 'TEA':
            select.classList.add('select-tea');
            break;
        case 'TEP':
            select.classList.add('select-tep');
            break;
        case 'TED':
            select.classList.add('select-ted');
            break;
        default:
            select.classList.add('select-vacio');
    }
}

// Función para seleccionar todas las observaciones de un estudiante
function seleccionarTodasObservaciones(estudianteId) {
    const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    actualizarObservaciones(estudianteId);
}

// Función para limpiar todas las observaciones de un estudiante
function limpiarObservaciones(estudianteId) {
    const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    actualizarObservaciones(estudianteId);
}

// Función para actualizar el resumen de observaciones
function actualizarObservaciones(estudianteId) {
    const checkboxes = document.querySelectorAll(`input[data-estudiante="${estudianteId}"].observacion-checkbox:checked`);
    const observaciones = Array.from(checkboxes).map(cb => cb.value);
    const textoFinal = observaciones.join('. ') + (observaciones.length > 0 ? '.' : '');
    
    // Actualizar campo oculto
    const campoOculto = document.getElementById(`observaciones_final_${estudianteId}`);
    if (campoOculto) {
        campoOculto.value = textoFinal;
    }
    
    // Actualizar resumen visual
    const resumen = document.getElementById(`resumen_${estudianteId}`);
    if (resumen) {
        if (textoFinal) {
            resumen.innerHTML = `
                <div class="alert alert-info alert-sm p-2 mb-0">
                    <small><strong>Observaciones:</strong><br>${textoFinal}</small>
                </div>
            `;
        } else {
            resumen.innerHTML = '<small class="text-muted">Sin observaciones seleccionadas</small>';
        }
    }
}

// ========== FUNCIONES CORREGIDAS PARA ACCIONES MASIVAS ==========

// Función corregida para recargar vista de calificaciones
function recargarVistaCalificaciones() {
    console.log('Recargando vista de calificaciones...');
    
    // Mostrar indicador de carga
    mostrarIndicadorCarga();
    
    // Simplemente recargar la página después de un delay
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// FUNCIÓN CORREGIDA: Eliminar contenido sin cambiar de página
function confirmarEliminarContenido(contenidoId, titulo) {
    if (confirm(`¿Está seguro de eliminar "${titulo}"?\n\nEsta acción eliminará todas las calificaciones asociadas.`)) {
        
        // Mostrar indicador de carga
        const btnEliminar = document.querySelector(`[onclick*="confirmarEliminarContenido(${contenidoId}"]`);
        let textoOriginal = '';
        
        if (btnEliminar) {
            textoOriginal = btnEliminar.innerHTML;
            btnEliminar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Eliminando...';
            btnEliminar.disabled = true;
        }
        
        // Crear FormData para envío AJAX
        const formData = new FormData();
        formData.append('accion', 'eliminar_contenido');
        formData.append('contenido_id', contenidoId);
        
        // Enviar petición AJAX
        fetch('contenidos.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Verificar si la respuesta indica éxito
            if (data.includes('eliminado correctamente') || data.includes('success')) {
                mostrarAlerta('success', `Contenido "${titulo}" eliminado correctamente`);
                
                // Actualizar la vista automáticamente
                setTimeout(() => {
                    recargarVistaCalificaciones();
                }, 500);
                
            } else if (data.includes('error') || data.includes('Error')) {
                // Extraer mensaje de error si es posible
                const errorMatch = data.match(/Error:?\s*([^<\n]+)/i);
                const errorMsg = errorMatch ? errorMatch[1] : 'Error desconocido al eliminar';
                mostrarAlerta('danger', `Error al eliminar: ${errorMsg}`);
            } else {
                // Asumir éxito si no hay indicadores claros de error
                mostrarAlerta('success', `Contenido "${titulo}" eliminado correctamente`);
                
                setTimeout(() => {
                    recargarVistaCalificaciones();
                }, 500);
            }
        })
        .catch(error => {
            console.error('Error al eliminar contenido:', error);
            mostrarAlerta('danger', 'Error de conexión al eliminar el contenido');
        })
        .finally(() => {
            // Restaurar botón
            if (btnEliminar) {
                btnEliminar.innerHTML = textoOriginal;
                btnEliminar.disabled = false;
            }
        });
    }
}

// Función para actualizar automáticamente la vista después de calificar
function actualizarVistaCalificaciones() {
    // Obtener parámetros actuales de la URL
    const urlParams = new URLSearchParams(window.location.search);
    const cuatrimestre = urlParams.get('cuatrimestre') || '1';
    const materiaId = urlParams.get('materia');
    
    if (!materiaId) {
        console.error('No se puede actualizar: falta ID de materia');
        return;
    }
    
    // Mostrar indicador de carga
    mostrarIndicadorCarga();
    
    // Hacer petición AJAX para obtener la vista actualizada
    fetch(`vista_cuatrimestral_con_bloqueos.php?materia=${materiaId}&cuatrimestre=${cuatrimestre}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(html => {
        // Extraer solo la tabla de calificaciones del HTML recibido
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Buscar la tabla de calificaciones en el HTML recibido
        const nuevaTabla = doc.querySelector('.table-calificaciones');
        const tablaActual = document.querySelector('.table-calificaciones');
        
        if (nuevaTabla && tablaActual) {
            // Reemplazar solo el contenido de la tabla
            tablaActual.innerHTML = nuevaTabla.innerHTML;
            
            // Volver a aplicar los event listeners a los nuevos elementos
            reinicializarEventListeners();
            
            // Mostrar mensaje de éxito
            mostrarMensajeExito('Vista actualizada correctamente');
        } else {
            // Si no encuentra la tabla específica, actualizar todo el contenedor
            const nuevoContenido = doc.querySelector('.container-fluid');
            const contenidoActual = document.querySelector('.container-fluid');
            
            if (nuevoContenido && contenidoActual) {
                contenidoActual.innerHTML = nuevoContenido.innerHTML;
                reinicializarEventListeners();
            }
        }
        
        ocultarIndicadorCarga();
    })
    .catch(error => {
        console.error('Error al actualizar vista:', error);
        ocultarIndicadorCarga();
        mostrarMensajeError('Error al actualizar la vista');
    });
}

// Función para mostrar indicador de carga
function mostrarIndicadorCarga() {
    // Crear overlay de carga si no existe
    let overlay = document.getElementById('loading-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'loading-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        `;
        overlay.innerHTML = `
            <div class="bg-white p-4 rounded shadow">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="ms-3 d-inline-block">Actualizando vista...</div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
}

// Función para ocultar indicador de carga
function ocultarIndicadorCarga() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
}

// Función para mostrar mensajes
function mostrarMensajeExito(mensaje) {
    mostrarMensaje(mensaje, 'success');
}

function mostrarMensajeError(mensaje) {
    mostrarMensaje(mensaje, 'danger');
}

function mostrarMensaje(mensaje, tipo) {
    // Remover mensajes anteriores
    const mensajesAnteriores = document.querySelectorAll('.alert-auto-generated');
    mensajesAnteriores.forEach(msg => msg.remove());
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo} alert-dismissible fade show alert-auto-generated`;
    alertDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 1050; max-width: 400px;';
    alertDiv.innerHTML = `
        <i class="bi bi-${tipo === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
        ${mensaje}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Auto-ocultar después de 3 segundos
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 3000);
}

// Función para reinicializar event listeners después de actualizar el DOM
function reinicializarEventListeners() {
    // Aquí puedes agregar cualquier inicialización que necesites después de actualizar la vista
    // Por ejemplo, tooltips, modals, etc.
    
    // Reinicializar tooltips de Bootstrap si los usas
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Variable global para almacenar el ID de la materia
window.materiaCursoId = <?= $materiaSeleccionada ?>;

// ========== SISTEMA DE RESTRICCIONES ==========

// Función para verificar contenidos no acreditados de un estudiante
function verificarContenidosNoAcreditados(estudianteId, materiaCursoId) {
    return fetch('ajax_verificar_contenidos_no_acreditados.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `estudiante_id=${estudianteId}&materia_curso_id=${materiaCursoId}`
    })
    .then(response => response.json())
    .then(data => {
        return {
            tieneNoAcreditados: data.tiene_no_acreditados || false,
            contenidosNoAcreditados: data.contenidos_no_acreditados || [],
            totalContenidos: data.total_contenidos || 0,
            detalles: data.detalles || [],
            estadisticas: data.estadisticas || {}
        };
    })
    .catch(error => {
        console.error('Error verificando contenidos:', error);
        return {
            tieneNoAcreditados: false,
            contenidosNoAcreditados: [],
            totalContenidos: 0,
            detalles: [],
            estadisticas: {}
        };
    });
}

// Función para aplicar restricciones a un select de calificación
function aplicarRestriccionesCalificacion(selectElement, estudianteId, materiaCursoId) {
    // Marcar como verificando
    selectElement.classList.add('verificando-contenidos');
    
    verificarContenidosNoAcreditados(estudianteId, materiaCursoId)
        .then(resultado => {
            const { tieneNoAcreditados, contenidosNoAcreditados, detalles, estadisticas } = resultado;
            
            // Remover estado de verificación
            selectElement.classList.remove('verificando-contenidos');
            
            // Remover restricciones anteriores
            selectElement.classList.remove('calificacion-restringida');
            selectElement.removeAttribute('data-restriccion');
            
            // Obtener todas las opciones del select
            const opciones = selectElement.querySelectorAll('option');
            
            if (tieneNoAcreditados) {
                // Aplicar restricciones: deshabilitar opciones 7-10
                opciones.forEach(option => {
                    const valor = parseInt(option.value);
                    if (valor >= 7 && valor <= 10) {
                        option.disabled = true;
                        option.classList.add('opcion-bloqueada');
                        option.title = `Bloqueado - ${contenidosNoAcreditados.length} contenido(s) no acreditado(s)`;
                    } else {
                        option.disabled = false;
                        option.classList.remove('opcion-bloqueada');
                        option.title = '';
                    }
                });
                
                // Marcar el select como restringido
                selectElement.classList.add('calificacion-restringida');
                selectElement.setAttribute('data-restriccion', 'contenidos-no-acreditados');
                selectElement.setAttribute('data-contenidos-na', contenidosNoAcreditados.length);
                
                // Si la calificación actual está en el rango restringido, mostrar advertencia
                const valorActual = parseInt(selectElement.value);
                if (valorActual >= 7 && valorActual <= 10) {
                    mostrarNotificacionRestriccion(estudianteId, contenidosNoAcreditados, 'calificacion_alta_con_na');
                }
                
                // Actualizar título del select
                selectElement.title = `Calificaciones 7-10 bloqueadas - ${contenidosNoAcreditados.length} contenido(s) no acreditado(s)`;
                
                // Mostrar información de restricción
                mostrarInfoRestriccion(selectElement, detalles, estadisticas);
                
            } else {
                // Quitar todas las restricciones
                opciones.forEach(option => {
                    option.disabled = false;
                    option.classList.remove('opcion-bloqueada');
                    option.title = '';
                });
                
                selectElement.title = '';
                ocultarInfoRestriccion(selectElement);
            }
        })
        .catch(error => {
            console.error('Error aplicando restricciones:', error);
            selectElement.classList.remove('verificando-contenidos');
        });
}

// Función para mostrar notificación de restricción
function mostrarNotificacionRestriccion(estudianteId, contenidosNoAcreditados, tipo = 'restriccion_general') {
    const notificacionId = `notif-restriccion-${estudianteId}-${tipo}`;
    
    // Remover notificación anterior si existe
    const existente = document.getElementById(notificacionId);
    if (existente) {
        existente.remove();
    }
    
    const notificacion = document.createElement('div');
    notificacion.id = notificacionId;
    notificacion.className = 'alert alert-warning alert-dismissible fade show position-fixed';
    notificacion.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideInRight 0.3s ease-out;
    `;
    
    let mensaje = '';
    switch(tipo) {
        case 'calificacion_alta_con_na':
            mensaje = `
                <h6><i class="bi bi-exclamation-triangle"></i> Calificación Alta con Contenidos No Acreditados</h6>
                <p class="mb-1">Ha seleccionado una calificación de 7-10, pero el estudiante tiene contenidos no acreditados.</p>
                <p class="mb-2"><strong>Recomendación:</strong> Revise y acredite los contenidos pendientes antes de asignar calificaciones altas.</p>
            `;
            break;
        default:
            mensaje = `
                <h6><i class="bi bi-lock"></i> Calificaciones Restringidas</h6>
                <p class="mb-1">Las calificaciones 7-10 están bloqueadas porque el estudiante tiene contenidos no acreditados:</p>
                <ul class="mb-2 small">
                    ${contenidosNoAcreditados.slice(0, 3).map(c => `<li>${c.titulo} (${c.fecha})</li>`).join('')}
                    ${contenidosNoAcreditados.length > 3 ? `<li>... y ${contenidosNoAcreditados.length - 3} más</li>` : ''}
                </ul>
            `;
    }
    
    notificacion.innerHTML = `
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        ${mensaje}
        <div class="d-flex justify-content-between align-items-center mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" 
                    onclick="mostrarDetalleRestriccion('${estudianteId}', ${JSON.stringify(contenidosNoAcreditados).replace(/"/g, '&quot;')})">
                Ver Detalles
            </button>
            <small class="text-muted">Para habilitar calificaciones altas, acredite los contenidos pendientes.</small>
        </div>
    `;
    
    document.body.appendChild(notificacion);
    
    // Auto-remover después de 10 segundos
    setTimeout(() => {
        if (notificacion.parentNode) {
            notificacion.remove();
        }
    }, 10000);
}

// Función para mostrar información de restricción junto al campo
function mostrarInfoRestriccion(selectElement, detalles, estadisticas) {
    const estudianteId = selectElement.getAttribute('data-estudiante');
    const infoId = `info-restriccion-${estudianteId}`;
    
    // Remover info anterior si existe
    const existente = document.getElementById(infoId);
    if (existente) {
        existente.remove();
    }
    
    const infoElement = document.createElement('div');
    infoElement.id = infoId;
    infoElement.className = 'restriccion-info mt-1';
    infoElement.innerHTML = `
        <div class="d-flex align-items-center justify-content-between gap-3">
            <small class="text-warning d-flex align-items-center">
                <i class="bi bi-lock me-1 icono-restriccion"></i>
                <span>7-10</span>
                
            </small>
            <button type="button" class="btn btn-outline-warning btn-ver-detalles-restriccion" 
                    onclick="mostrarDetalleRestriccion('${estudianteId}', ${JSON.stringify(detalles).replace(/"/g, '&quot;')})"
                    title="Ver contenidos no acreditados">
                <i class="bi bi-info-circle"></i> 
            </button>
        </div>
        ${estadisticas.total_contenidos ? `
        <div class="progress mt-1" style="height: 4px;">
            <div class="progress-bar bg-success" style="width: ${(estadisticas.acreditados / estadisticas.total_contenidos) * 100}%"></div>
            <div class="progress-bar bg-danger" style="width: ${(estadisticas.no_acreditados / estadisticas.total_contenidos) * 100}%"></div>
            <div class="progress-bar bg-secondary" style="width: ${(estadisticas.no_corresponde / estadisticas.total_contenidos) * 100}%"></div>
        </div>
        <small class="text-muted d-block">${estadisticas.acreditados}/${estadisticas.total_contenidos} acreditados (${estadisticas.porcentaje_acreditacion}%)</small>
        ` : ''}
    `;
    
    // Insertar después del select
    selectElement.parentNode.insertBefore(infoElement, selectElement.nextSibling);
}

// Función para ocultar información de restricción
function ocultarInfoRestriccion(selectElement) {
    const estudianteId = selectElement.getAttribute('data-estudiante');
    const infoId = `info-restriccion-${estudianteId}`;
    const existente = document.getElementById(infoId);
    if (existente) {
        existente.remove();
    }
}

// Función para mostrar detalle de restricción en modal
function mostrarDetalleRestriccion(estudianteId, detalles) {
    const modalHtml = `
        <div class="modal fade modal-detalle-restriccion" id="modalDetalleRestriccion" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Contenidos No Acreditados - Restricción de Calificación
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info alert-sistema-restriccion">
                            <h6 class="alert-heading">¿Por qué están bloqueadas las calificaciones 7-10?</h6>
                            <p class="mb-0">
                                Cuando un estudiante tiene contenidos marcados como "No Acreditado", 
                                el sistema restringe automáticamente las calificaciones altas (7-10) para mantener 
                                la coherencia pedagógica entre el desempeño en contenidos específicos y la calificación general.
                            </p>
                        </div>
                        
                        <h6>Contenidos No Acreditados:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Contenido</th>
                                        <th>Fecha</th>
                                        <th>Bimestre</th>
                                        <th>Tipo</th>
                                        <th>Calificación</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${detalles.map(d => `
                                        <tr>
                                            <td>
                                                <strong>${d.titulo}</strong>
                                                ${d.descripcion ? `<br><small class="text-muted">${d.descripcion.substring(0, 50)}...</small>` : ''}
                                            </td>
                                            <td>${d.fecha}</td>
                                            <td>
                                                <span class="badge bg-info">${d.bimestre}°</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-${d.tipo_evaluacion === 'numerica' ? 'primary' : 'success'}">
                                                    ${d.tipo_evaluacion === 'numerica' ? 'Numérica' : 'Cualitativa'}
                                                </span>
                                            </td>
                                            <td>
                                                <code>${d.calificacion || 'N/A'}</code>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger estado-no-acreditado">No Acreditado</span>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-warning">
                            <h6 class="alert-heading">Para habilitar calificaciones 7-10:</h6>
                            <ol class="mb-2">
                                <li>Vaya a la sección de <strong>Gestión de Contenidos</strong> de esta materia</li>
                                <li>Localice los contenidos marcados como "No Acreditado"</li>
                                <li>Re-evalúe y marque como "Acreditado" los contenidos que corresponda</li>
                                <li>Las restricciones se quitarán automáticamente al recargar</li>
                            </ol>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">Las restricciones se actualizan en tiempo real</small>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="refrescarRestricciones()">
                                    <i class="bi bi-arrow-clockwise"></i> Actualizar Estado
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-light">
                            <h6><i class="bi bi-info-circle"></i> Información Adicional:</h6>
                            <ul class="mb-0 small">
                                <li><strong>Contenidos "No Corresponde":</strong> No generan restricciones</li>
                                <li><strong>Contenidos sin calificar:</strong> No generan restricciones</li>
                                <li><strong>Solo "No Acreditado":</strong> Bloquea calificaciones altas</li>
                                <li><strong>Calificaciones 1-6:</strong> Siempre disponibles para reflejar el rendimiento real</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cerrar
                        </button>
                        <a href="contenidos.php?materia=${window.materiaCursoId}" class="btn btn-primary" target="_blank">
                            <i class="bi bi-list-check"></i> Ir a Gestión de Contenidos
                        </a>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remover modal anterior si existe
    const existente = document.getElementById('modalDetalleRestriccion');
    if (existente) {
        existente.remove();
    }
    
    // Agregar modal al DOM
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Mostrar modal
    const modal = new bootstrap.Modal(document.getElementById('modalDetalleRestriccion'));
    modal.show();
}

// Función principal para inicializar el sistema de restricciones
function inicializarSistemaRestricciones() {
    const materiaCursoId = window.materiaCursoId;
    
    if (!materiaCursoId) {
        console.warn('No se pudo obtener el ID de la materia para el sistema de restricciones');
        return;
    }
    
    console.log('Inicializando sistema de restricciones para materia:', materiaCursoId);
    
    // Aplicar restricciones a todos los selects de calificación cuatrimestral
    document.querySelectorAll('select[name*="calificacion_1c"], select[name*="calificacion_2c"], select[name*="calificacion_final"]').forEach(select => {
        const estudianteId = select.getAttribute('data-estudiante') || 
                           select.name.match(/estudiantes\[(\d+)\]/)?.[1];
        
        if (estudianteId) {
            select.setAttribute('data-estudiante', estudianteId);
            aplicarRestriccionesCalificacion(select, estudianteId, materiaCursoId);
            
            // Escuchar cambios en el select
            select.addEventListener('change', function() {
                const valorSeleccionado = parseInt(this.value);
                
                // Si selecciona una calificación alta, verificar inmediatamente
                if (valorSeleccionado >= 7 && valorSeleccionado <= 10) {
                    verificarContenidosNoAcreditados(estudianteId, materiaCursoId)
                        .then(resultado => {
                            if (resultado.tieneNoAcreditados) {
                                mostrarNotificacionRestriccion(estudianteId, resultado.contenidosNoAcreditados, 'calificacion_alta_con_na');
                            }
                        });
                }
                
                // Re-verificar restricciones después de un cambio
                setTimeout(() => {
                    aplicarRestriccionesCalificacion(this, estudianteId, materiaCursoId);
                }, 100);
            });
        }
    });
}

// Función para refrescar restricciones manualmente
function refrescarRestricciones() {
    console.log('Refrescando restricciones...');
    
    // Remover todas las restricciones existentes
    document.querySelectorAll('.calificacion-restringida').forEach(select => {
        select.classList.remove('calificacion-restringida');
        select.removeAttribute('data-restriccion');
        select.querySelectorAll('option').forEach(option => {
            option.disabled = false;
            option.classList.remove('opcion-bloqueada');
        });
    });
    
    // Remover información de restricciones
    document.querySelectorAll('.restriccion-info').forEach(info => {
        info.remove();
    });
    
    // Re-inicializar
    setTimeout(inicializarSistemaRestricciones, 100);
}

// Función para actualizar automáticamente la vista después de calificar
function actualizarVistaCalificaciones() {
    // Obtener parámetros actuales de la URL
    const urlParams = new URLSearchParams(window.location.search);
    const cuatrimestre = urlParams.get('cuatrimestre') || '1';
    const materiaId = urlParams.get('materia');
    
    if (!materiaId) {
        console.error('No se puede actualizar: falta ID de materia');
        return;
    }
    
    // Mostrar indicador de carga
    mostrarIndicadorCarga();
    
    // Simplemente recargar después de un delay corto
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// ========== FUNCIONES DE UTILIDAD ==========

// Función mejorada para mostrar alertas
function mostrarAlerta(tipo, mensaje) {
    // Remover alertas anteriores
    const alertasAnteriores = document.querySelectorAll('.alert-flotante');
    alertasAnteriores.forEach(alerta => alerta.remove());
    
    const alertaId = 'alerta-' + Date.now();
    const alerta = document.createElement('div');
    alerta.id = alertaId;
    alerta.className = `alert alert-${tipo} alert-dismissible fade show position-fixed alert-flotante`;
    alerta.style.cssText = `
        top: 20px; 
        right: 20px; 
        z-index: 10000; 
        max-width: 500px; 
        min-width: 350px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        border: none;
        border-left: 5px solid var(--bs-${tipo});
        border-radius: 10px;
        animation: slideInRight 0.3s ease-out;
        white-space: pre-line;
    `;
    
    const iconos = {
        'success': 'check-circle-fill',
        'danger': 'exclamation-triangle-fill',
        'warning': 'exclamation-triangle-fill',
        'info': 'info-circle-fill'
    };
    
    const titulos = {
        'success': 'Éxito',
        'danger': 'Error',
        'warning': 'Advertencia',
        'info': 'Información'
    };
    
    alerta.innerHTML = `
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        <div class="d-flex align-items-start">
            <i class="bi bi-${iconos[tipo] || 'info-circle-fill'} me-3 mt-1" style="font-size: 1.2rem;"></i>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 4px;">
                    ${titulos[tipo]}
                </div>
                <div style="font-size: 0.9rem; line-height: 1.4;">${mensaje}</div>
                ${tipo === 'danger' ? `
                <div class="mt-2">
                    <button type="button" class="btn btn-outline-${tipo} btn-sm" onclick="window.verificarArchivoAjax()">
                        <i class="bi bi-search"></i> Verificar Conexión
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-1" onclick="console.log('Estado actual:', window.location.href)">
                        <i class="bi bi-info-circle"></i> Debug Info
                    </button>
                </div>
                ` : ''}
            </div>
        </div>
    `;
    
    document.body.appendChild(alerta);
    
    // Auto-remover después de más tiempo si es error
    const tiempoRemover = tipo === 'danger' ? 10000 : 6000;
    setTimeout(() => {
        const elemento = document.getElementById(alertaId);
        if (elemento) {
            elemento.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => elemento.remove(), 300);
        }
    }, tiempoRemover);
}

// ========== INICIALIZACIÓN ==========

// Inicializar cuando se carga el DOM
document.addEventListener('DOMContentLoaded', function() {
    // Mejorar la presentación de campos de desempeño con animaciones
    document.querySelectorAll('.badge-desempeno').forEach(campo => {
        campo.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
            this.style.transition = 'all 0.2s ease';
            this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.15)';
        });
        
        campo.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
        });
    });
    
    // Mejorar la presentación de badges de valoración
    document.querySelectorAll('.badge-valoracion').forEach(campo => {
        campo.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
            this.style.transition = 'all 0.2s ease';
            this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.15)';
        });
        
        campo.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
        });
    });
    
    // Animación de entrada para badges de desempeño
    document.querySelectorAll('.badge-desempeno:not(.badge-sin-dato)').forEach((badge, index) => {
        badge.style.opacity = '0';
        badge.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            badge.style.transition = 'all 0.3s ease';
            badge.style.opacity = '1';
            badge.style.transform = 'translateY(0)';
        }, index * 50);
    });
    
    // Animación de entrada para badges de valoración
    document.querySelectorAll('.badge-valoracion:not(.badge-valoracion-vacio)').forEach((badge, index) => {
        badge.style.opacity = '0';
        badge.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            badge.style.transition = 'all 0.3s ease';
            badge.style.opacity = '1';
            badge.style.transform = 'translateY(0)';
        }, index * 30);
    });
    
    // Mejorar estética de los selects de valoración cuando están habilitados
    document.querySelectorAll('.valoracion-select').forEach(select => {
        // Aplicar estilo inicial según el valor seleccionado
        actualizarEstiloSelect(select);
        
        // Actualizar estilo cuando cambia el valor
        select.addEventListener('change', function() {
            actualizarEstiloSelect(this);
        });
    });
    
    // Event listeners para observaciones
    document.querySelectorAll('.observacion-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const estudianteId = this.getAttribute('data-estudiante');
            actualizarObservaciones(estudianteId);
        });
    });
    
    // Inicializar tooltips si están disponibles
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Agregar estilos CSS para animaciones
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        .alert-flotante {
            animation: slideInRight 0.3s ease-out;
        }
    `;
    document.head.appendChild(style);
    
    // Interceptar eventos de modals existentes para detectar guardado exitoso
    document.addEventListener('hidden.bs.modal', function(event) {
        const modal = event.target;
        
        // Verificar si tiene el flag de guardado exitoso
        if (modal.dataset.guardadoExitoso === 'true') {
            console.log('Modal cerrado después de guardado exitoso');
            
            // Limpiar el flag
            delete modal.dataset.guardadoExitoso;
            
            // Si no se ha iniciado ya la recarga, hacerlo
            if (!document.getElementById('loading-overlay')) {
                recargarVistaCalificaciones();
            }
        }
    });
    
    // Escuchar evento personalizado de calificación guardada
    document.addEventListener('calificacionGuardada', function(event) {
        console.log('Evento calificacionGuardada recibido:', event.detail);
        
        const mensaje = event.detail && event.detail.message ? 
                       event.detail.message : 
                       'Calificación guardada correctamente';
        
        window.notificarCalificacionGuardada(mensaje, event.detail);
    });

    // Inicializar sistema de restricciones después de que todo esté cargado
    setTimeout(() => {
        inicializarSistemaRestricciones();
    }, 1000);
});

// Escuchar cambios en calificaciones de contenidos (si están en modales)
document.addEventListener('click', function(e) {
    if (e.target.closest('#btnGuardarCalificaciones') || 
        e.target.closest('button[onclick*="guardarCalificaciones"]')) {
        // Después de guardar calificaciones de contenidos, actualizar restricciones
        setTimeout(() => {
            refrescarRestricciones();
        }, 1500);
    }
});

// Exponer funciones globalmente
window.SistemaRestricciones = {
    inicializar: inicializarSistemaRestricciones,
    refrescar: refrescarRestricciones,
    verificarContenidos: verificarContenidosNoAcreditados,
    aplicarRestricciones: aplicarRestriccionesCalificacion
};

// Detectar cuando se cierren modales de contenidos para refrescar
document.addEventListener('hidden.bs.modal', function(e) {
    if (e.target.id === 'modalCalificarContenido' || e.target.id === 'modalCrearContenidoCalif') {
        setTimeout(refrescarRestricciones, 500);
    }
});

// Función global que puede ser llamada desde los modals
window.notificarCalificacionGuardada = function(mensaje = 'Calificación guardada correctamente') {
    mostrarAlerta('success', mensaje);
    setTimeout(() => {
        actualizarVistaCalificaciones();
    }, 1500);
};

// Mejorar la navegación - Destacar pestaña activa según período actual
const cuatrimestreActivo = '<?= $cuatrimestreActivo ?>';
const cuatrimestreVista = '<?= $cuatrimestreVista ?>';

// Agregar indicador visual si estamos en el cuatrimestre activo
if (cuatrimestreVista === cuatrimestreActivo && cuatrimestreVista !== 'final') {
    const tabActivo = document.querySelector(`.nav-link[href*="cuatrimestre=${cuatrimestreVista}"]`);
    if (tabActivo) {
        tabActivo.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
        tabActivo.style.borderColor = '#1e7e34';
        tabActivo.style.color = 'white';
    }
}

// ========== FUNCIONES GLOBALES PARA ACCIONES MASIVAS ==========
// Estas funciones deben estar disponibles globalmente para ser llamadas desde los modals







// Función de debug para verificar disponibilidad del archivo
window.verificarArchivoAjax = function() {
    console.log('Verificando disponibilidad de ajax_acreditar_todos.php...');
    
    fetch('ajax_acreditar_todos.php', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Respuesta de verificación:', response.status, response.statusText);
        return response.text();
    })
    .then(text => {
        console.log('Contenido de respuesta:', text.substring(0, 500) + '...');
        
        if (text.includes('Fatal error') || text.includes('Parse error')) {
            console.error('❌ Error PHP detectado en ajax_acreditar_todos.php');
        } else if (text.includes('success')) {
            console.log('✅ Archivo ajax_acreditar_todos.php responde correctamente');
        } else {
            console.warn('⚠️ Respuesta inesperada del archivo');
        }
    })
    .catch(error => {
        console.error('❌ No se pudo verificar el archivo:', error);
    });
};

// Función global para abrir modal de calificación
window.abrirModalCalificar = function(contenidoId) {
    console.log('Abriendo modal para contenido:', contenidoId);
    
    // Mostrar indicador de carga
    mostrarIndicadorCarga();
    
    // Hacer petición AJAX para cargar el contenido del modal
    fetch(`ajax_cargar_calificacion.php?contenido=${contenidoId}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.text())
    .then(html => {
        ocultarIndicadorCarga();
        
        // Crear modal si no existe
        let modal = document.getElementById('modalCalificarContenido');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'modalCalificarContenido';
            modal.setAttribute('tabindex', '-1');
            modal.innerHTML = `
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Calificar Contenido</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Contenido se carga aquí -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-primary" onclick="guardarCalificacionesModal()">
                                <i class="bi bi-save"></i> Guardar Calificaciones
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        // Insertar contenido
        const modalBody = modal.querySelector('.modal-body');
        modalBody.innerHTML = html;
        
        // Mostrar modal
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        
        // Marcar que las funciones globales están disponibles
        modal.dataset.funcionesGlobalesDisponibles = 'true';
        
    })
    .catch(error => {
        console.error('Error al cargar modal:', error);
        ocultarIndicadorCarga();
        mostrarAlerta('danger', 'Error al cargar el modal de calificación');
    });
};

// Función global para guardar calificaciones del modal
window.guardarCalificacionesModal = function() {
    const form = document.getElementById('formCalificarModal');
    if (!form) {
        mostrarAlerta('warning', 'Formulario no encontrado');
        return;
    }
    
    // Mostrar indicador de carga
    mostrarIndicadorCarga();
    
    const formData = new FormData(form);
    formData.append('guardar_calificaciones', '1');
    
    fetch('ajax_guardar_calificaciones.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        ocultarIndicadorCarga();
        
        if (data.success) {
            mostrarAlerta('success', data.message || 'Calificaciones guardadas correctamente');
            
            // Cerrar modal
            const modal = document.getElementById('modalCalificarContenido');
            if (modal) {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
            }
            
            // Actualizar vista
            setTimeout(() => {
                recargarVistaCalificaciones();
            }, 1000);
            
        } else {
            mostrarAlerta('danger', data.message || 'Error al guardar calificaciones');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        ocultarIndicadorCarga();
        mostrarAlerta('danger', 'Error de conexión al guardar calificaciones');
    });
};

// Función global para crear nuevo contenido
window.abrirModalCrearContenido = function() {
    const materiaId = <?= $materiaSeleccionada ?>;
    const cuatrimestre = '<?= $cuatrimestreVista ?>';
    
    if (!materiaId) {
        mostrarAlerta('warning', 'No se ha seleccionado una materia');
        return;
    }
    
    // Abrir en nueva ventana/pestaña
    const url = `contenidos_crear.php?materia=${materiaId}&cuatrimestre=${cuatrimestre}&origen=${encodeURIComponent(window.location.href)}`;
    window.open(url, '_blank');
};

// Función para manejar el botón limpiar del modal
window.limpiarFormularioModal = function() {
    if (confirm('¿Está seguro de limpiar todas las calificaciones?')) {
        const form = document.getElementById('formCalificarModal');
        if (form) {
            form.reset();
            
            // Encontrar el modal y mostrar alerta dentro de él
            const modal = document.getElementById('modalCalificarContenido');
            if (modal) {
                mostrarAlertaEnModal('info', 'Formulario limpiado', modal);
            } else {
                mostrarAlerta('info', 'Formulario limpiado');
            }
        }
    }
};

// Función auxiliar para mostrar alertas dentro del modal
function mostrarAlertaEnModal(tipo, mensaje, modal) {
    const modalBody = modal.querySelector('.modal-body');
    if (!modalBody) return;
    
    // Remover alertas anteriores
    const alertasAnteriores = modalBody.querySelectorAll('.alert-modal-temp');
    alertasAnteriores.forEach(alerta => alerta.remove());
    
    const alerta = document.createElement('div');
    alerta.className = `alert alert-${tipo} alert-dismissible fade show alert-modal-temp`;
    alerta.style.cssText = 'margin-bottom: 15px;';
    
    const iconos = {
        'success': 'check-circle-fill',
        'danger': 'exclamation-triangle-fill',
        'warning': 'exclamation-triangle-fill',
        'info': 'info-circle-fill'
    };
    
    alerta.innerHTML = `
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        <div class="d-flex align-items-start">
            <i class="bi bi-${iconos[tipo]} me-2 mt-1"></i>
            <div>${mensaje}</div>
        </div>
    `;
    
    modalBody.insertBefore(alerta, modalBody.firstChild);
    
    // Auto-remover después de 4 segundos
    setTimeout(() => {
        if (alerta.parentNode) {
            alerta.remove();
        }
    }, 4000);
}

console.log('JavaScript de vista cuatrimestral cargado correctamente');
console.log('Funciones globales de acciones masivas disponibles:', {
    acreditarTodos: typeof window.acreditarTodosContenido,
    noAcreditarTodos: typeof window.noAcreditarTodosContenido,
    noCorresponde: typeof window.noCorrespondeTodosContenido,
    abrirModal: typeof window.abrirModalCalificar
});

// Variables globales para el sistema de filtrado de subgrupos
let subgruposDisponibles = <?= json_encode($subgruposDisponibles ?? []) ?>;
let estadisticasSubgrupos = <?= json_encode($estadisticasSubgrupos ?? []) ?>;
let subgrupoActual = 'todos';



// Función para actualizar la información del subgrupo seleccionado
function actualizarInfoSubgrupo(subgrupo, total, regulares, recursando) {
    const infoContainer = document.getElementById('info-subgrupo-seleccionado');
    const totalSpan = document.getElementById('total-estudiantes-mostrados');
    
    if (totalSpan) {
        totalSpan.textContent = total;
    }
    
    // Actualizar badges
    const badgeRegulares = infoContainer.querySelector('.badge.bg-success');
    const badgeRecursando = infoContainer.querySelector('.badge.bg-warning');
    
    if (badgeRegulares) badgeRegulares.textContent = regulares;
    if (badgeRecursando) badgeRecursando.textContent = recursando;
    
    // Cambiar color del alert según el subgrupo
    infoContainer.className = 'alert mb-0 p-2 ';
    
    switch (subgrupo) {
        case 'todos':
            infoContainer.className += 'alert-light';
            break;
        case 'sin-subgrupo':
            infoContainer.className += 'alert-warning';
            break;
        default:
            infoContainer.className += 'alert-info';
            break;
    }
}

// Función para actualizar botones de acceso rápido
function actualizarBotonesAccesoRapido(subgrupoSeleccionado) {
    const botones = document.querySelectorAll('.btn-group button[onclick*="seleccionarSubgrupo"]');
    
    botones.forEach(boton => {
        const onclick = boton.getAttribute('onclick');
        const valorBoton = onclick.match(/seleccionarSubgrupo\('([^']+)'\)/)?.[1];
        
        // Remover clases de estado anterior
        boton.classList.remove('btn-primary', 'btn-success', 'btn-warning', 'btn-secondary');
        boton.classList.remove('btn-outline-primary', 'btn-outline-success', 'btn-outline-warning', 'btn-outline-secondary');
        
        if (valorBoton === subgrupoSeleccionado) {
            // Botón activo
            switch (subgrupoSeleccionado) {
                case 'todos':
                    boton.classList.add('btn-secondary');
                    break;
                case 'sin-subgrupo':
                    boton.classList.add('btn-warning');
                    break;
                default:
                    boton.classList.add('btn-primary');
                    break;
            }
        } else {
            // Botón inactivo
            switch (valorBoton) {
                case 'todos':
                    boton.classList.add('btn-outline-secondary');
                    break;
                case 'sin-subgrupo':
                    boton.classList.add('btn-outline-warning');
                    break;
                default:
                    boton.classList.add('btn-outline-primary');
                    break;
            }
        }
    });
}

// Función para seleccionar subgrupo desde botones
function seleccionarSubgrupo(subgrupo) {
    const select = document.getElementById('filtro-subgrupo');
    if (select) {
        select.value = subgrupo;
        filtrarPorSubgrupo();
    }
}

// Función CORREGIDA para mostrar/ocultar solo la columna de subgrupo (no los contenidos)
function alternarColumnaSubgrupo(subgrupoSeleccionado) {
    // Solo ocultar la columna de "Subgrupo" cuando se filtra por uno específico
    // NO tocar las columnas de contenidos
    
    const tablaContenidos = document.querySelector('#tabla-contenidos-main-1, #tabla-contenidos-main-2');
    if (!tablaContenidos) return;
    
    // Buscar específicamente la columna de "Subgrupo" por su contenido o posición
    const headers = tablaContenidos.querySelectorAll('thead th');
    let columnaSubgrupoIndex = -1;
    
    // Encontrar la columna que contiene "Subgrupo" o "Rotación"
    headers.forEach((header, index) => {
        const textoHeader = header.textContent.toLowerCase();
        if (textoHeader.includes('subgrupo') || textoHeader.includes('rotación')) {
            columnaSubgrupoIndex = index + 1; // nth-child es 1-based
        }
    });
    
    if (columnaSubgrupoIndex > 0) {
        const ocultarColumnaSubgrupo = subgrupoSeleccionado !== 'todos' && subgrupoSeleccionado !== 'sin-subgrupo';
        
        // Ocultar/mostrar solo la columna de subgrupo
        const columnasSubgrupo = tablaContenidos.querySelectorAll(`th:nth-child(${columnaSubgrupoIndex}), td:nth-child(${columnaSubgrupoIndex})`);
        
        columnasSubgrupo.forEach(element => {
            if (ocultarColumnaSubgrupo) {
                element.style.display = 'none';
            } else {
                element.style.display = '';
            }
        });
    }
    
    // IMPORTANTE: NO tocar las columnas de contenidos (las que tienen números 1, 2, 3, etc.)
    // Esas columnas deben permanecer siempre visibles
}

// Función para aplicar animación al filtrado
function aplicarAnimacionFiltrado() {
    const tabla = document.querySelector('.table-responsive table');
    if (tabla) {
        tabla.style.opacity = '0.7';
        tabla.style.transform = 'scale(0.98)';
        tabla.style.transition = 'all 0.2s ease';
        
        setTimeout(() => {
            tabla.style.opacity = '1';
            tabla.style.transform = 'scale(1)';
        }, 150);
    }
}

// Función para mostrar modal de estadísticas
function mostrarEstadisticasSubgrupos() {
    const modal = new bootstrap.Modal(document.getElementById('modalEstadisticasSubgrupos'));
    modal.show();
}

// Función para seleccionar subgrupo desde el modal y cerrar
function seleccionarSubgrupoYCerrarModal(subgrupo) {
    // Cerrar modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalEstadisticasSubgrupos'));
    if (modal) {
        modal.hide();
    }
    
    // Seleccionar subgrupo
    setTimeout(() => {
        seleccionarSubgrupo(subgrupo);
    }, 300);
}

// Función para buscar estudiante dentro del subgrupo actual
function buscarEstudianteEnSubgrupo() {
    const termino = prompt('Buscar estudiante (nombre o apellido):');
    if (!termino) return;
    
    const filasVisibles = document.querySelectorAll('tbody tr:not(.fila-oculta)');
    let encontrado = false;
    
    filasVisibles.forEach(fila => {
        const textoFila = fila.textContent.toLowerCase();
        if (textoFila.includes(termino.toLowerCase())) {
            fila.scrollIntoView({ behavior: 'smooth', block: 'center' });
            fila.style.backgroundColor = '#fff3cd';
            
            setTimeout(() => {
                fila.style.backgroundColor = '';
            }, 3000);
            
            encontrado = true;
        }
    });
    
    if (!encontrado) {
        alert(`No se encontró "${termino}" en el subgrupo actual.`);
    }
}

// Función para exportar datos del subgrupo actual
function exportarSubgrupoActual() {
    if (subgrupoActual === 'todos') {
        alert('Exportando todos los estudiantes...');
    } else {
        alert(`Exportando estudiantes del subgrupo: ${subgrupoActual}`);
    }
    
    // Aquí puedes implementar la lógica de exportación
    console.log('Exportando subgrupo:', subgrupoActual);
}

// AGREGAR esta función nueva en vista_cuatrimestral_con_bloqueos.php
// (puede reemplazar a la existente si la encuentras)

// BUSCAR y REEMPLAZAR la función de filtrado en vista_cuatrimestral_con_bloqueos.php



// También agregar esta función de debug para verificar
window.debugFiltroSubgrupos = function() {
    console.log('=== DEBUG FILTRO SUBGRUPOS ===');
    
    const select = document.getElementById('filtro-subgrupo');
    console.log('Select encontrado:', !!select);
    if (select) {
        console.log('Valor actual:', select.value);
    }
    
    const filasEstudiantes = document.querySelectorAll('tbody tr');
    console.log('Filas en tbody:', filasEstudiantes.length);
    
    let estudiantesEncontrados = 0;
    
    filasEstudiantes.forEach((fila, index) => {
        const celdaNombre = fila.querySelector('td:nth-child(2)');
        const textoNombre = celdaNombre ? celdaNombre.textContent.trim() : '';
        
        const esFilaEstudiante = textoNombre.includes(',') || 
                                textoNombre.includes('Matr.:') ||
                                celdaNombre.querySelector('strong');
        
        if (esFilaEstudiante) {
            estudiantesEncontrados++;
            const badges = fila.querySelectorAll('.badge');
            const subgrupos = [];
            badges.forEach(badge => {
                const texto = badge.textContent.trim();
                if (texto !== 'C' && texto !== 'R' && texto !== 'TEA' && texto !== 'TEP' && texto !== 'TED') {
                    subgrupos.push(texto);
                }
            });
            
            console.log(`Estudiante ${estudiantesEncontrados}:`, {
                nombre: textoNombre.substring(0, 30),
                subgrupos: subgrupos,
                visible: fila.style.display !== 'none'
            });
        }
    });
    
    console.log('Total estudiantes encontrados:', estudiantesEncontrados);
};

// Inicialización al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Si hay subgrupos disponibles, inicializar el filtrado
    if (subgruposDisponibles && subgruposDisponibles.length > 0) {
        console.log('Sistema de filtrado de subgrupos inicializado');
        console.log('Subgrupos disponibles:', subgruposDisponibles);
        
        // Aplicar filtro inicial si hay parámetro en URL
        const urlParams = new URLSearchParams(window.location.search);
        const subgrupoUrl = urlParams.get('subgrupo');
        
        if (subgrupoUrl && subgruposDisponibles.includes(subgrupoUrl)) {
            document.getElementById('filtro-subgrupo').value = subgrupoUrl;
            filtrarPorSubgrupo();
        }
        
        // Agregar botón de búsqueda en el selector
        const selectorContainer = document.getElementById('selector-subgrupos-container');
        if (selectorContainer) {
            const cardBody = selectorContainer.querySelector('.card-body');
            
            // Agregar funciones adicionales
            const funcionesExtra = document.createElement('div');
            funcionesExtra.className = 'mt-2 pt-2 border-top';
            funcionesExtra.innerHTML = `
                
            `;
            
            cardBody.appendChild(funcionesExtra);
        }
    }
});

// CSS adicional para mejorar la presentación
const style = document.createElement('style');
style.textContent = `
    .fila-oculta {
        opacity: 0.3;
        transform: scale(0.95);
        transition: all 0.2s ease;
    }
    
    .btn-group .btn.active {
        transform: scale(1.05);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    
    #selector-subgrupos-container {
        position: sticky;
        top: 0;
        z-index: 100;
        margin-bottom: 15px;
    }
    
    .table-responsive {
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    @media (max-width: 768px) {
        .btn-group .badge {
            display: none;
        }
        
        #selector-subgrupos-container .btn-group {
            flex-direction: column;
            align-items: stretch;
        }
        
        #selector-subgrupos-container .btn-group .btn {
            margin-bottom: 2px;
        }
    }
`;
document.head.appendChild(style);

console.log('Sistema de filtrado de subgrupos cargado correctamente');
</script>
