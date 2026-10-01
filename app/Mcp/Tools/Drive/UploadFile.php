<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use App\Models\Drive\DriveFile;
use App\Models\Owner;
use App\Models\User;
use App\Support\RemoteDownload;
use App\Support\RemoteDownloadFailed;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Put a file into the user\'s Drive and get back the URL it is served from: "source_url" has the server fetch it from the public web (a picture, an MP4, a PDF ...), and "text" writes a plain text file. For a file on your own disk use request-upload instead -- file bytes are never sent through here. To put an image in a note: upload it, then use the "markdown" line from the response inside create-note or update-note.')]
class UploadFile extends DriveTool
{
    /** For text sent inline; a fetched file may be as big as the Drive takes. */
    private const MAX_TEXT_BYTES = 50 * 1024 * 1024;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(255)->description('File name including its extension, e.g. "diagram.png". The extension decides how the file is served back. Required with text; with source_url it defaults to the last part of the URL.'),
            'text' => $schema->string()->description('Plain text contents, for a text file such as .md, .txt or .csv.'),
            'source_url' => $schema->string()->max(2000)->description('An http(s) URL on the public web for the server to download the file from, e.g. a picture or an MP4. Max 500 MB.'),
            'folder' => $this->folderArgument($schema, 'Folder to upload into; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string'],
            'source_url' => ['nullable', 'string', 'max:2000'],
            'folder' => ['nullable', 'string', 'max:1000'],
        ]);

        if (isset($validated['source_url']) === isset($validated['text'])) {
            return Response::error('Pass either source_url or text, not both and not neither. For a file on your own disk, use request-upload.');
        }

        if (isset($validated['source_url'])) {
            try {
                ['path' => $temp, 'name' => $name] = RemoteDownload::fetch($validated['source_url'], DriveFile::MAX_KB * 1024);
            } catch (RemoteDownloadFailed $e) {
                return Response::error($e->getMessage());
            }
        } else {
            if (! isset($validated['name'])) {
                return Response::error('Pass a "name" for the file, e.g. "notes.md".');
            }

            if (strlen($validated['text']) > self::MAX_TEXT_BYTES) {
                return Response::error('That text is larger than the 50 MB limit.');
            }

            $temp = (string) tempnam(sys_get_temp_dir(), 'mcp-upload-');
            file_put_contents($temp, $validated['text']);
            $name = $validated['name'];
        }

        try {
            return $this->store($owner, $user, $temp, $validated['name'] ?? $name, $validated['folder'] ?? '');
        } finally {
            @unlink($temp);
        }
    }

    private function store(Owner $owner, User $user, string $temp, string $name, string $folder): Response|ResponseFactory
    {
        // Only the file name itself: a client should not be able to aim the
        // upload at a path of its choosing
        $name = basename(str_replace('\\', '/', trim($name)));

        if ($name === '' || $name === '.' || $name === '..') {
            return Response::error('That name is not a usable file name.');
        }

        $folder = $this->ensureFolderAt($owner, $folder, $user);

        // test: true -- these bytes were fetched or written here, not sent as a PHP upload
        $file = DriveFile::store(new UploadedFile($temp, $name, mime_content_type($temp) ?: null, null, true), $owner, $folder, $user);

        return Response::structured($this->summary($file));
    }
}
