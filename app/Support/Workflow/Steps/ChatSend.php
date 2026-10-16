<?php

namespace App\Support\Workflow\Steps;

use App\Events\ChatMessagePosted;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatRoom;
use App\Support\Agents\Sourced;
use App\Support\Ai\AiFailed;
use App\Support\Chat\ChatEngine;
use App\Support\Live\Live;
use App\Support\Workflow\Inbound\Envelope;
use App\Support\Workflow\References;

/**
 * Write in a room, as the workflow: shown under the workflow's name (or the
 * outside sender's, for a message carried over from Telegram), naming who
 * it acts as, with a file from the Drive or a place on a map under it when
 * one is named. Said
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
        // A file named that comes to nothing -- a step before it found none -- is no file
        $file = DriveSteps::fileIfAny($step);

        // A place: a record with lat and lng, as a message that sent one gives it
        $place = filled($step->setting('place')) ? ChatMessage::placeOf($step->template('place')) : null;

        if ($content === '' && $file === null && $place === null) {
            throw new StepFailed('The message came to nothing.');
        }

        // Said already, on a try before: the same message stands
        $said = ChatMessage::where('workflow_node_run_id', $step->nodeRun->id)->first();

        if ($said === null) {
            $workflow = $step->run->workflow;
            $sender = trim($step->text('sender'));

            [$content, $emojis] = self::emojis(mb_substr($content, 0, 20000));

            $said = $room->messages()->create([
                'role' => ChatMessage::WORKFLOW,
                'content' => $content,
                'meta' => array_filter([
                    'workflow' => $workflow->ref_id,
                    'name' => $sender !== '' ? mb_substr($sender, 0, 80) : ($workflow->title ?: 'Workflow'),
                    'outside' => $sender !== '',
                    'by' => $step->run->actor()?->name,
                    'run' => $step->run->ref_id,
                    // Shown under the words: the room's people may open what is in its owner's Drive
                    'file' => $file?->ref_id,
                    'place' => $place,
                    // Drawn small and bare, as a sticker is, rather than as a picture sent
                    'sticker' => $file !== null && $step->setting('shown') === 'sticker' ? true : null,
                    'emojis' => $emojis ?: null,
                    // What an agent answered from: shown to the project under the message
                    'sources' => filled($step->setting('sources')) ? Sourced::kept($step->template('sources')) : null,
                    // For the project alone, in a customer's chat
                    'internal' => $room->kind === ChatRoom::CUSTOMER && $step->setting('note') === 'note' ? true : null,
                ], fn ($value) => $value !== null),
            ]);
            $said->forceFill(['workflow_node_run_id' => $step->nodeRun->id])->save();
            $room->touch();

            Live::tellEveryone(new ChatMessagePosted($room, $said));
        }

        $output = ['room' => $room->ref_id, 'message' => ['id' => $said->id, 'content' => $said->content, ...$file === null ? [] : ['file' => $file->ref_id], ...$place === null ? [] : ['place' => $place]]];

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
     * Words with LINE's emoji written into them as pictures (Envelope::line
     * gives them so, as "display") are kept as the words alone, each emoji
     * by its name, with where its picture goes noted beside -- so the chat
     * can draw them, and anything else reading the message reads words.
     * Only pictures from LINE's own emoji address are taken.
     *
     * @return array{0: string, 1: list<array{index: int, length: int, url: string}>}
     */
    private static function emojis(string $content): array
    {
        $emojis = [];
        $plain = '';
        $at = 0;
        // Counted as the page will count: in UTF-16 units
        $units = fn (string $text) => intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);

        preg_match_all('/!\\[([^\\]\\n]*)\\]\\(('.preg_quote(Envelope::LINE_EMOJI, '/').'[A-Za-z0-9\\/]+\\.png)\\)/u', $content, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($found as $one) {
            $plain .= substr($content, $at, $one[0][1] - $at);
            $name = $one[1][0] !== '' ? $one[1][0] : '(emoji)';
            $emojis[] = ['index' => $units($plain), 'length' => $units($name), 'url' => $one[2][0]];
            $plain .= $name;
            $at = $one[0][1] + strlen($one[0][0]);
        }

        return [$plain.substr($content, $at), $emojis];
    }

    /**
     * A room the workflow may write in, by its ref_id.
     *
     * @throws StepFailed when it has no such room
     */
    public static function room(StepContext $step, string $refId): ChatRoom
    {
        return References::room($step->owner(), trim($refId))
            ?? throw new StepFailed('"'.$refId.'" is not a room this workflow can use: '.($step->run->workflow->project_id ? 'one of its project\'s chats.' : 'one of its owner\'s own chats.'));
    }
}
