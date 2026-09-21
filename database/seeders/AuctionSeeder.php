<?php

namespace Database\Seeders;

use App\auxiliary\Seats;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\BoardTable;
use Illuminate\Database\Seeder;

class AuctionSeeder extends Seeder
{
  private const CALLS_TO_SEED = 6;

  /**
   * Make the first calls of each seeded playing, clockwise from the dealer.
   * Not rule-legal: every call is a random one.
   */
  public function run(): void
  {
    $plays = BoardTable::with(['board', 'seats'])->get();
    $bidIds = Bid::pluck('id');

    foreach ($plays as $play) {
      $players = $play->seats->pluck('user_id', 'seat');

      // a player per seat, or this playing can't be seeded
      if ($players->count() < 4) {
        continue;
      }

      $seat = $play->board->dealer;

      for ($call = 0; $call < self::CALLS_TO_SEED; $call++) {
        Auction::create([
          'board_table_id' => $play->id,
          'user_id' => $players[$seat],
          'bid_id' => $bidIds->random(),
          'seat' => $seat,
        ]);

        $seat = Seats::next($seat);
      }
    }
  }
}
