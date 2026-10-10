<?php

namespace Database\Factories\Workflow;

use App\Models\User;
use App\Models\Workflow\WorkflowFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowFolder>
 */
class WorkflowFolderFactory extends Factory
{
    protected $model = WorkflowFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
        ];
    }
}
