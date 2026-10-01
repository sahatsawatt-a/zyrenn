<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Controller;
use App\Mcp\Tools\Drive\RequestUpload;
use App\Mcp\Tools\DriveTool;
use App\Models\Drive\DriveFile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Take one file through a link from the request-upload MCP tool: an agent
 * uploads a file from its own disk with curl, rather than spending the whole
 * file in tokens as base64. No session -- the signed link says whose Drive,
 * which folder, and who is uploading.
 *
 * Every answer is JSON, short, and meant for an agent to read.
 */
class DriveUploadLinkController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->hasValidRelativeSignature()) {
            return $this->error('This upload link is not valid or has run out; get a new one with request-upload.', 403);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:'.DriveFile::MAX_KB],
        ], [
            'file.required' => 'Send the file as the multipart field "file", e.g. curl -F \'file=@/path/to/file.png\' ...',
            'file.max' => 'That file is larger than the '.intdiv(DriveFile::MAX_KB, 1024).' MB limit.',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $user = User::query()->whereKey($request->integer('user'))->first();
        $project = $request->filled('project') ? Project::query()->whereKey($request->integer('project'))->first() : null;

        if (! $user || ($request->filled('project') && ! $project)) {
            return $this->error('The Drive this link was for is gone.', 404);
        }

        // They may have lost the right to add to the project since the link was made
        if ($project && ! Gate::forUser($user)->allows('contribute', $project)) {
            return $this->error("The user can no longer add files to \"{$project->name}\".", 403);
        }

        $owner = $project ?? $user;
        $folder = $request->filled('folder') ? $owner->driveFolders()->where('ref_id', $request->query('folder'))->first() : null;

        if ($request->filled('folder') && ! $folder) {
            return $this->error('The folder this link was for has been deleted; get a new link with request-upload.', 404);
        }

        // Spent before the file is stored, so two uploads racing on one link can't both land
        if (! Cache::add('drive-upload-link:'.$request->query('nonce'), true, now()->addMinutes(RequestUpload::EXPIRES_MINUTES + 1))) {
            return $this->error('This upload link has been used; get a new one with request-upload.', 410);
        }

        $file = DriveFile::store($request->file('file'), $owner, $folder, $user);

        return response()->json(DriveTool::summary($file), 201);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
