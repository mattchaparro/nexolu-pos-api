<?php

namespace Database\Factories;

use App\Models\ServiceWorkflow;
use App\Models\ServiceWorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceWorkflowStage>
 */
class ServiceWorkflowStageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => ServiceWorkflow::factory(),
            'label' => fake()->word(),
            'color' => '#6b7280',
            'sort_order' => 0,
            'is_initial' => false,
        ];
    }
}
