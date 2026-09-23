<?php

namespace App\Http\Resources\Api\V1;

use App\Models\MachineModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MachineModel */
class MachineModelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // Inactive Machine Models are listed — an entry may still be labelled
            // with one — so the flag is what lets RPA-TOOL grey the row out.
            'is_active' => (bool) $this->is_active,
            'brand' => $this->whenLoaded('machineBrand', fn () => [
                'id' => $this->machineBrand->id,
                'name' => $this->machineBrand->name,
            ]),
        ];
    }
}
