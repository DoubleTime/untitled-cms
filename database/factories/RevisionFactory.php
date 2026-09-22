<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default revisable; override with ->for($script, 'revisable') for a
            // Script, or by passing revisable_type/revisable_id.
            'revisable_type' => AiModel::class,
            'revisable_id' => AiModel::factory(),
            'number' => 1,
            'status' => Revision::STATUS_DRAFT,
            'change_note' => fake()->sentence(),
            'original_filename' => 'model.h5',
            'disk_path' => 'ai_model/'.fake()->uuid().'/1.h5',
            'size_bytes' => fake()->numberBetween(1000, 5_000_000),
            'sha256' => hash('sha256', Str::random(16)),
            'mime' => 'application/octet-stream',
            'uploaded_by' => User::factory(),
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => Revision::STATUS_RELEASED,
            'released_at' => now(),
        ]);
    }

    public function deprecated(): static
    {
        return $this->state(fn () => [
            'status' => Revision::STATUS_DEPRECATED,
            'released_at' => now()->subDay(),
            'deprecated_at' => now(),
        ]);
    }
}
