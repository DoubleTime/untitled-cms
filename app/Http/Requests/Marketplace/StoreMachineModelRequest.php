<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MachineModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMachineModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MachineModel::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'machine_brand_id' => 'required|string|exists:machine_brands,id',
            // The schema enforces unique (machine_brand_id, name); mirror it here.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('machine_models', 'name')
                    ->where('machine_brand_id', $this->input('machine_brand_id')),
            ],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
