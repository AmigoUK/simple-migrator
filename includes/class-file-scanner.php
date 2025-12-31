<?php
/**
 * File Scanner
 *
 * Recursively scans the wp-content directory to generate a file manifest
 * for migration. Uses SPL iterators for memory-efficient operation.
 *
 * @package Simple_Migrator
 */

namespace Simple_Migrator;

class File_Scanner {

    /**
     * Files to exclude from migration
     *
     * @var array
     */
    private $exclude_files = array(
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
    );

    /**
     * Directories to exclude from migration
     *
     * @var array
     */
    private $exclude_dirs = array(
        'cache',
        'backups',
        'upgrade',
        'uploads/cache',  // Cache files in uploads
    );

    /**
     * Maximum file size for batch processing (2MB)
     * Files larger than this will be transferred in chunks
     *
     * @var int
     */
    private $batch_threshold = 2097152; // 2MB in bytes

    /**
     * Scan wp-content directory and generate manifest
     *
     * @param bool $include_uploads Whether to include uploads directory
     * @return array Manifest with file list and statistics
     */
    public function scan($include_uploads = true) {
        $manifest = array(
            'files' => array(),
            'total_size' => 0,
            'total_count' => 0,
            'large_files' => 0,
            'small_files' => 0,
        );

        $wp_content = WP_CONTENT_DIR;

        // Directories to scan within wp-content
        $scan_dirs = array('plugins', 'themes', 'uploads');
        if (!$include_uploads) {
            $scan_dirs = array('plugins', 'themes');
        }

        foreach ($scan_dirs as $dir) {
            $dir_path = $wp_content . '/' . $dir;

            if (!is_dir($dir_path)) {
                continue;
            }

            $this->scan_directory($dir_path, $dir, $manifest);
        }

        return $manifest;
    }

    /**
     * Scan a single directory recursively
     *
     * @param string $dir_path Full path to directory
     * @param string $relative_path Relative path from wp-content
     * @param array &$manifest Manifest array (passed by reference)
     */
    private function scan_directory($dir_path, $relative_path, &$manifest) {
        try {
            $directory = new \RecursiveDirectoryIterator(
                $dir_path,
                \FilesystemIterator::SKIP_DOTS |
                \FilesystemIterator::UNIX_PATHS
            );

            $iterator = new \RecursiveIteratorIterator(
                $directory,
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD // Skip unreadable directories
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();
                $filepath = $file->getPathname();
                $relative_file_path = str_replace(WP_CONTENT_DIR . '/', '', $filepath);

                // Check if file should be excluded
                if ($this->should_exclude_file($filename, $relative_file_path)) {
                    continue;
                }

                // Check if parent directory should be excluded
                if ($this->should_exclude_directory($file->getPath())) {
                    continue;
                }

                $file_size = $file->getSize();
                $file_info = array(
                    'path' => $relative_file_path,
                    'name' => $filename,
                    'size' => $file_size,
                    'modified' => $file->getMTime(),
                    'is_large' => $file_size > $this->batch_threshold,
                );

                $manifest['files'][] = $file_info;
                $manifest['total_size'] += $file_size;
                $manifest['total_count']++;

                if ($file_size > $this->batch_threshold) {
                    $manifest['large_files']++;
                } else {
                    $manifest['small_files']++;
                }
            }
        } catch (\Exception $e) {
            // Log error but continue with other directories
            error_log('Simple Migrator: Error scanning directory ' . $dir_path . ': ' . $e->getMessage());
        }
    }

    /**
     * Check if a file should be excluded
     *
     * @param string $filename
     * @param string $relative_path
     * @return bool
     */
    private function should_exclude_file($filename, $relative_path) {
        // Check filename against exclude list
        if (in_array($filename, $this->exclude_files)) {
            return true;
        }

        // Check file extensions
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $exclude_extensions = array('log', 'tmp', 'bak', 'swp', 'swo');

        if (in_array($extension, $exclude_extensions)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a directory should be excluded
     *
     * @param string $dir_path Full directory path
     * @return bool
     */
    private function should_exclude_directory($dir_path) {
        $parts = explode('/', $dir_path);

        foreach ($parts as $part) {
            if (in_array($part, $this->exclude_dirs)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Group small files into batches for efficient transfer
     *
     * @param array $manifest File manifest from scan()
     * @param int $max_batch_size Maximum size per batch in bytes (default 2MB)
     * @return array Array of batches, each containing file paths
     */
    public function create_batches($manifest, $max_batch_size = 2097152) {
        $batches = array();
        $current_batch = array();
        $current_size = 0;

        foreach ($manifest['files'] as $file) {
            // Skip large files - they're transferred individually
            if ($file['is_large']) {
                continue;
            }

            $file_size = $file['size'];

            // If adding this file would exceed batch size, start new batch
            if ($current_size + $file_size > $max_batch_size && !empty($current_batch)) {
                $batches[] = $current_batch;
                $current_batch = array();
                $current_size = 0;
            }

            $current_batch[] = $file['path'];
            $current_size += $file_size;
        }

        // Add final batch if not empty
        if (!empty($current_batch)) {
            $batches[] = $current_batch;
        }

        return $batches;
    }

    /**
     * Get list of large files that need chunked transfer
     *
     * @param array $manifest File manifest from scan()
     * @return array Array of large file info
     */
    public function get_large_files($manifest) {
        return array_filter($manifest['files'], function($file) {
            return $file['is_large'];
        });
    }

    /**
     * Calculate number of chunks needed for a file
     *
     * @param int $file_size File size in bytes
     * @param int $chunk_size Chunk size in bytes (default 2MB)
     * @return int Number of chunks
     */
    public function calculate_chunks($file_size, $chunk_size = 2097152) {
        return (int) ceil($file_size / $chunk_size);
    }

    /**
     * Get human-readable size string
     *
     * @param int $bytes Size in bytes
     * @param int $decimals Number of decimal places
     * @return string Formatted size string
     */
    public function format_size($bytes, $decimals = 2) {
        $size_units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($size_units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $decimals) . ' ' . $size_units[$pow];
    }
}
