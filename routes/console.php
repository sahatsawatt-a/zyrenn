<?php

use App\Support\Workflow\Engine;
use App\Support\Workflow\Health;
use App\Support\Workflow\Inbound\Schedules;
use App\Support\Workflow\Inbound\TelegramPoll;
use App\Support\Workflow\Listeners;
use App\Support\Workflow\Retention;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Workflow steps a dead worker or a lost job left behind: handed on, woken, or settled
Artisan::command('workflows:recover', function () {
    $done = Engine::recover();

    if (array_sum($done) > 0) {
        $this->info(collect($done)->map(fn (int $count, string $what) => "{$what}: {$count}")->implode(', '));
    }
})->purpose('Put right the workflow steps a dead worker or a lost job left behind');

Schedule::command('workflows:recover')->everyMinute()->withoutOverlapping();

// What is old is let go: ended runs, webhook records, who changed what
Artisan::command('workflows:prune', function () {
    $gone = Retention::prune();
    $this->info(collect($gone)->map(fn (int $count, string $what) => "{$what}: {$count}")->implode(', '));
})->purpose('Delete workflow runs, webhook records and audit entries older than they are kept for');

Schedule::command('workflows:prune')->dailyAt('03:30')->withoutOverlapping();

// How the workflows have been doing, for whoever runs the app
Artisan::command('workflows:health {--hours=24}', function () {
    $health = Health::of(null, (int) $this->option('hours'));
    $ms = fn (?int $value) => $value === null ? '-' : ($value < 1000 ? "{$value} ms" : round($value / 1000, 1).' s');

    $this->table(['', ''], [
        ['Runs', "{$health['runs']['total']} ({$health['runs']['succeeded']} succeeded, {$health['runs']['failed']} failed, {$health['runs']['cancelled']} cancelled)"],
        ['Going / waiting now', "{$health['runs']['going']} / {$health['runs']['waiting']}"],
        ['Run time p50 / p95', $ms($health['duration_ms']['p50']).' / '.$ms($health['duration_ms']['p95'])],
        ['Wait for a worker p50 / p95', $ms($health['queue_ms']['p50']).' / '.$ms($health['queue_ms']['p95'])],
        ['Steps tried again', (string) $health['retried']],
        ['Sends that may not have gone out', (string) $health['unsure']],
        ['Stuck now', (string) $health['stuck']],
    ]);

    foreach ($health['failures'] as $failure) {
        $this->line("  failed: {$failure['title']} ({$failure['run']}): {$failure['error']}");
    }
})->purpose('Show how workflow runs have been doing');

// Schedules that are due start their runs; once a minute, as cron would
Artisan::command('workflows:schedules', function () {
    Schedules::tick();
})->purpose('Start the workflow runs whose schedule is due this minute');

Schedule::command('workflows:schedules')->everyMinute()->withoutOverlapping();

// Bots set to "poll" are asked for their messages every few seconds, for most of each minute
Artisan::command('workflows:telegram-poll {--seconds=55}', function () {
    $until = now()->addSeconds((int) $this->option('seconds'));

    // Nothing listens for a bot: nothing to ask, until next minute
    if (Listeners::forKind('telegram')->isEmpty()) {
        return;
    }

    do {
        TelegramPoll::once();
        sleep(3);
    } while (now()->lt($until));
})->purpose('Ask Telegram for the messages of bots that are polled, for workflows');

Schedule::command('workflows:telegram-poll')->everyMinute()->withoutOverlapping()->runInBackground();
