<?php

namespace Database\Factories\Drive;

use App\Models\Drive\DriveFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DriveFile>
 */
class DriveFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->word().'.png',
            'path' => 'drive/test/'.Str::random(32).'.png',
            'mime' => 'image/png',
            'ext' => 'png',
            'size' => 1024,
            'kind' => 'image',
        ];
    }
}
