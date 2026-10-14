<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/**
 * Holds any blocks until a `:::` line closes the callout, or the reveal:
 * both are a `:::` line, what they hold, and a `:::` line.
 */
final class CalloutParser extends AbstractBlockContinueParser
{
    public function __construct(private readonly Callout|Reveal $block) {}

    public function getBlock(): Callout|Reveal
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): BlockContinue
    {
        if (! $cursor->isIndented() && trim($cursor->getRemainder()) === ':::') {
            $cursor->advanceToEnd();

            return BlockContinue::finished();
        }

        return BlockContinue::at($cursor);
    }
}
