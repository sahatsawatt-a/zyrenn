<?php

namespace Database\Factories\Workflow;

use App\Models\Project;
use App\Models\User;
use App\Models\Workflow\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
        ];
    }

    /**
     * Its steps and connections, in the kept shape's terms.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function steps(array $nodes, array $edges = []): static
    {
        return $this->state(['definition' => ['nodes' => $nodes, 'edges' => $edges]]);
    }

    /**
     * A project's, made by $by.
     */
    public function forProject(Project $project, ?User $by = null): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'project_id' => $project->id,
            'created_by' => $by?->id,
        ]);
    }
}
