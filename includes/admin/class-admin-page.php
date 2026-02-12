<?php
/**
 * Admin Page
 *
 * Handles the WordPress admin interface for the plugin.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator\Admin;

use Simple_Migrator\Settings;

class Admin_Page {

    /**
     * Single instance
     *
     * @var Admin_Page
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Admin_Page
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Simple Migrator', 'simple-migrator'),
            __('Simple Migrator', 'simple-migrator'),
            'manage_options',
            'simple-migrator',
            array($this, 'render_admin_page'),
            'dashicons-migrate',
            30
        );

        $settings_page = Settings_Page::get_instance();
        add_submenu_page(
            'simple-migrator',
            __('Settings', 'simple-migrator'),
            __('Settings', 'simple-migrator'),
            'manage_options',
            'simple-migrator-settings',
            array($settings_page, 'render_page')
        );
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Only load on our admin pages
        if ('toplevel_page_simple-migrator' !== $hook && 'simple-migrator_page_simple-migrator-settings' !== $hook) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'sm-admin-css',
            SM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SM_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'sm-admin-js',
            SM_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            SM_VERSION,
            true
        );

        // Localize script
        $sm_settings = Settings::get_instance();
        wp_localize_script('sm-admin-js', 'smData', array(
            'apiUrl'       => rest_url(SM_API_NAMESPACE),
            'nonce'        => wp_create_nonce('sm_admin_nonce'),
            'pluginUrl'    => SM_PLUGIN_URL,
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'homeUrl'      => home_url(),
            'settings'     => array(
                'chunkSize'  => $sm_settings->get('chunk_size'),
                'batchSize'  => $sm_settings->get('batch_size'),
                'maxRetries' => $sm_settings->get('max_retries'),
            ),
            'strings'      => array(
                'connectionSuccess' => __('Connection successful!', 'simple-migrator'),
                'connectionFailed'  => __('Connection failed.', 'simple-migrator'),
                'migrationComplete' => __('Migration completed successfully!', 'simple-migrator'),
                'migrationError'    => __('Migration error:', 'simple-migrator'),
            ),
        ));
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'simple-migrator'));
        }

        // Get current mode
        $mode = get_option('sm_source_mode', 'none');

        ?>
        <div class="wrap sm-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="sm-mode-selector">
                <h2><?php _e('Select Migration Mode', 'simple-migrator'); ?></h2>
                <p class="description">
                    <?php _e('Choose whether this site will be a Source (data will be copied FROM here) or Destination (data will be copied TO here).', 'simple-migrator'); ?>
                </p>

                <div class="sm-mode-buttons">
                    <button type="button" class="button button-primary button-large" data-mode="source">
                        <span class="dashicons dashicons-upload"></span>
                        <?php _e('Source Mode', 'simple-migrator'); ?>
                    </button>
                    <button type="button" class="button button-primary button-large" data-mode="destination">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Destination Mode', 'simple-migrator'); ?>
                    </button>
                </div>

                <input type="hidden" id="sm-current-mode" value="<?php echo esc_attr($mode); ?>" />
            </div>

            <div id="sm-source-panel" class="sm-panel" style="display: none;">
                <h2>
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Source Configuration', 'simple-migrator'); ?>
                    <small><a href="#" id="sm-change-mode-source" style="float: right; font-weight: normal;"><?php _e('← Change Mode', 'simple-migrator'); ?></a></small>
                </h2>

                <div class="sm-card">
                    <h3><?php _e('Migration Key', 'simple-migrator'); ?></h3>
                    <p class="description">
                        <?php _e('This secret key is used to authenticate connections from the destination site. Share this key only with trusted destinations.', 'simple-migrator'); ?>
                    </p>

                    <div class="sm-key-display">
                        <code id="sm-migration-key"><?php echo esc_html($this->get_migration_key_string()); ?></code>
                        <button type="button" class="button" id="sm-copy-key">
                            <span class="dashicons dashicons-admin-page"></span>
                            <?php _e('Copy Key', 'simple-migrator'); ?>
                        </button>
                        <button type="button" class="button" id="sm-regenerate-key">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Regenerate', 'simple-migrator'); ?>
                        </button>
                    </div>
                </div>

                <div class="sm-card">
                    <h3><?php _e('Source Information', 'simple-migrator'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <tbody>
                            <tr>
                                <th><?php _e('Site URL', 'simple-migrator'); ?></th>
                                <td><code><?php echo esc_html(home_url()); ?></code></td>
                            </tr>
                            <tr>
                                <th><?php _e('WordPress Version', 'simple-migrator'); ?></th>
                                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('PHP Version', 'simple-migrator'); ?></th>
                                <td><?php echo esc_html(phpversion()); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('MySQL Version', 'simple-migrator'); ?></th>
                                <td><?php echo esc_html($GLOBALS['wpdb']->db_version()); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Memory Limit', 'simple-migrator'); ?></th>
                                <td><?php echo esc_html(ini_get('memory_limit')); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Max Upload Size', 'simple-migrator'); ?></th>
                                <td><?php echo esc_html(size_format(wp_max_upload_size())); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="sm-destination-panel" class="sm-panel" style="display: none;">
                <h2>
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Destination Configuration', 'simple-migrator'); ?>
                    <small><a href="#" id="sm-change-mode-destination" style="float: right; font-weight: normal;"><?php _e('← Change Mode', 'simple-migrator'); ?></a></small>
                </h2>

                <div class="sm-card">
                    <h3><?php _e('Connect to Source', 'simple-migrator'); ?></h3>
                    <p class="description">
                        <?php _e('Enter the migration key from the source site to establish a connection.', 'simple-migrator'); ?>
                    </p>

                    <div class="sm-connection-form">
                        <div class="sm-form-group">
                            <label for="sm-source-key"><?php _e('Source Migration Key', 'simple-migrator'); ?></label>
                            <textarea id="sm-source-key" class="large-text" rows="3" placeholder="<?php echo esc_attr__('https://source-site.com|your-secret-key-here', 'simple-migrator'); ?>"></textarea>
                        </div>

                        <div class="sm-form-group">
                            <label class="sm-checkbox-label">
                                <input type="checkbox" id="sm-save-key" value="1">
                                <span><?php _e('Save migration key for development', 'simple-migrator'); ?></span>
                            </label>
                            <p class="description">
                                <strong><?php _e('⚠️ Development Only:', 'simple-migrator'); ?></strong>
                                <?php _e('The key will be stored in your database. This is convenient for development but not recommended for production sites.', 'simple-migrator'); ?>
                            </p>
                        </div>

                        <button type="button" class="button button-primary button-large" id="sm-test-connection">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php _e('Test Connection', 'simple-migrator'); ?>
                        </button>
                    </div>

                    <div id="sm-connection-result" class="sm-connection-result" style="display: none;"></div>
                </div>

                <div id="sm-migration-controls" class="sm-card" style="display: none;">
                    <h3><?php _e('Migration Progress', 'simple-migrator'); ?></h3>

                    <div class="sm-progress-section">
                        <h4><?php _e('Scan Phase', 'simple-migrator'); ?></h4>
                        <div class="sm-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sm-progress-fill" id="sm-scan-progress" style="width: 0%;"></div>
                        </div>
                        <div class="sm-progress-status" id="sm-scan-status"><?php _e('Waiting to start...', 'simple-migrator'); ?></div>
                    </div>

                    <div class="sm-progress-section">
                        <h4><?php _e('Database Phase', 'simple-migrator'); ?></h4>
                        <div class="sm-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sm-progress-fill" id="sm-database-progress" style="width: 0%;"></div>
                        </div>
                        <div class="sm-progress-status" id="sm-database-status"><?php _e('Waiting to start...', 'simple-migrator'); ?></div>
                    </div>

                    <div class="sm-progress-section">
                        <h4><?php _e('Files Phase', 'simple-migrator'); ?></h4>
                        <div class="sm-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sm-progress-fill" id="sm-files-progress" style="width: 0%;"></div>
                        </div>
                        <div class="sm-progress-status" id="sm-files-status"><?php _e('Waiting to start...', 'simple-migrator'); ?></div>
                    </div>

                    <div class="sm-progress-section">
                        <h4><?php _e('Finalize Phase', 'simple-migrator'); ?></h4>
                        <div class="sm-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sm-progress-fill" id="sm-finalize-progress" style="width: 0%;"></div>
                        </div>
                        <div class="sm-progress-status" id="sm-finalize-status"><?php _e('Waiting to start...', 'simple-migrator'); ?></div>
                    </div>

                    <div class="sm-actions">
                        <button type="button" class="button button-primary button-hero" id="sm-start-migration">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Start Migration', 'simple-migrator'); ?>
                        </button>

                        <button type="button" class="button" id="sm-pause-migration" style="display: none;">
                            <span class="dashicons dashicons-pause"></span>
                            <?php _e('Pause', 'simple-migrator'); ?>
                        </button>

                        <button type="button" class="button button-secondary" id="sm-cancel-migration" style="display: none;">
                            <span class="dashicons dashicons-no"></span>
                            <?php _e('Cancel', 'simple-migrator'); ?>
                        </button>
                    </div>
                </div>

                <div class="sm-card">
                    <h3>
                        <span class="dashicons dashicons-backup"></span>
                        <?php _e('Backup Management', 'simple-migrator'); ?>
                        <small><?php _e('(Development Safety)', 'simple-migrator'); ?></small>
                    </h3>
                    <p class="description">
                        <?php _e('Create backups before migration to quickly restore if something goes wrong. Backups are stored in your uploads directory.', 'simple-migrator'); ?>
                    </p>

                    <div class="sm-backup-actions">
                        <button type="button" class="button button-secondary" id="sm-create-backup">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <?php _e('Create Full Backup', 'simple-migrator'); ?>
                        </button>

                        <button type="button" class="button" id="sm-refresh-backups">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Refresh List', 'simple-migrator'); ?>
                        </button>
                    </div>

                    <div id="sm-backup-progress" class="sm-backup-progress" style="display: none;">
                        <div class="sm-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            <div class="sm-progress-fill" id="sm-backup-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="sm-progress-status" id="sm-backup-progress-status"><?php _e('Preparing...', 'simple-migrator'); ?></div>
                    </div>

                    <div id="sm-backup-list" class="sm-backup-list">
                        <p class="description"><?php _e('Loading backups...', 'simple-migrator'); ?></p>
                    </div>
                </div>
            </div>

            <div id="sm-no-mode-panel" class="sm-panel">
                <div class="sm-card sm-centered">
                    <span class="dashicons dashicons-admin-generic sm-large-icon"></span>
                    <h2><?php _e('Welcome to Simple Migrator', 'simple-migrator'); ?></h2>
                    <p>
                        <?php _e('Please select a mode above to get started with your migration.', 'simple-migrator'); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get migration key string
     *
     * @return string
     */
    private function get_migration_key_string() {
        $secret = get_option('sm_migration_secret', '');
        // Base64 encode the secret to avoid special characters breaking the format
        return home_url() . '|' . base64_encode($secret);
    }
}
