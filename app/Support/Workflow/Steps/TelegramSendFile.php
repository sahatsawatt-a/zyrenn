<?php

namespace App\Support\Workflow\Steps;

/**
 * Send a file on Telegram: one of the Drive's, from a bot to a chat, by
 * Telegram's own call for its kind -- sendPhoto for a picture, sendVideo for
 * a video, sendDocument for anything else -- with words under it when there
 * are any. Recorded and never simply repeated, as a message is
 * (TelegramSend).
 */
final class TelegramSendFile implements Step
{
    public function run(StepContext $step): StepResult
    {
        return TelegramSend::sendWith($step, DriveSteps::file($step));
    }
}
