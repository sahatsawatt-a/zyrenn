<?php

namespace App\Support\Drive;

use App\Models\Drive\DriveFile;
use App\Support\Chrome\Chrome;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * A WebM video in the Drive made MP4 (H.264), which plays everywhere it is
 * taken -- a phone, a Mac, a deck, a chat app -- where WebM does not. Made
 * in place: the same file, so every note, board and link to it still finds
 * it, now holding the MP4. The WebM itself goes ten minutes later.
 */
final class VideoMp4
{
    /** Whether `$file` is a video still to make MP4. */
    public static function wanted(DriveFile $file): bool
    {
        return $file->kind === 'video' && $file->mime === 'video/webm';
    }

    /**
     * Makes `$file` MP4 and answers with its new size. A file no longer WebM
     * is left as it is (0).
     *
     * @throws RuntimeException when the browser's ffmpeg could not
     */
    public static function make(DriveFile $file): int
    {
        if (! self::wanted($file)) {
            return 0;
        }

        $bytes = Chrome::mp4(URL::temporarySignedRoute('drive.files.signed', now()->addMinutes(30), ['file' => $file->ref_id], absolute: false));
        $disk = Storage::disk(DriveFile::DISK);
        $old = $file->path;
        $path = preg_replace('/\.webm$/i', '', $old).'.mp4';

        $disk->put($path, $bytes);
        $file->forceFill([
            'path' => $path,
            'name' => preg_replace('/\.webm$/i', '', $file->name).'.mp4',
            'mime' => 'video/mp4',
            'ext' => 'mp4',
            'size' => strlen($bytes),
        ])->save();

        // The WebM let go of a while later, not at once: someone may be part
        // way through fetching it, by the path they were given before
        if ($old !== $path) {
            dispatch(fn () => Storage::disk(DriveFile::DISK)->delete($old))->delay(now()->addMinutes(10));
        }

        return strlen($bytes);
    }
}
