<?php
/**
 * FahrplanPortal AJAX Class
 * Alle AJAX-Endpunkte und Unified System Integration
 * 
 * ✅ ERWEITERT: Publisher-AJAX-Endpunkte hinzugefügt
 * ✅ NEU: Publish/Rollback-Funktionalität über AJAX
 * ✅ NEU: Publisher-Statistiken für Admin-UI
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Ajax {
    
    private $database;
    private $parser;
    private $utils;
    private $pdf_parsing_enabled;
    private $publisher;               // ✅ NEU: Publisher-Komponente
    
    public function __construct($database, $parser, $utils, $pdf_parsing_enabled, $publisher = null) {
        $this->database = $database;
        $this->parser = $parser;
        $this->utils = $utils;
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        $this->publisher = $publisher;    // ✅ NEU: Publisher hinzugefügt
        
        // ✅ Admin AJAX Handler registrieren
        add_action('admin_init', array($this, 'register_unified_admin_handlers'), 20);
    }
    
    /**
     * ✅ ERWEITERT: Admin-Handler mit Publisher-Endpunkten registrieren
     */
    public function register_unified_admin_handlers() {
        // ✅ ERWEITERT: Admin UND Frontend-AJAX erlauben
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Unified AJAX System prüfen
        if (!class_exists('UnifiedAjaxSystem')) {
            error_log('⚠️ FAHRPLANPORTAL AJAX: Unified AJAX System nicht verfügbar');
            return;
        }
        
        $unified_system = UnifiedAjaxSystem::getInstance();
        if (!$unified_system) {
            error_log('⚠️ FAHRPLANPORTAL AJAX: Unified System Instanz nicht verfügbar');
            return;
        }
        
        // ✅ ERWEITERT: Module mit Publisher-Endpunkten registrieren
        $endpoints = array(
            // Bestehende Endpunkte
            'scan_chunk' => array($this, 'unified_scan_chunk'),
            'delete_fahrplan' => array($this, 'unified_delete_fahrplan'),
            'recreate_db' => array($this, 'unified_recreate_db'),
            'clear_db' => array($this, 'unified_clear_db'),
            'save_exclusion_words' => array($this, 'unified_save_exclusion_words'),
            'load_exclusion_words' => array($this, 'unified_load_exclusion_words'),
            'save_line_mapping' => array($this, 'unified_save_line_mapping'),
            'load_line_mapping' => array($this, 'unified_load_line_mapping'),
            'analyze_tags' => array($this, 'unified_analyze_tags'),
            
            // ✅ NEU: Publisher-Endpunkte
            'publish_to_live' => array($this, 'unified_publish_to_live'),
            'rollback_live' => array($this, 'unified_rollback_live'),
            'get_publish_stats' => array($this, 'unified_get_publish_stats'),
            'check_live_system' => array($this, 'unified_check_live_system'),
            
            // Frontend-Endpunkte (bleiben bestehen)
            'frontend_search' => array($this, 'unified_frontend_search'),
            'frontend_suggestions' => array($this, 'unified_frontend_suggestions')
        );
        
        $unified_system->register_module('fahrplanportal', $endpoints);
        
        error_log('✅ FAHRPLANPORTAL AJAX: Unified Module registriert mit ' . count($endpoints) . ' Endpunkten (inkl. Publisher)');
    }
    
    // =======================================================
    // ✅ NEU: PUBLISHER-AJAX-ENDPUNKTE
    // =======================================================
    
    /**
     * ✅ NEU: Staging → Live veröffentlichen
     */
    public function unified_publish_to_live() {
        // Berechtigungsprüfung
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung für Publish-Operationen');
            return;
        }
        
        // Publisher verfügbar?
        if (!$this->publisher) {
            wp_send_json_error('Publisher-System nicht verfügbar');
            return;
        }
        
        // Publish durchführen
        $result = $this->publisher->publish_staging_to_live();
        
        if ($result['success']) {
            // Zusätzliche Statistiken für UI
            $stats = $this->publisher->get_publish_statistics();
            $result['stats'] = $stats;
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * ✅ NEU: Rollback zu Backup durchführen
     */
    public function unified_rollback_live() {
        // Berechtigungsprüfung
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung für Rollback-Operationen');
            return;
        }
        
        // Publisher verfügbar?
        if (!$this->publisher) {
            wp_send_json_error('Publisher-System nicht verfügbar');
            return;
        }
        
        // Rollback durchführen
        $result = $this->publisher->rollback_to_backup();
        
        if ($result['success']) {
            // Zusätzliche Statistiken für UI
            $stats = $this->publisher->get_publish_statistics();
            $result['stats'] = $stats;
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * ✅ NEU: Publisher-Statistiken abrufen
     */
    public function unified_get_publish_stats() {
        // Berechtigungsprüfung
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        // Publisher verfügbar?
        if (!$this->publisher) {
            wp_send_json_error('Publisher-System nicht verfügbar');
            return;
        }
        
        // Statistiken abrufen
        $stats = $this->publisher->get_publish_statistics();
        
        // Erweiterte Informationen hinzufügen
        $stats['system_ready'] = $this->publisher->is_live_system_ready();
        $stats['last_publish_formatted'] = $this->publisher->get_last_publish_date();
        
        wp_send_json_success($stats);
    }
    
    /**
     * ✅ NEU: Live-System-Status prüfen
     */
    public function unified_check_live_system() {
        // Berechtigungsprüfung
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        // Publisher verfügbar?
        if (!$this->publisher) {
            wp_send_json_success(array(
                'system_ready' => false,
                'message' => 'Publisher-System nicht verfügbar'
            ));
            return;
        }
        
        // System-Status prüfen
        $system_ready = $this->publisher->is_live_system_ready();
        
        wp_send_json_success(array(
            'system_ready' => $system_ready,
            'message' => $system_ready ? 'Live-System betriebsbereit' : 'Live-System wird initialisiert...'
        ));
    }
    
    // =======================================================
    // BESTEHENDE ENDPUNKTE (unverändert)
    // =======================================================
    
    /**
     * ✅ UNIFIED: Chunk-basiertes Scannen
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
        
        $base_scan_path = ABSPATH . 'fahrplaene/' . $folder . '/';
        
        if (!is_dir($base_scan_path)) {
            wp_send_json_error('Verzeichnis nicht gefunden');
            return;
        }
        
        // Alle Dateien sammeln
        $all_files = $this->parser->collect_all_scan_files($base_scan_path, $folder);
        
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
                // Prüfen ob schon vorhanden
                $existing = $this->database->fahrplan_exists($file_info['filename'], $file_info['folder'], $file_info['region']);
                
                if ($existing) {
                    $chunk_stats['skipped']++;
                } else {
                    $result = $this->parser->process_single_pdf_file($file_info);
                    
                    if ($result['success']) {
                        $this->database->insert_fahrplan($result['data']);
                        $chunk_stats['imported']++;
                    } else {
                        $chunk_stats['skipped']++;
                    }
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
        $chunk_stats['total_files'] = count($all_files);
        $chunk_stats['remaining_files'] = max(0, count($all_files) - (($chunk_index + 1) * $chunk_size));
        $chunk_stats['is_complete'] = $chunk_stats['remaining_files'] == 0;
        
        wp_send_json_success($chunk_stats);
    }
    
    /**
     * ✅ UNIFIED: Fahrplan löschen
     */
    public function unified_delete_fahrplan() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $id = intval($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error('Ungültige ID');
            return;
        }
        
        $result = $this->database->delete_fahrplan($id);
        
        if ($result !== false) {
            wp_send_json_success('Fahrplan erfolgreich gelöscht');
        } else {
            wp_send_json_error('Fehler beim Löschen');
        }
    }
    
    /**
     * ✅ UNIFIED: Datenbank neu erstellen
     */
    public function unified_recreate_db() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        if ($this->database->recreate_database()) {
            wp_send_json_success('Datenbank erfolgreich neu erstellt');
        } else {
            wp_send_json_error('Fehler beim Neu-Erstellen der Datenbank');
        }
    }
    
    /**
     * ✅ UNIFIED: Alle Einträge löschen
     */
    public function unified_clear_db() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $result = $this->database->clear_database();
        
        if ($result !== false) {
            wp_send_json_success('Alle Fahrpläne erfolgreich gelöscht');
        } else {
            wp_send_json_error('Fehler beim Leeren der Tabelle');
        }
    }
    
    /**
     * ✅ UNIFIED: Exklusionswörter speichern
     */
    public function unified_save_exclusion_words() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $exclusion_words = sanitize_textarea_field($_POST['exclusion_words'] ?? '');
        
        update_option('fahrplanportal_exclusion_words', $exclusion_words);
        
        // Wörter zählen
        $word_count = 0;
        if (!empty($exclusion_words)) {
            $words_array = preg_split('/[\s,\t\n\r]+/', $exclusion_words, -1, PREG_SPLIT_NO_EMPTY);
            $word_count = count($words_array);
        }
        
        wp_send_json_success(array(
            'message' => 'Exklusionswörter erfolgreich gespeichert',
            'word_count' => $word_count
        ));
    }
    
    /**
     * ✅ UNIFIED: Exklusionswörter laden
     */
    public function unified_load_exclusion_words() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $exclusion_words = get_option('fahrplanportal_exclusion_words', '');
        
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
            return;
        }
        
        $line_mapping = sanitize_textarea_field($_POST['line_mapping'] ?? '');
        
        update_option('fahrplanportal_line_mapping', $line_mapping);
        
        // Mapping-Einträge zählen
        $mapping_count = 0;
        if (!empty($line_mapping)) {
            $lines = preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0) {
                    if (strpos($line, ':') !== false) {
                        $mapping_count++;
                    }
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Linien-Mapping erfolgreich gespeichert',
            'mapping_count' => $mapping_count
        ));
    }
    
    /**
     * ✅ UNIFIED: Linien-Mapping laden
     */
    public function unified_load_line_mapping() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $line_mapping = get_option('fahrplanportal_line_mapping', '');
        
        $mapping_count = 0;
        if (!empty($line_mapping)) {
            $lines = preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0) {
                    if (strpos($line, ':') !== false) {
                        $mapping_count++;
                    }
                }
            }
        }
        
        wp_send_json_success(array(
            'line_mapping' => $line_mapping,
            'mapping_count' => $mapping_count
        ));
    }
    
    /**
     * ✅ UNIFIED: Tag-Analyse durchführen
     */
    public function unified_analyze_tags() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        if (!$this->pdf_parsing_enabled) {
            wp_send_json_error('PDF-Parsing nicht verfügbar');
            return;
        }
        
        // Alle Tags aus der Datenbank sammeln
        $tag_rows = $this->database->get_all_tags();
        
        $all_words = array();
        foreach ($tag_rows as $row) {
            if (!empty($row->tags)) {
                $words = explode(',', $row->tags);
                foreach ($words as $word) {
                    $word = trim($word);
                    if (!empty($word) && strlen($word) >= 3) {
                        $all_words[] = strtolower($word);
                    }
                }
            }
        }
        
        if (empty($all_words)) {
            wp_send_json_success(array(
                'unique_words' => array(),
                'total_count' => 0,
                'message' => 'Keine Tags in der Datenbank gefunden'
            ));
            return;
        }
        
        // Wörter zählen und sortieren
        $word_counts = array_count_values($all_words);
        arsort($word_counts);
        
        // Exklusionsliste laden
        $exclusion_words = $this->utils->get_exclusion_words();
        
        // Wörter analysieren
        $analyzed_words = array();
        foreach ($word_counts as $word => $count) {
            $is_excluded = isset($exclusion_words[$word]);
            $analyzed_words[] = array(
                'word' => $word,
                'count' => $count,
                'excluded' => $is_excluded
            );
        }
        
        wp_send_json_success(array(
            'unique_words' => $analyzed_words,
            'total_count' => count($all_words),
            'unique_count' => count($word_counts),
            'message' => 'Tag-Analyse abgeschlossen'
        ));
    }
    
    /**
     * ✅ UNIFIED: Frontend-Suche
     */
    public function unified_frontend_search() {
        $search_term = sanitize_text_field($_POST['search'] ?? '');
        $jahr = sanitize_text_field($_POST['jahr'] ?? '');
        $region = sanitize_text_field($_POST['region'] ?? '');
        
        // Search-Parameter für Database
        $search_params = array(
            'search_term' => $search_term,
            'jahr' => $jahr,
            'region' => $region,
            'limit' => 50
        );
        
        // Frontend-Suche über Database-Klasse (nutzt automatisch Live-Daten)
        $results = $this->database->search_fahrplaene_frontend($search_params);
        
        // Ergebnisse formatieren
        $formatted_results = array();
        foreach ($results as $row) {
            $formatted_results[] = array(
                'id' => $row->id,
                'titel' => $row->titel,
                'linie_alt' => $row->linie_alt,
                'linie_neu' => $row->linie_neu,
                'region' => $row->region,
                'jahr' => $row->jahr,
                'gueltig_von' => $row->gueltig_von,
                'gueltig_bis' => $row->gueltig_bis,
                'pdf_url' => $this->utils->get_pdf_url($row->pdf_pfad)
            );
        }
        
        wp_send_json_success(array(
            'results' => $formatted_results,
            'count' => count($formatted_results),
            'search_term' => $search_term
        ));
    }
    
    /**
     * ✅ UNIFIED: Frontend-Vorschläge (Autocomplete)
     */
    public function unified_frontend_suggestions() {
        $search_term = sanitize_text_field($_POST['term'] ?? '');
        
        if (strlen($search_term) < 2) {
            wp_send_json_success(array('suggestions' => array()));
            return;
        }
        
        global $wpdb;
        
        // Frontend nutzt automatisch Live-Tabelle über Database-Klasse
        $active_table = $this->database->get_table_name();
        $search_param = '%' . $wpdb->esc_like($search_term) . '%';
        
        $suggestions = array();
        
        // Verschiedene Suggestion-Typen sammeln
        try {
            // Regionen
            $regions = $this->extract_frontend_regions($search_param, $wpdb);
            foreach ($regions as $region_data) {
                $suggestions[] = array(
                    'type' => 'region',
                    'value' => $region_data['region'],
                    'label' => $region_data['region'] . ' (' . $region_data['count'] . ' Fahrpläne)',
                    'count' => $region_data['count']
                );
            }
            
            // Liniennummern
            $line_numbers = $this->extract_frontend_line_numbers($search_param, $wpdb);
            foreach ($line_numbers as $line_data) {
                $suggestions[] = array(
                    'type' => 'line',
                    'value' => $line_data['line'],
                    'label' => 'Linie ' . $line_data['line'],
                    'count' => $line_data['count']
                );
            }
            
            // Titel-Wörter
            $title_words = $this->extract_frontend_title_words($search_param, $wpdb);
            foreach ($title_words as $word_data) {
                $suggestions[] = array(
                    'type' => 'title',
                    'value' => $word_data['word'],
                    'label' => $word_data['word'],
                    'count' => $word_data['count']
                );
            }
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL FRONTEND SUGGESTIONS ERROR: ' . $e->getMessage());
        }
        
        wp_send_json_success(array('suggestions' => $suggestions));
    }
    
    // =======================================================
    // HELPER-METHODEN für Frontend-Suggestions
    // =======================================================
    
    private function extract_frontend_regions($search_param, $wpdb) {
        $active_table = $this->database->get_table_name();
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT region, COUNT(*) as count
            FROM {$active_table} 
            WHERE region LIKE %s AND region != ''
            GROUP BY region
            ORDER BY count DESC
            LIMIT 10
        ", $search_param));
        
        return array_map(function($result) {
            return array('region' => $result->region, 'count' => $result->count);
        }, $results);
    }
    
    private function extract_frontend_line_numbers($search_param, $wpdb) {
        $active_table = $this->database->get_table_name();
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                CASE 
                    WHEN linie_alt LIKE %s THEN linie_alt
                    WHEN linie_neu LIKE %s THEN linie_neu
                END as line_number,
                COUNT(*) as count
            FROM {$active_table} 
            WHERE (linie_alt LIKE %s OR linie_neu LIKE %s)
            AND (linie_alt LIKE %s OR linie_neu LIKE %s)
            GROUP BY line_number
            HAVING line_number IS NOT NULL
            ORDER BY count DESC
            LIMIT 10
        ", $search_param, $search_param, $search_param, $search_param, $search_param, $search_param));
        
        $lines = array();
        foreach ($results as $result) {
            $line_parts = explode(',', $result->line_number);
            foreach ($line_parts as $line) {
                $line = trim($line);
                if (!empty($line) && preg_match('/^\d{2,4}$/', $line)) {
                    $lines[] = array('line' => $line, 'count' => $result->count);
                }
            }
        }
        return $lines;
    }
    
    private function extract_frontend_title_words($search_param, $wpdb) {
        $active_table = $this->database->get_table_name();
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT titel, COUNT(*) as count
            FROM {$active_table} 
            WHERE titel LIKE %s 
            GROUP BY titel
            ORDER BY count DESC
            LIMIT 20
        ", $search_param));
        
        $words = array();
        foreach ($results as $result) {
            $parts = explode('—', $result->titel);
            foreach ($parts as $part) {
                $part = trim($part);
                $sub_parts = preg_split('/[\s\-]+/', $part);
                foreach ($sub_parts as $word) {
                    $word = trim($word, '.,!?');
                    if (strlen($word) >= 3 && stripos($word, $search_param) !== false) {
                        $words[] = array('word' => $word, 'count' => $result->count);
                    }
                }
            }
        }
        return array_slice($words, 0, 10);
    }
}