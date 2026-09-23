# Marketplace

> Catalogue of AI Models and Scripts that run on UNYSIS Boxes, fetched by RPA-TOOL

Last updated: 2026-09-23 (Phase 6)

Vocabulary is fixed in [`CONTEXT.md`](../../CONTEXT.md) — use those terms verbatim in code, UI copy and docs.
The implementation plan is [`docs/marketplace-plan.md`](../../docs/marketplace-plan.md); decisions are in [`docs/adr/`](../../docs/adr).

**Status: Phases 1-6 complete — the Marketplace is the whole application.** Phase 1 gave the schema, models,
permissions, config and the private disk; Phase 2 added the Customers, Customer User and Machines
admin plus the web-login rejection; Phase 3 added the Scripts and AI Models admin,
`RevisionService`, `DownloadService`, the Revision lifecycle, Preview Images and soft/hard delete;
Phase 4 added `routes/api.php`, Sanctum login, UNYSIS Box auto-registration and every read + download
endpoint; Phase 5 added the UNYSIS Boxes admin, the Download log and the derived installed-revision view;
Phase 6 stripped the inherited CMS surface (pages, banners, menus, redirects, the public site and the
AI Hub) and replaced the boilerplate dashboard with real Marketplace stats, adding the Download CSV
export and the Usage report.
The endpoint reference written for the RPA-TOOL developers is
[`docs/api/rpa-tool-v1.md`](../../docs/api/rpa-tool-v1.md).

## What it is

UNYSIS Team Members publish two kinds of catalogue entry, both targeting one Machine Model:

- **AI Model** — a trained inference model published on its own (`.h5` files).
- **Script** — a packaged automation sequence bundling a flow definition with the AI models and libraries it needs (`.zip` bundles).

Each entry accumulates **Revisions**: immutable, sequentially numbered uploads with a change note.
Only `released` Revisions are offered to RPA-TOOL by default. **Customer Users** sign in from RPA-TOOL on an
**UNYSIS Box**, and every fetch is recorded as a **Download**.

## Tables

All ULID primary keys via `HasUlidKey`, every reference column indexed, no foreign key constraints —
see [architecture/datastore](../architecture/datastore.md). Migration:
`database/migrations/2026_09_22_000001_create_marketplace_tables.php`.

| Table | Purpose / notable columns |
|---|---|
| `customers` | code (unique), company, contact_*, notes, is_active |
| `users` (altered) | nullable indexed `customer_id` — set only for Customer Users |
| `machine_brands` | name **unique**, slug |
| `machine_models` | machine_brand_id, name, slug, description, is_active; **unique (machine_brand_id, name)** |
| `scripts` | machine_model_id, customer_id?, name, slug, description, created_by, `deleted_at` |
| `script_images` | script_id, vault_file_id, sort_order |
| `ai_models` | machine_model_id, customer_id?, name, slug, description, framework, input_size, labels, notes, created_by, `deleted_at` |
| `revisions` | revisable_type/_id, number, status, change_note, original_filename, disk_path, size_bytes, sha256, mime, uploaded_by, released_by, released_at, deprecated_at; **unique (revisable_type, revisable_id, number)** |
| `unysis_boxes` | customer_id, motherboard_uuid **unique**, name, location, machine_model_id?, status, last_seen_at, last_ip, first_user_id |
| `downloads` | revision_id, revisable_type/_id, user_id, unysis_box_id?, source, ip, user_agent |

Derived, never stored: download totals, unique-box counts, and the "installed revision" per box
(the latest download per box per entry).

Status columns are plain strings with a default — not native enums — so the same DDL runs on SQLite (tests)
and PostgreSQL (production). The repo uses no PHP backed enums; the allowed values live as class constants
(`Revision::STATUS_*`, `UnysisBox::STATUS_*`, `Download::SOURCE_*`).

## Models

`app/Models/`: `Customer`, `MachineBrand`, `MachineModel`, `Script`, `ScriptImage`,
`AiModel`, `Revision`, `UnysisBox`, `Download`. All plain Eloquent.

- `Revision` is polymorphic (`revisable()` morphTo) so both entry types share one revision/download
  implementation while staying separate entities in the UI and the API. `Relation::enforceMorphMap()`
  in `AppServiceProvider` maps the aliases, so `revisable_type` holds `script` / `ai_model`,
  and `getMorphClass()` is what `RevisionService` keys `config('marketplace.allowed_extensions')` and
  the storage path on.
- `App\Models\Concerns\HasRevisions` is used by `Script` and `AiModel` and provides
  `revisions()` (morphMany, `number` desc), `latestReleasedRevision()` (ignores draft and deprecated),
  and `downloads()`.
- `Script` and `AiModel` are soft-deleting; hard delete is a separate permission.
- `ScriptImage` belongs to a `VaultFile` — Preview Images are ordinary public Vault media,
  unlike Revision files (see below).
- `User::customer()` and `User::isCustomerUser()` were added; `customer_id` is fillable.
  `isCustomerUser()` is a label check, **not** an authorisation check.

## Permissions

Appended to `Role::availablePermissions()` (the single source of truth — never hardcode counts):

```
customers.view|create|edit|delete
machines.view|create|edit|delete                  (brands + models)
scripts.view|create|edit|delete|upload|release|hard_delete
ai_models.view|create|edit|delete|upload|release|hard_delete
unysis_boxes.view|edit|block
downloads.view
```

