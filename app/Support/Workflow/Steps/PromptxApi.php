<?php

namespace App\Support\Workflow\Steps;

use App\Models\Connection;
use App\Support\Connections\PromptxSignIn;
use App\Support\Workflow\References;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What every PromptX step shares: the account it acts as, the project it
 * acts in, and PromptX's answer read the same way each time.
 *
 * Flows are reached with a flows token, which is good for one project for
 * a week. None is kept: each step trades the account token for the
 * project's own as it runs -- the account's usual project first, and
 * another by switching to it.
 */
final class PromptxApi
{
    /** Where flows and their runs are. */
    public const FLOWS = 'https://wf.promptxai.com/api/v1';

    /**
     * The step's account: one of the owner's PromptX connections.
     *
     * @throws StepFailed
     */
    public static function account(StepContext $step): Connection
    {
        return References::connection($step->owner(), (string) $step->setting('connection'), ['promptx'])
            ?? throw new StepFailed('Its PromptX connection is gone, or is not this workflow\'s.');
    }

    /**
     * A flows token for the project the step names, or the account's usual
     * one, and that project's id.
     *
     * @return array{token: string, project: string}
     *
     * @throws StepFailed
     */
    public static function project(StepContext $step, Connection $account): array
    {
        $token = PromptxSignIn::accountToken($account)
            ?? throw new StepFailed("PromptX no longer takes the sign-in of \"{$account->name}\": type its password on the connection to sign in again.");

        $user = self::result(self::reach(fn () => Http::timeout(25)->acceptJson()->withToken($token)
            ->get(PromptxSignIn::ACCOUNT.'/workflow-api/v1/get-user')));
        $usual = ['token' => (string) $user->json('wfAuth.accessToken'), 'project' => (string) $user->json('wfAuth.projectId')];
        $wanted = trim($step->text('project'));

        if ($usual['token'] === '') {
            throw new StepFailed('PromptX gave no way into its flows for this account.');
        }

        if ($wanted === '' || $wanted === $usual['project']) {
            return $usual;
        }

        $switched = self::reach(fn () => self::as($usual['token'])->post(self::FLOWS.'/authentication/switch-project', ['projectId' => $wanted]));

        if (in_array($switched->status(), [403, 404], true)) {
            throw new StepFailed("This PromptX account has no project \"{$wanted}\".");
        }

        return ['token' => (string) self::result($switched)->json('token'), 'project' => $wanted];
    }

    /**
     * A request with a flows token.
     */
    public static function as(string $token): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withToken($token);
    }

    /**
     * A call that asks, or sets something to what it would be anyway: made,
     * and made again if need be.
     *
     * @param  callable(): Response  $ask
     *
     * @throws StepFailed
     */
    public static function call(callable $ask): Response
    {
        return self::result(self::reach($ask));
    }

    /**
     * A call that makes something new on PromptX, recorded so it is never
     * simply made twice.
     *
     * @param  callable(): Response  $send
     *
     * @throws StepFailed
     */
    public static function send(StepContext $step, string $target, callable $send): Response
    {
        return self::result($step->deliver('promptx', $target, null, function () use ($send) {
            try {
                return $send();
            } catch (ConnectionException $e) {
                throw StepFailed::unreached($e, 'PromptX');
            }
        }));
    }

    /**
     * @param  callable(): Response  $ask
     *
     * @throws StepFailed
     */
    private static function reach(callable $ask): Response
    {
        try {
            return $ask();
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach PromptX.', retryable: true);
        }
    }

    /**
     * PromptX's answer, or why there is none worth having.
     *
     * @throws StepFailed
     */
    public static function result(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $said = $response->json('message') ?? $response->json('code') ?? $response->json('error');
        $said = is_string($said) && $said !== '' ? rtrim($said, '.').'.' : "it answered with {$response->status()}.";

        throw match (true) {
            $response->status() === 401 => new StepFailed('PromptX no longer takes this sign-in: type the password on the connection to sign in again.'),
            $response->status() === 404 => new StepFailed('PromptX has no such thing: '.$said),
            $response->status() === 429 => new StepFailed('PromptX asked to slow down.', retryable: true, retryAfter: 60),
            default => new StepFailed('PromptX said: '.$said, retryable: $response->serverError()),
        };
    }
}
