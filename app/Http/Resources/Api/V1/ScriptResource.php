<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\PresentsCatalogueEntry;
use App\Models\Script;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Script in a list payload.
 *
 * @mixin Script
 */
class ScriptResource extends JsonResource
{
    use PresentsCatalogueEntry;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->commonEntryFields() + [
            'preview_image_url' => $this->previewImageUrl(),
        ];
    }

    /** The first Preview Image represents the Script. */
    protected function previewImageUrl(): ?string
    {
        if (! $this->relationLoaded('images')) {
            return null;
        }

        return $this->images->first()?->vaultFile?->url;
    }
}
