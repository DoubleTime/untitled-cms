<?php

namespace App\Http\Requests;

use App\Models\VaultFile;
use App\Models\VaultFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadVaultFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', VaultFile::class);
    }

    public function rules(): array
    {
        return [
            'files' => 'required|array|max:20',
            'files.*' => 'file|max:'.config('vault.max_upload_kb', 51200),
            'folder_id' => 'nullable|string|exists:'.VaultFolder::class.',id',
            'is_public' => 'sometimes|boolean',
        ];
    }
}
