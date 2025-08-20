<?php
/**
 * Fahrplanportal Frontend Shortcode - PRODUCTION VERSION
 * Frontend-Funktionen von fahrplanportal.php hierher verschoben
 * 
 * ✅ NEU: Unified Frontend-Funktionen von fahrplanportal.php übernommen
 * ✅ DEAKTIVIERT: Redundante Direct-AJAX-Funktionen auskommentiert
 * ✅ BEIBEHALTEN: Asset-Loading und Shortcode-Registrierung
 * ✅ VERBESSERT: Intelligente Datumsdarstellung hinzugefügt
 * ✅ ERWEITERT: Tag-Extraktion für Autocomplete implementiert
 * ✅ PRODUCTION: Debug-Ausgaben entfernt, Live-Tag-Detection beibehalten
 * ✅ NEU: Search Logging Integration hinzugefügt
 */

if (!defined('ABSPATH')) {
    exit;
}

class FahrplanportalShortcode {
    
    private $table_name;
    private $pdf_parsing_enabled;
    private $plugin_url;
    
    // ✅ Debug-Konstante für Entwicklung (false = Production)
    private $debug_enabled;
    
    public function __construct() {
        global $wpdb;
        
        $this->table_name = $wpdb->prefix . 'fahrplaene';
        $this->pdf_parsing_enabled = $this->check_pdf_parser_availability();
        $this->plugin_url = plugin_dir_url(dirname(dirname(__FILE__))) . 'fahrplanportal/assets/frontend/';
        
        // ✅ Debug nur wenn WP_DEBUG aktiv UND explizit gewünscht
        $this->debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('FAHRPLANPORTAL_DEBUG') && FAHRPLANPORTAL_DEBUG;
        
        // Shortcode registrieren
        add_shortcode('fahrplanportal', array($this, 'render_shortcode'));
        
        // ✅ NEU: Unified Frontend Handler registrieren
        add_action('admin_init', array($this, 'register_unified_frontend_handlers'), 25);
        
        // Scripts laden
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
        
        $this->debug_log('✅ FAHRPLANPORTAL SHORTCODE: Initialisiert (Production mit Tag-Extraktion)');
    }
    
    /**
     * ✅ Debug-Helper: Nur loggen wenn Debug aktiviert
     */
    private function debug_log($message) {
        if ($this->debug_enabled) {
            error_log($message);
        }
    }
    
    /**
     * ✅ NEU: Frontend Handler im Unified System registrieren
     */
    public function register_unified_frontend_handlers() {
        // Nur bei Admin + AJAX registrieren
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Unified System verfügbar?
        if (!class_exists('UnifiedAjaxSystem')) {
            $this->debug_log('❌ FAHRPLANPORTAL SHORTCODE: Unified System nicht verfügbar');
            return;
        }
        
        $unified_system = UnifiedAjaxSystem::getInstance();
        
        if (!$unified_system) {
            $this->debug_log('❌ FAHRPLANPORTAL SHORTCODE: Unified System Instanz nicht verfügbar');
            return;
        }
        
        // ✅ NEU: Frontend-Module hier registrieren (verschoben von fahrplanportal.php)
        $unified_system->register_module('fahrplanportal_frontend', array(
            'search' => array($this, 'unified_frontend_search'),
            'autocomplete' => array($this, 'unified_frontend_autocomplete'),
        ));
        
        $this->debug_log('✅ FAHRPLANPORTAL SHORTCODE: Frontend Handler im Unified System registriert');
    }
    
    // ========================================
    // ✅ NEU: UNIFIED FRONTEND HANDLER (von fahrplanportal.php verschoben)
    // ========================================
    
