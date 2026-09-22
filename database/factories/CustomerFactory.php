<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = fake()->unique()->company();

        return [
            // Short, unique, uppercase key, e.g. INARI-123.
            'code' => strtoupper(fake()->unique()->bothify('?????-###')),
            'company' => $company,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'notes' => null,
            'is_active' => true,
        ];
    }
}
