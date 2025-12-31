<?php
/**
 * Backup Manager
 *
 * Handles database and file backups before migration for development safety.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

class Backup_Manager {

    /**
     * Single instance
     *
     * @var Backup_Manager
     */
    private static $instance = null;

    /**
     * Backup directory
     *
     * @var string
     */
    private $backup_dir;

    /**
     * Maximum number of backups to keep
     *
     * @var int
     */
    private $max_backups = 3;

    /**
     * Get instance
     *
     * @return Backup_Manager
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
        // Set backup directory
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/sm-backups/';

        // Create backup directory if it doesn't exist
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);

            // Create .htaccess to protect backups
            file_put_contents($this->backup_dir . '.htaccess', 'Deny from all');

            // Create index.php to prevent directory listing
            file_put_contents($this->backup_dir . 'index.php', '<?php // Silence is golden.');
        }

        // Register AJAX actions
        add_action('wp_ajax_sm_create_backup', array($this, 'ajax_create_backup'));
        add_action('wp_ajax_sm_restore_backup', array($this, 'ajax_restore_backup'));
        add_action('wp_ajax_sm_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('wp_ajax_sm_list_backups', array($this, 'ajax_list_backups'));
    }

    /**
     * Create a full backup with detailed progress
     *
     * @param bool $progress_callback Optional callback for progress updates
     * @return array|WP_Error Backup info or error
     */
    public function create_backup($progress_callback = null) {
        $timestamp = current_time('Y-m-d-His');
        $backup_id = 'backup-' . $timestamp;
        $backup_path = $this->backup_dir . $backup_id . '/';

        // Create backup directory
        if (!wp_mkdir_p($backup_path)) {
            return new \WP_Error('mkdir_failed', __('Failed to create backup directory.', 'simple-migrator'));
        }

        if ($progress_callback) {
            call_user_func($progress_callback, 3, __('Creating backup directory...', 'simple-migrator'));
        }

        $errors = array();

        // Backup database
        if ($progress_callback) {
            call_user_func($progress_callback, 5, __('Starting database backup...', 'simple-migrator'));
        }

        $db_result = $this->backup_database($backup_path, $progress_callback);
        if (is_wp_error($db_result)) {
            $errors[] = $db_result->get_error_message();
        }

        if ($progress_callback) {
            call_user_func($progress_callback, 55, __('Database backed up, preparing file backup...', 'simple-migrator'));
        }

        // Backup files
        if ($progress_callback) {
            call_user_func($progress_callback, 60, __('Starting file backup (this may take a while)...', 'simple-migrator'));
        }

        $files_result = $this->backup_files($backup_path, $progress_callback);
        if (is_wp_error($files_result)) {
            $errors[] = $files_result->get_error_message();
        }

        if ($progress_callback) {
            call_user_func($progress_callback, 90, __('Creating backup metadata...', 'simple-migrator'));
        }

        // Create backup metadata
        $metadata = array(
            'backup_id' => $backup_id,
            'timestamp' => $timestamp,
            'created_at' => current_time('mysql'),
            'db_size' => is_wp_error($db_result) ? 0 : filesize($backup_path . 'database.sql'),
            'files_size' => is_wp_error($files_result) ? 0 : filesize($backup_path . 'files.zip'),
            'total_size' => 0,
            'status' => empty($errors) ? 'complete' : 'partial',
            'errors' => $errors
        );

        $metadata['total_size'] = $metadata['db_size'] + $metadata['files_size'];

        // Save metadata
        file_put_contents($backup_path . 'backup.json', wp_json_encode($metadata, JSON_PRETTY_PRINT));

        if ($progress_callback) {
            call_user_func($progress_callback, 95, __('Cleaning up old backups...', 'simple-migrator'));
        }

        // Clean up old backups
        $this->cleanup_old_backups();

        if ($progress_callback) {
            call_user_func($progress_callback, 100, __('Backup complete!', 'simple-migrator'));
        }

        return $metadata;
    }

    /**
     * Backup database with progress tracking
     *
     * @param string $backup_path Backup directory path
     * @param callable $progress_callback Optional progress callback
     * @return true|WP_Error
     */
    private function backup_database($backup_path, $progress_callback = null) {
        global $wpdb;

        try {
            // Use mysqldump if available (faster)
            $mysql_cmd = $this->find_mysqldump();

            if ($mysql_cmd) {
                $db_file = $backup_path . 'database.sql';

                // Note: We redirect stderr to /dev/null to suppress mysqldump warnings
                // Warnings like "Using a password on the command line..." should not be in the SQL file
                $command = sprintf(
                    '%s --host=%s --user=%s --password=%s --single-transaction --quick --lock-tables=false %s > %s 2>/dev/null',
                    \escapeshellcmd($mysql_cmd),
                    \escapeshellarg(DB_HOST),
                    \escapeshellarg(DB_USER),
                    \escapeshellarg(DB_PASSWORD),
                    \escapeshellarg(DB_NAME),
                    \escapeshellarg($db_file)
                );

                exec($command, $output, $return_code);

                if ($return_code !== 0 || !file_exists($db_file) || filesize($db_file) === 0) {
                    // Fallback to PHP dump
                    return $this->backup_database_php($backup_path, $progress_callback);
                }

                return true;
            } else {
                // Use PHP-based dump
                return $this->backup_database_php($backup_path, $progress_callback);
            }
        } catch (Exception $e) {
            return new \WP_Error('db_backup_failed', $e->getMessage());
        }
    }

    /**
     * Backup database using PHP (fallback) with progress tracking
     *
     * @param string $backup_path Backup directory path
     * @param callable $progress_callback Optional progress callback
     * @return true|WP_Error
     */
    private function backup_database_php($backup_path, $progress_callback = null) {
        global $wpdb;

        try {
            $db_file = fopen($backup_path . 'database.sql', 'w');

            if (!$db_file) {
                return new \WP_Error('file_open_failed', __('Failed to create database backup file.', 'simple-migrator'));
            }

            // Get all tables
            $tables = $wpdb->get_col('SHOW TABLES');

            foreach ($tables as $table) {
                // Write DROP TABLE statement
                fwrite($db_file, "DROP TABLE IF EXISTS `$table`;\n");

                // Get CREATE TABLE statement
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
                if ($create_table && isset($create_table[1])) {
                    fwrite($db_file, $create_table[1] . ";\n\n");
                }

                // Get table data
                $row_count = 0;
                $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);

                foreach ($rows as $row) {
                    $columns = array_map(function($col) use ($wpdb) {
                        return is_null($col) ? 'NULL' : $wpdb->prepare('%s', $col);
                    }, array_values($row));

                    $values = implode(', ', $columns);
                    $columns_list = implode('`, `', array_keys($row));

                    fwrite($db_file, "INSERT INTO `$table` (`$columns_list`) VALUES ($values);\n");
                    $row_count++;

                    // Flush every 100 rows
                    if ($row_count % 100 === 0) {
                        fflush($db_file);
                    }
                }

                fwrite($db_file, "\n");
            }

            fclose($db_file);
            return true;

        } catch (Exception $e) {
            return new \WP_Error('php_db_backup_failed', $e->getMessage());
        }
    }

    /**
     * Find mysqldump command
     *
     * @return string|false Path to mysqldump or false
     */
    private function find_mysqldump() {
        $possible_paths = array(
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/Applications/MAMP/Library/bin/mysqldump',
            '/Applications/MAMP/Library/bin/mysql80/bin/mysqldump',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql*.*\\bin\\mysqldump.exe',
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Try 'which' command
        @exec('which mysqldump 2>/dev/null', $output);
        if (!empty($output[0]) && file_exists($output[0])) {
            return $output[0];
        }

        return false;
    }

    /**
     * Backup files with progress tracking
     *
     * @param string $backup_path Backup directory path
     * @param callable $progress_callback Optional progress callback
     * @return true|WP_Error
     */
    private function backup_files($backup_path, $progress_callback = null) {
        $content_dir = WP_CONTENT_DIR;
        $zip_file = $backup_path . 'files.zip';

        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_missing', __('ZipArchive class not available.', 'simple-migrator'));
        }

        try {
            $zip = new \ZipArchive();

            if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return new \WP_Error('zip_create_failed', __('Failed to create zip file.', 'simple-migrator'));
            }

            // Add files to archive with progress tracking
            if ($progress_callback) {
                call_user_func($progress_callback, 65, __('Scanning files for backup...', 'simple-migrator'));
            }

            $this->add_files_to_zip($zip, $content_dir, strlen($content_dir) + 1, $progress_callback);

            if ($progress_callback) {
                call_user_func($progress_callback, 85, __('Compressing files...', 'simple-migrator'));
            }

            if ($zip->close() === false) {
                return new \WP_Error('zip_close_failed', __('Failed to close zip file.', 'simple-migrator'));
            }

            return true;

        } catch (Exception $e) {
            return new \WP_Error('file_backup_failed', $e->getMessage());
        }
    }

    /**
     * Recursively add files to zip with progress tracking
     *
     * @param \ZipArchive $zip ZipArchive instance
     * @param string $base_dir Base directory
     * @param int $strip_length Length to strip from paths
     * @param callable $progress_callback Optional progress callback
     */
    private function add_files_to_zip($zip, $base_dir, $strip_length, $progress_callback = null) {
        // First, count total files (need fresh iterator)
        $counter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $total_files = iterator_count($counter);

        // Now process files (new iterator)
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $file_count = 0;

        foreach ($files as $file) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, $strip_length);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else {
                $zip->addFile($file_path, $relative_path);
            }

            // Update progress every 100 files
            if ($progress_callback && ++$file_count % 100 === 0) {
                $progress = 65 + (($file_count / $total_files) * 20); // 65-85% range
                call_user_func($progress_callback, $progress, sprintf(__('Adding files to archive (%d/%d)...', 'simple-migrator'), $file_count, $total_files));
            }
        }
    }

    /**
     * Restore from backup
     *
     * @param string $backup_id Backup ID
     * @param bool $progress_callback Optional callback for progress updates
     * @return true|WP_Error
     */
    public function restore_backup($backup_id, $progress_callback = null) {
        $backup_path = $this->backup_dir . $backup_id . '/';

        if (!file_exists($backup_path . 'backup.json')) {
            return new \WP_Error('backup_not_found', __('Backup not found.', 'simple-migrator'));
        }

        $metadata = json_decode(file_get_contents($backup_path . 'backup.json'), true);

        if ($progress_callback) {
            call_user_func($progress_callback, 10, __('Restoring database...', 'simple-migrator'));
        }

        // Restore database
        $db_result = $this->restore_database($backup_path);
        if (is_wp_error($db_result)) {
            return $db_result;
        }

        if ($progress_callback) {
            call_user_func($progress_callback, 60, __('Restoring files...', 'simple-migrator'));
        }

        // Restore files
        $files_result = $this->restore_files($backup_path);
        if (is_wp_error($files_result)) {
            return $files_result;
        }

        if ($progress_callback) {
            call_user_func($progress_callback, 100, __('Restore complete!', 'simple-migrator'));
        }

        return true;
    }

    /**
     * Restore database from backup
     *
     * @param string $backup_path Backup directory path
     * @return true|WP_Error
     */
    private function restore_database($backup_path) {
        global $wpdb;

        $sql_file = $backup_path . 'database.sql';

        if (!file_exists($sql_file)) {
            return new \WP_Error('db_backup_missing', __('Database backup file not found.', 'simple-migrator'));
        }

        try {
            // Read SQL file
            $sql = file_get_contents($sql_file);

            // Split into individual queries
            $queries = $this->split_sql_file($sql);

            // Disable foreign key checks
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0;');

            foreach ($queries as $query) {
                if (!empty(trim($query))) {
                    $result = $wpdb->query($query);
                    if ($result === false) {
                        // Log error but continue
                        error_log('SM: Failed to execute query: ' . substr($query, 0, 100));
                    }
                }
            }

            // Re-enable foreign key checks
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1;');

            return true;

        } catch (Exception $e) {
            return new \WP_Error('db_restore_failed', $e->getMessage());
        }
    }

    /**
     * Split SQL file into individual queries
     * Filters out non-SQL lines (like mysqldump warnings)
     *
     * @param string $sql SQL content
     * @return array Array of queries
     */
    private function split_sql_file($sql) {
        $queries = array();
        $current_query = '';
        $in_string = false;
        $escape = false;

        // SQL keywords that indicate the start of a valid query
        $sql_keywords = array(
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER',
            'TRUNCATE', 'RENAME', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'USE', 'SET',
            'LOCK', 'UNLOCK', 'START', 'COMMIT', 'ROLLBACK', 'BEGIN', 'REPLACE',
            'CALL', 'DECLARE', 'GRANT', 'REVOKE', '/*!', '/*', '--', 'FLUSH'
        );

        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];

            if ($in_string) {
                if ($char === '\\' && !$escape) {
                    $escape = true;
                } elseif ($char === "'" && !$escape) {
                    $in_string = false;
                } else {
                    $escape = false;
                }
                $current_query .= $char;
            } else {
                if ($char === "'") {
                    $in_string = true;
                    $current_query .= $char;
                } elseif ($char === ';') {
                    $current_query .= $char;
                    $trimmed = trim($current_query);

                    // Only add if it looks like a valid SQL statement
                    if (!empty($trimmed) && $this->is_valid_sql_line($trimmed, $sql_keywords)) {
                        $queries[] = $current_query;
                    }

                    $current_query = '';
                } else {
                    $current_query .= $char;
                }
            }
        }

        // Handle last query (without semicolon)
        if (!empty(trim($current_query))) {
            $trimmed = trim($current_query);
            if ($this->is_valid_sql_line($trimmed, $sql_keywords)) {
                $queries[] = $current_query;
            }
        }

        return $queries;
    }

    /**
     * Check if a line looks like valid SQL
     *
     * @param string $line Line to check
     * @param array $sql_keywords Valid SQL keywords
     * @return bool True if line looks like SQL
     */
    private function is_valid_sql_line($line, $sql_keywords) {
        // Skip empty lines
        if (empty($line)) {
            return false;
        }

        // Skip mysqldump warnings and error messages
        if (preg_match('/^mysqldump:/', $line)) {
            return false;
        }

        // Skip lines that look like error messages
        if (preg_match('/^MySQL dump|^\d+\.|Warning:|Error:|Note:/', $line)) {
            return false;
        }

        // Check if line starts with a SQL keyword (allowing leading whitespace/comments)
        $upper = strtoupper(trim($line));
        foreach ($sql_keywords as $keyword) {
            if (strpos($upper, $keyword) === 0 || strpos($upper, '/* ' . $keyword) === 0) {
                return true;
            }
        }

        // Also allow comment lines and MySQL-specific directives
        if (preg_match('/^(--|#|\/\*|\/\*\!|\*\/)/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Restore files from backup
     *
     * @param string $backup_path Backup directory path
     * @return true|WP_Error
     */
    private function restore_files($backup_path) {
        $content_dir = WP_CONTENT_DIR;
        $zip_file = $backup_path . 'files.zip';

        if (!file_exists($zip_file)) {
            return new \WP_Error('files_backup_missing', __('Files backup file not found.', 'simple-migrator'));
        }

        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_missing', __('ZipArchive class not available.', 'simple-migrator'));
        }

        try {
            $zip = new \ZipArchive();

            if ($zip->open($zip_file) !== true) {
                return new \WP_Error('zip_open_failed', __('Failed to open zip file.', 'simple-migrator'));
            }

            // Extract files
            if ($zip->extractTo($content_dir) === false) {
                $zip->close();
                return new \WP_Error('zip_extract_failed', __('Failed to extract files.', 'simple-migrator'));
            }

            $zip->close();
            return true;

        } catch (Exception $e) {
            return new \WP_Error('file_restore_failed', $e->getMessage());
        }
    }

    /**
     * List all backups
     *
     * @return array Array of backup metadata
     */
    public function list_backups() {
        $backups = array();

        if (!is_dir($this->backup_dir)) {
            return $backups;
        }

        $dirs = glob($this->backup_dir . 'backup-*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $backup_id = basename($dir);
            $metadata_file = $dir . '/backup.json';

            if (file_exists($metadata_file)) {
                $metadata = json_decode(file_get_contents($metadata_file), true);
                if ($metadata) {
                    $backups[] = $metadata;
                }
            }
        }

        // Sort by timestamp descending
        usort($backups, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $backups;
    }

    /**
     * Delete a backup
     *
     * @param string $backup_id Backup ID
     * @return true|WP_Error
     */
    public function delete_backup($backup_id) {
        $backup_path = $this->backup_dir . $backup_id . '/';

        if (!file_exists($backup_path)) {
            return new \WP_Error('backup_not_found', __('Backup not found.', 'simple-migrator'));
        }

        // Recursively delete backup directory
        $this->recursive_delete($backup_path);

        return true;
    }

    /**
     * Clean up old backups
     */
    private function cleanup_old_backups() {
        $backups = $this->list_backups();

        if (count($backups) > $this->max_backups) {
            $to_delete = array_slice($backups, $this->max_backups);

            foreach ($to_delete as $backup) {
                $this->delete_backup($backup['backup_id']);
            }
        }
    }

    /**
     * Recursively delete directory
     *
     * @param string $dir Directory path
     */
    private function recursive_delete($dir) {
        if (!file_exists($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->recursive_delete($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * AJAX: Create backup with progress tracking
     */
    public function ajax_create_backup() {
        // Log to WordPress debug log
        error_log('SM: ajax_create_backup() started');

        // Disable ALL output buffering to enable streaming
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set headers to prevent buffering
        header('Content-Type: application/json');
        header('X-Accel-Buffering: no'); // Nginx

        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            error_log('SM: Verification failed: ' . $verify->get_error_message());
            echo json_encode(array('type' => 'error', 'error' => $verify->get_error_message()));
            flush();
            exit;
        }

        error_log('SM: Verification passed, starting backup');

        // Start time for calculation
        $start_time = microtime(true);

        try {
            error_log('SM: About to call create_backup()');

            // Send progress updates during backup
            $result = $this->create_backup(function($progress, $message) use ($start_time) {
                error_log("SM: Progress: {$progress}% - {$message}");

                // Calculate elapsed time
                $elapsed = microtime(true) - $start_time;

                // Estimate remaining time (rough calculation)
                $estimated_total = $progress > 0 ? $elapsed / ($progress / 100) : 0;
                $remaining = max(0, $estimated_total - $elapsed);

                // Send progress update
                echo json_encode(array(
                    'type' => 'progress',
                    'progress' => $progress,
                    'message' => $message,
                    'elapsed' => round($elapsed, 1),
                    'remaining' => round($remaining, 1)
                ));
                echo "\n";

                // Only flush if there's a buffer (avoid PHP notices)
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

            error_log('SM: Backup completed, result: ' . print_r($result, true));

            if (is_wp_error($result)) {
                error_log('SM: Backup error: ' . $result->get_error_message());
                echo json_encode(array('type' => 'error', 'error' => $result->get_error_message()));
                flush();
                exit;
            }

            // Add total time to result
            $result['total_time'] = round(microtime(true) - $start_time, 1);

            // Send final result in the same format (not wrapped by WordPress)
            echo json_encode(array(
                'type' => 'complete',
                'success' => true,
                'data' => $result
            ));
            flush();
        } catch (Exception $e) {
            error_log('SM: Exception caught: ' . $e->getMessage());
            echo json_encode(array('type' => 'error', 'error' => $e->getMessage()));
            flush();
        } catch (Error $e) {
            error_log('SM: Fatal error caught: ' . $e->getMessage());
            echo json_encode(array('type' => 'error', 'error' => $e->getMessage()));
            flush();
        }

        error_log('SM: ajax_create_backup() finished');
        exit;
    }

    /**
     * AJAX: Restore backup
     */
    public function ajax_restore_backup() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';

        if (empty($backup_id)) {
            wp_send_json_error(__('Invalid backup ID.', 'simple-migrator'));
        }

        $result = $this->restore_backup($backup_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array('message' => __('Backup restored successfully.', 'simple-migrator')));
    }

    /**
     * AJAX: Delete backup
     */
    public function ajax_delete_backup() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';

        if (empty($backup_id)) {
            wp_send_json_error(__('Invalid backup ID.', 'simple-migrator'));
        }

        $result = $this->delete_backup($backup_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array('message' => __('Backup deleted.', 'simple-migrator')));
    }

    /**
     * AJAX: List backups
     */
    public function ajax_list_backups() {
        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            wp_send_json_error($verify->get_error_message());
        }

        $backups = $this->list_backups();

        wp_send_json_success(array('backups' => $backups));
    }

    /**
     * Verify AJAX request
     *
     * @return true|WP_Error
     */
    private function verify_request() {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('permission_denied', __('You do not have permission to perform this action.', 'simple-migrator'));
        }

        // Check both POST and JSON input for nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : null;
        if (!$nonce && isset($_SERVER['HTTP_X_WP_NONCE'])) {
            $nonce = $_SERVER['HTTP_X_WP_NONCE'];
        }

        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('invalid_nonce', __('Invalid security token.', 'simple-migrator'));
        }

        return true;
    }

    /**
     * Get the backup directory path
     *
     * @return string Backup directory path
     */
    public function get_backup_dir() {
        return $this->backup_dir;
    }

    /**
     * Get list of all backup IDs
     *
     * @return array Array of backup IDs
     */
    public function get_all_backups() {
        $backups = array();

        if (is_dir($this->backup_dir)) {
            $dirs = glob($this->backup_dir . 'backup-*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $backup_id = basename($dir);
                if (file_exists($dir . '/backup.json')) {
                    $backups[] = $backup_id;
                }
            }
        }

        // Sort by name (which contains timestamp), newest first
        rsort($backups);

        return $backups;
    }

    /**
     * Get backup metadata
     *
     * @param string $backup_id Backup ID
     * @return array|false Metadata array or false if not found
     */
    public function get_backup_metadata($backup_id) {
        $metadata_file = $this->backup_dir . $backup_id . '/backup.json';

        if (!file_exists($metadata_file)) {
            return false;
        }

        $json = file_get_contents($metadata_file);
        $metadata = json_decode($json, true);

        return $metadata ?: false;
    }

    /**
     * Delete a backup
     *
     * @param string $backup_id Backup ID
     * @return true|WP_Error
     */
    public function delete_backup($backup_id) {
        $backup_path = $this->backup_dir . $backup_id . '/';

        if (!file_exists($backup_path . 'backup.json')) {
            return new \WP_Error('backup_not_found', __('Backup not found:', 'simple-migrator') . ' ' . $backup_id);
        }

        // Recursively delete backup directory
        $this->recursive_delete($backup_path);

        return true;
    }

    /**
     * Restore files from backup (for WP-CLI partial restore)
     *
     * @param string $backup_path Backup directory path
     * @return true|WP_Error
     */
    public function restore_files($backup_path) {
        return $this->restore_files_helper($backup_path);
    }

    /**
     * Restore database from backup (for WP-CLI partial restore)
     *
     * @param string $backup_path Backup directory path
     * @return true|WP_Error
     */
    public function restore_database($backup_path) {
        return $this->restore_database_helper($backup_path);
    }

    /**
     * Recursively delete a directory
     *
     * @param string $dir Directory path
     * @return void
     */
    private function recursive_delete($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    if (is_dir($dir . '/' . $object)) {
                        $this->recursive_delete($dir . '/' . $object);
                    } else {
                        unlink($dir . '/' . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Helper method to restore files (separated for WP-CLI access)
     */
    private function restore_files_helper($backup_path) {
        $content_dir = WP_CONTENT_DIR;
        $zip_file = $backup_path . 'files.zip';

        if (!file_exists($zip_file)) {
            return new \WP_Error('files_backup_missing', __('Files backup file not found.', 'simple-migrator'));
        }

        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_missing', __('ZipArchive class not available.', 'simple-migrator'));
        }

        try {
            $zip = new \ZipArchive();

            if ($zip->open($zip_file) !== true) {
                return new \WP_Error('zip_open_failed', __('Failed to open zip file.', 'simple-migrator'));
            }

            // Extract to wp-content
            if ($zip->extractTo($content_dir) !== true) {
                $zip->close();
                return new \WP_Error('zip_extract_failed', __('Failed to extract files.', 'simple-migrator'));
            }

            $zip->close();

            return true;
        } catch (\Exception $e) {
            return new \WP_Error('files_restore_failed', $e->getMessage());
        }
    }

    /**
     * Helper method to restore database (separated for WP-CLI access)
     */
    private function restore_database_helper($backup_path) {
        global $wpdb;

        $sql_file = $backup_path . 'database.sql';

        if (!file_exists($sql_file)) {
            return new \WP_Error('db_backup_missing', __('Database backup file not found.', 'simple-migrator'));
        }

        try {
            // Read SQL file
            $sql = file_get_contents($sql_file);

            // Split into individual queries
            $queries = $this->split_sql_file($sql);

            // Disable foreign key checks
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0;');

            foreach ($queries as $query) {
                if (!empty(trim($query))) {
                    $result = $wpdb->query($query);
                    if ($result === false) {
                        // Log error but continue
                        error_log('SM: Failed to execute query: ' . substr($query, 0, 100));
                    }
                }
            }

            // Re-enable foreign key checks
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1;');

            return true;

        } catch (\Exception $e) {
            return new \WP_Error('db_restore_failed', $e->getMessage());
        }
    }
}
