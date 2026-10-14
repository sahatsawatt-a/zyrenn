<?php

namespace App\Support\Agents;

/**
 * Where an answer came from: the passages of a knowledge base the agent was
 * given before it answered, each as it is shown beside the answer -- what
 * it is called, where to open it, how near the question it was, and what it
 * says -- so whoever reads the answer can check it before it is sent.
 *
 * @phpstan-type Source array{title: string, url: string|null, kind: string, score: float, text: string}
 */
final class Sourced
{
    /** The most of a passage shown beside an answer, and the most sources kept. */
    private const TEXT = 400;

    private const MOST = 5;

    /**
     * @param  list<array{title: string, url: string|null, kind: string, text: string, score: float}>  $found
     * @return list<Source>
     */
    public static function from(array $found): array
    {
        return array_map(fn (array $one) => [
            'title' => mb_substr($one['title'], 0, 200),
            'url' => $one['url'],
            'kind' => $one['kind'],
            'score' => round($one['score'], 2),
            'text' => mb_strimwidth($one['text'], 0, self::TEXT, '…'),
        ], array_slice($found, 0, self::MOST));
    }

    /**
     * Sources as a step hands them on, made safe to keep with a message:
     * only what has a title, an address of the app's own or https.
     *
     * @return list<Source>
     */
    public static function kept(mixed $given): array
    {
        if (! is_array($given)) {
            return [];
        }

        $kept = [];

        foreach (array_slice(array_values($given), 0, self::MOST) as $one) {
            if (! is_array($one) || ! is_string($one['title'] ?? null) || trim($one['title']) === '') {
                continue;
            }

            $url = is_string($one['url'] ?? null) ? $one['url'] : null;

            $kept[] = [
                'title' => mb_substr(trim($one['title']), 0, 200),
                'url' => $url !== null && (str_starts_with($url, 'https://') || str_starts_with($url, (string) config('app.url')) || str_starts_with($url, '/')) ? mb_substr($url, 0, 500) : null,
                'kind' => is_string($one['kind'] ?? null) ? mb_substr($one['kind'], 0, 16) : 'note',
                'score' => is_numeric($one['score'] ?? null) ? round((float) $one['score'], 2) : 0.0,
                'text' => is_string($one['text'] ?? null) ? mb_strimwidth($one['text'], 0, self::TEXT, '…') : '',
            ];
        }

        return $kept;
    }
}
