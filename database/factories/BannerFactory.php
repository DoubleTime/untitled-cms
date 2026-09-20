<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'slug' => fake()->unique()->slug(),
            'slides' => null,
            'image_url' => [fake()->imageUrl()],
            'alt_text' => fake()->sentence(3),
            'link_url' => fake()->url(),
            'description' => fake()->sentence(10),
            'order' => 0,
            'is_active' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(30),
        ];
    }
}
