<?php
/**
 * FahrplanPortal Admin Class
 * WordPress Admin-Interface, Menüs und Admin-Seiten
 * 
 * ✅ ERWEITERT: Publisher-UI für Staging/Live-Verwaltung
 * ✅ ORIGINAL: Alle bestehenden Funktionen vollständig erhalten
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Admin {
    
    private $database;
    private $utils;
    private $pdf_base_path;
    private $pdf_parsing_enabled;
    private $publisher;              // ✅ NEU: Publisher-Komponente
    
    public function __construct($database, $utils, $pdf_base_path, $pdf_parsing_enabled, $publisher = null) {
        $this->database = $database;
        $this->utils = $utils;
        $this->pdf_base_path = $pdf_base_path;
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        $this->publisher = $publisher;   // ✅ NEU: Publisher hinzugefügt
        
        $this->init_hooks();
    }
    
    /**
     * Admin-Hooks initialisieren
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Admin-Menü hinzufügen
     */
    public function add_admin_menu() {
        // Hauptseite "Fahrpläne" erstellen
        add_menu_page(
            'Fahrpläne',
            'Fahrpläne',
            'edit_posts',
            'fahrplaene',
            array($this, 'admin_page'),
            'dashicons-calendar-alt',
            30
        );
        
        // Erste Unterseite "Portal Verwaltung"
        add_submenu_page(
            'fahrplaene',
            'Portal Verwaltung',
            'Portal Verwaltung',
            'edit_posts',
            'fahrplaene',
            array($this, 'admin_page')
        );
        
        // DB-Wartung als weitere Unterseite
        add_submenu_page(
            'fahrplaene',
            'DB Wartung',
            'DB Wartung',
            'manage_options',
            'fahrplanportal-db',
            array($this, 'db_maintenance_page')
        );
    }
    
    /**
     * ✅ ERWEITERT: Admin-Scripts laden mit Publisher-Support
     */
    public function enqueue_admin_scripts($hook) {
        // ✅ GEFIXT: Nur auf Fahrplan-Admin-Seiten laden
        if (strpos($hook, 'fahrplaene') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        wp_enqueue_script(
            'fahrplanportal-admin',
            plugins_url('assets/admin/admin.js', dirname(__FILE__)),
            array('jquery'),
            '3.0.0', // ✅ Version erhöht für Publisher-Features
            true
        );
        
        // ✅ ERWEITERT: Unified AJAX Config mit Publisher-Features
        wp_localize_script('fahrplanportal-admin', 'fahrplanportal_unified', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('unified_ajax_master_nonce'),
            'action' => 'unified_ajax',
            'module' => 'fahrplanportal',
            'pdf_parsing_enabled' => $this->pdf_parsing_enabled,
            'publisher_enabled' => !is_null($this->publisher),  // ✅ NEU
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'context' => 'admin_fahrplanportal_publisher'        // ✅ NEU
        ));
        
        wp_enqueue_style(
            'fahrplanportal-admin',
            plugins_url('assets/admin/admin.css', dirname(__FILE__)),
            array(),
            '3.0.0'
        );
        
        error_log('✅ FAHRPLANPORTAL: Admin-Scripts geladen für: ' . $hook . ' (mit Publisher-Support)');
    }
    
    /**
     * ✅ ERWEITERT: Hauptadmin-Seite mit Publisher-Bereich
     */
    public function admin_page() {
        $available_folders = $this->get_available_folders();
        
        // ✅ NEU: Publisher-Statistiken laden
        $publish_stats = $this->get_publisher_stats();
        ?>
        <div class="wrap">
            <h1>Fahrplanportal Verwaltung</h1>
            
            <?php if (!$this->pdf_parsing_enabled): ?>
                <div class="notice notice-warning">
                    <p><strong>Hinweis:</strong> PDF-Parsing ist nicht verfügbar. Tags werden nicht automatisch generiert. 
                    Stelle sicher, dass der Smalot PDF Parser korrekt geladen ist.</p>
                </div>
            <?php endif; ?>
            
            <!-- ✅ BESTEHEND: Scan-Bereich (unverändert) -->
            <div class="fahrplan-controls">
                <p>
                    <label for="scan-year">Ordner auswählen:</label>
                    <select id="scan-year">
                        <?php if (empty($available_folders)): ?>
                            <option value="">Keine Ordner gefunden</option>
                        <?php else: ?>
                            <?php foreach ($available_folders as $folder): ?>
                                <option value="<?php echo esc_attr($folder); ?>"><?php echo esc_html($folder); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button type="button" id="scan-directory" class="button button-primary" <?php echo empty($available_folders) ? 'disabled' : ''; ?>>
                        Verzeichnis scannen
                    </button>
                    <span id="scan-status"></span>
                </p>
                
                <?php if (empty($available_folders)): ?>
                    <p class="description" style="color: #d63031;">
                        <strong>Hinweis:</strong> Keine Unterordner im Verzeichnis <code><?php echo esc_html($this->pdf_base_path); ?></code> gefunden.
                        <br>Erstelle Ordner wie <code>2025</code>, <code>testverzeichnis</code> etc. und lade PDF-Dateien hinein.
                    </p>
                <?php else: ?>
                    <p class="description">
                        <strong>Gefundene Ordner:</strong> <?php echo implode(', ', $available_folders); ?>
                        <br><strong>Struktur:</strong> <code>fahrplaene/[Ordner]/[Region]/fahrplan.pdf</code>
                        <br><strong>Beispiel:</strong> <code>fahrplaene/2025/villach-land/561-feldkirchen-unterberg.pdf</code> (2-3 stellige Nummern)
                        <br><strong>⚠️ Gültigkeit:</strong> Ordner <code>2025</code> = Fahrpläne gültig vom <strong>14.12.2024 bis 13.12.2025</strong>
                        <br><strong>🔄 Neue Nummernlogik:</strong> 2-3 stellige Nummern (561, 82) werden über Mapping zu alten 4-stelligen Nummern zugeordnet
                        <?php if ($this->pdf_parsing_enabled): ?>
                            <br><strong>PDF-Parsing:</strong> Aktiviert - Inhalte werden automatisch geparst und als Tags gespeichert!
                        <?php else: ?>
                            <br><strong>PDF-Parsing:</strong> Nicht verfügbar - nur Metadaten werden gespeichert.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- ✅ NEU: PUBLISHER-BEREICH -->
            <?php if ($this->publisher): ?>
            <div class="publish-management">
                <h2>🚀 Live-Veröffentlichung</h2>
                
                <!-- Status-Übersicht -->
                <div class="publish-status-overview" style="
                    background: #f8f9fa;
                    border: 2px solid #dee2e6;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 20px;
                ">
                    <h3 style="margin-top: 0; color: #495057;">📊 System-Status</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <strong style="color: #6c757d; display: block; margin-bottom: 8px;">Staging-Bereich (Bearbeitung)</strong>
                            <div style="font-size: 24px; color: #28a745;" id="staging-count">
                                <?php echo $publish_stats['staging_count']; ?> Einträge
                            </div>
                            <small style="color: #6c757d;">Wird beim Scannen gefüllt</small>
                        </div>
                        
                        <div>
                            <strong style="color: #6c757d; display: block; margin-bottom: 8px;">Live-Bereich (Frontend)</strong>
                            <div style="font-size: 24px; color: #007cba;" id="live-count">
                                <?php echo $publish_stats['live_count']; ?> Einträge
                            </div>
                            <small style="color: #6c757d;">Für Website-Besucher sichtbar</small>
                        </div>
                        
                        <div>
                            <strong style="color: #6c757d; display: block; margin-bottom: 8px;">Synchronisation</strong>
                            <div style="font-size: 18px; color: <?php echo $publish_stats['tables_synced'] ? '#28a745' : '#dc3545'; ?>;" id="sync-status">
                                <?php echo $publish_stats['tables_synced'] ? '✅ Synchron' : '⚠️ Unterschiedlich'; ?>
                            </div>
                            <small style="color: #6c757d;">Staging ↔ Live Vergleich</small>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <strong style="color: #6c757d; display: block; margin-bottom: 8px;">Letzte Veröffentlichung</strong>
                            <div style="color: #495057;" id="last-publish">
                                <?php echo $publish_stats['last_publish'] ? date('d.m.Y H:i', strtotime($publish_stats['last_publish'])) : 'Noch nie veröffentlicht'; ?>
                            </div>
                        </div>
                        
                        <div>
                            <strong style="color: #6c757d; display: block; margin-bottom: 8px;">Backup verfügbar</strong>
                            <div style="color: <?php echo $publish_stats['has_backup'] ? '#28a745' : '#dc3545'; ?>;" id="backup-status">
                                <?php echo $publish_stats['has_backup'] ? '✅ Backup vorhanden' : '❌ Kein Backup'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Aktionen -->
                <div class="publish-actions" style="
                    background: #ffffff;
                    border: 2px solid #007cba;
                    border-radius: 8px;
                    padding: 20px;
                    margin-bottom: 20px;
                ">
                    <h3 style="margin-top: 0; color: #007cba;">🎯 Aktionen</h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <h4 style="color: #495057; margin: 0 0 10px 0;">Live veröffentlichen</h4>
                            <p style="margin: 0 0 15px 0; color: #6c757d; font-size: 14px;">
                                Kopiert alle Staging-Daten zum Live-System. 
                                Ein Backup wird automatisch erstellt.
                            </p>
                            <button type="button" id="publish-to-live" class="button button-primary" style="
                                background: #28a745; 
                                border-color: #28a745;
                                padding: 8px 16px;
                                font-size: 14px;
                            ">
                                <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                                🚀 Live veröffentlichen
                            </button>
                            <span id="publish-status" style="margin-left: 10px;"></span>
                        </div>
                        
                        <div>
                            <h4 style="color: #495057; margin: 0 0 10px 0;">Rollback durchführen</h4>
                            <p style="margin: 0 0 15px 0; color: #6c757d; font-size: 14px;">
                                Stellt den letzten Backup-Stand wieder her.
                                <?php if (!$publish_stats['has_backup']): ?>
                                <strong style="color: #dc3545;">Kein Backup verfügbar!</strong>
                                <?php endif; ?>
                            </p>
                            <button type="button" id="rollback-live" class="button button-secondary" style="
                                padding: 8px 16px;
                                font-size: 14px;
                            " <?php echo !$publish_stats['has_backup'] ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-undo" style="vertical-align: middle;"></span>
                                ⏪ Rollback durchführen
                            </button>
                            <span id="rollback-status" style="margin-left: 10px;"></span>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #dee2e6;">
                        <button type="button" id="refresh-publish-stats" class="button button-secondary">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                            Status aktualisieren
                        </button>
                        <small style="color: #6c757d; margin-left: 15px;">
                            Aktualisiert die Zähler und den Synchronisations-Status
                        </small>
                    </div>
                </div>
                
                <!-- Hinweise -->
                <div class="publish-info" style="
                    background: #fff3cd;
                    border: 2px solid #ffc107;
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 20px;
                ">
                    <h4 style="margin: 0 0 10px 0; color: #856404;">ℹ️ Wichtige Hinweise</h4>
                    <ul style="margin: 0; color: #856404; line-height: 1.6;">
                        <li><strong>Staging-Bereich:</strong> Hier bearbeiten Sie die Fahrpläne. Änderungen sind nicht sofort live.</li>
                        <li><strong>Live-Bereich:</strong> Dies sehen die Website-Besucher. Wird nur durch "Veröffentlichen" aktualisiert.</li>
                        <li><strong>Backup:</strong> Wird vor jeder Veröffentlichung automatisch erstellt für Rollback-Möglichkeit.</li>
                        <li><strong>Synchronisation:</strong> Zeigt an, ob Staging und Live identisch sind.</li>
                    </ul>
                </div>
            </div>
            
            <hr style="margin: 30px 0;">
            <?php else: ?>
            <div class="notice notice-info">
                <p><strong>Info:</strong> Publisher-System wird initialisiert. Bitte laden Sie die Seite neu.</p>
            </div>
            <hr style="margin: 30px 0;">
            <?php endif; ?>
            
            <!-- ✅ BESTEHEND: Fahrplan-Liste Header -->
            <div class="fahrplan-list-header">
                <div class="fahrplan-filter-controls">
                    <label for="region-filter">Nach Region filtern:</label>
                    <select id="region-filter">
                        <option value="">Alle Regionen anzeigen</option>
                    </select>
                    <button type="button" id="clear-filter" class="button button-secondary">Filter zurücksetzen</button>
                </div>
                
                <table id="fahrplaene-table" class="display nowrap" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Linie Alt</th>
                            <th>Linie Neu</th>
                            <th>Titel</th>
                            <th>Gültig von</th>
                            <th>Gültig bis</th>
                            <th>Ordner</th>
                            <th>Region</th>
                            <th>PDF</th>
                            <th>Kurzbeschreibung</th>
                            <?php if ($this->pdf_parsing_enabled): ?>
                                <th>Tags</th>
                            <?php endif; ?>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $this->get_fahrplaene_rows(); ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Modal für Bearbeitung -->
            <?php $this->render_edit_modal(); ?>
        </div>
        
        <!-- ✅ GEFIXT: Minimaler Admin-Init Script -->
        <script>
        // Admin-Only Initialisierung
        jQuery(document).ready(function($) {
            console.log('FAHRPLANPORTAL: Admin-Seite geladen, warte auf admin.js...');
            
            // Admin-Kontext bestätigen
            if (typeof fahrplanportal_unified !== 'undefined') {
                console.log('✅ FAHRPLANPORTAL: Admin-Kontext bestätigt:', fahrplanportal_unified.context);
            }
            
            // Spin-Animation für Dashicons
            $('<style>.dashicons.spinning { animation: spin 1s linear infinite; } @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>').appendTo('head');
        });
        </script>
        
        <!-- ✅ NEU: Publisher JavaScript -->
        <?php $this->render_publisher_js(); ?>
        <?php
    }
    
    /**
     * ✅ NEU: Publisher-Statistiken laden
     */
    private function get_publisher_stats() {
        if (!$this->publisher) {
            return array(
                'staging_count' => 0,
                'live_count' => 0,
                'backup_count' => 0,
                'last_publish' => '',
                'last_backup' => '',
                'has_backup' => false,
                'tables_synced' => false
            );
        }
        
        return $this->publisher->get_publish_statistics();
    }
    
    /**
     * ✅ NEU: Publisher JavaScript rendern
     */
    private function render_publisher_js() {
        if (!$this->publisher) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            
            // ✅ Publisher-AJAX-Hilfsfunktion
            function publisherAdminCall(action, data, options) {
                return fahrplanAdminCall(action, data, options);
            }
            
            // ✅ Status aktualisieren
            $('#refresh-publish-stats').on('click', function() {
                var $btn = $(this);
                var originalText = $btn.text();
                
                $btn.prop('disabled', true).text('Lädt...');
                
                publisherAdminCall('get_publish_stats', {}, {
                    success: function(stats) {
                        // UI aktualisieren
                        $('#staging-count').text(stats.staging_count + ' Einträge');
                        $('#live-count').text(stats.live_count + ' Einträge');
                        
                        var syncStatus = stats.tables_synced ? '✅ Synchron' : '⚠️ Unterschiedlich';
                        var syncColor = stats.tables_synced ? '#28a745' : '#dc3545';
                        $('#sync-status').text(syncStatus).css('color', syncColor);
                        
                        var lastPublish = stats.last_publish ? 
                            new Date(stats.last_publish).toLocaleString('de-DE') : 
                            'Noch nie veröffentlicht';
                        $('#last-publish').text(lastPublish);
                        
                        var backupStatus = stats.has_backup ? '✅ Backup vorhanden' : '❌ Kein Backup';
                        var backupColor = stats.has_backup ? '#28a745' : '#dc3545';
                        $('#backup-status').text(backupStatus).css('color', backupColor);
                        
                        // Rollback-Button aktivieren/deaktivieren
                        $('#rollback-live').prop('disabled', !stats.has_backup);
                        
                        $btn.text('✅ Aktualisiert');
                        setTimeout(function() {
                            $btn.text(originalText);
                        }, 2000);
                    },
                    error: function(error) {
                        alert('Fehler beim Aktualisieren: ' + error.message);
                        $btn.text(originalText);
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // ✅ Live veröffentlichen
            $('#publish-to-live').on('click', function() {
                var $btn = $(this);
                var $status = $('#publish-status');
                
                var confirmed = confirm(
                    'Live-Veröffentlichung starten?\n\n' +
                    '• Alle Staging-Daten werden live veröffentlicht\n' +
                    '• Ein Backup wird automatisch erstellt\n' +
                    '• Website-Besucher sehen sofort die neuen Daten\n\n' +
                    'Fortfahren?'
                );
                
                if (!confirmed) return;
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">🚀 Veröffentliche...</span>');
                
                publisherAdminCall('publish_to_live', {}, {
                    success: function(response) {
                        $status.html('<span style="color: green;">✅ ' + response.message + '</span>');
                        
                        // Status automatisch aktualisieren
                        setTimeout(function() {
                            $('#refresh-publish-stats').click();
                        }, 1000);
                        
                        setTimeout(function() {
                            $status.html('');
                        }, 5000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">❌ ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // ✅ Rollback durchführen
            $('#rollback-live').on('click', function() {
                var $btn = $(this);
                var $status = $('#rollback-status');
                
                var confirmed = confirm(
                    'Rollback durchführen?\n\n' +
                    '⚠️ ACHTUNG: Dies stellt den letzten Backup-Stand wieder her!\n' +
                    '• Alle aktuellen Live-Daten gehen verloren\n' +
                    '• Website-Besucher sehen die älteren Daten\n' +
                    '• Diese Aktion kann nicht rückgängig gemacht werden\n\n' +
                    'Rollback wirklich durchführen?'
                );
                
                if (!confirmed) return;
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">⏪ Stelle wieder her...</span>');
                
                publisherAdminCall('rollback_live', {}, {
                    success: function(response) {
                        $status.html('<span style="color: green;">✅ ' + response.message + '</span>');
                        
                        // Status automatisch aktualisieren
                        setTimeout(function() {
                            $('#refresh-publish-stats').click();
                        }, 1000);
                        
                        setTimeout(function() {
                            $status.html('');
                        }, 5000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">❌ ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // ✅ Status beim Laden aktualisieren
            $('#refresh-publish-stats').click();
        });
        </script>
        <?php
    }
    
    /**
     * Modal für Bearbeitung rendern
     */
    private function render_edit_modal() {
        ?>
        <!-- Modal bleibt unverändert -->
        <div id="fahrplan-edit-modal" class="fahrplan-modal">
            <div class="fahrplan-modal-content">
                <div class="fahrplan-modal-header">
                    <h2>Fahrplan bearbeiten</h2>
                    <button class="fahrplan-modal-close" id="close-modal-btn" type="button">&times;</button>
                </div>
                
                <div class="fahrplan-modal-body">
                    <form id="fahrplan-edit-form">
                        <input type="hidden" id="edit-id" value="">
                        
                        <div class="fahrplan-form-group">
                            <label for="edit-titel">Titel</label>
                            <input type="text" id="edit-titel" name="titel" required>
                        </div>
                        
                        <div class="fahrplan-form-row">
                            <div class="fahrplan-form-group">
                                <label for="edit-linie-alt">Linie Alt (4-stellig)</label>
                                <input type="text" id="edit-linie-alt" name="linie_alt" readonly>
                            </div>
                            <div class="fahrplan-form-group">
                                <label for="edit-linie-neu">Linie Neu (2-3 stellig)</label>
                                <input type="text" id="edit-linie-neu" name="linie_neu">
                            </div>
                        </div>
                        
                        <div class="fahrplan-form-group">
                            <label for="edit-kurzbeschreibung">Kurzbeschreibung</label>
                            <textarea id="edit-kurzbeschreibung" name="kurzbeschreibung"></textarea>
                        </div>
                        
                        <div class="fahrplan-form-row">
                            <div class="fahrplan-form-group">
                                <label for="edit-gueltig-von">Gültig von</label>
                                <input type="date" id="edit-gueltig-von" name="gueltig_von">
                            </div>
                            <div class="fahrplan-form-group">
                                <label for="edit-gueltig-bis">Gültig bis</label>
                                <input type="date" id="edit-gueltig-bis" name="gueltig_bis">
                            </div>
                        </div>
                        
                        <div class="fahrplan-form-group">
                            <label for="edit-region">Region</label>
                            <input type="text" id="edit-region" name="region">
                        </div>
                        
                        <?php if ($this->pdf_parsing_enabled): ?>
                            <div class="fahrplan-form-group">
                                <label for="edit-tags">Tags (kommagetrennt)</label>
                                <textarea id="edit-tags" name="tags" placeholder="Wort1, Wort2, Wort3..." rows="4"></textarea>
                                <small class="description">Tags werden automatisch beim PDF-Import generiert, können aber manuell bearbeitet werden.</small>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="fahrplan-modal-footer">
                    <button type="button" class="button button-secondary" id="cancel-edit-btn">
                        Abbrechen
                    </button>
                    <button type="button" class="button button-primary" id="save-edit-btn">
                        Speichern
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * DB-Wartungsseite - ✅ GEFIXT: Admin-Only Interface mit neuer Mapping-Erklärung
     */
    public function db_maintenance_page() {
        $current_exclusions = get_option('fahrplanportal_exclusion_words', '');
        $word_count = empty($current_exclusions) ? 0 : count(preg_split('/[\s,\t\n\r]+/', $current_exclusions, -1, PREG_SPLIT_NO_EMPTY));
        
        $current_mapping = get_option('fahrplanportal_line_mapping', '');
        $mapping_count = 0;
        if (!empty($current_mapping)) {
            $lines = preg_split('/[\n\r]+/', $current_mapping, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0) {
                    if (strpos($line, ':') !== false) {
                        $mapping_count++;
                    }
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Fahrplanportal - DB Wartung</h1>
            
            <?php if ($this->pdf_parsing_enabled): ?>
            <!-- ✅ GEÄNDERT: Exklusionswörter Sektion erweitert -->
            <div class="exclusion-management">
                <h3>🚫 Exklusionswörter für PDF-Parsing</h3>
                <p class="description">
                    Diese Wörter werden beim PDF-Parsing ignoriert und nicht als Tags gespeichert.
                    Hilft dabei, unwichtige Wörter aus der Suche herauszufiltern.
                    <br><strong>Aktuell:</strong> <?php echo $word_count; ?> Wörter
                    <br><strong>Format:</strong> Ein Wort pro Zeile oder durch Leerzeichen getrennt
                </p>
                
                <textarea id="exclusion-words" rows="15" cols="100" 
                          placeholder="Geben Sie Wörter ein, die beim PDF-Parsing ignoriert werden sollen:

aber alle allem allen aller alles also auch auf aus bei bin bis bist
dass den der des die dies doch dort durch ein eine einem einen einer eines
für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht
noch nur oder sich sie sind über und uns von war wird wir zu zum zur

fahrplan fahrt zug bus bahn haltestelle bahnhof station linie route verkehr
abfahrt ankunft uhrzeit zeit"
                          style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($current_exclusions); ?></textarea>
                
                <p>
                    <button type="button" id="save-exclusion-words" class="button button-primary">
                        <span class="dashicons dashicons-saved" style="vertical-align: middle;"></span> 
                        Exklusionswörter speichern
                    </button>
                    <button type="button" id="load-exclusion-words" class="button button-secondary">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> 
                        Neu laden
                    </button>
                    <button type="button" id="load-default-exclusions" class="button button-secondary">
                        Standard-Deutsche-Stoppwörter hinzufügen
                    </button>
                    <span id="exclusion-status" style="margin-left: 15px;"></span>
                </p>
                
                <details>
                    <summary style="cursor: pointer; font-weight: bold;">Tag-Analyse (Klicken zum Aufklappen)</summary>
                    <div style="margin-top: 15px;">
                        <p>
                            <button type="button" id="analyze-tags" class="button button-secondary">
                                🔍 Tag-Analyse durchführen
                            </button>
                            <span class="description">Analysiert alle Tags aus der Datenbank und zeigt an, welche Wörter ausgeschlossen sind.</span>
                        </p>
                        
                        <div id="tag-analysis-results" style="
                            display: none;
                            background: #f8f9fa;
                            border: 1px solid #dee2e6;
                            border-radius: 5px;
                            padding: 15px;
                            margin-top: 15px;
                            max-height: 400px;
                            overflow-y: auto;
                        ">
                            <!-- Analyse-Ergebnisse werden hier eingefügt -->
                        </div>
                    </div>
                    
                    <!-- Weitere Analyse-Bereiche werden hier eingefügt -->
                </div>
            </div>
            
            <hr style="margin: 30px 0;">
            <?php endif; ?>
            
            <!-- ✅ GEÄNDERT: SEKTION: Linien-Mapping mit neuer Erklärung -->
            <div class="line-mapping-management">
                <h3>🔄 Linien-Mapping (Neu → Alt) - NEUE NUMMERNLOGIK</h3>
                <div class="notice notice-info" style="margin: 10px 0;">
                    <p><strong>⚠️ WICHTIGE ÄNDERUNG:</strong> Das Mapping-Format wurde umgestellt!</p>
                    <p><strong>NEUES FORMAT:</strong> <code>neue_nummer:alte_nummer</code> (z.B. <code>100:5000</code>)</p>
                    <p><strong>Bedeutung:</strong> Neue 2-3 stellige Nummer <code>100</code> wird zur alten 4-stelligen Nummer <code>5000</code> zugeordnet</p>
                </div>
                <p class="description">
                    Das System erkennt jetzt 2-3 stellige Fahrplannummern (561, 82) als neue Hauptnummern und ordnet ihnen über diese Mapping-Tabelle die alten 4-stelligen Nummern zu.
                    <br><strong>Format:</strong> Eine Zuordnung pro Zeile im Format <code>neue_nummer:alte_nummer</code>
                    <br><strong>Beispiel:</strong> <code>100:5000</code> bedeutet: PDF mit neuer Nummer 100 wird auch die alte Nummer 5000 zugeordnet
                    <br><strong>Import-Logik:</strong> PDFs wie <code>100-feldkirchen-villach.pdf</code> bekommen automatisch beide Nummern (100 + 5000)
                    <br><strong>Aktuell:</strong> <?php echo $mapping_count; ?> Zuordnungen in der Mapping-Liste.
                </p>
                
                <div class="mapping-form">
                    <textarea id="line-mapping" name="line_mapping" rows="12" cols="100" 
                              placeholder="// ✅ NEUES Linien-Mapping Format: neue_nummer:alte_nummer
// Beispiele:
100:5000
101:5001
102:5002
561:5561
82:5082

// ⚠️ NICHT MEHR: 5000:100 (alte Format)
// ✅ JETZT: 100:5000 (neue Format)

// Kommentare mit // oder # sind erlaubt
# Mapping für Kärntner Linien"
                              style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($current_mapping); ?></textarea>
                    
                    <p>
                        <button type="button" id="save-line-mapping" class="button button-primary">
                            <span class="dashicons dashicons-saved" style="vertical-align: middle;"></span> 
                            Linien-Mapping speichern
                        </button>
                        <button type="button" id="load-line-mapping" class="button button-secondary">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> 
                            Neu laden
                        </button>
                        <span id="mapping-status" style="margin-left: 15px;"></span>
                    </p>
                    
                    <details>
                        <summary style="cursor: pointer; font-weight: bold;">Beispiel-Mapping laden (Klicken zum Aufklappen)</summary>
                        <p style="margin-top: 10px;">
                            <button type="button" id="load-example-mapping" class="button button-secondary">
                                ✅ Neue Format Beispiel-Zuordnungen hinzufügen
                            </button>
                            <small class="description" style="display: block; margin-top: 5px;">
                                Fügt Beispiel-Zuordnungen im neuen Format hinzu: 100:5000, 101:5001, 102:5002, etc.
                            </small>
                        </p>
                    </details>
                </div>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <div class="db-maintenance">
                <h3>Gefährliche Aktionen</h3>
                <p>
                    <button type="button" id="recreate-db" class="button button-secondary">
                        Datenbank neu erstellen
                    </button>
                    <span class="description">Löscht alle Daten und erstellt die Tabelle neu!</span>
                </p>
                
                <p>
                    <button type="button" id="clear-db" class="button button-secondary">
                        Alle Einträge löschen
                    </button>
                    <span class="description">Behält die Tabelle, löscht nur die Daten.</span>
                </p>
                
                <h3>Statistiken</h3>
                <p>Anzahl Fahrpläne: <strong><?php echo $this->database->get_fahrplaene_count(); ?></strong></p>
                <p>PDF-Parsing: <strong><?php echo $this->pdf_parsing_enabled ? 'Aktiviert' : 'Nicht verfügbar'; ?></strong></p>
                <?php if ($this->pdf_parsing_enabled): ?>
                <p>Exklusionsliste: <strong><?php echo $word_count; ?> Wörter</strong></p>
                <?php endif; ?>
                <p>Linien-Mapping: <strong><?php echo $mapping_count; ?> Zuordnungen (Neu → Alt Format)</strong></p>
                <?php if ($this->publisher): ?>
                <p>Publisher-System: <strong>✅ Aktiv</strong></p>
                <?php endif; ?>
            </div>

            <?php if ($this->pdf_parsing_enabled): ?>
            <hr style="margin: 30px 0;">

            <!-- ✅ NEU: TAG-ANALYSE SEKTION -->
            <div class="tag-analysis-management">
                <h3>🔍 Tag-Analyse & Optimierung</h3>
                <p class="description">
                    Analysiert alle Tags aus allen Fahrplänen in der Datenbank und gleicht sie mit der Exklusionsliste ab.
                    Hilft dabei, die Tag-Qualität zu verbessern und unerwünschte Wörter zu identifizieren.
                    <br><strong>Funktion:</strong> Sammelt alle eindeutigen Tags und zeigt an, welche bereits ausgeschlossen sind (grün) und welche noch nicht (rot).
                </p>
                
                <div class="tag-analysis-container">
                    <p>
                        <button type="button" id="analyze-tags-detailed" class="button button-primary">
                            <span class="dashicons dashicons-chart-pie" style="vertical-align: middle;"></span>
                            🔬 Detaillierte Tag-Analyse starten
                        </button>
                        <span id="tag-analysis-status" style="margin-left: 15px;"></span>
                    </p>
                    
                    <div id="tag-analysis-detailed-results" style="
                        display: none;
                        background: white;
                        border: 2px solid #007cba;
                        border-radius: 8px;
                        padding: 20px;
                        margin-top: 20px;
                    ">
                        <!-- Detaillierte Analyse-Ergebnisse werden hier eingefügt -->
                    </div>
                    
                    <!-- Weitere Analyse-Bereiche werden hier eingefügt -->
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- JavaScript für DB-Wartung -->
        <?php $this->render_maintenance_js(); ?>
        <?php
    }
    
    /**
     * JavaScript für DB-Wartungsseite rendern
     */
    private function render_maintenance_js() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Standard-Exklusionsliste Button
            $('#load-default-exclusions').on('click', function() {
                var defaultExclusions = `aber alle allem allen aller alles also auch auf aus bei bin bis bist dass den der des die dies doch dort durch ein eine einem einen einer eines für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht noch nur oder sich sie sind über und uns von war wird wir zu zum zur

fahrplan fahrt zug bus bahn haltestelle bahnhof station linie route verkehr abfahrt ankunft uhrzeit zeit

montag dienstag mittwoch donnerstag freitag samstag sonntag
januar februar märz april mai juni juli august september oktober november dezember

gehen geht ging kommt kommen kam kann könnte sollte würde
haben hat hatte sein war waren werden wird wurde`;
                
                var currentExclusions = $('#exclusion-words').val().trim();
                if (currentExclusions) {
                    $('#exclusion-words').val(currentExclusions + '\n\n' + defaultExclusions);
                } else {
                    $('#exclusion-words').val(defaultExclusions);
                }
                
                alert('Standard-Deutsche-Stoppwörter hinzugefügt!');
            });
            
            // ✅ NEU: Erweiterte Mapping-Beispiele mit Buchstaben-Zahl-Kombinationen laden
            $('#load-example-mapping').on('click', function() {
                var newMappingExample = `// ✅ ERWEITERTE Format Beispiel-Zuordnungen (neue_bezeichnung:alte_bezeichnung)
// Standard-Mapping für Kärntner Linien

// ✅ NEU: Buchstaben-Zahl-Kombinationen (X-Linien, Schnellbus, etc.)
X1:SB1
X2:SB2
X3:SB3
X4:SB4
X5:SB5

// Standard 2-3 stellige zu 4-stelligen Nummern
100:5000
101:5001
102:5002
561:5561
82:5082
83:5083
84:5084
85:5085

// Weitere Beispiele
200:5200
300:5300
400:5400
500:5500`;
                
                var currentMapping = $('#line-mapping').val().trim();
                if (currentMapping) {
                    $('#line-mapping').val(currentMapping + '\n\n' + newMappingExample);
                } else {
                    $('#line-mapping').val(newMappingExample);
                }
                
                $('#mapping-status').html('<span style="color: blue;">ℹ Admin Beispiel-Zuordnungen hinzugefügt. Klicken Sie "Speichern" um zu übernehmen.</span>');
                setTimeout(function() {
                    $('#mapping-status').html('');
                }, 5000);
            });
            
            // Admin Exklusionswörter speichern
            $('#save-exclusion-words').on('click', function() {
                var $btn = $(this);
                var $status = $('#exclusion-status');
                var exclusionText = $('#exclusion-words').val();
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">Admin speichert...</span>');
                
                fahrplanAdminCall('save_exclusion_words', {exclusion_words: exclusionText}, {
                    success: function(response) {
                        $status.html('<span style="color: green;">✓ Admin gespeichert (' + response.word_count + ' Wörter)</span>');
                        setTimeout(function() {
                            $status.html('');
                        }, 3000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Admin Exklusionswörter laden
            $('#load-exclusion-words').on('click', function() {
                var $btn = $(this);
                var $status = $('#exclusion-status');
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">Admin lädt...</span>');
                
                fahrplanAdminCall('load_exclusion_words', {}, {
                    success: function(response) {
                        $('#exclusion-words').val(response.exclusion_words);
                        $status.html('<span style="color: green;">✓ Admin geladen (' + response.word_count + ' Wörter)</span>');
                        setTimeout(function() {
                            $status.html('');
                        }, 3000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Admin Linien-Mapping speichern
            $('#save-line-mapping').on('click', function() {
                var $btn = $(this);
                var $status = $('#mapping-status');
                var mappingText = $('#line-mapping').val();
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">Admin speichert...</span>');
                
                fahrplanAdminCall('save_line_mapping', {line_mapping: mappingText}, {
                    success: function(response) {
                        $status.html('<span style="color: green;">✓ Admin gespeichert (' + response.mapping_count + ' Zuordnungen)</span>');
                        setTimeout(function() {
                            $status.html('');
                        }, 3000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Admin Linien-Mapping laden
            $('#load-line-mapping').on('click', function() {
                var $btn = $(this);
                var $status = $('#mapping-status');
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">Admin lädt...</span>');
                
                fahrplanAdminCall('load_line_mapping', {}, {
                    success: function(response) {
                        $('#line-mapping').val(response.line_mapping);
                        $status.html('<span style="color: green;">✓ Admin geladen (' + response.mapping_count + ' Zuordnungen)</span>');
                        setTimeout(function() {
                            $status.html('');
                        }, 3000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">✗ Admin Fehler: ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Admin DB-Wartung Buttons
            $('#recreate-db').on('click', function() {
                if (!confirm('Admin: Wirklich die komplette Datenbank neu erstellen? Alle Daten gehen verloren!')) {
                    return;
                }
                
                fahrplanAdminCall('recreate_db', {}, {
                    success: function(response) {
                        alert('Admin: Datenbank erfolgreich neu erstellt');
                        location.reload();
                    },
                    error: function(error) {
                        alert('Admin Fehler: ' + error.message);
                    }
                });
            });
            
            $('#clear-db').on('click', function() {
                if (!confirm('Admin: Wirklich alle Fahrpläne löschen?')) {
                    return;
                }
                
                fahrplanAdminCall('clear_db', {}, {
                    success: function(response) {
                        alert('Admin: Alle Fahrpläne erfolgreich gelöscht');
                        location.reload();
                    },
                    error: function(error) {
                        alert('Admin Fehler: ' + error.message);
                    }
                });
            });
            
            // ✅ NEU: Tag-Analyse
            $('#analyze-tags-detailed').on('click', function() {
                var $btn = $(this);
                var $status = $('#tag-analysis-status');
                var $results = $('#tag-analysis-detailed-results');
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">🔬 Analysiere Tags...</span>');
                
                fahrplanAdminCall('analyze_tags', {}, {
                    success: function(response) {
                        $status.html('<span style="color: green;">✓ Analyse abgeschlossen</span>');
                        
                        var html = '<h4>📊 Tag-Analyse Ergebnisse</h4>';
                        html += '<p><strong>Gesamt-Tags:</strong> ' + response.total_count + ' | ';
                        html += '<strong>Eindeutige Wörter:</strong> ' + response.unique_count + '</p>';
                        
                        if (response.unique_words.length > 0) {
                            html += '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
                            
                            response.unique_words.forEach(function(word) {
                                var color = word.excluded ? '#28a745' : '#dc3545';
                                var icon = word.excluded ? '✅' : '❌';
                                html += '<span style="color: ' + color + '; margin-right: 15px;">';
                                html += icon + ' ' + word.word + ' (' + word.count + 'x)';
                                html += '</span>';
                            });
                            
                            html += '</div>';
                        } else {
                            html += '<p>Keine Tags gefunden.</p>';
                        }
                        
                        $results.html(html).show();
                        
                        setTimeout(function() {
                            $status.html('');
                        }, 3000);
                    },
                    error: function(error) {
                        $status.html('<span style="color: red;">✗ Fehler: ' + error.message + '</span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Verfügbare Ordner ermitteln
     */
    private function get_available_folders() {
        $folders = array();
        $directories = glob($this->pdf_base_path . '*', GLOB_ONLYDIR);
        
        foreach ($directories as $dir) {
            $dirname = basename($dir);
            if (substr($dirname, 0, 1) !== '.') {
                $folders[] = $dirname;
            }
        }
        
        // Sortierung: Jahre zuerst, dann alphabetisch
        usort($folders, function($a, $b) {
            $a_is_year = preg_match('/^\d{4}$/', $a);
            $b_is_year = preg_match('/^\d{4}$/', $b);
            
            if ($a_is_year && $b_is_year) {
                return $b <=> $a;
            }
            if ($a_is_year && !$b_is_year) {
                return -1;
            }
            if (!$a_is_year && $b_is_year) {
                return 1;
            }
            return strcasecmp($a, $b);
        });
        
        return $folders;
    }
    
    /**
     * Fahrpläne aus DB laden - NEUE SPALTENREIHENFOLGE
     */
    private function get_fahrplaene_rows() {
        $results = $this->database->get_all_fahrplaene();
        
        $colspan = $this->pdf_parsing_enabled ? 12 : 11;
        
        if (empty($results)) {
            return '<tr><td colspan="' . $colspan . '">Keine Fahrpläne gefunden. Verwenden Sie "Verzeichnis scannen".</td></tr>';
        }
        
        $output = '';
        foreach ($results as $row) {
            $pdf_url = $this->utils->get_pdf_url($row->pdf_pfad);
            
            // Datum in deutsches Format umwandeln
            $gueltig_von_de = $this->utils->format_german_date($row->gueltig_von);
            $gueltig_bis_de = $this->utils->format_german_date($row->gueltig_bis);
            
            // Region-Feld
            $region = isset($row->region) ? $row->region : '';
            
            // Tags-Spalte nur wenn verfügbar
            $tags_column = '';
            if ($this->pdf_parsing_enabled) {
                $tags_display = $this->utils->format_tags_for_display($row->tags ?? '', $this->pdf_parsing_enabled);
                $tags_column = '<td>' . $tags_display . '</td>';
            }
            
            $output .= '<tr data-id="' . esc_attr($row->id) . '">';
            $output .= '<td>' . esc_html($row->id) . '</td>';
            $output .= '<td>' . esc_html($row->linie_alt) . '</td>';
            $output .= '<td style="cursor: pointer;" title="Klicken zum Bearbeiten">' . esc_html($row->linie_neu) . '</td>';
            $output .= '<td>' . esc_html($row->titel) . '</td>';
            $output .= '<td>' . esc_html($gueltig_von_de) . '</td>';
            $output .= '<td>' . esc_html($gueltig_bis_de) . '</td>';
            $output .= '<td>' . esc_html($row->jahr) . '</td>';
            $output .= '<td>' . esc_html($region) . '</td>';
            $output .= '<td><a href="' . esc_url($pdf_url) . '" target="_blank">PDF öffnen</a></td>';
            $output .= '<td>' . esc_html($row->kurzbeschreibung) . '</td>';
            $output .= $tags_column;
            $output .= '<td>';
            $output .= '<button class="button button-secondary edit-fahrplan" data-id="' . esc_attr($row->id) . '" style="margin-right: 5px;">Bearbeiten</button>';
            $output .= '<button class="button button-secondary delete-fahrplan" data-id="' . esc_attr($row->id) . '">Löschen</button>';
            $output .= '</td>';
            $output .= '</tr>';
        }
        
        return $output;
    }
}