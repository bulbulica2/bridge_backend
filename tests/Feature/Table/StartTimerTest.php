<?php

namespace Tests\Feature\Table;

use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Events\UnseatedFromTable;
use App\Jobs\ExpireStart;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Services\BoardSelectionService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Start timer: once a full table outside a set waits for one human's
 * Start only, and another human has pressed it, that human has
 * `bridge.start_seconds` (`table_seats.start_deadline`) before the queued
 * `ExpireStart` frees their seat. And changing the set time revokes every
 * Start.
 */
class StartTimerTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  private BoardSelectionService $boards;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    config(['bridge.start_seconds' => 15]);

    // hold the robots back: nothing here is about their play
    Event::fake([PlayingUpdated::class, HandDealt::class]);

    $this->seats = app(TableSeatService::class);
    $this->boards = app(BoardSelectionService::class);

    $this->freezeSecond();
  }

  public function test_two_humans_and_two_robots_one_ready_times_the_other(): void
  {
    Queue::fake([ExpireStart::class]);
    Event::fake([PlayingUpdated::class, HandDealt::class, TableUpdated::class]);

    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'robot', 'S' => 'human', 'W' => 'robot']);

    $this->assertNull($this->deadline($table, 'S'));
    Queue::assertNothingPushed();

    $seats = collect($this->actingAs($players['N'])->postJson("/tables/$table->id/start")
      ->assertOk()
      ->assertJsonPath('message', 'Ready: waiting for the other players.')
      ->json('data.seats'))->keyBy('seat');

    $deadline = now()->addSeconds(15);
    $this->assertSame($deadline->toJSON(), $seats['S']['start_deadline']);
    $this->assertNull($seats['N']['start_deadline']);
    $this->assertNull($seats['E']['start_deadline']);

    Event::assertDispatched(TableUpdated::class, fn ($event) => data_get(collect($event->table['seats'])->firstWhere('seat', 'S'), 'start_deadline') === $deadline->toJSON());
    Queue::assertPushed(ExpireStart::class, fn ($job) => $job->seatId === $this->seatOf($table, 'S')->id && $job->delay->eq($deadline));

    // every table payload shows it
    $this->actingAs($players['S'])->getJson("/tables/$table->id")
      ->assertJsonPath('data.seats.2.start_deadline', $deadline->toJSON());

    // pressed in time: the board is dealt, and the timer is gone
    $this->travel(14)->seconds();
    $this->actingAs($players['S'])->postJson("/tables/$table->id/start")
      ->assertOk()
      ->assertJsonPath('message', 'Board dealt.');

    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());
  }

  public function test_not_pressing_in_time_frees_the_seat(): void
  {
    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'robot', 'S' => 'human', 'W' => 'robot']);

    $this->boards->start($table, $players['N']);
    $seat = $this->seatOf($table, 'S');

    Event::fake([PlayingUpdated::class, HandDealt::class, TableUpdated::class, UnseatedFromTable::class]);

    // not yet
    $this->travel(14)->seconds();
    (new ExpireStart($seat->id))->handle($this->seats);
    $this->assertTrue($table->seats()->whereKey($seat->id)->exists());

    $this->travel(1)->seconds();
    (new ExpireStart($seat->id))->handle($this->seats);

    $this->assertFalse($table->seats()->where('seat', 'S')->exists());
    $this->assertNull($table->fresh()->board_id);

    // the table allows kibitzers (by default): S stays as one
    Event::assertDispatched(UnseatedFromTable::class, function (UnseatedFromTable $event) use ($players, $table) {
      return $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$players['S']->id
        && $event->broadcastWith() === ['table_id' => $table->id, 'reason' => 'start_timeout', 'kibitzing' => true];
    });
    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['free_seats'] === ['S'] && $event->table['kibitzers'] === 1);
    $this->assertSame($table->id, $players['S']->kibitzing()->value('table_id'));
    $this->actingAs($players['S'])->getJson("/tables/$table->id/playing")->assertOk()->assertJsonPath('data.my_seat', null);

    // N's Start stands, and nothing is held against S: they may sit again
    // (no longer watching), and the timer runs afresh for them
    $this->assertNotNull($this->seatOf($table, 'N')->ready_at);
    $this->seats->seat($table, $players['S'], 'S');
    $this->assertEquals(now()->addSeconds(15), $this->deadline($table, 'S'));
    $this->assertNull($players['S']->kibitzing()->first());

    // a seat that is gone frees nothing
    $this->assertFalse($this->seats->expireStart($seat->id));
  }

  public function test_at_a_table_without_kibitzers_the_late_player_is_just_unseated(): void
  {
    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'robot', 'S' => 'human', 'W' => 'robot']);
    $table->update(['allow_kibitzers' => false]);

    $this->boards->start($table, $players['N']);
    $seat = $this->seatOf($table, 'S');

    Event::fake([UnseatedFromTable::class]);

    $this->travel(15)->seconds();
    (new ExpireStart($seat->id))->handle($this->seats);

    $this->assertFalse($table->seats()->where('seat', 'S')->exists());
    $this->assertSame(0, $table->kibitzers()->count());
    Event::assertDispatched(UnseatedFromTable::class, fn (UnseatedFromTable $event) => $event->userId === $players['S']->id
      && $event->broadcastWith() === ['table_id' => $table->id, 'reason' => 'start_timeout', 'kibitzing' => false]);
  }

  public function test_four_humans_the_fourth_is_timed_once_three_are_ready(): void
  {
    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'human', 'S' => 'human', 'W' => 'human']);

    $this->boards->start($table, $players['N']);
    $this->boards->start($table, $players['E']);
    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());

    $this->boards->start($table, $players['S']);
    $this->assertEquals(now()->addSeconds(15), $this->deadline($table, 'W'));
    $first = $this->seatOf($table, 'W');

    // somebody else's Start doesn't restart it
    $this->travel(5)->seconds();
    $this->boards->start($table, $players['S']);
    $this->assertEquals($first->start_deadline, $this->deadline($table, 'W'));

    // one of the three takes theirs back: the timer stops
    $this->boards->withdrawStart($table, $players['E']);
    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());

    // a job queued for the stopped timer frees nothing when it comes
    $this->travel(15)->seconds();
    $this->assertFalse($this->seats->expireStart($first->id));
    $this->assertTrue($table->seats()->where('user_id', $players['W']->id)->exists());

    // pressed again: W waits on a fresh 15 s; E is not timed though they
    // pressed last
    $this->boards->start($table, $players['E']);
    $this->assertEquals(now()->addSeconds(15), $this->deadline($table, 'W'));
    $this->assertFalse($this->seats->expireStart($first->id));

    // the last Start deals
    $this->assertNotNull($this->boards->start($table, $players['W']));
    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());
  }

  public function test_one_human_with_three_robots_is_never_timed(): void
  {
    $owner = User::factory()->create();

    $id = $this->actingAs($owner)->postJson('/tables', ['seat' => 'S', 'robots' => true])
      ->assertCreated()
      ->assertJsonPath('data.seats.2.start_deadline', null)
      ->json('data.id');

    $this->assertSame(0, TableSeat::where('table_id', $id)->whereNotNull('start_deadline')->count());
  }

  public function test_the_timer_waits_for_a_full_table_and_stops_when_it_is_not(): void
  {
    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'human', 'S' => 'human']);

    foreach (['N', 'E', 'S'] as $seat) {
      $this->boards->start($table, $players[$seat]);
    }

    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());

    // the fourth to sit down is the one everybody waits for
    $newcomer = User::factory()->create();
    $this->seats->seat($table, $newcomer, 'W');
    $this->assertEquals(now()->addSeconds(15), $this->deadline($table, 'W'));

    // the one being waited for leaves: nobody is timed
    $this->seats->remove($table, $newcomer);
    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());

    // a manager seats someone: timed; then kicks a ready player: not
    $this->seats->seat($table, $newcomer, 'W', User::findOrFail($table->moderated_by));
    $this->assertNotNull($this->deadline($table, 'W'));
    $this->actingAs($players['N'])->deleteJson("/tables/$table->id/seats/{$players['E']->id}")->assertOk();
    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());

    // E sits back down and is now the one waited for, until they move to
    // another table
    $this->seats->seat($table, $players['E'], 'E');
    $this->boards->start($table, $newcomer);
    $this->assertNotNull($this->deadline($table, 'E'));
    $other = Table::factory()->create(['board_id' => null]);
    $this->seats->seat($other, $players['E'], 'N');
    $this->assertSame(0, $table->seats()->whereNotNull('start_deadline')->count());
    $this->assertSame(0, $other->seats()->whereNotNull('start_deadline')->count());
  }

  public function test_a_robot_filling_the_table_times_the_one_human_not_ready(): void
  {
    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'human', 'S' => 'robot']);

    $this->boards->start($table, $players['N']);
    app(RobotService::class)->seatRobot($table, 'W', $players['N']);

    $this->assertEquals(now()->addSeconds(15), $this->deadline($table, 'E'));
  }

  public function test_a_moderator_and_an_admin_who_do_not_press_start_lose_the_seat(): void
  {
    $moderator = User::factory()->create();
    $admin = User::factory()->isAdmin()->create();
    $table = Table::factory()->create(['board_id' => null, 'created_by' => $moderator->id, 'moderated_by' => $moderator->id]);
    $this->seats->seat($table, $moderator, 'N');
    $this->seats->seat($table, $admin, 'E');
    $first = User::factory()->create();
    $this->seats->seat($table, $first, 'S');
    $this->seats->seat($table, User::factory()->create(), 'W');

    $this->boards->start($table, $first);
    $this->boards->start($table, $admin);
    $this->boards->start($table, User::query()->latest('id')->first());
    $seat = $this->seatOf($table, 'N');

    $this->travel(15)->seconds();
    $this->assertTrue($this->seats->expireStart($seat->id));

    // the human seated longest runs the table now: the admin
    $this->assertSame($admin->id, (int) $table->fresh()->moderated_by);

    // an admin is timed like anyone
    $this->boards->withdrawStart($table, $admin);
    $this->seats->seat($table, $moderator, 'N');
    $this->boards->start($table, $moderator);
    $this->assertEquals(now()->addSeconds(15), $this->deadline($table, 'E'));

    $this->travel(15)->seconds();
    $this->assertTrue($this->seats->expireStart($this->seatOf($table, 'E')->id));

    $this->assertFalse($table->seats()->where('user_id', $admin->id)->exists());
    $this->assertTrue($admin->fresh()->is_admin);
    $this->assertSame($first->id, (int) $table->fresh()->moderated_by);
  }

  public function test_changing_the_set_time_revokes_every_start(): void
  {
    [$table, $players] = $this->tableWith(['N' => 'human', 'E' => 'robot', 'S' => 'human', 'W' => 'human']);
    $url = "/tables/$table->id";

    $this->boards->start($table, $players['N']);
    $this->boards->start($table, $players['S']);
    $this->assertNotNull($this->deadline($table, 'W'));

    Event::fake([PlayingUpdated::class, HandDealt::class, TableUpdated::class]);

    // the same value changes nothing
    $this->actingAs($players['N'])->patchJson($url, ['set_minutes' => $table->set_minutes])->assertOk();
    Event::assertNotDispatched(TableUpdated::class);
    $this->assertSame(3, $table->seats()->whereNotNull('ready_at')->count());
    $this->assertNotNull($this->deadline($table, 'W'));

    // a new one revokes every human's Start and the timer; robots stay ready
    $seats = collect($this->actingAs($players['N'])->patchJson($url, ['set_minutes' => 8])
      ->assertOk()
      ->assertJsonPath('data.set_minutes', 8)
      ->json('data.seats'))->keyBy('seat');

    foreach (['N', 'S', 'W'] as $seat) {
      $this->assertFalse($seats[$seat]['ready']);
      $this->assertNull($seats[$seat]['start_deadline']);
    }

    $this->assertTrue($seats['E']['ready']);

    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatched(TableUpdated::class, fn ($event) => collect($event->table['seats'])->every(
      fn ($seat) => $seat['start_deadline'] === null && $seat['ready'] === ($seat['seat'] === 'E')
    ));

    $this->travel(15)->seconds();
    $this->assertFalse($this->seats->expireStart($this->seatOf($table, 'W')->id));
  }

  /**
   * A table its first human created, with fresh humans and pool robots in
   * the seats asked for.
   *
   * @param  array<string, 'human'|'robot'>  $seats
   * @return array{0: Table, 1: array<string, User>}
   */
  private function tableWith(array $seats): array
  {
    $table = null;
    $players = [];

    foreach ($seats as $seat => $kind) {
      if ($kind === 'robot') {
        $players[$seat] = app(RobotService::class)->seatRobot($table, $seat, reset($players))->user;

        continue;
      }

      $players[$seat] = User::factory()->create();
      $table ??= Table::factory()->create(['board_id' => null, 'created_by' => $players[$seat]->id, 'moderated_by' => $players[$seat]->id]);
      $this->seats->seat($table, $players[$seat], $seat);
    }

    return [$table, $players];
  }

  private function seatOf(Table $table, string $seat): TableSeat
  {
    return $table->seats()->where('seat', $seat)->firstOrFail();
  }

  private function deadline(Table $table, string $seat): mixed
  {
    return $table->seats()->where('seat', $seat)->first()?->start_deadline;
  }
}
