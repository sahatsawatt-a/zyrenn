<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\InstagramSignIn;
use App\Support\Connections\Meta;

/**
 * Instagram's other calls, each a kind of step of its own (ServiceKinds
 * names them; this runs them): commenting, answering a comment, taking one
 * away, taking a post down, and reading -- the account's posts, a post's
 * comments, and the account itself.
 *
 * Instagram answers where the connection signed in: at its own address, or
 * at Facebook's (InstagramSignIn::graph). Only there may a post be deleted.
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
            'instagram.delete' => self::remove($step, $account),
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
            ->post(InstagramSignIn::graph($account)."/{$on}/{$as}", ['message' => $text]));

        return ['id' => (string) $made->json('id'), 'on' => $on];
    }

    /**
     * @return array{done: true}
     */
    private static function delete(StepContext $step, Connection $account): array
    {
        $comment = MetaApi::thing($step, 'comment', 'comment');

        MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))->delete(InstagramSignIn::graph($account)."/{$comment}"));

        return ['done' => true];
    }

    /**
     * Take a post, a reel or a story down, or several as the one post they
     * are. Instagram lets an app do so only by way of Facebook, so a
     * connection signed in with Instagram is told before anything is asked.
     *
     * @return array{done: true}
     */
    private static function remove(StepContext $step, Connection $account): array
    {
        $post = MetaApi::thing($step, 'post', 'post');

        if (! InstagramSignIn::byFacebook($account)) {
            throw new StepFailed("Instagram lets an app delete a post only by way of Facebook, and \"{$account->name}\" signs in with Instagram: set \"Sign in with\" to Facebook on the connection, and sign in again.");
        }

        MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))->delete(Meta::FACEBOOK."/{$post}"));

        return ['done' => true];
    }

    /**
     * @return array{posts: list<array<string, mixed>>, first: array<string, mixed>|null}
     */
    private static function posts(StepContext $step, Connection $account): array
    {
        $found = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(InstagramSignIn::graph($account).'/'.$account->setting('member').'/media', [
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
            ->get(InstagramSignIn::graph($account)."/{$on}/comments", ['fields' => 'id,text,username,timestamp,like_count', 'limit' => MetaApi::count($step, 25)]));

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
        // Its own login names the account user_id and says what kind it is; Facebook's does neither
        $who = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(InstagramSignIn::account($account), ['fields' => (InstagramSignIn::byFacebook($account) ? 'id' : 'user_id,account_type').',username,name,profile_picture_url,followers_count,follows_count,media_count']));

        return [
            'id' => $who->json('user_id') ?? $who->json('id'),
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
