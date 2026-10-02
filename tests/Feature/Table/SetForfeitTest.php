<?php

namespace Tests\Feature\Table;

use App\auxiliary\Seats;
use App\Events\TableUpdated;
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
 * back in time, play goes on; away for three minutes, their side forfeits
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
    $this->travel(30)->seconds();
    $this->alive('N', 'E', 'S');
    $lastSeen = $this->seatOf('W')->last_seen_at;

    // 50 seconds quiet: not yet
    $this->travel(20)->seconds();
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    $this->travel(15)->seconds();
    $this->alive('N', 'E', 'S');

    Event::fake([TableUpdated::class]);

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, 'W')['away_since'] === $lastSeen->toJSON());

    // away since their last sign of life; the forfeit three minutes after it
    $this->state('N')->assertOk();
    $seat = $this->seatIn($this->tableAs('N')->json('data'), 'W');
    $this->assertSame($lastSeen->toJSON(), $seat['away_since']);
    $this->assertSame($lastSeen->copy()->addMinutes(3)->toJSON(), $seat['forfeit_at']);
    $this->assertNull($this->seatIn($this->tableAs('N')->json('data'), 'N')['forfeit_at']);

    // the seat is held: nobody else takes it, and the board waits
    $this->assertSame(4, $this->table->seats()->count());
    $this->assertSame([], $this->table->fresh()->freeSeats());

    // back with a heartbeat before the deadline
    $this->travel(1)->minutes();
    Event::fake([TableUpdated::class]);
    $this->actingAs($this->players['W'])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();

    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, 'W')['away_since'] === null);
    $this->assertNull($this->seatOf('W')->away_since);

    // the old deadline passes with nothing lost
    $this->travel(2)->minutes();
    $this->alive('N', 'E', 'S', 'W');
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());
    $this->assertNull(TableSet::sole()->finished_at);
    $this->state('W')->assertOk()->assertJsonPath('data.phase', 'auction');
  }

  public function test_a_playing_request_brings_an_away_player_back(): void
  {
    $this->travel(2)->minutes();
    $this->alive('N', 'E', 'S');
    $this->seats->checkAway();
    $this->assertNotNull($this->seatOf('W')->away_since);

    $this->state('W')->assertOk();

    $this->assertNull($this->seatOf('W')->away_since);
  }

  public function test_still_away_at_three_minutes_their_side_forfeits_the_set(): void
  {
    $playing = BoardTable::where('table_id', $this->table->id)->sole();
    $set = TableSet::sole();

    $this->travel(70)->seconds();
    $this->alive('N', 'W', 'S');
    $this->seats->checkAway();

    // two minutes later E has been quiet 3:10
    $this->travel(2)->minutes();
    $this->alive('N', 'W', 'S');

    Event::fake([TableUpdated::class]);

    $this->artisan('tables:check-away')
      ->expectsOutput('Marked 0 players away, forfeited 1 set, freed 0 seats.')
      ->assertSuccessful();

    $set->refresh();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame('EW', $set->forfeited_by);
    $this->assertNotNull($set->finished_at);

    // the seat freed as by a leave, the board on the table abandoned
    $this->assertNull($this->seatOf('E'));
    $this->assertNull($playing->fresh()->table_id);
    $this->assertNull($this->table->fresh()->board_id);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['set']['ended'] === 'forfeit'
      && $event->table['set']['forfeited_by'] === 'EW'
      && $event->table['free_seats'] === ['E']);

    // everyone gets the set's results: the other side won it
    $this->actingAs($this->players['N'])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.ended', 'forfeit')
      ->assertJsonPath('data.forfeited_by', 'EW')
      ->assertJsonPath('data.winner', 'NS');
    $this->actingAs($this->players['E'])->getJson("/sets/$set->id")->assertOk();

    // a newcomer and everyone's Start: a new set
    $this->seats->seat($this->table, User::factory()->create(), 'E');
    $this->startBoard($this->table);
    $this->assertSame(2, $this->table->fresh()->latestSet->number);
  }

  public function test_a_player_gone_longer_than_the_deadline_forfeits_on_the_first_check(): void
  {
    $this->travel(10)->minutes();
    $this->alive('N', 'E', 'W');

    $this->assertSame(['away' => 1, 'forfeited' => 1, 'freed' => 0], $this->seats->checkAway());

    $this->assertSame('NS', TableSet::sole()->forfeited_by);
    $this->assertNull($this->seatOf('S'));
  }

  public function test_two_away_the_one_away_longest_forfeits_and_the_other_is_freed(): void
  {
    $this->travel(1)->minutes();
    $this->alive('N', 'E', 'S');
    $this->travel(1)->minutes();
    $this->alive('N', 'S');

    // W quiet for 5 minutes, E for 4
    $this->travel(3)->minutes();
    $this->alive('N', 'S');

    $this->assertSame(['away' => 2, 'forfeited' => 1, 'freed' => 1], $this->seats->checkAway());

    // the set is lost by the side of whoever went first; once it is over
    // nobody is held for it
    $this->assertSame('EW', TableSet::sole()->forfeited_by);
    $this->assertSame(['N', 'S'], $this->table->seats()->orderBy('seat')->pluck('seat')->all());
  }

  public function test_leave_mid_set_holds_the_seat_and_the_player_may_come_back(): void
  {
    $this->freezeSecond();
    Event::fake([TableUpdated::class]);

    $this->leave('S')
      ->assertStatus(202)
      ->assertJsonPath('message', 'You left in the middle of a set: your seat is held for 3 minutes. Come back to the table before then, or your side forfeits the set.')
      ->assertJsonPath('data.free_seats', []);

    $seat = $this->seatOf('S');
    $this->assertEquals(now(), $seat->away_since);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $this->seatIn($event->table, 'S')['forfeit_at'] === now()->addMinutes(3)->toJSON());

    // nobody else may sit there meanwhile
    $this->actingAs(User::factory()->create())->postJson("/tables/{$this->table->id}/seats", ['seat' => 'S'])->assertStatus(409);

    // leaving again changes nothing
    $this->travel(1)->minutes();
    $this->leave('S')->assertStatus(202);
    $this->assertEquals($seat->away_since, $this->seatOf('S')->away_since);

    // back in time
    $this->actingAs($this->players['S'])->postJson("/tables/{$this->table->id}/heartbeat")->assertOk();
    $this->assertNull($this->seatOf('S')->away_since);

    $this->travel(3)->minutes();
    $this->alive('N', 'E', 'S', 'W');
    $this->seats->checkAway();
    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_leave_mid_set_forfeits_once_the_time_is_up(): void
  {
    $this->leave('N')->assertStatus(202);

    $this->travel(2)->minutes();
    $this->alive('E', 'S', 'W');
    $this->assertSame(0, $this->seats->checkAway()['forfeited']);

    $this->travel(1)->minutes();
    $this->alive('E', 'S', 'W');
    $this->assertSame(1, $this->seats->checkAway()['forfeited']);

    $this->assertSame('NS', TableSet::sole()->forfeited_by);
    $this->assertNull($this->seatOf('N'));
    // the moderator was the one to go: the role is handed on
    $this->assertSame($this->players['E']->id, (int) $this->table->fresh()->moderated_by);
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

    // the human goes; their side, robot partner and all, loses the set
    $seat = $table->seats()->where('user_id', $human->id)->value('seat');
    $this->travel(4)->minutes();
    $this->seats->checkAway();

    $set = $table->sets()->sole();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame(Seats::side($seat), $set->forfeited_by);
    $this->assertSame(3, $table->seats()->count());
    $this->assertNotNull($table->fresh()->unattended_since);
  }

  public function test_an_admin_away_never_forfeits_and_the_others_may_leave_without_penalty(): void
  {
    $this->players['E']->forceFill(['is_admin' => true])->save();

    $this->travel(10)->minutes();
    $this->alive('N', 'S', 'W');

    $this->assertSame(['away' => 1, 'forfeited' => 0, 'freed' => 0], $this->seats->checkAway());

    // away, but no deadline: the table waits for them
    $this->assertNotNull($this->seatOf('E')->away_since);
    $this->assertNull($this->seatIn($this->tableAs('N')->json('data'), 'E')['forfeit_at']);

    // with an admin away nobody else's absence costs anything either
    $this->travel(5)->minutes();
    $this->alive('N', 'S');
    $this->seats->checkAway();
    $this->assertNotNull($this->seatOf('W')->away_since);
    $this->assertNull($this->seatIn($this->tableAs('N')->json('data'), 'W')['forfeit_at']);
    $this->assertNull(TableSet::sole()->finished_at);

    // and Leave is immediate: the set is abandoned, nobody forfeits it
    $this->leave('S')->assertOk()->assertJsonPath('message', 'You left the table.');
    $this->assertSame(['ended' => 'abandoned', 'forfeited_by' => null], TableSet::sole()->only('ended', 'forfeited_by'));

    // with the set over, W's held seat goes; the admin's stays, no longer away
    $this->alive('N');
    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 1], $this->seats->checkAway());
    $this->assertNull($this->seatOf('W'));
    $this->assertNull($this->seatOf('E')->away_since);
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
