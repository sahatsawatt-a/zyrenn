<?php

use App\Http\Controllers\Workflow\CodeAdminController;
use App\Http\Controllers\Workflow\WorkflowController;
use App\Http\Controllers\Workflow\WorkflowFolderController;
use App\Http\Controllers\Workflow\WorkflowRunController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Your own at /workflows, a project's at /p/{project}/workflows
    Route::owned(function () {
        Route::resource('workflows', WorkflowController::class)->only(['index', 'store']);
        Route::resource('workflow-folders', WorkflowFolderController::class)->only(['store']);
    });

    Route::resource('workflows', WorkflowController::class)->only(['show', 'update', 'destroy']);
    Route::post('workflows/{workflow}/publish', [WorkflowController::class, 'publish'])->name('workflows.publish');
    Route::patch('workflows/{workflow}/enabled', [WorkflowController::class, 'enable'])->name('workflows.enable');

    // Running it, and how its runs went
    Route::get('workflows/{workflow}/runs', [WorkflowRunController::class, 'index'])->name('workflows.runs.index');
    Route::post('workflows/{workflow}/runs', [WorkflowRunController::class, 'store'])
        ->middleware('throttle:30,1')->name('workflows.runs.store');
    Route::get('workflow-runs/{run}', [WorkflowRunController::class, 'show'])->name('workflow-runs.show');
    Route::post('workflow-runs/{run}/cancel', [WorkflowRunController::class, 'cancel'])->name('workflow-runs.cancel');
    Route::post('workflow-runs/{run}/steps/{node}/answer', [WorkflowRunController::class, 'answer'])->name('workflow-runs.answer');
    Route::resource('workflow-folders', WorkflowFolderController::class)
        ->only(['update', 'destroy'])
        ->parameters(['workflow-folders' => 'folder']);

    // What "Run code" may use and how long it may run: the app's admins say
    Route::prefix('admin/code')->name('admin.code.')->group(function () {
        Route::get('/', [CodeAdminController::class, 'page'])->name('page');
        Route::patch('settings', [CodeAdminController::class, 'settings'])->name('settings');
        Route::post('libraries', [CodeAdminController::class, 'store'])->middleware('throttle:20,1')->name('libraries.store');
        Route::patch('libraries/{library}', [CodeAdminController::class, 'update'])->name('libraries.update');
        Route::delete('libraries/{library}', [CodeAdminController::class, 'destroy'])->name('libraries.destroy');
    });
});
