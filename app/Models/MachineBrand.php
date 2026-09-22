<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable tag naming the manufacturer of a Machine Model.
 */
class MachineBrand extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function machineModels(): HasMany
    {
        return $this->hasMany(MachineModel::class);
    }
}
