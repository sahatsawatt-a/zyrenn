<?php

use App\Http\Controllers\Drive\DriveController;
use App\Http\Controllers\Drive\DriveFileController;
use App\Http\Controllers\Drive\DriveFolderController;
use App\Http\Controllers\Drive\DriveUploadLinkController;
use App\Http\Controllers\Drive\PhotoEditController;
use Illuminate\Support\Facades\Route;

// A user's private file store, and each project's. Every file is streamed back
// only to whoever may see it.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::prefix('drive')->name('drive.')->group(function () {
            Route::get('/', [DriveController::class, 'index'])->name('index');
            Route::get('pick', [DriveController::class, 'pick'])->name('pick');

            Route::resource('files', DriveFileController::class)->only(['store']);
            Route::resource('folders', DriveFolderController::class)->only(['store']);
            Route::post('photo-edits', [PhotoEditController::class, 'store'])->name('photo-edits.store');
        });
    });

    Route::prefix('drive')->name('drive.')->group(function () {
        Route::resource('files', DriveFileController::class)->only(['show', 'update', 'destroy']);
        Route::get('files/{file}/original', [PhotoEditController::class, 'original'])->name('files.original');
        Route::resource('folders', DriveFolderController::class)->only(['update', 'destroy']);
    });
});

// One file, for the renderer drawing a board (App\Support\BoardRender): no
// session, only a signed link that runs out in minutes
Route::get('drive/files/{file}/signed', [DriveFileController::class, 'signed'])
    ->middleware('signed:relative')
    ->name('drive.files.signed');

// One file, from an agent's own disk through a link from the request-upload MCP
// tool; the controller checks the signature itself, to answer in JSON
Route::post('drive/upload', DriveUploadLinkController::class)
    ->middleware('throttle:mcp')
    ->name('drive.upload-link');
