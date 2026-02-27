<?php
/**
 * Plugin Name: IDE Code Snippets Bridge
 * Plugin URI: https://github.com/ide-snippets/wordpress-snippets-manager
 * Description: Bridge plugin that provides a secure REST API to connect your WordPress site with IDE extensions (like Trae AI, VS Code) for seamless code snippet management.
 * Version: 1.3.0
 * Author: IDE Snippets by eliodata.com
 * Author URI: https://eliodata.com
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Text Domain: ide-snippets-bridge
 *
 * @package IDESnippets
 * @subpackage Bridge
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('IDE_SNIPPETS_BRIDGE_VERSION', '1.3.0');
define('IDE_SNIPPETS_BRIDGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IDE_SNIPPETS_BRIDGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IDE_SNIPPETS_BRIDGE_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include the API endpoint class
require_once IDE_SNIPPETS_BRIDGE_PLUGIN_DIR . 'includes/class-ide-snippets-api.php';

/**
 * Main plugin class
 *
 * @since 1.0.0
 */
class IDE_Snippets_Bridge {

    /** @var IDE_Snippets_Bridge|null */
    private static $instance = null;

    /**
     * Get plugin instance (singleton)
     *
     * @return IDE_Snippets_Bridge
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('rest_api_init', [$this, 'init_api']);
        add_action('admin_notices', [$this, 'check_dependencies']);

        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Initialize the API endpoints
     */
    public function init_api() {
        $api = new IDE_Snippets_API();
        $api->register_routes();
    }

    /**
     * Check for required dependencies and display admin notice if missing
     */
    public function check_dependencies() {
        // Need admin functions for is_plugin_active
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $code_snippets_active     = is_plugin_active('code-snippets/code-snippets.php');
        $code_snippets_pro_active = is_plugin_active('code-snippets-pro/code-snippets.php');

        if (!$code_snippets_active && !$code_snippets_pro_active) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>IDE Code Snippets Bridge:</strong> ';
            echo 'This plugin requires the <a href="' . esc_url(admin_url('plugin-install.php?s=code-snippets&tab=search&type=term')) . '">Code Snippets</a> plugin to be installed and activated.';
            echo '</p>';
            echo '</div>';
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(IDE_SNIPPETS_BRIDGE_PLUGIN_BASENAME);
            wp_die(esc_html__('IDE Code Snippets Bridge requires WordPress 5.0 or higher.', 'ide-snippets-bridge'));
        }

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(IDE_SNIPPETS_BRIDGE_PLUGIN_BASENAME);
            wp_die(esc_html__('IDE Code Snippets Bridge requires PHP 7.4 or higher.', 'ide-snippets-bridge'));
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 *
 * @return IDE_Snippets_Bridge
 */
function ide_snippets_bridge_init() {
    return IDE_Snippets_Bridge::get_instance();
}

// Go!
ide_snippets_bridge_init();
