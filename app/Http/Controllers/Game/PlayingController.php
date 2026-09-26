<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\NextBoardException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\NextBoardRequest;
use App\Models\Table;
use App\Services\BoardSelectionService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayingController extends BaseController
{
  /**
   * The whole game state as the caller may see it: enough to render the
   * table from scratch after a refresh or a reconnect.
   */
  public function show(Request $request, Table $table, PlayingStateService $state): JsonResponse
  {
    $this->authorize('play', $table);

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Playing retrieved successfully.');
  }

  /**
   * Ask for the next board once the current one is finished — for the
   * caller, or with `everyone` (a manager) for all four. The last player to
   * ask deals it. Answers with the game state: still the finished board
   * while somebody has to ask, the new one once it is dealt.
   */
  public function next(
    NextBoardRequest $request,
    Table $table,
    BoardSelectionService $boards,
    PlayingStateService $state
  ): JsonResponse {
    try {
      $dealt = $boards->moveOn($table, $request->user(), $request->boolean('everyone'));
    } catch (NextBoardException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse(
      $state->stateFor($table, $request->user()),
      $dealt === null ? 'Waiting for the other players.' : 'Next board dealt.'
    );
  }
}
