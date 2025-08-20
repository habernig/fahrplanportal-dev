<?php
/**
 * Fahrplanportal Module - REORGANISIERTE VERSION
 * Verwaltung von Bus-Fahrplänen für Kärntner Linien
 * 
 * ✅ REFACTORED: Aufgeteilt in spezialisierte Klassen für bessere Wartbarkeit
 * ✅ BEIBEHALTEN: Alle bestehende Funktionalität ohne Änderungen
 * ✅ GEFIXT: Übersichtliche Struktur mit klaren Verantwortlichkeiten
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Autoloader für FahrplanPortal Klassen
spl_autoload_register('fahrplanportal_autoload');

function fahrplanportal_autoload($class_name) {
    if (strpos($class_name, 'FahrplanPortal_') === 0) {
        $class_file = str_replace('_', '-', strtolower($class_name));
        $file_path = __DIR__ . '/includes/class-' . $class_file . '.php';
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

// ✅ GEFIXT: SHORTCODE IMMER LADEN (auch im Frontend)
if (file_exists(__DIR__ . '/functions/shortcode.php')) {
    require_once(__DIR__ . '/functions/shortcode.php');
    error_log('✅ FAHRPLANPORTAL: Shortcode geladen (Frontend + Admin)');
}

if (file_exists(__DIR__ . '/functions/search-logging.php')) {
    require_once(__DIR__ . '/functions/search-logging.php');
}

// ✅ ERWEITERT: Frontend ausschließen, Admin-AJAX + Frontend-AJAX erlauben
// ABER: Shortcode ist bereits geladen!
if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
    // Im Frontend: Nichts machen außer bei AJAX, sofort beenden
    // Shortcode ist aber bereits registriert!
    error_log('✅ FAHRPLANPORTAL: Frontend-Exit (Shortcode bereits geladen)');
    return;
}

// PDF-Parser nur laden wenn verfügbar (nur für Admin/AJAX)
if (file_exists(__DIR__ . '/functions/pdf_parser.php')) {
    require_once(__DIR__ . '/functions/pdf_parser.php');
}

// Plugin initialisieren
FahrplanPortal_Core::get_instance();