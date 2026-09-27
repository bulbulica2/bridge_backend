<?php

namespace Tests\Feature\Database;

use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Services\AuctionService;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use App\Services\ScoringService;
use Database\Seeders\game\UserSeeder;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
  private PlayingStateService $state;

  protected function setUp(): void
  {
    parent::setUp();

    // the command a developer runs, rather than RefreshDatabase's migrate
    $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    $this->state = app(PlayingStateService::class);
  }

  public function test_seeded_tables_cover_every_phase(): void
  {
    $phases = Table::all()->map(fn (Table $table) => $this->state->phase($this->state->currentPlaying($table)));

    $this->assertContains(PlayingStateService::PHASE_WAITING, $phases);
    $this->assertContains(PlayingStateService::PHASE_AUCTION, $phases);
    $this->assertContains(PlayingStateService::PHASE_PLAY, $phases);

    $finished = BoardTable::whereNotNull('finished_at')->get();
    $this->assertTrue($finished->contains(fn ($playing) => $playing->contract_bid_id === null), 'no passed out board');
    $this->assertTrue($finished->contains(fn ($playing) => $playing->contract_bid_id !== null), 'no board played out');

    $inPlay = Table::all()->first(fn (Table $table) => $this->state->phase($this->state->currentPlaying($table)) === PlayingStateService::PHASE_PLAY);
    $this->assertNotNull($this->state->dummyHand($this->state->currentPlaying($inPlay)), 'the table in play has no opening lead');
  }

  public function test_the_admin_is_to_call_at_a_full_table(): void
  {
    $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
    $table = $admin->seats()->firstOrFail()->table;
    $playing = $this->state->currentPlaying($table);

    $this->assertSame(4, $table->seats()->count());
    $this->assertSame(PlayingStateService::PHASE_AUCTION, $this->state->phase($playing));
    $this->assertSame($admin->id, $this->state->actingUserId($playing));
  }

  public function test_every_seeded_call_is_legal(): void
  {
    $playings = BoardTable::with(['board', 'seats', 'auctions.bid', 'contractBid'])->get();
    $this->assertNotEmpty($playings);

    foreach ($playings as $playing) {
      $calls = $this->state->calls($playing);
      $made = [];

      foreach ($playing->auctions->sortBy('id')->values() as $i => $auction) {
        $call = $calls[$i];

        $this->assertSame(AuctionService::nextToCall($made, $playing->board->dealer), $call['seat']);
        $this->assertNull(AuctionService::illegalReason($made, $call['seat'], $call['bid']));
        $this->assertSame($playing->seats->firstWhere('seat', $call['seat'])->user_id, $auction->user_id);

        $made[] = $call;
      }

      if (! AuctionService::isOver($made)) {
        $this->assertNull($playing->auction_ended_at);

        continue;
      }

      $result = AuctionService::result($made);
      $this->assertNotNull($playing->auction_ended_at);
      $this->assertSame($result['bid']->id ?? null, $playing->contract_bid_id);
      $this->assertSame($result['declarer'] ?? null, $playing->declarer_seat);
      $this->assertSame($result['doubled'] ?? 0, (int) $playing->doubled);
    }
  }

  public function test_every_seeded_card_is_legal_and_finished_boards_are_scored(): void
  {
    $playings = BoardTable::whereNotNull('contract_bid_id')
      ->with(['board.cards', 'seats', 'contractBid', 'cardPlays.card'])
      ->get();
    $this->assertNotEmpty($playings);

    foreach ($playings as $playing) {
      $declarer = $playing->declarer_seat;
      $trump = $this->state->trump($playing);
      $dealt = $playing->board->cards->groupBy(fn ($card) => $card->pivot->seat);
      $rows = $playing->cardPlays->sortBy([['round', 'asc'], ['order', 'asc']])->values();
      $played = [];

      foreach ($this->state->plays($playing) as $i => $play) {
        $this->assertSame(CardPlayService::nextToPlay($played, $declarer, $trump), $play['seat']);

        $gone = array_map(fn ($earlier) => $earlier['card']->id, $played);
        $hand = $dealt[$play['seat']]
          ->reject(fn ($card) => in_array($card->id, $gone))
          ->map(fn ($card) => ['id' => $card->id, 'suit' => $card->suit])
          ->values()
          ->all();

        $this->assertNull(CardPlayService::illegalReason($played, $hand, $play['card']));

        $actingSeat = CardPlayService::actingSeat($play['seat'], $declarer);
        $this->assertSame($playing->seats->firstWhere('seat', $actingSeat)->user_id, $rows[$i]->user_id);

        $played[] = $play;
      }

      foreach (CardPlayService::tricks($played, $trump) as $trick) {
        $winners = $rows->where('round', $trick['round'])->where('won_trick', true);
        $this->assertSame([$trick['winner']], $winners->pluck('seat')->all());
      }

      if (count($played) < CardPlayService::TRICKS * 4) {
        $this->assertNull($playing->finished_at);

        continue;
      }

      $won = CardPlayService::tricksWon($played, $trump)[CardPlayService::side($declarer)];
      $score = ScoringService::score($playing->contractBid, (int) $playing->doubled, $declarer, $playing->board->vulnerable, $won);

      $this->assertNotNull($playing->finished_at);
      $this->assertSame($won, (int) $playing->tricks_won);
      $this->assertSame(CardPlayService::side($declarer) === 'ns' ? $score : -$score, (int) $playing->score);
    }
  }
}
