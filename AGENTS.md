# AGENTS.md

Single source of truth for all AI coding agents (Codex, Claude Code, Gemini CLI, and others) working in this repository. `CLAUDE.md` and `GEMINI.md` only forward here — add or change shared instructions in this file, never in the stubs.

## Project Overview

**Untitled CMS** is an AI-native Content Management System built on Laravel 13 with PostgreSQL and a React + Inertia.js admin SPA. Public pages are served as HTML by default and as Markdown+YAML frontmatter when requested with `Accept: text/markdown` (for AI crawlers/agents).

## Project Structure & Module Organization

Backend code lives in `app/`, routes in `routes/`, database migrations and seeders in `database/`, and tests in `tests/Feature` and `tests/Unit`. Frontend code is under `resources/js/` and `resources/css/`, with pages in `resources/js/Pages`, shared UI in `resources/js/Components`, and layouts in `resources/js/Layouts`. Project notes and architecture docs live in `wiki/` and `docs/`, with module-specific pages such as `wiki/modules/vault.md` and `wiki/architecture/request-flow.md`.

## Build, Test, and Development Commands

- `composer run setup`: installs dependencies, prepares `.env`, generates the app key, runs migrations and seeders, and builds assets.
- `composer run dev`: starts the Laravel server, queue listener, log viewer (Pail), and Vite HMR together.
- `composer run test`: clears config and runs the PHPUnit suite.
- `php artisan test tests/Feature/VaultUploadTest.php`: runs a single test file.
- `php artisan test --filter NameOfTest`: runs a single test case while iterating.
- `./vendor/bin/pint`: formats PHP code using Laravel Pint.
- `npm run dev`: runs the Vite dev server for frontend assets.
- `npm run build`: runs `tsc && vite build` — TypeScript errors fail the build.

## Architecture

### Stack
- **Backend:** Laravel 13, PHP 8.4, PostgreSQL (plain Eloquent, ULID primary keys)
- **Frontend:** React 19 + TypeScript, Inertia.js (props-based routing, no client-side router), Tailwind CSS v4, Shadcn/Radix UI
- **Build:** Vite 8 (frontend build runs `tsc && vite build`)
- **Auth:** Laravel Sanctum + Sessions, Laravel Socialite (Google, GitHub — toggled via Settings UI)

### Request Flow
```
Browser → Laravel Route → Middleware Stack → Controller → Service/Model → PostgreSQL
                                                       ↓
                                              Inertia::render($page, $props) → React Page Component
```

### Service Layer (`app/Services/`)

Business logic lives here, not in controllers.

- **`AiService`** — Multi-provider AI orchestration (OpenAI, Gemini, OpenRouter, Stability, etc.). Providers are configured at runtime via the AI Hub UI, not hardcoded. Provider HTTP uses `AiHttpClient`; untrusted URLs use `SafeHttpClient`.
- **`AiActionService`** — Structured AI-driven CMS mutations (create/update pages and banners). Actions are validated against a whitelist, resolved server-side, and are revertible via `ActivityLog` before-state snapshots.
- **`AiContextService`** — Aggregates project context (pages, settings) for AI prompts; caches to avoid redundant DB queries.
- **`VaultService`** — Media management. Entry point for all vault operations; delegates uploads to the pipe pipeline.
- **`SettingsService`** — Key/value settings with cache. Always use this instead of querying `settings` directly.
- **`ActivityLogger`** — Static `log()` call used throughout controllers to write to `activity_logs`. Fails silently to avoid disrupting user flow.
- **`SafeHttpClient`** — SSRF-protected HTTP client for untrusted (user/AI-supplied) URLs. Provider APIs use `AiHttpClient` instead. Never call the `Http` facade directly from app code for these paths.
- **`AiHttpClient`** — Thin client for hub-configured AI vendor endpoints (timeouts + logging).
- **`HtmlSanitizer`** — HTMLPurifier wrapper; `clean($html, $profile)` uses named profiles from `config/purifier.php`.
- **`EmailWebhooks/`** — Per-provider inbound webhook handlers (Mailgun, Resend, SendGrid) behind a shared contract in `Contracts/`.

### Pipeline Pattern (Vault Upload)

