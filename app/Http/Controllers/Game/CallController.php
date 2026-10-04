<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\IllegalCallException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\AskAboutCallRequest;
use App\Http\Requests\Game\ExplainCallRequest;
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
   * redouble, alerted to the opponents with `alert` or an `explanation`.
   * Answers with the game state as `GET /tables/{table}/playing` serves it.
   */
  public function store(
    MakeCallRequest $request,
    Table $table,
    AuctionService $auction,
    PlayingStateService $state
  ): JsonResponse {
    $bid = Bid::findOrFail($request->validated('bid_id'));

    try {
      $auction->call($table, $request->user(), $bid, $request->boolean('alert'), $request->validated('explanation'));
    } catch (IllegalCallException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Call made successfully.', 201);
  }

  /**
   * Ask what the opponents' call at `$index` of the auction (from 0)
   * means. A robot answers at once; a human bidder is asked
   * (`CallQuestioned`) and answers with `explain()`.
   */
  public function question(
    AskAboutCallRequest $request,
    Table $table,
    int $index,
    AuctionService $auction,
    PlayingStateService $state
  ): JsonResponse {
    try {
      $answered = $auction->ask($table, $request->user(), $index);
    } catch (IllegalCallException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse(
      $state->stateFor($table, $request->user()),
      $answered ? 'Question answered.' : 'Question asked: waiting for the answer.'
    );
  }

  /**
   * Explain the caller's own call at `$index`: answer a question about it,
   * or add or fix its alert's explanation. Both opponents get it.
   */
  public function explain(
    ExplainCallRequest $request,
    Table $table,
    int $index,
    AuctionService $auction,
    PlayingStateService $state
  ): JsonResponse {
    try {
      $auction->explain($table, $request->user(), $index, $request->validated('explanation'));
    } catch (IllegalCallException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Call explained.');
  }
}
