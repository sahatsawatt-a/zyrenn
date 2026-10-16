<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Connections\Line;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * What every LINE step shares: the channel, a call to the Messaging API
 * with LINE's answer read the same way each time, and a message made up
 * from a step's settings -- LINE's reply, push, multicast and broadcast
 * calls all carry the same kinds of message.
 */
final class LineApi
{
    /** How what a button does starts, when it is an address to open. */
    public const ADDRESS = '^(https?://|tel:|line://)';

    private const MAX_PICTURE_BYTES = 10 * 1024 * 1024;

    private const MAX_MEDIA_BYTES = 200 * 1024 * 1024;

    /**
     * How long the address of a picture or a video sent on LINE stays good.
     * LINE keeps no copy: each phone fetches it from this app when it shows
     * the message, and again later if it has let go of it -- so the link has
     * to outlast the conversation, and the app has to stay reachable.
     */
    private const LINK_MINUTES = 60 * 24 * 30;

    /**
     * The step's channel: one of the owner's, or the one the run's chat is linked through.
     *
     * @throws StepFailed
     */
    public static function channel(StepContext $step): Connection
    {
        return References::connectionFor($step->owner(), (string) $step->setting('connection'), ['line'], $step->run->trigger_data)
            ?? throw new StepFailed($step->setting('connection') === References::LINKED
                ? 'This run did not start from a chat linked to a LINE channel.'
                : 'Its channel is gone, or is not this workflow\'s.');
    }

    /**
     * A setting that has to come to something: a user's id, a token.
     *
     * @throws StepFailed
     */
    public static function needs(StepContext $step, string $setting, string $what): string
    {
        $value = trim($step->text($setting));

        return $value !== '' ? $value : throw new StepFailed("There is no {$what}.");
    }

