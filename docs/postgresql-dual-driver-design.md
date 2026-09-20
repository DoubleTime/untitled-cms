# PostgreSQL Dual-Driver Support — Design

**Date:** 2026-09-20
**Status:** Design approved, not yet implemented
**Scope:** Support MongoDB *or* PostgreSQL as the datastore, selected by configuration. One driver active per deployment.

## Goal

Untitled CMS is MongoDB-native: all 16 models extend `MongoDB\Laravel\Eloquent\Model` and pin `protected $connection = 'mongodb'`. This design adds PostgreSQL as an alternative datastore without duplicating the model layer, and without changing the frontend contract.

Non-goals: running both datastores simultaneously; migrating existing Mongo data into Postgres; PostgreSQL full-text search.

## Findings That Shape The Design

Established by reading the codebase and `vendor/mongodb/laravel-mongodb` v5.7:

1. **`mongodb/laravel-mongodb` v5 is not a separate hierarchy.** `MongoDB\Laravel\Eloquent\Model` extends `Illuminate\Database\Eloquent\Model` and applies a `DocumentModel` trait. The two drivers differ by one trait, not by base class.
2. **`protected $collection` is inert in v5.** The package has no `getTable()` override and no `$collection` support. Table names already resolve through Laravel's convention, and every declared collection name happens to match it (`ActivityLog` → `activity_logs`, `AiHub` → `ai_hubs`). These 16 declarations are dead code.
3. **No relational schema exists anywhere.** The 17 migrations are vestigial — `create_roles_table` creates only `id` and `timestamps()`. Mongo is schemaless, so field definitions live only in `$fillable`/`$casts`. Postgres schema must be authored from scratch.
4. **Tests do not run on SQLite today.** `phpunit.xml` sets `DB_CONNECTION=sqlite`, but that only changes `database.default`; every model pins `mongodb` explicitly, which wins. The test suite talks to a live MongoDB. `CLAUDE.md` states otherwise and is incorrect.
5. **Three raw aggregation pipelines** exist, in `DashboardController`, `EmailLogController`, and `VaultFolderController`. They are also the only code in the app that cannot be tested without a live MongoDB.

### Pre-existing portability bugs (must fix)

- **`Role::$permissions` has no array cast.** `syncPermissions(array $permissions)` assigns an array; `hasPermission()` does `in_array($permission, $this->permissions ?? [])`. Mongo stores arrays natively, so this works today. On Postgres the value round-trips as a string and `in_array` fails — silently breaking the entire permissions system. Add `'permissions' => 'array'`.
- **`Redirect` declares no `$casts`.** `active` (bool) and `type` (301/302 int) depend on Mongo's native typing. Add `'active' => 'boolean'`, `'type' => 'integer'`.

## Architecture

### 1. Aliased base class

Two thin abstract bases, both extending the *same* Laravel model class:

```php
// app/Models/Base/MongoModel.php
abstract class MongoModel extends \Illuminate\Database\Eloquent\Model
{
    use \MongoDB\Laravel\Eloquent\DocumentModel;

    protected $primaryKey = '_id';
    protected $keyType = 'string';
}

// app/Models/Base/SqlModel.php
abstract class SqlModel extends \Illuminate\Database\Eloquent\Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUlids;
}
```

`App\Models\Base\Model` is a runtime alias to one of them, registered in a dedicated `DatastoreServiceProvider::register()`, listed **first** in `bootstrap/providers.php`:

```php
class_alias(
    config('database.default') === 'mongodb' ? MongoModel::class : SqlModel::class,
    'App\Models\Base\Model'
);
```

**Why a service provider and not composer `autoload.files`:** an earlier draft of this design used `autoload.files`, which does not work. `env()` returns `null` at autoload time because Dotenv runs during `LoadEnvironmentVariables`, part of bootstrap and therefore after the autoloader. Laravel's bootstrap order is `LoadEnvironmentVariables` → `LoadConfiguration` → `RegisterProviders` → `BootProviders`, so by `register()` the config is loaded and `config()` is safe.

