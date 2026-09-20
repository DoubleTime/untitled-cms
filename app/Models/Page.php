<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    use HasFactory, HasUlidKey, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'status', // 'draft', 'published'
        'seo_title',
        'seo_description',
        'featured_image',
        'featured_images',
        'author_id',
        'published_at',
        'tags',
        'is_system_page',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'featured_images' => 'array',
        'tags' => 'array',
        'is_system_page' => 'boolean',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Return a slug that is unique in the pages collection.
     * Fetches all taken variants in one query to avoid N+1 loops.
     *
     * @param  string  $base  The desired base slug (already Str::slug'd).
     * @param  string|null  $excludeId  ULID to exclude (for updates).
     */
    public static function uniqueSlug(string $base, ?string $excludeId = null): string
    {
        $query = static::withTrashed()->where('slug', 'like', $base.'%');
        if ($excludeId) {
            $query->whereKeyNot($excludeId);
        }
        $taken = $query->pluck('slug')->flip()->all();

        if (! isset($taken[$base])) {
            return $base;
        }

        $counter = 2;
        while (isset($taken[$base.'-'.$counter])) {
            $counter++;
        }

        return $base.'-'.$counter;
    }
}
