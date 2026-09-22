<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/login — RPA-TOOL signs in with a Customer User's credentials and
 * the motherboard UUID of the AI Box it is running on (docs/adr/0002).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'motherboard_uuid' => ['required', 'string', 'max:128'],
            'box_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
