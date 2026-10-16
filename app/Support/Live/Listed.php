<?php

namespace App\Support\Live;

use App\Events\ListChanged;
use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\Browser\BrowserSuite;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Map\Trip;
use App\Models\Map\TripFolder;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\Owner;
use App\Models\Product\ProductElement;
use App\Models\Product\ProductLink;
use App\Models\Product\ProductRequest;
use App\Models\Product\Requirement;
use App\Models\Table\Table;
use App\Models\Table\TableFolder;
use App\Models\Ticket\Ticket;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowFolder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * Telling a space's open lists that something in them changed (ListChanged),
 * whoever changed it: a person, a workflow, a job or MCP. Many changes at
 * once -- a folder and all in it, a product's parts -- go out as one per
 * kind, once the request or job is done. Where nothing ends soon after -- an
 * MCP server on stdio, tinker -- each goes out as it happens.
 */
final class Listed
{
    /** What each model is listed as. */
    public const KINDS = [
        Note::class => 'notes',
        NoteFolder::class => 'notes',
        Board::class => 'boards',
        BoardFolder::class => 'boards',
        Table::class => 'tables',
        TableFolder::class => 'tables',
        Trip::class => 'trips',
        TripFolder::class => 'trips',
        Workflow::class => 'workflows',
        WorkflowFolder::class => 'workflows',
        DriveFile::class => 'drive',
        DriveFolder::class => 'drive',
        Ticket::class => 'tickets',
        ProductElement::class => 'product',
        ProductLink::class => 'product',
        ProductRequest::class => 'product',
        Requirement::class => 'product',
        Place::class => 'places',
        PlaceList::class => 'places',
        BrowserSuite::class => 'browser_suites',
    ];

    /**
     * What is waiting to go out when the request or job is done, by space and kind.
     *
     * @var array<string, true>
     */
    private static array $waiting = [];

    /** Inside a job on a real queue, whose end sends what was deferred. */
    private static bool $inJob = false;

    public static function listen(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            self::$inJob = ! in_array($event->connectionName, ['sync', 'deferred'], true);
        });
        Event::listen(JobAttempted::class, fn () => self::$inJob = false);
    }

    public static function changed(Model $model): void
    {
        $kind = self::KINDS[$model::class] ?? null;
        $owner = $kind === null ? null : self::ownerOf($model);

        if ($owner === null) {
            return;
        }

        if (app()->runningInConsole() && ! self::$inJob) {
            Live::tell(new ListChanged($owner, $kind));

            return;
        }

        $key = $owner::class.':'.$owner->getKey().':'.$kind;

        if (isset(self::$waiting[$key])) {
            return;
        }

        self::$waiting[$key] = true;

        // Even when the request fails after it: a list asked for again costs little
        defer(function () use ($key, $owner, $kind) {
            unset(self::$waiting[$key]);
            Live::tell(new ListChanged($owner, $kind));
        })->always();
    }

    /** The user or project whose lists it is in. */
    private static function ownerOf(Model $model): ?Owner
    {
        if ($model instanceof ProductLink) {
            $model = $model->from;
        }

        if ($model !== null && in_array(BelongsToOwner::class, class_uses_recursive($model), true)) {
            /** @var (Model&Owner)|null $owner */
            $owner = $model->getAttribute('user_id') !== null ? $model->getRelationValue('user') : $model->getRelationValue('project');

            return $owner;
        }

        $project = $model?->getRelationValue('project');

        return $project instanceof Owner ? $project : null;
    }
}
