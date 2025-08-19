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
            'manage_options',
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
                                ⟳ Übersprungen: <span id="scan-skipped">0</span>
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
            $mapping_count = count(array_filter($lines, function($line) {
                $line = trim($line);
                return !empty($line) && strpos($line, '//') !== 0 && strpos($line, '#') !== 0;
            }));
        }
        ?>
        <div class="wrap">
            <h1>Datenbank Wartung</h1>
            
            <?php if ($this->pdf_parsing_enabled): ?>
            <!-- SEKTION: Exklusionsliste -->
            <div class="exclusion-management">
                <h3>PDF-Parsing Exklusionsliste</h3>
                <p class="description">
                    Hier können Sie Wörter definieren, die beim PDF-Parsing aus den Tags entfernt werden sollen. 
                    Trennen Sie die Wörter durch Leerzeichen, Kommas oder Zeilenumbrüche.
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
                        <button type="button" id="load-exclusion-words" class="button button-secondary">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span> 
                            Neu laden
                        </button>
                        <span id="exclusion-status" style="margin-left: 15px;"></span>
                    </p>
                    
                    <details>
                        <summary style="cursor: pointer; font-weight: bold;">Standard-Exklusionsliste laden (Klicken zum Aufklappen)</summary>
                        <p style="margin-top: 10px;">
                            <button type="button" id="load-default-exclusions" class="button button-secondary">
                                Standard-Deutsche-Stoppwörter hinzufügen
                            </button>
                            <small class="description" style="display: block; margin-top: 5px;">
                                Fügt häufige deutsche Wörter zur Exklusionsliste hinzu (aber, der, die, das, etc.)
                            </small>
                        </p>
                    </details>
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
                
                <!-- ✅ NEU: ERGEBNISSE CONTAINER -->
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
            
            // NEUE REIHENFOLGE: ID, Linie Alt, Linie Neu, Titel, Gültig von, Gültig bis, Ordner, Region, PDF, Kurzbeschreibung, [Tags], Aktionen
            $output .= sprintf(
                '<tr data-id="%d">
                    <td>%d</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
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
                $row->id,                    // ID (nochmal für Anzeige)
                esc_html($row->linie_alt),   // Linie Alt
                esc_html($row->linie_neu),   // Linie Neu  
                esc_html($row->titel),       // Titel
                esc_html($gueltig_von_de),   // Gültig von
                esc_html($gueltig_bis_de),   // Gültig bis
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