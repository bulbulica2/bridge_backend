<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Models\Bid;
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
 * The set clock: each human (not an admin) has a time bank for the whole
 * set, `table_sets.minutes` (copied from the table's `set_minutes` when the
 * set opens), which runs down only while the board waits for them
 * (`acting_user_id`). Every call, card or claim takes the time it was
 * awaited off the acting player's bank. `turn_deadline` is the earlier of
 * the turn clock and the bank's end (`turn_deadline_by`), and running out
 * of the bank puts a robot in the seat (`set_time`).
 */
class SetClockTest extends TestCase
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

    config(['bridge.away_seconds' => 60, 'bridge.turn_seconds' => 60, 'bridge.set_minutes' => 16]);

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

  public function test_a_new_table_takes_the_set_minutes_asked_for_or_the_default(): void
  {
    $this->assertSame(16, $this->table->set_minutes);

    $this->actingAs(User::factory()->create())->postJson('/tables')
      ->assertCreated()
      ->assertJsonPath('data.set_minutes', 16);

    config(['bridge.set_minutes' => 12]);
    $this->actingAs(User::factory()->create())->postJson('/tables')
      ->assertCreated()
      ->assertJsonPath('data.set_minutes', 12);

    $this->actingAs(User::factory()->create())->postJson('/tables', ['set_minutes' => 8])
      ->assertCreated()
      ->assertJsonPath('data.set_minutes', 8);

    foreach ([10, 0, 'abc', 60] as $minutes) {
      $this->actingAs(User::factory()->create())->postJson('/tables', ['set_minutes' => $minutes])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('set_minutes');
    }
  }

  public function test_the_moderator_changes_the_set_minutes_between_sets_but_not_mid_set(): void
  {
    $url = "/tables/{$this->table->id}";

    // only a manager
    $this->actingAs($this->players['E'])->patchJson($url, ['set_minutes' => 20])
      ->assertForbidden()
      ->assertJsonPath('message', "Only the table moderator or an admin can change the table's settings.");
    $this->actingAs($this->players['N'])->patchJson($url, ['set_minutes' => 9])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('set_minutes');

    Event::fake([TableUpdated::class]);

    $this->actingAs($this->players['N'])->patchJson($url, ['set_minutes' => 20])
      ->assertOk()
      ->assertJsonPath('message', 'Table updated successfully.')
      ->assertJsonPath('data.set_minutes', 20)
      ->assertJsonPath('data.can_manage', true);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['set_minutes'] === 20);

    // the set copies it when it opens
    $this->startBoard($this->table);
    $set = TableSet::sole();
    $this->assertSame(20, $set->minutes);
    $this->state('N')
      ->assertJsonPath('data.set.minutes', 20)
      ->assertJsonPath('data.set.time_left', ['N' => 1200, 'E' => 1200, 'S' => 1200, 'W' => 1200]);

    // mid-set, between its boards too
    $this->actingAs($this->players['N'])->patchJson($url, ['set_minutes' => 8])
      ->assertStatus(409)
      ->assertJsonPath('message', 'A set is going on at this table: change its settings once it is over.');
    $this->passOut();
    $this->actingAs($this->players['N'])->patchJson($url, ['set_minutes' => 8])->assertStatus(409);
    $this->assertSame(20, $this->table->fresh()->set_minutes);

    // once it is over, it changes the next set only
    $set->end(TableSet::ENDED_COMPLETED);
    $this->actingAs($this->players['N'])->patchJson($url, ['set_minutes' => 8])
      ->assertOk()
      ->assertJsonPath('data.set_minutes', 8)
      ->assertJsonPath('data.set.minutes', 20);
    $this->assertSame(20, $set->fresh()->minutes);

    foreach (Seats::SEATS as $seat) {
      $this->actingAs($this->players[$seat])->postJson("/tables/{$this->table->id}/start")->assertOk();
    }

    $this->state('N')
      ->assertJsonPath('data.set.number', 2)
      ->assertJsonPath('data.set.minutes', 8)
      ->assertJsonPath('data.set.time_left', ['N' => 480, 'E' => 480, 'S' => 480, 'W' => 480]);
  }

  public function test_the_bank_runs_only_for_the_acting_player_by_the_time_they_took(): void
  {
    $this->startBoard($this->table);
    $declarer = $this->turn();
    $leader = Seats::next($declarer);
    $dummy = Seats::partner($declarer);
    $fourth = Seats::partner($leader);

    $this->state($leader)
      ->assertJsonPath('data.turn_started_at', now()->toJSON())
      ->assertJsonPath('data.turn_deadline_by', 'move');

    $this->travel(20)->seconds();
    $this->bid($declarer, '1NT')->assertJsonPath('data.turn_started_at', now()->toJSON());
    $this->travel(5)->seconds();
    $this->bid($leader, 'P');
    $this->travel(3)->seconds();
    $this->bid($dummy, 'P');
    $this->travel(2)->seconds();
    $this->bid($fourth, 'P');
    $this->travel(7)->seconds();
    $this->playCard($leader, $leader);

    // dummy's turn is declarer's time
    $this->state($declarer)
      ->assertJsonPath('data.turn', $dummy)
      ->assertJsonPath('data.acting_user_id', $this->players[$declarer]->id);

    $this->travel(11)->seconds();
    Event::fake([PlayingUpdated::class]);
    $this->playCard($declarer, $dummy);

    $left = [$declarer => 960 - 20 - 11, $leader => 960 - 5 - 7, $dummy => 960 - 3, $fourth => 960 - 2];
    $expected = array_merge(array_fill_keys(Seats::SEATS, null), $left);

    $this->state($fourth)->assertJsonPath('data.set.time_left', $expected);
    Event::assertDispatched(PlayingUpdated::class, fn ($event) => $event->playing['set']['time_left'] === $expected
      && $event->playing['turn_started_at'] === now()->toJSON());
    $this->assertSame($expected[$declarer] * 1000, TableSetSeat::where('seat', $declarer)->sole()->time_left_ms);

    // being there, a heartbeat or a chat line, takes nothing
    $this->travel(30)->seconds();
    $this->actingAs($this->players[$fourth])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();
    $this->actingAs($this->players[$fourth])
      ->postJson("/tables/{$this->table->id}/messages", ['body' => 'Hm.', 'to' => 'table'])
      ->assertCreated();
    $this->state($fourth)->assertJsonPath('data.set.time_left', $expected);
  }

  public function test_nobody_is_charged_between_boards_or_while_a_claim_is_pending(): void
  {
    config(['bridge.claim_seconds' => 600]);
    $playing = $this->startBoard($this->table);
    $full = ['N' => 960, 'E' => 960, 'S' => 960, 'W' => 960];

    // passed out at once; five minutes between boards
    $this->passOut();
    $this->state('N')->assertJsonPath('data.turn_deadline', null)->assertJsonPath('data.turn_deadline_by', null);
    $this->travel(5)->minutes();
    $this->assertNotNull(app(BoardSelectionService::class)->dealNext($playing->id));
    $this->state('N')->assertJsonPath('data.set.board', 2)->assertJsonPath('data.set.time_left', $full);

    $declarer = $this->turn();
    $leader = Seats::next($declarer);
    $this->bid($declarer, '1NT');
    $this->bid($leader, 'P');
    $this->bid(Seats::partner($declarer), 'P');
    $this->bid(Seats::partner($leader), 'P');
    $this->playCard($leader, $leader);

    // declarer claims ten seconds into dummy's turn: charged up to the claim
    $this->travel(10)->seconds();
    $this->actingAs($this->players[$declarer])->postJson("/tables/{$this->table->id}/claim", ['tricks' => 7])
      ->assertCreated()
      ->assertJsonPath('data.turn_deadline', null)
      ->assertJsonPath("data.set.time_left.$declarer", 950);

    // then nobody while it is pending
    $this->travel(5)->minutes();
    $this->actingAs($this->players[$leader])->postJson("/tables/{$this->table->id}/claim/response", ['accept' => false])
      ->assertOk()
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON())
      ->assertJsonPath('data.set.time_left', [...$full, $declarer => 950]);

    $this->travel(4)->seconds();
    $this->playCard($declarer, Seats::partner($declarer))->assertJsonPath("data.set.time_left.$declarer", 946);
  }

  public function test_the_deadline_is_the_end_of_the_bank_when_that_comes_first(): void
  {
    $this->startBoard($this->table);
    $dealer = $this->turn();
    $next = Seats::next($dealer);
    TableSetSeat::where('seat', $dealer)->update(['time_left_ms' => 45_000]);
    TableSetSeat::where('seat', $next)->update(['time_left_ms' => 60_000]);

    $this->state($dealer)
      ->assertJsonPath('data.set.time_left.'.$dealer, 45)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(45)->toJSON())
      ->assertJsonPath('data.turn_deadline_by', 'set');

    // a bank the size of a turn: it is the set's time that runs out
    $this->travel(10)->seconds();
    $this->bid($dealer, 'P')
      ->assertJsonPath('data.set.time_left.'.$dealer, 35)
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON())
      ->assertJsonPath('data.turn_deadline_by', 'set');

    // more than a turn: the turn clock ends it
    $this->bid($next, 'P')
      ->assertJsonPath('data.turn_deadline', now()->addSeconds(60)->toJSON())
      ->assertJsonPath('data.turn_deadline_by', 'move');
  }

  public function test_running_out_of_the_bank_puts_a_robot_in_the_seat_for_set_time(): void
  {
    $this->startBoard($this->table);
    $set = TableSet::sole();
    $dealer = $this->turn();
    $late = $this->players[$dealer];
    $partner = Seats::partner($dealer);
    TableSetSeat::where('seat', $dealer)->update(['time_left_ms' => 30_000]);

    $this->travel(29)->seconds();
    $this->alive();
    $this->assertSame(0, $this->seats->checkAway()['timed_out']);

    $this->travel(1)->seconds();
    $this->alive();
    $this->artisan('tables:check-away')
      ->expectsOutput('Marked 0 players away, replaced 1 player with a robot, freed 0 seats, expired 0 claims.')
      ->assertSuccessful();

    $robot = TableSeat::where('table_id', $this->table->id)->where('seat', $dealer)->sole()->user;
    $this->assertTrue($robot->is_robot);
    $this->assertSame(
      ['user_id' => $robot->id, 'replaced_user_id' => $late->id, 'replaced_reason' => 'set_time', 'time_left_ms' => 0],
      TableSetSeat::where('seat', $dealer)->sole()->only('user_id', 'replaced_user_id', 'replaced_reason', 'time_left_ms')
    );
    $this->assertNull($set->fresh()->finished_at);

    // the robot has no bank; the set's results keep the time its human used
    $this->state($partner)
      ->assertJsonPath('data.set.replaced.0.reason', 'set_time')
      ->assertJsonPath("data.set.time_left.$dealer", null);
    $this->actingAs($late)->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.minutes', 16)
      ->assertJsonPath("data.time_left.$dealer", null)
      ->assertJsonPath("data.time_used.$dealer", 960)
      ->assertJsonPath("data.time_used.$partner", 0)
      ->assertJsonPath('data.replaced.0.reason', 'set_time');
  }

  public function test_running_out_of_the_bank_while_away_is_still_set_time(): void
  {
    $this->startBoard($this->table);
    $dealer = $this->turn();
    TableSetSeat::where('seat', $dealer)->update(['time_left_ms' => 20_000]);
    $this->actingAs($this->players[$dealer])->deleteJson("/tables/{$this->table->id}/seats")->assertStatus(202);

    $this->travel(20)->seconds();
    $this->assertSame(1, $this->seats->checkAway()['timed_out']);
    $this->assertSame(TableSetSeat::REASON_SET_TIME, TableSetSeat::where('seat', $dealer)->sole()->replaced_reason);
  }

  public function test_robots_and_admins_have_no_bank(): void
  {
    $human = User::factory()->create();
    $id = $this->actingAs($human)->postJson('/tables', ['robots' => true, 'set_minutes' => 12, 'seat' => 'S'])
      ->assertCreated()
      ->json('data.id');
    $table = Table::findOrFail($id);

    // hold the robots back
    Event::fake([PlayingUpdated::class]);
    $this->startBoard($table);

    $this->actingAs($human)->getJson("/tables/$table->id/playing")
      ->assertJsonPath('data.set.minutes', 12)
      ->assertJsonPath('data.set.time_left', ['N' => null, 'E' => null, 'S' => 720, 'W' => null]);
    $this->actingAs($human)->getJson("/tables/$table->id")
      ->assertJsonPath('data.set.time_left', ['N' => null, 'E' => null, 'S' => 720, 'W' => null]);
    $this->actingAs($human)->getJson('/sets/'.TableSet::where('table_id', $table->id)->sole()->id)
      ->assertJsonPath('data.time_used', ['N' => null, 'E' => null, 'S' => 0, 'W' => null]);

    // an admin's turn costs nothing either
    $this->players['E']->forceFill(['is_admin' => true])->save();
    $this->startBoard($this->table);
    $this->state('N')->assertJsonPath('data.set.time_left.E', null);
    $this->assertNull(TableSetSeat::where('table_set_id', TableSet::where('table_id', $this->table->id)->sole()->id)->where('seat', 'E')->sole()->time_left_ms);

    while ($this->turn() !== 'E') {
      $this->bid($this->turn(), 'P');
    }

    $this->state('N')->assertJsonPath('data.turn_deadline', null)->assertJsonPath('data.turn_deadline_by', null);
    $this->travel(2)->minutes();
    $this->bid('E', 'P');
    $this->state('N')->assertJsonPath('data.set.time_left.E', null);
  }

  /**
   * The seat the board is waiting for.
   */
  private function turn(): ?string
  {
    $state = app(PlayingStateService::class);

    return $state->turn($state->currentPlaying($this->table->refresh()));
  }

  /**
   * Four passes, from whoever is on turn.
   */
  private function passOut(): void
  {
    foreach (range(1, 4) as $call) {
      $this->bid($this->turn(), 'P');
    }
  }

  private function bid(string $seat, string $call): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', $call)->value('id')])
      ->assertCreated();
  }

  /**
   * `$player` plays a legal card from `$hand`'s cards: their own, or dummy's
   * for declarer.
   */
  private function playCard(string $player, string $hand): TestResponse
  {
    $state = $this->state($player)->json('data');
    $cards = $hand === $player ? $state['hand'] : $state['dummy_hand'];
    $led = $state['current_trick'][0]['card']['suit'] ?? null;
    $card = collect($cards)->firstWhere('suit', $led) ?? $cards[0];

    return $this->actingAs($this->players[$player])
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $card['id']])
      ->assertCreated();
  }

  /**
   * A sign of life from all four.
   */
  private function alive(): void
  {
    foreach ($this->players as $player) {
      $this->seats->touch($this->table, $player);
    }
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing")->assertOk();
  }
}