    /**
     * ✅ VERSCHOBEN: Frontend-Suche für Shortcode (von fahrplanportal.php)
     * ✅ ERWEITERT: Mit Search Logging Integration
     */
    public function unified_frontend_search() {
        $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
        $search_text = isset($_POST['search_text']) ? sanitize_text_field($_POST['search_text']) : '';
        $max_results = isset($_POST['max_results']) ? intval($_POST['max_results']) : 100;
        
        if (empty($region) && empty($search_text)) {
            wp_send_json_error('Kein Filter gesetzt');
            return;
        }
        
        global $wpdb;
        
        // ✅ LIVE-FIX: Tag-Spalte live prüfen statt Konstruktor-Variable verwenden
        $live_pdf_parsing = $this->has_tags_column();
        
        // AND-Logik zwischen Hauptfiltern
        $where_conditions = array();
        $query_params = array();
        
        // ✅ NEU: Such-Parameter für Logging vorbereiten
        $search_log_term = '';

        if (!empty($region) && !empty($search_text)) {
            // Beide Filter: Region UND Suchtext
            $search_log_term = $search_text . ' (' . $region . ')';
            
            $search_fields = array(
                "titel LIKE %s",
                "linie_alt LIKE %s", 
                "linie_neu LIKE %s",
                "kurzbeschreibung LIKE %s"
            );
            
            if ($live_pdf_parsing) {
                $search_fields[] = "tags LIKE %s";
            }
            
            $where_clause = "region = %s AND (" . implode(" OR ", $search_fields) . ")";
            $query_params[] = $region;
            
            $search_param = '%' . $wpdb->esc_like($search_text) . '%';
            foreach ($search_fields as $field) {
                $query_params[] = $search_param;
            }
        } elseif (!empty($region)) {
            // Nur Region
            $search_log_term = 'Region: ' . $region;
            $where_clause = "region = %s";
            $query_params[] = $region;
        } elseif (!empty($search_text)) {
            // Nur Suchtext
            $search_log_term = $search_text;
            $search_fields = array(
                "titel LIKE %s",
                "linie_alt LIKE %s", 
                "linie_neu LIKE %s",
                "kurzbeschreibung LIKE %s"
            );
            
            if ($live_pdf_parsing) {
                $search_fields[] = "tags LIKE %s";
            }
            
            $where_clause = "(" . implode(" OR ", $search_fields) . ")";
            
            $search_param = '%' . $wpdb->esc_like($search_text) . '%';
            foreach ($search_fields as $field) {
                $query_params[] = $search_param;
            }
        }
        
        $query = "
            SELECT * FROM {$this->table_name} 
            WHERE {$where_clause}
            ORDER BY region ASC, linie_alt ASC, titel ASC 
            LIMIT %d
        ";
        
        $query_params[] = $max_results;
        
        $results = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        if ($wpdb->last_error) {
            $this->debug_log('❌ FAHRPLANPORTAL SHORTCODE: DB Error - ' . $wpdb->last_error);
            wp_send_json_error('Datenbankfehler');
            return;
        }
        
        // ✅ NEU: Search Logging - Suchanfrage protokollieren
        if (!empty($search_log_term) && isset($GLOBALS['fahrplan_search_logger'])) {
            try {
                $result_count = is_array($results) ? count($results) : 0;
                $GLOBALS['fahrplan_search_logger']->log_search(
                    $search_log_term, 
                    $result_count,
                    'frontend_search'
                );
                $this->debug_log('✅ FAHRPLANPORTAL SHORTCODE: Search logged - "' . $search_log_term . '" with ' . $result_count . ' results');
            } catch (Exception $e) {
                $this->debug_log('⚠️ FAHRPLANPORTAL SHORTCODE: Search logging failed - ' . $e->getMessage());
            }
        }
        
        if (empty($results)) {
            wp_send_json_success(array('count' => 0, 'html' => ''));
            return;
        }
        
        // HTML für Ergebnisse generieren
        $html = '';
        foreach ($results as $fahrplan) {
            $html .= $this->render_frontend_fahrplan_item($fahrplan);
        }
        
        wp_send_json_success(array('count' => count($results), 'html' => $html));
    }
    
