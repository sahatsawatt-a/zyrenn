<?php

namespace App\Support\Workflow\Inbound;

use Illuminate\Http\Request;

/**
 * What came in, in one shape whichever service sent it, so a workflow reads
 * trigger.text and trigger.chat.id the same way from Telegram and from LINE:
 *
 *     { service, type, id, chat: { id, kind, title }, sender: { id, name },
 *       text, files, reply: { token, to }, raw }
 *
 * A file comes as its id only; a step fetches it, never the hook.
 */
final class Envelope
{
    /**
     * A Telegram update, or null for a kind no trigger takes.
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    public static function telegram(array $update): ?array
    {
        $button = $update['callback_query'] ?? null;
        $message = $update['message'] ?? $update['edited_message'] ?? ($button['message'] ?? null);

        if (! is_array($message)) {
            return null;
        }

        $from = $button['from'] ?? $message['from'] ?? [];
        $chat = $message['chat'] ?? [];
        $files = array_values(array_filter([
            ...array_map(fn (array $size) => $size['file_id'] ?? null, array_slice($message['photo'] ?? [], -1)),
            $message['document']['file_id'] ?? null,
            $message['voice']['file_id'] ?? null,
            $message['video']['file_id'] ?? null,
        ]));

        return [
            'service' => 'telegram',
            'type' => $button !== null ? 'button' : (isset($update['edited_message']) ? 'edited' : 'message'),
            'id' => (string) ($update['update_id'] ?? ''),
            'chat' => [
                'id' => (string) ($chat['id'] ?? ''),
                'kind' => ($chat['type'] ?? 'private') === 'private' ? 'private' : 'group',
                'title' => $chat['title'] ?? null,
            ],
            'sender' => [
                'id' => (string) ($from['id'] ?? ''),
                'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? null),
                'username' => $from['username'] ?? null,
            ],
            'text' => (string) ($button['data'] ?? $message['text'] ?? $message['caption'] ?? ''),
            'files' => $files,
            'reply' => ['token' => null, 'to' => $message['message_id'] ?? null],
            'raw' => $update,
        ];
    }

    /**
     * One LINE webhook event.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function line(array $event): array
    {
        $source = $event['source'] ?? [];
        $message = $event['message'] ?? [];

        return [
            'service' => 'line',
            'type' => (string) ($event['type'] ?? 'message'),
            'id' => (string) ($event['webhookEventId'] ?? ''),
            'chat' => [
                'id' => (string) ($source['groupId'] ?? $source['roomId'] ?? $source['userId'] ?? ''),
                'kind' => ($source['type'] ?? 'user') === 'user' ? 'private' : 'group',
                'title' => null,
            ],
            'sender' => ['id' => (string) ($source['userId'] ?? ''), 'name' => null],
            'text' => (string) ($message['text'] ?? $event['postback']['data'] ?? ''),
            'files' => ($message['type'] ?? 'text') !== 'text' && isset($message['id']) ? [(string) $message['id']] : [],
            'reply' => ['token' => $event['replyToken'] ?? null, 'to' => $message['id'] ?? null],
            'raw' => $event,
        ];
    }

    /**
     * A request to a webhook: its body read as JSON or a form when it is
     * one, else as text; and its headers, but never cookies or credentials.
     *
     * @return array<string, mixed>
     */
    public static function webhook(Request $request): array
    {
        $raw = $request->getContent();
        $json = json_decode($raw, true);

        $headers = collect($request->headers->all())
            ->except(['cookie', 'authorization', 'proxy-authorization'])
            ->map(fn (array $values) => count($values) === 1 ? $values[0] : $values)
            ->all();

        return [
            'service' => 'webhook',
            'type' => 'request',
            'method' => $request->method(),
            'body' => is_array($json) ? $json : ($request->request->all() ?: $raw),
            'query' => $request->query->all(),
            'headers' => $headers,
        ];
    }
}
