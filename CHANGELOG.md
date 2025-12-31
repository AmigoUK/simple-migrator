# Changelog

All notable changes to Simple Migrator will be documented in this file.

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