    /**
     * ✅ ERWEITERT: Frontend-Autocomplete mit Tag-Unterstützung (von fahrplanportal.php)
     * ✅ HINWEIS: Autocomplete wird NICHT geloggt (zu viele Requests)
     */
    public function unified_frontend_autocomplete() {
        $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        
        if (strlen($search_term) < 2) {
            wp_send_json_success(array('suggestions' => array()));
            return;
        }
        
        global $wpdb;
        
        // ✅ LIVE-FIX: Tag-Spalte live prüfen
        $live_pdf_parsing = $this->has_tags_column();
        
        $search_param = '%' . $wpdb->esc_like($search_term) . '%';
        $suggestions = array();
        $word_frequency = array();
        
        // 1. REGIONEN EXTRAHIEREN
        $regions = $this->extract_frontend_regions($search_param, $wpdb);
        foreach ($regions as $region_data) {
            $region = strtolower(trim($region_data['region']));
            if (strlen($region) >= 2 && stripos($region, trim($search_term)) !== false) {
                if (!isset($word_frequency[$region])) {
                    $word_frequency[$region] = array(
                        'word' => $region_data['region'],
                        'count' => 0,
                        'source' => 'Region'
                    );
                }
                $word_frequency[$region]['count'] += $region_data['count'];
            }
        }
        
        // 2. LINIENNUMMERN
        $line_numbers = $this->extract_frontend_line_numbers($search_param, $wpdb);
        foreach ($line_numbers as $line_data) {
            $line = trim($line_data['line']);
            if (stripos($line, trim($search_term)) !== false) {
                if (!isset($word_frequency[$line])) {
                    $word_frequency[$line] = array(
                        'word' => $line,
                        'count' => 0,
                        'source' => 'Linie'
                    );
                }
                $word_frequency[$line]['count'] += $line_data['count'];
            }
        }
        
        // 3. ORTSNAMEN AUS TITELN
        $title_words = $this->extract_frontend_title_words($search_param, $wpdb);
        foreach ($title_words as $word_data) {
            $word = strtolower(trim($word_data['word']));
            if (strlen($word) >= 2 && stripos($word, trim($search_term)) !== false) {
                if (!isset($word_frequency[$word])) {
                    $word_frequency[$word] = array(
                        'word' => $word_data['word'],
                        'count' => 0,
                        'source' => 'Ort'
                    );
                }
                $word_frequency[$word]['count'] += $word_data['count'];
            }
        }
        
        // ✅ LIVE-FIX: 4. TAG-WÖRTER MIT LIVE-CHECK
        if ($live_pdf_parsing) {
            $tag_words = $this->extract_frontend_tag_words($search_param, $wpdb, $search_term);
            
            foreach ($tag_words as $word_data) {
                $word = strtolower(trim($word_data['word']));
                if (strlen($word) >= 2 && stripos($word, trim($search_term)) !== false) {
                    if (!isset($word_frequency[$word])) {
                        $word_frequency[$word] = array(
                            'word' => $word_data['word'],
                            'count' => 0,
                            'source' => 'Tag'
                        );
                    }
                    $word_frequency[$word]['count'] += $word_data['count'];
                }
            }
        }
        
        // ✅ ERWEITERT: Nach Häufigkeit und Relevanz sortieren (inkl. Tags)
        uasort($word_frequency, function($a, $b) {
            $priority_order = array('Region' => 5, 'Linie' => 4, 'Ort' => 3, 'Tag' => 2, 'Inhalt' => 1);
            $a_priority = $priority_order[$a['source']] ?? 0;
            $b_priority = $priority_order[$b['source']] ?? 0;
            
            if ($a_priority !== $b_priority) {
                return $b_priority - $a_priority;
            }
            
            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }
            
            return strcasecmp($a['word'], $b['word']);
        });
        
        // Top-Vorschläge zusammenstellen
        $max_suggestions = 8;
        $count = 0;
        
        foreach ($word_frequency as $word_data) {
            if ($count >= $max_suggestions) break;
            
            $suggestions[] = array(
                'text' => $word_data['word'],
                'context' => $word_data['source'] . ' (' . $word_data['count'] . '×)',
                'full_text' => $word_data['word']
            );
            
            $count++;
        }
        
        // ✅ OPTIONAL: Autocomplete-Logging (normalerweise deaktiviert wegen zu vielen Requests)
        // Nur aktivieren für spezielle Analyse-Zwecke
        /*
        if (isset($GLOBALS['fahrplan_search_logger']) && defined('FAHRPLANPORTAL_LOG_AUTOCOMPLETE')) {
            try {
                $GLOBALS['fahrplan_search_logger']->log_search(
                    $search_term, 
                    count($suggestions),
                    'autocomplete'
                );
            } catch (Exception $e) {
                $this->debug_log('⚠️ FAHRPLANPORTAL SHORTCODE: Autocomplete logging failed - ' . $e->getMessage());
            }
        }
        */
        
