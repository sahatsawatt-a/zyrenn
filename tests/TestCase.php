<?php

namespace Tests;

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
