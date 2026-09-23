# Overview

> Unysis Marketplace: Laravel 13 + PostgreSQL catalogue of AI Models and Scripts, with a React/Inertia admin SPA and an API for RPA-TOOL.

Last updated: 2026-09-23

## What it is

Unysis Marketplace is the internal catalogue where the UNYSIS team publishes the AI Models
and Scripts that run on UNYSIS Boxes, and from which RPA-TOOL fetches them. Vocabulary for
the domain (Revision, Machine Model, Customer, Team Member, UNYSIS Box, Download) is defined
in `CONTEXT.md` — use those words.

It is **not** a public-facing site. There is no public content surface: the admin SPA, the
media endpoints, the email webhook/unsubscribe routes and the RPA-TOOL API are all there is.
`/` redirects to the dashboard for signed-in Team Members and to the login page otherwise.

## Who it's for

- **Team Members** sign in to the admin SPA to manage the catalogue, upload Revisions and
  read the reports.
- **Customer Users** never see the admin. They authenticate through RPA-TOOL against the
  `/api/v1` endpoints, from a registered UNYSIS Box.

## Core capabilities

- Catalogue of Scripts and AI Models, organised by Machine Brand / Machine Model, with
  Preview Images and Customer labelling
- Immutable, numbered Revisions with a `draft -> released -> deprecated` lifecycle, checksum
  and optional ClamAV scan on upload
- RPA-TOOL API v1: Sanctum tokens bound to a UNYSIS Box, catalogue browsing and downloads
- UNYSIS Box registration, approval and blocking
- Download recording, filterable log with CSV export, and a Usage report (by Customer, by entry)
- Dashboard: stat cards, 30-day downloads chart, latest Revisions, recently seen Boxes —
  every panel gated on the viewer's permissions
- Media management (Vault) with a hardened upload pipeline
- Role-based permissions with per-resource policies and cached checks
- Activity logging, custom maintenance mode, email logs / suppression / delivery webhooks

## Key numbers

Source of truth is always the code; these numbers are snapshots:

- **42** permissions in `resource.action` format (`Role::availablePermissions()`)
- **5** seeded roles: `admin`, `editor`, `author`, `viewer`, `customer`
- **12** Policy classes under `app/Policies/`
- **5** Vault upload pipeline stages (+ optional ClamAV `SandboxedScan`)
- **158** registered routes
- **6** migration files (core, vault, logs, framework, marketplace, Sanctum tokens)
- **347** tests / **1316** assertions, green on SQLite and PostgreSQL
- RPA-TOOL rate limits: 60/min general, 20/min downloads, 5/min login

## See also

- [modules/marketplace](modules/marketplace.md) — catalogue schema, revisions, RPA-TOOL API
- [architecture/stack](architecture/stack.md) — stack and design decisions
- [modules/services](modules/services.md) — service layer overview
- [modules/permissions](modules/permissions.md) — how access control works
- [architecture/datastore](architecture/datastore.md) — PostgreSQL schema, ULID keys, no FKs
