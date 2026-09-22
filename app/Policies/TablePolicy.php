<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy
{
  /**
   * Whether the user runs this table: seating and kicking other players.
   *
   * A table has one manager at a time. It starts as the creator, who is
   * seated by `TableController::store`, and `TableSeatService::remove()`
   * passes `moderated_by` on when that manager leaves. The creator only
   * counts while they still hold a seat here — once they walk away the role
   * has already moved to someone else, and a departed creator must not keep
   * managing a table they left. Admins override regardless of where they sit.
   */
  public function manage(User $user, Table $table): bool
  {
    return $user->is_admin
      || $user->id === (int) $table->moderated_by
      || ($user->id === (int) $table->created_by
        && $table->seats()->where('user_id', $user->id)->exists());
  }

  /**
   * Whether the user may read the table's game state: only the players
   * seated there, the same audience as the `table.{id}` channel.
   */
  public function play(User $user, Table $table): bool
  {
    return $table->seats()->where('user_id', $user->id)->exists();
  }
}
