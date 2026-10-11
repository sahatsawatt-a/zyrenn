<?php

namespace App\Support\Workflow\Steps;

/**
 * Reply on LINE: an answer to a LINE message, by LINE's reply call, with the
 * token the message came with -- free, but good only for a short while
 * after the message, and only once. Words, a picture from the Drive, or
 * both, as a pushed message is (LineSend).
 */
final class LineReply implements Step
{
    public function run(StepContext $step): StepResult
    {
        return LineSend::message($step, 'reply');
    }
}
