<?php

namespace App\Jobs;

use App\Models\Workflow\CodeLibrary;
use App\Support\Workflow\Code\CodeLibraries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A library an admin added to "Run code", installed: fetched and bundled by
 * the runner for its language, which can take a minute.
 */
class InstallCodeLibrary implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public int $library) {}

    public function handle(): void
    {
        $library = CodeLibrary::find($this->library);

        if ($library !== null && $library->status === CodeLibrary::INSTALLING) {
            CodeLibraries::install($library);
        }
    }

    public function failed(?Throwable $e): void
    {
        CodeLibrary::whereKey($this->library)->where('status', CodeLibrary::INSTALLING)
            ->update(['status' => CodeLibrary::FAILED, 'error' => 'It could not be installed: '.mb_substr((string) $e?->getMessage(), 0, 300)]);
    }
}