        wp_send_json_success(array('suggestions' => $suggestions));
    }
    
    // ========================================
    // ✅ ERWEITERT: FRONTEND HELPER-METHODEN (mit Tag-Extraktion)
    // ========================================
    
    private function extract_frontend_regions($search_param, $wpdb) {
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT region, COUNT(*) as count
            FROM {$this->table_name} 
            WHERE region LIKE %s AND region != ''
            GROUP BY region
            ORDER BY count DESC
            LIMIT 10
        ", $search_param));
        
        return array_map(function($result) {
            return array('region' => $result->region, 'count' => $result->count);
        }, $results);
    }
    
    // ✅ GEÄNDERT: Unterstützt jetzt 2-4 stellige Nummern
    private function extract_frontend_line_numbers($search_param, $wpdb) {
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                CASE 
                    WHEN linie_alt LIKE %s THEN linie_alt
                    WHEN linie_neu LIKE %s THEN linie_neu
                END as line_number,
                COUNT(*) as count
            FROM {$this->table_name} 
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
                // ✅ GEÄNDERT: Akzeptiert jetzt 2-4 stellige Nummern
                if (!empty($line) && preg_match('/^\d{2,4}$/', $line)) {
                    $lines[] = array('line' => $line, 'count' => $result->count);
                }
            }
        }
        return $lines;
    }
    
    private function extract_frontend_title_words($search_param, $wpdb) {
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT titel, COUNT(*) as count
            FROM {$this->table_name} 
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
                    $word = trim($word, '.,!?;:-()[]{}');
                    $word = trim($word);
                    
                    if (strlen($word) >= 2 && !is_numeric($word)) {
                        if ($this->is_likely_place_name($word)) {
                            $word_lower = strtolower($word);
                            if (!isset($words[$word_lower])) {
                                $words[$word_lower] = array('word' => $word, 'count' => 0);
                            }
                            $words[$word_lower]['count'] += $result->count;
                        }
                    }
                }
            }
        }
        return array_values($words);
    }
    
    /**
     * ✅ NEU: Tag-Wörter für Frontend-Autocomplete extrahieren (Production)
     */
    private function extract_frontend_tag_words($search_param, $wpdb, $search_term) {
        // ✅ LIVE-CHECK: Tag-Spalte direkt prüfen
        if (!$this->has_tags_column()) {
            return array();
        }
        
        // Alle Einträge mit Tags holen die dem Suchbegriff entsprechen
        $query = "SELECT tags, COUNT(*) as count FROM {$this->table_name} WHERE tags IS NOT NULL AND tags != '' AND tags LIKE %s GROUP BY tags ORDER BY count DESC LIMIT 50";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $search_param));
        
        if ($wpdb->last_error || empty($results)) {
            return array();
        }
        
        $words = array();
        $search_term_lower = strtolower(trim($search_term));
        
        foreach ($results as $result) {
            if (empty($result->tags)) {
                continue;
            }
            
            // Kommagetrennte Tags in einzelne Wörter aufteilen
            $tag_parts = explode(',', $result->tags);
            
            foreach ($tag_parts as $tag) {
                $tag = trim($tag);
                
                // Leere Tags überspringen
                if (empty($tag) || strlen($tag) < 2) {
                    continue;
                }
                
                // Nur Tags die den Suchbegriff enthalten
                if (stripos($tag, $search_term_lower) === false) {
                    continue;
                }
                
                // Sonderzeichen entfernen aber deutsche Umlaute beibehalten
                $clean_tag = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $tag);
                $clean_tag = trim($clean_tag);
                
                if (empty($clean_tag) || strlen($clean_tag) < 2) {
                    continue;
                }
                
                // Numerische Tags überspringen (sind meist Liniennummern)
                if (is_numeric($clean_tag)) {
                    continue;
                }
                
                // Tag-Qualität prüfen (deutsche Begriffe bevorzugen)
                if ($this->is_quality_tag($clean_tag)) {
                    $tag_lower = strtolower($clean_tag);
                    
                    if (!isset($words[$tag_lower])) {
                        $words[$tag_lower] = array(
                            'word' => $clean_tag,
                            'count' => 0
                        );
                    }
                    
                    $words[$tag_lower]['count'] += $result->count;
                }
            }
        }
        
        // Nach Häufigkeit sortieren
        uasort($words, function($a, $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }
            return strcasecmp($a['word'], $b['word']);
        });
        
        // Nur die Top 15 Tag-Wörter zurückgeben
        $top_words = array_slice(array_values($words), 0, 15);
        
        return $top_words;
    }
    
    /**
     * ✅ NEU: Tag-Qualität prüfen (deutsche Ortsnamen und sinnvolle Begriffe)
     */
    private function is_quality_tag($tag) {
        $tag_lower = strtolower(trim($tag));
        
        // Mindestlänge
        if (strlen($tag) < 3) {
            return false;
        }
        
        // Ausschließen: Häufige uninteressante Wörter
        $excluded_words = array(
            'und', 'oder', 'der', 'die', 'das', 'ein', 'eine', 'von', 'zu', 'mit', 'auf', 'bei', 'nach', 'für',
            'ist', 'sind', 'war', 'wird', 'werden', 'haben', 'hat', 'hatte', 'auch', 'nur', 'noch', 'nicht',
            'bus', 'bahn', 'zug', 'linie', 'fahrt', 'abfahrt', 'ankunft', 'station', 'bahnhof', 'haltestelle',
            'montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonntag',
            'januar', 'februar', 'märz', 'april', 'mai', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'dezember'
        );
        
        if (in_array($tag_lower, $excluded_words)) {
            return false;
        }
        
        // Bevorzugen: Deutsche Umlaute (typisch für österreichische Ortsnamen)
        if (preg_match('/[äöüßÄÖÜ]/', $tag)) {
            return true;
        }
        
        // Bevorzugen: Großgeschriebene Wörter (Ortsnamen)
        if (ctype_upper($tag[0]) && ctype_alpha($tag)) {
            return true;
        }
        
        // Bevorzugen: Zusammengesetzte Wörter mit Bindestrich
        if (strpos($tag, '-') !== false && strlen($tag) >= 5) {
            return true;
        }
        
        // Bevorzugen: Wörter die wie Ortsnamen aussehen
        $place_indicators = array(
            'berg', 'burg', 'dorf', 'feld', 'hof', 'kirchen', 'stadt', 'markt', 'tal', 'see',
            'baden', 'sankt', 'st', 'bad', 'neu', 'alt', 'groß', 'klein', 'ober', 'unter',
            'veit', 'paul', 'georgen', 'michael', 'stefan', 'johann', 'anton'
        );
        
        foreach ($place_indicators as $indicator) {
            if (stripos($tag_lower, $indicator) !== false) {
                return true;
            }
        }
        
        // Standard: Alphabetische Begriffe ab 4 Zeichen
        if (strlen($tag) >= 4 && ctype_alpha($tag)) {
            return true;
        }
        
        return false;
    }
    
    private function is_likely_place_name($word) {
        $place_indicators = array(
            'bach', 'berg', 'dorf', 'feld', 'hof', 'kirchen', 'stadt', 'markt',
            'sankt', 'st.', 'bad', 'neu', 'alt', 'groß', 'klein',
            'veit', 'paul', 'georgen', 'michael', 'stefan'
        );
        
        $word_lower = strtolower($word);
        
        foreach ($place_indicators as $indicator) {
            if (str_ends_with($word_lower, $indicator) || str_starts_with($word_lower, $indicator)) {
                return true;
            }
        }
        
        if (ctype_upper($word[0]) && strlen($word) >= 3) {
            return true;
        }
        
        if (preg_match('/[äöüÄÖÜß]/', $word)) {
            return true;
        }
        
        return false;
    }
    
    // ========================================
    // ✅ NEU: VERBESSERTE DATUMSFUNKTIONEN
    // ========================================
    
    /**
     * Formatiert Gültigkeitsdaten intelligent
     * 
     * @param string $gueltig_von Start-Datum (Format: Y-m-d oder deutsches Format)
     * @param string $gueltig_bis End-Datum (Format: Y-m-d oder deutsches Format)
     * @return string Formatierter Gültigkeitstext
     */
    private function format_validity_period($gueltig_von, $gueltig_bis) {
        // Leere oder ungültige Datumswerte prüfen
        if (empty($gueltig_von) && empty($gueltig_bis)) {
            return '';
        }
        
        // Datumswerte normalisieren und validieren
        $von_timestamp = $this->parse_date_to_timestamp($gueltig_von);
        $bis_timestamp = $this->parse_date_to_timestamp($gueltig_bis);
        
        // Nur Start-Datum vorhanden
        if ($von_timestamp && !$bis_timestamp) {
            return 'Gültig ab: ' . date('d.m.Y', $von_timestamp);
        }
        
        // Nur End-Datum vorhanden
        if (!$von_timestamp && $bis_timestamp) {
            return 'Gültig bis: ' . date('d.m.Y', $bis_timestamp);
        }
        
        // Beide Datumswerte vorhanden
        if ($von_timestamp && $bis_timestamp) {
            $von_jahr = date('Y', $von_timestamp);
            $bis_jahr = date('Y', $bis_timestamp);
            
            // Gleiches Jahr: "Gültig: 01.01 - 31.12.2025"
            if ($von_jahr === $bis_jahr) {
                return sprintf(
                    'Gültig: %s - %s',
                    date('d.m', $von_timestamp),
                    date('d.m.Y', $bis_timestamp)
                );
            }
            // Verschiedene Jahre: "Gültig: 15.03.2025 - 03.06.2026"
            else {
                return sprintf(
                    'Gültig: %s - %s',
                    date('d.m.Y', $von_timestamp),
                    date('d.m.Y', $bis_timestamp)
                );
            }
        }
        
        return '';
    }
    
    /**
     * Hilfsfunktion: Konvertiert verschiedene Datumsformate zu Timestamp
     * 
     * @param string $date Datum in verschiedenen Formaten
     * @return int|false Timestamp oder false bei Fehler
     */
    private function parse_date_to_timestamp($date) {
        if (empty($date) || $date === '0000-00-00') {
            return false;
        }
        
        // Bereits ein Timestamp?
        if (is_numeric($date)) {
            return (int) $date;
        }
        
        // Standard-Parsing versuchen
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return $timestamp;
        }
        
        // Deutsches Format versuchen (dd.mm.yyyy)
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $matches)) {
            $timestamp = mktime(0, 0, 0, (int)$matches[2], (int)$matches[1], (int)$matches[3]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
        
        return false;
    }
    
    /**
 * ✅ VERBESSERT: Frontend Fahrplan-Item rendern mit Mobile Download-Layout
 */
private function render_frontend_fahrplan_item($fahrplan) {
    $pdf_url = site_url('fahrplaene/' . $fahrplan->pdf_pfad);
    
    // ✅ NEU: Verwende die intelligente Datumsfunktion
    $validity_text = $this->format_validity_period($fahrplan->gueltig_von, $fahrplan->gueltig_bis);
    
    ob_start();
    ?>
    <a href="<?php echo esc_url($pdf_url); ?>" 
       target="_blank" 
       class="card fahrplanportal-item text-decoration-none text-reset mb-3"
       data-fahrplan-id="<?php echo esc_attr($fahrplan->id); ?>">
       
        <div class="card-body">
            <div class="fahrplanportal-item-content">
                
                <div class="fahrplanportal-top-row">
                    <div class="fahrplanportal-badges">
                        <?php if (!empty($fahrplan->linie_neu)): ?>
                            <span class="badge bg-success fahrplan-line-badge"><?php echo esc_html($fahrplan->linie_neu); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($fahrplan->linie_alt)): ?>
                            <span class="badge bg-primary fahrplan-line-badge"><?php echo esc_html($fahrplan->linie_alt); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="fahrplanportal-validity">
                        <?php 
                        // ✅ NEU: Einfache Ausgabe der intelligent formatierten Gültigkeitsdauer
                        if (!empty($validity_text)) {
                            echo esc_html($validity_text);
                        }
                        ?>
                    </div>
                    
                    <div class="fahrplanportal-region-desktop">
                        <i class="fas fa-map-marker-alt text-primary me-2"></i>
                        <?php echo esc_html($fahrplan->region); ?>
                    </div>
                </div>
                
                <div class="fahrplanportal-title">
                    <p><?php echo esc_html($fahrplan->titel); ?></p>
                </div>
                
                <?php if (!empty($fahrplan->kurzbeschreibung)): ?>
                <div class="fahrplanportal-description">
                    <p><?php echo esc_html($fahrplan->kurzbeschreibung); ?></p>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- ✅ NEU: Mobile Region + Download Row -->
            <div class="fahrplanportal-mobile-bottom">
                <div class="fahrplanportal-region-mobile">
                    <i class="fas fa-map-marker-alt text-primary me-2"></i>
                    <?php echo esc_html($fahrplan->region); ?>
                </div>
                
                <div class="fahrplanportal-download">
                    <div class="fahrplan-download-btn">
                        <i class="fas fa-download"></i>
                    </div>
                </div>
            </div>
            
        </div>
        
    </a>
    <?php
    
    return ob_get_clean();
}
    
    
    // ========================================
    // ✅ BEIBEHALTEN: ASSET-LOADING UND SHORTCODE-FUNKTIONEN
    // ========================================
    
    private function check_pdf_parser_availability() {
        // ✅ FRONTEND-FIX: Prüfe zuerst ob Tag-Spalte in DB existiert
        global $wpdb;
        
        // Datenbankbasierte Prüfung (funktioniert immer)
        $table_info = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'tags'");
        $has_tags_column = !empty($table_info);
        
        // Parser-Funktionen Prüfung (nur für Admin)
        $has_parser_functions = function_exists('hd_process_pdf_for_words') && 
                               (class_exists('\Smalot\PdfParser\Parser') || class_exists('Parser'));
        
        // ✅ FRONTEND-LÖSUNG: Wenn Tag-Spalte existiert, Tags verwenden (auch ohne Parser)
        if ($has_tags_column) {
            // Im Admin: Volle Parser-Funktionalität
            if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
                $result = $has_parser_functions;
            } 
            // Im Frontend: Tag-Nutzung ohne Parser (nur für Suche/Autocomplete)
            else {
                $result = true; // Tags nutzen, auch ohne Parser
            }
        } else {
            $result = false;
        }
        
        return $result;
    }
    
    public function maybe_enqueue_assets() {
        global $post;
        
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'fahrplanportal')) {
            $this->enqueue_frontend_assets();
            add_action('wp_footer', array($this, 'ensure_direct_config'), 1);
        }
    }
    
    private function enqueue_frontend_assets() {
        wp_enqueue_script('jquery');
        
        wp_enqueue_style(
            'fahrplanportal-frontend',
            $this->plugin_url . 'fahrplanportal.css',
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'fahrplanportal-frontend',
            $this->plugin_url . 'fahrplanportal.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script('fahrplanportal-frontend', 'fahrplanportal_direct', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fahrplanportal_direct_nonce'),
            'search_action' => 'fahrplanportal_direct_search',
            'autocomplete_action' => 'fahrplanportal_direct_autocomplete',
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'context' => 'frontend_direct'
        ));
        
        $this->debug_log('✅ FAHRPLANPORTAL: Assets geladen');
    }
    
    public function ensure_direct_config() {
        global $post;
        
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'fahrplanportal')) {
            ?>
            <script type="text/javascript">
            if (typeof fahrplanportal_direct === "undefined") {
                console.log("🔧 BACKUP: Lade Fallback-Config");
                window.fahrplanportal_direct = {
                    ajax_url: "<?php echo esc_js(admin_url('admin-ajax.php')); ?>",
                    nonce: "<?php echo esc_js(wp_create_nonce('fahrplanportal_direct_nonce')); ?>",
                    search_action: "fahrplanportal_direct_search",
                    autocomplete_action: "fahrplanportal_direct_autocomplete",
                    debug: <?php echo defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false'; ?>,
                    context: "footer_backup"
                };
            }
            </script>
            <?php
        }
    }
    
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
    
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'region' => '',
            'max_results' => 100,
            'show_tags' => 'auto'
        ), $atts, 'fahrplanportal');
        
        $unique_id = 'fahrplanportal-' . uniqid();
        $regions = $this->get_available_regions();
        
        ob_start();
        ?>
        <div class="fahrplanportal-frontend" id="<?php echo esc_attr($unique_id); ?>" data-max-results="<?php echo esc_attr($atts['max_results']); ?>">
            
            <div class="fahrplanportal-filters">
                <div class="row g-3 align-items-end">
                    
                    <div class="col-md-4">
                        <label for="<?php echo esc_attr($unique_id); ?>-region" class="form-label">
                            Nach Region filtern:
                        </label>
                        <select class="form-select fahrplanportal-region-filter" 
                                id="<?php echo esc_attr($unique_id); ?>-region">
                            <option value="">Bitte Region wählen</option>
                            <?php foreach ($regions as $region): ?>
                                <option value="<?php echo esc_attr($region); ?>" 
                                        <?php selected($atts['region'], $region); ?>>
                                    <?php echo esc_html($region); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-5">
                        <label for="<?php echo esc_attr($unique_id); ?>-search" class="form-label">
                            Nach Linie oder Ort filtern:
                            <?php /* if ($this->has_tags_column()): ?>
                                <small class="text-muted">(inkl. Inhalte)</small>
                            <?php endif; */ ?>
                        </label>
                        <div class="autocomplete-wrapper">
                            <input type="text" 
                                   class="form-control fahrplanportal-text-search" 
                                   id="<?php echo esc_attr($unique_id); ?>-search"
                                   placeholder="Suchbegriff eingeben..."
                                   autocomplete="off">
                            <div class="autocomplete-dropdown" id="<?php echo esc_attr($unique_id); ?>-autocomplete"></div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <button type="button" 
                                class="btn btn-danger w-100 fahrplanportal-reset">
                            <i class="fas fa-redo me-2"></i>Filter zurücksetzen
                        </button>
                    </div>
                    
                </div>
            </div>
            
            <div class="fahrplanportal-results mt-4">
                
                <div class="fahrplanportal-empty-state">
                    <div class="empty-state-content">
                        <div class="empty-state-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/>
                            </svg>
                        </div>
                        <h5>Filter verwenden</h5>
                        <p class="text-muted">
                            Bitte wählen Sie eine Region oder geben Sie einen Suchbegriff ein, um Fahrpläne anzuzeigen.
                            <?php /* if ($this->has_tags_column()): ?>
                                <br><small><strong>Tipp:</strong> Sie können auch nach Inhalten der PDFs suchen!</small>
                            <?php endif; */ ?>
                        </p>
                    </div>
                </div>
                
                <div class="fahrplanportal-loading d-none">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Wird geladen...</span>
                        </div>
                        <p class="mt-2 text-muted">Suche läuft...</p>
                    </div>
                </div>
                
                <div class="fahrplanportal-no-results d-none">
                    <div class="text-center py-4">
                        <div class="text-muted mb-2">
                            <i class="fas fa-search fa-2x"></i>
                        </div>
                        <h5>Keine Ergebnisse</h5>
                        <p class="text-muted">Ihre Suche hat kein Ergebnis erzielt.</p>
                    </div>
                </div>
                
                <div class="fahrplanportal-results-list d-none">
                    <div class="results-header mb-3">
                        <h5>Gefundene Fahrpläne <span class="badge bg-primary fahrplanportal-count">0</span></h5>
                    </div>
                    <div class="results-container">
                        <!-- Results werden hier via AJAX eingefügt -->
                    </div>
                </div>
                
            </div>
            
        </div>
        
        <?php
        $this->render_javascript_config($unique_id, $atts['max_results']);
        
        return ob_get_clean();
    }
    
    private function render_javascript_config($unique_id, $max_results) {
        ?>
        <script type="text/javascript">
        if (typeof window.fahrplanportalConfigs === 'undefined') {
            window.fahrplanportalConfigs = {};
        }
        window.fahrplanportalConfigs['<?php echo esc_js($unique_id); ?>'] = {
            uniqueId: '<?php echo esc_js($unique_id); ?>',
            maxResults: <?php echo intval($max_results); ?>,
            debug: <?php echo defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false'; ?>
        };
        
        if (typeof window.fahrplanportalInit === 'function') {
            window.fahrplanportalInit('<?php echo esc_js($unique_id); ?>');
        }
        </script>
        <?php
    }
    
    private function get_available_regions() {
        global $wpdb;
        
        $results = $wpdb->get_col("
            SELECT DISTINCT region 
            FROM {$this->table_name} 
            WHERE region != '' 
            ORDER BY region ASC
        ");
        
        return array_filter($results);
    }
    
    /**
     * ✅ LIVE-HELPER: Prüft ob Tag-Spalte in DB existiert (unabhängig von Parser-Funktionen)
     */
    private function has_tags_column() {
        global $wpdb;
        
        static $cache_result = null;
        
        // Cache verwenden für Performance
        if ($cache_result !== null) {
            return $cache_result;
        }
        
        try {
            $table_info = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'tags'");
            $cache_result = !empty($table_info);
            
            return $cache_result;
        } catch (Exception $e) {
            $cache_result = false;
            return false;
        }
    }
}

// ✅ Global verfügbar machen für Unified System Cross-Reference
global $fahrplanportal_shortcode_instance;
$fahrplanportal_shortcode_instance = new FahrplanportalShortcode();

?>