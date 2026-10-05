<?php

namespace App\Support\Formula;

use App\Models\Note\Note;

/**
 * A note's live values, {{ … }}: what each formula in it comes to now, worked
 * out among the note's owner's trips and tables. Nothing of the answer is
 * kept in the note -- only the formula -- so a total that changes never
 * touches what people are typing.
 */
final class NoteValues
{
    public const MAX = 200;

    /**
     * The formulas in a note's body, each once, in the order they appear.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<string>
     */
    public static function expressions(?array $doc): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            if (($node['type'] ?? null) === 'formula' && is_string($node['attrs']['expression'] ?? null)) {
                $found[$node['attrs']['expression']] = true;
            }

            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($doc ?? []);

        return array_slice(array_keys($found), 0, self::MAX);
    }

    /**
     * What each formula comes to, keyed by the formula: {value, text} or {error}.
     *
     * @param  list<string>  $expressions
     * @return array<string, array{value?: mixed, text?: string, error?: string}>
     */
    public static function of(Note $note, array $expressions): array
    {
        $scope = new References($note->owner());
        $values = [];

        foreach ($expressions as $expression) {
            $answer = Formula::attempt($expression, $scope);
            $values[$expression] = $answer['error'] !== null
                ? ['error' => $answer['error']]
                : ['value' => $answer['value'], 'text' => $answer['text']];
        }

        return $values;
    }
}
