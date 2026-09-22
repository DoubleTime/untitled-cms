<?php

namespace App\Http\Requests\Marketplace;

use App\Models\AiBox;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The editable part of an AI Box: the Team Member's label, where it stands and
 * which Machine Model it drives.
 *
 * `status` is deliberately absent — it only moves through the block / unblock /
 * activate actions, which carry their own permission and side effects.
 */
class UpdateAiBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ai_box') ?? new AiBox);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'machine_model_id' => ['nullable', 'string', 'exists:machine_models,id'],
        ];
    }
}
