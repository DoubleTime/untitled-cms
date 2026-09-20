<?php

namespace App\Http\Requests;

use App\Models\VaultFile;
use App\Services\SafeHttpClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

class SaveAiImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('create', VaultFile::class);
    }

    public function rules(): array
    {
        return [
            'image' => 'required|string',
            'filename' => 'nullable|string|max:255',
            'folder_id' => 'nullable|string|exists:\App\Models\VaultFolder,id',
        ];
    }

    public function getPreparedUploadedFile(): UploadedFile
    {
        $imageData = $this->input('image');
        $customName = $this->input('filename');

        if (str_starts_with($imageData, 'data:')) {
            return $this->prepareBase64ImageFile($imageData, $customName);
        }

        return $this->prepareRemoteImageFile($imageData, $customName);
    }

    private function prepareBase64ImageFile(string $dataUri, ?string $customName): UploadedFile
    {
        if (! preg_match('/^data:(image\/[a-zA-Z+]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new \Exception('Invalid image data URI.');
        }

        $mimeType = $matches[1];
        $extension = explode('/', $mimeType)[1] ?? 'png';
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $binaryData = base64_decode($matches[2]);

        return $this->createTempUploadedFile($binaryData, $extension, $mimeType, $customName);
    }

    private function prepareRemoteImageFile(string $url, ?string $customName): UploadedFile
    {
        $parsedUrl = parse_url($url);
        if (! $parsedUrl || ! isset($parsedUrl['host'])) {
            throw new \Exception('Invalid image URL format.');
        }

        $response = SafeHttpClient::get($url, 10);
        if (! $response->successful()) {
            throw new \Exception('Failed to download remote image. Status: '.$response->status());
        }

        $contentType = $response->header('Content-Type') ?? 'image/png';
        // Strip charset or boundary parameters (e.g. "image/jpeg; charset=utf-8" → "image/jpeg").
        $mime = strtolower(trim(explode(';', $contentType)[0]));

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        if (! in_array($mime, $allowedMimes, true)) {
            throw new \Exception('Remote URL did not return a supported image type: '.$mime);
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'png',
        };

        return $this->createTempUploadedFile($response->body(), $extension, $mime, $customName);
    }

    private function createTempUploadedFile(string $binaryData, string $extension, string $mimeType, ?string $customName): UploadedFile
    {
        // tempnam() creates a zero-byte file at the base path; we need a .ext variant.
        // Delete the base file immediately to avoid an orphaned temp file.
        $basePath = tempnam(sys_get_temp_dir(), 'ai_vault_');
        @unlink($basePath);
        $tmpPath = $basePath.'.'.$extension;
        file_put_contents($tmpPath, $binaryData);

        $filename = $customName
            ? preg_replace('/[^a-zA-Z0-9\-_\.]/', '-', $customName).'.'.$extension
            : 'ai-generated-'.now()->format('Ymd-His').'.'.$extension;

        return new UploadedFile(
            $tmpPath,
            $filename,
            $mimeType,
            \UPLOAD_ERR_OK,
            true
        );
    }
}
