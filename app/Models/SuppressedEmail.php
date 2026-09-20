<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

class SuppressedEmail extends Model
{
    use HasUlidKey;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'reason', // bounced_hard, complained, unsubscribed
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Check if an email is suppressed.
     */
    public static function isSuppressed(string $email): bool
    {
        return self::where('email', strtolower($email))->exists();
    }
}
