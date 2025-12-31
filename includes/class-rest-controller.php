<?php
/**
 * REST Controller
 *
 * Handles all REST API endpoints for the migration system.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

use WP_REST_Server;
use WP_REST_Controller;
use WP_REST_Response;
use WP_Error;

class REST_Controller extends WP_REST_Controller {

    /**
     * Single instance
     *
     * @var REST_Controller
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return REST_Controller
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
    public function __construct() {
        $this->namespace = SM_API_NAMESPACE;

        // Hook CORS handling as early as possible to catch preflight OPTIONS requests
        add_action('init', array($this, 'handle_cors'), 5);
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_pre_serve_request', array($this, 'add_cors_headers_to_response'));
    }

    /**
     * Handle CORS headers for cross-origin requests
     * Called early to catch preflight OPTIONS requests
     */
    public function handle_cors() {
        // Only handle requests to our API namespace
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        // Check if this is a request to our API
        if (strpos($request_uri, '/wp-json/simple-migrator/') === false) {
            return;
        }

        // Get the origin
        $origin = $this->get_request_origin();

        if (!$origin) {
            return;
        }

        // For development, allow all local/private IP addresses
        $is_local = $this->is_local_origin($origin);

        // Get allowed origins list
        $allowed_origins = $this->get_allowed_origins();
        $is_allowed = in_array($origin, $allowed_origins, true);

        // Allow if in whitelist OR is local development
        $should_allow = $is_allowed || $is_local;

        // Handle preflight OPTIONS request
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            if ($should_allow) {
                status_header(200);
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
                header('Access-Control-Allow-Headers: X-Migration-Secret, Content-Type, Authorization, X-WP-Nonce');
                header('Access-Control-Max-Age: 86400');
                exit;
            }
        }

        // Add CORS headers to actual requests if allowed
        if ($should_allow) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
    }

    /**
     * Check if the origin is a local/private address
     *
     * @param string $origin The origin URL
     * @return bool True if local/private
     */
    private function is_local_origin($origin) {
        $parsed = parse_url($origin);

        if (!isset($parsed['host'])) {
            return false;
        }

        $host = $parsed['host'];

        // Check for localhost variants
        $local_patterns = array(
            'localhost',
            '127.0.0.1',
            '::1',
        );

        // Check for exact match
        if (in_array($host, $local_patterns, true)) {
            return true;
        }

        // Check for private IP ranges (RFC 1918)
        if (preg_match('/^10\./', $host) ||
            preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host) ||
            preg_match('/^192\.168\./', $host)) {
            return true;
        }

        // Check for common TLDs used in local development
        if (preg_match('/\.(local|dev|test|wp|example)$/', $host)) {
            return true;
        }

        return false;
    }

    /**
     * Add CORS headers to REST response
     * Only allows requests from known origins
     */
    public function add_cors_headers_to_response($value) {
        $allowed_origins = $this->get_allowed_origins();
        $origin = $this->get_request_origin();

        // Only set CORS headers for allowed origins
        if ($origin && in_array($origin, $allowed_origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
            header('Access-Control-Allow-Headers: X-Migration-Secret, Content-Type, Authorization, X-WP-Nonce');
        }

        return $value;
    }

    /**
     * Get list of allowed origins for CORS
     *
     * @return array List of allowed origins
     */
    private function get_allowed_origins() {
        $origins = array(
            site_url(),
            home_url(),
        );

        // Add source URL if in destination mode
        $source_url = get_option('sm_source_url', '');
        if (!empty($source_url)) {
            $origins[] = $source_url;
        }

        // Allow any URLs from the mode-specific storage
        // This handles the P2P migration use case
        $mode = get_option('sm_source_mode', 'none');

        if ($mode === 'destination') {
            // In destination mode, we've connected to a source
            // Allow that source to make requests
            $connected_sources = get_option('sm_connected_sources', array());
            if (is_array($connected_sources)) {
                $origins = array_merge($origins, $connected_sources);
            }
        } elseif ($mode === 'source') {
            // In source mode, allow connected destinations to make requests
            $connected_destinations = get_option('sm_connected_destinations', array());
            if (is_array($connected_destinations)) {
                $origins = array_merge($origins, $connected_destinations);
            }
        }

        return array_unique(array_filter($origins));
    }

    /**
     * Get the request origin safely
     *
     * @return string|null Origin URL or null
     */
    private function get_request_origin() {
        $origin = null;

        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $origin = $_SERVER['HTTP_ORIGIN'];
        } elseif (isset($_SERVER['HTTP_REFERER'])) {
            $parsed = parse_url($_SERVER['HTTP_REFERER']);
            if (isset($parsed['scheme']) && isset($parsed['host'])) {
                $origin = $parsed['scheme'] . '://' . $parsed['host'];
                if (isset($parsed['port'])) {
                    $origin .= ':' . $parsed['port'];
                }
            }
        }

        return $origin;
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // Handshake endpoint
        register_rest_route($this->namespace, '/handshake', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'handle_handshake'),
                'permission_callback' => array($this, 'check_migration_permission'),
                'schema'              => array($this, 'get_handshake_schema'),
            ),
        ));

        // Scan/Manifest endpoint - get file list
        register_rest_route($this->namespace, '/scan/manifest', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_manifest'),
                'permission_callback' => array($this, 'check_migration_permission'),
            ),
        ));

        // Scan/Database endpoint - get table info
        register_rest_route($this->namespace, '/scan/database', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_database_info'),
                'permission_callback' => array($this, 'check_migration_permission'),
            ),
        ));

        // Stream file endpoint - for chunked file transfer
        register_rest_route($this->namespace, '/stream/file', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'stream_file'),
                'permission_callback' => array($this, 'check_migration_permission'),
                'args'                => array(
                    'path'  => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'description'       => 'Relative path from wp-content',
                    ),
                    'start' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                        'description'       => 'Byte offset to start from',
                    ),
                    'end'   => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                        'description'       => 'Byte offset to end at (0 = use chunk size)',
                    ),
                ),
            ),
        ));

        // Stream batch endpoint - for multiple small files
        register_rest_route($this->namespace, '/stream/batch', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'stream_batch'),
                'permission_callback' => array($this, 'check_migration_permission'),
                'args'                => array(
                    'files' => array(
                        'required'    => true,
                        'description' => 'JSON array of file paths',
                    ),
                ),
            ),
        ));

        // Database rows endpoint - streaming DB data
        register_rest_route($this->namespace, '/stream/rows', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'stream_table_rows'),
                'permission_callback' => array($this, 'check_migration_permission'),
                'args'                => array(
                    'table'     => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'description'       => 'Table name (without prefix)',
                    ),
                    'offset'    => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                        'description'       => 'Row offset',
                    ),
                    'batch'     => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 1000,
                        'description'       => 'Number of rows per batch',
                    ),
                    'last_id'   => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                        'description'       => 'Last ID seen (for keyset pagination)',
                    ),
                ),
            ),
        ));

        // Table schema endpoint
        register_rest_route($this->namespace, '/stream/schema', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_table_schema'),
                'permission_callback' => array($this, 'check_migration_permission'),
                'args'                => array(
                    'table' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ),
        ));

        // Configuration endpoint - get source info
        register_rest_route($this->namespace, '/config/info', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_source_info'),
                'permission_callback' => array($this, 'check_migration_permission'),
            ),
        ));
    }

    /**
     * Check if user is admin
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_admin_permission($request) {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permissions to perform this action.', 'simple-migrator'),
                array('status' => 403)
            );
        }
        return true;
    }

    /**
     * Check migration permission with secret key
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_migration_permission($request) {
        // Get the secret from header
        $secret = $request->get_header('X-Migration-Secret');

        if (!$secret) {
            return new WP_Error(
                'missing_secret',
                __('Migration secret is required.', 'simple-migrator'),
                array('status' => 401)
            );
        }

        // Verify secret
        $stored_secret = get_option('sm_migration_secret');

        if (!hash_equals($stored_secret, $secret)) {
            return new WP_Error(
                'invalid_secret',
                __('Invalid migration secret.', 'simple-migrator'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * Handle handshake request
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_handshake($request) {
        $secret = $request->get_header('X-Migration-Secret');

        if (!$secret || !hash_equals(get_option('sm_migration_secret'), $secret)) {
            return new WP_Error(
                'handshake_failed',
                __('Authentication failed.', 'simple-migrator'),
                array('status' => 403)
            );
        }

        // Get the origin of the request for CORS tracking
        $origin = $this->get_request_origin();

        // Store connected destination for CORS
        if ($origin) {
            $connected_destinations = get_option('sm_connected_destinations', array());
            if (!is_array($connected_destinations)) {
                $connected_destinations = array();
            }

            // Add origin if not already tracked
            if (!in_array($origin, $connected_destinations, true)) {
                $connected_destinations[] = $origin;
                // Limit to last 10 destinations to prevent option bloat
                if (count($connected_destinations) > 10) {
                    $connected_destinations = array_slice($connected_destinations, -10);
                }
                update_option('sm_connected_destinations', $connected_destinations);
            }
        }

        // Return server info
        $info = array(
            'version'         => SM_VERSION,
            'php_version'     => phpversion(),
            'wp_version'      => get_bloginfo('version'),
            'mysql_version'   => $GLOBALS['wpdb']->db_version(),
            'max_upload_size' => size_format(wp_max_upload_size()),
            'memory_limit'    => ini_get('memory_limit'),
            'max_execution'   => ini_get('max_execution_time'),
            'site_url'        => home_url(),
            'admin_email'     => get_option('admin_email'),
            'timestamp'       => current_time('mysql'),
        );

        return rest_ensure_response($info);
    }

    /**
     * Get file manifest
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_manifest($request) {
        $scanner = new File_Scanner();
        $manifest = $scanner->scan();

        // Add batch information
        $batches = $scanner->create_batches($manifest);
        $large_files = $scanner->get_large_files($manifest);

        $manifest['batches'] = count($batches);
        $manifest['large_files_count'] = count($large_files);
        $manifest['total_chunks'] = 0;

        foreach ($large_files as $file) {
            $chunks = $scanner->calculate_chunks($file['size'], SM_CHUNK_SIZE);
            $manifest['total_chunks'] += $chunks;
        }

        return rest_ensure_response($manifest);
    }

    /**
     * Get database information
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_database_info($request) {
        global $wpdb;

        $tables = $wpdb->get_results(
            "SHOW TABLES LIKE '{$wpdb->prefix}%'",
            ARRAY_N
        );

        $table_info = array();
        foreach ($tables as $table) {
            $table_name = $table[0];
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
            $table_info[] = array(
                'name' => $table_name,
                'rows' => (int) $count,
            );
        }

        return rest_ensure_response(array(
            'tables' => $table_info,
            'total_tables' => count($table_info),
        ));
    }

    /**
     * Stream file chunk
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function stream_file($request) {
        $path = $request->get_param('path');
        $start = $request->get_param('start');
        $end = $request->get_param('end');

        // Security: Ensure path is within wp-content
        $full_path = realpath(WP_CONTENT_DIR . '/' . $path);
        $content_dir = realpath(WP_CONTENT_DIR);

        // More robust path validation - ensure path starts with content dir and has directory separator
        if ($full_path === false || strpos($full_path, $content_dir . DIRECTORY_SEPARATOR) !== 0 && $full_path !== $content_dir) {
            return new WP_Error(
                'invalid_path',
                __('Invalid file path.', 'simple-migrator'),
                array('status' => 400)
            );
        }

        // Additional check: verify no symlink bypass
        if (!$this->is_path_safe($full_path, $content_dir)) {
            return new WP_Error(
                'invalid_path',
                __('File path is outside allowed directory.', 'simple-migrator'),
                array('status' => 400)
            );
        }

        if (!file_exists($full_path) || !is_file($full_path)) {
            return new WP_Error(
                'file_not_found',
                __('File not found.', 'simple-migrator'),
                array('status' => 404)
            );
        }

        $file_size = filesize($full_path);
        $chunk_size = $end > 0 ? ($end - $start) : SM_CHUNK_SIZE;

        // Open file
        $handle = fopen($full_path, 'rb');
        if (!$handle) {
            return new WP_Error(
                'file_open_failed',
                __('Could not open file.', 'simple-migrator'),
                array('status' => 500)
            );
        }

        // Seek to start position
        fseek($handle, $start);

        // Read chunk
        $data = fread($handle, $chunk_size);
        fclose($handle);

        // Calculate checksum
        $checksum = md5($data);

        return rest_ensure_response(array(
            'data' => base64_encode($data),
            'checksum' => $checksum,
            'bytes_read' => strlen($data),
            'file_size' => $file_size,
            'offset' => $start,
        ));
    }

    /**
     * Check if a path is safe (within allowed directory and not a symlink bypass)
     *
     * @param string $full_path The full path to check
     * @param string $allowed_dir The allowed base directory
     * @return bool True if path is safe
     */
    private function is_path_safe($full_path, $allowed_dir) {
        // Normalize paths
        $full_path = wp_normalize_path($full_path);
        $allowed_dir = wp_normalize_path($allowed_dir);

        // Check that path starts with allowed dir
        if (strpos($full_path, $allowed_dir) !== 0) {
            return false;
        }

        // Additional check: resolve the parent directory components
        $parts = explode('/', trim(str_replace($allowed_dir, '', $full_path), '/'));
        $depth = 0;
        foreach ($parts as $part) {
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                $depth--;
            } else {
                $depth++;
            }
            if ($depth < 0) {
                return false; // Tried to go above allowed dir
            }
        }

        return true;
    }

    /**
     * Stream batch of files (for small files)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function stream_batch($request) {
        $files_json = $request->get_param('files');
        $files = json_decode($files_json, true);

        if (!is_array($files)) {
            return new WP_Error(
                'invalid_files',
                __('Invalid files parameter.', 'simple-migrator'),
                array('status' => 400)
            );
        }

        // Create temporary zip file with better cleanup
        $temp_dir = sys_get_temp_dir();
        $temp_file = tempnam($temp_dir, 'sm_zip_');
        $zip_file = $temp_file . '.zip';
        register_shutdown_function(function() use ($zip_file) {
            if (file_exists($zip_file)) {
                @unlink($zip_file);
            }
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
        });

        // Delete the temp file so ZipArchive can create the zip
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new WP_Error(
                'zip_create_failed',
                __('Could not create zip archive.', 'simple-migrator'),
                array('status' => 500)
            );
        }

        $content_dir = realpath(WP_CONTENT_DIR);

        // Add files to zip
        foreach ($files as $file_path) {
            // Security: Ensure path is within wp-content
            $full_path = realpath(WP_CONTENT_DIR . '/' . $file_path);

            // Use the same robust path validation
            if ($full_path === false || strpos($full_path, $content_dir . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            if (!file_exists($full_path) || !is_file($full_path)) {
                continue;
            }

            // Add file to zip with relative path
            $local_name = $file_path;
            $zip->addFile($full_path, $local_name);
        }

        $zip->close();

        // Read zip file
        $zip_data = file_get_contents($zip_file);
        $checksum = md5($zip_data);

        // Clean up temp file (explicit cleanup, shutdown handler is backup)
        unlink($zip_file);

        return rest_ensure_response(array(
            'data' => base64_encode($zip_data),
            'checksum' => $checksum,
            'size' => strlen($zip_data),
            'file_count' => count($files),
        ));
    }

    /**
     * Stream table rows
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function stream_table_rows($request) {
        global $wpdb;

        $table = $request->get_param('table');
        $offset = $request->get_param('offset');
        $batch = $request->get_param('batch');
        $last_id = $request->get_param('last_id');

        // Security: Validate table name
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return new WP_Error(
                'invalid_table',
                __('Invalid table name.', 'simple-migrator'),
                array('status' => 400)
            );
        }

        // Detect primary key column for keyset pagination
        $primary_key = $this->get_primary_key($table);
        $use_keyset = ($primary_key && $last_id > 0);

        // Build query based on pagination method
        if ($use_keyset) {
            // Keyset pagination using detected primary key
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `$table` WHERE `$primary_key` > %d ORDER BY `$primary_key` ASC LIMIT %d",
                    $last_id,
                    $batch
                ),
                ARRAY_A
            );
        } else {
            // Offset pagination (fallback for tables without suitable primary key)
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `$table` LIMIT %d, %d",
                    $offset,
                    $batch
                ),
                ARRAY_A
            );
        }

        // Encode binary data
        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                // Check if value is binary or non-UTF8
                if (!mb_check_encoding($value, 'UTF-8')) {
                    $row[$key] = base64_encode($value);
                    $row[$key . '_base64'] = true;
                }
            }
        }

        // Get next ID for pagination (use detected primary key)
        $next_id = 0;
        if ($primary_key && !empty($rows)) {
            $last_row = $rows[count($rows) - 1];
            $next_id = isset($last_row[$primary_key]) ? (int) $last_row[$primary_key] : 0;
        }

        return rest_ensure_response(array(
            'rows' => $rows,
            'count' => count($rows),
            'next_id' => $next_id,
            'has_more' => count($rows) === $batch,
            'primary_key' => $primary_key,
        ));
    }

    /**
     * Get the primary key column for a table
     *
     * @param string $table Table name
     * @return string|null Primary key column name or null
     */
    private function get_primary_key($table) {
        global $wpdb;

        // Get table indexes
        $indexes = $wpdb->get_results(
            "SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        );

        if (empty($indexes)) {
            // No primary key, try to find an auto_increment column
            $columns = $wpdb->get_results(
                "SHOW COLUMNS FROM `$table` WHERE Extra LIKE '%auto_increment%'",
                ARRAY_A
            );

            if (!empty($columns)) {
                return $columns[0]['Field'];
            }

            // Try common WordPress primary keys
            $common_keys = array('ID', 'id', 'option_id', 'user_id', 'term_id', 'comment_ID', 'post_id', 'link_id');
            $existing_columns = $wpdb->get_col(
                "SHOW COLUMNS FROM `$table`"
            );

            foreach ($common_keys as $key) {
                if (in_array($key, $existing_columns, true)) {
                    return $key;
                }
            }

            return null;
        }

        // Single column primary key
        if (count($indexes) === 1) {
            return $indexes[0]['Column_name'];
        }

        // Multi-column primary key - return first column
        return $indexes[0]['Column_name'];
    }

    /**
     * Get table schema
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_table_schema($request) {
        global $wpdb;

        $table = $request->get_param('table');

        // Security: Validate table name
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return new WP_Error(
                'invalid_table',
                __('Invalid table name.', 'simple-migrator'),
                array('status' => 400)
            );
        }

        $schema = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);

        if (!$schema) {
            return new WP_Error(
                'schema_not_found',
                __('Could not retrieve table schema.', 'simple-migrator'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'schema' => $schema[1],
        ));
    }

    /**
     * Get source information
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_source_info($request) {
        return rest_ensure_response(array(
            'site_url' => home_url(),
            'home_url' => home_url(),
            'admin_url' => admin_url(),
            'wp_version' => get_bloginfo('version'),
            'table_prefix' => $GLOBALS['wpdb']->prefix,
            'is_multisite' => is_multisite(),
            'active_plugins' => get_option('active_plugins', array()),
        ));
    }

    /**
     * Get handshake schema
     *
     * @return array
     */
    public function get_handshake_schema() {
        return array(
            '$schema'              => 'http://json-schema.org/draft-04/schema#',
            'title'                => 'handshake',
            'type'                 => 'object',
            'properties'           => array(
                'version'       => array(
                    'description' => 'Plugin version',
                    'type'        => 'string',
                ),
                'php_version'   => array(
                    'description' => 'PHP version',
                    'type'        => 'string',
                ),
                'wp_version'    => array(
                    'description' => 'WordPress version',
                    'type'        => 'string',
                ),
            ),
        );
    }
}

// Initialize REST routes
add_action('rest_api_init', function() {
    REST_Controller::get_instance()->register_routes();
});
