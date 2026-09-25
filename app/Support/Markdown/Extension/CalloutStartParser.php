<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Opens a callout on a `:::callout` line; anything after it is the icon.
 */
final class CalloutStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || ! preg_match('/^:::callout\s*(.*)$/', trim($cursor->getRemainder()), $m)) {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        return BlockStart::of(new CalloutParser($m[1] !== '' ? $m[1] : '💡'))->at($cursor);
    }
}
