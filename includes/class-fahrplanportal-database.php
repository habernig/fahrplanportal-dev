<?php
/**
 * FahrplanPortal Database Class
 * Alle Datenbankoperationen und Schema-Management
 * 
 * ✅ ERWEITERT: Publisher-Integration für Staging/Live-System
 * ✅ NEU: Live-Tabellen-Zugriff für Frontend
 * ✅ NEU: Publisher-Statistiken und Metadaten
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Database {
    
    private $table_name;              // Staging-Tabelle (bestehend)
    private $live_table_name;         // Live-Tabelle für Frontend
    private $pdf_parsing_enabled;
    private $use_live_data = false;   // Flag für Frontend/Backend-Unterscheidung
    
    public function __construct($pdf_parsing_enabled) {
        global $wpdb;
        
        $this->table_name = $wpdb->prefix . 'fahrplaene';          // Staging
        $this->live_table_name = $wpdb->prefix . 'fahrplaene_live'; // Live
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        
        // ✅ Frontend/Backend-Erkennung für automatische Live-Nutzung
        $this->detect_frontend_context();
        
        // ✅ Admin-Hooks NUR wenn echtes Admin (kein AJAX)
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('admin_init', array($this, 'init_database'));
        }
    }
    
    /**
     * ✅ NEU: Frontend-Context erkennen und Live-Daten nutzen
     */
    private function detect_frontend_context() {
        // Im Frontend (außer AJAX) → Live-Daten verwenden
        if (!is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            $this->use_live_data = true;
            error_log('✅ FAHRPLANPORTAL DATABASE: Frontend-Modus → Live-Daten');
        }
        // Bei Frontend-AJAX (Shortcode-Suchen) → Live-Daten verwenden
        elseif (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['module']) && $_POST['module'] === 'fahrplanportal') {
            // Prüfen ob es Frontend-AJAX ist (z.B. Suche)
            $frontend_actions = array('frontend_search', 'frontend_suggestions');
            if (isset($_POST['module_action']) && in_array($_POST['module_action'], $frontend_actions)) {
                $this->use_live_data = true;
                error_log('✅ FAHRPLANPORTAL DATABASE: Frontend-AJAX → Live-Daten');
            }
        }
    }
    
    /**
     * ✅ NEU: Aktive Tabelle ermitteln (Staging oder Live)
     */
    private function get_active_table() {
        return $this->use_live_data ? $this->live_table_name : $this->table_name;
    }
    
    /**
     * ✅ NEU: Live-Daten erzwingen (für Publisher)
     */
    public function force_live_data($use_live = true) {
        $this->use_live_data = $use_live;
    }
    
    /**
     * ✅ NEU: Live-Tabellennamen für Publisher
     */
    public function get_live_table_name() {
        return $this->live_table_name;
    }
    
    /**
     * ✅ NEU: Staging-Tabellennamen für Publisher
     */
    public function get_staging_table_name() {
        return $this->table_name;
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
     * ✅ ERWEITERT: Einzelnen Fahrplan aus aktiver Tabelle laden
     */
    public function get_fahrplan($id) {
        global $wpdb;
        $active_table = $this->get_active_table();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$active_table} WHERE id = %d",
            $id
        ));
    }
    
    /**
     * ✅ ERWEITERT: Alle Fahrpläne aus aktiver Tabelle laden
     */
    public function get_all_fahrplaene() {
        global $wpdb;
        $active_table = $this->get_active_table();
        
        return $wpdb->get_results("SELECT * FROM {$active_table} ORDER BY created_at DESC");
    }
    
    /**
     * ✅ NEU: Spezifische Tabelle für Abfragen (für Admin-Vergleiche)
     */
    public function get_fahrplaene_from_table($table_type = 'staging') {
        global $wpdb;
        
        $table_name = ($table_type === 'live') ? $this->live_table_name : $this->table_name;
        
        return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC");
    }
    
    /**
     * Fahrplan einfügen (IMMER in Staging-Tabelle)
     */
    public function insert_fahrplan($data) {
        global $wpdb;
        
        $format_array = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
        
        // Tags nur hinzufügen wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled && isset($data['tags'])) {
            $format_array[] = '%s';
        }
        
        // IMMER in Staging-Tabelle einfügen (nicht Live)
        $result = $wpdb->insert($this->table_name, $data, $format_array);
        
        if ($result === false) {
            throw new Exception("DB-Insert fehlgeschlagen: " . $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Fahrplan aktualisieren (IMMER in Staging-Tabelle)
     */
    public function update_fahrplan($id, $data) {
        global $wpdb;
        
        $format = array();
        $allowed_fields = array('titel', 'linie_alt', 'linie_neu', 'kurzbeschreibung', 'gueltig_von', 'gueltig_bis', 'region');
        
        // Tags nur wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled) {
            $allowed_fields[] = 'tags';
        }
        
        $update_data = array();
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $format[] = '%s';
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        // IMMER in Staging-Tabelle aktualisieren (nicht Live)
        return $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Fahrplan löschen (IMMER aus Staging-Tabelle)
     */
    public function delete_fahrplan($id) {
        global $wpdb;
        
        // IMMER aus Staging-Tabelle löschen (nicht Live)
        return $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
    }
    
    /**
     * ✅ ERWEITERT: Prüfen ob Fahrplan bereits existiert (in aktiver Tabelle)
     */
    public function fahrplan_exists($dateiname, $jahr, $region) {
        global $wpdb;
        $active_table = $this->get_active_table();
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$active_table} WHERE dateiname = %s AND jahr = %s AND region = %s",
            $dateiname, $jahr, $region
        ));
    }
    
    /**
     * ✅ ERWEITERT: Anzahl Fahrpläne ermitteln (aus aktiver Tabelle)
     */
    public function get_fahrplaene_count() {
        global $wpdb;
        $active_table = $this->get_active_table();
        
        return $wpdb->get_var("SELECT COUNT(*) FROM {$active_table}");
    }
    
    /**
     * ✅ NEU: Anzahl aus spezifischer Tabelle
     */
    public function get_count_from_table($table_type = 'staging') {
        global $wpdb;
        
        $table_name = ($table_type === 'live') ? $this->live_table_name : $this->table_name;
        
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"));
    }
    
    /**
     * Datenbank neu erstellen (NUR Staging-Tabelle)
     */
    public function recreate_database() {
        global $wpdb;
        
        // NUR Staging-Tabelle neu erstellen (Live bleibt unberührt)
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
        
        // ✅ FIX: Direkte Datenbank-Erstellung ohne AJAX-Blockierung
        return $this->create_database_schema();
    }
    
    /**
     * ✅ NEU: Datenbank-Schema direkt erstellen (ohne AJAX-Prüfung)
     */
    private function create_database_schema() {
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
            
            // ✅ GEFIXT: dbDelta mit Error-Handling
            $result = dbDelta($sql);
            
            if ($wpdb->last_error) {
                error_log('❌ FAHRPLANPORTAL: recreate_database dbDelta Fehler: ' . $wpdb->last_error);
                return false;
            }
            
            // Spalten erweitern/hinzufügen falls nötig
            $wpdb->query("ALTER TABLE {$this->table_name} MODIFY COLUMN jahr VARCHAR(50) NOT NULL");
            
            // Tags-Spalte nur hinzufügen wenn PDF-Parsing verfügbar
            if ($this->pdf_parsing_enabled) {
                $tags_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'tags'");
                if (empty($tags_column_exists)) {
                    $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN tags LONGTEXT AFTER region");
                    $wpdb->query("ALTER TABLE {$this->table_name} ADD FULLTEXT INDEX idx_tags (tags)");
                    error_log('FAHRPLANPORTAL: Tags-Spalte bei recreate hinzugefügt');
                }
            }
            
            error_log('✅ FAHRPLANPORTAL: Datenbank erfolgreich neu erstellt (via recreate_database)');
            return true;
            
        } catch (Exception $e) {
            error_log('❌ FAHRPLANPORTAL: recreate_database Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Alle Einträge löschen (NUR aus Staging-Tabelle)
     */
    public function clear_database() {
        global $wpdb;
        
        // NUR Staging-Tabelle leeren (Live bleibt unberührt)
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * ✅ ERWEITERT: Alle Tags für Analyse sammeln (aus aktiver Tabelle)
     */
    public function get_all_tags() {
        global $wpdb;
        
        if (!$this->pdf_parsing_enabled) {
            return array();
        }
        
        $active_table = $this->get_active_table();
        
        return $wpdb->get_results("SELECT tags FROM {$active_table} WHERE tags IS NOT NULL AND tags != ''");
    }
    
    /**
     * ✅ NEU: Publish-Metadaten verwalten
     */
    public function get_last_publish_info() {
        return array(
            'last_publish' => get_option('fahrplanportal_last_publish', ''),
            'last_publish_count' => get_option('fahrplanportal_last_publish_count', 0),
            'last_backup' => get_option('fahrplanportal_last_backup', ''),
            'last_rollback' => get_option('fahrplanportal_last_rollback', '')
        );
    }
    
    /**
     * ✅ NEU: Letzte Aktualisierung für Frontend (deutsches Format)
     */
    public function get_last_update_display() {
        $last_publish = get_option('fahrplanportal_last_publish', '');
        
        if (empty($last_publish)) {
            return 'Noch nicht veröffentlicht';
        }
        
        $timestamp = strtotime($last_publish);
        return date('d.m.Y \u\m H:i \U\h\r', $timestamp);
    }
    
    /**
     * ✅ NEU: Frontend-Suchfunktionen (IMMER aus Live-Tabelle)
     */
    public function search_fahrplaene_frontend($search_params) {
        global $wpdb;
        
        // Frontend nutzt IMMER Live-Tabelle
        $search_table = $this->live_table_name;
        
        $where_conditions = array();
        $bind_params = array();
        
        // Suchbedingungen aufbauen
        if (!empty($search_params['search_term'])) {
            $search_term = '%' . $wpdb->esc_like($search_params['search_term']) . '%';
            
            $search_conditions = array(
                "titel LIKE %s",
                "linie_alt LIKE %s", 
                "linie_neu LIKE %s",
                "region LIKE %s"
            );
            
            // Tag-Suche nur wenn verfügbar
            if ($this->pdf_parsing_enabled) {
                $search_conditions[] = "tags LIKE %s";
                $bind_params = array_fill(0, 5, $search_term);
            } else {
                $bind_params = array_fill(0, 4, $search_term);
            }
            
            $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
        }
        
        // Jahr-Filter
        if (!empty($search_params['jahr'])) {
            $where_conditions[] = "jahr = %s";
            $bind_params[] = $search_params['jahr'];
        }
        
        // Region-Filter
        if (!empty($search_params['region'])) {
            $where_conditions[] = "region LIKE %s";
            $bind_params[] = '%' . $wpdb->esc_like($search_params['region']) . '%';
        }
        
        // SQL zusammenbauen
        $sql = "SELECT * FROM {$search_table}";
        
        if (!empty($where_conditions)) {
            $sql .= " WHERE " . implode(" AND ", $where_conditions);
        }
        
        $sql .= " ORDER BY titel ASC";
        
        // Limit hinzufügen
        if (isset($search_params['limit'])) {
            $sql .= " LIMIT " . intval($search_params['limit']);
        }
        
        // Query ausführen
        if (!empty($bind_params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $bind_params));
        } else {
            return $wpdb->get_results($sql);
        }
    }
    
    /**
     * Table name getter (gibt aktive Tabelle zurück)
     */
    public function get_table_name() {
        return $this->get_active_table();
    }
    
    /**
     * ✅ NEU: Prüfen ob Live-System verfügbar ist
     */
    public function is_live_system_available() {
        global $wpdb;
        
        $live_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->live_table_name));
        
        return !empty($live_exists);
    }
}