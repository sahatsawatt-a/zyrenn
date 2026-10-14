<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Opens a callout on a `:::callout` line -- anything after it is the icon --
 * and a reveal on a `:::reveal` line, where anything after it is what the
 * line says.
 */
final class CalloutStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || ! preg_match('/^:::(callout|reveal)\s*(.*)$/u', trim($cursor->getRemainder()), $m)) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        $block = $m[1] === 'reveal'
            ? new Reveal($m[2] !== '' ? $m[2] : 'Answer')
            : new Callout($m[2] !== '' ? $m[2] : '💡');

        return BlockStart::of(new CalloutParser($block))->at($cursor);
    }
}
