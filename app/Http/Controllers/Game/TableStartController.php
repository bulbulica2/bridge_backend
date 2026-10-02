<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\StartBoardException;
use App\Http\Controllers\BaseController;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\BoardSelectionService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableStartController extends BaseController
{
  /**
   * The caller presses Start. The board is dealt once the table is full and
   * every human seated there has pressed it (robots always have). Answers
   * with the table, plus the caller's game state as `playing` so the
   * request that deals needs no `GET /tables/{table}/playing` after it.
   */
  public function store(
    Request $request,
    Table $table,
    BoardSelectionService $boards,
    PlayingStateService $state
  ): JsonResponse {
    $this->authorize('play', $table);

    try {
      $dealt = $boards->start($table, $request->user());
    } catch (StartBoardException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(
      (new TableResource($table))->withPlaying($state->dealtStateFor($table, $request->user())),
      $dealt === null ? 'Ready: waiting for the other players.' : 'Board dealt.'
    );
  }

  /**
   * The caller takes their Start back, while the board isn't dealt yet.
   */
  public function destroy(Request $request, Table $table, BoardSelectionService $boards): JsonResponse
  {
    $this->authorize('play', $table);

    try {
      $boards->withdrawStart($table, $request->user());
    } catch (StartBoardException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    $table->load('seats.user');

    return $this->sendResponse(new TableResource($table), 'Start withdrawn.');
  }
}
