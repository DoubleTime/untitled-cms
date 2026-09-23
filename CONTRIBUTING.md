# Contributing to Unysis Marketplace

Thank you for considering a contribution. This file covers the human workflow; the authoritative
instructions for both people and AI agents live in [`AGENTS.md`](AGENTS.md), and the domain vocabulary
is fixed in [`CONTEXT.md`](CONTEXT.md). Use the terms defined there verbatim in code, UI copy and docs.

## Getting Started

You need **PHP 8.4**, Composer, Node, and **PostgreSQL** (13+; CI runs 17).

1. Fork the repository and clone your fork.
2. Copy `.env.example` to `.env` and point it at your PostgreSQL database:
    ```env
    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=untitled_cms
    DB_USERNAME=postgres
    DB_PASSWORD=
    ```
3. Run the setup script — installs dependencies, generates the app key, migrates, seeds and builds assets:
    ```bash
    composer run setup
    ```
4. Start dev services (server, queue listener, Pail log viewer and Vite together):
    ```bash
    composer run dev
    ```

## Development Workflow

- Branch from **`master`** for all changes.
- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages:
    ```
    feat(marketplace): add Revision deprecation
    fix(api): reject a draft revision in ?revision=N
    docs: update the RPA-TOOL endpoint reference
    ```
- Run the test suite before opening a PR:
    ```bash
    composer run test              # clears config, then runs PHPUnit
    php artisan test --filter NameOfTest
    ```
- Run the PHP formatter, and the frontend build (which type-checks):
    ```bash
    ./vendor/bin/pint
    npm run build                  # tsc && vite build — TypeScript errors fail the build
    ```

## Testing

PHPUnit is configured in `phpunit.xml`, which sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`:
**the whole suite runs on SQLite in-memory** and needs no database service. Production runs
PostgreSQL, and CI runs the suite on both to catch dialect differences — see
`app/Support/DateBucket.php` for the one expression that genuinely differs.

Feature tests (`tests/Feature`) are preferred for controller, policy and workflow coverage; keep
`tests/Unit` for code that has no HTTP surface. Name test files after what they cover
(`RevisionServiceTest.php`, `UsageReportTest.php`).

Anything written against both dialects — raw SQL, grouped queries, date handling — must stay in the
SQLite/PostgreSQL common subset or go through a helper that switches, never an inline dialect check.

## Adding a New Module

A "module" here is a Model + Controller + Policy + Inertia page set.

### Backend

1. **Model** (`app/Models/`): plain `Illuminate\Database\Eloquent\Model` with the
   `App\Models\Concerns\HasUlidKey` trait — every table uses a **ULID string primary key**. Reference
   columns (`user_id`, `customer_id`, …) get an index but **no foreign-key constraint**; see
   [`wiki/architecture/datastore.md`](wiki/architecture/datastore.md) for why.
2. **Migration** (`database/migrations/`): the schema is grouped by responsibility rather than one
   file per change. Status columns are plain strings with a default, not native enums, so the same
   DDL runs on SQLite and PostgreSQL. The repo uses no PHP backed enums — allowed values are class
   constants.
3. **Service** (`app/Services/`): business logic lives in a service, not a controller. Controllers
   validate, authorise, call a service and render.
4. **Controller** (`app/Http/Controllers/`): authorise every action, through a policy
   (`Gate::authorize`) or the `can:` route middleware, which is aliased to
   `App\Http\Middleware\CheckPermission` — not Laravel's default.
5. **Policy** (`app/Policies/`): one per resource type, registered in `AppServiceProvider::boot()`.
6. **Permissions**: add `resource.action` strings to `Role::availablePermissions()` in
   `app/Models/Role.php`. That list is the single source of truth — never hardcode a copy or a count.
7. **Seeder**: `RoleSeeder` syncs the admin role from `availablePermissions()`; update it when a role
   other than admin should get the new permission.
8. **Routes**: add them to `routes/web.php` inside the admin group (`auth` + `verified` + `admin`),
   or to `routes/api.php` under `v1` for RPA-TOOL endpoints.

### Frontend

1. Create a page under `resources/js/Pages/YourModule/`, one file per controller.
2. Type its props with the `PageProps<T>` generic from `resources/js/types/index.d.ts`.
3. Use Inertia props and `useForm()` from `@inertiajs/react` — there is no separate API layer and no
   client-side router.
4. Follow the existing table and form patterns: the shared `DataTable` (TanStack Table), Zod
   validation, Sonner toasts via `use-flash-toast`.

## Code Style

- **PHP**: **Laravel Pint is the style authority.** Run `./vendor/bin/pint` before committing; if
  Pint is happy, the file is correctly formatted. There is no separate PSR-12 checklist to satisfy,
  and this codebase does **not** use `declare(strict_types=1)` — do not add it to new files.
- **TypeScript/React**: ESLint + Prettier (`eslint.config.js`). PascalCase components, camelCase
  functions and variables, names that match the feature area.
- **Editor**: follow `.editorconfig` — UTF-8, LF, 4-space indentation (2 in YAML), no trailing
  whitespace.
- **No debug code**: remove `dd()`, `dump()`, `console.log()` and `console.error()` before opening a PR.

## Documentation

The `wiki/` directory is a maintained knowledge base, not an afterthought. If you add a feature,
routing pattern, schema change, dependency or architectural decision, update the matching page under
`wiki/modules/`, `wiki/architecture/`, `wiki/database/` or `wiki/frontend/`, and append an entry to
`wiki/log.md` in the format `wiki/SCHEMA.md` defines. Decisions that closed off an alternative belong
in `docs/adr/`.

## Pull Request Checklist

- [ ] Tests pass (`composer run test`) — say which you ran and paste the summary line
- [ ] `./vendor/bin/pint` reports no changes
- [ ] `npm run build` exits 0 (TypeScript included)
- [ ] No secrets or environment-specific values committed
- [ ] New permissions added to `Role::availablePermissions()` and, if needed, `RoleSeeder`
- [ ] Migration or seeding steps listed in the PR description; screenshots for UI work
- [ ] `wiki/` updated and `wiki/log.md` appended
- [ ] PR description explains _why_, not just _what_

## Reporting Bugs

Issues are tracked as GitHub issues on `DoubleTime/untitled-cms` and managed with the `gh` CLI — see
[`docs/agents/issue-tracker.md`](docs/agents/issue-tracker.md). For security vulnerabilities, see
[SECURITY.md](SECURITY.md).
