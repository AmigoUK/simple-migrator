/**
 * Simple Migrator Admin JavaScript
 *
 * Handles the client-side orchestration and state management for migrations.
 * Includes retry logic, error recovery, and progress persistence.
 *
 * @package Simple_Migrator
 */

'use strict';

/**
 * Migration State Manager
 * Handles the finite state machine for migration phases with persistence
 */
const MigrationState = {
    // State version for migration handling
    STATE_VERSION: '1.0',

    // Current phase
    phase: 'idle', // idle, handshake, scan, database, files, finalize, complete, error, paused

    // Connection info
    sourceUrl: '',
    sourceSecret: '',
    sourceTablePrefix: 'wp_',

    // Scan data
    manifest: null,
    totalBytes: 0,
    totalRows: 0,

    // Database progress
    currentTable: 0,
    totalTables: 0,
    tableOffset: 0,
    lastTableId: 0,
    tableSchemas: {}, // Cache table schemas

    // File progress
    currentFileIndex: 0,
    totalFiles: 0,
    fileByteOffset: 0,
    completedFiles: [],

    // Flags
    isPaused: false,
    isCancelled: false,
    canResume: false,

    // Error tracking
    lastError: null,
    errorCount: 0,
    maxRetries: 5,
    retryDelay: 1000, // Starting delay in ms

    // Statistics
    stats: {
        startTime: null,
        endTime: null,
        bytesTransferred: 0,
        rowsTransferred: 0,
        filesTransferred: 0,
        retries: 0,
        errors: []
    },

    /**
     * Save state to localStorage for resume capability
     * Note: manifest is NOT saved due to size limitations
     */
    save() {
        const stateToSave = {
            version: this.STATE_VERSION,
            phase: this.phase,
            sourceUrl: this.sourceUrl,
            currentTable: this.currentTable,
            totalTables: this.totalTables,
            tableOffset: this.tableOffset,
            lastTableId: this.lastTableId,
            currentFileIndex: this.currentFileIndex,
            totalFiles: this.totalFiles,
            fileByteOffset: this.fileByteOffset,
            completedFiles: this.completedFiles,
            // Don't save manifest - it's too large and can be reloaded from source
            stats: this.stats,
            canResume: true
        };

        try {
            localStorage.setItem('sm_migration_state', JSON.stringify(stateToSave));
        } catch (e) {
            console.warn('Could not save migration state:', e);
            // If localStorage is full, try to save minimal state
            try {
                const minimalState = {
                    version: this.STATE_VERSION,
                    phase: this.phase,
                    canResume: true
                };
                localStorage.setItem('sm_migration_state', JSON.stringify(minimalState));
            } catch (e2) {
                console.error('Could not save even minimal state:', e2);
            }
        }
    },

    /**
     * Load state from localStorage
     * Handles version mismatches gracefully
     */
    load() {
        try {
            const saved = localStorage.getItem('sm_migration_state');
            if (saved) {
                const state = JSON.parse(saved);

                // Check version compatibility
                if (state.version && state.version !== this.STATE_VERSION) {
                    console.warn(`State version mismatch: expected ${this.STATE_VERSION}, got ${state.version}`);
                    // For now, try to load anyway - future versions can do migrations here
                }

                // Manifest needs to be reloaded from source
                if (state.manifest) {
                    delete state.manifest;
                }

                Object.assign(this, state);
                return true;
            }
        } catch (e) {
            console.warn('Could not load migration state:', e);
            // Clear corrupted state
            this.clearSaved();
        }
        return false;
    },

    /**
     * Clear saved state
     */
    clearSaved() {
        try {
            localStorage.removeItem('sm_migration_state');
        } catch (e) {
            console.warn('Could not clear migration state:', e);
        }
    },

    /**
     * Reset state (preserves connection credentials)
     */
    reset() {
        this.phase = 'idle';
        // Note: sourceUrl, sourceSecret, and sourceTablePrefix are preserved
        // They should only be set once via connection test and persist across resets
        this.manifest = null;
        this.totalBytes = 0;
        this.totalRows = 0;
        this.currentTable = 0;
        this.totalTables = 0;
        this.tableOffset = 0;
        this.lastTableId = 0;
        this.tableSchemas = {};
        this.currentFileIndex = 0;
        this.totalFiles = 0;
        this.fileByteOffset = 0;
        this.completedFiles = [];
        this.isPaused = false;
        this.isCancelled = false;
        this.canResume = false;
        this.lastError = null;
        this.errorCount = 0;
        this.retryDelay = 1000;
        this.stats = {
            startTime: null,
            endTime: null,
            bytesTransferred: 0,
            rowsTransferred: 0,
            filesTransferred: 0,
            retries: 0,
            errors: []
        };
    },

    /**
     * Update phase
     */
    setPhase(phase) {
        this.phase = phase;
        this.trigger('phaseChange', phase);
        this.save();
    },

    /**
     * Record error
     */
    recordError(error, context = '') {
        this.lastError = {
            message: error.message || String(error),
            context: context,
            timestamp: new Date().toISOString()
        };
        this.errorCount++;
        this.stats.errors.push(this.lastError);
        this.save();
    },

    /**
     * Increment retry counter
     */
    incrementRetry() {
        this.stats.retries++;
        this.save();
    },

    /**
     * Simple event emitter
     */
    events: {},
    on(event, callback) {
        if (!this.events[event]) {
            this.events[event] = [];
        }
        this.events[event].push(callback);
    },
    trigger(event, data) {
        if (this.events[event]) {
            this.events[event].forEach(callback => callback(data));
        }
    }
};

