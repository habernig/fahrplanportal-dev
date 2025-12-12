<?php
/**
 * FahrplanPortal Settings Class
 * Einstellungsseite mit AJAX-Speicherung fuer Redakteur-Berechtigung
 * 
 * Checkboxen:
 * 1. hide_results_until_search - Ergebnisbereich erst nach Filtersuche anzeigen
 * 2. disable_validity_check - Alle Fahrpläne anzeigen (Gültigkeitsprüfung deaktivieren)
 */

if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_Settings {
    
    const OPTION_NAME = 'fahrplanportal_settings';
    
    private $default_settings = array(
        'hide_results_until_search' => 1,
        'disable_validity_check' => 0,
        'scan_chunk_size' => 10,
    );
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_settings_submenu'), 99);
        add_action('wp_ajax_fahrplanportal_save_settings', array($this, 'ajax_save_settings'));
    }
    
    public function add_settings_submenu() {
        add_submenu_page(
            'fahrplaene',
            'Einstellungen',
            'Einstellungen',
            'edit_posts',
            'fahrplanportal-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function ajax_save_settings() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Keine Berechtigung');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fahrplanportal_settings_nonce')) {
            wp_send_json_error('Ungueltiger Sicherheitstoken');
            return;
        }
        
        $settings = array();
        $settings['hide_results_until_search'] = isset($_POST['hide_results_until_search']) && $_POST['hide_results_until_search'] === '1' ? 1 : 0;
        $settings['disable_validity_check'] = isset($_POST['disable_validity_check']) && $_POST['disable_validity_check'] === '1' ? 1 : 0;
        
        // Chunk-Size (zwischen 1 und 50, Default 10)
        $chunk_size = isset($_POST['scan_chunk_size']) ? intval($_POST['scan_chunk_size']) : 10;
        $settings['scan_chunk_size'] = max(1, min(50, $chunk_size));
        
        update_option(self::OPTION_NAME, $settings);
        
        wp_send_json_success(array(
            'message' => 'Einstellungen gespeichert',
            'settings' => $settings
        ));
    }
    
    public function get_settings() {
        $settings = get_option(self::OPTION_NAME, array());
        return wp_parse_args($settings, $this->default_settings);
    }
    
    public function get_setting($key, $default = null) {
        $settings = $this->get_settings();
        
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        
        if (isset($this->default_settings[$key])) {
            return $this->default_settings[$key];
        }
        
        return $default;
    }
    
    public function render_settings_page() {
        if (!current_user_can('edit_posts')) {
            wp_die('Sie haben keine Berechtigung.');
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-admin-settings" style="vertical-align: middle; margin-right: 10px;"></span>
                FahrplanPortal Einstellungen
                <?php echo $this->get_plugin_version_badge(); ?>
            </h1>
            
            <hr class="wp-header-end">
            
            <div id="fahrplanportal-settings-notice" style="display: none;"></div>
            
            <form id="fahrplanportal-settings-form" method="post">
                <?php wp_nonce_field('fahrplanportal_settings_nonce', 'fahrplanportal_settings_nonce'); ?>
                
                <div class="fahrplanportal-settings-container" style="margin-top: 20px;">
                    
                    <div class="card" style="padding: 20px; margin-bottom: 20px; max-width: 100%;">
                        <h2 style="margin-top: 0;">
                            <span class="dashicons dashicons-admin-generic" style="vertical-align: middle; margin-right: 8px;"></span>
                            Allgemeine Einstellungen
                        </h2>
                        
                        <p class="description" style="margin-bottom: 20px;">
                            Hier können Sie verschiedene Optionen für das FahrplanPortal konfigurieren.
                        </p>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                
                                <!-- Checkbox 1: Ergebnisbereich erst nach Filtersuche anzeigen -->
                                <tr>
                                    <th scope="row">Frontend-Anzeige</th>
                                    <td>
                                        <fieldset>
                                            <label for="hide_results_until_search">
                                                <input type="checkbox" 
                                                       name="hide_results_until_search" 
                                                       id="hide_results_until_search" 
                                                       value="1" 
                                                       <?php checked($settings['hide_results_until_search'], 1); ?>>
                                                Ergebnisbereich erst nach Filtersuche anzeigen
                                            </label>
                                            <p class="description">
                                                Wenn aktiviert, wird der Ergebnisbereich beim Laden des Shortcodes (ohne Region-Parameter) 
                                                zunächst ausgeblendet und erscheint erst, nachdem der Benutzer eine Suche durchgeführt hat.
                                            </p>
                                        </fieldset>
                                    </td>
                                </tr>
                                
                                <!-- Checkbox 2: Gueltigkeitspruefung deaktivieren -->
                                <tr>
                                    <th scope="row">Gültigkeitsfilter</th>
                                    <td>
                                        <fieldset>
                                            <label for="disable_validity_check">
                                                <input type="checkbox" 
                                                       name="disable_validity_check" 
                                                       id="disable_validity_check" 
                                                       value="1" 
                                                       <?php checked($settings['disable_validity_check'], 1); ?>>
                                                Gültigkeitspruefung deaktivieren (Alle Fahrpläne anzeigen)
                                            </label>
                                            <p class="description">
                                                Wenn aktiviert, werden alle Fahrpläne in den Suchergebnissen angezeigt - auch solche mit 
                                                einem Gültigkeitsdatum in der Zukunft (z.B. Fahrpläne fuer das nächste Jahr).
                                                <br><strong>Default:</strong> Nur Fahrpläne anzeigen, deren Gültigkeit bereits begonnen hat.
                                            </p>
                                        </fieldset>
                                    </td>
                                </tr>
                                
                                <!-- Feld 3: Scan Chunk-Size -->
                                <tr>
                                    <th scope="row">Scan Chunk-Size</th>
                                    <td>
                                        <fieldset>
                                            <input type="number" 
                                                   name="scan_chunk_size" 
                                                   id="scan_chunk_size" 
                                                   value="<?php echo esc_attr($settings['scan_chunk_size']); ?>"
                                                   min="1" 
                                                   max="50" 
                                                   step="1"
                                                   class="small-text">
                                            <label for="scan_chunk_size">PDFs pro Chunk</label>
                                            <p class="description">
                                                Anzahl der PDFs, die pro Chunk beim Verzeichnis-Scan verarbeitet werden.
                                                <br><strong>Empfohlen:</strong> 10 (Standard). Höhere Werte = schneller, aber mehr Serverbelastung.
                                                <br><strong>Bereich:</strong> 1-50 PDFs pro Chunk.
                                            </p>
                                        </fieldset>
                                    </td>
                                </tr>
                                
                            </tbody>
                        </table>
                    </div>
                    
                    <p class="submit">
                        <button type="submit" id="fahrplanportal-save-settings" class="button button-primary">
                            <span class="dashicons dashicons-saved" style="vertical-align: middle; margin-right: 5px;"></span>
                            Einstellungen speichern
                        </button>
                        <span id="fahrplanportal-settings-spinner" class="spinner" style="float: none; margin-left: 10px;"></span>
                    </p>
                    
                </div>
            </form>
            
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#fahrplanportal-settings-form').on('submit', function(e) {
                e.preventDefault();
                
                var $button = $('#fahrplanportal-save-settings');
                var $spinner = $('#fahrplanportal-settings-spinner');
                var $notice = $('#fahrplanportal-settings-notice');
                
                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $notice.hide();
                
                var data = {
                    action: 'fahrplanportal_save_settings',
                    nonce: $('#fahrplanportal_settings_nonce').val(),
                    hide_results_until_search: $('#hide_results_until_search').is(':checked') ? '1' : '0',
                    disable_validity_check: $('#disable_validity_check').is(':checked') ? '1' : '0',
                    scan_chunk_size: $('#scan_chunk_size').val()
                };
                
                $.post(ajaxurl, data, function(response) {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    
                    if (response.success) {
                        $notice.html('<div class="notice notice-success is-dismissible"><p><strong>' + response.data.message + '</strong></p></div>').show();
                    } else {
                        $notice.html('<div class="notice notice-error is-dismissible"><p><strong>Fehler: ' + response.data + '</strong></p></div>').show();
                    }
                    
                    setTimeout(function() {
                        $notice.find('.notice').fadeOut();
                    }, 3000);
                    
                }).fail(function(xhr, status, error) {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $notice.html('<div class="notice notice-error is-dismissible"><p><strong>Verbindungsfehler: ' + error + '</strong></p></div>').show();
                });
            });
        });
        </script>
        <?php
    }
    
    private function get_plugin_version_badge() {
        $plugin_file = WP_PLUGIN_DIR . '/hd-kaerntner-linien/hd_kaerntner_linien.php';
        
        if (!file_exists($plugin_file)) {
            $plugin_file = WP_PLUGIN_DIR . '/hd-kaerntner-linien/index.php';
            if (!file_exists($plugin_file)) {
                return '';
            }
        }
        
        $plugin_data = get_file_data($plugin_file, array(
            'Version' => 'Version',
            'GitHub Branch' => 'GitHub Branch'
        ));
        
        $version = !empty($plugin_data['Version']) ? $plugin_data['Version'] : '?';
        $branch = !empty($plugin_data['GitHub Branch']) ? $plugin_data['GitHub Branch'] : 'main';
        
        $branch_color = '#666';
        if ($branch === 'dev' || $branch === 'develop') {
            $branch_color = '#d63638';
        } elseif ($branch === 'main' || $branch === 'master') {
            $branch_color = '#00a32a';
        }
        
        return sprintf(
            '<small style="font-weight: normal; font-size: 12px; margin-left: 10px;">
                <span style="background: #f0f0f1; padding: 2px 8px; border-radius: 3px; color: #666;">v%s</span>
                <span style="background: %s; color: white; padding: 2px 8px; border-radius: 3px; margin-left: 5px;">%s</span>
            </small>',
            esc_html($version),
            esc_attr($branch_color),
            esc_html($branch)
        );
    }
}
