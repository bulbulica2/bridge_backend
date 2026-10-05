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
use App\Services\BoardSelectionService;
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
 * back in time, play goes on. The board waits for one player at a time, so
 * only the away player on turn has a forfeit clock, three minutes from when
 * the board began waiting for them; when it runs out their side forfeits
 * the set. Leave mid-set is going away, moving to another table forfeits at
 * once, robots are never away and an admin never costs their side the set.
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

    config(['bridge.away_seconds' => 60, 'bridge.set_forfeit_minutes' => 3, 'bridge.idle_seat_minutes' => 5]);

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
    $this->freezeSecond();
    $turn = $this->turn();
    $others = $this->others($turn);

    $this->travel(30)->seconds();
    $this->alive(...$others);
    $lastSeen = $this->seatOf($turn)->last_seen_at;

    // 50 seconds quiet: not yet
    $this->travel(20)->seconds();
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(15)->seconds();
    $this->alive(...$others);

    Event::fake([TableUpdated::class]);

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $turn)['away_since'] === $lastSeen->toJSON());

    // away since their last sign of life; the board waits for them, so
    // their clock runs: three whole minutes from now
    $this->state($others[0])->assertOk();
    $seat = $this->seatIn($this->tableAs($others[0])->json('data'), $turn);
    $this->assertSame($lastSeen->toJSON(), $seat['away_since']);
    $this->assertSame(now()->addMinutes(3)->toJSON(), $seat['forfeit_at']);
    $this->assertNull($this->seatIn($this->tableAs($others[0])->json('data'), $others[0])['forfeit_at']);

    // the seat is held: nobody else takes it, and the board waits
    $this->assertSame(4, $this->table->seats()->count());
    $this->assertSame([], $this->table->fresh()->freeSeats());

    // back with a heartbeat before the deadline
    $this->travel(1)->minutes();
    Event::fake([TableUpdated::class]);
    $this->actingAs($this->players[$turn])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();

    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $turn)['away_since'] === null
      && $this->seatIn($event->table, $turn)['forfeit_at'] === null);
    $this->assertNull($this->seatOf($turn)->away_since);
    $this->assertNull($this->seatOf($turn)->forfeit_at);

    // the old deadline passes with nothing lost
    $this->travel(2)->minutes();
    $this->alive(...Seats::SEATS);
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull(TableSet::sole()->finished_at);
    $this->state($turn)->assertOk()->assertJsonPath('data.phase', 'auction');
  }

  public function test_a_playing_request_brings_an_away_player_back(): void
  {
    $turn = $this->turn();

    $this->travel(2)->minutes();
    $this->alive(...$this->others($turn));
    $this->seats->checkAway();
    $this->assertNotNull($this->seatOf($turn)->away_since);
    $this->assertNotNull($this->seatOf($turn)->forfeit_at);

    $this->state($turn)->assertOk();

    $this->assertNull($this->seatOf($turn)->away_since);
    $this->assertNull($this->seatOf($turn)->forfeit_at);
  }

  public function test_still_away_when_the_clock_runs_out_their_side_forfeits_the_set(): void
  {
    $playing = BoardTable::where('table_id', $this->table->id)->sole();
    $set = TableSet::sole();
    $turn = $this->turn();
    $others = $this->others($turn);

    $this->travel(70)->seconds();
    $this->alive(...$others);
    $this->seats->checkAway();

    // the clock started at the check: 2:59 later it hasn't run out
    $this->travel(179)->seconds();
    $this->alive(...$others);
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);

    $this->travel(1)->seconds();
    $this->alive(...$others);

    Event::fake([TableUpdated::class]);

    $this->artisan('tables:check-away')
      ->expectsOutput('Marked 0 players away, forfeited 1 set, freed 0 seats.')
      ->assertSuccessful();

    $side = Seats::side($turn);
    $winners = $side === 'NS' ? 'EW' : 'NS';

    $set->refresh();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame($side, $set->forfeited_by);
    $this->assertNotNull($set->finished_at);

    // the seat freed as by a leave, the board on the table abandoned
    $this->assertNull($this->seatOf($turn));
    $this->assertNull($playing->fresh()->table_id);
    $this->assertNull($this->table->fresh()->board_id);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['set']['ended'] === 'forfeit'
      && $event->table['set']['forfeited_by'] === $side
      && $event->table['free_seats'] === [$turn]);

    // everyone gets the set's results: the other side won it
    $this->actingAs($this->players[$others[0]])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.ended', 'forfeit')
      ->assertJsonPath('data.forfeited_by', $side)
      ->assertJsonPath('data.winner', $winners);
    $this->actingAs($this->players[$turn])->getJson("/sets/$set->id")->assertOk();

    // a newcomer and everyone's Start: a new set
    $this->seats->seat($this->table, User::factory()->create(), $turn);
    $this->startBoard($this->table);
    $this->assertSame(2, $this->table->fresh()->latestSet->number);
  }

  public function test_the_clock_starts_when_the_board_waits_for_them_not_when_they_went_quiet(): void
  {
    $this->freezeSecond();
    $turn = $this->turn();

    // gone for ten minutes before anyone checked: three minutes left all the same
    $this->travel(10)->minutes();
    $this->alive(...$this->others($turn));

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertEquals(now()->addMinutes(3), $this->seatOf($turn)->forfeit_at);
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_with_two_away_only_the_one_on_turn_has_a_clock_and_it_moves_with_the_turn(): void
  {
    $this->freezeSecond();
    $turn = $this->turn();
    $next = Seats::next($turn);

    $this->travel(2)->minutes();
    $this->alive(...$this->others($turn, $next));
    $this->assertSame(['away' => 2, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // both away; the board waits for one of them only
    $table = $this->tableAs($this->others($turn, $next)[0])->json('data');
    $this->assertSame(now()->addMinutes(3)->toJSON(), $this->seatIn($table, $turn)['forfeit_at']);
    $this->assertNotNull($this->seatIn($table, $next)['away_since']);
    $this->assertNull($this->seatIn($table, $next)['forfeit_at']);

    // the first comes back and calls: now the board waits for the second,
    // whose three minutes start now, and the table hears of it at once
    $this->travel(1)->minutes();
    Event::fake([TableUpdated::class]);
    $this->pass($turn);

    $this->assertNull($this->seatOf($turn)->forfeit_at);
    $this->assertEquals(now()->addMinutes(3), $this->seatOf($next)->forfeit_at);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $next)['forfeit_at'] === now()->addMinutes(3)->toJSON());

    // two minutes on, the first goes quiet again: they aren't on turn, so
    // they cost nothing however long they stay away
    $this->travel(2)->minutes();
    $this->alive(...$this->others($turn, $next));
    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull($this->seatOf($turn)->forfeit_at);

    // only the one on turn forfeits when their clock runs out; the other,
    // once the set is over, is freed
    $this->travel(1)->minutes();
    $this->alive(...$this->others($turn, $next));
    $this->assertSame(['away' => 0, 'forfeited' => 1, 'freed' => 1], $this->seats->checkAway());
    $this->assertSame(Seats::side($next), TableSet::sole()->forfeited_by);
  }

  public function test_an_away_player_not_on_turn_never_forfeits(): void
  {
    $quiet = Seats::next($this->turn());

    $this->travel(10)->minutes();
    $this->alive(...$this->others($quiet));

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull($this->seatOf($quiet)->forfeit_at);

    $this->travel(10)->minutes();
    $this->alive(...$this->others($quiet));
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_on_dummys_turn_the_clock_runs_for_declarer(): void
  {
    $this->freezeSecond();
    $declarer = $this->turn();
    $dummy = Seats::partner($declarer);
    $leader = Seats::next($declarer);

    $this->bid($declarer, '1NT');
    $this->pass($leader);
    $this->pass($dummy);
    $this->pass(Seats::partner($leader));
    $this->lead($leader);

    // dummy's turn: declarer plays dummy's card
    $this->assertSame($dummy, $this->turn());

    $this->travel(2)->minutes();
    $this->alive($leader, Seats::partner($leader));
    $this->assertSame(['away' => 2, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->assertEquals(now()->addMinutes(3), $this->seatOf($declarer)->forfeit_at);
    $this->assertNull($this->seatOf($dummy)->forfeit_at);

    $this->travel(3)->minutes();
    $this->alive($leader, Seats::partner($leader));
    $this->assertSame(1, $this->seats->checkAway()['forfeited']);
    $this->assertSame(Seats::side($declarer), TableSet::sole()->forfeited_by);
  }

  public function test_no_clock_runs_while_a_claim_is_pending(): void
  {
    $this->freezeSecond();
    $declarer = $this->turn();
    $leader = Seats::next($declarer);

    $this->bid($declarer, '1NT');
    $this->pass($leader);
    $this->pass(Seats::partner($declarer));
    $this->pass(Seats::partner($leader));
    $this->lead($leader);

    $this->travel(2)->minutes();
    $this->alive(...$this->others($declarer));
    $this->seats->checkAway();
    $this->assertNotNull($this->seatOf($declarer)->forfeit_at);

    // a defender claims: nobody is on turn until it is settled
    $this->travel(30)->seconds();
    Event::fake([TableUpdated::class]);
    $this->actingAs($this->players[$leader])->postJson("/tables/{$this->table->id}/claim", ['tricks' => 1])->assertCreated();

    $this->assertNull($this->seatOf($declarer)->forfeit_at);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $declarer)['forfeit_at'] === null);

    $this->travel(5)->minutes();
    $this->alive(...$this->others($declarer));
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull($this->seatOf($declarer)->forfeit_at);

    // withdrawn: the board waits for declarer again, three whole minutes
    $this->actingAs($this->players[$leader])->deleteJson("/tables/{$this->table->id}/claim")->assertOk();
    $this->assertEquals(now()->addMinutes(3), $this->seatOf($declarer)->forfeit_at);
  }

  public function test_no_clock_runs_between_boards_and_the_next_board_starts_one(): void
  {
    $this->freezeSecond();
    $playing = BoardTable::where('table_id', $this->table->id)->sole();

    // passed out: the board is over, the next one comes by itself
    foreach (range(1, 4) as $call) {
      $this->pass($this->turn());
    }

    $this->travel(5)->seconds();
    $this->leave('E')->assertStatus(202);
    $this->assertNull($this->seatOf('E')->forfeit_at);

    $this->travel(5)->seconds();
    $this->alive('N', 'S', 'W');
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull($this->seatOf('E')->forfeit_at);

    // the next board: once it waits for E, E's clock starts
    $this->assertNotNull(app(BoardSelectionService::class)->dealNext($playing->id));

    while ($this->turn() !== 'E') {
      $this->assertNull($this->seatOf('E')->forfeit_at);
      $this->pass($this->turn());
    }

    $this->assertEquals(now()->addMinutes(3), $this->seatOf('E')->forfeit_at);
  }

  public function test_leave_mid_set_holds_the_seat_and_the_player_may_come_back(): void
  {
    $this->freezeSecond();
    $turn = $this->turn();
    $other = Seats::next($turn);
    Event::fake([TableUpdated::class]);

    $this->leave($turn)
      ->assertStatus(202)
      ->assertJsonPath('message', 'You left in the middle of a set: your seat is held. Once the table is waiting for you, come back within 3 minutes, or your side forfeits the set.')
      ->assertJsonPath('data.free_seats', []);

    // on turn: their clock runs from the moment they leave
    $seat = $this->seatOf($turn);
    $this->assertEquals(now(), $seat->away_since);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $turn)['forfeit_at'] === now()->addMinutes(3)->toJSON());

    // somebody else leaving is away too, but the board isn't waiting for them
    $this->leave($other)->assertStatus(202);
    $this->assertNotNull($this->seatOf($other)->away_since);
    $this->assertNull($this->seatOf($other)->forfeit_at);

    // nobody else may sit there meanwhile
    $this->actingAs(User::factory()->create())->postJson("/tables/{$this->table->id}/seats", ['seat' => $turn])->assertStatus(409);

    // leaving again changes nothing
    $this->travel(1)->minutes();
    $this->leave($turn)->assertStatus(202);
    $this->assertEquals($seat->away_since, $this->seatOf($turn)->away_since);
    $this->assertEquals($seat->forfeit_at, $this->seatOf($turn)->forfeit_at);

    // back in time
    $this->actingAs($this->players[$turn])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();
    $this->assertNull($this->seatOf($turn)->away_since);
    $this->assertNull($this->seatOf($turn)->forfeit_at);

    $this->travel(3)->minutes();
    $this->alive(...Seats::SEATS);
    $this->seats->checkAway();
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_leave_mid_set_forfeits_once_the_time_is_up(): void
  {
    $turn = $this->turn();
    $others = $this->others($turn);
    $this->table->update(['moderated_by' => $this->players[$turn]->id]);

    $this->leave($turn)->assertStatus(202);

    $this->travel(2)->minutes();
    $this->alive(...$others);
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);

    $this->travel(1)->minutes();
    $this->alive(...$others);
    $this->assertSame(1, $this->seats->checkAway()['forfeited']);

    $this->assertSame(Seats::side($turn), TableSet::sole()->forfeited_by);
    $this->assertNull($this->seatOf($turn));
    // the moderator was the one to go: the role is handed on, to the
    // human seated here longest
    $this->assertSame($this->players[$others[0]]->id, (int) $this->table->fresh()->moderated_by);
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
      ->assertJsonPath('data.set.forfeited_by', 'EW');

    // a new set, and a kick of somebody who is there
    $this->seats->seat($this->table, $this->players['E'], 'E');
    $this->startBoard($this->table);

    $this->actingAs($this->players['N'])->deleteJson("/tables/{$this->table->id}/seats/{$this->players['S']->id}")
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table.')
      ->assertJsonPath('data.set.ended', 'abandoned')
      ->assertJsonPath('data.set.forfeited_by', null);
  }

  public function test_robots_are_never_away_and_a_robots_partner_forfeits_for_their_side(): void
  {
    $human = User::factory()->create();
    $id = $this->actingAs($human)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');
    $table = Table::findOrFail($id);
    $this->startBoard($table);

    // robots send no heartbeat: they are never away
    $this->travel(2)->minutes();
    $this->seats->touch($table, $human);
    $this->seats->checkAway();
    $this->assertSame(0, $table->seats()->whereNotNull('away_since')->count());

    // the human goes, the robots having called up to them: their side,
    // robot partner and all, loses the set when their clock runs out
    $seat = $table->seats()->where('user_id', $human->id)->value('seat');
    $this->travel(4)->minutes();
    $this->seats->checkAway();
    $this->assertNotNull($table->seats()->where('user_id', $human->id)->value('forfeit_at'));
    $this->travel(3)->minutes();
    $this->seats->checkAway();

    $set = $table->sets()->sole();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame(Seats::side($seat), $set->forfeited_by);
    $this->assertSame(3, $table->seats()->count());
    $this->assertNotNull($table->fresh()->unattended_since);
  }

  public function test_an_admin_on_turn_away_has_no_clock(): void
  {
    $turn = $this->turn();
    $this->players[$turn]->forceFill(['is_admin' => true])->save();

    $this->travel(10)->minutes();
    $this->alive(...$this->others($turn));

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // away, but no deadline: the table waits for them
    $this->assertNotNull($this->seatOf($turn)->away_since);
    $this->assertNull($this->seatIn($this->tableAs($this->others($turn)[0])->json('data'), $turn)['forfeit_at']);

    $this->travel(10)->minutes();
    $this->alive(...$this->others($turn));
    $this->seats->checkAway();
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_an_admin_away_never_forfeits_and_the_others_may_leave_without_penalty(): void
  {
    $this->freezeSecond();
    $turn = $this->turn();
    $admin = Seats::next($turn);
    $this->players[$admin]->forceFill(['is_admin' => true])->save();

    // the admin goes, then the player on turn: no clock for anyone
    $this->travel(10)->minutes();
    $this->alive(...$this->others($admin));
    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(5)->minutes();
    $this->alive(...$this->others($admin, $turn));
    $this->seats->checkAway();
    $this->assertNotNull($this->seatOf($turn)->away_since);
    $this->assertNull($this->seatIn($this->tableAs($this->others($admin, $turn)[0])->json('data'), $turn)['forfeit_at']);
    $this->assertNull(TableSet::sole()->finished_at);

    // the admin back: the board waits on the player on turn, whose three
    // minutes start now
    $this->travel(1)->minutes();
    Event::fake([TableUpdated::class]);
    $this->alive($admin);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, $turn)['forfeit_at'] === now()->addMinutes(3)->toJSON());

    // the admin away again: the clock stops
    $this->travel(2)->minutes();
    $this->alive(...$this->others($admin, $turn));
    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull($this->seatOf($turn)->forfeit_at);

    // and Leave is immediate: the set is abandoned, nobody forfeits it
    $leaver = $this->others($admin, $turn)[0];
    $this->leave($leaver)->assertOk()->assertJsonPath('message', 'You left the table.');
    $this->assertSame(['ended' => 'abandoned', 'forfeited_by' => null], TableSet::sole()->only('ended', 'forfeited_by'));

    // with the set over, the held seat goes; the admin's stays, no longer away
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 1], $this->seats->checkAway());
    $this->assertNull($this->seatOf($turn));
    $this->assertNull($this->seatOf($admin)->away_since);
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

  private function bid(string $seat, string $call): void
  {
    $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', $call)->value('id')])
      ->assertCreated();
  }

  private function pass(string $seat): void
  {
    $this->bid($seat, 'P');
  }

  /**
   * `$seat` leads the first card of their hand.
   */
  private function lead(string $seat): void
  {
    $card = $this->state($seat)->json('data.hand.0.id');

    $this->actingAs($this->players[$seat])
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
