<?php
/**
 * FahrplanPortal AJAX Class
 * Alle AJAX-Endpunkte und Unified System Integration
 * 
 * ✅ AKTUALISIERT: Flexibles Mapping-System für alle Formate
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
            'estimated_time' => $this->estimate_processing_time($total_files, $this->pdf_parsing_enabled)
        ));
    }
    
    /**
     * Helper: Geschätzte Verarbeitungszeit
     */
    private function estimate_processing_time($file_count, $parsing_enabled) {
        // Geschätzte Zeit pro Datei (in Sekunden)
        $time_per_file = $parsing_enabled ? 0.5 : 0.1;
        $total_seconds = $file_count * $time_per_file;
        
        if ($total_seconds < 60) {
            return round($total_seconds) . ' Sekunden';
        } else {
            return round($total_seconds / 60, 1) . ' Minuten';
        }
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
            wp_send_json_error('Verzeichnis nicht gefunden: ' . $base_scan_path);
            return;
        }
        
        // Alle Dateien sammeln
        $all_files = $this->parser->collect_all_scan_files($base_scan_path, $folder);
        
        // Chunk extrahieren
        $start_index = $chunk_index * $chunk_size;
        $chunk_files = array_slice($all_files, $start_index, $chunk_size);
        
        // Chunk verarbeiten
        $chunk_stats = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'processed' => 0,
            'region_stats' => array(),  // NEU: Für Regionen-Statistik
            'files' => array()
        );
        
        foreach ($chunk_files as $file_info) {
            try {
                
                // Am Anfang jeder Datei-Verarbeitung
                $chunk_stats['processed']++;
                // Prüfen ob schon vorhanden
                $existing = $this->database->fahrplan_exists($file_info['filename'], $file_info['folder'], $file_info['region']);
                
                if ($existing) {
                    $chunk_stats['skipped']++;
                    $chunk_stats['files'][] = array(
                        'file' => $file_info['filename'],
                        'status' => 'skipped',
                        'message' => 'Bereits vorhanden'
                    );
                } else {
                    $result = $this->parser->process_single_pdf_file($file_info);
                    if ($result['success']) {
                        $this->database->insert_fahrplan($result['data']);
                        $chunk_stats['imported']++;
                        $chunk_stats['files'][] = array(
                            'file' => $file_info['filename'],
                            'status' => 'imported',
                            'message' => 'Erfolgreich importiert'
                        );
                    } else {
                        $chunk_stats['skipped']++;
                        $chunk_stats['files'][] = array(
                            'file' => $file_info['filename'],
                            'status' => 'skipped',
                            'message' => 'Parse-Fehler'
                        );
                    }
                }
            } catch (Exception $e) {
                $chunk_stats['errors']++;
                $chunk_stats['files'][] = array(
                    'file' => $file_info['filename'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                );
                error_log('FAHRPLANPORTAL: Fehler bei ' . $file_info['filename'] . ' - ' . $e->getMessage());
            }
        }

        $chunk_stats['processed'] = $chunk_stats['imported'] + $chunk_stats['skipped'] + $chunk_stats['errors'];
        
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
     * ✅ AKTUALISIERT: Linien-Mapping speichern mit flexibler Validierung
     */
    public function unified_save_line_mapping() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $line_mapping = sanitize_textarea_field($_POST['line_mapping'] ?? '');
        
        // Zuordnungen zählen mit FLEXIBLER Validierung
        $mapping_count = 0;
        $valid_mappings = array();
        $invalid_lines = array();
        
        if (!empty($line_mapping)) {
            $lines = preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY);
            
            foreach ($lines as $line_num => $line) {
                $line = trim($line);
                
                // Überspringe leere Zeilen und Kommentare
                if (empty($line) || strpos($line, '//') === 0 || strpos($line, '#') === 0) {
                    continue;
                }
                
                // ✅ NEUE VALIDIERUNG: Nur prüfen ob genau EIN Doppelpunkt vorhanden ist
                $colon_count = substr_count($line, ':');
                
                if ($colon_count === 1) {
                    // Zusätzlich prüfen ob beide Teile nicht leer sind
                    $parts = explode(':', $line, 2);
                    $linie_neu = trim($parts[0]);
                    $linie_alt = trim($parts[1]);
                    
                    if (!empty($linie_neu) && !empty($linie_alt)) {
                        $mapping_count++;
                        $valid_mappings[] = array(
                            'neu' => $linie_neu,
                            'alt' => $linie_alt,
                            'line' => $line_num + 1
                        );
                    } else {
                        $invalid_lines[] = array(
                            'line' => $line_num + 1,
                            'content' => $line,
                            'error' => 'Leere Teile vor oder nach dem Doppelpunkt'
                        );
                    }
                } else {
                    $invalid_lines[] = array(
                        'line' => $line_num + 1,
                        'content' => $line,
                        'error' => $colon_count === 0 ? 'Kein Doppelpunkt gefunden' : 'Mehrere Doppelpunkte gefunden (' . $colon_count . ')'
                    );
                }
            }
        }
        
        // Speichern
        update_option('fahrplanportal_line_mapping', $line_mapping);
        
        // Detaillierte Antwort mit Statistiken
        $response = array(
            'message' => 'Linien-Mapping erfolgreich gespeichert',
            'mapping_count' => $mapping_count,
            'total_lines' => count(preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY))
        );
        
        // Zeige spezielle Mappings
        $special_mappings = array();
        foreach ($valid_mappings as $mapping) {
            if (strpos($mapping['alt'], 'keine') !== false ||
                strpos($mapping['alt'], '/') !== false ||
                strpos($mapping['alt'], '(') !== false ||
                strpos($mapping['alt'], ' ') !== false ||
                preg_match('/^[A-Z]\d+$/i', $mapping['neu'])) {
                $special_mappings[] = $mapping['neu'] . ' → ' . $mapping['alt'];
            }
        }
        
        if (!empty($special_mappings)) {
            $response['special_mappings'] = array_slice($special_mappings, 0, 5); // Zeige erste 5
            $response['special_mappings_count'] = count($special_mappings);
        }
        
        // Bei Fehlern diese auch melden
        if (!empty($invalid_lines)) {
            $response['warnings'] = array(
                'count' => count($invalid_lines),
                'lines' => array_slice($invalid_lines, 0, 3) // Zeige erste 3 Fehler
            );
            
            // Logge alle Fehler
            foreach ($invalid_lines as $invalid) {
                error_log("FAHRPLANPORTAL: ⚠️ Ungültige Mapping-Zeile " . $invalid['line'] . ": " . $invalid['error'] . " - '" . $invalid['content'] . "'");
            }
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * ✅ AKTUALISIERT: Linien-Mapping laden mit flexibler Zählung
     */
    public function unified_load_line_mapping() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $line_mapping = get_option('fahrplanportal_line_mapping', '');
        
        // Zuordnungen zählen und analysieren
        $mapping_count = 0;
        $mapping_stats = array(
            'numeric' => 0,
            'alphanumeric' => 0,
            'text' => 0,
            'special' => 0
        );
        
        if (!empty($line_mapping)) {
            $lines = preg_split('/[\n\r]+/', $line_mapping, -1, PREG_SPLIT_NO_EMPTY);
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Überspringe leere Zeilen und Kommentare
                if (empty($line) || strpos($line, '//') === 0 || strpos($line, '#') === 0) {
                    continue;
                }
                
                // Prüfe auf gültiges Mapping (genau ein Doppelpunkt)
                if (substr_count($line, ':') === 1) {
                    $parts = explode(':', $line, 2);
                    $linie_neu = trim($parts[0]);
                    $linie_alt = trim($parts[1]);
                    
                    if (!empty($linie_neu) && !empty($linie_alt)) {
                        $mapping_count++;
                        
                        // Statistiken sammeln
                        if (is_numeric($linie_neu)) {
                            $mapping_stats['numeric']++;
                        } elseif (preg_match('/^[A-Z]\d+$/i', $linie_neu)) {
                            $mapping_stats['alphanumeric']++;
                        } elseif (strpos($linie_alt, 'keine') !== false || 
                                 strpos($linie_alt, '/') !== false || 
                                 strpos($linie_alt, '(') !== false) {
                            $mapping_stats['special']++;
                        } else {
                            $mapping_stats['text']++;
                        }
                    }
                }
            }
        }
        
        wp_send_json_success(array(
            'line_mapping' => $line_mapping,
            'mapping_count' => $mapping_count,
            'mapping_stats' => $mapping_stats
        ));
    }
    

    /**
     * ✅ NEU: Alle Tags aus der Datenbank analysieren
     * GEFIXT: Verarbeitet und sendet ALLE Tags, nicht nur Top 100
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
                // Leere Response mit erwarteter Struktur
                wp_send_json_success(array(
                    'message' => 'Keine Tags in der Datenbank gefunden',
                    'statistics' => array(
                        'total_fahrplaene' => 0,
                        'total_tags' => 0,
                        'total_unique_tags' => 0,
                        'excluded_count' => 0,
                        'not_excluded_count' => 0,
                        'exclusion_percentage' => 0,
                        'exclusion_list_size' => 0
                    ),
                    'analysis' => array(
                        'excluded_tags' => array(),
                        'not_excluded_tags' => array(),
                        'excluded_tags_total' => 0,
                        'not_excluded_tags_total' => 0,
                        'top_frequent_tags' => array(),
                        'short_tags' => array(),
                        'long_tags' => array()
                    ),
                    'processing_time' => 0
                ));
                return;
            }
            
            $start_time = microtime(true);
            
            error_log('FAHRPLANPORTAL: Gefundene Fahrpläne mit Tags: ' . count($tag_rows));
            
            // ✅ Schritt 2: Alle Tags sammeln und zählen
            $all_tags = array();
            $tag_counts = array();
            
            foreach ($tag_rows as $row) {
                if (empty($row->tags)) {
                    continue;
                }
                
                // Tags sind komma-getrennt
                $tags = explode(',', $row->tags);
                
                foreach ($tags as $tag) {
                    $tag = trim(mb_strtolower($tag, 'UTF-8'));
                    
                    if (!empty($tag)) {
                        if (!isset($tag_counts[$tag])) {
                            $tag_counts[$tag] = 0;
                        }
                        $tag_counts[$tag]++;
                        $all_tags[] = $tag;
                    }
                }
            }
            
            $total_tags = count($all_tags);
            $unique_tags = count($tag_counts);
            
            error_log('FAHRPLANPORTAL: Gesamt-Tags: ' . $total_tags . ', Unique Tags: ' . $unique_tags);
            
            // ✅ Schritt 3: Exklusionsliste laden
            $exclusion_words = $this->utils->get_exclusion_words();
            error_log('FAHRPLANPORTAL: Exklusionsliste geladen: ' . count($exclusion_words) . ' Wörter');
            
            // ✅ Schritt 4: ALLE Tags kategorisieren (nicht nur Top 100!)
            $excluded_tags = array();
            $not_excluded_tags = array();
            $short_tags = array();
            $long_tags = array();
            
            // ALLE Tags durchgehen für Kategorisierung
            foreach ($tag_counts as $tag => $count) {
                // Kategorisierung nach Exklusion
                if (isset($exclusion_words[$tag])) {
                    $excluded_tags[] = $tag;
                } else {
                    $not_excluded_tags[] = $tag;
                }
                
                // Kategorisierung nach Länge
                $tag_length = mb_strlen($tag, 'UTF-8');
                if ($tag_length <= 2) {
                    $short_tags[] = $tag;
                } elseif ($tag_length >= 15) {
                    $long_tags[] = $tag;
                }
            }
            
            // Nach Häufigkeit sortieren für Top-Tags (nur für die Anzeige der Top 10)
            arsort($tag_counts);
            $top_frequent_tags = array_slice($tag_counts, 0, 10, true);
            
            // Prozentuale Exklusionsrate berechnen
            $exclusion_percentage = $unique_tags > 0 ? 
                round((count($excluded_tags) / $unique_tags) * 100, 1) : 0;
            
            $processing_time = round(microtime(true) - $start_time, 2);
            
            // ✅ Schritt 5: ALLE Tags senden (keine Limitierung!)
            $result = array(
                'message' => 'Tag-Analyse erfolgreich abgeschlossen',
                'statistics' => array(
                    'total_fahrplaene' => count($tag_rows),
                    'total_tags' => $total_tags,
                    'total_unique_tags' => $unique_tags,
                    'excluded_count' => count($excluded_tags),
                    'not_excluded_count' => count($not_excluded_tags),
                    'exclusion_percentage' => $exclusion_percentage,
                    'exclusion_list_size' => count($exclusion_words)
                ),
                'analysis' => array(
                    // ALLE Tags senden, nicht nur Top 20 oder 100!
                    'excluded_tags' => $excluded_tags,  // ALLE ausgeschlossenen Tags
                    'not_excluded_tags' => $not_excluded_tags,  // ALLE nicht ausgeschlossenen Tags
                    'excluded_tags_total' => count($excluded_tags),
                    'not_excluded_tags_total' => count($not_excluded_tags),
                    'top_frequent_tags' => $top_frequent_tags,
                    'short_tags' => array_slice($short_tags, 0, 10),
                    'long_tags' => array_slice($long_tags, 0, 10)
                ),
                'processing_time' => $processing_time
            );
            
            error_log('FAHRPLANPORTAL: Tag-Analyse abgeschlossen');
            error_log('FAHRPLANPORTAL: Sende ' . count($not_excluded_tags) . ' nicht ausgeschlossene Tags');
            error_log('FAHRPLANPORTAL: Sende ' . count($excluded_tags) . ' ausgeschlossene Tags');
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Fehler bei Tag-Analyse: ' . $e->getMessage());
            wp_send_json_error('Fehler bei der Tag-Analyse: ' . $e->getMessage());
        }
    }
}