<?php

namespace App\Support\Workflow\Steps;

use App\Models\Drive\DriveFile;
use App\Support\Chat\AiClient;
use App\Support\Chat\AiFailed;
use App\Support\Workflow\References;

/**
 * Ask AI: one question to a model, through one of the owner's AI
 * connections -- a project's own, for a project's workflow, never someone's
 * personal key. Its answer comes out as text, or read as JSON. Pictures from
 * the Drive can go with the question, for a model that takes them.
 */
final class AskAi implements Step
{
    /** The most of an answer kept. */
    private const MAX_CHARS = 50000;

    private const MAX_PICTURES = 4;

    private const MAX_PICTURE_BYTES = 8 * 1024 * 1024;

    private const PICTURE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private AiClient $ai) {}

    public function run(StepContext $step): StepResult
    {
        $connection = References::connection($step->owner(), (string) $step->setting('connection'), ['ai'])
            ?? throw new StepFailed('Its AI connection is gone, or is not this workflow\'s.');
        $model = trim((string) $step->setting('model')) ?: $connection->setting('default_model');

        if ($model === null || $model === '') {
            throw new StepFailed('Name a model: its connection has no usual one.');
        }

        $system = trim($step->text('system'));
        $pictures = filled($step->setting('pictures')) ? self::pictures($step) : [];
        $messages = [
            ...($system !== '' ? [['role' => 'system', 'content' => $system]] : []),
            // With pictures, the question is one part among them
            ['role' => 'user', 'content' => $pictures === [] ? $step->text('prompt') : [['type' => 'text', 'text' => $step->text('prompt')], ...$pictures]],
        ];

        $text = '';
        // Shown in the run panel as it comes
        $live = new TextStream($step);

        try {
            foreach ($this->ai->stream($connection, $model, $messages) as $piece) {
                $text .= $piece;
                $live->add($piece);

                if (mb_strlen($text) > self::MAX_CHARS) {
                    break;
                }
            }

            $live->flush();
        } catch (AiFailed $e) {
            // A host down or busy for a moment may answer the next time; asking again costs nothing else
            throw new StepFailed($e->getMessage(), retryable: true);
        }

        $output = ['text' => trim($text), 'model' => $model];

        if ($step->setting('answer') === 'json') {
            $json = json_decode(self::unfenced($text), true);

            if (! is_array($json)) {
                throw new StepFailed('The answer was not JSON: '.mb_strimwidth(trim($text), 0, 200, '…'));
            }

            $output['json'] = $json;
        }

        return new StepResult($output);
    }

    /**
     * The Drive pictures the step names, each as a part of the question:
     * its bytes written into the request, since the model's host can't
     * fetch from the Drive.
     *
     * @return list<array{type: string, image_url: array{url: string}}>
     */
    private static function pictures(StepContext $step): array
    {
        $files = DriveSteps::files($step, 'pictures');

        if (count($files) > self::MAX_PICTURES) {
            throw new StepFailed('A question takes at most '.self::MAX_PICTURES.' pictures.');
        }

        return array_map(function (DriveFile $file) {
            if (! in_array($file->mime, self::PICTURE_MIMES, true)) {
                throw new StepFailed("\"{$file->name}\" is not a picture a model can look at (JPEG, PNG, WebP or GIF).");
            }

            if ($file->size > self::MAX_PICTURE_BYTES) {
                throw new StepFailed("\"{$file->name}\" is larger than ".intdiv(self::MAX_PICTURE_BYTES, 1024 * 1024).' MB, the most a picture put to a model may be.');
            }

            return ['type' => 'image_url', 'image_url' => ['url' => "data:{$file->mime};base64,".base64_encode((string) file_get_contents(DriveSteps::path($file)))]];
        }, $files);
    }

    /**
     * An answer without the ```json fence models like to put round it.
     */
    private static function unfenced(string $text): string
    {
        $text = trim($text);

        return preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $found) === 1 ? $found[1] : $text;
    }
}
