<?php
/**
 * verificar_materias.php - Script de verificación de materias CORREGIDO
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 * 
 * OBJETIVO: 74 materias con subgrupos (68 talleres + 6 construcción ciudadanía) y 65 materias normales
 * CORRIGE: Error de REGEXP por patrones LIKE compatibles con SQLite
 */

require_once 'config.php';

// Verificar permisos (solo admin y directivos)
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'directivo'])) {
    $_SESSION['message'] = 'No tiene permisos para acceder a esta sección';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

require_once 'header.php';

$db = Database::getInstance();
$cicloActivo = $db->fetchOne("SELECT * FROM ciclos_lectivos WHERE activo = 1");
$cicloLectivoId = $cicloActivo ? $cicloActivo['id'] : 0;

// Procesar acciones
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
$resultado = null;

if ($accion === 'corregir_subgrupos') {
    try {
        $resultado = corregirMarcadoSubgrupos($db, $cicloLectivoId);
    } catch (Exception $e) {
        $resultado = ['tipo' => 'error', 'mensaje' => 'Error: ' . $e->getMessage()];
    }
}

if ($accion === 'detectar_exhaustivo') {
    try {
        $resultado = detectarTodasMateriasTaller($db, $cicloLectivoId);
    } catch (Exception $e) {
        $resultado = ['tipo' => 'error', 'mensaje' => 'Error en detección exhaustiva: ' . $e->getMessage()];
    }
}

$verificaciones = ejecutarVerificaciones($db, $cicloLectivoId);

/**
 * Función principal de verificaciones
 */
function ejecutarVerificaciones($db, $cicloLectivoId) {
    try {
        // 1. Verificación general
        $resumenGeneral = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_materias,
                COUNT(CASE WHEN mp.requiere_subgrupos = 1 THEN 1 END) as con_subgrupos,
                COUNT(CASE WHEN mp.requiere_subgrupos = 0 THEN 1 END) as sin_subgrupos
             FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?",
            [$cicloLectivoId]
        );
        
        // 2. Verificación por año
        $porAno = $db->fetchAll(
            "SELECT 
                c.anio,
                COUNT(*) as total_materias,
                COUNT(CASE WHEN 
                    -- Patrones para talleres (compatible SQLite)
                    ((m.codigo LIKE '1PT%' OR m.codigo LIKE '1LT%' OR m.codigo LIKE '1ST%' OR
                      m.codigo LIKE '2PT%' OR m.codigo LIKE '2LT%' OR m.codigo LIKE '2ST%' OR
                      m.codigo LIKE '3PT%' OR m.codigo LIKE '3LT%' OR m.codigo LIKE '3ST%' OR
                      m.codigo LIKE '4PT%' OR m.codigo LIKE '4LT%' OR m.codigo LIKE '4ST%' OR
                      m.codigo LIKE '5PT%' OR m.codigo LIKE '5LT%' OR m.codigo LIKE '5ST%' OR
                      m.codigo LIKE '6PT%' OR m.codigo LIKE '6LT%' OR m.codigo LIKE '6ST%' OR
                      m.codigo LIKE '7PT%' OR m.codigo LIKE '7LT%' OR m.codigo LIKE '7ST%') OR
                     (m.codigo LIKE '%MEA%' OR m.codigo LIKE '%DPM%' OR m.codigo LIKE '%IAE%' OR 
                      m.codigo LIKE '%DT%' OR m.codigo LIKE '%LME%' OR m.codigo LIKE '%LMCC%' OR 
                      m.codigo LIKE '%MME%' OR m.codigo LIKE '%PDE%' OR m.codigo LIKE '%PDIE%') OR
                     m.codigo IN ('1LT3', '2LT1', '3LT1', '1PT3', '2PT3', '3PT3', '1ST3', '2ST3', '3ST3',
                                 '2FE', '3F', '3F1', '3IED1', '4DA', '4E', '4EB', '4IED2', 
                                 '4M', '4M1', '4M2', '4PA', '4S', '4T', '5C', '5C1', '5CF1', '5CF2', 
                                 '5ED', '5M3', '5ME', '5MTT', '5R', '6C2', '6CF', '6CT', '6DEE', 
                                 '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC', '7C3', '7CC', 
                                 '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI'))
                    AND m.codigo NOT LIKE '%DERECHO%'
                    AND LOWER(m.nombre) NOT LIKE '%derecho%'
                    AND LOWER(m.nombre) NOT LIKE '%trabajo%'
                    THEN 1 END) as materias_taller,
                COUNT(CASE WHEN 
                    (LOWER(m.nombre) LIKE '%construccion%ciudadania%' OR LOWER(m.nombre) LIKE '%constr%ciud%' OR
                     m.codigo IN ('CCE', 'CCM', 'CCM1'))
                    THEN 1 END) as construccion_ciudadania
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             GROUP BY c.anio
             ORDER BY c.anio",
            [$cicloLectivoId]
        );
        
        // 3. Materias de taller detectadas
        $talleres = $db->fetchAll(
            "SELECT 
                c.anio,
                m.codigo,
                m.nombre,
                c.nombre as curso,
                CASE 
                    WHEN m.codigo LIKE '%PT%' THEN 'Producción/Taller'
                    WHEN m.codigo LIKE '%LT%' THEN 'Laboratorio Técnico'
                    WHEN m.codigo LIKE '%ST%' THEN 'Seminario Técnico'
                    WHEN m.codigo LIKE '%MEA%' THEN 'Mecatrónica'
                    WHEN m.codigo LIKE '%DPM%' THEN 'Diseño Producción Mecánica'
                    WHEN m.codigo LIKE '%IAE%' THEN 'Instalaciones'
                    WHEN m.codigo LIKE '%DT%' THEN 'Dibujo Técnico'
                    WHEN m.codigo LIKE '%LME%' THEN 'Laboratorio Mediciones'
                    WHEN m.codigo LIKE '%LMCC%' THEN 'Lab Metrología'
                    WHEN m.codigo LIKE '%MME%' THEN 'Mantenimiento'
                    WHEN m.codigo LIKE '%PDE%' THEN 'Procesos/Diseño'
                    WHEN m.codigo LIKE '%PDIE%' THEN 'Procesos Industriales'
                    WHEN m.codigo IN ('1LT3', '2LT1', '3LT1') THEN 'Dibujo Técnico'
                    WHEN m.codigo IN ('1PT3', '2PT3', '3PT3') THEN 'Tecnología'
                    WHEN m.codigo IN ('1ST3', '2ST3', '3ST3') THEN 'Proyecto Tecnológico'
                    ELSE 'Taller Especializado'
                END as tipo_taller,
                CASE WHEN mp.requiere_subgrupos = 1 THEN 'SÍ' ELSE 'NO' END as marcado_subgrupos
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             AND (
                 (m.codigo LIKE '1PT%' OR m.codigo LIKE '1LT%' OR m.codigo LIKE '1ST%' OR
                  m.codigo LIKE '2PT%' OR m.codigo LIKE '2LT%' OR m.codigo LIKE '2ST%' OR
                  m.codigo LIKE '3PT%' OR m.codigo LIKE '3LT%' OR m.codigo LIKE '3ST%' OR
                  m.codigo LIKE '4PT%' OR m.codigo LIKE '4LT%' OR m.codigo LIKE '4ST%' OR
                  m.codigo LIKE '5PT%' OR m.codigo LIKE '5LT%' OR m.codigo LIKE '5ST%' OR
                  m.codigo LIKE '6PT%' OR m.codigo LIKE '6LT%' OR m.codigo LIKE '6ST%' OR
                  m.codigo LIKE '7PT%' OR m.codigo LIKE '7LT%' OR m.codigo LIKE '7ST%') OR
                 (m.codigo LIKE '%MEA%' OR m.codigo LIKE '%DPM%' OR m.codigo LIKE '%IAE%' OR 
                  m.codigo LIKE '%DT%' OR m.codigo LIKE '%LME%' OR m.codigo LIKE '%LMCC%' OR 
                  m.codigo LIKE '%MME%' OR m.codigo LIKE '%PDE%' OR m.codigo LIKE '%PDIE%') OR
                 m.codigo IN ('1LT3', '2LT1', '3LT1', '1PT3', '2PT3', '3PT3', '1ST3', '2ST3', '3ST3',
                             '2FE', '3F', '3F1', '3IED1', '4DA', '4E', '4EB', '4IED2', 
                             '4M', '4M1', '4M2', '4PA', '4S', '4T', '5C', '5C1', '5CF1', '5CF2', 
                             '5ED', '5M3', '5ME', '5MTT', '5R', '6C2', '6CF', '6CT', '6DEE', 
                             '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC', '7C3', '7CC', 
                             '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI')
             )
             AND m.codigo NOT LIKE '%DERECHO%'
             AND LOWER(m.nombre) NOT LIKE '%derecho%'
             AND LOWER(m.nombre) NOT LIKE '%trabajo%'
             ORDER BY c.anio, m.codigo",
            [$cicloLectivoId]
        );
        
        // 4. Construcción de ciudadanía
        $ciudadania = $db->fetchAll(
            "SELECT 
                c.anio,
                m.codigo,
                m.nombre,
                c.nombre as curso,
                CASE WHEN mp.requiere_subgrupos = 1 THEN 'SÍ' ELSE 'NO' END as marcado_subgrupos
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             AND c.anio IN (2, 3)
             AND (LOWER(m.nombre) LIKE '%construccion%ciudadania%' 
                  OR LOWER(m.nombre) LIKE '%constr%ciud%'
                  OR m.codigo IN ('CCE', 'CCM', 'CCM1'))
             ORDER BY c.anio, m.nombre",
            [$cicloLectivoId]
        );
        
        // 5. Configuración de subgrupos actual
        $subgruposConfig = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_configurados,
                COUNT(CASE WHEN cs.rotacion_automatica = 1 THEN 1 END) as con_rotacion,
                COUNT(CASE WHEN cs.rotacion_automatica = 0 THEN 1 END) as sin_rotacion
             FROM materias_por_curso mp
             JOIN configuracion_subgrupos cs ON mp.id = cs.materia_curso_id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ? AND cs.ciclo_lectivo_id = ?",
            [$cicloLectivoId, $cicloLectivoId]
        );
        
        // 6. Materias que necesitan configuración
        $necesitanConfig = $db->fetchAll(
            "SELECT 
                c.anio,
                m.codigo,
                m.nombre,
                c.nombre as curso
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             LEFT JOIN configuracion_subgrupos cs ON mp.id = cs.materia_curso_id 
                 AND cs.ciclo_lectivo_id = ?
             WHERE c.ciclo_lectivo_id = ?
             AND mp.requiere_subgrupos = 1
             AND cs.id IS NULL
             ORDER BY c.anio, m.codigo",
            [$cicloLectivoId, $cicloLectivoId]
        );
        
        // 7. Materias sin marcar que deberían tener subgrupos
        $sinMarcar = $db->fetchAll(
            "SELECT 
                c.anio,
                m.codigo,
                m.nombre,
                c.nombre as curso,
                CASE 
                    WHEN ((m.codigo LIKE '1PT%' OR m.codigo LIKE '1LT%' OR m.codigo LIKE '1ST%' OR
                           m.codigo LIKE '2PT%' OR m.codigo LIKE '2LT%' OR m.codigo LIKE '2ST%' OR
                           m.codigo LIKE '3PT%' OR m.codigo LIKE '3LT%' OR m.codigo LIKE '3ST%' OR
                           m.codigo LIKE '4PT%' OR m.codigo LIKE '4LT%' OR m.codigo LIKE '4ST%' OR
                           m.codigo LIKE '5PT%' OR m.codigo LIKE '5LT%' OR m.codigo LIKE '5ST%' OR
                           m.codigo LIKE '6PT%' OR m.codigo LIKE '6LT%' OR m.codigo LIKE '6ST%' OR
                           m.codigo LIKE '7PT%' OR m.codigo LIKE '7LT%' OR m.codigo LIKE '7ST%') OR
                          (m.codigo LIKE '%MEA%' OR m.codigo LIKE '%DPM%' OR m.codigo LIKE '%IAE%' OR 
                           m.codigo LIKE '%DT%' OR m.codigo LIKE '%LME%' OR m.codigo LIKE '%LMCC%' OR 
                           m.codigo LIKE '%MME%' OR m.codigo LIKE '%PDE%' OR m.codigo LIKE '%PDIE%') OR
                          m.codigo IN ('1LT3', '2LT1', '3LT1', '1PT3', '2PT3', '3PT3', '1ST3', '2ST3', '3ST3',
                                      '2FE', '3F', '3F1', '3IED1', '4DA', '4E', '4EB', '4IED2', 
                                      '4M', '4M1', '4M2', '4PA', '4S', '4T', '5C', '5C1', '5CF1', '5CF2', 
                                      '5ED', '5M3', '5ME', '5MTT', '5R', '6C2', '6CF', '6CT', '6DEE', 
                                      '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC', '7C3', '7CC', 
                                      '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI'))
                    THEN 'Taller'
                    ELSE 'Ciudadanía'
                END as tipo_problema
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             AND mp.requiere_subgrupos = 0
             AND (
                 -- Talleres
                 ((m.codigo LIKE '1PT%' OR m.codigo LIKE '1LT%' OR m.codigo LIKE '1ST%' OR
                   m.codigo LIKE '2PT%' OR m.codigo LIKE '2LT%' OR m.codigo LIKE '2ST%' OR
                   m.codigo LIKE '3PT%' OR m.codigo LIKE '3LT%' OR m.codigo LIKE '3ST%' OR
                   m.codigo LIKE '4PT%' OR m.codigo LIKE '4LT%' OR m.codigo LIKE '4ST%' OR
                   m.codigo LIKE '5PT%' OR m.codigo LIKE '5LT%' OR m.codigo LIKE '5ST%' OR
                   m.codigo LIKE '6PT%' OR m.codigo LIKE '6LT%' OR m.codigo LIKE '6ST%' OR
                   m.codigo LIKE '7PT%' OR m.codigo LIKE '7LT%' OR m.codigo LIKE '7ST%') OR
                  (m.codigo LIKE '%MEA%' OR m.codigo LIKE '%DPM%' OR m.codigo LIKE '%IAE%' OR 
                   m.codigo LIKE '%DT%' OR m.codigo LIKE '%LME%' OR m.codigo LIKE '%LMCC%' OR 
                   m.codigo LIKE '%MME%' OR m.codigo LIKE '%PDE%' OR m.codigo LIKE '%PDIE%') OR
                  m.codigo IN ('1LT3', '2LT1', '3LT1', '1PT3', '2PT3', '3PT3', '1ST3', '2ST3', '3ST3',
                              '2FE', '3F', '3F1', '3IED1', '4DA', '4E', '4EB', '4IED2', 
                              '4M', '4M1', '4M2', '4PA', '4S', '4T', '5C', '5C1', '5CF1', '5CF2', 
                              '5ED', '5M3', '5ME', '5MTT', '5R', '6C2', '6CF', '6CT', '6DEE', 
                              '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC', '7C3', '7CC', 
                              '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI'))
                 AND m.codigo NOT LIKE '%DERECHO%'
                 AND LOWER(m.nombre) NOT LIKE '%derecho%'
                 AND LOWER(m.nombre) NOT LIKE '%trabajo%'
                 OR
                 -- Construcción de ciudadanía (2° y 3° año)
                 (c.anio IN (2, 3) AND (LOWER(m.nombre) LIKE '%construccion%ciudadania%' 
                  OR LOWER(m.nombre) LIKE '%constr%ciud%' OR m.codigo IN ('CCE', 'CCM', 'CCM1')))
             )
             ORDER BY c.anio, m.codigo",
            [$cicloLectivoId]
        );
        
        $esperadoPorAno = [1 => 18, 2 => 21, 3 => 22, 4 => 22, 5 => 20, 6 => 22, 7 => 18];
        
        return [
            'resumen_general' => $resumenGeneral,
            'por_ano' => $porAno,
            'talleres' => $talleres,
            'ciudadania' => $ciudadania,
            'subgrupos_config' => $subgruposConfig,
            'necesitan_config' => $necesitanConfig,
            'sin_marcar' => $sinMarcar,
            'esperado_por_ano' => $esperadoPorAno,
            'ciclo_lectivo_id' => $cicloLectivoId
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Función CORREGIDA para marcar solo Construcción de Ciudadanía
 */
function corregirMarcadoSubgrupos($db, $cicloLectivoId) {
    try {
        $ciudadaniaACorregir = $db->fetchAll(
            "SELECT mp.id, m.codigo, m.nombre, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             AND c.anio IN (2, 3)
             AND (LOWER(m.nombre) LIKE '%construccion%ciudadania%' 
                  OR LOWER(m.nombre) LIKE '%constr%ciud%' 
                  OR m.codigo IN ('CCE', 'CCM', 'CCM1'))
             AND mp.requiere_subgrupos = 0",
            [$cicloLectivoId]
        );
        
        $ciudadaniaCorregida = 0;
        foreach ($ciudadaniaACorregir as $materia) {
            $db->query(
                "UPDATE materias_por_curso SET requiere_subgrupos = 1 WHERE id = ?",
                [$materia['id']]
            );
            $ciudadaniaCorregida++;
        }
        
        return [
            'tipo' => 'success',
            'mensaje' => "✅ Se corrigieron {$ciudadaniaCorregida} materias de Construcción de la Ciudadanía.\n\n💡 Para marcar TODAS las materias de taller (46+ materias), use el botón 'Detectar TODOS los Talleres'."
        ];
        
    } catch (Exception $e) {
        return [
            'tipo' => 'error',
            'mensaje' => 'Error al corregir Ciudadanía: ' . $e->getMessage()
        ];
    }
}

/**
 * Función NUEVA para detectar TODAS las materias de taller + ciudadanía
 */
function detectarTodasMateriasTaller($db, $cicloLectivoId) {
    try {
        // 1. Obtener TODAS las materias de taller sin marcar
        $materiasTaller = $db->fetchAll(
            "SELECT mp.id, m.codigo, m.nombre, c.anio, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             AND mp.requiere_subgrupos = 0
             AND (
                 -- Patrones específicos para talleres (compatible con SQLite)
                 (m.codigo LIKE '1PT%' OR m.codigo LIKE '1LT%' OR m.codigo LIKE '1ST%' OR
                  m.codigo LIKE '2PT%' OR m.codigo LIKE '2LT%' OR m.codigo LIKE '2ST%' OR
                  m.codigo LIKE '3PT%' OR m.codigo LIKE '3LT%' OR m.codigo LIKE '3ST%' OR
                  m.codigo LIKE '4PT%' OR m.codigo LIKE '4LT%' OR m.codigo LIKE '4ST%' OR
                  m.codigo LIKE '5PT%' OR m.codigo LIKE '5LT%' OR m.codigo LIKE '5ST%' OR
                  m.codigo LIKE '6PT%' OR m.codigo LIKE '6LT%' OR m.codigo LIKE '6ST%' OR
                  m.codigo LIKE '7PT%' OR m.codigo LIKE '7LT%' OR m.codigo LIKE '7ST%') OR
                 (m.codigo LIKE '%MEA%' OR m.codigo LIKE '%DPM%' OR m.codigo LIKE '%IAE%' OR 
                  m.codigo LIKE '%DT%' OR m.codigo LIKE '%LME%' OR m.codigo LIKE '%LMCC%' OR 
                  m.codigo LIKE '%MME%' OR m.codigo LIKE '%PDE%' OR m.codigo LIKE '%PDIE%') OR
                 -- Códigos específicos incluyendo los que faltaban
                 m.codigo IN ('1LT3', '2LT1', '3LT1', '1PT3', '2PT3', '3PT3', '1ST3', '2ST3', '3ST3',
                             '2FE', '3F', '3F1', '3IED1', '4DA', '4E', '4EB', '4IED2', 
                             '4M', '4M1', '4M2', '4PA', '4S', '4T', '5C', '5C1', '5CF1', '5CF2', 
                             '5ED', '5M3', '5ME', '5MTT', '5R', '6C2', '6CF', '6CT', '6DEE', 
                             '6L', '6LME', '6M', '6M1', '6MCI1', '6P', '6SC', '7C3', '7CC', 
                             '7CE', '7DE', '7DP', '7LMC', '7MCI2', '7ME', '7ME1', '7MM', '7RI')
             )
             -- EXCLUIR materias que NO son talleres
             AND m.codigo NOT LIKE '%DERECHO%'
             AND LOWER(m.nombre) NOT LIKE '%derecho%'
             AND LOWER(m.nombre) NOT LIKE '%trabajo%'
             AND LOWER(m.nombre) NOT LIKE '%legislacion%'
             ORDER BY c.anio, m.codigo",
            [$cicloLectivoId]
        );
        
        // 2. Obtener materias de Construcción de Ciudadanía sin marcar
        $materiasCiudadania = $db->fetchAll(
            "SELECT mp.id, m.codigo, m.nombre, c.anio, c.nombre as curso_nombre
             FROM materias_por_curso mp
             JOIN materias m ON mp.materia_id = m.id
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ?
             AND c.anio IN (2, 3)
             AND (LOWER(m.nombre) LIKE '%construccion%ciudadania%' 
                  OR LOWER(m.nombre) LIKE '%constr%ciud%' 
                  OR m.codigo IN ('CCE', 'CCM', 'CCM1'))
             AND mp.requiere_subgrupos = 0
             ORDER BY c.anio, m.codigo",
            [$cicloLectivoId]
        );
        
        $talleresCorregidos = 0;
        $ciudadaniaCorregida = 0;
        $log = [];
        
        // Procesar talleres
        foreach ($materiasTaller as $materia) {
            $db->query(
                "UPDATE materias_por_curso SET requiere_subgrupos = 1 WHERE id = ?",
                [$materia['id']]
            );
            $talleresCorregidos++;
            $log[] = "🔧 TALLER: {$materia['codigo']} - {$materia['nombre']} ({$materia['curso_nombre']})";
        }
        
        // Procesar construcción de ciudadanía
        foreach ($materiasCiudadania as $materia) {
            $db->query(
                "UPDATE materias_por_curso SET requiere_subgrupos = 1 WHERE id = ?",
                [$materia['id']]
            );
            $ciudadaniaCorregida++;
            $log[] = "👥 CIUDADANÍA: {$materia['codigo']} - {$materia['nombre']} ({$materia['curso_nombre']})";
        }
        
        $totalCorregidas = $talleresCorregidos + $ciudadaniaCorregida;
        
        // Verificar resultado final
        $totalConSubgrupos = $db->fetchOne(
            "SELECT COUNT(*) as total FROM materias_por_curso mp
             JOIN cursos c ON mp.curso_id = c.id
             WHERE c.ciclo_lectivo_id = ? AND mp.requiere_subgrupos = 1",
            [$cicloLectivoId]
        )['total'];
        
        return [
            'tipo' => 'success',
            'mensaje' => "🎯 DETECCIÓN EXHAUSTIVA COMPLETADA:\n\n" .
                        "✅ Se marcaron {$totalCorregidas} materias para subgrupos:\n" .
                        "   • {$talleresCorregidos} materias de TALLER\n" .
                        "   • {$ciudadaniaCorregida} materias de CONSTRUCCIÓN DE CIUDADANÍA\n\n" .
                        "📊 ESTADO ACTUAL: {$totalConSubgrupos}/74 materias con subgrupos\n\n" .
                        "🚀 SIGUIENTE PASO: Vaya a 'Gestionar Subgrupos' → 'Detectar y Configurar Automáticamente'\n\n" .
                        "📋 MATERIAS PROCESADAS:\n" . implode("\n", array_slice($log, 0, 15)) . 
                        ($totalCorregidas > 15 ? "\n... y " . ($totalCorregidas - 15) . " más." : "")
        ];
        
    } catch (Exception $e) {
        return [
            'tipo' => 'error',
            'mensaje' => 'Error en detección exhaustiva: ' . $e->getMessage()
        ];
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Título -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-clipboard-check"></i> 
                        Verificación de Materias - Escuela Técnica Henry Ford
                    </h4>
                </div>
                <div class="card-body">
                    <p class="mb-1">
                        <strong>Objetivo:</strong> Verificar que las 139 materias del plan de estudios estén correctamente configuradas.
                    </p>
                    <p class="mb-0">
                        <strong>Esperado:</strong> 74 materias con subgrupos (68 talleres + 6 construcción ciudadanía) y 65 materias normales.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Mensaje de resultado -->
    <?php if ($resultado): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-<?= $resultado['tipo'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <i class="bi bi-<?= $resultado['tipo'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <pre style="white-space: pre-wrap; margin: 0;"><?= htmlspecialchars($resultado['mensaje']) ?></pre>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($verificaciones['error'])): ?>
    <!-- Error -->
    <div class="row">
        <div class="col-12">
            <div class="alert alert-danger">
                <h5><i class="bi bi-exclamation-triangle"></i> Error en Verificación</h5>
                <p><?= htmlspecialchars($verificaciones['error']) ?></p>
            </div>
        </div>
    </div>
    <?php else: ?>
    
    <!-- Resumen General -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-graph-up"></i> Resumen General</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="card <?= $verificaciones['resumen_general']['total_materias'] == 139 ? 'border-success' : 'border-warning' ?>">
                                <div class="card-body">
                                    <h3 class="<?= $verificaciones['resumen_general']['total_materias'] == 139 ? 'text-success' : 'text-warning' ?>">
                                        <?= $verificaciones['resumen_general']['total_materias'] ?>
                                    </h3>
                                    <p class="card-text">Total Materias</p>
                                    <small class="text-muted">Esperado: 139</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card <?= $verificaciones['resumen_general']['con_subgrupos'] == 74 ? 'border-success' : 'border-warning' ?>">
                                <div class="card-body">
                                    <h3 class="<?= $verificaciones['resumen_general']['con_subgrupos'] == 74 ? 'text-success' : 'text-warning' ?>">
                                        <?= $verificaciones['resumen_general']['con_subgrupos'] ?>
                                    </h3>
                                    <p class="card-text">Con Subgrupos</p>
                                    <small class="text-muted">Esperado: 74</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card <?= $verificaciones['resumen_general']['sin_subgrupos'] == 65 ? 'border-success' : 'border-warning' ?>">
                                <div class="card-body">
                                    <h3 class="<?= $verificaciones['resumen_general']['sin_subgrupos'] == 65 ? 'text-success' : 'text-warning' ?>">
                                        <?= $verificaciones['resumen_general']['sin_subgrupos'] ?>
                                    </h3>
                                    <p class="card-text">Sin Subgrupos</p>
                                    <small class="text-muted">Esperado: 65</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card <?= ($verificaciones['subgrupos_config']['total_configurados'] ?? 0) == 74 ? 'border-success' : 'border-info' ?>">
                                <div class="card-body">
                                    <h3 class="text-info">
                                        <?= $verificaciones['subgrupos_config']['total_configurados'] ?? 0 ?>
                                    </h3>
                                    <p class="card-text">Configurados</p>
                                    <small class="text-muted">Subgrupos configurados</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Estado general -->
                    <div class="row mt-3">
                        <div class="col-12">
                            <?php 
                            $totalMaterias = $verificaciones['resumen_general']['total_materias'];
                            $conSubgrupos = $verificaciones['resumen_general']['con_subgrupos'];
                            $sinSubgrupos = $verificaciones['resumen_general']['sin_subgrupos'];
                            
                            if ($totalMaterias == 139 && $conSubgrupos == 74 && $sinSubgrupos == 65): ?>
                            <div class="alert alert-success">
                                <h6><i class="bi bi-check-circle"></i> <strong>¡PERFECTO!</strong></h6>
                                <p class="mb-0">Todas las materias están correctamente configuradas según el plan de estudios.</p>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <h6><i class="bi bi-exclamation-triangle"></i> <strong>Necesita Corrección</strong></h6>
                                <p class="mb-2">Hay diferencias con la configuración esperada:</p>
                                <ul class="mb-0">
                                    <?php if ($totalMaterias != 139): ?>
                                    <li>Total de materias: <?= $totalMaterias ?> (esperado: 139) - Diferencia: <?= $totalMaterias - 139 ?></li>
                                    <?php endif; ?>
                                    <?php if ($conSubgrupos != 74): ?>
                                    <li>Materias con subgrupos: <?= $conSubgrupos ?> (esperado: 74) - Diferencia: <?= $conSubgrupos - 74 ?></li>
                                    <?php endif; ?>
                                    <?php if ($sinSubgrupos != 65): ?>
                                    <li>Materias sin subgrupos: <?= $sinSubgrupos ?> (esperado: 65) - Diferencia: <?= $sinSubgrupos - 65 ?></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Verificación por año -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-table"></i> Verificación por Año</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th>Año</th>
                                    <th class="text-center">Total BD</th>
                                    <th class="text-center">Total Esperado</th>
                                    <th class="text-center">Diferencia</th>
                                    <th class="text-center">Talleres</th>
                                    <th class="text-center">Ciudadanía</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($verificaciones['por_ano'] as $ano): ?>
                                <?php 
                                    $esperado = $verificaciones['esperado_por_ano'][$ano['anio']];
                                    $diferencia = $ano['total_materias'] - $esperado;
                                    $estado = $diferencia == 0 ? 'success' : 'warning';
                                    $iconoEstado = $diferencia == 0 ? 'check-circle' : 'exclamation-triangle';
                                ?>
                                <tr>
                                    <td><strong><?= $ano['anio'] ?>° Año</strong></td>
                                    <td class="text-center"><?= $ano['total_materias'] ?></td>
                                    <td class="text-center"><?= $esperado ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $estado ?>">
                                            <?= $diferencia > 0 ? '+' : '' ?><?= $diferencia ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?= $ano['materias_taller'] ?></td>
                                    <td class="text-center"><?= $ano['construccion_ciudadania'] ?></td>
                                    <td class="text-center">
                                        <i class="bi bi-<?= $iconoEstado ?> text-<?= $estado ?>"></i>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Problemas encontrados -->
    <?php if (!empty($verificaciones['sin_marcar'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Materias Sin Marcar para Subgrupos (<?= count($verificaciones['sin_marcar']) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <p>Las siguientes materias deberían tener subgrupos pero no están marcadas:</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Año</th>
                                    <th>Código</th>
                                    <th>Materia</th>
                                    <th>Curso</th>
                                    <th>Tipo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($verificaciones['sin_marcar'] as $materia): ?>
                                <tr>
                                    <td><?= $materia['anio'] ?>°</td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($materia['codigo']) ?></span></td>
                                    <td><?= htmlspecialchars($materia['nombre']) ?></td>
                                    <td><?= htmlspecialchars($materia['curso']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $materia['tipo_problema'] == 'Taller' ? 'warning' : 'info' ?>">
                                            <?= $materia['tipo_problema'] ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <form method="POST" style="display: inline;" class="me-2">
                            <input type="hidden" name="accion" value="corregir_subgrupos">
                            <button type="submit" class="btn btn-info" onclick="return confirm('¿Marcar automáticamente las 6 materias de Construcción de la Ciudadanía?')">
                                <i class="bi bi-people"></i> Corregir Solo Ciudadanía (6 materias)
                            </button>
                        </form>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="accion" value="detectar_exhaustivo">
                            <button type="submit" class="btn btn-success" onclick="return confirm('¿Detectar y marcar TODAS las materias de taller + Construcción de Ciudadanía?\n\nEsto procesará todas las <?= count($verificaciones['sin_marcar']) ?> materias detectadas.')">
                                <i class="bi bi-magic"></i> Detectar TODOS los Talleres (<?= count($verificaciones['sin_marcar']) ?> materias)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Materias que necesitan configuración -->
    <?php if (!empty($verificaciones['necesitan_config'])): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-gear"></i> 
                        Materias Marcadas pero Sin Configurar (<?= count($verificaciones['necesitan_config']) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <p>Estas materias están marcadas como "requiere_subgrupos = 1" pero no tienen configuración:</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Año</th>
                                    <th>Código</th>
                                    <th>Materia</th>
                                    <th>Curso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($verificaciones['necesitan_config'] as $materia): ?>
                                <tr>
                                    <td><?= $materia['anio'] ?>°</td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($materia['codigo']) ?></span></td>
                                    <td><?= htmlspecialchars($materia['nombre']) ?></td>
                                    <td><?= htmlspecialchars($materia['curso']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle"></i>
                        <strong>Solución:</strong> Vaya a "Gestionar Subgrupos" y use el botón "Detectar y Configurar Automáticamente".
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Talleres detectados -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-tools"></i> 
                        Materias de Taller Detectadas (<?= count($verificaciones['talleres']) ?> / 68 esperadas)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($verificaciones['talleres']) == 68): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> <strong>¡Perfecto!</strong> Se detectaron las 68 materias de taller esperadas.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Se detectaron <?= count($verificaciones['talleres']) ?> materias de taller, pero se esperan 68.
                    </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <?php 
                        $tallersPorTipo = [];
                        foreach ($verificaciones['talleres'] as $taller) {
                            $tallersPorTipo[$taller['tipo_taller']][] = $taller;
                        }
                        ?>
                        
                        <?php foreach ($tallersPorTipo as $tipo => $talleres): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><?= $tipo ?> (<?= count($talleres) ?>)</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($talleres as $taller): ?>
                                        <div class="col-6 mb-1">
                                            <small>
                                                <span class="badge bg-<?= $taller['marcado_subgrupos'] == 'SÍ' ? 'success' : 'warning' ?>">
                                                    <?= htmlspecialchars($taller['codigo']) ?>
                                                </span>
                                                <?= $taller['anio'] ?>°
                                            </small>
                                        </div>
                                        <?php endforeach; ?>
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

    <!-- Construcción de Ciudadanía -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-people"></i> 
                        Construcción de la Ciudadanía (<?= count($verificaciones['ciudadania']) ?> / 6 esperadas)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($verificaciones['ciudadania']) == 6): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> <strong>¡Perfecto!</strong> Se detectaron las 6 materias de Construcción de la Ciudadanía esperadas.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Se detectaron <?= count($verificaciones['ciudadania']) ?> materias de Construcción de la Ciudadanía, pero se esperan 6.
                    </div>
                    <?php endif; ?>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Año</th>
                                    <th>Código</th>
                                    <th>Materia</th>
                                    <th>Curso</th>
                                    <th>Marcado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($verificaciones['ciudadania'] as $materia): ?>
                                <tr>
                                    <td><?= $materia['anio'] ?>°</td>
                                    <td><?= htmlspecialchars($materia['codigo']) ?></td>
                                    <td><?= htmlspecialchars($materia['nombre']) ?></td>
                                    <td><?= htmlspecialchars($materia['curso']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $materia['marcado_subgrupos'] == 'SÍ' ? 'success' : 'warning' ?>">
                                            <?= $materia['marcado_subgrupos'] ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Acciones disponibles -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> Acciones Disponibles</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <a href="gestionar_subgrupos.php" class="btn btn-primary w-100 py-3">
                                <i class="bi bi-gear mb-2 d-block fs-3"></i>
                                Gestionar Subgrupos
                                <small class="d-block">Configurar y asignar estudiantes</small>
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="materias.php" class="btn btn-info w-100 py-3 text-white">
                                <i class="bi bi-journal-text mb-2 d-block fs-3"></i>
                                Gestionar Materias
                                <small class="d-block">Ver y editar materias</small>
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="verificar_materias.php" class="btn btn-secondary w-100 py-3">
                                <i class="bi bi-arrow-clockwise mb-2 d-block fs-3"></i>
                                Recargar Verificación
                                <small class="d-block">Actualizar datos</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php
require_once 'footer.php';
?>