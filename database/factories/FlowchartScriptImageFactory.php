<?php

namespace Database\Factories;

use App\Models\FlowchartScript;
use App\Models\VaultFile;
use Illuminate\Database\Eloquent\Factories\Factory;

class FlowchartScriptImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flowchart_script_id' => FlowchartScript::factory(),
            'vault_file_id' => VaultFile::factory(),
            'sort_order' => 0,
        ];
    }
}
