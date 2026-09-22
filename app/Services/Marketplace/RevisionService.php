<?php

namespace App\Services\Marketplace;

use App\Exceptions\Marketplace\InvalidRevisionTransition;
use App\Models\AiModel;
use App\Models\Revision;
use App\Models\Script;
use App\Models\User;
use App\Services\ClamAvScanner;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Uploads and lifecycle for Revisions — the immutable, sequentially numbered
 * uploads of an AI Model or Script.
 *
 * Revision files bypass the Vault (docs/adr/0003): they live on the private
 * `marketplace` disk under {morph alias}/{revisable id}/{number}.{ext} and are
 * only reachable through the authenticated, logged download endpoints.
 */
class RevisionService
{
    /**
     * Extensions that must never appear as an intermediate part of a filename —
     * the same idea as App\Vault\Pipes\DetectDoubleExtension, applied here because
     * Revision files never go through the Vault pipeline.
     *
     * @var array<int, string>
     */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'sh', 'bat',
        'pl', 'cgi', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jar', 'dll', 'com', 'msi', 'ps1',
    ];

    /**
     * File signatures keyed by extension. The client-supplied extension is not
     * trusted on its own; the bytes have to agree.
     *
     * @var array<string, array<int, string>>
     */
    private const MAGIC_BYTES = [
        // Local file header, empty archive, spanned archive.
        'zip' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        // HDF5 superblock signature.
        'h5' => ["\x89HDF\r\n\x1a\n"],
    ];

    public function __construct(private ClamAvScanner $scanner) {}

    /**
     * Validate, scan, store and record a new Revision for a catalogue entry.
     *
     * @throws ValidationException
     */
    public function upload(
        Script|AiModel $revisable,
        UploadedFile $file,
        string $changeNote,
        User $uploader,
    ): Revision {
        $alias = $revisable->getMorphClass();
        $extension = strtolower($file->getClientOriginalExtension());

        $this->assertAllowedExtension($alias, $extension, $file->getClientOriginalName());
        $this->assertNoDoubleExtension($file->getClientOriginalName());
        $this->assertWithinSizeCap($file);
        $this->assertMagicBytes($file, $extension);
        $this->assertNotInfected($file);

        $sha256 = (string) hash_file('sha256', $file->getRealPath());
        $sizeBytes = (int) $file->getSize();
        $originalName = $file->getClientOriginalName();
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        // The number is max+1 per revisable, taken inside the transaction. A
        // concurrent upload can still win the unique index, so retry once.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $revisable, $alias, $file, $extension, $changeNote, $uploader,
                    $sha256, $sizeBytes, $originalName, $mime
                ) {
                    $number = $this->nextNumber($revisable);
                    $directory = $alias.'/'.$revisable->getKey();
                    $filename = $number.'.'.$extension;

                    // Insert the row before touching the disk: the unique index on
                    // (revisable, number) is what detects a concurrent upload, and it
                    // must fire before this attempt can overwrite the other upload's file.
                    $revision = Revision::create([
                        'revisable_type' => $alias,
                        'revisable_id' => $revisable->getKey(),
                        'number' => $number,
                        'status' => Revision::STATUS_DRAFT,
                        'change_note' => $changeNote,
                        'original_filename' => $originalName,
                        'disk_path' => $directory.'/'.$filename,
                        'size_bytes' => $sizeBytes,
                        'sha256' => $sha256,
                        'mime' => $mime,
                        'uploaded_by' => $uploader->getKey(),
                    ]);

                    Storage::disk($this->disk())->putFileAs($directory, $file, $filename);

                    return $revision;
                });
            } catch (QueryException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Draft → released. There is no path back to draft.
     *
     * @throws InvalidRevisionTransition
     */
    public function release(Revision $revision, User $user): Revision
    {
        if ($revision->status !== Revision::STATUS_DRAFT) {
            throw InvalidRevisionTransition::for($revision, 'released');
        }

        $revision->forceFill([
            'status' => Revision::STATUS_RELEASED,
            'released_by' => $user->getKey(),
            'released_at' => now(),
        ])->save();

        return $revision;
    }

    /**
     * Released → deprecated. A draft is never deprecated; it is deleted instead.
     *
     * @throws InvalidRevisionTransition
     */
    public function deprecate(Revision $revision, User $user): Revision
    {
        if ($revision->status !== Revision::STATUS_RELEASED) {
            throw InvalidRevisionTransition::for($revision, 'deprecated');
        }

        $revision->forceFill([
            'status' => Revision::STATUS_DEPRECATED,
            'deprecated_at' => now(),
        ])->save();

        return $revision;
    }

    /**
     * Remove the stored file for a Revision. Used by hard delete; the Download
     * rows that referenced the Revision are never deleted.
     */
    public function deleteFile(Revision $revision): void
    {
        if ($revision->disk_path) {
            Storage::disk($this->disk())->delete($revision->disk_path);
        }
    }

    /**
     * The next Revision number for this entry — max + 1, per revisable.
     */
    public function nextNumber(Script|AiModel $revisable): int
    {
        $max = Revision::query()
            ->where('revisable_type', $revisable->getMorphClass())
            ->where('revisable_id', $revisable->getKey())
            ->max('number');

        return ((int) $max) + 1;
    }

    /**
     * The extensions accepted for a catalogue entry type, e.g. ['zip'].
     *
     * @return array<int, string>
     */
    public function allowedExtensions(Script|AiModel $revisable): array
    {
        return (array) (config('marketplace.allowed_extensions')[$revisable->getMorphClass()] ?? []);
    }

    private function disk(): string
    {
        return (string) config('marketplace.disk');
    }

    private function assertAllowedExtension(string $alias, string $extension, string $filename): void
    {
        $allowed = (array) (config('marketplace.allowed_extensions')[$alias] ?? []);

        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'Only '.implode(', ', array_map(fn ($e) => '.'.$e, $allowed))
                    ." files may be uploaded here. Got: {$filename}",
            ]);
        }
    }

    private function assertNoDoubleExtension(string $filename): void
    {
        $parts = explode('.', $filename);
        $count = count($parts);

        for ($i = 1; $i < $count - 1; $i++) {
            if (in_array(strtolower($parts[$i]), self::DANGEROUS_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'file' => "Potential malicious double extension detected in filename: {$filename}",
                ]);
            }
        }
    }

    private function assertWithinSizeCap(UploadedFile $file): void
    {
        $maxKb = (int) config('marketplace.max_upload_kb');

        if ((int) $file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => 'The file is larger than the '.round($maxKb / 1024).' MB Revision upload limit.',
            ]);
        }
    }

    /**
     * The client-supplied extension is not trusted on its own: a .zip has to
     * actually be a zip, and a .h5 has to carry the HDF5 superblock signature.
     */
    private function assertMagicBytes(UploadedFile $file, string $extension): void
    {
        $signatures = self::MAGIC_BYTES[$extension] ?? null;

        if ($signatures === null) {
            return;
        }

        $path = $file->getRealPath();
        $handle = $path === false ? false : @fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 8);

        if ($handle !== false) {
            fclose($handle);
        }

        foreach ($signatures as $signature) {
            if (str_starts_with($head, $signature)) {
                return;
            }
        }

        // A zip written by a tool that prepends data still opens cleanly, so give
        // ZipArchive the final say before rejecting.
        if ($extension === 'zip' && $path !== false && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive;
            if ($zip->open($path) === true) {
                $zip->close();

                return;
            }
        }

        throw ValidationException::withMessages([
            'file' => "The file does not look like a valid .{$extension} file.",
        ]);
    }

    private function assertNotInfected(UploadedFile $file): void
    {
        if (! config('vault.clamav_enabled')) {
            return;
        }

        $path = $file->getRealPath();

        if ($path === false) {
            return;
        }

        $threat = $this->scanner->scan($path);

        if ($threat !== null) {
            throw ValidationException::withMessages([
                'file' => "The upload was rejected by the virus scanner: {$threat}.",
            ]);
        }
    }
}
