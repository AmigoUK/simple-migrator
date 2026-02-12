# Changelog

All notable changes to Simple Migrator will be documented in this file.

## [1.0.29] - 2026-02-12
### Fixed - Comprehensive Security Hardening (116 issues)

**Security (Critical):**
- SQL injection via unvalidated column names in `process_rows`
- Path traversal TOCTOU in `write_chunk` and `extract_batch`
- Command injection in mysqldump via `escapeshellcmd` misuse
- XSS in admin.js (8 instances of unescaped `.html()` calls)
- ZIP extraction path traversal with depth tracking
- Arbitrary SQL execution in backup restore (added statement whitelist)
- Backup delete/restore path traversal (validate backup_id format)
- Migration secret logging removed from browser console

**Security (High):**
- CORS policy: removed `.dev` TLD and digit-stripped hostname matching
- Prototype pollution via `Object.assign` in MigrationState
- Added `X-WP-Nonce` CSRF headers to `fetch()` calls
- Added concurrent migration lock via transients
- Removed sensitive info exposure (admin_email, php_version, active_plugins)
- Fixed DOS via unbounded batch request (limit to 100 files)
- Fixed memory exhaustion with batched LIMIT/OFFSET queries

**Bug Fixes:**
- Transaction rollback dead code (namespace Exception catch)
- Double REST route and AJAX handler registration
- Operator precedence bug in path validation
- `check_migration_permission` TypeError on missing option
- Undefined `$result` variable and `$wpdb->show_errors` leak
- Wrong backup path (`uploads/simple-migrator` → `uploads/sm-backups`)
- Division by zero in progress calculation and `add_files_to_zip`
- Pause/resume race condition in migration state
- Error swallowing in createBackup stream processing
- Nonce action mismatch (`wp_rest` → `sm_admin_nonce`)
- `home_url('|')` generating wrong URL format
- Version constant mismatch (1.0.27 → 1.0.29)

**Architecture:**
- Added shared `Database_Utils::get_primary_key()` to deduplicate logic
- Replace OFFSET pagination with keyset pagination in serialization fixer
- Added nested serialized data handling
- Added multisite guard to prevent usage on multisite installations
- Added streaming SQL reader for memory-efficient backup restore
- Added deactivation cleanup for transients
- Added `uninstall.php` for proper plugin data cleanup on deletion

### Fixed - Restore Stuck (2025-12-31)
- Pre-process SQL file to remove multi-line mysqldump warnings
- Properly filter out warning blocks that span multiple lines
- Exclude `.git`, `.svn`, `.hg`, `node_modules`, IDE folders, and OS junk from backups
- Extract files individually during restore instead of bulk `extractTo()`
- Skip `.git` and development files during restore
- Skip backup directory during restore to prevent recursion
- Continue restore even if some files fail (log summary of results)

---

## [1.0.28] - 2025-12-31
### Fixed - Fatal Error from Duplicate Method Declarations
- **Fatal error:** `Cannot redeclare Simple_Migrator\Backup_Manager::delete_backup()`
- Removed duplicate `delete_backup()`, `restore_database()`, `restore_files()`, `recursive_delete()` methods
- Made `restore_database()` and `restore_files()` public for WP-CLI access
- Kept new WP-CLI helper methods: `get_backup_dir()`, `get_all_backups()`, `get_backup_metadata()`

---

## [1.0.27] - 2025-12-31
### Added - WP-CLI Integration
- **Emergency Recovery:** Restore backups when site is broken/inaccessible
- **New WP-CLI commands:**
  - `wp sm backup list` - List all backups with metadata
  - `wp sm backup create [--progress]` - Create new backup
  - `wp sm backup restore <id> [--yes] [--skip-db] [--skip-files]` - Restore backup
  - `wp sm backup delete <id> [--yes]` - Delete a backup
  - `wp sm backup clean --keep=3` - Keep only N most recent backups
- **No browser needed** - All operations via SSH/CLI
- **No PHP timeout issues** - Direct CLI execution
- **Better error output** - Full error messages in terminal
- **Automation ready** - Can be scripted/cron'd

### New Helper Methods (Backup_Manager)
- `get_all_backups()` - Get list of all backup IDs
- `get_backup_metadata($id)` - Get backup metadata
- `delete_backup($id)` - Delete a backup
- `get_backup_dir()` - Get backup directory path

See [user-guide.md](user-guide.md) for full WP-CLI usage examples.

---

