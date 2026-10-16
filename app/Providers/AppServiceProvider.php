<?php

namespace App\Providers;

use App\Models\Board\Board;
use App\Models\Board\BoardShape;
use App\Models\Browser\BrowserSuite;
use App\Models\Chat\ChatRoom;
use App\Models\Connection;
use App\Models\Drive\DriveFile;
use App\Models\Folder;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Map\Trip;
use App\Models\Note\Note;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Policies\ChatPolicy;
use App\Policies\ContentPolicy;
use App\Support\Formula\Dependents;
use App\Support\Live\Listed;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // A formula that reads a trip or another table follows it when it changes
        Dependents::listen();

        // Lists open in a space follow what is made, changed and deleted in it, from anywhere
        Listed::listen();

        // One policy for everything a user or a project owns; the Gate finds
        // Folder's for each kind of folder
        foreach ([Note::class, Board::class, BoardShape::class, Table::class, Trip::class, PlaceList::class, Place::class, DriveFile::class, Folder::class, Connection::class, Workflow::class, BrowserSuite::class] as $model) {
            Gate::policy($model, ContentPolicy::class);
        }

        Gate::policy(ChatRoom::class, ChatPolicy::class);

        // Whoever runs the app itself: what is about the app, not anyone's content
        Gate::define('admin', fn (User $user) => $user->is_admin);

        $this->configureOwnedRoutes();
    }

    /**
     * Routes that list or make things for an owner are registered with
     * Route::owned(): once for the user's own, as "notes.index" at /notes, and
     * once for a project's, as "projects.notes.index" at /p/{project}/notes.
     * Only members get past the second; the controller asks for more when the
     * route changes something.
     */
    protected function configureOwnedRoutes(): void
    {
        Route::model('project', Project::class);

        Route::macro('owned', function (Closure $routes): void {
            $routes();

            Route::prefix('p/{project}')
                ->name('projects.')
                ->middleware('can:view,project')
                ->group($routes);
        });
    }

    /**
     * Configure rate limiters.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // A map asks often -- a suggestion per pause in typing, a route per day
        // of a trip -- but most answers come from the cache, not the services
        RateLimiter::for('maps', fn (Request $request) => Limit::perMinute(240)
            ->by($request->user()?->id ?: $request->ip()));

        // Telegram and LINE send every bot's messages from a few addresses of
        // their own, so a hook is held back by itself -- one busy bot never
        // slows another's -- with a far higher cap per address against a
        // flood of made-up ones. Held back means 429, which senders retry.
        RateLimiter::for('hooks', fn (Request $request) => [
            Limit::perMinute(600)->by('hook:'.$request->path()),
            Limit::perMinute(3000)->by('hooks-from:'.$request->ip()),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
