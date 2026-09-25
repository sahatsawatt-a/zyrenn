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

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
