<?php

namespace Database\Seeders\game;

use App\auxiliary\Suits;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BidSeeder extends Seeder
{
  /**
   * The three calls that aren't a contract bid. They have no level or strain,
   * so they have no rank and are never compared.
   */
  private const SPECIAL_CALLS = [
    'P' => 'Pass',
    'X' => 'Double',
    'XX' => 'Redouble',
  ];

  /**
   * Run the database seeds. 38 calls: the three special ones, then 1C…7NT.
   */
  public function run(): void
  {
    $bidding = [];

    foreach (self::SPECIAL_CALLS as $abbreviation => $fullName) {
      $bidding[] = [
        'suit' => $abbreviation,
        'suit_name' => $fullName,
        'special' => true,
        'level' => null,
        'strain' => null,
      ];
    }

    for ($level = 1; $level <= 7; $level++) {
      foreach (Suits::ALL_SUIT_NAMES as $suitAbbreviation => $suitFullName) {
        $bidding[] = [
          'suit' => "$level$suitAbbreviation",
          'suit_name' => "$level $suitFullName",
          'special' => false,
          'level' => $level,
          'strain' => $suitAbbreviation,
        ];
      }
    }

    DB::table('bids')->insert($bidding);
  }
}
