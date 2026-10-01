<?php

use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\ChatRoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('chats', ChatRoomController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['chats' => 'room']);

    Route::post('chats/{room}/messages', [ChatMessageController::class, 'store'])->name('chats.messages.store');
});
