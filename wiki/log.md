# Wiki Log

Append-only record of wiki operations. Format: `## [YYYY-MM-DD] <op> | <title>`

---

## [2026-09-22] update | Marketplace Phase 1 (Foundation)
- Added [modules/marketplace](modules/marketplace.md): the Unysis Marketplace catalogue —
  vocabulary pointer to `CONTEXT.md`, the nine new tables, the polymorphic Revision design,
  the new permission groups, the private `marketplace` disk, the access model from the three
  ADRs, and the five phases still outstanding.
- Schema: `database/migrations/2026_09_22_000001_create_marketplace_tables.php` creates
  `customers`, `machine_brands`, `machine_models`, `scripts`,
  `script_images`, `ai_models`, `revisions`, `unysis_boxes`, `downloads`, and adds a
  nullable indexed `users.customer_id`. Repo conventions held: ULID keys, indexed reference
  columns, no FK constraints, soft deletes on the two catalogue entry tables. Status columns
  are plain strings with a default so the DDL runs unchanged on SQLite and PostgreSQL.
- Models: nine plain Eloquent models plus `App\Models\Concerns\HasRevisions`
  (`revisions()`, `latestReleasedRevision()`, `downloads()`), shared by `Script`
  and `AiModel`. `User` gained `customer()` and `isCustomerUser()`.
- Permissions: 26 new strings appended to `Role::availablePermissions()` in five groups.
  `RoleSeeder` syncs admin from that list, so admin picked them up with no enumeration; it
  also seeds a `customer` role with no permissions and `backend_access = false`.
