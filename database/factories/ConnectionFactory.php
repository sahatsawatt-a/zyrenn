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
     * A LinkedIn member, signed in for another 30 days.
     */
    public function linkedin(): static
    {
        return $this->state([
            'name' => 'Ada on LinkedIn',
            'service' => Services::LINKEDIN,
            'settings' => ['client_id' => 'client-1', 'member' => 'abc123', 'member_name' => 'Ada Lovelace', 'expires_at' => now()->addDays(30)->toIso8601String()],
            'secrets' => ['client_secret' => 'linkedin-secret', 'access_token' => 'linkedin-token'],
        ]);
    }

    /**
     * A Facebook Page, signed in.
     */
    public function facebook(): static
    {
        return $this->state([
            'name' => 'Shop page',
            'service' => Services::FACEBOOK,
            'settings' => ['client_id' => 'app-1', 'page_id' => '1001', 'page_name' => 'Shop'],
            'secrets' => ['client_secret' => 'app-secret', 'page_token' => 'page-token'],
        ]);
    }

    /**
     * An Instagram professional account, signed in for another 30 days.
     */
    public function instagram(): static
    {
        return $this->state([
            'name' => 'Shop on Instagram',
            'service' => Services::INSTAGRAM,
            'settings' => ['client_id' => 'ig-app', 'member' => '1789', 'member_name' => 'shop', 'expires_at' => now()->addDays(30)->toIso8601String()],
            'secrets' => ['client_secret' => 'ig-secret', 'access_token' => 'ig-token'],
        ]);
    }

    /**
     * A Threads profile, signed in for another 30 days.
     */
    public function threads(): static
    {
        return $this->state([
            'name' => 'Shop on Threads',
            'service' => Services::THREADS,
            'settings' => ['client_id' => 'th-app', 'member' => '2456', 'member_name' => 'shop', 'expires_at' => now()->addDays(30)->toIso8601String()],
            'secrets' => ['client_secret' => 'th-secret', 'access_token' => 'th-token'],
        ]);
    }

    /**
     * A WhatsApp business number.
     */
    public function whatsapp(): static
    {
        return $this->state([
            'name' => 'Shop line',
            'service' => Services::WHATSAPP,
            'settings' => ['phone_number_id' => '106540352242922'],
            'secrets' => ['access_token' => 'wa-token', 'app_secret' => 'wa-app-secret'],
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
     * A Stripe test key, with a webhook signing secret for its trigger.
     */
    public function stripe(): static
    {
        return $this->state(['name' => 'Stripe', 'service' => Services::STRIPE, 'settings' => [], 'secrets' => ['secret_key' => 'sk_test_key', 'webhook_secret' => 'whsec_secret']]);
    }

    /**
     * A GitHub token, working on zyrenn/app unless a step names another, with
     * a webhook secret for its trigger.
     */
    public function github(): static
    {
        return $this->state(['name' => 'GitHub', 'service' => Services::GITHUB, 'settings' => ['repo' => 'zyrenn/app'], 'secrets' => ['token' => 'ghp-token', 'webhook_secret' => 'gh-secret']]);
    }

    /**
     * A PromptX account signed in, its account token running out in $days.
     */
    public function promptx(int $days = 30, string $refresh = 'refresh-1'): static
    {
        return $this->state(['name' => 'PromptX', 'service' => Services::PROMPTX, 'settings' => ['login' => 'ada@example.com'], 'secrets' => [
            'access_token' => self::jwt(['email' => 'ada@example.com', 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'exp' => now()->addDays($days)->getTimestamp()]),
            'refresh_token' => $refresh,
        ]]);
    }

    /**
     * A token that says what it is given of itself, unsigned.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function jwt(array $claims): string
    {
        $part = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $part(['alg' => 'none']).'.'.$part($claims).'.sig';
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
