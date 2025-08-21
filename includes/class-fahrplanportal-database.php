<?php
/**
 * FahrplanPortal Database Class
 * Alle Datenbankoperationen und Schema-Management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Database {
    
    private $table_name;
    private $pdf_parsing_enabled;
    
    public function __construct($pdf_parsing_enabled) {
        global $wpdb;
        
        $this->table_name = $wpdb->prefix . 'fahrplaene';
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        
        // ✅ Admin-Hooks NUR wenn echtes Admin (kein AJAX)
        if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('admin_init', array($this, 'init_database'));
        }
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

            $this->add_pdf_status_column();
            
            error_log('✅ FAHRPLANPORTAL: Datenbank erfolgreich initialisiert (echter Admin)');
            return true;
            
        } catch (Exception $e) {
            error_log('❌ FAHRPLANPORTAL: init_database Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Einzelnen Fahrplan aus DB laden
     */
    public function get_fahrplan($id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Alle Fahrpläne aus DB laden
     */
    public function get_all_fahrplaene() {
        global $wpdb;
        
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");
    }
    
    /**
     * Fahrplan einfügen
     */
    public function insert_fahrplan($data) {
        global $wpdb;
        
        $format_array = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
        
        // Tags nur hinzufügen wenn PDF-Parsing verfügbar
        if ($this->pdf_parsing_enabled && isset($data['tags'])) {
            $format_array[] = '%s';
        }
        
        $result = $wpdb->insert($this->table_name, $data, $format_array);
        
        if ($result === false) {
            throw new Exception("DB-Insert fehlgeschlagen: " . $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Fahrplan aktualisieren
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
        
        return $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Fahrplan löschen
     */
    public function delete_fahrplan($id) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
    }
    
    /**
     * Prüfen ob Fahrplan bereits existiert
     */
    public function fahrplan_exists($dateiname, $jahr, $region) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE dateiname = %s AND jahr = %s AND region = %s",
            $dateiname, $jahr, $region
        ));
    }
    
    /**
     * Anzahl Fahrpläne ermitteln
     */
    public function get_fahrplaene_count() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    /**
     * Datenbank neu erstellen
     */
    public function recreate_database() {
        global $wpdb;
        
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
     * Alle Einträge löschen
     */
    public function clear_database() {
        global $wpdb;
        
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
    }
    
    /**
     * Alle Tags für Analyse sammeln
     */
    public function get_all_tags() {
        global $wpdb;
        
        if (!$this->pdf_parsing_enabled) {
            return array();
        }
        
        return $wpdb->get_results("SELECT tags FROM {$this->table_name} WHERE tags IS NOT NULL AND tags != ''");
    }
    
    /**
     * Table name getter
     */
    public function get_table_name() {
        return $this->table_name;
    }


    /**
     * ✅ NEU: Alle Fahrpläne mit Tags laden (Performance-optimiert)
     * Lädt nur ID und Tags-Spalte für Tag-Bereinigung
     */
    public function get_all_fahrplaene_with_tags() {
        global $wpdb;
        
        // Nur ausführen wenn PDF-Parsing verfügbar
        if (!$this->pdf_parsing_enabled) {
            return array();
        }
        
        return $wpdb->get_results(
            "SELECT id, tags FROM {$this->table_name} 
             WHERE tags IS NOT NULL AND tags != '' 
             ORDER BY id ASC"
        );
    }

    /**
     * ✅ NEU: Tags für spezifischen Fahrplan aktualisieren
     * Performance-optimiert: Updated nur Tags-Spalte
     */
    public function update_fahrplan_tags($id, $tags) {
        global $wpdb;
        
        // Nur ausführen wenn PDF-Parsing verfügbar
        if (!$this->pdf_parsing_enabled) {
            return false;
        }
        
        $result = $wpdb->update(
            $this->table_name,
            array('tags' => $tags),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            error_log("FAHRPLANPORTAL: Fehler beim Tag-Update für ID $id: " . $wpdb->last_error);
            return false;
        }
        
        return $result;
    }

    /**
     * ✅ NEU: Statistiken für Tag-Bereinigung
     * Zählt Fahrpläne mit Tags für Progress-Anzeige
     */
    public function get_fahrplaene_with_tags_count() {
        global $wpdb;
        
        // Nur ausführen wenn PDF-Parsing verfügbar
        if (!$this->pdf_parsing_enabled) {
            return 0;
        }
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE tags IS NOT NULL AND tags != ''"
        );
    }


    /**
     * ✅ VERBESSERT: Fahrplan nach PDF-Pfad suchen (mit Debug-Logging)
     */
    public function get_fahrplan_by_path($pdf_pfad) {
        global $wpdb;
        
        error_log('FAHRPLANPORTAL: DEBUG - Suche Fahrplan mit Pfad: ' . $pdf_pfad);
        
        // Exakte Suche
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE pdf_pfad = %s LIMIT 1",
            $pdf_pfad
        );
        
        $result = $wpdb->get_row($sql);
        
        if ($result) {
            error_log('FAHRPLANPORTAL: DEBUG - Fahrplan gefunden (exakt): ID ' . $result->id);
            return $result;
        }
        
        // ✅ FALLBACK: Suche nach Dateiname falls exakter Pfad nicht gefunden
        $filename = basename($pdf_pfad);
        error_log('FAHRPLANPORTAL: DEBUG - Exakter Pfad nicht gefunden, suche nach Dateiname: ' . $filename);
        
        $sql_filename = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE pdf_pfad LIKE %s LIMIT 1",
            '%' . $filename
        );
        
        $result_filename = $wpdb->get_row($sql_filename);
        
        if ($result_filename) {
            error_log('FAHRPLANPORTAL: DEBUG - Fahrplan gefunden (Dateiname): ID ' . $result_filename->id . ', Pfad: ' . $result_filename->pdf_pfad);
            return $result_filename;
        }
        
        error_log('FAHRPLANPORTAL: DEBUG - Kein Fahrplan gefunden für: ' . $pdf_pfad);
        return null;
    }


    /**
     * ✅ NEU: PDF-Status Spalte zur Tabelle hinzufügen
     * Status: 'OK', 'MISSING', 'IMPORT'
     */
    public function add_pdf_status_column() {
        global $wpdb;
        
        try {
            // Prüfen ob Spalte bereits existiert
            $column_exists = $wpdb->get_results(
                "SHOW COLUMNS FROM {$this->table_name} LIKE 'pdf_status'"
            );
            
            if (empty($column_exists)) {
                // Spalte hinzufügen
                $sql = "ALTER TABLE {$this->table_name} 
                        ADD COLUMN pdf_status VARCHAR(20) DEFAULT 'OK' 
                        AFTER region";
                
                $result = $wpdb->query($sql);
                
                if ($result !== false) {
                    error_log('FAHRPLANPORTAL: pdf_status Spalte erfolgreich hinzugefügt');
                    
                    // Alle bestehenden Einträge auf 'OK' setzen
                    $wpdb->query("UPDATE {$this->table_name} SET pdf_status = 'OK' WHERE pdf_status IS NULL");
                    
                    return true;
                } else {
                    error_log('FAHRPLANPORTAL: Fehler beim Hinzufügen der pdf_status Spalte: ' . $wpdb->last_error);
                    return false;
                }
            } else {
                error_log('FAHRPLANPORTAL: pdf_status Spalte existiert bereits');
                return true;
            }
            
        } catch (Exception $e) {
            error_log('FAHRPLANPORTAL: Exception beim Hinzufügen der pdf_status Spalte: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✅ NEU: PDF-Status für bestimmte ID setzen
     */
    public function update_pdf_status($id, $status) {
        global $wpdb;
        
        $allowed_statuses = array('OK', 'MISSING', 'IMPORT');
        
        if (!in_array($status, $allowed_statuses)) {
            error_log('FAHRPLANPORTAL: Ungültiger pdf_status: ' . $status);
            return false;
        }
        
        $result = $wpdb->update(
            $this->table_name,
            array('pdf_status' => $status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * ✅ NEU: Alle Einträge mit Status MISSING löschen
     */
    public function delete_missing_fahrplaene() {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('pdf_status' => 'MISSING'),
            array('%s')
        );
        
        if ($result !== false) {
            error_log('FAHRPLANPORTAL: ' . $result . ' fehlende PDFs aus Datenbank gelöscht');
        }
        
        return $result;
    }
    
    /**
     * ✅ NEU: Anzahl Einträge nach Status abfragen
     */
    public function get_status_counts() {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT pdf_status, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY pdf_status"
        );
        
        $counts = array(
            'OK' => 0,
            'MISSING' => 0,
            'IMPORT' => 0
        );
        
        foreach ($results as $row) {
            if (isset($counts[$row->pdf_status])) {
                $counts[$row->pdf_status] = intval($row->count);
            }
        }
        
        return $counts;
    }

    /**
     * ✅ NEU: Alle verwendeten Ordner aus der Datenbank ermitteln
     */
    public function get_used_folders() {
        global $wpdb;
        
        // Unique Ordner-Namen aus Jahr-Spalte extrahieren
        $folders = $wpdb->get_col(
            "SELECT DISTINCT jahr FROM {$this->table_name} 
             WHERE jahr IS NOT NULL AND jahr != '' 
             ORDER BY jahr DESC"
        );
        
        if (empty($folders)) {
            error_log('FAHRPLANPORTAL: Keine Ordner in Datenbank gefunden');
            return array();
        }
        
        error_log('FAHRPLANPORTAL: Verwendete Ordner aus DB: ' . implode(', ', $folders));
        
        return $folders;
    }

}