## [1.0.26] - 2025-12-31
### Fixed - Backup Restore SQL Parsing Error
- **Critical fix:** Backup restore now correctly handles mysqldump output
- **backup_database():** Redirect stderr to /dev/null (`2>/dev/null`) instead of including in SQL file
- **split_sql_file():** Filter out non-SQL lines (mysqldump warnings, error messages)
- **is_valid_sql_line():** New helper method validates SQL lines before execution
- Previously mysqldump warnings like "Using a password on the command line..." were being
  written to the SQL file, causing syntax errors during restore

### Technical Details
- Changed mysqldump command from `2>&1` to `2>/dev/null`
- Added SQL keyword validation (SELECT, INSERT, CREATE, DROP, etc.)
- Skip lines starting with: mysqldump:, Warning:, Error:, Note:, MySQL dump
- Allows valid SQL comments (--, #, /* */)

---

## [1.0.25] - 2025-12-31
### Fixed - Session Loss During Migration
- **Critical fix:** WordPress session no longer breaks during migration
- **wp_users handling:** Delete all users EXCEPT current user (keeps session alive)
- **wp_usermeta handling:** Delete all usermeta EXCEPT current user's
- **wp_options handling:** Skip entirely - let it be migrated normally, then fix protected options
- Previous approach was truncating protected tables which destroyed the current user mid-session
- New approach preserves the logged-in user throughout the migration process

### Technical Details
- `prepare_database()` now:
  - Gets current user ID before any operations
  - For wp_users: `DELETE FROM wp_users WHERE ID != current_user_id`
  - For wp_usermeta: `DELETE FROM wp_usermeta WHERE user_id != current_user_id`
  - For wp_options: Skip (don't drop or truncate)
  - All other tables: Drop as normal
- `finalize_migration()` restores protected options after migration completes
- Current user remains authenticated throughout entire migration

---

## [1.0.24] - 2025-12-31
### Fixed - Smart Merge Mode
- **Critical fix:** WordPress no longer goes into install mode during migration
- **Protected wp_users and wp_usermeta tables** - Destination admin account is now preserved
- **Protected critical wp_options entries:**
  - siteurl, home (destination site URLs preserved)
  - admin_email, active_plugins, current_theme
  - template, stylesheet
  - sm_migration_secret, sm_source_url, sm_source_mode
- **New finalize_migration() AJAX endpoint** - Restores preserved settings after database phase
- **prepare_database() now:**
  - Preserves critical options BEFORE dropping tables
  - Preserves admin account before truncating wp_users
  - Truncates protected tables instead of dropping (preserves structure)
  - Returns both `dropped` and `preserved` table lists

### Technical Details
- Added `SM_PROTECTED_TABLES` constant for tables to truncate (not drop)
- Added `SM_PROTECTED_OPTIONS` constant for wp_options entries to preserve
- Methods: `preserve_critical_options()`, `preserve_admin_account()`, `restore_admin_account()`, `finalize_migration()`
- JavaScript: Added `finalizeMigration()` call after database phase completes

---

## [1.0.23] - 2025-12-31
### Fixed
- **Critical CORS bug** - REST_Controller was never instantiated early enough
- `handle_cors()` hook to `init` (priority 5) was never registered
- Instance was only created during `rest_api_init`, which fires AFTER `init`
- Now instantiates REST_Controller when file loads
### Fixed
- **Local development hostname recognition**
- `is_local_origin()` now recognizes similar hostnames (e.g., developmentwp vs developmentwp2)
- Added hostname without TLD detection (common in local dev)
- Added `.localhost` and `.invalid` TLDs to local patterns

---

## [1.0.20] - 2025-12-31
### Fixed
- **Critical PHP fatal error** - `Call to undefined function Simple_Migrator\escshellcmd()`
- Used incorrect function names:
  - `escshellcmd()` → `\escapeshellcmd()` (correct PHP function)
  - `escshellarg()` → `\escapeshellarg()` (correct PHP function)
- Added backslash prefix for global function calls within namespace
- Fixed `ob_flush()` PHP notice - now only calls when buffer exists

### Impact
- Backup creation was completely broken due to fatal error
- WordPress returned generic critical error page instead of executing backup
- This fix restores full backup functionality

---

## [1.0.19] - 2025-12-31
### Added
- Comprehensive error logging to diagnose backup failures
- Log all backup phases to WordPress debug log
- Catch both Exception and Error types for complete error handling
- Added debug output to browser console

### Debugging
- Check `/wp-content/debug.log` for detailed PHP errors
- Console now shows line count and buffer state for troubleshooting

---

## [1.0.18] - 2025-12-31
### Fixed
- **Improved output buffering handling** - now clears ALL buffer levels
- Added `Content-Type` and `X-Accel-Buffering: no` headers
- Wrapped backup creation in try-catch for better error handling

### Added
- **Comprehensive debug logging** to JavaScript console
- Line count tracking to see how many JSON lines are received
- All received lines are logged for debugging
- Better error messages directing users to check console (F12)

### Debugging
- When backup fails, check browser console (F12) for:
  - Total lines received
  - Final buffer state
  - All parsed JSON lines
  - Error details

---

## [1.0.17] - 2025-12-31
### Fixed
- **"No response from server" error** in backup creation
- Changed from `wp_send_json_success()` to manual JSON encoding for streaming
- WordPress AJAX wrapper was incompatible with streaming format
- Division by zero in time estimation when progress is 0%

### Changed
- All backup responses now include `type` field:
  - `type: "progress"` - Progress updates during backup
  - `type: "complete"` - Final successful result
  - `type: "error"` - Error messages
- Disabled PHP output buffering at start of AJAX handler
- Improved error detection with `hasError` flag in JavaScript

---

## [1.0.16] - 2025-12-31
### Fixed
- **Critical nonce mismatch bug** in Backup_Manager (was checking 'sm_nonce' instead of 'wp_rest')
- Backup list now loads correctly instead of showing "Loading backups..." indefinitely

### Added
- **Real-time streaming progress updates** during backup creation
- **Time estimates** showing elapsed and remaining time
- **Detailed progress checkpoints**:
  - 3%: Creating backup directory
  - 5-55%: Database backup with intermediate steps
  - 55-90%: File backup with file count updates (every 100 files)
  - 90-95%: Creating metadata and cleanup
  - 100%: Complete
- **formatTime() utility** to display seconds as readable format (45s, 2m 30s, 1h 15m)
- **Comprehensive error handling** with user-friendly error messages in backup UI
- **Total backup time display** on completion

### Improved
- Backup creation now uses fetch() with streaming response parsing for real-time updates
- loadBackups() now shows specific errors instead of failing silently
- JavaScript handles JSON line-by-line parsing for streaming progress

---

## [1.0.15] - 2025-12-31
### Fixed
- **PHP Fatal error**: Class "Simple_Migrator\WP_Error" not found
- Added leading backslash to all 19 WP_Error references in Backup_Manager
- Backup system now fully functional with proper namespace handling

---

## [1.0.14] - 2025-12-31
### Fixed
- **JavaScript syntax error** preventing all functionality
- Removed PHP translation functions from JavaScript files
- Replaced `<?php _e() ?>` calls with plain English strings in admin.js
- All JavaScript features now working correctly

---

## [1.0.13] - 2025-12-31
### Fixed
- Mode switching now works correctly (Source/Destination buttons)
- Mode selector is now hidden after selection
- Added "Change Mode" links to all configuration panels
- Created diagnostic HTML tool for troubleshooting

---

## [1.0.12] - 2025-12-31
### Added
- **Full backup/restore system** for development safety
- Database backup (mysqldump with PHP fallback)
- File backup (wp-content zipped with ZipArchive)
- Automatic cleanup (keeps last 3 backups)
- One-click restore functionality
- Admin UI for backup management
- Auto-prompt before migration if no recent backup exists
- Protected backup storage (.htaccess in uploads directory)

### Security
- Backups stored in uploads directory with .htaccess protection
- Backup deletion requires confirmation

---

## [1.0.11] - 2025-12-31
### Added
- **Development key saving** feature for destination mode
- Checkbox to save migration key in database (development only)
- AJAX handlers for save/load source key
- Base64 encoding for basic obfuscation (NOT encryption)
- Clear warnings about development-only usage

---

## [1.0.10] - 2025-12-31
### Changed
- Migration key regeneration now reloads page to show new key
- Improved UX for key regeneration workflow

---

## [1.0.9] - 2025-12-31
### Added
- Production-ready release
- Comprehensive QA review completed
- Security fixes implemented
- Full migration functionality with peer-to-peer transfer
- Bit-by-bit transfer technology
- Resume capability for interrupted migrations
- Error recovery and retry logic

---

## Earlier Versions
### Initial Features
- Source and Destination modes
- Migration key authentication
- Database migration with prefix replacement
- File migration with chunked transfer
- Search and replace for URLs
- Serialization-safe data handling
- REST API endpoints
- AJAX handlers for migration process
- WordPress admin interface