/**
 * API Handler with Retry Logic
 * Manages communication with retry logic and exponential backoff
 */
const API = {
    /**
     * Sleep for specified milliseconds
     */
    async sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    },

    /**
     * Calculate retry delay with exponential backoff
     */
    getRetryDelay(attempt) {
        // Exponential backoff: 1s, 2s, 4s, 8s, 16s
        return Math.min(1000 * Math.pow(2, attempt), 16000);
    },

    /**
     * Make request with retry logic
     */
    async requestWithRetry(requestFn, context = 'API Request', maxRetries = 5) {
        let lastError;

        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            try {
                if (attempt > 0) {
                    const delay = this.getRetryDelay(attempt - 1);
                    console.log(`Retrying ${context} (attempt ${attempt + 1}/${maxRetries + 1}) after ${delay}ms...`);
                    await this.sleep(delay);
                    MigrationState.incrementRetry();
                }

                const result = await requestFn();
                // Success - clear retry state
                return result;

            } catch (error) {
                lastError = error;
                console.error(`Attempt ${attempt + 1} failed for ${context}:`, error);

                // Check if error is retryable
                if (!this.isRetryableError(error)) {
                    throw error;
                }

                MigrationState.recordError(error, context);
            }
        }

        // All retries failed
        throw new Error(`${context} failed after ${maxRetries + 1} attempts: ${lastError.message}`);
    },

    /**
     * Check if error is retryable
     */
    isRetryableError(error) {
        const retryablePatterns = [
            /network/i,
            /timeout/i,
            /connection/i,
            /ECONNREFUSED/i,
            /ETIMEDOUT/i,
            /5\d\d/, // Server errors
            /fetch failed/i
        ];

        const errorMessage = error.message || String(error);

        return retryablePatterns.some(pattern => pattern.test(errorMessage));
    },

    /**
     * Make authenticated request to source API
     */
    async request(endpoint, method = 'GET', data = null, secret = null) {
        const headers = {
            'Content-Type': 'application/json'
        };

        // Only include WordPress nonce for local requests (no secret)
        // Cross-origin requests to source server use X-Migration-Secret instead
        if (!secret) {
            headers['X-WP-Nonce'] = smData.nonce;
        }

        if (secret) {
            headers['X-Migration-Secret'] = secret;
        }

        const config = {
            method: method,
            headers: headers
        };

        if (data && method !== 'GET') {
            config.body = JSON.stringify(data);
        }

        // Use source URL when secret is provided, otherwise use local API URL
        let url;
        if (secret) {
            // Request to source server
            url = MigrationState.sourceUrl.replace(/\/$/, '') + '/wp-json/simple-migrator/v1' + endpoint;
        } else {
            // Local request
            url = smData.apiUrl + endpoint;
        }

        const response = await fetch(url, config);

        if (!response.ok) {
            const error = await response.json().catch(() => ({
                message: 'Unknown error'
            }));
            throw new Error(error.message || `HTTP ${response.status}`);
        }

        return response.json();
    },

    /**
     * Test connection to source
     */
    async testConnection(sourceUrl, sourceSecret) {
        return this.requestWithRetry(
            async () => {
                const url = sourceUrl.replace(/\/$/, '') + '/wp-json/simple-migrator/v1/handshake';

                // Debug logging
                console.log('Simple Migrator - Testing Connection:');
                console.log('  URL:', url);
                console.log('  Secret length:', sourceSecret.length);
                console.log('  Secret (first 20):', sourceSecret.substring(0, 20));

                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Migration-Secret': sourceSecret
                    }
                });

                if (!response.ok) {
                    console.error('Response status:', response.status);
                    throw new Error('Connection failed');
                }

                return response.json();
            },
            'Testing connection'
        );
    },

    /**
     * Get file manifest from source
     */
    async getManifest() {
        return this.requestWithRetry(
            () => this.request('/scan/manifest', 'GET', null, MigrationState.sourceSecret),
            'Getting file manifest'
        );
    },

    /**
     * Get database info from source
     */
    async getDatabaseInfo() {
        return this.requestWithRetry(
            () => this.request('/scan/database', 'GET', null, MigrationState.sourceSecret),
            'Getting database information'
        );
    },

    /**
     * Get source configuration
     */
    async getSourceInfo() {
        return this.requestWithRetry(
            () => this.request('/config/info', 'GET', null, MigrationState.sourceSecret),
            'Getting source configuration'
        );
    },

    /**
     * Get table schema
     */
    async getTableSchema(table) {
        return this.requestWithRetry(
            () => {
                const params = new URLSearchParams({ table: table });
                return this.request(`/stream/schema?${params.toString()}`, 'GET', null, MigrationState.sourceSecret);
            },
            `Getting schema for table ${table}`
        );
    },

    /**
     * Get table rows
     */
    async getTableRows(table, offset = 0, batch = 1000, lastId = 0) {
        return this.requestWithRetry(
            () => {
                const params = new URLSearchParams({
                    table: table,
                    offset: offset,
                    batch: batch,
                    last_id: lastId
                });
                return this.request(`/stream/rows?${params.toString()}`, 'GET', null, MigrationState.sourceSecret);
            },
            `Getting rows from table ${table} (offset: ${offset})`
        );
    },

    /**
     * Stream file chunk
     */
    async streamFile(path, start = 0, end = 0) {
        return this.requestWithRetry(
            () => {
                const params = new URLSearchParams({
                    path: path,
                    start: start,
                    end: end
                });
                return this.request(`/stream/file?${params.toString()}`, 'GET', null, MigrationState.sourceSecret);
            },
            `Streaming file ${path} (bytes ${start}-${end})`,
            3 // Fewer retries for files
        );
    }
};

