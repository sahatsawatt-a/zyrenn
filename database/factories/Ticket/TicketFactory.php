<?php

namespace Database\Factories\Ticket;

use App\Models\Project;
use App\Models\Ticket\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // Counted up by Ticket::file(); here, just one nobody else has
            'number' => fake()->unique()->numberBetween(1, 1_000_000),
            'type' => Ticket::BUG,
            'title' => fake()->sentence(4),
            'unit' => 'Board',
            'body' => fake()->sentence(),
        ];
    }
}
