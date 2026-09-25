<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * `$…$` inline math, by pandoc's rules: no space just inside the dollars and no
 * digit right after the closing one, so "$5 and $10" stays text.
 */
final class InlineMathParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('(?<![\\\\$])\$(?=[^\s$])([^$\n]*?[^\s$\\\\])\$(?![\d$])');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new InlineMath($inlineContext->getSubMatches()[0]));

        return true;
    }
}
