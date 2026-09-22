<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// a user's own channel: anything meant for one player only (their hand,
// HandDealt) belongs here, not on the table channel
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
  return (int) $user->id === (int) $id;
});

// live updates for one table, for the players seated at it. Checked once, at
// subscribe time: somebody who leaves stays subscribed until they disconnect,
// so the table channel must only ever carry what any player may see
Broadcast::channel('table.{tableId}', function (User $user, $tableId) {
  return $user->seats()->where('table_id', (int) $tableId)->exists();
});
