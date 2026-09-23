# Services

> Overview of app/Services/* and how they relate to each other.

Last updated: 2026-09-23

## Overview

All business logic lives in `app/Services/`. Controllers call services; services
call models. Services should not call other services unless clearly necessary —
keep the dependency graph shallow. Small, stateless query/formatting helpers that
are not really services live in `app/Support/` (see below).

## Service inventory

### VaultService
Media management. Handles file uploads through the security pipeline and stores
metadata. Entry point for vault operations (upload, delete, move, folder management).
Internally delegates to pipes in `app/Vault/Pipes/`. See [modules/vault](vault.md).

### SettingsService
Key/value settings store with cache layer. Wraps the `settings` table.
Use this instead of reading settings directly from the DB.

### ActivityLogger
Static `log()` used by controllers to write `activity_logs`. Fails silently so logging
never breaks the user flow.

### ClamAvScanner
The one ClamAV implementation. `scan(string $path): ?string` streams a file to clamd (INSTREAM over TCP)
and returns the threat name or null. It owns the fail-open / fail-closed decision from
`vault.clamav_fail_closed`. Used by `App\Vault\Pipes\SandboxedScan` and by
`Marketplace\RevisionService`; do not re-implement the socket protocol anywhere else.
See [modules/vault](vault.md).

### Marketplace\RevisionService
Uploads and lifecycle for Revisions of a Script or an AI Model. Validates the extension
(from `config/marketplace.php`, keyed by morph alias), refuses double extensions, enforces the size cap,
verifies magic bytes (zip / HDF5), optionally scans with `ClamAvScanner`, numbers the Revision
`max + 1` per revisable inside a transaction, stores it on the private `marketplace` disk and records the
SHA-256. `release()` / `deprecate()` walk the one-way `draft -> released -> deprecated` lifecycle and
throw `App\Exceptions\Marketplace\InvalidRevisionTransition` otherwise; `deleteFile()` backs hard
delete. See [modules/marketplace](marketplace.md).

### Marketplace\DownloadService
`record()` writes one `downloads` row (source `web` from the admin, `api` from RPA-TOOL);
`stream()` returns the file as a `StreamedResponse` under its original filename with
`X-Checksum-SHA256` and `X-Revision-Number`. Download rows are never deleted.

### Marketplace\UnysisBoxService
Auto-registration and presence tracking for UNYSIS Boxes. `resolve()` finds or creates the box
for a reported motherboard UUID under the signing-in Customer User's Customer, throwing
`UnysisBoxBelongsToAnotherCustomer` / `UnysisBoxBlocked`. `touch()` updates `last_seen_at` but
skips the write when the box was seen less than `TOUCH_INTERVAL_SECONDS` (60) ago from the same
IP — the resolve middleware runs on every API request, so an unthrottled write would mean one
UPDATE per catalogue read.

### Marketplace\UnysisBoxInstalledService
Derives what a box currently has installed. "Installed" is not stored: it is the **latest
Download of a catalogue entry by that box**, whatever the Revision's status is now. The
"latest per entry" reduction happens in PHP rather than SQL — `max(id)` is wrong under ULID
keys and a window function would need two dialects — so it reads the box's own (bounded)
Download log newest-first and keeps the first row per entry. Five queries regardless of size.

### EmailWebhooks/*
Provider adapters (Resend, Mailgun, SendGrid) that normalize inbound webhook events.
See [modules/email](email.md).

## Support classes (`app/Support/`)

### DownloadQuery
The **one** filter builder for the Download log — the log index, its CSV export and the Usage
report all narrow the same table the same way. `filters()` normalises the query string (unknown
values are dropped, never passed through) and the builder applies them. Every column in it is
**table-qualified**, because the report joins `unysis_boxes` and `users` onto `downloads` and an
unqualified column would be ambiguous.

### DownloadPresenter
Shapes rows for the admin pages: `entryNames()` resolves each catalogue entry's name, Machine
Model and Machine Brand with one `withTrashed()` query per entry type, keyed `"{morph alias}:{id}"`.
A `morphTo` eager load would miss soft-deleted entries, and hard-deleted entries deliberately keep
their Download rows, so a row whose entry is gone still renders. Any row carrying
`revisable_type` / `revisable_id` works — Download rows, grouped report rows and Revision rows.

### DateBucket
`expression($column)` returns the SQL that buckets a timestamp to `YYYY-MM-DD` —
`strftime` on SQLite, `to_char` on PostgreSQL. This is the one unavoidable dialect branch
(dashboard chart, usage report); new raw SQL should follow this pattern rather than branching
inline. See [architecture/testing](../architecture/testing.md).

## Outbound HTTP

There is no outbound HTTP in application code any more — the SSRF-hardened and provider
clients went with the AI features. If a new integration needs to call out, add a dedicated
client rather than using the `Http` facade from a controller, and treat any user-supplied URL
as untrusted.

## When to add a new service

Add a service when:
- Logic is reused across multiple controllers
- Logic is complex enough to warrant unit testing in isolation
- Logic has external side effects (file I/O, HTTP calls, cache writes)

Don't add a service for simple one-off CRUD — a controller method is fine.

## See also

- [modules/vault](vault.md) — VaultService pipeline detail
- [modules/marketplace](marketplace.md) — catalogue, revisions, RPA-TOOL API
- [architecture/stack](../architecture/stack.md) — how services fit in the request flow
