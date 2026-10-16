<?php

namespace Tests;

use App\Support\Outside\RemoteDownload;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test gets a throwaway `local` disk.
     *
     * The database is an in-memory SQLite, but the disk is not swapped out, so a
     * test that writes or deletes reaches the real storage/app/private. Deleting a
     * user is the dangerous one: the factory's first user takes id 1, and
     * User::deleted drops drive/{id} -- which wiped a developer's own Drive files.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // No test waits on DNS: a name out in the world is a public address at
        // once. One this machine answers for itself -- localhost, or a number
        // written as a host -- is still asked for, since that is what a fetch
        // from the server's own network has to be caught by.
        RemoteDownload::resolveUsing(fn (string $host) => $host === 'localhost' || ! str_contains($host, '.')
            ? gethostbynamel($host)
            : ['93.184.215.14']);
    }

    /**
     * Runs whatever is queued, to the end.
     *
     * A worker that finds nothing sleeps before it looks at --stop-when-empty,
     * three seconds unless told otherwise -- which was nine tenths of the whole
     * suite's time. A job that should come due later is moved to with travel().
     */
    protected function work(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
    }

    /**
     * A note's document without the ids its blocks are given (NoteBlocks), for
     * comparing it with what was written.
     *
     * @param  array<string, mixed>|null  $doc
     * @return array<string, mixed>|null
     */
    protected function withoutBlockIds(?array $doc): ?array
    {
        if ($doc === null) {
            return null;
        }

        unset($doc['attrs']['id']);

        if (($doc['attrs'] ?? null) === []) {
            unset($doc['attrs']);
        }

        foreach ($doc['content'] ?? [] as $at => $child) {
            if (is_array($child)) {
                $doc['content'][$at] = $this->withoutBlockIds($child);
            }
        }

        return $doc;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
