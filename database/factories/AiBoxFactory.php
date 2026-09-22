<?php

namespace Database\Factories;

use App\Models\AiBox;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AiBoxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'motherboard_uuid' => (string) Str::uuid(),
            'name' => fake()->word().' box',
            'location' => fake()->city(),
            'machine_model_id' => null,
            'status' => AiBox::STATUS_PENDING,
            'last_seen_at' => now(),
            'last_ip' => fake()->ipv4(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => AiBox::STATUS_ACTIVE]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => AiBox::STATUS_BLOCKED]);
    }
}
