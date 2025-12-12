<?php
/**
 * Fahrplanportal Frontend Shortcode
 * MIT Settings-Integration:
 * - hide_results_until_search
 * - disable_validity_check
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
        $this->debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('FAHRPLANPORTAL_DEBUG') && FAHRPLANPORTAL_DEBUG;
        
        add_shortcode('fahrplanportal', array($this, 'render_shortcode'));
        add_action('init', array($this, 'register_unified_frontend_handlers'), 15);
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }
    
    private function debug_log($message) {
        if ($this->debug_enabled) {
            error_log($message);
        }
    }
    
    /**
     * Settings-Wert abrufen
     */
    private function get_setting($key, $default = null) {
        $settings = get_option('fahrplanportal_settings', array());
        
        $defaults = array(
            'hide_results_until_search' => 1,
            'disable_validity_check' => 0,
        );
        
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        
        if (isset($defaults[$key])) {
            return $defaults[$key];
        }
        
        return $default;
    }
    
    public function register_unified_frontend_handlers() {
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        if (!class_exists('UnifiedAjaxSystem')) {
            return;
        }
        
        $unified_system = UnifiedAjaxSystem::getInstance();
        
        if (!$unified_system) {
            return;
        }
        
        $unified_system->register_module('fahrplanportal_frontend', array(
            'search' => array($this, 'unified_frontend_search'),
            'autocomplete' => array($this, 'unified_frontend_autocomplete'),
        ));
    }
    
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
                    $GLOBALS['fahrplan_search_logger']->log_search($search_log_term, $result_count, 'frontend_search');
                } catch (Exception $e) {
                    // Ignore
                }
            }
        }
        
        if (empty($results)) {
            wp_send_json_success(array('count' => 0, 'html' => ''));
            return;
        }
        
        $html = '';
        foreach ($results as $fahrplan) {
            $html .= $this->render_frontend_fahrplan_item($fahrplan);
        }
        
        wp_send_json_success(array('count' => count($results), 'html' => $html));
    }
    
    public function unified_frontend_autocomplete() {
        $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        
        if (strlen($search_term) < 2) {
            wp_send_json_success(array('suggestions' => array()));
            return;
        }
        
        global $wpdb;
        
        $live_pdf_parsing = $this->has_tags_column();
        $search_param = '%' . $wpdb->esc_like($search_term) . '%';
        
        $word_frequency = array();
        
        $linien_neu = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT linie_neu FROM {$this->table_name} WHERE linie_neu LIKE %s AND linie_neu != ''",
            $search_param
        ));
        
        foreach ($linien_neu as $linie) {
            $linien_array = array_map('trim', explode(',', $linie));
            foreach ($linien_array as $einzelne_linie) {
                if (stripos($einzelne_linie, $search_term) !== false) {
                    $key = strtolower($einzelne_linie);
                    if (!isset($word_frequency[$key])) {
                        $word_frequency[$key] = array('word' => $einzelne_linie, 'count' => 0, 'source' => 'Linie');
                    }
                    $word_frequency[$key]['count']++;
                }
            }
        }
        
        $titles = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT titel FROM {$this->table_name} WHERE titel LIKE %s",
            $search_param
        ));
        
        foreach ($titles as $title) {
            $words = preg_split('/[\s\-\/]+/', $title);
            foreach ($words as $word) {
                $word = trim($word, '.,;:()[]');
                if (strlen($word) >= 3 && stripos($word, $search_term) !== false) {
                    $key = strtolower($word);
                    if (!isset($word_frequency[$key])) {
                        $word_frequency[$key] = array('word' => $word, 'count' => 0, 'source' => 'Route');
                    }
                    $word_frequency[$key]['count']++;
                }
            }
        }
        
        if ($live_pdf_parsing) {
            $tags_results = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT tags FROM {$this->table_name} WHERE tags LIKE %s AND tags != ''",
                $search_param
            ));
            
            foreach ($tags_results as $tags_string) {
                $tags_array = array_map('trim', explode(',', $tags_string));
                foreach ($tags_array as $tag) {
                    if (strlen($tag) >= 2 && stripos($tag, $search_term) !== false) {
                        $key = strtolower($tag);
                        if (!isset($word_frequency[$key])) {
                            $word_frequency[$key] = array('word' => $tag, 'count' => 0, 'source' => 'Haltestelle');
                        }
                        $word_frequency[$key]['count']++;
                    }
                }
            }
        }
        
        $suggestions = array();
        $priority_order = array('Linie' => 3, 'Route' => 2, 'Haltestelle' => 1);
        
        usort($word_frequency, function($a, $b) use ($priority_order) {
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
        
        $max_suggestions = 8;
        $count = 0;
        
        foreach ($word_frequency as $word_data) {
            if ($count >= $max_suggestions) break;
            
            $suggestions[] = array(
                'text' => $word_data['word'],
                'context' => $word_data['source'] . ' (' . $word_data['count'] . 'x)',
                'full_text' => $word_data['word']
            );
            
            $count++;
        }
        
        wp_send_json_success(array('suggestions' => $suggestions));
    }
    
    /**
     * Zentrale Suchmethode MIT disable_validity_check Setting
     */
    private function get_fahrplaene_by_criteria($region = '', $search_text = '', $max_results = 100) {
        global $wpdb;
        
        $live_pdf_parsing = $this->has_tags_column();
        
        // Setting: Gültigkeitspruefung deaktivieren?
        $disable_validity_check = (bool) $this->get_setting('disable_validity_check', 0);
        
        $where_conditions = array();
        $query_params = array();
        
        // Gültigkeitspruefung NUR wenn NICHT deaktiviert
        if (!$disable_validity_check) {
            $today = date('Y-m-d');
            $where_conditions[] = "(gueltig_von IS NULL OR gueltig_von <= %s)";
            $where_conditions[] = "(gueltig_bis IS NULL OR gueltig_bis >= %s)";
            $query_params[] = $today;
            $query_params[] = $today;
        }

        if (!empty($region) && !empty($search_text)) {
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
            $field_count = $live_pdf_parsing ? 5 : 4;
            for ($i = 0; $i < $field_count; $i++) {
                $query_params[] = $search_param;
            }
        } elseif (!empty($region)) {
            $where_conditions[] = "region = %s";
            $query_params[] = $region;
        } elseif (!empty($search_text)) {
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
            $field_count = $live_pdf_parsing ? 5 : 4;
            for ($i = 0; $i < $field_count; $i++) {
                $query_params[] = $search_param;
            }
        }
        
        $query_params[] = $max_results;
        
        // Query bauen - mit oder ohne WHERE je nach Bedingungen
        if (!empty($where_conditions)) {
            $query = "SELECT * FROM {$this->table_name} WHERE " . implode(" AND ", $where_conditions) . " ORDER BY linie_neu ASC, titel ASC LIMIT %d";
        } else {
            $query = "SELECT * FROM {$this->table_name} ORDER BY linie_neu ASC, titel ASC LIMIT %d";
        }
        
        $results = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        return $results ?: array();
    }
    
    private function format_validity_period($gueltig_von, $gueltig_bis) {
        if (empty($gueltig_von) && empty($gueltig_bis)) {
            return '';
        }
        
        $von_formatted = $this->format_date_german($gueltig_von);
        $bis_formatted = $this->format_date_german($gueltig_bis);
        
        if ($von_formatted && $bis_formatted) {
            return "Gültig: $von_formatted - $bis_formatted";
        } elseif ($von_formatted) {
            return "Gültig ab: $von_formatted";
        } elseif ($bis_formatted) {
            return "Gültig bis: $bis_formatted";
        }
        
        return '';
    }
    
    private function format_date_german($date) {
        if (empty($date)) return '';
        
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        
        return date('d.m.Y', $timestamp);
    }
    
    private function render_frontend_fahrplan_item($fahrplan) {
        $pdf_url = site_url('fahrplaene/' . $fahrplan->pdf_pfad);
        $validity_text = $this->format_validity_period($fahrplan->gueltig_von, $fahrplan->gueltig_bis);
        
        ob_start();
        ?>
        <a href="<?php echo esc_url($pdf_url); ?>"
           aria-label="PDF herunterladen fuer Linie <?php echo esc_html($fahrplan->linie_neu); ?>"
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
                    
                    <div class="fahrplanportal-title" role="text" data-not-heading="true">
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
    }
    
    public function ensure_direct_config() {
        global $post;
        
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'fahrplanportal')) {
            ?>
            <script type="text/javascript">
            if (typeof fahrplanportal_direct === "undefined") {
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
    
    /**
     * SHORTCODE RENDER-FUNKTION MIT SETTINGS
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
        
        $predefined_region = !empty($atts['region']);
        $show_filters = $atts['show_filters'] === 'auto' ? !$predefined_region : ($atts['show_filters'] === 'true' || $atts['show_filters'] === '1');
        
        // Settings-Wert: Ergebnisbereich erst nach Filtersuche anzeigen
        $hide_results_until_search = false;
        if (!$predefined_region) {
            $hide_results_until_search = (bool) $this->get_setting('hide_results_until_search', 1);
        }
        
        $initial_results = array();
        if ($predefined_region) {
            $initial_results = $this->get_fahrplaene_by_criteria($atts['region'], '', $atts['max_results']);
            if (is_wp_error($initial_results)) {
                $initial_results = array();
            }
        }
        
        $last_update = $this->get_last_update_date();
        
        ob_start();
        ?>
        <div class="fahrplanportal-frontend" 
             id="<?php echo esc_attr($unique_id); ?>" 
             data-max-results="<?php echo esc_attr($atts['max_results']); ?>"
             data-predefined-region="<?php echo esc_attr($atts['region']); ?>"
             data-show-filters="<?php echo $show_filters ? 'true' : 'false'; ?>"
             data-hide-results-until-search="<?php echo $hide_results_until_search ? 'true' : 'false'; ?>">
            
            <?php if ($last_update && !$predefined_region): ?>
            <div class="fahrplanportal-update-info mb-3">
                <small style="display:block;font-size: 0.85em;text-align: <?php echo $show_filters ? 'right' : 'center'; ?>;" class="text-muted">
                    <i class="fa-solid fa-arrows-rotate"></i> 
                    Letzte Aktualisierung: <i class="fa-regular fa-clock"></i> <?php echo esc_html($last_update); ?>
                </small>
            </div>
            <?php endif; ?>
            
            <?php if ($show_filters): ?>
            <div class="fahrplanportal-filters">
                <div class="row g-3 align-items-end">
                    
                    <div class="col-md-4">
                        <label for="<?php echo esc_attr($unique_id); ?>-region" class="form-label">
                            Nach Region filtern:
                        </label>
                        <select class="form-select fahrplanportal-region-filter" 
                                id="<?php echo esc_attr($unique_id); ?>-region">
                            <option value="">Region wählen</option>
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
                            <i class="fas fa-redo me-2"></i>Filter zuruecksetzen
                        </button>
                    </div>
                    
                </div>
            </div>
            <?php endif; ?>
            
            <div class="fahrplanportal-results mt-4" <?php echo ($hide_results_until_search && !$predefined_region) ? 'style="display: none;"' : ''; ?>>
                
                <?php if (!$predefined_region && !$hide_results_until_search): ?>
                <div class="fahrplanportal-empty-state">
                    <div class="empty-state-content">
                        <div class="empty-state-icon">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/>
                            </svg>
                        </div>
                        <div class="empty-state-title" role="text" data-not-heading="true">Filter verwenden</div>
                        <p class="text-muted">
                            Bitte wählen Sie eine Region oder geben Sie einen Suchbegriff ein, um Fahrpläne anzuzeigen.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="fahrplanportal-loading d-none">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Wird geladen...</span>
                        </div>
                        <p class="mt-2 text-muted">Suche läuft...</p>
                    </div>
                </div>
                
                <div class="fahrplanportal-no-results <?php echo empty($initial_results) && $predefined_region ? '' : 'd-none'; ?>">
                    <div class="text-center py-4">
                        <div class="text-muted mb-2">
                            <i class="fas fa-search fa-2x"></i>
                        </div>
                        <div role="text" data-not-heading="true">Keine Ergebnisse</div>
                        <p class="text-muted">
                            <?php if ($predefined_region): ?>
                                Fuer die Region "<?php echo esc_html($atts['region']); ?>" wurden keine Fahrpläne gefunden.
                            <?php else: ?>
                                Ihre Suche hat kein Ergebnis erzielt.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div class="fahrplanportal-results-list <?php echo empty($initial_results) ? 'd-none' : ''; ?>">
                    <?php if ($show_filters): ?>
                    <div class="results-header mb-3">
                        <p class="lead">Gefundene Fahrpläne <span class="badge bg-primary fahrplanportal-count"><?php echo count($initial_results); ?></span></p>
                    </div>
                    <?php endif; ?>
                    <div class="results-container">
                        <?php 
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
        $this->render_javascript_config($unique_id, $atts['max_results'], $predefined_region, $atts['region'], $hide_results_until_search);
        
        return ob_get_clean();
    }
    
    private function render_javascript_config($unique_id, $max_results, $predefined_region = false, $region = '', $hide_results_until_search = false) {
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
            hideResultsUntilSearch: <?php echo $hide_results_until_search ? 'true' : 'false'; ?>,
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

global $fahrplanportal_shortcode_instance;
$fahrplanportal_shortcode_instance = new FahrplanportalShortcode();