`RoleSeeder` syncs the admin role from `availablePermissions()`, so admin picks these up automatically.
It also seeds a `customer` role: slug `customer`, no permissions, `backend_access = false`, so
`RequireAdminAccess` already rejects Customer Users from the admin area. See [permissions](permissions.md).

## Storage and config

`config/marketplace.php`:

| Key | Default | Env |
|---|---|---|
| `disk` | `marketplace` | `MARKETPLACE_DISK` |
| `max_upload_kb` | `1048576` (1 GB) | `MARKETPLACE_MAX_UPLOAD_KB` |
| `allowed_extensions` | `['ai_model' => ['h5'], 'script' => ['zip']]` | — |
| `token_ttl_days` | `30` | `MARKETPLACE_TOKEN_TTL_DAYS` |

`config/filesystems.php` gains a private `marketplace` disk (`local` driver, `storage/app/marketplace`).

**Revision files bypass the Vault** (docs/adr/0003): they are `.h5` weights and `.zip` bundles routinely
larger than the Vault's 50 MB cap, and must only be reachable through authenticated, logged download
endpoints. Preview Images still use the [Vault](vault.md) because they are ordinary public pictures.

## Access model

Per docs/adr/0001, the Customer label is a **secondary filter, never an access wall** — every authenticated
Customer User sees the whole catalogue, and the primary axis is Machine Model. Nothing customer-confidential
may be uploaded. Per docs/adr/0002, UNYSIS Boxes identify by the motherboard UUID they report under a human
Customer User login; download attribution (`Customer User + UNYSIS Box`) is derived from the API token, never
from request parameters.

## Admin UI (Phase 2)

All routes live in the existing admin group (`auth` + `verified` + `admin`) under
`Route::prefix('marketplace')->name('marketplace.')`, so every URL is `/admin/marketplace/...`
and every route name is `admin.marketplace.*`.

| Route name | Method / path | Controller | Permission |
|---|---|---|---|
| `admin.marketplace.machine-brands.*` | resource, no `show` | `Marketplace\MachineBrandController` | `machines.view/create/edit/delete` |
| `admin.marketplace.machine-models.*` | resource, no `show` | `Marketplace\MachineModelController` | `machines.view/create/edit/delete` |
| `admin.marketplace.customers.*` | resource **incl. `show`** | `Marketplace\CustomerController` | `customers.view/create/edit/delete` |
| `admin.marketplace.customers.users.store` | `POST /customers/{customer}/users` | `Marketplace\CustomerUserController@store` | `customers.edit` |
| `admin.marketplace.customers.users.toggle-active` | `POST .../users/{user}/toggle-active` | `@toggleActive` | `customers.edit` |
| `admin.marketplace.customers.users.revoke-tokens` | `POST .../users/{user}/revoke-tokens` | `@revokeTokens` | `customers.edit` |
| `admin.marketplace.customers.users.send-password-reset` | `POST .../users/{user}/send-password-reset` | `@sendPasswordReset` | `customers.edit` |

### Policies

`CustomerPolicy`, `MachineBrandPolicy` and `MachineModelPolicy` map straight onto `customers.*` and
`machines.*` — Machine Brands and Machine Models deliberately share one permission family. They are
registered explicitly in `AppServiceProvider::boot()` next to `SettingPolicy` and `EmailLogPolicy`.
Every Customer User action is authorised as `update` on the **owning Customer**, not on the user.

### Form requests (`app/Http/Requests/Marketplace/`)

`Store`/`Update` pairs for MachineBrand, MachineModel and Customer, plus `StoreCustomerUserRequest`.
Machine Brand names are globally unique; Machine Model names are unique **per Machine Brand**, mirroring
the schema's composite unique index. Slugs are never user input — controllers derive them with
`Str::slug($name)` and append `-2`, `-3`… until unique.

### Deletes never cascade

| Deleting | Refused when | Result |
|---|---|---|
| Machine Brand | it still has Machine Models | flash `error`, nothing deleted |
| Machine Model | it still has Scripts or AI Models | flash `error`, nothing deleted |
| Customer | it still has Customer Users or UNYSIS Boxes | flash `error`, nothing deleted |

### Inertia pages (`resources/js/Pages/Marketplace/`)

- `MachineBrands/{Index,Create,Edit}.tsx`
- `MachineModels/{Index,Create,Edit}.tsx` — the form has a Machine Brand select; the index filters by
  Machine Brand and by status.
- `Customers/{Index,Create,Edit,Show}.tsx` — `Show` has a **Customer Users** tab listing name, email,
  active, created and live session count, with Create Customer User (dialog), Deactivate/Reactivate,
  Revoke all sessions and Send password reset.

Indexes use the shared `DataTable` with `DataTableToolbar` for search and its built-in client-side
pagination; the controllers return full ordered collections rather than a Laravel paginator, because
`DataTable` paginates in the browser.

The sidebar gains a **Marketplace** section (`app-sidebar.tsx`) whose entries appear only for the
matching `.view` permission: Customers (`customers.view`), Machine Brands and Machine Models
(`machines.view`).

`HandleInertiaRequests` now shares a `flash` prop (`success` / `error`), and
`resources/js/hooks/use-flash-toast.ts` turns it into a Sonner toast — that is how the refusal
messages above actually reach the user.

## Customer Users

Created from the Customer detail page. A new Customer User always gets:

