<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;

/**
 * LINE's other calls, each a kind of step of its own (NodeTypes names them;
 * this runs them): the Messaging API call a kind stands for, what it is
 * sent, and what of LINE's answer the step gives out.
 *
 * Multicast and broadcast carry a message and go out as a push does, with a
 * key so LINE never delivers one twice (LineSend). The rest ask, or set
 * something to what it would be anyway, and can be made again.
 */
final class LineCall implements Step
{
    public function run(StepContext $step): StepResult
    {
        if (in_array($step->node['type'], ['line.multicast', 'line.broadcast'], true)) {
            return LineSend::message($step, substr($step->node['type'], 5));
        }

        $channel = LineApi::channel($step);

        return new StepResult(match ($step->node['type']) {
            'line.loading' => self::done($channel, 'POST', 'chat/loading/start', [
                'chatId' => LineApi::needs($step, 'chat', 'chat: a person\'s id, like {{ trigger.sender.id }}'),
                // In steps of five seconds, from 5 to 60
                'loadingSeconds' => max(5, min(60, (int) (ceil(((int) $step->setting('seconds') ?: 20) / 5) * 5))),
            ]),
            'line.read' => self::done($channel, 'POST', 'chat/markAsRead', [
                'markAsReadToken' => LineApi::needs($step, 'token', 'read token: a LINE message comes with one, {{ trigger.reply.read }}'),
            ]),
            'line.leave' => self::leave($step, $channel),
            'line.richmenu' => self::done($channel, 'POST', 'user/'.rawurlencode(self::user($step)).'/richmenu/'.rawurlencode(LineApi::needs($step, 'menu', 'rich menu: its id, as "LINE: rich menus" gives it'))),
            'line.richmenu_off' => self::done($channel, 'DELETE', 'user/'.rawurlencode(self::user($step)).'/richmenu'),

            'line.quota' => self::quota($channel),
            'line.consumption' => ['sent' => (int) (LineApi::call($channel, 'GET', 'message/quota/consumption')['totalUsage'] ?? 0)],
            'line.richmenus' => self::menus($channel),
            'line.group' => self::group($step, $channel),
            'line.group_count' => self::count($step, $channel),

            default => throw new StepFailed("This app doesn't know the LINE step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{done: true}
     */
    private static function done(Connection $channel, string $method, string $path, array $body = []): array
    {
        LineApi::call($channel, $method, $path, $body);

        return ['done' => true];
    }

    private static function user(StepContext $step): string
    {
        return LineApi::needs($step, 'user', 'person: a LINE user id, like {{ trigger.sender.id }}');
    }

    /**
     * A group (C…) or a multi-person chat (R…), as LINE tells them apart.
     *
     * @return array{0: string, 1: string}
     */
    private static function shared(StepContext $step): array
    {
        $chat = LineApi::needs($step, 'chat', 'group: its id, like {{ trigger.chat.id }}');

        return match (true) {
            str_starts_with($chat, 'C') => ['group', $chat],
            str_starts_with($chat, 'R') => ['room', $chat],
            default => throw new StepFailed("\"{$chat}\" is not a group (C…) or a multi-person chat (R…)."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function leave(StepContext $step, Connection $channel): array
    {
        [$kind, $chat] = self::shared($step);
        LineApi::call($channel, 'POST', "{$kind}/".rawurlencode($chat).'/leave');

        return ['done' => true, 'chat' => $chat];
    }

    /**
     * @return array<string, mixed>
     */
    private static function quota(Connection $channel): array
    {
        $quota = LineApi::call($channel, 'GET', 'message/quota');

        return [
            // No limit at all on some plans
            'limited' => ($quota['type'] ?? 'none') === 'limited',
            'limit' => isset($quota['value']) ? (int) $quota['value'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function menus(Connection $channel): array
    {
        $menus = array_map(fn (array $menu) => [
            'id' => $menu['richMenuId'] ?? null,
            'name' => $menu['name'] ?? null,
            'bar' => $menu['chatBarText'] ?? null,
        ], array_values(array_filter((array) (LineApi::call($channel, 'GET', 'richmenu/list')['richmenus'] ?? []), 'is_array')));

        return ['menus' => $menus, 'count' => count($menus)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function group(StepContext $step, Connection $channel): array
    {
        [$kind, $chat] = self::shared($step);

        if ($kind !== 'group') {
            throw new StepFailed('Only a group (C…) has a name and a picture; a multi-person chat has neither.');
        }

        $summary = LineApi::call($channel, 'GET', 'group/'.rawurlencode($chat).'/summary');

        return ['id' => $chat, 'name' => $summary['groupName'] ?? null, 'picture' => $summary['pictureUrl'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function count(StepContext $step, Connection $channel): array
    {
        [$kind, $chat] = self::shared($step);

        return ['chat' => $chat, 'count' => (int) (LineApi::call($channel, 'GET', "{$kind}/".rawurlencode($chat).'/members/count')['count'] ?? 0)];
    }
}
