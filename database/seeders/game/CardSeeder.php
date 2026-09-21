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
    $dbCards = [];
    foreach (array_keys(Suits::SUIT_NAME) as $suitAbbreviation) {
      for ($cards = 2; $cards <= 15; $cards++) {
        if ($cards == 11) {
          continue;
        }

        if ($cards == 12) {
          $rankName = 'Jack';
        } elseif ($cards == 13) {
          $rankName = 'Queen';
        } elseif ($cards == 14) {
          $rankName = 'King';
        } elseif ($cards == 15) {
          $rankName = 'Ace';
        } else {
          $rankName = $cards;
        }
        $dbCards[] = [
          'suit' => $suitAbbreviation,
          'rank' => $cards,
          'rank_name' => $rankName,
        ];
      }
    }

    DB::table('cards')->insert($dbCards);
  }
}
