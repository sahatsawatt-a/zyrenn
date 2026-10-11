<?php

namespace App\Support\Workflow\Steps;

/**
 * Read a file: one of the Drive's, by its ref_id -- what it is called, its
 * kind and size, where it is served from -- and, when it is text, what it
 * says: the start of it, for a long one.
 */
final class DriveRead implements Step
{
    /** The most of a text file given out: a step's whole output is capped (Engine). */
    private const MAX_TEXT_BYTES = 64 * 1024;

    private const TEXT_MIMES = ['application/json', 'application/xml', 'application/x-ndjson', 'application/yaml', 'application/x-yaml'];

    public function run(StepContext $step): StepResult
    {
        $file = DriveSteps::file($step);
        $text = null;

        if (str_starts_with((string) $file->mime, 'text/') || in_array($file->mime, self::TEXT_MIMES, true)) {
            $read = (string) file_get_contents(DriveSteps::path($file), length: self::MAX_TEXT_BYTES);
            // Cut on a whole character, and only given when it is text after all
            $read = mb_strcut($read, 0, self::MAX_TEXT_BYTES, 'UTF-8');
            $text = mb_check_encoding($read, 'UTF-8') ? $read : null;
        }

        return new StepResult([
            ...DriveSteps::summary($file),
            'text' => $text,
            'cut_short' => $text !== null && $file->size > self::MAX_TEXT_BYTES,
        ]);
    }
}