- Naming trap worth remembering: `App\Models\AiModel` (a marketplace catalogue entry) is
  unrelated to `App\Models\AiHub` (the CMS's own AI provider config).
- Also updated (outside the wiki, same commit): `AGENTS.md` gained a `### Marketplace`
  list under Database; `config/marketplace.php` and a private `marketplace` disk in
  `config/filesystems.php`; `.env.example` gained the three `MARKETPLACE_*` keys.
- Suite: 142 tests / 360 assertions green on SQLite in-memory (16 of them new).

## [2026-09-21] update | PostgreSQL migration documented (Task 8)
- Documents the completed move off MongoDB: all 16 models are now plain Eloquent
  (`HasUlidKey` trait for ULID string primary keys), a full relational schema was
  authored from scratch in `database/migrations/` (5 files, grouped by responsibility:
  core, content, vault, logs, framework), and `mongodb/laravel-mongodb` is gone.
- Added [architecture/datastore](architecture/datastore.md): ULID keys and the
  frontend `id: string` contract, schema file layout, why reference columns carry
  indexes but no FK constraints, why `role_user` exists (SQL needs a pivot where
  MongoDB used ID arrays), and `app/Support/DateBucket.php` as the one place the
  SQLite-vs-PostgreSQL dialect difference is allowed to live.
- Recorded an honest correction: before this migration the test suite could not run
  at all. `phpunit.xml` set `DB_DATABASE=:memory:` while every model pinned the
  `mongodb` connection; `config/database.php` fed that literal string to the Mongo
  driver, which rejected it, so all 97 tests errored at setup. `architecture/testing.md`
  had claimed "SQLite override" for months without that claim ever being exercised.
  Now: SQLite in-memory for tests, real PostgreSQL in CI and production, 97/97 tests
  (245 assertions) green on both.
- Marked [architecture/mongodb](architecture/mongodb.md) superseded/historical rather
  than deleting it — it still explains the original trade-offs and the test gap that
  triggered the move.
- Updated [architecture/stack](architecture/stack.md), [architecture/testing](architecture/testing.md),
  [database/collections](database/collections.md) (renamed conceptually from "MongoDB
  collections" to "Eloquent tables"; added `role_user`, `.env` block, no-FK note),
  and `wiki/index.md`.
- Also updated (outside the wiki, same commit): `AGENTS.md` (the canonical source
  `CLAUDE.md` forwards to — stack/database/testing sections), `.env.example` (removed
  a dead MongoDB Atlas URI comment block), `composer.json` description, seeded page
  copy in `database/seeders/ContentSeeder.php`, and a stale MongoDB-rationale comment
  in `app/Http/Middleware/CheckMaintenanceMode.php`.

## [2026-09-13] fix | Page save, editor sync and Vault size label
- Found during browser smoke tests of the upgrade; all three predate it.
- `PageController` store/update: empty editor content arrives as `null` (ConvertEmptyStringsToNull) and crashed `clean()`.
  Store now cleans `content ?? ''`. Update only cleans `content` when it was submitted, so partial updates keep existing content.
- `Components/Editor.tsx`: the TinyMCE textarea id was regenerated on every render, so each render re-initialised the editor
  and leaked instances (500+ seen). The id is now a stable `useRef`, and cleanup removes the editor instance by id.
  Content syncs on `input change undo redo` (not `SetContent`, which would mark forms dirty on load).
- `VaultController::adminPage` sends `maxUploadSize` in MB (the dialog's unit): the smallest of `upload_max_filesize`,
  `post_max_size` and `vault.max_upload_kb`, ignoring 0/-1 ini values.
- New tests: empty-content create/update and omitted-content update in `PageControllerTest`, and the Vault MB prop in `VaultUploadTest`.
- Added `config/inertia.php` setting `pages.paths` to `resources/js/Pages`. Inertia v3 defaults to lowercase `js/pages`,
  which only matches on case-insensitive filesystems. `assertInertia()->component()` failed on Linux CI while passing on macOS.

## [2026-09-13] update | Dependency upgrades completed
- Executed all 7 phases of `docs/dependency-upgrade-plan.md` (see its Outcome section). Final gate is green:
  93 tests on PHPUnit 13, `tsc` and Vite 8 build clean, both audits clean.
- New conventions: admin tables use TanStack v9 `useTable` with the shared `dataTableFeatures` and `DataTableColumnDef<T>`
  from `Components/Common/DataTable.tsx`. Inertia shared props are typed via `InertiaConfig.sharedPageProps` in `types/global.d.ts`.
  `OptimizeVaultImageJob` uses Laravel's `Image` facade.
- `package.json` carries an `overrides` pin for `@babel/plugin-transform-runtime` (`^7.29.0`) so Vite 8's React plugin
  installs next to the shadcn CLI. Node requirement is now 22.12+.
- Updated [architecture/stack](architecture/stack.md), [frontend/ui-stack](frontend/ui-stack.md) and
  [modules/vault](modules/vault.md). The Vault page's "Intervention Image" claim for `SanitizeImage` was wrong (it uses GD) and is corrected.

## [2026-09-13] ingest | Dependency upgrade plan
- Added `docs/dependency-upgrade-plan.md`: 7 staged phases. (1) npm audit + in-range updates, (2) `laravel/ai` 0.11,
  (3) Intervention 4 via `Illuminate\Image`, (4) Vite 8 toolchain, (5) Inertia v3, (6) PHPUnit 13, (7) TanStack Table 9, lucide 1.x, react-dropzone 20, and other npm majors.
- Framework already at latest (v13.31.0). Baseline: 91 tests green, zero PHPUnit 12.5 deprecations, `tsc` clean.
- Blocked: Guzzle 8 (`league/oauth1-client` 1.11 caps at `^7`). Deferred: TypeScript 7 (no compiler API; `types: []` default).
- Found: Laravel 13's `Illuminate\Image` drivers require Intervention `^4`. `OptimizeVaultImageJob` will move onto the `Image` facade.
- Wiki pages to update as phases land: architecture/stack, frontend/ui-stack (Vite 7 mentions), modules/vault, modules/ai-hub, architecture/testing.

## [2026-09-13] release | 0.5.1
- Security patch after 0.5.0's CI Security Audit failed (`composer audit --no-dev` exits 1 on any advisory).
- Updated `laravel/framework`, `league/commonmark`, `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `phpseclib/phpseclib`,
  `mongodb/mongodb` (2.4.2), `mongodb/laravel-mongodb` (5.11.0).
- **New platform requirement:** `mongodb/mongodb` 2.4 needs `ext-mongodb ^2.4`, now declared in `composer.json`.
  CI (`setup-php`) and the Dockerfiles install the latest extension unpinned, so they're unaffected; local and
  self-hosted installs must upgrade the extension. On Homebrew PHP, `pecl upgrade` fails if `php.ini` already
  loads `mongodb.so` — comment the line out, `pecl install -f mongodb`, and pecl re-adds it.
- README/`docs/deployment.md` requirement rows now say `mongodb` (2.4+).

## [2026-09-13] release | 0.5.0
- `package.json` / `package-lock.json` bumped to 0.5.0. `composer.json` intentionally carries no `version` (Packagist reads tags).
- CHANGELOG `[Unreleased]` promoted to `[0.5.0] — 2026-09-13`; README "What's New" section and current version updated.
- CHANGELOG compare links fixed: tags are un-prefixed (`0.4.0`), so the old `v0.x.0` links returned 404.
- Release ships migration `2026_09_13_093743_add_unique_index_to_vault_folders` — upgraders must run `php artisan migrate`.

## [2026-09-13] update | README and CHANGELOG synced with changes since 0.4.0
- README gained a "What's New Since 0.4.0" section; CHANGELOG `[Unreleased]` populated from the 14 commits since the tag.
- README claims corrected against code: pipeline is 6 stages + optional `SandboxedScan` (inserted after `ValidateMimeType`);
  no scheduled publishing (moved to Planned); Menus use nested items with up/down ordering and Banners use an `order`
  field (the data-table drag handle does not persist); Node >= 20 / npm >= 10; `mongodb/laravel-mongodb ^5.7`;
  `ezyang/htmlpurifier` replaces `mews/purifier`; Recharts `^3`; added Resend, AI Assistant, Email, TinyMCE, Windows installer.
- `overview.md` permission count corrected to 31. `AGENTS.md` social login corrected to Google + GitHub only.
- Open issue: `install.sh` / `install.ps1` still accept PHP >= 8.2 while `composer.json` requires `^8.4`.

## [2026-09-13] refactor | Consolidated agent config into AGENTS.md
- `AGENTS.md` is now the single source of truth for all agents; it absorbed CLAUDE.md's architecture,
  services, permissions, middleware, Inertia props, frontend, routes, and database sections.
- `CLAUDE.md` and `GEMINI.md` are stubs that import `@AGENTS.md`; the three duplicated wiki-protocol blocks were merged.
- Conflicts resolved: wiki-update triggers unioned; log step now points to the SCHEMA.md entry format;
  tests live in both `tests/Feature` and `tests/Unit`.
- Drift fixed against code: policy count (was "8", now 10 — count removed), permission count (was "~32", 31 — count removed),
  unverifiable local Mongo port 27018 dropped (CI and `.env.example` use 27017), added `admin` middleware alias,
  conditional `SandboxedScan` pipe, `HtmlSanitizer` + `EmailWebhooks/` services, `/media` and user-batch throttles,
  `auth.user` field subset, and the 300s `active_menus` cache.
- `SCHEMA.md` convention now says "don't duplicate AGENTS.md" instead of CLAUDE.md.

## [2026-09-13] update | Impact review fixes: menus, vault policies, folder index
- `MenuController` item rules now match the real `{id, title, url, target, order, subItems}` shape.
  Rules for `label`/`children` rejected every save and, with `excludeUnvalidatedArrayKeys`, stripped item data.
- `VaultFolderPolicy::create` requires `media.create` even with a parent. New `forceDelete` requires `media.delete`.
- `saveAiImage` authorizes the target folder. Folder restore checks for name collisions.
- Unique folder index migration guards against "already exists" and duplicate-key errors. Folder writes map a lost race (11000) to a 422.
- `folders.list?all=1` returns the full tree (nested folders were invisible). `useVaultBrowser` debounces search,
  drops stale responses, resets alt text on selection, and batch-restores via one request.
- Batch `uuids` are capped at 500. `saveAiImage` always removes its temp file.
- Corrected the long-standing "tests use SQLite in-memory" claim. Only the default connection is SQLite; models pin `mongodb`,
  so tests need the MongoDB server from `.env` (the local Docker container on 27018).
- Updated [modules/permissions](modules/permissions.md), [modules/vault](modules/vault.md),
  [database/collections](database/collections.md), [architecture/testing](architecture/testing.md), and [frontend/ui-stack](frontend/ui-stack.md).

## [2026-09-05] ingest | Search discoverability baseline (Day 0)
Digested `docs/seo-discoverability-plan.md` and executed Step 1. Created
[discoverability](discoverability.md) with the Day-0 baseline: 0 stars, no topics, empty homepageUrl,
no Packagist package, Pages not enabled, and **0 Google-indexed pages** for the repo
(verified in a real browser, not a search API).
Key correction to the plan: the repo page is unindexed but the project *is* surfacing
through proxies — trendshift.io, the `watchtower` org page and the `NavanithanS` user
page all rank for `"Untitled CMS"` carrying the repo description.
Plan §3 superseded: competing `Untitled CMS` projects now exist (`tar6/Untitled-CMS`,
hirammendiola.com). New finding: the `watchtower` org name collides with
`containrrr/watchtower`.
Plan §7 both flagship keywords dropped after validation (`laravel mongodb cms` is a
driver-docs query; `ai native cms` is now vendor-owned). Surviving surface is the
llms.txt / Markdown-for-agents angle.
Staged: README lead paragraph moved above the badge block; `composer.json` gained
`homepage`/`support`, lost its hardcoded `version` (repo has git tags).

## [2026-07-15] update | Impact sentinel + code review hardening follow-ups
- emptyTrash: per-file `forceDelete` only; avoid Mongo `chunk()` SortDirection bug.
- Batch vault FormRequests use `Rule::exists(Model::class)`; batch actions require `viewAny`.
- save AI image uses named route `admin.vault.save-ai-image`; SaveAiImage policy create.
- VaultFolderController uses `$this->authorize`; upload uses folder-scoped create.
- useVaultBrowser: no double-fetch on trash/root; shift-select sets lastSelectedFile.
- 87 PHPUnit tests green; npm build green.

## [2026-07-14] update | Hardening epic complete
- Implemented `AiHttpClient`; `AiService` no longer uses raw `Http::`.
- Policy-first Vault + public draft preview (`viewAny` on Page).
- Vault FormRequests; Banner/Menu unique rules use `Rule::unique(Model::class)`.
- Vault FE: `useVaultBrowser`, `VaultDialogs`, `VaultFolderInfoPopover`.
- Tests: PublicPageMarkdown, Menu, Banner, AiChat, AiHttpClient unit; suite green.
- Plan marked complete: `docs/hardening-entropy-implementation-plan.md`.

## [2026-07-12] update | Hardening epic: docs truth, Mongo ADR, HTTP tiers, policy-first
- Aligned README/wiki with code: PHP 8.4+, TinyMCE, 32 permissions, multi-provider AI, Vault pipeline wording.
- Added [architecture/mongodb](architecture/mongodb.md) decision page; linked from index + stack.
- Documented two-tier outbound HTTP (`SafeHttpClient` vs `AiHttpClient`) in services + ai-hub.
- Documented policy-first controller convention in permissions.
- Added SCHEMA “Source of truth” anti-drift table.
- Implementation plan: `docs/hardening-entropy-implementation-plan.md`.

## [2026-06-28] update | AI Chat Resilience & Shadcn Primitives
- Documented adoption of shadcn/ui chat primitives in `frontend/ui-stack.md`.
- Updated `modules/ai-hub.md` to reflect proper context usage of chat sessions.
- Documented AI chat rate limits and HTTP 429 2-second retry logic in `modules/ai-hub.md`.

## [2026-06-13] update | SCHEMA.md Release Operation
Added the `Release` operation to `SCHEMA.md` to ensure `CHANGELOG.md` is updated on every new release.

## [2026-06-13] update | Security Sweep & Vault Performance Fixes
Completed a comprehensive security audit using Sentinel and Code Reviewer standards:
- **SSRF / DNS Rebinding**: Hardened `SafeHttpClient` using `CURLOPT_RESOLVE` to pin IP addresses and prevent DNS rebinding attacks on outbound requests.
- **XSS Protection**: Installed `rehype-sanitize` in `AiChatSidebar.tsx` to safely render AI-generated markdown, neutralizing Stored XSS vectors.
- **Authorization**: Fixed a bypass in `AiActionController` by dynamically authorizing resolved models for updates. 
- **Policy Refactor**: Resolved dummy-model anti-patterns in `UserPolicy` by introducing dedicated `batchUpdate` and `batchDelete` gate methods.
- **Performance**: Fixed a severe O(N²) exponential recursion query bug in `VaultService::purgeFolder` by strictly looking up descendants via `parent_id` rather than wildcard `path_slug` matching.

## [2026-06-08] feat | Upgrade integrations for Laravel 13.8/13.14 features
- Applied Laravel 13.8.0 `shouldRenderJsonWhen` exception feature in `bootstrap/app.php` with custom API route bypassing.
- Integrated Apple `passwordrules` autocomplete hints via Inertia shared props into React Auth/User forms.
- Migrated `ProcessEmailWebhook` to the new `Interruptible` contract for clean daemon teardowns.
## [2026-05-23] fix | Setting::get() cache serialization
Impact Sentinel detected `Setting::get()` caching full Eloquent model objects, which broke under
the new `serializable_classes => false` config (incomplete object warnings on every request).
Refactored to cache the coerced scalar value instead of the model instance. Flushed stale cache.
Also bumped `laravel/framework` from v13.4.0 → v13.11.2 (latest). 56 tests pass, zero warnings.

## [2026-05-23] update | Sync with laravel/laravel v13.7.0 Skeleton
Applied skeleton changes from v13.0.0→v13.7.0:
- Added `laravel/pao ^1.0.6` (PHP Agent-Optimized Output) — auto-formats PHPUnit/Artisan output as compact JSON when running inside AI agents.
- Created `.npmrc` with `ignore-scripts=true` and `audit=true` (v13.1.2 security hardening against malicious postinstall scripts).
- Updated `.gitignore`: removed `/.fleet`, added `/.codex`, `/.cursor/`, `/public/fonts-manifest.dev.json`, `_ide_helper.php`.
- Updated `.editorconfig`: compose file glob now covers `docker-compose.{yml,yaml}` naming variants.
- Deferred: `@no_additional_args` in composer test script (requires Composer ≥2.8; we're on 2.7.6).
- Deferred: Vite font plugin migration (v13.5.0) — we use `@fontsource-variable/*` packages instead.

## [2026-05-23] update | Laravel 13 Upgrade Hardening
Verified Laravel 13.4.0 framework compatibility and applied recommended upgrade changes:
- Bumped `phpunit/phpunit` from `^11.5.3` to `^12.0` (locked at 12.5.26). All 56 tests pass.
- Added `serializable_classes => false` to `config/cache.php` for deserialization attack hardening.
- Verified no `new static()` in model boot methods, no `Str::createUuidsUsing` in tests.
- Cache prefix already uses L13 hyphenated format. `PreventRequestForgery` middleware is active via framework defaults.
- `nunomaduro/collision ^8.6` remains compatible. `laravel/ai ^0.5` (v0.5.1) still pre-release.

## [2026-05-23] update | Shadcn Preset & React 19
Updated UI stack documentation and dependencies:
- Upgraded React to v19 in package.json and reflected the change in CLAUDE.md, wiki/architecture/stack.md, and wiki/frontend/ui-stack.md.
- Initialized Shadcn with the custom b2fA preset and unified radix imports.
- Created a baseline restore point for the Tailwind v4 + React 19 + b2fA UI state.

## [2026-05-23] update | Tailwind v4 & Shadcn Preset Migration
Migrated the front-end style stack from Tailwind CSS v3 to v4 and updated the shadcn setup:
- Run `@tailwindcss/upgrade` to move dependency packages and translate stylesheet settings to native CSS `@theme`.
- Unified 20 individual `@radix-ui/react-*` dependencies under a single `radix-ui` library.
- Updated `components.json` and regenerated/overwrote all 36 components under `resources/js/Components/ui/` with modern v4 registry code.
- Fixed TypeScript errors in `resizable.tsx` and `UploadPipelineTracker.tsx`.
- Updated [frontend/ui-stack](frontend/ui-stack.md) with the new stack information.

## [2026-05-23] update | Media Vault Security Gates & Scaling
Improved security, query performance, and scaling across the Media Vault:
- Added sibling unique name checks on folder create, rename, and move to prevent path/slug collisions.
- Added destination folder authorization checks to prevent folder move privilege bypasses.
- Added strict fail-closed toggle options on ClamAV scanner timeouts or daemon connectivity outages.
- Eager-loaded the folder relationship in file listings to resolve N+1 database queries.
- Optimised the Artisan purge command via chunking to maintain a low and constant memory footprint.
- Added client memory protection in upload dialog, bypassing pre-upload hashing on files larger than 10MB.
- Updated [modules/vault](modules/vault.md) to document the dynamic scanning stages and fail-closed options.

## [2026-04-13] feat | Media Vault Hardening & Optimizations
Implemented [docs/vault-improvements-plan.md](../docs/vault-improvements-plan.md).
- Phase 1: Cascade slugs and physical relocation for folder rename/move in `VaultService`.
- Phase 2: Added `PruneVaultSandbox` job and `vault:purge` Artisan command.
- Phase 3: Added CDN-friendly `/media/{uuid}` route and RFC 7232 headers for caching.
- Phase 4: Added batch-restore, empty-trash, and folder force-delete endpoints.
- Phase 5: Implemented client-side SHA-256 duplicate detection with `VaultUploadDialog` warnings.
- Phase 6: Created `useVaultPicker` React hook for global media selection.


## [2026-04-12] feat | OpenRouter & AI Hub Security Refactor
Integrated OpenRouter as a supported AI provider for text generation and vision. 
Implemented `clear_key` explicit API key revocation UI within the AI Hub dashboard.
Optimized `AiContextService` to prevent unconditional database context loading out of scope.

## [2026-04-06] refactor | Multi-provider Email Abstraction
Abstracted all email provider logic into [Services/EmailWebhooks/Contracts/WebhookProvider](Services/EmailWebhooks/Contracts/WebhookProvider.md).
Created Support for Resend (Svix), Mailgun (HMAC), and SendGrid (ECDSA).
Renamed `resend_id` → `provider_message_id` across `email_logs` and updated indexes.
Unified webhook endpoint to `/webhooks/email` with generic middleware/job.
Restored legacy SDK compatibility while centralizing config under `services.email_webhook`.

## [2026-04-05] init | Wiki created from CLAUDE.md seed
Scaffolded wiki structure. Created SCHEMA.md, index.md, log.md, and 9 seed pages
derived from CLAUDE.md: overview, architecture, services, vault-pipeline, permissions,
frontend, database, middleware, ai-hub, testing.

## [2026-04-05] update | Resend email integration implemented
Created [modules/email](modules/email.md): full documentation of the listener pipeline
(StopSuppressedEmail → InjectUnsubscribeHeaders → LogSentEmail), webhook
verification, suppression model, unsubscribe flow, stats caching, and
EmailLogPolicy registration. Updated [database/collections](database/collections.md) with email_logs
and suppressed_emails. Updated [architecture/middleware](architecture/middleware.md) with resend.webhook
alias and event auto-discovery note (withEvents discover:false).

## [2026-04-05] ingest | Resend.com mail integration brainstorm
Digested `docs/brainstorm-resend-integration.md`. Key decisions captured: credentials
in `.env` only (RESEND_KEY, RESEND_WEBHOOK_SECRET), MongoDB TTL 90d (EMAIL_LOG_TTL_DAYS),
webhook handler queued as ProcessResendWebhook job (updateOrCreate race-safety), CTR as
primary AI signal, HMAC-signed unsubscribe tokens, List-Unsubscribe header required.
New wiki page to create: [modules/email](modules/email.md) once implementation begins.

## [2026-09-21] update | Agent workflow config: subagent delegation + skill docs
Added `## Agent skills` block to AGENTS.md (GitHub issue tracker via `gh`, single-context
domain docs) with `docs/agents/issue-tracker.md` and `docs/agents/domain.md`. Added
`## Building Code: Delegate to a Subagent`: implementation work dispatches to a subagent
with an explicit model — `opus` for services/vault pipes/permissions/middleware, security
paths, multi-layer or schema changes; `sonnet` for mechanical single-file work. Subagents
must run Pint / `npm run build` and report real test output; the dispatcher reviews the diff.

## [2026-09-22] ingest | Unysis Marketplace domain model and plan
- Added `CONTEXT.md` (glossary), `docs/adr/0001–0003`, `docs/marketplace-plan.md`. No code yet.

## [2026-09-22] update | Marketplace Phase 2: Customers, Customer Users and Machines admin

Shipped the Phase 2 admin described in `docs/marketplace-plan.md`. Added
`Marketplace\{MachineBrand,MachineModel,Customer,CustomerUser}Controller`, `CustomerPolicy`,
`MachineBrandPolicy`, `MachineModelPolicy` (registered in `AppServiceProvider`), seven form requests
under `app/Http/Requests/Marketplace/`, and the routes under `admin.marketplace.*`. Deletes refuse
rather than cascade when dependents exist. Inertia pages live in `resources/js/Pages/Marketplace/`;
the sidebar gained a Marketplace section gated per `.view` permission, and `HandleInertiaRequests`
now shares `flash` so those refusals surface as toasts.

Customer Users get only the `customer` role plus `customer_id`; the invite path sends a password
reset. `User` now uses Sanctum's `HasApiTokens`, which needed a `personal_access_tokens` migration
with a string `tokenable_id` (ULIDs) and `'user' => User::class` in the enforced morph map.

Web login is now closed to them: `LoginRequest::isRpaToolOnly()` drives a rejection in both
`LoginRequest::authenticate()` and `SocialAuthController::callback()`. Details in
[modules/marketplace](modules/marketplace.md); see also [permissions](modules/permissions.md).

## [2026-09-22] update | Marketplace Phase 3: Catalogue admin, Revisions and Downloads

Shipped the Phase 3 catalogue admin from `docs/marketplace-plan.md`. Added
`App\Services\Marketplace\{RevisionService,DownloadService}`,
`App\Exceptions\Marketplace\InvalidRevisionTransition`,
`Marketplace\{Script,AiModel}Controller`, `ScriptPolicy`, `AiModelPolicy`
(registered in `AppServiceProvider`), six form requests, and the `admin.marketplace.scripts.*` /
`admin.marketplace.ai-models.*` routes, with `throttle:30,1` on the Revision upload routes.

`RevisionService` validates extension (keyed by `getMorphClass()`), double extension, size cap and
magic bytes (`PK\x03\x04` / `\x89HDF\r\n\x1a\n`), optionally scans with ClamAV, numbers
`max + 1` per revisable inside a transaction with a single retry, and stores on the private
`marketplace` disk at `{alias}/{id}/{number}.{ext}` with the SHA-256 recorded. The lifecycle is one
way: `draft -> released -> deprecated`, `InvalidRevisionTransition` otherwise.

The clamd implementation was extracted from `App\Vault\Pipes\SandboxedScan` into
`App\Services\ClamAvScanner` so the Vault pipeline and the Marketplace share one copy; the pipe now
injects it and keeps its old behaviour, and the Vault tests still pass unchanged — see
[modules/vault](modules/vault.md).

Front end: `Pages/Marketplace/{Scripts,AiModels}/{Index,Create,Edit,Show}.tsx` plus shared
`Components/Marketplace/{RevisionsTable,RevisionUploadDialog,RevisionStatusBadge,DownloadsTable,PreviewImagesManager,CatalogueFilters}`.
Preview Images reuse the global Vault picker with `@dnd-kit` ordering (first image is the cover).
Sidebar entries gated on `scripts.view` / `ai_models.view`; new types in `resources/js/types/index.d.ts`.

`bootstrap/app.php` now turns `PostTooLargeException` into a flash `error` — the app cap is enforced by
`StoreRevisionRequest`, but `upload_max_filesize` and `post_max_size` must still be raised on the VPS.

Tests: `RevisionServiceTest`, `ScriptControllerTest`, `AiModelControllerTest`,
`ScriptImagesTest` (61 new cases). Full suite 251/251, 614 assertions.
Details in [modules/marketplace](modules/marketplace.md) and [modules/services](modules/services.md).

## [2026-09-22] update | Marketplace Phase 4: RPA-TOOL API

Shipped the Phase 4 RPA-TOOL API from `docs/marketplace-plan.md`. Added `routes/api.php` (registered
in `bootstrap/app.php` with `withRouting(api: ..., apiPrefix: 'api')`), everything under
`/api/v1` with route names `api.v1.*`.

`App\Services\Marketplace\UnysisBoxService` auto-registers an UNYSIS Box from the motherboard UUID a login
reports, under the Customer User's Customer, as `pending` with `first_user_id`; it refuses a box
that belongs to another Customer (`UnysisBoxBelongsToAnotherCustomer`) or has been blocked
(`UnysisBoxBlocked`), both 403, and throttles the `last_seen_at` write to once a minute.

`App\Http\Middleware\ResolveUnysisBox` (alias `unysis-box`) runs after `auth:sanctum` on every
authenticated route: it resolves the box from the **token name** (the UUID the token was issued for),
re-checks the box, the Customer User and the Customer, and puts the box on the request as the
`unysis_box` attribute. That re-check is what makes blocking a box take effect immediately rather than
when its 30-day token expires.

Endpoints: `login` / `logout` / `me`, the `machine-brands` / `machine-models` / `customers` lookups,
and the five catalogue endpoints (`index`, `show`, `revisions`, `download`, `check-update`) for both
Scripts and AI Models, served by an abstract `CatalogueController` with two thin
subclasses and Eloquent API Resources in `app/Http/Resources/Api/V1/`. Draft Revisions and entries
with no released Revision are never exposed; downloads record a `Download` with source `api` and the
box from the request attribute, then stream with `X-Checksum-SHA256`.

Throttling uses named limiters in `AppServiceProvider` keyed on the **token id** (`rpa` 60/min,
`rpa-download` 20/min, `rpa-login` 5/min by IP), because Laravel's default buckets by user id and one
Customer User may run several boxes. `config/auth.php` gained an explicit `sanctum` guard.

Wrote `docs/api/rpa-tool-v1.md`, the endpoint reference for the RPA-TOOL developers (auth flow,
every request/response, error codes, throttles, checksum verification, the check-update loop).

Tests: `tests/Feature/Api/V1/{LoginTest,LogoutMeTest,ResolveUnysisBoxMiddlewareTest,CatalogueReadTest,DownloadTest,CheckUpdateTest}`
plus `tests/Unit/UnysisBoxServiceTest` (69 new cases). Full suite 320/320, 947 assertions.
Details in [modules/marketplace](modules/marketplace.md) and
[architecture/middleware](architecture/middleware.md).

## [2026-09-22] update | Marketplace Phase 5 — UNYSIS Boxes & Downloads admin

Added the UNYSIS Boxes admin and the Download log, closing the last build phase before the deferred
CMS strip. `UnysisBoxController` (index / show / edit / update / activate / block / unblock / destroy)
under `UnysisBoxPolicy` (`unysis_boxes.view` / `.edit` / `.block`, registered in `AppServiceProvider`), and
`DownloadController@index` behind `can:downloads.view`. Routes sit under the existing `marketplace`
prefix; the sidebar gains UNYSIS Boxes and Downloads, each gated on its `.view` permission.

Boxes are never created from the admin — RPA-TOOL registers them (docs/adr/0002) — and `update`
accepts only `name`, `location` and `machine_model_id`. Status moves only through the three explicit
actions. **Blocking deletes every Sanctum token named after the box's motherboard UUID**, so access
is cut in the same instant rather than on the next `ResolveUnysisBox` check; `destroy` refuses a box with
recorded Downloads, since Download rows are never deleted.

`UnysisBoxInstalledService` derives the Installed tab — the latest Download per catalogue entry per box.
It reduces the box's own (bounded) Download log in PHP rather than carrying a window function for
PostgreSQL and a self-join for SQLite, and because `max(id)` is not the latest row when the keys are
ULIDs. Five queries regardless of entry count; the writeup is in
[modules/marketplace](modules/marketplace.md).

The Download log is server-paginated at 50 a page with filters on source, Customer, UNYSIS Box, entry
type, entry name, user and date range, plus a summary strip (total, last 7 days, unique boxes, top 5
entries) computed over the filtered set in four grouped queries. Customer and entry-name filtering
both needed care: a Download carries no `customer_id` (it reaches one through its UNYSIS Box or its
Customer User), and no join is possible across two tables behind one morph column.
`App\Support\DownloadPresenter` resolves entry names `withTrashed()` per page so a row whose entry was
hard deleted still renders.

Also: a unique-UNYSIS-Box count beside the Download total on the Scripts and AI Models Show pages (header
and per Revision row, via a correlated `count(distinct unysis_box_id)` sub-select), an UNYSIS Boxes tab on the
Customer detail page, and `DownloadFactory` corrected to store the morph alias rather than the class
name. No migration was needed — every figure on these pages is derived.

Not done: no Marketplace cards on the Dashboard. That page has no data-driven stats grid to extend
(its cards come from a hardcoded `section-cards.tsx` with placeholder figures), so wiring real counts
belongs with the Phase 6 CMS strip.

Tests: `tests/Feature/Marketplace/{UnysisBoxControllerTest,UnysisBoxInstalledTest,DownloadControllerTest}`
(39 new cases), plus one case in `ScriptControllerTest` covering the new count columns.
Full suite 360/360, 1217 assertions; Pint clean; `npm run build` exits 0.

## [2026-09-22] update | Domain rename: UNYSIS Box, Script, Customer code

Three vocabulary corrections, applied to the existing Phase 1–5 migration file in place rather than
through alter/rename migrations (the marketplace tables had been rolled back on the dev database, so
the schema is recreated from scratch).

**Customer `name` → `code`.** `customers.code` is now a short, unique, uppercase key (e.g.
`INARI-123`); `company` remains the display label everywhere the UI shows a Customer. The plain
`index('name')` became `unique()`. Both Customer form requests normalise the input with
`prepareForValidation()` (`trim` + `strtoupper`) and validate `alpha_dash|max:32` plus a `unique`
rule that ignores the record on update, so a Customer can be saved without changing its own code.
`CustomerResource` and `AuthController::identityPayload` now expose `{id, code, company}`.

**AI Box → UNYSIS Box.** Table `ai_boxes` → `unysis_boxes`, column `downloads.ai_box_id` →
`unysis_box_id`, model/service/exception/policy/middleware/controller/request/page/component names,
the `ai-box` middleware alias (`unysis-box`), the request attribute (`unysis_box`), the admin routes
(`/admin/marketplace/unysis-boxes`, `admin.marketplace.unysis-boxes.*`, `{unysis_box}`), the
`ai_boxes.view|edit|block` permissions (`unysis_boxes.*`) and the API response key `ai_box` →
`unysis_box` in `login` and `me`. `motherboard_uuid` and `box_name` are unchanged request fields.
`AiModel` / `ai_models` are a different concept and were deliberately left alone.

**FlowChart Script → Script.** Tables `flowchart_scripts` → `scripts` and `flowchart_script_images`
→ `script_images`, column `flowchart_script_id` → `script_id`, the morph alias `flowchart_script` →
`script` (which also moves the Revision storage path prefix, harmless on a fresh disk), the
`allowed_extensions` config key, the model/policy/controller/request/resource/factory/test names,
and the `entry_type` values in the API and the Download log. The `scripts.*` permissions and the
`/admin/marketplace/scripts` and `/api/v1/scripts` routes already used the short name and did not
move.

`CONTEXT.md` now defines **Script** (avoid: FlowChart Script) and **UNYSIS Box** (avoid: AI Box).
`docs/adr/0002-*` was renamed to `0002-unysis-boxes-identify-by-motherboard-uuid-under-user-login.md`.

Verification: `migrate:fresh --seed` on the dev PostgreSQL succeeds; the admin role seeds with all 58
permissions from `Role::availablePermissions()`; Pint clean; `npm run build` exits 0; suite green at
362 tests / 1221 assertions (run per directory — a full single-process run trips the 120s
`max_execution_time` because every Inertia page render spends ~2s on a refused Inertia SSR connect
to `127.0.0.1:13714`; this predates the rename).


## [2026-09-23] update | Phase 6 — CMS strip, rebrand, dashboard, download reporting

The inherited CMS boilerplate is gone; the Marketplace is now the whole application.

**Removed.** Pages, Banners, Menus and Redirects — controllers, models, policies, form requests,
Inertia pages, factories and tests — together with the public surface they served: `PublicController`,
`FeedController` (`/rss`, `/feed`), `SitemapController` (`/sitemap.md`), `LlmsController`
(`/llms.txt`, `/llms-full.txt`), the `/{slug}` catch-all, `PublicLayout` and `Pages/Public/{Home,Page}.tsx`.
`Pages/Public/Unsubscribed.tsx` stayed — the email unsubscribe flow renders it. The
`Accept: text/markdown` negotiation and the whole llms.txt story went with them.

The AI Hub and AI chat/actions went too: `AiHubController`, `AiController`, `AiActionController`,
`AiContextController`, `ChatSessionController`, `AiService`, `AiActionService`, `AiContextService`,
`AiHttpClient`, the `AiHub` and `ChatSession` models, `AiHubPolicy`, `AiHubObserver`, `AiHubSeeder`,
`Pages/AiHub/*`, every `Components/Ai/*`, the `aiChatEnabled` and `tinymce_api_key` shared props,
`SaveAiImageRequest` + `VaultController@saveAiImage`, `GenerateMissingAltTextJob` +
`VaultController@generateMissingAltText` + the `vault:generate-alt-text` command, and the AI image
generation and AI alt-text UI in the Vault browser. `SafeHttpClient`, `HtmlSanitizer` and the global
`clean()` helper had no callers once that code left, so they went with it, along with
`config/purifier.php` and `config/openai.php`.

**Schema.** Nothing is deployed yet, so the migrations were edited in place rather than given drop
migrations: `2026_09_21_000002_create_content_tables.php` was deleted outright (`pages`, `banners`,
`menus`, `redirects`, `chat_sessions`) and `ai_hubs` was cut out of the core file. Five migration
files remain plus the Sanctum tokens table.

**Vault pipeline.** `ModerationCheck` called `AiService::moderateImage`, so it was removed from
`VaultService::upload()`. The pipeline is now `DetectDoubleExtension` → `ValidateMimeType` →
`SanitizeImage` → `GenerateUuid` → `StoreMetadata`, with `SandboxedScan` still spliced in after
`ValidateMimeType` when `vault.clamav_enabled`. The `vault_files.moderation_reason` column stays —
`SandboxedScan` writes the ClamAV verdict into it.

**Permissions.** `Role::availablePermissions()` lost `pages.*`, `banners.*`, `menus.*` and
`ai-integrations.*` and now holds **42** strings. `RoleSeeder` was rewritten around the catalogue:
`admin` (all 42), `editor` (catalogue + Vault), `author` (draft and upload, no release or delete),
a new `viewer` (read-only, backend access) and `customer` (no permissions, no backend access). The
dead `RolesAndPermissionsSeeder` was deleted, as were `ContentSeeder`, `MenuSeeder` and `AiHubSeeder`.

**Rebrand.** "Untitled CMS" / "Unysis Library" → **Unysis Marketplace** across `.env`, `.env.example`,
`config/app.php`, `composer.json`, the seeded `site_name`/`site_description`, and the sidebar header,
which now reads the version from the new `config('app.version')` (`1.0.0`, matching `package.json`)
through a new `appVersion` shared prop instead of a hardcoded `v0.2.0`.

**Root route.** `/` redirects to `admin.dashboard` for a signed-in Team Member with backend access and
to `login` for everyone else, with a comment noting a public landing page may replace it later.

**Dashboard.** `DashboardController` and `Pages/Dashboard.tsx` were rewritten from mock data to real
Marketplace figures: five permission-gated cards, a 30-day downloads chart on `DateBucket`, the last
ten Revisions and the last ten UNYSIS Boxes by `last_seen_at`. A panel the Team Member cannot see is
`null` in the props, not hidden in the browser.

**Reporting.** `App\Support\DownloadQuery` now holds the one filter builder shared by the Download
log, its new CSV export (`/admin/marketplace/downloads/export`, streamed and chunked) and the new
Usage report (`/admin/marketplace/reports/usage`, plus a per-section export). The report attributes a
Download to its Customer by left-joining `unysis_boxes` and `users` and grouping on
`coalesce(...)`; every figure is grouped SQL in the SQLite/PostgreSQL common subset.
`DownloadPresenter::entryNames()` gained Machine Model and Brand and now accepts any row carrying
`revisable_type`/`revisable_id`.

**Docs.** Rewrote `AGENTS.md` (project overview, service layer, middleware stack, shared props, route
structure, key tables, frontend libraries, model-selection guidance; the AI-Native Endpoints section
is gone), `README.md`, `wiki/overview.md`, `wiki/modules/services.md`,
`wiki/architecture/{middleware,request-flow,stack,testing,datastore}.md`, `wiki/database/collections.md`,
`wiki/frontend/ui-stack.md`, the anti-drift table in `wiki/SCHEMA.md`, and scoped fixes in
`wiki/modules/{vault,permissions}.md`. Extended `wiki/modules/marketplace.md` with the dashboard,
`DownloadQuery`, the CSV export and the Usage report. Deleted `wiki/modules/ai-hub.md` and
`wiki/discoverability.md` (wholly about the old public product's search surface). Marked Phase 6
shipped in `docs/marketplace-plan.md` and added a "Future: public landing page" note there.

Verification: `migrate:fresh --seed --force` succeeds on the dev PostgreSQL; `Role::availablePermissions()`
and the seeded admin role both count 42; every dashboard and report query was also executed against
PostgreSQL directly, not just SQLite; Pint clean; `npm run build` exits 0; suite green at 347 tests /
1316 assertions (down from 362 — the CMS tests went, `DashboardTest`, `DownloadExportTest` and
`UsageReportTest` arrived). `route:list --except-vendor` fell from 203 to 158 routes.

## [2026-09-23] update | Code-review fixes: logout gate, lookup semantics, service extraction, CatalogueAdminController

Applied the findings of a Standards + Spec review of the Marketplace work.

**Spec.** `POST /api/v1/logout` moved out of the `unysis-box` middleware group: it needs only
`auth:sanctum` + `throttle:rpa`, so a blocked box, a deactivated Customer User and a deactivated
Customer can all revoke their own token (everything else, `GET /me` included, still 403s).
`RevisionService`'s upload retry now fires only on a unique-constraint violation — `isUniqueViolation()`
reads `errorInfo[0]` for SQLSTATE `23505` / `23000` — and rethrows every other `QueryException` at
once. `Api\V1\LookupController::customers` and `machineModels` no longer filter `->active()`: both are
filter lists for entries that may still be labelled with an inactive Customer or Machine Model, so the
rows stay and `CustomerResource` / `MachineModelResource` carry `is_active` instead. An entry with no
released **and** no deprecated Revision now 404s from `show` and `revisions` with
`NO_RELEASED_REVISION`, consistent with the list hiding it. `cover_image_url` renamed to
`preview_image_url` throughout ("cover" is on the `_Avoid_` list for Preview Image in `CONTEXT.md`).
The Usage report's by-Customer section now matches its docblock: only Customers with a Download in the
range, plus one "No Customer (internal)" row when unattributed web downloads are in range.

**Standards.** Removed the redundant private `authorizeView()` from `DownloadController` and
`ReportController` — both routes already sit in the `can:downloads.view` group. Added
`App\Http\Controllers\Marketplace\CatalogueAdminController`, an abstract holding every Revision action,
restore, force delete and the shared payload builders; `ScriptController` and `AiModelController` keep
only what differs and thin typed overrides for implicit route model binding. Fixed the
`'script-script'` slug fallback typo. Moved business logic out of controllers into
`App\Services\Marketplace\{UsageReportService, DashboardStatsService}` and `DownloadService::statsFor()`,
and `git mv`'d `DownloadQuery` and `DownloadPresenter` from `App\Support` into `App\Services\Marketplace`.
`LoginRequest::isRpaToolOnly()` and `RPA_TOOL_ONLY_MESSAGE` became
`App\Services\Marketplace\CustomerUserGuard`, injected into the web login, Socialite, the API login and
`ResolveUnysisBox`. Added `App\Support\CatalogueEntryType` (constants `SCRIPT`, `AI_MODEL`, `ALL`, plus
`modelClass()`, `label()`, `fromModel()`) and used it in the presenter, the query builder, the dashboard
stats, `UnysisBoxInstalledService`, `StoreRevisionRequest`, `UpdateAiModelRequest`, the
`enforceMorphMap()` call and `config/marketplace.php`. Deduped `withVisibleRevisions()` /
`loadVisibleRevisions()` in `Api\V1\CatalogueController`.

**Docs.** Rewrote `CONTRIBUTING.md` against reality — PostgreSQL rather than MongoDB, plain Eloquent
with `HasUlidKey`, branch from `master`, PHP 8.4, the AGENTS.md command set, SQLite in-memory testing —
and dropped the `declare(strict_types=1)` rule (0 of 149 files complied), naming Pint as the style
authority instead. Updated `docs/api/rpa-tool-v1.md` (logout gate, lookup semantics, draft-only 404,
`preview_image_url`), `wiki/modules/marketplace.md` (bare `Last updated` date per `wiki/SCHEMA.md`),
`wiki/modules/services.md` and the AGENTS.md service and support lists.

Verification: Pint clean on every changed PHP file; `npm run build` exits 0; suite green at 358 tests /
1406 assertions (up from 347 — eleven new cases across `LogoutMeTest`, `CatalogueReadTest`,
`RevisionServiceTest` and `UsageReportTest`).
