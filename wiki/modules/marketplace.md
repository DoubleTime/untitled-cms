# Marketplace

> Catalogue of AI Models and FlowChart Scripts that run on UNYSIS AI Boxes, fetched by RPA-TOOL

Last updated: 2026-09-22 (Phase 3)

Vocabulary is fixed in [`CONTEXT.md`](../../CONTEXT.md) — use those terms verbatim in code, UI copy and docs.
The implementation plan is [`docs/marketplace-plan.md`](../../docs/marketplace-plan.md); decisions are in [`docs/adr/`](../../docs/adr).

**Status: Phase 3 (Catalogue admin) shipped.** Phase 1 gave the schema, models, permissions, config
and the private disk; Phase 2 added the Customers, Customer User and Machines admin plus the
web-login rejection; Phase 3 adds the FlowChart Scripts and AI Models admin, `RevisionService`,
`DownloadService`, the Revision lifecycle, Preview Images and soft/hard delete. The RPA-TOOL API is
still to come.

## What it is

UNYSIS Team Members publish two kinds of catalogue entry, both targeting one Machine Model:

- **AI Model** — a trained inference model published on its own (`.h5` files).
- **FlowChart Script** — a packaged automation sequence bundling a flow definition with the AI models and libraries it needs (`.zip` bundles).

Each entry accumulates **Revisions**: immutable, sequentially numbered uploads with a change note.
Only `released` Revisions are offered to RPA-TOOL by default. **Customer Users** sign in from RPA-TOOL on an
**AI Box**, and every fetch is recorded as a **Download**.

## Tables

All ULID primary keys via `HasUlidKey`, every reference column indexed, no foreign key constraints —
see [architecture/datastore](../architecture/datastore.md). Migration:
`database/migrations/2026_09_22_000001_create_marketplace_tables.php`.

| Table | Purpose / notable columns |
|---|---|
| `customers` | name, company, contact_*, notes, is_active |
| `users` (altered) | nullable indexed `customer_id` — set only for Customer Users |
| `machine_brands` | name **unique**, slug |
| `machine_models` | machine_brand_id, name, slug, description, is_active; **unique (machine_brand_id, name)** |
| `flowchart_scripts` | machine_model_id, customer_id?, name, slug, description, created_by, `deleted_at` |
| `flowchart_script_images` | flowchart_script_id, vault_file_id, sort_order |
| `ai_models` | machine_model_id, customer_id?, name, slug, description, framework, input_size, labels, notes, created_by, `deleted_at` |
| `revisions` | revisable_type/_id, number, status, change_note, original_filename, disk_path, size_bytes, sha256, mime, uploaded_by, released_by, released_at, deprecated_at; **unique (revisable_type, revisable_id, number)** |
| `ai_boxes` | customer_id, motherboard_uuid **unique**, name, location, machine_model_id?, status, last_seen_at, last_ip, first_user_id |
| `downloads` | revision_id, revisable_type/_id, user_id, ai_box_id?, source, ip, user_agent |

Derived, never stored: download totals, unique-box counts, and the "installed revision" per box
(the latest download per box per entry).

Status columns are plain strings with a default — not native enums — so the same DDL runs on SQLite (tests)
and PostgreSQL (production). The repo uses no PHP backed enums; the allowed values live as class constants
(`Revision::STATUS_*`, `AiBox::STATUS_*`, `Download::SOURCE_*`).

## Models

`app/Models/`: `Customer`, `MachineBrand`, `MachineModel`, `FlowchartScript`, `FlowchartScriptImage`,
`AiModel`, `Revision`, `AiBox`, `Download`. All plain Eloquent.

- `Revision` is polymorphic (`revisable()` morphTo) so both entry types share one revision/download
  implementation while staying separate entities in the UI and the API. `Relation::enforceMorphMap()`
  in `AppServiceProvider` maps the aliases, so `revisable_type` holds `flowchart_script` / `ai_model`,
  and `getMorphClass()` is what `RevisionService` keys `config('marketplace.allowed_extensions')` and
  the storage path on.
