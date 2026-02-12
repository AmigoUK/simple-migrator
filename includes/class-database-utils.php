<?php
/**
 * Database Utilities
 *
 * Shared database helper methods used across the plugin.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

class Database_Utils {

    /**
     * Get the primary key column for a table
     *
     * Returns null if no primary key is found, rather than falling back
     * to the first column (which could cause data corruption).
     *
     * @param string $table Table name
     * @return string|null Primary key column name or null
     */
    public static function get_primary_key($table) {
        global $wpdb;

        // Validate table name
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return null;
        }

        // Get primary key index
        $indexes = $wpdb->get_results(
            "SHOW INDEX FROM `$table` WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        );

        if (!empty($indexes)) {
            // Return first column of primary key
            return $indexes[0]['Column_name'];
        }

        // Fallback: try to find an auto_increment column
        $columns = $wpdb->get_results(
            "SHOW COLUMNS FROM `$table` WHERE Extra LIKE '%auto_increment%'",
            ARRAY_A
        );

        if (!empty($columns)) {
            return $columns[0]['Field'];
        }

        // Try common WordPress primary keys
        $common_keys = array('ID', 'id', 'option_id', 'user_id', 'term_id', 'comment_ID', 'post_id', 'link_id');
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`");

        foreach ($common_keys as $key) {
            if (in_array($key, $existing_columns, true)) {
                return $key;
            }
        }

        return null;
    }
}
