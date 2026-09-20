<?php

namespace App\Services;

use App\Jobs\OptimizeVaultImageJob;
use App\Models\Setting;
use App\Models\VaultAuditLog;
use App\Models\VaultFile;
use App\Models\VaultFolder;
use App\Vault\DTOs\VaultPipelinePayload;
use App\Vault\Pipes\DetectDoubleExtension;
use App\Vault\Pipes\GenerateUuid;
use App\Vault\Pipes\ModerationCheck;
use App\Vault\Pipes\SandboxedScan;
use App\Vault\Pipes\SanitizeImage;
use App\Vault\Pipes\StoreMetadata;
use App\Vault\Pipes\ValidateMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VaultService
{
    public function upload(UploadedFile $file, ?string $folderId = null, ?VaultFolder $targetFolder = null, bool $isPublic = true): VaultFile
    {
        $folder = $targetFolder ?? $this->ensureFolderExists($folderId);

        $pipes = [
            DetectDoubleExtension::class,
            ValidateMimeType::class,
            SanitizeImage::class,
            ModerationCheck::class,
            GenerateUuid::class,
            StoreMetadata::class,
        ];

        if (config('vault.clamav_enabled')) {
            array_splice($pipes, 2, 0, SandboxedScan::class);
        }

        $payload = new VaultPipelinePayload($file, $folderId, $folder);
        $payload->is_public = $isPublic;

        return app(Pipeline::class)
            ->send($payload)
            ->through($pipes)
            ->then(function (VaultPipelinePayload $payload) {
                $file = $payload->created_file;
                $this->audit('file.upload', $file, null, $file->toArray());

                if (Setting::get('vault.webp_conversion', true)) {
                    OptimizeVaultImageJob::dispatch($file);
                }

                return $file;
            });
    }

    private function ensureFolderExists(?string $folderId): ?VaultFolder
    {
        if (! $folderId) {
            return null;
        }

        $folder = VaultFolder::find($folderId);

        if (! $folder) {
            throw new \InvalidArgumentException('Folder not found.');
        }

        return $folder;
    }

    public function createFolder(string $name, ?string $parentId, $ownerId): VaultFolder
    {
        $folder = VaultFolder::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'parent_id' => $parentId,
            'owner_id' => $ownerId,
            // path_slug logic could be added here if needed for materialized path
            'path_slug' => $this->generatePathSlug($name, $parentId),
        ]);

        $this->audit('folder.create', $folder, null, $folder->toArray());

        return $folder;
    }

    public function renameFile(VaultFile $file, string $newName): void
    {
        $oldValues = ['original_name' => $file->original_name];

        // Sanitize new name (prevent traversal, specific chars)
        $cleanName = basename($newName);

        $file->update(['original_name' => $cleanName]);

        $this->audit('file.rename', $file, $oldValues, ['original_name' => $cleanName]);
    }

    public function moveFile(VaultFile $file, ?string $targetFolderId): void
    {
        $oldValues = ['folder_id' => $file->folder_id];

        if ($targetFolderId) {
            $folder = VaultFolder::findOrFail($targetFolderId);
        }

        $file->update(['folder_id' => $targetFolderId]);

        $this->audit('file.move', $file, $oldValues, ['folder_id' => $targetFolderId]);
    }

    public function deleteFile(VaultFile $file): void
    {
        $fileData = $file->toArray();

        // Secure the file by appending .trashed so it's no longer publicly accessible
        $this->moveStorageFile($file, '', '.trashed');

        $file->delete(); // Soft delete
        $this->audit('file.delete', $file, $fileData, null);
    }

    public function restoreFile(VaultFile $file): void
    {
        // Revert the .trashed extension
        $this->moveStorageFile($file, '.trashed', '');

        $file->restore();
        $this->audit('file.restore', $file, null, $file->toArray());
    }

    public function purgeFile(VaultFile $file): void
    {
        $fileData = $file->toArray();
        $disk = Storage::disk($this->resolveDiskPrefix($file));

        // Remove the physical file (it might have .trashed appended if we soft-deleted it)
        $this->deleteStorageFile($file, '.trashed');
        // Fallback for hard-deleting an active file directly
        $this->deleteStorageFile($file, '');

        // Delete the optimized WebP variant if it exists
        if ($file->optimized_path && $disk->exists($file->optimized_path)) {
            $disk->delete($file->optimized_path);
        }

        $file->forceDelete(); // Hard delete
        $this->audit('file.purge', $file, $fileData, null);
    }

    private function resolveDiskPrefix(VaultFile $file): string
    {
        return $file->is_public ? 'public' : 'vault';
    }

    private function moveStorageFile(VaultFile $file, string $fromSuffix = '', string $toSuffix = ''): void
    {
        $disk = Storage::disk($this->resolveDiskPrefix($file));
        $fromPath = $file->storage_path.$fromSuffix;
        $toPath = $file->storage_path.$toSuffix;

        if ($disk->exists($fromPath)) {
            $disk->move($fromPath, $toPath);
        }
    }

    private function deleteStorageFile(VaultFile $file, string $suffix = ''): void
    {
        $disk = Storage::disk($this->resolveDiskPrefix($file));
        $path = $file->storage_path.$suffix;

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    public function renameFolder(VaultFolder $folder, string $newName): void
    {
        $oldPathSlug = $folder->path_slug;
        $oldValues = ['name' => $folder->name, 'path_slug' => $oldPathSlug];

        $folder->name = $newName;
        $folder->path_slug = $this->generatePathSlug($newName, $folder->parent_id);
        $folder->save();

        $this->cascadeFolderUpdate($folder, $oldPathSlug);

        $this->audit('folder.rename', $folder, $oldValues, ['name' => $newName, 'path_slug' => $folder->path_slug]);
    }

    public function moveFolder(VaultFolder $folder, ?string $parentId): void
    {
        $oldPathSlug = $folder->path_slug;
        $oldValues = ['parent_id' => $folder->parent_id, 'path_slug' => $oldPathSlug];

        // Block if moving into self or child
        if ($parentId) {
            $target = VaultFolder::findOrFail($parentId);
            if ($target->id === $folder->id || str_starts_with($target->path_slug, $folder->path_slug.'/')) {
                throw new \InvalidArgumentException('Cannot move a folder into itself or its children.');
            }
        }

        $folder->parent_id = $parentId;
        $folder->path_slug = $this->generatePathSlug($folder->name, $parentId);
        $folder->save();

        $this->cascadeFolderUpdate($folder, $oldPathSlug);

        $this->audit('folder.move', $folder, $oldValues, ['parent_id' => $parentId, 'path_slug' => $folder->path_slug]);
    }

    protected function cascadeFolderUpdate(VaultFolder $folder, ?string $oldPathSlug): void
    {
        if (empty($oldPathSlug)) {
            return;
        }

        // 1. Update children path_slugs
        VaultFolder::where('path_slug', 'like', $oldPathSlug.'/%')->chunk(100, function ($children) use ($oldPathSlug, $folder) {
            foreach ($children as $child) {
                $relativePart = Str::after($child->path_slug, $oldPathSlug);
                $newChildSlug = $folder->path_slug.$relativePart;
                $child->update(['path_slug' => $newChildSlug]);
            }
        });

        // 2. Relocate physical files
        $childrenSlugs = VaultFolder::where('path_slug', 'like', $folder->path_slug.'/%')->pluck('path_slug', 'id')->toArray();
        $folderIds = array_merge(array_keys($childrenSlugs), [$folder->id]);

        // Pre-build a folder_id → path_slug map
        $slugMap = [];
        foreach ($childrenSlugs as $id => $slug) {
            $slugMap[(string) $id] = $slug;
        }
        $slugMap[(string) $folder->id] = $folder->path_slug;

        $moved = [];
        try {
            VaultFile::whereIn('folder_id', $folderIds)->chunk(100, function ($files) use (&$moved, $slugMap) {
                foreach ($files as $file) {
                    $disk = Storage::disk($this->resolveDiskPrefix($file));
                    $oldStoragePath = $file->storage_path;

                    // Determine new storage path using the pre-built map (no DB query per file)
                    $filename = basename($oldStoragePath);
                    $folderPath = $slugMap[(string) $file->folder_id] ?? '';
                    $newStoragePath = 'vault'.($folderPath ? '/'.ltrim($folderPath, '/') : '').'/'.$filename;

                    if ($oldStoragePath === $newStoragePath) {
                        continue;
                    }

                    if ($disk->exists($oldStoragePath)) {
                        // Ensure target directory exists
                        $disk->makeDirectory(dirname($newStoragePath));
                        $disk->move($oldStoragePath, $newStoragePath);
                        $moved[] = [$oldStoragePath, $newStoragePath, $file, null, null];

                        $updates = ['storage_path' => $newStoragePath];

                        // Fix #4: also relocate the WebP optimized variant so
                        // resolveServingPath() doesn't return a stale/orphaned path.
                        $newOptPath = null;
                        if ($file->is_optimized && $file->optimized_path) {
                            $optFilename = basename($file->optimized_path);
                            $newOptPath = 'vault'.($folderPath ? '/'.ltrim($folderPath, '/') : '').'/'.$optFilename;
                            if ($file->optimized_path !== $newOptPath && $disk->exists($file->optimized_path)) {
                                // Skip makeDirectory if the opt file shares the same target
                                // directory as the main file (the common case) — it was already
                                // created above and the call would be a redundant filesystem round-trip.
                                if (dirname($newOptPath) !== dirname($newStoragePath)) {
                                    $disk->makeDirectory(dirname($newOptPath));
                                }
                                $disk->move($file->optimized_path, $newOptPath);
                                $updates['optimized_path'] = $newOptPath;
                            }
                        }

                        // Update the rollback entry with the old optimized path for reversal
                        $moved[array_key_last($moved)] = [$oldStoragePath, $newStoragePath, $file, $file->optimized_path, $newOptPath];

                        $file->update($updates);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Folder relocation failed. Starting rollback for '.count($moved).' files. Error: '.$e->getMessage());
            // Rollback disk moves on failure
            foreach (array_reverse($moved) as [$from, $to, $f, $oldOptPath, $newOptPath]) {
                try {
                    $disk = Storage::disk($this->resolveDiskPrefix($f));
                    $disk->move($to, $from);
                    $rollbackUpdates = ['storage_path' => $from];

                    if ($newOptPath && $oldOptPath && $disk->exists($newOptPath)) {
                        $disk->move($newOptPath, $oldOptPath);
                        $rollbackUpdates['optimized_path'] = $oldOptPath;
                    }

                    $f->update($rollbackUpdates);
                } catch (\Throwable $rollbackError) {
                    Log::error('Relocation rollback failed for file: '.$f->uuid.". From: $to, To: $from. Error: ".$rollbackError->getMessage());
                }
            }
            Log::info('Folder relocation rollback complete.');
            throw $e;
        }
    }

    public function deleteFolder(VaultFolder $folder): void
    {
        $folderData = $folder->toArray(); // Capture before soft delete
        $folder->delete();
        $this->audit('folder.delete', $folder, $folderData, null);
    }

    public function restoreFolder(VaultFolder $folder): void
    {
        $folder->restore();
        $this->audit('folder.restore', $folder, null, $folder->toArray());
    }

    public function purgeFolder(VaultFolder $folder): void
    {
        // Cascade delete children records using parent_id to avoid exponential loops
        VaultFolder::where('parent_id', $folder->id)->withTrashed()->chunk(50, function ($children) {
            foreach ($children as $child) {
                $this->purgeFolder($child);
            }
        });

        // Purge files in this folder
        VaultFile::where('folder_id', $folder->id)->withTrashed()->chunk(100, function ($files) {
            foreach ($files as $file) {
                $this->purgeFile($file);
            }
        });

        $folderData = $folder->toArray();
        $folder->forceDelete();
        $this->audit('folder.purge', $folder, $folderData, null);
    }

    private function audit($event, $resource, $old = null, $new = null)
    {
        try {
            VaultAuditLog::create([
                'user_id' => Auth::id() ?? null,
                'event' => $event,
                'resource_type' => class_basename($resource),
                'resource_id' => $resource->id,
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to write audit log: '.$e->getMessage());
        }
    }

    private function generatePathSlug($name, $parentId)
    {
        $slug = '/'.Str::slug($name);

        if (! $parentId) {
            return $slug;
        }

        $parent = VaultFolder::find($parentId);

        return $parent ? $parent->path_slug.$slug : $slug;
    }
}
