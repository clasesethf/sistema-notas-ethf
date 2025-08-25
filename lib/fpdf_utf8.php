<?php
/**
 * fpdf_utf8.php - Extensión de FPDF con soporte para UTF-8
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

require('lib/fpdf/fpdf.php');

/**
 * Extensión de FPDF con mejor soporte para caracteres UTF-8
 */
class FPDF_UTF8 extends FPDF {
    /**
     * Función modificada para soportar UTF-8
     */
    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='') {
        // Convertir si no está vacío
        if($txt) {
            $txt = mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
        }
        // Llamar al método padre
        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
    }

    /**
     * Función modificada para soportar UTF-8
     */
    function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false) {
        // Convertir si no está vacío
        if($txt) {
            $txt = mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
        }
        // Llamar al método padre
        parent::MultiCell($w, $h, $txt, $border, $align, $fill);
    }

    /**
     * Función modificada para soportar UTF-8
     */
    function Write($h, $txt, $link='') {
        // Convertir si no está vacío
        if($txt) {
            $txt = mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
        }
        // Llamar al método padre
        parent::Write($h, $txt, $link);
    }
}
