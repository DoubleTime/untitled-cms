<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VaultFile extends Model
{
    use HasFactory, HasUlidKey, SoftDeletes;

    protected $appends = ['url'];

    protected $fillable = [
        'uuid',
        'folder_id',
        'storage_path',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'hash_sha256',
        'uploaded_by',
        'is_public',
        'validation_status',
        'width',
        'height',
        'alt_text',
        'optimized_path',
        'optimized_size',
        'is_optimized',
        'use_original',
        'moderation_reason',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_public' => 'boolean',
        'width' => 'integer',
        'height' => 'integer',
        'optimized_size' => 'integer',
        'is_optimized' => 'boolean',
        'use_original' => 'boolean',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(VaultFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        if ($this->is_public && ! $this->trashed()) {
            // Include the file extension in the URL so browsers and CDNs can infer
            // the content type from the path — e.g. /media/bb440a63-....jpg
            return route('vault.public', [
                'uuid' => $this->uuid,
                'extension' => $this->extension,
            ]);
        }

        // Fallback for private files and trashed files
        return route('admin.vault.file.serve', ['uuid' => $this->uuid]);
    }

    public function resolveServingPath(): string
    {
        if ($this->is_optimized && ! $this->use_original && $this->optimized_path) {
            return $this->optimized_path;
        }

        return $this->storage_path;
    }
}
