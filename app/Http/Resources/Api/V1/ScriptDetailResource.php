<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Script;
use App\Models\ScriptImage;
use Illuminate\Http\Request;

/**
 * A Script with its Preview Images and every Revision the API exposes.
 *
 * @mixin Script
 */
class ScriptDetailResource extends ScriptResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'images' => $this->relationLoaded('images')
                ? $this->images->map(fn (ScriptImage $image) => [
                    'url' => $image->vaultFile?->url,
                    'sort_order' => (int) $image->sort_order,
                ])->values()->all()
                : [],
            'revisions' => RevisionResource::collection($this->visibleRevisions())->resolve($request),
        ];
    }
}
