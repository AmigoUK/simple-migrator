# Simple Migrator

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress Version](https://img.shields.io/badge/WordPress-5.0%2B-brightgreen.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)

A distributed, peer-to-peer WordPress migration plugin designed for reliable 1:1 site cloning with bit-by-bit transfer technology. Move your WordPress site between servers without the limitations of traditional backup plugins.

## Features

- **Bit-by-Bit Transfer** - Handles sites of any size by breaking data into manageable chunks
- **Resume Capability** - Interrupted migrations can be resumed from where they left off
- **Automatic Retry** - Built-in retry logic with exponential backoff handles network issues
- **Zero Downtime** - Pull-based architecture keeps your site live during migration
- **Table Prefix Translation** - Automatically handles different table prefixes (e.g., `wp_` to `prod_`)
- **Serialization Safe** - Advanced search & replace preserves serialized PHP data
- **Peer-to-Peer** - Direct server-to-server transfer, no cloud storage required
- **Progress Tracking** - Real-time progress bars and detailed statistics
- **Pause & Resume** - Control your migration with pause, resume, and cancel options

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Memory**: 128MB minimum (256MB recommended)
- **Permissions**: Ability to install plugins (Administrator access)
- **Network**: Both source and destination servers must be accessible from each other

## Installation

1. Download/Clone this repository into your `/wp-content/plugins/` directory
2. Go to **WordPress Admin → Plugins**
3. Find "Simple Migrator" in the list
4. Click **Activate**
5. **Important**: Install on BOTH source and destination WordPress sites

## Quick Start

### Source Site Setup

1. Go to **Simple Migrator** in WordPress admin
2. Click **Source Mode**
3. Copy the **Migration Key** (format: `https://yoursite.com|YOUR_64_CHAR_SECRET`)

### Destination Site Setup

1. Go to **Simple Migrator** in WordPress admin
2. Click **Destination Mode**
3. Paste the migration key from source
4. Click **Test Connection**
5. Click **Start Migration**

## Architecture

### Pull-Based P2P Model

```
┌─────────────┐         ┌─────────────┐
│   SOURCE    │◄────────│DESTINATION  │
│  Server     │  REST   │   Server    │
│             │  API    │             │
└─────────────┘         └─────────────┘
```

The destination server pulls data from the source server via REST API.

### Migration Phases

1. **Scan** - File manifest & database discovery
2. **Database Transfer** - Schema creation & batch row insertion
3. **File Transfer** - Chunked streaming (2MB chunks)
4. **Finalize** - Serialization-safe search & replace

## Security Features

- 64-character cryptographically secure secret key
- Header-based authentication (`X-Migration-Secret`)
- HTTPS/SSL support
- MD5 checksum verification
- Path validation (prevents directory traversal)
- WordPress capability checks
- Nonce verification on all AJAX calls

## Documentation

See [user-guide.md](user-guide.md) for comprehensive documentation including:
- Detailed installation instructions
- Troubleshooting guide
- FAQ
- Performance tips
- Security best practices

## Technical Details

### REST API Endpoints

```
POST /wp-json/simple-migrator/v1/handshake
GET  /wp-json/simple-migrator/v1/scan/manifest
GET  /wp-json/simple-migrator/v1/scan/database
GET  /wp-json/simple-migrator/v1/stream/file
GET  /wp-json/simple-migrator/v1/stream/batch
GET  /wp-json/simple-migrator/v1/stream/rows
GET  /wp-json/simple-migrator/v1/stream/schema
GET  /wp-json/simple-migrator/v1/config/info
```

### AJAX Actions

- `sm_set_mode` - Set migration mode
- `sm_regenerate_key` - Regenerate migration secret
- `sm_save_source_url` - Save source URL for search & replace
- `sm_get_config` - Get destination configuration
- `sm_prepare_database` - Drop existing tables if needed
- `sm_process_rows` - Insert database rows
- `sm_write_chunk` - Write file chunks
- `sm_extract_batch` - Extract batched zip archives
- `sm_search_replace` - Perform serialization-safe URL replacement
- `sm_flush_permalinks` - Flush WordPress permalinks

## File Structure

```
simple-migrator/
├── simple-migrator.php       # Main plugin file
├── includes/
│   ├── class-rest-controller.php
│   ├── class-ajax-handler.php
│   ├── class-serialization-fixer.php
│   └── class-file-scanner.php
├── includes/admin/
│   └── class-admin-page.php
├── assets/
│   ├── js/
│   │   └── admin.js
│   └── css/
│       └── admin.css
├── user-guide.md             # Detailed user documentation
└── README.md                 # This file
```

## Troubleshooting

**Connection Failed?**
- Verify migration key is complete (URL | Secret)
- Check if source site is accessible from destination
- Ensure both sites have Simple Migrator installed

**Migration Stuck?**
- Use Pause/Resume to refresh connection
- Check PHP memory limit (recommend 256MB)
- Try during off-peak hours

## Changelog

### 1.0.0
- Initial release
- Bit-by-bit file and database transfer
- Resume capability
- Retry logic with exponential backoff
- Table prefix translation
- Serialization-safe search & replace

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Open a Pull Request

## Support

- **Issues**: [GitHub Issues](https://github.com/AmigoUK/simple-migrator/issues)
- **Documentation**: See [user-guide.md](user-guide.md)

## Author

**Tomasz 'Amigo' Lewandowski**
- Website: [https://www.attv.uk](https://www.attv.uk)

## License

GPL v2 or later

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

---

**Note**: Always test migrations on a staging site before using in production. Keep backups of your data.
