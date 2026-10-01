<?php

namespace Database\Factories\Chat;

use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_room_id' => ChatRoom::factory(),
            'role' => ChatMessage::USER,
            'content' => $this->faker->sentence(),
        ];
    }
}
