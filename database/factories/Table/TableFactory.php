<?php

namespace Database\Factories\Table;

use App\Models\Table\Table;
use App\Models\User;
use App\Support\Table\TableStorage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
    protected $model = Table::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
        ];
    }

    /**
     * A table is never without somewhere to keep its rows.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Table $table) => TableStorage::create($table));
    }
}
