<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use Illuminate\Http\Client\Response;

/**
 * Post on LinkedIn: words on the member's own profile, alone or with one
 * thing more -- a link shown as a card, a picture or several, a video, a
 * document shown page by page, a poll. LinkedIn's call for a post, the
 * older one (ugcPosts) for what it has and the newer (rest/posts) for the
 * rest (LinkedInApi). What goes with the words is handed to LinkedIn first,
 * which is part of posting it: LinkedIn shows nothing it was not given for a
 * post.
 *
 * Recorded and never simply repeated, as any send is.
 */
final class LinkedInPost implements Step
{
    /** The most letters LinkedIn takes in a post's words. */
    public const MAX_LETTERS = 3000;

    private const PICTURE_MIMES = ['image/jpeg', 'image/png', 'image/gif'];

    private const DOCUMENT_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** The largest video LinkedIn takes in one piece, and the largest document. */
    private const MAX_VIDEO_BYTES = 200 * 1024 * 1024;

    private const MAX_DOCUMENT_BYTES = 100 * 1024 * 1024;

    /** How long a poll may stay open, as LinkedIn calls it. */
    public const POLL_LASTS = ['ONE_DAY' => 'A day', 'THREE_DAYS' => 'Three days', 'SEVEN_DAYS' => 'A week', 'FOURTEEN_DAYS' => 'Two weeks'];

    public function run(StepContext $step): StepResult
    {
        $account = LinkedInApi::account($step);
        $text = trim($step->text('text'));
        $with = (string) ($step->setting('with') ?: 'none');

        if (mb_strlen($text) > self::MAX_LETTERS) {
            throw new StepFailed('A LinkedIn post takes at most '.number_format(self::MAX_LETTERS).' letters, and this is '.number_format(mb_strlen($text)).'.');
        }

        $response = in_array($with, ['pictures', 'document', 'poll'], true)
            ? self::newer($step, $account, $text, $with)
            : self::older($step, $account, $text, $with);

        return new StepResult(self::gaveOut($response, $account));
    }

    /**
     * What a post made gives out: its id, where it is, and as whom.
     *
     * @return array{id: string, url: string|null, as: string|null}
     */
    public static function gaveOut(Response $response, Connection $account): array
    {
        $id = LinkedInApi::made($response);

        return [
            'id' => $id,
            'url' => $id === '' ? null : "https://www.linkedin.com/feed/update/{$id}/",
            'as' => $account->setting('member_name'),
        ];
    }

    /**
     * Who sees a post, as a step says. (A group's members alone is not among
     * them: LinkedIn has a place for a group in a post, and answers an
     * ordinary app that names one with a 500.)
     */
    public static function seenBy(StepContext $step): string
    {
        return $step->setting('visibility') === 'CONNECTIONS' ? 'CONNECTIONS' : 'PUBLIC';
    }

