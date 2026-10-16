<?php

namespace App\Support\Workflow\Steps;

use App\Support\Live\Collab;
use App\Support\Note\NoteBlockProblem;
use App\Support\Note\NoteBlocks;
use App\Support\Note\PageLinks;

/**
 * Add to a note: Markdown put at its end -- or its start -- the note found
 * by its ref_id or title. A note open somewhere changes there as it is
 * written, as an MCP edit does.
 */
final class NoteAppend implements Step
{
    public function run(StepContext $step): StepResult
    {
        $owner = $step->owner();
        $note = NoteSteps::note($step);
        $markdown = (string) $step->template('markdown');

        if (trim($markdown) === '') {
            throw new StepFailed('What it adds came out empty.');
        }

        // Added to the note as everyone has it now
        Collab::flush($note);
        $note->refresh();

        try {
            $done = NoteBlocks::apply($note->content, [[
                'op' => $step->setting('where') === 'start' ? 'prepend' : 'append',
                'markdown' => $markdown,
            ]]);
        } catch (NoteBlockProblem $problem) {
            throw new StepFailed($problem->getMessage());
        }

        [$done['edits']] = PageLinks::resolve($done['edits'], $owner);
        [$done['doc']] = PageLinks::resolve($done['doc'], $owner);

        if (Collab::apply($note, $done['edits'], $step->actor()) === null) {
            $note->content = $done['doc'];
            $note->save();
        } else {
            $note->refresh();
        }

        return new StepResult(NoteSteps::out($note));
    }
}
