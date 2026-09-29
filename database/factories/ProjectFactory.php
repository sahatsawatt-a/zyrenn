<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
        ];
    }

    /**
     * A project with the user in it, in that role.
     */
    public function withMember(User $user, string $role = Project::OWNER): static
    {
        return $this->hasAttached($user, ['role' => $role], 'members');
    }
}