    /**
     * A call that asks, or sets something to what it would be anyway.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> what LINE answered
     *
     * @throws StepFailed
     */
    public static function call(Connection $channel, string $method, string $path, array $body = []): array
    {
        $response = self::answer($channel, fn (PendingRequest $request) => $request->send($method, Line::api($path), $method === 'GET' || $body === [] ? [] : ['json' => $body]));

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * What LINE has at an address, or null when it has no such thing: a
     * person with no rich menu of their own, a name no menu goes by.
     *
     * @param  list<int>  $none  what LINE answers with when there is none
     * @return array<string, mixed>|null
     *
     * @throws StepFailed
     */
    public static function find(Connection $channel, string $path, array $none = [404]): ?array
    {
        $response = self::answer($channel, fn (PendingRequest $request) => $request->get(Line::api($path)), $none);

        return ! $response->successful() ? null : (is_array($response->json()) ? $response->json() : []);
    }

    /**
     * A file's bytes put to LINE, which takes them at another address than
     * everything else.
     *
     * @throws StepFailed
     */
    public static function upload(Connection $channel, string $path, string $bytes, string $mime): void
    {
        self::answer($channel, fn (PendingRequest $request) => $request->timeout(60)->withBody($bytes, $mime)->post(Line::data($path)));
    }

    /**
     * LINE's answer to one request, read the same way each time: busy is
     * tried again, a refusal is passed on as LINE worded it -- with the
     * part of what was sent it objects to, when it names one.
     *
     * @param  callable(PendingRequest): Response  $ask
     * @param  list<int>  $allowed  statuses that are an answer here, not a refusal
     *
     * @throws StepFailed
     */
    private static function answer(Connection $channel, callable $ask, array $allowed = []): Response
    {
        try {
            $response = $ask(Line::http($channel));
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach LINE.', retryable: true);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new StepFailed("LINE is busy ({$response->status()}).", retryable: true, retryAfter: 30);
        }

        if (! $response->successful() && ! in_array($response->status(), $allowed, true)) {
            $detail = $response->json('details.0');
            $about = is_array($detail) ? trim(($detail['property'] ?? '').' '.($detail['message'] ?? '')) : '';

            throw new StepFailed('LINE said: '.($response->json('message') ?? "{$response->status()}.").($about === '' ? '' : " ({$about})"));
        }

        return $response;
    }

    /**
     * The message a step's settings make up, as LINE takes it: of the kind
     * chosen, with the quick replies under it and the message it quotes.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StepFailed
     */
    public static function messages(StepContext $step): array
    {
        $messages = match ((string) ($step->setting('kind') ?: 'text')) {
            'sticker' => [[
                'type' => 'sticker',
                'packageId' => self::needs($step, 'package', 'sticker package'),
                'stickerId' => self::needs($step, 'sticker', 'sticker'),
            ]],
            // A place, and after it whatever was written with it
            'location' => [self::place($step), ...($said = trim($step->text('text'))) === '' ? [] : [['type' => 'text', 'text' => mb_substr($said, 0, 5000)]]],
            'video' => [[
                'type' => 'video',
                'originalContentUrl' => self::fetched($step, 'media', ['video/mp4'], 'an MP4 video'),
                'previewImageUrl' => self::fetched($step, 'preview', ['image/jpeg', 'image/png'], 'a JPEG or PNG picture to show before it plays'),
            ]],
            'audio' => [[
                'type' => 'audio',
                'originalContentUrl' => self::fetched($step, 'media', ['audio/mpeg', 'audio/mp4', 'audio/x-m4a'], 'an MP3 or M4A recording'),
                'duration' => max(1, (int) round((float) self::needs($step, 'seconds', 'length for the recording, in seconds') * 1000)),
            ]],
            'flex' => [self::flex($step)],
            'buttons' => [self::template($step, [
                'type' => 'buttons',
                'title' => mb_substr(trim($step->text('title')), 0, 40) ?: null,
                'text' => mb_substr(self::needs($step, 'text', 'message over the buttons'), 0, 160),
                'actions' => self::actions($step, 'choices', 4) ?: throw new StepFailed('Name at least one button.'),
            ])],
            'confirm' => [self::template($step, [
                'type' => 'confirm',
                'text' => mb_substr(self::needs($step, 'text', 'question to ask'), 0, 240),
                'actions' => [
                    self::action(trim($step->text('yes')) ?: 'Yes', ''),
                    self::action(trim($step->text('no')) ?: 'No', ''),
                ],
            ])],
            default => self::words($step),
        };

        $quick = self::actions($step, 'quick', 13);

        if ($quick !== []) {
            $messages[count($messages) - 1]['quickReply'] = ['items' => array_map(fn (array $action) => ['type' => 'action', 'action' => $action], $quick)];
        }

        return $messages;
    }

    /**
     * Words, a picture from the Drive, or both: the picture first.
     *
     * @return list<array<string, mixed>>
     */
    private static function words(StepContext $step): array
    {
        $text = trim($step->text('text'));
        $file = filled($step->setting('file')) ? DriveSteps::file($step) : null;

        if ($text === '' && $file === null) {
            throw new StepFailed('The message came to nothing.');
        }

        $quote = trim($step->text('quote'));

        return [
            ...$file === null ? [] : [self::picture($file)],
            ...$text === '' ? [] : [array_filter(['type' => 'text', 'text' => mb_substr($text, 0, 5000), 'quoteToken' => $quote], fn ($part) => $part !== '')],
        ];
    }

    /**
     * A picture as LINE takes one: an address for it, and one for the small
     * version shown in the chat -- the same, there being no smaller one.
     *
     * @return array<string, string>
     */
    private static function picture(DriveFile $file): array
    {
        if (! in_array($file->mime, ['image/jpeg', 'image/png'], true)) {
            throw new StepFailed("LINE takes only a JPEG or PNG picture this way, and \"{$file->name}\" is neither.");
        }

        if ($file->size > self::MAX_PICTURE_BYTES) {
            throw new StepFailed('LINE takes pictures of at most '.intdiv(self::MAX_PICTURE_BYTES, 1024 * 1024)." MB, and \"{$file->name}\" is larger.");
        }

        DriveSteps::path($file);
        $url = DriveSteps::publicUrl($file, self::LINK_MINUTES);

        return ['type' => 'image', 'originalContentUrl' => $url, 'previewImageUrl' => $url];
    }

    /**
     * An address LINE fetches one of the Drive's files from, when the file
     * is of a kind LINE takes.
     *
     * @param  list<string>  $mimes
     */
    private static function fetched(StepContext $step, string $setting, array $mimes, string $kind): string
    {
        $file = DriveSteps::file($step, $setting);

        if (! in_array($file->mime, $mimes, true)) {
            throw new StepFailed("LINE takes {$kind} here, and \"{$file->name}\" is not one.");
        }

        if ($file->size > self::MAX_MEDIA_BYTES) {
            throw new StepFailed('LINE takes files of at most '.intdiv(self::MAX_MEDIA_BYTES, 1024 * 1024)." MB, and \"{$file->name}\" is larger.");
        }

        DriveSteps::path($file);

        return DriveSteps::publicUrl($file, self::LINK_MINUTES);
    }

    /**
     * @return array<string, mixed>
     */
    private static function place(StepContext $step): array
    {
        $lat = self::needs($step, 'lat', 'latitude');
        $lng = self::needs($step, 'lng', 'longitude');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw new StepFailed('A place is two numbers: its latitude and its longitude.');
        }

        $title = mb_substr(trim($step->text('title')) ?: 'A place', 0, 100);

        return [
            'type' => 'location',
            'title' => $title,
            'address' => mb_substr(trim($step->text('address')) ?: $title, 0, 100),
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
        ];
    }

