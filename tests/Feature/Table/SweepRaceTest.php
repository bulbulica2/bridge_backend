<?php

namespace Tests\Feature\Table;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Services\TableSeatService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scheduled sweeps pick their candidates first, then recheck each one
 * under its table's lock, since a player may have moved, left or sat down
 * in between. On one connection nothing can happen in between, so each
 * test stages that change from a model event or query listener fired while
 * the sweep is part way through.
 */
class SweepRaceTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  protected function setUp(): void
  {
    parent::setUp();

    config([
      'bridge.idle_seat_minutes' => 5,
      'bridge.unattended_table_minutes' => 10,
    ]);

    $this->seats = app(TableSeatService::class);
  }

  public function test_check_away_skips_a_table_deleted_before_its_turn(): void
  {
    // two tables with no set on, each with one player still marked away
    [$first, $firstAway] = $this->tableWithAPlayerAway();
    [$second] = $this->tableWithAPlayerAway();

    // freeing the first table's seat, its last players leave the second
    $this->onFirst(TableSeat::class, 'deleted', fn () => $second->delete());

    $this->assertSame(['away' => 0, 'forfeited' => 0, 'freed' => 1], $this->seats->checkAway());

    $this->assertNull($firstAway->seats()->first());
    $this->assertModelExists($first);
    $this->assertModelMissing($second);
  }

  public function test_the_unattended_sweep_spares_a_table_a_human_sat_down_at_meanwhile(): void
  {
    $first = $this->unattendedTable();
    $second = $this->unattendedTable();
    $newcomer = User::factory()->create();

    // while the first table is being deleted, a human sits at the second
    $this->onFirst(Table::class, 'deleted', fn () => $this->seats->seat($second, $newcomer, 'N'));

    $this->travel(11)->minutes();

    $this->assertSame(1, $this->seats->deleteUnattendedTables());

    $this->assertModelMissing($first);
    $this->assertModelExists($second);
    $this->assertNull($second->fresh()->unattended_since);
  }

  public function test_the_idle_sweep_skips_a_seat_freed_meanwhile(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $north = User::factory()->create();
    $east = User::factory()->create();
    $this->seats->seat($table, $north, 'N');
    $this->seats->seat($table, $east, 'E');
    $this->seats->seat($table, User::factory()->create(), 'S');

    $this->travel(6)->minutes();
    $table->seats()->where('seat', 'S')->update(['last_seen_at' => now()]);

    // as North's seat is freed, East leaves on their own
    $this->onFirst(TableSeat::class, 'deleted', fn () => $this->seats->remove($table, $east));

    $this->assertSame(1, $this->seats->releaseIdleSeats());
    $this->assertSame(['S'], $table->seats()->pluck('seat')->all());
  }

  public function test_the_idle_sweep_spares_a_player_whose_heartbeat_came_meanwhile(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $east = User::factory()->create();
    $this->seats->seat($table, User::factory()->create(), 'N');
    $this->seats->seat($table, $east, 'E');

    $this->travel(6)->minutes();

    // as North's seat is freed, East's heartbeat arrives
    $this->onFirst(TableSeat::class, 'deleted', fn () => $this->seats->touch($table, $east));

    $this->assertSame(1, $this->seats->releaseIdleSeats());
    $this->assertSame(['E'], $table->seats()->pluck('seat')->all());
  }

  public function test_the_idle_sweep_skips_a_seat_moved_to_another_table_meanwhile(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $player = User::factory()->create();
    $seat = $this->seats->seat($table, $player, 'N');
    $this->seats->seat($table, User::factory()->create(), 'E');
    $elsewhere = Table::factory()->create(['board_id' => null]);

    $this->travel(6)->minutes();
    $table->seats()->where('seat', 'E')->update(['last_seen_at' => now()]);

    // the player moves just after the sweep read which table their seat is
    // at, before it locked that table
    $moved = false;
    DB::listen(function (QueryExecuted $query) use (&$moved, $seat, $elsewhere) {
      if (! $moved && str_starts_with($query->sql, 'select "table_id" from "table_seats"')) {
        $moved = true;
        DB::table('table_seats')->where('id', $seat->id)->update(['table_id' => $elsewhere->id]);
      }
    });

    $this->assertSame(0, $this->seats->releaseIdleSeats());
    $this->assertTrue($moved);
    $this->assertSame($elsewhere->id, $seat->fresh()->table_id);
  }

  /**
   * Run `$then` the first time model event `$event` (e.g. `deleted`) fires
   * for a `$model`.
   *
   * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
   */
  private function onFirst(string $model, string $event, callable $then): void
  {
    $fired = false;

    $model::$event(function () use (&$fired, $then) {
      if (! $fired) {
        $fired = true;
        $then();
      }
    });
  }

  /**
   * A table with no set on: one player at N present, one at E away.
   *
   * @return array{Table, User}
   */
  private function tableWithAPlayerAway(): array
  {
    $table = Table::factory()->create(['board_id' => null]);
    $away = User::factory()->create();
    $this->seats->seat($table, User::factory()->create(), 'N');
    $this->seats->seat($table, $away, 'E')->update(['away_since' => now()]);

    return [$table, $away];
  }

  /**
   * A table only robots keep, since now.
   */
  private function unattendedTable(): Table
  {
    $table = Table::factory()->create(['board_id' => null, 'moderated_by' => null, 'unattended_since' => now()]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => User::factory()->robot()->create()->id, 'seat' => 'E']);

    return $table;
  }
}
