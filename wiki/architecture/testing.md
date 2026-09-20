# Testing

> Test setup, conventions, and known gotchas.

Last updated: 2026-09-21

## Running tests

```bash
composer run test

# Single file
php artisan test tests/Feature/VaultUploadTest.php
```

## Setup

`phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. All 16 models are
plain Eloquent with no pinned connection, so this is a real, working override: the whole
suite runs against SQLite in-memory, no external database service required. Production
runs PostgreSQL — see [architecture/datastore](datastore.md).

**Before this was true:** every model previously pinned `$connection = 'mongodb'`, so
the SQLite override only ever changed the *default* connection and model queries still
hit a real MongoDB server fed the literal string `:memory:` by `config/database.php`,
which the Mongo driver rejected. All 97 tests errored at setup — the suite had no
regression value. This is why `architecture/testing.md` historically claimed "SQLite
in-memory" while actually requiring MongoDB; that claim is now true.

## Vite manifest workaround

Tests don't build frontend assets. Creating `public/hot` before each test makes
Vite switch to dev-server mode, which skips the manifest lookup. This file is
removed in `tearDown` to avoid side effects.

If you see `ViteManifestNotFoundException` in tests, check that this setup/teardown
is in place in the test class.

## Test coverage

Tests in `tests/Feature/` cover:
- Auth (login, logout, registration)
- Maintenance mode
- Profile management
- Vault upload, trash, and folder operations (incl. restore collisions, force-delete permissions, `folders.list?all=1`)
- Vault policies (`PolicyTest`)
- Menus (real `Edit.tsx` payload shape, dangerous URL schemes) and Banners controllers
- AI chat (mocked `AiService`)
- Public pages as HTML/Markdown and draft preview permissions

`tests/Unit/` covers `AiHttpClient`.

Currently missing coverage (investigate):
- AI Hub provider integrations (real HTTP paths)
- Redirect middleware

## CI

The GitHub Actions PHPUnit job runs the suite twice: once on SQLite (the default,
matching local `composer run test`) and once against a real PostgreSQL 17 service, to
catch SQLite-vs-PostgreSQL SQL dialect differences before they reach production. See
[architecture/datastore](datastore.md) for the one dialect difference (`app/Support/DateBucket.php`)
that couldn't be written in a dialect-neutral way.

## Gotchas

- No foreign-key constraints exist in the schema (deliberate — see
  [architecture/datastore](datastore.md)), so duplicate-key/uniqueness races are exercised
  by unique indexes, not FK behaviour.
- The `public/hot` Vite workaround is fragile — if Vite changes how it detects
  dev mode, this will break silently.
- New raw SQL should be written dialect-neutral (query builder) so it passes on both
  SQLite and PostgreSQL; if a dialect difference is unavoidable, follow the `DateBucket`
  pattern instead of branching inline.

## See also

- [architecture/datastore](datastore.md) — PostgreSQL migration, schema, `DateBucket`
- [database/collections](../database/collections.md) — table conventions
- [modules/vault](../modules/vault.md) — what VaultUploadTest is testing
