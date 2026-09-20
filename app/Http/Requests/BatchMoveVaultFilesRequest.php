<?php

namespace App\Http\Requests;

use App\Models\VaultFile;
use App\Models\VaultFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchMoveVaultFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Per-file / target-folder Gate checks run in the controller.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Cap bounds the per-item exists queries and per-file policy checks
            'uuids' => 'required|array|max:500',
            'uuids.*' => ['string', Rule::exists(VaultFile::class, 'uuid')],
            'folder_id' => ['nullable', 'string', Rule::exists(VaultFolder::class, 'id')],
        ];
    }
}
