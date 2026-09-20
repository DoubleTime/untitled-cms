# Collections

> Eloquent models, tables, and conventions (PostgreSQL production, SQLite tests).

Last updated: 2026-09-21 (PostgreSQL migration: ULID keys, tables, no FKs)

## Menu item shape

`MenuController` validates every persisted key in `items[]`. Laravel's `validated()` drops nested
keys that have no rule, so a new item field (e.g. `icon`) needs a rule in `menuItemRules()`, or it is
silently discarded on save. `Menus/Edit.tsx`, `MenuSeeder` and `PublicLayout` all use this shape.

## Overview

PostgreSQL is the production database; tests run SQLite in-memory (see
[architecture/testing](../architecture/testing.md)). All models are plain Eloquent —
no custom connection or collection name is needed. See
[architecture/datastore](../architecture/datastore.md) for the full migration writeup.

## Model conventions

Models are plain Eloquent (`Illuminate\Database\Eloquent\Model`; `User` extends
`Illuminate\Foundation\Auth\User`). Primary keys are ULID strings via the shared
`App\Models\Concerns\HasUlidKey` trait, not auto-increment integers — do not assume
integer IDs when writing new queries or factories.

Reference columns (`author_id`, `user_id`, `folder_id`, etc.) are indexed but carry
**no foreign-key constraints** — deliberate, see [architecture/datastore](../architecture/datastore.md#no-foreign-key-constraints).

## Tables

| Table | Purpose |
|-----------|---------|
| `users` | User accounts |
| `roles` | Role definitions with permission arrays |
| `role_user` | Pivot table for the users↔roles many-to-many; replaces MongoDB's ID-array-on-both-documents approach. See [architecture/datastore](../architecture/datastore.md#role_user-pivot) |
| `pages` | CMS content pages |
| `banners` | Banner/announcement records |
| `vault_files` | Uploaded file metadata |
| `vault_folders` | Vault directory structure. Unique index `vault_folders_parent_name_unique` on `(parent_id, name, deleted_at)` |
| `activity_logs` | Audit trail of user actions |
| `ai_hubs` | AI provider configurations |
| `chat_sessions` | AI chat history |
| `menus` | Navigation menu definitions; `items[]` = `{id, title, url, target, order, subItems[]}` |
| `settings` | Key/value site settings |
| `redirects` | URL redirect rules |
| `email_logs` | Outbound email delivery records (status, timestamps, resend_id) |
| `suppressed_emails` | Addresses blocked from receiving email (bounced, complained, unsubscribed) |

## .env configuration

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=untitled_cms
DB_USERNAME=postgres
DB_PASSWORD=
```

## Settings access

Don't query the `settings` table directly. Use `SettingsService` which
adds a caching layer. See [modules/services](../modules/services.md).

## See also

- [architecture/datastore](../architecture/datastore.md) — PostgreSQL migration, ULID keys, schema layout, no FKs
- [modules/services](../modules/services.md) — SettingsService caching layer
- [modules/vault](../modules/vault.md) — how vault_files records are created
- [architecture/testing](../architecture/testing.md) — SQLite test setup, PostgreSQL production
