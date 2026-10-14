<?php

use App\Http\Controllers\Agent\AgentController;
use Illuminate\Support\Facades\Route;

// Agents: one's own at /agents, a project's at /p/{project}/agents
Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::post('agents', [AgentController::class, 'store'])->name('agents.store');
    });

    Route::patch('agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
    Route::delete('agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
    Route::post('agents/{agent}/restore', [AgentController::class, 'restore'])->name('agents.restore');
    Route::post('agents/{agent}/try', [AgentController::class, 'try'])->middleware('throttle:30,1')->name('agents.try');
    Route::get('agents/{agent}/tools', [AgentController::class, 'tools'])->middleware('throttle:30,1')->name('agents.tools');
});
