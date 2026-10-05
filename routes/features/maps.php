<?php

use App\Http\Controllers\Map\MapController;
use App\Http\Controllers\Map\MapServiceController;
use App\Http\Controllers\Map\PlaceController;
use App\Http\Controllers\Map\PlaceListController;
use App\Http\Controllers\Map\TripController;
use App\Http\Controllers\Map\TripFolderController;
use App\Support\Maps\MapStyle;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Your own at /maps and /trips, a project's at /p/{project}/maps and /p/{project}/trips
    Route::owned(function () {
        Route::get('maps', [MapController::class, 'index'])->name('maps.index');
        Route::post('place-lists', [PlaceListController::class, 'store'])->name('place-lists.store');
        Route::get('places/pick', [PlaceController::class, 'pick'])->name('places.pick');
        Route::post('places', [PlaceController::class, 'store'])->name('places.store');

        Route::get('trips/pick', [TripController::class, 'pick'])->name('trips.pick');
        Route::resource('trips', TripController::class)->only(['index', 'store']);
        Route::resource('trip-folders', TripFolderController::class)->only(['store']);
    });

    Route::resource('trips', TripController::class)->only(['show', 'update', 'destroy']);
    Route::get('trips/{trip}/content', [TripController::class, 'content'])->name('trips.content');
    Route::resource('trip-folders', TripFolderController::class)
        ->only(['update', 'destroy'])
        ->parameters(['trip-folders' => 'folder']);

    Route::patch('place-lists/{placeList}', [PlaceListController::class, 'update'])->name('place-lists.update');
    Route::delete('place-lists/{placeList}', [PlaceListController::class, 'destroy'])->name('place-lists.destroy');
    Route::patch('places/{place}', [PlaceController::class, 'update'])->name('places.update');
    Route::delete('places/{place}', [PlaceController::class, 'destroy'])->name('places.destroy');

    // What the map asks of other services, through us -- each answer kept
    Route::prefix('map-services')
        ->name('map-services.')
        ->middleware('throttle:maps')
        ->controller(MapServiceController::class)
        ->group(function () {
            Route::get('search', 'search')->name('search');
            Route::get('reverse', 'reverse')->name('reverse');
            Route::post('route', 'route')->name('route');
            Route::post('routes', 'routes')->name('routes');
            Route::post('quickest', 'quickest')->name('quickest');
            // Each new place looked up can spend a SerpAPI search
            Route::get('place-details', 'placeDetails')->middleware('throttle:30,1')->name('place-details');
            Route::get('styles/{name}.json', 'style')
                ->whereIn('name', array_keys(MapStyle::NAMES))
                ->name('style');
        });
});
