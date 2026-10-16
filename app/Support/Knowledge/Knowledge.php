<?php

namespace App\Support\Knowledge;

use App\Models\Knowledge\KnowledgeBase;
use App\Models\Knowledge\KnowledgeChunk;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiFailed;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A knowledge base read and asked. Reading cuts every document its sources
 * give into passages of about a paragraph or a few, keeps each with the
 * numbers its meaning is found by -- made only for a passage not kept
 * already -- and lets go of what is no longer there. Asking finds the
 * passages nearest a question: by meaning when the base has an embedding
 * model, else by the words (pieces of three letters, so Thai and other
 * writing without spaces is found too).
 *
 * @phpstan-import-type Document from Sources
 *
 * @phpstan-type Found array{title: string, url: string|null, kind: string, text: string, score: float}
 */
final class Knowledge
{
    /** About how long a passage is, in letters, and the most a base keeps. */
    private const PASSAGE = 1000;

    public const MOST_PASSAGES = 3000;

    private const EMBED_AT_ONCE = 32;

    public function __construct(private AiClient $ai) {}

    /**
     * Read the base's sources again.
     */
    public function read(KnowledgeBase $base): void
    {
        $base->forceFill(['state' => KnowledgeBase::READING, 'problem' => null])->save();

        try {
            $passages = [];

            foreach (Sources::documents($base) as $document) {
                foreach (self::cut($document['text']) as $at => $text) {
                    $passages[] = [
                        'source_kind' => $document['kind'],
                        'source_ref' => $document['ref'],
                        'title' => mb_substr($document['title'], 0, 255),
                        'url' => $document['url'] === null ? null : mb_substr($document['url'], 0, 255),
                        'position' => $at,
                        'text' => $text,
                        'hash' => sha1($document['title']."\n".$text),
                    ];

                    if (count($passages) > self::MOST_PASSAGES) {
                        throw new \RuntimeException('There is more than '.number_format(self::MOST_PASSAGES).' passages to read: choose fewer or smaller sources.');
                    }
                }
            }

            $this->embedded($base, $passages);

            DB::transaction(function () use ($base, $passages) {
                $base->chunks()->delete();
                $now = now();

                foreach (array_chunk($passages, 200) as $some) {
                    KnowledgeChunk::insert(array_map(fn (array $passage) => [
                        ...$passage,
                        'knowledge_base_id' => $base->id,
                        'embedding' => isset($passage['embedding']) ? json_encode($passage['embedding']) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $some));
                }
            });

            $base->forceFill(['state' => KnowledgeBase::IDLE, 'read_at' => now()])->save();
        } catch (Throwable $e) {
            $base->forceFill(['state' => KnowledgeBase::FAILED, 'problem' => mb_strimwidth($e->getMessage(), 0, 500, '…')])->save();

            if (! $e instanceof AiFailed && ! $e instanceof \RuntimeException) {
                report($e);
            }
        }
    }

    /**
     * The passages given numbers: those the base kept already keep theirs.
     *
     * @param  list<array<string, mixed>>  $passages
     *
     * @throws AiFailed
     */
    private function embedded(KnowledgeBase $base, array &$passages): void
    {
        if (! $base->embeds() || $base->connection === null) {
            return;
        }

        $kept = $base->chunks()->whereNotNull('embedding')->whereIn('hash', array_column($passages, 'hash'))->pluck('embedding', 'hash');
        $missing = [];

        foreach ($passages as $at => $passage) {
            $vector = $kept[$passage['hash']] ?? null;

            if (is_string($vector)) {
                $vector = json_decode($vector, true);
            }

            is_array($vector) ? $passages[$at]['embedding'] = $vector : $missing[] = $at;
        }

        foreach (array_chunk($missing, self::EMBED_AT_ONCE) as $some) {
            $vectors = $this->ai->embed($base->connection, (string) $base->embed_model, array_map(fn (int $at) => $passages[$at]['title']."\n".$passages[$at]['text'], $some));

            foreach ($some as $i => $at) {
                $passages[$at]['embedding'] = $vectors[$i];
            }
        }
    }

    /**
     * The passages nearest a question, best first.
     *
     * @return list<Found>
     *
     * @throws AiFailed
     */
    public function find(KnowledgeBase $base, string $question, int $most = 5): array
    {
        $question = trim($question);

        if ($question === '') {
            return [];
        }

        $chunks = $base->chunks()->get(['id', 'source_kind', 'title', 'url', 'text', 'embedding']);
        $meaning = $base->embeds() && $base->connection !== null && $chunks->contains(fn (KnowledgeChunk $chunk) => $chunk->embedding !== null);
        $vector = $meaning ? $this->ai->embed($base->connection, (string) $base->embed_model, [$question])[0] : [];
        $pieces = $meaning ? [] : self::pieces($question);

        return array_values($chunks
            ->map(fn (KnowledgeChunk $chunk) => [
                'title' => $chunk->title,
                'url' => $chunk->url,
                'kind' => $chunk->source_kind,
                'text' => $chunk->text,
                'score' => round($meaning
                    ? self::cosine($vector, $chunk->embedding ?? [])
                    : self::overlap($pieces, self::pieces($chunk->title."\n".$chunk->text)), 4),
            ])
            ->filter(fn (array $found) => $found['score'] > ($meaning ? 0.0 : 0.05))
            ->sortByDesc('score')
            ->take(max(1, min(20, $most)))
            ->all());
    }

    /**
     * Passages given to a model as what it may answer from, each under where it came from.
     *
     * @param  list<Found>  $found
     */
    public static function context(array $found): string
    {
        return implode("\n\n", array_map(fn (array $one) => "### {$one['title']}\n{$one['text']}", $found));
    }

    /**
     * A document cut at paragraphs into passages of about PASSAGE letters;
     * a paragraph longer than that, at sentences, then anywhere.
     *
     * @return list<string>
     */
    public static function cut(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/u', trim(str_replace("\r\n", "\n", $text))) ?: [];
        $passages = [];
        $now = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            foreach (mb_strlen($paragraph) > self::PASSAGE ? self::split($paragraph) : [$paragraph] as $piece) {
                if ($now !== '' && mb_strlen($now) + mb_strlen($piece) + 2 > self::PASSAGE) {
                    $passages[] = $now;
                    $now = '';
                }

                $now = $now === '' ? $piece : "{$now}\n\n{$piece}";
            }
        }

        if ($now !== '') {
            $passages[] = $now;
        }

        return $passages;
    }

    /**
     * @return list<string>
     */
    private static function split(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?。！？])\s+/u', $paragraph) ?: [$paragraph];
        $pieces = [];

        foreach ($sentences as $sentence) {
            foreach (mb_str_split($sentence, self::PASSAGE) as $piece) {
                $pieces[] = $piece;
            }
        }

        return $pieces;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /**
     * The pieces of three letters a text is made of, lower-cased, spaces as one.
     *
     * @return array<string, true>
     */
    private static function pieces(string $text): array
    {
        $text = ' '.mb_strtolower((string) preg_replace('/[\s\p{P}]+/u', ' ', $text)).' ';
        $pieces = [];
        $letters = mb_str_split($text);

        for ($i = 0, $n = count($letters) - 2; $i < $n; $i++) {
            $pieces[$letters[$i].$letters[$i + 1].$letters[$i + 2]] = true;
        }

        return $pieces;
    }

    /**
     * How much of the question a passage holds: the share of its pieces found there.
     *
     * @param  array<string, true>  $asked
     * @param  array<string, true>  $in
     */
    private static function overlap(array $asked, array $in): float
    {
        return $asked === [] ? 0.0 : count(array_intersect_key($asked, $in)) / count($asked);
    }
}
