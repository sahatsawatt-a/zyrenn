<?php

namespace App\Support\Browser;

use App\Models\Browser\BrowserSuite;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Pages kept open in the browser while a check is built on them, step by
 * step -- from the Build by clicking dialog, or by an AI client over MCP.
 * Each belongs to whoever opened it, for one suite (and, over MCP, perhaps
 * one of its checks, which a step that works can be added to). A person has
 * one at a time: opening another closes the one they left open.
 *
 * @phpstan-type Known array{user: int, suite: int, check: string|null}
 */
final class LivePages
{
    private const KEPT_MINUTES = 10;

    /**
     * Opens a page and does `steps` on it, which start with opening one.
     * `$check` names the check (by id) that steps done on it may be added to.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed> its id, what the steps did, and what is on the page
     *
     * @throws ValidationException when the steps cannot lead anywhere
     * @throws RuntimeException when the browser cannot open it
     */
    public static function open(User $by, BrowserSuite $suite, array $steps, string $size = 'desktop', ?string $check = null, string $title = ''): array
    {
        if ($steps === [] || $steps[0]['do'] !== 'open') {
            throw ValidationException::withMessages(['steps' => 'Start with "Open a page", so there is a page to build on.']);
        }

        if (($missing = BrowserCheck::unready(['title' => $title, 'steps' => $steps])) !== null) {
            throw ValidationException::withMessages(['steps' => "To get to the page -- {$missing}."]);
        }

        $theirs = self::theirs($by);

        if (is_string($before = Cache::pull($theirs))) {
            BrowserCheck::closeLive($before);
            Cache::forget(self::key($before));
        }

        $page = BrowserCheck::openLive($steps, $suite->valuesFor($steps), $size);
        self::remember($page['id'], ['user' => $by->id, 'suite' => $suite->id, 'check' => $check]);
        Cache::put($theirs, $page['id'], now()->addMinutes(self::KEPT_MINUTES));

        return $page;
    }

    /**
     * One more step on a page of theirs: what came of it, and the page now.
     * With `$keep`, a step that worked is added to the page's check.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     *
     * @throws ValidationException when the page is not theirs, or gone
     */
    public static function step(User $by, string $id, array $step, bool $keep = false): array
    {
        [$known, $suite] = self::theirsCalled($by, $id);
        $page = BrowserCheck::stepLive($id, $step, $suite->valuesFor([$step]));
        self::remember($id, $known);

        if ($keep && $page['step']['ok'] && $known['check'] !== null) {
            $suite->take(['checks' => array_map(
                fn (array $check) => $check['id'] === $known['check']
                    ? [...$check, 'steps' => [...$check['steps'], BrowserCheck::only($step)]]
                    : $check,
                $suite->checks,
            )])->save();
            $page['kept'] = true;
        }

        return $page;
    }

    public static function close(User $by, string $id): void
    {
        self::theirsCalled($by, $id);
        BrowserCheck::closeLive($id);
        Cache::forget(self::key($id));
        Cache::forget(self::theirs($by));
    }

    /** The suite a page of theirs is built for. */
    public static function suiteOf(User $by, string $id): BrowserSuite
    {
        return self::theirsCalled($by, $id)[1];
    }

    /**
     * @return array{Known, BrowserSuite}
     */
    private static function theirsCalled(User $by, string $id): array
    {
        /** @var Known|null $known */
        $known = preg_match('/^[0-9a-f-]{36}$/', $id) ? Cache::get(self::key($id)) : null;

        if ($known === null || $known['user'] !== $by->id) {
            throw ValidationException::withMessages(['page' => 'That page has been closed: open it again.']);
        }

        return [$known, BrowserSuite::query()->whereKey($known['suite'])->firstOrFail()];
    }

    /** @param  Known  $known */
    private static function remember(string $id, array $known): void
    {
        Cache::put(self::key($id), $known, now()->addMinutes(self::KEPT_MINUTES));
    }

    private static function key(string $id): string
    {
        return "browser-live:{$id}";
    }

    private static function theirs(User $by): string
    {
        return "browser-live-of:{$by->id}";
    }
}
