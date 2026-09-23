# UI Stack

> React 19 + TypeScript + Inertia.js admin SPA patterns and conventions.

Last updated: 2026-09-23

## Stack

- **React 19 + TypeScript** — component framework
- **Inertia.js** — props-based routing (no client-side router, no API layer)
- **Tailwind CSS v4** — utility-first styling with native CSS `@theme` and OKLCH variables
- **Shadcn/Radix UI** — accessible component primitives using unified `radix-ui` dependency
- **Vite 8** — build tool with HMR (Rolldown + Oxc)

## Key libraries by concern

| Concern | Library |
|---------|---------|
| Data grids | TanStack Table v9 — `useTable` with the shared `dataTableFeatures` and `DataTableColumnDef<T>` from `Components/Common/DataTable.tsx` |
| Drag and drop | @dnd-kit (Vault, Script Preview Image ordering) |
| Charts | Recharts (dashboard downloads chart, Usage report) |
| Form validation | Zod |
| Toast notifications | Sonner |
| File uploads | react-dropzone (Vault uploads, Revision uploads) |
| Icons | lucide-react |

## Page structure

Each page in `resources/js/Pages/` corresponds to a controller. Inertia renders
the matching page component and passes controller data as props. There are no
separate API calls — all data arrives with the page load.

```
resources/js/Pages/
  Auth/
  Dashboard.tsx        ← stat cards, downloads chart, latest Revisions, recent Boxes
  Marketplace/
    Customers/ MachineBrands/ MachineModels/
    Scripts/ AiModels/
    UnysisBoxes/ Downloads/
    Reports/Usage.tsx  ← date range, totals, by Customer, by entry
  Vault/               ← media manager
  Users/
  Roles/
  EmailLogs/
  Activity/
  Settings/
  Profile/
  Public/Unsubscribed.tsx
```

Dashboard and report panels are **permission-gated server-side**: a panel the Team Member
cannot see arrives as `null` in the props rather than being hidden in the component.

## Shared props (always available)

Injected by `HandleInertiaRequests` middleware — this is the whole list:
- `appName`, `appVersion` — shown in the sidebar header as `v{appVersion}`
- `auth.user` — current user object
- `auth.permissions` — array of permission strings for the current user
- `auth.canAccessBackend` — boolean
- `settings` — public settings key/value
- `passwordRulesString` — human-readable password policy for auth forms
- `flash.success`, `flash.error`

## Sidebar

`Components/app-sidebar.tsx` has three groups — **Platform** (Dashboard), **Marketplace**
(catalogue, UNYSIS Boxes, Downloads, Usage Report) and **Administration** (Vault, Users,
Roles, Email Logs, Activity, Settings). Every entry is gated on its own permission.

## Inertia patterns

- Use `useForm()` from `@inertiajs/react` for forms — handles loading state,
  errors, and submission automatically.
- Use `router.visit()` or `<Link>` for navigation — never `window.location`.
- Props are typed — check the corresponding controller's `Inertia::render()` call
  to see what's available on a given page.

## No API layer

There is no separate REST/GraphQL API consumed by the frontend. If you need data
that isn't in the initial page props, add it to the controller's props or use a
partial Inertia reload — not a fetch call.

**Exception — Media Vault.** The Vault browser and `Components/Vault/VaultPicker.tsx` call JSON endpoints
under `admin/vault/*` with axios. Their state and handlers live in
`resources/js/hooks/useVaultBrowser.ts`, with dialogs and pickers in `Components/Vault/` (`VaultDialogs`, `VaultUploadDialog`, `VaultBreadcrumb`, `VaultThumbnail`, `UploadPipelineTracker`). Read error text
from the response's `error`/`message` fields. See [modules/vault](../modules/vault.md#api-contracts).

## See also

- [architecture/request-flow](../architecture/request-flow.md) — how Inertia fits in the request flow
- [architecture/middleware](../architecture/middleware.md) — HandleInertiaRequests and shared props
