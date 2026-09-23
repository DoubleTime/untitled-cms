<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customers are exposed to RPA-TOOL for filtering only — the Customer label is a
 * secondary filter, never an access wall (docs/adr/0001), so every Customer User
 * sees every Customer. Nothing but the id, the code, the company and whether the
 * Customer is still active is returned; an inactive Customer is listed so an entry
 * labelled with it still has a filter, and RPA-TOOL greys the row out.
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
            'code' => $this->code,
            'company' => $this->company,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
