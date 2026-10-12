<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;

/**
 * WhatsApp's other calls, each a kind of step of its own (ServiceKinds
 * names them; this runs them): a file from the Drive, a template -- the
 * only thing that reaches someone outside the 24 hours after their last
 * message -- a place, a reaction, and marking a message read.
 */
final class WhatsAppCall implements Step
{
    private const MB = 1024 * 1024;

    /** What WhatsApp shows as itself, each with its kinds and its most bytes; anything else goes as a document. */
    private const SHOWN = [
        'image' => [['image/jpeg', 'image/png'], 5 * self::MB],
        'video' => [['video/mp4', 'video/3gpp'], 16 * self::MB],
        'audio' => [['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'], 16 * self::MB],
    ];

    private const MAX_DOCUMENT_BYTES = 100 * self::MB;

    public function run(StepContext $step): StepResult
    {
        $number = WhatsAppApi::number($step);

        return new StepResult(match ($step->node['type']) {
            'whatsapp.send_file' => self::file($step, $number),
            'whatsapp.template' => self::template($step, $number),
            'whatsapp.location' => self::location($step, $number),
            'whatsapp.react' => WhatsAppApi::send($step, $number, WhatsAppApi::to($step), ['type' => 'reaction', 'reaction' => [
                'message_id' => MetaApi::needs($step, 'message', 'message to react to: its id, like {{ trigger.reply.to }}'),
                // Left empty, a reaction made before is taken back
                'emoji' => trim($step->text('emoji')),
            ]]),
            'whatsapp.read' => self::read($step, $number),

            default => throw new StepFailed("This app doesn't know the WhatsApp step \"{$step->node['type']}\"."),
        });
    }

    /**
     * A file of the Drive's: handed to WhatsApp first, then sent by what
     * WhatsApp calls it -- shown as a picture, a video or a recording where
     * it is one WhatsApp shows, as a document otherwise.
     *
     * @return array<string, mixed>
     */
    private static function file(StepContext $step, Connection $number): array
    {
        $to = WhatsAppApi::to($step);
        $file = DriveSteps::file($step);
        $as = self::shownAs($file);
        $caption = mb_substr(trim($step->text('text')), 0, 1024);
        $bytes = fopen(DriveSteps::path($file), 'r');

        if ($bytes === false) {
            throw new StepFailed("\"{$file->name}\" is in the Drive, but could not be read.");
        }

        $id = MetaApi::call(WhatsAppApi::NAME, fn () => MetaApi::as($number->secret('access_token'), 300)
            ->attach('file', $bytes, $file->name, ['Content-Type' => (string) $file->mime])
            ->post(WhatsAppApi::at($number, 'media'), ['messaging_product' => 'whatsapp', 'type' => (string) $file->mime]))->json('id');

        return [...WhatsAppApi::send($step, $number, $to, ['type' => $as, $as => array_filter([
            'id' => (string) $id,
            // A recording carries no words
            'caption' => $as === 'audio' ? null : $caption,
            'filename' => $as === 'document' ? $file->name : null,
        ])]), 'file' => $file->ref_id];
    }

    /**
     * @throws StepFailed when it is more than WhatsApp carries at all
     */
    private static function shownAs(DriveFile $file): string
    {
        foreach (self::SHOWN as $as => [$mimes, $most]) {
            if (in_array($file->mime, $mimes, true) && $file->size <= $most) {
                return $as;
            }
        }

        return $file->size <= self::MAX_DOCUMENT_BYTES
            ? 'document'
            : throw new StepFailed('WhatsApp carries files of at most '.intdiv(self::MAX_DOCUMENT_BYTES, self::MB)." MB, and \"{$file->name}\" is larger.");
    }

    /**
     * A template the business has had approved, with the values its words
     * leave room for, in order.
     *
     * @return array<string, mixed>
     */
    private static function template(StepContext $step, Connection $number): array
    {
        $given = $step->template('values');
        $values = array_values(array_filter(array_map(
            fn ($value) => trim((string) (is_scalar($value) ? $value : '')),
            is_array($given) ? $given : (preg_split('/\R/', (string) $given) ?: []),
        ), fn (string $value) => $value !== ''));

        return WhatsAppApi::send($step, $number, WhatsAppApi::to($step), ['type' => 'template', 'template' => [
            'name' => MetaApi::needs($step, 'name', 'template: its name, as WhatsApp Manager lists it'),
            'language' => ['code' => trim($step->text('language')) ?: 'en_US'],
            ...$values === [] ? [] : ['components' => [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $values),
            ]]],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function location(StepContext $step, Connection $number): array
    {
        $lat = $step->template('lat');
        $lng = $step->template('lng');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw new StepFailed('A place is two numbers, its latitude and its longitude.');
        }

        return WhatsAppApi::send($step, $number, WhatsAppApi::to($step), ['type' => 'location', 'location' => array_filter([
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'name' => trim($step->text('name')),
            'address' => trim($step->text('address')),
        ], fn ($part) => $part !== '')]);
    }

    /**
     * @return array{done: true}
     */
    private static function read(StepContext $step, Connection $number): array
    {
        $message = MetaApi::needs($step, 'message', 'message to mark: its id, like {{ trigger.reply.to }}');

        MetaApi::call(WhatsAppApi::NAME, fn () => MetaApi::as($number->secret('access_token'))->post(WhatsAppApi::at($number, 'messages'), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $message,
            // "typing…" under it, until an answer comes or 25 seconds pass
            ...$step->setting('typing') === 'yes' ? ['typing_indicator' => ['type' => 'text']] : [],
        ]));

        return ['done' => true];
    }
}
