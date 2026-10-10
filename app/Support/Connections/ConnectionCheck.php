<?php

namespace App\Support\Connections;

use App\Models\Connection;
use App\Support\Chat\AiClient;
use App\Support\Chat\AiFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Whether a connection works, asked of the service itself in the cheapest
 * way it offers: the models a host lists, the bot Telegram says a token is,
 * the channel LINE says a token opens. A failure is an answer too, so it
 * comes back as ok: false rather than as an error. A token is never repeated
 * in what comes back, even when the service says it.
 */
class ConnectionCheck
{
    public function __construct(private AiClient $ai) {}

    /**
     * @return array{ok: bool, message: string, models?: list<string>, free?: list<string>|null}
     */
    public function run(Connection $connection): array
    {
        if ($connection->isAi()) {
            return $this->ai($connection);
        }

        return match ($connection->service) {
            Services::TELEGRAM => $this->telegram($connection),
            Services::LINE => $this->line($connection),
            default => ['ok' => true, 'message' => 'Nothing to try ahead: it is checked when it is used.'],
        };
    }

    /**
     * @return array{ok: bool, message: string, models?: list<string>, free?: list<string>|null}
     */
    private function ai(Connection $connection): array
    {
        if (($connection->setting('base_url') ?? '') === '') {
            return ['ok' => false, 'message' => 'Give the address of the host.'];
        }

        try {
            $catalog = $this->ai->catalog($connection);
        } catch (AiFailed $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $count = count($catalog['models']);

        return ['ok' => true, 'message' => "Works: {$count} ".($count === 1 ? 'model' : 'models').' available.', ...$catalog];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function telegram(Connection $connection): array
    {
        $token = $connection->secret('bot_token') ?? '';

        try {
            $response = Http::withoutRedirecting()->timeout(10)->acceptJson()
                ->get("https://api.telegram.org/bot{$token}/getMe");
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach Telegram.'];
        }

        if ($response->successful() && $response->json('ok') === true) {
            $name = $response->json('result.username');

            return ['ok' => true, 'message' => is_string($name) ? "Works: this is @{$name}." : 'Works.'];
        }

        return ['ok' => false, 'message' => in_array($response->status(), [401, 404], true)
            ? 'Telegram does not know this token.'
            : "Telegram answered with {$response->status()}."];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function line(Connection $connection): array
    {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->acceptJson()
                ->withToken($connection->secret('channel_access_token') ?? '')
                ->get('https://api.line.me/v2/bot/info');
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'Could not reach LINE.'];
        }

        if ($response->successful()) {
            $name = $response->json('displayName');

            return ['ok' => true, 'message' => is_string($name) ? "Works: this is “{$name}”." : 'Works.'];
        }

        return ['ok' => false, 'message' => $response->status() === 401
            ? 'LINE refused the channel access token.'
            : "LINE answered with {$response->status()}."];
    }
}
