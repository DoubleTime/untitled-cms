<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * The Customer code is the short unique key (e.g. INARI-123); it is always stored
     * uppercase so the uniqueness check and every lookup agree on one spelling.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'alpha_dash', 'max:32',
                Rule::unique('customers', 'code')->ignore($this->route('customer')?->id),
            ],
            'company' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
