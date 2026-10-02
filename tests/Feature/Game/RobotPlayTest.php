<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Robots\RobotBidder;
use App\Robots\RobotCardPlayer;
use App\Robots\RobotClaims;
use App\Robots\RobotHand;
use App\Services\AuctionService;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * One human and three robots. Tests run the queue on `sync`, so every robot
 * move happens inside the request that made it the robots' turn.
 */
class RobotPlayTest extends TestCase
{
  use RefreshDatabase;

  private PlayingStateService $state;

  private User $human;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->state = app(PlayingStateService::class);
    $this->human = User::factory()->create();
  }

  public function test_one_human_plays_a_full_board_with_robots_and_moves_on(): void
  {
    $table = $this->robotTable();

    // a passed out board has no play: deal again until one is played out
    for ($boards = 1; ; $boards++) {
      $this->assertLessThan(20, $boards, 'no board reached a contract');

      $playing = $this->state->currentPlaying($table);
      $this->playOut($table);

      $playing->refresh();

      if ($playing->contract_bid_id !== null) {
        break;
      }

      $this->actingAs($this->human)->postJson("/tables/$table->id/playing/next")->assertOk();
    }

    // played out, or claimed once a robot held nothing but top winners
    $this->assertNotNull($playing->finished_at);
    $this->assertTrue($playing->cardPlays->count() === 52 || $playing->claim_seat !== null);
    $this->assertReplaysThroughTheRules($playing);

    // the robots asked for the next board as soon as this one ended
    $robotSeats = $playing->seats()->with('user')->get()->filter(fn ($seat) => $seat->user->is_robot)->pluck('seat')->all();
    $this->assertCount(3, $robotSeats);

    $this->actingAs($this->human)->getJson("/tables/$table->id/playing")
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.ready', array_values(array_intersect(Seats::SEATS, $robotSeats)));

    // and the human's Next deals the next board
    $this->actingAs($this->human)->postJson("/tables/$table->id/playing/next")
      ->assertOk()
      ->assertJsonPath('message', 'Next board dealt.')
      ->assertJsonPath('data.phase', 'auction');

    $this->assertNotSame($playing->board_id, $table->fresh()->board_id);
  }

  public function test_robots_accept_a_claim_they_cannot_beat(): void
  {
    $table = $this->claimTable(['N' => 'S', 'E' => 'H', 'S' => 'D', 'W' => 'C']);

    // declarer holds all thirteen trumps; the defenders' tops would be ruffed
    $this->actingAs($this->human)->postJson("/tables/$table->id/claim", ['tricks' => 13])->assertCreated();

    $playing = $this->state->currentPlaying($table);

    $this->assertNotNull($playing->finished_at);
    $this->assertSame(13, $playing->tricks_won);
    $this->assertSame(['E', 'W'], $playing->claim_accepted);

    $this->actingAs($this->human)->getJson("/tables/$table->id/playing")
      ->assertJsonPath('data.result.claimed', true);
  }

  public function test_robots_reject_a_claim_that_takes_their_sure_winner(): void
  {
    // E holds the ace of trumps
    $table = $this->claimTable(['N' => 'S', 'E' => 'H', 'S' => 'D', 'W' => 'C'], swap: [['S', 15], ['H', 2]]);

    $this->actingAs($this->human)->postJson("/tables/$table->id/claim", ['tricks' => 13])->assertCreated();

    $playing = $this->state->currentPlaying($table);

    $this->assertNull($playing->finished_at);
    $this->assertNull($playing->claim_seat);

    // play went on: E, the robot on lead, made the opening lead, and now it
    // is dummy's turn, which the human plays
    $this->assertCount(1, $playing->cardPlays);
    $this->assertSame('E', $playing->cardPlays->first()->seat);
    $this->assertSame($this->human->id, $this->state->actingUserId($playing));
  }

  public function test_a_robot_declarer_claims_when_every_trick_left_is_a_top_winner(): void
  {
    // the robot declarer S holds all thirteen diamonds (trumps); the human
    // is dummy N
    $table = $this->claimTable(['N' => 'S', 'E' => 'H', 'S' => 'D', 'W' => 'C'], declarer: 'S');

    PlayingUpdated::dispatch($table);

    // W led a club, S ruffed it and claimed the other twelve; the robot
    // defenders accepted
    $playing = BoardTable::where('table_id', $table->id)->sole();

    $this->assertCount(4, $playing->cardPlays);
    $this->assertNotNull($playing->finished_at);
    $this->assertSame('S', $playing->claim_seat);
    $this->assertSame(12, $playing->claim_tricks);
    $this->assertSame(13, $playing->tricks_won);
  }

  public function test_a_robot_plays_on_after_its_claim_is_rejected(): void
  {
    // the robot declarer S holds all the diamonds; the human defends as E
    $table = $this->claimTable(['N' => 'S', 'E' => 'H', 'S' => 'D', 'W' => 'C'], human: 'E', declarer: 'S');

    // W leads a club and dummy follows: it is the human's turn
    PlayingUpdated::dispatch($table);

    $playing = $this->state->currentPlaying($table);
    $this->assertSame($this->human->id, $this->state->actingUserId($playing));

    $heart = $this->actingAs($this->human)->getJson("/tables/$table->id/playing")->json('data.hand.0.id');
    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $heart])->assertCreated();

    // S ruffs and claims the rest; the human rejects
    $playing->refresh();
    $this->assertSame('S', $playing->claim_seat);
    $this->assertSame(['W'], $playing->claim_accepted);

    $this->actingAs($this->human)->postJson("/tables/$table->id/claim/response", ['accept' => false])->assertOk();

    // S doesn't claim again from the same place: it leads, W and dummy
    // follow, and it is the human's turn again
    $playing->refresh();
    $this->assertNull($playing->claim_seat);
    $this->assertCount(7, $playing->cardPlays);
    $this->assertSame($this->human->id, $this->state->actingUserId($playing));
  }

  public function test_robots_wait_at_an_unattended_table_and_a_returning_human_runs_it(): void
  {
    // a passed out board, finished before any robot asked for the next one
    $table = Event::fakeFor(function () {
      $table = $this->robotTable();
      $playing = $this->state->currentPlaying($table);
      $pass = Bid::where('suit', Bid::PASS)->firstOrFail();

      for ($i = 0; $i < 4; $i++) {
        $seat = AuctionService::nextToCall($this->state->calls($playing->refresh()), $playing->board->dealer);
        app(AuctionService::class)->call($table, $playing->seats->firstWhere('seat', $seat)->user, $pass);
      }

      return $table;
    }, [PlayingUpdated::class]);

    $this->actingAs($this->human)->deleteJson("/tables/$table->id/seats")->assertOk();

    $table->refresh();
    $this->assertNotNull($table->unattended_since);
    $this->assertNull($table->moderated_by);

    // nobody human is left: the robots don't move
    PlayingUpdated::dispatch($table);
    $this->assertFalse(app(RobotService::class)->act($table));

    $playing = BoardTable::where('table_id', $table->id)->sole();
    $this->assertSame(0, $playing->seats()->whereNotNull('ready_at')->count());

    // a human sitting down runs the table; their Start deals, and the
    // robots start at once
    $newcomer = User::factory()->create();
    $this->actingAs($newcomer)->postJson("/tables/$table->id/seats", ['seat' => 'N'])->assertCreated();

    $table->refresh();
    $this->assertNull($table->unattended_since);
    $this->assertSame($newcomer->id, $table->moderated_by);
    $this->assertSame($playing->id, $this->state->currentPlaying($table)->id);

    $this->actingAs($newcomer)->postJson("/tables/$table->id/start")
      ->assertOk()
      ->assertJsonPath('message', 'Board dealt.');

    $next = $this->state->currentPlaying($table->refresh());
    $this->assertNotSame($playing->id, $next->id);
    $this->assertSame($newcomer->id, $this->state->actingUserId($next), 'the robots should have called up to the human');
  }

  /**
   * The human creates a table with robots and presses Start, which deals the
   * first board; the robots then call until it is the human's turn.
   */
  private function robotTable(): Table
  {
    $response = $this->actingAs($this->human)
      ->postJson('/tables', ['name' => 'Practice', 'robots' => true])
      ->assertCreated();

    $this->actingAs($this->human)->postJson('/tables/'.$response->json('data.id').'/start')->assertOk();

    return Table::findOrFail($response->json('data.id'));
  }

  /**
   * The human plays their turns, as a client would, until the board is
   * finished: the robots' own logic stands in for the human's choices,
   * answering a robot's claim too.
   */
  private function playOut(Table $table): void
  {
    for ($moves = 0; ; $moves++) {
      $this->assertLessThan(100, $moves, 'the board never finished');

      $state = $this->actingAs($this->human)->getJson("/tables/$table->id/playing")->assertOk()->json('data');

      if ($state['phase'] === 'finished') {
        return;
      }

      if ($state['claim'] !== null) {
        $this->postJson("/tables/$table->id/claim/response", ['accept' => RobotClaims::accepts($state)])->assertOk();

        continue;
      }

      $this->assertSame($this->human->id, $state['acting_user_id'], 'the robots stopped on their own turn');

      if ($state['phase'] === 'auction') {
        $calls = array_map(fn ($call) => ['seat' => $call['seat'], 'call' => $call['bid']['call']], $state['auction']);
        $call = RobotBidder::choose(new RobotHand($state['hand']), $calls, $state['my_seat']);

        $this->postJson("/tables/$table->id/calls", ['bid_id' => Bid::where('suit', $call)->value('id')])->assertCreated();
      } else {
        $this->postJson("/tables/$table->id/cards", ['card_id' => RobotCardPlayer::choose($state)])->assertCreated();
      }
    }
  }

  /**
   * Every call and card of a playing, replayed through the rules in order.
   */
  private function assertReplaysThroughTheRules(BoardTable $playing): void
  {
    $playing->load(PlayingStateService::RELATIONS);
    $calls = $this->state->calls($playing);

    foreach ($calls as $i => $call) {
      $made = array_slice($calls, 0, $i);
      $this->assertSame(AuctionService::nextToCall($made, $playing->board->dealer), $call['seat']);
      $this->assertNull(AuctionService::illegalReason($made, $call['seat'], $call['bid']));
    }

    $this->assertTrue(AuctionService::isOver($calls));

    $plays = $this->state->plays($playing);
    $trump = $this->state->trump($playing);
    $deal = $this->state->deal($playing);

    foreach ($plays as $i => $play) {
      $made = array_slice($plays, 0, $i);
      $this->assertSame(CardPlayService::nextToPlay($made, $playing->declarer_seat, $trump), $play['seat']);

      $held = array_values(array_filter(
        $deal[$play['seat']],
        fn ($card) => ! in_array($card['id'], array_map(fn ($done) => (int) $done['card']->id, $made), true),
      ));
      $this->assertNull(CardPlayService::illegalReason($made, $held, $play['card']));
    }
  }

  /**
   * `$declarer` declares 1 of the suit it holds over three passes, with the
   * deal rigged so each seat holds one whole suit (`$swap` exchanges two
   * cards), and nobody has played a card yet. The human sits in `$human`,
   * robots in the other seats, held back while it is set up.
   *
   * @param  array<string, string>  $suits  seat => the suit it holds
   * @param  array{0: array{0: string, 1: int}, 1: array{0: string, 1: int}}|null  $swap  two [suit, rank] cards
   */
  private function claimTable(array $suits, ?array $swap = null, string $human = 'N', string $declarer = 'N'): Table
  {
    return Event::fakeFor(function () use ($suits, $swap, $human, $declarer) {
      $table = Table::create(['created_by' => $this->human->id, 'moderated_by' => $this->human->id]);
      app(TableSeatService::class)->seat($table, $this->human, $human);

      foreach (array_diff(Seats::SEATS, [$human]) as $seat) {
        app(RobotService::class)->seatRobot($table, $seat, $this->human);
      }

      $this->startBoard($table);

      $playing = $this->state->currentPlaying($table->refresh());

      foreach ($suits as $seat => $suit) {
        DB::table('board_card')
          ->where('board_id', $playing->board_id)
          ->whereIn('card_id', DB::table('cards')->where('suit', $suit)->select('id'))
          ->update(['seat' => $seat]);
      }

      if ($swap !== null) {
        $seats = array_map(fn ($card) => array_flip($suits)[$card[0]], $swap);
        $ids = array_map(fn ($card) => DB::table('cards')->where('suit', $card[0])->where('rank', $card[1])->value('id'), $swap);

        DB::table('board_card')->where('board_id', $playing->board_id)->where('card_id', $ids[0])->update(['seat' => $seats[1]]);
        DB::table('board_card')->where('board_id', $playing->board_id)->where('card_id', $ids[1])->update(['seat' => $seats[0]]);
      }

      $auction = app(AuctionService::class);
      $pass = Bid::where('suit', Bid::PASS)->firstOrFail();
      $opening = Bid::where('suit', '1'.$suits[$declarer])->firstOrFail();
      $opened = false;

      while (! AuctionService::isOver($this->state->calls($playing->refresh()))) {
        $seat = AuctionService::nextToCall($this->state->calls($playing), $playing->board->dealer);
        $bid = $seat === $declarer && ! $opened ? $opening : $pass;
        $opened = $opened || $seat === $declarer;

        $auction->call($table, $playing->seats->firstWhere('seat', $seat)->user, $bid);
      }

      return $table;
    }, [PlayingUpdated::class]);
  }
}
