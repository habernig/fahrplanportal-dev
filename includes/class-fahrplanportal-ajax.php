<?php
/**
 * FahrplanPortal AJAX Class
 * Alle AJAX-Endpunkte und Unified System Integration
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
    
    public function __construct($database, $parser, $utils, $pdf_parsing_enabled) {
        $this->database = $database;
        $this->parser = $parser;
        $this->utils = $utils;
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        
        // ✅ Admin AJAX Handler registrieren
        add_action('admin_init', array($this, 'register_unified_admin_handlers'), 20);
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
        
        $base_scan_path = ABSPATH . 'fahrplaene/' . $folder . '/';
        
        if (!is_dir($base_scan_path)) {
            wp_send_json_error('Verzeichnis nicht gefunden: ' . $base_scan_path);
            return;
        }
        
        $all_files = $this->parser->collect_all_scan_files($base_scan_path, $folder);
        
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
            'estimated_time' => $this->utils->estimate_processing_time($total_files, $this->pdf_parsing_enabled)
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
        wp_send_json_success(array(
            'chunk_index' => $chunk_index,
            'chunk_size' => count($chunk_files),
            'stats' => $chunk_stats,
            'total_files' => count($all_files),
            'parsing_enabled' => $this->pdf_parsing_enabled
        ));
    }
    
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
        
        $fahrplan = $this->database->get_fahrplan($id);
        
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
        
        $base_scan_path = ABSPATH . 'fahrplaene/' . $folder . '/';
        
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
        $all_files = $this->parser->collect_all_scan_files($base_scan_path, $folder);
        
        // Alle Dateien verarbeiten (nutzt jetzt automatisch die neue Gültigkeitslogik)
        foreach ($all_files as $file_info) {
            try {
                // Prüfen ob schon vorhanden
                $existing = $this->database->fahrplan_exists($file_info['filename'], $file_info['folder'], $file_info['region']);
                
                if ($existing) {
                    $skipped++;
                } else {
                    $result = $this->parser->process_single_pdf_file($file_info);
                    if ($result['success']) {
                        $this->database->insert_fahrplan($result['data']);
                        $imported++;
                    } else {
                        $skipped++;
                    }
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
        
        $update_data = array();
        
        // Erlaubte Felder für Update
        $allowed_fields = array('titel', 'linie_alt', 'linie_neu', 'kurzbeschreibung', 'gueltig_von', 'gueltig_bis', 'region');
        
        // Tags nur wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled) {
            $allowed_fields[] = 'tags';
        }
        
        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $update_data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        if (empty($update_data)) {
            wp_send_json_error('Keine Daten zum Aktualisieren');
        }
        
        $result = $this->database->update_fahrplan($id, $update_data);
        
        if ($result !== false) {
            wp_send_json_success('Fahrplan erfolgreich aktualisiert');
        } else {
            wp_send_json_error('Fehler beim Aktualisieren');
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
    
    /**
     * ✅ NEU: Alle Tags aus der Datenbank analysieren
     */
    public function unified_analyze_all_tags() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung für Tag-Analyse');
            return;
        }
        
        error_log('FAHRPLANPORTAL: Starte Tag-Analyse für alle Fahrpläne');
        
        try {
            // ✅ Schritt 1: Alle Tags aus der Datenbank sammeln
            $tag_rows = $this->database->get_all_tags();
            
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
            $exclusion_words = $this->utils->get_exclusion_words();
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