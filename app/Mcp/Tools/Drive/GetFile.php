<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use App\Models\Drive\DriveFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get one Drive file: its details plus the file itself where that makes sense -- images come back as an image, text files as their text. The response also carries the "markdown" line for embedding an image in a note.')]
class GetFile extends DriveTool
{
    /** An image much bigger than this is not worth pushing through a tool call. */
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $owner = $this->targetOwner($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $file = $this->findFile($owner, $validated['ref_id']);

        if (! $file) {
            return Response::error("File {$validated['ref_id']} was not found.");
        }

        $disk = Storage::disk(DriveFile::DISK);

        if (! $disk->exists($file->path)) {
            return Response::error("File {$validated['ref_id']} (\"{$file->name}\") is listed in the Drive but its contents are missing.");
        }

        $summary = $this->summary($file);
        $parts = [Response::text("{$file->name} ({$file->kind}, {$file->size} bytes) at {$file->url()}")];

        if ($file->isImage() && $file->mime !== 'image/svg+xml' && $file->size <= self::MAX_IMAGE_BYTES) {
            $parts[] = Response::image($disk->get($file->path), (string) $file->mime);
        } elseif ($this->isText($file) && $file->size <= self::MAX_INLINE_BYTES) {
            $text = (string) $disk->get($file->path);
            $parts[] = Response::text($text);
            $summary['text'] = $text;
        }

        return Response::make($parts)->withStructuredContent($summary);
    }

    /**
     * Files worth handing back as text. SVG counts: it is markup, and reading
     * it is more useful than rendering it.
     */
    private function isText(DriveFile $file): bool
    {
        return str_starts_with((string) $file->mime, 'text/')
            || in_array($file->mime, ['application/json', 'application/xml', 'image/svg+xml'], true)
            || in_array($file->ext, ['md', 'txt', 'csv', 'json', 'xml', 'yml', 'yaml', 'log', 'svg'], true);
    }
}
