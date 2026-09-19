<?php

namespace Tests\Feature\Table;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DELETE /tables/{table}/seats/{user} — quitting your own seat, or a manager
 * kicking somebody else out of theirs.
 */
class RemoveUserFromTableTest extends TestCase
{
  use RefreshDatabase;

  /**
   * A table whose creator is also its moderator, plus one other player.
   *
   * @return array{0: Table, 1: User, 2: User} table, creator, other player
   */
  private function tableWithCreatorAndPlayer(string $seat = 'N', string $playerSeat = 'E'): array
  {
    $creator = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $creator->id,
      'moderated_by' => $creator->id,
    ]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $creator->id, 'seat' => $seat]);

    $player = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $player->id, 'seat' => $playerSeat]);

    return [$table, $creator, $player];
  }

  public function test_guest_is_rejected(): void
  {
    [$table, , $player] = $this->tableWithCreatorAndPlayer();

    $this->deleteJson("/tables/$table->id/seats/$player->id")->assertUnauthorized();
  }

  public function test_a_user_quits_their_own_seat(): void
  {
    [$table, , $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs($player)->deleteJson("/tables/$table->id/seats/$player->id")
      ->assertOk()
      ->assertJsonPath('message', 'You left the table.')
      ->assertJsonCount(1, 'data.seats')
      ->assertJsonPath('data.free_seats', ['E', 'S', 'W']);

    $this->assertDatabaseMissing('table_seats', ['table_id' => $table->id, 'user_id' => $player->id]);
  }

  public function test_a_manager_kicks_another_player(): void
  {
    [$table, $creator, $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$player->id")
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table.')
      ->assertJsonCount(1, 'data.seats')
      ->assertJsonPath('data.moderated_by', $creator->id);

    $this->assertDatabaseMissing('table_seats', ['table_id' => $table->id, 'user_id' => $player->id]);
    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $creator->id]);
  }

  public function test_an_admin_who_is_not_at_the_table_can_kick(): void
  {
    [$table, , $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs(User::factory()->isAdmin()->create())
      ->deleteJson("/tables/$table->id/seats/$player->id")
      ->assertOk();

    $this->assertDatabaseMissing('table_seats', ['table_id' => $table->id, 'user_id' => $player->id]);
  }

  public function test_a_non_manager_cannot_kick_someone_else(): void
  {
    [$table, $creator, $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs($player)->deleteJson("/tables/$table->id/seats/$creator->id")
      ->assertForbidden()
      ->assertJsonPath('message', 'Only the table creator, its moderator or an admin can remove other players.');

    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $creator->id]);
  }

  public function test_a_stranger_cannot_kick(): void
  {
    [$table, , $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs(User::factory()->create())
      ->deleteJson("/tables/$table->id/seats/$player->id")
      ->assertForbidden();

    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $player->id]);
  }

  public function test_a_manager_aiming_at_themselves_just_quits(): void
  {
    [$table, $creator, $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$creator->id")
      ->assertOk()
      ->assertJsonPath('message', 'You left the table.')
      ->assertJsonPath('data.moderated_by', $player->id)
      ->assertJsonPath('data.created_by', $creator->id);

    $this->assertDatabaseMissing('table_seats', ['table_id' => $table->id, 'user_id' => $creator->id]);
  }

  public function test_a_user_not_seated_at_this_table_returns_404(): void
  {
    [$table, $creator] = $this->tableWithCreatorAndPlayer();
    $stranger = User::factory()->create();

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$stranger->id")
      ->assertNotFound()
      ->assertJsonPath('message', 'That user is not seated at this table.');
  }

  public function test_quitting_a_table_you_do_not_sit_at_returns_404(): void
  {
    [$table] = $this->tableWithCreatorAndPlayer();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->deleteJson("/tables/$table->id/seats/$stranger->id")
      ->assertNotFound()
      ->assertJsonPath('message', 'You are not seated at this table.');
  }

  public function test_an_unknown_user_or_table_returns_404(): void
  {
    [$table, $creator, $player] = $this->tableWithCreatorAndPlayer();

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/999999")->assertNotFound();
    $this->actingAs($creator)->deleteJson("/tables/999999/seats/$player->id")->assertNotFound();
  }

  public function test_removing_the_last_player_deletes_the_table(): void
  {
    $creator = User::factory()->create();
    $id = $this->actingAs($creator)->postJson('/tables')->assertCreated()->json('data.id');

    $this->actingAs($creator)->deleteJson("/tables/$id/seats/$creator->id")
      ->assertOk()
      ->assertJsonPath('data.table_deleted', true)
      ->assertJsonPath('message', 'You left the table. Nobody was left, so the table was deleted.');

    $this->assertDatabaseMissing('tables', ['id' => $id]);
    $this->assertDatabaseCount('table_seats', 0);
  }

  public function test_a_kicked_player_frees_their_seat_and_may_join_elsewhere(): void
  {
    [$table, $creator, $player] = $this->tableWithCreatorAndPlayer();
    $other = Table::factory()->create(['board_id' => null]);

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$player->id")->assertOk();

    // nothing records the kick, so a kicked player can sit down again at once
    $this->actingAs($player)->postJson("/tables/$other->id/seats", ['seat' => 'W'])->assertCreated();
  }

  public function test_a_departed_creator_can_no_longer_manage_the_table(): void
  {
    [$table, $creator, $player] = $this->tableWithCreatorAndPlayer();
    $third = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $third->id, 'seat' => 'S']);

    // the creator quits, so the role moves to the earliest remaining player
    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$creator->id")
      ->assertOk()
      ->assertJsonPath('data.moderated_by', $player->id);

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$third->id")
      ->assertForbidden();

    $this->assertDatabaseHas('table_seats', ['table_id' => $table->id, 'user_id' => $third->id]);
  }
}
