<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Support\Connections\InstagramSignIn;
use App\Support\Connections\Meta;

/**
 * Instagram's messages, each call a kind of step of its own (ServiceKinds
 * names them; this runs them): words to someone who wrote, a file, a
 * message to whoever commented, a heart on a message, and who someone is.
 *
 * Instagram takes a message only within 24 hours of the other's last one,
 * and lets an app read and write them only by its own login, with the
 * permission for messages: a connection signed in by Facebook is told
 * before anything is asked.
 */
final class InstagramChat implements Step
{
    private const NAME = 'Instagram';

    /** Instagram counts a message in bytes, not letters. */
    private const MAX_BYTES = 1000;

    private const MAX_ANSWERS = 13;

    private const MAX_ANSWER_LETTERS = 20;

    private const MB = 1024 * 1024;

    /**
     * What Instagram shows in a chat, as what, and the most of each it fetches.
     *
     * @var array<string, array{list<string>, int}>
     */
    private const SHOWN = [
        'image' => [['image/jpeg', 'image/png', 'image/gif'], 8 * self::MB],
        'video' => [['video/mp4', 'video/quicktime', 'video/webm', 'video/ogg', 'video/x-msvideo'], 25 * self::MB],
        'audio' => [['audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/wav', 'audio/x-wav'], 25 * self::MB],
    ];

    public function run(StepContext $step): StepResult
    {
        $account = MetaApi::account($step, 'instagram');

        if (InstagramSignIn::byFacebook($account)) {
            throw new StepFailed("Instagram's messages go by its own login, and \"{$account->name}\" signs in with Facebook: set \"Sign in with\" to Instagram on the connection, and sign in again.");
        }

        return new StepResult(match ($step->node['type']) {
            'instagram.send' => self::words($step, $account),
            'instagram.send_file' => self::file($step, $account),
            'instagram.message_commenter' => self::commenter($step, $account),
            'instagram.react' => self::react($step, $account),
            'instagram.profile' => self::profile($step, $account),

            default => throw new StepFailed("This app doesn't know the Instagram step \"{$step->node['type']}\"."),
        });
    }

    /**
     * Words, with quick answers under them when the step names any.
     *
     * @return array{id: string|null, to: string}
     */
    private static function words(StepContext $step, Connection $account): array
    {
        $to = self::to($step);
        $answers = self::answers($step);

        return self::send($step, $account, ['id' => $to], [
            'text' => self::text($step),
            ...$answers === [] ? [] : ['quick_replies' => $answers],
        ]);
    }

    /**
     * A picture, a video or a recording of the Drive's. Instagram is not
     * sent it: it is told where it is, and fetches it.
     *
     * @return array<string, mixed>
     */
    private static function file(StepContext $step, Connection $account): array
    {
        $to = self::to($step);
        $file = DriveSteps::file($step);

        return [...self::send($step, $account, ['id' => $to], [
            'attachment' => ['type' => self::shownAs($file), 'payload' => ['url' => DriveSteps::publicUrl($file)]],
        ]), 'file' => $file->ref_id];
    }

    /**
     * A message to whoever wrote a comment, named by the comment: the one
     * thing that reaches someone who has not written to the account.
     *
     * @return array{id: string|null, to: string}
     */
    private static function commenter(StepContext $step, Connection $account): array
    {
        return self::send($step, $account, ['comment_id' => MetaApi::thing($step, 'comment', 'comment')], ['text' => self::text($step)]);
    }

