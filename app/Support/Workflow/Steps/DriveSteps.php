<?php

namespace App\Support\Workflow\Steps;

use App\Mcp\Tools\DriveTool;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Support\Folders;
use App\Support\RemoteDownloadFailed;
use App\Support\Workflow\Inbound\Hooks;
use App\Support\Workflow\Template;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * What the steps that handle files share. A file in a workflow is always one
 * in the Drive of the workflow's owner -- a person's own, or the project's --
 * and is passed from step to step as its ref_id: its bytes never go through
 * what a step gives out.
 */
final class DriveSteps
{
    /** The largest file a step fetches; Telegram itself hands a bot at most 20 MB. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** How long a fetch may take: within the time a step is given (Engine). */
    public const TIMEOUT = 120;

    /** How long a link LINE or another service fetches a file through stays good. */
    private const LINK_MINUTES = 60;

    /**
     * The file a setting names: by its ref_id, by the address it is served
     * from, or as a file an earlier step gave out whole.
     *
     * @throws StepFailed when the workflow's owner has no such file
     */
    public static function file(StepContext $step, string $setting = 'file'): DriveFile
    {
        return self::find($step, $step->template($setting))
            ?? throw new StepFailed('Its file is gone, or is not in this workflow\'s Drive.');
    }

    /**
     * The file a setting names, or none when the setting is left empty or
     * comes to nothing -- for a file that only sometimes goes with something.
     *
     * @throws StepFailed when it names one the workflow's owner doesn't have
     */
    public static function fileIfAny(StepContext $step, string $setting = 'file'): ?DriveFile
    {
        if (! filled($step->setting($setting))) {
            return null;
        }

        $named = $step->template($setting);

        if ($named === null || (is_string($named) && trim($named) === '')) {
            return null;
        }

        return self::find($step, $named)
            ?? throw new StepFailed('Its file is gone, or is not in this workflow\'s Drive.');
    }

    /**
     * Every file a setting names: a list of them, or several ref_ids
     * written out with commas or spaces between.
     *
     * @return list<DriveFile>
     *
     * @throws StepFailed when one of them is not the owner's
     */
    public static function files(StepContext $step, string $setting): array
    {
        $named = $step->template($setting);
        $named = is_array($named) && array_is_list($named)
            ? $named
            : (is_string($named) ? (preg_split('/[\s,]+/', $named, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [$named]);

        return array_map(
            fn ($one) => self::find($step, $one) ?? throw new StepFailed('One of its files is gone, or is not in this workflow\'s Drive: '.mb_strimwidth(Template::text($one), 0, 60, '…')),
            array_values(array_filter($named, fn ($one) => $one !== null && $one !== '')),
        );
    }

    private static function find(StepContext $step, mixed $named): ?DriveFile
    {
        $ref = is_array($named) ? ($named['ref_id'] ?? '') : $named;
        $ref = trim(Template::text($ref));

        // As a note or a chat message points at it: /drive/files/k3x9m2p7qa
        if (preg_match('#/drive/files/([A-Za-z0-9]+)#', $ref, $found) === 1) {
            $ref = $found[1];
        }

        return $ref === '' ? null : $step->owner()->driveFiles()->where('ref_id', $ref)->first();
    }

    /**
     * The folder at a path, made when it is not there yet; null for the top.
     */
    public static function folder(StepContext $step, string $setting = 'folder'): ?DriveFolder
    {
        return Folders::ensure($step->owner()->driveFolders(), mb_substr($step->text($setting), 0, 1000), $step->run->actor());
    }

    /**
     * The file a step told to use the one there finds: of the name it gives,
     * in its folder. Null when it wasn't told to, gives no name, or there
     * is none -- and it is then fetched and kept.
     */
    public static function there(StepContext $step): ?DriveFile
    {
        if ($step->setting('same') !== 'reuse' || ($name = DriveFile::nameOnly($step->text('name'))) === '') {
            return null;
        }

        return $step->owner()->driveFiles()
            ->where('name', $name)
            ->where('folder_id', self::folder($step)?->id)
            ->latest('id')
            ->first();
    }

    /**
     * Keep fetched or written bytes in the Drive, under the step's "name"
     * (else the name they came with) and in its "folder", and give the file
     * out. The temporary file is deleted either way.
     *
     * @param  array{path: string, name: string}  $got
     *
     * @throws StepFailed when there is no name to keep it under
     */
    public static function keep(StepContext $step, array $got): StepResult
    {
        try {
            $name = DriveFile::nameOnly($step->text('name')) ?: DriveFile::nameOnly($got['name']);

            if ($name === '') {
                throw new StepFailed('Give the file a name, with its extension: notes.txt.');
            }

            // A name given without an extension keeps the one the file came with
            if (pathinfo($name, PATHINFO_EXTENSION) === '' && ($ext = pathinfo($got['name'], PATHINFO_EXTENSION)) !== '') {
                $name .= ".{$ext}";
            }

            $file = DriveFile::keep($got['path'], $name, $step->owner(), self::folder($step), $step->run->actor());
        } finally {
            @unlink($got['path']);
        }

        return new StepResult(self::summary($file));
    }

    /**
     * A download, its failures said as the step's own.
     *
     * @param  callable(): array{path: string, name: string, type?: string}  $fetch
     * @return array{path: string, name: string}
     */
    public static function fetched(callable $fetch, ?string $service = null): array
    {
        try {
            return $fetch();
        } catch (RemoteDownloadFailed $e) {
            throw new StepFailed($e->getMessage());
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach '.($service ?? 'that address').'.', retryable: true);
        }
    }

    /**
     * @throws StepFailed to be tried again, when the service says it is busy
     */
    public static function busy(Response $response, string $service, int $after): void
    {
        if ($response->status() === 429 || $response->serverError()) {
            throw new StepFailed("{$service} is busy ({$response->status()}).", retryable: true, retryAfter: $after);
        }
    }

    /**
     * A file as a step gives it out: the same as the Drive's MCP tools say
     * it, so "markdown" is the line that shows a picture in a note or a chat.
     *
     * @return array<string, mixed>
     */
    public static function summary(DriveFile $file): array
    {
        return collect(DriveTool::summary($file))->except(['user_id', 'project'])->all();
    }

    /**
     * Where the file's bytes are on this machine.
     *
     * @throws StepFailed when they are gone
     */
    public static function path(DriveFile $file): string
    {
        $disk = Storage::disk(DriveFile::DISK);

        return $disk->exists($file->path)
            ? $disk->path($file->path)
            : throw new StepFailed("\"{$file->name}\" is in the Drive, but its contents are gone.");
    }

    /**
     * An address anyone outside can fetch the file from, for an hour unless
     * told how long: it is signed, so it gives away that one file and
     * nothing else.
     *
     * @throws StepFailed when the app has no public address
     */
    public static function publicUrl(DriveFile $file, ?int $minutes = null): string
    {
        $base = Hooks::url('');

        if ($base === null) {
            throw new StepFailed('This is fetched from the app by the service it is sent through, which needs the app\'s public address (WORKFLOWS_PUBLIC_URL).');
        }

        $link = URL::temporarySignedRoute('hooks.file', now()->addMinutes($minutes ?? self::LINK_MINUTES), ['file' => $file], absolute: false);

        // The route is /hooks/files/…, and the base already ends in /hooks/
        return substr($base, 0, -strlen('/hooks/')).$link;
    }
}
