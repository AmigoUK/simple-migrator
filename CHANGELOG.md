# Changelog

All notable changes to Simple Migrator will be documented in this file.

## [1.0.24] - 2025-01-31
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

## [1.0.23] - 2025-01-31
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

## [1.0.20] - 2025-01-31
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

## [1.0.19] - 2025-01-31
### Added
- Comprehensive error logging to diagnose backup failures
- Log all backup phases to WordPress debug log
- Catch both Exception and Error types for complete error handling
- Added debug output to browser console

### Debugging
- Check `/wp-content/debug.log` for detailed PHP errors
- Console now shows line count and buffer state for troubleshooting

---

## [1.0.18] - 2025-01-31
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

## [1.0.17] - 2025-01-31
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

## [1.0.16] - 2025-01-31
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

## [1.0.15] - 2025-01-31
### Fixed
- **PHP Fatal error**: Class "Simple_Migrator\WP_Error" not found
- Added leading backslash to all 19 WP_Error references in Backup_Manager
- Backup system now fully functional with proper namespace handling

---

## [1.0.14] - 2025-01-31
### Fixed
- **JavaScript syntax error** preventing all functionality
- Removed PHP translation functions from JavaScript files
- Replaced `<?php _e() ?>` calls with plain English strings in admin.js
- All JavaScript features now working correctly

---

## [1.0.13] - 2025-01-31
### Fixed
- Mode switching now works correctly (Source/Destination buttons)
- Mode selector is now hidden after selection
- Added "Change Mode" links to all configuration panels
- Created diagnostic HTML tool for troubleshooting

---

## [1.0.12] - 2025-01-31
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

## [1.0.11] - 2025-01-31
### Added
- **Development key saving** feature for destination mode
- Checkbox to save migration key in database (development only)
- AJAX handlers for save/load source key
- Base64 encoding for basic obfuscation (NOT encryption)
- Clear warnings about development-only usage

---

## [1.0.10] - 2025-01-31
### Changed
- Migration key regeneration now reloads page to show new key
- Improved UX for key regeneration workflow

---

## [1.0.9] - 2025-01-31
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
