# Simple Migrator

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

### Method 1: Manual Installation

1. Download the plugin zip file
2. Go to **WordPress Admin → Plugins → Add New**
3. Click **Upload Plugin**
4. Select the zip file and click **Install Now**
5. Activate the plugin

### Method 2: FTP Installation

1. Upload the `simple-migrator` folder to `/wp-content/plugins/`
2. Go to **WordPress Admin → Plugins**
3. Find "Simple Migrator" in the list
4. Click **Activate**

### Method 3: Installation on Both Sites

**Important**: You must install Simple Migrator on **BOTH** the source (original) site and the destination (new) site.

## Usage Guide

### Step 1: Configure the Source Site

1. Log into your **source site** (the site you want to copy FROM)
2. Go to **Simple Migrator** in the WordPress admin menu
3. Click **Source Mode**
4. Copy the **Migration Key** that appears on the screen
5. Keep this key secure - it allows access to your site data

**Example Migration Key:**
```
https://mysite.com|a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6
```

### Step 2: Configure the Destination Site

1. Log into your **destination site** (the NEW WordPress installation)
2. Go to **Simple Migrator** in the WordPress admin menu
3. Click **Destination Mode**
4. Paste the migration key from the source site
5. Click **Test Connection**

If successful, you'll see:
- ✓ Connection Successful
- Source server information
- WordPress, PHP, and MySQL versions
- "Start Migration" button appears

### Step 3: Start the Migration

1. Review the connection information
2. Click **Start Migration**
3. The migration will proceed through 4 phases:

#### Phase 1: Scan
- Scans source files and database
- Generates a file manifest
- Counts database rows

#### Phase 2: Database Transfer
- Drops existing tables (with your confirmation)
- Creates new tables with correct schema
- Transfers data in batches of 1000 rows
- Progress updates for each table

#### Phase 3: File Transfer
- Transfers all files from wp-content
- Large files transferred in 2MB chunks
- Small files batched into zip archives
- Automatically excludes: cache, node_modules, .git

#### Phase 4: Finalize
- Performs search & replace (old URL → new URL)
- Preserves serialized data integrity
- Updates site URL and home URL
- Flushes permalinks

### Step 4: Complete

When migration finishes, you'll see:
- ✓ Migration Complete!
- Statistics (duration, rows, files, data size)
- "View Site" button to check your new site

## Migration Controls

During migration, you have several options:

### Pause
- Temporarily stop the migration
- Resume from current position
- Useful for freeing server resources

### Resume
- Continue a paused migration
- Or resume an interrupted migration
- Picks up exactly where it left off

### Cancel
- Stop the migration completely
- Progress is saved for later resumption
- Can resume from any incomplete phase

## Resume an Interrupted Migration

If your migration was interrupted (network issue, browser closed, etc.):

1. Return to **Simple Migrator** on the destination site
2. Click **Destination Mode**
3. You'll see a **Resume Migration** button
4. Click it to continue from where you stopped

The plugin remembers:
- Current phase (scan/database/files/finalize)
- Table and row position
- File transfer progress
- Completed files list

## What Gets Migrated

### Database
- All WordPress tables (posts, pages, users, comments, etc.)
- Custom post types
- Taxonomies and terms
- Options and settings
- Plugin and theme data

### Files
- Plugins
- Themes
- Uploads (images, media, documents)
- WordPress configuration files

### What's Excluded
- `wp-content/cache/`
- `wp-content/uploads/cache/`
- `node_modules/`
- `.git/`
- `.svn/`
- Backup directories
- Log files

## Advanced Features

### Table Prefix Translation

The plugin automatically handles different table prefixes:

**Source**: `wp_posts`, `wp_options`
**Destination**: `prod_posts`, `prod_options`

All table references in constraints and indexes are updated automatically.

### Serialized Data Protection

WordPress stores complex data in "serialized" format. Simple Migrator's advanced search & replace:

- Unserializes the data
- Recursively walks through arrays and objects
- Replaces URLs
- Re-serializes with correct byte counts

This prevents:
- Broken widgets
- Missing theme options
- Plugin settings corruption

### Error Handling & Retry Logic

The plugin automatically retries failed operations:

- **Network errors**: Up to 5 retries
- **Timeout errors**: Exponential backoff (1s, 2s, 4s, 8s, 16s)
- **File transfer errors**: Up to 3 retries per chunk
- All errors are logged and displayed in statistics

## Troubleshooting

### Connection Failed

**Problem**: "Connection Failed" when testing connection

**Solutions**:
1. Verify the migration key is complete (URL | Secret)
2. Check if source site is accessible from destination
3. Ensure both sites have Simple Migrator installed
4. Check firewall settings on both servers
5. Verify HTTPS/SSL certificates are valid

### Migration Stuck or Slow

**Problem**: Migration appears stuck or very slow

