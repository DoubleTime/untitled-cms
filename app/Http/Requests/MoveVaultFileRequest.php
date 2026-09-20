<?php

namespace App\Http\Requests;

use App\Models\VaultFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveVaultFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'folder_id' => ['nullable', 'string', Rule::exists(VaultFolder::class, 'id')],
        ];
    }
}
