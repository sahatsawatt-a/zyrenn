<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * `{{ … }}`, a live value, on one line and with no braces inside it.
 */
final class InlineValueParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('\{\{[ \t]*([^{}\n]*?[^{}\s])[ \t]*\}\}');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new InlineValue($inlineContext->getSubMatches()[0]));

        return true;
    }
}
