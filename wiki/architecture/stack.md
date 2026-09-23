# Stack

> Technology choices and key design decisions.

Last updated: 2026-09-23

## Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13, PHP **8.4+** |
| Database | PostgreSQL (production), SQLite in-memory (tests) — see [architecture/datastore](datastore.md) |
| Frontend | React 19 + TypeScript, Inertia.js |
| Styling | Tailwind CSS v4, Shadcn/Radix UI |
| Build | Vite 8 (Node 22.12+) |
| Auth | Laravel Sanctum + Sessions (web); Sanctum personal access tokens (RPA-TOOL API) |
| Mail | Resend (`resend/resend-laravel`), with Mailgun/SendGrid webhook adapters |
| File storage | Local `public` disk for the Vault, private `marketplace` disk for Revision files |

## Key design decisions

**Service layer over fat controllers.** All business logic lives in `app/Services/`.
Controllers are thin orchestrators. This makes services testable in isolation and
keeps controllers readable.

**Typed pipeline for Vault uploads.** Rather than a monolithic upload handler, uploads
pass through a sequence of discrete pipe classes. Each pipe does one thing and passes
a typed DTO to the next. Easy to add, remove, or reorder stages. See [modules/vault](../modules/vault.md).

**PostgreSQL, plain Eloquent.** Every model is a plain Eloquent model with ULID
primary keys (`App\Models\Concerns\HasUlidKey`). Production runs PostgreSQL; tests run
SQLite in-memory. MongoDB was removed after the migration documented in
[architecture/datastore](datastore.md); see [architecture/mongodb](mongodb.md) for the
historical record of why it was chosen and why it was dropped.

**Immutable Revisions.** Catalogue entries (Scripts, AI Models) never change their uploaded
file in place — each upload is a new, sequentially numbered Revision walking a one-way
`draft -> released -> deprecated` lifecycle, with its own checksum. See
[modules/marketplace](../modules/marketplace.md).

**Admin-only surface.** There is no public content surface; `/` redirects to the dashboard or
the login page. The only non-admin entry points are the media endpoints, the email webhook /
unsubscribe routes and the RPA-TOOL API.

**API is the box, not the user.** RPA-TOOL tokens are named after the UNYSIS Box's motherboard
UUID and resolved on every request, so blocking a box takes effect immediately rather than at
token expiry. See [architecture/middleware](middleware.md).

**Custom permission middleware + policy-first controllers.** The `can` middleware alias
points to `CheckPermission`. Controllers should authorize via policies. See [modules/permissions](../modules/permissions.md).

**Dialect-neutral SQL.** Tests run SQLite, production runs PostgreSQL, and CI runs both.
Raw SQL is written through the query builder; the one unavoidable difference lives in
`App\Support\DateBucket`. See [architecture/testing](testing.md).

## See also

- [architecture/request-flow](request-flow.md) — how a request moves through the system
- [architecture/middleware](middleware.md) — full middleware stack
- [architecture/datastore](datastore.md) — PostgreSQL migration, ULID keys, schema layout
- [architecture/mongodb](mongodb.md) — historical MongoDB decision and test gap
- [modules/services](../modules/services.md) — service layer details
- [modules/marketplace](../modules/marketplace.md) — catalogue, revisions, RPA-TOOL API
- [frontend/ui-stack](../frontend/ui-stack.md) — React/Inertia frontend
- [database/collections](../database/collections.md) — model and table conventions
