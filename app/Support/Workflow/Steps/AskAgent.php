<?php

namespace App\Support\Workflow\Steps;

use App\Support\Agents\AgentRunner;
use App\Support\Ai\AiFailed;
use App\Support\Workflow\References;

/**
 * Ask an agent: one of the owner's, as it now is or at a version named --
 * its model, its instructions, what its knowledge base finds -- given a
 * message and, in a chat, the conversation so far. Gives out the answer as
 * text, read as JSON when it is, and the version that answered.
 */
final class AskAgent implements Step
{
    public function __construct(private AgentRunner $agents) {}

    public function run(StepContext $step): StepResult
    {
        $agent = References::agent($step->owner(), (string) $step->setting('agent'))
            ?? throw new StepFailed('Its agent is gone, or is not this workflow\'s.');
        $version = trim((string) $step->setting('version'));
        $room = filled($step->setting('room')) && trim($step->text('room')) !== '' ? ChatSend::room($step, $step->text('room')) : null;

        if ($version !== '' && ! ctype_digit($version)) {
            throw new StepFailed('A version is a number, like 3; left empty, the latest.');
        }

        // What asking again would not mend: a version that is not there, or one with no model to ask
        $at = $agent->at($version === '' ? null : (int) $version)
            ?? throw new StepFailed($version === '' ? "\"{$agent->name}\" has no version yet: set it up on its page." : "\"{$agent->name}\" has no version {$version}.");

        if ($at->connection_id === null) {
            throw new StepFailed("Version {$at->number} of \"{$agent->name}\" has no AI connection: choose one on its page.");
        }

        $live = new TextStream($step);

        try {
            // In a workflow its tools act for whoever the run acts as, and may change things it was let to
            $answer = $this->agents->ask($agent, $version === '' ? null : (int) $version, $step->text('message'), $room, piece: fn (string $part) => $live->add($part), for: $step->actor(), changes: true);
            $live->flush();
        } catch (AiFailed $e) {
            throw new StepFailed($e->getMessage(), retryable: true);
        }

        if ($step->setting('answer') === 'json' && ! is_array($answer['json'])) {
            throw new StepFailed('The answer was not JSON: '.mb_strimwidth($answer['text'], 0, 200, '…'));
        }

        return new StepResult([
            'text' => $answer['text'],
            ...$step->setting('answer') === 'json' ? ['json' => $answer['json']] : [],
            'agent' => $agent->name,
            'version' => $answer['version'],
            'model' => $answer['model'],
            'found' => $answer['found'],
            // What it was given to answer from, to be shown beside the answer
            'sources' => $answer['sources'],
            'knows' => $answer['knows'],
            // What it did with its tools
            'actions' => $answer['actions'],
        ]);
    }
}
