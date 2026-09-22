<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMachineModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('machine_model'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'machine_brand_id' => 'required|string|exists:machine_brands,id',
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('machine_models', 'name')
                    ->where('machine_brand_id', $this->input('machine_brand_id'))
                    ->ignore($this->route('machine_model')->id),
            ],
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
