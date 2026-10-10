<?php

namespace App\Support\Workflow\Steps;

use App\Events\WorkflowRunText;
use App\Support\Live\Live;

/**
 * Words passed on to the editor as a step writes them, a few times a second
 * rather than word by word: an AI answer streamed into the run panel.
 */
final class TextStream
{
    /** How often, at most, words are sent. */
    private const EVERY_MS = 250;

    /** Sent sooner than that once this much has gathered: a message stays small. */
    private const MOST_CHARS = 2000;

    private string $waiting = '';

    private int $sent = 0;

    private float $lastAt = 0;

    private readonly string $workflow;

    public function __construct(private StepContext $step)
    {
        $this->workflow = (string) $step->run->workflow()->value('ref_id');
    }

    public function add(string $piece): void
    {
        $this->waiting .= $piece;

        if (mb_strlen($this->waiting) >= self::MOST_CHARS || (microtime(true) * 1000) - $this->lastAt >= self::EVERY_MS) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->waiting === '') {
            return;
        }

        Live::tellEveryone(new WorkflowRunText($this->workflow, $this->step->run->ref_id, $this->step->nodeRun, $this->sent, $this->waiting));
        $this->sent += mb_strlen($this->waiting);
        $this->waiting = '';
        $this->lastAt = microtime(true) * 1000;
    }
}
