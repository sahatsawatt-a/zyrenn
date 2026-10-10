<?php

namespace App\Support\Workflow;

/**
 * Workflows to start from, already joined up: each needs only the
 * connections it names. Chosen from the workflow list; what is made is an
 * ordinary draft, to change at will, published like any other.
 *
 * @phpstan-type Starter array{title: string, description: string, needs: array<string, string>, definition: array<string, mixed>}
 */
final class Templates
{
    /**
     * Every template by its key, its connections filled in from $chosen
     * (service => connection ref_id) when given.
     *
     * @param  array<string, string>  $chosen
     * @return array<string, Starter>
     */
    public static function all(array $chosen = []): array
    {
        $telegram = $chosen['telegram'] ?? '';
        $line = $chosen['line'] ?? '';
        $ai = $chosen['ai'] ?? '';

        return [
            'telegram-room' => [
                'title' => 'Telegram chats in a room, both ways',
                'description' => 'Each Telegram chat with your bot gets a chat of its own here: what is sent to the bot is written in it, and what you write in it goes back to Telegram.',
                'needs' => ['telegram' => 'Bot'],
                'definition' => self::chains([
                    [
                        ['heard', 'trigger.telegram', ['connection' => $telegram]],
                        ['room', 'chat.room', ['key' => 'telegram:{{ trigger.chat.id }}', 'title' => '{{ coalesce(trigger.chat.title, trigger.sender.name, "Someone") }} on Telegram', 'link' => $telegram, 'link_chat' => '{{ trigger.chat.id }}']],
                        ['say', 'chat.send', ['room' => '{{ node("room").room }}', 'message' => '{{ trigger.text }}', 'sender' => '{{ trigger.sender.name }} (Telegram)']],
                    ],
                    [
                        ['written', 'trigger.chat', ['room' => References::LINKED.':telegram']],
                        ['back', 'telegram.send', ['connection' => References::LINKED, 'chat' => '{{ trigger.room.link.chat }}', 'text' => '{{ trigger.text }}']],
                    ],
                ]),
            ],
            'line-room' => [
                'title' => 'LINE chats in a room, both ways',
                'description' => 'Each LINE chat with your channel gets a chat of its own here: what is sent to it is written in it, and what you write in it goes back to LINE (counted against the channel\'s monthly allowance).',
                'needs' => ['line' => 'Channel'],
                'definition' => self::chains([
                    [
                        ['heard', 'trigger.line', ['connection' => $line]],
                        // Who wrote: when LINE won't say, the message is still written, from "Someone"
                        ['who', 'line.profile', ['connection' => $line, 'user' => '{{ trigger.sender.id }}', 'chat' => '{{ trigger.chat.id }}'], 'continue'],
                        ['room', 'chat.room', ['key' => 'line:{{ trigger.chat.id }}', 'title' => '{{ coalesce(node("who").group, node("who").name, "Someone") }} on LINE', 'link' => $line, 'link_chat' => '{{ trigger.chat.id }}']],
                        ['say', 'chat.send', ['room' => '{{ node("room").room }}', 'message' => '{{ trigger.text }}', 'sender' => '{{ coalesce(node("who").name, "Someone") }} (LINE)']],
                    ],
                    [
                        ['written', 'trigger.chat', ['room' => References::LINKED.':line']],
                        ['back', 'line.send', ['connection' => References::LINKED, 'mode' => 'push', 'to' => '{{ trigger.room.link.chat }}', 'text' => '{{ trigger.text }}']],
                    ],
                ]),
            ],
            'telegram-ai' => [
                'title' => 'AI answers on Telegram',
                'description' => 'Whatever is sent to your bot is put to an AI model, and its answer sent back.',
                'needs' => ['telegram' => 'Bot', 'ai' => 'AI connection'],
                'definition' => self::chain([
                    ['heard', 'trigger.telegram', ['connection' => $telegram]],
                    ['ask', 'ai.chat', ['connection' => $ai, 'system' => 'You answer messages sent to a Telegram bot. Be brief and friendly.', 'prompt' => '{{ trigger.text }}']],
                    ['answer', 'telegram.send', ['connection' => $telegram, 'chat' => '{{ trigger.chat.id }}', 'text' => '{{ node("ask").text }}']],
                ]),
            ],
            'line-ai' => [
                'title' => 'AI answers on LINE',
                'description' => 'Whatever is sent to your LINE channel is put to an AI model, and its answer sent back.',
                'needs' => ['line' => 'Channel', 'ai' => 'AI connection'],
                'definition' => self::chain([
                    ['heard', 'trigger.line', ['connection' => $line]],
                    ['ask', 'ai.chat', ['connection' => $ai, 'system' => 'You answer messages sent to a LINE account. Be brief and friendly.', 'prompt' => '{{ trigger.text }}']],
                    ['answer', 'line.send', ['connection' => $line, 'mode' => 'reply', 'text' => '{{ node("ask").text }}']],
                ]),
            ],
            'daily-telegram' => [
                'title' => 'Daily summary to Telegram',
                'description' => 'Every weekday morning, a table\'s rows are summed up by an AI model and sent to a Telegram chat. Choose the table and the chat after.',
                'needs' => ['telegram' => 'Bot', 'ai' => 'AI connection'],
                'definition' => self::chain([
                    ['morning', 'trigger.schedule', ['cron' => '0 8 * * 1-5']],
                    ['rows', 'table.find', ['limit' => 100]],
                    ['ask', 'ai.chat', ['connection' => $ai, 'system' => 'Sum up these rows for a busy reader in a few lines.', 'prompt' => '{{ node("rows").rows }}']],
                    ['send', 'telegram.send', ['connection' => $telegram, 'chat' => '', 'text' => '{{ node("ask").text }}']],
                ]),
            ],
        ];
    }

    /**
     * Steps one after another, left to right.
     *
     * @param  list<array{string, string, array<string, mixed>}>  $steps
     * @return array<string, mixed>
     */
    private static function chain(array $steps): array
    {
        return self::chains([$steps]);
    }

    /**
     * Several rows of steps, one under another, each one after another. A
     * step's fourth part, when given, is what it does when it fails.
     *
     * @param  list<list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string}>>  $rows
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function chains(array $rows): array
    {
        $nodes = [];
        $edges = [];

        foreach ($rows as $row => $steps) {
            foreach ($steps as $at => $step) {
                [$key, $type, $config] = $step;
                $nodes[] = [
                    'id' => $key, 'key' => $key, 'type' => $type,
                    'position' => ['x' => $at * 320, 'y' => $row * 220],
                    'config' => array_filter($config, fn ($value) => $value !== ''),
                    ...isset($step[3]) ? ['onError' => $step[3]] : [],
                ];

                if ($at > 0) {
                    $edges[] = ['id' => "{$steps[$at - 1][0]}.out.{$key}", 'source' => $steps[$at - 1][0], 'sourceHandle' => 'out', 'target' => $key];
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
