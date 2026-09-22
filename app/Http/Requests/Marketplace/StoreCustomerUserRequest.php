<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Creating a Customer User under a Customer. Managing a Customer's users is an
 * edit of that Customer, so it is gated on `customers.edit`.
 */
class StoreCustomerUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            // Omitted means "invite": a password reset link is sent so the
            // Customer User sets their own password.
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
