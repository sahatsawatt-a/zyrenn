<?php

namespace Database\Factories\Chat;

use App\Models\Chat\ChatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatRoom>
 */
class ChatRoomFactory extends Factory
{
    protected $model = ChatRoom::class;

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
}
