<?php

namespace Database\Seeders;

use App\Models\Board;
use App\Models\Card;
use App\Models\Cardplay;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Seeder;

class CardplaySeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    for ($i = 0; $i < 100; $i++) {
      CardPlay::create([
        'user_id' => User::inRandomOrder()->first()->id,
        'table_id' => Table::inRandomOrder()->first()->id,
        'board_id' => Board::inRandomOrder()->first()->id,
        'card_id' => Card::inRandomOrder()->first()->id,
        'round' => rand(1, 13),
        'order' => rand(1, 4),
      ]);
    }
  }
}
