<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use Illuminate\Support\Facades\Route;

// What is in a project is under /p/{project} too, through each feature's Route::owned()
Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

    Route::prefix('p/{project}')->name('projects.')->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->name('show');
        Route::get('settings', [ProjectController::class, 'edit'])->name('edit');
        Route::patch('/', [ProjectController::class, 'update'])->name('update');
        Route::delete('/', [ProjectController::class, 'destroy'])->name('destroy');

        Route::post('members', [ProjectMemberController::class, 'store'])->name('members.store');
        Route::scopeBindings()->group(function () {
            Route::patch('members/{membership}', [ProjectMemberController::class, 'update'])->name('members.update');
            Route::delete('members/{membership}', [ProjectMemberController::class, 'destroy'])->name('members.destroy');
        });
    });
});
