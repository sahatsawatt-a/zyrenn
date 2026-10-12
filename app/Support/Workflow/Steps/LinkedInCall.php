<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\LinkedInSignIn;

/**
 * LinkedIn's other calls, each a kind of step of its own (ServiceKinds
 * names them; this runs them): reposting, deleting a post, commenting and
 * taking a comment away, liking and taking a like back, and asking who the
 * member is.
 *
 * A repost and a comment leave something new, so they are recorded and
 * never simply made twice. The rest set something to what it would be
 * anyway, or only ask, and can be made again.
 */
final class LinkedInCall implements Step
{
    /** The most letters LinkedIn takes in a comment. */
    private const MAX_COMMENT = 1250;

    public function run(StepContext $step): StepResult
    {
        $account = LinkedInApi::account($step);

        return new StepResult(match ($step->node['type']) {
            'linkedin.repost' => self::repost($step, $account),
            'linkedin.delete' => self::delete($step, $account),
            'linkedin.comment' => self::comment($step, $account),
            'linkedin.comment_delete' => self::uncomment($step, $account),
            'linkedin.like' => self::like($step, $account),
            'linkedin.unlike' => self::unlike($step, $account),
            'linkedin.me' => self::me($account),

            default => throw new StepFailed("This app doesn't know the LinkedIn step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function repost(StepContext $step, Connection $account): array
    {
        $post = LinkedInApi::post($step);
        $text = trim($step->text('text'));

        if (mb_strlen($text) > LinkedInPost::MAX_LETTERS) {
            throw new StepFailed('A LinkedIn post takes at most '.number_format(LinkedInPost::MAX_LETTERS).' letters, and this is '.number_format(mb_strlen($text)).'.');
        }

        $response = LinkedInApi::send($step, $post, fn () => LinkedInApi::newer($account)
            ->post('https://api.linkedin.com/rest/posts', LinkedInPost::body($step, $account, $text, ['reshareContext' => ['parent' => $post]])));

        return [...LinkedInPost::gaveOut($response, $account), 'of' => $post];
    }

    /**
     * @return array{done: true, id: string}
     */
    private static function delete(StepContext $step, Connection $account): array
    {
        $post = LinkedInApi::post($step);

        LinkedInApi::call(fn () => LinkedInApi::as($account)->delete('https://api.linkedin.com/v2/ugcPosts/'.rawurlencode($post)));

        return ['done' => true, 'id' => $post];
    }

    /**
     * @return array{id: string, urn: string|null, post: string}
     */
    private static function comment(StepContext $step, Connection $account): array
    {
        $post = LinkedInApi::post($step);
        $text = trim($step->text('text'));

        if ($text === '' || mb_strlen($text) > self::MAX_COMMENT) {
            throw new StepFailed($text === '' ? 'The comment came to nothing.' : 'A LinkedIn comment takes at most '.number_format(self::MAX_COMMENT).' letters, and this is '.number_format(mb_strlen($text)).'.');
        }

        $made = LinkedInApi::send($step, $post, fn () => LinkedInApi::as($account)
            ->post('https://api.linkedin.com/v2/socialActions/'.rawurlencode($post).'/comments', [
                'actor' => LinkedInApi::author($account),
                'message' => ['text' => $text],
            ]));

        $urn = $made->json('$URN') ?? $made->json('commentUrn');

        return ['id' => LinkedInApi::made($made), 'urn' => is_string($urn) ? $urn : null, 'post' => $post];
    }

    /**
     * A comment is named whole (urn:li:comment:(its post,its id)), as
     * "Comment on LinkedIn" gives it -- or by its bare id, with its post.
     *
     * @return array{done: true}
     */
    private static function uncomment(StepContext $step, Connection $account): array
    {
        $named = trim($step->text('comment'));

        [$post, $id] = match (true) {
            preg_match('/^urn:li:comment:\((urn:li:[A-Za-z]+:\d+),(\d+)\)$/', rawurldecode($named), $found) === 1 => [$found[1], $found[2]],
            preg_match('/^\d+$/', $named) === 1 => [LinkedInApi::post($step), $named],
            $named === '' => throw new StepFailed('There is no comment: what "Comment on LinkedIn" gives as urn.'),
            default => throw new StepFailed('That is not a LinkedIn comment: what "Comment on LinkedIn" gives as urn, or its id with its post.'),
        };

        $me = rawurlencode(LinkedInApi::author($account));

        LinkedInApi::call(fn () => LinkedInApi::as($account)
            ->delete('https://api.linkedin.com/v2/socialActions/'.rawurlencode($post).'/comments/'.$id."?actor={$me}"));

        return ['done' => true];
    }

    /**
     * @return array{done: true, post: string}
     */
    private static function like(StepContext $step, Connection $account): array
    {
        $post = LinkedInApi::post($step);

        LinkedInApi::call(fn () => LinkedInApi::as($account)
            ->post('https://api.linkedin.com/v2/socialActions/'.rawurlencode($post).'/likes', [
                'actor' => LinkedInApi::author($account),
                'object' => $post,
            ]));

        return ['done' => true, 'post' => $post];
    }

    /**
     * @return array{done: true, post: string}
     */
    private static function unlike(StepContext $step, Connection $account): array
    {
        $post = LinkedInApi::post($step);
        $me = rawurlencode(LinkedInApi::author($account));

        LinkedInApi::call(fn () => LinkedInApi::as($account)
            ->delete('https://api.linkedin.com/v2/socialActions/'.rawurlencode($post)."/likes/{$me}?actor={$me}"));

        return ['done' => true, 'post' => $post];
    }

    /**
     * @return array<string, string|null>
     */
    private static function me(Connection $account): array
    {
        $who = LinkedInApi::call(fn () => LinkedInSignIn::who($account->secret('access_token') ?? ''));
        $language = $who->json('locale');

        return [
            'id' => $who->json('sub'),
            'name' => $who->json('name'),
            'first' => $who->json('given_name'),
            'last' => $who->json('family_name'),
            'picture' => $who->json('picture'),
            'language' => is_array($language) ? ($language['language'] ?? null) : $language,
        ];
    }
}
