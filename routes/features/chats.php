<?php

use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\ChatRoomController;
use App\Http\Controllers\Chat\SavedReplyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('chats', ChatRoomController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['chats' => 'room']);

    // Talking with people, kept apart from one's own rooms with an agent

    // Someone to talk with, and a project's room -- before {room}, so neither is taken for one
    Route::post('chats/direct', [ChatRoomController::class, 'direct'])->name('chats.direct');
    Route::middleware('can:view,project')->group(function () {
        Route::get('p/{project}/chat', [ChatRoomController::class, 'project'])->name('projects.chat');
        Route::post('p/{project}/chat', [ChatRoomController::class, 'startGroup'])->name('projects.chat.store');
        // Its chats with customers, wherever each is held
        Route::get('p/{project}/inbox', [ChatRoomController::class, 'inbox'])->name('projects.inbox');
        // Answers it gives its customers often
        Route::get('p/{project}/replies', [SavedReplyController::class, 'index'])->name('projects.replies.index');
        Route::post('p/{project}/replies', [SavedReplyController::class, 'store'])->name('projects.replies.store');
        Route::patch('p/{project}/replies/{reply}', [SavedReplyController::class, 'update'])->name('projects.replies.update');
        Route::delete('p/{project}/replies/{reply}', [SavedReplyController::class, 'destroy'])->name('projects.replies.destroy');
    });
    Route::post('chats/{room}/join', [ChatRoomController::class, 'join'])->name('chats.join');
    Route::post('chats/{room}/leave', [ChatRoomController::class, 'leave'])->name('chats.leave');

    Route::post('chats/{room}/messages', [ChatMessageController::class, 'store'])->name('chats.messages.store');
    Route::get('chats/{room}/messages', [ChatRoomController::class, 'earlier'])->name('chats.messages.index');
    Route::post('chats/{room}/read', [ChatRoomController::class, 'read'])->name('chats.read');
    Route::get('chats/{room}/files', [ChatRoomController::class, 'files'])->name('chats.files');

    // Who a customer's chat is with, on every service: joined to another's, taken apart, named
    Route::get('chats/{room}/contact', [ChatRoomController::class, 'sameCandidates'])->name('chats.contact.candidates');
    Route::post('chats/{room}/contact', [ChatRoomController::class, 'sameAs'])->name('chats.contact.join');
    Route::delete('chats/{room}/contact', [ChatRoomController::class, 'notSame'])->name('chats.contact.leave');
    Route::patch('chats/{room}/contact', [ChatRoomController::class, 'nameContact'])->name('chats.contact.update');

    // What a customer asked, made a ticket of the project's
    Route::post('chats/{room}/tickets', [ChatRoomController::class, 'promote'])->name('chats.tickets.store');
    // A reply an agent writes, for a person to send
    Route::post('chats/{room}/suggest', [ChatRoomController::class, 'suggest'])->middleware('throttle:30,1')->name('chats.suggest');
});
