# Permissions

> Role-based access control with policy classes and per-user caching.

Last updated: 2026-09-23

## Format

Permissions are strings in `resource.action` format (e.g. `scripts.edit`, `media.create`).

The canonical list is **`Role::availablePermissions()`** in `app/Models/Role.php`
(**42** strings as of 2026-09-23). Keep Policy classes, seeders, and UI in sync with that list.

## Components

### User model helpers
`User::hasPermission(string)`, `getCachedPermissions()`, `canAccessBackend()`, plus
`HasRoles` for `hasRole(string $slug)`. Permission cache is busted on role save and
`User::syncRoles()`.

### Policy classes (`app/Policies/`)
One per resource type. Policies call `hasPermission()` (and sometimes `hasRole('admin')`).
Laravel routes `$user->can('edit', $page)` / `$this->authorize(...)` to the right method.

### CheckPermission middleware
The `can` middleware alias points to this custom class, **not** Laravel's built-in
`can` middleware. Behaviour is similar but enforces project permission strings.

## Policy-first convention (controllers)

Prefer this order when adding or refactoring endpoints:

1. **Resource actions** → `$this->authorize(...)` / `Gate::authorize(...)` against a Policy
2. **Input validation** → FormRequest; `authorize()` may call Gate
3. **Cross-cutting flags** (e.g. draft preview) → policy ability (`viewAny` / dedicated method), not raw `hasPermission` in the controller
4. **Permission string catalog** → only defined in `Role::availablePermissions()`

Avoid scattering `auth()->user()->hasPermission('…')` in controllers when a policy already
covers the same check. Folder-scoped Vault rules stay on `VaultFolderPolicy` / `VaultFilePolicy`.

## Vault authorization rules

| Ability | Rule |
|---------|------|
| `VaultFolderPolicy::create($parent)` | global `media.create` **and** (no parent, or `update` on parent) |
| `VaultFilePolicy::create($folder)` | delegates to `VaultFolderPolicy::create` for the folder |
| `VaultFilePolicy::updateAny` | class-level `media.edit` (batch metadata updates) |
| `VaultFilePolicy::forceDelete` | global `media.delete` (irreversible) |
| `VaultFolderPolicy::forceDelete` | global `media.delete` **and** folder `delete` — purges the whole subtree |

Batch file endpoints require `viewAny` (`media.view`), then check each file individually.
`emptyTrash` purges only files the actor may `forceDelete`. Any endpoint that accepts
`folder_id` (upload, move) must authorize against the target folder.

## Caching

Permissions are cached per user (~60s). Role `saved` events bust member caches.
Bypassing the app to edit roles in the DB can leave stale cache until TTL expires.

## Gotcha: custom middleware alias

`->middleware('can:resource.action')` hits `CheckPermission`, not Laravel's default.

## See also

- [architecture/middleware](../architecture/middleware.md) — full middleware stack
- [architecture/request-flow](../architecture/request-flow.md) — where auth fits in the request lifecycle
