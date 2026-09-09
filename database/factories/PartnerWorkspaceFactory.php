<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PartnerWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PartnerWorkspace> */
class PartnerWorkspaceFactory extends Factory
{
    protected $model = PartnerWorkspace::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'partner_key' => 'ais',
            'external_project_id' => fake()->uuid(),
            'project_name' => fake()->company(),
            'locale' => 'en',
        ];
    }
}