/**
 * Migration Orchestrator with Error Handling
 * The main controller that coordinates the migration process
 */
const Orchestrator = {
    /**
     * Start the migration process
     */
    async start() {
        MigrationState.stats.startTime = new Date().toISOString();
        MigrationState.save();

        // Check for recent backup before starting migration (development safety)
        try {
            const backupCheck = await jQuery.ajax({
                url: smData.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'sm_list_backups',
                    nonce: smData.nonce
                }
            });

            if (backupCheck.success && backupCheck.data.backups.length > 0) {
                const latestBackup = backupCheck.data.backups[0];
                const backupTime = new Date(latestBackup.created_at);
                const now = new Date();
                const hoursSinceBackup = (now - backupTime) / (1000 * 60 * 60);

                if (hoursSinceBackup > 1) {
                    const proceed = confirm(
                        'Simple Migrator - Development Safety Check\n\n' +
                        'Your latest backup is ' + Math.round(hoursSinceBackup) + ' hours old.\n\n' +
                        'For development safety, consider creating a fresh backup before migration.\n' +
                        'Click "OK" to create a backup first,\n' +
                        'Click "Cancel" to proceed with migration anyway.'
                    );

                    if (proceed) {
                        // Create backup first
                        await UI.createBackup();

                        // Wait a bit for backup to complete
                        await new Promise(resolve => setTimeout(resolve, 3000));
                    }
                }
            } else {
                const proceed = confirm(
                    'Simple Migrator - Development Safety Check\n\n' +
                    'No backups found!\n\n' +
                    'For development safety, we strongly recommend creating a backup before migration.\n' +
                    'Click "OK" to create a backup first,\n' +
                    'Click "Cancel" to proceed with migration anyway.'
                );

                if (proceed) {
                    // Create backup first
                    await UI.createBackup();

                    // Wait a bit for backup to complete
                    await new Promise(resolve => setTimeout(resolve, 3000));
                }
            }
        } catch (error) {
            console.log('Backup check failed, proceeding with migration:', error);
        }

        try {
            MigrationState.setPhase('scan');
            await this.scanPhase();

            if (!MigrationState.isCancelled) {
                MigrationState.setPhase('database');
                await this.databasePhase();
            }

            if (!MigrationState.isCancelled) {
                MigrationState.setPhase('files');
                await this.filesPhase();
            }

            if (!MigrationState.isCancelled) {
                MigrationState.setPhase('finalize');
                await this.finalizePhase();
            }

            if (!MigrationState.isCancelled) {
                MigrationState.stats.endTime = new Date().toISOString();
                MigrationState.setPhase('complete');
                MigrationState.clearSaved();
                UI.showComplete();
            }
        } catch (error) {
            console.error('Migration error:', error);
            MigrationState.setPhase('error');
            MigrationState.stats.endTime = new Date().toISOString();
            MigrationState.save();
            UI.showError(error.message, error);
        }
    },

    /**
     * Resume interrupted migration
     * Note: manifest is reloaded from source, not from localStorage
     */
    async resume() {
        if (!MigrationState.canResume) {
            alert('No migration to resume.');
            return;
        }

        const resume = confirm(`Resume migration from phase: ${MigrationState.phase}?`);
        if (!resume) return;

        MigrationState.isCancelled = false;
        MigrationState.isPaused = false;

        // Reload manifest from source since we don't store it in localStorage
        if (!MigrationState.manifest || MigrationState.manifest.files.length === 0) {
            try {
                UI.updateStatus('scan', 'Reloading manifest from source...');
                const manifest = await API.getManifest();
                MigrationState.manifest = manifest;
                MigrationState.totalFiles = manifest.files.length;
            } catch (error) {
                alert('Failed to reload manifest from source: ' + error.message);
                MigrationState.reset();
                MigrationState.clearSaved();
                return;
            }
        }

        // Resume from appropriate phase
        switch (MigrationState.phase) {
            case 'scan':
                await this.scanPhase();
                break;
            case 'database':
                await this.databasePhase();
                break;
            case 'files':
                await this.filesPhase();
                break;
            case 'finalize':
                await this.finalizePhase();
                break;
            default:
                await this.start();
        }
    },

    /**
     * Scan Phase: Gather information about source
     */
    async scanPhase() {
        try {
            UI.updateStatus('scan', 'Connecting to source...');

            // Get source configuration
            const sourceConfig = await API.getSourceInfo();
            MigrationState.sourceTablePrefix = sourceConfig.table_prefix;

            UI.updateStatus('scan', 'Scanning files...');

            // Get file manifest
            const manifest = await API.getManifest();
            MigrationState.manifest = manifest;

            UI.updateStatus('scan', 'Scanning database...');

            // Get database info
            const dbInfo = await API.getDatabaseInfo();
            MigrationState.totalTables = dbInfo.total_tables;

            UI.updateProgress('scan', 100, `Scan complete: ${manifest.total_count} files, ${dbInfo.total_tables} tables`);
            MigrationState.setPhase('scan_complete');
        } catch (error) {
            throw new Error(`Scan phase failed: ${error.message}`);
        }
    },

    /**
     * Database Phase: Transfer database tables
     */
    async databasePhase() {
        try {
            // Get source configuration if not already set
            if (!MigrationState.sourceTablePrefix) {
                const sourceConfig = await API.getSourceInfo();
                MigrationState.sourceTablePrefix = sourceConfig.table_prefix;
            }

            // Get destination configuration
            const destConfig = await this.getDestinationConfig();

            // Get database info from source
            const dbInfo = await API.getDatabaseInfo();

            // Check if we're resuming
            const startTable = MigrationState.currentTable || 0;

            // Prepare database (drop existing tables if needed)
            if (startTable === 0) {
                const overwrite = confirm('This will overwrite existing database tables. Continue?');
                if (overwrite) {
                    UI.updateStatus('database', 'Preparing database...');
                    await this.prepareDatabase(dbInfo.tables, overwrite);
                } else {
                    throw new Error('Migration cancelled by user');
                }
            }

            // Process each table
            for (let i = startTable; i < dbInfo.tables.length; i++) {
                if (MigrationState.isCancelled) break;

                // Check for pause
                while (MigrationState.isPaused) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                    if (MigrationState.isCancelled) return;
                }

                const table = dbInfo.tables[i];
                MigrationState.currentTable = i;

                // Reset row offset for new table
                if (MigrationState.lastTableId === 0 && MigrationState.tableOffset === 0) {
                    UI.updateStatus('database', `Creating table ${table.name} (${i + 1}/${dbInfo.tables.length})`);

                    // Get schema from source (use full table name with prefix)
                    const schema = await API.getTableSchema(table.name);
                    MigrationState.tableSchemas[table.name] = schema.schema;

                    // Create table on destination
                    await this.createTable(table.name, schema.schema, MigrationState.sourceTablePrefix);
                }

                UI.updateStatus('database', `Transferring data from ${table.name} (${i + 1}/${dbInfo.tables.length})`);

                // Transfer rows in batches
                let hasMore = true;
                let totalRows = MigrationState.tableOffset || 0;
                let lastId = MigrationState.lastTableId || 0;

                while (hasMore && !MigrationState.isCancelled) {
                    // Check for pause
                    while (MigrationState.isPaused) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                        if (MigrationState.isCancelled) return;
                    }

                    const data = await API.getTableRows(
                        table.name,  // Use full table name with prefix
                        0, // Using keyset pagination, offset not needed
                        1000,
                        lastId
                    );

                    // Process rows (send to destination)
                    await this.processTableRows(table.name, data.rows, MigrationState.sourceTablePrefix);

                    totalRows += data.count;
                    MigrationState.tableOffset = totalRows;
                    MigrationState.stats.rowsTransferred += data.count;
                    hasMore = data.has_more;
                    lastId = data.next_id;
                    MigrationState.lastTableId = lastId;

                    // Update progress
                    const progress = ((i + (totalRows / table.rows)) / dbInfo.tables.length) * 100;
                    UI.updateProgress('database', progress, `Transferred ${totalRows}/${table.rows} rows from ${table.name}`);

                    // Save state periodically
                    if (totalRows % 5000 === 0) {
                        MigrationState.save();
                    }
                }

                // Reset row counters for next table
                MigrationState.tableOffset = 0;
                MigrationState.lastTableId = 0;
            }

            if (!MigrationState.isCancelled) {
                UI.updateProgress('database', 100, 'Database transfer complete');
            }
        } catch (error) {
            throw new Error(`Database phase failed: ${error.message}`);
        }
    },

    /**
     * Files Phase: Transfer files
     */
    async filesPhase() {
        try {
            const manifest = MigrationState.manifest;
            const files = manifest.files || [];

            // Check if we're resuming
            const startFile = MigrationState.currentFileIndex || 0;

            MigrationState.totalFiles = files.length;

            for (let i = startFile; i < files.length; i++) {
                if (MigrationState.isCancelled) break;

                // Skip already completed files
                if (MigrationState.completedFiles.includes(files[i].path)) {
                    continue;
                }

                const file = files[i];
                MigrationState.currentFileIndex = i;
                MigrationState.fileByteOffset = 0;

                // Check for pause
                while (MigrationState.isPaused) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                    if (MigrationState.isCancelled) return;
                }

                UI.updateStatus('files', `Transferring ${file.name} (${i + 1}/${files.length})`);

                try {
                    // Transfer file in chunks
                    await this.transferFile(file.path, file.size);
                    MigrationState.completedFiles.push(file.path);
                    MigrationState.stats.filesTransferred++;
                    MigrationState.stats.bytesTransferred += file.size;

                    // Save state periodically
                    if ((i + 1) % 10 === 0) {
                        MigrationState.save();
                    }
                } catch (error) {
                    console.error(`Failed to transfer file ${file.path}:`, error);
                    // Ask user what to do
                    const continueMigration = confirm(`Failed to transfer ${file.path}: ${error.message}\n\nContinue with remaining files?`);
                    if (!continueMigration) {
                        throw error;
                    }
                }

                const progress = ((i + 1) / files.length) * 100;
                UI.updateProgress('files', progress, `Transferred ${i + 1}/${files.length} files`);
            }

            if (!MigrationState.isCancelled) {
                UI.updateProgress('files', 100, 'File transfer complete');
            }
        } catch (error) {
            throw new Error(`Files phase failed: ${error.message}`);
        }
    },

    /**
     * Transfer a single file in chunks
     */
    async transferFile(filePath, fileSize) {
        const chunkSize = 2 * 1024 * 1024; // 2MB
        let offset = MigrationState.fileByteOffset || 0;

        while (offset < fileSize && !MigrationState.isCancelled) {
            // Check for pause
            while (MigrationState.isPaused) {
                await new Promise(resolve => setTimeout(resolve, 100));
                if (MigrationState.isCancelled) return;
            }

            const end = Math.min(offset + chunkSize, fileSize);
            const chunk = await API.streamFile(filePath, offset, end);

            // Send chunk to destination
            await this.writeFileChunk(filePath, chunk, offset);

            offset += chunk.bytes_read;
            MigrationState.fileByteOffset = offset;
        }
    },

    /**
     * Finalize Phase: Search & Replace, flush permalinks
     */
    async finalizePhase() {
        try {
            UI.updateStatus('finalize', 'Running search & replace...');

            // Perform search & replace
            await this.performSearchReplace();

            UI.updateStatus('finalize', 'Flushing permalinks...');

            // Flush permalinks
            await this.flushPermalinks();

            UI.updateProgress('finalize', 100, 'Finalization complete');
        } catch (error) {
            throw new Error(`Finalize phase failed: ${error.message}`);
        }
    },

    /**
     * Get destination configuration
     */
    async getDestinationConfig() {
        const response = await jQuery.post(smData.ajaxUrl, {
            action: 'sm_get_config',
            nonce: smData.nonce
        });

        if (!response.success) {
            throw new Error(response.data || 'Failed to get destination config');
        }

        return response.data;
    },

    /**
     * Prepare database (drop existing tables)
     */
    async prepareDatabase(tables, overwrite) {
        const tableNames = tables.map(t => t.name);

        const response = await jQuery.post(smData.ajaxUrl, {
            action: 'sm_prepare_database',
            nonce: smData.nonce,
            overwrite: overwrite,
            tables: tableNames,
            source_prefix: MigrationState.sourceTablePrefix
        });

        if (!response.success) {
            throw new Error(response.data || 'Failed to prepare database');
        }

        return response;
    },

    /**
     * Create table on destination
     */
    async createTable(sourceTableName, schema, sourcePrefix) {
        // Use fetch to send JSON properly (better for large schemas)
        // Note: action must be in URL for WordPress AJAX to find it when using JSON body
        const response = await fetch(smData.ajaxUrl + '?action=sm_create_table', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'sm_create_table',
                nonce: smData.nonce,
                source_table_name: sourceTableName,
                source_prefix: sourcePrefix,
                schema: schema
            })
        });

        const responseText = await response.text();

        // Check if response is HTML (error page) instead of JSON
        if (responseText.trim().startsWith('<')) {
            console.error('Server returned HTML instead of JSON:', responseText);
            throw new Error('Server error: Received HTML response instead of JSON. Check PHP error logs.');
        }

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON response:', responseText);
            throw new Error('Invalid JSON response from server. Check PHP error logs.');
        }

        if (!result.success) {
            throw new Error(result.data || 'Failed to create table');
        }

        return result;
    },

    /**
     * Process table rows (send to destination)
     */
    async processTableRows(tableName, rows, sourcePrefix) {
        // Use fetch to send JSON properly
        // Note: action must be in URL for WordPress AJAX to find it when using JSON body
        const response = await fetch(smData.ajaxUrl + '?action=sm_process_rows', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'sm_process_rows',
                nonce: smData.nonce,
                table: tableName,
                source_prefix: sourcePrefix,
                rows: rows
            })
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.data || 'Failed to process rows');
        }

        return result;
    },

    /**
     * Write file chunk (send to destination)
     */
    async writeFileChunk(filePath, chunk, offset) {
        const response = await jQuery.post(smData.ajaxUrl, {
            action: 'sm_write_chunk',
            nonce: smData.nonce,
            path: filePath,
            data: chunk.data,
            checksum: chunk.checksum,
            offset: offset
        });

        if (!response.success) {
            throw new Error(response.data || 'Failed to write file chunk');
        }

        return response;
    },

    /**
     * Perform search & replace
     */
    async performSearchReplace() {
        const response = await jQuery.post(smData.ajaxUrl, {
            action: 'sm_search_replace',
            nonce: smData.nonce
        });

        if (!response.success) {
            throw new Error(response.data || 'Failed to perform search & replace');
        }

        return response;
    },

    /**
     * Flush permalinks
     */
    async flushPermalinks() {
        const response = await jQuery.post(smData.ajaxUrl, {
            action: 'sm_flush_permalinks',
            nonce: smData.nonce
        });

        if (!response.success) {
            throw new Error(response.data || 'Failed to flush permalinks');
        }

        return response;
    },

    /**
     * Pause migration
     */
    pause() {
        MigrationState.isPaused = true;
        MigrationState.setPhase('paused');
    },

    /**
     * Resume migration
     */
    resume() {
        MigrationState.isPaused = false;
        // Resume will be handled by the current loop
    },

    /**
     * Cancel migration
     */
    cancel() {
        if (confirm('Are you sure you want to cancel the migration? Progress will be saved for resuming.')) {
            MigrationState.isCancelled = true;
            MigrationState.canResume = true;
            MigrationState.save();
        }
    }
};

