<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * The note editor's Markdown syntax beyond GFM: callouts (`:::callout 💡` … `:::`),
 * `$$` math blocks, `$…$` inline math, `{{ … }}` live values and `[[Page]]` links.
 */
final class NoteSyntaxExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addBlockStartParser(new CalloutStartParser, 55)
            ->addBlockStartParser(new MathBlockStartParser, 55)
            ->addInlineParser(new InlineMathParser)
            ->addInlineParser(new InlineValueParser)
            // Before the brackets of an ordinary link are read as one
            ->addInlineParser(new PageLinkParser, 50);
    }
}
