<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Connections\Meta;

/**
 * Post on Instagram, as a professional account: a picture, several pictures
 * and videos to swipe through, a reel, or a story. Instagram is not sent
 * the file; it is handed an address and fetches it, so the app has to be
 * reachable from outside, and a video is published once Instagram has
 * worked through it (Containers).
 *
 * Publishing is recorded and never simply repeated. Instagram lets an app
 * publish, and not take a post down again.
 */
final class InstagramPost implements Step
{
    private const NAME = 'Instagram';

    /** The most letters of a caption. */
    private const MAX_CAPTION = 2200;

    public function run(StepContext $step): StepResult
    {
        $account = MetaApi::account($step, 'instagram');
        $as = fn (int $timeout = 60) => MetaApi::as($account->secret('access_token'), $timeout);
        $me = Meta::INSTAGRAM.'/'.$account->setting('member');
        $caption = trim($step->text('caption'));

        if (mb_strlen($caption) > self::MAX_CAPTION) {
            throw new StepFailed('An Instagram caption is at most '.number_format(self::MAX_CAPTION).' letters, and this is '.number_format(mb_strlen($caption)).'.');
        }

        return Containers::publish(
            $step,
            self::NAME,
            fn () => (string) MetaApi::call(self::NAME, fn () => $as()->post("{$me}/media", self::post($step, $account, $caption)))->json('id'),
            function (string $made) use ($as) {
                $stands = MetaApi::call(self::NAME, fn () => $as(20)->get(Meta::INSTAGRAM."/{$made}", ['fields' => 'status_code,status']));

                return [(string) $stands->json('status_code'), $stands->json('status')];
            },
            function (string $made) use ($step, $account, $as, $me) {
                $id = (string) MetaApi::send($step, self::NAME, (string) $account->setting('member'), fn () => $as()->post("{$me}/media_publish", ['creation_id' => $made]))->json('id');

                return [
                    'id' => $id,
                    // Where it is, asked after: a post that is up is not failed for want of its address
                    'url' => rescue(fn () => MetaApi::call(self::NAME, fn () => $as(20)->get(Meta::INSTAGRAM."/{$id}", ['fields' => 'permalink']))->json('permalink'), null, false),
                    'as' => $account->setting('member_name'),
                ];
            },
        );
    }

    /**
     * What Instagram is handed for the post: what it is, where its file is,
     * and its caption.
     *
     * @return array<string, mixed>
     */
    private static function post(StepContext $step, Connection $account, string $caption): array
    {
        $what = (string) ($step->setting('what') ?: 'picture');
        $with = array_filter(['caption' => $caption]);

        return match ($what) {
            'reel' => ['media_type' => 'REELS', 'video_url' => DriveSteps::publicUrl(self::video(DriveSteps::file($step, 'video'))), ...$with],
            'story' => ['media_type' => 'STORIES', ...self::shown(DriveSteps::file($step, 'story'))],
            'several' => ['media_type' => 'CAROUSEL', 'children' => implode(',', self::each($step, $account)), ...$with],
            default => [...self::shown(self::picture(DriveSteps::file($step, 'picture'))), ...$with],
        };
    }

    /**
     * Each of several, handed over by itself for the one post to hold.
     *
     * @return list<string>
     */
    private static function each(StepContext $step, Connection $account): array
    {
        $files = DriveSteps::files($step, 'files');

        if (count($files) < 2 || count($files) > 10) {
            throw new StepFailed('Several are from 2 to 10 pictures or videos, and these are '.count($files).'.');
        }

        // Every one is looked at before any is handed over
        $shown = array_map(self::shown(...), $files);

        return array_map(fn (array $one) => (string) MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'), 60)
            ->post(Meta::INSTAGRAM.'/'.$account->setting('member').'/media', [...$one, 'is_carousel_item' => true]))->json('id'), $shown);
    }

    /**
     * A picture or a video as Instagram is told of it: where to fetch it.
     *
     * @return array<string, string>
     */
    private static function shown(DriveFile $file): array
    {
        return str_starts_with((string) $file->mime, 'video/')
            ? ['media_type' => 'VIDEO', 'video_url' => DriveSteps::publicUrl(self::video($file))]
            : ['image_url' => DriveSteps::publicUrl(self::picture($file))];
    }

    private static function picture(DriveFile $picture): DriveFile
    {
        return $picture->mime === 'image/jpeg'
            ? $picture
            : throw new StepFailed("\"{$picture->name}\" is not a picture Instagram takes: only a JPEG.");
    }

    private static function video(DriveFile $video): DriveFile
    {
        return in_array($video->mime, ['video/mp4', 'video/quicktime'], true)
            ? $video
            : throw new StepFailed("\"{$video->name}\" is not a video Instagram takes: an MP4 or a MOV.");
    }
}
