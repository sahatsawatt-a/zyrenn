<?php

namespace App\Support\Workflow\Steps;

use App\Support\RemoteDownload;
use App\Support\RemoteDownloadFailed;
use App\Support\Workflow\References;
use App\Support\Workflow\Template;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * HTTP request: calls an address on the public internet -- never this
 * server or its own network, the address checked and the connection held to
 * it, and redirects given out rather than followed. A key goes only to the
 * address it was saved for. A request that changes something carries an
 * Idempotency-Key the same on every try, for services that honour one.
 */
final class HttpRequest implements Step
{
    /** The most of an answer read. */
    private const MAX_BYTES = 1024 * 1024;

    public function run(StepContext $step): StepResult
    {
        $method = strtoupper((string) $step->setting('method'));
        $url = trim($step->text('url'));
        $query = self::fields($step, 'query');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        try {
            [$host, $port, $ip] = RemoteDownload::publicTarget($url);
        } catch (RemoteDownloadFailed $e) {
            throw new StepFailed($e->getMessage());
        }

        $headers = array_map(fn ($value) => Template::text($value), self::fields($step, 'headers'));

        if (filled($step->setting('auth'))) {
            $key = References::connection($step->owner(), (string) $step->setting('auth'), ['http_header'])
                ?? throw new StepFailed('Its key is gone, or is not this workflow\'s.');

            if (! $key->sendsTo($url)) {
                throw new StepFailed("The key \"{$key->name}\" is only sent to {$key->setting('base_url')}.");
            }

            $headers[(string) $key->setting('header_name')] = (string) $key->secret('header_value');
        }

        $changes = ! in_array($method, ['GET', 'HEAD'], true);

        if ($changes) {
            $headers['Idempotency-Key'] ??= $step->sendKey();
        }

        $http = Http::withoutRedirecting()
            ->withUserAgent('Zyrenn')
            ->withHeaders($headers)
            ->connectTimeout(10)
            ->timeout((int) ($step->setting('timeout') ?: 30))
            ->withOptions([
                'stream' => true,
                'curl' => str_contains($ip, ':') ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
            ]);

        $http = match ($step->setting('body_type')) {
            'json' => $http->withBody((string) json_encode($step->template('body'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json'),
            'form' => $http->asForm()->withBody(http_build_query((array) $step->template('body')), 'application/x-www-form-urlencoded'),
            'text' => $http->withBody($step->text('body'), 'text/plain'),
            default => $http,
        };

        $call = function () use ($http, $method, $url, $host, $changes) {
            try {
                return $http->send($method, $url);
            } catch (ConnectionException $e) {
                // Only asking: asking again is safe
                throw $changes ? StepFailed::unreached($e, $host) : new StepFailed("Could not reach {$host}.", retryable: true);
            }
        };

        $response = $changes ? $step->deliver('http', "{$method} {$host}", $headers['Idempotency-Key'], $call) : $call();
        $status = $response->status();
        $body = self::read($response->toPsrResponse()->getBody());

        if ($status === 429 || $status >= 500) {
            $after = $response->header('Retry-After');

            throw new StepFailed("{$host} answered {$status}.", retryable: true, retryAfter: is_numeric($after) ? (int) $after : null);
        }

        if ($status >= 400 && $step->setting('errors') !== 'continue') {
            throw new StepFailed("{$host} answered {$status}: ".mb_strimwidth(trim(strip_tags($body)), 0, 200, '…'));
        }

        $json = json_decode($body, true);

        return new StepResult([
            'status' => $status,
            'headers' => array_map(fn (array $values) => count($values) === 1 ? $values[0] : $values, $response->headers()),
            'body' => $json !== null || trim($body) === 'null' ? $json : $body,
        ]);
    }

    /**
     * Named values worked out, without the ones left nameless.
     *
     * @return array<string, mixed>
     */
    private static function fields(StepContext $step, string $name): array
    {
        $values = [];

        foreach ((array) $step->setting($name) as $field) {
            if ($field['name'] !== '') {
                $values[$field['name']] = $step->template("{$name}.{$field['name']}", $field['value']);
            }
        }

        return $values;
    }

    /**
     * The answer's body, up to the most read.
     */
    private static function read(StreamInterface $body): string
    {
        $read = '';

        while (! $body->eof()) {
            $read .= $body->read(64 * 1024);

            if (strlen($read) > self::MAX_BYTES) {
                throw new StepFailed('The answer is longer than '.(self::MAX_BYTES / 1024).' KB.');
            }
        }

        return $read;
    }
}
