<?php

namespace Database\Factories;

use App\Models\Script;
use App\Models\VaultFile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScriptImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'script_id' => Script::factory(),
            'vault_file_id' => VaultFile::factory(),
            'sort_order' => 0,
        ];
    }
}
