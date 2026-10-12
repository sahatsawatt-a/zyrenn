<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Meta;
use Illuminate\Http\Client\Response;

/**
 * Facebook's other calls for a Page, each a kind of step of its own
 * (ServiceKinds names them; this runs them): commenting as the Page, liking
 * and taking a like back, deleting, and reading -- the Page's posts, what
 * people wrote under one, and the Page itself.
 */
final class FacebookCall implements Step
{
    private const NAME = 'Facebook';

    public function run(StepContext $step): StepResult
    {
        $page = MetaApi::account($step, 'facebook');
        $as = fn (int $timeout = 20) => MetaApi::as($page->secret('page_token'), $timeout);
        $on = fn (string $what = 'post or comment') => Meta::FACEBOOK.'/'.MetaApi::thing($step, 'post', $what);

        return new StepResult(match ($step->node['type']) {
            'facebook.comment' => self::comment($step, $page),
            'facebook.like' => self::done(fn () => $as()->post($on().'/likes')),
            'facebook.unlike' => self::done(fn () => $as()->delete($on().'/likes')),
            'facebook.delete' => self::done(fn () => $as()->delete($on())),
            'facebook.posts' => self::posts($step, $page),
            'facebook.comments' => self::comments($step, $page),
            'facebook.me' => self::me($page),

            default => throw new StepFailed("This app doesn't know the Facebook step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @param  callable(): Response  $ask
     * @return array{done: true}
     */
    private static function done(callable $ask): array
    {
        MetaApi::call(self::NAME, $ask);

        return ['done' => true];
    }

    /**
     * @return array{id: string, on: string}
     */
    private static function comment(StepContext $step, Connection $page): array
    {
        $on = MetaApi::thing($step, 'post', 'post or comment');
        $text = MetaApi::needs($step, 'text', 'comment: it came to nothing');

        $made = MetaApi::send($step, self::NAME, $on, fn () => MetaApi::as($page->secret('page_token'))
            ->post(Meta::FACEBOOK."/{$on}/comments", ['message' => $text]));

        return ['id' => (string) $made->json('id'), 'on' => $on];
    }

    /**
     * @return array{posts: list<array<string, mixed>>, first: array<string, mixed>|null}
     */
    private static function posts(StepContext $step, Connection $page): array
    {
        $found = MetaApi::call(self::NAME, fn () => MetaApi::as($page->secret('page_token'))
            ->get(Meta::FACEBOOK.'/'.$page->setting('page_id').'/feed', ['fields' => 'id,message,created_time,permalink_url', 'limit' => MetaApi::count($step)]));

        $posts = array_map(fn (array $post) => [
            'id' => (string) ($post['id'] ?? ''),
            'text' => (string) ($post['message'] ?? ''),
            'at' => $post['created_time'] ?? null,
            'url' => $post['permalink_url'] ?? null,
        ], array_values(array_filter((array) $found->json('data'), 'is_array')));

        return ['posts' => $posts, 'first' => $posts[0] ?? null];
    }

    /**
     * @return array{comments: list<array<string, mixed>>, count: int}
     */
    private static function comments(StepContext $step, Connection $page): array
    {
        $on = MetaApi::thing($step, 'post', 'post');
        $found = MetaApi::call(self::NAME, fn () => MetaApi::as($page->secret('page_token'))
            ->get(Meta::FACEBOOK."/{$on}/comments", ['fields' => 'id,message,created_time,from', 'order' => 'reverse_chronological', 'limit' => MetaApi::count($step, 25)]));

        $comments = array_map(fn (array $comment) => [
            'id' => (string) ($comment['id'] ?? ''),
            'text' => (string) ($comment['message'] ?? ''),
            'at' => $comment['created_time'] ?? null,
            // Facebook says who only to a Page that may know
            'from' => ['id' => $comment['from']['id'] ?? null, 'name' => $comment['from']['name'] ?? null],
        ], array_values(array_filter((array) $found->json('data'), 'is_array')));

        return ['comments' => $comments, 'count' => count($comments)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function me(Connection $page): array
    {
        $who = MetaApi::call(self::NAME, fn () => MetaApi::as($page->secret('page_token'))
            ->get(Meta::FACEBOOK.'/'.$page->setting('page_id'), ['fields' => 'id,name,link,fan_count,followers_count,picture{url}']));

        return [
            'id' => $who->json('id'),
            'name' => $who->json('name'),
            'url' => $who->json('link'),
            'likes' => $who->json('fan_count'),
            'followers' => $who->json('followers_count'),
            'picture' => $who->json('picture.data.url'),
        ];
    }
}
