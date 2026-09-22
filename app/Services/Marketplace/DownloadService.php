<?php

namespace App\Services\Marketplace;

use App\Models\Download;
use App\Models\Revision;
use App\Models\UnysisBox;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records and serves Revision file downloads.
 *
 * Every fetch of a Revision file is recorded, whether it came from RPA-TOOL
 * (source `api`) or from a Team Member on the admin pages (source `web`).
 * Download rows are never deleted — not even when the Revision is hard deleted.
 */
class DownloadService
{
    public function record(
        Revision $revision,
        ?User $user,
        ?UnysisBox $box,
        string $source,
        Request $request,
    ): Download {
        return Download::create([
            'revision_id' => $revision->getKey(),
            'revisable_type' => $revision->revisable_type,
            'revisable_id' => $revision->revisable_id,
            'user_id' => $user?->getKey(),
            'unysis_box_id' => $box?->getKey(),
            'source' => $source,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    /**
     * Stream the stored file back under its original filename, with the checksum
     * and Revision number in headers so the caller can verify what it got.
     */
    public function stream(Revision $revision): StreamedResponse
    {
        return Storage::disk((string) config('marketplace.disk'))->download(
            $revision->disk_path,
            $revision->original_filename,
            [
                'X-Checksum-SHA256' => (string) $revision->sha256,
                'X-Revision-Number' => (string) $revision->number,
            ],
        );
    }
}
