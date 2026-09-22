<?php

namespace Database\Factories;

use App\Models\MachineModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AiModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'machine_model_id' => MachineModel::factory(),
            'customer_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'framework' => 'keras',
            'input_size' => '224x224',
            'labels' => 'ok,ng',
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
