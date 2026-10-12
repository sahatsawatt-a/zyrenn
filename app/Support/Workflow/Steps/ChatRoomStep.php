<?php

namespace App\Support\Workflow\Steps;

use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Support\Workflow\References;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Find or make a room: the room that stands for an outside conversation --
 * one for each Telegram chat, say -- made the first time it is asked for.
 * A personal workflow's rooms are its owner's own chats; a project's are
 * the project's group chats, started by whoever the run acts as.
 */
final class ChatRoomStep implements Step
{
    public function run(StepContext $step): StepResult
    {
        $owner = $step->owner();
        $key = trim($step->text('key'));
        $title = mb_substr(trim($step->text('title')) ?: 'From a workflow', 0, 80);

        if ($key !== '') {
            $found = References::rooms($owner)->where('external_key', $key)->first();

            if ($found !== null) {
                self::link($step, $found);

                return new StepResult(self::describe($found, false));
            }
        }

        try {
            $room = $owner instanceof Project ? self::group($owner, $step, $title) : self::own($owner->getKey(), $title);
            $room->forceFill(['external_key' => $key !== '' ? mb_substr($key, 0, 120) : null])->save();
        } catch (UniqueConstraintViolationException) {
            // Another run made it a moment before
            $room = References::rooms($owner)->where('external_key', $key)->firstOrFail();
            self::link($step, $room);

            return new StepResult(self::describe($room, false));
        }

        self::link($step, $room);

        return new StepResult(self::describe($room, true));
    }

    private static function own(int|string $userId, string $title): ChatRoom
    {
        $room = new ChatRoom;
        $room->kind = ChatRoom::PERSONAL;
        $room->user_id = (int) $userId;
        $room->title = $title;
        $room->save();

        return $room;
    }

    private static function group(Project $project, StepContext $step, string $title): ChatRoom
    {
        $by = $step->actor();

        if (! in_array($project->roleOf($by), [Project::OWNER, Project::EDITOR], true)) {
            throw new StepFailed("{$by->name}, who this workflow acts as, can no longer change things in the project.");
        }

        return ChatRoom::startGroup($project, $by, $title);
    }

    /**
     * Link the room to the outside chat it stands for, through the bot or
     * channel named, so what is written in it can go back the same way.
     */
    private static function link(StepContext $step, ChatRoom $room): void
    {
        self::note($step, $room);

        $through = trim((string) $step->setting('link'));

        if ($through === '') {
            return;
        }

        $connection = References::connection($step->owner(), $through, ['telegram', 'line'])
            ?? throw new StepFailed('The bot or channel to link it to is gone, or is not this workflow\'s.');
        $chat = trim($step->text('link_chat'));

        if ($chat === '') {
            throw new StepFailed('There is no chat to link it to.');
        }

        $link = ['service' => $connection->service, 'connection' => $connection->ref_id, 'chat' => mb_substr($chat, 0, 120)];

        if ($room->link !== $link) {
            $room->forceFill(['link' => $link])->save();
        }
    }

    /**
     * Keep the room's picture and the details the step names, each worked
     * out now: who it is with can change their name and their face.
     */
    private static function note(StepContext $step, ChatRoom $room): void
    {
        $details = [];

        foreach ((array) $step->setting('details') as $field) {
            $name = trim((string) ($field['name'] ?? ''));

            if ($name !== '') {
                $details[$name] = $step->text("details.{$name}", (string) ($field['value'] ?? ''));
            }
        }

        $picture = filled($step->setting('picture')) ? trim($step->text('picture')) : null;

        // A picture in the Drive, by its ref_id, is shown from there: one of the workflow's own, and a picture
        if ($picture !== null && $picture !== '' && ! str_starts_with($picture, 'https://')) {
            $file = $step->owner()->driveFiles()->where('ref_id', $picture)->first();
            $picture = $file?->isImage() ? $file->url() : null;
        }

        $room->note($details, $picture);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(ChatRoom $room, bool $created): array
    {
        return [
            'room' => $room->ref_id,
            'title' => $room->title,
            'link' => $room->link,
            'picture' => $room->picture,
            'details' => $room->details ?? [],
            'url' => route('chats.show', $room),
            'created' => $created,
        ];
    }
}
