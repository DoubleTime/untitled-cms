<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific make of equipment that FlowChart Scripts and AI Models target;
 * the primary way the catalogue is organised.
 */
class MachineModel extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'machine_brand_id',
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function machineBrand(): BelongsTo
    {
        return $this->belongsTo(MachineBrand::class);
    }

    public function flowchartScripts(): HasMany
    {
        return $this->hasMany(FlowchartScript::class);
    }

    public function aiModels(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function aiBoxes(): HasMany
    {
        return $this->hasMany(AiBox::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
