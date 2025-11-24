<?php
/**
 * Fahrplanportal Frontend Shortcode - ANGEPASSTE VERSION
 * ✅ BUGFIX: Region-Parameter funktioniert jetzt korrekt
 * ✅ NEU: Filter werden bei vorgegebener Region versteckt
 * ✅ NEU: Automatisches Laden der Ergebnisse bei Region-Parameter
 * ✅ NEU: Reine Ergebnisliste ohne Filter möglich
 * ✅ VERBESSERT: Intelligente Darstellung je nach Shortcode-Parametern
 * ✅ ANGEPASST: Minimale Darstellung bei vordefinierter Region
 */

if (!defined('ABSPATH')) {
    exit;
}

class FahrplanportalShortcode {
    
    private $table_name;
    private $pdf_parsing_enabled;
    private $plugin_url;
    private $debug_enabled;
    
    public function __construct() {
        global $wpdb;
        
        $this->table_name = $wpdb->prefix . 'fahrplaene';
        $this->pdf_parsing_enabled = $this->check_pdf_parser_availability();
        $this->plugin_url = plugin_dir_url(dirname(dirname(__FILE__))) . 'fahrplanportal/assets/frontend/';
        
        // Debug nur wenn WP_DEBUG aktiv UND explizit gewünscht
        $this->debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('FAHRPLANPORTAL_DEBUG') && FAHRPLANPORTAL_DEBUG;
        
        // Shortcode registrieren
        add_shortcode('fahrplanportal', array($this, 'render_shortcode'));
        
        // Unified Frontend Handler registrieren
        add_action('init', array($this, 'register_unified_frontend_handlers'), 15);
        
        // Scripts laden
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
        
        $this->debug_log('✅ FAHRPLANPORTAL SHORTCODE: Initialisiert (ANGEPASSTE VERSION)');
    }
    
    /**
     * Debug-Helper: Nur loggen wenn Debug aktiviert
     */
    private function debug_log($message) {
        if ($this->debug_enabled) {
            error_log($message);
        }
    }
    
    /**
     * Frontend Handler im Unified System registrieren
     */
    public function register_unified_frontend_handlers() {
        
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $this->debug_log('🔄 FAHRPLANPORTAL SHORTCODE: AJAX-Call erkannt, registriere Handler');
        }
        
        if (!class_exists('UnifiedAjaxSystem')) {
            $this->debug_log('❌ FAHRPLANPORTAL SHORTCODE: Unified System nicht verfügbar');
            return;
        }
        
        $unified_system = UnifiedAjaxSystem::getInstance();
        
        if (!$unified_system) {
            $this->debug_log('❌ FAHRPLANPORTAL SHORTCODE: Unified System Instanz nicht verfügbar');
            return;
        }
        
        // Frontend-Module registrieren
        $unified_system->register_module('fahrplanportal_frontend', array(
            'search' => array($this, 'unified_frontend_search'),
            'autocomplete' => array($this, 'unified_frontend_autocomplete'),
        ));
        
