<?php

namespace App\Jobs;

use App\Models\Drive\DriveFile;
use App\Support\Drive\VideoMp4;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A WebM video just put in the Drive made MP4 (VideoMp4), away from whoever
 * put it there: a long one takes minutes. On the slow-work worker's queue
 * with browser checks, one at a time -- never beside a workflow step a
 * customer is waiting on, nor several at once on a machine short of memory.
 * Should it fail, the WebM stays as it was.
 */
class MakeVideoMp4 implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1000;

    public int $tries = 1;

    public function __construct(public int $fileId)
    {
        $this->onQueue(RunBrowserChecks::QUEUE);
    }

    public function handle(): void
    {
        $file = DriveFile::find($this->fileId);

        if ($file === null) {
            return;
        }

        try {
            VideoMp4::make($file);
        } catch (Throwable $problem) {
            // Kept as WebM: it plays where it did, and the command tries again
            report($problem);
        }
    }
}
