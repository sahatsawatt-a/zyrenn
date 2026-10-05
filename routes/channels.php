<?php

use App\Models\Board\Board;
use App\Models\Chat\ChatRoom;
use App\Models\Map\Trip;
use App\Models\Note\Note;
use App\Models\Table\Table;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Who has a note, board, table or trip open, and what changes in it. Anyone who
// may see it may listen; what they are shown of each other is their name.
$here = fn (User $user) => ['id' => $user->id, 'name' => $user->name];

Broadcast::channel('notes.{note}', fn (User $user, Note $note) => $user->can('view', $note) ? $here($user) : false);
Broadcast::channel('boards.{board}', fn (User $user, Board $board) => $user->can('view', $board) ? $here($user) : false);
Broadcast::channel('tables.{table}', fn (User $user, Table $table) => $user->can('view', $table) ? $here($user) : false);
Broadcast::channel('trips.{trip}', fn (User $user, Trip $trip) => $user->can('view', $trip) ? $here($user) : false);

// Who has a chat room open, and what is said in it: anyone in the room
Broadcast::channel('chats.{room}', fn (User $user, ChatRoom $room) => $room->includes($user) ? $here($user) : false);
