<?php

namespace App\Models;

use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use HasFactory, HasUlidKey;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'provider_message_id',
        'recipient',
        'subject',
        'mailable',
        'context_type',
        'context_id',
        'status',
        'sent_at',
        'opened_at',
        'delivered_at',
        'bounced_at',
        'clicked_at',
        'complained_at',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'delivered_at' => 'datetime',
        'bounced_at' => 'datetime',
        'clicked_at' => 'datetime',
        'complained_at' => 'datetime',
        'metadata' => 'array',
    ];
}
