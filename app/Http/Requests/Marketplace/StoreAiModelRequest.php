<?php

namespace App\Http\Requests\Marketplace;

use App\Models\AiModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AiModel::class);
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
                // An AI Model name is unique within its Machine Model.
                Rule::unique('ai_models', 'name')
                    ->where('machine_model_id', $this->input('machine_model_id'))
                    ->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string',
            'framework' => 'nullable|string|max:255',
            'input_size' => 'nullable|string|max:255',
            'labels' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }
}
