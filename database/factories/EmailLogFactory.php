<?php

namespace Database\Factories;

use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailLog>
 */
class EmailLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_message_id' => '<'.Str::random(24).'@example.com>',
            'recipient' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'mailable' => 'App\\Mail\\TestMail',
            'status' => 'delivered',
            'sent_at' => now(),
            'delivered_at' => now(),
        ];
    }
}
