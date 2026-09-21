<?php

namespace Tests\Feature\Table;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTableTest extends TestCase
{
  use RefreshDatabase;

  public function test_guest_is_rejected(): void
  {
    $this->postJson('/tables')->assertUnauthorized();
    $this->getJson('/tables')->assertUnauthorized();

    $this->assertDatabaseCount('tables', 0);
  }

  public function test_create_returns_201_and_seats_the_creator(): void
  {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/tables', ['name' => 'Friday club', 'seat' => 'E']);

    $response->assertCreated()
      ->assertJsonPath('status', 201)
      ->assertJsonPath('data.name', 'Friday club')
      ->assertJsonPath('data.created_by', $user->id)
      ->assertJsonPath('data.moderated_by', $user->id)
      ->assertJsonPath('data.board_id', null)
      ->assertJsonPath('data.seats.0.seat', 'E')
      ->assertJsonPath('data.seats.0.user.id', $user->id)
      ->assertJsonPath('data.free_seats', ['N', 'S', 'W'])
      ->assertJsonMissingPath('data.closed_at');

    $this->assertDatabaseHas('table_seats', [
      'table_id' => $response->json('data.id'),
      'user_id' => $user->id,
      'seat' => 'E',
    ]);
  }

  public function test_seated_players_are_shown_without_their_email(): void
  {
    $user = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null]);
    $player = User::factory()->create(['description' => 'Weak twos.']);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $player->id, 'seat' => 'N']);

    $this->actingAs($user)->getJson("/tables/$table->id")
      ->assertOk()
      ->assertJsonPath('data.seats.0.user', [
        'id' => $player->id,
        'name' => $player->name,
        'username' => $player->username,
        'description' => 'Weak twos.',
      ])
      ->assertJsonMissingPath('data.seats.0.user.email');

    $this->actingAs($user)->getJson('/tables')
      ->assertOk()
      ->assertJsonPath('data.0.seats.0.user.username', $player->username)
      ->assertJsonMissingPath('data.0.seats.0.user.email');

    $this->actingAs($user)->postJson("/tables/$table->id/seats", ['seat' => 'E'])
      ->assertCreated()
      ->assertJsonMissingPath('data.seats.0.user.email')
      ->assertJsonMissingPath('data.seats.1.user.email');
  }

  public function test_creator_sits_north_by_default(): void
  {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/tables')
      ->assertCreated()
      ->assertJsonPath('data.name', null)
      ->assertJsonPath('data.seats.0.seat', 'N');
  }

  public function test_invalid_seat_is_rejected(): void
  {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/tables', ['seat' => 'X'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('seat');

    $this->assertDatabaseCount('tables', 0);
  }

  public function test_a_second_create_while_still_seated_returns_409(): void
  {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/tables')->assertCreated();

    $this->actingAs($user)->postJson('/tables')
      ->assertStatus(409)
      ->assertJsonPath('status', 409)
      ->assertJsonPath('data', []);

    $this->assertDatabaseCount('tables', 1);
  }

  public function test_a_user_seated_at_another_table_gets_409(): void
  {
    $user = User::factory()->create();
    $other = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $other->id, 'user_id' => $user->id, 'seat' => 'S']);

    $this->actingAs($user)->postJson('/tables')->assertStatus(409);

    $this->assertDatabaseCount('tables', 1);
  }

  public function test_a_creator_may_keep_three_active_tables_but_not_a_fourth(): void
  {
    $creator = User::factory()->create();

    // each round: create a table, let somebody else sit down, then walk away.
    // the table stays active on their account because a player is still there.
    foreach (User::factory()->count(Table::MAX_ACTIVE_PER_CREATOR)->create() as $other) {
      $id = $this->actingAs($creator)->postJson('/tables')->assertCreated()->json('data.id');

      $this->actingAs($other)->postJson("/tables/$id/seats", ['seat' => 'S'])->assertCreated();
      $this->actingAs($creator)->deleteJson("/tables/$id/seats")->assertOk();
    }

    $this->assertSame(Table::MAX_ACTIVE_PER_CREATOR, $creator->createdTables()->active()->count());

    $this->actingAs($creator)->postJson('/tables')
      ->assertStatus(409)
      ->assertJsonPath('message', 'You already have 3 active tables.');

    $this->assertDatabaseCount('tables', Table::MAX_ACTIVE_PER_CREATOR);
  }

  public function test_a_table_that_emptied_itself_does_not_count_towards_the_limit(): void
  {
    $creator = User::factory()->create();

    for ($i = 0; $i < Table::MAX_ACTIVE_PER_CREATOR + 1; $i++) {
      $id = $this->actingAs($creator)->postJson('/tables')->assertCreated()->json('data.id');

      // nobody else joined, so leaving deletes the table and frees the slot
      $this->actingAs($creator)->deleteJson("/tables/$id/seats")->assertOk();
    }

    $this->assertDatabaseCount('tables', 0);
  }

  public function test_index_returns_tables_newest_first_with_seats_and_free_seats(): void
  {
    $user = User::factory()->create();
    $older = Table::factory()->create(['board_id' => null, 'created_at' => now()->subHour()]);
    TableSeat::factory()->create(['table_id' => $older->id, 'seat' => 'N']);
    $newer = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $newer->id, 'seat' => 'W']);

    $this->actingAs($user)->getJson('/tables')
      ->assertOk()
      ->assertJsonCount(2, 'data')
      ->assertJsonPath('data.0.id', $newer->id)
      ->assertJsonPath('data.0.seats.0.seat', 'W')
      ->assertJsonPath('data.0.free_seats', ['N', 'E', 'S'])
      ->assertJsonStructure(['data' => [['seats' => [['user' => ['id']]]]]])
      ->assertJsonPath('data.1.id', $older->id)
      ->assertJsonMissingPath('data.0.closed_at');
  }

  public function test_show_returns_seats_and_free_seats(): void
  {
    $user = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null]);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'N']);
    TableSeat::factory()->create(['table_id' => $table->id, 'seat' => 'S']);

    $this->actingAs($user)->getJson("/tables/$table->id")
      ->assertOk()
      ->assertJsonPath('data.id', $table->id)
      ->assertJsonCount(2, 'data.seats')
      ->assertJsonStructure(['data' => ['seats' => [['user' => ['id']]]]])
      ->assertJsonPath('data.free_seats', ['E', 'W']);
  }
}
