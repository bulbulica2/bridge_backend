<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\DeclarerHandShown;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\BoardTableSeat;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\TableSet;
use App\Models\TableSetSeat;
use App\Models\User;
use App\Services\BoardSelectionService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The turn clock: the human the board waits for (`acting_user_id`) has
 * `bridge.turn_seconds` to call, play or act on a claim, from when the
 * board began waiting for them, shown as `turn_deadline`. Only a move
 * resets it. Once it has passed, `tables:check-away` takes them out and a
 * robot plays their seat for the rest of the set, from where the board is
 * (`turn_timeout`); they may not sit down there again until the set is
 * over. Nobody has a clock between boards or while a claim is pending, nor
 * does a robot or an admin.
 */
class TurnTimerTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  private Table $table;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    config(['bridge.away_seconds' => 60, 'bridge.turn_seconds' => 60]);

    // whole seconds, as the timestamp columns keep them
    $this->freezeSecond();

    $this->seats = app(TableSeatService::class);
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      $this->seats->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->update(['moderated_by' => $this->players['N']->id]);
  }

  public function test_the_dealer_has_a_minute_from_the_deal_and_each_call_hands_the_next_player_a_fresh_one(): void
  {
    // the last Start deals, and its answer carries the dealer's deadline
    foreach (['N', 'E', 'S'] as $seat) {
      $this->actingAs($this->players[$seat])->postJson("/tables/{$this->table->id}/start")->assertOk();
    }

    Event::fake([PlayingUpdated::class]);

    $this->actingAs($this->players['W'])->postJson("/tables/{$this->table->id}/start")
      ->assertOk()
      ->assertJsonPath('data.playing.turn_deadline', now()->addSeconds(60)->toJSON());

    $dealer = $this->turn();
    Event::assertDispatched(PlayingUpdated::class, fn ($event) => $event->playing['turn_deadline'] === now()->addSeconds(60)->toJSON());
    $this->state($dealer)
      ->assertJsonPath('data.acting_user_id', $this->players[$dealer]->id)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON());

    // a call 20 seconds on: the next player's minute starts now
    $this->travel(20)->seconds();
    Event::fake([PlayingUpdated::class]);

    $this->pass($dealer)
      ->assertJsonPath('data.acting_user_id', $this->players[Seats::next($dealer)]->id)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON());
    Event::assertDispatched(PlayingUpdated::class, fn ($event) => $event->playing['turn_deadline'] === now()->addSeconds(60)->toJSON());
  }

  public function test_the_turn_lasts_as_long_as_configured(): void
  {
    config(['bridge.turn_seconds' => 30]);
    $this->startBoard($this->table);

    $this->state('N')->assertJsonPath('data.turn_deadline', now()->addSeconds(30)->toJSON());
  }

  public function test_being_there_is_not_playing(): void
  {
    $this->startBoard($this->table);
    $turn = $this->turn();
    $deadline = now()->addSeconds(60)->toJSON();

    // a heartbeat, the game state and a chat line, 30 seconds on
    $this->travel(30)->seconds();
    $this->actingAs($this->players[$turn])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();
    $this->state($turn)->assertOk();
    $this->actingAs($this->players[$turn])
      ->postJson("/tables/{$this->table->id}/messages", ['body' => 'Thinking…', 'to' => 'table'])
      ->assertCreated();

    $this->state($turn)->assertJsonPath('data.turn_deadline', $deadline);
    $this->assertEquals(now()->subSeconds(30), BoardTable::sole()->turn_started_at);
  }

  public function test_past_the_deadline_a_robot_takes_the_seat_and_plays_the_board_on(): void
  {
    $playing = $this->startBoard($this->table);
    $set = TableSet::sole();
    $turn = $this->turn();
    $late = $this->players[$turn];
    $partner = Seats::partner($turn);

    // there all along, never calling
    $this->travel(59)->seconds();
    $this->alive(...Seats::SEATS);
    $this->assertSame(['away' => 0, 'timed_out' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(1)->seconds();
    Event::fake([TableUpdated::class]);

    $this->artisan('tables:check-away')
      ->expectsOutput('Marked 0 players away, replaced 1 player with a robot, freed 0 seats, expired 0 claims.')
      ->assertSuccessful();

    // a robot sits in the seat, ready; the set and the board go on
    $robot = TableSeat::where('table_id', $this->table->id)->where('seat', $turn)->sole()->user;
    $this->assertTrue($robot->is_robot);
    $this->assertDatabaseMissing('table_seats', ['user_id' => $late->id]);
    $this->assertNull($set->fresh()->finished_at);
    $this->assertSame($playing->id, BoardTable::where('table_id', $this->table->id)->sole()->id);
    $this->assertSame($playing->board_id, $this->table->fresh()->board_id);

    // the set's seat and the board's snapshot are the robot's now, and say
    // whom it took over from
    $this->assertSame(
      ['user_id' => $robot->id, 'replaced_user_id' => $late->id, 'replaced_reason' => 'turn_timeout'],
      TableSetSeat::where('seat', $turn)->sole()->only('user_id', 'replaced_user_id', 'replaced_reason')
    );
    $this->assertSame(
      ['user_id' => $robot->id, 'replaced_user_id' => $late->id],
      BoardTableSeat::where('board_table_id', $playing->id)->where('seat', $turn)->sole()->only('user_id', 'replaced_user_id')
    );

    // the robot has made the call they didn't
    $this->assertSame($robot->id, (int) Auction::where('board_table_id', $playing->id)->sole()->user_id);

    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['free_seats'] === []
      && $event->table['set']['ended'] === null
      && $event->table['set']['replaced'] === [['seat' => $turn, 'user_id' => $late->id, 'reason' => 'turn_timeout']]);

    $this->state($partner)
      ->assertJsonPath('data.players.'.$turn.'.is_robot', true)
      ->assertJsonPath('data.set.replaced.0.user_id', $late->id);

    // they may still read the set, but not sit down at it again
    $this->actingAs($late)->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.ended', null)
      ->assertJsonPath('data.players.'.$turn.'.is_robot', true)
      ->assertJsonPath('data.replaced.0.reason', 'turn_timeout');
  }

  public function test_a_player_a_robot_took_over_from_may_not_sit_down_again_until_the_set_is_over(): void
  {
    $this->startBoard($this->table);
    $turn = $this->turn();
    $late = $this->players[$turn];

    $this->travel(60)->seconds();
    $this->alive(...Seats::SEATS);
    $this->seats->checkAway();

    // the robot holds the seat; were it free, it would still be refused
    TableSeat::where('table_id', $this->table->id)->where('seat', $turn)->delete();

    $this->actingAs($late)->postJson("/tables/{$this->table->id}/seats", ['seat' => $turn])
      ->assertStatus(409)
      ->assertJsonPath('message', 'You walked out on the set going on at this table: you may sit down here again once it is over.');
    $this->actingAs($this->players[Seats::next($turn)])->postJson("/tables/{$this->table->id}/seats/users", ['user_id' => $late->id, 'seat' => $turn])
      ->assertStatus(409);

    // once it is over, they may
    TableSet::sole()->end(TableSet::ENDED_COMPLETED);
    $this->actingAs($late)->postJson("/tables/{$this->table->id}/seats", ['seat' => $turn])->assertCreated();
  }

  public function test_on_dummys_turn_the_clock_follows_declarer_and_dummy_takes_over_a_robot_declarers_hand(): void
  {
    $this->startBoard($this->table);
    $declarer = $this->turn();
    $dummy = Seats::partner($declarer);
    $leader = Seats::next($declarer);

    $this->bid($declarer, '1NT');
    $this->pass($leader);
    $this->pass($dummy);
    $this->pass(Seats::partner($leader));

    // the opening leader's minute, from the last call
    $this->travel(10)->seconds();
    $this->state($leader)
      ->assertJsonPath('data.acting_user_id', $this->players[$leader]->id)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(50)->toJSON());

    $this->lead($leader)
      ->assertJsonPath('data.turn', $dummy)
      ->assertJsonPath('data.acting_user_id', $this->players[$declarer]->id)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON());

    // everyone there; declarer doesn't play dummy's card
    $this->travel(60)->seconds();
    $this->alive(...Seats::SEATS);
    Event::fake([DeclarerHandShown::class]);
    $this->seats->checkAway();

    $this->assertSame(TableSetSeat::REASON_TURN_TIMEOUT, TableSetSeat::where('seat', $declarer)->sole()->replaced_reason);
    $this->assertDatabaseMissing('table_seats', ['user_id' => $this->players[$declarer]->id]);

    // a robot declarer with a human dummy: dummy plays both hands now, with
    // a fresh minute, and gets declarer's cards
    Event::assertDispatched(DeclarerHandShown::class, fn ($event) => $event->userId === $this->players[$dummy]->id);
    $this->state($dummy)
      ->assertJsonPath('data.acting_user_id', $this->players[$dummy]->id)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON())
      ->assertJsonCount(13, 'data.declarer_hand');
  }

  public function test_no_clock_runs_between_boards_and_the_next_board_starts_one(): void
  {
    $playing = $this->startBoard($this->table);

    // passed out: the board is over, the next one comes by itself
    foreach (range(1, 4) as $call) {
      $this->pass($this->turn());
    }

    $this->state('N')->assertJsonPath('data.phase', 'finished')->assertJsonPath('data.turn_deadline', null);

    $this->travel(5)->minutes();
    $this->alive(...Seats::SEATS);
    $this->assertSame(['away' => 0, 'timed_out' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->assertNotNull(app(BoardSelectionService::class)->dealNext($playing->id));
    $this->state('N')->assertJsonPath('data.phase', 'auction')->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON());
  }

  public function test_no_clock_runs_while_a_claim_is_pending_and_a_rejected_claim_restarts_it(): void
  {
    // a claim that waits longer than a turn
    config(['bridge.claim_seconds' => 600]);
    $this->startBoard($this->table);
    $declarer = $this->turn();
    $leader = Seats::next($declarer);

    $this->bid($declarer, '1NT');
    $this->pass($leader);
    $this->pass(Seats::partner($declarer));
    $this->pass(Seats::partner($leader));
    $this->lead($leader);

    // declarer claims on dummy's turn: nobody is on turn until it is settled
    $this->travel(30)->seconds();
    $this->actingAs($this->players[$declarer])->postJson("/tables/{$this->table->id}/claim", ['tricks' => 7])
      ->assertCreated()
      ->assertJsonPath('data.turn_deadline', null);

    $this->travel(5)->minutes();
    $this->alive(...Seats::SEATS);
    $this->assertSame(0, $this->seats->checkAway()['timed_out']);

    // rejected: declarer's turn again, with a whole minute
    $this->actingAs($this->players[$leader])->postJson("/tables/{$this->table->id}/claim/response", ['accept' => false])
      ->assertOk()
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON());

    $this->travel(59)->seconds();
    $this->alive(...Seats::SEATS);
    $this->assertSame(0, $this->seats->checkAway()['timed_out']);
    $this->travel(1)->seconds();
    $this->assertSame(1, $this->seats->checkAway()['timed_out']);
    $this->assertSame($declarer, TableSetSeat::whereNotNull('replaced_user_id')->sole()->seat);
  }

  public function test_a_robot_on_turn_has_no_clock(): void
  {
    $human = User::factory()->create();
    $id = $this->actingAs($human)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');
    $table = Table::findOrFail($id);

    // hold the robots back, so that one of them stays on turn
    Event::fake([PlayingUpdated::class]);
    $this->startBoard($table);

    $state = app(PlayingStateService::class);

    if ($state->actingUserId($state->currentPlaying($table)) === $human->id) {
      $this->actingAs($human)->postJson("/tables/$table->id/calls", ['bid_id' => Bid::where('suit', 'P')->value('id')])->assertCreated();
    }

    $this->actingAs($human)->getJson("/tables/$table->id/playing")
      ->assertJsonPath('data.turn_deadline', null)
      ->assertJsonPath('data.players.'.$this->turnAt($table).'.is_robot', true);

    $this->travel(5)->minutes();
    $this->seats->touch($table, $human);
    $this->seats->checkAway();
    $this->assertDatabaseHas('table_seats', ['user_id' => $human->id]);
    $this->assertNull($table->sets()->sole()->finished_at);
  }

  /**
   * The seat the board is waiting for.
   */
  private function turn(): ?string
  {
    return $this->turnAt($this->table);
  }

  private function turnAt(Table $table): ?string
  {
    $state = app(PlayingStateService::class);

    return $state->turn($state->currentPlaying($table->refresh()));
  }

  private function bid(string $seat, string $call): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', $call)->value('id')])
      ->assertCreated();
  }

  private function pass(string $seat): TestResponse
  {
    return $this->bid($seat, 'P');
  }

  /**
   * `$seat` leads the first card of their hand.
   */
  private function lead(string $seat): TestResponse
  {
    $card = $this->state($seat)->json('data.hand.0.id');

    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $card])
      ->assertCreated();
  }

  /**
   * A sign of life from each of `$seats`.
   */
  private function alive(string ...$seats): void
  {
    foreach ($seats as $seat) {
      $this->seats->touch($this->table, $this->players[$seat]);
    }
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing")->assertOk();
  }
}
