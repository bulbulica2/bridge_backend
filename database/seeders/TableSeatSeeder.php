<?php

namespace Database\Seeders;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Database\Seeder;

class TableSeatSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    $tables = Table::all();

    foreach ($tables as $table) {
      $users = User::inRandomOrder()->limit(4)->get();

      $seats = ['N', 'S', 'E', 'W'];

      foreach ($users as $index => $user) {
        TableSeat::create([
          'table_id' => $table->id,
          'user_id' => $user->id,
          'seat' => $seats[$index] ?? null,
        ]);
      }
    }
  }
}
