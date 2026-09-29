<?php

namespace Database\Factories\Table;

use App\Models\Table\TableFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TableFolder>
 */
class TableFolderFactory extends Factory
{
    protected $model = TableFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
        ];
    }
}
