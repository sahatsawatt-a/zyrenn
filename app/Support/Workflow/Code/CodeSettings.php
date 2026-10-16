<?php

namespace App\Support\Workflow\Code;

use App\Models\AppSetting;
use App\Models\Workflow\CodeLibrary;

/**
 * What the app's admins allow a workflow's "Run code" step: whether Python
 * may be written at all, the most seconds a step of each language may run,
 * and how much memory Python is given. JavaScript is always there; its
 * memory is the code runner's own, fixed.
 */
final class CodeSettings
{
    /** The most seconds a step may say, until an admin says otherwise. */
    public const MOST_SECONDS = 30;

    /** What not even an admin may allow: a step is stopped at 150 (RunWorkflowStep); Python, with its loading, at 60. */
    public const CEILING_SECONDS = ['javascript' => 120, 'python' => 60];

    public const PYTHON_MEMORY = 512;

    /** What Python may be given, in MB: its container has 1 GB. */
    public const PYTHON_MEMORY_RANGE = [128, 768];

    private const KEY = 'code.settings';

    /**
     * @return array{python: bool, seconds: array{javascript: int, python: int}, python_memory: int}
     */
    public static function get(): array
    {
        $set = (array) AppSetting::read(self::KEY, []);
        $seconds = (array) ($set['seconds'] ?? []);

        return [
            'python' => (bool) ($set['python'] ?? false),
            'seconds' => [
                CodeLibrary::JAVASCRIPT => self::seconds($seconds[CodeLibrary::JAVASCRIPT] ?? self::MOST_SECONDS, CodeLibrary::JAVASCRIPT),
                CodeLibrary::PYTHON => self::seconds($seconds[CodeLibrary::PYTHON] ?? self::MOST_SECONDS, CodeLibrary::PYTHON),
            ],
            'python_memory' => max(self::PYTHON_MEMORY_RANGE[0], min(self::PYTHON_MEMORY_RANGE[1], (int) ($set['python_memory'] ?? self::PYTHON_MEMORY))),
        ];
    }

    /**
     * @param  array{python: bool, seconds: array{javascript: int, python: int}, python_memory: int}  $settings
     */
    public static function set(array $settings): void
    {
        AppSetting::write(self::KEY, $settings);
    }

    public static function pythonOn(): bool
    {
        return self::get()['python'];
    }

    /** The most seconds a step in `$language` may run. */
    public static function mostSeconds(string $language): int
    {
        return self::get()['seconds'][$language] ?? self::MOST_SECONDS;
    }

    private static function seconds(mixed $seconds, string $language): int
    {
        return max(1, min(self::CEILING_SECONDS[$language], (int) $seconds));
    }
}
