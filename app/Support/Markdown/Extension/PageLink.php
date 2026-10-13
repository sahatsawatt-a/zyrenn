<?php

namespace App\Support\Markdown\Extension;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * A link to another page by its name, [[Budget]] -- or [[Budget|the numbers]]
 * to show other words, or [[board:Launch]] to say which kind of page is meant.
 * Which page that is, is found when the note is saved (App\Support\Note\PageLinks).
 */
final class PageLink extends AbstractInline
{
    public function __construct(
        public readonly string $title,
        public readonly string $text = '',
        public readonly string $kind = '',
    ) {
        parent::__construct();
    }
}
