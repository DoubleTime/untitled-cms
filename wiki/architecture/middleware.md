# Middleware

> Web and API middleware stacks, rate limiters, and what each layer does.

Last updated: 2026-09-22

## Web middleware stack (in order)

1. **`HandleInertiaRequests`** — shares `auth`, `permissions`, `menus`, `settings`
   as Inertia props on every page load. If a shared prop is missing on the frontend,
   this is the first place to look.

2. **`AddLinkHeadersForPreloadedAssets`** — adds `Link: <url>; rel=preload` headers
   for critical assets. Performance optimization.

3. **`CheckRedirects`** — database-driven URL redirects. Looks up the request path
   in the `redirects` collection and issues a redirect if found. Runs early so
   redirects take effect before page logic.

4. **`CheckMaintenanceMode`** — custom maintenance mode. Serves a maintenance page
   to regular users but lets admins through. This is **not** Laravel's built-in
   maintenance mode — it's a custom implementation that checks the `settings`
   collection and the current user's role.

## API middleware stack (`/api/*`)

`routes/api.php` is registered in `bootstrap/app.php` with
`withRouting(api: ..., apiPrefix: 'api')`, so it gets Laravel's default `api` group —
`SubstituteBindings` and nothing else. The RPA-TOOL routes add their own layers:

1. **`auth:sanctum`** — personal access token in `Authorization: Bearer ...`. The `sanctum` guard is
   declared explicitly in `config/auth.php` (Sanctum would register it anyway).
2. **`ai-box`** (`App\Http\Middleware\ResolveAiBox`) — resolves the AI Box from the **token's
   name**, which is the motherboard UUID the token was issued for. It aborts 403 when the box is
   gone, belongs to another Customer, or is blocked, and when the caller is no longer an active
   Customer User of an active Customer. The box lands on the request as the `ai_box` attribute; the
   download endpoints read it from there and never from input. It also calls
   `AiBoxService::touch()`, which skips the write if the box was seen less than 60 seconds ago from
   the same IP.
3. **`throttle:rpa` / `throttle:rpa-download` / `throttle:rpa-login`** — see Rate limiting below.

`ResolveAiBox` running on *every* request, rather than only at login, is deliberate: blocking a box
or deactivating a Customer User has to take effect at once, not when the 30-day token expires.
Middleware priority puts `Authenticate` before `ThrottleRequests`, so the throttle limiters can see
the resolved token.

Exception rendering: `bootstrap/app.php` calls
`shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson())`, so API
callers get JSON — `401 {"message":"Unauthenticated."}`, `422` with an `errors` map, `403`/`404` with
a `message` — and are never redirected to the login page or handed an Inertia error page.

See [modules/marketplace](../modules/marketplace.md) and
[`docs/api/rpa-tool-v1.md`](../../docs/api/rpa-tool-v1.md).

## Auth middleware

Routes requiring authentication use:
- `auth` — standard Sanctum/session auth check
- `verified` — email verification check (applied to all admin routes)

## Permission middleware

The `can` middleware alias maps to `CheckPermission` (custom), not Laravel's built-in.
Usage: `->middleware('can:resource.action')`. See [modules/permissions](../modules/permissions.md).

## Webhook middleware

`resend.webhook` alias → `VerifyResendWebhook`. Applied only to `POST /webhooks/resend`.
Verifies Svix/Standard Webhooks v1 HMAC-SHA256 signatures and rejects requests whose
timestamp deviates more than 5 minutes in either direction. CSRF is exempted for this
route in `bootstrap/app.php`. See [modules/email](../modules/email.md) for full detail.

## Event discovery

Auto-discovery is **disabled** via `->withEvents(discover: false)` in `bootstrap/app.php`.
All listeners are registered manually in `AppServiceProvider`. This is intentional —
the mail listener chain (`StopSuppressedEmail` → `InjectUnsubscribeHeaders`) requires a
guaranteed execution order that auto-discovery cannot provide. If you add a new listener,
register it explicitly there.

## Rate limiting

AI endpoints are rate-limited at the route level, not via middleware:
- Text generation: 30 requests/minute
- Image generation: 10 requests/minute

The RPA-TOOL API uses **named** limiters, registered in `AppServiceProvider`:

| Limiter | Limit | Bucket |
|---|---|---|
| `rpa-login` | 5/min | Caller IP |
| `rpa-download` | 20/min | Sanctum token id, IP as fallback |
| `rpa` | 60/min | Sanctum token id, IP as fallback |

Plain `throttle:60,1` would bucket by **user id**, and one Customer User may run several AI Boxes;
the token is the box, so the token id is the right bucket.

## Gotchas

- `CheckMaintenanceMode` reads from `settings` — if `SettingsService` cache is
  warm with stale data, maintenance mode changes may not take effect immediately.
- `CheckRedirects` runs on every request — keep the `redirects` collection indexed
  on the path field for performance.

## See also

- [modules/permissions](../modules/permissions.md) — CheckPermission middleware detail
- [architecture/request-flow](request-flow.md) — where middleware fits in the full request flow
- [frontend/ui-stack](../frontend/ui-stack.md) — HandleInertiaRequests and shared props
