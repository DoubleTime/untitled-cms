<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlowchartScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('script'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'machine_model_id' => 'required|string|exists:machine_models,id',
            'customer_id' => 'nullable|string|exists:customers,id',
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('flowchart_scripts', 'name')
                    ->where('machine_model_id', $this->input('machine_model_id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('script')->id),
            ],
            'description' => 'nullable|string',
        ];
    }
}
