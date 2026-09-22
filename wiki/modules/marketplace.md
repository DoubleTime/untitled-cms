# Marketplace

> Catalogue of AI Models and FlowChart Scripts that run on UNYSIS AI Boxes, fetched by RPA-TOOL

Last updated: 2026-09-22

Vocabulary is fixed in [`CONTEXT.md`](../../CONTEXT.md) — use those terms verbatim in code, UI copy and docs.
The implementation plan is [`docs/marketplace-plan.md`](../../docs/marketplace-plan.md); decisions are in [`docs/adr/`](../../docs/adr).

**Status: Phase 1 (Foundation) shipped.** Schema, models, permissions, config and the private disk exist.
There are no controllers, routes, services or UI yet.

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
  implementation while staying separate entities in the UI and the API. No morph map is registered,
  so `revisable_type` holds the FQCN.
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

## Tests

`tests/Feature/Marketplace/`: `MarketplaceSchemaTest` (tables exist, unique constraints throw),
`RevisionModelTest` (polymorphism, `latestReleasedRevision()` semantics), `MarketplacePermissionsTest`
(permission list, seeded `customer` role, `canAccessBackend()`, `isCustomerUser()`).
Factories exist for all nine models.

## Remaining phases

2. Customers & Machines admin — CRUD, policies, Inertia pages, Customer User management, web-login rejection.
3. Catalogue admin — Scripts + AI Models CRUD, `RevisionService`, upload/release/deprecate, gallery, hard delete.
4. RPA-TOOL API — `routes/api.php` under `/api/v1`, Sanctum login, AI Box auto-register/block, read + download endpoints.
5. AI Boxes & Downloads admin — box management, download log, counts, installed-revision view.
6. CMS strip (deferred) — possibly remove pages/banners/menus/llms/AI hub.

## See also

- [permissions](permissions.md)
- [vault](vault.md)
- [architecture/datastore](../architecture/datastore.md)
- [database/collections](../database/collections.md)
