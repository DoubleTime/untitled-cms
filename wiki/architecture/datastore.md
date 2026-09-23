# Datastore

> PostgreSQL is the production and CI datastore; MongoDB has been fully removed.

Last updated: 2026-09-21

## Decision

**Production database is PostgreSQL.** All 16 models are plain Eloquent
(`Illuminate\Database\Eloquent\Model`; `User` extends `Illuminate\Foundation\Auth\User`).
`mongodb/laravel-mongodb`, its service provider, and the `mongodb` connection block are
gone from the codebase entirely.

### Why the move off MongoDB

[architecture/mongodb](mongodb.md) recorded the original MongoDB decision and its known
costs: weaker relational integrity, Eloquent relationship internals that differed from
classic SQL Laravel, and — critically — a test suite that could not actually exercise
MongoDB-specific behaviour with confidence.

The concrete trigger: before this migration, `phpunit.xml` set `DB_DATABASE=:memory:`
while every model pinned the `mongodb` connection. `config/database.php` fed that
`:memory:` value straight to the Mongo driver, which rejected it — **all 97 tests
errored at setup**. The suite had zero regression value. That gap, not a workload or
scaling concern, is why the migration happened.

## ULID primary keys

Primary keys are now 26-character ULID strings (`App\Models\Concerns\HasUlidKey`, which
wraps Laravel's `HasUlids` trait) instead of MongoDB ObjectIds. The trait is applied
once and shared across all 16 models so the key strategy can change in one place.

This was chosen deliberately to keep the frontend contract stable: `resources/js/types/index.d.ts`
already typed `id` as `string`, so nothing in `resources/js/` needed to change. ULIDs
also sort lexically by creation time, which ObjectIds did too — so ordering-by-id
behaviour carries over unchanged.

## Schema

The relational schema lives in `database/migrations/`, authored from scratch — there
was no prior relational schema to migrate from; the old migration files were vestigial
stubs that only created `id` + timestamps. It was grouped into five files by
responsibility:

| File | Covers |
|------|--------|
| `2026_09_21_000001_create_core_tables.php` | `users`, `roles`, `role_user`, `settings` |
| `2026_09_21_000003_create_vault_tables.php` | `vault_folders`, `vault_files`, `vault_folder_permissions` |
| `2026_09_21_000004_create_log_tables.php` | `activity_logs`, `vault_audit_logs`, `email_logs`, `suppressed_emails` |
| `2026_09_21_000005_create_framework_tables.php` | Laravel framework tables: `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` |
| `2026_09_22_000001_create_marketplace_tables.php` | `customers`, `machine_brands`, `machine_models`, `scripts`, `script_images`, `ai_models`, `revisions`, `unysis_boxes`, `downloads`, and `users.customer_id` |
| `2026_09_23_000001_create_personal_access_tokens_table.php` | Sanctum tokens for the RPA-TOOL API |

The Phase 6 CMS strip deleted `2026_09_21_000002_create_content_tables.php` outright
(`pages`, `banners`, `menus`, `redirects`, `chat_sessions`) and cut `ai_hubs` out of the core
file. Nothing was deployed at that point, so the migrations were edited in place rather than
given drop migrations — see [modules/marketplace](../modules/marketplace.md).

## No foreign-key constraints

Reference columns (`author_id`, `user_id`, `folder_id`, etc.) carry **indexes but no
foreign-key constraints**. This is deliberate, not an oversight: MongoDB enforced no
referential integrity, and the application code was written against that assumption
(e.g. deletes that leave orphaned references). Adding FK constraints now would turn
currently-succeeding deletes into runtime errors. If this is revisited, it needs an
audit of every delete path first, not just a migration change.

## `role_user` pivot

A `role_user` pivot table now exists purely because SQL needs one for a many-to-many
relationship. MongoDB stored `belongsToMany` as ID arrays on both the `users` and
`roles` documents; the pivot table replaces that, but the Eloquent relationship code
in `User`/`Role` is unchanged. See the comment at the top of `role_user`'s creation
in `2026_09_21_000001_create_core_tables.php`.

## Aggregations → query builder SQL

Three raw MongoDB aggregation pipelines were rewritten as Laravel query-builder SQL.
The one dialect difference that couldn't be abstracted away — date bucketing for
time-series aggregation (`strftime` on SQLite vs `to_char` on PostgreSQL) — is isolated
in `app/Support/DateBucket.php::expression()`. Any new time-bucketed query should use
this helper rather than hand-rolling a dialect-specific expression.

## Tests vs production

Tests run on SQLite in-memory (`phpunit.xml`); production runs PostgreSQL. Because both
are real SQL dialects now (unlike the old SQLite-vs-Mongo split), the suite has actual
regression value on both. CI additionally runs the full suite against a real PostgreSQL
service (see `.github/workflows/`), specifically to catch the SQLite/PostgreSQL dialect
gaps that `DateBucket` documents — 97/97 tests (245 assertions) pass on both locally.

Write new SQL as dialect-neutral query-builder calls where possible. When a dialect
difference is genuinely unavoidable, follow the `DateBucket` pattern: isolate it behind
a small helper keyed on `DB::connection()->getDriverName()`, rather than scattering
raw dialect-specific SQL through application code.

## See also

- [architecture/mongodb](mongodb.md) — the original MongoDB decision this migration reversed, and the test-suite gap that triggered the move
- [architecture/testing](testing.md) — SQLite test setup and CI details
- [architecture/stack](stack.md) — stack summary
- [database/collections](../database/collections.md) — table conventions and per-table notes
