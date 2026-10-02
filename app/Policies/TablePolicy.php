<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TablePolicy
{
  /**
   * Whether the user runs this table: seating and kicking other players.
   *
   * A table has one role, its moderator (`moderated_by`). It starts as the
   * creator, who is seated by `TableController::store`, and
   * `TableSeatService::remove()` passes it to the human seated longest when
   * the moderator leaves. `created_by` grants nothing: a creator who left
   * and came back is a player like any other. Admins override regardless of
   * where they sit, to deal with cheating.
   */
  public function manage(User $user, Table $table): bool
  {
    return $user->is_admin || $user->id === (int) $table->moderated_by;
  }

  /**
   * Whether the user may take `$target`'s seat away: their own always (a
   * quit), anybody's if they manage the table, and a robot's at an
   * unattended table — one only robots are keeping — by anyone at all,
   * since nobody manages it. An admin's seat is the exception: only the
   * admin themselves or another admin may take it, never the moderator.
   */
  public function kick(User $user, Table $table, User $target): Response
  {
    if ($user->id === $target->id) {
      return Response::allow();
    }

    if ($target->is_admin) {
      return $user->is_admin ? Response::allow() : Response::deny('Only an admin can remove an admin.');
    }

    return ($target->is_robot && $table->unattended_since !== null) || $this->manage($user, $table)
      ? Response::allow()
      : Response::deny('Only the table moderator or an admin can remove other players.');
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
