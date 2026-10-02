<?php

namespace App\Support\Markdown;

use App\Support\Markdown\Extension\Callout;
use App\Support\Markdown\Extension\InlineMath;
use App\Support\Markdown\Extension\MathBlock;
use App\Support\Markdown\Extension\NoteSyntaxExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Reads Markdown into the note editor's Tiptap JSON: league/commonmark parses
 * it (CommonMark, GFM tables / task lists / strikethrough, and our
 * NoteSyntaxExtension) and each node of the tree maps to a Tiptap node.
 *
 * A single newline inside a paragraph is a hard break, and an image on a line
 * of its own is an image block. So is a <video> tag, as a video block.
 */
final class MarkdownToTiptap
{
    /** How a page break is written (TiptapToMarkdown writes it, pageBreak() reads it). */
    public const PAGE_BREAK = '<!-- pagebreak -->';

    private MarkdownParser $parser;

    public function __construct()
    {
        $environment = new Environment;
        $environment
            ->addExtension(new CommonMarkCoreExtension)
            ->addExtension(new TableExtension)
            ->addExtension(new StrikethroughExtension)
            ->addExtension(new TaskListExtension)
            ->addExtension(new NoteSyntaxExtension);

        $this->parser = new MarkdownParser($environment);
    }

    /**
     * @return array{type: string, content: array<int, array<string, mixed>>}
     */
    public function convert(string $markdown): array
    {
        $blocks = $this->blocks($this->parser->parse($markdown));

        return ['type' => 'doc', 'content' => $blocks ?: [['type' => 'paragraph']]];
    }

    // ------------------------------------------------------------------
    // Blocks
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blocks(Node $parent): array
    {
        $blocks = [];

        foreach ($parent->children() as $child) {
            array_push($blocks, ...$this->block($child));
        }

        return $blocks;
    }

    /**
     * One Markdown block can become several Tiptap blocks (a paragraph split by
     * an image line, a list mixing task and plain items).
     *
     * @return array<int, array<string, mixed>>
     */
    private function block(Node $node): array
    {
        return match (true) {
            $node instanceof Paragraph => $this->paragraph($node),
            $node instanceof Heading => [$this->node('heading', ['level' => $node->getLevel()], $this->inlines($node))],
            $node instanceof FencedCode => [$this->codeBlock($node->getInfoWords()[0] ?? '', $node->getLiteral())],
            $node instanceof IndentedCode => [$this->codeBlock('', $node->getLiteral())],
            $node instanceof BlockQuote => [$this->node('blockquote', content: $this->blocks($node) ?: [['type' => 'paragraph']])],
            $node instanceof ListBlock => $this->lists($node),
            $node instanceof ThematicBreak => [['type' => 'horizontalRule']],
            $node instanceof Table => [$this->table($node)],
            $node instanceof Callout => [$this->node('callout', ['icon' => $node->icon], $this->blocks($node) ?: [['type' => 'paragraph']])],
            $node instanceof MathBlock => $node->latex === '' ? [] : [$this->node('blockMath', ['latex' => $node->latex])],
            $node instanceof HtmlBlock => [$this->pageBreak($node->getLiteral()) ?? $this->video($node->getLiteral()) ?? $this->textParagraph($node->getLiteral())],
            default => [],
        };
    }

    /**
     * Splits the paragraph into lines, so a line holding only an image (or a
     * video tag) becomes a block of its own and the other lines stay
     * paragraphs joined by hard breaks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paragraph(Paragraph $paragraph): array
    {
        $lines = [[]];
        foreach ($paragraph->children() as $child) {
            if ($child instanceof Newline) {
                $lines[] = [];
            } else {
                $lines[array_key_last($lines)][] = $child;
            }
        }

        $blocks = [];
        $content = [];

        foreach ($lines as $line) {
            $media = $this->mediaLine($line);

            if ($media) {
                if ($content) {
                    $blocks[] = $this->node('paragraph', content: $this->mergeText($content));
                    $content = [];
                }

                $blocks[] = $media;

                continue;
            }

            if ($content) {
                $content[] = ['type' => 'hardBreak'];
            }
            array_push($content, ...$this->inlineNodes($line));
        }

        if ($content || ! $blocks) {
            $blocks[] = $this->node('paragraph', content: $this->mergeText($content));
        }

        return $blocks;
    }

    /**
     * The image or video block a line of a paragraph is, when that is all
     * the line holds.
     *
     * @param  array<int, Node>  $line
     * @return array<string, mixed>|null
     */
    private function mediaLine(array $line): ?array
    {
        if (count($line) === 1 && $line[0] instanceof Image) {
            $alt = $this->plainText($line[0]);

            return $this->node('image', ['src' => $line[0]->getUrl(), 'alt' => $alt !== '' ? $alt : null]);
        }

        $html = '';

        foreach ($line as $node) {
            if (! $node instanceof HtmlInline) {
                return null;
            }

            $html .= $node->getLiteral();
        }

        return $html !== '' ? $this->video($html) : null;
    }

