<?php

namespace Tests\Feature;

use App\Support\Markdown\TiptapMarkdown;
use Tests\TestCase;

class TiptapMarkdownTest extends TestCase
{
    public function test_markdown_round_trips_through_tiptap_json()
    {
        $markdown = <<<'MD'
# Title

Some **bold**, *italic*, ~~gone~~, `code` and a [link](https://example.com).
Second line after a hard break.

## Lists

- one
- two
  - nested

1. first
2. second

- [ ] todo
- [x] done

> quoted
> text

```php
echo 'hi';
```

```mermaid
flowchart TD
  A --> B
```

:::callout 🔥
Callout body
:::

| Name | Role |
| --- | --- |
| Ada | Eng |
| Lin \| Ops |  |

---

Euler: $e^{i\pi} + 1 = 0$ while prices like $5 and $10 stay text.

$$
\int_0^1 x^2\,dx = \frac{1}{3}
$$
MD;

        $doc = TiptapMarkdown::toDoc($markdown);

        $this->assertSame($markdown."\n", TiptapMarkdown::toMarkdown($doc));
    }

    public function test_it_builds_the_expected_nodes()
    {
        $doc = TiptapMarkdown::toDoc("- [x] ship **it**\n\n```mermaid\npie\n```");

        $this->assertSame('taskList', $doc['content'][0]['type']);
        $this->assertTrue($doc['content'][0]['content'][0]['attrs']['checked']);
        $this->assertSame(
            [['type' => 'text', 'text' => 'ship '], ['type' => 'text', 'text' => 'it', 'marks' => [['type' => 'bold']]]],
            $doc['content'][0]['content'][0]['content'][0]['content'],
        );
        $this->assertSame(['language' => 'mermaid'], $doc['content'][1]['attrs']);
    }

    public function test_math_becomes_math_nodes()
    {
        $doc = TiptapMarkdown::toDoc("Area is \$\\pi r^2\$ for \$5 and \$10.\n\n\$\$a^2 + b^2 = c^2\$\$");

        $this->assertSame(
            [
                ['type' => 'text', 'text' => 'Area is '],
                ['type' => 'inlineMath', 'attrs' => ['latex' => '\\pi r^2']],
                ['type' => 'text', 'text' => ' for $5 and $10.'],
            ],
            $doc['content'][0]['content'],
        );
        $this->assertSame(['type' => 'blockMath', 'attrs' => ['latex' => 'a^2 + b^2 = c^2']], $doc['content'][1]);
    }

    public function test_a_board_embed_survives_the_round_trip()
    {
        // The note keeps what it points at, not a copy of the board
        $markdown = "```board\nref: k3x9m2p7qa\nframe: i4\n```";
        $doc = TiptapMarkdown::toDoc($markdown);

        $this->assertSame('codeBlock', $doc['content'][0]['type']);
        $this->assertSame('board', $doc['content'][0]['attrs']['language']);
        $this->assertSame($markdown, trim(TiptapMarkdown::toMarkdown($doc)));
    }

    public function test_empty_input_produces_a_valid_document()
    {
        $this->assertSame(['type' => 'doc', 'content' => [['type' => 'paragraph']]], TiptapMarkdown::toDoc(''));
        $this->assertSame('', TiptapMarkdown::toMarkdown(null));
    }

    public function test_an_image_on_its_own_line_round_trips_as_an_image_block()
    {
        $markdown = "Before\n\n![A cat](https://example.com/cat.png)\n\nAfter";

        $doc = TiptapMarkdown::toDoc($markdown);

        $this->assertSame(
            ['type' => 'image', 'attrs' => ['src' => 'https://example.com/cat.png', 'alt' => 'A cat']],
            $doc['content'][1],
        );
        $this->assertSame($markdown."\n", TiptapMarkdown::toMarkdown($doc));
    }

    public function test_a_video_on_its_own_line_round_trips_as_a_video_block()
    {
        $markdown = "Before\n\n<video src=\"/drive/files/abc\" title=\"Tom &amp; Jerry.mp4\"></video>\n\nAfter";

        $doc = TiptapMarkdown::toDoc($markdown);

        $this->assertSame(
            ['type' => 'video', 'attrs' => ['src' => '/drive/files/abc', 'title' => 'Tom & Jerry.mp4']],
            $doc['content'][1],
        );
        $this->assertSame($markdown."\n", TiptapMarkdown::toMarkdown($doc));

        // Written by hand: next to other lines, single quotes, no closing tag
        $doc = TiptapMarkdown::toDoc("Watch this:\n<video src='/drive/files/xyz'>\nThat was it.");

        $this->assertSame(['paragraph', 'video', 'paragraph'], array_column($doc['content'], 'type'));
        $this->assertSame(['src' => '/drive/files/xyz', 'title' => null], $doc['content'][1]['attrs']);

        // Any other HTML is still kept as the words it is
        $this->assertSame('paragraph', TiptapMarkdown::toDoc('<div>hi</div>')['content'][0]['type']);
    }

    public function test_a_page_break_round_trips_as_its_own_block()
    {
        $markdown = "Cover\n\n<!-- pagebreak -->\n\n# Chapter one";

        $doc = TiptapMarkdown::toDoc($markdown);

        $this->assertSame(['paragraph', 'pageBreak', 'heading'], array_column($doc['content'], 'type'));
        $this->assertSame($markdown."\n", TiptapMarkdown::toMarkdown($doc));

        // However it is spelled, it is read as a break and written back the one way
        $doc = TiptapMarkdown::toDoc("A\n\n<!--Page-Break-->\n\nB");
        $this->assertSame('pageBreak', $doc['content'][1]['type']);
        $this->assertSame("A\n\n<!-- pagebreak -->\n\nB\n", TiptapMarkdown::toMarkdown($doc));

        // Any other comment is not one
        $this->assertSame('paragraph', TiptapMarkdown::toDoc('<!-- note to self -->')['content'][0]['type']);
    }

    public function test_it_follows_commonmark_for_escapes_and_nested_emphasis()
    {
        $this->assertSame(
            [['type' => 'text', 'text' => 'Not *italic* or $math$']],
            TiptapMarkdown::toDoc('Not \*italic\* or \$math$')['content'][0]['content'],
        );
        $this->assertSame(
            [['type' => 'text', 'text' => 'both', 'marks' => [['type' => 'italic'], ['type' => 'bold']]]],
            TiptapMarkdown::toDoc('***both***')['content'][0]['content'],
        );
        $this->assertSame(
            [['type' => 'text', 'text' => '$x$', 'marks' => [['type' => 'code']]]],
            TiptapMarkdown::toDoc('`$x$`')['content'][0]['content'],
        );
    }

    public function test_an_image_inside_a_line_of_text_stays_text()
    {
        $this->assertSame(
            [['type' => 'text', 'text' => 'see ![cat](cat.png) here']],
            TiptapMarkdown::toDoc('see ![cat](cat.png) here')['content'][0]['content'],
        );
    }

    public function test_loose_lists_tilde_fences_and_setext_headings_parse()
    {
        $doc = TiptapMarkdown::toDoc("Title\n=====\n\n- a\n\n- b\n\n~~~\ncode\n~~~");

        $this->assertSame(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Title']]], $doc['content'][0]);
        $this->assertCount(2, $doc['content'][1]['content']);
        $this->assertSame('codeBlock', $doc['content'][2]['type']);
    }

    public function test_a_list_mixing_tasks_and_plain_items_is_split()
    {
        $doc = TiptapMarkdown::toDoc("- [ ] todo\n- plain\n- [x] done");

        $this->assertSame(['taskList', 'bulletList', 'taskList'], array_column($doc['content'], 'type'));
    }
}
