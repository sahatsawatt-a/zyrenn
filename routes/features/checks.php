<?php

use App\Http\Controllers\Browser\BrowserCheckController;
use App\Http\Controllers\Browser\TestRunController;
use Illuminate\Support\Facades\Route;

// Browser checks: suites of checks of a public site, one's own or a
// project's, built by clicking through its pages and run in a real browser.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::get('checks', [BrowserCheckController::class, 'show'])->name('checks.index');
        Route::post('checks/suites', [BrowserCheckController::class, 'storeSuite'])->name('checks.suites.store');
    });

    // A suite and its runs know whose they are
    Route::get('checks/suites/{suite}', [BrowserCheckController::class, 'showSuite'])->name('checks.suites.show');
    Route::patch('checks/suites/{suite}', [BrowserCheckController::class, 'updateSuite'])->name('checks.suites.update');
    Route::delete('checks/suites/{suite}', [BrowserCheckController::class, 'destroySuite'])->name('checks.suites.destroy');
    Route::post('checks/suites/{suite}/look', [BrowserCheckController::class, 'look'])->middleware('throttle:30,1')->name('checks.suites.look');
    // A page kept open while a check is built by clicking through it
    Route::post('checks/suites/{suite}/live', [BrowserCheckController::class, 'openLive'])->middleware('throttle:20,1')->name('checks.suites.live');
    Route::post('checks/live/{live}/step', [BrowserCheckController::class, 'stepLive'])->middleware('throttle:120,1')->name('checks.live.step');
    Route::post('checks/live/{live}/close', [BrowserCheckController::class, 'closeLive'])->name('checks.live.close');
    Route::post('checks/suites/{suite}/runs', [BrowserCheckController::class, 'startRun'])->middleware('throttle:20,1')->name('checks.runs.store');
    Route::get('checks/runs/{browserRun}', [BrowserCheckController::class, 'showRun'])->name('checks.runs.show');

    // The app's own browser suites, run and followed -- about the app itself,
    // so for its admins, and kept apart from anyone's browser checks
    Route::prefix('admin/test-runs')->name('admin.test-runs.')->group(function () {
        Route::get('/', [TestRunController::class, 'page'])->name('page');
        Route::get('state', [TestRunController::class, 'index'])->name('index');
        Route::post('/', [TestRunController::class, 'store'])->name('store');
        Route::get('{run}', [TestRunController::class, 'show'])->name('show');
        Route::post('{run}/stop', [TestRunController::class, 'stop'])->name('stop');
        Route::get('{run}/{suite}/{file}', [TestRunController::class, 'shot'])->name('shot');
    });
});
