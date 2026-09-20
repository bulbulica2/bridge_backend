<?php

namespace Tests\Feature\Table;

use App\auxiliary\Seats;
use App\Exceptions\SeatUnavailableException;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Services\TableSeatService;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taking a seat while you already hold one moves you, rather than being
 * refused. One seat per user is still the rule.
 */
class MoveBetweenTablesTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  protected function setUp(): void
  {
    parent::setUp();

    // filling a table deals it a board, which needs the reference cards
    $this->seed(CardSeeder::class);

    $this->seats = app(TableSeatService::class);
  }

  public function test_moving_leaves_exactly_one_seat_on_the_new_table(): void
  {
    $user = User::factory()->create();
    $old = $this->tableWith(['N' => $user, 'E' => User::factory()->create()]);
    $new = Table::factory()->create(['board_id' => null]);

    $this->seats->seat($new, $user, 'S');

    $this->assertSame(1, $user->seats()->count());
    $this->assertDatabaseHas('table_seats', [
      'table_id' => $new->id,
      'user_id' => $user->id,
      'seat' => 'S',
    ]);
    $this->assertDatabaseMissing('table_seats', ['table_id' => $old->id, 'user_id' => $user->id]);
  }

  public function test_the_old_table_is_deleted_when_the_mover_was_its_last_player(): void
  {
    $user = User::factory()->create();
    $old = $this->tableWith(['N' => $user]);
    $new = Table::factory()->create(['board_id' => null]);

    $this->seats->seat($new, $user, 'N');

    $this->assertDatabaseMissing('tables', ['id' => $old->id]);
  }

  public function test_moving_hands_the_moderator_role_on(): void
  {
    $moderator = User::factory()->create();
    $stayer = User::factory()->create();
    $old = $this->tableWith(['N' => $moderator, 'E' => $stayer]);
    $old->update(['moderated_by' => $moderator->id, 'created_by' => $moderator->id]);

    $this->seats->seat(Table::factory()->create(['board_id' => null]), $moderator, 'N');

    $this->assertSame($stayer->id, $old->fresh()->moderated_by);
  }

  public function test_moving_detaches_an_unfinished_playing_on_the_old_table(): void
  {
    $old = Table::factory()->create(['board_id' => null]);
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $players[$seat] = User::factory()->create();
      $this->seats->seat($old, $players[$seat], $seat);
    }

    $playing = BoardTable::where('table_id', $old->id)->firstOrFail();

    $this->seats->seat(Table::factory()->create(['board_id' => null]), $players['S'], 'N');

    // kept with its snapshot, cut loose from the table
    $this->assertDatabaseHas('board_table', ['id' => $playing->id, 'table_id' => null]);
    $this->assertSame(4, $playing->seats()->count());
    $this->assertNull($old->fresh()->board_id);
  }

  public function test_moving_into_a_fourth_seat_deals_the_new_table_a_board(): void
  {
    $user = User::factory()->create();
    $this->tableWith(['N' => $user, 'E' => User::factory()->create()]);

    $new = Table::factory()->create(['board_id' => null]);

    foreach (['N', 'E', 'S'] as $seat) {
      $this->seats->seat($new, User::factory()->create(), $seat);
    }

    $this->seats->seat($new, $user, 'W');

    $this->assertNotNull($new->fresh()->board_id);
    $this->assertSame(4, BoardTable::where('table_id', $new->id)->firstOrFail()->seats()->count());
  }

  public function test_a_failed_move_leaves_the_original_seat_untouched(): void
  {
    $user = User::factory()->create();
    $old = $this->tableWith(['N' => $user, 'E' => User::factory()->create()]);

    $new = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $new->id, 'seat' => 'S']);

    try {
      $this->seats->seat($new, $user, 'S');
      $this->fail('Expected the taken seat to be refused.');
    } catch (SeatUnavailableException $e) {
      $this->assertSame('Seat S is already taken.', $e->getMessage());
    }

    $this->assertDatabaseHas('table_seats', [
      'table_id' => $old->id,
      'user_id' => $user->id,
      'seat' => 'N',
    ]);
    $this->assertSame(1, $user->seats()->count());
    $this->assertDatabaseHas('tables', ['id' => $old->id]);
  }

  public function test_changing_seat_at_the_same_table_keeps_the_table_and_the_row(): void
  {
    $user = User::factory()->create();
    $table = $this->tableWith(['N' => $user]);
    $before = $user->seats()->firstOrFail();

    $moved = $this->seats->seat($table, $user, 'W');

    $this->assertSame('W', $moved->seat);
    // the same row moved, so the table was never emptied and the join order
    // the moderator handover reads is preserved
    $this->assertSame($before->id, $moved->id);
    $this->assertDatabaseHas('tables', ['id' => $table->id]);
    $this->assertSame(1, $table->seats()->count());
  }

  public function test_changing_to_a_seat_somebody_else_holds_is_refused(): void
  {
    $user = User::factory()->create();
    $table = $this->tableWith(['N' => $user, 'E' => User::factory()->create()]);

    $this->expectException(SeatUnavailableException::class);
    $this->expectExceptionMessage('Seat E is already taken.');

    $this->seats->seat($table, $user, 'E');
  }

  public function test_a_manager_may_not_pull_a_player_off_another_table(): void
  {
    $manager = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $manager->id,
      'moderated_by' => $manager->id,
    ]);
    $this->seats->seat($table, $manager, 'N');

    $target = User::factory()->create();
    $elsewhere = $this->tableWith(['N' => $target]);

    $this->actingAs($manager)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'E'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'That user is already seated at a table.');

    $this->assertDatabaseHas('table_seats', ['table_id' => $elsewhere->id, 'user_id' => $target->id]);
  }

  public function test_a_manager_seating_themselves_still_moves_them(): void
  {
    $manager = User::factory()->create();
    $old = $this->tableWith(['N' => $manager, 'E' => User::factory()->create()]);

    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $manager->id,
      'moderated_by' => $manager->id,
    ]);

    $this->actingAs($manager)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $manager->id, 'seat' => 'S'])
      ->assertCreated();

    $this->assertDatabaseMissing('table_seats', ['table_id' => $old->id, 'user_id' => $manager->id]);
    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $manager->id]);
  }

  public function test_moving_over_http_frees_the_old_seat(): void
  {
    $user = User::factory()->create();
    $old = $this->tableWith(['N' => $user, 'E' => User::factory()->create()]);
    $new = Table::factory()->create(['board_id' => null]);

    $this->actingAs($user)
      ->postJson("/tables/$new->id/seats", ['seat' => 'E'])
      ->assertCreated()
      ->assertJsonPath('data.id', $new->id)
      ->assertJsonPath('data.free_seats', ['N', 'S', 'W']);

    $this->assertSame(['E'], $old->fresh()->seats()->pluck('seat')->all());
  }

  /**
   * A table with the given players already seated.
   *
   * @param  array<string, User>  $players
   */
  private function tableWith(array $players): Table
  {
    $table = Table::factory()->create(['board_id' => null]);

    foreach ($players as $seat => $player) {
      $this->seats->seat($table, $player, $seat);
    }

    return $table;
  }
}
