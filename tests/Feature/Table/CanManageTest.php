<?php

namespace Tests\Feature\Table;

use App\Events\TableUpdated;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * `can_manage` on the table payload: TablePolicy::manage for the caller, so
 * the client never has to re-implement it.
 */
class CanManageTest extends TestCase
{
  use RefreshDatabase;

  /**
   * A table made by a creator who left, now moderated by another player,
   * with a third plain player.
   *
   * @return array{0: Table, 1: User, 2: User, 3: User} table, creator, moderator, player
   */
  private function tableWhoseCreatorLeft(): array
  {
    $creator = User::factory()->create();
    $moderator = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $creator->id,
      'moderated_by' => $moderator->id,
    ]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $moderator->id, 'seat' => 'N']);

    $player = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $player->id, 'seat' => 'E']);

    return [$table, $creator, $moderator, $player];
  }

  private function assertCanManage(User $user, Table $table, bool $expected): void
  {
    $this->actingAs($user)->getJson("/tables/$table->id")
      ->assertOk()
      ->assertJsonPath('data.can_manage', $expected);
  }

  public function test_the_moderator_can_manage(): void
  {
    [$table, , $moderator] = $this->tableWhoseCreatorLeft();

    $this->assertCanManage($moderator, $table, true);
  }

  public function test_a_creator_seated_again_is_a_plain_player(): void
  {
    [$table, $creator] = $this->tableWhoseCreatorLeft();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $creator->id, 'seat' => 'S']);

    $this->assertCanManage($creator, $table, false);
  }

  public function test_an_admin_can_manage_seated_or_not(): void
  {
    [$table] = $this->tableWhoseCreatorLeft();
    $admin = User::factory()->isAdmin()->create();

    $this->assertCanManage($admin, $table, true);

    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $admin->id, 'seat' => 'W']);

    $this->assertCanManage($admin, $table, true);
  }

  public function test_a_creator_who_left_cannot_manage(): void
  {
    [$table, $creator] = $this->tableWhoseCreatorLeft();

    $this->assertCanManage($creator, $table, false);
  }

  public function test_a_plain_player_cannot_manage(): void
  {
    [$table, , , $player] = $this->tableWhoseCreatorLeft();

    $this->assertCanManage($player, $table, false);
  }

  public function test_the_list_says_it_per_table(): void
  {
    [$managed, , $moderator] = $this->tableWhoseCreatorLeft();
    [$other] = $this->tableWhoseCreatorLeft();

    $tables = collect($this->actingAs($moderator)->getJson('/tables')->assertOk()->json('data'))
      ->pluck('can_manage', 'id');

    $this->assertSame([$managed->id => true, $other->id => false], $tables->sortKeys()->all());
  }

  public function test_seat_changes_answer_for_the_caller(): void
  {
    [$table, , $moderator, $player] = $this->tableWhoseCreatorLeft();

    $this->actingAs($moderator)
      ->deleteJson("/tables/$table->id/seats/$player->id")
      ->assertOk()
      ->assertJsonPath('data.can_manage', true);

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats", ['seat' => 'S'])
      ->assertCreated()
      ->assertJsonPath('data.can_manage', false);
  }

  public function test_the_broadcast_leaves_it_out(): void
  {
    Event::fake([TableUpdated::class]);
    [$table, , $moderator] = $this->tableWhoseCreatorLeft();

    // made by a manager, whose true must not reach the other players
    $this->actingAs($moderator)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => User::factory()->create()->id, 'seat' => 'S'])
      ->assertCreated()
      ->assertJsonPath('data.can_manage', true);

    Event::assertDispatched(TableUpdated::class, function (TableUpdated $event) {
      return ! array_key_exists('can_manage', $event->table)
        && ! array_key_exists('can_manage', $event->broadcastWith()['table']);
    });
  }
}
