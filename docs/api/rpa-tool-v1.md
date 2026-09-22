# Marketplace API v1 — reference for RPA-TOOL

The Unysis Marketplace exposes a read-only catalogue API that RPA-TOOL uses to browse
FlowChart Scripts and AI Models and to download their Revisions. Everything lives under
`/api/v1` and speaks JSON.

- **Base URL**: `https://<marketplace-host>/api/v1`
- **Content type**: `application/json`. Send `Accept: application/json` on every request.
- **Auth**: a Sanctum bearer token obtained from `POST /login`.

Terms used below (AI Box, FlowChart Script, AI Model, Revision, Customer User) are defined in
[`CONTEXT.md`](../../CONTEXT.md).

---

## 1. Authentication

### The model

RPA-TOOL signs in with a **human Customer User's** email and password *plus the motherboard UUID of
the AI Box it is running on*. There is no per-device provisioning step: the first time a UUID is
seen it is registered automatically as an AI Box under that user's Customer, with status `pending`.
A UNYSIS Team Member labels or blocks it afterwards.

The token that comes back is **named after the motherboard UUID**, and every later request resolves
the AI Box from that name. Consequences worth knowing:

- Use one token per AI Box. Do not copy a token from one box to another — the download log would
  attribute the fetch to the wrong box.
- Downloads are attributed to `Customer User + AI Box`, both taken from the token. There is no
  request parameter that can change either.
- Blocking a box, deactivating the Customer User, or deactivating the Customer takes effect on the
  **next request**, not when the token expires.

Tokens live for 30 days by default. Re-run `POST /login` when yours expires (or any time you get a
`401`).

### `POST /login`

Unauthenticated. Throttled to **5 requests per minute per IP**.

```http
POST /api/v1/login
Content-Type: application/json
Accept: application/json

{
  "email": "aina@inari.example",
  "password": "••••••••",
  "motherboard_uuid": "4c4c4544-0037-5810-8043-b4c04f504433",
  "box_name": "SMT line 3 — cell A"
}
```

| Field | Required | Notes |
|---|---|---|
| `email` | yes | Customer User's email. |
| `password` | yes | |
| `motherboard_uuid` | yes | Max 128 chars. Trimmed and lowercased server-side, so case does not matter. |
| `box_name` | no | Only used the first time a box is seen, or when it still has no label. A Team Member's label is never overwritten. |

**200 OK**

```json
{
  "token": "17|wUq3zv2hQ0v8PbYb2NfR0v0z8cH1TqJ7yq1pEk4d",
  "token_type": "Bearer",
  "expires_at": "2026-10-22T09:14:03.000000Z",
  "user":     { "id": "01j...", "name": "Aina", "email": "aina@inari.example" },
  "customer": { "id": "01j...", "name": "Inari Amertron", "company": "Inari Amertron" },
  "ai_box":   {
    "id": "01j...",
    "motherboard_uuid": "4c4c4544-0037-5810-8043-b4c04f504433",
    "name": "SMT line 3 — cell A",
    "status": "pending"
  }
}
```

`ai_box.status` is one of `pending`, `active`, `blocked`. A `pending` box works normally — it just
has not been reviewed by a Team Member yet.

**Failures**

| Status | Body | Cause |
|---|---|---|
| 422 | `{"message":"...","errors":{"email":["These credentials do not match our records."]}}` | Unknown email **or** wrong password — deliberately indistinguishable. |
| 422 | `errors.motherboard_uuid` | Missing or over 128 chars. |
| 403 | `{"message":"This account has been deactivated."}` | `is_active` is false on the user. |
| 403 | `{"message":"This account cannot access the Marketplace API."}` | Not a Customer User (a UNYSIS Team Member, say), or the Customer is deactivated. |
| 403 | `{"message":"This AI Box (motherboard UUID …) has been blocked. Contact UNYSIS support."}` | The box was blocked. |
| 403 | `{"message":"This AI Box (motherboard UUID …) is already registered to a different Customer. …"}` | The UUID belongs to another Customer's box. Never auto-reassigned. |
| 429 | `{"message":"Too Many Attempts."}` | More than 5 login attempts in a minute from this IP. |

