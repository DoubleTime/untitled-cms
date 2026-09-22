<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Single ClamAV implementation shared by the Vault upload pipeline
 * (App\Vault\Pipes\SandboxedScan) and the Marketplace RevisionService.
 *
 * Streams a file to clamd over TCP with the INSTREAM protocol and reports the
 * threat name, or null when the file is clean. Daemon failures are logged and
 * swallowed unless `vault.clamav_fail_closed` is set, in which case they are
 * raised as a ValidationException on the `file` key — the behaviour the Vault
 * pipe had before this class was extracted.
 */
class ClamAvScanner
{
    /**
     * Scan a file on disk.
     *
     * @return string|null the threat name when infected, null when clean (or when
     *                     the daemon was unreachable and fail-closed is off)
     *
     * @throws ValidationException when clamd is unreachable and `vault.clamav_fail_closed` is true
     */
    public function scan(string $filePath): ?string
    {
        try {
            $threat = $this->scanViaClamd($filePath);

            if ($threat !== null) {
                Log::warning("ClamAV: infected file detected. Threat: {$threat}. Path: {$filePath}");
            } else {
                Log::info("ClamAV: file clean. Path: {$filePath}");
            }

            return $threat;
        } catch (\Throwable $e) {
            // If clamd is unreachable or timed out, log and continue — don't block the upload.
            // Ops team should be alerted separately if clamd goes down.
            Log::error("ClamAV scan failed (daemon unreachable?): {$e->getMessage()}. Path: {$filePath}");

            if (config('vault.clamav_fail_closed', false)) {
                throw ValidationException::withMessages([
                    'file' => 'ClamAV scanning failed (service offline or timed out).',
                ]);
            }

            return null;
        }
    }

    /**
     * Stream the file to clamd via TCP using the INSTREAM protocol.
     * Returns the threat name if infected, null if clean.
     *
     * @throws \RuntimeException if the daemon is unreachable, the file cannot be opened,
     *                           or the socket times out before a response is received.
     */
    protected function scanViaClamd(string $filePath): ?string
    {
        $host = (string) config('vault.clamav_host', '127.0.0.1');
        $port = (int) config('vault.clamav_port', 3310);
        $timeout = (int) config('vault.clamav_timeout', 30);

        $socket = @fsockopen($host, $port, $errCode, $errStr, $timeout);

        if (! $socket) {
            throw new \RuntimeException("Cannot connect to ClamAV daemon at {$host}:{$port} — {$errStr} (code {$errCode})");
        }

        try {
            stream_set_timeout($socket, $timeout);

            // Initiate INSTREAM scan (null-terminated command)
            fwrite($socket, "zINSTREAM\0");

            $handle = fopen($filePath, 'rb');
            if (! $handle) {
                throw new \RuntimeException("Cannot open file for ClamAV scan: {$filePath}");
            }

            try {
                // Send file contents in 8 KB chunks, each prefixed with a 4-byte big-endian length
                while (! feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($socket, pack('N', strlen($chunk)).$chunk);
                }
            } finally {
                fclose($handle);
            }

            // Signal end of stream with a zero-length chunk
            fwrite($socket, pack('N', 0));

            $response = fgets($socket, 1024);

            // A timed-out read returns false/empty and must not be treated as "clean".
            // Throw so the outer catch logs the failure rather than silently passing the file.
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out'] || $response === false) {
                throw new \RuntimeException("ClamAV scan timed out waiting for response. Path: {$filePath}");
            }
        } finally {
            fclose($socket);
        }

        $response = trim((string) $response);

        // clamd responds: "stream: OK" or "stream: MALWARE_NAME FOUND"
        if (str_ends_with($response, 'FOUND')) {
            preg_match('/stream: (.+) FOUND$/', $response, $matches);

            return $matches[1] ?? 'Unknown threat';
        }

        return null;
    }
}
