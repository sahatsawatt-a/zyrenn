<?php

namespace App\Support\Workflow\Steps;

use App\Models\Note\Note;
use App\Support\Folders;
use App\Support\Markdown\TiptapMarkdown;
use App\Support\Note\PageLinks;

/**
 * Write a note: Markdown made into a note of the workflow's owner, in a
 * folder by its path (made when it is not there), [[Title]] links pointing
 * at those pages. Given out as it is.
 */
final class NoteCreate implements Step
{
    public function run(StepContext $step): StepResult
    {
        $owner = $step->owner();
        $actor = $step->actor();
        $title = trim((string) $step->template('title'));

        if ($title === '') {
            throw new StepFailed('Its title came out empty.');
        }

        $markdown = (string) $step->template('markdown');
        [$doc] = PageLinks::resolve(TiptapMarkdown::toDoc($markdown), $owner);

        $note = (new Note(['title' => mb_substr($title, 0, 255), 'content' => $markdown === '' ? null : $doc]))->ownedBy($owner, $actor);
        $note->folder_id = Folders::ensure($owner->noteFolders(), trim((string) $step->template('folder')), $actor)?->id;
        $note->save();

        return new StepResult(NoteSteps::out($note->refresh()));
    }
}
