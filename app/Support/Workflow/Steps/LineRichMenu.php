<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;

/**
 * LINE's rich menus -- the panel under a chat, a picture with places to
 * tap -- each call a kind of step of its own (ServiceKinds names them; this
 * runs them): making one and deleting it, showing one to everyone or to
 * some, and the short names by which one menu turns to another, as tabs do.
 *
 * A menu and its picture are made together, the menu the picture's own
 * size: LINE shows no menu without a picture, takes none of another size,
 * and lets neither be changed after -- a menu that is to look different is
 * made again.
 *
 * Wherever a menu is named, its id (richmenu-…) or a short name given to it
 * will do.
 */
final class LineRichMenu implements Step
{
    private const MAX_PICTURE_BYTES = 1024 * 1024;

    /** How many people one call links a menu to, or takes one from. */
    private const MOST_AT_ONCE = 500;

    /**
     * Where a layout's buttons are, in the order they are named -- left to
     * right, top to bottom -- each as [x, y, width, height] on a picture
     * six across and two down.
     *
     * @var array<string, list<array{int, int, int, int}>>
     */
    public const LAYOUTS = [
        'six' => [[0, 0, 2, 1], [2, 0, 2, 1], [4, 0, 2, 1], [0, 1, 2, 1], [2, 1, 2, 1], [4, 1, 2, 1]],
        'four' => [[0, 0, 3, 1], [3, 0, 3, 1], [0, 1, 3, 1], [3, 1, 3, 1]],
        'top' => [[0, 0, 6, 1], [0, 1, 2, 1], [2, 1, 2, 1], [4, 1, 2, 1]],
        'large' => [[0, 0, 4, 2], [4, 0, 2, 1], [4, 1, 2, 1]],
        'rows' => [[0, 0, 6, 1], [0, 1, 6, 1]],
        'three' => [[0, 0, 2, 2], [2, 0, 2, 2], [4, 0, 2, 2]],
        'two' => [[0, 0, 3, 2], [3, 0, 3, 2]],
        'narrow' => [[0, 0, 2, 2], [2, 0, 4, 2]],
        'wide' => [[0, 0, 4, 2], [4, 0, 2, 2]],
        'one' => [[0, 0, 6, 2]],
    ];

