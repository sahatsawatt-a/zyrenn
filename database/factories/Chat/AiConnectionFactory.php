<?php

namespace Database\Factories\Chat;

use App\Models\Chat\AiConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConnection>
 */
class AiConnectionFactory extends Factory
{
    protected $model = AiConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Local Ollama',
            'kind' => AiConnection::OLLAMA,
            'base_url' => 'http://ollama.test:11434',
            'default_model' => 'qwen2.5:7b',
        ];
    }

    public function openrouter(): static
    {
        return $this->state([
            'name' => 'OpenRouter',
            'kind' => AiConnection::OPENROUTER,
            'base_url' => AiConnection::PRESETS[AiConnection::OPENROUTER]['url'],
            'api_key' => 'sk-or-secret',
        ]);
    }
}
