<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\BaseController;
use App\Models\Board;
use App\Services\BoardResultsService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;

/**
 * A board after the fact, for the players who have finished it.
 */
class BoardController extends BaseController
{
  /**
   * The board with all four hands as dealt.
   */
  public function show(Board $board, PlayingStateService $state): JsonResponse
  {
    $this->authorize('view', $board);

    return $this->sendResponse([
      'id' => $board->id,
      'number' => $board->number,
      'dealer' => $board->dealer,
      'vulnerable' => $board->vulnerable,
      'deal' => $state->boardDeal($board),
    ], 'Board retrieved successfully.');
  }

  /**
   * Every finished playing of the board, with matchpoints.
   */
  public function results(Board $board, BoardResultsService $results): JsonResponse
  {
    $this->authorize('view', $board);

    return $this->sendResponse($results->results($board), 'Results retrieved successfully.');
  }
}
