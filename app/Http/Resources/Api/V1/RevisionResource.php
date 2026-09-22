<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Revision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Revision as RPA-TOOL sees it. Draft Revisions are filtered out before this
 * resource is ever reached — the API only ever shows released and deprecated.
 *
 * @mixin Revision
 */
class RevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'status' => $this->status,
            'size_bytes' => (int) $this->size_bytes,
            'sha256' => $this->sha256,
            'change_note' => $this->change_note,
            'original_filename' => $this->original_filename,
            'released_at' => $this->released_at?->toISOString(),
            'deprecated_at' => $this->deprecated_at?->toISOString(),
        ];
    }
}
