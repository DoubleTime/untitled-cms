<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\PresentsCatalogueEntry;
use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An AI Model in a list payload. AI Models have no Preview Images; they carry the
 * inference metadata instead.
 *
 * @mixin AiModel
 */
class AiModelResource extends JsonResource
{
    use PresentsCatalogueEntry;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->commonEntryFields() + [
            'framework' => $this->framework,
            'input_size' => $this->input_size,
            'labels' => $this->labels,
            'notes' => $this->notes,
        ];
    }
}
