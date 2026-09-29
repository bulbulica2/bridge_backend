<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\BaseController;
use App\Http\Resources\PlayingResource;
use App\Models\Bid;
use Illuminate\Http\JsonResponse;

class BidController extends BaseController
{
  /**
   * The 38 calls, shaped like `auction[].bid` in the game state, so a client
   * can learn the `bid_id` of a call nobody has made yet. `P`, `X` and `XX`
   * come first, then the contract bids by rank; never by id, which isn't
   * pinned to the rank.
   */
  public function index(): JsonResponse
  {
    $specials = [Bid::PASS, Bid::DOUBLE, Bid::REDOUBLE];

    $bids = Bid::specials()->get()
      ->sortBy(fn (Bid $bid) => array_search($bid->suit, $specials, true))
      ->concat(Bid::contracts()->get()->sort(fn (Bid $a, Bid $b) => $a->rank() <=> $b->rank()))
      ->map(fn (Bid $bid) => PlayingResource::bid($bid))
      ->values();

    return $this->sendResponse($bids, 'Bids retrieved successfully.');
  }
}
