<?php

namespace Database\Seeders\game;

use App\Models\Board;
use App\Models\Table;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    // use boards BoardSeeder dealt, so seeded plays have cards
    Table::factory(5)->create([
      'board_id' => fn() => Board::has('cards')->inRandomOrder()->value('id'),
    ]);

    $table = Table::find(2);

    if ($table) {
      $table->board_id = Table::find(1)->board->id;
      $table->save();
    }
  }
}
