<?php

namespace App\Support\Chat;

use App\Models\Connection;
use App\Support\Connections\Services;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to a connection in the chat-completions dialect that Ollama and
 * OpenRouter both speak.
 *
 * The host is the user's to choose, a machine of their own included, so
 * private addresses are allowed; what is limited is what comes back. Only
 * the model's words, its names, and the host's own error message are read,
 * and redirects are never followed, so a host can't be used to read
 * something else on the network.
 */
class AiClient
{
    /**
     * What the connection offers: the names of its models, and which of them
     * cost nothing -- null when the host does not say what anything costs.
     *
     * @return array{models: list<string>, free: list<string>|null}
     *
     * @throws AiFailed
     */
    public function catalog(Connection $connection): array
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http->timeout(15)->get($this->root($connection).'/models'));

        $names = [];
        $free = [];
        $priced = false;

        foreach ((array) $response->json('data', []) as $model) {
            if (! is_array($model) || ! is_string($model['id'] ?? null) || $model['id'] === '') {
                continue;
            }

            $names[] = $model['id'];

            // OpenRouter and its kind give each model's price per token, as text
            if (is_array($model['pricing'] ?? null)) {
                $priced = true;

                if ($this->costsNothing($model['pricing'])) {
                    $free[] = $model['id'];
                }
            }
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        sort($free, SORT_NATURAL | SORT_FLAG_CASE);

        return ['models' => $names, 'free' => $priced ? $free : null];
    }

    /**
     * Whether a model's price is nothing for what goes in and what comes out.
     * A price that is not a number -- or is negative, as a router's is while
     * it has no price of its own -- is not "free".
     *
     * @param  array<mixed>  $pricing
     */
    private function costsNothing(array $pricing): bool
    {
        foreach (['prompt', 'completion'] as $direction) {
            $price = $pricing[$direction] ?? null;

            if (! is_numeric($price) || (float) $price !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ask for an answer to a conversation and hand it over as it arrives.
     *
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages  content in parts when pictures go with it
     * @return Generator<int, string>
     *
     * @throws AiFailed
     */
    public function stream(Connection $connection, string $model, array $messages): Generator
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http
            // A model that has to be loaded can sit quiet for a while before the first word
            ->withOptions(['stream' => true, 'read_timeout' => 180])
            ->timeout(0)
            ->post($this->root($connection).'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ]));

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                $text = $this->textOf($line);

                if ($text === null) {
                    continue;
                }

                if ($text === false) {
                    return;
                }

                yield $text;
            }
        }
    }

    /**
     * One answer, whole, to a conversation that offers tools: the words, or
     * the tools the model asks to use -- each by name, with its arguments
     * read as JSON -- as hosts in this dialect give them.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: string, calls: list<array{id: string, name: string, arguments: array<string, mixed>}>, message: array<string, mixed>}
     *
     * @throws AiFailed
     */
    public function complete(Connection $connection, string $model, array $messages, array $tools): array
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http
            ->timeout(240)
            ->post($this->root($connection).'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                ...$tools === [] ? [] : ['tools' => $tools],
                'stream' => false,
            ]));

        $message = (array) $response->json('choices.0.message', []);
        $calls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $at => $call) {
            $function = is_array($call) ? (array) ($call['function'] ?? []) : [];
            $arguments = $function['arguments'] ?? [];
            $arguments = is_string($arguments) ? (json_decode($arguments, true) ?? []) : (array) $arguments;

            if (is_string($function['name'] ?? null)) {
                $calls[] = ['id' => (string) ($call['id'] ?? "call_{$at}"), 'name' => $function['name'], 'arguments' => is_array($arguments) ? $arguments : []];
            }
        }

        return ['content' => trim((string) ($message['content'] ?? '')), 'calls' => $calls, 'message' => $message];
    }

    /**
     * Each text as a list of numbers its meaning is near others' by: what
     * the connection's embedding model makes of it, in the order given.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws AiFailed
     */
    public function embed(Connection $connection, string $model, array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->send($connection, fn (PendingRequest $http) => $http
            // A model that has to be loaded first takes a while
            ->timeout(120)
            ->post($this->root($connection).'/embeddings', ['model' => $model, 'input' => $texts]));

        $vectors = [];

        foreach ((array) $response->json('data', []) as $at => $item) {
            $vector = is_array($item) ? ($item['embedding'] ?? null) : null;

            if (! is_array($vector) || $vector === []) {
                throw new AiFailed("{$model} gave no embedding.");
            }

            $vectors[(int) ($item['index'] ?? $at)] = array_map('floatval', array_values($vector));
        }

        ksort($vectors);

        if (count($vectors) !== count($texts)) {
            throw new AiFailed("{$model} gave ".count($vectors).' embeddings for '.count($texts).' texts.');
        }

        return array_values($vectors);
    }

    /**
     * Where to call: the connection's address, with "localhost" read as the user means it.
     */
    private function root(Connection $connection): string
    {
        return HostAddress::reachable($connection->apiRoot());
    }

    /**
     * What one line of the event stream says: words, nothing, or false at the end.
     *
     * @throws AiFailed
     */
    private function textOf(string $line): string|false|null
    {
        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, 5));

        if ($data === '[DONE]') {
            return false;
        }

        $event = json_decode($data, true);

        if (! is_array($event)) {
            return null;
        }

        // A host can fail partway through an answer it has started
        if (isset($event['error'])) {
            throw new AiFailed($this->reason($event['error']['message'] ?? null, 'The model stopped answering.'));
        }

        $text = $event['choices'][0]['delta']['content'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws AiFailed
     */
    private function send(Connection $connection, callable $call): Response
    {
        $http = Http::withoutRedirecting()
            ->connectTimeout(10)
            ->acceptJson()
            ->withUserAgent('Zyrenn');

        if ($connection->secret('api_key')) {
            $http = $http->withToken($connection->secret('api_key'));
        }

        if ($connection->service === Services::OPENROUTER) {
            $http = $http->withHeaders(['X-Title' => 'Zyrenn']);
        }

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new AiFailed('Could not reach '.(parse_url($connection->setting('base_url') ?? '', PHP_URL_HOST) ?: 'the host').'.');
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        throw new AiFailed(match (true) {
            in_array($status, [401, 403], true) => 'The host refused the key.',
            $status === 404 => $this->reason($response->json('error.message'), 'The host does not have that model, or does not answer here.'),
            $response->redirect() => 'The host sent us somewhere else.',
            default => $this->reason($response->json('error.message'), "The host answered with {$status}."),
        });
    }

    /**
     * The host's own words for what went wrong, if it gave any in the usual place.
     */
    private function reason(mixed $message, string $fallback): string
    {
        return is_string($message) && $message !== '' ? mb_strimwidth($message, 0, 300, '…') : $fallback;
    }
}
