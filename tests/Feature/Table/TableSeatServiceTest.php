<?php

namespace Tests\Feature\Table;

use App\Exceptions\SeatUnavailableException;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Services\TableSeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableSeatServiceTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $service;

  protected function setUp(): void
  {
    parent::setUp();

    $this->service = new TableSeatService();
  }

  public function test_seats_a_user_at_a_free_seat(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $user = User::factory()->create();

    $seat = $this->service->seat($table, $user, 'W');

    $this->assertSame('W', $seat->seat);
    $this->assertSame($user->id, $seat->user_id);
  }

  public function test_an_unknown_seat_is_rejected(): void
  {
    $table = Table::factory()->create(['board_id' => null]);

    $this->expectException(SeatUnavailableException::class);

    $this->service->seat($table, User::factory()->create(), 'X');
  }

  public function test_a_taken_seat_is_rejected(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'E']);

    $this->expectException(SeatUnavailableException::class);

    $this->service->seat($table, User::factory()->create(), 'E');
  }

  public function test_a_user_seated_elsewhere_is_rejected(): void
  {
    $user = User::factory()->create();
    TableSeat::factory()->create(['user_id' => $user->id, 'table_id' => Table::factory()->create(['board_id' => null])->id]);

    $this->expectException(SeatUnavailableException::class);

    $this->service->seat(Table::factory()->create(['board_id' => null]), $user, 'N');
  }

  public function test_seating_someone_else_who_sits_elsewhere_names_them_in_the_error(): void
  {
    $user = User::factory()->create();
    TableSeat::factory()->create(['user_id' => $user->id, 'table_id' => Table::factory()->create(['board_id' => null])->id]);

    $this->expectException(SeatUnavailableException::class);
    $this->expectExceptionMessage('That user is already seated at a table.');

    $this->service->seat(Table::factory()->create(['board_id' => null]), $user, 'N', User::factory()->create());
  }

  public function test_leaving_an_otherwise_empty_table_deletes_it(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $user = User::factory()->create();
    $this->service->seat($table, $user, 'N');

    $deleted = $this->service->remove($table, $user);

    $this->assertTrue($deleted);
    $this->assertDatabaseMissing('tables', ['id' => $table->id]);
    $this->assertDatabaseCount('table_seats', 0);
  }

  public function test_leaving_keeps_a_table_that_still_has_a_player(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $leaver = User::factory()->create();
    $stayer = User::factory()->create();
    $this->service->seat($table, $leaver, 'N');
    $this->service->seat($table, $stayer, 'S');

    $deleted = $this->service->remove($table, $leaver);

    $this->assertFalse($deleted);
    $this->assertDatabaseHas('tables', ['id' => $table->id]);
    $this->assertSame(['S'], $table->seats()->pluck('seat')->all());
  }

  public function test_the_moderator_role_passes_to_the_earliest_remaining_player(): void
  {
    $moderator = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null, 'moderated_by' => $moderator->id]);
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->service->seat($table, $moderator, 'N');
    $this->service->seat($table, $first, 'E');
    $this->service->seat($table, $second, 'S');

    $this->service->remove($table, $moderator);

    $this->assertSame($first->id, $table->fresh()->moderated_by);
  }

  public function test_a_non_moderator_leaving_does_not_move_the_moderator_role(): void
  {
    $moderator = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null, 'moderated_by' => $moderator->id]);
    $other = User::factory()->create();

    $this->service->seat($table, $moderator, 'N');
    $this->service->seat($table, $other, 'E');

    $this->service->remove($table, $other);

    $this->assertSame($moderator->id, $table->fresh()->moderated_by);
  }

  public function test_removing_someone_else_who_does_not_sit_there_names_them_in_the_error(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);

    $this->expectException(SeatUnavailableException::class);
    $this->expectExceptionMessage('That user is not seated at this table.');

    $this->service->remove($table, User::factory()->create(), User::factory()->create());
  }

  public function test_the_moderator_role_goes_back_to_a_still_seated_creator(): void
  {
    $creator = User::factory()->create();
    $moderator = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $creator->id,
      'moderated_by' => $moderator->id,
    ]);

    // the moderator joined first, so without the creator rule they'd hand
    // the table to $other
    $this->service->seat($table, $moderator, 'N');
    $this->service->seat($table, $creator, 'E');
    $this->service->seat($table, User::factory()->create(), 'S');

    $this->service->remove($table, $moderator);

    $this->assertSame($creator->id, $table->fresh()->moderated_by);
  }

  public function test_leaving_a_table_you_do_not_sit_at_is_rejected(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);

    $this->expectException(SeatUnavailableException::class);

    $this->service->remove($table, User::factory()->create());
  }
}
