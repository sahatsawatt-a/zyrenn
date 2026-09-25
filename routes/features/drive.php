<?php

use App\Http\Controllers\Drive\DriveController;
use App\Http\Controllers\Drive\DriveFileController;
use App\Http\Controllers\Drive\DriveFolderController;
use Illuminate\Support\Facades\Route;

// The user's private file store. Every file is streamed back only to its owner.
Route::middleware(['auth', 'verified'])->prefix('drive')->name('drive.')->group(function () {
    Route::get('/', [DriveController::class, 'index'])->name('index');
    Route::get('pick', [DriveController::class, 'pick'])->name('pick');

    Route::resource('files', DriveFileController::class)->only(['store', 'show', 'update', 'destroy']);
    Route::resource('folders', DriveFolderController::class)->only(['store', 'update', 'destroy']);
});
