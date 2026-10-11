<?php

namespace App\Support\Workflow\Steps;

use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Support\Folders;

/**
 * Find files: what is in the Drive of the workflow's owner -- in one folder
 * or anywhere, by part of a name, of one kind -- newest first, as files.
 */
final class DriveFind implements Step
{
    private const MOST = 100;

    public function run(StepContext $step): StepResult
    {
        $owner = $step->owner();
        $path = trim($step->text('folder'));
        $folder = $path === '' ? null : Folders::at($owner->driveFolders(), $path);
        $name = trim($step->text('name'));
        $kind = (string) ($step->setting('kind') ?: 'any');

        // No such folder holds no files
        $files = $folder === false ? collect() : $owner->driveFiles()
            ->when($folder instanceof DriveFolder, fn ($query) => $query->where('folder_id', $folder->id))
            ->when($name !== '', fn ($query) => $query->whereLike('name', '%'.addcslashes($name, '%_\\').'%'))
            ->when($kind !== 'any', fn ($query) => $query->where('kind', $kind))
            ->with('folder')
            ->latest()
            ->latest('id')
            ->limit(max(1, min(self::MOST, (int) ($step->setting('limit') ?: 20))))
            ->get();

        return new StepResult([
            'files' => $files->map(fn (DriveFile $file) => DriveSteps::summary($file))->all(),
            'count' => $files->count(),
        ]);
    }
}
