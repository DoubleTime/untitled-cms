<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FlowchartScript;
use App\Models\FlowchartScriptImage;
use Illuminate\Http\Request;

/**
 * A FlowChart Script with its Preview Images and every Revision the API exposes.
 *
 * @mixin FlowchartScript
 */
class FlowchartScriptDetailResource extends FlowchartScriptResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'images' => $this->relationLoaded('images')
                ? $this->images->map(fn (FlowchartScriptImage $image) => [
                    'url' => $image->vaultFile?->url,
                    'sort_order' => (int) $image->sort_order,
                ])->values()->all()
                : [],
            'revisions' => RevisionResource::collection($this->visibleRevisions())->resolve($request),
        ];
    }
}
