<?php
/**
 * FahrplanPortal Publisher Class
 * Staging/Live-Verwaltung für Fahrpläne
 * 
 * ✅ NEU: Zwei-Tabellen-System (Staging/Live)
 * ✅ SICHERHEIT: Backup vor jedem Publish
 * ✅ ROLLBACK: Wiederherstellung möglich
 * ✅ LOGGING: Umfassende Protokollierung
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Publisher {
    
    private $staging_table;
    private $live_table;
    private $backup_table;
    private $database;
    
    public function __construct($database) {
        global $wpdb;
        
        $this->staging_table = $wpdb->prefix . 'fahrplaene';          // Bestehende Staging-Tabelle
        $this->live_table = $wpdb->prefix . 'fahrplaene_live';       // Neue Live-Tabelle
        $this->backup_table = $wpdb->prefix . 'fahrplaene_backup';   // Neue Backup-Tabelle
        $this->database = $database;
        
        // Live/Backup-Tabellen bei Bedarf erstellen
        add_action('admin_init', array($this, 'ensure_publish_tables'), 30);
    }
    
    /**
     * ✅ Live und Backup-Tabellen sicherstellen
     */
    public function ensure_publish_tables() {
        // Nur bei echtem Admin (kein AJAX)
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        
        global $wpdb;
        
        try {
            // Live-Tabelle erstellen (identisch zur Staging-Tabelle)
            $this->create_table_if_not_exists($this->live_table);
            
            // Backup-Tabelle erstellen (identisch zur Staging-Tabelle)
            $this->create_table_if_not_exists($this->backup_table);
            
            error_log('✅ FAHRPLANPORTAL PUBLISHER: Live/Backup-Tabellen sichergestellt');
            
        } catch (Exception $e) {
            error_log('❌ FAHRPLANPORTAL PUBLISHER: Fehler beim Tabellen-Setup: ' . $e->getMessage());
        }
    }
    
    /**
     * ✅ Tabelle erstellen wenn nicht vorhanden (basierend auf Staging-Schema)
     */
    private function create_table_if_not_exists($table_name) {
        global $wpdb;
        
        // Prüfen ob Tabelle bereits existiert
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if ($table_exists) {
            return true; // Tabelle existiert bereits
        }
        
        // Schema von Staging-Tabelle kopieren
        $staging_schema = $wpdb->get_results("SHOW CREATE TABLE {$this->staging_table}");
        
        if (empty($staging_schema)) {
            throw new Exception("Staging-Tabelle Schema konnte nicht gelesen werden");
        }
        
        $create_sql = $staging_schema[0]->{'Create Table'};
        
        // Tabellenname im Schema ersetzen
        $staging_name = $this->staging_table;
        $create_sql = str_replace("`{$staging_name}`", "`{$table_name}`", $create_sql);
        $create_sql = str_replace("CREATE TABLE `{$staging_name}`", "CREATE TABLE `{$table_name}`", $create_sql);
        
        // Tabelle erstellen
        $result = $wpdb->query($create_sql);
        
        if ($result === false) {
            throw new Exception("Fehler beim Erstellen der Tabelle {$table_name}: " . $wpdb->last_error);
        }
        
        error_log("✅ FAHRPLANPORTAL PUBLISHER: Tabelle {$table_name} erfolgreich erstellt");
        return true;
    }
    
    /**
     * ✅ HAUPT-FUNKTION: Staging → Live veröffentlichen
     */
    public function publish_staging_to_live() {
        global $wpdb;
        
        try {
            // 1. Backup der aktuellen Live-Daten erstellen
            $backup_created = $this->create_backup();
            
            if (!$backup_created) {
                throw new Exception("Backup konnte nicht erstellt werden");
            }
            
            // 2. Transaktion starten für atomare Operation
            $wpdb->query('START TRANSACTION');
            
            // 3. Live-Tabelle leeren
            $clear_result = $wpdb->query("TRUNCATE TABLE {$this->live_table}");
            if ($clear_result === false) {
                throw new Exception("Live-Tabelle konnte nicht geleert werden: " . $wpdb->last_error);
            }
            
            // 4. Staging-Daten zu Live kopieren
            $copy_result = $wpdb->query("INSERT INTO {$this->live_table} SELECT * FROM {$this->staging_table}");
            if ($copy_result === false) {
                throw new Exception("Staging-Daten konnten nicht kopiert werden: " . $wpdb->last_error);
            }
            
            // 5. Publish-Metadaten speichern
            update_option('fahrplanportal_last_publish', current_time('mysql'));
            update_option('fahrplanportal_last_publish_count', $copy_result);
            
            // 6. Transaktion bestätigen
            $wpdb->query('COMMIT');
            
            // 7. Logging
            error_log("✅ FAHRPLANPORTAL PUBLISHER: Erfolgreich veröffentlicht - {$copy_result} Einträge");
            
            return array(
                'success' => true,
                'published_count' => $copy_result,
                'publish_date' => current_time('mysql'),
                'message' => "✅ {$copy_result} Einträge erfolgreich veröffentlicht"
            );
            
        } catch (Exception $e) {
            // Rollback bei Fehlern
            $wpdb->query('ROLLBACK');
            
            error_log("❌ FAHRPLANPORTAL PUBLISHER: Publish-Fehler: " . $e->getMessage());
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'message' => "❌ Fehler beim Veröffentlichen: " . $e->getMessage()
            );
        }
    }
    
    /**
     * ✅ Backup der Live-Daten erstellen
     */
    public function create_backup() {
        global $wpdb;
        
        try {
            // Backup-Tabelle leeren
            $clear_result = $wpdb->query("TRUNCATE TABLE {$this->backup_table}");
            if ($clear_result === false) {
                throw new Exception("Backup-Tabelle konnte nicht geleert werden: " . $wpdb->last_error);
            }
            
            // Live-Daten zu Backup kopieren
            $backup_result = $wpdb->query("INSERT INTO {$this->backup_table} SELECT * FROM {$this->live_table}");
            if ($backup_result === false) {
                throw new Exception("Live-Daten konnten nicht gesichert werden: " . $wpdb->last_error);
            }
            
            // Backup-Metadaten speichern
            update_option('fahrplanportal_last_backup', current_time('mysql'));
            update_option('fahrplanportal_last_backup_count', $backup_result);
            
            error_log("✅ FAHRPLANPORTAL PUBLISHER: Backup erstellt - {$backup_result} Einträge");
            
            return true;
            
        } catch (Exception $e) {
            error_log("❌ FAHRPLANPORTAL PUBLISHER: Backup-Fehler: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✅ Rollback: Backup → Live wiederherstellen
     */
    public function rollback_to_backup() {
        global $wpdb;
        
        try {
            // Prüfen ob Backup vorhanden
            $backup_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->backup_table}");
            
            if ($backup_count == 0) {
                throw new Exception("Kein Backup verfügbar für Rollback");
            }
            
            // Transaktion starten
            $wpdb->query('START TRANSACTION');
            
            // Live-Tabelle leeren
            $clear_result = $wpdb->query("TRUNCATE TABLE {$this->live_table}");
            if ($clear_result === false) {
                throw new Exception("Live-Tabelle konnte nicht geleert werden: " . $wpdb->last_error);
            }
            
            // Backup-Daten zu Live kopieren
            $rollback_result = $wpdb->query("INSERT INTO {$this->live_table} SELECT * FROM {$this->backup_table}");
            if ($rollback_result === false) {
                throw new Exception("Backup konnte nicht wiederhergestellt werden: " . $wpdb->last_error);
            }
            
            // Rollback-Metadaten speichern
            $backup_date = get_option('fahrplanportal_last_backup', 'Unbekannt');
            update_option('fahrplanportal_last_rollback', current_time('mysql'));
            update_option('fahrplanportal_last_rollback_count', $rollback_result);
            
            // Transaktion bestätigen
            $wpdb->query('COMMIT');
            
            error_log("✅ FAHRPLANPORTAL PUBLISHER: Rollback erfolgreich - {$rollback_result} Einträge wiederhergestellt");
            
            return array(
                'success' => true,
                'restored_count' => $rollback_result,
                'backup_date' => $backup_date,
                'message' => "⏪ {$rollback_result} Einträge erfolgreich wiederhergestellt (Stand: {$backup_date})"
            );
            
        } catch (Exception $e) {
            // Rollback bei Fehlern
            $wpdb->query('ROLLBACK');
            
            error_log("❌ FAHRPLANPORTAL PUBLISHER: Rollback-Fehler: " . $e->getMessage());
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'message' => "❌ Fehler beim Rollback: " . $e->getMessage()
            );
        }
    }
    
    /**
     * ✅ Publish-Statistiken für Admin-UI
     */
    public function get_publish_statistics() {
        global $wpdb;
        
        try {
            $staging_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->staging_table}");
            $live_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->live_table}");
            $backup_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->backup_table}");
            
            $last_publish = get_option('fahrplanportal_last_publish', '');
            $last_backup = get_option('fahrplanportal_last_backup', '');
            
            return array(
                'staging_count' => intval($staging_count),
                'live_count' => intval($live_count),
                'backup_count' => intval($backup_count),
                'last_publish' => $last_publish,
                'last_backup' => $last_backup,
                'has_backup' => ($backup_count > 0),
                'tables_synced' => ($staging_count == $live_count)
            );
            
        } catch (Exception $e) {
            error_log("❌ FAHRPLANPORTAL PUBLISHER: Statistik-Fehler: " . $e->getMessage());
            
            return array(
                'staging_count' => 0,
                'live_count' => 0,
                'backup_count' => 0,
                'last_publish' => '',
                'last_backup' => '',
                'has_backup' => false,
                'tables_synced' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * ✅ Letztes Publish-Datum für Frontend
     */
    public function get_last_publish_date() {
        $last_publish = get_option('fahrplanportal_last_publish', '');
        
        if (empty($last_publish)) {
            return '';
        }
        
        // Deutsches Datumsformat
        $timestamp = strtotime($last_publish);
        return date('d.m.Y H:i', $timestamp);
    }
    
    /**
     * ✅ Live-Tabellennamen für Frontend-Zugriff
     */
    public function get_live_table_name() {
        return $this->live_table;
    }
    
    /**
     * ✅ Prüfen ob Live-System bereit ist
     */
    public function is_live_system_ready() {
        global $wpdb;
        
        // Prüfen ob Live-Tabelle existiert
        $live_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->live_table));
        
        // Prüfen ob Backup-Tabelle existiert  
        $backup_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->backup_table));
        
        return (!empty($live_exists) && !empty($backup_exists));
    }
}