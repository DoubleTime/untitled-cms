# Vault

> Media manager: upload pipeline, configuration, and storage.

Last updated: 2026-09-22

## Overview

`VaultService` runs uploads through a Laravel pipeline of pipe classes in
`app/Vault/Pipes/`. Each pipe receives a typed `VaultPipelinePayload` DTO,
does one thing, and passes it to the next stage. If any pipe rejects the file,
the upload fails with an appropriate error.

## Pipeline stages (in order)

| # | Pipe class | What it does |
|---|-----------|--------------|
| 1 | `DetectDoubleExtension` | Rejects files like `image.php.jpg` — disguised executables |
| 2 | `ValidateMimeType` | Checks MIME type against allowlist in `config/vault.php` |
| * | `SandboxedScan` | Optional ClamAV antivirus daemon scanning (TCP stream INSTREAM mode; dynamically injected at pos 2 when enabled) |
| 3 | `SanitizeImage` | Strips EXIF/metadata by re-encoding images with native GD (`imagecreatefrom*`) |
| 4 | `ModerationCheck` | Optional AI content moderation check |
| 5 | `GenerateUuid` | Assigns a UUID filename to prevent path traversal / collisions |
| 6 | `StoreMetadata` | Persists file record to MongoDB `vault_files` collection |

*Note: `SandboxedScan` connects to a clamd daemon via TCP. By default, it fails open, but can be configured to fail closed using the `CLAMAV_FAIL_CLOSED` setting, blocking uploads when the scanner is offline.*

### The scanner itself lives outside the pipe

As of the Marketplace Phase 3 work the clamd conversation is **not** in the pipe. It was extracted to
`App\Services\ClamAvScanner`, so the Vault pipeline and `App\Services\Marketplace\RevisionService`
share one implementation — Revision files bypass the Vault entirely (see
[marketplace](marketplace.md) and `docs/adr/0003`) but must still be scanned the same way.

```php
ClamAvScanner::scan(string $filePath): ?string   // threat name, or null when clean
```

`scan()` owns the INSTREAM protocol, the timeout handling (a timed-out read is an error, never
"clean") **and** the fail-open / fail-closed decision: a daemon failure is logged and swallowed unless
`vault.clamav_fail_closed` is set, in which case it raises a `ValidationException` on the `file` key —
exactly the behaviour the pipe had before. `SandboxedScan` now only decides what to do with a hit: it
flags the payload `infected` and records the reason, leaving the upload to continue and be quarantined.
`RevisionService` instead refuses the upload outright.

Behaviour, config keys (`CLAMAV_ENABLED`, `CLAMAV_HOST`, `CLAMAV_PORT`, `CLAMAV_TIMEOUT`,
`CLAMAV_FAIL_CLOSED`) and the existing Vault tests are unchanged by the extraction.

## Configuration (`config/vault.php`)

- **Allowed MIME types** — explicit allowlist
- **Max size** — 50 MB
- **ClamAV** — optional antivirus scan (`CLAMAV_ENABLED=false` by default)
- **Image washing** — `image_washing = true` by default (GD re-encode in `SanitizeImage`)

## Image optimization

After upload, `VaultService` dispatches `OptimizeVaultImageJob` for JPG/PNG files. The job converts the file to WebP
(quality 85) with Laravel's `Image` facade (`Image::fromStorage()->toWebp()->quality(85)->toBytes()`). It writes
`optimized_<name>.webp` next to the original and sets `optimized_path`, `optimized_size` and `is_optimized`.
The driver comes from `config('images.default')` (`IMAGE_DRIVER`, default `gd`). Laravel's GD/Imagick image drivers
are built on `intervention/image` `^4`, so that package stays a direct dependency even though app code doesn't import it.
Covered by `tests/Feature/OptimizeVaultImageJobTest.php`.

## DTO

`app/Vault/DTOs/VaultPipelinePayload.php` carries state through the pipeline.
Inspect this class to see what data is available at each stage.

## Adding a new pipe

1. Create a class in `app/Vault/Pipes/` implementing the pipe interface.
2. Add it to the pipeline sequence in `VaultService`.
3. It receives and must pass along `VaultPipelinePayload`.

## Folder name uniqueness

`vault_folders` has a unique index `vault_folders_parent_name_unique` on
`(parent_id, name, deleted_at)`. Because `deleted_at` is part of the key, trashed folders don't block new ones.
`VaultFolderController` checks for collisions on store, rename, move **and restore**, and returns
a 422 before the index would reject the write. If the migration fails with a duplicate-key error,
rename or trash the duplicate folders first. If a concurrent request passes the pre-check and then
hits the index, the controller returns the same 422 (duplicate key, code 11000).

## API contracts

- `GET admin/vault/folders?all=1` returns the **whole folder tree**, which `useVaultBrowser` and `VaultPicker` use for the
  sidebar and breadcrumbs. Without `all`, it returns one level under `parent_id` (root when omitted).
- Batch file endpoints (`batch-move`, `batch-delete`, `batch-restore`) accept at most 500 `uuids`.
- The frontend hook `resources/js/hooks/useVaultBrowser.ts` debounces search (300 ms) and ignores
  stale responses. It shows the server's `error`/`message` text in toasts.

See [modules/permissions](permissions.md#vault-authorization-rules) for Vault policy rules.

## Gotchas

- `SanitizeImage` needs the PHP `gd` extension (with WebP support). Without it this stage fails.
  Check `image_washing` config if you're seeing unexpected errors.
- `ModerationCheck` is an AI call — it adds latency and can fail if AI is
  misconfigured. Investigate whether it short-circuits gracefully on failure.
- ClamAV is off by default. Do not assume it runs in production unless explicitly enabled.

## See also

- [modules/services](services.md) — VaultService overview
- [database/collections](../database/collections.md) — vault_files and vault_folders collections
