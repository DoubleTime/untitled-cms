<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MachineBrand;
use Illuminate\Foundation\Http\FormRequest;

class StoreMachineBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MachineBrand::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:machine_brands,name',
        ];
    }
}