**Solutions**:
1. Check browser console for errors
2. Verify server PHP memory limit (recommend 256MB)
3. Check max_execution_time setting
4. Use Pause/Resume to refresh the connection
5. Try again during off-peak hours

### File Permission Errors

**Problem**: "Failed to write file chunk" errors

**Solutions**:
1. Ensure wp-content is writable (755 permissions)
2. Check file ownership matches web server user
3. Verify sufficient disk space on destination
4. Contact your hosting provider if using suPHP/suEXEC

### Timeout Errors

**Problem**: "Maximum execution time exceeded"

**Solutions**:
1. The plugin automatically retries - just wait
2. Increase PHP max_execution_time in php.ini
3. Reduce batch size (edit SM_CHUNK_SIZE in plugin)
4. Use Pause/Resume to continue

### Database Errors

**Problem**: "Could not create table" or similar

**Solutions**:
1. Check database user has CREATE TABLE permission
2. Verify sufficient database space
3. Ensure MySQL version is compatible
4. Check for table name conflicts

## Performance Tips

### For Large Sites (1GB+)

1. **Run during off-peak hours** - Less server load
2. **Use Pause/Resume** - Break migration into sessions
3. **Increase PHP limits**:
   ```php
   memory_limit = 256M
   max_execution_time = 300
   post_max_size = 8M
   upload_max_filesize = 8M
   ```
4. **Monitor server resources** - Watch CPU and memory
5. **Check statistics** - Review retry count and errors

### For Slow Connections

1. **Increase chunk size** - Edit SM_CHUNK_SIZE (default 2MB)
2. **Use batch transfers** - Enabled by default for small files
3. **Resume capability** - Don't worry about interruptions
4. **Progress persistence** - State saved automatically

## Security

### Authentication

- **Secret key** - 64-character cryptographically secure random string
- **Header-based** - Uses `X-Migration-Secret` header
- **One-time use** - Regenerate key after migration
- **Never expires** - Key valid until manually regenerated

### Data Protection

- **HTTPS supported** - Encrypted transfer when using SSL
- **Checksum verification** - MD5 checksums for all chunks
- **Path validation** - Prevents directory traversal attacks
- **Capability checks** - Requires Administrator role
- **Nonce verification** - WordPress nonces for all AJAX calls

### Best Practices

1. **Regenerate key** after each migration
2. **Delete plugin** from both sites when done
3. **Use HTTPS** for production migrations
4. **Backup first** - Always have a recent backup
5. **Test locally** - Try with staging sites first

## Limitations

- **Server-to-server only** - Both sites must be online
- **WordPress access** - Need admin access to both sites
- **Network connectivity** - Servers must communicate directly
- **Same WordPress version** - Best results with matching versions
- **Plugin compatibility** - Both sites need the plugin version

## FAQ

### Q: Can I migrate to a server with a different table prefix?

**A**: Yes! The plugin automatically translates table prefixes. For example, `wp_posts` on the source becomes `prod_posts` on the destination (if your prefix is `prod_`).

### Q: What happens if the migration is interrupted?

**A**: The plugin saves progress automatically. Just return to the migration page and click "Resume Migration" to continue from where it stopped.

### Q: How long does a migration take?

**A**: Depends on site size and connection speed:
- Small site (100MB): ~5-15 minutes
- Medium site (500MB): ~30-60 minutes
- Large site (2GB+): 1-3 hours

### Q: Can I use my site during migration?

**A**: Yes! The source site remains live. The destination site shouldn't be used until migration completes.

### Q: Do I need to keep the plugin installed?

**A**: No. Once migration is complete, you can delete Simple Migrator from both sites. Remember to regenerate your keys first if you plan to migrate again.

### Q: What about multisite installations?

**A**: Currently, Simple Migrator is designed for single-site WordPress installations. Multisite support is planned for a future version.

### Q: Can I migrate from a local installation?

**A**: Yes, if your local WordPress installation is accessible from the destination server. You may need to use a service like ngrok for testing.

### Q: Does this work with WordPress.com?

**A**: No. WordPress.com sites cannot have custom plugins installed. This plugin works with self-hosted WordPress installations only.

## Changelog

### 1.0.0
- Initial release
- Bit-by-bit file and database transfer
- Resume capability
- Retry logic with exponential backoff
- Table prefix translation
- Serialization-safe search & replace
- Progress persistence
- Pause/Resume/Cancel controls

## Support

- **Issues**: Report bugs at [GitHub Issues](https://github.com/your-repo/simple-migrator/issues)
- **Documentation**: See inline documentation in code
- **Security**: Report security issues privately

## Credits

Developed with ❤️ for the WordPress community. Built following WordPress coding standards and best practices.

## License

GPL v2 or later

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

---

**Note**: This plugin is in active development. Always test migrations on a staging site before using in production. Keep backups of your data.
