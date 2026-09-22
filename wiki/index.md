# Wiki Index

Content catalog for the untitled-cms wiki. Updated on every ingest.

## Core

| Page | Summary |
|------|---------|
| [overview](overview.md) | Project summary, capabilities, and key numbers |
| [discoverability](discoverability.md) | Day-0 search baseline, verified keyword surface, naming risks |

## Architecture

| Page | Summary |
|------|---------|
| [architecture/stack](architecture/stack.md) | Technology choices and key design decisions |
| [architecture/request-flow](architecture/request-flow.md) | How a request moves from browser to response |
| [architecture/middleware](architecture/middleware.md) | Web and API middleware stacks, rate limiters, what each layer does |
| [architecture/testing](architecture/testing.md) | Test setup, SQLite (tests) vs PostgreSQL (prod), known gotchas |
| [architecture/mongodb](architecture/mongodb.md) | Historical: why MongoDB was chosen, and why it was abandoned |
| [architecture/datastore](architecture/datastore.md) | PostgreSQL migration: ULID keys, schema layout, no FKs, `role_user`, `DateBucket` |

## Database

| Page | Summary |
|------|---------|
| [database/collections](database/collections.md) | Eloquent models, tables, and conventions |

## Frontend

| Page | Summary |
|------|---------|
| [frontend/ui-stack](frontend/ui-stack.md) | React/Inertia patterns, libraries, shared props |

## Modules

| Page | Summary |
|------|---------|
| [modules/services](modules/services.md) | app/Services/* overview and when to use each |
| [modules/vault](modules/vault.md) | Media manager: upload pipeline, config, storage |
| [modules/permissions](modules/permissions.md) | Role-based access control, policy classes, caching |
| [modules/ai-hub](modules/ai-hub.md) | AI provider config, usage tracking, integration patterns |
| [modules/marketplace](modules/marketplace.md) | Unysis Marketplace: catalogue schema, revisions, permissions, storage, RPA-TOOL API |
| [modules/email](modules/email.md) | Resend email pipeline, suppression, webhooks, unsubscribe flow |

---

*To add a page: create the file in the right subfolder, add a row here, append an entry to [log](log.md).*