    /**
     * A page break, on a line of its own: "<!-- pagebreak -->" (also
     * "page-break", any case or spacing).
     *
     * @return array<string, mixed>|null
     */
    private function pageBreak(string $html): ?array
    {
        return preg_match('~^\s*<!--\s*page[\s-]?break\s*-->\s*$~i', $html) ? ['type' => 'pageBreak'] : null;
    }

    /**
     * A video block, from HTML that is one <video> tag and nothing else.
     *
     * @return array<string, mixed>|null
     */
    private function video(string $html): ?array
    {
        if (! preg_match('~^\s*<video\b([^>]*)>\s*(?:</video>)?\s*$~i', $html, $tag)) {
            return null;
        }

        // name="value" or name='value'
        preg_match_all('~\b(src|title)\s*=\s*(["\'])(.*?)\2~i', $tag[1], $found, PREG_SET_ORDER);

        $attrs = [];

        foreach ($found as $attribute) {
            $attrs[strtolower($attribute[1])] = html_entity_decode($attribute[3], ENT_QUOTES | ENT_HTML5);
        }

        if (($attrs['src'] ?? '') === '') {
            return null;
        }

        return $this->node('video', ['src' => $attrs['src'], 'title' => ($attrs['title'] ?? '') !== '' ? $attrs['title'] : null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function codeBlock(string $language, string $code): array
    {
        $code = preg_replace('/\n$/', '', $code);

        return $this->node(
            'codeBlock',
            ['language' => $language !== '' ? strtolower($language) : 'plaintext'],
            $code !== '' ? [['type' => 'text', 'text' => $code]] : [],
        );
    }

    /**
     * A bullet list whose items start with `[ ]` / `[x]` is a task list; a
     * list mixing both is split into runs of each.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lists(ListBlock $list): array
    {
        $data = $list->getListData();

        if ($data->type === ListBlock::TYPE_ORDERED) {
            $items = array_map(
                fn (Node $item) => $this->node('listItem', content: $this->listItemContent($item)),
                iterator_to_array($list->children(), false),
            );

            return [$this->node('orderedList', ($data->start ?? 1) !== 1 ? ['start' => $data->start] : [], $items)];
        }

        $lists = [];
        $kind = 'bulletList';
        $items = [];

        foreach ($list->children() as $item) {
            $marker = $this->taskMarker($item);

            if ($items && ($marker ? 'taskList' : 'bulletList') !== $kind) {
                $lists[] = $this->node($kind, content: $items);
                $items = [];
            }

            $kind = $marker ? 'taskList' : 'bulletList';
            $items[] = $marker
                ? $this->node('taskItem', ['checked' => $marker->isChecked()], $this->listItemContent($item))
                : $this->node('listItem', content: $this->listItemContent($item));
        }

        if ($items) {
            $lists[] = $this->node($kind, content: $items);
        }

        return $lists;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listItemContent(Node $item): array
    {
        return $this->blocks($item) ?: [['type' => 'paragraph']];
    }

    private function taskMarker(Node $item): ?TaskListItemMarker
    {
        $marker = $item->firstChild()?->firstChild();

        return $marker instanceof TaskListItemMarker ? $marker : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Table $table): array
    {
        $rows = [];

        foreach ($table->children() as $section) {
            foreach ($section->children() as $row) {
                $cells = [];

                foreach ($row->children() as $cell) {
                    $header = $cell instanceof TableCell && $cell->getType() === TableCell::TYPE_HEADER;
                    $cells[] = $this->node(
                        $header ? 'tableHeader' : 'tableCell',
                        content: [$this->node('paragraph', content: $this->inlines($cell))],
                    );
                }

                $rows[] = $cells;
            }
        }

        // Every row gets the header's column count
        $columns = count($rows[0] ?? []);
        $rows = array_map(
            fn (array $cells) => $this->node('tableRow', content: array_pad(
                array_slice($cells, 0, $columns),
                $columns,
                ['type' => 'tableCell', 'content' => [['type' => 'paragraph']]],
            )),
            $rows,
        );

        return $this->node('table', content: $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function textParagraph(string $text): array
    {
        $content = [];

        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if ($content) {
                $content[] = ['type' => 'hardBreak'];
            }
            $content[] = ['type' => 'text', 'text' => $line];
        }

        return $this->node('paragraph', content: $this->mergeText($content));
    }

    // ------------------------------------------------------------------
    // Inline content
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inlines(Node $parent): array
    {
        return $this->mergeText($this->inlineNodes(iterator_to_array($parent->children(), false)));
    }

    /**
     * @param  array<int, Node>  $nodes
     * @param  array<int, array<string, mixed>>  $marks  Outermost first
     * @return array<int, array<string, mixed>>
     */
    private function inlineNodes(array $nodes, array $marks = []): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $children = fn (array $mark) => $this->inlineNodes(iterator_to_array($node->children(), false), [...$marks, $mark]);

            array_push($out, ...match (true) {
                $node instanceof Text => [$this->text($node->getLiteral(), $marks)],
                $node instanceof Code => [$this->text($node->getLiteral(), [...$marks, ['type' => 'code']])],
                $node instanceof Strong => $children(['type' => 'bold']),
                $node instanceof Emphasis => $children(['type' => 'italic']),
                $node instanceof Strikethrough => $children(['type' => 'strike']),
                $node instanceof Link => $children(['type' => 'link', 'attrs' => ['href' => $node->getUrl()]]),
                // Images inside a line of text have no inline node in the editor
                $node instanceof Image => [$this->text('!['.$this->plainText($node).']('.$node->getUrl().')', $marks)],
                $node instanceof InlineMath => [['type' => 'inlineMath', 'attrs' => ['latex' => $node->latex]]],
                $node instanceof Newline => [['type' => 'hardBreak']],
                $node instanceof HtmlInline => [$this->text($node->getLiteral(), $marks)],
                // Bullet list task markers become taskItem attrs; in ordered lists they stay text
                $node instanceof TaskListItemMarker => $this->taskMarkerText($node, $marks),
                default => $this->inlineNodes(iterator_to_array($node->children(), false), $marks),
            });
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<int, array<string, mixed>>
     */
    private function taskMarkerText(TaskListItemMarker $marker, array $marks): array
    {
        $list = $marker->parent()?->parent()?->parent();

        if ($list instanceof ListBlock && $list->getListData()->type === ListBlock::TYPE_ORDERED) {
            return [$this->text($marker->isChecked() ? '[x]' : '[ ]', $marks)];
        }

        // Drop the space the marker leaves in front of the item's text
        if ($marker->next() instanceof Text) {
            $marker->next()->setLiteral(ltrim($marker->next()->getLiteral()));
        }

        return [];
    }

    /**
     * Joins neighbouring text nodes with the same marks and drops empty ones.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function mergeText(array $nodes): array
    {
        $merged = [];

        foreach ($nodes as $node) {
            if ($node['type'] === 'text' && $node['text'] === '') {
                continue;
            }

            $last = array_key_last($merged);

            if (
                $last !== null
                && $node['type'] === 'text'
                && $merged[$last]['type'] === 'text'
                && ($merged[$last]['marks'] ?? []) === ($node['marks'] ?? [])
            ) {
                $merged[$last]['text'] .= $node['text'];

                continue;
            }

            $merged[] = $node;
        }

        return $merged;
    }

    private function plainText(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof Text || $child instanceof Code) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // Tiptap nodes
    // ------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private function text(string $text, array $marks): array
    {
        return $marks ? ['type' => 'text', 'text' => $text, 'marks' => $marks] : ['type' => 'text', 'text' => $text];
    }

    /**
     * A Tiptap node, leaving out empty attrs and content.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private function node(string $type, array $attrs = [], array $content = []): array
    {
        return array_filter(
            ['type' => $type, 'attrs' => $attrs, 'content' => $content],
            fn (mixed $value) => $value !== [],
        );
    }
}
