<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AiModel;
use Illuminate\Http\Request;

/**
 * An AI Model with every Revision the API exposes.
 *
 * @mixin AiModel
 */
class AiModelDetailResource extends AiModelResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'revisions' => RevisionResource::collection($this->visibleRevisions())->resolve($request),
        ];
    }
}
