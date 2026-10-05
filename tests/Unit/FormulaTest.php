<?php

namespace Tests\Unit;

use App\Support\Formula\Formula;
use App\Support\Formula\FormulaError;
use App\Support\Formula\Members;
use App\Support\Formula\NamedValues;
use Tests\TestCase;

/**
 * The formula language every computed value is written in: a table's
 * formula columns, its footer, a note's chips.
 */
class FormulaTest extends TestCase
{
    private function scope(): NamedValues
    {
        // A trip's budget: a row of it, the table's parameters around it
        $parameters = new NamedValues(['rate' => 5.0, 'people' => 2.0, 'start' => '2026-12-03']);

        return new NamedValues([
            'cny' => 120.0,
            'Cost (THB)' => 600.0,
            'kind' => 'estimate',
            'day' => '5',
            'note' => null,
            'budget' => $this->table(['day' => [1.0, 1.0, 5.0], 'thb' => [100.0, 250.0, 400.0]]),
            'hotel' => ['name' => 'Indigo', 'nights' => 9.0],
        ], $parameters);
    }

    /**
     * Another table, as a formula sees it: each column a list.
     *
     * @param  array<string, list<mixed>>  $columns
     */
    private function table(array $columns): Members
    {
        return new class($columns) implements Members
        {
            /** @param array<string, list<mixed>> $columns */
            public function __construct(private array $columns) {}

            public function member(string $name): mixed
            {
                return $this->columns[$name] ?? throw new FormulaError("No column \"{$name}\".");
            }

            public function call(string $name, array $args): mixed
            {
                throw new FormulaError("No \"{$name}\" to call.");
            }
        };
    }

    private function text(string $formula): string
    {
        $answer = Formula::attempt($formula, $this->scope());
        $this->assertNull($answer['error'], "{$formula}: {$answer['error']}");

        return $answer['text'];
    }

    private function problem(string $formula): string
    {
        $answer = Formula::attempt($formula, $this->scope());
        $this->assertNotNull($answer['error'], "{$formula} should not work out");

        return $answer['error'];
    }

    public function test_arithmetic_follows_the_usual_order()
    {
        $this->assertSame('14', $this->text('2 + 3 * 4'));
        $this->assertSame('20', $this->text('(2 + 3) * 4'));
        $this->assertSame('512', $this->text('2 ^ 3 ^ 2'));
        $this->assertSame('-4', $this->text('-2 ^ 2'));
        $this->assertSame('1', $this->text('7 % 3'));
        $this->assertSame('0.3', $this->text('0.1 + 0.2'));
        $this->assertSame('2.5', $this->text('5 / 2'));
    }

    public function test_names_are_columns_then_parameters_and_ignore_case()
    {
        $this->assertSame('600', $this->text('cny * rate'));
        $this->assertSame('300', $this->text('[cost (thb)] / People'));
        $this->assertSame('120', $this->text('CNY'));
        $this->assertSame('Nothing is called "fx".', $this->problem('cny * fx'));
    }

    public function test_conditions_and_text()
    {
        $this->assertSame('0', $this->text('if(kind = "estimate", 0, cny)'));
        $this->assertSame('120', $this->text('if(kind != "estimate", 0, cny)'));
        $this->assertSame('', $this->text('if(false, 1)'));
        $this->assertSame('true', $this->text('day = 5 and not (cny < 100)'));
        $this->assertSame('Day 5 · ฿600', $this->text('"Day " & day & " · ฿" & cny * rate'));
        $this->assertSame('1,234.50', $this->text('text(1234.5, 2)'));

        // The branch not taken is never worked out
        $this->assertSame('1', $this->text('if(true, 1, 1 / 0)'));
        $this->assertSame('false', $this->text('false and 1 / 0'));
    }

    public function test_an_empty_cell_is_nothing_in_text_and_zero_in_sums()
    {
        $this->assertSame('5', $this->text('note + 5'));
        $this->assertSame('x', $this->text('note & "x"'));
        $this->assertSame('fallback', $this->text('coalesce(note, "fallback")'));
    }

    public function test_another_tables_columns_are_lists_worked_on_whole()
    {
        $this->assertSame('750', $this->text('sum(budget.thb)'));
        $this->assertSame('3750', $this->text('sum(budget.thb * rate)'));
        $this->assertSame('400', $this->text('sum(if(budget.day = 5, budget.thb, 0))'));
        $this->assertSame('350', $this->text('sum(filter(budget.thb, budget.day = 1))'));
        $this->assertSame('3', $this->text('count(budget.thb)'));
        $this->assertSame('250', $this->text('avg(budget.thb)'));
        $this->assertSame('100, 400', $this->text('min(budget.thb) & ", " & max(budget.thb)'));
        $this->assertSame('Indigo for 9 nights', $this->text('hotel.name & " for " & hotel.nights & " nights"'));
        $this->assertSame('No column "eur".', $this->problem('budget.eur'));
        $this->assertStringContainsString('different lengths', $this->problem('budget.thb + filter(budget.thb, budget.day = 1)'));
    }

    public function test_dates_move_by_days()
    {
        $this->assertSame('2026-12-07', $this->text('date(start) + day - 1'));
        $this->assertSame('Mon 7 Dec', $this->text('text(date(start) + 4, "D j M")'));
        $this->assertSame('Thu', $this->text('weekday(date(2026, 12, 3))'));
        $this->assertSame('9', $this->text('days(date(start), date("2026-12-12"))'));
        $this->assertSame('-9', $this->text('date(start) - date("2026-12-12")'));
        $this->assertSame('true', $this->text('date("2026-12-12") > date(start)'));
        $this->assertSame('2026-11-30', $this->text('date(start) - 3'));
        $this->assertSame('2026-2-30 is not a date.', $this->problem('date(2026, 2, 30)'));
    }

    public function test_what_goes_wrong_is_said_plainly()
    {
        $this->assertSame('Dividing by zero.', $this->problem('cny / 0'));
        $this->assertSame('Expected a number but got the text "estimate".', $this->problem('kind * 2'));
        $this->assertSame('There is no function called "vlookup".', $this->problem('vlookup(cny)'));
        $this->assertSame('Expected a value but found the end of the formula at character 6.', $this->problem('cny +'));
        $this->assertSame('Unexpected "cny" at character 5.', $this->problem('cny cny'));
        $this->assertSame('A text starting at character 1 is never closed with ".', $this->problem('"open'));
        $this->assertSame('"$" at character 1 means nothing in a formula.', $this->problem('$5'));
        $this->assertSame('The formula is empty.', $this->problem('  '));
        $this->assertSame('the number 120 has no parts, so it has no "x".', $this->problem('cny.x'));
    }

    public function test_a_runaway_formula_is_stopped()
    {
        $this->assertSame('The formula is nested too deeply.', $this->problem(str_repeat('(', 100).'1'.str_repeat(')', 100)));
        $this->assertStringContainsString('at most', $this->problem(str_repeat('1+', 1500).'1'));
    }

    public function test_the_names_a_formula_looks_up_are_listed_once()
    {
        $this->assertSame(['cny', 'rate', 'budget', 'cost (thb)'], Formula::names('round(cny * rate) + sum(budget.thb) + [Cost (THB)] + CNY'));
    }
}
