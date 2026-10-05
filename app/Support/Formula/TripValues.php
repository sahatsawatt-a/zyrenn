<?php

namespace App\Support\Formula;

use App\Models\Map\Trip;
use App\Support\Maps\TripDocument;

/**
 * A trip as a formula reads it: trip("Shanghai").total_cost, .nights,
 * .start_date, .day(5).date, .stay(1).cost. Everything comes from the
 * trip's own totals (TripDocument::totals), which its page shows too.
 */
final class TripValues implements Members
{
    /** @var array<string, array<string, mixed>> the totals, by ref_id and revision, for this request */
    private static array $worked = [];

    /** @var array<string, mixed> */
    private array $totals;

    public function __construct(private Trip $trip)
    {
        $key = $trip->ref_id.'@'.$trip->revision;
        $this->totals = self::$worked[$key] ??= TripDocument::totals(TripDocument::normalize($trip->content));
    }

    public function member(string $name): mixed
    {
        $totals = $this->totals;

        return match (mb_strtolower($name)) {
            'title' => $this->trip->title,
            'currency' => (string) $totals['currency'],
            'total_cost' => (float) $totals['total_cost'],
            'stops_cost' => (float) $totals['stops_cost'],
            'rides_cost' => (float) $totals['rides_cost'],
            'stays_cost' => (float) $totals['stays_cost'],
            'nights' => (float) $totals['nights'],
            'day_count', 'days' => (float) $totals['day_count'],
            'start_date' => Evaluator::date($totals['start_date']),
            'end_date' => Evaluator::date($totals['end_date']),
            default => throw new FormulaError("A trip has no \"{$name}\": it has title, total_cost, stops_cost, rides_cost, stays_cost, nights, day_count, start_date, end_date and currency, and day(n) and stay(n)."),
        };
    }

    public function call(string $name, array $args): mixed
    {
        return match (mb_strtolower($name)) {
            'day' => $this->day($args),
            'stay' => $this->stay($args),
            default => throw new FormulaError("A trip has no \"{$name}\" to call: it has day(n) and stay(n)."),
        };
    }

    /**
     * Day n of the trip, as its page counts them from 1.
     *
     * @param  list<mixed>  $args
     * @return array{date: \DateTimeImmutable, cost: float, stops: float}
     */
    private function day(array $args): array
    {
        $days = $this->totals['days'];
        $n = (int) Evaluator::number($args[0] ?? throw new FormulaError('day(n) needs which day, e.g. day(5).'));

        if ($n < 1 || $n > count($days)) {
            throw new FormulaError('The trip has days 1 to '.count($days).", not day {$n}.");
        }

        $day = $days[$n - 1];

        return ['date' => Evaluator::date($day['date']), 'cost' => (float) $day['cost'], 'stops' => (float) $day['stops']];
    }

    /**
     * A stay, by its place among them (first check-in first), or by its name.
     *
     * @param  list<mixed>  $args
     * @return array{name: string, nights: float, cost: float}
     */
    private function stay(array $args): array
    {
        $stays = $this->totals['stays'];
        $which = $args[0] ?? throw new FormulaError('stay(n) needs which stay, e.g. stay(1).');

        if (is_string($which) && ! is_numeric($which)) {
            $found = array_values(array_filter($stays, fn (array $stay) => mb_strtolower($stay['name']) === mb_strtolower(trim($which))));
            $stay = $found[0] ?? throw new FormulaError("The trip has no stay called \"{$which}\".");
        } else {
            $n = (int) Evaluator::number($which);
            $stay = $stays[$n - 1] ?? throw new FormulaError(count($stays) === 0
                ? 'The trip has no stays.'
                : 'The trip has stays 1 to '.count($stays).", not stay {$n}.");
        }

        return ['name' => (string) $stay['name'], 'nights' => (float) $stay['nights'], 'cost' => (float) $stay['cost']];
    }
}
