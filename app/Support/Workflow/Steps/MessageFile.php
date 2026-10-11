<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Workflow\References;
use App\Support\Workflow\Template;

/**
 * What "Telegram: get the file" and "LINE: get the file" share. A message
 * carries only the ids of what was sent with it (Envelope); each service's
 * own step asks it for the file, with the bot's or channel's key, and keeps
 * it in the Drive of the workflow's owner -- in the one step, since a file's
 * bytes can't go from step to step.
 */
final class MessageFile
{
    /**
     * The file a step names (the first the message carried, when it names
     * none), fetched by $fetch and kept in the Drive.
     *
     * @param  callable(Connection, string, array<string, mixed>): array{path: string, name: string}  $fetch  given the connection, the file's id, and what started the run
     *
     * @throws StepFailed
     */
    public static function get(StepContext $step, string $service, string $whose, callable $fetch): StepResult
    {
        $through = References::connection($step->owner(), (string) $step->setting('connection'), [$service])
            ?? throw new StepFailed("Its {$whose} is gone, or is not this workflow's.");
        $trigger = $step->run->trigger_data ?? [];
        // Left empty: the first the message carried
        $sent = filled($step->setting('file')) ? $step->template('file') : ($trigger['files'][0] ?? null);
        $id = trim(Template::text(is_array($sent) ? ($sent['id'] ?? $sent['file_id'] ?? '') : $sent));

        if ($id === '') {
            throw new StepFailed('There is no file here: the message carried none. An "If" on len(trigger.files) > 0 keeps this step for the messages that do.');
        }

        $got = $fetch($through, $id, $trigger);
        $ext = pathinfo($got['name'], PATHINFO_EXTENSION);

        return DriveSteps::keep($step, [
            'path' => $got['path'],
            // What its sender called it; a photo has no name, so it is named for when it came
            'name' => self::sentAs($trigger, $id) ?? $service.'-'.now()->format('Y-m-d-His').($ext !== '' ? ".{$ext}" : ''),
        ]);
    }

    /**
     * What a file was called by whoever sent it, when the message says: a
     * document's name on Telegram, a file's on LINE.
     *
     * @param  array<string, mixed>  $trigger
     */
    private static function sentAs(array $trigger, string $id): ?string
    {
        $raw = $trigger['raw'] ?? [];
        $message = $raw['message'] ?? $raw['edited_message'] ?? [];

        if (! is_array($message)) {
            return null;
        }

        if ((string) ($message['id'] ?? '') === $id && is_string($message['fileName'] ?? null)) {
            return $message['fileName'];
        }

        foreach (['document', 'video', 'audio', 'animation'] as $kind) {
            if (($message[$kind]['file_id'] ?? null) === $id && is_string($message[$kind]['file_name'] ?? null)) {
                return $message[$kind]['file_name'];
            }
        }

        return null;
    }
}
