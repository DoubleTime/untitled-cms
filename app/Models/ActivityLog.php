<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory, HasUlidKey;

    protected $fillable = [
        'user_id',
        'action', // created, updated, deleted, ai_created, ai_updated, reverted
        'description',
        'subject_type', // App\Models\Page
        'subject_id',
        'ip_address',
        'user_agent',
        'before_state', // JSON snapshot of record before AI action (for revert)
        'is_ai_action', // bool flag for AI-triggered entries
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'before_state' => 'array',
        'is_ai_action' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
