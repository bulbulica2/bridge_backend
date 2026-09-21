<?php

namespace Tests\Feature\Table;

use App\Events\TableUpdated;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TableBroadcastTest extends TestCase
{
  use RefreshDatabase;

  public function test_taking_a_seat_broadcasts_the_table(): void
  {
    Event::fake([TableUpdated::class]);

    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);

    $response = $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats", ['seat' => 'E'])
      ->assertCreated();

    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatched(TableUpdated::class, function (TableUpdated $event) use ($table, $response) {
      // one table shape, whether it came over HTTP or the socket
      return $event->broadcastOn() == [new PrivateChannel("table.$table->id")]
        && $event->broadcastWith() === ['table' => $response->json('data')];
    });
  }

  public function test_the_broadcast_hides_player_emails(): void
  {
    Event::fake([TableUpdated::class]);

    $table = Table::factory()->create(['board_id' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson("/tables/$table->id/seats", ['seat' => 'S'])->assertCreated();

    Event::assertDispatched(TableUpdated::class, function (TableUpdated $event) use ($user) {
      $player = $event->table['seats'][0]['user'];

      return $player['id'] === $user->id && !array_key_exists('email', $player);
    });
  }

  public function test_the_fourth_seat_broadcasts_the_dealt_board(): void
  {
    $this->seed(CardSeeder::class);
    Event::fake([TableUpdated::class]);

    $table = Table::factory()->create(['board_id' => null]);

    foreach (['N', 'E', 'S'] as $seat) {
      TableSeat::factory()->create(['table_id' => $table->id, 'seat' => $seat]);
    }

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats", ['seat' => 'W'])
      ->assertCreated();

    Event::assertDispatched(TableUpdated::class, function (TableUpdated $event) use ($table) {
      return $event->table['board_id'] !== null
        && $event->table['board_id'] === $table->fresh()->board_id
        && $event->table['free_seats'] === [];
    });
  }

  public function test_a_manager_seating_someone_broadcasts_the_table(): void
  {
    Event::fake([TableUpdated::class]);

    $creator = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $creator->id,
      'moderated_by' => $creator->id,
    ]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $creator->id, 'seat' => 'N']);

    $this->actingAs($creator)->postJson("/tables/$table->id/seats/users", [
      'user_id' => User::factory()->create()->id,
      'seat' => 'E',
    ])->assertCreated();

    Event::assertDispatched(TableUpdated::class, fn (TableUpdated $event) => $event->tableId === $table->id);
  }

  public function test_freeing_a_seat_broadcasts_the_table(): void
  {
    Event::fake([TableUpdated::class]);

    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);
    $leaver = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $leaver->id, 'seat' => 'E']);

    $this->actingAs($leaver)->deleteJson("/tables/$table->id/seats")->assertOk();

    Event::assertDispatched(TableUpdated::class, function (TableUpdated $event) use ($table) {
      return $event->tableId === $table->id
        && count($event->table['seats']) === 1
        && $event->table['free_seats'] === ['E', 'S', 'W'];
    });
  }

  public function test_a_kick_broadcasts_the_table(): void
  {
    Event::fake([TableUpdated::class]);

    $creator = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $creator->id,
      'moderated_by' => $creator->id,
    ]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $creator->id, 'seat' => 'N']);
    $kicked = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $kicked->id, 'seat' => 'E']);

    $this->actingAs($creator)->deleteJson("/tables/$table->id/seats/$kicked->id")->assertOk();

    Event::assertDispatched(TableUpdated::class, fn (TableUpdated $event) => $event->table['free_seats'] === ['E', 'S', 'W']);
  }

  public function test_the_last_player_leaving_broadcasts_nothing(): void
  {
    Event::fake([TableUpdated::class]);

    $user = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $user->id, 'seat' => 'N']);

    $this->actingAs($user)->deleteJson("/tables/$table->id/seats")
      ->assertOk()
      ->assertJsonPath('data.table_deleted', true);

    Event::assertNotDispatched(TableUpdated::class);
  }

  public function test_moving_broadcasts_both_tables(): void
  {
    Event::fake([TableUpdated::class]);

    $user = User::factory()->create();
    $old = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $old->id, 'seat' => 'N']);
    TableSeat::factory()->create(['table_id' => $old->id, 'user_id' => $user->id, 'seat' => 'E']);
    $new = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $new->id, 'seat' => 'N']);

    $this->actingAs($user)->postJson("/tables/$new->id/seats", ['seat' => 'S'])->assertCreated();

    Event::assertDispatchedTimes(TableUpdated::class, 2);
    Event::assertDispatched(TableUpdated::class, fn (TableUpdated $event) => $event->tableId === $old->id
      && $event->table['free_seats'] === ['E', 'S', 'W']);
    Event::assertDispatched(TableUpdated::class, fn (TableUpdated $event) => $event->tableId === $new->id
      && $event->table['free_seats'] === ['E', 'W']);
  }

  public function test_moving_off_a_table_you_emptied_broadcasts_only_the_new_one(): void
  {
    Event::fake([TableUpdated::class]);

    $user = User::factory()->create();
    $old = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $old->id, 'user_id' => $user->id, 'seat' => 'N']);
    $new = Table::factory()->create(['board_id' => null]);

    $this->actingAs($user)->postJson("/tables/$new->id/seats", ['seat' => 'N'])->assertCreated();

    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatched(TableUpdated::class, fn (TableUpdated $event) => $event->tableId === $new->id);
  }

  public function test_changing_seat_at_the_same_table_broadcasts_it(): void
  {
    Event::fake([TableUpdated::class]);

    $user = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $user->id, 'seat' => 'N']);

    $this->actingAs($user)->postJson("/tables/$table->id/seats", ['seat' => 'W'])->assertCreated();

    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatched(TableUpdated::class, fn (TableUpdated $event) => $event->table['free_seats'] === ['N', 'E', 'S']);
  }

  public function test_a_refused_seat_broadcasts_nothing(): void
  {
    Event::fake([TableUpdated::class]);

    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'E']);

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats", ['seat' => 'E'])
      ->assertStatus(409);

    Event::assertNotDispatched(TableUpdated::class);
  }

  public function test_a_seated_player_may_subscribe_to_the_table_channel(): void
  {
    $this->useReverbBroadcaster();

    $user = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $user->id, 'seat' => 'N']);

    $this->actingAs($user)->postJson('/broadcasting/auth', [
      'socket_id' => '1234.5678',
      'channel_name' => "private-table.$table->id",
    ])->assertOk()->assertJsonStructure(['auth']);
  }

  public function test_a_player_seated_elsewhere_may_not_subscribe(): void
  {
    $this->useReverbBroadcaster();

    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);
    $user = User::factory()->create();
    TableSeat::factory()->create([
      'table_id' => Table::factory()->create(['board_id' => null])->id,
      'user_id' => $user->id,
      'seat' => 'N',
    ]);

    $this->actingAs($user)->postJson('/broadcasting/auth', [
      'socket_id' => '1234.5678',
      'channel_name' => "private-table.$table->id",
    ])->assertForbidden();
  }

  public function test_an_unseated_user_or_a_guest_may_not_subscribe(): void
  {
    $this->useReverbBroadcaster();

    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);
    $body = ['socket_id' => '1234.5678', 'channel_name' => "private-table.$table->id"];

    $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', $body)->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->postJson('/broadcasting/auth', $body)->assertForbidden();
  }

  /**
   * Tests broadcast through the `null` driver (phpunit.xml), whose auth()
   * lets everybody in. Channel authorization is only real on a Pusher-style
   * driver, and Reverb signs the reply locally, so no server has to run.
   * Channels register on the driver that was default at boot, so the
   * callbacks are loaded again onto the new one.
   */
  private function useReverbBroadcaster(): void
  {
    config([
      'broadcasting.default' => 'reverb',
      'broadcasting.connections.reverb.key' => 'test-key',
      'broadcasting.connections.reverb.secret' => 'test-secret',
      'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    $this->app->make(BroadcastManager::class)->forgetDrivers();

    require base_path('routes/channels.php');
  }
}
