<?php

namespace App\Support\Workflow\Steps;

use App\Support\Outside\RemoteDownload;

/**
 * Save to Drive: a file at an address on the internet, or text written
 * here -- kept in the Drive of the workflow's owner, and given out as that
 * file. (A file sent to a bot or a channel is fetched by that service's own
 * step: "Telegram: get the file", "LINE: get the file".)
 *
 * Run again after a worker died, it saves a second copy rather than lose
 * the first: nothing outside is changed by it.
 */
final class DriveSave implements Step
{
    /** Text written into a file. */
    private const MAX_TEXT_BYTES = 5 * 1024 * 1024;

    public function run(StepContext $step): StepResult
    {
        // Told to use a file of that name already in the folder: nothing is fetched
        if (($there = DriveSteps::there($step)) !== null) {
            return new StepResult(DriveSteps::summary($there));
        }

        return DriveSteps::keep($step, $step->setting('from') === 'text' ? self::fromText($step) : self::fromUrl($step));
    }

    /**
     * @return array{path: string, name: string}
     */
    private static function fromUrl(StepContext $step): array
    {
        $url = trim($step->text('url'));

        if ($url === '') {
            throw new StepFailed('The address came to nothing.');
        }

        return DriveSteps::fetched(fn () => RemoteDownload::fetch($url, DriveSteps::MAX_BYTES, DriveSteps::TIMEOUT));
    }

    /**
     * @return array{path: string, name: string}
     */
    private static function fromText(StepContext $step): array
    {
        $text = $step->text('text');

        if (strlen($text) > self::MAX_TEXT_BYTES) {
            throw new StepFailed('That text is larger than '.intdiv(self::MAX_TEXT_BYTES, 1024 * 1024).' MB.');
        }

        $temp = (string) tempnam(sys_get_temp_dir(), 'workflow-file-');
        file_put_contents($temp, $text);

        return ['path' => $temp, 'name' => ''];
    }
}
