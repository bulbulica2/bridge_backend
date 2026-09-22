<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\BaseController;
use App\Models\Table;
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
}
