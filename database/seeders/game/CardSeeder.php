<?php

namespace Database\Seeders\game;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CardSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    $suitName = array(
      1 => 'clubs',
      2 => 'diamonds',
      3 => 'hearts',
      4 => 'spades',
    );

    $dbCards = array();
    for ($suits = 1; $suits <= 4; $suits++) {
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
          'suit' => $suits,
          'suit_name' => $suitName[$suits],
          'rank' => $cards,
          'rank_name' => $rankName,
        ];
      }
    }

    DB::table('cards')->insert($dbCards);
  }
}
