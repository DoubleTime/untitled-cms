<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\Download;
use App\Models\Revision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DownloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'revision_id' => Revision::factory(),
            // The morph alias, not the class name — Relation::enforceMorphMap()
            // means that is what a real Download row carries.
            'revisable_type' => 'ai_model',
            'revisable_id' => AiModel::factory(),
            'user_id' => User::factory(),
            'unysis_box_id' => null,
            'source' => Download::SOURCE_API,
            'ip' => fake()->ipv4(),
            'user_agent' => 'RPA-TOOL/1.0',
        ];
    }
}
