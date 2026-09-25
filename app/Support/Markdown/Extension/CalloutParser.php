<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/**
 * Holds any blocks until a `:::` line closes the callout.
 */
final class CalloutParser extends AbstractBlockContinueParser
{
    private Callout $block;

    public function __construct(string $icon)
    {
        $this->block = new Callout($icon);
    }

    public function getBlock(): Callout
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
