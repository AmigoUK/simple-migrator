<?php
/**
 * Settings
 *
 * Central configuration management for Simple Migrator.
 * Provides configurable parameters with sensible defaults,
 * validation, and filter hooks for developer customization.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

class Settings {

    /**
     * Single instance
     *
     * @var Settings
     */
    private static $instance = null;

    /**
     * Option name in wp_options
     *
     * @var string
     */
    const OPTION_NAME = 'sm_settings';

    /**
     * Current settings (merged with defaults)
     *
     * @var array
     */
    private $settings = array();

    /**
     * Default values for all settings
     *
     * @var array
     */
    private $defaults = array(
        // UI-configurable settings
        'chunk_size'    => 2097152,   // 2MB in bytes
        'batch_size'    => 1000,      // rows per batch
        'max_retries'   => 5,
        'max_backups'   => 3,
        'lock_timeout'  => 1800,      // 30 minutes in seconds

        // Developer-only settings (filter hooks, no UI)
        'protected_tables' => array('users', 'usermeta'),
        'protected_options' => array(
            'siteurl',
            'home',
            'admin_email',
            'active_plugins',
            'current_theme',
            'template',
            'stylesheet',
            'sm_migration_secret',
            'sm_source_url',
            'sm_source_mode',
        ),
        'exclude_files' => array(
            '.git',
            '.svn',
            '.hg',
            'node_modules',
            'bower_components',
            '.DS_Store',
            'Thumbs.db',
            '.env',
            '.htaccess',
            'debug.log',
        ),
        'exclude_dirs' => array(
            'cache',
            'sm-backups',
            'upgrade',
            'simple-migrator',
        ),
        'exclude_extensions' => array('log', 'tmp', 'bak', 'swp', 'swo'),
        'backup_exclude_patterns' => array(
            '/\.git$/',
            '/\.svn$/',
            '/\.hg$/',
            '/\.sass-cache$/',
            '/node_modules$/',
            '/\.npm$/',
            '/\.bower$/',
            '/vendor\/bower\//',
            '/\.idea$/',
            '/\.vscode$/',
            '/\.DS_Store$/',
            '/Thumbs\.db$/',
            '/\.log$/',
        ),
    );

    /**
     * Validation rules for UI-configurable settings
     * Format: key => [min, max]
     *
     * @var array
     */
    private $validation_rules = array(
        'chunk_size'   => array(524288, 10485760),    // 0.5MB – 10MB
        'batch_size'   => array(100, 5000),
        'max_retries'  => array(1, 10),
        'max_backups'  => array(1, 10),
        'lock_timeout' => array(300, 7200),            // 5min – 120min
    );

    /**
     * Get instance
     *
     * @return Settings
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
        $this->load();
    }

    /**
     * Load settings from database, merged with defaults
     */
    private function load() {
        $saved = get_option(self::OPTION_NAME, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $this->settings = wp_parse_args($saved, $this->defaults);
    }

    /**
     * Get a setting value
     *
     * Applies filter "sm_setting_{$key}" so developers can override any value.
     *
     * @param string $key Setting key
     * @return mixed Setting value
     */
    public function get($key) {
        $value = isset($this->settings[$key]) ? $this->settings[$key] : null;
        return apply_filters("sm_setting_{$key}", $value);
    }

    /**
     * Update a single setting
     *
     * @param string $key   Setting key
     * @param mixed  $value Setting value
     * @return bool True on success
     */
    public function update($key, $value) {
        if (!array_key_exists($key, $this->defaults)) {
            return false;
        }

        $value = $this->validate($key, $value);
        $this->settings[$key] = $value;

        return $this->save();
    }

    /**
     * Update multiple settings at once
     *
     * @param array $settings Key-value pairs to update
     * @return bool True on success
     */
    public function update_all($settings) {
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, $this->defaults)) {
                continue;
            }
            $this->settings[$key] = $this->validate($key, $value);
        }

        return $this->save();
    }

    /**
     * Get all default values
     *
     * @return array
     */
    public function get_defaults() {
        return $this->defaults;
    }

    /**
     * Reset all settings to defaults
     *
     * @return bool True on success
     */
    public function reset() {
        $this->settings = $this->defaults;
        return $this->save();
    }

    /**
     * Validate a setting value against its rules
     *
     * @param string $key   Setting key
     * @param mixed  $value Value to validate
     * @return mixed Validated (and possibly clamped) value
     */
    public function validate($key, $value) {
        if (isset($this->validation_rules[$key])) {
            $value = (int) $value;
            list($min, $max) = $this->validation_rules[$key];
            $value = max($min, min($max, $value));
        }

        return $value;
    }

    /**
     * Get validation rules for a setting
     *
     * @param string $key Setting key
     * @return array|null [min, max] or null if no rules
     */
    public function get_validation_rules($key) {
        return isset($this->validation_rules[$key]) ? $this->validation_rules[$key] : null;
    }

    /**
     * Save current settings to database (only non-default values)
     *
     * @return bool
     */
    private function save() {
        // Only store values that differ from defaults to keep the option small
        $to_save = array();
        foreach ($this->settings as $key => $value) {
            if (!isset($this->defaults[$key]) || $this->defaults[$key] !== $value) {
                $to_save[$key] = $value;
            }
        }

        return update_option(self::OPTION_NAME, $to_save);
    }
}
