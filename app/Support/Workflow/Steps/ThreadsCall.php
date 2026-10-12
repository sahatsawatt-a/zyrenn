<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\Meta;

/**
 * Threads' other calls, each a kind of step of its own (ServiceKinds names
 * them; this runs them): reposting, deleting, and reading -- the profile's
 * posts, a post's replies, and the profile itself.
 */
final class ThreadsCall implements Step
{
    private const NAME = 'Threads';

    public function run(StepContext $step): StepResult
    {
        $account = MetaApi::account($step, 'threads');

        return new StepResult(match ($step->node['type']) {
            'threads.repost' => self::repost($step, $account),
            'threads.delete' => self::delete($step, $account),
            'threads.posts' => self::listed($step, $account, Meta::THREADS.'/'.$account->setting('member').'/threads', 'posts'),
            'threads.replies' => self::listed($step, $account, Meta::THREADS.'/'.MetaApi::thing($step, 'post', 'post').'/replies', 'replies'),
            'threads.me' => self::me($account),

            default => throw new StepFailed("This app doesn't know the Threads step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @return array{id: string, of: string}
     */
    private static function repost(StepContext $step, Connection $account): array
    {
        $post = MetaApi::thing($step, 'post', 'post');

        $made = MetaApi::send($step, self::NAME, $post, fn () => MetaApi::as($account->secret('access_token'))->post(Meta::THREADS."/{$post}/repost"));

        return ['id' => (string) $made->json('id'), 'of' => $post];
    }

    /**
     * @return array{done: true, id: string}
     */
    private static function delete(StepContext $step, Connection $account): array
    {
        $post = MetaApi::thing($step, 'post', 'post');

        MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))->delete(Meta::THREADS."/{$post}"));

        return ['done' => true, 'id' => $post];
    }

    /**
     * The profile's posts, or the replies under one: Threads says them alike.
     *
     * @return array<string, mixed>
     */
    private static function listed(StepContext $step, Connection $account, string $from, string $as): array
    {
        $found = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get($from, ['fields' => 'id,text,username,permalink,timestamp,media_type', 'limit' => MetaApi::count($step, $as === 'posts' ? 10 : 25)]));

        $listed = array_map(fn (array $post) => [
            'id' => (string) ($post['id'] ?? ''),
            'text' => (string) ($post['text'] ?? ''),
            'from' => $post['username'] ?? null,
            'at' => $post['timestamp'] ?? null,
            'url' => $post['permalink'] ?? null,
        ], array_values(array_filter((array) $found->json('data'), 'is_array')));

        return $as === 'posts' ? ['posts' => $listed, 'first' => $listed[0] ?? null] : ['replies' => $listed, 'count' => count($listed)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function me(Connection $account): array
    {
        $who = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(Meta::THREADS.'/me', ['fields' => 'id,username,name,threads_profile_picture_url,threads_biography']));

        return [
            'id' => $who->json('id'),
            'username' => $who->json('username'),
            'name' => $who->json('name'),
            'picture' => $who->json('threads_profile_picture_url'),
            'about' => $who->json('threads_biography'),
        ];
    }
}
