<?php

namespace App\Support\Workflow\Steps;

use App\Support\Chat\AiFailed;
use App\Support\Knowledge\Knowledge;
use App\Support\Workflow\References;

/**
 * Search knowledge: the passages of one of the owner's knowledge bases
 * nearest a question, best first -- and all of them as one text, ready to
 * give a model as what it may answer from.
 */
final class KnowledgeSearch implements Step
{
    public function __construct(private Knowledge $knowledge) {}

    public function run(StepContext $step): StepResult
    {
        $base = References::knowledge($step->owner(), (string) $step->setting('knowledge'))
            ?? throw new StepFailed('Its knowledge base is gone, or is not this workflow\'s.');

        if ($base->read_at === null) {
            throw new StepFailed("\"{$base->title}\" has not been read yet: read it on its page first.", retryable: true, retryAfter: 60);
        }

        try {
            $found = $this->knowledge->find($base, $step->text('query'), (int) ($step->setting('limit') ?: 5));
        } catch (AiFailed $e) {
            throw new StepFailed($e->getMessage(), retryable: true);
        }

        return new StepResult([
            'found' => count($found),
            'results' => $found,
            'context' => Knowledge::context($found),
            'best' => $found[0]['score'] ?? null,
        ]);
    }
}