- `App\Models\Concerns\HasRevisions` is used by `FlowchartScript` and `AiModel` and provides
  `revisions()` (morphMany, `number` desc), `latestReleasedRevision()` (ignores draft and deprecated),
  and `downloads()`.
- `FlowchartScript` and `AiModel` are soft-deleting; hard delete is a separate permission.
- `FlowchartScriptImage` belongs to a `VaultFile` — Preview Images are ordinary public Vault media,
  unlike Revision files (see below).
- `User::customer()` and `User::isCustomerUser()` were added; `customer_id` is fillable.
  `isCustomerUser()` is a label check, **not** an authorisation check.

**Naming trap:** `App\Models\AiModel` is a marketplace catalogue entry. `App\Models\AiHub` is the CMS's own
AI provider config. They are unrelated.

## Permissions

Appended to `Role::availablePermissions()` (the single source of truth — never hardcode counts):

```
customers.view|create|edit|delete
machines.view|create|edit|delete                  (brands + models)
scripts.view|create|edit|delete|upload|release|hard_delete
ai_models.view|create|edit|delete|upload|release|hard_delete
ai_boxes.view|edit|block
downloads.view
```

`RoleSeeder` syncs the admin role from `availablePermissions()`, so admin picks these up automatically.
It also seeds a `customer` role: slug `customer`, no permissions, `backend_access = false`, so
`RequireAdminAccess` already rejects Customer Users from the admin area. See [permissions](permissions.md).

(The unused `RolesAndPermissionsSeeder` enumerates a hand-picked subset and is not wired into
`DatabaseSeeder`; it was left untouched.)

## Storage and config

`config/marketplace.php`:

| Key | Default | Env |
|---|---|---|
| `disk` | `marketplace` | `MARKETPLACE_DISK` |
| `max_upload_kb` | `1048576` (1 GB) | `MARKETPLACE_MAX_UPLOAD_KB` |
| `allowed_extensions` | `['ai_model' => ['h5'], 'flowchart_script' => ['zip']]` | — |
| `token_ttl_days` | `30` | `MARKETPLACE_TOKEN_TTL_DAYS` |

`config/filesystems.php` gains a private `marketplace` disk (`local` driver, `storage/app/marketplace`).

**Revision files bypass the Vault** (docs/adr/0003): they are `.h5` weights and `.zip` bundles routinely
larger than the Vault's 50 MB cap, and must only be reachable through authenticated, logged download
endpoints. Preview Images still use the [Vault](vault.md) because they are ordinary public pictures.

## Access model

Per docs/adr/0001, the Customer label is a **secondary filter, never an access wall** — every authenticated
Customer User sees the whole catalogue, and the primary axis is Machine Model. Nothing customer-confidential
may be uploaded. Per docs/adr/0002, AI Boxes identify by the motherboard UUID they report under a human
Customer User login; download attribution (`Customer User + AI Box`) is derived from the API token, never
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
| Machine Model | it still has FlowChart Scripts or AI Models | flash `error`, nothing deleted |
| Customer | it still has Customer Users or AI Boxes | flash `error`, nothing deleted |

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

Deactivating also deletes every Sanctum token (`$user->tokens()->delete()`), so an AI Box already
holding one stops immediately. "Revoke all sessions" does the same without touching `is_active`, and
flashes the count revoked.

`User` now uses `Laravel\Sanctum\HasApiTokens`. Two consequences worth knowing:

- Sanctum 4 does not auto-load its migration, and its `morphs('tokenable')` would create a bigint key,
  which cannot hold this app's ULIDs.
  `database/migrations/2026_09_23_000001_create_personal_access_tokens_table.php` creates the same
  table with a **string** `tokenable_id`.
