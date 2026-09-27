<?php

namespace Tests\Feature\Table;

use App\auxiliary\Seats;
use App\Events\TableUpdated;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IdleSeatsTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  protected function setUp(): void
  {
    parent::setUp();

    config(['bridge.idle_seat_minutes' => 5, 'bridge.idle_playing_seat_minutes' => 15]);

    $this->seats = app(TableSeatService::class);
  }

  public function test_a_heartbeat_updates_last_seen_at(): void
  {
    [$table, $players] = $this->lobbyTable(['N', 'E']);

    $this->travel(3)->minutes();

    $this->actingAs($players['N'])->postJson("/tables/$table->id/heartbeat")
      ->assertOk()
      ->assertJsonPath('message', 'Heartbeat received.')
      ->assertJsonPath('data.last_seen_at', now()->startOfSecond()->toJSON());

    $this->assertEquals(now()->toDateTimeString(), $this->seatOf($players['N'])->last_seen_at->toDateTimeString());
    // only the caller's seat
    $this->assertTrue($this->seatOf($players['E'])->last_seen_at->lt(now()->subMinutes(2)));
  }

  public function test_a_heartbeat_from_somebody_not_seated_there_is_refused(): void
  {
    [$table] = $this->lobbyTable(['N']);
    $elsewhere = Table::factory()->create(['board_id' => null]);
    $other = User::factory()->create();
    $this->seats->seat($elsewhere, $other, 'N');

    $this->postJson("/tables/$table->id/heartbeat")->assertUnauthorized();

    $this->actingAs(User::factory()->create())->postJson("/tables/$table->id/heartbeat")->assertForbidden();

    $this->travel(3)->minutes();

    // seated at another table: refused, and their own seat isn't touched
    $this->actingAs($other)->postJson("/tables/$table->id/heartbeat")->assertForbidden();
    $this->assertTrue($this->seatOf($other)->last_seen_at->lt(now()->subMinutes(2)));
  }

  public function test_playing_requests_count_as_a_heartbeat(): void
  {
    [$table, $players] = $this->fullTable();

    $this->travel(3)->minutes();

    $this->actingAs($players['S'])->getJson("/tables/$table->id/playing")->assertOk();

    $this->assertEquals(now()->toDateTimeString(), $this->seatOf($players['S'])->last_seen_at->toDateTimeString());
    $this->assertTrue($this->seatOf($players['N'])->last_seen_at->lt(now()->subMinutes(2)));

    // a refused request still counts: the player is plainly there
    $this->travel(1)->minutes();
    $this->actingAs($players['N'])->postJson("/tables/$table->id/calls", ['bid_id' => 99999])->assertUnprocessable();
    $this->assertEquals(now()->toDateTimeString(), $this->seatOf($players['N'])->last_seen_at->toDateTimeString());
  }

  public function test_the_sweeper_frees_only_stale_seats_and_hands_moderation_on(): void
  {
    [$table, $players] = $this->lobbyTable(['N', 'E', 'S']);
    $table->update(['created_by' => $players['N']->id, 'moderated_by' => $players['N']->id]);

    $this->travel(4)->minutes();
    $this->actingAs($players['S'])->postJson("/tables/$table->id/heartbeat")->assertOk();
    $this->travel(2)->minutes();

    Event::fake([TableUpdated::class]);

    $this->artisan('tables:release-idle-seats')
      ->expectsOutput('Freed 2 idle seats.')
      ->assertSuccessful();

    // N and E went quiet 6 minutes ago, S sent a heartbeat 2 minutes ago
    $this->assertSame([$players['S']->id], $table->seats()->pluck('user_id')->all());
    $this->assertSame($players['S']->id, (int) $table->fresh()->moderated_by);
    Event::assertDispatched(TableUpdated::class);
  }

  public function test_the_sweeper_leaves_active_players_alone(): void
  {
    [$table] = $this->lobbyTable(['N', 'E']);

    $this->travel(4)->minutes();

    $this->artisan('tables:release-idle-seats')->expectsOutput('Freed 0 idle seats.');

    $this->assertSame(2, $table->seats()->count());
  }

  public function test_the_sweeper_deletes_a_table_it_empties(): void
  {
    [$table] = $this->lobbyTable(['N', 'E']);

    $this->travel(6)->minutes();

    $this->assertSame(2, $this->seats->releaseIdleSeats());
    $this->assertModelMissing($table);
  }

  public function test_a_board_in_progress_gets_the_longer_timeout_then_is_abandoned(): void
  {
    [$table, $players] = $this->fullTable();
    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();

    // everybody else keeps playing; W has gone
    foreach (['N' => 5, 'E' => 5, 'S' => 4] as $seat => $minutes) {
      $this->travel($minutes)->minutes();
      $this->actingAs($players[$seat])->getJson("/tables/$table->id/playing")->assertOk();
    }

    // W has been quiet 14 minutes: past the lobby timeout, not the playing one
    $this->assertSame(0, $this->seats->releaseIdleSeats());
    $this->assertSame(4, $table->seats()->count());

    $this->travel(2)->minutes();

    $this->assertSame(1, $this->seats->releaseIdleSeats());
    $this->assertNull($this->seatOf($players['W']));
    // left through remove(): the unfinished playing is detached, not deleted
    $this->assertNull($playing->fresh()->table_id);
    $this->assertNull($table->fresh()->board_id);
  }

  public function test_a_finished_board_gets_the_lobby_timeout(): void
  {
    [$table] = $this->fullTable();
    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();
    $playing->update(['auction_ended_at' => now()]);
    $playing->finish(null);

    $this->travel(6)->minutes();

    $this->assertSame(4, $this->seats->releaseIdleSeats());
    $this->assertModelMissing($table);
    // the finished result is kept
    $this->assertNotNull($playing->fresh()->finished_at);
  }

  public function test_the_sweeper_is_scheduled(): void
  {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    $this->assertStringContainsString('tables:release-idle-seats', $commands);
  }

  /**
   * A table with no board yet and a player in each of `$seats`.
   *
   * @param  list<string>  $seats
   * @return array{Table, array<string, User>}
   */
  private function lobbyTable(array $seats): array
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = [];

    foreach ($seats as $seat) {
      $players[$seat] = User::factory()->create();
      $this->seats->seat($table, $players[$seat], $seat);
    }

    return [$table, $players];
  }

  /**
   * A full table, so a board has been dealt and its auction is open.
   *
   * @return array{Table, array<string, User>}
   */
  private function fullTable(): array
  {
    $this->seed([CardSeeder::class, BidSeeder::class]);

    [$table, $players] = $this->lobbyTable(Seats::SEATS);

    return [$table->refresh(), $players];
  }

  private function seatOf(User $user): ?TableSeat
  {
    return TableSeat::where('user_id', $user->id)->first();
  }
}
