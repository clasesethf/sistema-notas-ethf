<?php
/**
 * bloqueos_helper.php - Funciones auxiliares para manejar bloqueos de calificaciones
 * Incluir este archivo en calificaciones.php antes de las vistas
 */

/**
 * Obtiene la configuración de bloqueos para un ciclo lectivo
 */
function obtenerConfiguracionBloqueos($db, $cicloLectivoId) {
    try {
        $config = $db->fetchOne(
            "SELECT * FROM bloqueos_calificaciones WHERE ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
        
        if (!$config) {
            // Configuración por defecto (todo desbloqueado)
            return [
                'valoracion_1bim_bloqueada' => 0,
                'desempeno_1bim_bloqueado' => 0,
                'observaciones_1bim_bloqueadas' => 0,
                'valoracion_3bim_bloqueada' => 0,
                'desempeno_3bim_bloqueado' => 0,
                'observaciones_3bim_bloqueadas' => 0,
                'valoracion_1c_bloqueada' => 0,
                'calificacion_1c_bloqueada' => 0,
                'valoracion_2c_bloqueada' => 0,
                'calificacion_2c_bloqueada' => 0,
                'intensificacion_1c_bloqueada' => 0,
                'calificacion_final_bloqueada' => 0,
                'observaciones_cuatrimestrales_bloqueadas' => 0,
                'bloqueo_general' => 0,
                'observaciones' => ''
            ];
        }
        
        return $config;
    } catch (Exception $e) {
        error_log("Error obteniendo configuración de bloqueos: " . $e->getMessage());
        return ['bloqueo_general' => 0]; // En caso de error, no bloquear
    }
}

/**
 * Verifica si un campo específico está bloqueado
 */
function estaColumnaBloqueada($configuracionBloqueos, $nombreCampo) {
    // Si hay bloqueo general, todo está bloqueado
    if ($configuracionBloqueos['bloqueo_general']) {
        return true;
    }
    
    // Verificar bloqueo específico del campo
    return isset($configuracionBloqueos[$nombreCampo]) && $configuracionBloqueos[$nombreCampo];
}

/**
 * Verifica si el usuario actual puede editar (bypass para admin/directivos)
 */
function puedeEditarCalificaciones($tipoUsuario) {
    return in_array($tipoUsuario, ['admin', 'directivo']);
}

/**
 * Genera atributos HTML para un campo según su estado de bloqueo
 */
function generarAtributosCampo($configuracionBloqueos, $nombreCampo, $tipoUsuario, $valorActual = null) {
    $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
    $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
    
    $atributos = [];
    $clases = [];
    
    // Si el usuario es admin/directivo, puede editar siempre (pero mostrar advertencia)
    if ($puedeEditar) {
        if ($bloqueado) {
            $clases[] = 'campo-admin-override';
            $atributos['title'] = 'Campo bloqueado para profesores - Usted puede editarlo por ser ' . ($tipoUsuario === 'admin' ? 'administrador' : 'directivo');
        }
    } else {
        // Para profesores, respetar el bloqueo
        if ($bloqueado) {
            $atributos['disabled'] = 'disabled';
            $clases[] = 'campo-bloqueado-sistema';
            
            $motivo = $configuracionBloqueos['observaciones'] ?? 'Campo bloqueado por configuración del sistema';
            $atributos['title'] = "Bloqueado: $motivo";
        }
    }
    
    if (count($clases) > 0) {
        $atributos['class'] = implode(' ', $clases);
    }
    
    return $atributos;
}

/**
 * Genera un campo oculto para preservar valores de campos bloqueados
 */
function generarCampoOcultoSiBloqueado($configuracionBloqueos, $nombreCampo, $tipoUsuario, $nombreFormulario, $valorActual) {
    $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
    $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
    
    // Solo generar campo oculto si está bloqueado para profesores
    if ($bloqueado && !$puedeEditar && $valorActual !== null) {
        return "<input type=\"hidden\" name=\"$nombreFormulario\" value=\"" . htmlspecialchars($valorActual) . "\">";
    }
    
    return '';
}

/**
 * Renderiza un ícono de estado del campo
 */
function generarIconoEstadoCampo($configuracionBloqueos, $nombreCampo, $tipoUsuario) {
    $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
    $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
    
    if (!$bloqueado) {
        return '<i class="bi bi-unlock text-success" title="Campo habilitado"></i>';
    }
    
    if ($puedeEditar) {
        return '<i class="bi bi-shield-exclamation text-warning" title="Bloqueado para profesores - Usted puede editar"></i>';
    } else {
        return '<i class="bi bi-lock text-danger" title="Campo bloqueado"></i>';
    }
}

/**
 * Genera alerta informativa sobre el estado de bloqueos
 */
function generarAlertaBloqueos($configuracionBloqueos, $tipoUsuario) {
    $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
    $bloqueoGeneral = $configuracionBloqueos['bloqueo_general'];
    
    if ($bloqueoGeneral) {
        if ($puedeEditar) {
            return [
                'tipo' => 'warning',
                'mensaje' => '<i class="bi bi-shield-exclamation"></i> <strong>Bloqueo general activo:</strong> Los profesores no pueden editar calificaciones. Usted puede editar por ser ' . ($tipoUsuario === 'admin' ? 'administrador' : 'directivo') . '.',
                'detalle' => $configuracionBloqueos['observaciones'] ?? ''
            ];
        } else {
            return [
                'tipo' => 'danger',
                'mensaje' => '<i class="bi bi-lock"></i> <strong>Acceso bloqueado:</strong> La carga de calificaciones está temporalmente deshabilitada.',
                'detalle' => $configuracionBloqueos['observaciones'] ?? 'Contacte a la administración para más información.'
            ];
        }
    }
    
    // Contar campos bloqueados específicos
    $camposBloqueados = 0;
    $totalCampos = 13; // Total de campos bloqueables
    
    foreach ($configuracionBloqueos as $campo => $valor) {
        if (strpos($campo, '_bloqueada') !== false || strpos($campo, '_bloqueado') !== false) {
            if ($valor) $camposBloqueados++;
        }
    }
    
    if ($camposBloqueados > 0 && !$puedeEditar) {
        return [
            'tipo' => 'info',
            'mensaje' => "<i class=\"bi bi-info-circle\"></i> <strong>Restricciones activas:</strong> $camposBloqueados de $totalCampos campos están bloqueados para edición.",
            'detalle' => $configuracionBloqueos['observaciones'] ?? ''
        ];
    }
    
    if ($camposBloqueados > 0 && $puedeEditar) {
        return [
            'tipo' => 'warning',
            'mensaje' => "<i class=\"bi bi-shield-check\"></i> <strong>Vista administrativa:</strong> $camposBloqueados campos bloqueados para profesores. Usted puede editarlos.",
            'detalle' => $configuracionBloqueos['observaciones'] ?? ''
        ];
    }
    
    return null;
}

/**
 * Valida si se puede guardar un valor en un campo específico
 */
function puedeGuardarCampo($configuracionBloqueos, $nombreCampo, $tipoUsuario) {
    $puedeEditar = puedeEditarCalificaciones($tipoUsuario);
    $bloqueado = estaColumnaBloqueada($configuracionBloqueos, $nombreCampo);
    
    // Admin y directivos siempre pueden guardar
    if ($puedeEditar) {
        return true;
    }
    
    // Profesores solo si no está bloqueado
    return !$bloqueado;
}

/**
 * Filtra datos del formulario eliminando campos que no se pueden editar
 */
function filtrarDatosFormulario($configuracionBloqueos, $datosEstudiante, $tipoUsuario) {
    $datosFiltrados = [];
    
    foreach ($datosEstudiante as $campo => $valor) {
        // Mapear nombres de campos del formulario a nombres de configuración
        $nombreConfiguracion = mapearNombreCampo($campo);
        
        if ($nombreConfiguracion && puedeGuardarCampo($configuracionBloqueos, $nombreConfiguracion, $tipoUsuario)) {
            $datosFiltrados[$campo] = $valor;
        } elseif (!$nombreConfiguracion) {
            // Campos que no están en la configuración de bloqueos (como tipo_cursada)
            $datosFiltrados[$campo] = $valor;
        }
    }
    
    return $datosFiltrados;
}

/**
 * Mapea nombres de campos del formulario a nombres de configuración de bloqueos
 */
function mapearNombreCampo($nombreFormulario) {
    $mapeo = [
        'valoracion' => 'valoracion_1bim_bloqueada', // Se determinará dinámicamente
        'desempeno' => 'desempeno_1bim_bloqueado',   // Se determinará dinámicamente
        'valoracion_1c' => 'valoracion_1c_bloqueada',
        'calificacion_1c' => 'calificacion_1c_bloqueada',
        'valoracion_2c' => 'valoracion_2c_bloqueada',
        'calificacion_2c' => 'calificacion_2c_bloqueada',
        'intensificacion_1c' => 'intensificacion_1c_bloqueada',
        'calificacion_final' => 'calificacion_final_bloqueada',
        'observaciones' => 'observaciones_cuatrimestrales_bloqueadas' // Por defecto, se ajustará según contexto
    ];
    
    return $mapeo[$nombreFormulario] ?? null;
}

/**
 * Determina el nombre de configuración de bloqueo según el contexto (bimestre/cuatrimestre)
 */
function obtenerNombreBloqueoContextual($nombreCampo, $esBimestral = false, $bimestre = 1) {
    if ($esBimestral) {
        switch ($nombreCampo) {
            case 'valoracion':
                return $bimestre == 1 ? 'valoracion_1bim_bloqueada' : 'valoracion_3bim_bloqueada';
            case 'desempeno':
                return $bimestre == 1 ? 'desempeno_1bim_bloqueado' : 'desempeno_3bim_bloqueado';
            case 'observaciones':
                return $bimestre == 1 ? 'observaciones_1bim_bloqueadas' : 'observaciones_3bim_bloqueadas';
        }
    }
    
    return mapearNombreCampo($nombreCampo);
}

/**
 * Función para obtener la clase CSS según el tipo de valoración
 */
function obtenerClaseValoracion($valoracion) {
    switch (strtoupper($valoracion)) {
        case 'TEA':
            return 'badge-valoracion-tea';
        case 'TEP':
            return 'badge-valoracion-tep';
        case 'TED':
            return 'badge-valoracion-ted';
        default:
            return 'badge-valoracion-vacio';
    }
}

/**
 * Función para obtener la clase CSS según el tipo de desempeño
 */
function obtenerClaseDesempeno($desempeno) {
    switch (strtolower($desempeno)) {
        case 'excelente':
            return 'badge-desempeno-excelente';
        case 'muy bueno':
            return 'badge-desempeno-muy-bueno';
        case 'bueno':
            return 'badge-desempeno-bueno';
        case 'regular':
            return 'badge-desempeno-regular';
        case 'malo':
            return 'badge-desempeno-malo';
        default:
            return 'badge-desempeno-neutro';
    }
}
?>