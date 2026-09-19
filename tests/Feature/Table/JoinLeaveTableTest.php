<?php

namespace Tests\Feature\Table;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinLeaveTableTest extends TestCase
{
  use RefreshDatabase;

  public function test_guest_is_rejected(): void
  {
    $table = Table::factory()->create(['board_id' => null]);

    $this->postJson("/tables/$table->id/seats", ['seat' => 'N'])->assertUnauthorized();
    $this->deleteJson("/tables/$table->id/seats")->assertUnauthorized();
  }

  public function test_joining_takes_a_free_seat(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson("/tables/$table->id/seats", ['seat' => 'E'])
      ->assertCreated()
      ->assertJsonPath('status', 201)
      ->assertJsonCount(2, 'data.seats')
      ->assertJsonPath('data.free_seats', ['S', 'W']);

    $this->assertDatabaseHas('table_seats', [
      'table_id' => $table->id,
      'user_id' => $user->id,
      'seat' => 'E',
    ]);
  }

  public function test_joining_a_taken_seat_returns_409(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'E']);

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats", ['seat' => 'E'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'Seat E is already taken.');
  }

  public function test_joining_while_seated_elsewhere_returns_409(): void
  {
    $user = User::factory()->create();
    TableSeat::factory()->create([
      'table_id' => Table::factory()->create(['board_id' => null])->id,
      'user_id' => $user->id,
      'seat' => 'N',
    ]);
    $table = Table::factory()->create(['board_id' => null]);

    $this->actingAs($user)->postJson("/tables/$table->id/seats", ['seat' => 'N'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'You are already seated at a table.');
  }

  public function test_join_requires_a_valid_seat(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson("/tables/$table->id/seats")
      ->assertUnprocessable()
      ->assertJsonValidationErrors('seat');

    $this->actingAs($user)->postJson("/tables/$table->id/seats", ['seat' => 'X'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('seat');
  }

  public function test_the_last_player_to_leave_deletes_the_table(): void
  {
    $user = User::factory()->create();
    $id = $this->actingAs($user)->postJson('/tables')->assertCreated()->json('data.id');

    $this->actingAs($user)->deleteJson("/tables/$id/seats")
      ->assertOk()
      ->assertJsonPath('data.table_deleted', true);

    $this->assertDatabaseMissing('tables', ['id' => $id]);
    $this->assertDatabaseCount('table_seats', 0);
  }

  public function test_leaving_hands_the_table_to_the_earliest_joiner(): void
  {
    $creator = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $id = $this->actingAs($creator)->postJson('/tables')->assertCreated()->json('data.id');
    $this->actingAs($first)->postJson("/tables/$id/seats", ['seat' => 'E'])->assertCreated();
    $this->actingAs($second)->postJson("/tables/$id/seats", ['seat' => 'S'])->assertCreated();

    $this->actingAs($creator)->deleteJson("/tables/$id/seats")
      ->assertOk()
      ->assertJsonPath('data.moderated_by', $first->id)
      ->assertJsonPath('data.created_by', $creator->id)
      ->assertJsonCount(2, 'data.seats')
      ->assertJsonPath('data.free_seats', ['N', 'W']);

    $this->assertDatabaseHas('tables', [
      'id' => $id,
      'moderated_by' => $first->id,
      'created_by' => $creator->id,
    ]);
  }

  public function test_leaving_a_table_you_do_not_sit_at_returns_409(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);

    $this->actingAs(User::factory()->create())
      ->deleteJson("/tables/$table->id/seats")
      ->assertStatus(409)
      ->assertJsonPath('message', 'You are not seated at this table.');
  }

  public function test_leaving_frees_the_user_to_join_another_table(): void
  {
    $user = User::factory()->create();
    $other = Table::factory()->create(['board_id' => null]);

    $id = $this->actingAs($user)->postJson('/tables')->assertCreated()->json('data.id');
    $this->actingAs($user)->deleteJson("/tables/$id/seats")->assertOk();

    $this->actingAs($user)->postJson("/tables/$other->id/seats", ['seat' => 'W'])
      ->assertCreated();
  }

  public function test_joining_a_table_that_does_not_exist_returns_404(): void
  {
    $this->actingAs(User::factory()->create())
      ->postJson('/tables/999999/seats', ['seat' => 'N'])
      ->assertNotFound();
  }

  /**
   * A table has no "closed" state: the last player out deletes the row. So a
   * table that is over refuses a join with a 404, not a 409 — there is no
   * longer a table to be full, taken or closed.
   */
  public function test_joining_a_table_the_last_player_left_returns_404(): void
  {
    $creator = User::factory()->create();
    $id = $this->actingAs($creator)->postJson('/tables')->assertCreated()->json('data.id');

    $this->actingAs($creator)->deleteJson("/tables/$id/seats")
      ->assertOk()
      ->assertJsonPath('data.table_deleted', true);

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$id/seats", ['seat' => 'N'])
      ->assertNotFound();
  }
}
