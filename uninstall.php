<?php
/**
 * Simple Migrator Uninstall
 *
 * Removes all plugin data when the plugin is deleted through WordPress admin.
 *
 * @package Simple_Migrator
 */

// Exit if not called by WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
$options_to_delete = array(
    'sm_migration_secret',
    'sm_source_mode',
    'sm_source_url',
    'sm_dev_saved_source_key',
    'sm_connected_sources',
    'sm_connected_destinations',
    'sm_settings',
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Delete transients
delete_transient('sm_preserved_options');
delete_transient('sm_preserved_admin');
delete_transient('sm_migration_lock');

// Recursively remove backup directory
$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/sm-backups/';

if (is_dir($backup_dir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backup_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }

    @rmdir($backup_dir);
}
