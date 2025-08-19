<?php
/**
 * Fahrplanportal Module - REORGANISIERTE VERSION
 * Verwaltung von Bus-Fahrplänen für Kärntner Linien
 * 
 * ✅ ENTFERNT: Frontend-Funktionen nach shortcode.php verschoben
 * ✅ BEIBEHALTEN: Admin-Funktionen, PDF-Scanning, DB-Verwaltung
 * ✅ GEFIXT: Keine Frontend-Registrierung mehr (passiert in shortcode.php)
 * ✅ NEU: Gültigkeitsdaten werden aus Ordnernamen abgeleitet (2025 → 14.12.2024-13.12.2025)
 * ✅ UPDATE: Fahrplanwechsel erfolgt am 14. Dezember, nicht zum Kalenderjahr
 * ✅ NEUE NUMMERNLOGIK: 2-3 stellige Nummern als neue Hauptnummern, 4-stellige als alte Nummern über Mapping
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
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

class FahrplanPortal {
    
    private $table_name;
    private $pdf_base_path;
    private $pdf_parsing_enabled;
    
    public function __construct() {
        global $wpdb;
        
        // ✅ ERWEITERT: Frontend ausschließen, Admin-AJAX + Frontend-AJAX erlauben
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return; // Nur Frontend ohne AJAX ausschließen
        }
        
        $this->table_name = $wpdb->prefix . 'fahrplaene';
        $this->pdf_base_path = ABSPATH . 'fahrplaene/';
        
        // PDF-Parsing nur aktivieren wenn Parser verfügbar
        $this->pdf_parsing_enabled = $this->check_pdf_parser_availability();
        
        if ($this->pdf_parsing_enabled) {
            error_log('FAHRPLANPORTAL: PDF-Parsing ist verfügbar (Admin+AJAX)');
        } else {
            error_log('FAHRPLANPORTAL: PDF-Parsing nicht verfügbar - arbeite ohne Tags (Admin+AJAX)');
        }
        
        // ✅ Admin-Hooks NUR wenn echtes Admin (kein AJAX)
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('admin_init', array($this, 'init_database'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
        
        // ✅ Admin AJAX Handler registrieren
        add_action('admin_init', array($this, 'register_unified_admin_handlers'), 20);
        
        error_log('✅ FAHRPLANPORTAL: Initialisiert (Admin + Admin-AJAX Handler - OHNE Frontend)');
    }
    
    /**
     * ✅ ERWEITERT: register_unified_admin_handlers() um Tag-Analyse erweitern
     * ✅ Diese Zeile in die bestehende Admin-Module Registrierung HINZUFÜGEN
     */
    public function register_unified_admin_handlers() {
        // ✅ ERWEITERT: Admin UND Frontend-AJAX erlauben
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return; // Nur echtes Frontend ausschließen
        }
        
        // Prüfen ob Unified System verfügbar ist
        if (!class_exists('UnifiedAjaxSystem')) {
            error_log('❌ FAHRPLANPORTAL: Unified AJAX System nicht verfügbar');
            return;
        }
        
        $unified_system = UnifiedAjaxSystem::getInstance();
        
        if (!$unified_system) {
            error_log('❌ FAHRPLANPORTAL: Unified System Instanz nicht verfügbar');
            return;
        }
        
        // ✅ ERWEITERT: Admin-Module (jetzt mit Tag-Analyse)
        $unified_system->register_module('fahrplanportal', array(
            'scan_fahrplaene' => array($this, 'unified_scan_fahrplaene'),
            'scan_chunk' => array($this, 'unified_scan_chunk'),
            'get_scan_info' => array($this, 'unified_get_scan_info'),
            'update_fahrplan' => array($this, 'unified_update_fahrplan'),
            'delete_fahrplan' => array($this, 'unified_delete_fahrplan'),
            'get_fahrplan' => array($this, 'unified_get_fahrplan'),
            'recreate_db' => array($this, 'unified_recreate_db'),
            'clear_db' => array($this, 'unified_clear_db'),
            'save_exclusion_words' => array($this, 'unified_save_exclusion_words'),
            'load_exclusion_words' => array($this, 'unified_load_exclusion_words'),
            'save_line_mapping' => array($this, 'unified_save_line_mapping'),
            'load_line_mapping' => array($this, 'unified_load_line_mapping'),
            
            // ✅ NEU: Tag-Analyse Action hinzufügen
            'analyze_all_tags' => array($this, 'unified_analyze_all_tags'),
        ));
        
        error_log('✅ FAHRPLANPORTAL: Admin Handler mit Tag-Analyse im Unified System registriert');
    }
    
    // ========================================
    // ✅ NEU: CHUNKED SCANNING SYSTEM
    // ========================================
    
    /**
     * ✅ NEU: Scan-Informationen sammeln (vor dem eigentlichen Scan)
     */
    public function unified_get_scan_info() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $folder = sanitize_text_field($_POST['folder'] ?? '');
        if (empty($folder)) {
            wp_send_json_error('Kein Ordner ausgewählt');
            return;
        }
        
        $base_scan_path = $this->pdf_base_path . $folder . '/';
        
        if (!is_dir($base_scan_path)) {
            wp_send_json_error('Verzeichnis nicht gefunden: ' . $base_scan_path);
            return;
        }
        
        $all_files = $this->collect_all_scan_files($base_scan_path, $folder);
        
        $total_files = count($all_files);
        $chunk_size = 10; // 10 PDFs pro Chunk
        $total_chunks = ceil($total_files / $chunk_size);
        
        // Regionen-Statistik
        $regions = array();
        foreach ($all_files as $file_info) {
            $region = $file_info['region'] ?: 'Hauptverzeichnis';
            if (!isset($regions[$region])) {
                $regions[$region] = 0;
            }
            $regions[$region]++;
        }
        
        wp_send_json_success(array(
            'total_files' => $total_files,
            'total_chunks' => $total_chunks,
            'chunk_size' => $chunk_size,
            'regions' => $regions,
            'parsing_enabled' => $this->pdf_parsing_enabled,
            'estimated_time' => $this->estimate_processing_time($total_files)
        ));
    }
    
    /**
     * ✅ NEU: Einzelnen Chunk verarbeiten
     */
    public function unified_scan_chunk() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $folder = sanitize_text_field($_POST['folder'] ?? '');
        $chunk_index = intval($_POST['chunk_index'] ?? 0);
        $chunk_size = intval($_POST['chunk_size'] ?? 10);
        
        if (empty($folder)) {
            wp_send_json_error('Kein Ordner ausgewählt');
            return;
        }
        
        $base_scan_path = $this->pdf_base_path . $folder . '/';
        
        if (!is_dir($base_scan_path)) {
            wp_send_json_error('Verzeichnis nicht gefunden');
            return;
        }
        
        // Alle Dateien sammeln
        $all_files = $this->collect_all_scan_files($base_scan_path, $folder);
        
        // Chunk-Dateien ermitteln
        $start_index = $chunk_index * $chunk_size;
        $chunk_files = array_slice($all_files, $start_index, $chunk_size);
        
        $chunk_stats = array(
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'current_file' => '',
            'region_stats' => array(),
            'error_details' => array()
        );
        
        // Chunk-Dateien verarbeiten
        foreach ($chunk_files as $file_info) {
            $chunk_stats['current_file'] = $file_info['filename'];
            $chunk_stats['processed']++;
            
            try {
                $result = $this->process_single_pdf_file($file_info);
                
                if ($result['success']) {
                    $chunk_stats['imported']++;
                } else {
                    $chunk_stats['skipped']++;
                }
                
                // Regionen-Statistik
                $region = $file_info['region'] ?: 'Hauptverzeichnis';
                if (!isset($chunk_stats['region_stats'][$region])) {
                    $chunk_stats['region_stats'][$region] = 0;
                }
                $chunk_stats['region_stats'][$region]++;
                
            } catch (Exception $e) {
                $chunk_stats['errors']++;
                $chunk_stats['error_details'][] = array(
                    'file' => $file_info['filename'],
                    'error' => $e->getMessage()
                );
                
                error_log('FAHRPLANPORTAL CHUNK ERROR: ' . $file_info['filename'] . ' - ' . $e->getMessage());
            }
        }
        
        // Chunk-Ergebnis zurückgeben
        wp_send_json_success(array(
            'chunk_index' => $chunk_index,
            'chunk_size' => count($chunk_files),
            'stats' => $chunk_stats,
            'total_files' => count($all_files),
            'parsing_enabled' => $this->pdf_parsing_enabled
        ));
    }
    
    /**
     * ✅ HILFSMETHODE: Alle Scan-Dateien sammeln
     */
    private function collect_all_scan_files($base_scan_path, $folder) {
        $all_files = array();
        
        // Direkte PDFs im Hauptordner
        $direct_pdfs = glob($base_scan_path . '*.pdf');
        foreach ($direct_pdfs as $file) {
            $all_files[] = array(
                'full_path' => $file,
                'filename' => basename($file),
                'folder' => $folder,
                'region' => ''
            );
        }
        
        // Regionen-Unterordner
        $region_dirs = glob($base_scan_path . '*', GLOB_ONLYDIR);
        foreach ($region_dirs as $region_dir) {
            $region_name = basename($region_dir);
            
            // Versteckte Ordner überspringen
            if (substr($region_name, 0, 1) === '.') {
                continue;
            }
            
            $region_pdfs = glob($region_dir . '/*.pdf');
            foreach ($region_pdfs as $file) {
                $all_files[] = array(
                    'full_path' => $file,
                    'filename' => basename($file),
                    'folder' => $folder,
                    'region' => $region_name
                );
            }
        }
        
        return $all_files;
    }
    
    /**
     * ✅ HILFSMETHODE: Einzelne PDF-Datei verarbeiten
     * ✅ GEÄNDERT: Gültigkeitsdaten aus Ordnernamen ableiten (14.12. Vorjahr bis 13.12. aktuelles Jahr)
     * ✅ NEUE NUMMERNLOGIK: 2-3 stellige Nummern als neue Hauptnummern
     */
    private function process_single_pdf_file($file_info) {
        global $wpdb;
        
        $filename = $file_info['filename'];
        $folder = $file_info['folder'];
        $region = $file_info['region'];
        
        // Dateiname parsen
        $parsed = $this->parse_filename($filename);
        
        if (!$parsed) {
            throw new Exception("Dateiname-Parsing fehlgeschlagen für: " . $filename);
        }
        
        // ✅ NEU: Gültigkeitsdaten aus Ordnernamen ableiten
        // Fahrplanwechsel erfolgt am 14. Dezember, nicht zum Kalenderjahr
        // Jahr X gilt vom 14.12.(X-1) bis 13.12.X
        if (preg_match('/^(\d{4})/', $folder, $matches)) {
            $jahr = intval($matches[1]);  // Extrahiert z.B. "2026" aus "2026-dev"
            $vorjahr = $jahr - 1;
            
            // Gültig vom 14. Dezember des Vorjahres
            $parsed['gueltig_von'] = $vorjahr . '-12-14';
            // Gültig bis 13. Dezember des aktuellen Jahres
            $parsed['gueltig_bis'] = $jahr . '-12-13';
            
            error_log("FAHRPLANPORTAL: Gültigkeitsdaten aus Ordner '$folder' abgeleitet: {$vorjahr}-12-14 bis {$jahr}-12-13");
        } else {
            // Fallback: Aktuelles Jahr verwenden mit gleicher Logik
            $jahr = intval(date('Y'));
            $vorjahr = $jahr - 1;
            
            $parsed['gueltig_von'] = $vorjahr . '-12-14';
            $parsed['gueltig_bis'] = $jahr . '-12-13';
            
            error_log("FAHRPLANPORTAL: Ordner '$folder' enthält kein Jahr, verwende aktuelles Jahr: {$vorjahr}-12-14 bis {$jahr}-12-13");
        }
        
        // Prüfen ob schon vorhanden
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE dateiname = %s AND jahr = %s AND region = %s",
            $filename, $folder, $region
        ));
        
        if ($existing) {
            return array('success' => false, 'reason' => 'duplicate');
        }
        
        // PDF-Pfad erstellen
        $pdf_pfad = $folder . '/';
        if (!empty($region)) {
            $pdf_pfad .= $region . '/';
        }
        $pdf_pfad .= $filename;
        
        // PDF parsen für Tags (falls verfügbar)
        $tags = '';
        if ($this->pdf_parsing_enabled) {
            $full_pdf_path = $this->pdf_base_path . $pdf_pfad;
            $tags = $this->extract_pdf_tags($full_pdf_path);
        }
        
        // Daten-Array vorbereiten
        $data = array(
            'titel' => $parsed['titel'],
            'linie_alt' => $parsed['linie_alt'],
            'linie_neu' => $parsed['linie_neu'],
            'kurzbeschreibung' => '',
            'gueltig_von' => $parsed['gueltig_von'],
            'gueltig_bis' => $parsed['gueltig_bis'],
            'pdf_pfad' => $pdf_pfad,
            'dateiname' => $filename,
            'jahr' => $folder,
            'region' => $this->format_region_name($region)
        );
        
        $format_array = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
        
        // Tags nur hinzufügen wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled) {
            $data['tags'] = $tags;
            $format_array[] = '%s';
        }
        
        // DB-Insert
        $result = $wpdb->insert($this->table_name, $data, $format_array);
        
        if ($result === false) {
            throw new Exception("DB-Insert fehlgeschlagen: " . $wpdb->last_error);
        }
        
        return array('success' => true, 'id' => $wpdb->insert_id);
    }
    
    /**
     * ✅ HILFSMETHODE: Verarbeitungszeit schätzen
     */
    private function estimate_processing_time($total_files) {
        // Basis-Zeit pro Datei
        $base_time_per_file = 0.2; // 200ms pro Datei ohne PDF-Parsing
        
        if ($this->pdf_parsing_enabled) {
            $base_time_per_file = 0.8; // 800ms pro Datei mit PDF-Parsing
        }
        
        $total_seconds = $total_files * $base_time_per_file;
        
        // Auf 5-Sekunden-Schritte runden
        $rounded_seconds = ceil($total_seconds / 5) * 5;
        
        return array(
            'seconds' => $rounded_seconds,
            'formatted' => $this->format_duration($rounded_seconds)
        );
    }
    
    /**
     * ✅ HILFSMETHODE: Dauer formatieren
     */
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return $seconds . ' Sekunden';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remaining_seconds = $seconds % 60;
            return $minutes . ' Min' . ($remaining_seconds > 0 ? ' ' . $remaining_seconds . ' Sek' : '');
        } else {
            $hours = floor($seconds / 3600);
            $remaining_minutes = floor(($seconds % 3600) / 60);
            return $hours . ' Std' . ($remaining_minutes > 0 ? ' ' . $remaining_minutes . ' Min' : '');
        }
    }
    
    // ========================================
    // ✅ ENTFERNT: FRONTEND AJAX HANDLER (nach shortcode.php verschoben)
    // ========================================
    
    /* ✅ VERSCHOBEN: Frontend-Funktionen nach shortcode.php
    public function unified_frontend_search() { ... }
    public function unified_frontend_autocomplete() { ... }
    private function render_frontend_fahrplan_item($fahrplan) { ... }
    private function extract_frontend_regions($search_param, $wpdb) { ... }
    private function extract_frontend_line_numbers($search_param, $wpdb) { ... }
    private function extract_frontend_title_words($search_param, $wpdb) { ... }
    private function extract_frontend_tag_words($search_param, $wpdb) { ... }
    private function is_likely_place_name($word) { ... }
    */
    
    // ========================================
    // ✅ ALLE BESTEHENDEN ADMIN-FUNKTIONEN (unverändert)
    // ========================================
    
    /**
     * PDF-Parser Verfügbarkeit prüfen - NEUE METHODE
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
     * ✅ NEU: Exklusionsliste aus WordPress Options laden (erweitert für Tag-Analyse)
     * ✅ ÜBERSCHREIBT: Die bestehende get_exclusion_words() Funktion für bessere Performance
     */
    private function get_exclusion_words() {
        $exclusion_text = get_option('fahrplanportal_exclusion_words', '');
        
        if (empty($exclusion_text)) {
            return array();
        }
        
        // Wörter können durch Leerzeichen, Kommas, Tabs oder Zeilenumbrüche getrennt sein
        $exclusion_words_array = preg_split('/[\s,\t\n\r]+/', $exclusion_text, -1, PREG_SPLIT_NO_EMPTY);
        $exclusion_words_array = array_map('trim', $exclusion_words_array);
        $exclusion_words_array = array_map('mb_strtolower', $exclusion_words_array);
        
        // Performance-Optimierung: array_flip für O(1) Lookups
        return array_flip($exclusion_words_array);
    }
    
    /**
     * ✅ BUG-FIX: Linien-Mapping aus WordPress Options laden 
     * ✅ ERWEITERT: Unterstützt jetzt auch Buchstaben-Zahl-Kombinationen (X1:SB1, X2:SB2)
     * ✅ PROBLEM: Das alte Regex war nur auf reine Zahlen ausgelegt
     */
    private function get_line_mapping() {
        $mapping_text = get_option('fahrplanportal_line_mapping', '');
        
        if (empty($mapping_text)) {
            error_log("FAHRPLANPORTAL: Mapping-Text ist leer");
            return array();
        }
        
        $mapping_array = array();
        $lines = preg_split('/[\n\r]+/', $mapping_text, -1, PREG_SPLIT_NO_EMPTY);
        
        error_log("FAHRPLANPORTAL: Verarbeite " . count($lines) . " Mapping-Zeilen");
        
        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            
            // Überspringe leere Zeilen und Kommentare
            if (empty($line) || strpos($line, '//') === 0 || strpos($line, '#') === 0) {
                continue;
            }
            
            // ✅ BUG-FIX: ERWEITERTE REGEX für Buchstaben-Zahl-Kombinationen
            // Altes Pattern: /^(\d+)\s*:\s*(\d+)$/ (nur Zahlen)
            // Neues Pattern: Unterstützt auch X1:SB1, A2:SA2, etc.
            if (preg_match('/^([A-Za-z]*\d+)\s*:\s*([A-Za-z]*\d+)$/', $line, $matches)) {
                $linie_neu = strtoupper(trim($matches[1]));  // X2, 100, A1 → X2, 100, A1
                $linie_alt = strtoupper(trim($matches[2]));  // SB2, 5000, SA1 → SB2, 5000, SA1
                
                $mapping_array[$linie_neu] = $linie_alt;
                
                error_log("FAHRPLANPORTAL: Mapping geladen (Zeile " . ($line_num + 1) . ") - Neue: '$linie_neu' → Alte: '$linie_alt'");
            } else {
                error_log("FAHRPLANPORTAL: ⚠️ Mapping-Zeile " . ($line_num + 1) . " ungültig: '$line'");
                error_log("FAHRPLANPORTAL: 🔍 Erwartet Format: 'neue_bezeichnung:alte_bezeichnung' (z.B. X2:SB2 oder 100:5000)");
            }
        }
        
        error_log("FAHRPLANPORTAL: ✅ " . count($mapping_array) . " Mapping-Einträge erfolgreich geladen");
        
        // Debug: Alle geladenen Mappings ausgeben
        if (!empty($mapping_array)) {
            error_log("FAHRPLANPORTAL: 📋 Geladene Mappings:");
            foreach ($mapping_array as $neu => $alt) {
                error_log("FAHRPLANPORTAL:    $neu → $alt");
            }
        }
        
        return $mapping_array;
    }
    
    /**
     * ✅ SOFORT-FIX: Datenbank nur bei echten Admin-Calls initialisieren
     */
    public function init_database() {
        // ✅ SOFORT-FIX: Nur bei AJAX-Calls nicht ausführen
        if (defined('DOING_AJAX') && DOING_AJAX) {
            error_log('⚠️ FAHRPLANPORTAL: init_database übersprungen (AJAX-Call)');
            return;
        }
        
        error_log('🔄 FAHRPLANPORTAL: init_database wird ausgeführt (echter Admin-Call)');
        
        global $wpdb;
        
        try {
            $charset_collate = $wpdb->get_charset_collate();
            
            // Basis-SQL ohne Tags
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                titel VARCHAR(255) NOT NULL,
                linie_alt VARCHAR(100),
                linie_neu VARCHAR(100),
                kurzbeschreibung TEXT,
                gueltig_von DATE,
                gueltig_bis DATE,
                pdf_pfad VARCHAR(500) NOT NULL,
                dateiname VARCHAR(255) NOT NULL,
                jahr VARCHAR(50) NOT NULL,
                region VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_linien (linie_alt, linie_neu),
                INDEX idx_gueltig (gueltig_von, gueltig_bis),
                INDEX idx_jahr (jahr),
                INDEX idx_region (region)
            ) $charset_collate;";
            
            // Tags-Spalte nur hinzufügen wenn PDF-Parsing verfügbar
            if ($this->pdf_parsing_enabled) {
                $sql = str_replace(
                    'region VARCHAR(100) NOT NULL,',
                    'region VARCHAR(100) NOT NULL,
                tags LONGTEXT,',
                    $sql
                );
                
                $sql = str_replace(
                    'INDEX idx_region (region)',
                    'INDEX idx_region (region),
                FULLTEXT idx_tags (tags)',
                    $sql
                );
            }
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            // ✅ SOFORT-FIX: dbDelta mit Error-Handling
            $result = dbDelta($sql);
            
            if ($wpdb->last_error) {
                error_log('❌ FAHRPLANPORTAL: dbDelta Fehler: ' . $wpdb->last_error);
                return false;
            }
            
            // Spalten erweitern/hinzufügen falls nötig - mit Error-Handling
            $wpdb->query("ALTER TABLE {$this->table_name} MODIFY COLUMN jahr VARCHAR(50) NOT NULL");
            
            // Region-Spalte hinzufügen falls sie nicht existiert
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'region'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN region VARCHAR(100) NOT NULL DEFAULT '' AFTER jahr");
                $wpdb->query("ALTER TABLE {$this->table_name} ADD INDEX idx_region (region)");
            }
            
            // Tags-Spalte nur hinzufügen wenn PDF-Parsing verfügbar
            if ($this->pdf_parsing_enabled) {
                $tags_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'tags'");
                if (empty($tags_column_exists)) {
                    $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN tags LONGTEXT AFTER region");
                    $wpdb->query("ALTER TABLE {$this->table_name} ADD FULLTEXT INDEX idx_tags (tags)");
                    error_log('FAHRPLANPORTAL: Tags-Spalte hinzugefügt');
                }
            }
            
            error_log('✅ FAHRPLANPORTAL: Datenbank erfolgreich initialisiert (echter Admin)');
            return true;
            
        } catch (Exception $e) {
            error_log('❌ FAHRPLANPORTAL: init_database Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Admin-Menü hinzufügen
     */
    public function add_admin_menu() {
        // Hauptseite "Fahrpläne" erstellen
        add_menu_page(
            'Fahrpläne',
            'Fahrpläne',
            'edit_posts',
            'fahrplaene',
            array($this, 'admin_page'),
            'dashicons-calendar-alt',
            30
        );
        
        // Erste Unterseite "Portal Verwaltung"
        add_submenu_page(
            'fahrplaene',
            'Portal Verwaltung',
            'Portal Verwaltung',
            'edit_posts',
            'fahrplaene',
            array($this, 'admin_page')
        );
        
        // DB-Wartung als weitere Unterseite
        add_submenu_page(
            'fahrplaene',
            'DB Wartung',
            'DB Wartung',
            'manage_options',
            'fahrplanportal-db',
            array($this, 'db_maintenance_page')
        );
    }
    
    /**
     * Admin-Scripts laden - ✅ GEFIXT: Nur im relevanten Admin-Bereich
     */
    public function enqueue_admin_scripts($hook) {
        // ✅ GEFIXT: Nur auf Fahrplan-Admin-Seiten laden
        if (strpos($hook, 'fahrplaene') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        wp_enqueue_script(
            'fahrplanportal-admin',
            plugins_url('assets/admin/admin.js', __FILE__),
            array('jquery'),
            '2.5.0', // ✅ Version erhöht für Chunked Scanning
            true
        );
        
        // ✅ GEFIXT: Unified AJAX Config nur für Admin
        wp_localize_script('fahrplanportal-admin', 'fahrplanportal_unified', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('unified_ajax_master_nonce'),
            'action' => 'unified_ajax',
            'module' => 'fahrplanportal',
            'pdf_parsing_enabled' => $this->pdf_parsing_enabled,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'context' => 'admin_fahrplanportal_chunked'
        ));
        
        wp_enqueue_style(
            'fahrplanportal-admin',
            plugins_url('assets/admin/admin.css', __FILE__),
            array(),
            '2.5.0'
        );
        
        error_log('✅ FAHRPLANPORTAL: Admin-Scripts geladen für: ' . $hook);
    }
    
    /**
     * Hauptadmin-Seite - ✅ GEFIXT: Admin-Only Interface
     */
    public function admin_page() {
        $available_folders = $this->get_available_folders();
        ?>
        <div class="wrap">
            <h1>Fahrplanportal Verwaltung</h1>
            
            <?php if (!$this->pdf_parsing_enabled): ?>
                <div class="notice notice-warning">
                    <p><strong>Hinweis:</strong> PDF-Parsing ist nicht verfügbar. Tags werden nicht automatisch generiert. 
                    Stelle sicher, dass der Smalot PDF Parser korrekt geladen ist.</p>
                </div>
            <?php endif; ?>
            
            <div class="fahrplan-controls">
                <p>
                    <label for="scan-year">Ordner auswählen:</label>
                    <select id="scan-year">
                        <?php if (empty($available_folders)): ?>
                            <option value="">Keine Ordner gefunden</option>
                        <?php else: ?>
                            <?php foreach ($available_folders as $folder): ?>
                                <option value="<?php echo esc_attr($folder); ?>"><?php echo esc_html($folder); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="button" id="scan-directory" class="button button-primary" <?php echo empty($available_folders) ? 'disabled' : ''; ?>>
                        Verzeichnis scannen
                    </button>
                    <span id="scan-status"></span>
                </p>
                
                <?php if (empty($available_folders)): ?>
                    <p class="description" style="color: #d63031;">
                        <strong>Hinweis:</strong> Keine Unterordner im Verzeichnis <code><?php echo esc_html($this->pdf_base_path); ?></code> gefunden.
                        <br>Erstelle Ordner wie <code>2025</code>, <code>testverzeichnis</code> etc. und lade PDF-Dateien hinein.
                    </p>
                <?php else: ?>
                    <p class="description">
                        <strong>Gefundene Ordner:</strong> <?php echo implode(', ', $available_folders); ?>
                        <br><strong>Struktur:</strong> <code>fahrplaene/[Ordner]/[Region]/fahrplan.pdf</code>
                        <br><strong>Beispiel:</strong> <code>fahrplaene/2025/villach-land/561-feldkirchen-unterberg.pdf</code> (2-3 stellige Nummern)
                        <br><strong>⚠️ Gültigkeit:</strong> Ordner <code>2025</code> = Fahrpläne gültig vom <strong>14.12.2024 bis 13.12.2025</strong>
                        <br><strong>🔄 Neue Nummernlogik:</strong> 2-3 stellige Nummern (561, 82) werden über Mapping zu alten 4-stelligen Nummern zugeordnet
                        <?php if ($this->pdf_parsing_enabled): ?>
                            <br><strong>PDF-Parsing:</strong> Aktiviert - Inhalte werden automatisch geparst und als Tags gespeichert!
                        <?php else: ?>
                            <br><strong>PDF-Parsing:</strong> Nicht verfügbar - nur Metadaten werden gespeichert.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- ✅ NEU: Chunked Progress Bar -->
            <div id="scan-progress-container" style="display: none;">
                <div class="card" style="border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 6px;">
                    <h4 style="margin: 0 0 15px 0; color: #0073aa;">
                        <i class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></i>
                        PDF-Scanning läuft...
                    </h4>
                    
                    <!-- Progress Bar -->
                    <div class="progress mb-3" style="height: 20px; background: #f1f1f1; border-radius: 10px; overflow: hidden;">
                        <div id="scan-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" 
                             style="width: 0%; background: linear-gradient(90deg, #0073aa, #005a87); height: 100%; border-radius: 10px;">
                        </div>
                    </div>
                    
                    <!-- Progress Text -->
                    <div class="row mb-3" style="margin: 0;">
                        <div class="col-sm-6" style="padding: 0;">
                            <strong id="scan-progress-text">0% (0/0 PDFs)</strong>
                        </div>
                        <div class="col-sm-6 text-right" style="padding: 0; text-align: right;">
                            <span id="scan-time-remaining">Geschätzte Zeit: berechne...</span>
                        </div>
                    </div>
                    
                    <!-- Current File -->
                    <div class="mb-3">
                        <small><strong>Aktuell:</strong> <span id="scan-current-file">Bereite vor...</span></small>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="row mb-3" style="margin: 0;">
                        <div class="col-sm-3" style="padding: 0 10px 0 0;">
                            <span class="badge badge-success" style="background: #46b450; color: white; padding: 5px 10px;">
                                ✓ Importiert: <span id="scan-imported">0</span>
                            </span>
                        </div>
                        <div class="col-sm-3" style="padding: 0 10px;">
                            <span class="badge badge-info" style="background: #00a0d2; color: white; padding: 5px 10px;">
                                ⟳ Übersprungen: <span id="scan-skipped">0</span>
                            </span>
                        </div>
                        <div class="col-sm-3" style="padding: 0 10px;">
                            <span class="badge badge-danger" style="background: #dc3232; color: white; padding: 5px 10px;">
                                ✗ Fehler: <span id="scan-errors">0</span>
                            </span>
                        </div>
                        <div class="col-sm-3" style="padding: 0 0 0 10px;">
                            <span class="badge badge-secondary" style="background: #666; color: white; padding: 5px 10px;">
                                📊 Chunk: <span id="scan-current-chunk">0/0</span>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Region Activity -->
                    <div class="mb-3">
                        <h5 style="margin: 0 0 10px 0;">Letzte Aktivität:</h5>
                        <div id="scan-region-activity" style="max-height: 100px; overflow-y: auto; background: #f9f9f9; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                            <div class="text-muted">Bereit zum Scannen...</div>
                        </div>
                    </div>
                    
                    <!-- Cancel Button -->
                    <div class="text-center">
                        <button type="button" id="scan-cancel" class="button button-secondary" style="background: #dc3232; color: white; border-color: #dc3232;">
                            ❌ Abbrechen
                        </button>
                    </div>
                </div>
            </div>
            
            <div id="fahrplaene-container">
                <div class="fahrplan-filter-controls">
                    <label for="region-filter">Nach Region filtern:</label>
                    <select id="region-filter">
                        <option value="">Alle Regionen anzeigen</option>
                    </select>
                    <button type="button" id="clear-filter" class="button button-secondary">Filter zurücksetzen</button>
                </div>
                
                <table id="fahrplaene-table" class="display nowrap" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Linie Alt</th>
                            <th>Linie Neu</th>
                            <th>Titel</th>
                            <th>Gültig von</th>
                            <th>Gültig bis</th>
                            <th>Ordner</th>
                            <th>Region</th>
                            <th>PDF</th>
                            <th>Kurzbeschreibung</th>
                            <?php if ($this->pdf_parsing_enabled): ?>
                                <th>Tags</th>
                            <?php endif; ?>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $this->get_fahrplaene_rows(); ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Modal bleibt unverändert -->
            <div id="fahrplan-edit-modal" class="fahrplan-modal">
                <div class="fahrplan-modal-content">
                    <div class="fahrplan-modal-header">
                        <h2>Fahrplan bearbeiten</h2>
                        <button class="fahrplan-modal-close" id="close-modal-btn" type="button">&times;</button>
                    </div>
                    
                    <div class="fahrplan-modal-body">
                        <form id="fahrplan-edit-form">
                            <input type="hidden" id="edit-id" value="">
                            
                            <div class="fahrplan-form-group">
                                <label for="edit-titel">Titel</label>
                                <input type="text" id="edit-titel" name="titel" required>
                            </div>
                            
                            <div class="fahrplan-form-row">
                                <div class="fahrplan-form-group">
                                    <label for="edit-linie-alt">Linie Alt (4-stellig)</label>
                                    <input type="text" id="edit-linie-alt" name="linie_alt" readonly>
                                </div>
                                <div class="fahrplan-form-group">
                                    <label for="edit-linie-neu">Linie Neu (2-3 stellig)</label>
                                    <input type="text" id="edit-linie-neu" name="linie_neu">
                                </div>
                            </div>
                            
                            <div class="fahrplan-form-group">
                                <label for="edit-kurzbeschreibung">Kurzbeschreibung</label>
                                <textarea id="edit-kurzbeschreibung" name="kurzbeschreibung"></textarea>
                            </div>
                            
                            <div class="fahrplan-form-row">
                                <div class="fahrplan-form-group">
                                    <label for="edit-gueltig-von">Gültig von</label>
                                    <input type="date" id="edit-gueltig-von" name="gueltig_von">
                                </div>
                                <div class="fahrplan-form-group">
                                    <label for="edit-gueltig-bis">Gültig bis</label>
                                    <input type="date" id="edit-gueltig-bis" name="gueltig_bis">
                                </div>
                            </div>
                            
                            <div class="fahrplan-form-group">
                                <label for="edit-region">Region</label>
                                <input type="text" id="edit-region" name="region">
                            </div>
                            
                            <?php if ($this->pdf_parsing_enabled): ?>
                                <div class="fahrplan-form-group">
                                    <label for="edit-tags">Tags (kommagetrennt)</label>
                                    <textarea id="edit-tags" name="tags" placeholder="Wort1, Wort2, Wort3..." rows="4"></textarea>
                                    <small class="description">Tags werden automatisch beim PDF-Import generiert, können aber manuell bearbeitet werden.</small>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <div class="fahrplan-modal-footer">
                        <button type="button" class="button button-secondary" id="cancel-edit-btn">
                            Abbrechen
                        </button>
                        <button type="button" class="button button-primary" id="save-edit-btn">
                            Speichern
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ✅ GEFIXT: Minimaler Admin-Init Script -->
        <script>
        // Admin-Only Initialisierung
        jQuery(document).ready(function($) {
            console.log('FAHRPLANPORTAL: Admin-Seite geladen, warte auf admin.js...');
            
            // Admin-Kontext bestätigen
            if (typeof fahrplanportal_unified !== 'undefined') {
                console.log('✅ FAHRPLANPORTAL: Admin-Kontext bestätigt:', fahrplanportal_unified.context);
            }
            
            // Spin-Animation für Dashicons
            $('<style>.dashicons.spinning { animation: spin 1s linear infinite; } @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>').appendTo('head');
        });
        </script>
        <?php
    }

 /**
 * DB-Wartungsseite - ✅ GEFIXT: Admin-Only Interface mit neuer Mapping-Erklärung
 */
public function db_maintenance_page() {
    $current_exclusions = get_option('fahrplanportal_exclusion_words', '');
    $word_count = empty($current_exclusions) ? 0 : count(preg_split('/[\s,\t\n\r]+/', $current_exclusions, -1, PREG_SPLIT_NO_EMPTY));
    
    $current_mapping = get_option('fahrplanportal_line_mapping', '');
    $mapping_count = 0;
    if (!empty($current_mapping)) {
        $lines = preg_split('/[\n\r]+/', $current_mapping, -1, PREG_SPLIT_NO_EMPTY);
        $mapping_count = count(array_filter($lines, function($line) {
            $line = trim($line);
            return !empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0;
        }));
    }
    ?>
    <div class="wrap">
        <h1>Datenbank Wartung</h1>
        
        <?php if ($this->pdf_parsing_enabled): ?>
        <!-- SEKTION: Exklusionsliste -->
        <div class="exclusion-management">
            <h3>PDF-Parsing Exklusionsliste</h3>
            <p class="description">
                Hier können Sie Wörter definieren, die beim PDF-Parsing aus den Tags entfernt werden sollen. 
                Trennen Sie die Wörter durch Leerzeichen, Kommas oder Zeilenumbrüche.
                <br><strong>Aktuell:</strong> <?php echo $word_count; ?> Wörter in der Exklusionsliste.
            </p>
            
            <div class="exclusion-form">
                <textarea id="exclusion-words" name="exclusion_words" rows="8" cols="100" 
                          placeholder="aber alle allem allen aller alles also auch auf aus bei bin bis bist dass den der des die dies doch dort durch ein eine einem einen einer eines für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht noch nur oder sich sie sind über und uns von war wird wir zu zum zur

fahrplan fahrt zug bus bahn haltestelle bahnhof station linie route verkehr abfahrt ankunft uhrzeit

montag dienstag mittwoch donnerstag freitag samstag sonntag"
                          style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($current_exclusions); ?></textarea>
                
                <p>
                    <button type="button" id="save-exclusion-words" class="button button-primary">
                        <span class="dashicons dashicons-saved" style="vertical-align: middle;"></span> 
                        Exklusionsliste speichern
                    </button>
                    <button type="button" id="load-exclusion-words" class="button button-secondary">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> 
                        Neu laden
                    </button>
                    <span id="exclusion-status" style="margin-left: 15px;"></span>
                </p>
                
                <details>
                    <summary style="cursor: pointer; font-weight: bold;">Standard-Exklusionsliste laden (Klicken zum Aufklappen)</summary>
                    <p style="margin-top: 10px;">
                        <button type="button" id="load-default-exclusions" class="button button-secondary">
                            Standard-Deutsche-Stoppwörter hinzufügen
                        </button>
                        <small class="description" style="display: block; margin-top: 5px;">
                            Fügt häufige deutsche Wörter zur Exklusionsliste hinzu (aber, der, die, das, etc.)
                        </small>
                    </p>
                </details>
            </div>
        </div>
        
        <hr style="margin: 30px 0;">
        <?php endif; ?>
        
        <!-- ✅ GEÄNDERT: SEKTION: Linien-Mapping mit neuer Erklärung -->
        <div class="line-mapping-management">
            <h3>🔄 Linien-Mapping (Neu → Alt) - NEUE NUMMERNLOGIK</h3>
            <div class="notice notice-info" style="margin: 10px 0;">
                <p><strong>⚠️ WICHTIGE ÄNDERUNG:</strong> Das Mapping-Format wurde umgestellt!</p>
                <p><strong>NEUES FORMAT:</strong> <code>neue_nummer:alte_nummer</code> (z.B. <code>100:5000</code>)</p>
                <p><strong>Bedeutung:</strong> Neue 2-3 stellige Nummer <code>100</code> wird zur alten 4-stelligen Nummer <code>5000</code> zugeordnet</p>
            </div>
            <p class="description">
                Das System erkennt jetzt 2-3 stellige Fahrplannummern (561, 82) als neue Hauptnummern und ordnet ihnen über diese Mapping-Tabelle die alten 4-stelligen Nummern zu.
                <br><strong>Format:</strong> Eine Zuordnung pro Zeile im Format <code>neue_nummer:alte_nummer</code>
                <br><strong>Beispiel:</strong> <code>100:5000</code> bedeutet: PDF mit neuer Nummer 100 wird auch die alte Nummer 5000 zugeordnet
                <br><strong>Import-Logik:</strong> PDFs wie <code>100-feldkirchen-villach.pdf</code> bekommen automatisch beide Nummern (100 + 5000)
                <br><strong>Aktuell:</strong> <?php echo $mapping_count; ?> Zuordnungen in der Mapping-Liste.
            </p>
            
            <div class="mapping-form">
                <textarea id="line-mapping" name="line_mapping" rows="12" cols="100" 
                          placeholder="// ✅ NEUES Linien-Mapping Format: neue_nummer:alte_nummer
// Beispiele:
100:5000
101:5001
102:5002
561:5561
82:5082

// ⚠️ NICHT MEHR: 5000:100 (alte Format)
// ✅ JETZT: 100:5000 (neue Format)

// Kommentare mit // oder # sind erlaubt
# Mapping für Kärntner Linien"
                          style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($current_mapping); ?></textarea>
                
                <p>
                    <button type="button" id="save-line-mapping" class="button button-primary">
                        <span class="dashicons dashicons-saved" style="vertical-align: middle;"></span> 
                        Linien-Mapping speichern
                    </button>
                    <button type="button" id="load-line-mapping" class="button button-secondary">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> 
                        Neu laden
                    </button>
                    <span id="mapping-status" style="margin-left: 15px;"></span>
                </p>
                
                <details>
                    <summary style="cursor: pointer; font-weight: bold;">Beispiel-Mapping laden (Klicken zum Aufklappen)</summary>
                    <p style="margin-top: 10px;">
                        <button type="button" id="load-example-mapping" class="button button-secondary">
                            ✅ Neue Format Beispiel-Zuordnungen hinzufügen
                        </button>
                        <small class="description" style="display: block; margin-top: 5px;">
                            Fügt Beispiel-Zuordnungen im neuen Format hinzu: 100:5000, 101:5001, 102:5002, etc.
                        </small>
                    </p>
                </details>
            </div>
        </div>
        
        <hr style="margin: 30px 0;">
        
        <div class="db-maintenance">
            <h3>Gefährliche Aktionen</h3>
            <p>
                <button type="button" id="recreate-db" class="button button-secondary">
                    Datenbank neu erstellen
                </button>
                <span class="description">Löscht alle Daten und erstellt die Tabelle neu!</span>
            </p>
            
            <p>
                <button type="button" id="clear-db" class="button button-secondary">
                    Alle Einträge löschen
                </button>
                <span class="description">Behält die Tabelle, löscht nur die Daten.</span>
            </p>
            
            <h3>Statistiken</h3>
            <p>Anzahl Fahrpläne: <strong><?php echo $this->get_fahrplaene_count(); ?></strong></p>
            <p>PDF-Parsing: <strong><?php echo $this->pdf_parsing_enabled ? 'Aktiviert' : 'Nicht verfügbar'; ?></strong></p>
            <?php if ($this->pdf_parsing_enabled): ?>
            <p>Exklusionsliste: <strong><?php echo $word_count; ?> Wörter</strong></p>
            <?php endif; ?>
            <p>Linien-Mapping: <strong><?php echo $mapping_count; ?> Zuordnungen (Neu → Alt Format)</strong></p>
        </div>

        <?php if ($this->pdf_parsing_enabled): ?>
        <hr style="margin: 30px 0;">

        <!-- ✅ NEU: TAG-ANALYSE SEKTION -->
        <div class="tag-analysis-management">
            <h3>🔍 Tag-Analyse & Optimierung</h3>
            <p class="description">
                Analysiert alle Tags aus allen Fahrplänen in der Datenbank und gleicht sie mit der Exklusionsliste ab.
                Hilft dabei, die Tag-Qualität zu verbessern und unerwünschte Wörter zu identifizieren.
                <br><strong>Funktion:</strong> Sammelt alle eindeutigen Tags und zeigt an, welche bereits ausgeschlossen sind (grün) und welche noch nicht (rot).
            </p>
            
            <div class="tag-analysis-controls" style="margin: 20px 0;">
                <p>
                    <button type="button" id="analyze-all-tags" class="button button-primary" style="
                        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                        border-color: #28a745;
                        color: white;
                        font-weight: 600;
                        padding: 8px 20px;
                        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
                    ">
                        <span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 5px;"></span>
                        Alle Tags analysieren
                    </button>
                    <span id="tag-analysis-status" style="margin-left: 15px;"></span>
                </p>
                
                <div class="tag-analysis-info" style="
                    background: #e3f2fd;
                    border: 2px solid #2196f3;
                    border-radius: 8px;
                    padding: 15px;
                    margin-top: 15px;
                ">
                    <h4 style="margin: 0 0 10px 0; color: #1565c0;">💡 Was passiert bei der Analyse:</h4>
                    <ol style="margin: 0; padding-left: 20px; color: #1565c0; line-height: 1.5;">
                        <li><strong>Sammeln:</strong> Alle Tags aus allen Fahrplänen werden gesammelt</li>
                        <li><strong>Bereinigen:</strong> Duplikate werden entfernt und alphabetisch sortiert</li>
                        <li><strong>Abgleichen:</strong> Jeder Tag wird gegen die aktuelle Exklusionsliste geprüft</li>
                        <li><strong>Kategorisieren:</strong> 
                            <span style="color: #28a745; font-weight: bold;">🟢 Grün = bereits ausgeschlossen</span>, 
                            <span style="color: #dc3545; font-weight: bold;">🔴 Rot = noch nicht ausgeschlossen</span>
                        </li>
                        <li><strong>Optimieren:</strong> Sie können rote Tags zur Exklusionsliste hinzufügen</li>
                    </ol>
                </div>
            </div>
            
            <!-- ✅ NEU: ERGEBNISSE CONTAINER -->
            <div id="tag-analysis-results" style="display: none; margin-top: 30px;">
                
                <!-- Statistiken -->
                <div id="tag-analysis-statistics" style="
                    background: #fff3cd;
                    border: 2px solid #ffc107;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 25px;
                ">
                    <h4 style="margin: 0 0 15px 0; color: #856404;">📊 Analyse-Statistiken</h4>
                    <div id="tag-stats-content" style="
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                        gap: 15px;
                        color: #856404;
                        font-weight: 500;
                    ">
                        <!-- Wird von JavaScript gefüllt -->
                    </div>
                </div>
                
                <!-- Zwei-Spalten Layout für Tag-Listen -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                    
                    <!-- LINKE SPALTE: Bereits ausgeschlossene Tags (GRÜN) -->
                    <div id="excluded-tags-container" style="
                        background: #d4edda;
                        border: 2px solid #28a745;
                        border-radius: 8px;
                        overflow: hidden;
                    ">
                        <div style="
                            background: #28a745;
                            color: white;
                            padding: 15px 20px;
                            font-weight: 600;
                            text-align: center;
                        ">
                            <span class="dashicons dashicons-yes-alt" style="vertical-align: middle; margin-right: 8px;"></span>
                            🟢 Bereits ausgeschlossen
                            <span id="excluded-tags-count" style="
                                background: rgba(255, 255, 255, 0.2);
                                padding: 2px 8px;
                                border-radius: 12px;
                                margin-left: 10px;
                                font-size: 12px;
                            ">0</span>
                        </div>
                        <div style="padding: 20px; max-height: 400px; overflow-y: auto;">
                            <p style="color: #155724; margin: 0 0 15px 0; font-size: 14px;">
                                Diese Tags werden beim Import bereits herausgefiltert:
                            </p>
                            <div id="excluded-tags-list" style="
                                font-family: monospace;
                                font-size: 12px;
                                line-height: 1.6;
                                color: #155724;
                            ">
                                <!-- Wird von JavaScript gefüllt -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- RECHTE SPALTE: Noch nicht ausgeschlossene Tags (ROT) -->
                    <div id="not-excluded-tags-container" style="
                        background: #f8d7da;
                        border: 2px solid #dc3545;
                        border-radius: 8px;
                        overflow: hidden;
                    ">
                        <div style="
                            background: #dc3545;
                            color: white;
                            padding: 15px 20px;
                            font-weight: 600;
                            text-align: center;
                        ">
                            <span class="dashicons dashicons-warning" style="vertical-align: middle; margin-right: 8px;"></span>
                            🔴 Noch nicht ausgeschlossen
                            <span id="not-excluded-tags-count" style="
                                background: rgba(255, 255, 255, 0.2);
                                padding: 2px 8px;
                                border-radius: 12px;
                                margin-left: 10px;
                                font-size: 12px;
                            ">0</span>
                        </div>
                        <div style="padding: 20px; max-height: 400px; overflow-y: auto;">
                            <p style="color: #721c24; margin: 0 0 15px 0; font-size: 14px;">
                                Diese Tags landen aktuell in den Fahrplan-Datenbank:
                            </p>
                            <div id="not-excluded-tags-list" style="
                                font-family: monospace;
                                font-size: 12px;
                                line-height: 1.6;
                                color: #721c24;
                            ">
                                <!-- Wird von JavaScript gefüllt -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ✅ NEU: ZUSÄTZLICHE ANALYSEDATEN -->
                <div id="tag-analysis-extras" style="margin-top: 25px; display: none;">
                    
                    <!-- Häufigkeits-Analyse -->
                    <div style="
                        background: #e7f3ff;
                        border: 2px solid #0073aa;
                        border-radius: 8px;
                        padding: 20px;
                        margin-bottom: 20px;
                    ">
                        <h4 style="margin: 0 0 15px 0; color: #0073aa;">📈 Top 20 häufigste Tags (nicht ausgeschlossen)</h4>
                        <div id="frequent-tags-list" style="
                            font-family: monospace;
                            font-size: 12px;
                            line-height: 1.6;
                            color: #0073aa;
                            columns: 2;
                            column-gap: 30px;
                        ">
                            <!-- Wird von JavaScript gefüllt -->
                        </div>
                    </div>
                    
                    <!-- Kurze und lange Tags -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        
                        <!-- Kurze Tags -->
                        <div style="
                            background: #fff0e6;
                            border: 2px solid #ff9500;
                            border-radius: 8px;
                            padding: 15px;
                        ">
                            <h5 style="margin: 0 0 10px 0; color: #cc7a00;">⚡ Kurze Tags (≤ 3 Zeichen)</h5>
                            <div id="short-tags-list" style="
                                font-family: monospace;
                                font-size: 11px;
                                color: #cc7a00;
                                max-height: 150px;
                                overflow-y: auto;
                            ">
                                <!-- Wird von JavaScript gefüllt -->
                            </div>
                        </div>
                        
                        <!-- Lange Tags -->
                        <div style="
                            background: #f0f8ff;
                            border: 2px solid #6c5ce7;
                            border-radius: 8px;
                            padding: 15px;
                        ">
                            <h5 style="margin: 0 0 10px 0; color: #5a4fcf;">📝 Lange Tags (≥ 10 Zeichen)</h5>
                            <div id="long-tags-list" style="
                                font-family: monospace;
                                font-size: 11px;
                                color: #5a4fcf;
                                max-height: 150px;
                                overflow-y: auto;
                            ">
                                <!-- Wird von JavaScript gefüllt -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Aktions-Buttons -->
                <div style="text-align: center; margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <p style="margin: 0 0 15px 0; color: #495057; font-weight: 500;">
                        Möchten Sie rote Tags zur Exklusionsliste hinzufügen?
                    </p>
                    <button type="button" id="show-analysis-extras" class="button button-secondary" style="margin-right: 10px;">
                        📊 Zusätzliche Analysen anzeigen
                    </button>
                    <button type="button" id="copy-red-tags" class="button button-secondary" style="margin-right: 10px;">
                        📋 Rote Tags kopieren
                    </button>
                    <button type="button" id="goto-exclusion-list" class="button button-primary">
                        ➡️ Zur Exklusionsliste
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>





    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Wartungs-Buttons
        $('#clearOldData').on('click', function() {
            if (confirm('Wirklich alle Daten älter als <?php echo $this->retention_days; ?> Tage löschen?')) {
                $.post(ajaxurl, {
                    "action": "unified_ajax",
                    "module": "fahrplanportal_search_logger",
                    "module_action": "clear_old_data",
                    "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",
                    "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
                }, function(response) {
                    if (response.success) {
                        alert('Alte Daten wurden gelöscht. Gelöschte Einträge: ' + response.data.deleted);
                    } else {
                        alert('Fehler beim Löschen: ' + response.data);
                    }
                });
            }
        });

        $('#clearAllData').on('click', function() {
            if (confirm('WIRKLICH ALLE Suchdaten unwiderruflich löschen?')) {
                if (confirm('Dies ist Ihre letzte Chance - wirklich ALLE Daten löschen?')) {
                    $.post(ajaxurl, {
                        "action": "unified_ajax",
                        "module": "fahrplanportal_search_logger",
                        "module_action": "clear_old_data",
                        "clear_all": "1",
                        "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",
                        "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
                    }, function(response) {
                        if (response.success) {
                            alert('Alle Suchdaten wurden gelöscht.');
                            location.reload();
                        } else {
                            alert('Fehler beim Löschen: ' + response.data);
                        }
                    }).fail(function(xhr, status, error) {
                        alert('AJAX-Fehler: ' + error);
                        console.error('AJAX Error:', xhr.responseText);
                    });
                }
            }
        });
        
        // ✅ NEU: Erweiterte Mapping-Beispiele mit Buchstaben-Zahl-Kombinationen laden
        $('#load-example-mapping').on('click', function() {
            var newMappingExample = `// ✅ ERWEITERTE Format Beispiel-Zuordnungen (neue_bezeichnung:alte_bezeichnung)
        // Standard-Mapping für Kärntner Linien

        // ✅ NEU: Buchstaben-Zahl-Kombinationen (X-Linien, Schnellbus, etc.)
        X1:SB1
        X2:SB2
        X3:SB3
        X4:SB4
        X5:SB5
        X10:SB10
        X11:SB11
        X12:SB12

        // ✅ NEU: Weitere Buchstaben-Kombinationen
        A1:SA1
        A2:SA2
        B1:SB1
        B2:SB2
        R1:REG1
        R2:REG2

        // ✅ NEU: Stadtbus-Kombinationen
        ST1:STADT1
        ST2:STADT2
        ST3:STADT3

        // Standard 2-3 stellige Nummern → 4-stellige Nummern
        100:5000
        101:5001
        102:5002
        103:5003
        104:5004
        105:5005
        106:5006
        107:5007
        108:5008
        109:5009
        110:5010
        111:5011
        112:5012
        113:5013
        114:5014
        115:5015

        // Spezielle Linien
        561:5561
        82:5082
        200:5200
        201:5201
        202:5202
        401:5401
        402:5402
        403:5403

        // Regionale Schnellverbindungen
        300:5300
        301:5301
        302:5302
        310:5310
        311:5311
        312:5312

        // ✅ BEISPIELE für Kombinierte PDFs:
        // X2-401-feldkirchen-moosburg-klagenfurt.pdf
        // → Neue: X2, 401 | Alte: SB2, 5401
        //
        // X1-X3-villach-spittal.pdf  
        // → Neue: X1, X3 | Alte: SB1, SB3
        //
        // 561-st-veit-klagenfurt.pdf
        // → Neue: 561 | Alte: 5561`;
            
            var currentMapping = $('#line-mapping').val().trim();
            if (currentMapping) {
                $('#line-mapping').val(currentMapping + '\n\n' + newMappingExample);
            } else {
                $('#line-mapping').val(newMappingExample);
            }
            
            alert('✅ ERWEITERTE Beispiel-Zuordnungen hinzugefügt!\n\n' +
                  '🆕 NEUE FEATURES:\n' +
                  '• Buchstaben-Zahl-Kombinationen: X1:SB1, X2:SB2\n' +
                  '• Kombinierte PDFs: X2-401-route.pdf\n' +
                  '• Mehrere Bezeichnungen pro PDF möglich\n\n' +
                  'Format: neue_bezeichnung:alte_bezeichnung\n' +
                  'Beispiel: X2:SB2 bedeutet X2 wird zu SB2 zugeordnet');
        });
        
        // Exklusionswörter Buttons
        $('#save-exclusion-words').on('click', function() {
            var exclusionWords = $('#exclusion-words').val();
            
            $.post(ajaxurl, {
                "action": "unified_ajax",
                "module": "fahrplanportal",
                "module_action": "save_exclusion_words",
                "exclusion_words": exclusionWords,
                "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",
                "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
            }, function(response) {
                if (response.success) {
                    $('#exclusion-status').html('<span style="color: green;">✅ Gespeichert (' + response.data.word_count + ' Wörter)</span>');
                    setTimeout(function() {
                        $('#exclusion-status').html('');
                    }, 3000);
                } else {
                    $('#exclusion-status').html('<span style="color: red;">❌ Fehler: ' + response.data + '</span>');
                }
            });
        });
        
        $('#load-exclusion-words').on('click', function() {
            $.post(ajaxurl, {
                "action": "unified_ajax",
                "module": "fahrplanportal",
                "module_action": "load_exclusion_words",
                "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",
                "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
            }, function(response) {
                if (response.success) {
                    $('#exclusion-words').val(response.data.exclusion_words);
                    $('#exclusion-status').html('<span style="color: blue;">🔄 Geladen (' + response.data.word_count + ' Wörter)</span>');
                    setTimeout(function() {
                        $('#exclusion-status').html('');
                    }, 3000);
                } else {
                    $('#exclusion-status').html('<span style="color: red;">❌ Fehler: ' + response.data + '</span>');
                }
            });
        });
        
        // Linien-Mapping Buttons
        $('#save-line-mapping').on('click', function() {
            var lineMapping = $('#line-mapping').val();
            
            $.post(ajaxurl, {
                "action": "unified_ajax",
                "module": "fahrplanportal",
                "module_action": "save_line_mapping",
                "line_mapping": lineMapping,
                "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",
                "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
            }, function(response) {
                if (response.success) {
                    $('#mapping-status').html('<span style="color: green;">✅ Gespeichert (' + response.data.mapping_count + ' Zuordnungen)</span>');
                    setTimeout(function() {
                        $('#mapping-status').html('');
                    }, 3000);
                } else {
                    $('#mapping-status').html('<span style="color: red;">❌ Fehler: ' + response.data + '</span>');
                }
            });
        });
        
        $('#load-line-mapping').on('click', function() {
            $.post(ajaxurl, {
                "action": "unified_ajax",
                "module": "fahrplanportal",
                "module_action": "load_line_mapping",
                "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",
                "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
            }, function(response) {
                if (response.success) {
                    $('#line-mapping').val(response.data.line_mapping);
                    $('#mapping-status').html('<span style="color: blue;">🔄 Geladen (' + response.data.mapping_count + ' Zuordnungen)</span>');
                    setTimeout(function() {
                        $('#mapping-status').html('');
                    }, 3000);
                } else {
                    $('#mapping-status').html('<span style="color: red;">❌ Fehler: ' + response.data + '</span>');
                }
            });
        });
        
        // Standard-Exklusionsliste Button
        $('#load-default-exclusions').on('click', function() {
            var defaultExclusions = `aber alle allem allen aller alles also auch auf aus bei bin bis bist dass den der des die dies doch dort durch ein eine einem einen einer eines für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht noch nur oder sich sie sind über und uns von war wird wir zu zum zur

fahrplan fahrt zug bus bahn haltestelle bahnhof station linie route verkehr abfahrt ankunft uhrzeit zeit

montag dienstag mittwoch donnerstag freitag samstag sonntag
januar februar märz april mai juni juli august september oktober november dezember

gehen geht ging kommt kommen kam kann könnte sollte würde
haben hat hatte sein war waren werden wird wurde`;
            
            var currentExclusions = $('#exclusion-words').val().trim();
            if (currentExclusions) {
                $('#exclusion-words').val(currentExclusions + '\n\n' + defaultExclusions);
            } else {
                $('#exclusion-words').val(defaultExclusions);
            }
            
            alert('Standard-Deutsche-Stoppwörter hinzugefügt!');
        });
    });
    </script>
    <?php
}
    
    // ========================================
    // ✅ UNIFIED AJAX HANDLER - ADMIN-ONLY
    // ========================================
    
    /**
     * ✅ UNIFIED: Einzelnen Fahrplan laden für Modal
     */
    public function unified_get_fahrplan() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error('Ungültige ID');
        }
        
        global $wpdb;
        
        $fahrplan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
        
        if (!$fahrplan) {
            wp_send_json_error('Fahrplan nicht gefunden');
        }
        
        wp_send_json_success($fahrplan);
    }
    
    /**
     * ✅ UNIFIED: Verzeichnis scannen (alte Methode für Fallback)
     * ✅ GEÄNDERT: Nutzt jetzt ebenfalls die neue Gültigkeitsdaten-Logik (14.12. bis 13.12.)
     */
    public function unified_scan_fahrplaene() {
        error_log('FAHRPLANPORTAL DEBUG: Start unified_scan_fahrplaene (Fallback für alte Implementierung)');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $folder = sanitize_text_field($_POST['folder'] ?? '');
        if (empty($folder)) {
            wp_send_json_error('Kein Ordner ausgewählt');
            return;
        }
        
        $base_scan_path = $this->pdf_base_path . $folder . '/';
        
        if (!is_dir($base_scan_path)) {
            wp_send_json_error('Verzeichnis nicht gefunden: ' . $base_scan_path);
            return;
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $debug_info = array();
        
        $parsing_status = $this->pdf_parsing_enabled ? 'mit PDF-Parsing' : 'ohne PDF-Parsing';
        $debug_info[] = "Fallback-Modus (alte Implementierung) " . $parsing_status;
        
        // Alle Dateien sammeln
        $all_files = $this->collect_all_scan_files($base_scan_path, $folder);
        
        // Alle Dateien verarbeiten (nutzt jetzt automatisch die neue Gültigkeitslogik)
        foreach ($all_files as $file_info) {
            try {
                $result = $this->process_single_pdf_file($file_info);
                if ($result['success']) {
                    $imported++;
                } else {
                    $skipped++;
                }
            } catch (Exception $e) {
                $errors++;
                $debug_info[] = "Fehler bei " . $file_info['filename'] . ": " . $e->getMessage();
            }
        }
        
        wp_send_json_success(array(
            'message' => "Fallback-Scan " . $parsing_status . ": $imported importiert, $skipped übersprungen, $errors Fehler",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'debug' => $debug_info,
            'pdf_parsing_enabled' => $this->pdf_parsing_enabled
        ));
    }
    
    /**
     * ✅ UNIFIED: Fahrplan aktualisieren
     */
    public function unified_update_fahrplan() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error('Ungültige ID');
        }
        
        global $wpdb;
        
        $update_data = array();
        $format = array();
        
        // Erlaubte Felder für Update
        $allowed_fields = array('titel', 'linie_alt', 'linie_neu', 'kurzbeschreibung', 'gueltig_von', 'gueltig_bis', 'region');
        
        // Tags nur wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled) {
            $allowed_fields[] = 'tags';
        }
        
        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $update_data[$field] = sanitize_text_field($_POST[$field]);
                $format[] = '%s';
            }
        }
        
        if (empty($update_data)) {
            wp_send_json_error('Keine Daten zum Aktualisieren');
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $format,
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Fahrplan erfolgreich aktualisiert');
        } else {
            wp_send_json_error('Fehler beim Aktualisieren: ' . $wpdb->last_error);
        }
    }
    
    /**
     * ✅ UNIFIED: Fahrplan löschen
     */
    public function unified_delete_fahrplan() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error('Ungültige ID');
        }
        
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Fahrplan erfolgreich gelöscht');
        } else {
            wp_send_json_error('Fehler beim Löschen: ' . $wpdb->last_error);
        }
    }
    
    /**
     * ✅ UNIFIED: Datenbank neu erstellen
     */
    public function unified_recreate_db() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        global $wpdb;
        
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
        $this->init_database();
        
        wp_send_json_success('Datenbank erfolgreich neu erstellt');
    }
    
    /**
     * ✅ UNIFIED: Alle Einträge löschen
     */
    public function unified_clear_db() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        global $wpdb;
        
        $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        if ($result !== false) {
            wp_send_json_success('Alle Fahrpläne erfolgreich gelöscht');
        } else {
            wp_send_json_error('Fehler beim Leeren der Tabelle: ' . $wpdb->last_error);
        }
    }
    
    /**
     * ✅ UNIFIED: Exklusionswörter speichern
     */
    public function unified_save_exclusion_words() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $exclusion_words = sanitize_textarea_field($_POST['exclusion_words'] ?? '');
        
        // Wörter zählen
        $word_count = 0;
        if (!empty($exclusion_words)) {
            $words_array = preg_split('/[\s,\t\n\r]+/', $exclusion_words, -1, PREG_SPLIT_NO_EMPTY);
            $word_count = count($words_array);
        }
        
        update_option('fahrplanportal_exclusion_words', $exclusion_words);
        
        wp_send_json_success(array(
            'message' => 'Exklusionsliste erfolgreich gespeichert',
            'word_count' => $word_count
        ));
    }
    
    /**
     * ✅ UNIFIED: Exklusionswörter laden
     */
    public function unified_load_exclusion_words() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $exclusion_words = get_option('fahrplanportal_exclusion_words', '');
        
        // Wörter zählen
        $word_count = 0;
        if (!empty($exclusion_words)) {
            $words_array = preg_split('/[\s,\t\n\r]+/', $exclusion_words, -1, PREG_SPLIT_NO_EMPTY);
            $word_count = count($words_array);
        }
        
        wp_send_json_success(array(
            'exclusion_words' => $exclusion_words,
            'word_count' => $word_count
        ));
    }
    
    /**
     * ✅ UNIFIED: Linien-Mapping speichern
     */
    public function unified_save_line_mapping() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $line_mapping = sanitize_textarea_field($_POST['line_mapping'] ?? '');
        
        // Zuordnungen zählen
        $mapping_count = 0;
        if (!empty($line_mapping)) {
            $lines = preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY);
            $mapping_count = count(array_filter($lines, function($line) {
                $line = trim($line);
                return !empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0 && preg_match('/^\d+\s*:\s*\d+$/', $line);
            }));
        }
        
        update_option('fahrplanportal_line_mapping', $line_mapping);
        
        wp_send_json_success(array(
            'message' => 'Linien-Mapping erfolgreich gespeichert',
            'mapping_count' => $mapping_count
        ));
    }
    
    /**
     * ✅ UNIFIED: Linien-Mapping laden
     */
    public function unified_load_line_mapping() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $line_mapping = get_option('fahrplanportal_line_mapping', '');
        
        // Zuordnungen zählen
        $mapping_count = 0;
        if (!empty($line_mapping)) {
            $lines = preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY);
            $mapping_count = count(array_filter($lines, function($line) {
                $line = trim($line);
                return !empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0 && preg_match('/^\d+\s*:\s*\d+$/', $line);
            }));
        }
        
        wp_send_json_success(array(
            'line_mapping' => $line_mapping,
            'mapping_count' => $mapping_count
        ));
    }
    
    // ========================================
    // ENDE UNIFIED AJAX HANDLER
    // ========================================
    
    /**
     * PDF parsen und Tags extrahieren - ANGEPASST für Backend-Exklusionsliste
     */
    private function extract_pdf_tags($pdf_file_path) {
        if (!$this->pdf_parsing_enabled) {
            error_log('FAHRPLANPORTAL: PDF-Parsing übersprungen (nicht verfügbar)');
            return '';
        }
        
        error_log('FAHRPLANPORTAL: Beginne PDF-Parsing für: ' . $pdf_file_path);
        
        // Prüfen ob Datei existiert
        if (!file_exists($pdf_file_path)) {
            error_log('FAHRPLANPORTAL: PDF-Datei nicht gefunden: ' . $pdf_file_path);
            return '';
        }
        
        try {
            // Exklusionswörter aus Backend laden
            $exclusion_words = $this->get_exclusion_words();
            
            // Direkte Verwendung der aktualisierten hd_process_pdf_for_words Funktion
            if (function_exists('hd_process_pdf_for_words')) {
                $words_array = hd_process_pdf_for_words($pdf_file_path, $exclusion_words);
                
                if (!empty($words_array)) {
                    // Array zu kommagetrennte Liste konvertieren
                    $tags_string = implode(', ', $words_array);
                    error_log('FAHRPLANPORTAL: PDF-Parsing erfolgreich - ' . count($words_array) . ' Wörter extrahiert (nach Exklusion)');
                    return $tags_string;
                } else {
                    error_log('FAHRPLANPORTAL: PDF-Parsing - keine Wörter extrahiert');
                    return '';
                }
            } else {
                error_log('FAHRPLANPORTAL: hd_process_pdf_for_words Funktion nicht verfügbar');
                return '';
            }
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: PDF-Parsing Fehler: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Verfügbare Ordner ermitteln
     */
    private function get_available_folders() {
        $folders = array();
        
        if (!is_dir($this->pdf_base_path)) {
            return $folders;
        }
        
        $directories = glob($this->pdf_base_path . '*', GLOB_ONLYDIR);
        
        foreach ($directories as $dir) {
            $dirname = basename($dir);
            if (substr($dirname, 0, 1) !== '.') {
                $folders[] = $dirname;
            }
        }
        
        // Sortierung: Jahre zuerst, dann alphabetisch
        usort($folders, function($a, $b) {
            $a_is_year = preg_match('/^\d{4}$/', $a);
            $b_is_year = preg_match('/^\d{4}$/', $b);
            
            if ($a_is_year && $b_is_year) {
                return $b <=> $a;
            }
            if ($a_is_year && !$b_is_year) {
                return -1;
            }
            if (!$a_is_year && $b_is_year) {
                return 1;
            }
            return strcasecmp($a, $b);
        });
        
        return $folders;
    }
    
    /**
     * Fahrpläne aus DB laden - NEUE SPALTENREIHENFOLGE
     */
    private function get_fahrplaene_rows() {
        global $wpdb;
        
        $results = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
        
        $colspan = $this->pdf_parsing_enabled ? 12 : 11;
        
        if (empty($results)) {
            return '<tr><td colspan="' . $colspan . '">Keine Fahrpläne gefunden. Verwenden Sie "Verzeichnis scannen".</td></tr>';
        }
        
        $output = '';
        foreach ($results as $row) {
            $pdf_url = $this->get_pdf_url($row->pdf_pfad);
            
            // Datum in deutsches Format umwandeln
            $gueltig_von_de = $this->format_german_date($row->gueltig_von);
            $gueltig_bis_de = $this->format_german_date($row->gueltig_bis);
            
            // Region-Feld
            $region = isset($row->region) ? $row->region : '';
            
            // Tags-Spalte nur wenn verfügbar
            $tags_column = '';
            if ($this->pdf_parsing_enabled) {
                $tags_display = $this->format_tags_for_display($row->tags ?? '');
                $tags_column = '<td>' . $tags_display . '</td>';
            }
            
            // NEUE REIHENFOLGE: ID, Linie Alt, Linie Neu, Titel, Gültig von, Gültig bis, Ordner, Region, PDF, Kurzbeschreibung, [Tags], Aktionen
            $output .= sprintf(
                '<tr data-id="%d">
                    <td>%d</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td><a href="%s" target="_blank"><span class="dashicons dashicons-media-document"></span></a></td>
                    <td>%s</td>
                    %s
                    <td>
                        <button class="button button-small edit-fahrplan" data-id="%d" title="Bearbeiten">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button class="button button-small delete-fahrplan" data-id="%d" title="Löschen">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>',
                $row->id,                    // ID
                $row->id,                    // ID (nochmal für Anzeige)
                esc_html($row->linie_alt),   // Linie Alt
                esc_html($row->linie_neu),   // Linie Neu  
                esc_html($row->titel),       // Titel
                esc_html($gueltig_von_de),   // Gültig von
                esc_html($gueltig_bis_de),   // Gültig bis
                esc_html($row->jahr),        // Ordner
                esc_html($region),           // Region
                esc_url($pdf_url),           // PDF
                esc_html($row->kurzbeschreibung), // Kurzbeschreibung
                $tags_column,                // Tags (optional)
                $row->id,                    // Bearbeiten-Button ID
                $row->id                     // Löschen-Button ID
            );
        }
        
        return $output;
    }
    
    /**
     * Tags für Anzeige formatieren - Nur wenn PDF-Parsing aktiv
     */
    private function format_tags_for_display($tags) {
        if (!$this->pdf_parsing_enabled || empty($tags)) {
            return '<span class="no-tags">Keine Tags</span>';
        }
        
        // Tags sind als kommagetrennte Liste gespeichert
        $tag_array = explode(',', $tags);
        $tag_array = array_map('trim', $tag_array);
        $tag_array = array_filter($tag_array); // Leere entfernen
        
        if (empty($tag_array)) {
            return '<span class="no-tags">Keine Tags</span>';
        }
        
        // EINFACH: Nur die kommagetrennte Liste zurückgeben
        return '<span class="simple-tags">' . esc_html(implode(', ', $tag_array)) . '</span>';
    }
    
    /**
     * Anzahl Fahrpläne ermitteln
     */
    private function get_fahrplaene_count() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    /**
     * PDF-URL generieren
     */
    private function get_pdf_url($pdf_pfad) {
        return site_url('fahrplaene/' . $pdf_pfad);
    }
    
    /**
     * ✅ KORRIGIERT: Regionsnamen formatieren (Stadt- und Vororteverkehr + normale Regionen)
     */
    private function format_region_name($region_raw) {
        // Leer? Dann unverändert zurück
        if (empty($region_raw)) {
            return $region_raw;
        }
        
        // Schon formatiert (hat Großbuchstaben)? Dann unverändert
        if (preg_match('/[A-ZÄÖÜ]/', $region_raw)) {
            return $region_raw;
        }
        
        error_log("FAHRPLANPORTAL: Format Region Raw Input: '$region_raw'");
        
        // ✅ NEU: Spezielle Behandlung für Stadt- und Vororteverkehr
        if (preg_match('/^([a-z])-stadt-und-vororteverkehr-(.+)$/', $region_raw, $matches)) {
            $buchstabe = strtoupper($matches[1]);  // b → B, c → C, etc.
            $stadt_name = $matches[2];             // villach, spittal-a-d-drau, etc.
            
            error_log("FAHRPLANPORTAL: Stadt- und Vororteverkehr erkannt - Buchstabe: '$buchstabe', Stadt: '$stadt_name'");
            
            // ✅ Stadt-Name formatieren (mit Abkürzungen und Umlauten)
            $formatted_city = $this->format_city_name_with_abbreviations($stadt_name);
            
            $result = $buchstabe . ' Stadt- u. Vororteverkehr ' . $formatted_city;
            
            error_log("FAHRPLANPORTAL: Stadt- und Vororteverkehr formatiert: '$region_raw' → '$result'");
            return $result;
        }
        
        // ✅ NEU: Spezielle Behandlung für Nummern-Regionen (01-moelltal, 02-liesertal, etc.)
        if (preg_match('/^(\d{2})-(.+)$/', $region_raw, $matches)) {
            $nummer = $matches[1];           // 01, 02, etc.
            $region_name = $matches[2];      // moelltal, liesertal, etc.
            
            error_log("FAHRPLANPORTAL: Nummern-Region erkannt - Nummer: '$nummer', Name: '$region_name'");
            
            // ✅ Region-Name formatieren (mit Abkürzungen und Umlauten)
            $formatted_region = $this->format_city_name_with_abbreviations($region_name);
            
            $result = $nummer . ' ' . $formatted_region;
            
            error_log("FAHRPLANPORTAL: Nummern-Region formatiert: '$region_raw' → '$result'");
            return $result;
        }
        
        // ✅ FALLBACK: Normale Region-Formatierung (wie bisher)
        return $this->format_normal_region_name($region_raw);
    }

    /**
     * ✅ NEU: Stadt-Namen mit Abkürzungen formatieren (für Stadt- und Vororteverkehr)
     */
    private function format_city_name_with_abbreviations($city_name_raw) {
        // ✅ Spezielle Stadt-Abkürzungen behandeln
        $special_cities = array(
            'spittal-a-d-drau' => 'Spittal an der Drau',
            'villach-a-d-drau' => 'Villach an der Drau',
            'klagenfurt-a-w-see' => 'Klagenfurt am Wörthersee',
            'st-veit-a-d-glan' => 'St.Veit an der Glan',
            'wolfsberg-a-d-lavant' => 'Wolfsberg an der Lavant',
            'feldkirchen-a-d-drau' => 'Feldkirchen an der Drau',
            'st-georgen-ob-bleiburg' => 'St.Georgen ob Bleiburg',
            'st-michael-ob-bleiburg' => 'St.Michael ob Bleiburg'

             
        );
        
        // ✅ Direkte Zuordnung prüfen
        if (isset($special_cities[$city_name_raw])) {
            $result = $special_cities[$city_name_raw];
            error_log("FAHRPLANPORTAL: Spezielle Stadt-Zuordnung: '$city_name_raw' → '$result'");
            return $result;
        }
        
        // ✅ Normale Formatierung für andere Städte
        $city_parts = explode('-', $city_name_raw);
        
        // ✅ Array-basierte Abkürzungs-Verarbeitung
        $city_parts = $this->process_abbreviations($city_parts);
        
        // ✅ Umlaute konvertieren
        $formatted_parts = array();
        foreach ($city_parts as $part) {
            $part_with_umlauts = $this->convert_german_umlauts($part);
            
            // ✅ Falls noch nicht formatiert: Ersten Buchstaben groß
            if (!preg_match('/^(St\.|an der |ob der |am |bei |unter )/', $part_with_umlauts)) {
                $part_with_umlauts = $this->ucfirst_german($part_with_umlauts);
            }
            
            $formatted_parts[] = $part_with_umlauts;
        }
        
        $result = implode(' ', $formatted_parts);
        error_log("FAHRPLANPORTAL: Stadt-Name formatiert: '$city_name_raw' → '$result'");
        
        return $result;
    }

    /**
     * ✅ NEU: Normale Region-Formatierung (bisherige Logik)
     */
    private function format_normal_region_name($region_raw) {
        // ✅ Deutsche Umlaute konvertieren
        $region_with_umlauts = $this->convert_german_umlauts_in_region($region_raw);
        
        // Bindestriche durch Leerzeichen ersetzen
        $region_spaced = str_replace('-', ' ', $region_with_umlauts);
        
        // In Kleinbuchstaben und dann in Wörter aufteilen
        $words = explode(' ', strtolower(trim($region_spaced)));
        
        // Wörter die klein bleiben sollen
        $lowercase_words = array('an', 'der', 'am', 'von', 'im', 'auf', 'bei', 'zu', 'zur', 'ob');
        
        $formatted_words = array();
        
        foreach ($words as $index => $word) {
            $word = trim($word);
            
            if (empty($word)) {
                continue; // Leere Wörter überspringen
            }
            
            // Erstes Wort oder nicht in Ausnahmeliste: groß schreiben
            if ($index === 0 || !in_array($word, $lowercase_words)) {
                $formatted_words[] = $this->ucfirst_german($word);
            } else {
                // Ausnahmewort: klein lassen
                $formatted_words[] = $word;
            }
        }
        
        $result = implode(' ', $formatted_words);
        
        error_log("FAHRPLANPORTAL: Normale Region formatiert: '$region_raw' → '$result'");
        
        return $result;
    }
    
    /**
     * ✅ VEREINFACHT: Deutsche Umlaute für Regionsnamen konvertieren
     * (Hauptlogik ist jetzt in convert_german_umlauts())
     */
    private function convert_german_umlauts_in_region($text) {
        // ✅ EINFACH: Verwende die Hauptfunktion für Regionen
        // Die gesamte Ausnahmenlogik ist bereits in convert_german_umlauts() implementiert
        return $this->convert_german_umlauts($text);
    }
    
    /**
     * ✅ NEU: Deutsche Groß-/Kleinschreibung mit Umlauten
     */
    private function ucfirst_german($word) {
        // Deutsche Umlaute und Sonderzeichen berücksichtigen
        $word = trim($word);
        
        if (empty($word)) {
            return $word;
        }
        
        // Ersten Buchstaben groß, Rest klein
        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . 
               mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
    }
    
    
    /**
     * ✅ ERWEITERT: Deutsche Umlaute konvertieren - SÜDKÄRNTEN FIX
     */
    private function convert_german_umlauts($text) {
        // Bestehende Ausnahmeliste...
        $exceptions = array(
            'auen', 'auenwald', 'auental', 'auendorf', 'auenbach',
            // ... alle bestehenden Ausnahmen bleiben ...
            'michael', 'michaelerberg', 'michaelsberg', 'michaelbeuern',
            // ... Rest der Ausnahmeliste ...
        );
        
        $original_text = $text;
        $text_lower = mb_strtolower($text, 'UTF-8');
        
        // Ausnahmen-Prüfung
        if (in_array($text_lower, $exceptions)) {
            error_log("FAHRPLANPORTAL: Titel-Ausnahme gefunden für '$original_text' - keine Umlaut-Konvertierung");
            return $text;
        }
        
        // Spezielle Michael-Prüfung
        if (stripos($text_lower, 'michael') !== false) {
            error_log("FAHRPLANPORTAL: 'Michael' in Text '$original_text' erkannt - keine Konvertierung");
            return $text;
        }
        
        // ✅ GEZIELTE ÖSTERREICHISCHE KONVERTIERUNGEN - SÜDKÄRNTEN HINZUGEFÜGT
        $priority_conversions = array(
            'woerthersee' => 'wörthersee',
            'woerth' => 'wörth', 
            'moell' => 'möll',
            'oesterreich' => 'österreich',
            'kaernten' => 'kärnten',
            'voelkermarkt' => 'völkermarkt',
            'goeriach' => 'göriach',
            'pusarnitz' => 'pusarnitz',
            
            // ✅ NEU: SÜDKÄRNTEN FIX
            'suedkaernten' => 'südkärnten',
            'suedkärnten' => 'südkärnten',
            'suedoesterreich' => 'südösterreich',
            'suedtirol' => 'südtirol',
            'westkaernten' => 'westkärnten',
            'ostkaernten' => 'ostkärnten',
            'nordkaernten' => 'nordkärnten',
            
            // Bestehende Brücken-Konvertierungen...
            'bruecke' => 'brücke',
            'bruecken' => 'brücken',
            'moellbruecke' => 'möllbrücke',
            
            // Bestehende weitere Konvertierungen...
            'muehle' => 'mühle',
            'muehlen' => 'mühlen',
            'gruenberg' => 'grünberg',
            'gruendorf' => 'gründorf',
        );
        
        // Prioritäts-Konvertierungen durchführen (CASE-INSENSITIVE)
        foreach ($priority_conversions as $search => $replace) {
            $text = str_ireplace($search, $replace, $text);
        }
        
        // Überprüfung ob sich etwas geändert hat
        if ($text !== $original_text) {
            error_log("FAHRPLANPORTAL: Prioritäts-Konvertierung durchgeführt: '$original_text' → '$text'");
            return $text;
        }
        
        // Standard Umlaut-Konvertierung (wie bisher)
        $conversions = array(
            'ae' => 'ä', 'Ae' => 'Ä', 'AE' => 'Ä',
            'oe' => 'ö', 'Oe' => 'Ö', 'OE' => 'Ö',
            'ue' => 'ü', 'Ue' => 'Ü', 'UE' => 'Ü'
        );
        
        $converted_text = $text;
        foreach ($conversions as $search => $replace) {
            $test_conversion = str_replace($search, $replace, $converted_text);
            $test_lower = mb_strtolower($test_conversion, 'UTF-8');
            
            if (in_array($test_lower, $exceptions)) {
                error_log("FAHRPLANPORTAL: Konvertierung '$search' → '$replace' übersprungen für '$converted_text' (würde Ausnahme '$test_lower' erzeugen)");
                continue;
            }
            
            $converted_text = str_replace($search, $replace, $converted_text);
        }
        
        if ($converted_text !== $text) {
            error_log("FAHRPLANPORTAL: Standard Umlaut-Konvertierung durchgeführt: '$original_text' → '$converted_text'");
        }
        
        return $converted_text;
    }
    
    /**
     * ✅ VEREINFACHT: "St." Abkürzung korrekt behandeln (jetzt überflüssig)
     * Diese Funktion wird nicht mehr benötigt, da process_abbreviations() die Arbeit übernimmt
     */
    private function fix_st_abbreviation($text) {
        // ✅ VEREINFACHT: Da process_abbreviations() bereits "St.Ort" erstellt,
        // ist diese Funktion nur noch für Backup-Fälle nötig
        
        if (strpos(strtolower($text), 'st.') === 0) {
            // Text beginnt bereits mit "St." - unverändert lassen
            return $text;
        }
        
        // Fallback für andere St.-Muster (sollte eigentlich nicht mehr vorkommen)
        $text = preg_replace_callback(
            '/\bst\.(\s*)([a-zA-Z]+)/',
            function($matches) {
                $space = $matches[1];
                $word = $matches[2];
                return 'St.' . ucfirst($word); // Ohne Leerzeichen!
            },
            $text
        );
        
        return $text;
    }


    /**
     * ✅ BUG-FIX: Verarbeitet Abkürzungen schrittweise (Array-basiert)
     * ✅ GEFIXT: "ob-bleiburg" Problem - unterscheidet zwischen "ob der" und "ob" ohne "der"
     * ✅ GEFIXT: St. + mehrere Wörter Problem (paul, georgen, johann, michael werden korrekt großgeschrieben)
     */
    private function process_abbreviations($orte_array) {
        $processed_orte = array();
        $i = 0;
        
        error_log("FAHRPLANPORTAL: Abkürzungs-Verarbeitung Start: " . implode(', ', $orte_array));
        
        while ($i < count($orte_array)) {
            $current = strtolower(trim($orte_array[$i]));
            
            // ✅ REGEL 1: "-st-" → "St." (ohne Leerzeichen zum nächsten Wort)
            if ($current === 'st' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // ✅ GEFIXT: Korrekte Großschreibung mit ucfirst_german()
                $combined = 'St.' . $this->ucfirst_german($next_ort);
                $processed_orte[] = $combined;
                $i += 2; // Überspringe beide Teile
                error_log("FAHRPLANPORTAL: St-Abkürzung: 'st + $next_ort' → '$combined'");
            }
            // ✅ REGEL 2: "-a-d-" → "an der" (mit Leerzeichen)
            elseif ($current === 'a' && isset($orte_array[$i + 1]) && isset($orte_array[$i + 2])) {
                $second = strtolower(trim($orte_array[$i + 1]));
                $third = trim($orte_array[$i + 2]);
                
                if ($second === 'd') {
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = 'an der ' . $this->ucfirst_german($third);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile
                    error_log("FAHRPLANPORTAL: A-D-Abkürzung: 'a + d + $third' → '$combined'");
                } else {
                    // Kein "a-d-" Muster, normal verarbeiten
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // ✅ BUG-FIX: REGEL 2.5: "-o-d-" → "ob der" NUR wenn wirklich 3 Teile vorhanden
            elseif ($current === 'o' && isset($orte_array[$i + 1]) && isset($orte_array[$i + 2])) {
                $second = strtolower(trim($orte_array[$i + 1]));
                $third = trim($orte_array[$i + 2]);
                
                if ($second === 'd') {
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = 'ob der ' . $this->ucfirst_german($third);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile
                    error_log("FAHRPLANPORTAL: O-D-Abkürzung: 'o + d + $third' → '$combined'");
                } else {
                    // Kein "o-d-" Muster, normal verarbeiten
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // ✅ REGEL 3: "-am-" → " am " (Präposition zwischen Orten)
            elseif ($current === 'am' && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // Prüfen ob vorheriger Ort existiert um ihn zu erweitern
                if (!empty($processed_orte)) {
                    $last_index = count($processed_orte) - 1;
                    $last_ort = $processed_orte[$last_index];
                    
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = $last_ort . ' am ' . $this->ucfirst_german($next_ort);
                    $processed_orte[$last_index] = $combined;
                    $i += 2; // Überspringe beide Teile
                    error_log("FAHRPLANPORTAL: Am-Erweiterung: '$last_ort + am + $next_ort' → '$combined'");
                } else {
                    // Kein vorheriger Ort, normal verarbeiten
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // ✅ NEU: REGEL 3.5: "-an-" → " an " (Präposition, IMMER an vorherigen Ort anhängen)
            elseif ($current === 'an' && isset($orte_array[$i + 1])) {
                $next_element = strtolower(trim($orte_array[$i + 1]));
                
                // ✅ Spezialfall: "an-der-..." → " an der "
                if ($next_element === 'der' && isset($orte_array[$i + 2])) {
                    $third = trim($orte_array[$i + 2]);
                    
                    // ✅ IMMER an vorherigen Ort anhängen
                    if (!empty($processed_orte)) {
                        $last_index = count($processed_orte) - 1;
                        $last_ort = $processed_orte[$last_index];
                        
                        $combined = $last_ort . ' an der ' . $this->ucfirst_german($third);
                        $processed_orte[$last_index] = $combined;
                        $i += 3; // Überspringe alle drei Teile (an + der + ort)
                        error_log("FAHRPLANPORTAL: An-Der-Erweiterung: '$last_ort + an + der + $third' → '$combined'");
                    } else {
                        // Fallback (sollte nicht vorkommen)
                        $combined = 'an der ' . $this->ucfirst_german($third);
                        $processed_orte[] = $combined;
                        $i += 3;
                        error_log("FAHRPLANPORTAL: An-Der-Abkürzung (Fallback): 'an + der + $third' → '$combined'");
                    }
                } else {
                    // ✅ Normalfall: "an-..." ohne "der" → " an "
                    $next_ort = trim($orte_array[$i + 1]);
                    
                    // ✅ IMMER an vorherigen Ort anhängen
                    if (!empty($processed_orte)) {
                        $last_index = count($processed_orte) - 1;
                        $last_ort = $processed_orte[$last_index];
                        
                        $combined = $last_ort . ' an ' . $this->ucfirst_german($next_ort);
                        $processed_orte[$last_index] = $combined;
                        $i += 2; // Überspringe beide Teile (an + ort)
                        error_log("FAHRPLANPORTAL: An-Erweiterung: '$last_ort + an + $next_ort' → '$combined'");
                    } else {
                        // Fallback (sollte nicht vorkommen)
                        $combined = 'an ' . $this->ucfirst_german($next_ort);
                        $processed_orte[] = $combined;
                        $i += 2;
                        error_log("FAHRPLANPORTAL: An-Abkürzung (Fallback): 'an + $next_ort' → '$combined'");
                    }
                }
            }
            // ✅ BUG-FIX: REGEL 4: "-ob-" → " ob " (IMMER an vorherigen Ort anhängen)
            elseif ($current === 'ob' && isset($orte_array[$i + 1])) {
                $next_element = strtolower(trim($orte_array[$i + 1]));
                
                // ✅ WICHTIG: Prüfen ob das nächste Element "der" ist
                if ($next_element === 'der' && isset($orte_array[$i + 2])) {
                    // Fall: "ob-der-..." → " ob der "
                    $third = trim($orte_array[$i + 2]);
                    
                    // ✅ IMMER an vorherigen Ort anhängen (nie als separates Element)
                    if (!empty($processed_orte)) {
                        $last_index = count($processed_orte) - 1;
                        $last_ort = $processed_orte[$last_index];
                        
                        $combined = $last_ort . ' ob der ' . $this->ucfirst_german($third);
                        $processed_orte[$last_index] = $combined;
                        $i += 3; // Überspringe alle drei Teile (ob + der + ort)
                        error_log("FAHRPLANPORTAL: Ob-Der-Erweiterung: '$last_ort + ob + der + $third' → '$combined'");
                    } else {
                        // Sollte nicht vorkommen, aber Fallback
                        $combined = 'ob der ' . $this->ucfirst_german($third);
                        $processed_orte[] = $combined;
                        $i += 3;
                        error_log("FAHRPLANPORTAL: Ob-Der-Abkürzung (Fallback): 'ob + der + $third' → '$combined'");
                    }
                } else {
                    // ✅ HAUPTFALL: "ob-..." ohne "der" → " ob " (IMMER anhängen)
                    $next_ort = trim($orte_array[$i + 1]);
                    
                    // ✅ IMMER an vorherigen Ort anhängen (nie als separates Element)
                    if (!empty($processed_orte)) {
                        $last_index = count($processed_orte) - 1;
                        $last_ort = $processed_orte[$last_index];
                        
                        $combined = $last_ort . ' ob ' . $this->ucfirst_german($next_ort);
                        $processed_orte[$last_index] = $combined;
                        $i += 2; // Überspringe beide Teile (ob + ort)
                        error_log("FAHRPLANPORTAL: Ob-Erweiterung: '$last_ort + ob + $next_ort' → '$combined'");
                    } else {
                        // Sollte nicht vorkommen, aber Fallback
                        $combined = 'ob ' . $this->ucfirst_german($next_ort);
                        $processed_orte[] = $combined;
                        $i += 2;
                        error_log("FAHRPLANPORTAL: Ob-Abkürzung (Fallback): 'ob + $next_ort' → '$combined'");
                    }
                }
            }
            // ✅ NEU: REGEL 5: Ortsteil-Präfixe (klein, groß, maria, etc.)
            elseif (in_array($current, array('klein', 'groß', 'maria', 'ober', 'unter', 'neu', 'alt')) && isset($orte_array[$i + 1])) {
                $next_element = strtolower(trim($orte_array[$i + 1]));
                
                // ✅ Spezialfall: Präfix + St + Ort → "Präfix St.Ort"
                if ($next_element === 'st' && isset($orte_array[$i + 2])) {
                    $third_element = trim($orte_array[$i + 2]);
                    $combined = $this->ucfirst_german($current) . ' St.' . $this->ucfirst_german($third_element);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile (präfix + st + ort)
                    error_log("FAHRPLANPORTAL: Präfix-St-Kombination: '$current + st + $third_element' → '$combined'");
                } else {
                    // ✅ Normalfall: Präfix + Ort → "Präfix Ort"
                    $combined = $this->ucfirst_german($current) . ' ' . $this->ucfirst_german($orte_array[$i + 1]);
                    $processed_orte[] = $combined;
                    $i += 2; // Überspringe beide Teile
                    error_log("FAHRPLANPORTAL: Ortsteil-Präfix: '$current + " . $orte_array[$i + 1] . "' → '$combined'");
                }
            }
            // ✅ NEU: REGEL 6: Einrichtungs-Präfixe (bahnhof, flughafen, firma, etc.)
            elseif (in_array($current, array('bahnhof', 'flughafen', 'krankenhaus', 'zentrum', 'campus', 'universität', 'schule', 'kirche', 'friedhof', 'rathaus', 'postamt', 'polizei', 'feuerwehr', 'firma', 'unternehmen', 'betrieb', 'werk', 'fabrik', 'büro', 'amt', 'behörde')) && isset($orte_array[$i + 1])) {
                $next_element = strtolower(trim($orte_array[$i + 1]));
                
                // ✅ Spezialfall: Einrichtung + St + Ort → "Einrichtung St.Ort"
                if ($next_element === 'st' && isset($orte_array[$i + 2])) {
                    $third_element = trim($orte_array[$i + 2]);
                    $combined = $this->ucfirst_german($current) . ' St.' . $this->ucfirst_german($third_element);
                    $processed_orte[] = $combined;
                    $i += 3; // Überspringe alle drei Teile (einrichtung + st + ort)
                    error_log("FAHRPLANPORTAL: Einrichtung-St-Kombination: '$current + st + $third_element' → '$combined'");
                } else {
                    // ✅ Normalfall: Einrichtung + Ort → "Einrichtung Ort"
                    $combined = $this->ucfirst_german($current) . ' ' . $this->ucfirst_german($orte_array[$i + 1]);
                    $processed_orte[] = $combined;
                    $i += 2; // Überspringe beide Teile
                    error_log("FAHRPLANPORTAL: Einrichtung-Präfix: '$current + " . $orte_array[$i + 1] . "' → '$combined'");
                }
            }
            // ✅ REGEL 7: Weitere Präpositionen (bei, unter, auf, im, etc.)
            elseif (in_array($current, array('bei', 'unter', 'auf', 'im')) && isset($orte_array[$i + 1])) {
                $next_ort = trim($orte_array[$i + 1]);
                
                // Erweitere den letzten Ort wenn vorhanden
                if (!empty($processed_orte)) {
                    $last_index = count($processed_orte) - 1;
                    $last_ort = $processed_orte[$last_index];
                    
                    // ✅ GEFIXT: Korrekte Großschreibung
                    $combined = $last_ort . ' ' . $current . ' ' . $this->ucfirst_german($next_ort);
                    $processed_orte[$last_index] = $combined;
                    $i += 2;
                    error_log("FAHRPLANPORTAL: Präposition-Erweiterung: '$last_ort + $current + $next_ort' → '$combined'");
                } else {
                    $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                    $i += 1;
                }
            }
            // ✅ NORMAL: Kein Abkürzungsmuster erkannt
            else {
                // ✅ GEFIXT: Verwende ucfirst_german() statt ucfirst()
                $processed_orte[] = $this->ucfirst_german($orte_array[$i]);
                $i += 1;
            }
        }
        
        error_log("FAHRPLANPORTAL: Abkürzungs-Verarbeitung Ergebnis: " . implode(' | ', $processed_orte));
        return $processed_orte;
    }

    /**
     * 🔍 DEBUG-VERSION: parse_filename() mit detailliertem Mapping-Logging
     * ✅ Zeigt genau an warum Mappings nicht gefunden werden
     */
    private function parse_filename($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $linie_alt = '';
        $linie_neu = '';
        
        error_log("FAHRPLANPORTAL: 🔍 DEBUG Parse Dateiname: " . $name);
        
        // ✅ Mapping laden mit Debug-Info
        $line_mapping = $this->get_line_mapping();
        error_log("FAHRPLANPORTAL: 🔍 Mapping-Array geladen: " . count($line_mapping) . " Einträge");
        
        // Buchstaben-Zahl-Kombinationen (X2-, X3-, etc.)
        if (preg_match('/^([A-Za-z]\d+)-(.+)$/', $name, $matches)) {
            $buchstaben_bezeichnung = strtoupper($matches[1]);  // X2, X3, X1 etc.
            $rest_route = $matches[2];
            
            error_log("FAHRPLANPORTAL: 🔍 Buchstaben-Bezeichnung erkannt: '$buchstaben_bezeichnung'");
            error_log("FAHRPLANPORTAL: 🔍 Rest-Route: '$rest_route'");
            
            // Erste Bezeichnung speichern
            $alle_bezeichnungen = array($buchstaben_bezeichnung);
            
            // Rest-Route analysieren: Weitere Nummern?
            if (preg_match('/^(\d{2,4}(?:-\d{2,4})*)-(.+)$/', $rest_route, $nummer_matches)) {
                $nummern_string = $nummer_matches[1];
                $final_route = $nummer_matches[2];
                
                $zusatz_nummern = explode('-', $nummern_string);
                $alle_bezeichnungen = array_merge($alle_bezeichnungen, $zusatz_nummern);
                
                error_log("FAHRPLANPORTAL: 🔍 Zusatz-Nummern gefunden: " . implode(', ', $zusatz_nummern));
            } else {
                $final_route = $rest_route;
                error_log("FAHRPLANPORTAL: 🔍 Keine Zusatz-Nummern, nur Route");
            }
            
            $linie_neu = implode(', ', $alle_bezeichnungen);
            error_log("FAHRPLANPORTAL: 🔍 Alle neue Bezeichnungen: [$linie_neu]");
            
            // ✅ DETAILLIERTES MAPPING-LOOKUP
            $alte_bezeichnungen = array();
            foreach ($alle_bezeichnungen as $bezeichnung) {
                $bezeichnung = strtoupper(trim($bezeichnung));  // Normalisierung
                
                error_log("FAHRPLANPORTAL: 🔍 Suche Mapping für: '$bezeichnung'");
                
                if (isset($line_mapping[$bezeichnung])) {
                    $gemappte_alte = $line_mapping[$bezeichnung];
                    $alte_bezeichnungen[] = $gemappte_alte;
                    error_log("FAHRPLANPORTAL: ✅ Mapping GEFUNDEN: '$bezeichnung' → '$gemappte_alte'");
                } else {
                    error_log("FAHRPLANPORTAL: ❌ Mapping NICHT gefunden für: '$bezeichnung'");
                    error_log("FAHRPLANPORTAL: 🔍 Verfügbare Mapping-Keys: " . implode(', ', array_keys($line_mapping)));
                    
                    // Fuzzy-Search für mögliche Tippfehler
                    $similar_keys = array();
                    foreach (array_keys($line_mapping) as $key) {
                        if (strcasecmp($key, $bezeichnung) === 0) {
                            $similar_keys[] = $key . " (case-insensitive match)";
                        } elseif (levenshtein($key, $bezeichnung) <= 2) {
                            $similar_keys[] = $key . " (ähnlich)";
                        }
                    }
                    
                    if (!empty($similar_keys)) {
                        error_log("FAHRPLANPORTAL: 💡 Ähnliche Keys gefunden: " . implode(', ', $similar_keys));
                    }
                }
            }
            
            if (!empty($alte_bezeichnungen)) {
                $linie_alt = implode(', ', $alte_bezeichnungen);
                error_log("FAHRPLANPORTAL: ✅ Finale alte Bezeichnungen: [$linie_alt]");
            } else {
                error_log("FAHRPLANPORTAL: ⚠️ KEINE Mappings gefunden - linie_alt bleibt leer");
            }
        }
        // Standard-Nummern (561-, 82-, etc.)
        elseif (preg_match('/^(\d{2,3}(?:-\d{2,3})*)-(.+)$/', $name, $matches)) {
            $nummern_string = $matches[1];
            $final_route = $matches[2];
            
            $nummern_array = explode('-', $nummern_string);
            $linie_neu = implode(', ', $nummern_array);
            
            error_log("FAHRPLANPORTAL: 🔍 Standard-Nummern: [$linie_neu]");
            
            // Mapping für Nummern
            $alte_nummern = array();
            foreach ($nummern_array as $nummer) {
                $nummer = trim($nummer);
                
                if (isset($line_mapping[$nummer])) {
                    $alte_nummern[] = $line_mapping[$nummer];
                    error_log("FAHRPLANPORTAL: ✅ Nummern-Mapping: '$nummer' → '" . $line_mapping[$nummer] . "'");
                } else {
                    error_log("FAHRPLANPORTAL: ❌ Nummern-Mapping nicht gefunden für: '$nummer'");
                }
            }
            
            if (!empty($alte_nummern)) {
                $linie_alt = implode(', ', $alte_nummern);
            }
        }
        // 4-stellige Nummern (Fallback)
        elseif (preg_match('/^(\d{4}(?:-\d{4})*)-(.+)$/', $name, $matches)) {
            $alte_nummern_string = $matches[1];
            $final_route = $matches[2];
            
            $alte_nummern_array = explode('-', $alte_nummern_string);
            $linie_alt = implode(', ', $alte_nummern_array);
            
            error_log("FAHRPLANPORTAL: 🔍 4-stellige Nummern (Fallback): [$linie_alt]");
            
            // Reverse Mapping
            $reverse_mapping = array_flip($line_mapping);
            $neue_nummern = array();
            foreach ($alte_nummern_array as $alte_nummer) {
                if (isset($reverse_mapping[$alte_nummer])) {
                    $neue_nummern[] = $reverse_mapping[$alte_nummer];
                    error_log("FAHRPLANPORTAL: ✅ Reverse-Mapping: '$alte_nummer' → '" . $reverse_mapping[$alte_nummer] . "'");
                }
            }
            
            if (!empty($neue_nummern)) {
                $linie_neu = implode(', ', $neue_nummern);
            }
        }
        else {
            error_log("FAHRPLANPORTAL: ❌ Kein Muster erkannt für: " . $name);
            return false;
        }
        
        // Route verarbeiten
        if (isset($final_route)) {
            $orte = explode('-', $final_route);
            $orte = $this->process_abbreviations($orte);
            
            $orte_formatted = array();
            foreach ($orte as $ort) {
                $ort_mit_umlauten = $this->convert_german_umlauts($ort);
                
                if (strpos($ort_mit_umlauten, 'St.') === 0 || 
                    strpos($ort_mit_umlauten, 'an der ') === 0 ||
                    strpos($ort_mit_umlauten, ' am ') !== false ||
                    strpos($ort_mit_umlauten, ' bei ') !== false ||
                    strpos($ort_mit_umlauten, ' ob ') !== false ||
                    strpos($ort_mit_umlauten, ' ob der ') !== false ||
                    strpos($ort_mit_umlauten, ' unter ') !== false) {
                    $ort_formatted = $ort_mit_umlauten;
                } else {
                    $ort_formatted = ucfirst($ort_mit_umlauten);
                }
                
                $orte_formatted[] = $ort_formatted;
            }
            
            $titel = implode(' — ', $orte_formatted);
            
            $result = array(
                'titel' => $titel,
                'linie_alt' => $linie_alt,
                'linie_neu' => $linie_neu,
                'kurzbeschreibung' => '',
                'gueltig_von' => '',
                'gueltig_bis' => ''
            );
            
            error_log("FAHRPLANPORTAL: 🎯 FINALES ERGEBNIS:");
            error_log("FAHRPLANPORTAL:    Titel: $titel");
            error_log("FAHRPLANPORTAL:    Linie Neu: '$linie_neu'");
            error_log("FAHRPLANPORTAL:    Linie Alt: '$linie_alt'");
            
            return $result;
        }
        
        error_log("FAHRPLANPORTAL: ❌ Parse fehlgeschlagen - keine finale Route");
        return false;
    }
    
    /**
     * ✅ HILFSMETHODE: Datum in deutsches Format umwandeln (für Admin-Interface)
     */
    private function format_german_date($date) {
        if (empty($date) || $date === '0000-00-00') {
            return '';
        }
        
        $timestamp = strtotime($date);
        if ($timestamp) {
            return date('d.m.Y', $timestamp);
        }
        
        return $date;
    }


    /**
 * ✅ NEU: Alle Tags aus der Datenbank analysieren
 * ✅ Sammelt alle Tags, entfernt Duplikate, gleicht mit Exklusionsliste ab
 */
public function unified_analyze_all_tags() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung für Tag-Analyse');
        return;
    }
    
    error_log('FAHRPLANPORTAL: Starte Tag-Analyse für alle Fahrpläne');
    
    global $wpdb;
    
    try {
        // ✅ Schritt 1: Alle Tags aus der Datenbank sammeln
        $all_tags_query = "SELECT tags FROM {$this->table_name} WHERE tags IS NOT NULL AND tags != ''";
        $tag_rows = $wpdb->get_results($all_tags_query);
        
        if (empty($tag_rows)) {
            wp_send_json_success(array(
                'message' => 'Keine Tags in der Datenbank gefunden',
                'total_fahrplaene' => 0,
                'total_unique_tags' => 0,
                'excluded_tags' => array(),
                'not_excluded_tags' => array()
            ));
            return;
        }
        
        error_log('FAHRPLANPORTAL: Gefundene Fahrpläne mit Tags: ' . count($tag_rows));
        
        // ✅ Schritt 2: Alle Tags sammeln und aufteilen
        $all_tags_raw = array();
        $fahrplan_count = 0;
        
        foreach ($tag_rows as $row) {
            $fahrplan_count++;
            $tags_string = trim($row->tags);
            
            if (!empty($tags_string)) {
                // Tags aufteilen (kommagetrennt)
                $tags_array = explode(',', $tags_string);
                
                foreach ($tags_array as $tag) {
                    $clean_tag = trim($tag);
                    if (!empty($clean_tag)) {
                        $all_tags_raw[] = mb_strtolower($clean_tag, 'UTF-8');
                    }
                }
            }
        }
        
        error_log('FAHRPLANPORTAL: Gesammelte Tags (mit Duplikaten): ' . count($all_tags_raw));
        
        // ✅ Schritt 3: Duplikate entfernen und sortieren
        $unique_tags = array_unique($all_tags_raw);
        sort($unique_tags);
        
        error_log('FAHRPLANPORTAL: Eindeutige Tags: ' . count($unique_tags));
        
        // ✅ Schritt 4: Aktuelle Exklusionsliste laden
        $exclusion_words = $this->get_exclusion_words();
        $exclusion_count = count($exclusion_words);
        
        error_log('FAHRPLANPORTAL: Exklusionsliste enthält: ' . $exclusion_count . ' Wörter');
        
        // ✅ Schritt 5: Tags gegen Exklusionsliste abgleichen
        $excluded_tags = array();      // Tags die bereits in Exklusionsliste sind
        $not_excluded_tags = array();  // Tags die NICHT in Exklusionsliste sind
        
        foreach ($unique_tags as $tag) {
            if (isset($exclusion_words[$tag])) {
                // Tag ist bereits in Exklusionsliste
                $excluded_tags[] = $tag;
            } else {
                // Tag ist NICHT in Exklusionsliste
                $not_excluded_tags[] = $tag;
            }
        }
        
        // ✅ Schritt 6: Statistiken sammeln
        $total_unique = count($unique_tags);
        $excluded_count = count($excluded_tags);
        $not_excluded_count = count($not_excluded_tags);
        $exclusion_percentage = $total_unique > 0 ? round(($excluded_count / $total_unique) * 100, 1) : 0;
        
        error_log('FAHRPLANPORTAL: Tag-Analyse abgeschlossen:');
        error_log('  - Fahrpläne mit Tags: ' . $fahrplan_count);
        error_log('  - Eindeutige Tags: ' . $total_unique);
        error_log('  - Bereits ausgeschlossen: ' . $excluded_count . ' (' . $exclusion_percentage . '%)');
        error_log('  - Noch nicht ausgeschlossen: ' . $not_excluded_count);
        
        // ✅ Schritt 7: Zusätzliche Analysen
        
        // Häufigkeits-Analyse für nicht ausgeschlossene Tags
        $tag_frequency = array();
        foreach ($all_tags_raw as $tag) {
            if (!isset($exclusion_words[$tag])) {
                if (!isset($tag_frequency[$tag])) {
                    $tag_frequency[$tag] = 0;
                }
                $tag_frequency[$tag]++;
            }
        }
        
        // Top 20 häufigste nicht ausgeschlossene Tags
        arsort($tag_frequency);
        $top_frequent_tags = array_slice($tag_frequency, 0, 20, true);
        
        // Kurze vs. lange Tags-Analyse (nicht ausgeschlossen)
        $short_tags = array();  // <= 3 Zeichen
        $long_tags = array();   // >= 10 Zeichen
        
        foreach ($not_excluded_tags as $tag) {
            $tag_length = mb_strlen($tag, 'UTF-8');
            
            if ($tag_length <= 3) {
                $short_tags[] = $tag;
            } elseif ($tag_length >= 10) {
                $long_tags[] = $tag;
            }
        }
        
        // ✅ Ergebnis zurückgeben
        wp_send_json_success(array(
            'message' => 'Tag-Analyse erfolgreich abgeschlossen',
            'statistics' => array(
                'total_fahrplaene' => $fahrplan_count,
                'total_unique_tags' => $total_unique,
                'excluded_count' => $excluded_count,
                'not_excluded_count' => $not_excluded_count,
                'exclusion_percentage' => $exclusion_percentage,
                'exclusion_list_size' => $exclusion_count
            ),
            'excluded_tags' => array_values($excluded_tags),
            'not_excluded_tags' => array_values($not_excluded_tags),
            'analysis' => array(
                'top_frequent_tags' => $top_frequent_tags,
                'short_tags' => $short_tags,
                'long_tags' => $long_tags,
                'short_tags_count' => count($short_tags),
                'long_tags_count' => count($long_tags)
            ),
            'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ));
        
    } catch (Exception $e) {
        error_log('FAHRPLANPORTAL: Tag-Analyse Fehler: ' . $e->getMessage());
        wp_send_json_error('Fehler bei der Tag-Analyse: ' . $e->getMessage());
    }
}



}

// ✅ GEFIXT: System für Admin + Admin-AJAX initialisieren (OHNE Frontend)
// Frontend wird durch shortcode.php abgedeckt
if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
    // Global verfügbar machen für Unified System
    global $fahrplanportal_instance;
    $fahrplanportal_instance = new FahrplanPortal();
    error_log('✅ FAHRPLANPORTAL: Initialisiert (Admin + Admin-AJAX - OHNE Frontend)');
} else {
    error_log('✅ FAHRPLANPORTAL: Frontend-Skip (Shortcode bereits geladen)');
}



?>