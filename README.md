# Unysis Marketplace

**Unysis Marketplace** is the internal catalogue where the UNYSIS team publishes the AI Models and
Scripts that run on UNYSIS Boxes, and from which **RPA-TOOL** fetches them. Team Members manage the
catalogue through a React + Inertia.js admin SPA; UNYSIS Boxes talk to a read-only JSON API with
Sanctum tokens bound to the box. It is built on Laravel 13, PHP 8.4 and PostgreSQL.

The domain vocabulary — AI Model, Script, Revision, Machine Model, Machine Brand, Customer,
Team Member, Customer User, UNYSIS Box, Download — is defined in [`CONTEXT.md`](CONTEXT.md).
Use those words.

> This is an internal application, not a public website. There is no public content surface:
> `/` redirects to the dashboard for signed-in Team Members and to the login page otherwise.

---

## What's Inside

| Area | Highlights |
| ---- | ---------- |
| **Catalogue** | Scripts and AI Models organised by Machine Brand / Machine Model · Preview Images · optional Customer labelling · soft delete and hard delete |
| **Revisions** | Immutable, sequentially numbered uploads with a change note, SHA-256 checksum, magic-byte validation, size cap, optional ClamAV scan, and a one-way `draft → released → deprecated` lifecycle |
| **UNYSIS Boxes** | Auto-registered on first sight by motherboard UUID · `pending` / `active` / `blocked` status · `last_seen_at` presence · derived "installed" view per box |
| **Downloads & reporting** | Every fetch recorded (web or API) · filterable Download log with CSV export · Usage report by Customer and by entry, with its own exports |
| **Dashboard** | Stat cards (Scripts, AI Models, Customers, Boxes, Downloads with 7-day delta) · 30-day downloads chart · latest Revisions · recently seen Boxes — each panel gated on the viewer's permissions |
| **RPA-TOOL API v1** | Sanctum bearer tokens named after the box · catalogue browsing, `check-update`, and streamed Revision downloads · dedicated rate limiters |
| **The Vault** | Hierarchical media manager · secure upload pipeline (5 stages + optional ClamAV) · folder-level permissions · trash and batch restore · full audit log |
| **Auth & RBAC** | Login · email verification · token-based invitations · granular `resource.action` permissions with Laravel Gate policies · social login (Google, GitHub) |
| **Ops** | Activity log · settings store · maintenance mode with role bypass · email logs, suppression list and delivery webhooks (Resend, Mailgun, SendGrid) |
| **LLM Wiki** | Persistent, agent-maintained knowledge base under [`wiki/`](wiki/index.md) |

---

## Requirements

