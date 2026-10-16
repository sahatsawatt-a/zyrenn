<?php

namespace App\Support\Browser;

use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Browser\BrowserSuiteVersion;
use Illuminate\Support\Facades\Auth;

/**
 * Each version of a suite's checks, kept as they change: what a run tested,
 * and what can be gone back to. Edits in a row by one person, before any run
 * has used them, are one version -- a suite is saved a moment after each
 * keystroke -- and a restore is always one of its own.
 */
final class SuiteVersions
{
    /** How long edits in a row by one person count as one version. */
    private const FOLD_MINUTES = 10;

    /** Keeps `$suite`'s checks as they are now: the latest version, or a new one. */
    public static function keep(BrowserSuite $suite, bool $ownVersion = false): void
    {
        /** @var BrowserSuiteVersion|null $latest */
        $latest = $suite->versions()->orderByDesc('version')->first();
        $by = Auth::id();

        $fold = ! $ownVersion
            && $latest !== null
            && $latest->created_by === $by
            && $latest->updated_at?->gt(now()->subMinutes(self::FOLD_MINUTES))
            && ! BrowserRun::where('suite_id', $suite->id)->where('suite_version', $latest->version)->exists();

        if ($fold) {
            $latest->forceFill(['checks' => $suite->checks])->save();

            return;
        }

        $version = ($latest !== null ? $latest->version : 0) + 1;
        $suite->versions()->create(['version' => $version, 'checks' => $suite->checks, 'created_by' => $by]);

        // Its number on the suite, saved without counting as a change of it
        BrowserSuite::whereKey($suite->id)->update(['version' => $version]);
        $suite->setAttribute('version', $version);
        $suite->syncOriginalAttribute('version');
    }

    /** `$suite`'s checks put back as `$version` had them -- as a new version, nothing lost. */
    public static function restore(BrowserSuite $suite, BrowserSuiteVersion $version): BrowserSuite
    {
        $suite->restoring = true;
        $suite->take(['checks' => $version->checks])->save();
        $suite->restoring = false;

        return $suite;
    }
}
