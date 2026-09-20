# MongoDB decision (superseded)

> Historical record: why MongoDB was originally chosen, and why it was abandoned. Current state is [architecture/datastore](datastore.md) — PostgreSQL.

Last updated: 2026-09-21

## Superseded

**This page is historical.** The application has moved to PostgreSQL; see
[architecture/datastore](datastore.md) for the current state and the reasoning behind
the move. This page is kept because the trade-offs below explain *why* the original
choice was made, and the "Test gap" section below is exactly what ended up forcing the
migration — see that page for how it played out.

## Original decision (no longer in effect)

Production database was MongoDB via `mongodb/laravel-mongodb`. All app models set
`protected $connection = 'mongodb'` and a collection name. There was no dual-write to SQL
in production.

## Why MongoDB

- Content shapes vary (pages body HTML, banner slides, menu trees, settings JSON)
- Vault metadata and AI hub config fit document storage well
- Fewer migrations for nested/flexible fields during early product evolution

## Costs / trade-offs

- Relational integrity and multi-document transactions are weaker / different than SQL
- Team must understand Mongo indexes (e.g. redirects, email logs, vault search)
- Eloquent relationship internals differ from classic SQL Laravel apps
- **Test dual-path:** PHPUnit uses SQLite in-memory (see [architecture/testing](testing.md)); CI may still run a Mongo service for the suite environment, but local tests favor SQLite speed

## Test gap

SQLite tests will not catch:

- Mongo-specific query operators or index requirements
- Collection-level quirks with soft deletes / ObjectId casting
- Production index miss latency

Mitigation: keep feature tests for authz and workflows; add targeted Mongo integration
tests only when a bug is Mongo-specific; document indexes in [database/collections](../database/collections.md).

## What actually triggered the migration

The "Test gap" above was not theoretical: `phpunit.xml` set `DB_DATABASE=:memory:`
while every model pinned the `mongodb` connection, and `config/database.php` fed that
`:memory:` value to the Mongo driver, which rejected it outright. **All 97 tests
errored at setup** — the suite had never actually run. That, not a scaling or
compliance need, is what drove the move to PostgreSQL. See
[architecture/datastore](datastore.md) for the full account.

## See also

- [architecture/datastore](datastore.md) — current datastore (PostgreSQL) and what changed
- [architecture/stack](stack.md) — stack summary
- [architecture/testing](testing.md) — current SQLite/PostgreSQL test setup
- [database/collections](../database/collections.md) — current models and tables
