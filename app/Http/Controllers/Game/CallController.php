<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\IllegalCallException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\MakeCallRequest;
use App\Models\Bid;
use App\Models\Table;
use App\Services\AuctionService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;

class CallController extends BaseController
{
  /**
   * Make the caller's next call in the auction: a bid, pass, double or
   * redouble. Answers with the game state as `GET /tables/{table}/playing`
   * serves it.
   */
  public function store(
    MakeCallRequest $request,
    Table $table,
    AuctionService $auction,
    PlayingStateService $state
  ): JsonResponse {
    $bid = Bid::findOrFail($request->validated('bid_id'));

    try {
      $auction->call($table, $request->user(), $bid);
    } catch (IllegalCallException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Call made successfully.', 201);
  }
}
