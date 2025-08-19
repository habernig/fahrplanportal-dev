<?php
/**
 * FahrplanPortal Core Class
 * Hauptinitialisierung und Komponenten-Management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Core {
    
    private static $instance = null;
    private $admin;
    private $ajax;
    private $database;
    private $parser;
    private $utils;
    
    private $pdf_base_path;
    private $pdf_parsing_enabled;
    
    /**
     * Singleton Pattern
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor for singleton
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Hauptinitialisierung
     */
    private function init() {
        // Basis-Eigenschaften setzen
        $this->pdf_base_path = ABSPATH . 'fahrplaene/';
        $this->pdf_parsing_enabled = $this->check_pdf_parser_availability();
        
        // ✅ ERWEITERT: Frontend ausschließen, Admin-AJAX + Frontend-AJAX erlauben
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return; // Nur Frontend ohne AJAX ausschließen
        }
        
        // Komponenten initialisieren
        $this->init_components();
        
        error_log('✅ FAHRPLANPORTAL: Core initialisiert (Admin + Admin-AJAX Handler - OHNE Frontend)');
    }
    
    /**
     * Komponenten initialisieren
     */
    private function init_components() {
        // Utils zuerst (wird von anderen benötigt)
        $this->utils = new FahrplanPortal_Utils();
        
        // Database initialisieren
        $this->database = new FahrplanPortal_Database($this->pdf_parsing_enabled);
        
        // Parser initialisieren
        $this->parser = new FahrplanPortal_Parser($this->pdf_base_path, $this->pdf_parsing_enabled, $this->utils);
        
        // AJAX Handler initialisieren
        $this->ajax = new FahrplanPortal_Ajax($this->database, $this->parser, $this->utils, $this->pdf_parsing_enabled);
        
        // Admin nur bei echtem Admin (kein AJAX)
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            $this->admin = new FahrplanPortal_Admin($this->database, $this->utils, $this->pdf_base_path, $this->pdf_parsing_enabled);
        }
    }
    
    /**
     * PDF-Parser Verfügbarkeit prüfen
     */
    private function check_pdf_parser_availability() {
        // Prüfung 1: Funktion existiert
        if (!function_exists('hd_process_pdf_for_words')) {
            error_log('FAHRPLANPORTAL: hd_process_pdf_for_words Funktion nicht gefunden');
            return false;
        }
        
        // Prüfung 2: Parser-Klasse verfügbar (verschiedene Namespaces probieren)
        if (class_exists('\Smalot\PdfParser\Parser')) {
            error_log('FAHRPLANPORTAL: Smalot PDF Parser verfügbar (Namespace)');
            return true;
        }
        
        if (class_exists('Parser')) {
            error_log('FAHRPLANPORTAL: Parser-Klasse verfügbar (global)');
            return true;
        }
        
        // Prüfung 3: Composer Autoloader
        if (file_exists(ABSPATH . 'vendor/autoload.php')) {
            require_once ABSPATH . 'vendor/autoload.php';
            if (class_exists('\Smalot\PdfParser\Parser')) {
                error_log('FAHRPLANPORTAL: Smalot PDF Parser via Composer geladen');
                return true;
            }
        }
        
        error_log('FAHRPLANPORTAL: PDF-Parser nicht verfügbar');
        return false;
    }
    
    /**
     * Getter für Komponenten
     */
    public function get_database() {
        return $this->database;
    }
    
    public function get_parser() {
        return $this->parser;
    }
    
    public function get_utils() {
        return $this->utils;
    }
    
    public function get_admin() {
        return $this->admin;
    }
    
    public function get_ajax() {
        return $this->ajax;
    }
    
    public function is_pdf_parsing_enabled() {
        return $this->pdf_parsing_enabled;
    }
}