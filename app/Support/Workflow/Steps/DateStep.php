<?php

namespace App\Support\Workflow\Steps;

use Carbon\CarbonImmutable;

/**
 * A date: read from text -- as it comes, by a pattern, or as seconds since
 * 1970 -- moved on or back ("+3 days"), shown in a time zone and written in
 * a format and a language.
 */
final class DateStep implements Step
{
    /** The formats on offer, as PHP's date() writes them. */
    public const FORMATS = [
        'iso' => 'Y-m-d\TH:i:sP',
        'date' => 'Y-m-d',
        'datetime' => 'Y-m-d H:i',
        'long' => 'j F Y',
        'long_time' => 'j F Y, H:i',
        'short' => 'd/m/Y',
        'us' => 'm/d/Y',
        'weekday' => 'l',
        'time' => 'H:i',
    ];

    public function run(StepContext $step): StepResult
    {
        $value = trim($step->text('value'));
        $from = $step->setting('from') ?: 'auto';
        $zoneIn = (string) ($step->setting('zone_in') ?: config('app.timezone'));
        $zone = (string) ($step->setting('zone') ?: $zoneIn);
        $language = trim($step->text('language')) ?: 'en';

        try {
            $date = match (true) {
                $value === '' => CarbonImmutable::now($zoneIn),
                $from === 'unix' => CarbonImmutable::createFromTimestamp((float) $value, $zoneIn),
                $from === 'unix_ms' => CarbonImmutable::createFromTimestampMs((float) $value, $zoneIn),
                $from === 'pattern' => CarbonImmutable::createFromFormat((string) $step->setting('pattern_in'), $value, $zoneIn)
                    ?: throw new \InvalidArgumentException('no match'),
                default => CarbonImmutable::parse($value, $zoneIn),
            };
        } catch (\Throwable) {
            throw new StepFailed("\"{$value}\" could not be read as a date".($from === 'pattern' ? " in the pattern \"{$step->setting('pattern_in')}\"" : '').'.');
        }

        $add = trim($step->text('add'));

        if ($add !== '') {
            // "+3 days", "-2 hours", "next monday"
            try {
                $date = $date->modify(preg_match('/^\d/', $add) ? "+{$add}" : $add);
            } catch (\Throwable) {
                throw new StepFailed("\"{$add}\" is not a way to move a date: write it as +3 days, -2 hours, +1 month.");
            }
        }

        try {
            $date = $date->setTimezone($zone);
        } catch (\Throwable) {
            throw new StepFailed("\"{$zone}\" is not a time zone.");
        }

        try {
            $date = $date->locale($language);
            $date = $date instanceof CarbonImmutable ? $date : throw new \InvalidArgumentException('no such language');
        } catch (\Throwable) {
            throw new StepFailed("\"{$language}\" is not a language: two letters, as en, th, ja.");
        }

        $format = (string) ($step->setting('format') ?: 'iso');

        $text = match ($format) {
            'unix' => (string) $date->getTimestamp(),
            'relative' => $date->diffForHumans(),
            'custom' => $date->translatedFormat((string) ($step->setting('pattern') ?: 'Y-m-d')),
            default => $date->translatedFormat(self::FORMATS[$format] ?? self::FORMATS['iso']),
        };

        return new StepResult([
            'value' => $text,
            'iso' => $date->toIso8601String(),
            'date' => $date->format('Y-m-d'),
            'time' => $date->format('H:i'),
            'weekday' => $date->translatedFormat('l'),
            'unix' => $date->getTimestamp(),
            'zone' => $date->getTimezone()->getName(),
        ]);
    }
}
