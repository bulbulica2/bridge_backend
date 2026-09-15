<?php

namespace Database\Seeders;

use App\auxiliary\Seats;
use App\Models\BoardTable;
use App\Models\Cardplay;
use Illuminate\Database\Seeder;

class CardplaySeeder extends Seeder
{
  private const TRICKS_TO_SEED = 3;

  /**
   * Play the first tricks of each seeded playing, taking cards from each seat's hand in order.
   * Not rule-legal: no follow-suit, and the trick winner is random.
   */
  public function run(): void
  {
    $plays = BoardTable::with(['board.cards', 'board', 'seats'])->get();

    foreach ($plays as $play) {
      $hands = $play->board->cards->groupBy(fn($card) => $card->pivot->seat);
      $players = $play->seats->pluck('user_id', 'seat');

      // a full hand per seat and a player per seat, or this playing can't be seeded
      if ($players->count() < 4 || collect(Seats::SEATS)->contains(fn($seat) => ($hands[$seat] ?? collect())->count() < self::TRICKS_TO_SEED)) {
        continue;
      }

      $leader = Seats::next($play->board->dealer);

      for ($round = 1; $round <= self::TRICKS_TO_SEED; $round++) {
        $winner = rand(1, 4);
        $seat = $leader;

        for ($order = 1; $order <= 4; $order++) {
          Cardplay::create([
            'user_id' => $players[$seat],
            'table_id' => $play->table_id,
            'board_id' => $play->board_id,
            'card_id' => $hands[$seat][$round - 1]->id,
            'seat' => $seat,
            'round' => $round,
            'order' => $order,
            'won_trick' => $order === $winner,
          ]);

          if ($order === $winner) {
            $nextLeader = $seat;
          }

          $seat = Seats::next($seat);
        }

        $leader = $nextLeader;
      }
    }
  }
}
