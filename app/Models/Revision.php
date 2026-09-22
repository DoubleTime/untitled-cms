<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An immutable, sequentially numbered upload of an AI Model or FlowChart Script,
 * carrying a change note and the Team Member who uploaded it.
 *
 * Only released Revisions are offered to RPA-TOOL by default. The numbering is
 * per revisable and enforced by a unique index on (revisable_type, revisable_id, number).
 */
class Revision extends Model
{
    use HasFactory, HasUlidKey;

    // Revision Status. Plain strings — the repo uses no backed enums.
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RELEASED = 'released';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_RELEASED,
        self::STATUS_DEPRECATED,
    ];

    protected $fillable = [
        'revisable_type',
        'revisable_id',
        'number',
        'status',
        'change_note',
        'original_filename',
        'disk_path',
        'size_bytes',
        'sha256',
        'mime',
        'uploaded_by',
        'released_by',
        'released_at',
        'deprecated_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'size_bytes' => 'integer',
        'released_at' => 'datetime',
        'deprecated_at' => 'datetime',
    ];

    public function revisable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RELEASED);
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    public function isDeprecated(): bool
    {
        return $this->status === self::STATUS_DEPRECATED;
    }
}
