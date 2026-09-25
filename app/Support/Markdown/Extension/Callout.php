<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Block\AbstractBlock;

final class Callout extends AbstractBlock
{
    public function __construct(public readonly string $icon)
    {
        parent::__construct();
    }
}
