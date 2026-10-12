<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Meta;

/**
 * Instagram's other calls, each a kind of step of its own (ServiceKinds
 * names them; this runs them): commenting, answering a comment, taking one
 * away, and reading -- the account's posts, a post's comments, and the
 * account itself.
 */
final class InstagramCall implements Step
{
    private const NAME = 'Instagram';

    public function run(StepContext $step): StepResult
    {
        $account = MetaApi::account($step, 'instagram');

        return new StepResult(match ($step->node['type']) {
            'instagram.comment' => self::write($step, $account, MetaApi::thing($step, 'post', 'post'), 'comments'),
            'instagram.reply' => self::write($step, $account, MetaApi::thing($step, 'comment', 'comment'), 'replies'),
            'instagram.comment_delete' => self::delete($step, $account),
            'instagram.posts' => self::posts($step, $account),
            'instagram.comments' => self::comments($step, $account),
            'instagram.me' => self::me($account),

            default => throw new StepFailed("This app doesn't know the Instagram step \"{$step->node['type']}\"."),
        });
    }

    /**
     * A comment under a post, or an answer under a comment.
     *
     * @return array{id: string, on: string}
     */
    private static function write(StepContext $step, Connection $account, string $on, string $as): array
    {
        $text = MetaApi::needs($step, 'text', 'comment: it came to nothing');

        $made = MetaApi::send($step, self::NAME, $on, fn () => MetaApi::as($account->secret('access_token'))
            ->post(Meta::INSTAGRAM."/{$on}/{$as}", ['message' => $text]));

        return ['id' => (string) $made->json('id'), 'on' => $on];
    }

    /**
     * @return array{done: true}
     */
    private static function delete(StepContext $step, Connection $account): array
    {
        $comment = MetaApi::thing($step, 'comment', 'comment');

        MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))->delete(Meta::INSTAGRAM."/{$comment}"));

        return ['done' => true];
    }

    /**
     * @return array{posts: list<array<string, mixed>>, first: array<string, mixed>|null}
     */
    private static function posts(StepContext $step, Connection $account): array
    {
        $found = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(Meta::INSTAGRAM.'/'.$account->setting('member').'/media', [
                'fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count',
                'limit' => MetaApi::count($step),
            ]));

        $posts = array_map(fn (array $post) => [
            'id' => (string) ($post['id'] ?? ''),
            'text' => (string) ($post['caption'] ?? ''),
            // FEED, REELS or STORY -- a reel is otherwise only a VIDEO
            'kind' => $post['media_product_type'] ?? $post['media_type'] ?? null,
            'at' => $post['timestamp'] ?? null,
            'url' => $post['permalink'] ?? null,
            'likes' => $post['like_count'] ?? null,
            'comments' => $post['comments_count'] ?? null,
        ], array_values(array_filter((array) $found->json('data'), 'is_array')));

        return ['posts' => $posts, 'first' => $posts[0] ?? null];
    }

    /**
     * @return array{comments: list<array<string, mixed>>, count: int}
     */
    private static function comments(StepContext $step, Connection $account): array
    {
        $on = MetaApi::thing($step, 'post', 'post');
        $found = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(Meta::INSTAGRAM."/{$on}/comments", ['fields' => 'id,text,username,timestamp,like_count', 'limit' => MetaApi::count($step, 25)]));

        $comments = array_map(fn (array $comment) => [
            'id' => (string) ($comment['id'] ?? ''),
            'text' => (string) ($comment['text'] ?? ''),
            'at' => $comment['timestamp'] ?? null,
            'from' => $comment['username'] ?? null,
            'likes' => $comment['like_count'] ?? null,
        ], array_values(array_filter((array) $found->json('data'), 'is_array')));

        return ['comments' => $comments, 'count' => count($comments)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function me(Connection $account): array
    {
        $who = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(Meta::INSTAGRAM.'/me', ['fields' => 'user_id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count']));

        return [
            'id' => $who->json('user_id'),
            'username' => $who->json('username'),
            'name' => $who->json('name'),
            'kind' => $who->json('account_type'),
            'picture' => $who->json('profile_picture_url'),
            'followers' => $who->json('followers_count'),
            'following' => $who->json('follows_count'),
            'posts' => $who->json('media_count'),
        ];
    }
}
