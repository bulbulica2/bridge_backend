<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\Table;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use RuntimeException;

class CardplaySeeder extends Seeder
{
  public function __construct(
    private CardPlayService $cardPlay,
    private PlayingStateService $state,
  ) {}

  /**
   * Play `$cards` random legal cards at the table's current playing through
   * `CardPlayService`, declarer playing dummy's, so trick winners are the
   * real ones. All 52 finish the board and score it. The auction must have
   * ended in a contract (`AuctionSeeder`).
   */
  public function run(Table $table, int $cards = CardPlayService::TRICKS * 4): void
  {
    $deck = Card::all()->keyBy('id');

    for ($played = 0; $played < $cards; $played++) {
      $playing = $this->state->currentPlaying($table);

      if ($this->state->phase($playing) !== PlayingStateService::PHASE_PLAY) {
        throw new RuntimeException("Table {$table->id} is not in the play: seed its auction to a contract first.");
      }

      $turn = $this->state->turn($playing);
      $plays = $this->state->plays($playing);
      $hand = $this->state->hand($playing, $turn);

      $legal = array_filter(
        $hand,
        fn ($held) => CardPlayService::illegalReason($plays, $hand, $deck[$held['id']]) === null,
      );

      $player = $playing->seats->firstWhere('user_id', $this->state->actingUserId($playing))->user;

      $this->cardPlay->play($table, $player, $deck[Arr::random($legal)['id']]);
    }
  }
}