- `customer_id` set to the owning Customer,
- **only** the `customer` role via `syncRoles()` — never anything carrying `backend_access`,
- `email_verified_at` set (they never use the web login, so verification is meaningless),
- `is_active` true.

Leave the password blank and the account is created with a random one and `Password::sendResetLink()`
is sent, so the Customer User chooses their own.

Deactivating also deletes every Sanctum token (`$user->tokens()->delete()`), so an UNYSIS Box already
holding one stops immediately. "Revoke all sessions" does the same without touching `is_active`, and
flashes the count revoked.

`User` now uses `Laravel\Sanctum\HasApiTokens`. Two consequences worth knowing:

- Sanctum 4 does not auto-load its migration, and its `morphs('tokenable')` would create a bigint key,
  which cannot hold this app's ULIDs.
  `database/migrations/2026_09_23_000001_create_personal_access_tokens_table.php` creates the same
  table with a **string** `tokenable_id`.
- `Relation::enforceMorphMap()` makes the morph map exhaustive, so `'user' => User::class` had to be
  added alongside `ai_model` and `script` — Sanctum's `tokens()` is a morphMany on `User`.

## Web login is closed to Customer Users

Per docs/adr/0002 a Customer User reaches the catalogue only through RPA-TOOL and must never hold a
web session.

`App\Http\Requests\Auth\LoginRequest::isRpaToolOnly(User)` is the single test: true when the user
`isCustomerUser()` (has a `customer_id`), **or** holds the `customer` role and no role granting
backend access.

- **Password login** — `LoginRequest::authenticate()` calls `rejectCustomerUser()` after the
  credential check succeeds: it logs the session straight back out, invalidates it, regenerates the
  CSRF token, and throws a `ValidationException` on `email` with `LoginRequest::RPA_TOOL_ONLY_MESSAGE`
  ("This account can only be used from RPA-TOOL."). A wrong password still fails first with the
  ordinary `auth.failed`, so nothing extra leaks either way.
- **Socialite** — `SocialAuthController::callback()` applies the same check twice: once on the user
  found by email, *before* the provider is linked, and once more just before `Auth::login()` to cover
  the duplicate-key recovery path.

`RequireAdminAccess` already redirects them away from `/admin/*` because the `customer` role has
`backend_access = false`; the login rejection is the layer in front of it.

## Services (Phase 3)

`app/Services/Marketplace/`:

### RevisionService

`upload(Script|AiModel $revisable, UploadedFile $file, string $changeNote, User $uploader): Revision`

1. **Extension** — must be in `config('marketplace.allowed_extensions')[$revisable->getMorphClass()]`
   (`script` -> `zip`, `ai_model` -> `h5`). The morph alias comes from `getMorphClass()`, so the
   enforced morph map in `AppServiceProvider` is the single source of the key.
2. **Double extension** — the same idea as `app/Vault/Pipes/DetectDoubleExtension.php`, reimplemented in
   the service because Revision files never enter the Vault pipeline. `payload.exe.zip` is refused.
3. **Size** — `config('marketplace.max_upload_kb')` (1 GB by default).
4. **Magic bytes** — a `.zip` must start with `PK\x03\x04` / `PK\x05\x06` / `PK\x07\x08`, with
   `ZipArchive::open()` as the fallback verdict; a `.h5` must carry the HDF5 superblock
   `\x89HDF\r\n\x1a\n`.
5. **ClamAV** — when `vault.clamav_enabled` is set, the file goes through `App\Services\ClamAvScanner`
   (the same implementation the Vault pipe uses — see [vault](vault.md)). A hit rejects the upload.

Every failure is a `ValidationException` on the `file` key, so the Inertia form shows it inline.

Numbering is `max(number) + 1` **per revisable**, taken inside a `DB::transaction`; a unique-index clash
(concurrent upload) is retried once. The file is stored on `config('marketplace.disk')` at
`{morph alias}/{revisable id}/{number}.{ext}`, and the row records `sha256` (`hash_file`),
`original_filename`, `size_bytes`, `mime`, `uploaded_by` and status `draft`.

Lifecycle — one way only, `App\Exceptions\Marketplace\InvalidRevisionTransition` otherwise:

```
        release()                 deprecate()
draft ------------->  released  ------------->  deprecated

   ^                                                |
   |                no path back                    |
   +------------------------------------------------+
                      (never allowed)
```

- `release(Revision, User)` — only from `draft`; sets `released_by` and `released_at`.
- `deprecate(Revision, User)` — only from `released`; sets `deprecated_at`.
- `deleteFile(Revision)` — removes the stored file; used by hard delete.

### DownloadService

- `record(Revision, ?User, ?UnysisBox, string $source, Request): Download` — `web` from the admin pages,
  `api` from the RPA-TOOL download endpoints (Phase 4).
- `stream(Revision): StreamedResponse` — `Storage::disk('marketplace')->download()` under the
  `original_filename`, with `X-Checksum-SHA256` and `X-Revision-Number` headers so the caller can verify
  what it got.

### UnysisBoxService (Phase 4)

`resolve(Customer, string $motherboardUuid, ?string $name, string $ip, User): UnysisBox` — normalises the
UUID (trim + lowercase), finds the box or creates it with `status = pending` and `first_user_id`, and
always bumps `last_seen_at` / `last_ip`. It throws
`App\Exceptions\Marketplace\UnysisBoxBelongsToAnotherCustomer` when the UUID is registered to a
different Customer (never reassigned silently) and `UnysisBoxBlocked` when the box is blocked; both come
back as a 403 carrying the exception message. A client-supplied `box_name` only ever fills a
**blank** name — a Team Member's label is never overwritten.

