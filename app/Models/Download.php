<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded fetch of a Revision's file, attributed to the Customer User who
 * requested it and the UNYSIS Box it was requested from.
 *
 * Both attributions are derived from the API token, never from request input
 * (docs/adr/0002). Totals and unique-box counts are derived from this table,
 * not stored.
 */
class Download extends Model
{
    use HasFactory, HasUlidKey;

    public const SOURCE_API = 'api';

    public const SOURCE_WEB = 'web';

    protected $fillable = [
        'revision_id',
        'revisable_type',
        'revisable_id',
        'user_id',
        'unysis_box_id',
        'source',
        'ip',
        'user_agent',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(Revision::class);
    }

    public function revisable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function unysisBox(): BelongsTo
    {
        return $this->belongsTo(UnysisBox::class);
    }
}
