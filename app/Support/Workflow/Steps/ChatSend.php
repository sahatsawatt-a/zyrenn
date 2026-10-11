<?php

namespace App\Support\Workflow\Steps;

use App\Events\ChatMessagePosted;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Support\Chat\AiFailed;
use App\Support\Chat\ChatEngine;
use App\Support\Live\Live;
use App\Support\Workflow\References;

/**
 * Write in a room, as the workflow: shown under the workflow's name (or the
 * outside sender's, for a message carried over from Telegram), naming who
 * it acts as, with a file from the Drive under it when one is named. Said
 * once, however often the step is tried. It can then ask the room's AI and
 * wait for the answer.
 */
final class ChatSend implements Step
{
    public function __construct(private ChatEngine $engine) {}

    public function run(StepContext $step): StepResult
    {
        $room = self::room($step, $step->text('room'));
        $content = trim($step->text('message'));
        $file = filled($step->setting('file')) ? DriveSteps::file($step) : null;

        if ($content === '' && $file === null) {
            throw new StepFailed('The message came to nothing.');
        }

        // Said already, on a try before: the same message stands
        $said = ChatMessage::where('workflow_node_run_id', $step->nodeRun->id)->first();

        if ($said === null) {
            $workflow = $step->run->workflow;
            $sender = trim($step->text('sender'));

            $said = $room->messages()->create([
                'role' => ChatMessage::WORKFLOW,
                'content' => mb_substr($content, 0, 20000),
                'meta' => array_filter([
                    'workflow' => $workflow->ref_id,
                    'name' => $sender !== '' ? mb_substr($sender, 0, 80) : ($workflow->title ?: 'Workflow'),
                    'outside' => $sender !== '',
                    'by' => $step->run->actor()?->name,
                    'run' => $step->run->ref_id,
                    // Shown under the words: the room's people may open what is in its owner's Drive
                    'file' => $file?->ref_id,
                ], fn ($value) => $value !== null),
            ]);
            $said->forceFill(['workflow_node_run_id' => $step->nodeRun->id])->save();
            $room->touch();

            Live::tellEveryone(new ChatMessagePosted($room, $said));
        }

        $output = ['room' => $room->ref_id, 'message' => ['id' => $said->id, 'content' => $said->content, ...$file === null ? [] : ['file' => $file->ref_id]]];

        if ($step->setting('ask') !== 'agent') {
            return new StepResult($output);
        }

        if (! $room->hasAgent()) {
            throw new StepFailed('This room has no AI to answer: only one\'s own chats have one, set in the chat.');
        }

        try {
            $answer = $this->engine->reply($room, fn () => true);
        } catch (AiFailed $e) {
            throw new StepFailed($e->getMessage(), retryable: true);
        }

        if ($answer !== null) {
            Live::tellEveryone(new ChatMessagePosted($room, $answer));
        }

        return new StepResult([...$output, 'answer' => $answer?->content]);
    }

    /**
     * A room the workflow may write in, by its ref_id.
     *
     * @throws StepFailed when it has no such room
     */
    public static function room(StepContext $step, string $refId): ChatRoom
    {
        return References::room($step->owner(), trim($refId))
            ?? throw new StepFailed('"'.$refId.'" is not a room this workflow can use: '.($step->run->workflow->project_id ? 'one of its project\'s group chats.' : 'one of its owner\'s own chats.'));
    }
}