### Using the token

```http
GET /api/v1/scripts
Authorization: Bearer 17|wUq3zv2hQ0v8PbYb2NfR0v0z8cH1TqJ7yq1pEk4d
Accept: application/json
```

Any request without a valid, unexpired token gets:

```http
HTTP/1.1 401 Unauthorized
{ "message": "Unauthenticated." }
```

The API never redirects and never returns HTML.

### `POST /logout`

Deletes the token used to make the call. Other tokens (other boxes) are untouched.

```http
HTTP/1.1 204 No Content
```

### `GET /me`

Confirms who the token belongs to and when it expires — useful as a cheap health check on startup.

```json
{
  "user":     { "id": "01j...", "name": "Aina", "email": "aina@inari.example" },
  "customer": { "id": "01j...", "name": "Inari Amertron", "company": "Inari Amertron" },
  "ai_box":   { "id": "01j...", "motherboard_uuid": "4c4c…4433", "name": "SMT line 3 — cell A", "status": "active" },
  "token_expires_at": "2026-10-22T09:14:03.000000Z"
}
```

---

## 2. Throttle limits

| Group | Limit | Bucket |
|---|---|---|
| `POST /login` | 5 / minute | Caller IP |
| `GET …/download` | 20 / minute | Token (i.e. one AI Box) |
| Everything else | 60 / minute | Token (i.e. one AI Box) |

Over the limit you get `429` with `Retry-After` and `X-RateLimit-*` headers. Honour `Retry-After`;
do not retry in a tight loop.

---

## 3. Reference lists

All three are small, unpaginated and safe to cache for the session. Results are wrapped in `data`.

### `GET /machine-brands`

```json
{ "data": [ { "id": "01j...", "name": "Fuji", "slug": "fuji" } ] }
```

### `GET /machine-models`

Active Machine Models only. Optional `?brand=<machine brand id>`.

```json
{ "data": [
  { "id": "01j...", "name": "NXT III", "slug": "nxt-iii", "brand": { "id": "01j...", "name": "Fuji" } }
] }
```

### `GET /customers`

Every active Customer, for building a filter dropdown. The Customer label on a catalogue entry is a
**filter, not a permission** — every Customer User can see and download every entry, whichever
Customer it is labelled with.

```json
{ "data": [ { "id": "01j...", "company": "Inari Amertron" } ] }
```

---

## 4. Catalogue

FlowChart Scripts live under `/scripts`, AI Models under `/ai-models`. The two have the same five
endpoints and the same payload shape, except that Scripts carry Preview Images and AI Models carry
the inference metadata (`framework`, `input_size`, `labels`, `notes`).

`{id}` is always the entry's ULID as returned by the list endpoint.

### `GET /scripts` · `GET /ai-models`

Paginated. **Only entries with at least one `released` Revision appear** — anything still in draft is
invisible to RPA-TOOL, because there is nothing it could download.

Query parameters (all optional, all AND-ed together):