    /**
     * A post by the newer call: these words, and what the caller adds.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    public static function body(StepContext $step, Connection $account, string $text, array $more): array
    {
        return [
            'author' => LinkedInApi::author($account),
            'commentary' => LinkedInApi::little($text),
            'visibility' => self::seenBy($step),
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
            ...$more,
        ];
    }

    /**
     * Words alone, or with a link, a picture or a video.
     *
     * @throws StepFailed
     */
    private static function older(StepContext $step, Connection $account, string $text, string $with): Response
    {
        $media = match ($with) {
            'link' => ['ARTICLE', ['originalUrl' => self::link($step)]],
            'picture' => ['IMAGE', ['media' => self::upload($account, self::picture(DriveSteps::file($step, 'picture')), 'feedshare-image')]],
            'video' => ['VIDEO', ['media' => self::upload($account, self::video($step), 'feedshare-video')]],
            default => ['NONE', null],
        };

        if ($text === '' && $media[1] === null) {
            throw new StepFailed('The post came to nothing.');
        }

        $title = $media[1] === null ? '' : trim($step->text('title'));
        $about = $media[1] === null ? '' : trim($step->text('description'));
        $author = LinkedInApi::author($account);

        return LinkedInApi::send($step, $author, fn () => LinkedInApi::as($account)->post('https://api.linkedin.com/v2/ugcPosts', [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => ['com.linkedin.ugc.ShareContent' => [
                'shareCommentary' => ['text' => $text],
                'shareMediaCategory' => $media[0],
                ...$media[1] === null ? [] : ['media' => [[
                    'status' => 'READY',
                    ...$media[1],
                    ...$title === '' ? [] : ['title' => ['text' => mb_substr($title, 0, 200)]],
                    ...$about === '' ? [] : ['description' => ['text' => mb_substr($about, 0, 256)]],
                ]]],
            ]],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => self::seenBy($step)],
        ]));
    }

    /**
     * Words with several pictures, a document or a poll.
     *
     * @throws StepFailed
     */
    private static function newer(StepContext $step, Connection $account, string $text, string $with): Response
    {
        $content = match ($with) {
            'pictures' => ['multiImage' => ['images' => array_map(
                fn (DriveFile $picture) => ['id' => self::hand($account, $picture, 'images', 'image')],
                self::pictures($step),
            )]],
            'document' => ['media' => self::document($step, $account)],
            default => ['poll' => self::poll($step)],
        };

        return LinkedInApi::send($step, LinkedInApi::author($account), fn () => LinkedInApi::newer($account)
            ->post('https://api.linkedin.com/rest/posts', self::body($step, $account, $text, ['content' => $content])));
    }

    /**
     * @throws StepFailed
     */
    private static function link(StepContext $step): string
    {
        $link = trim($step->text('link'));

        return preg_match('#^https?://#i', $link) === 1
            ? $link
            : throw new StepFailed($link === '' ? 'There is no link to post.' : 'The link is not an address: it starts with https://.');
    }

    /**
     * @throws StepFailed
     */
    private static function picture(DriveFile $picture): DriveFile
    {
        return in_array($picture->mime, self::PICTURE_MIMES, true)
            ? $picture
            : throw new StepFailed("\"{$picture->name}\" is not a picture LinkedIn shows: it takes a JPEG, a PNG or a GIF.");
    }

    /**
     * @return list<DriveFile>
     *
     * @throws StepFailed
     */
    private static function pictures(StepContext $step): array
    {
        $pictures = array_map(self::picture(...), DriveSteps::files($step, 'pictures'));

        return count($pictures) >= 2 && count($pictures) <= 20
            ? $pictures
            : throw new StepFailed('Several pictures are from 2 to 20, and these are '.count($pictures).'. One alone goes as "A picture".');
    }

    /**
     * @throws StepFailed
     */
    private static function video(StepContext $step): DriveFile
    {
        $video = DriveSteps::file($step, 'video');

        return match (true) {
            ! str_starts_with((string) $video->mime, 'video/') => throw new StepFailed("\"{$video->name}\" is not a video."),
            $video->size > self::MAX_VIDEO_BYTES => throw new StepFailed('A video goes to LinkedIn in one piece of at most '.intdiv(self::MAX_VIDEO_BYTES, 1024 * 1024)." MB, and \"{$video->name}\" is larger."),
            default => $video,
        };
    }

    /**
     * A document handed over, as a post carries it: named by its title, or as it is called.
     *
     * @return array{title: string, id: string}
     *
     * @throws StepFailed
     */
    private static function document(StepContext $step, Connection $account): array
    {
        $document = DriveSteps::file($step, 'document');

        if (! in_array($document->mime, self::DOCUMENT_MIMES, true)) {
            throw new StepFailed("\"{$document->name}\" is not a document LinkedIn shows: it takes a PDF, a Word file or a PowerPoint.");
        }

        if ($document->size > self::MAX_DOCUMENT_BYTES) {
            throw new StepFailed('A document on LinkedIn is at most '.intdiv(self::MAX_DOCUMENT_BYTES, 1024 * 1024)." MB and 300 pages, and \"{$document->name}\" is larger.");
        }

        return [
            'title' => mb_substr(trim($step->text('title')) ?: $document->name, 0, 200),
            'id' => self::hand($account, $document, 'documents', 'document'),
        ];
    }

    /**
     * A poll: what is asked, from two to four answers, and how long it stays open.
     *
     * @return array<string, mixed>
     *
     * @throws StepFailed
     */
    private static function poll(StepContext $step): array
    {
        $question = trim($step->text('question'));
        $given = $step->template('answers');
        $answers = array_values(array_filter(array_map(
            fn ($answer) => trim((string) (is_scalar($answer) ? $answer : '')),
            is_array($given) ? $given : (preg_split('/\R/', (string) $given) ?: []),
        ), fn (string $answer) => $answer !== ''));

        $long = array_filter($answers, fn (string $answer) => mb_strlen($answer) > 30);

        throw_if($question === '', new StepFailed('The poll asks nothing.'));
        throw_if(mb_strlen($question) > 140, new StepFailed('A poll\'s question is at most 140 letters, and this is '.mb_strlen($question).'.'));
        throw_if(count($answers) < 2 || count($answers) > 4, new StepFailed('A poll has from 2 to 4 answers, one on a line, and this has '.count($answers).'.'));
        throw_if($long !== [], new StepFailed('A poll\'s answer is at most 30 letters, and "'.mb_strimwidth((string) reset($long), 0, 40, '…').'" is longer.'));

        $lasts = (string) $step->setting('lasts');

        return [
            'question' => $question,
            'options' => array_map(fn (string $answer) => ['text' => $answer], $answers),
            'settings' => ['duration' => isset(self::POLL_LASTS[$lasts]) ? $lasts : 'THREE_DAYS'],
        ];
    }

    /**
     * Hand LinkedIn a picture or a video for a post by the older call: it
     * says where to put it, and names it for the post to carry.
     *
     * @throws StepFailed
     */
    private static function upload(Connection $account, DriveFile $file, string $recipe): string
    {
        $place = LinkedInApi::call(fn () => LinkedInApi::as($account)->post('https://api.linkedin.com/v2/assets?action=registerUpload', [
            'registerUploadRequest' => [
                'recipes' => ["urn:li:digitalmediaRecipe:{$recipe}"],
                'owner' => LinkedInApi::author($account),
                'serviceRelationships' => [['relationshipType' => 'OWNER', 'identifier' => 'urn:li:userGeneratedContent']],
            ],
        ]));

        // Its name has dots of its own, so it is not read by a dotted path
        $how = $place->json('value.uploadMechanism')['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest'] ?? [];
        $to = $how['uploadUrl'] ?? null;
        $asset = $place->json('value.asset');

        if (! is_string($to) || ! is_string($asset)) {
            throw new StepFailed("LinkedIn did not say where to put \"{$file->name}\".");
        }

        LinkedInApi::put($account, $to, $file, array_filter((array) ($how['headers'] ?? []), is_string(...)), asMember: $recipe !== 'feedshare-video');

        return $asset;
    }

    /**
     * Hand LinkedIn a picture or a document for a post by the newer call.
     *
     * @param  string  $what  the call's own name for them: "images", "documents"
     * @param  string  $named  where its answer names the one made: "image", "document"
     *
     * @throws StepFailed
     */
    private static function hand(Connection $account, DriveFile $file, string $what, string $named): string
    {
        $place = LinkedInApi::call(fn () => LinkedInApi::newer($account)->post("https://api.linkedin.com/rest/{$what}?action=initializeUpload", [
            'initializeUploadRequest' => ['owner' => LinkedInApi::author($account)],
        ]));

        $to = $place->json('value.uploadUrl');
        $id = $place->json("value.{$named}");

        if (! is_string($to) || ! is_string($id)) {
            throw new StepFailed("LinkedIn did not say where to put \"{$file->name}\".");
        }

        LinkedInApi::put($account, $to, $file);

        return $id;
    }
}
