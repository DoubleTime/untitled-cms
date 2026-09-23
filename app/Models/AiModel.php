<?php

namespace App\Models;

use App\Models\Concerns\HasRevisions;
use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A trained inference model published on its own, independent of any Script.
 */
class AiModel extends Model
{
    use HasFactory, HasRevisions, HasUlidKey, SoftDeletes;

    protected $fillable = [
        'machine_model_id',
        'customer_id',
        'name',
        'slug',
        'description',
        'framework',
        'input_size',
        'labels',
        'notes',
        'created_by',
    ];

    public function machineModel(): BelongsTo
    {
        return $this->belongsTo(MachineModel::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
