<?php
/**
 * FahrplanPortal Admin Class
 * WordPress Admin-Interface, Menüs und Admin-Seiten
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
    
    public function __construct($database, $utils, $pdf_base_path, $pdf_parsing_enabled) {
        $this->database = $database;
        $this->utils = $utils;
        $this->pdf_base_path = $pdf_base_path;
        $this->pdf_parsing_enabled = $pdf_parsing_enabled;
        
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
            'edit_posts',
            'fahrplanportal-db',
            array($this, 'db_maintenance_page')
        );
    }
    
    /**
     * Admin-Scripts laden - ✅ GEFIXT: Nur im relevanten Admin-Bereich
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
            '2.5.0', // ✅ Version erhöht für Chunked Scanning
            true
        );
        
        // ✅ GEFIXT: Unified AJAX Config nur für Admin
        wp_localize_script('fahrplanportal-admin', 'fahrplanportal_unified', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('unified_ajax_master_nonce'),
            'action' => 'unified_ajax',
            'module' => 'fahrplanportal',
            'pdf_parsing_enabled' => $this->pdf_parsing_enabled,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'context' => 'admin_fahrplanportal_chunked'
        ));
        
        wp_enqueue_style(
            'fahrplanportal-admin',
            plugins_url('assets/admin/admin.css', dirname(__FILE__)),
            array(),
            '2.5.0'
        );
        
        error_log('✅ FAHRPLANPORTAL: Admin-Scripts geladen für: ' . $hook);
    }
    
    /**
     * Hauptadmin-Seite - ✅ GEFIXT: Admin-Only Interface
     */
    public function admin_page() {
        $available_folders = $this->get_available_folders();
        ?>
        <div class="wrap">
            <h1>Fahrplanportal Verwaltung</h1>
            
            <?php if (!$this->pdf_parsing_enabled): ?>
                <div class="notice notice-warning">
                    <p><strong>Hinweis:</strong> PDF-Parsing ist nicht verfügbar. Tags werden nicht automatisch generiert. 
                    Stelle sicher, dass der Smalot PDF Parser korrekt geladen ist.</p>
                </div>
            <?php endif; ?>
            
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
                        <br>Erstelle Ordner wie <code>2026</code>, <code>2027</code> etc. und lade PDF-Dateien hinein.
                    </p>
                <?php else: ?>
                    <p class="description">
                        <strong>Gefundene Ordner:</strong> <?php echo implode(', ', $available_folders); ?>
                        <br><strong>Struktur:</strong> <code>fahrplaene/[Ordner]/[Region]/fahrplan.pdf</code>
                        <br><strong>Beispiel:</strong> <code>fahrplaene/2026/villach-land/561-feldkirchen-unterberg.pdf</code>
                        <br><strong>⚠️ Gültigkeit:</strong> Ordner <code>[2026]</code> = Fahrpläne gültig vom <strong>14.12.[2025] bis 13.12.[2026]</strong>
                        <?php if ($this->pdf_parsing_enabled): ?>
                            <br><strong>PDF-Parsing:</strong> Aktiviert - Inhalte werden automatisch geparst und als Tags gespeichert!
                        <?php else: ?>
                            <br><strong>PDF-Parsing:</strong> Nicht verfügbar - nur Metadaten werden gespeichert.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- ✅ NEU: Chunked Progress Bar -->
            <div id="scan-progress-container" style="display: none;">
                <div class="card" style="border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 6px;">
                    <h4 style="margin: 0 0 15px 0; color: #0073aa;">
                        <i class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></i>
                        PDF-Scanning läuft...
                    </h4>
                    
                    <!-- Progress Bar -->
                    <div class="progress mb-3" style="height: 20px; background: #f1f1f1; border-radius: 10px; overflow: hidden;">
                        <div id="scan-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" 
                             style="width: 0%; background: linear-gradient(90deg, #0073aa, #005a87); height: 100%; border-radius: 10px;">
                        </div>
                    </div>
                    
                    <!-- Progress Text -->
                    <div class="row mb-3" style="margin: 0;">
                        <div class="col-sm-6" style="padding: 0;">
                            <strong id="scan-progress-text">0% (0/0 PDFs)</strong>
                        </div>
                        <div class="col-sm-6 text-right" style="padding: 0; text-align: right;">
                            <span id="scan-time-remaining">Geschätzte Zeit: berechne...</span>
                        </div>
                    </div>
                    
                    <!-- Current File -->
                    <div class="mb-3">
                        <small><strong>Aktuell:</strong> <span id="scan-current-file">Bereite vor...</span></small>
                    </div>
                    
                    <!-- Statistics -->
                    <div class="row mb-3" style="margin: 0;">
                        <div class="col-sm-3" style="padding: 0 10px 0 0;">
                            <span class="badge badge-success" style="background: #46b450; color: white; padding: 5px 10px;">
                                ✓ Importiert: <span id="scan-imported">0</span>
                            </span>
                        </div>
                        <div class="col-sm-3" style="padding: 0 10px;">
                            <span class="badge badge-info" style="background: #00a0d2; color: white; padding: 5px 10px;">
                                ⏳ Übersprungen: <span id="scan-skipped">0</span>
                            </span>
                        </div>
                        <div class="col-sm-3" style="padding: 0 10px;">
                            <span class="badge badge-danger" style="background: #dc3232; color: white; padding: 5px 10px;">
                                ✗ Fehler: <span id="scan-errors">0</span>
                            </span>
                        </div>
                        <div class="col-sm-3" style="padding: 0 0 0 10px;">
                            <span class="badge badge-secondary" style="background: #666; color: white; padding: 5px 10px;">
                                📊 Chunk: <span id="scan-current-chunk">0/0</span>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Region Activity -->
                    <div class="mb-3">
                        <h5 style="margin: 0 0 10px 0;">Letzte Aktivität:</h5>
                        <div id="scan-region-activity" style="max-height: 100px; overflow-y: auto; background: #f9f9f9; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                            <div class="text-muted">Bereit zum Scannen...</div>
                        </div>
                    </div>
                    
                    <!-- Cancel Button -->
                    <div class="text-center">
                        <button type="button" id="scan-cancel" class="button button-secondary" style="background: #dc3232; color: white; border-color: #dc3232;">
                            ❌ Abbrechen
                        </button>
                    </div>
                </div>
            </div>
            
            <div id="fahrplaene-container">
                <div class="fahrplan-filter-controls">
                    <label for="region-filter">Nach Region filtern:</label>
                    <select id="region-filter">
                        <option value="">Alle Regionen anzeigen</option>
                    </select>
                    <button type="button" id="clear-filter" class="button button-secondary">Filter zurücksetzen</button>
                </div>

                <!-- ✅ NEU: Tabelle Status Aktualisierung - ERWEITERT für zweistufige Synchronisation -->
                <div style="margin: 20px 0; border-top: 1px solid #ddd; padding-top: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px; flex-wrap: wrap;">
                        <button type="button" id="update-table-status" class="button button-secondary">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                            Tabelle aktualisieren
                        </button>
                        <button type="button" id="delete-missing-pdfs" class="button button-secondary" style="background: #dc3545; border-color: #dc3545; color: white; display: none;">
                            <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>
                            Fehlende PDFs löschen
                        </button>
                        <button type="button" id="show-missing-details" class="button button-link" style="display: none;">
                            <span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 5px;"></span>
                            Details anzeigen
                        </button>
                        <span id="status-update-info" style="color: #666; font-size: 14px;">
                            Überprüft PDF-Status und findet neue Dateien
                        </span>
                    </div>
                    
                    <!-- ✅ NEU: Details-Container für fehlende PDFs -->
                    <div id="missing-pdfs-details" style="display: none; margin-top: 15px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                        <h4 style="margin: 0 0 10px 0; color: #856404;">Fehlende PDFs</h4>
                        <div id="missing-pdfs-list" style="max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px; color: #856404;">
                            <!-- Wird von JavaScript gefüllt -->
                        </div>
                        <p style="margin: 10px 0 0 0; font-size: 13px; color: #856404;">
                            <strong>Hinweis:</strong> Diese Dateien wurden im Dateisystem nicht gefunden. 
                            Klicken Sie "Fehlende PDFs löschen" um sie endgültig aus der Datenbank zu entfernen.
                        </p>
                    </div>
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
                            <th>Status</th>
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
                                <small>Linien Mapping in der DB Wartung durchführen.</small>
                                <input type="text" id="edit-linie-alt" name="linie_alt" readonly>
                            </div>
                            <div class="fahrplan-form-group">
                                <label for="edit-linie-neu">Linie Neu (2-3 stellig)</label>
                                <small>Linien Mapping in der DB Wartung durchführen.</small>
                                <input type="text" id="edit-linie-neu" name="linie_neu" readonly>
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
     * ✅ DB-Wartungsseite - ANGEPASST: Konditionelle UI für Admin/Redakteur
     */
    public function db_maintenance_page() {
        $current_exclusions = get_option('fahrplanportal_exclusion_words', '');
        $word_count = empty($current_exclusions) ? 0 : count(preg_split('/[\s,\t\n\r]+/', $current_exclusions, -1, PREG_SPLIT_NO_EMPTY));
        
        $current_mapping = get_option('fahrplanportal_line_mapping', '');
        $mapping_count = 0;
        if (!empty($current_mapping)) {
            $lines = preg_split('/[\n\r]+/', $current_mapping, -1, PREG_SPLIT_NO_EMPTY);
            $mapping_count = count(array_filter($lines, function($line) {
                $line = trim($line);
                return !empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0;
            }));
        }
        
        // ✅ Berechtigungsprüfung für verschiedene Bereiche
        $is_admin = current_user_can('manage_options');
        $can_edit = current_user_can('edit_posts');
        ?>
        <div class="wrap">
            <h1>Datenbank Wartung</h1>
            
            <!-- ✅ LINIEN-MAPPING SEKTION - FÜR ALLE BENUTZER MIT edit_posts -->
            <?php if ($can_edit): ?>
            <div class="line-mapping-management">
                <h3>🔄 Linien-Mapping (Neu → Alt) - NEUE NUMMERNLOGIK</h3>
                
                <p class="description">
                    Das System erkennt 2-3 stellige Fahrplannummern (561, 82) als neue Hauptnummern und ordnet diese über eine Mapping-Tabelle den alten 4-stelligen Nummern zu.
                    <br><strong>Format:</strong> Eine Zuordnung pro Zeile im Format <code>neue_nummer:alte_nummer</code>
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
                        
                        <!-- ✅ NEU: Mapping DB-Abgleich Button -->
                        <button type="button" id="update-mapping-in-db" class="button button-secondary" 
                                style="margin-left: 10px;">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> 
                            Mapping Tabelle mit DB aktualisieren
                        </button>
                        
                        <span id="mapping-status" style="margin-left: 15px;"></span>
                    </p>
                    
                    <!-- ✅ NEU: Erklärung für den neuen Button -->
                    <div style="background: #e8f4fd; border: 1px solid #0073aa; border-radius: 4px; padding: 10px; margin: 10px 0;">
                        <p style="margin: 0; font-size: 13px; color: #0073aa;">
                            <span class="dashicons dashicons-info" style="vertical-align: middle; margin-right: 5px;"></span>
                            <strong>Mapping DB-Abgleich:</strong> Aktualisiert alle bestehenden Fahrpläne in der Datenbank 
                            mit den neuen Mapping-Zuordnungen, ohne die PDFs nochmals einzulesen.
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ✅ ADMIN-ONLY SEKTION: Exklusionsliste und Tag-Analyse -->
            <?php if ($is_admin && $this->pdf_parsing_enabled): ?>
            <hr style="margin: 30px 0;">
            
            <!-- SEKTION: Exklusionsliste -->
            <div class="exclusion-management">
                <h3>PDF-Parsing Exklusionsliste</h3>
                <p class="description">
                    Hier können Sie Wörter definieren, die beim PDF-Parsing aus den Tags entfernt werden sollen. 
                    Trennen Sie die Wörter durch ein Komma.
                    <br><strong>Aktuell:</strong> <?php echo $word_count; ?> Wörter in der Exklusionsliste.
                </p>
                
                <div class="exclusion-form">
                    <textarea id="exclusion-words" name="exclusion_words" rows="8" cols="100" 
                              placeholder="aber alle allem allen aller alles also auch auf aus bei bin bis bist dass den der des die dies doch dort durch ein eine einem einen einer eines für hab hat hier ich ihr ihre ihrem ihren ihrer ihres ist mit nach nicht noch nur oder sich sie sind über und uns von war wird wir zu zum zur

fahrplan fahrt zug bus bahn haltestelle bahnhof station linie route verkehr abfahrt ankunft uhrzeit

montag dienstag mittwoch donnerstag freitag samstag sonntag"
                              style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($current_exclusions); ?></textarea>
                    
                    <p>
                        <button type="button" id="save-exclusion-words" class="button button-primary">
                            <span class="dashicons dashicons-saved" style="vertical-align: middle;"></span> 
                            Exklusionsliste speichern
                        </button>
                        <span id="exclusion-status" style="margin-left: 15px;"></span>
                    </p>
                </div>
            </div>

            <hr style="margin: 30px 0;">

            <!-- ✅ NEU: TAG-BEREINIGUNG SEKTION -->
            <div class="tag-cleanup-management">
                <h3>🧹 Tag-Bereinigung in Datenbank</h3>
                <p class="description">
                    Entfernt alle Exklusionswörter aus bereits gespeicherten Tags in der Datenbank.
                    <br><strong>Anwendung:</strong> Nach Änderungen an der Exklusionsliste ausführen, um bestehende Daten zu bereinigen.
                    <br><strong>Achtung:</strong> Diese Änderungen sind nicht rückgängig zu machen!
                </p>
                
                <div class="tag-cleanup-form">
                    <p>
                        <button type="button" id="cleanup-existing-tags" class="button button-primary" style="
                            background: linear-gradient(135deg, #e67e22 0%, #d68910 100%);
                            border-color: #d68910;
                            color: white;
                            font-weight: 600;
                            padding: 8px 16px;
                            box-shadow: 0 2px 8px rgba(230, 126, 34, 0.3);
                            transition: all 0.2s ease;
                        ">
                            <span class="dashicons dashicons-admin-tools" style="vertical-align: middle; margin-right: 5px;"></span>
                            Bestehende Tags bereinigen
                        </button>
                        <span id="cleanup-status" style="margin-left: 15px;"></span>
                    </p>
                    
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; font-weight: bold; color: #0073aa;">
                            ℹ️ Was macht diese Funktion? (Klicken zum Aufklappen)
                        </summary>
                        <div style="
                            background: #f8f9fa;
                            border: 2px solid #dee2e6;
                            border-radius: 8px;
                            padding: 15px;
                            margin-top: 10px;
                            font-size: 13px;
                            line-height: 1.5;
                        ">
                            <h4 style="margin: 0 0 10px 0; color: #495057;">Funktionsweise:</h4>
                            <ol style="margin: 0 0 15px 0; padding-left: 20px;">
                                <li><strong>Lädt alle Fahrpläne mit Tags</strong> aus der Datenbank</li>
                                <li><strong>Prüft jeden Tag</strong> gegen die aktuelle Exklusionsliste</li>
                                <li><strong>Entfernt alle Wörter</strong> die in der Exklusionsliste stehen</li>
                                <li><strong>Speichert bereinigte Tags</strong> zurück in die Datenbank</li>
                            </ol>
                            
                            <h4 style="margin: 0 0 10px 0; color: #495057;">Beispiel-Szenario:</h4>
                            <div style="background: white; padding: 10px; border-radius: 4px; margin-bottom: 10px;">
                                <strong>Vorher:</strong> "klagenfurt, bus, station, montag, verkehr"<br>
                                <strong>Nachher:</strong> "klagenfurt" (wenn "bus, station, montag, verkehr" in Exklusionsliste)
                            </div>
                            
                            <div style="
                                background: #fff3cd;
                                border: 1px solid #ffeaa7;
                                border-radius: 4px;
                                padding: 10px;
                                color: #856404;
                            ">
                                <strong>💡 Tipp:</strong> Führen Sie diese Funktion aus, nachdem Sie Ihre 
                                Exklusionsliste erweitert haben, um auch ältere Fahrpläne zu bereinigen.
                            </div>
                        </div>
                    </details>
                </div>
            </div>
            
            <hr style="margin: 30px 0;">

            <!-- ✅ NEU: TAG-ANALYSE SEKTION -->
            <div class="tag-analysis-management">
                <h3>🔍 Tag-Analyse & Optimierung</h3>
                <p class="description">
                    Analysiert alle Tags aus allen Fahrplänen in der Datenbank und gleicht sie mit der Exklusionsliste ab.
                    Hilft dabei, die Tag-Qualität zu verbessern und unerwünschte Wörter zu identifizieren.
                    <br><strong>Funktion:</strong> Sammelt alle eindeutigen Tags und zeigt an, welche bereits ausgeschlossen sind (grün) und welche noch nicht (rot).
                </p>
                
                <div class="tag-analysis-controls" style="margin: 20px 0;">
                    <p>
                        <button type="button" id="analyze-all-tags" class="button button-primary" style="
                            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                            border-color: #28a745;
                            color: white;
                            font-weight: 600;
                            padding: 8px 20px;
                            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
                        ">
                            <span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 5px;"></span>
                            Alle Tags analysieren
                        </button>
                        <span id="tag-analysis-status" style="margin-left: 15px;"></span>
                    </p>
                    
                    <div class="tag-analysis-info" style="
                        background: #e3f2fd;
                        border: 2px solid #2196f3;
                        border-radius: 8px;
                        padding: 15px;
                        margin-top: 15px;
                    ">
                        <h4 style="margin: 0 0 10px 0; color: #1565c0;">💡 Was passiert bei der Analyse:</h4>
                        <ol style="margin: 0; padding-left: 20px; color: #1565c0; line-height: 1.5;">
                            <li><strong>Sammeln:</strong> Alle Tags aus allen Fahrplänen werden gesammelt</li>
                            <li><strong>Bereinigen:</strong> Duplikate werden entfernt und alphabetisch sortiert</li>
                            <li><strong>Abgleichen:</strong> Jeder Tag wird gegen die aktuelle Exklusionsliste geprüft</li>
                            <li><strong>Kategorisieren:</strong> 
                                <span style="color: #28a745; font-weight: bold;">🟢 Grün = bereits ausgeschlossen</span>, 
                                <span style="color: #dc3545; font-weight: bold;">🔴 Rot = noch nicht ausgeschlossen</span>
                            </li>
                            <li><strong>Optimieren:</strong> Sie können rote Tags zur Exklusionsliste hinzufügen</li>
                        </ol>
                    </div>
                </div>
                
                <!-- ✅ NEU: ERGEBNISSE CONTAINER (KOMPLETT) -->
                <div id="tag-analysis-results" style="display: none; margin-top: 30px;">
                    
                    <!-- Statistiken -->
                    <div id="tag-analysis-statistics" style="
                        background: #fff3cd;
                        border: 2px solid #ffc107;
                        border-radius: 8px;
                        padding: 20px;
                        margin-bottom: 25px;
                    ">
                        <h4 style="margin: 0 0 15px 0; color: #856404;">📊 Analyse-Statistiken</h4>
                        <div id="tag-stats-content" style="
                            display: grid;
                            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                            gap: 15px;
                            color: #856404;
                            font-weight: 500;
                        ">
                            <!-- Wird von JavaScript gefüllt -->
                        </div>
                    </div>
                    
                    <!-- ✅ TAG-LISTEN: FEHLENDE CONTAINER HINZUGEFÜGT -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                        
                        <!-- Grüne Tags (Bereits ausgeschlossen) -->
                        <div style="
                            background: #d4edda;
                            border: 2px solid #28a745;
                            border-radius: 8px;
                            padding: 20px;
                        ">
                            <h4 style="margin: 0 0 15px 0; color: #155724;">
                                🟢 Bereits ausgeschlossene Tags (<span id="excluded-tags-count">0</span>)
                            </h4>
                            <div id="excluded-tags-list" style="
                                max-height: 300px;
                                overflow-y: auto;
                                padding: 10px;
                                background: rgba(255, 255, 255, 0.7);
                                border-radius: 5px;
                                color: #155724;
                                font-size: 13px;
                                line-height: 1.8;
                            ">
                                <!-- Wird von JavaScript gefüllt -->
                            </div>
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #c3e6cb;">
                                <p style="margin: 0; font-size: 12px; color: #155724;">
                                    ✅ Diese Tags werden bereits durch die Exklusionsliste gefiltert
                                </p>
                            </div>
                        </div>
                        
                        <!-- Rote Tags (Noch nicht ausgeschlossen) -->
                        <div style="
                            background: #f8d7da;
                            border: 2px solid #dc3545;
                            border-radius: 8px;
                            padding: 20px;
                        ">
                            <h4 style="margin: 0 0 15px 0; color: #721c24;">
                                🔴 Noch nicht ausgeschlossene Tags (<span id="not-excluded-tags-count">0</span>)
                            </h4>
                            <div id="not-excluded-tags-list" style="
                                max-height: 300px;
                                overflow-y: auto;
                                padding: 10px;
                                background: rgba(255, 255, 255, 0.7);
                                border-radius: 5px;
                                color: #721c24;
                                font-size: 13px;
                                line-height: 1.8;
                            ">
                                <!-- Wird von JavaScript gefüllt -->
                            </div>
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f5c6cb;">
                                <button type="button" id="copy-red-tags" class="button button-secondary" style="
                                    background: #dc3545;
                                    border-color: #dc3545;
                                    color: white;
                                    font-size: 12px;
                                    padding: 5px 15px;
                                ">
                                    📋 Rote Tags kopieren
                                </button>
                                <p style="margin: 5px 0 0 0; font-size: 12px; color: #721c24;">
                                    ⚠️ Diese Tags könnten zur Exklusionsliste hinzugefügt werden
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Zusätzliche Analysen (Optional) -->
                    <div style="margin-top: 25px;">
                        <button type="button" id="show-analysis-extras" class="button button-secondary" style="
                            background: #6c757d;
                            border-color: #6c757d;
                            color: white;
                        ">
                            📊 Zusätzliche Analysen anzeigen
                        </button>
                        
                        <div id="tag-analysis-extras" style="display: none; margin-top: 20px;">
                            <div style="
                                background: #e9ecef;
                                border: 2px solid #6c757d;
                                border-radius: 8px;
                                padding: 20px;
                            ">
                                <h4 style="margin: 0 0 15px 0; color: #495057;">📈 Erweiterte Analyse</h4>
                                
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                                    
                                    <!-- Top Häufige Tags -->
                                    <div>
                                        <h5 style="color: #495057; margin: 0 0 10px 0;">🏆 Top 10 häufigste Tags:</h5>
                                        <div id="frequent-tags-list" style="
                                            background: white;
                                            padding: 10px;
                                            border-radius: 5px;
                                            font-size: 12px;
                                            line-height: 1.8;
                                        ">
                                            <!-- Wird von JavaScript gefüllt -->
                                        </div>
                                    </div>
                                    
                                    <!-- Kurze Tags -->
                                    <div>
                                        <h5 style="color: #495057; margin: 0 0 10px 0;">🔍 Sehr kurze Tags (≤2 Zeichen):</h5>
                                        <div id="short-tags-list" style="
                                            background: white;
                                            padding: 10px;
                                            border-radius: 5px;
                                            font-size: 12px;
                                        ">
                                            <!-- Wird von JavaScript gefüllt -->
                                        </div>
                                    </div>
                                    
                                    <!-- Lange Tags -->
                                    <div>
                                        <h5 style="color: #495057; margin: 0 0 10px 0;">🔎 Sehr lange Tags (≥15 Zeichen):</h5>
                                        <div id="long-tags-list" style="
                                            background: white;
                                            padding: 10px;
                                            border-radius: 5px;
                                            font-size: 12px;
                                        ">
                                            <!-- Wird von JavaScript gefüllt -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ✅ ADMIN-ONLY SEKTION: Gefährliche DB-Operationen -->
            <?php if ($is_admin): ?>
            <hr style="margin: 30px 0;">
            
            <div class="db-operations" style="border-top: 2px solid #dc3545; padding-top: 20px; margin-top: 30px;">
                <h3 style="color: #dc3545;">⚠️ Gefährliche Datenbank-Operationen</h3>
                <p class="description" style="color: #d63031; font-weight: 500;">
                    Diese Funktionen sind nur für Administratoren verfügbar und können Datenverlust verursachen!
                </p>
                
                <p>
                    <button type="button" id="recreate-db" class="button button-secondary" style="background: #d63031; color: white; border-color: #a02622;">
                        🔄 Datenbank neu erstellen
                    </button>
                    <span class="description">Löscht alle Daten und erstellt die Tabelle neu!</span>
                </p>
                
                <p>
                    <button type="button" id="clear-db" class="button button-secondary" style="background: #d63031; color: white; border-color: #a02622;">
                        🗑️ Alle Einträge löschen
                    </button>
                    <span class="description">Behält die Tabelle, löscht nur die Daten.</span>
                </p>
            </div>
            <?php else: ?>
            <!-- Info für Nicht-Admins -->
            <hr style="margin: 30px 0;">
            <div class="db-info" style="border-top: 1px solid #ccc; padding-top: 20px; margin-top: 30px;">
                <p class="description" style="font-style: italic; color: #666;">
                    💡 <strong>Hinweis:</strong> Erweiterte Datenbank-Wartungsfunktionen sind nur für Administratoren verfügbar.
                </p>
            </div>
            <?php endif; ?>
            
            <!-- ✅ STATISTIKEN SEKTION - FÜR ALLE SICHTBAR -->
            <?php if ($can_edit): ?>
            <hr style="margin: 30px 0;">
            <div class="statistics-section">
                <h3>Statistiken</h3>
                <p>Anzahl Fahrpläne: <strong><?php echo $this->database->get_fahrplaene_count(); ?></strong></p>
                <p>PDF-Parsing: <strong><?php echo $this->pdf_parsing_enabled ? 'Aktiviert' : 'Nicht verfügbar'; ?></strong></p>
                <?php if ($is_admin && $this->pdf_parsing_enabled): ?>
                <p>Exklusionsliste: <strong><?php echo $word_count; ?> Wörter</strong></p>
                <?php endif; ?>
                <p>Linien-Mapping: <strong><?php echo $mapping_count; ?> Zuordnungen (Neu → Alt Format)</strong></p>
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
            // ✅ GEFIXT: Linien-Mapping speichern Event-Handler hinzugefügt
            $('#save-line-mapping').on('click', function() {
                var $btn = $(this);
                var $status = $('#mapping-status');
                var mappingText = $('#line-mapping').val();
                
                $btn.prop('disabled', true);
                $status.html('<span style="color: orange;">Speichere Mapping...</span>');
                
                // Verwende fahrplanAdminCall falls verfügbar, sonst jQuery AJAX
                if (typeof fahrplanAdminCall === 'function') {
                    fahrplanAdminCall('save_line_mapping', {line_mapping: mappingText}, {
                        success: function(response) {
                            $status.html('<span style="color: green;">✓ Gespeichert (' + response.mapping_count + ' Zuordnungen)</span>');
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
                } else {
                    // Fallback für direktes WordPress AJAX
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'unified_ajax',
                            module: 'fahrplanportal',
                            module_action: 'save_line_mapping',
                            line_mapping: mappingText,
                            nonce: '<?php echo wp_create_nonce("unified_ajax_master_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color: green;">✓ Gespeichert (' + response.data.mapping_count + ' Zuordnungen)</span>');
                                setTimeout(function() {
                                    $status.html('');
                                }, 3000);
                            } else {
                                $status.html('<span style="color: red;">✗ Fehler: ' + response.data + '</span>');
                            }
                        },
                        error: function(xhr, status, error) {
                            $status.html('<span style="color: red;">✗ AJAX-Fehler: ' + error + '</span>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false);
                        }
                    });
                }
            });

            // ✅ HINWEIS: Event-Handler für #update-mapping-in-db bereits in admin.js vorhanden
            // Daher hier NICHT nochmal registrieren (würde doppelte confirm()-Dialoge verursachen)

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
X10:SB10
X11:SB11
X12:SB12

// ✅ NEU: Weitere Buchstaben-Kombinationen
A1:SA1
A2:SA2
B1:SB1
B2:SB2
R1:REG1
R2:REG2

// ✅ NEU: Stadtbus-Kombinationen
ST1:STADT1
ST2:STADT2
ST3:STADT3

// Standard 2-3 stellige Nummern → 4-stellige Nummern
100:5000
101:5001
102:5002
103:5003
104:5004
105:5005
106:5006
107:5007
108:5008
109:5009
110:5010
111:5011
112:5012
113:5013
114:5014
115:5015

// Spezielle Linien
561:5561
82:5082
200:5200
201:5201
202:5202
401:5401
402:5402
403:5403

// Regionale Schnellverbindungen
300:5300
301:5301
302:5302
310:5310
311:5311
312:5312`;
                
                var currentMapping = $('#line-mapping').val().trim();
                if (currentMapping) {
                    $('#line-mapping').val(currentMapping + '\n\n' + newMappingExample);
                } else {
                    $('#line-mapping').val(newMappingExample);
                }
                
                alert('✅ ERWEITERTE Beispiel-Zuordnungen hinzugefügt!\n\n' +
                      '🆕 NEUE FEATURES:\n' +
                      '• Buchstaben-Zahl-Kombinationen: X1:SB1, X2:SB2\n' +
                      '• Kombinierte PDFs: X2-401-route.pdf\n' +
                      '• Mehrere Bezeichnungen pro PDF möglich\n\n' +
                      'Format: neue_bezeichnung:alte_bezeichnung\n' +
                      'Beispiel: X2:SB2 bedeutet X2 wird zu SB2 zugeordnet');
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
        
        if (!is_dir($this->pdf_base_path)) {
            return $folders;
        }
        
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
        
        $colspan = $this->pdf_parsing_enabled ? 13 : 12;
        
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
            
            // ✅ ERWEITERT: Status-Spalte basierend auf pdf_status
            $pdf_status = $row->pdf_status ?? 'OK';
            $status_display = '';
            
            switch ($pdf_status) {
                case 'OK':
                    $status_display = '<span class="status-ok">✅ OK</span>';
                    break;
                case 'MISSING':
                    $status_display = '<span class="status-missing">❌ Fehlt</span>';
                    break;
                case 'IMPORT':
                    $status_display = '<span class="status-import" data-pdf-path="' . esc_attr($row->pdf_pfad) . '">🔥 Import</span>';
                    break;
                default:
                    $status_display = '<span class="status-loading">⏳ Laden...</span>';
                    break;
            }
            
            // NEUE REIHENFOLGE: ID, Linie Alt, Linie Neu, Titel, Gültig von, Gültig bis, Status, Ordner, Region, PDF, Kurzbeschreibung, [Tags], Aktionen
            $output .= sprintf(
                '<tr data-id="%d" data-pdf-status="%s">
                    <td>%d</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td id="status-%d">%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td><a href="%s" target="_blank"><span class="dashicons dashicons-media-document"></span></a></td>
                    <td>%s</td>
                    %s
                    <td>
                        <button class="button button-small edit-fahrplan" data-id="%d" title="Bearbeiten">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button class="button button-small delete-fahrplan" data-id="%d" title="Löschen">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>',
                $row->id,                    // ID
                esc_attr($pdf_status),       // data-pdf-status
                $row->id,                    // ID (nochmal für Anzeige)
                esc_html($row->linie_alt),   // Linie Alt
                esc_html($row->linie_neu),   // Linie Neu  
                esc_html($row->titel),       // Titel
                esc_html($gueltig_von_de),   // Gültig von
                esc_html($gueltig_bis_de),   // Gültig bis
                $row->id,                    // Status id
                $status_display,             // Status Display (ERWEITERT)
                esc_html($row->jahr),        // Ordner
                esc_html($region),           // Region
                esc_url($pdf_url),           // PDF
                esc_html($row->kurzbeschreibung), // Kurzbeschreibung
                $tags_column,                // Tags (optional)
                $row->id,                    // Bearbeiten-Button ID
                $row->id                     // Löschen-Button ID
            );
        }
        
        return $output;
    }
}