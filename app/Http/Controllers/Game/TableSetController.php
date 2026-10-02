<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\BaseController;
use App\Models\TableSet;
use App\Services\BoardResultsService;
use Illuminate\Http\JsonResponse;

/**
 * A set of boards, during or after the fact.
 */
class TableSetController extends BaseController
{
  /**
   * The set's results: its finished boards with their results and
   * matchpoints, the totals per side and who won it. For the set's four
   * players, and for anyone who has finished all of those boards
   * (`TableSetPolicy::view`). The table may have been deleted since.
   */
  public function show(TableSet $set, BoardResultsService $results): JsonResponse
  {
    $this->authorize('view', $set);

    return $this->sendResponse($results->set($set), 'Set retrieved successfully.');
  }
}