    /**
     * A card laid out in LINE's Flex form: the JSON of a bubble or a carousel.
     *
     * @return array<string, mixed>
     */
    private static function flex(StepContext $step): array
    {
        $given = $step->template('flex');
        $contents = is_array($given) ? $given : json_decode((string) $given, true);

        if (! is_array($contents) || ! in_array($contents['type'] ?? null, ['bubble', 'carousel'], true)) {
            throw new StepFailed('A Flex card is the JSON of a "bubble" or a "carousel", as LINE\'s Flex Message Simulator gives it.');
        }

        return [
            'type' => 'flex',
            'altText' => mb_substr(trim($step->text('alt')) ?: 'A message', 0, 400),
            'contents' => $contents,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private static function template(StepContext $step, array $template): array
    {
        return [
            'type' => 'template',
            // What a notification, or a LINE too old to draw it, says instead
            'altText' => mb_substr(trim($step->text('alt')) ?: (string) $template['text'], 0, 400),
            'template' => array_filter($template, fn ($part) => $part !== null),
        ];
    }

    /**
     * What a step's named values do when tapped: each name is what the
     * button says, its value what tapping it does. In a rich menu, a value
     * may also turn to another of the channel's menus.
     *
     * @return list<array<string, string>>
     */
    public static function actions(StepContext $step, string $setting, int $most, bool $menu = false): array
    {
        $actions = [];

        foreach ((array) $step->setting($setting) as $field) {
            $label = trim((string) ($field['name'] ?? ''));

            if ($label !== '') {
                $actions[] = self::action($label, trim($step->text("{$setting}.{$label}", (string) ($field['value'] ?? ''))), $menu);
            }
        }

        if (count($actions) > $most) {
            throw new StepFailed("LINE shows at most {$most} of these, and there are ".count($actions).'.');
        }

        return $actions;
    }

    /**
     * One thing to tap: an address opens ("link:" before it says it is one,
     * where a formula gives it); "postback:" and some data comes back to
     * the workflow unseen (trigger.text), the label shown as said;
     * in a rich menu, "menu:" and the short name of another menu turns to
     * that one, as a tab does; anything else is said in the chat as the
     * person's own message -- the label itself, when there is nothing else.
     *
     * @return array<string, string>
     */
    private static function action(string $label, string $does, bool $menu = false): array
    {
        $label = mb_substr($label, 0, 20);

        return match (true) {
            preg_match('#'.self::ADDRESS.'#i', $does) === 1 => ['type' => 'uri', 'label' => $label, 'uri' => $does],
            str_starts_with($does, 'link:') => ['type' => 'uri', 'label' => $label, 'uri' => trim(substr($does, 5))],
            str_starts_with($does, 'postback:') => ['type' => 'postback', 'label' => $label, 'data' => mb_substr(trim(substr($does, 9)), 0, 300), 'displayText' => $label],
            $menu && str_starts_with($does, 'menu:') => ['type' => 'richmenuswitch', 'label' => $label, 'richMenuAliasId' => trim(substr($does, 5)), 'data' => 'menu:'.trim(substr($does, 5))],
            default => ['type' => 'message', 'label' => $label, 'text' => mb_substr($does !== '' ? $does : $label, 0, 300)],
        };
    }
}
