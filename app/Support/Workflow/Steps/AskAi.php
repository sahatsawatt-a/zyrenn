<?php

namespace App\Support\Workflow\Steps;

use App\Support\Chat\AiClient;
use App\Support\Chat\AiFailed;
use App\Support\Workflow\References;

/**
 * Ask AI: one question to a model, through one of the owner's AI
 * connections -- a project's own, for a project's workflow, never someone's
 * personal key. Its answer comes out as text, or read as JSON.
 */
final class AskAi implements Step
{
    /** The most of an answer kept. */
    private const MAX_CHARS = 50000;

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
        $messages = [
            ...($system !== '' ? [['role' => 'system', 'content' => $system]] : []),
            ['role' => 'user', 'content' => $step->text('prompt')],
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
     * An answer without the ```json fence models like to put round it.
     */
    private static function unfenced(string $text): string
    {
        $text = trim($text);

        return preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $found) === 1 ? $found[1] : $text;
    }
}