`touch(UnysisBox, ?string $ip)` is the per-request presence write, throttled by
`UnysisBoxService::TOUCH_INTERVAL_SECONDS` (60): a box seen inside that window from the same IP is not
written again, so a burst of catalogue reads is not a burst of `UPDATE`s.

`UnysisBoxService::normaliseUuid()` is the single definition of the comparison form, used by the service
and by `ResolveUnysisBox`.

### Storage layout

```
storage/app/marketplace/
  script/{script id}/1.zip
  script/{script id}/2.zip
  ai_model/{ai model id}/1.h5
```

The disk is private; nothing under it is web-reachable. The only way out is the authenticated download
route, which records a Download first.

### php.ini on the VPS

The app's own cap is `marketplace.max_upload_kb` (1 GB), enforced by `StoreRevisionRequest`'s `max:` rule
in kilobytes. PHP discards a body larger than `post_max_size` **before** validation runs, so
`upload_max_filesize` and `post_max_size` must be raised to at least the same figure on the VPS (and
`max_execution_time` plus nginx's `client_max_body_size` with them). When PHP does reject a body first,
`bootstrap/app.php` turns the resulting `PostTooLargeException` into a flash `error` instead of a bare 413.

## Catalogue admin (Phase 3)

| Route name | Method / path | Permission |
|---|---|---|
| `admin.marketplace.scripts.*` | resource incl. `show` | `scripts.view/create/edit/delete` |
| `admin.marketplace.scripts.images.sync` | `PUT scripts/{script}/images` | `scripts.edit` |
| `admin.marketplace.scripts.restore` | `POST scripts/{script}/restore` | `scripts.delete` |
| `admin.marketplace.scripts.force-destroy` | `DELETE scripts/{script}/force` | `scripts.hard_delete` |
| `admin.marketplace.scripts.revisions.store` | `POST scripts/{script}/revisions` (`throttle:30,1`) | `scripts.upload` |
| `admin.marketplace.scripts.revisions.release` | `POST .../{revision}/release` | `scripts.release` |
| `admin.marketplace.scripts.revisions.deprecate` | `POST .../{revision}/deprecate` | `scripts.release` |
| `admin.marketplace.scripts.revisions.download` | `GET .../{revision}/download` | `scripts.view` |
| `admin.marketplace.scripts.revisions.destroy` | `DELETE .../{revision}` | `scripts.hard_delete` |
| `admin.marketplace.ai-models.*` | the same set, minus images | `ai_models.*` |

`ScriptPolicy` and `AiModelPolicy` add `upload`, `release` and `hardDelete` on top of the usual
five and are registered in `AppServiceProvider::boot()`. Routes that take a soft-deleted entry
(`restore`, `force`) are declared `->withTrashed()`. A Revision that does not belong to the entry in the
URL 404s (`assertBelongsTo`).

**Deletes.** `destroy` soft deletes (`scripts.delete`) and the index has a *Show deleted* toggle with
Restore. `force` (`scripts.hard_delete`) deletes every Revision file, the Revision rows and the Preview
Image links, then `forceDelete()`s the entry. Hard-deleting a single Revision is allowed even when it is
the only released one — the flash then warns how many recorded Downloads referenced it.
**Download rows are never deleted.**

**Preview Images.** `syncImages` takes an ordered `vault_file_ids[]`, validated to exist in `vault_files`
and to have an `image/*` mime, and replaces the `script_images` rows with a fresh `sort_order`.
The first image is the cover. The picker is the global Vault picker
(`useVaultPicker` -> `Components/Vault/VaultPicker.tsx`); ordering is drag-and-drop with `@dnd-kit`.
AI Models have no Preview Images.

### Inertia pages and shared components

- `resources/js/Pages/Marketplace/Scripts/{Index,Create,Edit,Show}.tsx`
- `resources/js/Pages/Marketplace/AiModels/{Index,Create,Edit,Show}.tsx`
- `resources/js/Components/Marketplace/` — `RevisionsTable.tsx`, `RevisionUploadDialog.tsx`
  (react-dropzone plus change note, showing the allowed extension and the size cap),
  `RevisionStatusBadge.tsx`, `DownloadsTable.tsx`, `PreviewImagesManager.tsx`, `CatalogueFilters.tsx`
  (Machine Model options sorted and labelled by Machine Brand, plus Customer), `format.ts`.

Both Show pages use tabs: **Revisions**, **Downloads** (latest 50 `web` rows), **Details**, and — Scripts
only — **Preview Images**. The indexes list name, Machine Model, Customer, latest released Revision,
Revision count, total Downloads and updated-at, and reuse the shared `DataTable` with client-side paging,
so the controllers return full ordered collections. The latest released Revision number is computed from
an eager-loaded, column-limited `revisions` relation (then dropped from the payload) rather than a query
per row.

The sidebar gains **Scripts** (`scripts.view`) and **AI Models** (`ai_models.view`).

`ActivityLogger::log` records create / update / delete / restore / upload / release / deprecate /
hard_delete.

## RPA-TOOL API (Phase 4)

`routes/api.php`, registered in `bootstrap/app.php` with
`withRouting(api: ..., apiPrefix: 'api')`. Every route sits under
`Route::prefix('v1')->name('api.v1.')`, so URLs are `/api/v1/...` and names `api.v1.*`.

Auth is a Sanctum personal access token (`auth:sanctum`; the guard is also spelled out in
`config/auth.php`). **The token's name is the motherboard UUID it was issued for** — that is how
every later request finds its UNYSIS Box, and why download attribution can never come from request input
(docs/adr/0002).

| Method | Path | Notes |
|---|---|---|
| POST | `/login` | email, password, motherboard_uuid, box_name? -> token + user + customer + unysis_box |
| POST | `/logout` | deletes the current token, 204 |
| GET | `/me` | identity payload + `token_expires_at` |
| GET | `/machine-brands` | id, name, slug |
| GET | `/machine-models?brand=` | active only, with brand |
| GET | `/customers` | id + code + company, **all** active Customers (docs/adr/0001) |
| GET | `/scripts?machine_model=&brand=&customer=&q=&per_page=&page=` | paginated; only entries with a released Revision |
| GET | `/scripts/{id}` | detail + images + released/deprecated Revisions |
| GET | `/scripts/{id}/revisions` | the same Revision array |
| GET | `/scripts/{id}/download?revision=` | streams the file, records a Download |
| GET | `/scripts/{id}/check-update?current=` | `{update_available, latest, current_status}` |
| | `/ai-models/...` | the same five, `framework`/`input_size`/`labels`/`notes` instead of images |

### Who may sign in

`AuthController::login` is the mirror image of the web login: the web refuses Customer Users, the API
refuses everyone else. A caller must pass `LoginRequest::isRpaToolOnly()`, have a `customer_id`,
belong to an **active** Customer, and be `is_active` itself. A wrong password and an unknown email
both return the same 422 `These credentials do not match our records.`

### ResolveUnysisBox middleware

Alias `unysis-box`, applied after `auth:sanctum` on every authenticated API route. It re-runs the whole
gate on **every** request — account active, still a Customer User, Customer active, box exists,
belongs to this Customer, not blocked — then puts the box on the request as the `unysis_box` attribute
and calls `UnysisBoxService::touch()`. That re-check is the point: blocking a box or deactivating a
Customer User cuts access off at once rather than when the 30-day token expires. See
[architecture/middleware](../architecture/middleware.md).

### Throttling

Plain `throttle:60,1` buckets authenticated callers by **user id**, but one Customer User may run
several UNYSIS Boxes. Three named limiters are registered in `AppServiceProvider` and keyed on the
**token id** instead (falling back to the IP): `rpa` (60/min), `rpa-download` (20/min) and
`rpa-login` (5/min, by IP since there is no token yet).

### What the API never shows

- **Draft Revisions.** Lists, detail, `revisions` and `download` all filter to released + deprecated.
  A `?revision=N` pointing at a draft is a 404.
- **Entries with no released Revision.** Hidden from the list (`whereHas` released); the detail
  endpoint still resolves them, with an empty `revisions` array.
- **Soft-deleted entries.** Route model binding is not `withTrashed()`, so they 404.
- `revisions_count` counts what the API exposes (released + deprecated), not drafts.

### Resources

`app/Http/Resources/Api/V1/`: `MachineBrandResource`, `MachineModelResource`, `CustomerResource`,
`RevisionResource` (full) and `RevisionSummaryResource` (the `latest_revision` / `latest` short
form), plus `Script{,Detail}Resource` and `AiModel{,Detail}Resource`. The fields the two
entry types share live in `Concerns\PresentsCatalogueEntry`, which reads `latest_revision` out of
the eager-loaded, status-narrowed `revisions` relation rather than querying per row.

`CatalogueController` (abstract) holds index / show / revisions / download / check-update for both
entry types; `ScriptController` and `AiModelController` are thin, and exist mainly so the
route parameter `{entry}` has a concrete type hint for implicit binding.

## UNYSIS Boxes and Downloads admin (Phase 5)

| Route name | Method / path | Permission |
|---|---|---|
| `admin.marketplace.unysis-boxes.index` | `GET unysis-boxes` | `unysis_boxes.view` |
| `admin.marketplace.unysis-boxes.show` | `GET unysis-boxes/{unysis_box}` | `unysis_boxes.view` |
| `admin.marketplace.unysis-boxes.edit` / `.update` | `GET` / `PUT unysis-boxes/{unysis_box}` | `unysis_boxes.edit` |
| `admin.marketplace.unysis-boxes.activate` | `POST unysis-boxes/{unysis_box}/activate` | `unysis_boxes.edit` |
| `admin.marketplace.unysis-boxes.block` | `POST unysis-boxes/{unysis_box}/block` | `unysis_boxes.block` |
| `admin.marketplace.unysis-boxes.unblock` | `POST unysis-boxes/{unysis_box}/unblock` | `unysis_boxes.block` |
| `admin.marketplace.unysis-boxes.destroy` | `DELETE unysis-boxes/{unysis_box}` | `unysis_boxes.edit` |
| `admin.marketplace.downloads.index` | `GET downloads` | `downloads.view` (route middleware `can:`) |

`UnysisBoxPolicy` maps `viewAny`/`view` onto `unysis_boxes.view`, `update`/`delete` onto `unysis_boxes.edit` and
`block` onto `unysis_boxes.block`; it is registered in `AppServiceProvider::boot()`. There is deliberately
**no `create`** — RPA-TOOL registers a box by itself on first login (docs/adr/0002). The Download log
has no model of its own to authorise against, so its route carries `can:downloads.view` and the
controller re-checks `hasPermission('downloads.view')`.

### What a Team Member can change

`UpdateUnysisBoxRequest` accepts only `name`, `location` and `machine_model_id`. The motherboard UUID is
what the box reports and what its token is named after, and **`status` never moves through `update`** —
it changes only through the three explicit actions:

- **activate** (`unysis_boxes.edit`) — `pending` -> `active`. This is "I know this box", not a security
  action; it refuses a blocked box and tells the Team Member to unblock instead.
- **block** (`unysis_boxes.block`) — sets `blocked` **and deletes every Sanctum token named after the box's
  motherboard UUID**. `ResolveUnysisBox` would already refuse the box on its next request, but deleting the
  tokens revokes access in the same instant and without depending on the middleware; the token name *is*
  the UUID (docs/adr/0002), so "every token for this box" is an exact lookup. The flash says how many
  sessions were revoked.
- **unblock** (`unysis_boxes.block`) — back to `active`. The box must sign in again, since its tokens are gone.

`destroy` refuses a box that has any recorded Downloads — Download rows are the record of what a box
installed and are never deleted — and suggests blocking instead. A box with none is deleted along with
any tokens named after it.

### The Installed tab — how "installed" is derived

Nothing is stored. `App\Services\Marketplace\UnysisBoxInstalledService::forBox()` treats **the latest
Download of a catalogue entry by that box** as what the box is running, and returns one row per entry
it has ever fetched, whatever that Revision's status is now: a box that only ever pulled a Revision
since deprecated is still running it. Each row carries the entry, its Machine Model, the installed
Revision number and status, when it was downloaded, the highest **released** Revision number, and an
`outdated` flag when the installed number is lower than that. A draft Revision never makes an install
look outdated.

**Why the reduction happens in PHP.** The natural SQL is "the row with the greatest `created_at` per
(`revisable_type`, `revisable_id`)", which needs a window function or a self-join on a grouped max —
and `max(id)` is *not* the latest row, because the primary keys are ULIDs and only sort lexically when
generated in order. Rather than carry two dialect-specific queries for SQLite (tests) and PostgreSQL
(production), the service reads the box's own Download log — bounded by one UNYSIS Box, so tens to a few
hundred rows — ordered newest first and keeps the first row it sees per entry. The whole derivation is
**five queries** regardless of how many entries are installed: the Download log, the two entry tables
(`withTrashed()`, so a removed entry still shows), and the released Revisions of each entry type.

### The Download log

`DownloadController@index` is server-paginated at **50 a page** (the indexes elsewhere hand the whole
collection to the browser's `DataTable`; the Download log is append-only and unbounded, so it cannot).
Filters: `source`, `customer_id`, `unysis_box_id`, `entry_type`, `user_id`, `q` (entry name) and `from` / `to`.

Two of them are less obvious than they look:

- **Customer** — a Download carries no `customer_id`. It belongs to a Customer through the UNYSIS Box it
  came from, *or*, for a web fetch with no box, through the Customer User who made it, so the filter is
  an `OR` of two `whereIn` subqueries.
- **Entry name** — a join is impossible across two tables behind one morph column, so the matching entry
  ids are resolved per entry type first (`withTrashed()`) and the polymorphic columns filtered on those.

The summary strip above the table (total, last 7 days, unique UNYSIS Boxes, top 5 entries) is computed over
the **filtered** set with four grouped queries, whatever the row count. `count(distinct unysis_box_id)` and
a two-column `group by` both run unchanged on SQLite and PostgreSQL, so `App\Support\DateBucket` was
not needed here.

`App\Support\DownloadPresenter` shapes the rows for both this page and the UNYSIS Box Downloads tab. It
resolves entry names with one `withTrashed()` query per entry type per page, because a `morphTo` eager
load would not reach a soft-deleted entry — and hard-deleting an entry deliberately keeps its Download
rows, so a row whose entry is gone entirely still renders, unlinked.

### Inertia pages and components

- `resources/js/Pages/Marketplace/UnysisBoxes/{Index,Show,Edit}.tsx` — the index is the shared `DataTable`
  with faceted filters on Customer, status and Machine Model, and one hidden `search` column joining the
  motherboard UUID, name and location so one box matches on any of the three. `Show` has tabs
  **Installed**, **Downloads** (server-paginated) and **Details**.
- `resources/js/Pages/Marketplace/Downloads/Index.tsx` — the summary strip, the filter bar (filters go
  through the URL, so a filtered log is linkable) and the log.
- `resources/js/Components/Marketplace/` gains `UnysisBoxStatusBadge.tsx`, `InstalledRevisionsTable.tsx`,
  `DownloadLogTable.tsx` (the full log, with optional entry and UNYSIS Box columns — the older
  `DownloadsTable.tsx` stays as the narrow per-entry web-fetch list on the catalogue Show pages) and
  `Pagination.tsx`. `format.ts` gains `formatRelative()` for last-seen columns.
- The Customer detail page gains an **UNYSIS Boxes** tab (uuid, name, status, last seen), linking to each
  box when the viewer has `unysis_boxes.view`.
- The Scripts and AI Models Show pages gain a **unique UNYSIS Boxes** count beside the Download total, in
  the header and per Revision row. The per-Revision figure is a correlated `count(distinct unysis_box_id)`
  sub-select added to the existing Revisions query, so it is still one query.
- The sidebar gains **UNYSIS Boxes** (`unysis_boxes.view`) and **Downloads** (`downloads.view`).

`ActivityLogger::log` records `update`, `activate`, `block`, `unblock` and `delete` on UNYSIS Boxes.

## Dashboard, export and reporting (Phase 6)

### Dashboard

`DashboardController` renders `resources/js/Pages/Dashboard.tsx` from live Marketplace figures. Every
panel is gated on the `<resource>.view` permission of the page it summarises and is sent as `null`
when the Team Member cannot see it — a panel is omitted from the props, not hidden in the browser.

- **Cards** — Scripts and AI Models as *released / total* (released = at least one Revision with
  `status = released`, found with one `whereIn` against a `revisions` sub-select per type); active
  Customers; UNYSIS Boxes by status from one grouped query; Downloads in the last 7 days with the
  delta against the 7 before them.
- **Downloads per day** — a 30-day Recharts area chart. This is the one figure that needs
  `App\Support\DateBucket`, because bucketing a timestamp to `YYYY-MM-DD` is the single expression
  that differs between SQLite (`strftime`) and PostgreSQL (`to_char`). Gaps are filled in PHP so every
  day in the window has a point.
- **Latest Revisions** — the last 10 uploads across both entry types, with status and uploader.
- **Recently seen UNYSIS Boxes** — the last 10 by `last_seen_at`; a box that has never checked in has
  no `last_seen_at` and is left out.

### DownloadQuery — one filter builder

`App\Support\DownloadQuery` holds the request parsing (`filters()`) and the query construction
(`build()`) that the log index, its CSV export and the Usage report all share. Unknown filter values
are dropped rather than passed through, so a hand-edited URL never reaches the builder.

Every column in it is **table-qualified** (`downloads.created_at`, not `created_at`): the Usage report
joins `unysis_boxes` and `users` onto the same builder and both carry a `created_at` of their own, so
an unqualified predicate would be ambiguous on PostgreSQL.

### Download CSV export

`GET /admin/marketplace/downloads/export` (`downloads.view`) streams the **currently filtered** log
through `response()->streamDownload`, chunked 500 rows at a time, as `downloads-YYYY-MM-DD.csv`.
Columns: `downloaded_at`, `source`, `entry_type`, `entry_name`, `machine_model`, `machine_brand`,
`revision_number`, `revision_status`, `customer_code`, `customer_company`, `unysis_box_uuid`,
`unysis_box_name`, `user_name`, `user_email`, `ip`. The row set is unbounded, so nothing larger than
one chunk is ever held in memory. The "Export CSV" button on the log page carries the active filters
in its query string, so the file always matches what is on screen.

### Usage report

`ReportController` serves `GET /admin/marketplace/reports/usage` (page
`resources/js/Pages/Marketplace/Reports/Usage.tsx`, `downloads.view`), with a date range — presets 7 /
30 / 90 / 365 / all, default the last 30 days, overridable with an explicit `from`/`to` pair — and an
optional entry-type filter.

- **Totals strip** — downloads, Customers, UNYSIS Boxes and distinct entries, from one query.
- **By Customer** — code, company, active boxes, boxes that downloaded in the range, downloads,
  distinct entries downloaded, last download. Active boxes are a property of the Customer *today*, not
  of the range, so they come from their own grouped query over `unysis_boxes`.
- **By entry** — type, name, Machine Model and Brand, downloads, distinct Customers, distinct boxes,
  and the highest released Revision number (one grouped query over `revisions`).

Each table exports on its own at `GET /admin/marketplace/reports/usage/export?section=customers|entries`,
with the same range in the query string.

Attribution is the awkward part, as it is in the log: a Download carries no `customer_id`, so the
report left-joins `unysis_boxes` and `users` and groups on
`coalesce(unysis_boxes.customer_id, users.customer_id)`. Both joins are *left* joins — a Team Member's
web download belongs to no Customer at all and must not vanish from the totals; those rows are
reported under a "No Customer (internal)" row rather than dropped. `count(distinct ...)`, `||`
concatenation and `coalesce` are all in the SQLite/PostgreSQL common subset, so no dialect switch is
needed beyond `DateBucket` on the dashboard chart.

`App\Support\DownloadPresenter::entryNames()` now also resolves each entry's Machine Model and Machine
Brand, and accepts any row carrying `revisable_type`/`revisable_id` — Download rows, the report's
grouped rows and the dashboard's Revision rows all pass through it unchanged.

### Sidebar

The Marketplace group gained **Usage Report** (`downloads.view`). The inherited **Content** group is
gone with the CMS; the remaining groups are Platform (Dashboard), Marketplace and Administration
(Vault, Users, Roles, Email Logs, Activity, Settings), each entry gated on its permission.

## Tests

`tests/Feature/Marketplace/`:

- `MarketplaceSchemaTest` — tables exist, unique constraints throw.
- `RevisionModelTest` — polymorphism, `latestReleasedRevision()` semantics.
- `MarketplacePermissionsTest` — permission list, seeded `customer` role, `canAccessBackend()`, `isCustomerUser()`.
- `MachineBrandControllerTest`, `MachineModelControllerTest` — 403 without the permission, CRUD, slug
  generation, unique validation (including "unique per Machine Brand"), refusal to delete with dependents.
- `CustomerControllerTest` — 403 without the permission, CRUD, `show`, refusal to delete with Customer
  Users or UNYSIS Boxes.
- `CustomerUserControllerTest` — only the `customer` role is assigned and `customer_id` is set, the
  invite path sends `ResetPassword` (`Notification::fake()`), deactivate and revoke delete tokens, a
  user belonging to another Customer 404s.
- `CustomerUserWebLoginTest` — the web login refuses a Customer User and a customer-role-only user,
  while a Team Member and an ordinary user still log in.
- `RevisionServiceTest` — `Storage::fake('marketplace')`; numbering increments per revisable and is
  independent between two Scripts and between a Script and an AI Model; wrong extension, double
  extension, bad magic bytes and oversize are refused; the SHA-256 matches the bytes; every lifecycle
  transition allowed and refused; `ClamAvScanner` mocked to assert it is called only when
  `vault.clamav_enabled` is set and that a hit refuses the upload.
- `ScriptControllerTest`, `AiModelControllerTest` — 403 without each permission, CRUD, unique
  name per Machine Model, soft delete / restore / force delete (files removed), upload, release,
  deprecate, download (records a `web` Download and returns `X-Checksum-SHA256`), cross-entry Revision
  404, hard delete keeping Download rows.
- `ScriptImagesTest` — sync order, reorder, clear, non-image and unknown Vault file refused,
  403 without `scripts.edit`.

Factories exist for all nine models.

`tests/Feature/Api/V1/` (Phase 4), all on top of `ApiTestCase`:

- `LoginTest` — payload and token TTL/name, UUID normalisation, generic 422 for a wrong password or
  unknown email, 403 for an inactive user / a Team Member / an inactive Customer / a blocked box / a
  box owned by another Customer, auto-registration as `pending` with `first_user_id`, reuse of an
  existing box without clobbering its label, and the 5/min login throttle.
- `LogoutMeTest` — JSON 401 (with and without an `Accept` header), `me`, logout deleting only the
  current token, expired token refused.
- `ResolveUnysisBoxMiddlewareTest` — blocking, deleting or reassigning the box, and deactivating the
  user or the Customer, all refuse the *next* request on a live token; the touch throttle.
- `CatalogueReadTest` — lookups, entries without a released Revision hidden, drafts absent from
  detail, filters, the `q` search, per-page cap, soft-deleted 404, AI Model metadata.
- `DownloadTest` — default latest released, explicit deprecated, draft/unknown 404, the Download row
  (`api` + user + box), headers, cross-entry Revision, the 20/min throttle.
- `CheckUpdateTest` — every `current_status` branch.
- `tests/Unit/UnysisBoxServiceTest` — the service in isolation.

`tests/Feature/Marketplace/` (Phase 5):

- `UnysisBoxControllerTest` — 403 on every route without the matching permission, the index payload and its
  filter option sets, the label update, `status` and `motherboard_uuid` ignored by `update`, an unknown
  Machine Model refused, activate (including refusing a blocked box, and needing `unysis_boxes.edit` rather
  than `unysis_boxes.block`), unblock, destroy refused with Downloads and allowed without. Blocking is
  tested end to end: a token obtained from the real `POST /api/v1/login` works against `/api/v1/me`,
  the block deletes it, and the same token then 401s — while a second box's token is left alone.
- `UnysisBoxInstalledTest` — the latest Download per entry wins even when the rows are inserted newest
  first, the outdated flag, a draft Revision not making an install outdated, an entry whose only
  Download was of a since-deprecated Revision still listed, both entry types side by side, another
  box's Downloads ignored, and a soft-deleted entry still reported.
- `DownloadControllerTest` — 403 without `downloads.view`, newest-first ordering, 50-a-page pagination,
  every filter (source, entry type, UNYSIS Box, Customer through both the box and the user, user, entry
  name across both entry types, date range), the summary figures, and a Download whose entry was hard
  deleted still appearing.
- `DownloadExportTest` — 403 without the permission, the agreed header row, one fully joined data row
  (entry, Machine Model and Brand, Revision number and status, Customer, box), and a filter narrowing
  the file.
- `UsageReportTest` — 403 without the permission on both the page and the export, per-Customer
  aggregates with two Customers and a box each, the date range excluding older rows, the entries
  section (distinct Customers, distinct boxes, latest released Revision), the entry-type filter, and
  both section exports.

`tests/Feature/DashboardTest.php` covers the dashboard payload: the card figures, the 7-day delta and
the 30 filled chart buckets, the ten-row caps on both lists, and every panel coming back `null` for a
Team Member without the matching permission.

Tokens in these tests come from the real `POST /api/v1/login` rather than `Sanctum::actingAs`,
because `ResolveUnysisBox` resolves the box from the token **name** and an acting-as transient token
carries none. `ApiTestCase::asToken()` calls `forgetGuards()` first: the auth guard caches the
resolved user for the life of the container, which survives between requests inside one test.

## See also

- [`docs/api/rpa-tool-v1.md`](../../docs/api/rpa-tool-v1.md) — the endpoint reference for RPA-TOOL developers
- [permissions](permissions.md)
- [vault](vault.md)
- [architecture/middleware](../architecture/middleware.md)
- [architecture/datastore](../architecture/datastore.md)
- [database/collections](../database/collections.md)
