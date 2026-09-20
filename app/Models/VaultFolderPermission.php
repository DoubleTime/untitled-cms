<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

class VaultFolderPermission extends Model
{
    use HasUlidKey;

    protected $fillable = [
        'folder_id',
        'user_id',
        'role_id',
        'permission', // 'read', 'write', 'delete'
    ];

    public function folder()
    {
        return $this->belongsTo(VaultFolder::class, 'folder_id');
    }
}
