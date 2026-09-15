<?php

namespace Database\Seeders\game;

use App\Models\BoardTable;
use App\Models\Table;
use Illuminate\Database\Seeder;

class BoardTableSeeder extends Seeder
{
  /**
   * Start a playing for every full table that has a board, with a snapshot of its seats.
   */
  public function run(): void
  {
    $tables = Table::whereNotNull('board_id')->has('seats', '=', 4)->with('seats')->get();

    foreach ($tables as $table) {
      $play = BoardTable::create([
        'board_id' => $table->board_id,
        'table_id' => $table->id,
        'started_at' => now(),
      ]);

      foreach ($table->seats as $seat) {
        $play->seats()->create([
          'user_id' => $seat->user_id,
          'seat' => $seat->seat,
        ]);
      }
    }
  }
}
