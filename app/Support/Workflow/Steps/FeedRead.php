<?php

namespace App\Support\Workflow\Steps;

use App\Support\Outside\RemoteDownload;
use App\Support\Outside\RemoteDownloadFailed;
use App\Support\Workflow\Seen;
use Carbon\CarbonImmutable;
use SimpleXMLElement;

/**
 * Read a feed: an RSS, Atom or JSON Feed address on the public internet,
 * its items newest first -- or only the ones not given out before, which
 * with "Every ..." before it makes a feed's own trigger. Fetched as any
 * outside address is: only a public one, redirects followed by hand.
 */
final class FeedRead implements Step
{
    /** The most of a feed read. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** The most items given out. */
    public const MOST = 100;

    private const ATOM = 'http://www.w3.org/2005/Atom';

    private const MEDIA = 'http://search.yahoo.com/mrss/';

    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const DC = 'http://purl.org/dc/elements/1.1/';

    public function run(StepContext $step): StepResult
    {
        $url = trim($step->text('url'));

        if ($url === '') {
            throw new StepFailed('Give the feed\'s address.');
        }

        try {
            $file = RemoteDownload::fetch($url, self::MAX_BYTES, 30, [
                'Accept' => 'application/rss+xml, application/atom+xml, application/feed+json, application/xml;q=0.9, */*;q=0.8',
            ]);
        } catch (RemoteDownloadFailed $e) {
            throw new StepFailed($e->getMessage(), retryable: str_starts_with($e->getMessage(), 'Could not reach'));
        }

        $body = (string) file_get_contents($file['path']);
        @unlink($file['path']);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $feed = self::parse($body) ?? throw new StepFailed("{$host} did not answer with a feed (RSS, Atom or JSON Feed).");

        // Newest first; those with no date where the feed put them
        $items = $feed['items'];
        usort($items, fn (array $a, array $b) => strcmp((string) $b['published'], (string) $a['published']));
        $most = max(1, min(self::MOST, (int) ($step->setting('count') ?: 20)));

        if ($step->setting('only_new') === 'new') {
            $new = array_flip(Seen::unseen($step, array_column($items, 'id')));
            $items = array_values(array_filter($items, fn (array $item) => isset($new[$item['id']])));
            $items = array_slice($items, 0, $most);
            Seen::keep($step, array_column($items, 'id'));
        } else {
            $items = array_slice($items, 0, $most);
        }

        return new StepResult([
            'title' => $feed['title'],
            'link' => $feed['link'],
            'items' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * A feed's title, link and items, or null when it is not one.
     *
     * @return array{title: string, link: string, items: list<array<string, mixed>>}|null
     */
    public static function parse(string $body): ?array
    {
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? '');

        if (str_starts_with($body, '{')) {
            return self::jsonFeed(json_decode($body, true));
        }

        $before = libxml_use_internal_errors(true);
        // Never an outside entity or DTD: only what is in the feed itself
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($before);

        if ($xml === false) {
            return null;
        }

        return match (strtolower($xml->getName())) {
            'rss' => self::rss($xml->channel, $xml->channel->item),
            // RSS 1.0: its items beside the channel
            'rdf' => self::rss($xml->children('http://purl.org/rss/1.0/')->channel, $xml->children('http://purl.org/rss/1.0/')->item),
            'feed' => self::atom($xml),
            default => null,
        };
    }

    /**
     * @return array{title: string, link: string, items: list<array<string, mixed>>}
     */
    private static function rss(SimpleXMLElement $channel, SimpleXMLElement $entries): array
    {
        $items = [];

        foreach ($entries as $entry) {
            $media = $entry->children(self::MEDIA);
            $dc = $entry->children(self::DC);
            $content = (string) $entry->children(self::CONTENT)->encoded;
            $enclosure = $entry->enclosure;
            $picture = (string) ($media->thumbnail?->attributes()['url'] ?? $media->content?->attributes()['url'] ?? '');

            if ($picture === '' && str_starts_with((string) ($enclosure?->attributes()['type'] ?? ''), 'image/')) {
                $picture = (string) $enclosure->attributes()['url'];
            }

            $link = trim((string) $entry->link);
            $items[] = self::item(
                id: trim((string) $entry->guid) ?: $link ?: (string) $entry->title.(string) $entry->pubDate,
                title: (string) $entry->title,
                link: $link,
                summary: (string) $entry->description,
                content: $content !== '' ? $content : (string) $entry->description,
                published: (string) ($entry->pubDate ?: $dc->date),
                author: (string) ($dc->creator ?: $entry->author),
                categories: array_map('strval', iterator_to_array($entry->category, false)),
                picture: $picture,
            );
        }

        return ['title' => self::plain((string) $channel->title), 'link' => trim((string) $channel->link), 'items' => $items];
    }

    /**
     * @return array{title: string, link: string, items: list<array<string, mixed>>}
     */
    private static function atom(SimpleXMLElement $feed): array
    {
        $feed = $feed->children(self::ATOM);
        $items = [];

        foreach ($feed->entry as $entry) {
            $media = $entry->children(self::MEDIA);
            $link = self::atomLink($entry);
            $items[] = self::item(
                id: trim((string) $entry->id) ?: $link,
                title: (string) $entry->title,
                link: $link,
                summary: (string) ($entry->summary ?: $entry->content),
                content: (string) ($entry->content ?: $entry->summary),
                published: (string) ($entry->published ?: $entry->updated),
                author: (string) $entry->author?->name,
                categories: array_map(fn (SimpleXMLElement $one) => (string) ($one->attributes()['label'] ?? $one->attributes()['term']), iterator_to_array($entry->category, false)),
                picture: (string) ($media->thumbnail?->attributes()['url'] ?? ''),
            );
        }

        return ['title' => self::plain((string) $feed->title), 'link' => self::atomLink($feed), 'items' => $items];
    }

    /** An Atom entry's or feed's own page: its "alternate" link, or its first. */
    private static function atomLink(SimpleXMLElement $of): string
    {
        $first = '';

        foreach ($of->link as $link) {
            $attributes = $link->attributes();
            $first = $first ?: (string) $attributes['href'];

            if (in_array((string) ($attributes['rel'] ?? 'alternate'), ['alternate', ''], true)) {
                return (string) $attributes['href'];
            }
        }

        return $first;
    }

    /**
     * @param  mixed  $feed  a JSON Feed, decoded
     * @return array{title: string, link: string, items: list<array<string, mixed>>}|null
     */
    private static function jsonFeed(mixed $feed): ?array
    {
        if (! is_array($feed) || ! str_contains((string) ($feed['version'] ?? ''), 'jsonfeed.org') || ! is_array($feed['items'] ?? null)) {
            return null;
        }

        $items = [];

        foreach ($feed['items'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $link = (string) ($entry['url'] ?? '');
            $html = (string) ($entry['content_html'] ?? $entry['content_text'] ?? '');
            $items[] = self::item(
                id: (string) ($entry['id'] ?? $link),
                title: (string) ($entry['title'] ?? ''),
                link: $link,
                summary: (string) ($entry['summary'] ?? $html),
                content: $html,
                published: (string) ($entry['date_published'] ?? $entry['date_modified'] ?? ''),
                author: (string) ($entry['authors'][0]['name'] ?? $entry['author']['name'] ?? ''),
                categories: array_values(array_map('strval', (array) ($entry['tags'] ?? []))),
                picture: (string) ($entry['image'] ?? $entry['banner_image'] ?? ''),
            );
        }

        return ['title' => (string) ($feed['title'] ?? ''), 'link' => (string) ($feed['home_page_url'] ?? ''), 'items' => $items];
    }

    /**
     * One item, as every kind of feed gives it out: its words plain, its
     * content as the feed has it (often HTML), its date in ISO 8601.
     *
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    private static function item(string $id, string $title, string $link, string $summary, string $content, string $published, string $author, array $categories, string $picture): array
    {
        return [
            'id' => $id,
            'title' => self::plain($title),
            'link' => trim($link),
            'summary' => mb_strimwidth(self::plain($summary), 0, 1000, '…'),
            'content' => mb_strimwidth(trim($content), 0, 20000, '…'),
            'published' => self::date($published),
            'author' => self::plain($author),
            'categories' => array_values(array_filter(array_map(self::plain(...), $categories))),
            'picture' => trim($picture) ?: null,
        ];
    }

    /** Words without their HTML, on one line. */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function date(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($text))->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
