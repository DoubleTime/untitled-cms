<?php

namespace App\Http\Requests\Marketplace;

use App\Models\FlowchartScript;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlowchartScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FlowchartScript::class);
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
                // A FlowChart Script name is unique within its Machine Model.
                Rule::unique('flowchart_scripts', 'name')
                    ->where('machine_model_id', $this->input('machine_model_id'))
                    ->whereNull('deleted_at'),
            ],
            'description' => 'nullable|string',
        ];
    }
}
