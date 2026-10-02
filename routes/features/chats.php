<?php

use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\ChatRoomController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('chats', ChatRoomController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['chats' => 'room']);

    // Talking with people, kept apart from one's own rooms with an agent
    Route::get('messages', [ChatRoomController::class, 'messages'])->name('messages.index');

    // Someone to talk with, and a project's room -- before {room}, so neither is taken for one
    Route::post('chats/direct', [ChatRoomController::class, 'direct'])->name('chats.direct');
    Route::middleware('can:view,project')->group(function () {
        Route::get('p/{project}/chat', [ChatRoomController::class, 'project'])->name('projects.chat');
        Route::post('p/{project}/chat', [ChatRoomController::class, 'startGroup'])->name('projects.chat.store');
    });
    Route::post('chats/{room}/join', [ChatRoomController::class, 'join'])->name('chats.join');
    Route::post('chats/{room}/leave', [ChatRoomController::class, 'leave'])->name('chats.leave');

    Route::post('chats/{room}/messages', [ChatMessageController::class, 'store'])->name('chats.messages.store');
    Route::get('chats/{room}/messages', [ChatRoomController::class, 'earlier'])->name('chats.messages.index');
    Route::post('chats/{room}/read', [ChatRoomController::class, 'read'])->name('chats.read');
});
