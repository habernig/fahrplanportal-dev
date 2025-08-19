<?php
/**
 * Dateiname: fahrplanportal.php
 * Fahrplanportal Module - STAGING/LIVE VERSION
 * Verwaltung von Bus-Fahrplänen für Kärntner Linien
 * 
 * ✅ NEU: Staging/Live-System implementiert
 * ✅ STAGING TABLE: wp_fahrplaene (Arbeitsdaten)
 * ✅ LIVE TABLE: wp_fahrplaene_live (Öffentliche Daten)
 * ✅ BACKUP SYSTEM: wp_fahrplaene_backup_TIMESTAMP
 * ✅ GO-LIVE BUTTON: Kopiert Staging → Live
 * ✅ ROLLBACK: Stellt vorherige Live-Version wieder her
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ✅ SHORTCODE IMMER LADEN (auch im Frontend)
if (file_exists(__DIR__ . '/functions/shortcode.php')) {
    require_once(__DIR__ . '/functions/shortcode.php');
    error_log('✅ FAHRPLANPORTAL: Shortcode geladen (Frontend + Admin)');
}

if (file_exists(__DIR__ . '/functions/search-logging.php')) {
    require_once(__DIR__ . '/functions/search-logging.php');
}

// ✅ NEU: Publish Manager laden
if (file_exists(__DIR__ . '/functions/publish-manager.php')) {
    require_once(__DIR__ . '/functions/publish-manager.php');
}

// ✅ ERWEITERT: Frontend ausschließen, Admin-AJAX + Frontend-AJAX erlauben
if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
    error_log('✅ FAHRPLANPORTAL: Frontend-Exit (Shortcode bereits geladen)');
    return;
}

// PDF-Parser nur laden wenn verfügbar (nur für Admin/AJAX)
if (file_exists(__DIR__ . '/functions/pdf_parser.php')) {
    require_once(__DIR__ . '/functions/pdf_parser.php');
}

class FahrplanPortal {
    
    private $table_name;
    private $live_table_name;
    private $pdf_base_path;
    private $pdf_parsing_enabled;
    
    public function __construct() {
        global $wpdb;
        
        // ✅ ERWEITERT: Frontend ausschließen, Admin-AJAX + Frontend-AJAX erlauben
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        $this->table_name = $wpdb->prefix . 'fahrplaene';           // ✅ STAGING TABLE
        $this->live_table_name = $wpdb->prefix . 'fahrplaene_live'; // ✅ LIVE TABLE
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
        
        error_log('✅ FAHRPLANPORTAL: Initialisiert (Admin + Admin-AJAX Handler - STAGING/LIVE System)');
    }
    
    /**
     * ✅ ERWEITERT: register_unified_admin_handlers() um Publish-Funktionen
     */
    public function register_unified_admin_handlers() {
        // ✅ ERWEITERT: Admin UND Frontend-AJAX erlauben
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
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
        
        // ✅ ERWEITERT: Admin-Module (jetzt mit Publish-Funktionen)
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
            'analyze_all_tags' => array($this, 'unified_analyze_all_tags'),
            
            // ✅ NEU: Publish-Funktionen
            'get_publish_status' => array($this, 'unified_get_publish_status'),
            'preview_changes' => array($this, 'unified_preview_changes'),
            'go_live' => array($this, 'unified_go_live'),
            'rollback' => array($this, 'unified_rollback'),
            'get_backup_list' => array($this, 'unified_get_backup_list'),
        ));
        
        error_log('✅ FAHRPLANPORTAL: Admin Handler mit Staging/Live-System im Unified System registriert');
    }
    
    // ========================================
    // ✅ NEU: PUBLISH-SYSTEM FUNKTIONEN
    // ========================================
    
    /**
     * ✅ NEU: Publish-Status ermitteln
     */
    public function unified_get_publish_status() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        global $wpdb;
        
        try {
            // Staging-Anzahl
            $staging_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            $staging_last_update = $wpdb->get_var("SELECT MAX(updated_at) FROM {$this->table_name}");
            
            // Live-Anzahl (falls Tabelle existiert)
            $live_count = 0;
            $live_last_update = null;
            $live_table_exists = $this->table_exists($this->live_table_name);
            
            if ($live_table_exists) {
                $live_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->live_table_name}");
                $live_last_update = $wpdb->get_var("SELECT MAX(updated_at) FROM {$this->live_table_name}");
            }
            
            // Diff berechnen
            $differences = $this->calculate_staging_live_diff();
            
            // Backup-Status
            $latest_backup = $this->get_latest_backup_info();
            
            wp_send_json_success(array(
                'staging' => array(
                    'count' => intval($staging_count),
                    'last_update' => $staging_last_update,
                    'table_exists' => true
                ),
                'live' => array(
                    'count' => intval($live_count),
                    'last_update' => $live_last_update,
                    'table_exists' => $live_table_exists
                ),
                'differences' => $differences,
                'latest_backup' => $latest_backup,
                'can_publish' => $differences['total'] > 0,
                'can_rollback' => $latest_backup !== null
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Publish-Status Fehler: ' . $e->getMessage());
            wp_send_json_error('Fehler beim Ermitteln des Publish-Status: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ NEU: Vorschau der Änderungen
     */
    public function unified_preview_changes() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        try {
            $differences = $this->calculate_staging_live_diff();
            $detailed_changes = $this->get_detailed_changes();
            
            wp_send_json_success(array(
                'summary' => $differences,
                'details' => $detailed_changes,
                'estimated_time' => $this->estimate_publish_time($differences['total']),
                'validation' => $this->validate_staging_data()
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Preview-Changes Fehler: ' . $e->getMessage());
            wp_send_json_error('Fehler bei der Änderungsvorschau: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ NEU: Go-Live durchführen
     */
    public function unified_go_live() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung für Go-Live');
            return;
        }
        
        global $wpdb;
        
        try {
            // Schritt 1: Validation
            $validation = $this->validate_staging_data();
            if (!$validation['valid']) {
                wp_send_json_error('Staging-Daten sind nicht valide: ' . implode(', ', $validation['errors']));
                return;
            }
            
            // Schritt 2: Backup der aktuellen Live-Daten erstellen
            $backup_result = $this->create_live_backup();
            if (!$backup_result['success']) {
                wp_send_json_error('Backup fehlgeschlagen: ' . $backup_result['error']);
                return;
            }
            
            // Schritt 3: Live-Tabelle leeren und neu befüllen
            $wpdb->query("DELETE FROM {$this->live_table_name}");
            
            if ($wpdb->last_error) {
                wp_send_json_error('Fehler beim Leeren der Live-Tabelle: ' . $wpdb->last_error);
                return;
            }
            
            // Schritt 4: Staging → Live kopieren
            $copy_result = $wpdb->query("INSERT INTO {$this->live_table_name} SELECT * FROM {$this->table_name}");
            
            if ($copy_result === false) {
                wp_send_json_error('Fehler beim Kopieren der Daten: ' . $wpdb->last_error);
                return;
            }
            
            // Schritt 5: Statistiken sammeln
            $live_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->live_table_name}");
            
            wp_send_json_success(array(
                'message' => 'Go-Live erfolgreich abgeschlossen',
                'live_count' => intval($live_count),
                'backup_table' => $backup_result['backup_table'],
                'timestamp' => current_time('mysql'),
                'copied_records' => intval($copy_result)
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Go-Live Fehler: ' . $e->getMessage());
            wp_send_json_error('Fehler beim Go-Live: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ NEU: Rollback durchführen
     */
    public function unified_rollback() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung für Rollback');
            return;
        }
        
        $backup_table = sanitize_text_field($_POST['backup_table'] ?? '');
        
        if (empty($backup_table)) {
            wp_send_json_error('Kein Backup-Table angegeben');
            return;
        }
        
        global $wpdb;
        
        try {
            // Backup-Tabelle validieren
            if (!$this->table_exists($backup_table)) {
                wp_send_json_error('Backup-Tabelle existiert nicht: ' . $backup_table);
                return;
            }
            
            // Live-Tabelle leeren und Backup wiederherstellen
            $wpdb->query("DELETE FROM {$this->live_table_name}");
            $restore_result = $wpdb->query("INSERT INTO {$this->live_table_name} SELECT * FROM {$backup_table}");
            
            if ($restore_result === false) {
                wp_send_json_error('Fehler beim Wiederherstellen: ' . $wpdb->last_error);
                return;
            }
            
            $live_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->live_table_name}");
            
            wp_send_json_success(array(
                'message' => 'Rollback erfolgreich abgeschlossen',
                'live_count' => intval($live_count),
                'restored_from' => $backup_table,
                'timestamp' => current_time('mysql')
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Rollback Fehler: ' . $e->getMessage());
            wp_send_json_error('Fehler beim Rollback: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ NEU: Backup-Liste abrufen
     */
    public function unified_get_backup_list() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        try {
            $backups = $this->get_available_backups();
            
            wp_send_json_success(array(
                'backups' => $backups,
                'total' => count($backups)
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Backup-Liste Fehler: ' . $e->getMessage());
            wp_send_json_error('Fehler beim Laden der Backup-Liste: ' . $e->getMessage());
        }
    }
    
    // ========================================
    // ✅ NEU: PUBLISH-SYSTEM HELPER-METHODEN
    // ========================================
    
    /**
     * ✅ NEU: Berechnet Unterschiede zwischen Staging und Live
     */
    private function calculate_staging_live_diff() {
        global $wpdb;
        
        // Falls Live-Tabelle nicht existiert, sind alle Staging-Einträge "neu"
        if (!$this->table_exists($this->live_table_name)) {
            $total_staging = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            return array(
                'new' => intval($total_staging),
                'modified' => 0,
                'deleted' => 0,
                'total' => intval($total_staging)
            );
        }
        
        // Neue Einträge (in Staging, nicht in Live)
        $new_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->table_name} s
            LEFT JOIN {$this->live_table_name} l ON (
                s.dateiname = l.dateiname AND 
                s.jahr = l.jahr AND 
                s.region = l.region
            )
            WHERE l.id IS NULL
        ");
        
        // Geänderte Einträge (verschiedene updated_at)
        $modified_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->table_name} s
            INNER JOIN {$this->live_table_name} l ON (
                s.dateiname = l.dateiname AND 
                s.jahr = l.jahr AND 
                s.region = l.region
            )
            WHERE s.updated_at > l.updated_at
        ");
        
        // Gelöschte Einträge (in Live, nicht in Staging)
        $deleted_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->live_table_name} l
            LEFT JOIN {$this->table_name} s ON (
                l.dateiname = s.dateiname AND 
                l.jahr = s.jahr AND 
                l.region = s.region
            )
            WHERE s.id IS NULL
        ");
        
        return array(
            'new' => intval($new_count),
            'modified' => intval($modified_count),
            'deleted' => intval($deleted_count),
            'total' => intval($new_count) + intval($modified_count) + intval($deleted_count)
        );
    }
    
    /**
     * ✅ NEU: Detaillierte Änderungen abrufen
     */
    private function get_detailed_changes() {
        global $wpdb;
        
        $changes = array(
            'new' => array(),
            'modified' => array(),
            'deleted' => array()
        );
        
        if (!$this->table_exists($this->live_table_name)) {
            // Alle Staging-Einträge sind "neu"
            $new_entries = $wpdb->get_results("
                SELECT titel, dateiname, region, jahr 
                FROM {$this->table_name} 
                ORDER BY region, titel 
                LIMIT 10
            ");
            
            foreach ($new_entries as $entry) {
                $changes['new'][] = array(
                    'title' => $entry->titel,
                    'file' => $entry->dateiname,
                    'region' => $entry->region,
                    'year' => $entry->jahr
                );
            }
            
            return $changes;
        }
        
        // Neue Einträge
        $new_entries = $wpdb->get_results("
            SELECT s.titel, s.dateiname, s.region, s.jahr 
            FROM {$this->table_name} s
            LEFT JOIN {$this->live_table_name} l ON (
                s.dateiname = l.dateiname AND 
                s.jahr = l.jahr AND 
                s.region = l.region
            )
            WHERE l.id IS NULL
            ORDER BY s.region, s.titel 
            LIMIT 10
        ");
        
        foreach ($new_entries as $entry) {
            $changes['new'][] = array(
                'title' => $entry->titel,
                'file' => $entry->dateiname,
                'region' => $entry->region,
                'year' => $entry->jahr
            );
        }
        
        // Geänderte Einträge
        $modified_entries = $wpdb->get_results("
            SELECT s.titel, s.dateiname, s.region, s.jahr 
            FROM {$this->table_name} s
            INNER JOIN {$this->live_table_name} l ON (
                s.dateiname = l.dateiname AND 
                s.jahr = l.jahr AND 
                s.region = l.region
            )
            WHERE s.updated_at > l.updated_at
            ORDER BY s.region, s.titel 
            LIMIT 10
        ");
        
        foreach ($modified_entries as $entry) {
            $changes['modified'][] = array(
                'title' => $entry->titel,
                'file' => $entry->dateiname,
                'region' => $entry->region,
                'year' => $entry->jahr
            );
        }
        
        // Gelöschte Einträge
        $deleted_entries = $wpdb->get_results("
            SELECT l.titel, l.dateiname, l.region, l.jahr 
            FROM {$this->live_table_name} l
            LEFT JOIN {$this->table_name} s ON (
                l.dateiname = s.dateiname AND 
                l.jahr = s.jahr AND 
                l.region = s.region
            )
            WHERE s.id IS NULL
            ORDER BY l.region, l.titel 
            LIMIT 10
        ");
        
        foreach ($deleted_entries as $entry) {
            $changes['deleted'][] = array(
                'title' => $entry->titel,
                'file' => $entry->dateiname,
                'region' => $entry->region,
                'year' => $entry->jahr
            );
        }
        
        return $changes;
    }
    
    /**
     * ✅ NEU: Staging-Daten validieren
     */
    private function validate_staging_data() {
        global $wpdb;
        
        $errors = array();
        
        // Prüfung 1: Mindestens ein Eintrag vorhanden
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        if ($count == 0) {
            $errors[] = "Keine Daten in Staging-Tabelle";
        }
        
        // Prüfung 2: Keine leeren Titel
        $empty_titles = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE titel = '' OR titel IS NULL");
        if ($empty_titles > 0) {
            $errors[] = "$empty_titles Einträge ohne Titel";
        }
        
        // Prüfung 3: Keine leeren PDF-Pfade
        $empty_pdfs = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE pdf_pfad = '' OR pdf_pfad IS NULL");
        if ($empty_pdfs > 0) {
            $errors[] = "$empty_pdfs Einträge ohne PDF-Pfad";
        }
        
        return array(
            'valid' => empty($errors),
            'errors' => $errors,
            'total_checked' => intval($count)
        );
    }
    
    /**
     * ✅ NEU: Live-Backup erstellen
     */
    private function create_live_backup() {
        global $wpdb;
        
        if (!$this->table_exists($this->live_table_name)) {
            return array('success' => true, 'backup_table' => null, 'message' => 'Keine Live-Daten zum Backup');
        }
        
        $timestamp = date('Ymd_His');
        $backup_table = $wpdb->prefix . 'fahrplaene_backup_' . $timestamp;
        
        try {
            // Backup-Tabelle erstellen
            $create_sql = "CREATE TABLE {$backup_table} LIKE {$this->live_table_name}";
            $wpdb->query($create_sql);
            
            if ($wpdb->last_error) {
                return array('success' => false, 'error' => 'Backup-Tabelle erstellen fehlgeschlagen: ' . $wpdb->last_error);
            }
            
            // Daten kopieren
            $insert_sql = "INSERT INTO {$backup_table} SELECT * FROM {$this->live_table_name}";
            $result = $wpdb->query($insert_sql);
            
            if ($result === false) {
                return array('success' => false, 'error' => 'Backup-Daten kopieren fehlgeschlagen: ' . $wpdb->last_error);
            }
            
            return array(
                'success' => true, 
                'backup_table' => $backup_table,
                'records_backed_up' => intval($result)
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Backup-Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ NEU: Letzte Backup-Info abrufen
     */
    private function get_latest_backup_info() {
        $backups = $this->get_available_backups();
        
        if (empty($backups)) {
            return null;
        }
        
        return $backups[0]; // Erster ist der neueste
    }
    
    /**
     * ✅ NEU: Verfügbare Backups auflisten
     */
    private function get_available_backups() {
        global $wpdb;
        
        $prefix = $wpdb->prefix . 'fahrplaene_backup_';
        $tables = $wpdb->get_results("SHOW TABLES LIKE '{$prefix}%'", ARRAY_N);
        
        $backups = array();
        
        foreach ($tables as $table_array) {
            $table_name = $table_array[0];
            
            // Timestamp aus Tabellennamen extrahieren
            $timestamp_part = str_replace($prefix, '', $table_name);
            
            if (preg_match('/^(\d{8})_(\d{6})$/', $timestamp_part, $matches)) {
                $date_part = $matches[1]; // YYYYMMDD
                $time_part = $matches[2]; // HHMMSS
                
                $timestamp = DateTime::createFromFormat('Ymd_His', $timestamp_part);
                
                if ($timestamp) {
                    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                    
                    $backups[] = array(
                        'table_name' => $table_name,
                        'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                        'formatted_date' => $timestamp->format('d.m.Y H:i'),
                        'records' => intval($count),
                        'age_hours' => $this->calculate_hours_since($timestamp)
                    );
                }
            }
        }
        
        // Nach Datum sortieren (neueste zuerst)
        usort($backups, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        return $backups;
    }
    
    /**
     * ✅ NEU: Stunden seit Zeitpunkt berechnen
     */
    private function calculate_hours_since($datetime) {
        $now = new DateTime();
        $interval = $now->diff($datetime);
        
        return ($interval->days * 24) + $interval->h;
    }
    
    /**
     * ✅ NEU: Publish-Zeit schätzen
     */
    private function estimate_publish_time($record_count) {
        // Basis: 100ms pro Datensatz
        $base_time = $record_count * 0.1;
        
        // Minimum 2 Sekunden für Backup
        $total_time = max(2, $base_time + 2);
        
        return array(
            'seconds' => round($total_time),
            'formatted' => $this->format_duration($total_time)
        );
    }
    
    /**
     * ✅ NEU: Prüft ob Tabelle existiert
     */
    private function table_exists($table_name) {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        return !empty($table_exists);
    }
    
    // ========================================
    // ✅ ERWEITERTE DATABASE INIT (mit Live-Tabelle)
    // ========================================
    
    /**
     * ✅ ERWEITERT: Datenbank mit Live-Tabelle initialisieren
     */
    public function init_database() {
        // ✅ SOFORT-FIX: Nur bei AJAX-Calls nicht ausführen
        if (defined('DOING_AJAX') && DOING_AJAX) {
            error_log('⚠️ FAHRPLANPORTAL: init_database übersprungen (AJAX-Call)');
            return;
        }
        
        error_log('🔄 FAHRPLANPORTAL: init_database wird ausgeführt (echter Admin-Call) - STAGING/LIVE');
        
        global $wpdb;
        
        try {
            $charset_collate = $wpdb->get_charset_collate();
            
            // ✅ STAGING TABLE (bestehende Tabelle)
            $this->create_table_structure($this->table_name, $charset_collate, 'STAGING');
            
            // ✅ LIVE TABLE (neue Tabelle)
            $this->create_table_structure($this->live_table_name, $charset_collate, 'LIVE');
            
            error_log('✅ FAHRPLANPORTAL: Datenbank erfolgreich initialisiert (STAGING + LIVE Tabellen)');
            return true;
            
        } catch (Exception $e) {
            error_log('❌ FAHRPLANPORTAL: init_database Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✅ NEU: Tabellenstruktur erstellen (für Staging und Live)
     */
    private function create_table_structure($table_name, $charset_collate, $table_type = 'STAGING') {
        global $wpdb;
        
        // Basis-SQL ohne Tags
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
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
            INDEX idx_region (region),
            INDEX idx_dateiname_unique (dateiname, jahr, region)
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
                'INDEX idx_region (region),',
                'INDEX idx_region (region),
            FULLTEXT idx_tags (tags),',
                $sql
            );
        }
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // dbDelta mit Error-Handling
        $result = dbDelta($sql);
        
        if ($wpdb->last_error) {
            error_log('❌ FAHRPLANPORTAL: dbDelta Fehler für ' . $table_type . ': ' . $wpdb->last_error);
            throw new Exception('dbDelta Fehler für ' . $table_type . ': ' . $wpdb->last_error);
        }
        
        // Spalten erweitern/hinzufügen falls nötig
        $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN jahr VARCHAR(50) NOT NULL");
        
        // Region-Spalte hinzufügen falls sie nicht existiert
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'region'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN region VARCHAR(100) NOT NULL DEFAULT '' AFTER jahr");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_region (region)");
        }
        
        // Tags-Spalte nur hinzufügen wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled) {
            $tags_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'tags'");
            if (empty($tags_column_exists)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN tags LONGTEXT AFTER region");
                $wpdb->query("ALTER TABLE {$table_name} ADD FULLTEXT INDEX idx_tags (tags)");
                error_log('FAHRPLANPORTAL: Tags-Spalte hinzugefügt zu ' . $table_type);
            }
        }
        
        error_log('✅ FAHRPLANPORTAL: ' . $table_type . ' Tabelle erfolgreich erstellt/aktualisiert: ' . $table_name);
    }
    
    // ========================================
    // ✅ ERWEITERTE ADMIN-SEITE (mit Publish-Dashboard)
    // ========================================
    
    /**
     * ✅ ERWEITERT: Admin-Seite mit Staging/Live Dashboard
     */
    public function admin_page() {
        $available_folders = $this->get_available_folders();
        ?>
        <div class="wrap">
            <h1>Fahrplanportal Verwaltung - Staging/Live System</h1>
            
            <?php if (!$this->pdf_parsing_enabled): ?>
                <div class="notice notice-warning">
                    <p><strong>Hinweis:</strong> PDF-Parsing ist nicht verfügbar. Tags werden nicht automatisch generiert. 
                    Stelle sicher, dass der Smalot PDF Parser korrekt geladen ist.</p>
                </div>
            <?php endif; ?>
            
            <!-- ✅ NEU: Publish-Dashboard -->
            <div class="fahrplan-publish-dashboard">
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header">
                        <h2 style="margin: 0; color: #0073aa;">📊 Publish-Status</h2>
                    </div>
                    <div class="card-body" style="padding: 20px;">
                        <div id="publish-status-loading" style="text-align: center; padding: 20px;">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p>Lade Publish-Status...</p>
                        </div>
                        
                        <div id="publish-status-content" style="display: none;">
                            <!-- Wird via AJAX gefüllt -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ✅ ERWEITERT: Bestehende Scan-Controls -->
            <div class="fahrplan-controls">
                <p>
                    <label for="scan-year">Ordner auswählen (Import in STAGING):</label>
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
                        In Staging importieren
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
                        <strong>⚠️ STAGING-MODUS:</strong> Importierte Daten werden zunächst in die Staging-Umgebung geladen und sind NICHT öffentlich sichtbar.
                        <br><strong>📋 Workflow:</strong> 1) Import → 2) Daten prüfen/bearbeiten → 3) Go-Live Button → 4) Öffentliche Verfügbarkeit
                        <br><strong>Gefundene Ordner:</strong> <?php echo implode(', ', $available_folders); ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- ✅ Bestehende Progress Bar -->
            <div id="scan-progress-container" style="display: none;">
                <!-- Unverändert -->
            </div>
            
            <!-- ✅ GEÄNDERT: Staging-Tabelle statt Live-Daten -->
            <div id="fahrplaene-container">
                <div class="staging-table-header">
                    <h3 style="color: #856404; margin-bottom: 10px;">
                        📝 Staging-Daten (nicht öffentlich)
                        <small style="font-weight: normal; color: #666;">- Hier können Sie Daten bearbeiten bevor sie live gehen</small>
                    </h3>
                </div>
                
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
            
            <!-- ✅ Bestehende Modal bleibt unverändert -->
            <div id="fahrplan-edit-modal" class="fahrplan-modal">
                <!-- Unverändert -->
            </div>
        </div>
        
        <!-- ✅ Bestehender Admin-Init Script bleibt -->
        <script>
        jQuery(document).ready(function($) {
            console.log('FAHRPLANPORTAL: Admin-Seite geladen mit STAGING/LIVE System');
            
            if (typeof fahrplanportal_unified !== 'undefined') {
                console.log('✅ FAHRPLANPORTAL: Admin-Kontext bestätigt:', fahrplanportal_unified.context);
            }
        });
        </script>
        <?php
    }
    
    // ========================================
    // ✅ ALLE BESTEHENDEN FUNKTIONEN (unverändert)
    // ========================================
    
    /* Hier folgen alle bestehenden Funktionen wie:
     * - get_scan_info, scan_chunk, process_single_pdf_file
     * - parse_filename, extract_pdf_tags
     * - Modal-Funktionen, DataTable-Funktionen
     * - etc.
     * 
     * Diese bleiben KOMPLETT UNVERÄNDERT da sie nur mit Staging arbeiten
     */
    
    // [... alle bestehenden Funktionen aus der Original-Datei ...]
    // [Aus Platzgründen nicht wiederholt, aber alle bleiben identisch]
    
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return $seconds . ' Sek';
        } else if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remaining_seconds = $seconds % 60;
            return $minutes . ' Min' . ($remaining_seconds > 0 ? ' ' . $remaining_seconds . ' Sek' : '');
        } else {
            $hours = floor($seconds / 3600);
            $remaining_minutes = floor(($seconds % 3600) / 60);
            return $hours . ' Std' . ($remaining_minutes > 0 ? ' ' . $remaining_minutes . ' Min' : '');
        }
    }
    
    // ✅ Alle anderen bestehenden Methoden bleiben unverändert...
    // (unified_scan_fahrplaene, unified_scan_chunk, etc.)
    
} // Ende FahrplanPortal Klasse

// ✅ GEFIXT: System für Admin + Admin-AJAX initialisieren (OHNE Frontend)
if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
    global $fahrplanportal_instance;
    $fahrplanportal_instance = new FahrplanPortal();
    error_log('✅ FAHRPLANPORTAL: Initialisiert (Admin + Admin-AJAX - STAGING/LIVE System)');
} else {
    error_log('✅ FAHRPLANPORTAL: Frontend-Skip (Shortcode bereits geladen)');
}

?>