        $this->debug_log('✅ FAHRPLANPORTAL SHORTCODE: Frontend Handler registriert');
        error_log('🎯 FAHRPLANPORTAL: Frontend-Module verfügbar für AJAX-Calls');
    }
    
    // ========================================
    // UNIFIED FRONTEND HANDLER
    // ========================================
    
    /**
     * Frontend-Suche für Shortcode mit Gültigkeitsprüfung
     */
    public function unified_frontend_search() {
        $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
        $search_text = isset($_POST['search_text']) ? sanitize_text_field($_POST['search_text']) : '';
        $max_results = isset($_POST['max_results']) ? intval($_POST['max_results']) : 100;
        
        if (empty($region) && empty($search_text)) {
            wp_send_json_error('Kein Filter gesetzt');
            return;
        }
        
        $results = $this->get_fahrplaene_by_criteria($region, $search_text, $max_results);
        
        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
            return;
        }
        
        // Search Logging
        if (!empty($region) || !empty($search_text)) {
            $search_log_term = '';
            if (!empty($region) && !empty($search_text)) {
                $search_log_term = $search_text . ' (' . $region . ')';
            } elseif (!empty($region)) {
                $search_log_term = 'Region: ' . $region;
            } elseif (!empty($search_text)) {
                $search_log_term = $search_text;
            }
            
            if (!empty($search_log_term) && isset($GLOBALS['fahrplan_search_logger'])) {
                try {
                    $result_count = is_array($results) ? count($results) : 0;
                    $GLOBALS['fahrplan_search_logger']->log_search(
                        $search_log_term, 
                        $result_count,
                        'frontend_search'
                    );
                } catch (Exception $e) {
                    $this->debug_log('⚠️ FAHRPLANPORTAL SHORTCODE: Search logging failed - ' . $e->getMessage());
                }
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
     * Frontend-Autocomplete mit Tag-Unterstützung
     */
    public function unified_frontend_autocomplete() {
        $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        
        if (strlen($search_term) < 2) {
            wp_send_json_success(array('suggestions' => array()));
            return;
        }
        
        global $wpdb;
        
        $live_pdf_parsing = $this->has_tags_column();
        $search_param = '%' . $wpdb->esc_like($search_term) . '%';
        $suggestions = array();
        $word_frequency = array();
        
        // Liniennummern
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
        
        // Ortsnamen aus Titeln
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
        
        // Tag-Wörter (falls verfügbar)
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
        
        // Nach Häufigkeit und Relevanz sortieren
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
        
        wp_send_json_success(array('suggestions' => $suggestions));
    }
    
    // ========================================
    // ✅ NEU: ZENTRALE SUCHMETHODE
    // ========================================
    
    /**
     * ✅ NEU: Zentrale Methode zum Abrufen von Fahrplänen nach Kriterien
     * Verwendet sowohl vom AJAX-Handler als auch vom direkten Shortcode-Rendering
     * 
     * @param string $region Region zum Filtern (optional)
     * @param string $search_text Suchtext für Titel/Linien/Tags (optional)
     * @param int $max_results Maximale Anzahl Ergebnisse
     * @return array|WP_Error Array mit Fahrplan-Objekten oder WP_Error bei Fehler
     */
    private function get_fahrplaene_by_criteria($region = '', $search_text = '', $max_results = 100) {
        global $wpdb;
        
        $live_pdf_parsing = $this->has_tags_column();
        
        // AND-Logik zwischen Hauptfiltern
        $where_conditions = array();
        $query_params = array();
        
        // Gültigkeitsprüfung - nur gültige Fahrpläne anzeigen
        $today = date('Y-m-d');
        $where_conditions[] = "(gueltig_von IS NULL OR gueltig_von <= %s)";
        $where_conditions[] = "(gueltig_bis IS NULL OR gueltig_bis >= %s)";
        $query_params[] = $today;
        $query_params[] = $today;

        if (!empty($region) && !empty($search_text)) {
            // Beide Filter: Region UND Suchtext
            $search_fields = array(
                "titel LIKE %s",
                "linie_alt LIKE %s", 
                "linie_neu LIKE %s",
                "kurzbeschreibung LIKE %s"
            );
            
            if ($live_pdf_parsing) {
                $search_fields[] = "tags LIKE %s";
            }
            
            $where_conditions[] = "region = %s";
            $where_conditions[] = "(" . implode(" OR ", $search_fields) . ")";
            $query_params[] = $region;
            
            $search_param = '%' . $wpdb->esc_like($search_text) . '%';
            $search_count = count($search_fields);
            for ($i = 0; $i < $search_count; $i++) {
                $query_params[] = $search_param;
            }
            
        } elseif (!empty($region)) {
            // Nur Region
            $where_conditions[] = "region = %s";
            $query_params[] = $region;
            
        } elseif (!empty($search_text)) {
            // Nur Suchtext
            $search_fields = array(
                "titel LIKE %s",
                "linie_alt LIKE %s", 
                "linie_neu LIKE %s",
                "kurzbeschreibung LIKE %s"
            );
            
            if ($live_pdf_parsing) {
                $search_fields[] = "tags LIKE %s";
            }
            
            $where_conditions[] = "(" . implode(" OR ", $search_fields) . ")";
            
            $search_param = '%' . $wpdb->esc_like($search_text) . '%';
            $search_count = count($search_fields);
            for ($i = 0; $i < $search_count; $i++) {
                $query_params[] = $search_param;
            }
        } else {
            // Keine Filter - alle gültigen Fahrpläne (sollte normalerweise nicht passieren)
            // WHERE-Conditions enthalten bereits die Gültigkeitsprüfung
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "
            SELECT * FROM {$this->table_name} 
            WHERE {$where_clause}
            ORDER BY region ASC, linie_alt ASC, titel ASC 
            LIMIT %d
        ";
        
        $query_params[] = $max_results;

        // Debug-Ausgaben
        if ($this->debug_enabled) {
            $debug_query = $wpdb->prepare($query, $query_params);
            $this->debug_log('🔍 FAHRPLANPORTAL Query: ' . $debug_query);
            $this->debug_log('🔍 FAHRPLANPORTAL Params: ' . print_r($query_params, true));
        }
        
        $results = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        if ($wpdb->last_error) {
            $this->debug_log('❌ FAHRPLANPORTAL DB Error: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Datenbankfehler bei der Suche');
        }
        
        return $results;
    }
    
    // ========================================
    // FRONTEND HELPER-METHODEN
    // ========================================
    
    private function extract_frontend_line_numbers($search_param, $wpdb) {
        $today = date('Y-m-d');
        
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
            AND (gueltig_von IS NULL OR gueltig_von <= %s)
            AND (gueltig_bis IS NULL OR gueltig_bis >= %s)
            GROUP BY line_number
            HAVING line_number IS NOT NULL
            ORDER BY count DESC
            LIMIT 10
        ", $search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $today, $today));
        
        $lines = array();
        foreach ($results as $result) {
            $line_parts = explode(',', $result->line_number);
            foreach ($line_parts as $line) {
                $line = trim($line);
                
                if (!empty($line) && $this->is_valid_line_number($line)) {
                    $lines[] = array('line' => $line, 'count' => $result->count);
                }
            }
        }
        return $lines;
    }
    
    private function is_valid_line_number($line) {
        if (empty($line) || strlen($line) < 1) {
            return false;
        }
        
        // Rein numerische Liniennummern (2-4 stellig)
        if (preg_match('/^\d{2,4}$/', $line)) {
            return true;
        }
        
        // Buchstaben-Zahl-Kombinationen (X1, X2, X3, SB1, etc.)
        if (preg_match('/^[A-Z]{1,3}\d{1,3}$/i', $line)) {
            return true;
        }
        
        // Einzelne Buchstaben (A, B, C, etc.)
        if (preg_match('/^[A-Z]$/i', $line)) {
            return true;
        }
        
        // Spezielle Kombinationen mit Bindestrichen (Lin-1, Lin-2, etc.)
        if (preg_match('/^[A-Z]{2,5}-\d{1,3}$/i', $line)) {
            return true;
        }
        
        return false;
    }
    
    private function extract_frontend_title_words($search_param, $wpdb) {
        $today = date('Y-m-d');
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT titel, COUNT(*) as count
            FROM {$this->table_name} 
            WHERE titel LIKE %s 
            AND (gueltig_von IS NULL OR gueltig_von <= %s)
            AND (gueltig_bis IS NULL OR gueltig_bis >= %s)
            GROUP BY titel
            ORDER BY count DESC
            LIMIT 20
        ", $search_param, $today, $today));
        
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
                        if ($this->is_relevant_place_word($word)) {
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
    
    private function extract_frontend_tag_words($search_param, $wpdb, $search_term) {
        if (!$this->has_tags_column()) {
            return array();
        }
        
        $today = date('Y-m-d');
        
        $query = "SELECT tags, COUNT(*) as count FROM {$this->table_name} WHERE tags IS NOT NULL AND tags != '' AND tags LIKE %s AND (gueltig_von IS NULL OR gueltig_von <= %s) AND (gueltig_bis IS NULL OR gueltig_bis >= %s) GROUP BY tags ORDER BY count DESC LIMIT 50";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $search_param, $today, $today));
        
        if ($wpdb->last_error || empty($results)) {
            return array();
        }
        
        $words = array();
        $search_term_lower = strtolower(trim($search_term));
        
        foreach ($results as $result) {
            if (empty($result->tags)) {
                continue;
            }
            
            $tag_parts = explode(',', $result->tags);
            
            foreach ($tag_parts as $tag) {
                $tag = trim($tag);
                
                if (empty($tag) || strlen($tag) < 2) {
                    continue;
                }
                
                if (stripos($tag, $search_term_lower) === false) {
                    continue;
                }
                
                $clean_tag = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $tag);
                $clean_tag = trim($clean_tag);
                
                if (empty($clean_tag) || strlen($clean_tag) < 2) {
                    continue;
                }
                
                if (is_numeric($clean_tag)) {
                    continue;
                }
                
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
        
        uasort($words, function($a, $b) {
            if ($a['count'] !== $b['count']) {
                return $b['count'] - $a['count'];
            }
            return strcasecmp($a['word'], $b['word']);
        });
        
        $top_words = array_slice(array_values($words), 0, 15);
        
        return $top_words;
    }
    
    private function is_quality_tag($tag) {
        $tag_lower = strtolower(trim($tag));
        
        if (strlen($tag) < 3) {
            return false;
        }
        
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
        
        if (preg_match('/[äöüßÄÖÜ]/', $tag)) {
            return true;
        }
        
        if (ctype_upper($tag[0]) && ctype_alpha($tag)) {
            return true;
        }
        
        if (strpos($tag, '-') !== false && strlen($tag) >= 5) {
            return true;
        }
        
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
        
        if (strlen($tag) >= 4 && ctype_alpha($tag)) {
            return true;
        }
        
        return false;
    }
    
    private function is_relevant_place_word($word) {
        if (empty($word) || strlen($word) < 3) {
            return false;
        }
        
        $place_indicators = array(
            'dorf', 'stadt', 'berg', 'tal', 'feld', 'hof', 'kirchen', 'markt',
            'bad', 'neu', 'alt', 'groß', 'klein',
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
    // DATUMSFUNKTIONEN
    // ========================================
    
    /**
     * Formatiert Gültigkeitsdaten intelligent
     */
    private function format_validity_period($gueltig_von, $gueltig_bis) {
        if (empty($gueltig_von) && empty($gueltig_bis)) {
            return '';
        }
        
        $von_timestamp = $this->parse_date_to_timestamp($gueltig_von);
        $bis_timestamp = $this->parse_date_to_timestamp($gueltig_bis);
        
        if ($von_timestamp && !$bis_timestamp) {
            return 'Gültig ab: ' . date('d.m.Y', $von_timestamp);
        }
        
        if (!$von_timestamp && $bis_timestamp) {
            return 'Gültig bis: ' . date('d.m.Y', $bis_timestamp);
        }
        
        if ($von_timestamp && $bis_timestamp) {
            $von_jahr = date('Y', $von_timestamp);
            $bis_jahr = date('Y', $bis_timestamp);
            
            if ($von_jahr === $bis_jahr) {
                return sprintf(
                    'Gültig: %s - %s',
                    date('d.m', $von_timestamp),
                    date('d.m.Y', $bis_timestamp)
                );
            } else {
                return sprintf(
                    'Gültig: %s - %s',
                    date('d.m.Y', $von_timestamp),
                    date('d.m.Y', $bis_timestamp)
                );
            }
        }
        
        return '';
    }
    
    private function parse_date_to_timestamp($date) {
        if (empty($date) || $date === '0000-00-00') {
            return false;
        }
        
        if (is_numeric($date)) {
            return (int) $date;
        }
        
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return $timestamp;
        }
        
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $matches)) {
            $timestamp = mktime(0, 0, 0, (int)$matches[2], (int)$matches[1], (int)$matches[3]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
        
        return false;
    }
    
    /**
     * Frontend Fahrplan-Item rendern
     */
    private function render_frontend_fahrplan_item($fahrplan) {
        $pdf_url = site_url('fahrplaene/' . $fahrplan->pdf_pfad);
        $validity_text = $this->format_validity_period($fahrplan->gueltig_von, $fahrplan->gueltig_bis);
        
        ob_start();
        ?>
        <a href="<?php echo esc_url($pdf_url); ?>"
           aria-label="PDF herunterladen für Linie <?php echo esc_html($fahrplan->linie_neu); ?>"
           target="_blank" 
           class="card pdf_download fahrplanportal-item text-decoration-none text-reset mb-3"
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
                            if (!empty($validity_text)) {
                                echo esc_html($validity_text);
                            }
                            ?>
                        </div>
                        
                        <div class="fahrplanportal-region-desktop">
                            <i class="fa-regular fa-map me-2"></i>
                            <?php echo esc_html($fahrplan->region); ?>
                        </div>
                    </div>
                    
                    <div class="fahrplanportal-title" role="text" data-not-heading="true" >
                        <?php echo esc_html($fahrplan->titel); ?>
                    </div>
                    
                    <?php if (!empty($fahrplan->kurzbeschreibung)): ?>
                    <div class="fahrplanportal-description">
                        <p><?php echo esc_html($fahrplan->kurzbeschreibung); ?></p>
                    </div>
                    <?php endif; ?>
                    
                </div>
                
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
    // ASSET-LOADING UND SHORTCODE-FUNKTIONEN
    // ========================================
    
    private function check_pdf_parser_availability() {
        global $wpdb;
        
        $table_info = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'tags'");
        $has_tags_column = !empty($table_info);
        
        $has_parser_functions = function_exists('hd_process_pdf_for_words') && 
                               (class_exists('\Smalot\PdfParser\Parser') || class_exists('Parser'));
        
        if ($has_tags_column) {
            if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
                $result = $has_parser_functions;
            } else {
                $result = true;
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
    
    private function get_last_update_date() {
        global $wpdb;
        
        $last_update = $wpdb->get_var("
            SELECT MAX(updated_at) 
            FROM {$this->table_name}
        ");
        
        if ($last_update) {
            $timestamp = strtotime($last_update);
            if ($timestamp) {
                return date('d.m.Y H:i', $timestamp);
            }
        }
        
        return false;
    }
    
    // ========================================
    // ✅ ANGEPASSTE SHORTCODE RENDER-FUNKTION
    // ========================================
    
    /**
     * ✅ ANGEPASSTE SHORTCODE RENDER-FUNKTION
     * Minimale Darstellung bei vordefinierter Region
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'region' => '',
            'max_results' => 100,
            'show_tags' => 'auto',
            'show_filters' => 'auto'
        ), $atts, 'fahrplanportal');
        
        $unique_id = 'fahrplanportal-' . uniqid();
        $regions = $this->get_available_regions();
        
        // Automatisches Verhalten bei vordefinierter Region
        $predefined_region = !empty($atts['region']);
        $show_filters = $atts['show_filters'] === 'auto' ? !$predefined_region : ($atts['show_filters'] === 'true' || $atts['show_filters'] === '1');
        
        // Bei vordefinierter Region direkt Fahrpläne laden
        $initial_results = array();
        if ($predefined_region) {
            $initial_results = $this->get_fahrplaene_by_criteria($atts['region'], '', $atts['max_results']);
            if (is_wp_error($initial_results)) {
                $initial_results = array();
            }
        }
        
        // Letzte Aktualisierung ermitteln
        $last_update = $this->get_last_update_date();
        
        ob_start();
        ?>
        <div class="fahrplanportal-frontend" 
             id="<?php echo esc_attr($unique_id); ?>" 
             data-max-results="<?php echo esc_attr($atts['max_results']); ?>"
             data-predefined-region="<?php echo esc_attr($atts['region']); ?>"
             data-show-filters="<?php echo $show_filters ? 'true' : 'false'; ?>">
            
            <!-- ✅ GEÄNDERT: Aktualisierungshinweis nur anzeigen wenn KEINE Region vordefiniert ist -->
            <?php if ($last_update && !$predefined_region): ?>
            <div class="fahrplanportal-update-info mb-3">
                <small style="display:block;font-size: 0.85em;text-align: <?php echo $show_filters ? 'right' : 'center'; ?>;" class="text-muted">
                    <i class="fa-solid fa-arrows-rotate"></i> 
                    Letzte Aktualisierung: <i class="fa-regular fa-clock"></i> <?php echo esc_html($last_update); ?>
                </small>
            </div>
            <?php endif; ?>
            
            <!-- Filter nur anzeigen wenn gewünscht -->
            <?php if ($show_filters): ?>
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
            <?php endif; ?>
            
            <!-- ✅ ENTFERNT: Region-Header komplett entfernt -->
            <!-- Keine Titel-Sektion mehr für reine Ergebnisliste -->
            
            <div class="fahrplanportal-results mt-4">
                
                <!-- Empty State nur anzeigen wenn keine vordefinierte Region -->
                <?php if (!$predefined_region): ?>
                <div class="fahrplanportal-empty-state">
                    <div class="empty-state-content">
                        <div class="empty-state-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/>
                            </svg>
                        </div>
                        <div class="empty-state-title" role="text" data-not-heading="true" >Filter verwenden</div>
                        <p class="text-muted">
                            Bitte wählen Sie eine Region oder geben Sie einen Suchbegriff ein, um Fahrpläne anzuzeigen.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Loading State -->
                <div class="fahrplanportal-loading d-none">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Wird geladen...</span>
                        </div>
                        <p class="mt-2 text-muted">Suche läuft...</p>
                    </div>
                </div>
                
                <!-- No Results State -->
                <div class="fahrplanportal-no-results <?php echo empty($initial_results) && $predefined_region ? '' : 'd-none'; ?>">
                    <div class="text-center py-4">
                        <div class="text-muted mb-2">
                            <i class="fas fa-search fa-2x"></i>
                        </div>
                        <div role="text" data-not-heading="true" >Keine Ergebnisse</div>
                        <p class="text-muted">
                            <?php if ($predefined_region): ?>
                                Für die Region "<?php echo esc_html($atts['region']); ?>" wurden keine Fahrpläne gefunden.
                            <?php else: ?>
                                Ihre Suche hat kein Ergebnis erzielt.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <!-- Results List -->
                <div class="fahrplanportal-results-list <?php echo empty($initial_results) ? 'd-none' : ''; ?>">
                    <?php if ($show_filters): ?>
                    <div class="results-header mb-3">
                        <p class="lead">Gefundene Fahrpläne <span class="badge bg-primary fahrplanportal-count"><?php echo count($initial_results); ?></span></p>
                    </div>
                    <?php endif; ?>
                    <div class="results-container">
                        <?php 
                        // Initial Results direkt rendern wenn vorhanden
                        if (!empty($initial_results)) {
                            foreach ($initial_results as $fahrplan) {
                                echo $this->render_frontend_fahrplan_item($fahrplan);
                            }
                        }
                        ?>
                    </div>
                </div>
                
            </div>
            
        </div>
        
        <?php
        $this->render_javascript_config($unique_id, $atts['max_results'], $predefined_region, $atts['region']);
        
        return ob_get_clean();
    }
    
    /**
     * JavaScript-Config mit Region-Support
     */
    private function render_javascript_config($unique_id, $max_results, $predefined_region = false, $region = '') {
        ?>
        <script type="text/javascript">
        if (typeof window.fahrplanportalConfigs === 'undefined') {
            window.fahrplanportalConfigs = {};
        }
        window.fahrplanportalConfigs['<?php echo esc_js($unique_id); ?>'] = {
            uniqueId: '<?php echo esc_js($unique_id); ?>',
            maxResults: <?php echo intval($max_results); ?>,
            predefinedRegion: <?php echo $predefined_region ? 'true' : 'false'; ?>,
            region: '<?php echo esc_js($region); ?>',
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
     * Prüft ob Tag-Spalte in DB existiert
     */
    private function has_tags_column() {
        global $wpdb;
        
        static $cache_result = null;
        
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

// Global verfügbar machen für Unified System Cross-Reference
global $fahrplanportal_shortcode_instance;
$fahrplanportal_shortcode_instance = new FahrplanportalShortcode();

?>