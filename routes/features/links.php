<?php

use App\Http\Controllers\LinkPreviewController;
use Illuminate\Support\Facades\Route;

// What a link put in a note points at, fetched by the server from the public
// web: a card's title and picture, a map link's place, a site's icon.
Route::middleware(['auth', 'verified', 'throttle:maps'])
    ->prefix('link-preview')
    ->name('link-preview.')
    ->controller(LinkPreviewController::class)
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('image', 'image')->name('image');
        Route::get('icon', 'icon')->name('icon');
    });
