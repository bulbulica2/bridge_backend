<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\BaseController;
use App\Models\Card;
use Illuminate\Http\JsonResponse;

class CardController extends BaseController
{
  public function index(): JsonResponse
  {
    $cards = Card::all();
    return $this->sendResponse($cards, 'Cards retrieved successfully.');
  }

  public function show($id): JsonResponse
  {
    $card = Card::find($id);
    return $this->sendResponse($card, 'Card retrieved successfully.');
  }
}
