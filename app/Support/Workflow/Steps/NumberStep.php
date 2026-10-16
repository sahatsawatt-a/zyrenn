<?php

namespace App\Support\Workflow\Steps;

use NumberFormatter;

/**
 * A number written for people: with thousands marked, as money, as a
 * percentage or in words -- in a language's own way -- from a number or
 * from text that has one in it ("฿1,234.50").
 */
final class NumberStep implements Step
{
    public function run(StepContext $step): StepResult
    {
        $given = $step->template('value');
        $number = is_int($given) || is_float($given) ? $given : self::read(trim((string) (is_scalar($given) ? $given : '')));

        if ($number === null) {
            throw new StepFailed('"'.mb_strimwidth((string) (is_scalar($given) ? $given : json_encode($given)), 0, 60, '…').'" has no number in it.');
        }

        $decimals = max(0, min(10, (int) ($step->setting('decimals') ?? 2)));
        $language = trim($step->text('language')) ?: 'en';
        $style = (string) ($step->setting('style') ?: 'thousands');

        $text = match ($style) {
            'plain' => number_format($number, $decimals, '.', ''),
            'currency' => self::currency($number, $decimals, $language, strtoupper(trim($step->text('currency')) ?: 'USD')),
            default => self::formatted($number, $decimals, $language, match ($style) {
                'percent' => NumberFormatter::PERCENT,
                'words' => NumberFormatter::SPELLOUT,
                default => NumberFormatter::DECIMAL,
            }),
        };

        return new StepResult(['value' => $text, 'number' => $number]);
    }

    private static function formatted(int|float $number, int $decimals, string $language, int $style): string
    {
        $formatter = new NumberFormatter($language, $style);

        if ($style !== NumberFormatter::SPELLOUT) {
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        }

        return (string) $formatter->format($number);
    }

    private static function currency(int|float $number, int $decimals, string $language, string $code): string
    {
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new StepFailed("\"{$code}\" is not a currency: three letters, as THB, USD, EUR.");
        }

        $formatter = new NumberFormatter($language, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $formatter->formatCurrency((float) $number, $code);
    }

    /**
     * The number in a piece of text: "฿1,234.50" is 1234.5, "1.234,5" is
     * 1234.5 -- the last of a dot or comma marks the decimals, unless it is
     * a comma with three digits after it and no dot.
     */
    public static function read(string $text): int|float|null
    {
        if (! preg_match('/-?\d[\d.,\s\x{00A0}]*/u', $text, $found)) {
            return null;
        }

        $digits = (string) preg_replace('/[\s\x{00A0}]/u', '', rtrim($found[0], '.,'));
        $dot = strrpos($digits, '.');
        $comma = strrpos($digits, ',');
        $decimalComma = match (true) {
            $dot !== false && $comma !== false => $comma > $dot,
            $comma !== false => substr_count($digits, ',') === 1 && strlen($digits) - $comma - 1 !== 3,
            default => false,
        };

        if ($decimalComma) {
            $digits = str_replace(['.', ','], ['', '.'], $digits);
        } elseif ($dot !== false && substr_count($digits, '.') > 1) {
            // 1.234.567: dots marking thousands
            $digits = str_replace(['.', ','], '', $digits);
        } else {
            $digits = str_replace(',', '', $digits);
        }

        if (! is_numeric($digits)) {
            return null;
        }

        return str_contains($digits, '.') ? (float) $digits : (int) $digits;
    }
}
