<?php

namespace App\Support\Markdown;

/**
 * Writes the note editor's Tiptap JSON as Markdown, one small writer per node
 * type (the same shape as Tiptap's own `renderMarkdown`).
 */
final class TiptapToMarkdown
{
    /**
     * @param  array<string, mixed>|null  $doc
     */
    public function convert(?array $doc): string
    {
        if (! $doc || empty($doc['content'])) {
            return '';
        }

        return rtrim($this->blocksToMarkdown($doc['content']))."\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function blocksToMarkdown(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $parts[] = $this->blockToMarkdown($block);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockToMarkdown(array $block): string
    {
        $content = $block['content'] ?? [];
        $attrs = $block['attrs'] ?? [];

        return match ($block['type'] ?? '') {
            'heading' => str_repeat('#', (int) ($attrs['level'] ?? 1)).' '.$this->inlineToMarkdown($content),
            'bulletList' => $this->listToMarkdown($content, fn () => '- '),
            'orderedList' => $this->listToMarkdown($content, fn (int $i) => (($attrs['start'] ?? 1) + $i).'. '),
            'taskList' => $this->listToMarkdown($content, fn (int $i, array $item) => '- ['.(($item['attrs']['checked'] ?? false) ? 'x' : ' ').'] '),
            'blockquote' => $this->prefixLines($this->blocksToMarkdown($content), '> '),
            'codeBlock' => $this->codeBlockToMarkdown($block),
            'callout' => ':::callout '.($attrs['icon'] ?? '💡')."\n".$this->blocksToMarkdown($content)."\n:::",
            'table' => $this->tableToMarkdown($content),
            'horizontalRule' => '---',
            'blockMath' => "$$\n".($attrs['latex'] ?? '')."\n$$",
            'image' => '!['.($attrs['alt'] ?? '').']('.($attrs['src'] ?? '').')',
            default => $this->inlineToMarkdown($content),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  callable(int, array<string, mixed>): string  $marker
     */
    private function listToMarkdown(array $items, callable $marker): string
    {
        $lines = [];

        foreach ($items as $i => $item) {
            $prefix = $marker($i, $item);
            $children = $item['content'] ?? [];
            $first = array_shift($children);

            $lines[] = $prefix.($first ? $this->blockToMarkdown($first) : '');

            foreach ($children as $child) {
                $lines[] = $this->prefixLines($this->blockToMarkdown($child), str_repeat(' ', strlen($prefix)));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function codeBlockToMarkdown(array $block): string
    {
        $language = $block['attrs']['language'] ?? '';
        $language = $language === 'plaintext' ? '' : $language;
        $code = implode('', array_map(fn (array $node) => $node['text'] ?? '', $block['content'] ?? []));

        return '```'.$language."\n".$code."\n```";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function tableToMarkdown(array $rows): string
    {
        $lines = [];

        foreach ($rows as $index => $row) {
            $cells = array_map(function (array $cell) {
                $text = implode(' ', array_map(
                    fn (array $block) => $this->inlineToMarkdown($block['content'] ?? []),
                    $cell['content'] ?? [],
                ));

                return str_replace(['|', "\n"], ['\|', ' '], $text);
            }, $row['content'] ?? []);

            $lines[] = '| '.implode(' | ', $cells).' |';

            // GFM needs a header separator after the first row
            if ($index === 0) {
                $lines[] = '|'.str_repeat(' --- |', max(count($cells), 1));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function inlineToMarkdown(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'hardBreak') {
                $out .= "\n";

                continue;
            }

            if (($node['type'] ?? '') === 'inlineMath') {
                $out .= '$'.($node['attrs']['latex'] ?? '').'$';

                continue;
            }

            $text = $node['text'] ?? '';
            $marks = [];
            foreach ($node['marks'] ?? [] as $mark) {
                $marks[$mark['type']] = $mark;
            }

            if (isset($marks['code'])) {
                $text = '`'.$text.'`';
            } else {
                if (isset($marks['bold'])) {
                    $text = '**'.$text.'**';
                }
                if (isset($marks['italic'])) {
                    $text = '*'.$text.'*';
                }
                if (isset($marks['strike'])) {
                    $text = '~~'.$text.'~~';
                }
            }

            if (isset($marks['link'])) {
                $text = '['.$text.']('.($marks['link']['attrs']['href'] ?? '').')';
            }

            $out .= $text;
        }

        return $out;
    }

    private function prefixLines(string $text, string $prefix): string
    {
        return implode("\n", array_map(
            fn (string $line) => $line === '' ? rtrim($prefix) : $prefix.$line,
            explode("\n", $text),
        ));
    }
}
