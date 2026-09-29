<?php

namespace App\Policies;

use App\Models\Board;
use App\Models\User;
use App\Services\BoardResultsService;

class BoardPolicy
{
  public function __construct(private BoardResultsService $results) {}

  /**
   * Whether the user may see a board's deal and its results at other
   * tables: only once they have finished it themselves. Anyone else may
   * still be dealt it, so it would give the cards away.
   */
  public function view(User $user, Board $board): bool
  {
    return $this->results->hasFinished($user, $board);
  }
}
