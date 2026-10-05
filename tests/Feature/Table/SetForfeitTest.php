<?php

namespace Tests\Feature\Table;

use App\auxiliary\Seats;
use App\Events\TableUpdated;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\TableSet;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Going away mid-set: a player quiet for a minute is away, their seat held;
 * back, play goes on. The board stops waiting only for the player on turn,
 * away or not, once their turn clock runs out (`TurnTimerTest`): an away
 * player on turn who does forfeits the set for their side as `away`. Leave
 * mid-set is going away, moving to another table forfeits at once, kicking
 * an away player forfeits, robots are never away and an admin never costs
 * their side the set.
 */
class SetForfeitTest extends TestCase
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

    config(['bridge.away_seconds' => 60, 'bridge.turn_seconds' => 60, 'bridge.idle_seat_minutes' => 5]);

    // whole seconds, as the timestamp columns keep them
    $this->freezeSecond();

    $this->seats = app(TableSeatService::class);
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      $this->seats->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->update(['moderated_by' => $this->players['N']->id]);
    $this->startBoard($this->table);
  }

  public function test_a_quiet_player_is_away_after_a_minute_and_back_with_any_sign_of_life(): void
  {
    $dealer = $this->turn();
    $quiet = Seats::partner($dealer);
    $others = $this->others($quiet);
    $lastSeen = $this->seatOf($quiet)->last_seen_at;

    // the others play on
    $this->travel(30)->seconds();
    $this->pass($dealer);

    // 50 seconds quiet: not yet
    $this->travel(20)->seconds();
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(15)->seconds();
    $this->alive(...$others);

    Event::fake([TableUpdated::class]);

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $quiet)['away_since'] === $lastSeen->toJSON());

    // away since their last sign of life; a seat has no clock of its own
    $seat = $this->seatIn($this->tableAs($others[0])->json('data'), $quiet);
    $this->assertSame($lastSeen->toJSON(), $seat['away_since']);
    $this->assertArrayNotHasKey('forfeit_at', $seat);

    // the seat is held: nobody else takes it
    $this->assertSame(4, $this->table->seats()->count());
    $this->assertSame([], $this->table->fresh()->freeSeats());

    // back with a heartbeat
    $this->travel(5)->seconds();
    Event::fake([TableUpdated::class]);
    $this->actingAs($this->players[$quiet])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();

    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $quiet)['away_since'] === null);
    $this->assertNull($this->seatOf($quiet)->away_since);

    $this->alive(...Seats::SEATS);
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull(TableSet::sole()->finished_at);
    $this->state($quiet)->assertOk()->assertJsonPath('data.phase', 'auction');
  }

  public function test_a_playing_request_brings_an_away_player_back(): void
  {
    $dealer = $this->turn();
    $quiet = Seats::partner($dealer);

    $this->travel(30)->seconds();
    $this->pass($dealer);
    $this->travel(35)->seconds();
    $this->alive(...$this->others($quiet));
    $this->seats->checkAway();
    $this->assertNotNull($this->seatOf($quiet)->away_since);

    $this->state($quiet)->assertOk();

    $this->assertNull($this->seatOf($quiet)->away_since);
  }

  public function test_an_away_player_on_turn_who_runs_out_of_time_forfeits_the_set_as_away(): void
  {
    $playing = BoardTable::where('table_id', $this->table->id)->sole();
    $set = TableSet::sole();
    $turn = $this->turn();
    $others = $this->others($turn);
    $this->table->update(['moderated_by' => $this->players[$turn]->id]);

    // they leave on their turn: the seat is held, their clock runs on
    $this->travel(10)->seconds();
    $this->leave($turn)->assertStatus(202);

    // the board began waiting for them at the deal: 59 seconds on, not yet
    $this->travel(49)->seconds();
    $this->alive(...$others);
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);

    $this->travel(1)->seconds();

    Event::fake([TableUpdated::class]);

    $this->artisan('tables:check-away')
      ->expectsOutput('Marked 0 players away, forfeited 1 set, freed 0 seats.')
      ->assertSuccessful();

    $side = Seats::side($turn);
    $winners = $side === 'NS' ? 'EW' : 'NS';

    $set->refresh();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame($side, $set->forfeited_by);
    $this->assertSame(TableSet::FORFEIT_AWAY, $set->forfeit_reason);
    $this->assertNotNull($set->finished_at);

    // the seat freed as by a leave, the board on the table abandoned
    $this->assertNull($this->seatOf($turn));
    $this->assertNull($playing->fresh()->table_id);
    $this->assertNull($this->table->fresh()->board_id);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['set']['ended'] === 'forfeit'
      && $event->table['set']['forfeited_by'] === $side
      && $event->table['set']['forfeit_reason'] === 'away'
      && $event->table['free_seats'] === [$turn]);

    // the moderator was the one to go: the role is handed on, to the
    // human seated here longest
    $this->assertSame($this->players[$others[0]]->id, (int) $this->table->fresh()->moderated_by);

    // everyone gets the set's results: the other side won it
    $this->actingAs($this->players[$others[0]])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.ended', 'forfeit')
      ->assertJsonPath('data.forfeited_by', $side)
      ->assertJsonPath('data.forfeit_reason', 'away')
      ->assertJsonPath('data.winner', $winners);
    $this->actingAs($this->players[$turn])->getJson("/sets/$set->id")->assertOk();

    // a newcomer and everyone's Start: a new set
    $this->seats->seat($this->table, User::factory()->create(), $turn);
    $this->startBoard($this->table);
    $this->assertSame(2, $this->table->fresh()->latestSet->number);
  }

  public function test_an_away_player_not_on_turn_never_forfeits(): void
  {
    // an admin on turn has no clock: the board waits for them for good
    $turn = $this->turn();
    $this->players[$turn]->forceFill(['is_admin' => true])->save();
    $quiet = Seats::next($turn);

    $this->travel(10)->minutes();
    $this->alive(...$this->others($quiet));

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(10)->minutes();
    $this->alive(...$this->others($quiet));
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull(TableSet::sole()->finished_at);
    $this->assertNotNull($this->seatOf($quiet)->away_since);
  }

  public function test_leave_mid_set_holds_the_seat_and_the_player_may_come_back(): void
  {
    $turn = $this->turn();
    $other = Seats::next($turn);
    Event::fake([TableUpdated::class]);

    $this->leave($turn)
      ->assertStatus(202)
      ->assertJsonPath('message', 'You left in the middle of a set: your seat is held. Once the table is waiting for you, you have 60 seconds to play, or your side forfeits the set.')
      ->assertJsonPath('data.free_seats', []);

    $seat = $this->seatOf($turn);
    $this->assertEquals(now(), $seat->away_since);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $turn)['away_since'] === now()->toJSON());

    // somebody else leaving is away too
    $this->leave($other)->assertStatus(202);
    $this->assertNotNull($this->seatOf($other)->away_since);

    // nobody else may sit there meanwhile
    $this->actingAs(User::factory()->create())->postJson("/tables/{$this->table->id}/seats", ['seat' => $turn])->assertStatus(409);

    // leaving again changes nothing
    $this->travel(20)->seconds();
    $this->leave($turn)->assertStatus(202);
    $this->assertEquals($seat->away_since, $this->seatOf($turn)->away_since);

    // back in time, and playing
    $this->actingAs($this->players[$turn])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();
    $this->assertNull($this->seatOf($turn)->away_since);
    $this->pass($turn);

    $this->travel(30)->seconds();
    $this->alive(...$this->others($other));
    $this->seats->checkAway();
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_leave_with_a_one_second_turn_says_second(): void
  {
    config(['bridge.turn_seconds' => 1]);

    $this->leave('E')->assertStatus(202)->assertJsonPath('message', 'You left in the middle of a set: your seat is held. Once the table is waiting for you, you have 1 second to play, or your side forfeits the set.');
  }

  public function test_leave_between_boards_of_a_set_holds_the_seat_too(): void
  {
    $this->finishBoard();

    $this->leave('E')->assertStatus(202);

    // a quit through DELETE /tables/{table}/seats/{user} is the same
    $this->actingAs($this->players['W'])->deleteJson("/tables/{$this->table->id}/seats/{$this->players['W']->id}")->assertStatus(202);

    $this->assertSame(4, $this->table->seats()->count());
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_outside_a_set_leave_is_immediate_and_nobody_is_ever_away(): void
  {
    TableSet::sole()->update(['size' => 1]);
    $this->finishBoard();

    $this->travel(2)->minutes();
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->leave('E')->assertOk()->assertJsonPath('message', 'You left the table.')->assertJsonPath('data.free_seats', ['E']);
    $this->assertSame(TableSet::ENDED_COMPLETED, TableSet::sole()->ended);
  }

  public function test_a_player_held_when_the_set_ends_is_freed(): void
  {
    $this->leave('E')->assertStatus(202);

    // the set ends meanwhile without E: here its last board is played out
    TableSet::sole()->update(['size' => 1]);
    $this->finishBoard();

    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 1], $this->seats->checkAway());

    $this->assertNull($this->seatOf('E'));
    $this->assertSame(TableSet::ENDED_COMPLETED, TableSet::sole()->ended);
  }

  public function test_moving_to_another_table_mid_set_forfeits_at_once(): void
  {
    $other = Table::factory()->create(['board_id' => null]);

    $this->actingAs($this->players['W'])->postJson("/tables/$other->id/seats", ['seat' => 'N'])
      ->assertCreated()
      ->assertJsonPath('message', 'Seat taken successfully. You walked out on a set at your old table, so your side forfeited it.');

    $set = TableSet::sole();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame('EW', $set->forfeited_by);
    $this->assertSame(TableSet::FORFEIT_MOVED, $set->forfeit_reason);
    $this->assertSame($other->id, $this->seatOf('W')->table_id);

    // with the set over, moving on costs nothing
    $this->actingAs($this->players['N'])->postJson("/tables/$other->id/seats", ['seat' => 'S'])
      ->assertCreated()
      ->assertJsonPath('message', 'Seat taken successfully.');
    $this->assertSame('EW', $set->fresh()->forfeited_by);
  }

  public function test_kicking_an_away_player_forfeits_and_a_present_one_abandons(): void
  {
    $this->leave('E')->assertStatus(202);

    $this->actingAs($this->players['N'])->deleteJson("/tables/{$this->table->id}/seats/{$this->players['E']->id}")
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table. They were away mid-set, so their side forfeited it.')
      ->assertJsonPath('data.set.ended', 'forfeit')
      ->assertJsonPath('data.set.forfeited_by', 'EW')
      ->assertJsonPath('data.set.forfeit_reason', 'kicked');

    // a new set, and a kick of somebody who is there
    $this->seats->seat($this->table, $this->players['E'], 'E');
    $this->startBoard($this->table);

    $this->actingAs($this->players['N'])->deleteJson("/tables/{$this->table->id}/seats/{$this->players['S']->id}")
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table.')
      ->assertJsonPath('data.set.ended', 'abandoned')
      ->assertJsonPath('data.set.forfeited_by', null)
      ->assertJsonPath('data.set.forfeit_reason', null);
  }

  public function test_robots_are_never_away_and_a_robots_partner_forfeits_for_their_side(): void
  {
    $human = User::factory()->create();
    $id = $this->actingAs($human)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');
    $table = Table::findOrFail($id);
    $this->startBoard($table);

    // the robots have called up to the human. Robots send no heartbeat:
    // they are never away
    $this->travel(59)->seconds();
    $this->seats->touch($table, $human);
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // there, but not playing: their side, robot partner and all, loses the
    // set when their turn clock runs out
    $seat = $table->seats()->where('user_id', $human->id)->value('seat');
    $this->travel(1)->seconds();
    $this->seats->checkAway();

    $set = $table->sets()->sole();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame(Seats::side($seat), $set->forfeited_by);
    $this->assertSame(TableSet::FORFEIT_TURN_TIMEOUT, $set->forfeit_reason);
    $this->assertSame(3, $table->seats()->count());
    $this->assertNotNull($table->fresh()->unattended_since);
  }

  public function test_an_admin_on_turn_away_has_no_clock(): void
  {
    $turn = $this->turn();
    $this->players[$turn]->forceFill(['is_admin' => true])->save();

    $this->state($this->others($turn)[0])->assertJsonPath('data.turn_deadline', null);

    $this->travel(10)->minutes();
    $this->alive(...$this->others($turn));

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // away, but no deadline: the table waits for them
    $this->assertNotNull($this->seatOf($turn)->away_since);
    $this->state($this->others($turn)[0])->assertJsonPath('data.turn_deadline', null);

    $this->travel(10)->minutes();
    $this->alive(...$this->others($turn));
    $this->seats->checkAway();
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_while_an_admin_is_away_the_others_may_leave_without_penalty(): void
  {
    // the admin calls first, then goes quiet; the others play on
    $admin = $this->turn();
    $this->players[$admin]->forceFill(['is_admin' => true])->save();
    $this->pass($admin);

    $this->travel(30)->seconds();
    $this->pass(Seats::next($admin));

    $this->travel(31)->seconds();
    $this->alive(...$this->others($admin));
    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // Leave is immediate: the set is abandoned, nobody forfeits it
    $leaver = Seats::partner(Seats::next($admin));
    $this->leave($leaver)->assertOk()->assertJsonPath('message', 'You left the table.');
    $this->assertSame(['ended' => 'abandoned', 'forfeited_by' => null], TableSet::sole()->only('ended', 'forfeited_by'));

    // with the set over, the admin's seat is kept, no longer away
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull($this->seatOf($admin)->away_since);
  }

  public function test_while_an_admin_is_away_the_player_on_turn_still_runs_out_of_time(): void
  {
    $admin = $this->turn();
    $this->players[$admin]->forceFill(['is_admin' => true])->save();
    $this->pass($admin);

    $this->travel(50)->seconds();
    $this->pass(Seats::next($admin));
    $late = Seats::partner($admin);

    $this->travel(15)->seconds();
    $this->alive(...$this->others($admin));
    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // their own turn, their own time: the admin being away changes nothing
    $this->travel(44)->seconds();
    $this->alive(...$this->others($admin));
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);

    $this->travel(1)->seconds();
    $this->assertSame(1, $this->seats->checkAway()['forfeited']);
    $this->assertSame(
      ['ended' => 'forfeit', 'forfeited_by' => Seats::side($late), 'forfeit_reason' => 'turn_timeout'],
      TableSet::sole()->only('ended', 'forfeited_by', 'forfeit_reason')
    );
  }

  public function test_an_admins_own_leave_mid_set_is_immediate(): void
  {
    $this->players['S']->forceFill(['is_admin' => true])->save();

    $this->leave('S')->assertOk()->assertJsonPath('message', 'You left the table.');

    $this->assertNull($this->seatOf('S'));
    $this->assertSame(TableSet::ENDED_ABANDONED, TableSet::sole()->ended);
  }

  public function test_the_check_runs_every_ten_seconds(): void
  {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'tables:check-away'));

    $this->assertNotNull($event);
    $this->assertSame(10, $event->repeatSeconds);
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
   * Every seat but `$seats`, in seat order.
   *
   * @return list<string>
   */
  private function others(string ...$seats): array
  {
    return array_values(array_diff(Seats::SEATS, $seats));
  }

  private function pass(string $seat): void
  {
    $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', 'P')->value('id')])
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

  private function leave(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->deleteJson("/tables/{$this->table->id}/seats");
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing");
  }

  private function tableAs(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}")->assertOk();
  }

  /**
   * Pass the board on the table out.
   */
  private function finishBoard(): void
  {
    $playing = app(PlayingStateService::class)->currentPlaying($this->table->refresh());
    $playing->update(['auction_ended_at' => now()]);
    $playing->refresh()->finish(null);
  }

  private function seatOf(string $seat): ?TableSeat
  {
    return TableSeat::where('user_id', $this->players[$seat]->id)->first();
  }

  /**
   * One seat of a table payload.
   *
   * @param  array<string, mixed>  $table
   * @return array<string, mixed>
   */
  private function seatIn(array $table, string $seat): array
  {
    return collect($table['seats'])->firstWhere('seat', $seat);
  }
}
