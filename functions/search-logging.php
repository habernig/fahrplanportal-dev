<?php
/**
 * Fahrplanportal Search Logging Module
 * Tracking und Analyse von Suchbegriffen
 * 
 * Subpage-Namen für Chart.js Loading:
 * - fahrplanportal-search-stats (Hauptseite)
 * - fahrplanportal-search-settings (Einstellungen)
 * - fahrplanportal-search-export (Export)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanSearchLogger {
    
    private $log_table;
    private $stats_table;
    private $is_enabled;
    private $min_search_length = 3;
    private $retention_days = 180;
    private $rate_limit_minutes = 5;
    
    public function __construct() {
        global $wpdb;
        
        $this->log_table = $wpdb->prefix . 'fahrplan_search_logs';
        $this->stats_table = $wpdb->prefix . 'fahrplan_search_stats';
        
        // Einstellungen laden
        $this->is_enabled = get_option('fahrplanportal_search_logging_enabled', true);
        $this->min_search_length = get_option('fahrplanportal_search_min_length', 3);
        $this->retention_days = get_option('fahrplanportal_search_retention_days', 180);
        
        // Nur initialisieren wenn aktiviert
        if (!$this->is_enabled) {
            return;
        }
        
        // Admin-Hooks
        if (is_admin()) {
            add_action('admin_init', array($this, 'init_database'));
            add_action('admin_menu', array($this, 'add_admin_menu'), 20);
            
            // Unified AJAX Handler registrieren
            add_action('admin_init', array($this, 'register_ajax_handlers'), 30);
        }
        
        // Cron-Jobs einrichten
        add_action('fahrplanportal_hourly_maintenance', array($this, 'hourly_maintenance'));
        add_action('fahrplanportal_daily_maintenance', array($this, 'daily_maintenance'));
        
        // Cron-Schedule aktivieren falls noch nicht vorhanden
        if (!wp_next_scheduled('fahrplanportal_hourly_maintenance')) {
            wp_schedule_event(time(), 'hourly', 'fahrplanportal_hourly_maintenance');
        }
        
        if (!wp_next_scheduled('fahrplanportal_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'fahrplanportal_daily_maintenance');
        }
        
        error_log('FAHRPLAN SEARCH LOGGER: Initialisiert');
    }
    
    /**
     * Datenbank-Tabellen erstellen
     */
    public function init_database() {
        // Nur bei Admin-Requests, nicht bei AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();
        
        // Logs-Tabelle (Rohdaten)
        $sql_logs = "CREATE TABLE IF NOT EXISTS {$this->log_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            search_term VARCHAR(255) NOT NULL,
            search_term_normalized VARCHAR(255) NOT NULL,
            result_count INT(11) NOT NULL DEFAULT 0,
            search_type VARCHAR(20) NOT NULL DEFAULT 'search',
            search_date DATETIME NOT NULL,
            search_time TIME NOT NULL,
            found_results TINYINT(1) NOT NULL DEFAULT 0,
            session_hash VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_search_term (search_term),
            INDEX idx_normalized (search_term_normalized),
            INDEX idx_search_date (search_date),
            INDEX idx_session_date (session_hash, search_date)
        ) $charset_collate;";
        
        dbDelta($sql_logs);
        
        // Stats-Tabelle (Aggregierte Daten)
        $sql_stats = "CREATE TABLE IF NOT EXISTS {$this->stats_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            term VARCHAR(255) NOT NULL,
            term_normalized VARCHAR(255) NOT NULL,
            total_searches INT(11) NOT NULL DEFAULT 0,
            successful_searches INT(11) NOT NULL DEFAULT 0,
            failed_searches INT(11) NOT NULL DEFAULT 0,
            unique_sessions INT(11) NOT NULL DEFAULT 0,
            first_searched DATETIME NOT NULL,
            last_searched DATETIME NOT NULL,
            trend_7days FLOAT DEFAULT 0,
            trend_30days FLOAT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_term_unique (term_normalized),
            INDEX idx_total_searches (total_searches),
            INDEX idx_last_searched (last_searched)
        ) $charset_collate;";
        
        dbDelta($sql_stats);
        
        error_log('FAHRPLAN SEARCH LOGGER: Datenbank-Tabellen erstellt/aktualisiert');
    }
    
    /**
     * Admin-Menü hinzufügen
     */
    public function add_admin_menu() {
        // Hauptseite: Such-Statistiken
        add_submenu_page(
            'fahrplaene',
            'Such-Statistiken',
            'Such-Statistiken',
            'edit_posts',
            'fahrplanportal-search-stats',
            array($this, 'render_stats_page')
        );
        
        // Einstellungen
        add_submenu_page(
            'fahrplaene',
            'Such-Einstellungen',
            'Such-Einstellungen',
            'manage_options',
            'fahrplanportal-search-settings',
            array($this, 'render_settings_page')
        );
        
        // Export
        add_submenu_page(
            'fahrplaene',
            'Such-Export',
            'Such-Export',
            'edit_posts',
            'fahrplanportal-search-export',
            array($this, 'render_export_page')
        );
    }
    
    /**
     * AJAX Handler registrieren
     */
    public function register_ajax_handlers() {
        if (!class_exists('UnifiedAjaxSystem')) {
            error_log('FAHRPLAN SEARCH LOGGER: Unified AJAX System nicht verfügbar');
            return;
        }
        
        $unified_system = UnifiedAjaxSystem::getInstance();
        
        if (!$unified_system) {
            return;
        }
        
        // Search Logger Module registrieren
        $unified_system->register_module('fahrplanportal_search_logger', array(
            'get_dashboard_stats' => array($this, 'ajax_get_dashboard_stats'),
            'get_chart_data' => array($this, 'ajax_get_chart_data'),
            'get_top_searches' => array($this, 'ajax_get_top_searches'),
            'get_failed_searches' => array($this, 'ajax_get_failed_searches'),
            'export_data' => array($this, 'ajax_export_data'),
            'clear_old_data' => array($this, 'ajax_clear_old_data'),
            'save_settings' => array($this, 'ajax_save_settings'),
            'get_trending_searches' => array($this, 'ajax_get_trending_searches'),
            'get_search_details' => array($this, 'ajax_get_search_details'),
        ));
        
        error_log('FAHRPLAN SEARCH LOGGER: AJAX Handler registriert');
    }
    
    // ========================================
    // PUBLIC LOGGING METHODS
    // ========================================
    
    /**
     * Suchanfrage loggen (von shortcode.php aufgerufen)
     */
    public function log_search($search_term, $result_count = 0, $search_type = 'search') {
        if (!$this->is_enabled) {
            return;
        }
        
        // Bereinigung und Validierung
        $search_term = trim($search_term);
        if (strlen($search_term) < $this->min_search_length) {
            return;
        }
        
        // Normalisierung für Gruppierung
        $normalized = $this->normalize_search_term($search_term);
        
        // Session-Hash für Rate-Limiting (anonymisiert)
        $session_hash = $this->get_session_hash();
        
        // Rate-Limiting prüfen
        if ($this->is_rate_limited($session_hash, $normalized)) {
            return;
        }
        
        // In Datenbank schreiben
        global $wpdb;
        
        $data = array(
            'search_term' => $search_term,
            'search_term_normalized' => $normalized,
            'result_count' => intval($result_count),
            'search_type' => $search_type,
            'search_date' => current_time('mysql'),
            'search_time' => current_time('H:i:s'),
            'found_results' => ($result_count > 0) ? 1 : 0,
            'session_hash' => $session_hash
        );
        
        $wpdb->insert($this->log_table, $data);
        
        // Statistiken aktualisieren (async wenn möglich)
        $this->update_search_stats($normalized, $result_count > 0);
        
        error_log("FAHRPLAN SEARCH LOGGER: Logged search '$search_term' with $result_count results");
    }
    
    // ========================================
    // PRIVATE HELPER METHODS
    // ========================================
    
    /**
     * Suchbegriff normalisieren
     */
    private function normalize_search_term($term) {
        $term = mb_strtolower($term, 'UTF-8');
        $term = preg_replace('/\s+/', ' ', $term); // Multiple Leerzeichen reduzieren
        $term = trim($term);
        
        // Umlaute normalisieren (optional)
        $replacements = array(
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'ß' => 'ss'
        );
        
        // Nur wenn Einstellung aktiviert
        if (get_option('fahrplanportal_search_normalize_umlauts', false)) {
            $term = str_replace(array_keys($replacements), array_values($replacements), $term);
        }
        
        return $term;
    }
    
    /**
     * Session-Hash generieren (anonymisiert)
     */
    private function get_session_hash() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        
        // Keine IP-Adresse für besseren Datenschutz!
        $session_string = $user_agent . '|' . $accept_language . '|' . date('Y-m-d');
        
        return md5($session_string);
    }
    
    /**
     * Rate-Limiting prüfen
     */
    private function is_rate_limited($session_hash, $normalized_term) {
        global $wpdb;
        
        $minutes_ago = date('Y-m-d H:i:s', strtotime("-{$this->rate_limit_minutes} minutes"));
        
        $recent_search = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->log_table} 
            WHERE session_hash = %s 
            AND search_term_normalized = %s 
            AND search_date > %s",
            $session_hash,
            $normalized_term,
            $minutes_ago
        ));
        
        return ($recent_search > 0);
    }
    
    /**
     * Such-Statistiken aktualisieren
     */
    private function update_search_stats($normalized_term, $found_results) {
        global $wpdb;
        
        // Prüfen ob Eintrag existiert
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->stats_table} WHERE term_normalized = %s",
            $normalized_term
        ));
        
        if ($exists) {
            // Update
            $sql = $wpdb->prepare(
                "UPDATE {$this->stats_table} 
                SET total_searches = total_searches + 1,
                    successful_searches = successful_searches + %d,
                    failed_searches = failed_searches + %d,
                    last_searched = %s
                WHERE term_normalized = %s",
                $found_results ? 1 : 0,
                $found_results ? 0 : 1,
                current_time('mysql'),
                $normalized_term
            );
            
            $wpdb->query($sql);
        } else {
            // Insert
            $wpdb->insert($this->stats_table, array(
                'term' => $normalized_term,
                'term_normalized' => $normalized_term,
                'total_searches' => 1,
                'successful_searches' => $found_results ? 1 : 0,
                'failed_searches' => $found_results ? 0 : 1,
                'first_searched' => current_time('mysql'),
                'last_searched' => current_time('mysql')
            ));
        }
    }
    
    // ========================================
    // MAINTENANCE / CRON JOBS
    // ========================================
    
    /**
     * Stündliche Wartung
     */
    public function hourly_maintenance() {
        // Session-Hashes älter als 24h löschen (Datenschutz)
        $this->clean_old_session_data();
        
        // Unique Sessions in Stats aktualisieren
        $this->update_unique_sessions();
    }
    
    /**
     * Tägliche Wartung
     */
    public function daily_maintenance() {
        // Alte Logs löschen
        $this->delete_old_logs();
        
        // Trends berechnen
        $this->calculate_trends();
        
        // Optional: E-Mail-Report
        if (get_option('fahrplanportal_search_email_reports', false)) {
            $this->send_email_report();
        }
    }
    
    /**
     * Alte Logs löschen
     */
    private function delete_old_logs() {
        global $wpdb;
        
        $days_ago = date('Y-m-d', strtotime("-{$this->retention_days} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->log_table} WHERE search_date < %s",
            $days_ago
        ));
        
        if ($deleted > 0) {
            error_log("FAHRPLAN SEARCH LOGGER: Deleted $deleted old log entries");
        }
    }
    
    /**
     * Session-Daten bereinigen
     */
    private function clean_old_session_data() {
        global $wpdb;
        
        // Session-Hashes in Logs anonymisieren die älter als 24h sind
        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->log_table} 
            SET session_hash = 'anonymized' 
            WHERE search_date < %s 
            AND session_hash != 'anonymized'",
            $yesterday
        ));
    }
    
    /**
     * Unique Sessions aktualisieren
     */
    private function update_unique_sessions() {
        global $wpdb;
        
        // Für alle Terms die unique Sessions neu berechnen
        $terms = $wpdb->get_results("SELECT DISTINCT term_normalized FROM {$this->stats_table}");
        
        foreach ($terms as $term) {
            $unique_sessions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT session_hash) 
                FROM {$this->log_table} 
                WHERE search_term_normalized = %s 
                AND search_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                $term->term_normalized
            ));
            
            $wpdb->update(
                $this->stats_table,
                array('unique_sessions' => $unique_sessions),
                array('term_normalized' => $term->term_normalized)
            );
        }
    }
    
    /**
     * Trends berechnen
     */
    private function calculate_trends() {
        global $wpdb;
        
        // Alle Statistik-Einträge holen
        $stats = $wpdb->get_results("SELECT * FROM {$this->stats_table}");
        
        foreach ($stats as $stat) {
            // 7-Tage Trend
            $searches_7days_ago = $this->get_searches_in_period($stat->term_normalized, 14, 7);
            $searches_last_7days = $this->get_searches_in_period($stat->term_normalized, 7, 0);
            
            $trend_7days = 0;
            if ($searches_7days_ago > 0) {
                $trend_7days = (($searches_last_7days - $searches_7days_ago) / $searches_7days_ago) * 100;
            }
            
            // 30-Tage Trend
            $searches_30days_ago = $this->get_searches_in_period($stat->term_normalized, 60, 30);
            $searches_last_30days = $this->get_searches_in_period($stat->term_normalized, 30, 0);
            
            $trend_30days = 0;
            if ($searches_30days_ago > 0) {
                $trend_30days = (($searches_last_30days - $searches_30days_ago) / $searches_30days_ago) * 100;
            }
            
            // Update
            $wpdb->update(
                $this->stats_table,
                array(
                    'trend_7days' => $trend_7days,
                    'trend_30days' => $trend_30days
                ),
                array('id' => $stat->id)
            );
        }
    }
    
    /**
     * Suchen in Zeitraum zählen
     */
    private function get_searches_in_period($normalized_term, $days_ago_start, $days_ago_end) {
        global $wpdb;
        
        $date_start = date('Y-m-d', strtotime("-{$days_ago_start} days"));
        $date_end = date('Y-m-d', strtotime("-{$days_ago_end} days"));
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->log_table} 
            WHERE search_term_normalized = %s 
            AND search_date >= %s 
            AND search_date <= %s",
            $normalized_term,
            $date_start,
            $date_end
        ));
    }
    
    // ========================================
    // ADMIN PAGES RENDERING
    // ========================================
    
    /**
 * Statistik-Seite rendern
 */
