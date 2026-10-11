<?php

namespace App\Support\Workflow\Steps;

/**
 * Make a link to a file: an address anyone who has it can open one of the
 * Drive's files by, for a while, without signing in -- for sending a file
 * where only words can go (LINE takes no documents). It is signed: it opens
 * that one file and nothing else, and stops working when its time is up.
 */
final class DriveLink implements Step
{
    private const MOST_HOURS = 24 * 7;

    public function run(StepContext $step): StepResult
    {
        $file = DriveSteps::file($step);
        $hours = max(1, min(self::MOST_HOURS, (int) ($step->setting('hours') ?: 24)));

        DriveSteps::path($file);

        return new StepResult([
            'url' => DriveSteps::publicUrl($file, $hours * 60),
            'until' => now()->addHours($hours)->toIso8601String(),
            'name' => $file->name,
            'ref_id' => $file->ref_id,
        ]);
    }
}
