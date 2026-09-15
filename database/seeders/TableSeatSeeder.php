<?php

namespace Database\Seeders;

use App\auxiliary\Seats;
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
      // a user can only sit at one table, so some tables may stay partly empty
      $users = User::whereDoesntHave('seats')->inRandomOrder()->limit(4)->get();

      foreach ($users as $index => $user) {
        TableSeat::create([
          'table_id' => $table->id,
          'user_id' => $user->id,
          'seat' => Seats::SEATS[$index],
        ]);
      }
    }
  }
}
