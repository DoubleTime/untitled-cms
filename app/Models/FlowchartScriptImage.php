<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Preview Image attached to a FlowChart Script — a flow diagram or screenshot
 * so Team Members and Customer Users can recognise it before downloading.
 *
 * The picture itself is an ordinary Vault file served on the public /media route.
 */
class FlowchartScriptImage extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'flowchart_script_id',
        'vault_file_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function flowchartScript(): BelongsTo
    {
        return $this->belongsTo(FlowchartScript::class);
    }

    public function vaultFile(): BelongsTo
    {
        return $this->belongsTo(VaultFile::class);
    }
}
