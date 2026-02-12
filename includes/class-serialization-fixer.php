<?php
/**
 * Serialization Fixer
 *
 * Handles recursive search and replace while preserving PHP serialized data structure.
 * This is critical for WordPress migrations as options, postmeta, and other data
 * are stored in serialized format.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

class Serialization_Fixer {

    /**
     * Tables to scan for serialized data
     *
     * @var array
     */
    private $tables_to_scan = array();

    /**
     * Source URL
     *
     * @var string
     */
    private $source_url = '';

    /**
     * Destination URL
     *
     * @var string
     */
    private $destination_url = '';

    /**
     * Statistics
     *
     * @var array
     */
    private $stats = array(
        'tables_processed' => 0,
        'rows_processed' => 0,
        'replacements_made' => 0,
        'errors' => array()
    );

    /**
     * Constructor
     */
    public function __construct() {
        $this->tables_to_scan = array(
            'options',
            'postmeta',
            'commentmeta',
            'termmeta',
            'usermeta',
            'posts',
            'comments'
        );
    }

    /**
     * Perform search and replace
     *
     * @param string $source_url
     * @param string $destination_url
     * @return array Results with statistics
     */
    public function replace($source_url, $destination_url) {
        global $wpdb;

        $this->source_url = rtrim($source_url, '/');
        $this->destination_url = rtrim($destination_url, '/');
        $this->stats = array(
            'tables_processed' => 0,
            'rows_processed' => 0,
            'replacements_made' => 0,
            'errors' => array()
        );

        // Also handle URLs with and without trailing slashes
        $search_urls = array(
            $this->source_url,
            $this->source_url . '/',
            str_replace('https://', 'http://', $this->source_url),
            str_replace('https://', 'http://', $this->source_url) . '/',
            str_replace('http://', 'https://', $this->source_url),
            str_replace('http://', 'https://', $this->source_url) . '/'
        );

        $replace_urls = array(
            $this->destination_url,
            $this->destination_url . '/',
            str_replace('https://', 'http://', $this->destination_url),
            str_replace('https://', 'http://', $this->destination_url) . '/',
            str_replace('http://', 'https://', $this->destination_url),
            str_replace('http://', 'https://', $this->destination_url) . '/'
        );

        // Sort search URLs longest-first to prevent partial matches
        array_multisort(
            array_map('strlen', $search_urls),
            SORT_DESC,
            $search_urls,
            $replace_urls
        );

        foreach ($this->tables_to_scan as $table) {
            $full_table = $wpdb->prefix . $table;

            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table));
            if (!$table_exists) {
                continue;
            }

            $this->process_table($full_table, $search_urls, $replace_urls);
            $this->stats['tables_processed']++;
        }

        return $this->stats;
    }

    /**
     * Process a single table
     *
     * @param string $table
     * @param array $search_urls
     * @param array $replace_urls
     */
    private function process_table($table, $search_urls, $replace_urls) {
        global $wpdb;

        // Get all columns and their types
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);

        if (empty($columns)) {
            return;
        }

        // Get text-based columns
        $text_columns = array();
        foreach ($columns as $column) {
            $type = strtoupper($column['Type']);
            if (strpos($type, 'CHAR') !== false ||
                strpos($type, 'TEXT') !== false ||
                strpos($type, 'LONGTEXT') !== false ||
                strpos($type, 'MEDIUMTEXT') !== false) {
                $text_columns[] = $column['Field'];
            }
        }

        if (empty($text_columns)) {
            return;
        }

        // Get primary key
        $primary_key = $this->get_primary_key($table);

        // Process rows in batches using keyset pagination when possible
        $batch_size = Settings::get_instance()->get('batch_size');
        $last_pk_value = null;
        $use_keyset = ($primary_key !== null);

        while (true) {
            // Get a batch of rows
            $select_columns = array_unique(array_merge(
                $primary_key !== null ? array("`{$primary_key}`") : array(),
                array_map(function($c) { return "`{$c}`"; }, $text_columns)
            ));
            $columns_str = implode(', ', $select_columns);

            if ($use_keyset && $last_pk_value !== null) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT {$columns_str} FROM `{$table}` WHERE `{$primary_key}` > %s ORDER BY `{$primary_key}` ASC LIMIT %d",
                        $last_pk_value,
                        $batch_size
                    ),
                    ARRAY_A
                );
            } else {
                $order_clause = $use_keyset ? "ORDER BY `{$primary_key}` ASC" : '';
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT {$columns_str} FROM `{$table}` {$order_clause} LIMIT %d",
                        $batch_size
                    ),
                    ARRAY_A
                );
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $this->process_row($table, $row, $text_columns, $primary_key, $search_urls, $replace_urls);
                $this->stats['rows_processed']++;
            }

            // Update keyset pagination cursor
            if ($use_keyset && !empty($rows)) {
                $last_row = end($rows);
                $last_pk_value = $last_row[$primary_key];
            }

            if (count($rows) < $batch_size) {
                break;
            }

            // Prevent timeout
            if (function_exists('set_time_limit')) {
                set_time_limit(30);
            }
        }
    }

    /**
     * Process a single row
     *
     * @param string $table
     * @param array $row
     * @param array $text_columns
     * @param string $primary_key
     * @param array $search_urls
     * @param array $replace_urls
     */
    private function process_row($table, $row, $text_columns, $primary_key, $search_urls, $replace_urls) {
        global $wpdb;

        $update_needed = false;
        $update_data = array();

        foreach ($text_columns as $column) {
            $value = $row[$column];

            // Skip empty values
            if (empty($value)) {
                continue;
            }

            // Check if value might be serialized
            if ($this->is_serialized($value)) {
                $new_value = $this->recursive_replace($value, $search_urls, $replace_urls);

                if ($new_value !== $value) {
                    $update_data[$column] = $new_value;
                    $update_needed = true;
                }
            } else {
                // Simple string replacement
                $new_value = $this->simple_replace($value, $search_urls, $replace_urls);

                if ($new_value !== $value) {
                    $update_data[$column] = $new_value;
                    $update_needed = true;
                }
            }
        }

        // Perform update if needed
        if ($update_needed) {
            // Use correct format specifiers
            $data_format = array_fill(0, count($update_data), '%s');
            $pk_format = is_numeric($row[$primary_key]) ? '%d' : '%s';

            $result = $wpdb->update(
                $table,
                $update_data,
                array($primary_key => $row[$primary_key]),
                $data_format,
                array($pk_format)
            );

            if ($result !== false) {
                $this->stats['replacements_made']++;
            }
        }
    }

    /**
     * Recursive replacement for serialized data
     *
     * @param mixed $data
     * @param array $search_urls
     * @param array $replace_urls
     * @return mixed
     */
    private function recursive_replace($data, $search_urls, $replace_urls) {
        // Try to unserialize
        $unserialized = @unserialize($data);

        if ($unserialized === false && $data !== 'b:0;') {
            // Not valid serialized data, do simple replacement
            return $this->simple_replace($data, $search_urls, $replace_urls);
        }

        // Recursively process the unserialized data
        $unserialized = $this->process_data_structure($unserialized, $search_urls, $replace_urls);

        // Re-serialize with correct length counters
        return serialize($unserialized);
    }

    /**
     * Process data structure recursively
     *
     * @param mixed $data
     * @param array $search_urls
     * @param array $replace_urls
     * @return mixed
     */
    private function process_data_structure($data, $search_urls, $replace_urls) {
        if (is_string($data)) {
            // Check if the string itself is serialized (nested serialization)
            if ($this->is_serialized($data)) {
                return $this->recursive_replace($data, $search_urls, $replace_urls);
            }
            // Perform replacement
            return $this->simple_replace($data, $search_urls, $replace_urls);
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->process_data_structure($value, $search_urls, $replace_urls);
            }
            return $data;
        } elseif (is_object($data)) {
            // Handle objects carefully
            $object_vars = get_object_vars($data);
            foreach ($object_vars as $key => $value) {
                $data->$key = $this->process_data_structure($value, $search_urls, $replace_urls);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Simple string replacement
     *
     * @param string $string
     * @param array $search_urls
     * @param array $replace_urls
     * @return string
     */
    private function simple_replace($string, $search_urls, $replace_urls) {
        return str_replace($search_urls, $replace_urls, $string);
    }

    /**
     * Check if value is serialized
     *
     * @param string $data
     * @param bool $strict
     * @return bool
     */
    private function is_serialized($data, $strict = true) {
        // If it isn't a string, it isn't serialized
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);

        if ($data === 'N;') {
            return true;
        }

        if (strlen($data) < 4) {
            return false;
        }

        if ($data[1] !== ':') {
            return false;
        }

        if ($strict) {
            $lastc = substr($data, -1);
            if ($lastc !== ';' && $lastc !== '}') {
                return false;
            }
        } else {
            $semicolon = strpos($data, ';');
            $brace = strpos($data, '}');

            // Either ; or } must exist
            if ($semicolon === false && $brace === false) {
                return false;
            }

            // But neither can be in the first 3 characters
            if ($semicolon !== false && $semicolon < 3) {
                return false;
            }
            if ($brace !== false && $brace < 4) {
                return false;
            }
        }

        $token = $data[0];

        switch ($token) {
            case 's':
                if ($strict) {
                    if (substr($data, -2, 1) !== '"') {
                        return false;
                    }
                } elseif (strpos($data, '"') === false) {
                    return false;
                }
                break;
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E-]+;$end/", $data);
        }

        return false;
    }

    /**
     * Get primary key column for table
     *
     * Delegates to shared Database_Utils to avoid duplication.
     *
     * @param string $table
     * @return string|null
     */
    private function get_primary_key($table) {
        return Database_Utils::get_primary_key($table);
    }

    /**
     * Update site URLs in options table
     *
     * @param string $new_url
     */
    public function update_site_options($new_url) {
        update_option('siteurl', $new_url);
        update_option('home', $new_url);
    }
}
