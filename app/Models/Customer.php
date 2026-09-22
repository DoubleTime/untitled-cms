<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company that owns AI Boxes and has its own Customer Users.
 *
 * The Customer label on a catalogue entry is a secondary filter, never an access
 * boundary — every authenticated Customer User sees the whole catalogue (docs/adr/0001).
 */
class Customer extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'name',
        'company',
        'contact_name',
        'contact_email',
        'contact_phone',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function aiBoxes(): HasMany
    {
        return $this->hasMany(AiBox::class);
    }

    public function flowchartScripts(): HasMany
    {
        return $this->hasMany(FlowchartScript::class);
    }

    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
