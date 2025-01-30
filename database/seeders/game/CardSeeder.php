<?php

namespace Database\Seeders\game;

use App\auxiliary\Suits;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CardSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    $dbCards = array();
    foreach (Suits::SUIT_NAME as $suitAbbreviation => $suitFullName) {
      for ($cards = 2; $cards <= 15; $cards++) {
        if ($cards == 11) {
          continue;
        }

        if ($cards == 12) {
          $rankName = 'Jack';
        } else if ($cards == 13) {
          $rankName = 'Queen';
        } else if ($cards == 14) {
          $rankName = 'King';
        } else if ($cards == 15) {
          $rankName = 'Ace';
        } else {
          $rankName = $cards;
        }
        $dbCards[] = [
          'suit' => $suitAbbreviation,
          'suit_name' => $suitFullName,
          'rank' => $cards,
          'rank_name' => $rankName,
        ];
      }
    }

    DB::table('cards')->insert($dbCards);
  }
}
