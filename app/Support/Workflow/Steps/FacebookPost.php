<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Connections\Meta;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Post on a Facebook Page: words, alone or with a link shown as a card, a
 * picture or several, or a video -- as the Page, which is all Facebook lets
 * an app post as. Each is the Page's own call for it: its feed for words, a
 * link and several pictures, its photos for one, its videos for a video.
 *
 * Recorded and never simply repeated, as any send is.
 */
final class FacebookPost implements Step
{
    private const NAME = 'Facebook';

    public function run(StepContext $step): StepResult
    {
        $page = MetaApi::account($step, 'facebook');
        $id = (string) $page->setting('page_id');
        $text = trim($step->text('text'));
        $with = (string) ($step->setting('with') ?: 'none');

        $made = match ($with) {
            'picture' => self::picture($step, $page, $text),
            'pictures' => self::feed($step, $page, array_filter([
                'message' => $text,
                'attached_media' => array_map(fn (string $photo) => ['media_fbid' => $photo], self::kept($step, $page)),
            ])),
            'video' => self::video($step, $page, $text),
            default => self::feed($step, $page, array_filter([
                'message' => $text,
                'link' => $with === 'link' ? self::link($step) : null,
            ]) ?: throw new StepFailed('The post came to nothing.')),
        };

        // A picture's answer names the post it made beside itself; a video is its own
        $post = (string) ($made->json('post_id') ?? $made->json('id'));

        return new StepResult([
            'id' => $post,
            'url' => $with === 'video' ? "https://www.facebook.com/{$id}/videos/{$post}" : "https://www.facebook.com/{$post}",
            'as' => $page->setting('page_name'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private static function feed(StepContext $step, Connection $page, array $post): Response
    {
        $id = (string) $page->setting('page_id');

        return MetaApi::send($step, self::NAME, $id, fn () => MetaApi::as($page->secret('page_token'))->post(Meta::FACEBOOK."/{$id}/feed", $post));
    }

    private static function picture(StepContext $step, Connection $page, string $text): Response
    {
        $picture = self::image(DriveSteps::file($step, 'picture'));
        $id = (string) $page->setting('page_id');

        return MetaApi::send($step, self::NAME, $id, fn () => self::with($page, $picture, 120)
            ->post(Meta::FACEBOOK."/{$id}/photos", array_filter(['message' => $text])));
    }

    /**
     * Several pictures, each handed to the Page unseen, for one post to show together.
     *
     * @return list<string>
     */
    private static function kept(StepContext $step, Connection $page): array
    {
        $pictures = array_map(self::image(...), DriveSteps::files($step, 'pictures'));
        $id = (string) $page->setting('page_id');

        if (count($pictures) < 2 || count($pictures) > 10) {
            throw new StepFailed('Several pictures are from 2 to 10, and these are '.count($pictures).'. One alone goes as "A picture".');
        }

        return array_map(fn (DriveFile $picture) => (string) MetaApi::call(self::NAME, fn () => self::with($page, $picture, 120)
            ->post(Meta::FACEBOOK."/{$id}/photos", ['published' => 'false']))->json('id'), $pictures);
    }

    private static function video(StepContext $step, Connection $page, string $text): Response
    {
        $video = DriveSteps::file($step, 'video');
        $id = (string) $page->setting('page_id');

        if (! str_starts_with((string) $video->mime, 'video/')) {
            throw new StepFailed("\"{$video->name}\" is not a video.");
        }

        return MetaApi::send($step, self::NAME, $id, fn () => self::with($page, $video, 600)
            ->post('https://graph-video.facebook.com/'.Meta::VERSION."/{$id}/videos", array_filter([
                'description' => $text,
                'title' => trim($step->text('title')),
            ])));
    }

    /**
     * A request as the Page that carries a file of the Drive's.
     */
    private static function with(Connection $page, DriveFile $file, int $timeout): PendingRequest
    {
        $bytes = fopen(DriveSteps::path($file), 'r');

        return $bytes === false
            ? throw new StepFailed("\"{$file->name}\" is in the Drive, but could not be read.")
            : MetaApi::as($page->secret('page_token'), $timeout)->attach('source', $bytes, $file->name);
    }

    private static function image(DriveFile $picture): DriveFile
    {
        return in_array($picture->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $picture
            : throw new StepFailed("\"{$picture->name}\" is not a picture Facebook shows: it takes a JPEG, a PNG, a GIF or a WebP.");
    }

    private static function link(StepContext $step): string
    {
        $link = trim($step->text('link'));

        return preg_match('#^https?://#i', $link) === 1
            ? $link
            : throw new StepFailed($link === '' ? 'There is no link to post.' : 'The link is not an address: it starts with https://.');
    }
}
