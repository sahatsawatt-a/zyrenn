<?php

namespace App\Support\Workflow\Steps;

use App\Support\Connections\Services;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Send to Slack or to Discord: a message posted to a channel through an
 * incoming webhook, kept as a connection -- its address is its key, so it
 * stays secret and only goes to Slack's or Discord's own host. Slack takes
 * text and blocks; Discord content, a name and picture to post as, and
 * embeds, and answers with the message it made.
 */
final class WebhookSend implements Step
{
    public function run(StepContext $step): StepResult
    {
        $discord = str_starts_with((string) $step->node['type'], 'discord.');
        $service = $discord ? 'Discord' : 'Slack';
        $hook = References::connection($step->owner(), (string) $step->setting('connection'), [$discord ? Services::DISCORD : Services::SLACK])
            ?? throw new StepFailed("Its {$service} connection is gone, or is not this workflow's.");
        $url = (string) $hook->secret('url');

        if (! preg_match(Services::WEBHOOKS[$discord ? Services::DISCORD : Services::SLACK], $url)) {
            throw new StepFailed("\"{$hook->name}\" is not a {$service} webhook's address.");
        }

        $payload = $discord
            ? array_filter([
                'content' => $step->text('content'),
                'username' => $step->text('username'),
                'avatar_url' => $step->text('avatar'),
                'embeds' => self::list($step, 'embeds', 'Embeds'),
            ])
            : array_filter([
                'text' => $step->text('text'),
                'blocks' => self::list($step, 'blocks', 'Blocks'),
            ]);

        if (! isset($payload['text']) && ! isset($payload['content']) && ! isset($payload['blocks']) && ! isset($payload['embeds'])) {
            throw new StepFailed('Say what to send.');
        }

        /** @var Response $response */
        $response = $step->deliver(strtolower($service), $hook->name, null, function () use ($url, $payload, $discord, $service) {
            try {
                return Http::withoutRedirecting()->connectTimeout(10)->timeout(20)
                    ->post($discord ? "{$url}?wait=true" : $url, $payload);
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, $service);
            }
        });

        if ($response->status() === 429 || $response->serverError()) {
            $after = $response->json('retry_after') ?? $response->header('Retry-After');

            throw new StepFailed("{$service} answered {$response->status()}.", retryable: true, retryAfter: is_numeric($after) ? (int) ceil((float) $after) : null);
        }

        if (! $response->successful()) {
            $said = $discord ? (string) ($response->json('message') ?? $response->body()) : $response->body();

            throw new StepFailed("{$service} would not take it: ".mb_strimwidth(trim($said), 0, 200, '…'));
        }

        return new StepResult($discord
            ? ['id' => (string) $response->json('id'), 'channel_id' => (string) $response->json('channel_id'), 'sent_at' => $response->json('timestamp')]
            : ['sent' => true]);
    }

    /**
     * A setting that is a list of records -- Slack's blocks, Discord's
     * embeds -- given as a list or as JSON text; one record is a list of one.
     *
     * @return list<mixed>
     */
    private static function list(StepContext $step, string $name, string $label): array
    {
        $value = $step->template($name);

        if (is_string($value)) {
            if (trim($value) === '') {
                return [];
            }

            $value = json_decode($value, true);
        }

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new StepFailed("\"{$label}\" should be a JSON list.");
        }

        return array_is_list($value) ? $value : [$value];
    }
}
