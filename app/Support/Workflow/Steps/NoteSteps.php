<?php

namespace App\Support\Workflow\Steps;

use App\Models\Note\Note;

/**
 * What the Notes steps share: the workflow owner's notes, found by ref_id
 * or by title, and a note given out the same way each time.
 */
final class NoteSteps
{
    /** What a note step gives out before it has one. */
    public const EMPTY = ['ref_id' => null, 'title' => null, 'folder' => null, 'url' => null];

    /** The owner's note of that ref_id, or else of that title (the latest of several). */
    public static function note(StepContext $step): Note
    {
        $named = trim((string) $step->template('note'));

        if ($named === '') {
            throw new StepFailed('Say which note: its ref_id or its title.');
        }

        $notes = $step->owner()->notes();

        return (clone $notes)->where('ref_id', $named)->first()
            ?? (clone $notes)->whereRaw('lower(title) = ?', [mb_strtolower($named)])->latest('updated_at')->first()
            ?? throw new StepFailed("There is no note \"{$named}\" here.");
    }

    /** @return array<string, mixed> */
    public static function out(Note $note): array
    {
        return [
            'ref_id' => $note->ref_id,
            'title' => $note->title,
            'folder' => $note->folder?->name,
            'url' => route('notes.show', $note),
        ];
    }
}