    public function run(StepContext $step): StepResult
    {
        $channel = LineApi::channel($step);

        return new StepResult(match ($step->node['type']) {
            'line.richmenu_make' => self::make($step, $channel),
            'line.richmenu_delete' => self::done($channel, 'DELETE', 'richmenu/'.self::menu($step, $channel)),
            'line.richmenu_default' => self::done($channel, 'POST', 'user/all/richmenu/'.self::menu($step, $channel)),
            'line.richmenu_default_off' => self::done($channel, 'DELETE', 'user/all/richmenu'),
            'line.richmenu' => self::done($channel, 'POST', 'user/'.self::user($step).'/richmenu/'.self::menu($step, $channel)),
            'line.richmenu_off' => self::done($channel, 'DELETE', 'user/'.self::user($step).'/richmenu'),
            'line.richmenu_many' => self::many($step, $channel, 'link', ['richMenuId' => self::menu($step, $channel)]),
            'line.richmenu_many_off' => self::many($step, $channel, 'unlink'),
            'line.richmenu_of' => ['menu' => LineApi::find($channel, 'user/'.self::user($step).'/richmenu')['richMenuId'] ?? null],
            'line.richmenu_alias' => self::name($step, $channel),
            'line.richmenu_alias_off' => self::done($channel, 'DELETE', 'richmenu/alias/'.rawurlencode(self::alias($step))),
            'line.richmenus' => self::menus($channel),

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
        return rawurlencode(LineApi::needs($step, 'user', 'person: a LINE user id, like {{ trigger.sender.id }}'));
    }

    /**
     * The menu a step names, as its id: given as one, or as a short name
     * LINE is asked about.
     *
     * @throws StepFailed
     */
    private static function menu(StepContext $step, Connection $channel): string
    {
        $named = LineApi::needs($step, 'menu', 'rich menu: its id or its short name, as "LINE: rich menus" gives them');

        if (preg_match('/^richmenu-[0-9a-f]{32}$/', $named) === 1) {
            return $named;
        }

        $id = LineApi::find($channel, 'richmenu/alias/'.rawurlencode($named), [400, 404])['richMenuId'] ?? null;

        return match (true) {
            is_string($id) && $id !== '' => $id,
            // An id written some other way than LINE writes them today: LINE says if it is none
            preg_match('/^richmenu-[\w-]+$/', $named) === 1 => $named,
            default => throw new StepFailed("The channel has no rich menu with the id or the short name \"{$named}\"."),
        };
    }

    /**
     * A short name, as LINE takes one.
     *
     * @throws StepFailed
     */
    private static function alias(StepContext $step): string
    {
        $alias = LineApi::needs($step, 'alias', 'short name');

        return preg_match('/^[a-z0-9_-]{1,32}$/', $alias) === 1
            ? $alias
            : throw new StepFailed("A short name is up to 32 of a-z, 0-9, \"-\" and \"_\", and \"{$alias}\" is not.");
    }

    /**
     * A menu made and its picture put on it. Should the picture not go on,
     * the menu is taken away again: one without a picture can't be shown,
     * and would be left behind each time the step is tried.
     *
     * @return array<string, mixed>
     */
    private static function make(StepContext $step, Connection $channel): array
    {
        $name = mb_substr(LineApi::needs($step, 'name', 'name for the menu'), 0, 300);
        $bar = trim($step->text('bar')) ?: 'Menu';

        if (mb_strlen($bar) > 14) {
            throw new StepFailed("LINE shows at most 14 letters on the bar, and \"{$bar}\" is ".mb_strlen($bar).'.');
        }

        $picture = DriveSteps::file($step, 'picture');
        [$width, $height] = self::fits($picture->name, $picture->mime, $picture->size, DriveSteps::path($picture));
        $areas = self::areas($step, $width, $height);

        $id = (string) (LineApi::call($channel, 'POST', 'richmenu', [
            'size' => ['width' => $width, 'height' => $height],
            'selected' => $step->setting('open') !== 'no',
            'name' => $name,
            'chatBarText' => $bar,
            'areas' => $areas,
        ])['richMenuId'] ?? '');

        if ($id === '') {
            throw new StepFailed('LINE made no menu, and did not say why.');
        }

        try {
            LineApi::upload($channel, "richmenu/{$id}/content", (string) file_get_contents(DriveSteps::path($picture)), (string) $picture->mime);
        } catch (StepFailed $failed) {
            try {
                LineApi::call($channel, 'DELETE', "richmenu/{$id}");
            } catch (StepFailed) {
                // Left behind, then: "LINE: rich menus" lists it, without a picture
            }

            throw $failed;
        }

        return ['id' => $id, 'name' => $name, 'width' => $width, 'height' => $height, 'areas' => count($areas)];
    }

    /**
     * A picture LINE takes for a menu, as its width and height.
     *
     * @return array{int, int}
     *
     * @throws StepFailed
     */
    private static function fits(string $name, ?string $mime, int $size, string $path): array
    {
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new StepFailed("A rich menu's picture is a JPEG or a PNG, and \"{$name}\" is neither.");
        }

        if ($size > self::MAX_PICTURE_BYTES) {
            throw new StepFailed("A rich menu's picture is at most 1 MB, and \"{$name}\" is ".round($size / 1024 / 1024, 1).' MB.');
        }

        [$width, $height] = @getimagesize($path) ?: [0, 0];

        if ($width < 800 || $width > 2500 || $height < 250 || $width / $height < 1.45) {
            throw new StepFailed("A rich menu's picture is 800 to 2500 wide, at least 250 high, and at least 1.45 times as wide as it is high -- LINE's own are 2500 × 1686 and 2500 × 843 -- and \"{$name}\" is {$width} × {$height}.");
        }

        return [$width, $height];
    }

    /**
     * The places to tap: a layout's, one for each button named, or the
     * areas written out as LINE takes them.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StepFailed
     */
    private static function areas(StepContext $step, int $width, int $height): array
    {
        $layout = (string) ($step->setting('layout') ?: 'six');

        if ($layout === 'custom') {
            $given = $step->template('areas');
            $areas = is_array($given) ? $given : json_decode((string) $given, true);

            if (! is_array($areas) || ! array_is_list($areas) || $areas === [] || count($areas) > 20) {
                throw new StepFailed('Areas of one\'s own are a JSON list of up to 20, each with its "bounds" and its "action", as LINE takes them.');
            }

            return $areas;
        }

        $cells = self::LAYOUTS[$layout] ?? throw new StepFailed("This app doesn't know the layout \"{$layout}\".");
        $buttons = LineApi::actions($step, 'buttons', count($cells), menu: true);

        if ($buttons === []) {
            throw new StepFailed('Name at least one button: what it is, and what tapping it does.');
        }

        // Edges are rounded, not sizes, so that neighbours meet and the last ends at the picture's
        $across = fn (int $at) => (int) round($at * $width / 6);
        $down = fn (int $at) => (int) round($at * $height / 2);

        return array_map(fn (array $action, array $cell) => [
            'bounds' => [
                'x' => $across($cell[0]),
                'y' => $down($cell[1]),
                'width' => $across($cell[0] + $cell[2]) - $across($cell[0]),
                'height' => $down($cell[1] + $cell[3]) - $down($cell[1]),
            ],
            'action' => $action,
        ], $buttons, array_slice($cells, 0, count($buttons)));
    }

    /**
     * A menu given to several people, or taken from them. LINE does it
     * after answering, so the step is done before they see it.
     *
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    private static function many(StepContext $step, Connection $channel, string $how, array $with = []): array
    {
        $given = $step->template('to');
        $ids = is_array($given) ? $given : (preg_split('/[\s,]+/', (string) $given, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => is_scalar($id) ? trim((string) $id) : '', $ids), fn (string $id) => $id !== '')));

        if ($ids === [] || count($ids) > self::MOST_AT_ONCE) {
            throw new StepFailed($ids === [] ? 'There is no one named.' : 'LINE takes at most '.self::MOST_AT_ONCE.' people at once, and there are '.count($ids).'.');
        }

        LineApi::call($channel, 'POST', "richmenu/bulk/{$how}", [...$with, 'userIds' => $ids]);

        return ['done' => true, 'count' => count($ids)];
    }

    /**
     * A short name pointed at a menu: made, or moved when a menu already
     * goes by it -- which is how every tab that names it turns to a new menu.
     *
     * @return array<string, mixed>
     */
    private static function name(StepContext $step, Connection $channel): array
    {
        $alias = self::alias($step);
        $menu = self::menu($step, $channel);
        $had = LineApi::find($channel, 'richmenu/alias/'.rawurlencode($alias), [400, 404])['richMenuId'] ?? null;

        if ($had !== $menu) {
            LineApi::call($channel, 'POST', 'richmenu/alias'.($had === null ? '' : '/'.rawurlencode($alias)), [
                ...$had === null ? ['richMenuAliasId' => $alias] : [],
                'richMenuId' => $menu,
            ]);
        }

        return ['done' => true, 'alias' => $alias, 'menu' => $menu, 'moved' => $had !== null && $had !== $menu];
    }

    /**
     * The channel's menus made through this API -- those drawn in LINE
     * Official Account Manager are not told of -- each with the short names
     * it goes by, and whether everyone is shown it.
     *
     * @return array<string, mixed>
     */
    private static function menus(Connection $channel): array
    {
        $named = [];

        foreach ((array) (LineApi::call($channel, 'GET', 'richmenu/alias/list')['aliases'] ?? []) as $alias) {
            if (is_array($alias) && isset($alias['richMenuId'], $alias['richMenuAliasId'])) {
                $named[(string) $alias['richMenuId']][] = (string) $alias['richMenuAliasId'];
            }
        }

        // 403: everyone's menu was set in LINE Official Account Manager, which this can't see
        $default = LineApi::find($channel, 'user/all/richmenu', [403, 404])['richMenuId'] ?? null;

        $menus = array_map(fn (array $menu) => [
            'id' => $menu['richMenuId'] ?? null,
            'name' => $menu['name'] ?? null,
            'bar' => $menu['chatBarText'] ?? null,
            'width' => $menu['size']['width'] ?? null,
            'height' => $menu['size']['height'] ?? null,
            'areas' => count((array) ($menu['areas'] ?? [])),
            'aliases' => $named[(string) ($menu['richMenuId'] ?? '')] ?? [],
            'default' => isset($menu['richMenuId']) && $menu['richMenuId'] === $default,
        ], array_values(array_filter((array) (LineApi::call($channel, 'GET', 'richmenu/list')['richmenus'] ?? []), 'is_array')));

        return ['menus' => $menus, 'count' => count($menus), 'default' => $default];
    }
}