    /**
     * A heart on a message of theirs, or the one put there taken off.
     *
     * @return array{done: true}
     */
    private static function react(StepContext $step, Connection $account): array
    {
        $to = self::to($step);
        $message = MetaApi::needs($step, 'message', 'message to react to: its id, like {{ trigger.reply.to }}');
        $off = $step->setting('reaction') === 'none';

        MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))->post(Meta::INSTAGRAM.'/me/messages', [
            'recipient' => ['id' => $to],
            'sender_action' => $off ? 'unreact' : 'react',
            'payload' => ['message_id' => $message, ...$off ? [] : ['reaction' => 'love']],
        ]));

        return ['done' => true];
    }

    /**
     * Who someone is, as Instagram tells an account they wrote to.
     *
     * @return array<string, mixed>
     */
    private static function profile(StepContext $step, Connection $account): array
    {
        $user = MetaApi::thing($step, 'user', 'person: their id, like {{ trigger.sender.id }}');
        $who = MetaApi::call(self::NAME, fn () => MetaApi::as($account->secret('access_token'))
            ->get(Meta::INSTAGRAM."/{$user}", ['fields' => 'name,username,profile_pic,follower_count,is_verified_user,is_user_follow_business,is_business_follow_user']));

        return [
            'id' => $user,
            'username' => $who->json('username'),
            'name' => $who->json('name'),
            'picture' => $who->json('profile_pic'),
            'followers' => $who->json('follower_count'),
            'verified' => $who->json('is_verified_user'),
            'follows' => $who->json('is_user_follow_business'),
            'followed' => $who->json('is_business_follow_user'),
        ];
    }

    /**
     * Send a message, recorded so it never simply goes twice. Gives out
     * Instagram's id for it, and who it went to.
     *
     * @param  array<string, string>  $recipient  the person by their id, or by a comment of theirs
     * @param  array<string, mixed>  $message
     * @return array{id: string|null, to: string}
     *
     * @throws StepFailed
     */
    private static function send(StepContext $step, Connection $account, array $recipient, array $message): array
    {
        $target = (string) reset($recipient);
        $sent = MetaApi::send($step, self::NAME, $target, fn () => MetaApi::as($account->secret('access_token'), 60)
            ->post(Meta::INSTAGRAM.'/me/messages', ['recipient' => $recipient, 'message' => $message]));

        return ['id' => $sent->json('message_id'), 'to' => (string) ($sent->json('recipient_id') ?? $target)];
    }

    /**
     * Who a step writes to: the id Instagram gives this account for them.
     *
     * @throws StepFailed
     */
    private static function to(StepContext $step): string
    {
        $to = trim($step->text('to'));

        return preg_match('/^\d+$/', $to) === 1
            ? $to
            : throw new StepFailed('There is no one to send to: the id Instagram gives the account for them, like {{ trigger.chat.id }} -- not their @name.');
    }

    /**
     * @throws StepFailed
     */
    private static function text(StepContext $step): string
    {
        $text = MetaApi::needs($step, 'text', 'message: it came to nothing');

        return strlen($text) <= self::MAX_BYTES
            ? $text
            : throw new StepFailed('An Instagram message is at most '.number_format(self::MAX_BYTES).' bytes -- about that many letters in English, a third as many in Thai -- and this is '.number_format(strlen($text)).'.');
    }

    /**
     * Quick answers from a step's named values: each name is what the
     * answer says, its value what a tap sends back -- the name itself when
     * there is none.
     *
     * @return list<array{content_type: string, title: string, payload: string}>
     *
     * @throws StepFailed
     */
    private static function answers(StepContext $step): array
    {
        $answers = [];

        foreach ((array) $step->setting('buttons') as $field) {
            $label = trim((string) ($field['name'] ?? ''));

            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > self::MAX_ANSWER_LETTERS) {
                throw new StepFailed('A quick answer on Instagram says at most '.self::MAX_ANSWER_LETTERS." letters, and \"{$label}\" is longer.");
            }

            $back = trim($step->text("buttons.{$label}", (string) ($field['value'] ?? '')));
            $answers[] = ['content_type' => 'text', 'title' => $label, 'payload' => mb_substr($back !== '' ? $back : $label, 0, 1000)];
        }

        return count($answers) <= self::MAX_ANSWERS
            ? $answers
            : throw new StepFailed('Instagram shows at most '.self::MAX_ANSWERS.' quick answers under a message, and this names '.count($answers).'.');
    }

    /**
     * @throws StepFailed when it is nothing Instagram shows in a chat, or more than it fetches
     */
    private static function shownAs(DriveFile $file): string
    {
        foreach (self::SHOWN as $as => [$mimes, $most]) {
            if (in_array($file->mime, $mimes, true)) {
                return $file->size <= $most
                    ? $as
                    : throw new StepFailed("Instagram fetches a file like \"{$file->name}\" of at most ".intdiv($most, self::MB).' MB, and it is larger.');
            }
        }

        throw new StepFailed("\"{$file->name}\" is nothing Instagram shows in a chat: a JPEG, PNG or GIF picture, an MP4, MOV or WebM video, or an AAC, M4A or WAV recording.");
    }
}
