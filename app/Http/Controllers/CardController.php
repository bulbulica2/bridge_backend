<?php

namespace App\Http\Controllers;

use App\Models\Card;
use Illuminate\Database\Eloquent\Collection;

class CardController extends Controller
{
  public function getCards(): Collection
  {
    return Card::all();
  }

  public function getCard($id)
  {
    return Card::all()->find($id);
  }
}
