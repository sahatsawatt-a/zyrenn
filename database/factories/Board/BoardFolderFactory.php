<?php

namespace Database\Factories\Board;

use App\Models\Board\BoardFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardFolder>
 */
class BoardFolderFactory extends Factory
{
    protected $model = BoardFolder::class;

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
