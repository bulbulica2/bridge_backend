<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\NextBoardException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\NextBoardRequest;
use App\Http\Resources\PlayingResource;
use App\Models\BoardTable;
use App\Models\Table;
use App\Services\BoardSelectionService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayingController extends BaseController
{
  /**
   * The whole game state as the caller may see it: enough to render the
   * table from scratch after a refresh or a reconnect. A kibitzer gets the
   * public state, with no hand (`watcherStateFor()`).
   */
  public function show(Request $request, Table $table, PlayingStateService $state): JsonResponse
  {
    $this->authorize('watch', $table);

    $user = $request->user();

    return $this->sendResponse(
      $user->can('play', $table) ? $state->stateFor($table, $user) : $state->watcherStateFor($table),
      'Playing retrieved successfully.'
    );
  }

  /**
   * One finished playing after the fact, to review how the board was bid
   * and played: the same shape as the live state, less `ready`. Only for
   * players who have finished the board themselves (`BoardPolicy::view`),
   * like its results and its deal. The table may have been deleted since.
   *
   * An unfinished playing is a 404, like one that doesn't exist.
   */
  public function review(BoardTable $playing): JsonResponse
  {
    abort_if($playing->finished_at === null, 404);

    $this->authorize('view', $playing->board);

    $playing->load(PlayingStateService::RELATIONS);

    return $this->sendResponse((new PlayingResource($playing))->forReview(), 'Playing retrieved successfully.');
  }

  /**
   * Ask for the next board of the set now, rather than wait for
   * `next_board_at`, for the caller only. The last human to ask deals it
   * (robots count as asking). Answers with the game state: still the
   * finished board while a human has to ask, the new one once it is dealt.
   */
  public function next(
    NextBoardRequest $request,
    Table $table,
    BoardSelectionService $boards,
    PlayingStateService $state
  ): JsonResponse {
    try {
      $dealt = $boards->moveOn($table, $request->user());
    } catch (NextBoardException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse(
      $state->stateFor($table, $request->user()),
      $dealt === null ? 'Waiting for the other players.' : 'Next board dealt.'
    );
  }
}
