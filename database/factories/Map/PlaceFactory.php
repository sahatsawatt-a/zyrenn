<?php

namespace Database\Factories\Map;

use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'list_id' => PlaceList::factory(),
            // The list's owner, as a place always has
            'user_id' => fn (array $place) => PlaceList::query()->find((int) $place['list_id'])?->user_id,
            'project_id' => fn (array $place) => PlaceList::query()->find((int) $place['list_id'])?->project_id,
            'name' => $this->faker->company(),
            'lat' => $this->faker->latitude(),
            'lng' => $this->faker->longitude(),
        ];
    }
}
