<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatchMoveVaultFilesRequest;
use App\Http\Requests\BatchVaultUuidsRequest;
use App\Http\Requests\MoveVaultFileRequest;
use App\Http\Requests\RenameVaultFileRequest;
use App\Http\Requests\ToggleVaultOptimizationRequest;
use App\Http\Requests\UpdateVaultAltTextRequest;
use App\Http\Requests\UploadVaultFileRequest;
use App\Models\VaultFile;
use App\Models\VaultFolder;
use App\Services\VaultService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class VaultController extends Controller
{
    protected $vaultService;

    public function __construct(VaultService $vaultService)
    {
        $this->vaultService = $vaultService;
    }

    public function adminPage()
    {
        $this->authorize('viewAny', VaultFile::class);

        // The upload dialog shows this value in MB. Use the smallest of PHP's upload/post limits and
        // the Vault validation rule, ignoring "unlimited" (0 or -1) ini values.
        $limits = array_filter([
            $this->parsePhpIniSize(ini_get('upload_max_filesize')),
            $this->parsePhpIniSize(ini_get('post_max_size')),
            (int) config('vault.max_upload_kb', 51200) * 1024,
        ], fn (int $bytes) => $bytes > 0);

        return Inertia::render('Vault/Index', [
            'maxUploadSize' => max(1, intdiv(min($limits), 1024 * 1024)),
        ]);
    }

    /**
     * Convert PHP ini size strings (e.g. "50M", "8G", "512K") to bytes.
     * A plain (int) cast silently truncates the unit suffix and returns the wrong value.
     */
    private function parsePhpIniSize(string $size): int
    {
        $value = (int) $size;
        $unit = strtolower(substr(trim($size), -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    public function list(Request $request)
    {
        $this->authorize('viewAny', VaultFile::class);

        $files = $this->buildListQuery($request)->paginate(50);

        return response()->json($files);
    }

    public function upload(UploadVaultFileRequest $request)
    {
        // Global media create is checked by FormRequest

        $uploadedFiles = [];
        $errors = [];

        // Folder-scoped create: VaultFilePolicy::create delegates to folder write rules
        $targetFolder = null;
        if ($request->folder_id) {
            $targetFolder = VaultFolder::findOrFail($request->folder_id);
            $this->authorize('create', [VaultFile::class, $targetFolder]);
        }

        $isPublic = $request->boolean('is_public', true);

        foreach ($request->file('files') as $file) {
            try {
                $uploadedFiles[] = $this->vaultService->upload($file, $request->folder_id, $targetFolder, $isPublic);
            } catch (\Exception $e) {
                $errors[] = [
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'uploaded' => $uploadedFiles,
            'errors' => $errors,
        ]);
    }

    public function serve(string $uuid)
    {
        $file = VaultFile::withTrashed()->where('uuid', $uuid)->firstOrFail();

        $this->authorize('view', $file);

        $diskName = $file->is_public ? 'public' : 'vault';
        $storagePath = $file->storage_path;

        if ($file->trashed()) {
            $storagePath .= '.trashed';
        }

        // Check if we should serve the optimized file
        $isOptimizedRequest = $file->is_optimized && ! $file->use_original && $file->optimized_path;

        if ($isOptimizedRequest) {
            // The optimized file is never renamed on delete — only storage_path gets .trashed
            $storagePath = $file->optimized_path;
        }

        $path = Storage::disk($diskName)->path($storagePath);

        $etag = md5($file->updated_at.$file->size_bytes);
        $lastModified = $file->updated_at->toRfc7231String();

        if (request()->header('If-None-Match') === "\"{$etag}\"") {
            return response('', 304);
        }

        $contentType = ($file->is_optimized && ! $file->use_original && str_ends_with($storagePath, '.webp'))
            ? 'image/webp'
            : $file->mime_type;

        $filename = $file->original_name;

        return response()->file($path, [
            'ETag' => "\"{$etag}\"",
            'Last-Modified' => $lastModified,
            'Cache-Control' => $file->is_public ? 'public, max-age=31536000' : 'private, no-store',
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function servePublic(string $uuid, string $extension = '')
    {
        // $extension is the segment after the dot in /media/{uuid}.{extension}.
        // It is intentionally ignored — the file is resolved by UUID alone.
        // Its only purpose is to give browsers and CDNs a proper file extension in the URL.
        $file = VaultFile::where('uuid', $uuid)->where('is_public', true)->firstOrFail();

        $diskName = 'public';
        $storagePath = $file->resolveServingPath();

        $path = Storage::disk($diskName)->path($storagePath);

        if (! file_exists($path)) {
            abort(404);
        }

        $etag = md5($file->updated_at.$file->size_bytes);
        $lastModified = $file->updated_at->toRfc7231String();

        // Fix #6: compare If-Modified-Since as a parsed HTTP date rather than a string.
        // CDNs may normalise the date format slightly (e.g. extra whitespace, different
        // day-name capitalisation), so string equality produces false mismatches.
        $ifModifiedSince = request()->header('If-Modified-Since');
        $notModified = request()->header('If-None-Match') === "\"{$etag}\""
            || ($ifModifiedSince && $this->parseHttpDate($ifModifiedSince) >= $file->updated_at->timestamp);

        if ($notModified) {
            return response('', 304);
        }

        $contentType = ($file->is_optimized && str_ends_with($storagePath, '.webp')) ? 'image/webp' : $file->mime_type;

        return response()->file($path, [
            'ETag' => "\"{$etag}\"",
            'Last-Modified' => $lastModified,
            'Cache-Control' => 'public, max-age=31536000',
            'Content-Type' => $contentType,
        ]);
    }

    public function checkDuplicate(Request $request)
    {
        $this->authorize('viewAny', VaultFile::class);

        $request->validate(['hash' => 'required|string']);

        $file = VaultFile::where('hash_sha256', $request->hash)
            ->whereNull('deleted_at')
            ->with('folder')
            ->first();

        return response()->json([
            'isDuplicate' => (bool) $file,
            'file' => $file ? $file->only(['uuid', 'original_name', 'created_at', 'folder']) : null,
        ]);
    }

    public function batchRestore(BatchVaultUuidsRequest $request)
    {
        $this->authorize('viewAny', VaultFile::class);

        $files = VaultFile::onlyTrashed()->with('folder')->whereIn('uuid', $request->uuids)->get();
        $restoredCount = 0;

        foreach ($files as $file) {
            if (Gate::allows('restore', $file)) {
                $this->vaultService->restoreFile($file);
                $restoredCount++;
            }
        }

        return response()->json([
            'message' => "Successfully restored {$restoredCount} item(s).",
            'restored_count' => $restoredCount,
        ]);
    }

    public function emptyTrash()
    {
        $this->authorize('viewAny', VaultFile::class);

        $deletedCount = 0;

        // Do not use Eloquent chunk() here — mongodb/laravel-mongodb can fail serializing
        // SortDirection for the internal _id cursor. Trash volume is admin-scoped and modest.
        // Only permanently purge files the actor may forceDelete (media.delete).
        foreach (VaultFile::onlyTrashed()->cursor() as $file) {
            if (Gate::allows('forceDelete', $file)) {
                $this->vaultService->purgeFile($file);
                $deletedCount++;
            }
        }

        return response()->json([
            'message' => "Trash emptied. {$deletedCount} items permanently deleted.",
            'deleted_count' => $deletedCount,
        ]);
    }

    public function rename(RenameVaultFileRequest $request, string $uuid)
    {
        $file = VaultFile::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $file);

        $this->vaultService->renameFile($file, $request->name);

        return response()->json(['message' => 'Renamed successfully', 'file' => $file]);
    }

    public function updateAltText(UpdateVaultAltTextRequest $request, string $uuid)
    {
        $file = VaultFile::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $file);

        $file->update(['alt_text' => $request->input('alt_text')]);

        return response()->json(['message' => 'Alt text updated', 'alt_text' => $file->alt_text]);
    }

    public function toggleOptimization(ToggleVaultOptimizationRequest $request, string $uuid)
    {
        $file = VaultFile::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $file);

        $file->update(['use_original' => $request->boolean('use_original')]);

        return response()->json([
            'message' => 'Optimization preference updated',
            'use_original' => $file->use_original,
            'url' => $file->url,
        ]);
    }

    public function batchMove(BatchMoveVaultFilesRequest $request)
    {
        $this->authorize('viewAny', VaultFile::class);

        $uuids = $request->input('uuids');
        $folderId = $request->input('folder_id');

        if ($folderId) {
            $targetFolder = VaultFolder::findOrFail($folderId);
            $this->authorize('update', $targetFolder);
        }

        $files = VaultFile::with('folder')->whereIn('uuid', $uuids)->get();
        $movedCount = 0;

        foreach ($files as $file) {
            /** @var VaultFile $file */
            if (Gate::allows('update', $file)) {
                $this->vaultService->moveFile($file, $folderId);
                $movedCount++;
            }
        }

        return response()->json([
            'message' => "Successfully moved {$movedCount} item(s).",
            'moved_count' => $movedCount,
        ]);
    }

    public function batchDelete(BatchVaultUuidsRequest $request)
    {
        $this->authorize('viewAny', VaultFile::class);

        $uuids = $request->input('uuids');
        $files = VaultFile::with('folder')->whereIn('uuid', $uuids)->get();
        $deletedCount = 0;

        foreach ($files as $file) {
            /** @var VaultFile $file */
            if (Gate::allows('delete', $file)) {
                $this->vaultService->deleteFile($file);
                $deletedCount++;
            }
        }

        return response()->json([
            'message' => "Successfully deleted {$deletedCount} item(s).",
            'deleted_count' => $deletedCount,
        ]);
    }

    public function move(MoveVaultFileRequest $request, string $uuid)
    {
        $file = VaultFile::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $file);

        if ($request->folder_id) {
            $targetFolder = VaultFolder::findOrFail($request->folder_id);
            $this->authorize('update', $targetFolder);
        }

        $this->vaultService->moveFile($file, $request->folder_id);

        return response()->json(['message' => 'Moved successfully', 'file' => $file]);
    }

    public function destroy(string $uuid)
    {
        $file = VaultFile::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $file);

        $this->vaultService->deleteFile($file);

        return response()->json(['message' => 'Deleted successfully']);
    }

    public function trash(Request $request)
    {
        $this->authorize('viewAny', VaultFile::class);

        $files = VaultFile::onlyTrashed()->orderBy('deleted_at', 'desc')->paginate(50);

        return response()->json($files);
    }

    public function restore(string $uuid)
    {
        $file = VaultFile::onlyTrashed()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('restore', $file);

        $this->vaultService->restoreFile($file);

        return response()->json(['message' => 'Restored successfully']);
    }

    public function forceDestroy(string $uuid)
    {
        $file = VaultFile::onlyTrashed()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('forceDelete', $file);

        $this->vaultService->purgeFile($file);

        return response()->json(['message' => 'Permanently deleted successfully']);
    }

    private function buildListQuery(Request $request): Builder
    {
        $query = VaultFile::with('folder');

        $folderId = $request->query('folder_id');
        if ($folderId) {
            $query->where('folder_id', $folderId);
        } else {
            $query->whereNull('folder_id');
        }

        if ($search = $request->query('search')) {
            $search = preg_quote($search, '/');
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'like', "%{$search}%")
                    ->orWhere('alt_text', 'like', "%{$search}%");
            });
        }

        $type = $request->query('type');
        if ($type === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($type === 'document') {
            $query->whereNot('mime_type', 'like', 'image/%');
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Parse an HTTP-date header value (RFC 7231 / RFC 850 / asctime) into a Unix timestamp.
     * Returns 0 if the value cannot be parsed, which causes the 304 condition to be false
     * (safe fallback — serves the full response rather than a wrong 304).
     */
    private function parseHttpDate(string $value): int
    {
        $ts = strtotime($value);

        return $ts !== false ? $ts : 0;
    }
}
