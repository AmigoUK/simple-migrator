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
    private $max_backups;

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
        $this->max_backups = Settings::get_instance()->get('max_backups');

        // Set backup directory
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/sm-backups/';

        static $dir_initialized = false;
        if (!$dir_initialized) {
            // Create backup directory if it doesn't exist
            if (!file_exists($this->backup_dir)) {
                wp_mkdir_p($this->backup_dir);
                file_put_contents($this->backup_dir . '.htaccess', 'Deny from all');
                file_put_contents($this->backup_dir . 'index.php', '<?php // Silence is golden.');
            }
            $dir_initialized = true;
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
        $backup_id = 'backup-' . $timestamp . '-' . substr(uniqid('', true), -4);
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
                // Validate mysqldump path
                if (!file_exists($mysql_cmd) || !is_executable($mysql_cmd)) {
                    return $this->backup_database_php($backup_path, $progress_callback);
                }

                $command = sprintf(
                    '%s --host=%s --user=%s --password=%s --single-transaction --quick --lock-tables=false %s > %s 2>/dev/null',
                    \escapeshellarg($mysql_cmd),
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
        } catch (\Exception $e) {
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
                $safe_table = str_replace('`', '``', $table);

                // Write DROP TABLE statement
                fwrite($db_file, "DROP TABLE IF EXISTS `$safe_table`;\n");

                // Get CREATE TABLE statement
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `$safe_table`", ARRAY_N);
                if ($create_table && isset($create_table[1])) {
                    fwrite($db_file, $create_table[1] . ";\n\n");
                }

                // Batch query to prevent memory exhaustion
                $batch_size = 1000;
                $offset = 0;
                $row_count = 0;

                while (true) {
                    $rows = $wpdb->get_results(
                        $wpdb->prepare("SELECT * FROM `{$safe_table}` LIMIT %d, %d", $offset, $batch_size),
                        ARRAY_A
                    );

                    if (empty($rows)) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $columns = array_map(function($col) use ($wpdb) {
                            return is_null($col) ? 'NULL' : $wpdb->prepare('%s', $col);
                        }, array_values($row));

                        $values = implode(', ', $columns);
                        $safe_columns = array_map(function($col) {
                            return str_replace('`', '``', $col);
                        }, array_keys($row));
                        $columns_list = implode('`, `', $safe_columns);

                        fwrite($db_file, "INSERT INTO `$safe_table` (`$columns_list`) VALUES ($values);\n");
                        $row_count++;

                        // Flush every 100 rows
                        if ($row_count % 100 === 0) {
                            fflush($db_file);
                        }
                    }

                    if (count($rows) < $batch_size) {
                        break;
                    }

                    $offset += $batch_size;
                }

                fwrite($db_file, "\n");
            }

            fclose($db_file);
            return true;

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
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
        // Directories and files to exclude from backup (loaded from Settings)
        $exclude_patterns = Settings::get_instance()->get('backup_exclude_patterns');

        // Check if a path should be excluded
        $should_exclude = function($path) use ($exclude_patterns) {
            foreach ($exclude_patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    return true;
                }
            }
            return false;
        };

        // Custom filter iterator that excludes certain paths
        $filter_iterator = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            function($current, $key, $iterator) use ($should_exclude, $base_dir, $strip_length) {
                $file_path = $current->getRealPath();
                $relative_path = substr($file_path, strlen($base_dir) + 1);

                // Skip if path matches exclusion pattern
                if ($should_exclude($relative_path)) {
                    return false;
                }

                return true;
            }
        );

        // First, count total files (need fresh iterator)
        $counter = new \RecursiveIteratorIterator(
            $filter_iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $total_files = iterator_count($counter);

        // Now process files (new iterator)
        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                function($current, $key, $iterator) use ($should_exclude, $base_dir) {
                    $file_path = $current->getRealPath();
                    $relative_path = substr($file_path, strlen($base_dir) + 1);
                    return !$should_exclude($relative_path);
                }
            ),
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
                $progress = 65 + ($total_files > 0 ? ($file_count / $total_files) * 20 : 0); // 65-85% range
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
        // Validate backup ID format
        if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}(-[a-f0-9]{4})?$/', $backup_id)) {
            return new \WP_Error('invalid_backup_id', __('Invalid backup ID format.', 'simple-migrator'));
        }

        $backup_path = $this->backup_dir . $backup_id . '/';

        $real_backup_path = realpath($backup_path);
        if ($real_backup_path === false || strpos($real_backup_path, realpath($this->backup_dir)) !== 0) {
            return new \WP_Error('invalid_path', __('Backup path is outside allowed directory.', 'simple-migrator'));
        }

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
    public function restore_database($backup_path) {
        global $wpdb;

        $sql_file = $backup_path . 'database.sql';

        if (!file_exists($sql_file)) {
            return new \WP_Error('db_backup_missing', __('Database backup file not found.', 'simple-migrator'));
        }

        // Stream SQL file line-by-line instead of loading entirely into memory
        $handle = fopen($sql_file, 'r');
        if (!$handle) {
            return new \WP_Error('file_open_failed', __('Failed to open database backup file.', 'simple-migrator'));
        }

        // Whitelist of allowed SQL statement prefixes
        $allowed_prefixes = array(
            'DROP TABLE', 'CREATE TABLE', 'INSERT INTO', 'SET', 'LOCK TABLES',
            'UNLOCK TABLES', 'ALTER TABLE', '/*!', '--', 'REPLACE INTO'
        );

        // Disable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0;');

        $current_query = '';
        $in_string = false;
        $escape = false;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            // Skip comments and empty lines
            if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            $current_query .= $line;

            // Check if query is complete (ends with semicolon outside of strings)
            if (substr($trimmed, -1) === ';') {
                $query = trim($current_query);

                if (!empty($query)) {
                    // Validate against whitelist
                    $is_allowed = false;
                    $upper_query = strtoupper(ltrim($query));
                    foreach ($allowed_prefixes as $prefix) {
                        if (strpos($upper_query, $prefix) === 0) {
                            $is_allowed = true;
                            break;
                        }
                    }

                    if ($is_allowed) {
                        $result = $wpdb->query($query);
                        if ($result === false) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('SM: Failed to execute restore query: ' . substr($query, 0, 100));
                            }
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('SM: Skipped non-whitelisted SQL: ' . substr($query, 0, 100));
                        }
                    }
                }

                $current_query = '';
            }
        }

        fclose($handle);

        // Re-enable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1;');

        return true;
    }

    /**
     * Split SQL file into individual queries
     * Filters out non-SQL lines (like mysqldump warnings)
     *
     * @param string $sql SQL content
     * @return array Array of queries
     */
    private function split_sql_file($sql) {
        // First, pre-process to remove mysqldump warnings and error messages
        // These can span multiple lines and don't follow standard SQL syntax
        $lines = explode("\n", $sql);
        $clean_lines = array();
        $in_warning = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Detect mysqldump warnings/errors
            if (preg_match('/^mysqldump(\.exe)?:/', $trimmed)) {
                $in_warning = true;
                continue;
            }

            // If we're in a warning block, skip until we hit valid SQL
            if ($in_warning) {
                // Check if this is the start of valid SQL
                if (preg_match('/^(-- MySQL dump|\/\*!|SET |CREATE |DROP |ALTER |LOCK |UNLOCK )/', $trimmed)) {
                    $in_warning = false;
                    $clean_lines[] = $line;
                }
                // Otherwise, keep skipping warning lines
                continue;
            }

            // Skip standalone warning/error lines
            if (preg_match('/^(Warning:|Error:|Note:|MySQL dumpError)/', $trimmed)) {
                continue;
            }

            $clean_lines[] = $line;
        }

        $sql = implode("\n", $clean_lines);

        // Now parse the cleaned SQL
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
    public function restore_files($backup_path) {
        $content_dir = WP_CONTENT_DIR;
        $zip_file = $backup_path . 'files.zip';

        if (!file_exists($zip_file)) {
            return new \WP_Error('files_backup_missing', __('Files backup file not found.', 'simple-migrator'));
        }

        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_missing', __('ZipArchive class not available.', 'simple-migrator'));
        }

        // Files/directories to skip during restore (development files, etc.)
        $skip_patterns = array(
            '/\.git$/',           // Git repository
            '/\.svn$/',           // SVN repository
            '/\.hg$/',            // Mercurial repository
            '/\.sass-cache$/',    // Sass cache
            '/node_modules$/',    // Node modules
            '/\.DS_Store$/',      // macOS files
            '/Thumbs\.db$/',      // Windows thumbnails
        );

        $should_skip = function($path) use ($skip_patterns) {
            foreach ($skip_patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    return true;
                }
            }
            return false;
        };

        try {
            $zip = new \ZipArchive();

            if ($zip->open($zip_file) !== true) {
                return new \WP_Error('zip_open_failed', __('Failed to open zip file.', 'simple-migrator'));
            }

            $extracted_count = 0;
            $skipped_count = 0;
            $failed_count = 0;

            // Extract files one by one for better error handling
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                // Skip files that match exclusion patterns
                if ($should_skip($name)) {
                    $skipped_count++;
                    continue;
                }

                // Skip the backup directory itself to prevent recursion
                if (strpos($name, 'uploads/sm-backups/') === 0) {
                    $skipped_count++;
                    continue;
                }

                // Extract this file
                $result = $zip->extractTo($content_dir, $name);

                if ($result) {
                    $extracted_count++;
                } else {
                    $failed_count++;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('SM: Failed to extract: ' . $name);
                    }
                }
            }

            $zip->close();

            // Log summary
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('SM: File restore complete - Extracted: %d, Skipped: %d, Failed: %d',
                    $extracted_count, $skipped_count, $failed_count));
            }

            // Only return error if everything failed
            if ($extracted_count === 0 && $failed_count > 0) {
                return new \WP_Error('zip_extract_failed', __('Failed to extract any files.', 'simple-migrator'));
            }

            // Warn about failures but don't fail entirely
            if ($failed_count > 0) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('SM: Some files failed to extract, but restore continued.');
                }
            }

            return true;

        } catch (\Exception $e) {
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
        // Validate backup ID format
        if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}(-[a-f0-9]{4})?$/', $backup_id)) {
            return new \WP_Error('invalid_backup_id', __('Invalid backup ID format.', 'simple-migrator'));
        }

        $backup_path = $this->backup_dir . $backup_id . '/';

        if (!file_exists($backup_path)) {
            return new \WP_Error('backup_not_found', __('Backup not found.', 'simple-migrator'));
        }

        $real_backup_path = realpath($backup_path);
        if ($real_backup_path === false || strpos($real_backup_path, realpath($this->backup_dir)) !== 0) {
            return new \WP_Error('invalid_path', __('Backup path is outside allowed directory.', 'simple-migrator'));
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

        // Security: Refuse to delete anything outside backup dir
        $real_dir = realpath($dir);
        $real_backup_dir = realpath($this->backup_dir);
        if ($real_dir === false || $real_backup_dir === false || strpos($real_dir, $real_backup_dir) !== 0) {
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SM: ajax_create_backup() started');
        }

        // Disable ALL output buffering to enable streaming
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set headers to prevent buffering
        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no'); // Nginx

        $verify = $this->verify_request();
        if (is_wp_error($verify)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SM: Verification failed: ' . $verify->get_error_message());
            }
            echo json_encode(array('type' => 'error', 'error' => $verify->get_error_message()));
            flush();
            exit;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SM: Verification passed, starting backup');
        }

        // Start time for calculation
        $start_time = microtime(true);

        try {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SM: About to call create_backup()');
            }

            // Send progress updates during backup
            $result = $this->create_backup(function($progress, $message) use ($start_time) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("SM: Progress: {$progress}% - {$message}");
                }

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

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SM: Backup completed, result: ' . print_r($result, true));
            }

            if (is_wp_error($result)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('SM: Backup error: ' . $result->get_error_message());
                }
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
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SM: Exception caught: ' . $e->getMessage());
            }
            echo json_encode(array('type' => 'error', 'error' => $e->getMessage()));
            flush();
        } catch (\Error $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SM: Fatal error caught: ' . $e->getMessage());
            }
            echo json_encode(array('type' => 'error', 'error' => $e->getMessage()));
            flush();
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SM: ajax_create_backup() finished');
        }
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

        // Validate backup ID format
        if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}(-[a-f0-9]{4})?$/', $backup_id)) {
            wp_send_json_error(__('Invalid backup ID format.', 'simple-migrator'));
            return;
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

        // Validate backup ID format
        if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}(-[a-f0-9]{4})?$/', $backup_id)) {
            wp_send_json_error(__('Invalid backup ID format.', 'simple-migrator'));
            return;
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

        if (!$nonce || !wp_verify_nonce($nonce, 'sm_admin_nonce')) {
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
}
