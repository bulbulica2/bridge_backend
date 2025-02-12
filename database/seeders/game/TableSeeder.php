<?php

namespace Database\Seeders\game;

use App\Models\Table;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
      Table::factory(5)->create();

      $table = Table::find(2);

      if ($table) {
        $table->board_id = Table::find(1)->board->id;
        $table->save();
      }
    }
}
