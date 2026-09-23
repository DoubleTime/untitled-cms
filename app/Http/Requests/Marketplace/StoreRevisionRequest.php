<?php

namespace App\Http\Requests\Marketplace;

use App\Support\CatalogueEntryType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by both catalogue entry types — the route model tells us which one.
 *
 * The `max:` rule enforces the cap in kilobytes from config/marketplace.php, so
 * the limit is the app's, not php.ini's. A body larger than php.ini's
 * post_max_size never reaches validation at all; that is handled as a
 * PostTooLargeException in bootstrap/app.php.
 */
class StoreRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // One request class serves both catalogue entry types, and the route
        // parameter is named after the alias, so the aliases are what it looks for.
        foreach (CatalogueEntryType::ALL as $alias) {
            $revisable = $this->route($alias);

            if ($revisable !== null) {
                return $this->user()->can('upload', $revisable);
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('marketplace.max_upload_kb')],
            'change_note' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(((int) config('marketplace.max_upload_kb')) / 1024);

        return [
            'file.max' => "The Revision file may not be larger than {$maxMb} MB.",
            'change_note.required' => 'A change note is required for every Revision.',
        ];
    }
}
