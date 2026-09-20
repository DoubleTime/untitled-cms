<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'from_path',
        'to_path',
        'type', // 301, 302, etc.
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'type' => 'integer',
    ];

    protected $attributes = [
        'type' => 301,
        'active' => true,
    ];
}
