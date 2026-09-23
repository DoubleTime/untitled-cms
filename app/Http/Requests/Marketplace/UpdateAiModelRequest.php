<?php

namespace App\Http\Requests\Marketplace;

use App\Models\AiModel;
use App\Support\CatalogueEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiModelRequest extends FormRequest
{
    /** The route parameter is named after the morph alias. */
    private function aiModel(): ?AiModel
    {
        return $this->route(CatalogueEntryType::AI_MODEL);
    }

    public function authorize(): bool
    {
        return $this->aiModel() !== null && $this->user()->can('update', $this->aiModel());
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
                Rule::unique('ai_models', 'name')
                    ->where('machine_model_id', $this->input('machine_model_id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->aiModel()->id),
            ],
            'description' => 'nullable|string',
            'framework' => 'nullable|string|max:255',
            'input_size' => 'nullable|string|max:255',
            'labels' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }
}
