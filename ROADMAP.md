# Roadmap

This document outlines planned improvements for Simple Migrator. Versions are grouped by theme — each milestone builds on the previous one, moving from configuration to polish to new features to automation.

## v1.1.0 — Settings & Configurability ✅

_Released. All operational parameters are now configurable via an admin settings page and developer filter hooks._

- ~~**Admin settings page** — Configure chunk size, batch size, backup retention count, and max retries from the WordPress admin~~
- ~~**WordPress filter hooks** — Allow themes/plugins to customize file exclusion patterns and protected tables/options via `apply_filters()`~~
- ~~**Configurable migration lock timeout** — Adjust the concurrent-migration lock duration for slow servers or large migrations~~

## v1.2.0 — UX Polish

_With configurability in place, this release focuses on the admin experience — reducing friction during migrations and making errors easier to diagnose without browser DevTools._

- **Inline error log viewer** — Display migration errors directly in the admin panel instead of requiring the browser console
- **"Skip file" option** — Allow skipping individual files during migration instead of only fail/cancel
- **Single styled confirmation modal** — Replace multiple `confirm()` dialogs with one summary confirmation step
- **Expanded error details by default** — Show full error information without requiring extra clicks (power-user friendly)
- **Clearer connection diagnostics** — Surface DNS resolution, SSL certificate, and firewall hints when connections fail

## v1.3.0 — New Capabilities

_Once the existing workflow is polished, this release adds genuinely new migration modes and operational features that have been frequently requested._

- **Partial migration** — Support database-only or files-only migration for targeted workflows
- **Dry run mode** — Preview what would be migrated, with estimated time and data size, before committing
- **Migration history log** — Record source, destination, duration, and status of each migration for auditing
- **Bandwidth throttling** — Limit transfer speed to prevent server overload on shared hosting

## v2.0.0 — Automation & Integration

_A major version bump because this release introduces scheduled operations and external integrations that change the plugin's scope from a manual tool to an automation platform._

- **Scheduled backups via WP-Cron** — Configure automatic backup schedules without external cron jobs
- **Webhook notifications** — Send Slack messages or emails on migration/backup completion or failure
- **Migration profiles** — Save source URL, key, and settings as reusable profiles for repeated migrations
- **Multisite support** — Network-level migration and per-site migration for WordPress multisite installations