`VaultService` runs uploads through `app/Vault/Pipes/` in order:
1. `DetectDoubleExtension` → 2. `ValidateMimeType` → 3. `SanitizeImage` → 4. `ModerationCheck` → 5. `GenerateUuid` → 6. `StoreMetadata`

When `vault.clamav_enabled` is true, `SandboxedScan` (ClamAV) is spliced in after `ValidateMimeType`; `vault.clamav_fail_closed` controls whether a scanner outage rejects the upload.

State is carried via `app/Vault/DTOs/VaultPipelinePayload.php`. Upload config is in `config/vault.php` (allowed extensions, 50MB max, ClamAV toggle, `image_washing`).

### Permissions System

Permissions are strings in `resource.action` format (e.g. `pages.edit`, `media.upload`). The canonical list is defined in `Role::availablePermissions()` in `app/Models/Role.php` — this is the single source of truth; do not hardcode counts or copies elsewhere.

- **`User::hasPermission(string)`** / **`User::getCachedPermissions()`** — cached in Redis/cache for 60s per user
- **`User::canAccessBackend()`** — separate cache key; gates the entire admin area (checks `backend_access` flag on roles)
- **`HasRoles` trait** — only adds `hasRole(string $slug)` helper; everything else is on the `User` model
- **Policy classes** in `app/Policies/` — one per resource type
- **`CheckPermission` middleware** — the `can` alias points here (not Laravel's default). Usage: `->middleware('can:pages.edit')`
- **`RequireAdminAccess` middleware** — aliased as `admin`; applied to all admin routes; checks `canAccessBackend()` and redirects to `/` on failure
- Cache is busted automatically: `Role::saved` event busts all member caches; `User::syncRoles()` busts the affected user's cache

### Middleware Stack (web, in order)

Registered in `bootstrap/app.php`:

1. `HandleInertiaRequests` — shares props to all pages (see below)
2. `AddLinkHeadersForPreloadedAssets` — preload `Link` headers for performance
3. `CheckRedirects` — database-driven URL redirects (hits the database on every request — keep the `redirects` table indexed)
4. `CheckMaintenanceMode` — custom maintenance mode; reads from `SettingsService` (cache lag possible)

Admin routes additionally apply: `auth`, `verified`, `RequireAdminAccess`.

### Inertia Shared Props

`HandleInertiaRequests` shares on every page load:
```
auth.user              — subset of User: id, name, email, is_active
auth.permissions       — string[] from getCachedPermissions()
auth.canAccessBackend  — boolean
appName                — config('app.name')
settings               — public settings key/value (SettingsService::getPublicSettings)
tinymce_api_key        — only for backend users; null otherwise
aiChatEnabled          — boolean from settings
menus                  — Menu::active()->get()->keyBy('slug'), cached 300s under `active_menus`
```

### Frontend (`resources/js/`)

- **Pages/** — one file per controller. Props typed via `PageProps<T>` generic from `types/index.d.ts`.
- **Components/ui/** — Shadcn/Radix component wrappers
- **Layouts/** — `AuthenticatedLayout`, `AuthLayout`, `GuestLayout`, `PublicLayout`
- **types/index.d.ts** — `PageProps<T>` generic; extend it for page-specific props
- **`route()`** — Ziggy-generated typed route helper, available globally

Key UI libraries: TanStack Table (data grids), @dnd-kit (drag-drop), Recharts (analytics charts), Zod (form validation), Sonner (toasts), TinyMCE (`Editor.tsx`) for rich content, `react-dropzone` for Vault uploads.

Inertia form pattern: use `useForm()` from `@inertiajs/react` — handles loading state, errors, and submission. No fetch calls or separate API layer.

### AI-Native Endpoints (no auth)

- `GET /llms.txt` — llmstxt.org standard index of published pages (plain text)
- `GET /llms-full.txt` — full content of all published pages as Markdown; includes `x-llms-tokens` header
- `GET /sitemap.md` — sitemap for AI agents
- `GET /{slug}` with `Accept: text/markdown` — individual page as Markdown + YAML frontmatter

### Route Structure (`routes/web.php`)

- **Public (no auth):** `/`, `/rss`, `/feed`, `/sitemap.md`, `/llms.txt`, `/llms-full.txt`, `/{slug}`
- **Public media:** `/media/{uuid}.{extension}` (and legacy `/media/{uuid}`) — `throttle:1000,1`; resolved by UUID only
- **Profile:** `auth` only (no admin middleware)
- **Admin:** `/admin` prefix, `auth` + `verified` + `admin` (`RequireAdminAccess`) — all resource controllers
- **User batch actions:** `throttle:10,1`
- **AI text generation:** `throttle:30,1`
- **AI image generation:** `throttle:10,1`
- **AI chat + actions:** `throttle:60,1`

## Database

PostgreSQL is required for production; tests run on SQLite in-memory (see Testing Guidelines below).

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=untitled_cms
DB_USERNAME=postgres
DB_PASSWORD=
```

All models are plain Eloquent (`Illuminate\Database\Eloquent\Model`; `User` extends `Illuminate\Foundation\Auth\User`) with ULID string primary keys via the shared `App\Models\Concerns\HasUlidKey` trait. The schema lives in `database/migrations/`, grouped into five files by responsibility (core, content, vault, logs, framework). Reference columns (`author_id`, `user_id`, `folder_id`, etc.) carry indexes but no foreign-key constraints — deliberate, since the app was written against MongoDB's lack of referential integrity. See [architecture/datastore](wiki/architecture/datastore.md) for the full writeup.

Key tables: `users`, `roles`, `role_user`, `pages`, `banners`, `vault_files`, `vault_folders`, `activity_logs`, `ai_hubs`, `chat_sessions`, `menus`, `settings`, `redirects`, `email_logs`, `suppressed_emails`.

### Marketplace

Catalogue schema added by `2026_09_22_000001_create_marketplace_tables.php`. Vocabulary is fixed in `CONTEXT.md`; details in [modules/marketplace](wiki/modules/marketplace.md).

- `customers` — a company owning AI Boxes; `users.customer_id` (nullable) marks a Customer User
- `machine_brands`, `machine_models` — how the catalogue is organised; unique name per brand
- `flowchart_scripts`, `flowchart_script_images` — packaged automation sequences and their Vault-backed Preview Images
- `ai_models` — standalone trained inference models (unrelated to `ai_hubs`)
- `revisions` — polymorphic (`revisable`) numbered uploads shared by both entry types; unique number per revisable
- `ai_boxes` — edge devices keyed by a unique `motherboard_uuid`
- `downloads` — one logged fetch of a Revision file

Revision files live on the private `marketplace` disk (`config/marketplace.php`), not in the Vault.

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF endings, 4-space indentation, and no trailing whitespace. Use `2` spaces in YAML files. PHP code should follow Laravel conventions and be kept Pint-clean. React/TypeScript files use PascalCase for components, camelCase for functions and variables, and descriptive names that match the feature area, such as `resources/js/Pages/Vault/Index.tsx`.

## Testing Guidelines

PHPUnit is configured in `phpunit.xml`; feature tests live in `tests/Feature` and unit tests in `tests/Unit`. Use descriptive names like `VaultFolderTest.php` or `AuthenticationTest.php`. Prefer feature tests for controller, policy, and workflow coverage. See `wiki/architecture/testing.md` for details.

- `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`; the whole suite runs against SQLite in-memory, no external database service required. Production runs PostgreSQL. CI runs the suite on both SQLite and a real PostgreSQL 17 service to catch dialect differences (see `app/Support/DateBucket.php` for the one unavoidable one).
- Before the PostgreSQL migration, this suite could not actually run: every model pinned the `mongodb` connection, so the SQLite override only changed the default connection, and `config/database.php` fed the literal `:memory:` string to the Mongo driver, which rejected it — all 97 tests errored at setup. This is fixed now; the suite passes 97/97 (245 assertions) on both dialects.
- `tests/TestCase.php` creates the `public/hot` file in `setUp` and removes it in `tearDown` to bypass `ViteManifestNotFoundException`.

## LLM Wiki & Knowledge Base Management

A persistent, LLM-maintained knowledge base lives in the `wiki/` directory. This is the single source of truth for architectural context and module guidelines.

**CRITICAL INSTRUCTION FOR ALL AGENTS:**
You **MUST proactively construct and update the `wiki/`** immediately whenever you introduce new features, routing patterns, database schemas, API integrations, dependencies, significant design decisions, or architectural changes. Do not leave the system in an undocumented state; knowledge MUST be persisted here across operational runs.

### Automatic Retrieval Protocol
To effectively reference this knowledge in future runs and avoid redundant analysis:
1. **Initialize**: Read `wiki/index.md` at the start of complex requests to map out the current structure and available documentation.
2. **Navigate**: Follow links in the index to detailed breakdowns under `wiki/architecture/`, `wiki/database/`, `wiki/frontend/`, and `wiki/modules/`.
3. **Comply**: Read `wiki/SCHEMA.md` to understand formatting rules, update conventions, and query mechanics.
4. **Log**: Append a dated entry to `wiki/log.md` after making documented adjustments, using the format defined in `wiki/SCHEMA.md` (`## [YYYY-MM-DD] <operation> | <title>`).

### Key Wiki Files
- `wiki/index.md` — master content catalog with links to every page (start here).
- `wiki/SCHEMA.md` — how to ingest sources, query, update, and lint the wiki.
- `wiki/log.md` — append-only history of wiki operations.
- `wiki/overview.md` — project summary, capabilities, and key numbers.

Pages are organized under `wiki/architecture/`, `wiki/database/`, `wiki/frontend/`, and `wiki/modules/`. Cross-references use standard Markdown links (e.g. `[Vault](modules/vault.md)`, `[Request Flow](architecture/request-flow.md)`).

## Commit & Pull Request Guidelines

Recent commits use conventional-style prefixes with optional scopes, for example `fix(tests): ...`, `feat(vault): ...`, or `style: ...`. Keep commit subjects short and specific. Pull requests should describe the change, list any migration or seeding steps, and include screenshots for UI work. Link related issues when applicable and note any test commands you ran.

## Security & Configuration Tips

Do not commit secrets or environment-specific values. Local setup expects `.env`, PostgreSQL credentials, and a valid app key. If you change upload, auth, or AI-related code, call out any new permissions, queue jobs, or environment variables in the PR notes.

## Agent skills

### Issue tracker

Issues live as GitHub issues on `DoubleTime/untitled-cms`, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root (created lazily by `/domain-modeling`). See `docs/agents/domain.md`.

## Building Code: Delegate to a Subagent

When the task is **writing or changing code** (new features, refactors, bug fixes, migrations, tests), do not implement it inline. Dispatch a subagent with the `Agent` tool and pick the model by difficulty.

This applies to implementation work only. Answering questions, reading code, planning, reviewing, and one-line edits stay inline.

### Model selection

Pass `model` explicitly on every dispatch — never rely on the default.

**Use `opus`** when any of these hold:
- Touches the service layer (`app/Services/`), the vault pipe pipeline (`app/Vault/Pipes/`), permissions (`Role::availablePermissions()`, policies, `CheckPermission`), or middleware ordering in `bootstrap/app.php`
- Security-relevant: `SafeHttpClient`/SSRF paths, `HtmlSanitizer` profiles, upload validation, auth or Socialite flows, email webhook handlers
- Spans backend + frontend + migration together, or changes more than ~5 files
- Database schema changes, or anything that must work on both SQLite (tests) and PostgreSQL (production)
- Architecture is unsettled — the approach itself is part of the work

**Use `sonnet`** when the work is mechanical and the shape is already decided:
- Single Inertia page or React component under `resources/js/`
- Adding a controller action that follows an existing sibling exactly
- Writing tests against an interface that already exists
- Copy changes, Tailwind/styling, Pint-only formatting passes
- Mapping a settings key through `SettingsService` following an existing key

When genuinely between the two, pick `opus`.

### Dispatch rules

- Give the subagent the full task, not a fragment: the acceptance criteria, the files it may touch, and the relevant conventions from this file.
- Point it at `wiki/index.md` and the module page for the area it is changing.
- Require it to run `./vendor/bin/pint` for PHP changes and `npm run build` (which runs `tsc`) for TypeScript changes before reporting done.
- Require it to report which tests it ran and their actual output. Do not accept "tests pass" without it.
- Run independent subagents in parallel in a single message. Do not fan out subagents that would edit the same file.
- Review the subagent's diff yourself before reporting completion. The subagent's report is a claim, not a verification.
