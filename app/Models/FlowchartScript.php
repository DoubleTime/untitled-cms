<?php

namespace App\Models;

use App\Models\Concerns\HasRevisions;
use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A packaged automation sequence for one Machine Model, bundling its flow
 * definition with whatever AI models and libraries it needs to run.
 *
 * The packaged file itself lives on Revisions, on the private `marketplace`
 * disk rather than in the Vault (docs/adr/0003).
 */
class FlowchartScript extends Model
{
    use HasFactory, HasRevisions, HasUlidKey, SoftDeletes;

    protected $fillable = [
        'machine_model_id',
        'customer_id',
        'name',
        'slug',
        'description',
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

    /**
     * Preview Images, in display order.
     */
    public function images(): HasMany
    {
        return $this->hasMany(FlowchartScriptImage::class)->orderBy('sort_order');
    }
}
