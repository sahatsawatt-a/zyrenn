<?php

namespace App\Support\Workflow\Steps;

/**
 * Posting where a post is made, then published (Instagram, Threads): the
 * service is handed the post and fetches its picture or video itself, which
 * for a video takes a while -- so the step asks how it stands, and while the
 * service is still working it waits, holding no worker, and asks again.
 *
 * A post of several is made of parts handed over one by one, and can only be
 * made once the service has worked through every one of them: the step
 * waits for those the same way, before the post that holds them.
 */
final class Containers
{
    /** How often it asks, and how long between: five minutes in all. */
    private const TRIES = 30;

    private const SECONDS = 10;

    /** How something stands once the service has worked through it. */
    private const DONE = ['FINISHED', 'PUBLISHED'];

    /**
     * @param  callable(list<string>): string  $make  hands the service the post, of its parts when it has any; gives what it calls it
     * @param  callable(string): array{string, string|null}  $stands  how it stands (FINISHED, IN_PROGRESS, ERROR, EXPIRED), and why when it failed
     * @param  callable(string): array<string, mixed>  $publish  publishes it; gives what the step gives out
     * @param  (callable(): list<string>)|null  $parts  hands the service each of several; gives what it calls them
     *
     * @throws StepFailed
     */
    public static function publish(StepContext $step, string $service, callable $make, callable $stands, callable $publish, ?callable $parts = null): StepResult
    {
        $tries = (int) ($step->waited['tries'] ?? 0);
        $made = $step->waited['container'] ?? null;
        $held = $made === null && $parts !== null ? array_values((array) ($step->waited['parts'] ?? $parts())) : [];

        foreach ($held as $part) {
            [$state, $why] = $stands((string) $part);

            if (! in_array($state, self::DONE, true)) {
                return self::unready($service, $state, $why, $tries, ['parts' => $held]);
            }
        }

        $made = (string) ($made ?? $make($held));
        [$state, $why] = $stands($made);

        return in_array($state, self::DONE, true)
            ? new StepResult($publish($made))
            : self::unready($service, $state, $why, $tries, ['container' => $made]);
    }

    /**
     * Still being worked through: wait and ask again, keeping what was made
     * -- or say that it took too long, or could not be taken at all.
     *
     * @param  array<string, mixed>  $keep
     *
     * @throws StepFailed
     */
    private static function unready(string $service, string $state, ?string $why, int $tries, array $keep): StepResult
    {
        return match (true) {
            $state === 'IN_PROGRESS' && $tries < self::TRIES => StepResult::wait('processing', now()->addSeconds(self::SECONDS), [...$keep, 'tries' => $tries + 1]),
            $state === 'IN_PROGRESS' => throw new StepFailed("{$service} was still working through it after five minutes."),
            default => throw new StepFailed("{$service} could not take it".(filled($why) ? ": {$why}" : " ({$state}).")),
        };
    }
}
