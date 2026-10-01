<?php

namespace App\Support\Chat;

use App\Support\TiptapMarkdown;

/**
 * An agent's Markdown as the note editor's document, converted by the same
 * code that reads a note over MCP, so a chat shows headings, lists, code,
 * tables and formulas exactly as a note does.
 *
 * One difference: a model's picture or video from outside would load the
 * moment it is shown, and its address can carry what the model was told. So
 * only the ones in the Drive stay; the others are shown as text, address and
 * all, and open only if the user chooses to follow them.
 */
class ChatMarkdown
{
    /**
     * @return array{type: string, content: array<int, array<string, mixed>>}
     */
    public static function toDoc(string $markdown): array
    {
        $doc = TiptapMarkdown::toDoc($markdown);
        $doc['content'] = self::withoutRemoteMedia($doc['content']);

        return $doc;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function withoutRemoteMedia(array $nodes): array
    {
        return array_map(function (array $node) {
            if (in_array($node['type'] ?? null, ['image', 'video'], true) && ! self::inDrive((string) ($node['attrs']['src'] ?? ''))) {
                $label = $node['type'] === 'image' ? 'Picture' : 'Video';

                return [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => "[{$label} not shown: ".($node['attrs']['src'] ?? '').']']],
                ];
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = self::withoutRemoteMedia($node['content']);
            }

            return $node;
        }, $nodes);
    }

    private static function inDrive(string $src): bool
    {
        return (bool) preg_match('~^/drive/files/[a-z0-9]+$~i', $src);
    }
}
