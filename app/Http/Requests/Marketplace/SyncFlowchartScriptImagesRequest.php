<?php

namespace App\Http\Requests\Marketplace;

use App\Models\VaultFile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces the whole ordered Preview Image list for a FlowChart Script.
 * The first entry is the cover.
 */
class SyncFlowchartScriptImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('script'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vault_file_ids' => ['present', 'array', 'max:50'],
            'vault_file_ids.*' => ['required', 'string', 'distinct', 'exists:vault_files,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ids = array_filter((array) $this->input('vault_file_ids', []));

            if ($ids === [] || $validator->errors()->isNotEmpty()) {
                return;
            }

            // Preview Images are pictures — a PDF or a zip in the Vault is not one.
            $nonImage = VaultFile::whereIn('id', $ids)
                ->where(function ($query) {
                    $query->whereNull('mime_type')->orWhere('mime_type', 'not like', 'image/%');
                })
                ->exists();

            if ($nonImage) {
                $validator->errors()->add('vault_file_ids', 'Preview Images must be image files.');
            }
        });
    }
}
