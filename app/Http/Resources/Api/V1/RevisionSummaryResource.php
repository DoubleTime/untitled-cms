<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Revision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The short form of a Revision used for `latest_revision` in list payloads and
 * for `latest` in the check-update response — enough for RPA-TOOL to decide
 * whether it needs to download, without the full Revision record.
 *
 * @mixin Revision
 */
class RevisionSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'size_bytes' => (int) $this->size_bytes,
            'sha256' => $this->sha256,
            'released_at' => $this->released_at?->toISOString(),
            'change_note' => $this->change_note,
        ];
    }
}
