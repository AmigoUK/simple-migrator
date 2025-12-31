<?php
/**
 * AJAX Handler
 *
 * Handles all AJAX requests for the migration process on the destination server.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

class AJAX_Handler {

    /**
     * Single instance
     *
     * @var AJAX_Handler
     */
    private static $instance = null;

    /**
     * Cached JSON input data
     *
     * @var array|null
     */
    private $json_data = null;

    /**
     * Get instance
     *
     * @return AJAX_Handler
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
        // Register AJAX actions
        add_action('wp_ajax_sm_set_mode', array($this, 'set_mode'));
        add_action('wp_ajax_sm_regenerate_key', array($this, 'regenerate_key'));
        add_action('wp_ajax_sm_save_source_url', array($this, 'save_source_url'));
        add_action('wp_ajax_sm_save_source_key', array($this, 'save_source_key'));
        add_action('wp_ajax_sm_load_source_key', array($this, 'load_source_key'));
        add_action('wp_ajax_sm_get_config', array($this, 'get_config'));
        add_action('wp_ajax_sm_prepare_database', array($this, 'prepare_database'));
        add_action('wp_ajax_sm_process_rows', array($this, 'process_rows'));
        add_action('wp_ajax_sm_write_chunk', array($this, 'write_chunk'));
        add_action('wp_ajax_sm_extract_batch', array($this, 'extract_batch'));
        add_action('wp_ajax_sm_search_replace', array($this, 'search_replace'));
        add_action('wp_ajax_sm_flush_permalinks', array($this, 'flush_permalinks'));
        add_action('wp_ajax_sm_create_table', array($this, 'create_table'));
        add_action('wp_ajax_sm_drop_table', array($this, 'drop_table'));
        add_action('wp_ajax_sm_finalize_migration', array($this, 'finalize_migration'));
    }

    /**
     * Get JSON input data (cached)
     *
     * @return array|null Parsed JSON data or null
     */
    private function get_json_input() {
        if ($this->json_data === null) {
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $this->json_data = json_decode($input, true);
            } else {
                $this->json_data = false;
            }
        }
        return $this->json_data ?: null;
    }

    /**
     * Get a value from either POST or JSON input
     *
     * @param string $key The key to look for
     * @return mixed The value or null if not found
     */
    private function get_input($key) {
        // Check POST first
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        // Check JSON input
        $json_data = $this->get_json_input();
        if ($json_data && isset($json_data[$key])) {
            return $json_data[$key];
        }

        return null;
    }

    /**
     * Verify AJAX nonce and permissions
     * Handles both form POST and JSON request bodies
     *
     * @return bool|WP_Error
     */
    private function verify_request() {
        // Get nonce using the unified input method
        $nonce = $this->get_input('nonce');

        // Verify nonce
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('invalid_nonce', __('Invalid security token.', 'simple-migrator'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            return new \WP_Error('insufficient_permissions', __('You do not have sufficient permissions.', 'simple-migrator'));
        }

        return true;
    }

    /**
     * Set migration mode
     */
    public function set_mode() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'none';

        if (!in_array($mode, array('source', 'destination', 'none'))) {
            wp_send_json_error(__('Invalid mode.', 'simple-migrator'));
        }

        update_option('sm_source_mode', $mode);

        wp_send_json_success(array(
            'mode' => $mode
        ));
    }

    /**
     * Regenerate migration key
     */
    public function regenerate_key() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $new_secret = wp_generate_password(64, true, true);
        update_option('sm_migration_secret', $new_secret);

        // Base64 encode the secret to avoid special characters breaking the format
        $key_string = home_url('|') . base64_encode($new_secret);

        wp_send_json_success(array(
            'key' => $key_string
        ));
    }

    /**
     * Save source URL for search & replace
     */
    public function save_source_url() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $source_url = isset($_POST['source_url']) ? esc_url_raw($_POST['source_url']) : '';

        if (empty($source_url)) {
            wp_send_json_error(__('Invalid source URL.', 'simple-migrator'));
        }

        update_option('sm_source_url', $source_url);

        wp_send_json_success(array(
            'source_url' => $source_url
        ));
    }

    /**
     * Save source migration key for development
     *
     * DEVELOPMENT USE ONLY - Key is stored base64 encoded in database
     */
    public function save_source_key() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';

        if (empty($key)) {
            wp_send_json_error(__('Invalid key.', 'simple-migrator'));
        }

        // Validate key format
        $parts = explode('|', $key);
        if (count($parts) !== 2) {
            wp_send_json_error(__('Invalid key format.', 'simple-migrator'));
        }

        // Base64 encode for basic obfuscation (not encryption!)
        $encoded_key = base64_encode($key);
        update_option('sm_dev_saved_source_key', $encoded_key);

        wp_send_json_success();
    }

    /**
     * Load saved source migration key
     *
     * DEVELOPMENT USE ONLY
     */
    public function load_source_key() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $encoded_key = get_option('sm_dev_saved_source_key', '');

        if (empty($encoded_key)) {
            wp_send_json_success(array('key' => ''));
        }

        $key = base64_decode($encoded_key);

        wp_send_json_success(array('key' => $key));
    }

    /**
     * Get destination configuration
     */
    public function get_config() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        global $wpdb;

        wp_send_json_success(array(
            'table_prefix' => $wpdb->prefix,
            'home_url' => home_url(),
            'site_url' => site_url(),
        ));
    }

    /**
     * Prepare database for migration (drop existing tables if needed)
     * Smart Merge Mode: Preserves current user during migration
     */
    public function prepare_database() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        // Get parameters using unified input method
        $overwrite = $this->get_input('overwrite');
        $tables = $this->get_input('tables');
        $source_prefix = $this->get_input('source_prefix') ?: 'wp_';

        // Handle boolean
        $overwrite = filter_var($overwrite, FILTER_VALIDATE_BOOLEAN);

        // Handle arrays
        if (is_string($tables)) {
            $tables = json_decode($tables, true);
        }

        if (!is_array($tables)) {
            wp_send_json_error(__('Invalid tables parameter.', 'simple-migrator'));
        }

        global $wpdb;

        $dropped = array();
        $preserved = array();
        $errors = array();

        // Get current user ID - we MUST preserve this user to keep the session alive
        $current_user_id = get_current_user_id();
        $current_user_login = $current_user_id ? wp_get_current_user()->user_login : null;

        if ($overwrite) {
            // CRITICAL: Preserve critical wp_options BEFORE any table operations
            $this->preserve_critical_options();
            // Preserve admin account for final restoration
            $this->preserve_admin_account();

            foreach ($tables as $table) {
                // Replace source prefix with destination prefix
                $table_name = $this->replace_table_prefix($table, $source_prefix, $wpdb->prefix);

                // Validate table name for security
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
                    $errors[] = "Invalid table name: {$table_name}";
                    continue;
                }

                // Check if table exists
                $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));

                if ($table_exists) {
                    $table_base = str_replace($wpdb->prefix, '', $table_name);

                    // Handle wp_users specially - delete all EXCEPT current user
                    if ($table_base === 'users') {
                        if ($current_user_id && $current_user_login) {
                            // Delete all users except current one
                            $deleted = $wpdb->query($wpdb->prepare(
                                "DELETE FROM `{$table_name}` WHERE ID != %d",
                                $current_user_id
                            ));
                            if ($deleted !== false) {
                                $preserved[] = $table_name . ' (current user preserved)';
                            }
                        } else {
                            // No current user, truncate entirely
                            $wpdb->query("TRUNCATE TABLE `{$table_name}`");
                            $preserved[] = $table_name . ' (truncated)';
                        }
                    }
                    // Handle wp_usermeta specially - delete all EXCEPT current user's meta
                    elseif ($table_base === 'usermeta') {
                        if ($current_user_id) {
                            // Delete all usermeta except for current user
                            $deleted = $wpdb->query($wpdb->prepare(
                                "DELETE FROM `{$table_name}` WHERE user_id != %d",
                                $current_user_id
                            ));
                            if ($deleted !== false) {
                                $preserved[] = $table_name . ' (current user meta preserved)';
                            }
                        } else {
                            // No current user, truncate entirely
                            $wpdb->query("TRUNCATE TABLE `{$table_name}`");
                            $preserved[] = $table_name . ' (truncated)';
                        }
                    }
                    // Skip wp_options entirely - it will be overwritten during migration
                    // Then we'll restore specific protected options at the end
                    elseif ($table_base === 'options') {
                        $preserved[] = $table_name . ' (will be migrated, then fixed)';
                    }
                    // All other tables - drop as normal
                    else {
                        $result = $wpdb->query("DROP TABLE `{$table_name}`");

                        if ($result !== false) {
                            $dropped[] = $table_name;
                        } else {
                            $errors[] = "Failed to drop table: {$table_name}";
                        }
                    }
                }
            }
        }

        wp_send_json_success(array(
            'dropped' => $dropped,
            'preserved' => $preserved,
            'current_user_preserved' => $current_user_id,
            'errors' => $errors,
        ));
    }

    /**
     * Preserve critical wp_options entries before migration
     * Stores them in a transient for restoration after migration
     */
    private function preserve_critical_options() {
        global $wpdb;

        $protected_options = json_decode(SM_PROTECTED_OPTIONS, true);
        $preserved = array();

        foreach ($protected_options as $option) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $option
            ));
            if ($value !== null) {
                $preserved[$option] = $value;
            }
        }

        // Store in transient for restoration after migration
        set_transient('sm_preserved_options', $preserved, HOUR_IN_SECONDS);
    }

    /**
     * Preserve admin account before truncating wp_users
     * Stores admin credentials for restoration after migration
     */
    private function preserve_admin_account() {
        $admin = get_users(array(
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC'
        ));

        if (!empty($admin)) {
            $admin_user = $admin[0];
            $preserved_admin = array(
                'user_login'    => $admin_user->user_login,
                'user_pass'     => $admin_user->user_pass, // Hashed password
                'user_email'    => $admin_user->user_email,
                'user_url'      => $admin_user->user_url,
                'user_nicename' => $admin_user->user_nicename,
                'display_name'  => $admin_user->display_name,
            );

            set_transient('sm_preserved_admin', $preserved_admin, HOUR_IN_SECONDS);
        }
    }

    /**
     * Finalize migration - restore preserved options and admin
     * Called after database phase to restore destination site settings
     */
    public function finalize_migration() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        global $wpdb;

        // Restore preserved options
        $preserved_options = get_transient('sm_preserved_options');

        if ($preserved_options && is_array($preserved_options)) {
            foreach ($preserved_options as $option_name => $option_value) {
                // Check if option exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
                    $option_name
                ));

                if ($exists) {
                    // Update existing option
                    $wpdb->update(
                        $wpdb->options,
                        array('option_value' => $option_value),
                        array('option_name' => $option_name),
                        array('%s'),
                        array('%s')
                    );
                } else {
                    // Insert new option (in case it was dropped)
                    $wpdb->insert(
                        $wpdb->options,
                        array(
                            'option_name' => $option_name,
                            'option_value' => $option_value,
                            'autoload' => 'yes',
                        ),
                        array('%s', '%s', '%s')
                    );
                }
            }

            delete_transient('sm_preserved_options');
        }

        // Restore preserved admin account
        $this->restore_admin_account();

        // Flush caches to ensure changes take effect
        wp_cache_flush();

        wp_send_json_success(array(
            'message' => __('Migration finalized. Preserved settings restored.', 'simple-migrator')
        ));
    }

    /**
     * Restore preserved admin account after migration
     */
    private function restore_admin_account() {
        $preserved_admin = get_transient('sm_preserved_admin');

        if ($preserved_admin && is_array($preserved_admin)) {
            global $wpdb;

            // Check if admin still exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login = %s",
                $preserved_admin['user_login']
            ));

            if (!$exists) {
                // Re-insert admin with preserved password hash
                $wpdb->insert(
                    $wpdb->users,
                    array(
                        'user_login'    => $preserved_admin['user_login'],
                        'user_pass'     => $preserved_admin['user_pass'],
                        'user_email'    => $preserved_admin['user_email'],
                        'user_url'      => $preserved_admin['user_url'],
                        'user_nicename' => $preserved_admin['user_nicename'],
                        'display_name'  => $preserved_admin['display_name'],
                        'user_registered' => current_time('mysql'),
                        'user_activation_key' => '',
                    )
                );

                $user_id = $wpdb->insert_id;

                // Add admin capabilities and meta
                $capabilities = array('administrator' => 1);
                update_user_meta($user_id, $wpdb->prefix . 'capabilities', $capabilities);
                update_user_meta($user_id, $wpdb->prefix . 'user_level', 10);
            }

            delete_transient('sm_preserved_admin');
        }
    }

    /**
     * Process and insert table rows
     */
    public function process_rows() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        // Get parameters using unified input method
        $table = $this->get_input('table');
        $source_prefix = $this->get_input('source_prefix') ?: 'wp_';
        $rows = $this->get_input('rows');

        // Sanitize
        $table = $table ? sanitize_text_field($table) : '';
        $source_prefix = sanitize_text_field($source_prefix);

        // Handle rows as JSON string if needed
        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }

        if (empty($table) || !is_array($rows)) {
            wp_send_json_error(__('Invalid parameters.', 'simple-migrator'));
        }

        global $wpdb;

        // Replace source prefix with destination prefix (only at the start of table name)
        $table_name = $this->replace_table_prefix($table, $source_prefix, $wpdb->prefix);

        // Validate table name for security
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            wp_send_json_error(__('Invalid table name.', 'simple-migrator'));
        }

        $inserted = 0;
        $duplicates = 0;
        $errors = array();

        // Start transaction for atomic batch insert
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($rows as $row) {
                try {
                    // Decode base64 fields
                    $clean_row = array();
                    foreach ($row as $key => $value) {
                        if (substr($key, -8) === '_base64') {
                            // Skip the base64 flag
                            continue;
                        }

                        // Check if this field was base64 encoded
                        $base64_key = $key . '_base64';
                        if (isset($row[$base64_key])) {
                            $decoded = base64_decode($value, true);
                            if ($decoded === false) {
                                // Log error but don't insert this row
                                $errors[] = "Failed to decode base64 data for column: $key";
                                continue 2;
                            }
                            $clean_row[$key] = $decoded;
                        } else {
                            $clean_row[$key] = $value;
                        }
                    }

                    // Build INSERT query
                    $columns = array_keys($clean_row);
                    $placeholders = array_fill(0, count($columns), '%s');
                    $values = array_values($clean_row);

                    $sql = "INSERT INTO `{$table_name}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";

                    // Use prepare for security (even though data is trusted)
                    $prepared = $wpdb->prepare($sql, $values);
                    $result = $wpdb->query($prepared);

                    if ($result !== false) {
                        $inserted++;
                    } elseif (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                        // Track duplicate key errors separately
                        $duplicates++;
                    }
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }

            // Commit transaction if we got here without exceptions
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            // Rollback on any error
            $wpdb->query('ROLLBACK');
            $errors[] = "Transaction failed: " . $e->getMessage();
        }

        wp_send_json_success(array(
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'errors' => $errors
        ));
    }

    /**
     * Replace table prefix only at the beginning of the table name
     * This prevents replacing the prefix if it appears elsewhere in the name
     *
     * @param string $table_name Full table name with source prefix
     * @param string $source_prefix Source table prefix
     * @param string $dest_prefix Destination table prefix
     * @return string Table name with destination prefix
     */
    private function replace_table_prefix($table_name, $source_prefix, $dest_prefix) {
        // Only replace if the table name starts with the source prefix
        if (strpos($table_name, $source_prefix) === 0) {
            return $dest_prefix . substr($table_name, strlen($source_prefix));
        }
        return $table_name;
    }

    /**
     * Write file chunk with locking to prevent race conditions
     */
    public function write_chunk() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        $data = isset($_POST['data']) ? $_POST['data'] : '';
        $checksum = isset($_POST['checksum']) ? sanitize_text_field($_POST['checksum']) : '';
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        if (empty($path) || empty($data)) {
            wp_send_json_error(__('Invalid parameters.', 'simple-migrator'));
        }

        // Security: Ensure path is within wp-content
        $full_path = realpath(WP_CONTENT_DIR . '/' . dirname($path));
        $content_dir = realpath(WP_CONTENT_DIR);

        if ($full_path === false || strpos($full_path, $content_dir) !== 0) {
            wp_send_json_error(__('Invalid file path.', 'simple-migrator'));
        }

        // Create directory if it doesn't exist
        if (!file_exists($full_path)) {
            wp_mkdir_p($full_path);
        }

        $file_path = WP_CONTENT_DIR . '/' . $path;

        // Decode base64 data
        $decoded_data = base64_decode($data);

        // Verify checksum
        $actual_checksum = md5($decoded_data);
        if ($actual_checksum !== $checksum) {
            wp_send_json_error(__('Checksum verification failed.', 'simple-migrator'));
        }

        // Write chunk with exclusive lock to prevent race conditions
        $mode = ($offset === 0) ? 'wb' : 'ab';
        $handle = fopen($file_path, $mode);

        if ($handle === false) {
            wp_send_json_error(__('Failed to open file for writing.', 'simple-migrator'));
        }

        // Acquire exclusive lock and ensure it's released when file is closed
        try {
            if (flock($handle, LOCK_EX)) {
                if ($offset > 0) {
                    // Seek to end for append (in case file size changed)
                    fseek($handle, 0, SEEK_END);
                }

                $result = fwrite($handle, $decoded_data);
                fflush($handle);  // Ensure data is written
                // Lock is automatically released when file is closed
            } else {
                fclose($handle);
                wp_send_json_error(__('Could not acquire file lock.', 'simple-migrator'));
            }
        } finally {
            // Always close the handle, which releases the lock
            fclose($handle);
        }

        if ($result === false) {
            wp_send_json_error(__('Failed to write file chunk.', 'simple-migrator'));
        }

        // Set proper permissions
        chmod($file_path, 0644);

        wp_send_json_success(array(
            'bytes_written' => $result,
            'offset' => $offset
        ));
    }

    /**
     * Extract batch of files from zip archive
     */
    public function extract_batch() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $data = isset($_POST['data']) ? $_POST['data'] : '';
        $checksum = isset($_POST['checksum']) ? sanitize_text_field($_POST['checksum']) : '';

        if (empty($data)) {
            wp_send_json_error(__('Invalid parameters.', 'simple-migrator'));
        }

        // Decode base64 data
        $zip_data = base64_decode($data);

        // Verify checksum
        $actual_checksum = md5($zip_data);
        if ($actual_checksum !== $checksum) {
            wp_send_json_error(__('Checksum verification failed.', 'simple-migrator'));
        }

        // Create temporary file with better cleanup handling
        $temp_dir = sys_get_temp_dir();
        $temp_file = tempnam($temp_dir, 'sm_extract_');

        // Register shutdown function for cleanup in case of errors
        register_shutdown_function(function() use ($temp_file) {
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
        });

        file_put_contents($temp_file, $zip_data);

        // Extract zip
        $zip = new \ZipArchive();
        $result = $zip->open($temp_file);

        if ($result !== true) {
            unlink($temp_file);
            wp_send_json_error(__('Failed to open zip archive.', 'simple-migrator'));
        }

        $extracted_count = 0;
        $errors = array();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $file_path = $zip->getNameIndex($i);

            // Security: Ensure path is safe (no directory traversal)
            if (strpos($file_path, '..') !== false) {
                $errors[] = "Skipped unsafe path: {$file_path}";
                continue;
            }

            // Additional path validation
            $full_path = WP_CONTENT_DIR . '/' . $file_path;
            $real_path = realpath(dirname($full_path));
            $content_dir = realpath(WP_CONTENT_DIR);

            if ($real_path === false || strpos($real_path, $content_dir . DIRECTORY_SEPARATOR) !== 0) {
                $errors[] = "Path outside allowed directory: {$file_path}";
                continue;
            }

            // Create directory if needed
            $dir = dirname($full_path);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            // Extract file
            $zip->extractTo(WP_CONTENT_DIR, $file_path);

            // Set permissions
            if (file_exists($full_path)) {
                chmod($full_path, 0644);
                $extracted_count++;
            }
        }

        $zip->close();

        // Clean up temp file
        unlink($temp_file);

        wp_send_json_success(array(
            'extracted' => $extracted_count,
            'errors' => $errors
        ));
    }

    /**
     * Perform search and replace with serialized data handling
     */
    public function search_replace() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        // Get source and destination URLs
        $source_url = get_option('sm_source_url', '');
        $destination_url = home_url();

        if (empty($source_url)) {
            wp_send_json_error(__('Source URL not configured.', 'simple-migrator'));
        }

        $fixer = new Serialization_Fixer();
        $results = $fixer->replace($source_url, $destination_url);

        // Update site options
        $fixer->update_site_options($destination_url);

        wp_send_json_success($results);
    }

    /**
     * Flush permalinks
     */
    public function flush_permalinks() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        // Flush rewrite rules
        flush_rewrite_rules(true);

        wp_send_json_success(array(
            'message' => __('Permalinks flushed successfully.', 'simple-migrator')
        ));
    }

    /**
     * Create table from schema
     */
    public function create_table() {
        // Catch any errors and return JSON instead of HTML
        try {
            // Debug logging (only when WP_DEBUG is enabled)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SM: create_table() called");
            }

            $verify = $this->verify_request();
            if (is_wp_error($verify)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("SM: verify_request failed: " . $verify->get_error_message());
                }
                wp_send_json_error($verify->get_error_message());
            }

            // Get parameters using unified input method
            $schema = $this->get_input('schema');
            $source_table_name = $this->get_input('source_table_name');
            $source_prefix = $this->get_input('source_prefix') ?: 'wp_';

            // Fix escaped quotes in schema (caused by JSON encoding/decoding)
            if (is_string($schema)) {
                $schema = stripslashes($schema);
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SM: source_table_name: " . ($source_table_name ?: 'empty'));
                error_log("SM: source_prefix: " . $source_prefix);
            }

            // Sanitize
            $source_table_name = $source_table_name ? sanitize_text_field($source_table_name) : '';
            $source_prefix = sanitize_text_field($source_prefix);

            if (empty($schema) || empty($source_table_name)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("SM: Invalid parameters - schema: " . (empty($schema) ? 'empty' : 'present') . ", source_table_name: " . $source_table_name);
                }
                wp_send_json_error(__('Invalid parameters.', 'simple-migrator'));
            }

            global $wpdb;

            // Show errors for debugging
            $wpdb->show_errors = true;

            // Calculate new table name by replacing source prefix with destination prefix
            $new_table_name = $this->replace_table_prefix($source_table_name, $source_prefix, $wpdb->prefix);

            // Escape table names for regex (backslashes need special handling)
            $escaped_source = str_replace('\\', '\\\\', $source_table_name);

            // Replace table name in CREATE TABLE statement - more precise pattern
            // Pattern matches: CREATE TABLE `wp_tablename` or CREATE TABLE wp_tablename
            $pattern = '/CREATE TABLE\s+(`?' . preg_quote($escaped_source, '/') . '`?)/i';
            $schema = preg_replace($pattern, 'CREATE TABLE `' . $new_table_name . '`', $schema);

            // Replace all backtick-quoted references to the old table name
            $schema = str_replace('`' . $source_table_name . '`', '`' . $new_table_name . '`', $schema);

            // Also handle unquoted references in constraints (like REFERENCES wp_tablename)
            // But be careful to only replace when it's a standalone table name, not part of another string
            $schema = preg_replace('/\b' . preg_quote($source_table_name, '/') . '\b/', $new_table_name, $schema);

            // Clean up any multiple backticks that might have been created
            $schema = str_replace('``', '`', $schema);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SM: Creating table '$new_table_name' from '$source_table_name'");
                error_log("SM: Schema length: " . strlen($schema));
            }

            // Execute schema
            $result = $wpdb->query($schema);

            if ($result === false) {
                $error = $wpdb->last_error;
                if (empty($error)) {
                    $error = __('Failed to create table. No error message from database.', 'simple-migrator');
                }
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("SM: CREATE TABLE failed: " . $error);
                    error_log("SM: Schema (first 500 chars): " . substr($schema, 0, 500));
                }
                wp_send_json_error($error);
            }

            wp_send_json_success(array(
                'table' => $new_table_name,
                'source_table' => $source_table_name
            ));
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SM: Exception in create_table: " . $e->getMessage());
            }
            wp_send_json_error($e->getMessage());
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("SM: Throwable in create_table: " . $e->getMessage());
            }
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Drop table
     */
    public function drop_table() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $table = isset($_POST['table']) ? sanitize_text_field($_POST['table']) : '';

        if (empty($table)) {
            wp_send_json_error(__('Invalid table name.', 'simple-migrator'));
        }

        global $wpdb;

        // Security: Validate table name format
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            wp_send_json_error(__('Invalid table name.', 'simple-migrator'));
        }

        // Check if table exists and belongs to this WordPress install
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            wp_send_json_error(__('Table does not exist.', 'simple-migrator'));
        }

        // Drop table - table name is validated above
        $result = $wpdb->query("DROP TABLE `{$table}`");

        if ($result === false) {
            wp_send_json_error($wpdb->last_error);
        }

        wp_send_json_success(array(
            'table' => $table
        ));
    }
}

// Initialize AJAX handler
AJAX_Handler::get_instance();
