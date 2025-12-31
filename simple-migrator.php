<?php
/**
 * Plugin Name: Simple Migrator
 * Plugin URI: https://github.com/AmigoUK/simple-migrator
 * Description: Distributed, peer-to-peer WordPress migration plugin for reliable 1:1 site cloning with bit-by-bit transfer technology.
 * Version: 1.0.12
 * Author: Tomasz 'Amigo' Lewandowski
 * Author URI: https://www.attv.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-migrator
 * Domain Path: /languages
 * Network: false
 *
 *
 * @package Simple_Migrator
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core Constants
 */
define('SM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('SM_VERSION', '1.0.9');
define('SM_API_NAMESPACE', 'simple-migrator/v1');
define('SM_CHUNK_SIZE', 2 * 1024 * 1024); // 2MB chunks

/**
 * Autoloader
 */
spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'Simple_Migrator\\';

    // Base directory for the namespace prefix
    $base_dir = SM_PLUGIN_DIR . 'includes/';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Convert class name to filename
    // e.g., REST_Controller → class-rest-controller.php
    //      Admin\Admin_Page → admin/class-admin-page.php
    $parts = explode('\\', $relative_class);

    // The last part is the class name
    $class_name = array_pop($parts);
    $filename = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';

    // Build the file path
    $path = $base_dir;
    if (!empty($parts)) {
        // Add subdirectories (e.g., 'admin/')
        $path .= strtolower(implode('/', $parts)) . '/';
    }
    $file = $path . $filename;

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main Plugin Class
 */
class Simple_Migrator {

    /**
     * Single instance of the class
     *
     * @var Simple_Migrator
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Simple_Migrator
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
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize components after WordPress is fully loaded
        add_action('plugins_loaded', array($this, 'init'));

        // Load plugin text domain
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Generate a unique migration secret if none exists
        if (!get_option('sm_migration_secret')) {
            update_option('sm_migration_secret', wp_generate_password(64, true, true));
        }

        // Set default options
        if (!get_option('sm_source_mode')) {
            update_option('sm_source_mode', 'none'); // 'source', 'destination', or 'none'
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup if needed
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin components
     */
    public function init() {
        // Initialize REST API
        Simple_Migrator\REST_Controller::get_instance();

        // Initialize AJAX Handler
        Simple_Migrator\AJAX_Handler::get_instance();

        // Initialize Backup Manager
        Simple_Migrator\Backup_Manager::get_instance();

        // Initialize Admin
        if (is_admin()) {
            Simple_Migrator\Admin\Admin_Page::get_instance();
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'simple-migrator',
            false,
            dirname(SM_PLUGIN_BASENAME) . '/languages/'
        );
    }
}

/**
 * Initialize the plugin
 */
function simple_migrator() {
    return Simple_Migrator::get_instance();
}

// Start the plugin
simple_migrator();
