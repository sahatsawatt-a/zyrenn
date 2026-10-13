<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * `[[Page]]`, `[[Page|shown]]` and `[[board:Page]]`, on one line.
 */
final class PageLinkParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('\[\[([^\[\]|\n]+)(?:\|([^\[\]\n]+))?\]\]');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        [$target, $text] = [...$inlineContext->getSubMatches(), ''];
        $kind = '';

        // Only a kind there is counts: "Plan: phase 1" is a title with a colon in it
        if (preg_match('/^(note|board|table|trip):\s*(\S.*)$/iu', trim($target), $said)) {
            $kind = strtolower($said[1]);
            $target = $said[2];
        }

        if (trim($target) === '') {
            return false;
        }

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new PageLink(trim($target), trim($text), $kind));

        return true;
    }
}
