<?php

namespace App\Jobs;

use App\Models\Knowledge\KnowledgeBase;
use App\Support\Knowledge\Knowledge;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read a knowledge base's sources again, away from whoever asked: a model
 * turning many passages into numbers takes a while. One at a time for each
 * base.
 */
class ReadKnowledge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $baseId) {}

    public function uniqueId(): string
    {
        return (string) $this->baseId;
    }

    public function handle(Knowledge $knowledge): void
    {
        $base = KnowledgeBase::find($this->baseId);

        if ($base !== null) {
            $knowledge->read($base);
        }
    }
}
