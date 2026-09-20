<?php

namespace Tests\Feature;

use App\Jobs\OptimizeVaultImageJob;
use App\Models\VaultFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OptimizeVaultImageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_png_is_converted_to_webp(): void
    {
        $path = 'vault/photo.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('photo.png', 40, 40)->getContent());

        $file = VaultFile::factory()->create([
            'storage_path' => $path,
            'extension' => 'png',
            'mime_type' => 'image/png',
            'is_public' => true,
        ]);

        (new OptimizeVaultImageJob($file))->handle();

        $file->refresh();
        $this->assertTrue($file->is_optimized);
        $this->assertSame('vault/optimized_photo.webp', $file->optimized_path);
        $this->assertTrue(Storage::disk('public')->exists($file->optimized_path));

        $bytes = Storage::disk('public')->get($file->optimized_path);
        $this->assertSame('WEBP', substr($bytes, 8, 4));
        $this->assertSame(strlen($bytes), $file->optimized_size);
    }

    public function test_missing_source_file_is_skipped(): void
    {
        $file = VaultFile::factory()->create([
            'storage_path' => 'vault/missing.jpg',
            'extension' => 'jpg',
            'is_public' => true,
        ]);

        (new OptimizeVaultImageJob($file))->handle();

        $this->assertNotTrue($file->refresh()->is_optimized);
    }
}
