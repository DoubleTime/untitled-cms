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
            'brand' => $this->whenLoaded('machineBrand', fn () => [
                'id' => $this->machineBrand->id,
                'name' => $this->machineBrand->name,
            ]),
        ];
    }
}
