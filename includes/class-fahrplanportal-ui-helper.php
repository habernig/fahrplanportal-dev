<?php
/**
 * UI Helper für Fahrplanportal
 */

if (!defined('ABSPATH')) {
    exit;
}

class FahrplanPortal_UI_Helper {
    
    /**
     * Einheitlicher Page-Header
     * 
     * @param string $title Seitentitel
     */
    public static function render_page_header($title) {
        $icon_path = plugin_dir_path(__FILE__) . '../assets/kl-icon-menu.svg';
        $icon_exists = file_exists($icon_path);
        
        ?>
        <div class="fahrplanportal-page-header" style="
            display: flex;
            align-items: center;
            gap: 0px;
            padding: 20px 0;
            border-bottom: 1px solid #ddd;
            margin-bottom: 30px;
        ">
            <!-- SVG Icon Links -->
            <div class="header-icon" style="flex-shrink: 0;">
                <?php if ($icon_exists): ?>
                    <img src="<?php echo plugins_url('../assets/kl-icon-menu.svg', __FILE__); ?>" 
                         alt="Fahrplanportal" 
                         style="width: 80px; height: 80px; display: block;">
                <?php else: ?>
                    <span class="dashicons dashicons-calendar-alt" style="font-size: 80px; width: 80px; height: 80px; color: #0073aa;"></span>
                <?php endif; ?>
            </div>
            
            <!-- Titel Mitte -->
            <div class="header-title" style="flex-grow: 1;">
                <h1 style="
                    margin: 0; 
                    font-size: 34px; 
                    line-height: 1.2;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
                    font-weight: bold;
                ">
                    <?php echo esc_html($title); ?>
                </h1>
            </div>
            
            <!-- Version Badge Rechts -->
            <div class="header-badge" style="flex-shrink: 0;">
                <?php echo self::get_plugin_version_badge(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Plugin-Version Badge
     */
    public static function get_plugin_version_badge() {
        $plugin_file = WP_PLUGIN_DIR . '/hd-kaerntner-linien/hd_kaerntner_linien.php';
        
        if (!file_exists($plugin_file)) {
            $plugin_file = WP_PLUGIN_DIR . '/hd-kaerntner-linien/index.php';
            if (!file_exists($plugin_file)) {
                return '<small style="font-weight: normal; font-size: 12px; color: #666;">(Version unbekannt)</small>';
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
        } elseif (strpos($branch, 'feature') !== false) {
            $branch_color = '#2271b1';
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