| Requirement | Version | Notes |
| ----------- | ------- | ----- |
| **PHP** | >= 8.4 | Extensions: `mbstring`, `xml`, `curl`, `zip`, `gd`, `fileinfo`, `pdo_pgsql` |
| **Composer** | >= 2.0 | [getcomposer.org](https://getcomposer.org) |
| **PostgreSQL** | >= 16 | Production datastore; tests run SQLite in-memory |
| **Node.js** | >= 22.12 | [nodejs.org](https://nodejs.org) |
| **npm** | >= 10 | Bundled with Node.js |

---

## Setup

```bash
composer run setup
```

Runs in sequence: `composer install` → `.env` copy → `key:generate` → `migrate` → `db:seed`
→ `npm install` → `npm run build`.

Set at minimum in `.env`:

```env
APP_NAME="Unysis Marketplace"
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=unysis_marketplace
DB_USERNAME=postgres
DB_PASSWORD=
```

### Default login

After seeding, sign in at `http://localhost:8000/login` with `admin@example.com` / `password`.
**Change this password immediately.**

---

## Development

```bash
composer run dev     # server + queue worker + log viewer (Pail) + Vite HMR
```

| Command | Description |
| ------- | ----------- |
| `composer run dev` | Start all dev services |
| `composer run test` | Clear config and run the PHPUnit suite |
| `php artisan test --filter NameOfTest` | Run a single test case |
| `./vendor/bin/pint` | PHP code formatter (Laravel Pint) |
| `npm run dev` | Vite dev server with HMR only |
| `npm run build` | `tsc && vite build` — TypeScript errors fail the build |
| `php artisan migrate:fresh --seed` | Wipe and re-seed (dev only) |

Working with an AI coding agent? Project conventions for all agents live in [`AGENTS.md`](AGENTS.md);
`CLAUDE.md` and `GEMINI.md` forward to it.

---

## Admin areas

All admin routes sit under `/admin` behind `auth`, `verified` and `RequireAdminAccess`.

- **Dashboard** — catalogue and download health at a glance
- **Marketplace** — Customers (and their Customer Users), Machine Brands, Machine Models,
  Scripts, AI Models, UNYSIS Boxes, Downloads, Usage Report
- **Administration** — Vault, Users, Roles, Email Logs, Activity, Settings

---

## RPA-TOOL API

The read-only catalogue API lives under `/api/v1` and speaks JSON. RPA-TOOL signs in with a
Customer User's credentials **plus the motherboard UUID of the box it runs on**; the token that
comes back is named after that UUID, and every later request resolves the UNYSIS Box from it.
Downloads are attributed to `Customer User + UNYSIS Box` taken from the token — no request
parameter can change either. Blocking a box or deactivating a Customer User takes effect on the
next request, not at token expiry.

Full reference: [`docs/api/rpa-tool-v1.md`](docs/api/rpa-tool-v1.md).

Rate limits: 5/min login (per IP), 20/min downloads and 60/min general (per token).

---

## Permissions & roles

Permissions are strings in `resource.action` form. The canonical list is
`Role::availablePermissions()` in `app/Models/Role.php` — **42** permissions across `media`,
`users`, `roles`, `email_logs`, `manage-settings`, `customers`, `machines`, `scripts`,
`ai_models`, `unysis_boxes` and `downloads`. Do not hardcode copies of it elsewhere.

Seeded roles:

| Role | Backend access | What it can do |
| ---- | -------------- | -------------- |
| `admin` | yes | Everything |
| `editor` | yes | Manage the catalogue and its Vault media; no Users, Roles or Customers |
| `author` | yes | Draft catalogue entries and upload Revisions; cannot release or delete |
| `viewer` | yes | Read-only catalogue |
| `customer` | **no** | Customer Users signing in through RPA-TOOL; no admin access at all |

Checks are cached per user (~60s) and busted on role save and `User::syncRoles()`.
See [`wiki/modules/permissions.md`](wiki/modules/permissions.md).

---

## Tech stack

**Backend** — Laravel 13 · PHP 8.4 · PostgreSQL (plain Eloquent, ULID primary keys, no FK
constraints) · Laravel Sanctum (sessions + API tokens) · Laravel Socialite · Inertia.js ·
Intervention Image · Resend · Ziggy.

**Frontend** — React 19 · TypeScript · Tailwind CSS v4 · Shadcn/Radix UI · Vite 8 ·
TanStack Table · Recharts · @dnd-kit · react-dropzone · Zod · Sonner · lucide-react.

---

## Testing

```bash
composer run test
php artisan test tests/Feature/Marketplace/UsageReportTest.php
```

`phpunit.xml` pins `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`, so the suite needs no
external database. CI runs it twice — on SQLite and on a real PostgreSQL 17 service — to catch
dialect differences; the one unavoidable difference lives in `app/Support/DateBucket.php`.
Write new raw SQL through the query builder, or follow the `DateBucket` pattern.

See [`wiki/architecture/testing.md`](wiki/architecture/testing.md).

---

## Optional configuration

### ClamAV (virus scanning)

Off by default; shared by Vault uploads and Revision uploads.

```env
CLAMAV_ENABLED=true
# CLAMAV_HOST=127.0.0.1
# CLAMAV_PORT=3310
# CLAMAV_FAIL_CLOSED=false   # true = reject uploads when the scanner is unreachable
```

### Email

Set `MAIL_MAILER` (defaults to `log`). For Resend, add `RESEND_API_KEY`. Delivery webhooks from
Resend, Mailgun or SendGrid arrive at `/webhooks/email` and are verified with
`RESEND_WEBHOOK_SECRET`, `MAILGUN_WEBHOOK_SIGNING_KEY` or `SENDGRID_WEBHOOK_PUBLIC_KEY` —
see `.env.example`.

### Social login

Add OAuth credentials to `.env`, then enable them in **Admin → Settings → Auth**:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
```

---

## Documentation

- [`CONTEXT.md`](CONTEXT.md) — domain vocabulary
- [`AGENTS.md`](AGENTS.md) — conventions for humans and AI coding agents
- [`wiki/index.md`](wiki/index.md) — architecture and module knowledge base
- [`docs/api/rpa-tool-v1.md`](docs/api/rpa-tool-v1.md) — RPA-TOOL API reference
- [`docs/deployment.md`](docs/deployment.md) — production deployment guide
- [`CONTRIBUTING.md`](CONTRIBUTING.md) · [`SECURITY.md`](SECURITY.md) · [`CHANGELOG.md`](CHANGELOG.md)

---

## License

Licensed under the [MIT license](LICENSE).
