<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSet;
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
 * resets it. Once it has passed, `tables:check-away` takes them out and
 * their side forfeits the set (`turn_timeout`). Nobody has one between
 * boards or while a claim is pending, nor does a robot or an admin.
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

  public function test_past_the_deadline_the_check_forfeits_for_their_side_abandons_the_board_and_frees_the_seat(): void
  {
    $playing = $this->startBoard($this->table);
    $set = TableSet::sole();
    $turn = $this->turn();
    $others = array_values(array_diff(Seats::SEATS, [$turn]));

    // there all along, never calling
    $this->travel(59)->seconds();
    $this->alive(...Seats::SEATS);
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(1)->seconds();
    Event::fake([TableUpdated::class]);

    $this->artisan('tables:check-away')
      ->expectsOutput('Marked 0 players away, forfeited 1 set, freed 0 seats.')
      ->assertSuccessful();

    $side = Seats::side($turn);

    $this->assertSame(
      ['ended' => TableSet::ENDED_FORFEIT, 'forfeited_by' => $side, 'forfeit_reason' => TableSet::FORFEIT_TURN_TIMEOUT],
      $set->fresh()->only('ended', 'forfeited_by', 'forfeit_reason')
    );
    $this->assertDatabaseMissing('table_seats', ['user_id' => $this->players[$turn]->id]);
    $this->assertNull($playing->fresh()->table_id);
    $this->assertNull($playing->fresh()->finished_at);
    $this->assertNull($this->table->fresh()->board_id);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['free_seats'] === [$turn]
      && $event->table['set']['forfeit_reason'] === 'turn_timeout'
      && $event->table['set']['forfeited_by'] === $side);

    $this->actingAs($this->players[$others[0]])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.forfeit_reason', 'turn_timeout')
      ->assertJsonPath('data.winner', $side === 'NS' ? 'EW' : 'NS');

    // nothing left to time out
    $this->travel(5)->minutes();
    $this->alive(...$others);
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
  }

  public function test_on_dummys_turn_the_clock_follows_declarer(): void
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
    $this->seats->checkAway();

    $this->assertSame(
      ['forfeited_by' => Seats::side($declarer), 'forfeit_reason' => 'turn_timeout'],
      TableSet::sole()->only('forfeited_by', 'forfeit_reason')
    );
    $this->assertDatabaseMissing('table_seats', ['user_id' => $this->players[$declarer]->id]);
    $this->assertDatabaseHas('table_seats', ['user_id' => $this->players[$dummy]->id]);
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
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

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
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);

    // rejected: declarer's turn again, with a whole minute
    $this->actingAs($this->players[$leader])->postJson("/tables/{$this->table->id}/claim/response", ['accept' => false])
      ->assertOk()
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON());

    $this->travel(59)->seconds();
    $this->alive(...Seats::SEATS);
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);
    $this->travel(1)->seconds();
    $this->assertSame(1, $this->seats->checkAway()['forfeited']);
    $this->assertSame(Seats::side($declarer), TableSet::sole()->forfeited_by);
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
