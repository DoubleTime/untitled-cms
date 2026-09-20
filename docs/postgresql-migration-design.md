# PostgreSQL Migration — Design

**Date:** 2026-09-21
**Status:** Design approved, not yet implemented
**Scope:** Replace MongoDB with PostgreSQL as the sole datastore.

> Supersedes an earlier dual-driver design (MongoDB *or* PostgreSQL, selected by config). That approach was dropped in favour of a single datastore: it required a runtime-aliased model base class, parallel repository implementations, split migration paths, and a second CI leg — all to keep a driver the project no longer wants.

## Goal

Untitled CMS is MongoDB-native: 16 models extend `MongoDB\Laravel\Eloquent\Model` and pin `protected $connection = 'mongodb'`. This design moves the application to PostgreSQL, using standard Eloquent throughout, and removes the MongoDB dependency.

**Non-goals:** keeping MongoDB working; migrating existing MongoDB data into PostgreSQL (see Open Items); PostgreSQL full-text search.

## Findings That Shape The Design

Established by reading the codebase and `vendor/mongodb/laravel-mongodb` v5.7:

1. **`mongodb/laravel-mongodb` v5 is not a separate hierarchy.** `MongoDB\Laravel\Eloquent\Model` extends `Illuminate\Database\Eloquent\Model` and applies a `DocumentModel` trait. Dropping Mongo therefore means removing a trait and a base class, not restructuring the model layer.
2. **`protected $collection` is inert in v5.** The package has no `getTable()` override and no `$collection` support. Table names already resolve by Laravel convention, and every declared collection name matches it (`ActivityLog` → `activity_logs`). All 16 declarations are dead code.
3. **No relational schema exists anywhere.** The 17 migrations are vestigial — `create_roles_table` creates only `id` and `timestamps()`. Field definitions live only in `$fillable`/`$casts`, so the schema must be authored from scratch.
4. **The test suite cannot currently run at all.** `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. The connection override is ignored because models pin `mongodb`, but the database-name override is *not* ignored — `config/database.php:39` feeds `env('DB_DATABASE')` to the Mongo connection, which rejects `:memory:`. All 97 tests error with `Invalid database name: ':memory:'`. Supplying a real Mongo database name surfaces a second failure: `RefreshDatabase` uses transactions, which standalone `mongod` does not support. Fixing this is a consequence of the migration, not extra work.
5. **Three raw aggregation pipelines** exist, in `DashboardController`, `EmailLogController`, and `VaultFolderController`. Each becomes an ordinary query-builder expression.
6. **`belongsToMany` changes shape.** laravel-mongodb stores ID arrays on both documents; SQL needs a `role_user` pivot table. The relationship code is unchanged — only the table must exist.

### Pre-existing bugs this work must fix

- **`Role::$permissions` has no array cast.** `syncPermissions(array)` assigns an array and `hasPermission()` does `in_array($permission, $this->permissions ?? [])`. Mongo stores arrays natively, so it works today. On PostgreSQL the value round-trips as a string and `in_array` fails silently — breaking the permission system. Add `'permissions' => 'array'`.
- **`Redirect` declares no `$casts`.** `active` (bool) and `type` (301/302 int) depend on Mongo's native typing. Add `'active' => 'boolean'`, `'type' => 'integer'`.

## Architecture

### Models

All 16 models extend `Illuminate\Database\Eloquent\Model` and use `HasUlids`. `User` extends `Illuminate\Foundation\Auth\User`. Each model drops `protected $connection` and the inert `protected $collection`. Casts, fillables, relationships, and soft deletes are unchanged.

A `HasUlids` trait application per model is repetitive; instead an `App\Models\Concerns\HasUlidKey` trait wraps it, giving one place to change key strategy later.

### Primary keys — ULID

`$table->ulid('id')->primary()` throughout. ULIDs are strings, so `types/index.d.ts`'s `id: string` contract holds unchanged; only the explanatory comment on line 8 needs updating. ULIDs are also lexically sortable, preserving the rough creation-order semantics ObjectIds provided.

Chosen over bigint because bigint would break the frontend's string contract, and over UUIDv4 because v4 is unordered.

### Migrations

The 17 vestigial migrations are deleted and replaced with authored schema in `database/migrations/`, grouped into five files by responsibility (core, content, vault, logs, framework). No driver-conditional loading is needed.

**References carry no foreign-key constraints.** `author_id`, `user_id`, `folder_id`, `parent_id`, `role_id`, `owner_id`, `subject_id`, `context_id` become indexed ULID columns. Mongo enforced no referential integrity, so the existing application code does not expect cascade or restrict behaviour; adding constraints now would turn currently-succeeding deletes into runtime errors. Indexes give the lookup performance without changing semantics. Constraints can be added later as a deliberate, separately-tested change.

**Column mapping:**

| Source | PostgreSQL | Notes |
|---|---|---|
| `'array'` cast (12 fields) | `jsonb` | `json` on SQLite; Laravel's array cast handles both |
| `'encrypted'` (`AiHub::api_key`) | `text` | ciphertext exceeds plaintext length |
| `'hashed'` (`User::password`) | `string(255)` | |
| `'datetime'` casts | `timestamp` nullable | |
| `'boolean'` / `'integer'` casts | `boolean` / `integer` | |

Polymorphic pairs (`subject_type`/`subject_id`, `resource_type`/`resource_id`, `context_type`/`context_id`) get composite indexes — every lookup uses both halves.

**Indexes** mirror what the deleted Mongo index migrations reveal as hot: `suppressed_emails.email` unique, `vault_folders` unique composite, `vault_files` search fields, `email_logs` lookups — plus `pages.slug`, `banners.slug`, `settings.key`, `redirects.from_path`, `users.email` unique.

**Vault search** uses ordinary indexes with `ILIKE`, not tsvector/GIN. Full-text search can land later as its own change if search quality becomes a real complaint.

### Aggregations

The three `::raw()` pipelines become query-builder code inline in their controllers. With a single datastore there is no abstraction to justify — a repository layer here would be indirection with one implementation.

Translation notes:
- `count(delivered_at)` ignores NULLs, reproducing Mongo's `$cond` on `$ne: null`.
- `$facet` becomes `sum(case when … then 1 else 0 end)` in a single row — deliberately not PostgreSQL `FILTER`, so identical SQL runs on SQLite under test.
- Date bucketing differs between PostgreSQL (`to_char`) and SQLite (`strftime`). A small `dateBucket()` helper handles it, since tests run on SQLite and production on PostgreSQL.

### Dependency removal

`mongodb/laravel-mongodb` is removed from `composer.json`, along with `MongoDBServiceProvider` in `bootstrap/providers.php` and the `mongodb` connection block in `config/database.php`. `ext-mongodb` is no longer required.

### `_id` normalization

Seven call sites reference `_id` directly and must use the primary key instead:

| Location | Change |
|---|---|
| `UserController.php:98`, `:116`, `:134` | `whereIn('_id', $ids)` → `whereKey($ids)` |
| `VaultFolderController.php:39`, `:56` | `pluck('_id')` → `pluck('id')` |
| `VaultFolderController.php:110` | `where('_id','!=',…)` → `whereKeyNot(…)` |
| `Role.php:35` | `$role->users()->pluck('_id')` → `pluck('id')` |
| `types/index.d.ts:8` | update comment |

## Testing

`phpunit.xml` already targets SQLite in-memory; once models stop pinning `mongodb`, those settings take effect and the suite runs for the first time. The 20 feature tests (6 under `Auth/`) and 2 unit tests become the regression suite for the migration.

Tests currently call `User::truncate()` and similar in `setUp()` alongside `RefreshDatabase`, a workaround for Mongo's lack of transaction support. These become redundant and are removed.

New coverage: a `Role::hasPermission()` test asserting array round-trip, and a schema test asserting every expected table exists.

**Production runs PostgreSQL while tests run SQLite.** This is standard Laravel practice but is a real gap — SQLite is more permissive about types and silently accepts some invalid SQL. Mitigated by keeping all SQL dialect-neutral and by a CI leg running the suite against real PostgreSQL.

## Risks

| Risk | Mitigation |
|---|---|
| Schema derived from `$fillable` may miss dynamically-written attributes | Surfaces as test failures; audit `->update([...])` and direct attribute writes during implementation |
| SQLite/PostgreSQL divergence hides bugs | CI leg against real PostgreSQL |
| Existing MongoDB data is abandoned | Explicit non-goal; see Open Items |
| No FK constraints means orphaned references stay possible | Matches current behaviour exactly; revisit as a separate change |

## Documentation Impact

- `CLAUDE.md`: stack, database section, and the incorrect testing claim.
- `wiki/`: datastore page under `architecture/`, update `wiki/index.md`, append to `wiki/log.md`.
- `.env.example`: PostgreSQL variables.

## Open Items

- **Data migration from the existing MongoDB install is out of scope.** If wanted, it is a standalone script: read each collection, map ObjectId references to freshly-generated ULIDs via a lookup table, and insert. Worth doing before the Mongo database is discarded, and worth its own design.
