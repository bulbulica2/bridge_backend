<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\IllegalPlayException;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Game\PlayCardRequest;
use App\Models\Card;
use App\Models\Table;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use Illuminate\Http\JsonResponse;

class CardPlayController extends BaseController
{
  /**
   * Play the next card of the trick: from the caller's own hand, or from
   * dummy's when the caller is declarer. Answers with the game state as
   * `GET /tables/{table}/playing` serves it.
   */
  public function store(
    PlayCardRequest $request,
    Table $table,
    CardPlayService $play,
    PlayingStateService $state
  ): JsonResponse {
    $card = Card::findOrFail($request->validated('card_id'));

    try {
      $play->play($table, $request->user(), $card);
    } catch (IllegalPlayException $e) {
      return $this->sendError($e->getMessage(), 409);
    }

    return $this->sendResponse($state->stateFor($table, $request->user()), 'Card played successfully.', 201);
  }
}
