<?php

namespace Database\Seeders\game;

use App\Services\BoardSelectionService;
use Illuminate\Database\Seeder;

class BoardSeeder extends Seeder
{
  /**
   * How many boards a fresh database starts with. The API deals more on
   * demand once these are used up, so this is only a head start.
   */
  public const INITIAL_BOARDS = 5;

  public function __construct(private BoardSelectionService $boards) {}

  /**
   * Run the database seeds. Needs CardSeeder to have run.
   */
  public function run(int $count = self::INITIAL_BOARDS): void
  {
    for ($board = 0; $board < $count; $board++) {
      $this->boards->dealBoard();
    }
  }
}
