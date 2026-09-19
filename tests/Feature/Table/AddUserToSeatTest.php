<?php

namespace Tests\Feature\Table;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddUserToSeatTest extends TestCase
{
  use RefreshDatabase;

  private function tableWithCreatorAt(string $seat = 'N'): Table
  {
    $creator = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $creator->id,
      'moderated_by' => $creator->id,
    ]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $creator->id, 'seat' => $seat]);

    return $table;
  }

  public function test_guest_is_rejected(): void
  {
    $table = $this->tableWithCreatorAt();

    $this->postJson("/tables/$table->id/seats/users", [
      'user_id' => User::factory()->create()->id,
      'seat' => 'E',
    ])->assertUnauthorized();
  }

  public function test_the_creator_can_seat_another_user(): void
  {
    $table = $this->tableWithCreatorAt();
    $target = User::factory()->create();

    $this->actingAs($table->creator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'E'])
      ->assertCreated()
      ->assertJsonPath('status', 201)
      ->assertJsonPath('message', 'User seated successfully.')
      ->assertJsonCount(2, 'data.seats')
      ->assertJsonPath('data.free_seats', ['S', 'W']);

    $this->assertDatabaseHas('table_seats', [
      'table_id' => $table->id,
      'user_id' => $target->id,
      'seat' => 'E',
    ]);
  }

  public function test_the_moderator_can_seat_another_user(): void
  {
    $moderator = User::factory()->create();
    // created_by is someone else, so only moderated_by grants access
    $table = Table::factory()->create(['board_id' => null, 'moderated_by' => $moderator->id]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $moderator->id, 'seat' => 'N']);
    $target = User::factory()->create();

    $this->actingAs($moderator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'S'])
      ->assertCreated();

    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $target->id, 'seat' => 'S']);
  }

  public function test_an_admin_not_at_the_table_can_seat_another_user(): void
  {
    $table = $this->tableWithCreatorAt();
    $target = User::factory()->create();

    $this->actingAs(User::factory()->isAdmin()->create())
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'W'])
      ->assertCreated();

    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $target->id, 'seat' => 'W']);
  }

  public function test_a_player_who_is_not_a_manager_gets_403(): void
  {
    $table = $this->tableWithCreatorAt();
    $player = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $player->id, 'seat' => 'E']);
    $target = User::factory()->create();

    $this->actingAs($player)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'S'])
      ->assertForbidden()
      ->assertJsonPath('message', 'Only the table creator, its moderator or an admin can seat other players.');

    $this->assertDatabaseMissing('table_seats', ['user_id' => $target->id]);
  }

  public function test_authorization_is_checked_before_validation(): void
  {
    $table = $this->tableWithCreatorAt();

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats/users", ['seat' => 'X'])
      ->assertForbidden();
  }

  public function test_a_taken_seat_returns_409(): void
  {
    $table = $this->tableWithCreatorAt('N');

    $this->actingAs($table->creator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => User::factory()->create()->id, 'seat' => 'N'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'Seat N is already taken.');
  }

  public function test_a_target_seated_elsewhere_returns_409(): void
  {
    $table = $this->tableWithCreatorAt();
    $target = User::factory()->create();
    TableSeat::factory()->create([
      'table_id' => Table::factory()->create(['board_id' => null])->id,
      'user_id' => $target->id,
      'seat' => 'N',
    ]);

    $this->actingAs($table->creator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'E'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'That user is already seated at a table.');
  }

  public function test_an_invalid_seat_or_user_returns_422(): void
  {
    $table = $this->tableWithCreatorAt();
    $creator = $table->creator;
    $target = User::factory()->create();

    $this->actingAs($creator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id, 'seat' => 'X'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('seat');

    $this->actingAs($creator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $target->id])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('seat');

    $this->actingAs($creator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => 999999, 'seat' => 'E'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('user_id');

    $this->actingAs($creator)
      ->postJson("/tables/$table->id/seats/users", ['seat' => 'E'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('user_id');
  }

  public function test_a_missing_table_returns_404(): void
  {
    $this->actingAs(User::factory()->isAdmin()->create())
      ->postJson('/tables/999999/seats/users', ['user_id' => User::factory()->create()->id, 'seat' => 'E'])
      ->assertNotFound();
  }
}