public function render_stats_page() {
    // Quick Stats laden
    $quick_stats = $this->get_quick_stats();
    ?>
    <div class="wrap">
     
        <?php FahrplanPortal_UI_Helper::render_page_header('Fahrplan Such-Statistiken'); ?>
        
        <!-- Bootstrap Alert wenn Logging deaktiviert -->
        <?php if (!$this->is_enabled): ?>
            <div class="alert alert-warning">
                <strong>Hinweis:</strong> Das Such-Logging ist derzeit deaktiviert. 
                <a href="<?php echo admin_url('admin.php?page=fahrplanportal-search-settings'); ?>">Zu den Einstellungen</a>
            </div>
        <?php endif; ?>
        
        <!-- Quick Stats Cards (Bootstrap) -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title">Gesamte Suchen</h5>
                        <h2 class="mb-0"><?php echo number_format($quick_stats['total_searches'], 0, ',', '.'); ?></h2>
                        <small>Letzte <?php echo $this->retention_days; ?> Tage</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title">Erfolgreiche Suchen</h5>
                        <h2 class="mb-0"><?php echo $quick_stats['success_rate']; ?>%</h2>
                        <small><?php echo number_format($quick_stats['successful_searches'], 0, ',', '.'); ?> mit Ergebnissen</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h5 class="card-title">Unique Begriffe</h5>
                        <h2 class="mb-0"><?php echo number_format($quick_stats['unique_terms'], 0, ',', '.'); ?></h2>
                        <small>Verschiedene Suchbegriffe</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h5 class="card-title">Heute</h5>
                        <h2 class="mb-0"><?php echo number_format($quick_stats['searches_today'], 0, ',', '.'); ?></h2>
                        <small>Suchen heute</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Chart Container -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Suchen pro Tag (letzte 30 Tage)</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="searchesPerDayChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Erfolgsquote</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="successRateChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabs für verschiedene Ansichten -->
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-toggle="tab" href="#top-searches">Top Suchbegriffe</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#failed-searches">Erfolglose Suchen</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#trending-searches">Trending</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#time-analysis">Zeitanalyse</a>
            </li>
        </ul>
        
        <div class="tab-content mt-3">
            <!-- Top Searches Tab -->
            <div class="tab-pane fade show active" id="top-searches">
                <table id="topSearchesTable" class="table table-striped table-bordered" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Suchbegriff</th>
                            <th>Anzahl</th>
                            <th>Erfolgsquote</th>
                            <th>Trend 7 Tage</th>
                            <th>Letzte Suche</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Wird via JavaScript gefüllt -->
                    </tbody>
                </table>
            </div>
            
            <!-- Failed Searches Tab -->
            <div class="tab-pane fade" id="failed-searches">
                <div class="alert alert-info">
                    <strong>Tipp:</strong> Diese Suchbegriffe lieferten keine Ergebnisse. 
                    Prüfen Sie, ob entsprechende Fahrpläne fehlen oder die Begriffe anders geschrieben werden.
                </div>
                <table id="failedSearchesTable" class="table table-striped table-bordered" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Suchbegriff</th>
                            <th>Versuche</th>
                            <th>Erste Suche</th>
                            <th>Letzte Suche</th>
                            <th>Mögliche Alternativen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Wird via JavaScript gefüllt -->
                    </tbody>
                </table>
            </div>
            
            <!-- Trending Tab -->
            <div class="tab-pane fade" id="trending-searches">
                <div class="row">
                    <div class="col-md-6">
                        <h5>📈 Aufsteigend (7 Tage)</h5>
                        <table class="table table-sm">
                            <tbody id="trendingUpList">
                                <!-- Wird via JavaScript gefüllt -->
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>📉 Absteigend (7 Tage)</h5>
                        <table class="table table-sm">
                            <tbody id="trendingDownList">
                                <!-- Wird via JavaScript gefüllt -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Time Analysis Tab -->
            <div class="tab-pane fade" id="time-analysis">
                <h5>Suchen nach Tageszeit</h5>
                <canvas id="timeAnalysisChart" height="100"></canvas>
            </div>
        </div>
    
    <script>
    jQuery(document).ready(function($) {
        console.log('FAHRPLAN SEARCH STATS: Initialisiere Dashboard...');
        
        <?php
        // Daten direkt aus PHP laden
        global $wpdb;
        
        // Top Searches
        $top_searches = $wpdb->get_results("
            SELECT 
                term,
                total_searches,
                successful_searches,
                failed_searches,
                trend_7days,
                last_searched
            FROM {$this->stats_table}
            ORDER BY total_searches DESC
            LIMIT 25
        ");
        
        // Failed Searches
        $failed_searches = $wpdb->get_results("
            SELECT 
                term,
                failed_searches,
                first_searched,
                last_searched
            FROM {$this->stats_table}
            WHERE successful_searches = 0
            ORDER BY failed_searches DESC
            LIMIT 25
        ");
        
        // Trending Up
        $trending_up = $wpdb->get_results("
            SELECT term, trend_7days
            FROM {$this->stats_table}
            WHERE trend_7days > 20
            AND total_searches >= 5
            ORDER BY trend_7days DESC
            LIMIT 10
        ");
        
        // Trending Down
        $trending_down = $wpdb->get_results("
            SELECT term, trend_7days
            FROM {$this->stats_table}
            WHERE trend_7days < -20
            AND total_searches >= 5
            ORDER BY trend_7days ASC
            LIMIT 10
        ");
        ?>
        
        // Top Searches Daten
        var topSearchesData = <?php echo json_encode(array_map(function($row) {
            $success_rate = $row->total_searches > 0 
                ? round(($row->successful_searches / $row->total_searches) * 100, 1)
                : 0;
            return array(
                $row->term,
                $row->total_searches,
                '<span class="badge badge-' . ($success_rate >= 75 ? 'success' : ($success_rate >= 50 ? 'warning' : 'danger')) . '">' . $success_rate . '%</span>',
                $row->trend_7days > 0 
                    ? '<span class="text-success">↑ ' . number_format($row->trend_7days, 1) . '%</span>'
                    : ($row->trend_7days < 0 
                        ? '<span class="text-danger">↓ ' . number_format(abs($row->trend_7days), 1) . '%</span>'
                        : '<span class="text-muted">→ 0%</span>'),
                date('d.m.Y H:i', strtotime($row->last_searched)),
                '<button class="btn btn-sm btn-info view-details" data-term="' . esc_attr($row->term) . '">Details</button>'
            );
        }, $top_searches)); ?>;
        
        // Failed Searches Daten
        var failedSearchesData = <?php echo json_encode(array_map(function($row) {
            return array(
                $row->term,
                $row->failed_searches,
                date('d.m.Y', strtotime($row->first_searched)),
                date('d.m.Y', strtotime($row->last_searched)),
                '<span class="text-muted">-</span>'
            );
        }, $failed_searches)); ?>;
        
        // DataTables initialisieren mit Daten
        var topSearchesTable = $('#topSearchesTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/German.json"
            },
            "data": topSearchesData,
            "order": [[4, "desc"]],
            "pageLength": 50
        });
        
        var failedSearchesTable = $('#failedSearchesTable').DataTable({
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/German.json"
            },
            "data": failedSearchesData,
            "order": [[3, "desc"]],
            "pageLength": 50
        });
        
        // Tab-Funktionalität
        $('.nav-tabs a').on('click', function(e) {
            e.preventDefault();
            
            $('.nav-tabs .nav-link').removeClass('active');
            $('.tab-pane').removeClass('show active').hide();
            
            $(this).addClass('active');
            var targetId = $(this).attr('href');
            $(targetId).addClass('show active').show();
            
            // DataTables neu zeichnen wenn Tab gewechselt wird
            if (targetId === '#top-searches') {
                topSearchesTable.columns.adjust().draw();
            } else if (targetId === '#failed-searches') {
                failedSearchesTable.columns.adjust().draw();
            }
        });
        
        // Initial ersten Tab anzeigen
        $('.tab-pane').hide();
        $('.tab-pane.active').show();
        
        // Trending laden
        var upHtml = '';
        <?php foreach ($trending_up as $item): ?>
        upHtml += '<tr><td><?php echo esc_js($item->term); ?></td><td class="text-success">+<?php echo number_format($item->trend_7days, 1); ?>%</td></tr>';
        <?php endforeach; ?>
        $('#trendingUpList').html(upHtml || '<tr><td colspan="2" class="text-muted">Keine steigenden Trends</td></tr>');
        
        var downHtml = '';
        <?php foreach ($trending_down as $item): ?>
        downHtml += '<tr><td><?php echo esc_js($item->term); ?></td><td class="text-danger"><?php echo number_format($item->trend_7days, 1); ?>%</td></tr>';
        <?php endforeach; ?>
        $('#trendingDownList').html(downHtml || '<tr><td colspan="2" class="text-muted">Keine fallenden Trends</td></tr>');
        
        // Charts laden (wenn Chart.js verfügbar ist)
        if (typeof Chart !== 'undefined') {
            // Erfolgsquote Donut Chart
            var ctx1 = document.getElementById('successRateChart').getContext('2d');
            new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['Mit Ergebnissen', 'Ohne Ergebnisse'],
                    datasets: [{
                        data: [<?php echo $quick_stats['successful_searches']; ?>, <?php echo $quick_stats['total_searches'] - $quick_stats['successful_searches']; ?>],
                        backgroundColor: [
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(255, 99, 132, 0.8)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            
            // Suchen pro Tag Chart laden
            <?php
            // PHP-Code zum Abrufen der Daten für die letzten 30 Tage
            $searches_per_day = $wpdb->get_results("
                SELECT 
                    DATE(search_date) as date,
                    COUNT(*) as total_count,
                    SUM(found_results) as successful_count
                FROM {$this->log_table}
                WHERE search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(search_date)
                ORDER BY date ASC
            ");
            
            // Arrays für Chart.js vorbereiten
            $dates = array();
            $totals = array();
            $successful = array();
            
            // Alle Tage der letzten 30 Tage generieren (auch ohne Daten)
            $start_date = new DateTime('-29 days');
            $end_date = new DateTime();
            $interval = new DateInterval('P1D');
            $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));
            
            $data_by_date = array();
            foreach ($searches_per_day as $day) {
                $data_by_date[$day->date] = $day;
            }
            
            foreach ($date_range as $date) {
                $date_str = $date->format('Y-m-d');
                $dates[] = $date->format('d.m.');
                
                if (isset($data_by_date[$date_str])) {
                    $totals[] = intval($data_by_date[$date_str]->total_count);
                    $successful[] = intval($data_by_date[$date_str]->successful_count);
                } else {
                    $totals[] = 0;
                    $successful[] = 0;
                }
            }
            ?>
            
            // Suchen pro Tag Line Chart
            var ctx2 = document.getElementById('searchesPerDayChart').getContext('2d');
            var searchesPerDayChart = new Chart(ctx2, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($dates); ?>,
                    datasets: [{
                        label: 'Gesamte Suchen',
                        data: <?php echo json_encode($totals); ?>,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }, {
                        label: 'Erfolgreiche Suchen',
                        data: <?php echo json_encode($successful); ?>,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    var label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.parsed.y + ' Suchen';
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: 11
                                },
                                callback: function(value) {
                                    if (Math.floor(value) === value) {
                                        return value;
                                    }
                                }
                            },
                            grid: {
                                borderDash: [3, 3]
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
            
            // Zeit-Analyse Chart (für den Time Analysis Tab)
            <?php
            // Daten für Zeit-Analyse
            $time_analysis = $wpdb->get_results("
                SELECT 
                    HOUR(search_time) as hour,
                    COUNT(*) as count
                FROM {$this->log_table}
                WHERE search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY HOUR(search_time)
                ORDER BY hour ASC
            ");
            
            // 24 Stunden Array vorbereiten
            $hours_data = array_fill(0, 24, 0);
            foreach ($time_analysis as $hour) {
                $hours_data[$hour->hour] = intval($hour->count);
            }
            
            $hour_labels = array();
            for ($i = 0; $i < 24; $i++) {
                $hour_labels[] = sprintf('%02d:00', $i);
            }
            ?>
            
            // Event Listener für Tab-Wechsel erweitern
            $('.nav-tabs a[href="#time-analysis"]').on('shown.bs.tab', function() {
                if (!window.timeAnalysisChart) {
                    var ctx3 = document.getElementById('timeAnalysisChart').getContext('2d');
                    window.timeAnalysisChart = new Chart(ctx3, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($hour_labels); ?>,
                            datasets: [{
                                label: 'Anzahl Suchen',
                                data: <?php echo json_encode(array_values($hours_data)); ?>,
                                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                                borderColor: 'rgba(54, 162, 235, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.parsed.y + ' Suchen';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        maxRotation: 90,
                                        minRotation: 45,
                                        font: {
                                            size: 10
                                        }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1,
                                        callback: function(value) {
                                            if (Math.floor(value) === value) {
                                                return value;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            });
        }
        
        // Event Handler für Details-Button
        $(document).on('click', '.view-details', function() {
            var term = $(this).data('term');
            alert('Details für: ' + term + '\n(Modal-Implementierung folgt)');
        });
    });
    </script>
    
    <style>
        /* WordPress Admin Anpassungen für volle Breite */
        .wrap {
            margin-right: 20px !important;
            max-width: none !important;
        }

        /* Tabs volle Breite mit gleichmäßiger Verteilung */
        .nav-tabs {
            display: flex;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 0;
        }

        .nav-tabs .nav-item {
            flex: 1;
        }

        .nav-tabs .nav-link {
            cursor: pointer;
            color: #495057;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem 0.25rem 0 0;
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            text-align: center;
            display: block;
            width: 100%;
            border-bottom: none;
        }

        .nav-tabs .nav-link:hover {
            background-color: #e9ecef;
        }

        .nav-tabs .nav-link.active {
            color: #495057;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
        }

        /* Tab Content ohne Card-Einschränkungen */
        .tab-content {
            border: 1px solid #dee2e6;
            border-top: none;
            padding: 1.5rem;
            background-color: #fff;
            width: 100%;
        }

        /* DataTables volle Breite erzwingen */
        .dataTables_wrapper {
            width: 100% !important;
        }

        table.dataTable {
            width: 100% !important;
            margin: 0 !important;
        }

        /* Badge Styles */
        .badge {
            padding: 0.35em 0.65em;
            font-size: 0.875em;
            font-weight: 600;
            border-radius: 0.25rem;
            display: inline-block;
        }

        .badge-success {
            background-color: #198754;
            color: white;
        }

        .badge-warning {
            background-color: #ffc107;
            color: #000;
        }

        .badge-danger {
            background-color: #dc3545;
            color: white;
        }

        /* Button Styles */
        .btn {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 0.25rem;
            cursor: pointer;
        }

        .btn-info {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
            color: white;
        }

        .btn-info:hover {
            background-color: #0bacce;
            border-color: #0a9ec2;
            color: white;
        }

        /* Trend Indicators */
        .text-success {
            color: #198754 !important;
            font-weight: 600;
        }

        .text-danger {
            color: #dc3545 !important;
            font-weight: 600;
        }

        .text-muted {
            color: #6c757d !important;
        }

        /* Alert anpassen */
        .alert {
            margin-bottom: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .nav-tabs .nav-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
            }
            
            .tab-content {
                padding: 1rem;
            }
        }
        </style>
    <?php
}
    
    /**
     * Einstellungen-Seite rendern
     */
    public function render_settings_page() {
        // Einstellungen speichern wenn Form submitted
        if (isset($_POST['submit'])) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>Einstellungen gespeichert!</p></div>';
        }
        
        // Aktuelle Einstellungen laden
        $is_enabled = get_option('fahrplanportal_search_logging_enabled', true);
        $min_length = get_option('fahrplanportal_search_min_length', 3);
        $retention_days = get_option('fahrplanportal_search_retention_days', 180);
        $normalize_umlauts = get_option('fahrplanportal_search_normalize_umlauts', false);
        $email_reports = get_option('fahrplanportal_search_email_reports', false);
        $email_address = get_option('fahrplanportal_search_email_address', get_option('admin_email'));
        $blacklist = get_option('fahrplanportal_search_blacklist', '');
        ?>
        <div class="wrap">
            <?php FahrplanPortal_UI_Helper::render_page_header('Fahrplan Such-Einstellungen'); ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('fahrplanportal_search_settings'); ?>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Allgemeine Einstellungen</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Such-Logging aktiviert</label>
                            <div class="col-sm-9">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="is_enabled" 
                                           id="is_enabled" value="1" <?php checked($is_enabled); ?>>
                                    <label class="form-check-label" for="is_enabled">
                                        Suchbegriffe aufzeichnen und analysieren
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                            <label for="min_length" class="col-sm-3 col-form-label">Minimale Länge</label>
                            <div class="col-sm-9">
                                <input type="number" class="form-control" name="min_length" 
                                       id="min_length" value="<?php echo $min_length; ?>" min="1" max="10">
                                <small class="form-text text-muted">
                                    Suchbegriffe kürzer als diese Anzahl Zeichen werden ignoriert
                                </small>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                            <label for="retention_days" class="col-sm-3 col-form-label">Aufbewahrungsdauer</label>
                            <div class="col-sm-9">
                                <div class="input-group">
                                    <input type="number" class="form-control" name="retention_days" 
                                           id="retention_days" value="<?php echo $retention_days; ?>" min="30" max="365">
                                    <div class="input-group-append">
                                        <span class="input-group-text">Tage</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    Nach dieser Zeit werden Suchdaten automatisch gelöscht (DSGVO)
                                </small>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Umlaute normalisieren</label>
                            <div class="col-sm-9">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="normalize_umlauts" 
                                           id="normalize_umlauts" value="1" <?php checked($normalize_umlauts); ?>>
                                    <label class="form-check-label" for="normalize_umlauts">
                                        ä→ae, ö→oe, ü→ue für Gruppierung (z.B. "Kärnten" = "Kaernten")
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">E-Mail Reports</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group row">
                            <label class="col-sm-3 col-form-label">Wöchentliche Reports</label>
                            <div class="col-sm-9">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="email_reports" 
                                           id="email_reports" value="1" <?php checked($email_reports); ?>>
                                    <label class="form-check-label" for="email_reports">
                                        Wöchentliche Such-Statistiken per E-Mail erhalten
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                            <label for="email_address" class="col-sm-3 col-form-label">E-Mail Adresse</label>
                            <div class="col-sm-9">
                                <input type="email" class="form-control" name="email_address" 
                                       id="email_address" value="<?php echo esc_attr($email_address); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Blacklist</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="blacklist">Ignorierte Begriffe (einer pro Zeile)</label>
                            <textarea class="form-control" name="blacklist" id="blacklist" 
                                      rows="6"><?php echo esc_textarea($blacklist); ?></textarea>
                            <small class="form-text text-muted">
                                Diese Suchbegriffe werden nicht aufgezeichnet (z.B. Test-Begriffe)
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Wartung</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <strong>Vorsicht:</strong> Diese Aktionen können nicht rückgängig gemacht werden!
                        </div>
                        
                        <button type="button" class="btn btn-danger" id="clearOldData">
                            Alte Daten löschen (> <?php echo $retention_days; ?> Tage)
                        </button>
                        
                        <button type="button" class="btn btn-danger ml-2" id="clearAllData">
                            ALLE Suchdaten löschen
                        </button>
                    </div>
                </div>
                
                <button type="submit" name="submit" class="btn btn-primary btn-lg">
                    Einstellungen speichern
                </button>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Wartungs-Buttons
            $('#clearOldData').on('click', function() {
                if (confirm('Wirklich alle Daten älter als <?php echo $retention_days; ?> Tage löschen?')) {
                    $.post(ajaxurl, {
                        "action": "unified_ajax",
                        "module": "fahrplanportal_search_logger",
                        "module_action": "clear_old_data",  // <-- module_action statt method!
                        "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",  // <-- korrekter Nonce
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
                            "module_action": "clear_old_data",  // <-- module_action statt method!
                            "clear_all": "1",
                            "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",  // <-- korrekter Nonce
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
        });
        </script>
        <?php
    }
    
    /**
     * Export-Seite rendern  
     */
    public function render_export_page() {
        ?>
        <div class="wrap">
            <?php FahrplanPortal_UI_Helper::render_page_header('Fahrplan Such-Daten Export'); ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Export-Optionen</h5>
                </div>
                <div class="card-body">
                    <form id="exportForm">
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">Zeitraum</label>
                            <div class="col-sm-10">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="period" 
                                           id="period7" value="7" checked>
                                    <label class="form-check-label" for="period7">Letzte 7 Tage</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="period" 
                                           id="period30" value="30">
                                    <label class="form-check-label" for="period30">Letzte 30 Tage</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="period" 
                                           id="period90" value="90">
                                    <label class="form-check-label" for="period90">Letzte 90 Tage</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="period" 
                                           id="periodAll" value="all">
                                    <label class="form-check-label" for="periodAll">Alle Daten</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">Datentyp</label>
                            <div class="col-sm-10">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeStats" 
                                           name="include_stats" value="1" checked>
                                    <label class="form-check-label" for="includeStats">
                                        Aggregierte Statistiken
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeRaw" 
                                           name="include_raw" value="1">
                                    <label class="form-check-label" for="includeRaw">
                                        Rohdaten (alle einzelnen Suchanfragen)
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">Format</label>
                            <div class="col-sm-10">
                                <select class="form-control" name="format" id="exportFormat">
                                    <option value="csv">CSV (Excel-kompatibel)</option>
                                    <option value="json">JSON</option>
                                    <option value="pdf">PDF Report</option>
                                </select>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <button type="button" class="btn btn-primary" id="startExport">
                            <i class="dashicons dashicons-download"></i> Export starten
                        </button>
                        
                        <div id="exportProgress" class="mt-3" style="display: none;">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     role="progressbar" style="width: 0%"></div>
                            </div>
                            <p class="mt-2">Export wird vorbereitet...</p>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">Vorschau</h5>
                </div>
                <div class="card-body">
                    <p>Wählen Sie oben Ihre Export-Optionen und klicken Sie auf "Export starten".</p>
                    <div id="exportPreview"></div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#startExport').on('click', function() {
                var $btn = $(this);
                var $progress = $('#exportProgress');
                var $progressBar = $progress.find('.progress-bar');
                
                // UI vorbereiten
                $btn.prop('disabled', true);
                $progress.show();
                $progressBar.css('width', '50%');
                
                // Export-Daten sammeln
                var exportData = {
                    "action": "unified_ajax",
                    "module": "fahrplanportal_search_logger",
                    "module_action": "export_data",  // <-- module_action statt method!
                    "period": $('input[name="period"]:checked').val(),
                    "include_stats": $('#includeStats').is(':checked') ? 1 : 0,
                    "include_raw": $('#includeRaw').is(':checked') ? 1 : 0,
                    "format": $('#exportFormat').val(),
                    "nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>",  // <-- korrekter Nonce
                    "_ajax_nonce": "<?php echo wp_create_nonce('unified_ajax_master_nonce'); ?>"
                };
                
                $.post(ajaxurl, exportData, function(response) {
                    $progressBar.css('width', '100%');
                    
                    if (response.success) {
                        // Download starten
                        if (response.data.download_url) {
                            window.location.href = response.data.download_url;
                            $progress.find('p').text('Download gestartet!');
                        } else if (response.data.content) {
                            // Für JSON Preview
                            $('#exportPreview').html('<pre>' + JSON.stringify(response.data.content, null, 2) + '</pre>');
                        }
                        
                        setTimeout(function() {
                            $btn.prop('disabled', false);
                            $progress.hide();
                            $progressBar.css('width', '0%');
                        }, 2000);
                    } else {
                        alert('Export fehlgeschlagen: ' + response.data);
                        $btn.prop('disabled', false);
                        $progress.hide();
                    }
                }).fail(function(xhr, status, error) {
                    alert('AJAX-Fehler: ' + error);
                    console.error('AJAX Error:', xhr.responseText);
                    $btn.prop('disabled', false);
                    $progress.hide();
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Einstellungen speichern
     */
    private function save_settings() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'fahrplanportal_search_settings')) {
            return;
        }
        
        update_option('fahrplanportal_search_logging_enabled', isset($_POST['is_enabled']));
        update_option('fahrplanportal_search_min_length', intval($_POST['min_length']));
        update_option('fahrplanportal_search_retention_days', intval($_POST['retention_days']));
        update_option('fahrplanportal_search_normalize_umlauts', isset($_POST['normalize_umlauts']));
        update_option('fahrplanportal_search_email_reports', isset($_POST['email_reports']));
        update_option('fahrplanportal_search_email_address', sanitize_email($_POST['email_address']));
        update_option('fahrplanportal_search_blacklist', sanitize_textarea_field($_POST['blacklist']));
        
        // Klassen-Properties aktualisieren
        $this->is_enabled = get_option('fahrplanportal_search_logging_enabled', true);
        $this->min_search_length = get_option('fahrplanportal_search_min_length', 3);
        $this->retention_days = get_option('fahrplanportal_search_retention_days', 180);
    }
    
    // ========================================
    // AJAX HANDLER METHODS
    // ========================================
    
    /**
     * Dashboard Stats abrufen
     */
    public function ajax_get_dashboard_stats() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $stats = $this->get_quick_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * Chart-Daten abrufen
     */
    public function ajax_get_chart_data() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $chart_type = sanitize_text_field($_POST['chart_type'] ?? 'searches_per_day');
        
        switch ($chart_type) {
            case 'searches_per_day':
                $data = $this->get_searches_per_day_data();
                break;
                
            case 'success_rate':
                $data = $this->get_success_rate_data();
                break;
                
            case 'time_analysis':
                $data = $this->get_time_analysis_data();
                break;
                
            default:
                wp_send_json_error('Unbekannter Chart-Typ');
                return;
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * Top Searches abrufen
     */
    public function ajax_get_top_searches() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        global $wpdb;
        
        $results = $wpdb->get_results("
            SELECT 
                s.*,
                ROUND((s.successful_searches / s.total_searches) * 100, 1) as success_rate
            FROM {$this->stats_table} s
            ORDER BY s.total_searches DESC
            LIMIT 100
        ");
        
        wp_send_json_success($results);
    }
    
    /**
     * Failed Searches abrufen
     */
    public function ajax_get_failed_searches() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        global $wpdb;
        
        $results = $wpdb->get_results("
            SELECT 
                s.*,
                '' as alternatives
            FROM {$this->stats_table} s
            WHERE s.successful_searches = 0
            ORDER BY s.failed_searches DESC
            LIMIT 100
        ");
        
        // Alternativen vorschlagen (vereinfacht)
        foreach ($results as &$result) {
            $result->alternatives = $this->suggest_alternatives($result->term);
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * Trending Searches abrufen
     */
    public function ajax_get_trending_searches() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        global $wpdb;
        
        // Trending Up
        $trending_up = $wpdb->get_results("
            SELECT term, trend_7days, total_searches
            FROM {$this->stats_table}
            WHERE trend_7days > 20
            AND total_searches >= 5
            ORDER BY trend_7days DESC
            LIMIT 10
        ");
        
        // Trending Down  
        $trending_down = $wpdb->get_results("
            SELECT term, trend_7days, total_searches
            FROM {$this->stats_table}
            WHERE trend_7days < -20
            AND total_searches >= 5
            ORDER BY trend_7days ASC
            LIMIT 10
        ");
        
        wp_send_json_success(array(
            'trending_up' => $trending_up,
            'trending_down' => $trending_down
        ));
    }
    
    /**
     * Export-Funktion
     */
    public function ajax_export_data() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $period = sanitize_text_field($_POST['period'] ?? '30');
        $include_stats = (bool)($_POST['include_stats'] ?? true);
        $include_raw = (bool)($_POST['include_raw'] ?? false);
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        
        // Daten sammeln
        $export_data = $this->prepare_export_data($period, $include_stats, $include_raw);
        
        switch ($format) {
            case 'csv':
                $file_url = $this->export_to_csv($export_data);
                wp_send_json_success(array('download_url' => $file_url));
                break;
                
            case 'json':
                wp_send_json_success(array('content' => $export_data));
                break;
                
            case 'pdf':
                // PDF-Export würde hier implementiert
                wp_send_json_error('PDF-Export noch nicht implementiert');
                break;
                
            default:
                wp_send_json_error('Unbekanntes Format');
        }
    }
    
    /**
     * Alte Daten löschen
     */
    public function ajax_clear_old_data() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        global $wpdb;
        
        if (!empty($_POST['clear_all'])) {
            // Alle Daten löschen
            $wpdb->query("TRUNCATE TABLE {$this->log_table}");
            $wpdb->query("TRUNCATE TABLE {$this->stats_table}");
            
            wp_send_json_success(array('deleted' => 'all'));
        } else {
            // Nur alte Daten löschen
            $this->delete_old_logs();
            
            // Anzahl verbleibender Einträge
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table}");
            
            wp_send_json_success(array('deleted' => 'old', 'remaining' => $remaining));
        }
    }
    
    /**
     * Einstellungen speichern (AJAX)
     */
    public function ajax_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        // Settings verarbeiten...
        wp_send_json_success('Einstellungen gespeichert');
    }
    
    /**
     * Such-Details abrufen
     */
    public function ajax_get_search_details() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
        }
        
        $term = sanitize_text_field($_POST['term'] ?? '');
        if (empty($term)) {
            wp_send_json_error('Kein Suchbegriff angegeben');
        }
        
        // Details zum Suchbegriff sammeln
        $details = $this->get_search_term_details($term);
        
        wp_send_json_success($details);
    }
    
    // ========================================
    // HELPER METHODS FOR DATA
    // ========================================
    
    /**
     * Quick Stats berechnen
     */
    private function get_quick_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Gesamte Suchen
        $stats['total_searches'] = $wpdb->get_var("SELECT SUM(total_searches) FROM {$this->stats_table}");
        
        // Erfolgreiche Suchen
        $stats['successful_searches'] = $wpdb->get_var("SELECT SUM(successful_searches) FROM {$this->stats_table}");
        
        // Erfolgsquote
        $stats['success_rate'] = $stats['total_searches'] > 0 
            ? round(($stats['successful_searches'] / $stats['total_searches']) * 100, 1)
            : 0;
        
        // Unique Terms
        $stats['unique_terms'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->stats_table}");
        
        // Suchen heute
        $stats['searches_today'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->log_table} WHERE DATE(search_date) = CURDATE()"
        );
        
        return $stats;
    }
    
    /**
     * Searches per Day Daten (erweiterte Version)
     */
    private function get_searches_per_day_data() {
        global $wpdb;
        
        $results = $wpdb->get_results("
            SELECT 
                DATE(search_date) as date,
                COUNT(*) as total_count,
                SUM(found_results) as successful_count
            FROM {$this->log_table}
            WHERE search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(search_date)
            ORDER BY date ASC
        ");
        
        // Alle Tage der letzten 30 Tage generieren (auch ohne Daten)
        $start_date = new DateTime('-29 days');
        $end_date = new DateTime();
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));
        
        // Daten nach Datum indexieren
        $data_by_date = array();
        foreach ($results as $row) {
            $data_by_date[$row->date] = $row;
        }
        
        $labels = array();
        $totals = array();
        $successful = array();
        
        foreach ($date_range as $date) {
            $date_str = $date->format('Y-m-d');
            $labels[] = $date->format('d.m.');
            
            if (isset($data_by_date[$date_str])) {
                $totals[] = intval($data_by_date[$date_str]->total_count);
                $successful[] = intval($data_by_date[$date_str]->successful_count);
            } else {
                $totals[] = 0;
                $successful[] = 0;
            }
        }
        
        return array(
            'labels' => $labels,
            'datasets' => array(
                array(
                    'label' => 'Gesamte Suchen',
                    'data' => $totals
                ),
                array(
                    'label' => 'Erfolgreiche Suchen',
                    'data' => $successful
                )
            ),
            // Für Kompatibilität mit einfachen Anfragen
            'values' => $totals
        );
    }
    
    /**
     * Success Rate Daten
     */
    private function get_success_rate_data() {
        global $wpdb;
        
        $successful = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->log_table} 
            WHERE found_results = 1
            AND search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        
        $failed = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->log_table} 
            WHERE found_results = 0
            AND search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        
        return array(
            'successful' => $successful,
            'failed' => $failed
        );
    }
    
    /**
     * Time Analysis Daten
     */
    private function get_time_analysis_data() {
        global $wpdb;
        
        $results = $wpdb->get_results("
            SELECT 
                HOUR(search_time) as hour,
                COUNT(*) as count
            FROM {$this->log_table}
            WHERE search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY HOUR(search_time)
            ORDER BY hour ASC
        ");
        
        // 24 Stunden Array vorbereiten
        $hours = array_fill(0, 24, 0);
        
        foreach ($results as $row) {
            $hours[$row->hour] = $row->count;
        }
        
        $labels = array();
        for ($i = 0; $i < 24; $i++) {
            $labels[] = sprintf('%02d:00', $i);
        }
        
        return array(
            'labels' => $labels,
            'values' => array_values($hours)
        );
    }
    
    /**
     * Alternativen vorschlagen
     */
    private function suggest_alternatives($term) {
        global $wpdb;
        
        // Sehr vereinfachte Implementierung
        // In Produktion würde man hier Levenshtein-Distanz o.ä. nutzen
        
        $alternatives = array();
        
        // Nach ähnlichen erfolgreichen Begriffen suchen
        $similar = $wpdb->get_col($wpdb->prepare("
            SELECT term 
            FROM {$this->stats_table}
            WHERE successful_searches > 0
            AND term LIKE %s
            AND term != %s
            LIMIT 3
        ", '%' . $wpdb->esc_like(substr($term, 0, 3)) . '%', $term));
        
        return $similar;
    }
    
    /**
     * Export-Daten vorbereiten
     */
    private function prepare_export_data($period, $include_stats, $include_raw) {
        global $wpdb;
        
        $data = array();
        
        // Zeitraum bestimmen
        if ($period === 'all') {
            $date_condition = '1=1';
        } else {
            $days = intval($period);
            $date_condition = "search_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
        }
        
        // Statistiken
        if ($include_stats) {
            $data['statistics'] = $wpdb->get_results("
                SELECT * FROM {$this->stats_table}
                ORDER BY total_searches DESC
            ", ARRAY_A);
        }
        
        // Rohdaten
        if ($include_raw) {
            $data['raw_logs'] = $wpdb->get_results("
                SELECT 
                    search_term,
                    result_count,
                    search_type,
                    search_date,
                    found_results
                FROM {$this->log_table}
                WHERE $date_condition
                ORDER BY search_date DESC
            ", ARRAY_A);
        }
        
        return $data;
    }
    
    /**
     * CSV Export
     */
    private function export_to_csv($data) {
        $upload_dir = wp_upload_dir();
        $file_name = 'fahrplan-search-export-' . date('Y-m-d-His') . '.csv';
        $file_path = $upload_dir['path'] . '/' . $file_name;
        $file_url = $upload_dir['url'] . '/' . $file_name;
        
        $fp = fopen($file_path, 'w');
        
        // UTF-8 BOM für Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Statistiken
        if (isset($data['statistics'])) {
            fputcsv($fp, array('STATISTIKEN'), ';');
            fputcsv($fp, array(
                'Suchbegriff',
                'Anzahl Suchen',
                'Erfolgreiche Suchen',
                'Erfolglose Suchen',
                'Erfolgsquote %',
                'Trend 7 Tage %',
                'Erste Suche',
                'Letzte Suche'
            ), ';');
            
            foreach ($data['statistics'] as $stat) {
                $success_rate = $stat['total_searches'] > 0 
                    ? round(($stat['successful_searches'] / $stat['total_searches']) * 100, 1)
                    : 0;
                    
                fputcsv($fp, array(
                    $stat['term'],
                    $stat['total_searches'],
                    $stat['successful_searches'],
                    $stat['failed_searches'],
                    $success_rate,
                    $stat['trend_7days'],
                    $stat['first_searched'],
                    $stat['last_searched']
                ), ';');
            }
            
            fputcsv($fp, array(), ';'); // Leerzeile
        }
        
        // Rohdaten
        if (isset($data['raw_logs'])) {
            fputcsv($fp, array('ROHDATEN'), ';');
            fputcsv($fp, array(
                'Suchbegriff',
                'Ergebnisse',
                'Typ',
                'Datum/Zeit',
                'Erfolgreich'
            ), ';');
            
            foreach ($data['raw_logs'] as $log) {
                fputcsv($fp, array(
                    $log['search_term'],
                    $log['result_count'],
                    $log['search_type'],
                    $log['search_date'],
                    $log['found_results'] ? 'Ja' : 'Nein'
                ), ';');
            }
        }
        
        fclose($fp);
        
        return $file_url;
    }
    
    /**
     * E-Mail Report senden
     */
    private function send_email_report() {
        $email = get_option('fahrplanportal_search_email_address', get_option('admin_email'));
        
        if (empty($email)) {
            return;
        }
        
        // Report-Daten sammeln
        $stats = $this->get_quick_stats();
        $top_searches = $this->get_top_searches_for_email();
        $failed_searches = $this->get_failed_searches_for_email();
        
        // E-Mail zusammenstellen
        $subject = 'Fahrplan Such-Statistiken - Wochenbericht';
        
        $message = "Fahrplan Such-Statistiken\n";
        $message .= "Wochenbericht vom " . date('d.m.Y') . "\n\n";
        
        $message .= "ÜBERSICHT\n";
        $message .= "---------\n";
        $message .= "Gesamte Suchen: " . number_format($stats['total_searches'], 0, ',', '.') . "\n";
        $message .= "Erfolgsquote: " . $stats['success_rate'] . "%\n";
        $message .= "Unique Begriffe: " . number_format($stats['unique_terms'], 0, ',', '.') . "\n\n";
        
        $message .= "TOP 10 SUCHBEGRIFFE\n";
        $message .= "-------------------\n";
        foreach ($top_searches as $search) {
            $message .= sprintf("%-30s %5d Suchen\n", $search->term, $search->total_searches);
        }
        
        $message .= "\n";
        $message .= "TOP 10 ERFOLGLOSE SUCHEN\n";
        $message .= "-------------------------\n";
        foreach ($failed_searches as $search) {
            $message .= sprintf("%-30s %5d Versuche\n", $search->term, $search->failed_searches);
        }
        
        $message .= "\n";
        $message .= "Vollständige Statistiken finden Sie im WordPress Admin-Bereich.\n";
        
        wp_mail($email, $subject, $message);
    }
    
    /**
     * Top Searches für E-Mail
     */
    private function get_top_searches_for_email() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT term, total_searches
            FROM {$this->stats_table}
            ORDER BY total_searches DESC
            LIMIT 10
        ");
    }
    
    /**
     * Failed Searches für E-Mail
     */
    private function get_failed_searches_for_email() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT term, failed_searches
            FROM {$this->stats_table}
            WHERE successful_searches = 0
            ORDER BY failed_searches DESC
            LIMIT 10
        ");
    }
    
    /**
     * Details zu einem Suchbegriff
     */
    private function get_search_term_details($term) {
        global $wpdb;
        
        // Basis-Statistiken
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$this->stats_table}
            WHERE term = %s
        ", $term));
        
        // Zeitverlauf (letzte 30 Tage)
        $timeline = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(search_date) as date,
                COUNT(*) as count,
                SUM(found_results) as successful
            FROM {$this->log_table}
            WHERE search_term_normalized = %s
            AND search_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(search_date)
            ORDER BY date ASC
        ", $this->normalize_search_term($term)));
        
        // Tageszeit-Verteilung
        $time_distribution = $wpdb->get_results($wpdb->prepare("
            SELECT 
                HOUR(search_time) as hour,
                COUNT(*) as count
            FROM {$this->log_table}
            WHERE search_term_normalized = %s
            GROUP BY HOUR(search_time)
            ORDER BY hour ASC
        ", $this->normalize_search_term($term)));
        
        return array(
            'stats' => $stats,
            'timeline' => $timeline,
            'time_distribution' => $time_distribution
        );
    }
}

// Modul initialisieren wenn verfügbar
if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
    global $fahrplan_search_logger;
    $fahrplan_search_logger = new FahrplanSearchLogger();
}

?>