**Why this is early enough:** PHP autoloads a class on first *use*, not on constant resolution. `Setting::class` in `AppServiceProvider` resolves to a string without loading the class, and no model is instantiated during provider registration. Models first load when routes and controllers run, well after boot.

Consequence: the driver is selected by normal config, and cached config works. The provider must be registered before `AppServiceProvider`.

`User` extends `MongoDB\Laravel\Auth\User` rather than the plain model, so it needs its own pair aliased to `App\Models\Base\AuthUser`.

**Known cost:** static analysis and IDE autocomplete cannot follow a runtime `class_alias`; type resolution on inherited model methods is lost. A PHPStan-only stub file mitigates but does not eliminate this.

**Rejected alternatives:** parallel model hierarchies (16 models become 32 plus shared traits; drift is inevitable) and a repository layer (rewrites every controller and service, disproportionate to the goal).

### 2. Model sweep

For each of the 16 models: change `extends Model` to `extends \App\Models\Base\Model`, delete `protected $connection`, delete the inert `protected $collection`. Casts, fillables, relationships, and soft deletes are unchanged.

### 3. Primary keys — ULID

`$table->ulid('id')->primary()` on the SQL side, ObjectId on Mongo. Both produce string keys, so `types/index.d.ts`'s `id: string` contract holds on either driver; only the explanatory comment on line 8 changes.

ULID over UUIDv4 because it is lexically sortable like ObjectId, keeping ordering semantics aligned across drivers. ULID over bigint because bigint would break the string contract and force an ID remap across every reference.

### 4. Migrations

```
database/migrations/          shared, driver-neutral (framework always loads)
database/migrations/sql/      the 16 relational tables
database/migrations/mongodb/  index-creation migrations
```

`AppServiceProvider::boot()` registers exactly one driver path via `loadMigrationsFrom()`. Per-file driver guards were rejected — 33 guards rot.

Moving to `mongodb/`: `create_suppressed_emails_index`, `create_email_logs_indexes`, `add_search_indexes_to_vault_files`, `add_unique_index_to_vault_folders`, `rename_resend_id_to_provider_message_id_in_email_logs`. The vestigial stubs are replaced outright by real schema.

**Many-to-many needs a pivot table.** `User::roles()` and `Role::users()` both call `belongsToMany`. laravel-mongodb implements this by storing ID arrays on both documents (`role_ids`, `user_ids`) with no pivot collection; SQL expects a `role_user` pivot table with `user_id` and `role_id` ULID columns and a unique composite index. The relationship code itself needs no change — each driver's `belongsToMany` does the right thing — but the pivot migration is mandatory on SQL, and `->sync()` in `User::syncRoles()` works identically on both.

**References carry no foreign-key constraints.** `author_id`, `user_id`, `folder_id`, `parent_id`, `role_id`, `owner_id`, `subject_id`, `context_id` become indexed ULID columns only. Mongo enforces no referential integrity today; adding FKs would make deletes that succeed on Mongo throw on Postgres. Behavioural parity between drivers outranks database purity here, and it sidesteps migration ordering.

**Column mapping:**

| Source | Postgres | Notes |
|---|---|---|
| `'array'` cast (12 fields) | `jsonb` | `json` on SQLite; Laravel's array cast handles both |
| `'encrypted'` (`AiHub::api_key`) | `text` | ciphertext exceeds plaintext length |
| `'hashed'` (`User::password`) | `string(255)` | |
| `'datetime'` casts | `timestamp` nullable | |
| `'boolean'` / `'integer'` casts | `boolean` / `integer` | |

Polymorphic pairs (`subject_type`/`subject_id`, `resource_type`/`resource_id`, `context_type`/`context_id`) get composite indexes — every lookup uses both halves.

**Indexes:** mirror what the Mongo index migrations reveal as hot (`suppressed_emails.email` unique, `vault_folders` unique composite, `vault_files` search fields, `email_logs` lookups), plus `pages.slug`, `banners.slug`, `settings.key`, `redirects.from_path`, `users.email` unique.

