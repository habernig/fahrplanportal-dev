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
        
        // ✅ ERWEITERT: Admin-Module (jetzt mit zweistufiger Synchronisation)
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
            'cleanup_existing_tags' => array($this, 'unified_cleanup_existing_tags'),
            'update_mapping_in_db' => array($this, 'unified_update_mapping_in_db'),
            // ✅ ERWEITERT: Zweistufige Synchronisation Handler
            'sync_table' => array($this, 'unified_sync_table'),
            'import_single_pdf' => array($this, 'unified_import_single_pdf'),
            'delete_missing_pdfs' => array($this, 'unified_delete_missing_pdfs'),
            'get_missing_pdfs' => array($this, 'unified_get_missing_pdfs'),
            'get_all_status_updates' => array($this, 'unified_get_all_status_updates'),
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

        $error_details = array();
        
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
                
                // ✅ NEU: Fehler-Details für Client sammeln
                $error_details[] = array(
                    'file' => $file_info['filename'],
                    'error' => $e->getMessage(),
                    'region' => $file_info['region'] ?? 'Unbekannte Region'
                );
                
                error_log('FAHRPLANPORTAL: Fehler bei ' . $file_info['filename'] . ' - ' . $e->getMessage());
            }
        }

        $chunk_stats['processed'] = $chunk_stats['imported'] + $chunk_stats['skipped'] + $chunk_stats['errors'];
        
        // ✅ NEU: Error-Details in Stats integrieren
        $chunk_stats['error_details'] = $error_details;
        
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
     * ✅ GEÄNDERT: Berechtigung von manage_options auf edit_posts
     */
    public function unified_recreate_db() {
        if (!current_user_can('edit_posts')) {
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
     * ✅ GEÄNDERT: Berechtigung von manage_options auf edit_posts
     */
    public function unified_clear_db() {
        if (!current_user_can('edit_posts')) {
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
     * ✅ GEÄNDERT: Berechtigung von manage_options auf edit_posts
     */
    public function unified_save_line_mapping() {
        if (!current_user_can('edit_posts')) {
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
 * ✅ ERWEITERT: Mapping-Tabelle mit Datenbank abgleichen (v4)
 * 
 * Das Mapping ist die "einzige Wahrheit":
 * - FALL A: linie_neu vorhanden → linie_alt wird aus Mapping gesetzt/korrigiert
 * - FALL B: linie_alt vorhanden, linie_neu leer → linie_neu wird aus Reverse-Mapping gesetzt
 * - FALL C: linie_alt vorhanden, linie_neu falsch → linie_neu wird aus Reverse-Mapping korrigiert
 * 
 * ✅ NEU v4: Unterstützt GV-Suffix und andere Suffixe in linie_alt
 * 
 * Bidirektionale Synchronisation ohne PDF-Neuscan
 */
public function unified_update_mapping_in_db() {
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Keine Berechtigung für DB-Abgleich');
        return;
    }
    
    error_log('FAHRPLANPORTAL: 🔄 Starte Mapping-DB-Abgleich (v4 - mit GV-Suffix Support)');
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'fahrplaene';
    
    // 1. Aktuelles Mapping laden und parsen (neu → alt)
    $mapping_array = $this->utils->get_line_mapping();

    if (empty($mapping_array)) {
        wp_send_json_error('Keine Mapping-Konfiguration gefunden oder Parsing fehlgeschlagen');
        return;
    }
    
    error_log("FAHRPLANPORTAL: ✅ " . count($mapping_array) . " Mapping-Einträge geladen (neu → alt)");
    
    // 2. Reverse-Mapping erstellen (alt → neu)
    //    ✅ NEU: Auch Basis-Nummern ohne Suffix mappen
    $reverse_mapping = array();
    $reverse_mapping_full = array(); // Speichert den vollen linie_alt Wert
    
    foreach ($mapping_array as $neu => $alt) {
        // Nur wenn alt nicht "keine" ist
        if (strtolower($alt) !== 'keine') {
            $alt_trimmed = trim($alt);
            
            // Exakter Match (z.B. "8110 GV" → 126)
            $reverse_mapping[$alt_trimmed] = $neu;
            $reverse_mapping_full[$alt_trimmed] = $alt_trimmed;
            
            // ✅ NEU: Basis-Nummer extrahieren (ohne GV, ohne Suffix)
            // Extrahiert die führende Zahl aus Strings wie "8110 GV", "8106/08 GV", "5108"
            if (preg_match('/^(\d+)/', $alt_trimmed, $matches)) {
                $basis_nummer = $matches[1];
                
                // Nur hinzufügen wenn noch nicht vorhanden (erster Match gewinnt)
                if (!isset($reverse_mapping[$basis_nummer])) {
                    $reverse_mapping[$basis_nummer] = $neu;
                    $reverse_mapping_full[$basis_nummer] = $alt_trimmed; // Speichert vollen Wert für DB-Update
                    error_log("FAHRPLANPORTAL: 📝 Reverse-Mapping Basis: '$basis_nummer' → '$neu' (voll: '$alt_trimmed')");
                }
            }
        }
    }
    
    error_log("FAHRPLANPORTAL: ✅ " . count($reverse_mapping) . " Reverse-Mapping-Einträge erstellt (inkl. Basis-Nummern)");
    
    // 3. Alle Fahrpläne aus DB laden
    $all_fahrplaene = $wpdb->get_results(
        "SELECT id, titel, linie_neu, linie_alt FROM {$table_name} ORDER BY id ASC"
    );
    
    if (empty($all_fahrplaene)) {
        wp_send_json_error('Keine Fahrpläne in der Datenbank gefunden');
        return;
    }
    
    error_log("FAHRPLANPORTAL: 📊 " . count($all_fahrplaene) . " Fahrpläne zum Abgleich gefunden");
    
    // 4. Statistik-Variablen
    $updates_performed = 0;
    $updates_failed = 0;
    $no_mapping_found = 0;
    $already_correct = 0;
    $skipped_both_empty = 0;
    $updated_linie_alt = 0;
    $updated_linie_neu = 0;
    $updated_linie_alt_with_suffix = 0;
    $conflicts_linie_alt = 0;
    $conflicts_linie_neu = 0;
    $change_details = array();
    $conflict_details = array();
    
    foreach ($all_fahrplaene as $fahrplan) {
        $id = $fahrplan->id;
        $titel = $fahrplan->titel;
        $current_linie_neu = trim($fahrplan->linie_neu ?? '');
        $current_linie_alt = trim($fahrplan->linie_alt ?? '');
        
        // ✅ Beide Felder leer → überspringen mit Zählung
        if (empty($current_linie_neu) && empty($current_linie_alt)) {
            $skipped_both_empty++;
            continue;
        }
        
        // Tracking für diesen Fahrplan
        $update_data = array();
        $fahrplan_changes = array();
        $fahrplan_is_correct = true;
        $fahrplan_no_mapping = true;
        
        // =====================================================
        // FALL A: linie_neu ist befüllt
        //         → linie_alt aus Mapping ableiten/korrigieren
        // =====================================================
        if (!empty($current_linie_neu)) {
            $expected_linie_alt = '';
            
            // Mehrere Linien in linie_neu? (kommagetrennt)
            if (strpos($current_linie_neu, ',') !== false) {
                $linie_neu_array = array_map('trim', explode(',', $current_linie_neu));
                $alte_nummern = array();
                
                foreach ($linie_neu_array as $einzelne_linie) {
                    $gemappte_alte = $this->utils->lookup_mapping($einzelne_linie, $mapping_array);
                    
                    if ($gemappte_alte !== null && strtolower($gemappte_alte) !== 'keine') {
                        $alte_nummern[] = $gemappte_alte;
                    }
                }
                
                $expected_linie_alt = implode(', ', $alte_nummern);
            } 
            // Einzelne Linie
            else {
                $gemappte_alte = $this->utils->lookup_mapping($current_linie_neu, $mapping_array);
                
                if ($gemappte_alte !== null && strtolower($gemappte_alte) !== 'keine') {
                    $expected_linie_alt = $gemappte_alte;
                }
            }
            
            // Prüfen ob Update nötig
            if (!empty($expected_linie_alt)) {
                $fahrplan_no_mapping = false;
                
                if ($expected_linie_alt !== $current_linie_alt) {
                    $fahrplan_is_correct = false;
                    $update_data['linie_alt'] = $expected_linie_alt;
                    
                    // Konflikt wenn linie_alt bereits befüllt war
                    $is_conflict = !empty($current_linie_alt);
                    if ($is_conflict) {
                        $conflicts_linie_alt++;
                        $conflict_details[] = array(
                            'id' => $id,
                            'titel' => $titel,
                            'field' => 'linie_alt',
                            'old_value' => $current_linie_alt,
                            'new_value' => $expected_linie_alt,
                            'reason' => "Mapping ($current_linie_neu → $expected_linie_alt) überschreibt"
                        );
                        error_log("FAHRPLANPORTAL: ⚠️ KONFLIKT ID $id: linie_alt '$current_linie_alt' wird zu '$expected_linie_alt'");
                    }
                    
                    $fahrplan_changes[] = array(
                        'field' => 'linie_alt',
                        'direction' => 'neu→alt',
                        'old' => $current_linie_alt,
                        'new' => $expected_linie_alt,
                        'was_conflict' => $is_conflict
                    );
                }
            }
        }
        
        // =====================================================
        // FALL B + C: linie_alt ist befüllt
        //             → linie_neu aus Reverse-Mapping ableiten
        //             → AUCH wenn linie_neu bereits (falsch) befüllt ist!
        //             ✅ NEU: Auch linie_alt mit vollem Suffix aktualisieren
        // =====================================================
        if (!empty($current_linie_alt)) {
            $expected_linie_neu = '';
            $expected_linie_alt_full = ''; // ✅ NEU: Voller Wert mit Suffix
            
            // Mehrere alte Linien? (kommagetrennt)
            if (strpos($current_linie_alt, ',') !== false) {
                $linie_alt_array = array_map('trim', explode(',', $current_linie_alt));
                $neue_nummern = array();
                $alte_nummern_full = array();
                
                foreach ($linie_alt_array as $einzelne_alte) {
                    if (isset($reverse_mapping[$einzelne_alte])) {
                        $neue_nummern[] = $reverse_mapping[$einzelne_alte];
                        $alte_nummern_full[] = $reverse_mapping_full[$einzelne_alte] ?? $einzelne_alte;
                    }
                }
                
                $expected_linie_neu = implode(', ', $neue_nummern);
                $expected_linie_alt_full = implode(', ', $alte_nummern_full);
            }
            // Einzelne alte Linie
            else {
                if (isset($reverse_mapping[$current_linie_alt])) {
                    $expected_linie_neu = $reverse_mapping[$current_linie_alt];
                    $expected_linie_alt_full = $reverse_mapping_full[$current_linie_alt] ?? $current_linie_alt;
                }
            }
            
            // Prüfen ob Update nötig für linie_neu
            if (!empty($expected_linie_neu)) {
                $fahrplan_no_mapping = false;
                
                if ($expected_linie_neu !== $current_linie_neu) {
                    $fahrplan_is_correct = false;
                    $update_data['linie_neu'] = $expected_linie_neu;
                    
                    // Konflikt wenn linie_neu bereits (falsch) befüllt war
                    $is_conflict = !empty($current_linie_neu);
                    if ($is_conflict) {
                        $conflicts_linie_neu++;
                        $conflict_details[] = array(
                            'id' => $id,
                            'titel' => $titel,
                            'field' => 'linie_neu',
                            'old_value' => $current_linie_neu,
                            'new_value' => $expected_linie_neu,
                            'reason' => "Reverse-Mapping ($current_linie_alt → $expected_linie_neu) überschreibt"
                        );
                        error_log("FAHRPLANPORTAL: ⚠️ KONFLIKT ID $id: linie_neu '$current_linie_neu' wird zu '$expected_linie_neu' (aus linie_alt '$current_linie_alt')");
                    }
                    
                    $fahrplan_changes[] = array(
                        'field' => 'linie_neu',
                        'direction' => 'alt→neu',
                        'old' => $current_linie_neu,
                        'new' => $expected_linie_neu,
                        'was_conflict' => $is_conflict
                    );
                }
                
                // ✅ NEU: Auch linie_alt mit vollem Suffix aktualisieren wenn nötig
                if (!empty($expected_linie_alt_full) && $expected_linie_alt_full !== $current_linie_alt) {
                    // Nur wenn linie_alt nicht bereits durch Fall A gesetzt wird
                    if (!isset($update_data['linie_alt'])) {
                        $update_data['linie_alt'] = $expected_linie_alt_full;
                        $updated_linie_alt_with_suffix++;
                        
                        $fahrplan_changes[] = array(
                            'field' => 'linie_alt',
                            'direction' => 'suffix-ergänzung',
                            'old' => $current_linie_alt,
                            'new' => $expected_linie_alt_full,
                            'was_conflict' => false
                        );
                        
                        error_log("FAHRPLANPORTAL: 📝 ID $id: linie_alt '$current_linie_alt' → '$expected_linie_alt_full' (Suffix ergänzt)");
                    }
                }
            }
        }
        
        // =====================================================
        // UPDATE DURCHFÜHREN (wenn nötig)
        // =====================================================
        if (!empty($update_data)) {
            $result = $wpdb->update(
                $table_name,
                $update_data,
                array('id' => $id),
                array_fill(0, count($update_data), '%s'),
                array('%d')
            );
            
            if ($result !== false) {
                $updates_performed++;
                
                // Zähler aktualisieren
                if (isset($update_data['linie_alt'])) {
                    $updated_linie_alt++;
                }
                if (isset($update_data['linie_neu'])) {
                    $updated_linie_neu++;
                }
                
                // Change-Details sammeln
                $change_details[] = array(
                    'id' => $id,
                    'titel' => $titel,
                    'changes' => $fahrplan_changes
                );
                
                error_log("FAHRPLANPORTAL: ✏️ ID $id aktualisiert: " . json_encode($update_data));
            } else {
                $updates_failed++;
                error_log("FAHRPLANPORTAL: ❌ Update fehlgeschlagen für ID $id: " . $wpdb->last_error);
            }
        } elseif ($fahrplan_is_correct && !$fahrplan_no_mapping) {
            $already_correct++;
        } elseif ($fahrplan_no_mapping) {
            $no_mapping_found++;
            error_log("FAHRPLANPORTAL: ⚠️ Kein Mapping für ID $id (linie_neu: '$current_linie_neu', linie_alt: '$current_linie_alt')");
        }
    }
    
    // 5. Ergebnis zusammenfassen
    $summary = array(
        'total_fahrplaene' => count($all_fahrplaene),
        'updates_performed' => $updates_performed,
        'updated_linie_alt' => $updated_linie_alt,
        'updated_linie_neu' => $updated_linie_neu,
        'updated_linie_alt_with_suffix' => $updated_linie_alt_with_suffix,
        'conflicts_resolved' => $conflicts_linie_alt + $conflicts_linie_neu,
        'conflicts_linie_alt' => $conflicts_linie_alt,
        'conflicts_linie_neu' => $conflicts_linie_neu,
        'updates_failed' => $updates_failed,
        'no_mapping_found' => $no_mapping_found,
        'already_correct' => $already_correct,
        'skipped_both_empty' => $skipped_both_empty,
        'mapping_entries' => count($mapping_array),
        'reverse_mapping_entries' => count($reverse_mapping),
        'change_details' => array_slice($change_details, 0, 15),
        'conflict_details' => array_slice($conflict_details, 0, 10)
    );
    
    error_log("FAHRPLANPORTAL: 🎯 Mapping-DB-Abgleich (v4) abgeschlossen:");
    error_log("FAHRPLANPORTAL:    Updates gesamt: $updates_performed");
    error_log("FAHRPLANPORTAL:    - linie_alt aktualisiert: $updated_linie_alt (davon $updated_linie_alt_with_suffix mit Suffix)");
    error_log("FAHRPLANPORTAL:    - linie_neu aktualisiert: $updated_linie_neu");
    error_log("FAHRPLANPORTAL:    Konflikte gelöst: " . ($conflicts_linie_alt + $conflicts_linie_neu));
    error_log("FAHRPLANPORTAL:    - linie_alt überschrieben: $conflicts_linie_alt");
    error_log("FAHRPLANPORTAL:    - linie_neu überschrieben: $conflicts_linie_neu");
    error_log("FAHRPLANPORTAL:    Fehlgeschlagen: $updates_failed"); 
    error_log("FAHRPLANPORTAL:    Kein Mapping: $no_mapping_found");
    error_log("FAHRPLANPORTAL:    Bereits korrekt: $already_correct");
    error_log("FAHRPLANPORTAL:    Übersprungen (beide leer): $skipped_both_empty");
    
    wp_send_json_success($summary);
}


    
    /**
     * ✅ AKTUALISIERT: Linien-Mapping laden mit flexibler Zählung
     * ✅ GEÄNDERT: Berechtigung von manage_options auf edit_posts
     */
    public function unified_load_line_mapping() {
        if (!current_user_can('edit_posts')) {
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
     * ✅ Alle Tags aus der Datenbank analysieren
     * Mit alphabetischer Sortierung der not_excluded_tags
     * 
     * Diese Funktion ersetzt die bestehende unified_analyze_all_tags() 
     * in includes/class-fahrplanportal-ajax.php
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
            
            // ✅ NEU: ALPHABETISCHE SORTIERUNG (aufsteigend, case-insensitive)
            // Sortiere excluded_tags alphabetisch
            sort($excluded_tags, SORT_STRING | SORT_FLAG_CASE);
            
            // ✅ WICHTIG: Sortiere not_excluded_tags alphabetisch aufsteigend
            sort($not_excluded_tags, SORT_STRING | SORT_FLAG_CASE);
            
            // Sortiere auch die kurzen und langen Tags alphabetisch
            sort($short_tags, SORT_STRING | SORT_FLAG_CASE);
            sort($long_tags, SORT_STRING | SORT_FLAG_CASE);
            
            // Nach Häufigkeit sortieren für Top-Tags (nur für die Anzeige der Top 10)
            arsort($tag_counts);
            $top_frequent_tags = array_slice($tag_counts, 0, 10, true);
            
            // Prozentuale Exklusionsrate berechnen
            $exclusion_percentage = $unique_tags > 0 ? 
                round((count($excluded_tags) / $unique_tags) * 100, 1) : 0;
            
            $processing_time = round(microtime(true) - $start_time, 2);
            
            // ✅ Debug-Log für Sortierung
            error_log('FAHRPLANPORTAL: not_excluded_tags alphabetisch sortiert - erste 10: ' . 
                      implode(', ', array_slice($not_excluded_tags, 0, 10)));
            
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
                    'excluded_tags' => $excluded_tags,  // ALLE ausgeschlossenen Tags (alphabetisch)
                    'not_excluded_tags' => $not_excluded_tags,  // ALLE nicht ausgeschlossenen Tags (alphabetisch)
                    'excluded_tags_total' => count($excluded_tags),
                    'not_excluded_tags_total' => count($not_excluded_tags),
                    'top_frequent_tags' => $top_frequent_tags,
                    'short_tags' => array_slice($short_tags, 0, 10),
                    'long_tags' => array_slice($long_tags, 0, 10)
                ),
                'processing_time' => $processing_time
            );
            
            error_log('FAHRPLANPORTAL: Tag-Analyse abgeschlossen');
            error_log('FAHRPLANPORTAL: Sende ' . count($not_excluded_tags) . ' nicht ausgeschlossene Tags (alphabetisch sortiert)');
            error_log('FAHRPLANPORTAL: Sende ' . count($excluded_tags) . ' ausgeschlossene Tags (alphabetisch sortiert)');
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Fehler bei Tag-Analyse: ' . $e->getMessage());
            wp_send_json_error('Fehler bei der Tag-Analyse: ' . $e->getMessage());
        }
    }

    /**
     * ✅ NEU: Bestehende Tags in Datenbank bereinigen
     * Entfernt Exklusionswörter aus allen bereits gespeicherten Tags
     */
    public function unified_cleanup_existing_tags() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        // PDF-Parsing verfügbar prüfen
        if (!$this->pdf_parsing_enabled) {
            wp_send_json_error('PDF-Parsing nicht verfügbar - Tag-Bereinigung nicht möglich');
            return;
        }
        
        $start_time = microtime(true);
        
        try {
            // ✅ Schritt 1: Exklusionsliste laden
            $exclusion_words = $this->utils->get_exclusion_words();
            
            if (empty($exclusion_words)) {
                wp_send_json_error('Exklusionsliste ist leer - nichts zu bereinigen');
                return;
            }
            
            error_log('FAHRPLANPORTAL: Tag-Cleanup gestartet mit ' . count($exclusion_words) . ' Exklusionswörtern');
            
            // ✅ Schritt 2: Alle Fahrpläne mit Tags laden
            $fahrplaene_with_tags = $this->database->get_all_fahrplaene_with_tags();
            
            if (empty($fahrplaene_with_tags)) {
                wp_send_json_success(array(
                    'message' => 'Keine Fahrpläne mit Tags gefunden',
                    'updated_fahrplaene' => 0,
                    'removed_words' => 0,
                    'exclusion_count' => count($exclusion_words),
                    'processing_time' => round(microtime(true) - $start_time, 2)
                ));
                return;
            }
            
            error_log('FAHRPLANPORTAL: ' . count($fahrplaene_with_tags) . ' Fahrpläne mit Tags gefunden');
            
            // ✅ Schritt 3: Tags bereinigen
            $updated_count = 0;
            $removed_words_total = 0;
            $batch_size = 50; // Batches für Performance
            $processed = 0;
            
            foreach ($fahrplaene_with_tags as $fahrplan) {
                if (empty($fahrplan->tags)) {
                    continue;
                }
                
                // Tags parsen (kommagetrennt)
                $original_tags = explode(',', $fahrplan->tags);
                $clean_tags = array();
                $removed_words_this_fahrplan = 0;
                
                foreach ($original_tags as $tag) {
                    $tag = trim(mb_strtolower($tag, 'UTF-8'));
                    
                    if (empty($tag)) {
                        continue;
                    }
                    
                    // Prüfen ob Tag NICHT in Exklusionsliste
                    if (!isset($exclusion_words[$tag])) {
                        $clean_tags[] = $tag;
                    } else {
                        $removed_words_this_fahrplan++;
                        $removed_words_total++;
                    }
                }
                
                // Bereinigte Tags zusammenführen
                $new_tags = implode(', ', $clean_tags);
                
                // Nur updaten wenn sich was geändert hat
                if ($new_tags !== $fahrplan->tags) {
                    $update_result = $this->database->update_fahrplan_tags($fahrplan->id, $new_tags);
                    
                    if ($update_result !== false) {
                        $updated_count++;
                        
                        // Debug-Log für erste paar Updates
                        if ($updated_count <= 3) {
                            error_log("FAHRPLANPORTAL: ID {$fahrplan->id} - {$removed_words_this_fahrplan} Wörter entfernt");
                        }
                    } else {
                        error_log("FAHRPLANPORTAL: Update-Fehler für ID {$fahrplan->id}");
                    }
                }
                
                $processed++;
                
                // Batch-Processing: Kurze Pause alle 50 Einträge
                if ($processed % $batch_size === 0) {
                    // Micro-Pause für Server-Performance
                    usleep(1000); // 1ms
                }
            }
            
            $processing_time = round(microtime(true) - $start_time, 2);
            
            error_log("FAHRPLANPORTAL: Tag-Cleanup abgeschlossen - {$updated_count} Updates, {$removed_words_total} Wörter entfernt, {$processing_time}s");
            
            // ✅ Schritt 4: Erfolgs-Response mit Statistiken
            wp_send_json_success(array(
                'message' => "Tag-Bereinigung erfolgreich abgeschlossen",
                'updated_fahrplaene' => $updated_count,
                'removed_words' => $removed_words_total,
                'total_fahrplaene' => count($fahrplaene_with_tags),
                'exclusion_count' => count($exclusion_words),
                'processing_time' => $processing_time,
                'efficiency' => $updated_count > 0 ? 
                    round(($removed_words_total / $updated_count), 1) : 0 // Durchschnitt Wörter pro Update
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Tag-Cleanup Exception: ' . $e->getMessage());
            wp_send_json_error('Fehler bei Tag-Bereinigung: ' . $e->getMessage());
        }
    }

 
    /**
     * ✅ GEFIXT: Tabelle mit physikalischen Ordnern synchronisieren (nur relevante Ordner)
     */
    public function unified_sync_table() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        error_log('FAHRPLANPORTAL: Start unified_sync_table (nur relevante Ordner)');
        
        $sync_stats = array(
            'total_db_entries' => 0,
            'status_ok' => 0,
            'status_missing' => 0,
            'status_import' => 0,
            'marked_missing' => 0,
            'marked_import' => 0,
            'already_missing' => 0,
            'errors' => 0,
            'missing_files' => array(),
            'new_files' => array()
        );
        
        try {
            // 1. Alle DB-Einträge laden
            $all_fahrplaene = $this->database->get_all_fahrplaene();
            $sync_stats['total_db_entries'] = count($all_fahrplaene);
            
            // 2. Für jeden DB-Eintrag prüfen ob PDF existiert
            foreach ($all_fahrplaene as $fahrplan) {
                $pdf_path = ABSPATH . 'fahrplaene/' . $fahrplan->pdf_pfad;
                $current_status = $fahrplan->pdf_status ?? 'OK';
                
                if (file_exists($pdf_path)) {
                    // PDF existiert - Status auf OK setzen falls es anders war
                    if ($current_status !== 'OK') {
                        $this->database->update_pdf_status($fahrplan->id, 'OK');
                        error_log('FAHRPLANPORTAL: Status korrigiert zu OK: ' . $fahrplan->pdf_pfad);
                    }
                    $sync_stats['status_ok']++;
                } else {
                    // PDF fehlt - Status auf MISSING setzen (nicht löschen!)
                    if ($current_status !== 'MISSING') {
                        $this->database->update_pdf_status($fahrplan->id, 'MISSING');
                        $sync_stats['marked_missing']++;
                        $sync_stats['missing_files'][] = array(
                            'id' => $fahrplan->id,
                            'titel' => $fahrplan->titel,
                            'pdf_pfad' => $fahrplan->pdf_pfad
                        );
                        error_log('FAHRPLANPORTAL: PDF als MISSING markiert: ' . $fahrplan->pdf_pfad);
                    } else {
                        $sync_stats['already_missing']++;
                    }
                    $sync_stats['status_missing']++;
                }
            }
            
            // ✅ GEFIXT: 3. Nur relevante Ordner aus DB für neue PDFs scannen
            $used_folders = $this->database->get_used_folders();
            error_log('FAHRPLANPORTAL: Gefundene Ordner in DB: ' . implode(', ', $used_folders));
            
            $base_path = ABSPATH . 'fahrplaene/';
            
            foreach ($used_folders as $folder_name) {
                $folder_path = $base_path . $folder_name;
                
                if (!is_dir($folder_path)) {
                    error_log('FAHRPLANPORTAL: Ordner nicht gefunden: ' . $folder_path);
                    continue;
                }
                
                error_log('FAHRPLANPORTAL: Scanne Ordner für neue PDFs: ' . $folder_name);
                
                // Alle PDFs in diesem Ordner finden
                $new_pdfs = $this->find_new_pdfs_in_folder($folder_name);
                
                foreach ($new_pdfs as $new_pdf) {
                    // Neues PDF als IMPORT markieren (wird später erstellt)
                    $sync_stats['marked_import']++;
                    $sync_stats['new_files'][] = array(
                        'filename' => $new_pdf['filename'],
                        'folder' => $folder_name,
                        'region' => $new_pdf['region'],
                        'relative_path' => $new_pdf['relative_path']
                    );
                    
                    error_log('FAHRPLANPORTAL: Neues PDF registriert: ' . $new_pdf['relative_path']);
                }
            }
            
            $sync_stats['status_import'] = $sync_stats['marked_import'];
            
            // 4. Status-Zusammenfassung laden
            $status_counts = $this->database->get_status_counts();
            $sync_stats['final_status_counts'] = $status_counts;
            
            error_log('FAHRPLANPORTAL: Synchronisation abgeschlossen - Stats: ' . json_encode($sync_stats));
            
            wp_send_json_success(array(
                'message' => 'Synchronisation erfolgreich abgeschlossen',
                'stats' => $sync_stats,
                'two_stage' => true
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Sync-Fehler: ' . $e->getMessage());
            $sync_stats['errors']++;
            wp_send_json_error('Synchronisation fehlgeschlagen: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ VERBESSERT: Hilfsfunktion - Neue PDFs in Ordner finden (mit Debug-Logging)
     */
    private function find_new_pdfs_in_folder($folder_name) {
        $new_pdfs = array();
        $base_scan_path = ABSPATH . 'fahrplaene/' . $folder_name . '/';
        
        error_log('FAHRPLANPORTAL: DEBUG - Suche neue PDFs in Ordner: ' . $folder_name);
        error_log('FAHRPLANPORTAL: DEBUG - Base scan path: ' . $base_scan_path);
        
        if (!is_dir($base_scan_path)) {
            error_log('FAHRPLANPORTAL: DEBUG - Ordner nicht gefunden: ' . $base_scan_path);
            return $new_pdfs;
        }
        
        // Verwende bestehende Parser-Funktion
        $all_files = $this->parser->collect_all_scan_files($base_scan_path, $folder_name);
        error_log('FAHRPLANPORTAL: DEBUG - Gefundene Dateien gesamt: ' . count($all_files));
        
        // Debug: Alle gefundenen Dateien loggen
        foreach ($all_files as $index => $file_info) {
            error_log('FAHRPLANPORTAL: DEBUG - Datei ' . $index . ': ' . json_encode($file_info));
        }
        
        // Prüfe welche PDFs noch nicht in DB sind
        foreach ($all_files as $file_info) {
            // ✅ VERBESSERT: Mehrere Pfad-Varianten prüfen
            $possible_paths = array();
            
            // Variante 1: Mit Region-Unterordner
            if (!empty($file_info['region'])) {
                $possible_paths[] = $folder_name . '/' . $file_info['region'] . '/' . $file_info['filename'];
            }
            
            // Variante 2: Direkt im Hauptordner
            $possible_paths[] = $folder_name . '/' . $file_info['filename'];
            
            // Variante 3: Nur Dateiname (falls alte Struktur)
            $possible_paths[] = $file_info['filename'];
            
            error_log('FAHRPLANPORTAL: DEBUG - Prüfe Pfade für ' . $file_info['filename'] . ': ' . json_encode($possible_paths));
            
            $existing = null;
            
            // Alle möglichen Pfade prüfen
            foreach ($possible_paths as $test_path) {
                $existing = $this->database->get_fahrplan_by_path($test_path);
                if ($existing) {
                    error_log('FAHRPLANPORTAL: DEBUG - Gefunden in DB mit Pfad: ' . $test_path);
                    break;
                }
            }
            
            if (!$existing) {
                // ✅ Als neues PDF markieren - verwende den wahrscheinlichsten Pfad
                $relative_path = !empty($file_info['region']) ? 
                    $folder_name . '/' . $file_info['region'] . '/' . $file_info['filename'] :
                    $folder_name . '/' . $file_info['filename'];
                
                $file_info['relative_path'] = $relative_path;
                $new_pdfs[] = $file_info;
                
                error_log('FAHRPLANPORTAL: DEBUG - Neues PDF gefunden: ' . $relative_path);
            } else {
                error_log('FAHRPLANPORTAL: DEBUG - PDF bereits in DB: ' . $file_info['filename']);
            }
        }
        
        error_log('FAHRPLANPORTAL: DEBUG - Anzahl neue PDFs in Ordner ' . $folder_name . ': ' . count($new_pdfs));
        
        return $new_pdfs;
    }


    
    
    /**
     * ✅ NEU: Einzelnes PDF importieren
     */
    public function unified_import_single_pdf() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        $pdf_path = sanitize_text_field($_POST['pdf_path'] ?? '');
        
        if (empty($pdf_path)) {
            wp_send_json_error('PDF-Pfad fehlt');
            return;
        }
        
        error_log('FAHRPLANPORTAL: Start unified_import_single_pdf für: ' . $pdf_path);
        
        try {
            $full_path = ABSPATH . 'fahrplaene/' . $pdf_path;
            
            if (!file_exists($full_path)) {
                wp_send_json_error('PDF-Datei nicht gefunden: ' . $pdf_path);
                return;
            }
            
            // Ordner und Datei-Info extrahieren
            $path_parts = explode('/', $pdf_path);
            $folder = $path_parts[0];
            $filename = basename($pdf_path);
            
            // Region bestimmen
            $region = '';
            if (count($path_parts) > 2) {
                $region = $path_parts[1];
            }
            
            // File-Info für Parser erstellen
            $file_info = array(
                'filename' => $filename,
                'filepath' => $full_path,
                'relative_path' => implode('/', array_slice($path_parts, 1)),
                'region' => $region ?: 'Hauptverzeichnis'
            );
            
            // Verwende bestehende Parser-Funktionen
            $parsed_data = $this->parser->parse_single_pdf($file_info, $folder);
            
            if ($parsed_data) {
                // In DB speichern
                $result = $this->database->insert_fahrplan($parsed_data);
                
                if ($result) {
                    error_log('FAHRPLANPORTAL: PDF erfolgreich importiert: ' . $pdf_path);
                    wp_send_json_success(array(
                        'message' => 'PDF erfolgreich importiert',
                        'data' => $parsed_data
                    ));
                } else {
                    wp_send_json_error('Fehler beim Speichern in Datenbank');
                }
            } else {
                wp_send_json_error('Fehler beim Parsen der PDF-Datei');
            }
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Import-Fehler: ' . $e->getMessage());
            wp_send_json_error('Import fehlgeschlagen: ' . $e->getMessage());
        }
    }


    /**
     * ✅ NEU: Alle als MISSING markierte PDFs endgültig löschen
     */
    public function unified_delete_missing_pdfs() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        error_log('FAHRPLANPORTAL: Start unified_delete_missing_pdfs');
        
        try {
            // Anzahl MISSING Einträge vor dem Löschen
            $status_counts = $this->database->get_status_counts();
            $missing_count = $status_counts['MISSING'];
            
            if ($missing_count === 0) {
                wp_send_json_success(array(
                    'message' => 'Keine fehlenden PDFs zum Löschen gefunden',
                    'deleted_count' => 0
                ));
                return;
            }
            
            // Löschen ausführen
            $deleted_count = $this->database->delete_missing_fahrplaene();
            
            if ($deleted_count !== false) {
                error_log("FAHRPLANPORTAL: {$deleted_count} fehlende PDFs gelöscht");
                
                wp_send_json_success(array(
                    'message' => "Erfolgreich {$deleted_count} fehlende PDF-Einträge gelöscht",
                    'deleted_count' => $deleted_count
                ));
            } else {
                wp_send_json_error('Fehler beim Löschen der fehlenden PDFs');
            }
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: delete_missing_pdfs Fehler: ' . $e->getMessage());
            wp_send_json_error('Löschen fehlgeschlagen: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ NEU: Liste aller MISSING PDFs abrufen
     */
    public function unified_get_missing_pdfs() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        global $wpdb;
        
        try {
            $missing_pdfs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, titel, pdf_pfad, dateiname, region, jahr 
                     FROM {$wpdb->prefix}fahrplaene
                     WHERE pdf_status = %s 
                     ORDER BY jahr DESC, region ASC, titel ASC",
                    'MISSING'
                )
            );
            
            wp_send_json_success(array(
                'missing_pdfs' => $missing_pdfs,
                'count' => count($missing_pdfs)
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: get_missing_pdfs Fehler: ' . $e->getMessage());
            wp_send_json_error('Fehler beim Laden der fehlenden PDFs: ' . $e->getMessage());
        }
    }

    /**
     * ✅ NEU: Alle aktuellen Status-Daten für JavaScript laden
     */
    public function unified_get_all_status_updates() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        error_log('FAHRPLANPORTAL: Start unified_get_all_status_updates');
        
        try {
            // Alle Fahrpläne mit aktuellem Status laden
            $all_fahrplaene = $this->database->get_all_fahrplaene();
            $status_data = array();
            
            foreach ($all_fahrplaene as $fahrplan) {
                $status_data[$fahrplan->id] = $fahrplan->pdf_status ?? 'OK';
            }
            
            wp_send_json_success(array(
                'status_data' => $status_data,
                'total_count' => count($all_fahrplaene),
                'message' => 'Status-Daten erfolgreich geladen'
            ));
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Fehler in get_all_status_updates: ' . $e->getMessage());
            wp_send_json_error('Fehler beim Laden der Status-Daten: ' . $e->getMessage());
        }
    }

}