- `Relation::enforceMorphMap()` makes the morph map exhaustive, so `'user' => User::class` had to be
  added alongside `ai_model` and `flowchart_script` — Sanctum's `tokens()` is a morphMany on `User`.

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

`upload(FlowchartScript|AiModel $revisable, UploadedFile $file, string $changeNote, User $uploader): Revision`

1. **Extension** — must be in `config('marketplace.allowed_extensions')[$revisable->getMorphClass()]`
   (`flowchart_script` -> `zip`, `ai_model` -> `h5`). The morph alias comes from `getMorphClass()`, so the
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

- `record(Revision, ?User, ?AiBox, string $source, Request): Download` — `web` in this phase, `api` in
  Phase 4.
- `stream(Revision): StreamedResponse` — `Storage::disk('marketplace')->download()` under the
  `original_filename`, with `X-Checksum-SHA256` and `X-Revision-Number` headers so the caller can verify
  what it got.

### Storage layout

```
storage/app/marketplace/
  flowchart_script/{script id}/1.zip
  flowchart_script/{script id}/2.zip
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

`FlowchartScriptPolicy` and `AiModelPolicy` add `upload`, `release` and `hardDelete` on top of the usual
five and are registered in `AppServiceProvider::boot()`. Routes that take a soft-deleted entry
(`restore`, `force`) are declared `->withTrashed()`. A Revision that does not belong to the entry in the
URL 404s (`assertBelongsTo`).

**Deletes.** `destroy` soft deletes (`scripts.delete`) and the index has a *Show deleted* toggle with
Restore. `force` (`scripts.hard_delete`) deletes every Revision file, the Revision rows and the Preview
Image links, then `forceDelete()`s the entry. Hard-deleting a single Revision is allowed even when it is
the only released one — the flash then warns how many recorded Downloads referenced it.
**Download rows are never deleted.**

**Preview Images.** `syncImages` takes an ordered `vault_file_ids[]`, validated to exist in `vault_files`
and to have an `image/*` mime, and replaces the `flowchart_script_images` rows with a fresh `sort_order`.
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

The sidebar gains **FlowChart Scripts** (`scripts.view`) and **AI Models** (`ai_models.view`).

`ActivityLogger::log` records create / update / delete / restore / upload / release / deprecate /
hard_delete.

## Tests

`tests/Feature/Marketplace/`:

- `MarketplaceSchemaTest` — tables exist, unique constraints throw.
- `RevisionModelTest` — polymorphism, `latestReleasedRevision()` semantics.
- `MarketplacePermissionsTest` — permission list, seeded `customer` role, `canAccessBackend()`, `isCustomerUser()`.
- `MachineBrandControllerTest`, `MachineModelControllerTest` — 403 without the permission, CRUD, slug
  generation, unique validation (including "unique per Machine Brand"), refusal to delete with dependents.
- `CustomerControllerTest` — 403 without the permission, CRUD, `show`, refusal to delete with Customer
  Users or AI Boxes.
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
- `FlowchartScriptControllerTest`, `AiModelControllerTest` — 403 without each permission, CRUD, unique
  name per Machine Model, soft delete / restore / force delete (files removed), upload, release,
  deprecate, download (records a `web` Download and returns `X-Checksum-SHA256`), cross-entry Revision
  404, hard delete keeping Download rows.
- `FlowchartScriptImagesTest` — sync order, reorder, clear, non-image and unknown Vault file refused,
  403 without `scripts.edit`.

Factories exist for all nine models.

## Remaining phases

4. RPA-TOOL API — `routes/api.php` under `/api/v1`, Sanctum login, AI Box auto-register/block, read + download endpoints.
5. AI Boxes & Downloads admin — box management, download log, counts, installed-revision view.
6. CMS strip (deferred) — possibly remove pages/banners/menus/llms/AI hub.

## See also

- [permissions](permissions.md)
- [vault](vault.md)
- [architecture/datastore](../architecture/datastore.md)
- [database/collections](../database/collections.md)