**Vault search** uses ordinary indexes with `ILIKE`, not tsvector/GIN. Postgres FTS would diverge result ranking between drivers; it can land later as its own change if search quality becomes a real complaint.

16 table migrations; `vault_files` is widest at 19 columns.

### 5. Stats abstraction

```php
interface EmailStatsRepository {
    public function dailyCounts(CarbonInterface $since): Collection;  // 'Y-m-d' => [sent, delivered]
    public function statusTotals(): array;                            // total, delivered, opened, bounced
}

interface VaultStatsRepository {
    public function folderTotals(array $folderIds): Collection;       // folder_id => [files_count, files_size]
}
```

`Mongo*` implementations wrap the existing pipelines verbatim; `Sql*` implementations use the query builder. Bound by driver in `AppServiceProvider`. Controllers inject the interface and drop their `raw()` calls. The existing `Cache::remember('email_log_stats', 300)` stays in the controller, above the abstraction.

Translation notes:
- `count(delivered_at)` ignores NULLs natively, reproducing Mongo's `$cond` on `$ne: null` exactly.
- `$facet` becomes `sum(case when … then 1 else 0 end)` in a single row — deliberately not Postgres `FILTER`, so identical SQL runs on SQLite under test.
- Date bucketing is the one real divergence: `to_char(created_at,'YYYY-MM-DD')` on Postgres, `strftime('%Y-%m-%d')` on SQLite. A two-line driver switch inside `SqlEmailStatsRepository`. Grouping in PHP was rejected because email volume is unbounded.

### 6. Portable IDs

A `HasPortableId` concern normalizes `_id` to `getKeyName()`. Call sites:

| Location | Change |
|---|---|
| `UserController.php:116`, `:134` | `whereIn('_id', $ids)` → `whereKey($ids)` |
| `VaultFolderController.php:39`, `:56` | `pluck('_id')` → `pluck('id')` |
| `VaultFolderController.php:110` | `where('_id','!=',$folder->id)` → `whereKeyNot($folder->id)` |
| `Role.php:35` | `$role->users()->pluck('_id')` → `pluck((new User)->getKeyName())` |
| `types/index.d.ts:8` | update comment |

`VaultController.php:263`'s `_id` comment documents a driver serialization workaround in `chunk()`, not ID semantics. No change needed.

## Testing

Point `phpunit.xml` at SQLite in-memory. Because models no longer pin a connection, this finally does what `CLAUDE.md` already claims. The 20 existing feature tests (including 6 under `Auth/`) plus 2 unit tests become the regression suite proving the SQL path works — this is the primary verification for the whole design.

New coverage required:
- Unit tests for all four stats repository implementations (previously untestable without live MongoDB).
- A `Role::hasPermission()` test asserting array round-trip, guarding the cast bug above.

**The Mongo path needs its own CI job against a live MongoDB.** Without it, dual-driver rots into single-driver within a release, since the default test run would only exercise SQL.

## Risks

| Risk | Mitigation |
|---|---|
| Mongo-only method called in shared code, breaking on Postgres | Stats abstraction removes the known cases; Mongo CI job catches regressions |
| Lost static analysis on aliased base | PHPStan stub file |
| Driver choice locked to env, not cached config | Accepted; documented in deployment notes |
| Schema authored from `$fillable` may miss fields written dynamically | Audit for `->update([...])` and direct attribute writes outside `$fillable` during implementation |

## Documentation Impact

- `CLAUDE.md`: correct the SQLite testing claim; document driver selection.
- `wiki/`: per project convention, add a datastore-abstraction page under `architecture/`, update `wiki/index.md`, append to `wiki/log.md`.
- `.env.example`: document `DB_CONNECTION=pgsql` and its required vars.

## Open Items

- Data migration from an existing Mongo install into Postgres is explicitly out of scope. If needed later, ULID PKs mean IDs must be regenerated and every reference remapped — worth designing before any production Mongo install considers switching.
