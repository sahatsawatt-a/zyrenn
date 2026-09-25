<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Opens a math block on a `$$` line, or reads a whole `$$…$$` line.
 */
final class MathBlockStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        $line = trim($cursor->getRemainder());

        if ($line === '$$') {
            $parser = new MathBlockParser;
        } elseif (preg_match('/^\$\$(.+)\$\$$/', $line, $m)) {
            $parser = new MathBlockParser($m[1]);
        } else {
            return BlockStart::none();
        }

        $cursor->advanceToEnd();

        return BlockStart::of($parser)->at($cursor);
    }
}
