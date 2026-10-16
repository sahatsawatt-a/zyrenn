<?php

namespace App\Support\Browser;

use App\Models\AppSetting;
use App\Models\Browser\BrowserSuite;

/**
 * How long browser checks may wait, in seconds: how long each step waits for
 * the site ("step") and how long a whole check may take at full speed
 * ("check"; a slowed or filmed one is given its slowing on top). A suite sets
 * its own, up to the most an admin allows everyone -- itself never past the
 * ceilings here, which a run's own time limit keeps to.
 */
final class Limits
{
    /** What a suite waits that sets none. */
    public const DEFAULT = ['step' => 10, 'check' => 90];

    /** The most everyone may set, until an admin says otherwise. */
    public const MOST = ['step' => 60, 'check' => 600];

    /** What not even an admin may allow: a whole run is given 30 minutes. */
    public const CEILING = ['step' => 300, 'check' => 1800];

    /** The least an admin may make the most. */
    public const FLOOR = ['step' => 5, 'check' => 30];

    private const KEY = 'browser_checks.most';

    /**
     * The most anyone's checks may wait, as an admin set it.
     *
     * @return array{step: int, check: int}
     */
    public static function most(): array
    {
        $set = AppSetting::read(self::KEY, []);

        return [
            'step' => self::within((int) ($set['step'] ?? self::MOST['step']), 'step'),
            'check' => self::within((int) ($set['check'] ?? self::MOST['check']), 'check'),
        ];
    }

    /** @param  array{step: int, check: int}  $most */
    public static function setMost(array $most): void
    {
        AppSetting::write(self::KEY, [
            'step' => self::within($most['step'], 'step'),
            'check' => self::within($most['check'], 'check'),
        ]);
    }

    /**
     * What `$suite`'s checks wait: its own, or the default, never past the
     * most allowed now -- an admin lowering it lowers every suite's.
     *
     * @return array{step: int, check: int}
     */
    public static function of(?BrowserSuite $suite): array
    {
        $most = self::most();
        $own = $suite?->settings['limits'] ?? [];

        return [
            'step' => min((int) ($own['step'] ?? self::DEFAULT['step']), $most['step']),
            'check' => min((int) ($own['check'] ?? self::DEFAULT['check']), $most['check']),
        ];
    }

    private static function within(int $seconds, string $kind): int
    {
        return max(self::FLOOR[$kind], min(self::CEILING[$kind], $seconds));
    }
}
