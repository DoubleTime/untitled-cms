<?php

namespace App\Vault\Pipes;

use App\Services\ClamAvScanner;
use App\Vault\DTOs\VaultPipelinePayload;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Vault upload pipe: flags an infected upload. The clamd conversation itself
 * lives in App\Services\ClamAvScanner so the Marketplace RevisionService can
 * reuse exactly the same implementation.
 */
class SandboxedScan
{
    public function __construct(private ClamAvScanner $scanner) {}

    public function handle(VaultPipelinePayload $payload, Closure $next)
    {
        if (! config('vault.clamav_enabled')) {
            return $next($payload);
        }

        // getRealPath() returns false if the temp file no longer exists.
        // Guard early so the log and scan receive a usable path.
        $path = $payload->file->getRealPath();
        if ($path === false) {
            Log::warning('ClamAV: temp file no longer exists on disk, scan skipped.');

            return $next($payload);
        }

        // Throws a ValidationException when clamd is unreachable and
        // vault.clamav_fail_closed is set; otherwise returns null on failure.
        $threat = $this->scanner->scan($path);

        if ($threat !== null) {
            $payload->validation_status = 'infected';
            $payload->moderation_reason = "ClamAV detected: {$threat}";
            Log::warning("ClamAV: infected file marked as infected and flagged. Threat: {$threat}. Path: {$path}");
        }

        return $next($payload);
    }
}
