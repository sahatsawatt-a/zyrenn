<?php

namespace App\Support;

use App\Support\Markdown\MarkdownToTiptap;
use App\Support\Markdown\TiptapToMarkdown;

/**
 * Converts between the note editor's Tiptap JSON and Markdown, so MCP clients
 * can read and write notes as text.
 *
 * Supported blocks: paragraphs, headings, bullet / ordered / task lists (nested),
 * blockquotes, code blocks (incl. mermaid), callouts (`:::callout 💡` … `:::`),
 * GFM tables, horizontal rules, images and `$$` math blocks. Inline: bold, italic,
 * strike, code, links and `$…$` math (pandoc rules: no space inside the dollars,
 * so "$5 and $10" stays text). A single newline inside a paragraph is a hard break.
 */
class TiptapMarkdown
{
    /**
     * @param  array<string, mixed>|null  $doc
     */
    public static function toMarkdown(?array $doc): string
    {
        return (new TiptapToMarkdown)->convert($doc);
    }

    /**
     * @return array{type: string, content: array<int, array<string, mixed>>}
     */
    public static function toDoc(string $markdown): array
    {
        return (new MarkdownToTiptap)->convert($markdown);
    }
}
