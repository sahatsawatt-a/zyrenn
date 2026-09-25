<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Block\AbstractBlock;

final class MathBlock extends AbstractBlock
{
    public string $latex = '';
}
