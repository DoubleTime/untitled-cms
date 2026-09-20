<?php

namespace App\Http\Controllers;

use App\Models\VaultFile;
use App\Models\VaultFolder;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

class VaultFolderController extends Controller
{
    private const DUPLICATE_KEY_ERROR = 11000;

    private const DUPLICATE_NAME_MESSAGE = 'A folder with this name already exists in this directory.';

    protected $vaultService;

    public function __construct(VaultService $vaultService)
    {
        $this->vaultService = $vaultService;
    }

    public function list(Request $request)
    {
        $this->authorize('viewAny', VaultFolder::class);

        $query = VaultFolder::with(['owner', 'permissions']);

        // ?all=1 returns the whole tree (sidebar / breadcrumb); otherwise one level under parent_id
        if (! $request->boolean('all')) {
            $query->where('parent_id', $request->query('parent_id'));
        }

        $folders = $query->orderBy('name')->get();

        $folderIds = $folders->pluck('id')->map(fn ($id) => (string) $id)->toArray();

        $filesStats = VaultFile::query()
            ->whereIn('folder_id', $folderIds)
            ->groupBy('folder_id')
            ->get([
                'folder_id',
                DB::raw('count(*) as files_count'),
                DB::raw('coalesce(sum(size_bytes), 0) as files_size'),
            ])
            ->keyBy('folder_id');

        $folders->transform(function (VaultFolder $folder) use ($filesStats) {
            $stat = $filesStats->get((string) $folder->getKey());

            $folder->files_count = $stat ? (int) $stat->files_count : 0;
            $folder->files_size = $stat ? (int) $stat->files_size : 0;
            // Mark restricted folders so frontend can grey them out (Phase 1.2)
            $folder->is_restricted = $folder->permissions->isNotEmpty();
            $folder->makeHidden('permissions');

            return $folder;
        });

        return response()->json($folders);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|string',
        ]);

        $parent = null;
        if ($request->parent_id) {
            $parent = VaultFolder::findOrFail($request->parent_id);
        }

        $this->authorize('create', [VaultFolder::class, $parent]);

        // Prevent folder name collisions within the same parent
        $exists = VaultFolder::where('parent_id', $request->parent_id)
            ->where('name', $request->name)
            ->exists();
        if ($exists) {
            return response()->json(['error' => self::DUPLICATE_NAME_MESSAGE], 422);
        }

        $folder = null;
        $conflict = $this->withUniqueNameGuard(function () use ($request, &$folder) {
            $folder = $this->vaultService->createFolder($request->name, $request->parent_id, Auth::id());
        }, self::DUPLICATE_NAME_MESSAGE);

        return $conflict ?? response()->json($folder);
    }

    public function rename(Request $request, string $id)
    {
        $folder = VaultFolder::findOrFail($id);
        $this->authorize('update', $folder);

        $request->validate(['name' => 'required|string|max:255']);

        // Prevent folder name collisions within the same parent
        $exists = VaultFolder::where('parent_id', $folder->parent_id)
            ->where('name', $request->name)
            ->whereKeyNot($folder->getKey())
            ->exists();
        if ($exists) {
            return response()->json(['error' => self::DUPLICATE_NAME_MESSAGE], 422);
        }

        $conflict = $this->withUniqueNameGuard(
            fn () => $this->vaultService->renameFolder($folder, $request->name),
            self::DUPLICATE_NAME_MESSAGE
        );

        return $conflict ?? response()->json($folder->fresh());
    }

    public function move(Request $request, string $id)
    {
        $folder = VaultFolder::findOrFail($id);
        $this->authorize('update', $folder);

        $request->validate([
            'parent_id' => 'nullable|string',
        ]);

        if ($request->parent_id) {
            $destination = VaultFolder::findOrFail($request->parent_id);
            $this->authorize('update', $destination);
        }

        $collisionMessage = 'A folder with the same name already exists in the destination directory.';

        // Prevent folder name collisions within the target parent
        $exists = VaultFolder::where('parent_id', $request->parent_id)
            ->where('name', $folder->name)
            ->whereKeyNot($folder->getKey())
            ->exists();
        if ($exists) {
            return response()->json(['error' => $collisionMessage], 422);
        }

        try {
            $conflict = $this->withUniqueNameGuard(
                fn () => $this->vaultService->moveFolder($folder, $request->parent_id),
                $collisionMessage
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return $conflict ?? response()->json($folder->fresh());
    }

    public function restore(string $id)
    {
        $folder = VaultFolder::onlyTrashed()->findOrFail($id);
        $this->authorize('delete', $folder);

        // Phase 4.2: Block if parent folder is also trashed
        if ($folder->parent_id) {
            $parent = VaultFolder::withTrashed()->find($folder->parent_id);
            if ($parent && $parent->trashed()) {
                return response()->json(['error' => 'Cannot restore folder while parent is trashed'], 422);
            }
        }

        $collisionMessage = 'A folder with this name already exists in this directory. Rename it before restoring.';

        // An active sibling with the same name would violate vault_folders_parent_name_unique
        $exists = VaultFolder::where('parent_id', $folder->parent_id)
            ->where('name', $folder->name)
            ->exists();
        if ($exists) {
            return response()->json(['error' => $collisionMessage], 422);
        }

        $conflict = $this->withUniqueNameGuard(
            fn () => $this->vaultService->restoreFolder($folder),
            $collisionMessage
        );

        return $conflict ?? response()->json(['message' => 'Restored successfully']);
    }

    public function destroy(string $id)
    {
        $folder = VaultFolder::findOrFail($id);
        $this->authorize('delete', $folder);

        // Block soft delete if not empty (MVP rule)
        if ($folder->children()->exists() || $folder->files()->exists()) {
            return response()->json(['error' => 'Folder is not empty'], 422);
        }

        $this->vaultService->deleteFolder($folder);

        return response()->json(['message' => 'Deleted successfully']);
    }

    public function forceDestroy(string $id)
    {
        $folder = VaultFolder::withTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $folder);

        $this->vaultService->purgeFolder($folder);

        return response()->json(['message' => 'Folder and all contents purged successfully']);
    }

    /**
     * Run a folder write. If a concurrent request wins the race after the pre-check,
     * vault_folders_parent_name_unique rejects the write; map that to the same 422.
     */
    private function withUniqueNameGuard(callable $write, string $message): ?JsonResponse
    {
        try {
            $write();

            return null;
        } catch (BulkWriteException $e) {
            if ($e->getCode() !== self::DUPLICATE_KEY_ERROR) {
                throw $e;
            }

            return response()->json(['error' => $message], 422);
        }
    }
}
