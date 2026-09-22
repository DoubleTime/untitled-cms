<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Preview Image attached to a Script — a flow diagram or screenshot
 * so Team Members and Customer Users can recognise it before downloading.
 *
 * The picture itself is an ordinary Vault file served on the public /media route.
 */
class ScriptImage extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'script_id',
        'vault_file_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function vaultFile(): BelongsTo
    {
        return $this->belongsTo(VaultFile::class);
    }
}
