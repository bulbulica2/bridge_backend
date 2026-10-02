<?php

namespace App\Policies;

use App\Models\TableSet;
use App\Models\User;
use App\Services\BoardResultsService;

class TableSetPolicy
{
  public function __construct(private BoardResultsService $results) {}

  /**
   * Whether the user may see a set's results: its four players, or anyone
   * who has finished every board it finished. Anyone else may still be
   * dealt one of its boards, and the results would give the cards away.
   */
  public function view(User $user, TableSet $set): bool
  {
    return $this->results->maySeeSet($user, $set);
  }
}
