<?php

namespace Database\Factories\Map;

use App\Models\Map\PlaceList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceList>
 */
class PlaceListFactory extends Factory
{
    protected $model = PlaceList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'color' => PlaceList::COLORS[0],
        ];
    }
}
