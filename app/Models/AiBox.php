<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A deployed UNYSIS edge device running RPA-TOOL, identified to the Marketplace by
 * its motherboard UUID and belonging to one Customer.
 *
 * Auto-registered on first API login under a Customer User's credentials; a blocked
 * box is refused at login (docs/adr/0002).
 */
class AiBox extends Model
{
    use HasFactory, HasUlidKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_BLOCKED,
    ];

    protected $fillable = [
        'customer_id',
        'motherboard_uuid',
        'name',
        'location',
        'machine_model_id',
        'status',
        'last_seen_at',
        'last_ip',
        'first_user_id',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machineModel(): BelongsTo
    {
        return $this->belongsTo(MachineModel::class);
    }

    public function firstUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_user_id');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BLOCKED);
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
