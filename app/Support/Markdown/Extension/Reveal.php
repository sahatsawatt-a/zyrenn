<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * Something kept out of sight until it is asked for -- the answers under a
 * set of questions: `:::reveal Answers` … `:::`. What follows "reveal" on
 * its line is what the line says.
 */
final class Reveal extends AbstractBlock
{
    public function __construct(public readonly string $title)
    {
        parent::__construct();
    }
}
