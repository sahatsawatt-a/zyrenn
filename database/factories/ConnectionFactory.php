<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Project;
use App\Models\User;
use App\Support\Connections\Services;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    protected $model = Connection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Local Ollama',
            'service' => Services::OLLAMA,
            'settings' => ['base_url' => 'http://ollama.test:11434', 'default_model' => 'qwen2.5:7b'],
        ];
    }

    public function openrouter(): static
    {
        return $this->state([
            'name' => 'OpenRouter',
            'service' => Services::OPENROUTER,
            'settings' => ['base_url' => 'https://openrouter.ai/api/v1'],
            'secrets' => ['api_key' => 'sk-or-secret'],
        ]);
    }

    public function telegram(): static
    {
        return $this->state([
            'name' => 'Bot',
            'service' => Services::TELEGRAM,
            'settings' => ['receive' => 'poll'],
            'secrets' => ['bot_token' => '123456:telegram-secret'],
        ]);
    }

    /**
     * A SerpApi key.
     */
    public function serpapi(): static
    {
        return $this->state(['name' => 'Search', 'service' => Services::SERPAPI, 'settings' => [], 'secrets' => ['api_key' => 'serp-key']]);
    }

    /**
     * A Tavily key.
     */
    public function tavily(): static
    {
        return $this->state(['name' => 'Web', 'service' => Services::TAVILY, 'settings' => [], 'secrets' => ['api_key' => 'tvly-key']]);
    }

    /**
     * A project's, made by $by (a new member, unless given).
     */
    public function forProject(Project $project, ?User $by = null): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'project_id' => $project->id,
            'created_by' => $by?->id,
        ]);
    }
}
