# Unysis Marketplace — Implementation Plan

Vocabulary: [CONTEXT.md](../CONTEXT.md). Decisions: [docs/adr](adr/). Built on the existing Laravel 13 + Inertia boilerplate; the public CMS surface (pages, banners, menus, AI hub) is left in place for now and removed in a later stage.

## Data model

All tables: ULID primary keys via `HasUlidKey`, indexed reference columns, no FK constraints (repo convention). Must run on SQLite (tests) and PostgreSQL (prod).

| Table | Columns (beyond id/timestamps) |
|---|---|
| `customers` | name, company, contact_name, contact_email, contact_phone, notes, is_active |
| `users` (add) | customer_id nullable — set only for Customer Users |
| `machine_brands` | name (unique), slug |
| `machine_models` | machine_brand_id, name, slug, description, is_active; unique (brand, name) |
| `flowchart_scripts` | machine_model_id, customer_id nullable, name, slug, description, created_by, deleted_at |
| `flowchart_script_images` | flowchart_script_id, vault_file_id, sort_order |
| `ai_models` | machine_model_id, customer_id nullable, name, slug, description, framework, input_size, labels (text), notes, created_by, deleted_at |
| `revisions` | revisable_type, revisable_id, number (int, unique per revisable), status (draft/released/deprecated), change_note, original_filename, disk_path, size_bytes, sha256, mime, uploaded_by, released_by, released_at, deprecated_at |
| `ai_boxes` | customer_id, motherboard_uuid (unique), name, location, machine_model_id nullable, status (pending/active/blocked), last_seen_at, last_ip, first_user_id |
| `downloads` | revision_id, revisable_type, revisable_id, user_id, ai_box_id nullable, source (api/web), ip, user_agent, created_at |

Derived, not stored: download totals, unique-box counts, "installed revision" per box (latest download per box per entry).

`Revision` is polymorphic (`revisable`) so AI Models and FlowChart Scripts share one revision/download implementation while remaining separate entities in the UI and API.

## Permissions (append to `Role::availablePermissions()`)

```
customers.view/create/edit/delete
machines.view/create/edit/delete            (brands + models)
scripts.view/create/edit/delete/upload/release/hard_delete
ai_models.view/create/edit/delete/upload/release/hard_delete
ai_boxes.view/edit/block
downloads.view
```

Seeder: role `customer` — no permissions, `backend_access = false`. `RequireAdminAccess` already rejects it; web login must also reject users whose only role is `customer`.

## Services (`app/Services/Marketplace/`)

- `RevisionService` — `upload(revisable, UploadedFile, note, user)`: validate ext (`h5` for AI Model, `zip` for Script), size cap from `config/marketplace.php`, optional ClamAV via existing `SandboxedScan` logic, store on private `marketplace` disk under `{type}/{id}/{number}.{ext}`, compute SHA-256, next number = max+1 per revisable. `release()`, `deprecate()`, `latestReleased(revisable)`.
- `DownloadService` — `record(revision, user, aiBox|null, source, request)`; `streamDownload()` returns `StreamedResponse` with `X-Checksum-SHA256` header.
- `AiBoxService` — `resolve(customer, motherboardUuid, ip)`: find-or-create, bump `last_seen_at`; throw if blocked.

## API (`routes/api.php`, prefix `/api/v1`, Sanctum `auth:sanctum`)

Add `routes/api.php` registration in `bootstrap/app.php` (`withRouting(api: ...)`). Throttle `60,1` reads, `20,1` downloads, `5,1` login.

| Method | Path | Notes |
|---|---|---|
| POST | `/login` | email, password, motherboard_uuid, box_name? → token (30 d, name = uuid), user, customer, ai_box. Rejects inactive user, non-customer role, blocked box. |
| POST | `/logout` | revoke current token |
| GET | `/me` | user + customer + ai_box (from token name) |
| GET | `/machine-brands`, `/machine-models?brand=` | |
| GET | `/customers` | id + company only (for filter) |
| GET | `/scripts?machine_model=&customer=&q=` | latest released revision summary + cover image URL |
| GET | `/scripts/{id}` | full detail, images, released revisions |
| GET | `/scripts/{id}/revisions` | released + deprecated |
| GET | `/scripts/{id}/download?revision=` | default latest released; deprecated only by explicit number; logs Download |
| GET | `/scripts/{id}/check-update?current=` | `{ update_available, latest }` |
| same five | `/ai-models…` | |

Every token carries the AI Box in its name; `me`/downloads resolve the box from the token, never from input. Resources via Eloquent API Resources.

## Admin UI (Inertia, `/admin/marketplace/...`)

- Customers: CRUD + Users tab (create Customer User, send invite/reset, revoke all tokens, deactivate).
- Machines: Brands + Models CRUD.
- FlowChart Scripts / AI Models: index with filters (Machine Model, Brand, Customer), create/edit form (+ gallery for Scripts via Vault picker), show page with Revisions table (upload dialog, release/deprecate, download), download stats.
- AI Boxes: index (customer, uuid, name, last seen, status), edit label, block/unblock, "installed" list.
- Downloads: log table with filters; counts on entry/revision pages.
- Sidebar entries for all of the above.

## Phases (each = one PR, tests + Pint + `npm run build` green, wiki updated)

1. **Foundation** — migrations, models, permissions, seeders, `config/marketplace.php`, private disk. Feature tests for models + permissions. `wiki/modules/marketplace.md` created.
2. **Customers & Machines admin** — CRUD controllers, policies, Inertia pages, Customer User management + web-login rejection.
3. **Catalogue admin** — Scripts + AI Models CRUD, `RevisionService`, upload/release/deprecate, gallery, soft/hard delete, web download logging.
4. **RPA-TOOL API** — Sanctum login, AI Box auto-register/block, all read + download endpoints, API resources, feature tests per endpoint including throttle + blocked-box cases.
5. **AI Boxes & Downloads admin** — box management, download log, counts, installed-revision view.
6. **CMS strip (deferred, decide later)** — remove pages/banners/menus/llms/AI hub if confirmed.

## Model selection for dispatch

Phases 1, 3, 4 → `opus` (schema, service layer, security, both DB dialects). Phase 2 and 5 → `opus` for controllers/policies, `sonnet` acceptable for Inertia pages once the controller shape exists.
