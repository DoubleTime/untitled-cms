<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customers are exposed to RPA-TOOL for filtering only — the Customer label is a
 * secondary filter, never an access wall (docs/adr/0001), so every Customer User
 * sees every Customer. Nothing but the id and the company name is returned.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company' => $this->company,
        ];
    }
}
