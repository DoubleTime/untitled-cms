# Request Flow

> How a request moves from browser (or RPA-TOOL) to response.

Last updated: 2026-09-23

## Admin flow (Inertia)

```
Browser → Laravel Route → web middleware → Controller → Service/Model → PostgreSQL
                                                     ↓
                                    Inertia::render($page, $props) → React Page Component
```

Inertia.js is the bridge: controllers return `Inertia::render('PageName', $props)`
rather than JSON or HTML templates. Props flow directly into React components.
There is **no client-side router** — navigation is server-driven, and there is no
separate frontend API layer (the Vault browser's JSON endpoints are the one exception,
see [modules/vault](../modules/vault.md#api-contracts)).

## Inertia shared props

`HandleInertiaRequests` injects these on every page load:

- `appName`, `appVersion` (`config('app.version')`)
- `auth.user`, `auth.permissions` (cached permission strings), `auth.canAccessBackend`
- `settings` — public settings key/value
- `passwordRulesString`
- `flash.success`, `flash.error`

## Route groups

- **Public (no auth):** `/media/{uuid}.{extension}` (and the legacy `/media/{uuid}`),
  `POST /webhooks/email`, `/unsubscribe/{token}`, plus the auth routes in `routes/auth.php`
- **`/`** — a redirect, not a page: to `admin.dashboard` for an authenticated caller with
  backend access, otherwise to `login`. A comment in `routes/web.php` notes that a public
  landing page may replace this later.
- **Profile:** `auth` only (no admin middleware)
- **Admin:** `/admin` prefix, `auth` + `verified` + `admin` (`RequireAdminAccess`)
- **API:** `/api/v1`, `auth:sanctum` + `unysis-box` + named throttles

There is **no public content surface** — no page routes, no feeds, no `Accept`-based
content negotiation. Everything a person sees is either an auth screen or the admin SPA.

## API flow (RPA-TOOL)

```
RPA-TOOL → /api/v1/... → auth:sanctum → ResolveUnysisBox → throttle:rpa* → Controller → PostgreSQL
                                                                        ↓
                                                        JSON, or a StreamedResponse for a download
```

The Sanctum token's *name* is the UNYSIS Box's motherboard UUID; `ResolveUnysisBox` turns it
into the `unysis_box` request attribute, which the download endpoints read instead of trusting
input. Exceptions render as JSON for anything under `api/*`. See
[architecture/middleware](middleware.md) and [`docs/api/rpa-tool-v1.md`](../../docs/api/rpa-tool-v1.md).

## See also

- [architecture/middleware](middleware.md) — middleware stack that wraps every request
- [architecture/stack](stack.md) — technology choices
- [modules/marketplace](../modules/marketplace.md) — catalogue and API detail
- [frontend/ui-stack](../frontend/ui-stack.md) — what happens on the React side
