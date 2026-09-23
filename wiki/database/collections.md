# Collections

> Eloquent models, tables, and conventions (PostgreSQL production, SQLite tests).

Last updated: 2026-09-23

## Overview

PostgreSQL is the production database; tests run SQLite in-memory (see
[architecture/testing](../architecture/testing.md)). All models are plain Eloquent —
no custom connection or collection name is needed. See
[architecture/datastore](../architecture/datastore.md) for the full migration writeup.

## Migration files

The schema lives in `database/migrations/`, grouped by responsibility — **5** files plus the
Sanctum one:

| File | Tables |
|---|---|
| `..._create_core_tables.php` | `users`, `roles`, `role_user`, `settings` |
| `..._create_vault_tables.php` | `vault_folders`, `vault_files`, `vault_folder_permissions` |
| `..._create_log_tables.php` | `activity_logs`, `vault_audit_logs`, `email_logs`, `suppressed_emails` |
| `..._create_framework_tables.php` | `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` |
| `..._create_marketplace_tables.php` | `customers`, `machine_brands`, `machine_models`, `scripts`, `script_images`, `ai_models`, `revisions`, `unysis_boxes`, `downloads` |
| `..._create_personal_access_tokens_table.php` | `personal_access_tokens` (Sanctum) |

The old `..._create_content_tables.php` migration is **gone** — the CMS tables (`pages`,
`banners`, `menus`, `redirects`, `chat_sessions`) went with the content modules, and
`ai_hubs` was cut out of the core migration.

## Model conventions

Models are plain Eloquent (`Illuminate\Database\Eloquent\Model`; `User` extends
`Illuminate\Foundation\Auth\User`). Primary keys are ULID strings via the shared
`App\Models\Concerns\HasUlidKey` trait, not auto-increment integers — do not assume
integer IDs when writing new queries or factories.

Reference columns (`author_id`, `user_id`, `folder_id`, etc.) are indexed but carry
**no foreign-key constraints** — deliberate, see [architecture/datastore](../architecture/datastore.md#no-foreign-key-constraints).

## Tables

### Core

| Table | Purpose |
|-----------|---------|
| `users` | Team Members and Customer Users |
| `roles` | Role definitions with permission arrays |
| `role_user` | Pivot table for the users↔roles many-to-many. See [architecture/datastore](../architecture/datastore.md#role_user-pivot) |
| `settings` | Key/value site settings |
| `personal_access_tokens` | Sanctum tokens for the RPA-TOOL API; the token **name** is the UNYSIS Box motherboard UUID |

### Marketplace

| Table | Purpose |
|-----------|---------|
| `customers` | Companies that own UNYSIS Boxes and have Customer Users |
| `machine_brands` | Manufacturer tags for Machine Models |
| `machine_models` | Equipment makes that Scripts target |
| `scripts` | Script catalogue entries |
| `script_images` | Preview Images attached to a Script |
| `ai_models` | AI Model catalogue entries |
| `revisions` | Immutable numbered uploads (polymorphic `revisable_type`/`revisable_id`), status `draft`/`released`/`deprecated`, SHA-256 checksum |
| `unysis_boxes` | Registered edge devices: motherboard UUID, Customer, status, `last_seen_at` |
| `downloads` | One row per recorded fetch of a Revision file (`source` = `web` or `api`); never deleted |

See [modules/marketplace](../modules/marketplace.md) for column-level detail.

### Vault and logs

| Table | Purpose |
|-----------|---------|
| `vault_files` | Uploaded file metadata |
| `vault_folders` | Vault directory structure. Unique index `vault_folders_parent_name_unique` on `(parent_id, name, deleted_at)` |
| `vault_folder_permissions` | Per-folder access grants |
| `activity_logs` | Audit trail of user actions |
| `vault_audit_logs` | Vault-specific audit trail |
| `email_logs` | Outbound email delivery records (status, timestamps, provider id) |
| `suppressed_emails` | Addresses blocked from receiving email (bounced, complained, unsubscribed) |

## .env configuration

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=unysis_marketplace
DB_USERNAME=postgres
DB_PASSWORD=
```

## Settings access

Don't query the `settings` table directly. Use `SettingsService` which
adds a caching layer. See [modules/services](../modules/services.md).

## See also

- [architecture/datastore](../architecture/datastore.md) — PostgreSQL migration, ULID keys, schema layout, no FKs
- [modules/marketplace](../modules/marketplace.md) — catalogue schema in detail
- [modules/services](../modules/services.md) — SettingsService caching layer
- [modules/vault](../modules/vault.md) — how vault_files records are created
- [architecture/testing](../architecture/testing.md) — SQLite test setup, PostgreSQL production
