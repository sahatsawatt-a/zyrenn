<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/**
 * Collects LaTeX lines until the closing `$$`. A block opened by a whole
 * `$$…$$` line ($oneLine) ends on that line.
 */
final class MathBlockParser extends AbstractBlockContinueParser
{
    private MathBlock $block;

    /** @var array<int, string> */
    private array $lines = [];

    public function __construct(private readonly ?string $oneLine = null)
    {
        $this->block = new MathBlock;
    }

    public function getBlock(): MathBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($this->oneLine !== null) {
            return BlockContinue::none();
        }

        if (trim($cursor->getRemainder()) === '$$') {
            return BlockContinue::finished();
        }

        return BlockContinue::at($cursor);
    }

    public function addLine(string $line): void
    {
        $this->lines[] = $line;
    }

    public function closeBlock(): void
    {
        $this->block->latex = trim($this->oneLine ?? implode("\n", $this->lines));
    }
}
