<?php
/**
 * FahrplanPortal Core Class
 * Hauptinitialisierung und Komponenten-Management
 * 
 * ✅ ERWEITERT: Publisher-Integration für Staging/Live-System
 * ✅ NEU: Publisher-Komponente in Core-Architektur
 * ✅ NEU: Live-System-Verfügbarkeit prüfen
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
    private $publisher;          // ✅ NEU: Publisher-Komponente
    
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
        
        error_log('✅ FAHRPLANPORTAL: Core initialisiert (Admin + AJAX Handler + Publisher-System)');
    }
    
    /**
     * ✅ ERWEITERT: Komponenten initialisieren (inkl. Publisher)
     */
    private function init_components() {
        // Utils zuerst (wird von anderen benötigt)
        $this->utils = new FahrplanPortal_Utils();
        
        // Database initialisieren
        $this->database = new FahrplanPortal_Database($this->pdf_parsing_enabled);
        
        // ✅ NEU: Publisher initialisieren (nach Database)
        $this->publisher = new FahrplanPortal_Publisher($this->database);
        
        // Parser initialisieren
        $this->parser = new FahrplanPortal_Parser($this->pdf_base_path, $this->pdf_parsing_enabled, $this->utils);
        
        // ✅ ERWEITERT: AJAX Handler mit Publisher initialisieren
        $this->ajax = new FahrplanPortal_Ajax($this->database, $this->parser, $this->utils, $this->pdf_parsing_enabled, $this->publisher);
        
        // Admin nur bei echtem Admin (kein AJAX)
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            // ✅ ERWEITERT: Admin mit Publisher initialisieren
            $this->admin = new FahrplanPortal_Admin($this->database, $this->utils, $this->pdf_base_path, $this->pdf_parsing_enabled, $this->publisher);
        }
        
        // ✅ NEU: Live-System-Status prüfen und loggen
        $this->check_live_system_status();
    }
    
    /**
     * ✅ NEU: Live-System-Status prüfen
     */
    private function check_live_system_status() {
        if ($this->publisher && $this->publisher->is_live_system_ready()) {
            error_log('✅ FAHRPLANPORTAL: Live-System betriebsbereit');
        } else {
            error_log('⚠️ FAHRPLANPORTAL: Live-System wird beim ersten Admin-Besuch initialisiert');
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
     * Getter für Komponenten (bestehend)
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
    
    /**
     * ✅ NEU: Publisher-Getter
     */
    public function get_publisher() {
        return $this->publisher;
    }
    
    /**
     * PDF-Parsing Status
     */
    public function is_pdf_parsing_enabled() {
        return $this->pdf_parsing_enabled;
    }
    
    /**
     * ✅ NEU: Live-System-Status für externe Abfragen
     */
    public function is_live_system_ready() {
        return $this->publisher ? $this->publisher->is_live_system_ready() : false;
    }
    
    /**
     * ✅ NEU: Publisher-Statistiken abrufen (für externe Nutzung)
     */
    public function get_publish_statistics() {
        return $this->publisher ? $this->publisher->get_publish_statistics() : array();
    }
    
    /**
     * ✅ NEU: Letzte Publish-Info für Frontend
     */
    public function get_last_publish_info() {
        if (!$this->publisher) {
            return array(
                'date' => '',
                'formatted' => 'System noch nicht veröffentlicht'
            );
        }
        
        $stats = $this->publisher->get_publish_statistics();
        $formatted_date = $this->publisher->get_last_publish_date();
        
        return array(
            'date' => $stats['last_publish'],
            'formatted' => $formatted_date ?: 'Noch nicht veröffentlicht',
            'live_count' => $stats['live_count']
        );
    }
    
    /**
     * ✅ NEU: Live-Tabellen-Name für Shortcode
     */
    public function get_live_table_name() {
        return $this->publisher ? $this->publisher->get_live_table_name() : $this->database->get_table_name();
    }
    
    /**
     * ✅ NEU: System-Status für Debug/Admin
     */
    public function get_system_status() {
        $status = array(
            'pdf_parsing_enabled' => $this->pdf_parsing_enabled,
            'live_system_ready' => $this->is_live_system_ready(),
            'components_loaded' => array(
                'database' => !is_null($this->database),
                'parser' => !is_null($this->parser),
                'utils' => !is_null($this->utils),
                'ajax' => !is_null($this->ajax),
                'admin' => !is_null($this->admin),
                'publisher' => !is_null($this->publisher)
            )
        );
        
        if ($this->publisher) {
            $status['publish_stats'] = $this->publisher->get_publish_statistics();
        }
        
        return $status;
    }
    
    /**
     * ✅ NEU: Entwickler-Debug-Info
     */
    public function debug_system_info() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return 'Debug-Modus nicht aktiviert';
        }
        
        $info = $this->get_system_status();
        
        error_log('🔍 FAHRPLANPORTAL SYSTEM STATUS:');
        error_log('   PDF-Parsing: ' . ($info['pdf_parsing_enabled'] ? 'Aktiviert' : 'Deaktiviert'));
        error_log('   Live-System: ' . ($info['live_system_ready'] ? 'Bereit' : 'Nicht bereit'));
        error_log('   Komponenten: ' . json_encode($info['components_loaded']));
        
        if (isset($info['publish_stats'])) {
            error_log('   Staging-Einträge: ' . $info['publish_stats']['staging_count']);
            error_log('   Live-Einträge: ' . $info['publish_stats']['live_count']);
            error_log('   Letzter Publish: ' . ($info['publish_stats']['last_publish'] ?: 'Nie'));
        }
        
        return $info;
    }
    
    /**
     * ✅ NEU: Sicherer Shutdown (für eventuelle Cleanup-Operationen)
     */
    public function shutdown() {
        // Eventuelle Cleanup-Operationen hier
        error_log('✅ FAHRPLANPORTAL: Core-Shutdown durchgeführt');
    }
}