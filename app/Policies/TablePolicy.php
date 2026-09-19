<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

class TablePolicy
{
  /**
   * Whether the user runs this table: seating (and later kicking) other
   * players. The creator, the current moderator and any admin qualify.
   */
  public function manage(User $user, Table $table): bool
  {
    return $user->is_admin
      || $user->id === (int) $table->created_by
      || $user->id === (int) $table->moderated_by;
  }
}
