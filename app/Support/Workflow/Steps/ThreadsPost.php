<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Connections\Meta;

/**
 * Post on Threads: words, alone or with a link, a picture, a video or
 * several to swipe through -- a post of its own, or, told which post it
 * answers, a reply under it. As on Instagram, a picture or a video is
 * fetched by Threads from the app, which has to be reachable from outside,
 * and the post is published once Threads has worked through it (Containers).
 *
 * Publishing is recorded and never simply repeated.
 */
final class ThreadsPost implements Step
{
    private const NAME = 'Threads';

    /** The most letters of a post. */
    private const MAX_LETTERS = 500;

    public function run(StepContext $step): StepResult
    {
        $account = MetaApi::account($step, 'threads');
        $as = fn (int $timeout = 60) => MetaApi::as($account->secret('access_token'), $timeout);
        $me = Meta::THREADS.'/'.$account->setting('member');
        $text = trim($step->text('text'));
        $several = ($step->setting('with') ?: 'none') === 'several';

        if (mb_strlen($text) > self::MAX_LETTERS) {
            throw new StepFailed('A Threads post is at most '.self::MAX_LETTERS.' letters, and this is '.number_format(mb_strlen($text)).'.');
        }

        return Containers::publish(
            $step,
            self::NAME,
            fn (array $parts) => (string) MetaApi::call(self::NAME, fn () => $as()->post("{$me}/threads", self::post($step, $text, $parts)))->json('id'),
            function (string $made) use ($as) {
                $stands = MetaApi::call(self::NAME, fn () => $as(20)->get(Meta::THREADS."/{$made}", ['fields' => 'status,error_message']));

                return [(string) $stands->json('status'), $stands->json('error_message')];
            },
            function (string $made) use ($step, $account, $as, $me) {
                $id = (string) MetaApi::send($step, self::NAME, (string) $account->setting('member'), fn () => $as()->post("{$me}/threads_publish", ['creation_id' => $made]))->json('id');

                return [
                    'id' => $id,
                    'url' => rescue(fn () => MetaApi::call(self::NAME, fn () => $as(20)->get(Meta::THREADS."/{$id}", ['fields' => 'permalink']))->json('permalink'), null, false),
                    'as' => $account->setting('member_name'),
                ];
            },
            // Each of several is handed over first: Threads makes no post of parts it does not yet know
            $several ? fn () => self::each($step, $account) : null,
        );
    }

    /**
     * What Threads is handed for the post -- of several, what Threads calls each.
     *
     * @param  list<string>  $parts
     * @return array<string, mixed>
     */
    private static function post(StepContext $step, string $text, array $parts): array
    {
        $with = (string) ($step->setting('with') ?: 'none');
        $said = array_filter([
            'text' => $text,
            // Under the post it answers, when it answers one
            'reply_to_id' => filled($step->setting('reply_to')) ? MetaApi::thing($step, 'reply_to', 'post') : null,
        ]);

        return match ($with) {
            'picture', 'video' => [...self::shown(DriveSteps::file($step, $with), $with === 'video'), ...$said],
            'several' => ['media_type' => 'CAROUSEL', 'children' => implode(',', $parts), ...$said],
            'link' => ['media_type' => 'TEXT', 'link_attachment' => self::link($step), ...self::worded($said)],
            default => ['media_type' => 'TEXT', ...self::worded($said)],
        };
    }

    /**
     * @param  array<string, mixed>  $said
     * @return array<string, mixed>
     */
    private static function worded(array $said): array
    {
        return isset($said['text']) ? $said : throw new StepFailed('The post came to nothing.');
    }

    /**
     * @return list<string>
     */
    private static function each(StepContext $step, Connection $account): array
    {
        $files = DriveSteps::files($step, 'files');

        if (count($files) < 2 || count($files) > 20) {
            throw new StepFailed('Several are from 2 to 20 pictures or videos, and these are '.count($files).'.');
        }

        $shown = array_map(fn (DriveFile $file) => self::shown($file, str_starts_with((string) $file->mime, 'video/')), $files);

        return array_map(fn (array $one) => (string) MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'), 60)
            ->post(Meta::THREADS.'/'.$account->setting('member').'/threads', [...$one, 'is_carousel_item' => true]))->json('id'), $shown);
    }

    /**
     * @return array<string, string>
     */
    private static function shown(DriveFile $file, bool $video): array
    {
        $fits = $video ? in_array($file->mime, ['video/mp4', 'video/quicktime'], true) : in_array($file->mime, ['image/jpeg', 'image/png'], true);

        if (! $fits) {
            throw new StepFailed("\"{$file->name}\" is not ".($video ? 'a video Threads takes: an MP4 or a MOV.' : 'a picture Threads takes: a JPEG or a PNG.'));
        }

        return $video
            ? ['media_type' => 'VIDEO', 'video_url' => DriveSteps::publicUrl($file)]
            : ['media_type' => 'IMAGE', 'image_url' => DriveSteps::publicUrl($file)];
    }

    private static function link(StepContext $step): string
    {
        $link = trim($step->text('link'));

        return preg_match('#^https?://#i', $link) === 1
            ? $link
            : throw new StepFailed($link === '' ? 'There is no link to post.' : 'The link is not an address: it starts with https://.');
    }
}
