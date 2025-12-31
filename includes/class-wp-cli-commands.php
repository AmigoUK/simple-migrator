<?php
/**
 * WP-CLI Commands for Simple Migrator
 *
 * Provides command-line interface for backup and restore operations.
 * This enables emergency recovery when the WordPress site is broken.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

// Only load if WP-CLI is available
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

use WP_CLI;
use WP_CLI_Command;

/**
 * WP-CLI Commands for Simple Migrator
 */
class WP_CLI_Commands extends WP_CLI_Command {

    /**
     * List all available backups
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, csv, json, count)
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * ## EXAMPLES
     *     wp sm backup list
     *     wp sm backup list --format=json
     *
     * @when after_wp_load
     * @subcommand list
     */
    public function list_backups($args, $assoc_args) {
        $backup_manager = Backup_Manager::get_instance();
        $backups = $backup_manager->get_all_backups();

        if (empty($backups)) {
            WP_CLI::warning('No backups found.');
            return;
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $data = array();
        foreach ($backups as $backup) {
            $metadata = $backup_manager->get_backup_metadata($backup);
            if ($metadata) {
                $data[] = array(
                    'backup_id' => $metadata['backup_id'],
                    'created' => $metadata['created_at'],
                    'db_size' => size_format($metadata['db_size']),
                    'files_size' => size_format($metadata['files_size']),
                    'total_size' => size_format($metadata['total_size']),
                    'status' => $metadata['status'],
                );
            }
        }

        WP_CLI\Utils\format_items($format, $data, array(
            'backup_id', 'created', 'db_size', 'files_size', 'total_size', 'status'
        ));
    }

    /**
     * Create a new backup
     *
     * ## OPTIONS
     *
     * [--progress]
     * : Show progress during backup creation
     *
     * ## EXAMPLES
     *     wp sm backup create
     *     wp sm backup create --progress
     *
     * @when after_wp_load
     */
    public function create($args, $assoc_args) {
        $backup_manager = Backup_Manager::get_instance();

        WP_CLI::log('Creating backup...');

        $progress_callback = isset($assoc_args['progress']) ? function($progress, $message) {
            WP_CLI::log(sprintf("[%d%%] %s", $progress, $message));
        } : null;

        try {
            $result = $backup_manager->create_backup($progress_callback);

            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            WP_CLI::success(sprintf(
                'Backup created successfully: %s (DB: %s, Files: %s)',
                $result['backup_id'],
                size_format($result['db_size']),
                size_format($result['files_size'])
            ));
        } catch (\Exception $e) {
            WP_CLI::error('Backup failed: ' . $e->getMessage());
        }
    }

    /**
     * Restore a backup
     *
     * ## OPTIONS
     *
     * <backup-id>
     * : The backup ID to restore
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * [--skip-db]
     * : Skip database restoration (only files)
     *
     * [--skip-files]
     * : Skip file restoration (only database)
     *
     * ## EXAMPLES
     *     wp sm backup restore backup-2025-12-31-192822
     *     wp sm backup restore backup-2025-12-31-192822 --yes
     *     wp sm backup restore backup-2025-12-31-192822 --skip-files
     *
     * @when after_wp_load
     * @subcommand restore
     */
    public function restore_backup($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Please specify a backup ID.');
        }

        $backup_id = $args[0];
        $backup_manager = Backup_Manager::get_instance();

        // Check if backup exists
        $backup_path = $backup_manager->get_backup_dir() . $backup_id . '/';
        if (!file_exists($backup_path . 'backup.json')) {
            WP_CLI::error('Backup not found: ' . $backup_id);
        }

        $metadata = json_decode(file_get_contents($backup_path . 'backup.json'), true);

        // Confirm restoration
        if (!isset($assoc_args['yes'])) {
            WP_CLI::warning('This will REPLACE your current database and files!');
            WP_CLI::log(sprintf('Backup: %s', $backup_id));
            WP_CLI::log(sprintf('Created: %s', $metadata['created_at']));
            WP_CLI::log(sprintf('DB Size: %s', size_format($metadata['db_size'])));
            WP_CLI::log(sprintf('Files Size: %s', size_format($metadata['files_size'])));

            WP_CLI::confirm('Are you sure you want to continue?');
        }

        WP_CLI::log('Starting restoration...');

        // Handle partial restoration
        $skip_db = isset($assoc_args['skip-db']);
        $skip_files = isset($assoc_args['skip-files']);

        if ($skip_db && $skip_files) {
            WP_CLI::error('Cannot skip both database and files. Nothing to restore.');
        }

        try {
            if ($skip_db) {
                WP_CLI::log('Skipping database restoration (--skip-db)');
                $result = $backup_manager->restore_files($backup_path);
            } elseif ($skip_files) {
                WP_CLI::log('Skipping file restoration (--skip-files)');
                $result = $backup_manager->restore_database($backup_path);
            } else {
                $result = $backup_manager->restore_backup($backup_id);
            }

            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            WP_CLI::success('Backup restored successfully!');
            WP_CLI::log('Please refresh your site to verify.');
        } catch (\Exception $e) {
            WP_CLI::error('Restore failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete a backup
     *
     * ## OPTIONS
     *
     * <backup-id>
     * : The backup ID to delete
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *     wp sm backup delete backup-2025-12-31-192822
     *     wp sm backup delete backup-2025-12-31-192822 --yes
     *
     * @when after_wp_load
     */
    public function delete($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Please specify a backup ID.');
        }

        $backup_id = $args[0];

        if (!isset($assoc_args['yes'])) {
            WP_CLI::confirm(sprintf('Delete backup %s?', $backup_id));
        }

        $backup_manager = Backup_Manager::get_instance();
        $result = $backup_manager->delete_backup($backup_id);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success('Backup deleted: ' . $backup_id);
    }

    /**
     * Clean old backups, keeping only the N most recent
     *
     * ## OPTIONS
     *
     * [--keep=<number>]
     * : Number of backups to keep
     * ---
     * default: 3
     * ---
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *     wp sm backup clean --keep=3
     *     wp sm backup clean --keep=5 --yes
     *
     * @when after_wp_load
     */
    public function clean($args, $assoc_args) {
        $keep = isset($assoc_args['keep']) ? intval($assoc_args['keep']) : 3;

        if ($keep < 1) {
            WP_CLI::error('--keep must be at least 1');
        }

        $backup_manager = Backup_Manager::get_instance();
        $backups = $backup_manager->get_all_backups();

        if (count($backups) <= $keep) {
            WP_CLI::log(sprintf('You have %d backups, keeping %d. Nothing to clean.', count($backups), $keep));
            return;
        }

        // Sort by name (timestamp), oldest first
        rsort($backups);
        $to_delete = array_slice($backups, $keep);

        if (!isset($assoc_args['yes'])) {
            WP_CLI::log('Backups to delete:');
            foreach ($to_delete as $backup_id) {
                WP_CLI::log('  - ' . $backup_id);
            }
            WP_CLI::confirm(sprintf('Delete %d old backup(s)?', count($to_delete)));
        }

        $deleted = 0;
        foreach ($to_delete as $backup_id) {
            WP_CLI::log('Deleting: ' . $backup_id);
            $result = $backup_manager->delete_backup($backup_id);
            if (!is_wp_error($result)) {
                $deleted++;
            }
        }

        WP_CLI::success(sprintf('Cleaned up %d old backup(s).', $deleted));
    }
}

// Register commands with WP-CLI
WP_CLI::add_command('sm backup', new WP_CLI_Commands());
