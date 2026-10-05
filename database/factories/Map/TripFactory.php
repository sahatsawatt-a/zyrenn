<?php

namespace Database\Factories\Map;

use App\Models\Map\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    protected $model = Trip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->city(),
            'content' => ['startDate' => '2026-11-13', 'days' => [['id' => 'd1', 'stops' => []]]],
        ];
    }
}
