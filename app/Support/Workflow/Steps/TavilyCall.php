<?php

namespace App\Support\Workflow\Steps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Tavily's calls, each a kind of step of its own (ServiceKinds names them;
 * this runs them): a search of the web, pages read whole, a site crawled
 * and read, a site's pages listed, and what the key has used.
 *
 * Nothing here changes anything, but each spends credits: a call that
 * never reached Tavily is made again, one that was not answered in time is
 * not, since it may have been paid for.
 */
final class TavilyCall implements Step
{
    private const AT = 'https://api.tavily.com';

    /**
     * How long a crawl is given before it gives what it has: a step is
     * stopped at 150 seconds (RunWorkflowStep), and Tavily would go on to then.
     */
    private const CRAWL_SECONDS = 120;

    public function run(StepContext $step): StepResult
    {
        return new StepResult(match ($step->node['type']) {
            'tavily.search' => self::search($step),
            'tavily.extract' => self::extract($step),
            'tavily.crawl' => self::crawl($step),
            'tavily.map' => self::map($step),
            'tavily.usage' => self::usage($step),

            default => throw new StepFailed("This app doesn't know the Tavily step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function search(StepContext $step): array
    {
        $whole = in_array($step->setting('raw'), ['markdown', 'text'], true) ? $step->setting('raw') : null;
        $answer = self::ask($step, '/search', [
            ...self::more($step),
            'query' => SearchSteps::needs($step, 'query', 'nothing to search for'),
            'topic' => $step->setting('topic') ?: 'general',
            'search_depth' => $step->setting('depth') ?: 'basic',
            'max_results' => SearchSteps::count($step, 'count', 5, 20),
            ...in_array($step->setting('period'), ['day', 'week', 'month', 'year'], true) ? ['time_range' => $step->setting('period')] : [],
            ...in_array($step->setting('answer'), ['basic', 'advanced'], true) ? ['include_answer' => $step->setting('answer')] : [],
            ...$whole === null ? [] : ['include_raw_content' => $whole],
            ...self::domains($step, 'only', 'include_domains'),
            ...self::domains($step, 'not', 'exclude_domains'),
        ], 60);
        [$results, $cut] = SearchSteps::fit(SearchSteps::rows($answer['results'] ?? null, fn (array $one) => [
            'title' => $one['title'] ?? null,
            'url' => $one['url'] ?? null,
            'content' => $one['content'] ?? null,
            'score' => $one['score'] ?? null,
            ...isset($one['published_date']) ? ['date' => $one['published_date']] : [],
            ...$whole === null ? [] : ['text' => $one['raw_content'] ?? null],
        ]));

        return [
            'query' => $answer['query'] ?? null,
            'answer' => $answer['answer'] ?? null,
            'results' => $results,
            // A picture comes as its address, or with what it shows
            'images' => array_values(array_filter(array_map(fn ($image) => is_array($image) ? ($image['url'] ?? null) : $image, (array) ($answer['images'] ?? [])), is_string(...))),
            'text' => SearchSteps::words($results, 'url', 'content'),
            ...$whole === null ? [] : ['cut' => $cut],
            ...self::spent($answer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extract(StepContext $step): array
    {
        $urls = SearchSteps::listed($step, 'urls');

        if ($urls === [] || count($urls) > 20) {
            throw new StepFailed($urls === [] ? 'There is no page to read: its address.' : 'Tavily reads at most 20 pages at once, and this is '.count($urls).'.');
        }

        $about = trim($step->text('about'));
        $answer = self::ask($step, '/extract', [
            ...self::more($step),
            'urls' => $urls,
            'extract_depth' => $step->setting('depth') ?: 'basic',
            'format' => $step->setting('format') ?: 'markdown',
            ...$about === '' ? [] : ['query' => $about],
        ], 90);
        $failed = SearchSteps::rows($answer['failed_results'] ?? null, fn (array $one) => ['url' => $one['url'] ?? null, 'error' => $one['error'] ?? null]);
        [$results, $cut] = SearchSteps::fit(SearchSteps::rows($answer['results'] ?? null, self::page(...)));

        if ($results === []) {
            throw new StepFailed('Tavily could not read '.($failed[0]['url'] ?? 'any of them').(is_string($failed[0]['error'] ?? null) ? ": {$failed[0]['error']}" : '.'));
        }

        return ['text' => SearchSteps::together($results), 'results' => $results, 'failed' => $failed, 'cut' => $cut, ...self::spent($answer)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function crawl(StepContext $step): array
    {
        $answer = self::ask($step, '/crawl', [
            ...self::more($step),
            ...self::site($step, 20, 100),
            'extract_depth' => $step->setting('depth') ?: 'basic',
            'format' => $step->setting('format') ?: 'markdown',
        ], self::CRAWL_SECONDS + 15);
        [$results, $cut] = SearchSteps::fit(SearchSteps::rows($answer['results'] ?? null, self::page(...)));

        return [
            'site' => $answer['base_url'] ?? null,
            'results' => $results,
            'count' => count($results),
            'text' => $results === [] ? '' : SearchSteps::together($results),
            'cut' => $cut,
            ...self::spent($answer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(StepContext $step): array
    {
        $answer = self::ask($step, '/map', [...self::more($step), ...self::site($step, 50, 500)], self::CRAWL_SECONDS + 15);
        $urls = array_values(array_filter((array) ($answer['results'] ?? []), is_string(...)));

        return ['site' => $answer['base_url'] ?? null, 'urls' => $urls, 'count' => count($urls), ...self::spent($answer)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function usage(StepContext $step): array
    {
        $answer = self::ask($step, '/usage', null, 20);
        $used = fn (string $of) => is_numeric($answer[$of]['usage'] ?? $answer[$of]['plan_usage'] ?? null) ? (int) ($answer[$of]['usage'] ?? $answer[$of]['plan_usage']) : null;
        $limit = fn (string $of) => is_numeric($answer[$of]['limit'] ?? $answer[$of]['plan_limit'] ?? null) ? (int) ($answer[$of]['limit'] ?? $answer[$of]['plan_limit']) : null;
        // What is left is the least of what the key may still spend and what the plan may
        $left = array_filter([
            $limit('key') === null ? null : $limit('key') - (int) $used('key'),
            $limit('account') === null ? null : $limit('account') - (int) $used('account'),
        ], is_int(...));

        return [
            'used' => $used('key'),
            'limit' => $limit('key'),
            'left' => $left === [] ? null : max(0, min($left)),
            'plan' => $answer['account']['current_plan'] ?? null,
            'plan_used' => $used('account'),
            'plan_limit' => $limit('account'),
        ];
    }

    /**
     * A page as a step gives it out: where it is, and what it says.
     *
     * @param  array<string, mixed>  $one
     * @return array<string, mixed>
     */
    private static function page(array $one): array
    {
        return [
            'url' => $one['url'] ?? null,
            'text' => is_string($one['raw_content'] ?? null) ? $one['raw_content'] : '',
            ...is_array($one['images'] ?? null) && $one['images'] !== [] ? ['images' => $one['images']] : [],
        ];
    }

    /**
     * What a crawl and a list of a site's pages both ask: where to start,
     * how far to go, and which of what is found to keep.
     *
     * @return array<string, mixed>
     */
    private static function site(StepContext $step, int $usual, int $most): array
    {
        $told = trim($step->text('instructions'));
        $paths = fn (string $setting) => SearchSteps::listed($step, $setting, commas: false);

        return [
            'url' => SearchSteps::needs($step, 'url', 'no site to start from: its address'),
            ...$told === '' ? [] : ['instructions' => $told],
            'max_depth' => SearchSteps::count($step, 'max_depth', 1, 5),
            'limit' => SearchSteps::count($step, 'limit', $usual, $most),
            ...$paths('select_paths') === [] ? [] : ['select_paths' => $paths('select_paths')],
            ...$paths('exclude_paths') === [] ? [] : ['exclude_paths' => $paths('exclude_paths')],
            'allow_external' => $step->setting('external') === 'yes',
            'timeout' => self::CRAWL_SECONDS,
        ];
    }

    /**
     * Sites a search keeps to, or leaves out.
     *
     * @return array<string, list<string>>
     */
    private static function domains(StepContext $step, string $setting, string $as): array
    {
        $sites = array_map(
            fn (string $site) => strtolower((string) preg_replace('#^https?://(www\.)?|/.*$#i', '', $site)),
            SearchSteps::listed($step, $setting),
        );

        return $sites === [] ? [] : [$as => $sites];
    }

    /**
     * Anything else a call is told to ask. Tavily reads yes and no, numbers
     * and lists as themselves, so a value written as one is sent as one.
     *
     * @return array<string, mixed>
     */
    private static function more(StepContext $step): array
    {
        return array_map(function (mixed $value) {
            $read = is_string($value) ? json_decode(trim($value), true) : null;

            return $read === null ? $value : $read;
        }, $step->fields('more'));
    }

    /**
     * What a call cost, and what Tavily knows it by.
     *
     * @param  array<mixed>  $answer
     * @return array{credits: mixed, id: mixed}
     */
    private static function spent(array $answer): array
    {
        return ['credits' => $answer['usage']['credits'] ?? null, 'id' => $answer['request_id'] ?? null];
    }

    /**
     * Tavily's answer, or why there is none worth having.
     *
     * @param  array<string, mixed>|null  $body  null for a call that only asks
     * @return array<mixed>
     *
     * @throws StepFailed
     */
    private static function ask(StepContext $step, string $path, ?array $body, int $timeout): array
    {
        $request = Http::timeout($timeout)->acceptJson()->withToken(SearchSteps::key($step, 'tavily'));

        try {
            $response = $body === null ? $request->get(self::AT.$path) : $request->post(self::AT.$path, [...$body, 'include_usage' => true]);
        } catch (ConnectionException $e) {
            throw preg_match('/cURL error (6|7|35)\b/', $e->getMessage()) === 1
                ? new StepFailed('Could not reach Tavily.', retryable: true)
                : new StepFailed('Tavily did not answer in time. It is not asked again unasked, since it may have spent credits already.');
        }

        $answer = $response->json();

        if ($response->successful() && is_array($answer)) {
            return $answer;
        }

        $detail = is_array($answer) ? ($answer['detail'] ?? null) : null;
        $said = mb_strimwidth(match (true) {
            is_string($detail) => $detail,
            is_array($detail) && is_string($detail['error'] ?? null) => $detail['error'],
            // What it would not take, by what it was called
            is_array($detail) && array_is_list($detail) => implode('; ', array_map(
                fn ($one) => is_array($one) ? trim(implode('.', array_slice((array) ($one['loc'] ?? []), 1)).': '.(is_string($one['msg'] ?? null) ? $one['msg'] : ''), ': ') : '',
                $detail,
            )),
            default => "nothing it could be read by ({$response->status()}).",
        }, 0, 300, '…');
        $after = $response->header('Retry-After');

        throw match ($response->status()) {
            401 => new StepFailed('Tavily refused the key: check it on the connection.'),
            429 => new StepFailed('Tavily asked to slow down.', retryable: true, retryAfter: is_numeric($after) ? (int) $after : 60),
            432, 433 => new StepFailed("The key's Tavily credits are used up: {$said}"),
            default => new StepFailed("Tavily said: {$said}", retryable: $response->serverError()),
        };
    }
}
