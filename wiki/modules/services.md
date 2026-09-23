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
SHA-256. A concurrent upload that wins the unique index on (`revisable_type`, `revisable_id`, `number`)
is retried **once**, and only that clash is: `isUniqueViolation()` checks `errorInfo[0]` for SQLSTATE
`23505` (PostgreSQL) or `23000` (SQLite/MySQL), and any other `QueryException` is rethrown at once
rather than silently attempted a second time. `release()` / `deprecate()` walk the one-way `draft -> released -> deprecated` lifecycle and
throw `App\Exceptions\Marketplace\InvalidRevisionTransition` otherwise; `deleteFile()` backs hard
delete. See [modules/marketplace](marketplace.md).

### Marketplace\DownloadService
`record()` writes one `downloads` row (source `web` from the admin, `api` from RPA-TOOL);
`stream()` returns the file as a `StreamedResponse` under its original filename with
`X-Checksum-SHA256` and `X-Revision-Number`. `statsFor($entry)` is the header pair on a catalogue
Show page — total Downloads and distinct UNYSIS Boxes — which used to be a private method duplicated
in both admin controllers. Download rows are never deleted.

### Marketplace\CustomerUserGuard
`isRpaToolOnly(User)` — true when an account exists only for RPA-TOOL: it is linked to a Customer, or
it holds the `customer` role and no role granting backend access. One predicate, four callers: the web
login (`Auth\LoginRequest`), `SocialAuthController`, `Api\V1\AuthController` and the per-request
re-check in `ResolveUnysisBox`. `RPA_TOOL_ONLY_MESSAGE` is a constant here too. The web login refuses
these accounts and the API refuses everyone else, so the two rules are mirror images of one test
(docs/adr/0002).

### Marketplace\UsageReportService
The Usage report's aggregation: `filters()` (date-range presets plus an optional entry type,
normalised), `totals()`, `byCustomer()`, `byEntry()`, and `section()` / `csvRow()` for the two CSV
exports. A Download carries no `customer_id`, so the base query left-joins `unysis_boxes` and `users`
and groups on `coalesce(unysis_boxes.customer_id, users.customer_id)`. **`byCustomer()` returns only
the Customers that downloaded in the range**, plus a single "No Customer (internal)" row when the
range contains web downloads made without a box — a Customer with no activity is not a row.
`active_boxes` is still a property of the Customer *today*, from its own grouped query.

### Marketplace\DashboardStatsService
One method per dashboard panel — `entryCard(morph alias)`, `customerCard()`, `boxCard()`,
`downloadCard()`, `downloadsPerDay()`, `latestRevisions()`, `recentBoxes()` — so
`DashboardController` only decides which of them the viewer's permissions allow. `downloadsPerDay()`
is the one caller of `DateBucket`; gaps are filled in PHP so every day in the 30-day window has a point.

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

### Marketplace\DownloadQuery
The **one** filter builder for the Download log — the log index, its CSV export and the Usage
report all narrow the same table the same way. `filters()` normalises the query string (unknown
values are dropped, never passed through) and the builder applies them. Every column in it is
**table-qualified**, because the report joins `unysis_boxes` and `users` onto `downloads` and an
unqualified column would be ambiguous.

### Marketplace\DownloadPresenter
Shapes rows for the admin pages: `entryNames()` resolves each catalogue entry's name, Machine
Model and Machine Brand with one `withTrashed()` query per entry type, keyed `"{morph alias}:{id}"`.
A `morphTo` eager load would miss soft-deleted entries, and hard-deleted entries deliberately keep
their Download rows, so a row whose entry is gone still renders. Any row carrying
`revisable_type` / `revisable_id` works — Download rows, grouped report rows and Revision rows.

### EmailWebhooks/*
Provider adapters (Resend, Mailgun, SendGrid) that normalize inbound webhook events.
See [modules/email](email.md).

## Support classes (`app/Support/`)

### CatalogueEntryType
`final` class holding the two morph aliases as constants — `SCRIPT = 'script'`,
`AI_MODEL = 'ai_model'`, `ALL` — plus `modelClass($alias)`, `label($alias)` and `fromModel($model)`.
The same alias-to-class `match` used to be written out in `DownloadPresenter`, `DownloadQuery`,
`UnysisBoxInstalledService` and the dashboard, and the aliases themselves were spelled as literals in
`config/marketplace.php`, the `enforceMorphMap()` call and two form requests. All of them reference
this class now. `modelClass()` accepts a fully qualified class name as well as an alias and returns
`null` for anything unknown, so a `revisable_type` the application no longer has is skipped rather
than fatal.

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