| Parameter | Meaning |
|---|---|
| `machine_model` | Machine Model id, exact match. |
| `brand` | Machine Brand id, exact match (via the entry's Machine Model). |
| `customer` | Customer id, exact match. Entries with no Customer label are excluded when this is set. |
| `q` | Case-insensitive substring search over name and description. |
| `per_page` | Default 50, maximum 200. Values above the cap are clamped, not rejected. |
| `page` | 1-based page number. |

Results are ordered by name.

```json
{
  "data": [
    {
      "id": "01jr...",
      "name": "Tray feeder alignment",
      "slug": "tray-feeder-alignment",
      "description": "Aligns and verifies tray feeders before the run starts.",
      "machine_model": { "id": "01j...", "name": "NXT III", "brand": { "id": "01j...", "name": "Fuji" } },
      "customer": { "id": "01j...", "company": "Inari Amertron" },
      "cover_image_url": "https://marketplace.example/media/6f1c….png",
      "latest_revision": {
        "id": "01j...",
        "number": 4,
        "size_bytes": 18234112,
        "sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
        "released_at": "2026-09-18T11:02:55.000000Z",
        "change_note": "Faster tray scan, fixes the 0.2 mm offset on lane 2."
      },
      "revisions_count": 3,
      "downloads_count": 41,
      "updated_at": "2026-09-18T11:02:55.000000Z"
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "per_page": 50, "to": 50, "total": 128 }
}
```

Notes:

- `customer` is `null` when the entry carries no Customer label.
- `cover_image_url` is the first Preview Image, or `null`. Scripts only.
- `revisions_count` counts the Revisions this API exposes (released + deprecated), not drafts.
- `latest_revision` is the highest-numbered **released** Revision, or `null` (which cannot happen in
  a list result, only in a detail result).
- AI Model rows additionally carry `framework`, `input_size`, `labels` and `notes`, and have no
  `cover_image_url`.

### `GET /scripts/{id}` · `GET /ai-models/{id}`

The list fields plus:

- `images`: `[{ "url": "…", "sort_order": 0 }]` — Scripts only, in display order.
- `revisions`: every `released` and `deprecated` Revision, newest number first. **Drafts are never
  returned.**

```json
{
  "data": {
    "id": "01jr...",
    "name": "Tray feeder alignment",
    "…": "…",
    "images": [ { "url": "https://marketplace.example/media/6f1c….png", "sort_order": 0 } ],
    "revisions": [
      {
        "id": "01j...",
        "number": 4,
        "status": "released",
        "size_bytes": 18234112,
        "sha256": "9f86d0…0a08",
        "change_note": "Faster tray scan…",
        "original_filename": "tray-feeder-alignment-v4.zip",
        "released_at": "2026-09-18T11:02:55.000000Z",
        "deprecated_at": null
      },
      {
        "id": "01j...",
        "number": 3,
        "status": "deprecated",
        "…": "…",
        "deprecated_at": "2026-09-18T11:03:10.000000Z"
      }
    ]
  }
}
```

A deleted (or never-existing) entry returns `404`.

### `GET /scripts/{id}/revisions` · `GET /ai-models/{id}/revisions`

Just the `revisions` array above, unpaginated:

```json
{ "data": [ { "id": "…", "number": 4, "status": "released", "…": "…" } ] }
```

---

## 5. Downloading a Revision

### `GET /scripts/{id}/download` · `GET /ai-models/{id}/download`

Throttled to **20 requests per minute per box**.

| Parameter | Meaning |
|---|---|
| *(none)* | The latest **released** Revision. This is what you normally want. |
| `revision=N` | That exact Revision number. Allowed when it is `released` **or** `deprecated`, so a box can re-fetch what it is already running. |

The response is the raw file, streamed:

```http
HTTP/1.1 200 OK
Content-Type: application/zip
Content-Disposition: attachment; filename="tray-feeder-alignment-v4.zip"
X-Checksum-SHA256: 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08
X-Revision-Number: 4
```

FlowChart Scripts are `.zip` bundles; AI Models are `.h5` files.

**Always verify the download.** Hash the bytes you received and compare with `X-Checksum-SHA256`
(the same value as `sha256` in the Revision payload). Discard and retry on a mismatch.

```python
import hashlib, requests

r = requests.get(url, headers={"Authorization": f"Bearer {token}"}, stream=True)
r.raise_for_status()

digest = hashlib.sha256()
with open(target, "wb") as fh:
    for chunk in r.iter_content(1024 * 256):
        fh.write(chunk)
        digest.update(chunk)

if digest.hexdigest() != r.headers["X-Checksum-SHA256"]:
    raise RuntimeError("checksum mismatch — download corrupted")
```

**Failures**

| Status | Body | Cause |
|---|---|---|
| 404 | `{"message":"No released revision available."}` | No `revision` given and the entry has no released Revision. |
| 404 | `{"message":"Revision 7 is not available for download."}` | That number does not exist on this entry, or it is still a draft. |
| 403 | box/account message | The box was blocked, or the account or Customer deactivated, since the token was issued. |
| 429 | `{"message":"Too Many Attempts."}` | More than 20 downloads in a minute from this box. |

Every successful download is recorded against the Customer User and the AI Box, with source `api`.

---

## 6. Checking for updates

### `GET /scripts/{id}/check-update?current=N` · `GET /ai-models/{id}/check-update?current=N`

The cheap poll: one small JSON response instead of a detail fetch.

| Parameter | Meaning |
|---|---|
| `current` | The Revision number this box is currently running. Optional; must be an integer ≥ 1. |

```json
{
  "update_available": true,
  "latest": {
    "id": "01j...",
    "number": 4,
    "size_bytes": 18234112,
    "sha256": "9f86d0…0a08",
    "released_at": "2026-09-18T11:02:55.000000Z",
    "change_note": "Faster tray scan, fixes the 0.2 mm offset on lane 2."
  },
  "current_status": "deprecated"
}
```

| Field | Meaning |
|---|---|
| `update_available` | `true` when a released Revision exists that you are not running. |
| `latest` | The newest released Revision, or `null` when the entry has none. |
| `current_status` | `released`, `deprecated`, `unknown`, or `null` when you did not send `current`. `unknown` means the catalogue has no such released or deprecated Revision number — treat what you hold as stale. |

Suggested loop:

```text
for each installed entry:
    GET /scripts/{id}/check-update?current={installed_revision_number}
    if not update_available:            continue
    GET /scripts/{id}/download          # no ?revision — takes the latest released
    verify X-Checksum-SHA256
    install, then record latest.number as the new installed_revision_number
```

Poll no more than once a minute per entry, and stay inside the 60/minute budget: with many installed
entries, spread the checks out or walk `GET /scripts` once and compare `latest_revision.number`
locally instead.

---

## 7. Error format

Every error is JSON.

```json
{ "message": "Human-readable explanation." }
```

Validation errors add a field map:

```json
{
  "message": "The motherboard uuid field is required.",
  "errors": { "motherboard_uuid": ["The motherboard uuid field is required."] }
}
```

| Status | Meaning | What to do |
|---|---|---|
| 401 | Token missing, invalid or expired. | Re-run `POST /login`. |
| 403 | Box blocked, account or Customer deactivated, or account not allowed on the API. | Stop and surface the message; retrying will not help. Contact UNYSIS. |
| 404 | Entry or Revision not found (includes deleted entries and drafts). | Refresh the catalogue list. |
| 422 | Validation failed, or credentials rejected at login. | Fix the request. |
| 429 | Rate limited. | Wait for `Retry-After`. |
| 5xx | Marketplace problem. | Retry with backoff. |

---

## 8. Endpoint summary

| Method | Path | Auth | Throttle |
|---|---|---|---|
| POST | `/api/v1/login` | — | 5/min per IP |
| POST | `/api/v1/logout` | token | 60/min |
| GET | `/api/v1/me` | token | 60/min |
| GET | `/api/v1/machine-brands` | token | 60/min |
| GET | `/api/v1/machine-models` | token | 60/min |
| GET | `/api/v1/customers` | token | 60/min |
| GET | `/api/v1/scripts` | token | 60/min |
| GET | `/api/v1/scripts/{id}` | token | 60/min |
| GET | `/api/v1/scripts/{id}/revisions` | token | 60/min |
| GET | `/api/v1/scripts/{id}/download` | token | 20/min |
| GET | `/api/v1/scripts/{id}/check-update` | token | 60/min |
| GET | `/api/v1/ai-models` … | token | as above |
