<?php

namespace Database\Seeders\game;

use App\Models\Board;
use App\Models\Card;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BoardSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    Board::factory(5)->create();

    $finalRowValues = [];
    // For each board, shuffle the cards and prepare the data for insertion
    Board::all()->each(function ($board) use (&$finalRowValues) {
      $shuffledCards = $this->shuffleCards($board->id);
      $finalRowValues = array_merge($finalRowValues, $shuffledCards);
      // over 5000 records perform a table insert
      if (count($finalRowValues) > 5000) {
        DB::table('board_card')->insert($finalRowValues);
        $finalRowValues = [];
      }
    });

    // remaining items and perform table insert
    if ($finalRowValues) {
      DB::table('board_card')->insert($finalRowValues);
    }
  }

  public function shuffleNewBoards()
  {
    // TODO: populate the new crated boards with values
//    Board::whereHas('')->each(function ($board) {
//
//    });
  }

  private function shuffleCards(int $boardId): array
  {
    // Fetch all card IDs and shuffle them
    $cardIds = Card::pluck('id')->toArray();
    shuffle($cardIds);

    // Define the positions
    $positions = ['N', 'S', 'W', 'E'];

    // Split the shuffled cards into 4 groups of 13
    $tableRows = [];
    foreach (array_chunk($cardIds, 13) as $index => $chunk) {
      $position = $positions[$index];
      foreach ($chunk as $cardId) {
        $tableRows[] = [
          'board_id' => $boardId,
          'card_id' => $cardId,
          'position' => $position,
        ];
      }
    }

    return $tableRows;
  }
}
