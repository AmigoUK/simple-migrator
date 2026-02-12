<?php
/**
 * Settings Page
 *
 * Admin UI for configuring Simple Migrator parameters.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator\Admin;

use Simple_Migrator\Settings;

class Settings_Page {

    /**
     * Single instance
     *
     * @var Settings_Page
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Settings_Page
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
        add_action('wp_ajax_sm_save_settings', array($this, 'save_settings'));
        add_action('wp_ajax_sm_reset_settings', array($this, 'reset_settings'));
    }

    /**
     * Render settings page
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'simple-migrator'));
        }

        $settings = Settings::get_instance();
        $defaults = $settings->get_defaults();

        // Convert stored values to display units
        $chunk_mb   = $settings->get('chunk_size') / (1024 * 1024);
        $batch_size = $settings->get('batch_size');
        $max_retries = $settings->get('max_retries');
        $max_backups = $settings->get('max_backups');
        $lock_min   = $settings->get('lock_timeout') / 60;

        ?>
        <div class="wrap sm-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div id="sm-settings-notice" style="display: none;"></div>

            <div class="sm-card">
                <h3><?php _e('Migration Settings', 'simple-migrator'); ?></h3>
                <p class="description">
                    <?php _e('Configure migration parameters for your hosting environment. Developers can also use <code>sm_setting_{key}</code> filter hooks for advanced customization.', 'simple-migrator'); ?>
                </p>

                <form id="sm-settings-form" class="sm-settings-form">
                    <?php wp_nonce_field('sm_admin_nonce', 'sm_settings_nonce'); ?>

                    <div class="sm-settings-field">
                        <label for="sm-chunk-size">
                            <?php _e('Chunk Size', 'simple-migrator'); ?>
                            <span class="sm-settings-unit">(MB)</span>
                        </label>
                        <input type="number" id="sm-chunk-size" name="chunk_size"
                               value="<?php echo esc_attr($chunk_mb); ?>"
                               min="0.5" max="10" step="0.5" />
                        <p class="description">
                            <?php _e('Size of file transfer chunks. Smaller values use less memory but require more requests. Default: 2 MB.', 'simple-migrator'); ?>
                        </p>
                    </div>

                    <div class="sm-settings-field">
                        <label for="sm-batch-size">
                            <?php _e('Batch Size', 'simple-migrator'); ?>
                            <span class="sm-settings-unit">(rows)</span>
                        </label>
                        <input type="number" id="sm-batch-size" name="batch_size"
                               value="<?php echo esc_attr($batch_size); ?>"
                               min="100" max="5000" step="100" />
                        <p class="description">
                            <?php _e('Number of database rows transferred per request. Lower values for limited-memory hosts. Default: 1000.', 'simple-migrator'); ?>
                        </p>
                    </div>

                    <div class="sm-settings-field">
                        <label for="sm-max-retries">
                            <?php _e('Max Retries', 'simple-migrator'); ?>
                        </label>
                        <input type="number" id="sm-max-retries" name="max_retries"
                               value="<?php echo esc_attr($max_retries); ?>"
                               min="1" max="10" step="1" />
                        <p class="description">
                            <?php _e('Maximum retry attempts for failed network requests before aborting. Default: 5.', 'simple-migrator'); ?>
                        </p>
                    </div>

                    <div class="sm-settings-field">
                        <label for="sm-max-backups">
                            <?php _e('Backup Retention', 'simple-migrator'); ?>
                            <span class="sm-settings-unit">(backups)</span>
                        </label>
                        <input type="number" id="sm-max-backups" name="max_backups"
                               value="<?php echo esc_attr($max_backups); ?>"
                               min="1" max="10" step="1" />
                        <p class="description">
                            <?php _e('Maximum number of backups to keep. Oldest backups are automatically deleted. Default: 3.', 'simple-migrator'); ?>
                        </p>
                    </div>

                    <div class="sm-settings-field">
                        <label for="sm-lock-timeout">
                            <?php _e('Lock Timeout', 'simple-migrator'); ?>
                            <span class="sm-settings-unit">(minutes)</span>
                        </label>
                        <input type="number" id="sm-lock-timeout" name="lock_timeout"
                               value="<?php echo esc_attr($lock_min); ?>"
                               min="5" max="120" step="5" />
                        <p class="description">
                            <?php _e('How long the migration lock is held before expiring. Prevents concurrent migrations. Default: 30 minutes.', 'simple-migrator'); ?>
                        </p>
                    </div>

                    <div class="sm-settings-actions">
                        <button type="submit" class="button button-primary" id="sm-save-settings">
                            <?php _e('Save Settings', 'simple-migrator'); ?>
                        </button>
                        <button type="button" class="button" id="sm-reset-settings">
                            <?php _e('Reset to Defaults', 'simple-migrator'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="sm-card">
                <h3><?php _e('Filter Hooks for Developers', 'simple-migrator'); ?></h3>
                <p class="description">
                    <?php _e('The following settings are available only via PHP filter hooks. Add filters in your theme or plugin to customize.', 'simple-migrator'); ?>
                </p>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php _e('Filter', 'simple-migrator'); ?></th>
                            <th><?php _e('Description', 'simple-migrator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>sm_setting_protected_tables</code></td>
                            <td><?php _e('Tables preserved during Smart Merge (default: users, usermeta)', 'simple-migrator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>sm_setting_protected_options</code></td>
                            <td><?php _e('wp_options entries preserved during migration', 'simple-migrator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>sm_setting_exclude_files</code></td>
                            <td><?php _e('Files excluded from file scan', 'simple-migrator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>sm_setting_exclude_dirs</code></td>
                            <td><?php _e('Directories excluded from file scan', 'simple-migrator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>sm_setting_exclude_extensions</code></td>
                            <td><?php _e('File extensions excluded from scan', 'simple-migrator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>sm_setting_backup_exclude_patterns</code></td>
                            <td><?php _e('Regex patterns for backup file exclusion', 'simple-migrator'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Save settings
            $('#sm-settings-form').on('submit', function(e) {
                e.preventDefault();

                var $btn = $('#sm-save-settings');
                $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'simple-migrator')); ?>');

                $.post(smData.ajaxUrl, {
                    action: 'sm_save_settings',
                    nonce: smData.nonce,
                    chunk_size: $('#sm-chunk-size').val(),
                    batch_size: $('#sm-batch-size').val(),
                    max_retries: $('#sm-max-retries').val(),
                    max_backups: $('#sm-max-backups').val(),
                    lock_timeout: $('#sm-lock-timeout').val()
                }, function(response) {
                    if (response.success) {
                        showNotice('success', '<?php echo esc_js(__('Settings saved successfully.', 'simple-migrator')); ?>');
                    } else {
                        showNotice('error', response.data || '<?php echo esc_js(__('Failed to save settings.', 'simple-migrator')); ?>');
                    }
                }).fail(function() {
                    showNotice('error', '<?php echo esc_js(__('Network error. Please try again.', 'simple-migrator')); ?>');
                }).always(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Settings', 'simple-migrator')); ?>');
                });
            });

            // Reset settings
            $('#sm-reset-settings').on('click', function() {
                if (!confirm('<?php echo esc_js(__('Reset all settings to their default values?', 'simple-migrator')); ?>')) {
                    return;
                }

                var $btn = $(this);
                $btn.prop('disabled', true);

                $.post(smData.ajaxUrl, {
                    action: 'sm_reset_settings',
                    nonce: smData.nonce
                }, function(response) {
                    if (response.success) {
                        // Update form fields with defaults
                        var d = response.data;
                        $('#sm-chunk-size').val(d.chunk_size);
                        $('#sm-batch-size').val(d.batch_size);
                        $('#sm-max-retries').val(d.max_retries);
                        $('#sm-max-backups').val(d.max_backups);
                        $('#sm-lock-timeout').val(d.lock_timeout);
                        showNotice('success', '<?php echo esc_js(__('Settings reset to defaults.', 'simple-migrator')); ?>');
                    } else {
                        showNotice('error', response.data || '<?php echo esc_js(__('Failed to reset settings.', 'simple-migrator')); ?>');
                    }
                }).fail(function() {
                    showNotice('error', '<?php echo esc_js(__('Network error. Please try again.', 'simple-migrator')); ?>');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            });

            function showNotice(type, message) {
                var cls = type === 'success' ? 'notice-success' : 'notice-error';
                var $notice = $('#sm-settings-notice');
                $notice.attr('class', 'notice ' + cls + ' is-dismissible')
                       .html('<p>' + message + '</p>')
                       .show();
                setTimeout(function() { $notice.fadeOut(); }, 4000);
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX: Save settings
     */
    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'simple-migrator'));
            return;
        }

        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'sm_admin_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'simple-migrator'));
            return;
        }

        $settings = Settings::get_instance();

        // Convert from display units to storage units
        $values = array();

        if (isset($_POST['chunk_size'])) {
            $values['chunk_size'] = (int) (floatval($_POST['chunk_size']) * 1024 * 1024);
        }
        if (isset($_POST['batch_size'])) {
            $values['batch_size'] = (int) $_POST['batch_size'];
        }
        if (isset($_POST['max_retries'])) {
            $values['max_retries'] = (int) $_POST['max_retries'];
        }
        if (isset($_POST['max_backups'])) {
            $values['max_backups'] = (int) $_POST['max_backups'];
        }
        if (isset($_POST['lock_timeout'])) {
            $values['lock_timeout'] = (int) (floatval($_POST['lock_timeout']) * 60);
        }

        $result = $settings->update_all($values);

        if ($result) {
            wp_send_json_success(__('Settings saved.', 'simple-migrator'));
        } else {
            wp_send_json_error(__('No changes to save.', 'simple-migrator'));
        }
    }

    /**
     * AJAX: Reset settings to defaults
     */
    public function reset_settings() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'simple-migrator'));
            return;
        }

        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'sm_admin_nonce')) {
            wp_send_json_error(__('Invalid security token.', 'simple-migrator'));
            return;
        }

        $settings = Settings::get_instance();
        $settings->reset();

        $defaults = $settings->get_defaults();

        // Return defaults in display units
        wp_send_json_success(array(
            'chunk_size'  => $defaults['chunk_size'] / (1024 * 1024),
            'batch_size'  => $defaults['batch_size'],
            'max_retries' => $defaults['max_retries'],
            'max_backups' => $defaults['max_backups'],
            'lock_timeout' => $defaults['lock_timeout'] / 60,
        ));
    }
}
