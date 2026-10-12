<?php

namespace App\Support\Workflow\Steps;

/**
 * Posting where a post is made, then published (Instagram, Threads): the
 * service is handed the post and fetches its picture or video itself, which
 * for a video takes a while -- so the step asks how it stands, and while the
 * service is still working it waits, holding no worker, and asks again.
 */
final class Containers
{
    /** How often it asks, and how long between: five minutes in all. */
    private const TRIES = 30;

    private const SECONDS = 10;

    /**
     * @param  callable(): string  $make  hands the service the post; gives what it calls it
     * @param  callable(string): array{string, string|null}  $stands  how it stands (FINISHED, IN_PROGRESS, ERROR, EXPIRED), and why when it failed
     * @param  callable(string): array<string, mixed>  $publish  publishes it; gives what the step gives out
     *
     * @throws StepFailed
     */
    public static function publish(StepContext $step, string $service, callable $make, callable $stands, callable $publish): StepResult
    {
        $made = (string) ($step->waited['container'] ?? $make());
        $tries = (int) ($step->waited['tries'] ?? 0);
        [$state, $why] = $stands($made);

        return match (true) {
            in_array($state, ['FINISHED', 'PUBLISHED'], true) => new StepResult($publish($made)),
            $state === 'IN_PROGRESS' && $tries < self::TRIES => StepResult::wait('processing', now()->addSeconds(self::SECONDS), ['container' => $made, 'tries' => $tries + 1]),
            $state === 'IN_PROGRESS' => throw new StepFailed("{$service} was still working through it after five minutes."),
            default => throw new StepFailed("{$service} could not take it".(filled($why) ? ": {$why}" : " ({$state}).")),
        };
    }
}
