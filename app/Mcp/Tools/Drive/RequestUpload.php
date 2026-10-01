<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get a one-time link to upload a file from your own disk into the Drive, so the bytes never pass through the conversation -- the way to upload a picture or a video you have as a file. Run the "curl" command from the response with the file\'s path in it; it answers with the same JSON as upload-file ("url", "markdown", ...). The link takes one file, once, within 15 minutes.')]
class RequestUpload extends DriveTool
{
    public const EXPIRES_MINUTES = 15;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'folder' => $this->folderArgument($schema, 'Folder the file goes into; folders that don\'t exist yet are created.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);

        $validated = $request->validate([
            'folder' => ['nullable', 'string', 'max:1000'],
        ]);

        $folder = $this->ensureFolderAt($owner, $validated['folder'] ?? '', $user);
        $expires = now()->addMinutes(self::EXPIRES_MINUTES);

        // Signed as a path, so it holds whichever host the client reaches the
        // app by; the nonce is what makes it good for one upload only
        $link = url(URL::temporarySignedRoute('drive.upload-link', $expires, array_filter([
            'user' => $user->id,
            'project' => $owner instanceof Project ? $owner->id : null,
            'folder' => $folder?->ref_id,
            'nonce' => Str::random(32),
        ]), absolute: false));

        return Response::structured([
            'upload_url' => $link,
            'expires_at' => $expires->toIso8601String(),
            'curl' => "curl -sS -H 'Accept: application/json' -F 'file=@/path/to/file.png' '{$link}'",
            'note' => 'Send the file as the multipart field "file". It keeps its name from disk; to store it under another, add ;filename=new-name.png after the path.',
        ]);
    }
}
