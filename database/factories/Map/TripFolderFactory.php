<?php

namespace Database\Factories\Map;

use App\Models\Map\TripFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripFolder>
 */
class TripFolderFactory extends Factory
{
    protected $model = TripFolder::class;

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