/**
 * UI Controller
 * Manages the user interface with enhanced error display
 */
const UI = {
    /**
     * Initialize UI
     */
    init() {
        this.bindEvents();
        this.showCurrentMode();
        this.checkForResume();
    },

    /**
     * Check if there's a migration to resume
     */
    checkForResume() {
        const hasResume = MigrationState.load();

        if (hasResume && MigrationState.canResume) {
            const resumeButton = jQuery('<button>')
                .attr('id', 'sm-resume-migration')
                .addClass('button button-large')
                .html('<span class="dashicons dashicons-controls-play"></span> Resume Migration')
                .css({
                    'margin-top': '10px',
                    'background-color': '#ff6f00',
                    'border-color': '#ff6f00'
                })
                .on('click', function() {
                    Orchestrator.resume();
                    jQuery(this).remove();
                });

            jQuery('#sm-migration-controls').prepend(resumeButton);
            jQuery('#sm-migration-controls').show();
        }
    },

    /**
     * Bind event handlers
     */
    bindEvents() {
        // Mode selection
        jQuery('.sm-mode-buttons button').on('click', function() {
            const mode = jQuery(this).data('mode');
            UI.setMode(mode);
        });

        // Copy key button
        jQuery('#sm-copy-key').on('click', function() {
            const key = jQuery('#sm-migration-key').text();
            navigator.clipboard.writeText(key).then(() => {
                jQuery(this).text('Copied!');
                setTimeout(() => {
                    jQuery(this).html('<span class="dashicons dashicons-admin-page"></span> Copy Key');
                }, 2000);
            });
        });

        // Regenerate key button
        jQuery('#sm-regenerate-key').on('click', function() {
            if (confirm('Are you sure you want to regenerate the migration key? The old key will no longer work.')) {
                UI.regenerateKey();
            }
        });

        // Test connection button
        jQuery('#sm-test-connection').on('click', function() {
            UI.testConnection();
        });

        // Start migration button
        jQuery('#sm-start-migration').on('click', function() {
            // Clear any previous migration state
            MigrationState.reset();
            MigrationState.clearSaved();
            Orchestrator.start();
        });

        // Pause/Resume button
        jQuery('#sm-pause-migration').on('click', function() {
            if (MigrationState.isPaused) {
                Orchestrator.resume();
                jQuery(this).text('Pause');
            } else {
                Orchestrator.pause();
                jQuery(this).text('Resume');
            }
        });

        // Cancel button
        jQuery('#sm-cancel-migration').on('click', function() {
            Orchestrator.cancel();
        });

        // Backup management buttons
        jQuery('#sm-create-backup').on('click', function() {
            UI.createBackup();
        });

        jQuery('#sm-refresh-backups').on('click', function() {
            UI.loadBackups();
        });

        // Load backups when in destination mode
        const mode = jQuery('#sm-current-mode').val();
        if (mode === 'destination') {
            UI.loadBackups();
        }
    },

    /**
     * Show current mode panel
     */
    showCurrentMode() {
        const mode = jQuery('#sm-current-mode').val();

        jQuery('.sm-panel').hide();

        if (mode === 'source') {
            jQuery('#sm-source-panel').show();
        } else if (mode === 'destination') {
            jQuery('#sm-destination-panel').show();
            // Load saved key for development convenience
            this.loadSourceKey();
        } else {
            jQuery('#sm-no-mode-panel').show();
        }
    },

    /**
     * Set mode
     */
    setMode(mode) {
        // Save mode via AJAX
        jQuery.post(smData.ajaxUrl, {
            action: 'sm_set_mode',
            nonce: smData.nonce,
            mode: mode
        }, function() {
            jQuery('#sm-current-mode').val(mode);
            UI.showCurrentMode();
        });
    },

    /**
     * Test connection to source
     */
    async testConnection() {
        const keyText = jQuery('#sm-source-key').val().trim();

        if (!keyText) {
            alert('Please enter a migration key');
            return;
        }

        // Parse key
        const parts = keyText.split('|');
        if (parts.length !== 2) {
            alert('Invalid migration key format');
            return;
        }

        const sourceUrl = parts[0];
        // Decode base64 encoded secret
        const sourceSecret = atob(parts[1]);

        // Check if save key checkbox is enabled
        const saveKey = jQuery('#sm-save-key').is(':checked');

        const $result = jQuery('#sm-connection-result');
        $result.html('<span class="sm-spinner"></span> Testing connection...').show();

        try {
            const info = await API.testConnection(sourceUrl, sourceSecret);

            // Save key to storage if checkbox is checked
            if (saveKey) {
                UI.saveSourceKey(keyText);
            }

            // Store connection info in state
            MigrationState.sourceUrl = sourceUrl;
            MigrationState.sourceSecret = sourceSecret;

            // Save source URL to server for search & replace phase and CORS tracking
            jQuery.post(smData.ajaxUrl, {
                action: 'sm_save_source_url',
                nonce: smData.nonce,
                source_url: info.site_url
            }, function(response) {
                if (response.success) {
                    console.log('Source URL saved for CORS and search & replace');
                }
            });

            // Show success
            $result.removeClass('error').addClass('success');
            $result.html(`
                <h4>✓ Connection Successful!</h4>
                <p><strong>Source URL:</strong> ${info.site_url}</p>
                <p><strong>WordPress Version:</strong> ${info.wp_version}</p>
                <p><strong>PHP Version:</strong> ${info.php_version}</p>
                <p><strong>MySQL Version:</strong> ${info.mysql_version}</p>
                <p><strong>Memory Limit:</strong> ${info.memory_limit}</p>
            `);

            // Show migration controls
            jQuery('#sm-migration-controls').slideDown();
        } catch (error) {
            $result.removeClass('success').addClass('error');
            $result.html(`
                <h4>✗ Connection Failed</h4>
                <p><strong>Error:</strong> ${error.message}</p>
                <p>Please check the migration key and try again.</p>
            `);
        }
    },

    /**
     * Save source key to database
     */
    saveSourceKey(key) {
        jQuery.post(smData.ajaxUrl, {
            action: 'sm_save_source_key',
            nonce: smData.nonce,
            key: key
        }, function(response) {
            if (response.success) {
                console.log('Source key saved for development');
            }
        });
    },

    /**
     * Load saved source key from database
     */
    loadSourceKey() {
        jQuery.post(smData.ajaxUrl, {
            action: 'sm_load_source_key',
            nonce: smData.nonce
        }, function(response) {
            if (response.success && response.data.key) {
                jQuery('#sm-source-key').val(response.data.key);
                jQuery('#sm-save-key').prop('checked', true);
                console.log('Loaded saved source key');
            }
        });
    },

    /**
     * Update progress bar
     */
    updateProgress(phase, percent, status) {
        jQuery(`#sm-${phase}-progress`).css('width', percent + '%');
        jQuery(`#sm-${phase}-progress`).text(Math.round(percent) + '%');
        if (status) {
            jQuery(`#sm-${phase}-status`).text(status);
        }
    },

    /**
     * Update status text
     */
    updateStatus(phase, status) {
        jQuery(`#sm-${phase}-status`).text(status);
    },

    /**
     * Show complete message with statistics
     */
    showComplete() {
        jQuery('#sm-migration-controls').hide();

        const stats = MigrationState.stats;
        const duration = stats.startTime && stats.endTime ?
            Math.round((new Date(stats.endTime) - new Date(stats.startTime)) / 1000) : 0;

        jQuery('#sm-connection-result').removeClass('error').addClass('success').show();
        jQuery('#sm-connection-result').html(`
            <h4>🎉 Migration Complete!</h4>
            <div class="sm-stats">
                <p><strong>Duration:</strong> ${Math.floor(duration / 60)}m ${duration % 60}s</p>
                <p><strong>Rows Transferred:</strong> ${stats.rowsTransferred.toLocaleString()}</p>
                <p><strong>Files Transferred:</strong> ${stats.filesTransferred.toLocaleString()}</p>
                <p><strong>Data Transferred:</strong> ${UI.formatBytes(stats.bytesTransferred)}</p>
                ${stats.retries > 0 ? `<p><strong>Retries:</strong> ${stats.retries}</p>` : ''}
                ${stats.errors.length > 0 ? `<p><strong>Errors Encountered:</strong> ${stats.errors.length}</p>` : ''}
            </div>
            <p><a href="${smData.homeUrl}" target="_blank" class="button button-primary">View Site</a></p>
        `);
    },

    /**
     * Show error message with details
     */
    showError(message, error = null) {
        jQuery('#sm-migration-controls').hide();
        jQuery('#sm-connection-result').removeClass('success').addClass('error').show();

        let errorDetails = `<p><strong>Error:</strong> ${message}</p>`;

        if (error && error.context) {
            errorDetails += `<p><strong>Context:</strong> ${error.context}</p>`;
        }

        errorDetails += `
            <p>The migration has been paused and can be resumed later.</p>
            <button type="button" class="button button-secondary" id="sm-show-error-details">Show Technical Details</button>
            <div id="sm-error-details" style="display:none; margin-top: 15px; padding: 10px; background: #f0f0f0; border-radius: 4px;">
                <pre style="white-space: pre-wrap; word-wrap: break-word;">${error ? error.stack || message : message}</pre>
            </div>
        `;

        jQuery('#sm-connection-result').html(errorDetails);

        jQuery('#sm-show-error-details').on('click', function() {
            jQuery('#sm-error-details').slideToggle();
            jQuery(this).text(jQuery(this).text() === 'Show Technical Details' ? 'Hide Technical Details' : 'Show Technical Details');
        });
    },

    /**
     * Format bytes to human readable
     */
    formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },

    /**
     * Regenerate key
     */
    regenerateKey() {
        jQuery.post(smData.ajaxUrl, {
            action: 'sm_regenerate_key',
            nonce: smData.nonce
        }, function(response) {
            if (response.success) {
                // Reload page to show new key
                location.reload();
            }
        });
    },

    /**
     * Load backups list
     */
    loadBackups() {
        jQuery.post(smData.ajaxUrl, {
            action: 'sm_list_backups',
            nonce: smData.nonce
        }, function(response) {
            if (response.success) {
                UI.renderBackupList(response.data.backups);
            }
        });
    },

    /**
     * Render backups list
     */
    renderBackupList(backups) {
        const $list = jQuery('#sm-backup-list');

        if (backups.length === 0) {
            $list.html('<div class="sm-no-backups">' +
                '<span class="dashicons dashicons-backup" style="font-size: 48px; width: 48px; height: 48px;"></span>' +
                '<p><?php _e('No backups found. Create a backup before migration for safety.', 'simple-migrator'); ?></p>' +
                '</div>');
            return;
        }

        let html = '';
        backups.forEach((backup, index) => {
            const isLatest = index === 0;
            const statusClass = backup.status === 'complete' ? 'sm-backup-status-complete' : 'sm-backup-status-partial';

            html += '<div class="sm-backup-item ' + (isLatest ? 'latest' : '') + '">' +
                '<div class="sm-backup-info">' +
                '<div class="sm-backup-id">' +
                (isLatest ? '<?php _e('Latest', 'simple-migrator'); ?> • ' : '') +
                '<span class="' + statusClass + '">' + backup.status.toUpperCase() + '</span>' +
                '</div>' +
                '<div class="sm-backup-meta">' +
                '<span><span class="dashicons dashicons-calendar-alt"></span>' + backup.created_at + '</span>' +
                '<span><span class="dashicons dashicons-database"></span>' + UI.formatBytes(backup.db_size) + '</span>' +
                '<span><span class="dashicons dashicons-media-archive"></span>' + UI.formatBytes(backup.files_size) + '</span>' +
                '<span><span class="dashicons dashicons-admin-generic"></span>' + UI.formatBytes(backup.total_size) + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="sm-backup-actions-buttons">' +
                '<button type="button" class="button button-primary button-small sm-restore-backup" data-backup-id="' + backup.backup_id + '">' +
                '<?php _e('Restore', 'simple-migrator'); ?>' +
                '</button>' +
                '<button type="button" class="button button-small sm-delete-backup" data-backup-id="' + backup.backup_id + '">' +
                '<?php _e('Delete', 'simple-migrator'); ?>' +
                '</button>' +
                '</div>' +
                '</div>';
        });

        $list.html(html);

        // Bind event handlers
        jQuery('.sm-restore-backup').on('click', function() {
            const backupId = jQuery(this).data('backup-id');
            UI.restoreBackup(backupId);
        });

        jQuery('.sm-delete-backup').on('click', function() {
            const backupId = jQuery(this).data('backup-id');
            UI.deleteBackup(backupId);
        });
    },

    /**
     * Create backup
     */
    async createBackup() {
        if (!confirm('<?php _e('This will create a full backup of your database and files. This may take a few minutes. Continue?', 'simple-migrator'); ?>')) {
            return;
        }

        const $progress = jQuery('#sm-backup-progress');
        const $fill = jQuery('#sm-backup-progress-fill');
        const $status = jQuery('#sm-backup-progress-status');
        const $createBtn = jQuery('#sm-create-backup');

        $createBtn.prop('disabled', true);
        $progress.show();

        try {
            // Start backup creation
            const response = await jQuery.post(smData.ajaxUrl, {
                action: 'sm_create_backup',
                nonce: smData.nonce
            });

            if (response.success) {
                $fill.css('width', '100%');
                $status.text('<?php _e('Backup created successfully!', 'simple-migrator'); ?>');

                // Reload backups list
                setTimeout(() => {
                    $progress.hide();
                    $fill.css('width', '0%');
                    UI.loadBackups();
                }, 2000);
            }
        } catch (error) {
            $status.text('<?php _e('Backup failed: ', 'simple-migrator'); ?> ' + error.responseText);
        } finally {
            $createBtn.prop('disabled', false);
        }
    },

    /**
     * Restore backup
     */
    async restoreBackup(backupId) {
        const confirmMsg = '<?php _e('WARNING: This will replace your current database and files with the selected backup. All current data will be lost!\\n\\nAre you sure you want to continue?', 'simple-migrator'); ?>';

        if (!confirm(confirmMsg)) {
            return;
        }

        // Double confirmation
        if (!confirm('<?php _e('This is your last chance! Type "OK" to confirm restore.', 'simple-migrator'); ?>')) {
            return;
        }

        const $progress = jQuery('#sm-backup-progress');
        const $fill = jQuery('#sm-backup-progress-fill');
        const $status = jQuery('#sm-backup-progress-status');

        $progress.show();
        $status.text('<?php _e('Restoring backup... Please wait...', 'simple-migrator'); ?>');
        $fill.css('width', '10%');

        try {
            const response = await jQuery.post(smData.ajaxUrl, {
                action: 'sm_restore_backup',
                nonce: smData.nonce,
                backup_id: backupId
            });

            if (response.success) {
                $fill.css('width', '100%');
                $status.text('<?php _e('Restore complete! Reloading page...', 'simple-migrator'); ?>');

                setTimeout(() => {
                    location.reload();
                }, 2000);
            }
        } catch (error) {
            $status.text('<?php _e('Restore failed: ', 'simple-migrator'); ?> ' + error.responseText);
            $fill.css('width', '0%');
        }
    },

    /**
     * Delete backup
     */
    deleteBackup(backupId) {
        if (!confirm('<?php _e('Are you sure you want to delete this backup? This cannot be undone.', 'simple-migrator'); ?>')) {
            return;
        }

        jQuery.post(smData.ajaxUrl, {
            action: 'sm_delete_backup',
            nonce: smData.nonce,
            backup_id: backupId
        }, function(response) {
            if (response.success) {
                UI.loadBackups();
            }
        });
    }
};

/**
 * Initialize on document ready
 */
jQuery(document).ready(function() {
    UI.init();
});
