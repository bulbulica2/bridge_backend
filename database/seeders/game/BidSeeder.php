<?php

namespace Database\Seeders\game;

use App\auxiliary\Suits;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BidSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    $bidding = array();
    $bidding[] = [
      'suit' => 'P',
      'suit_name' => 'Pass',
      'special' => true,
    ];
    $bidding[] = [
      'suit' => 'X',
      'suit_name' => 'Double',
      'special' => true,
    ];
    $bidding[] = [
      'suit' => 'XX',
      'suit_name' => 'Redouble',
      'special' => true,
    ];

    for ($level = 1; $level <= 7; $level++) {
      foreach (Suits::ALL_SUIT_NAMES as $suitAbbreviation => $suitFullName) {
        $bidding[] = [
          'suit' => "$level$suitAbbreviation",
          'suit_name' => "$level $suitFullName",
          'special' => false,
        ];
      }
    }

    DB::table('bids')->insert($bidding);
  }
}
