<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use App\Models\Drive\DriveFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Upload a file into the user\'s Drive and get back the URL it is served from. Send bytes as base64 in "content_base64" (any file, including images and PDFs) or plain text in "text". To put an image in a note: upload it here, then use the "markdown" line from the response inside create-note or update-note.')]
class UploadFile extends DriveTool
{
    /** Matches the web uploader's limit. */
    private const MAX_BYTES = 50 * 1024 * 1024;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(255)->description('File name including its extension, e.g. "diagram.png". The extension decides how the file is served back.')->required(),
            'content_base64' => $schema->string()->description('The file\'s bytes, base64 encoded. Use this for images and any other binary file. Max 50 MB decoded.'),
            'text' => $schema->string()->description('Plain text contents, as an alternative to content_base64 (e.g. for .md, .txt or .csv).'),
            'folder' => $this->folderArgument($schema, 'Folder to upload into; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'content_base64' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'folder' => ['nullable', 'string', 'max:1000'],
        ]);

        if (isset($validated['content_base64']) === isset($validated['text'])) {
            return Response::error('Pass either content_base64 or text, not both and not neither.');
        }

        if (isset($validated['content_base64'])) {
            // strict: a client that sent something other than base64 should hear
            // about it rather than get a file full of rubbish
            $bytes = base64_decode($validated['content_base64'], true);

            if ($bytes === false) {
                return Response::error('content_base64 is not valid base64.');
            }
        } else {
            $bytes = $validated['text'];
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return Response::error('That file is larger than the 50 MB limit.');
        }

        // Only the file name itself: a client should not be able to aim the
        // upload at a path of its choosing
        $name = basename(str_replace('\\', '/', trim($validated['name'])));

        if ($name === '' || $name === '.' || $name === '..') {
            return Response::error('That name is not a usable file name.');
        }

        $folder = $this->ensureFolderAt($user, $validated['folder'] ?? '');

        $temp = tempnam(sys_get_temp_dir(), 'mcp-upload-');
        file_put_contents($temp, $bytes);

        try {
            // test: true -- these bytes arrived over MCP, not through a PHP upload
            $file = DriveFile::store(new UploadedFile($temp, $name, mime_content_type($temp) ?: null, null, true), $user, $folder);
        } finally {
            @unlink($temp);
        }

        return Response::structured($this->summary($file));
    }
}
