# Services

> Overview of app/Services/* and how they relate to each other.

Last updated: 2026-09-22

## Overview

All business logic lives in `app/Services/`. Controllers call services; services
call models. Services should not call other services unless clearly necessary —
keep the dependency graph shallow.

## Service inventory

### AiService
Multi-provider AI orchestration (SEO, tags, chat, vision alt-text, image generation,
moderation). Providers are configured at runtime via the admin AI Hub UI (not hardcoded
in `.env`). Text paths use `laravel/ai`; vision/image paths call provider HTTP APIs via
[#AiHttpClient](#aihttpclient). Rate-limited at the route level. See [modules/ai-hub](ai-hub.md).

### AiActionService
Whitelisted CMS mutations proposed by AI chat (create/update pages and banners).
Resolves IDs server-side, executes after confirmation, and supports revert via
`ActivityLog` before-state snapshots.

### AiContextService
Aggregates context for AI requests — collects relevant DB data (pages, settings,
etc.) and caches the result to avoid redundant queries within a session.

### AiHttpClient
Thin HTTP wrapper for **hub-configured AI provider endpoints** (timeouts, header helpers,
status logging without API keys). Not SSRF-hardened — endpoints are not user-controlled.
See [#Outbound HTTP policy](#outbound-http-policy).

### VaultService
Media management. Handles file uploads through the security pipeline and stores
metadata. Entry point for vault operations (upload, delete, move, folder management).
Internally delegates to pipes in `app/Vault/Pipes/`. See [modules/vault](vault.md).

### SettingsService
Key/value settings store with cache layer. Wraps the `settings` collection.
Use this instead of reading settings directly from the DB.

### SafeHttpClient
SSRF-protected HTTP client for **untrusted URLs** (user- or AI-supplied). Pins DNS,
blocks private/reserved IPs, does not follow redirects. See [#Outbound HTTP policy](#outbound-http-policy).

### HtmlSanitizer
HTML purification helpers for user-authored rich content.

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
Uploads and lifecycle for Revisions of a FlowChart Script or an AI Model. Validates the extension
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

### EmailWebhooks/*
Provider adapters (Resend, Mailgun, SendGrid) that normalize inbound webhook events.
See [modules/email](email.md).

## Outbound HTTP policy (two tiers)

| Tier | Client | Use when |
|------|--------|----------|
| Untrusted URL | `SafeHttpClient` | URL comes from a user, form field, or AI output (e.g. fetch remote image to save in Vault) |
| Provider API | `AiHttpClient` | Calling a known AI vendor base URL with a hub-stored API key |

Do **not** use Laravel's `Http` facade directly in application code for these cases —
go through the appropriate client so timeouts and security rules stay consistent.

## When to add a new service

Add a service when:
- Logic is reused across multiple controllers
- Logic is complex enough to warrant unit testing in isolation
- Logic has external side effects (file I/O, HTTP calls, cache writes)

Don't add a service for simple one-off CRUD — a controller method is fine.

## See also

- [modules/vault](vault.md) — VaultService pipeline detail
- [modules/ai-hub](ai-hub.md) — AI provider configuration
- [architecture/stack](../architecture/stack.md) — how services fit in the request flow
