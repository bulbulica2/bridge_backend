<?php

namespace Tests\Feature\Table;

use App\Models\Table;
use App\Models\TableSeat;
use App\Models\User;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Robots at a table: who may seat and kick them, the unattended table they
 * keep once the last human leaves, and the places they are left out of.
 */
class RobotSeatingTest extends TestCase
{
  use RefreshDatabase;

  private User $owner;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    config(['bridge.unattended_table_minutes' => 10]);

    $this->owner = User::factory()->create();
  }

  public function test_a_table_created_with_robots_is_full_but_not_dealt(): void
  {
    $response = $this->actingAs($this->owner)
      ->postJson('/tables', ['seat' => 'S', 'robots' => true])
      ->assertCreated()
      ->assertJsonPath('data.free_seats', [])
      ->assertJsonPath('data.moderated_by', $this->owner->id)
      ->assertJsonCount(4, 'data.seats');

    $seats = collect($response->json('data.seats'))->keyBy('seat');

    $this->assertSame($this->owner->id, $seats['S']['user']['id']);
    $this->assertFalse($seats['S']['user']['is_robot']);

    foreach (['N', 'E', 'W'] as $seat) {
      $this->assertTrue($seats[$seat]['user']['is_robot']);
      $this->assertMatchesRegularExpression('/^robot-\d+$/', $seats[$seat]['user']['username']);
      $this->assertArrayNotHasKey('email', $seats[$seat]['user']);
    }

    // robots are ready at once; the creator has still to press Start
    foreach (['N', 'E', 'W'] as $seat) {
      $this->assertTrue($seats[$seat]['ready']);
    }

    $this->assertFalse($seats['S']['ready']);
    $this->assertNull($response->json('data.board_id'));
    $this->assertNull($response->json('data.playing'));

    $this->actingAs($this->owner)->getJson('/tables/'.$response->json('data.id').'/playing')
      ->assertOk()
      ->assertJsonPath('data.phase', 'waiting');
  }

  public function test_the_creators_one_start_deals_at_a_robot_table_and_carries_their_game_state(): void
  {
    $id = $this->actingAs($this->owner)
      ->postJson('/tables', ['seat' => 'S', 'robots' => true])
      ->assertCreated()
      ->json('data.id');

    $response = $this->actingAs($this->owner)
      ->postJson("/tables/$id/start")
      ->assertOk()
      ->assertJsonPath('message', 'Board dealt.')
      ->assertJsonPath('data.board_id', Table::findOrFail($id)->board_id)
      ->assertJsonPath('data.playing.my_seat', 'S')
      ->assertJsonCount(13, 'data.playing.hand');

    // exactly what the client would otherwise fetch next, robots' calls
    // included (the queue runs on sync here)
    $this->assertSame(
      $this->actingAs($this->owner)->getJson('/tables/'.$id.'/playing')->json('data'),
      $response->json('data.playing')
    );
  }

  public function test_without_robots_the_creator_sits_alone(): void
  {
    $this->actingAs($this->owner)->postJson('/tables', ['robots' => false])
      ->assertCreated()
      ->assertJsonCount(1, 'data.seats')
      ->assertJsonPath('data.board_id', null)
      ->assertJsonPath('data.playing', null);

    $this->assertSame(0, User::robots()->count());
  }

  public function test_a_manager_seats_a_robot(): void
  {
    $table = $this->tableOf($this->owner);

    $response = $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])
      ->assertCreated()
      ->assertJsonPath('message', 'Robot seated successfully.')
      ->assertJsonPath('data.free_seats', ['S', 'W']);

    $this->assertTrue(collect($response->json('data.seats'))->firstWhere('seat', 'E')['user']['is_robot']);

    // an admin manages every table
    $this->actingAs(User::factory()->isAdmin()->create())
      ->postJson("/tables/$table->id/seats/robots", ['seat' => 'S'])
      ->assertCreated();
  }

  public function test_the_fourth_seat_taken_by_a_robot_deals_once_every_human_pressed_start(): void
  {
    $table = $this->tableOf($this->owner);
    $this->startBoard($table);

    foreach (['E', 'S'] as $seat) {
      $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => $seat])->assertCreated();
    }

    $this->assertNull($table->fresh()->board_id);

    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.playing.phase', 'auction')
      ->assertJsonPath('data.playing.my_seat', 'N');

    $this->assertNotNull($table->fresh()->board_id);
  }

  public function test_only_a_manager_seats_a_robot(): void
  {
    $table = $this->tableOf($this->owner);
    $player = User::factory()->create();
    app(TableSeatService::class)->seat($table, $player, 'S');

    $this->actingAs($player)->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])
      ->assertForbidden()
      ->assertJsonPath('message', 'Only the table moderator or an admin can seat a robot.');

    $this->assertSame(2, $table->seats()->count());
  }

  public function test_a_guest_gets_401(): void
  {
    $table = Table::factory()->create(['board_id' => null]);

    $this->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])->assertUnauthorized();
  }

  public function test_a_taken_seat_is_a_409_and_a_bad_one_a_422(): void
  {
    $table = $this->tableOf($this->owner);

    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'N'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'Seat N is already taken.');

    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'X'])
      ->assertStatus(422)
      ->assertJsonValidationErrors('seat');

    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots")
      ->assertStatus(422)
      ->assertJsonValidationErrors('seat');
  }

  public function test_robots_come_from_a_pool_one_table_each(): void
  {
    $first = $this->tableOf($this->owner);
    $this->actingAs($this->owner)->postJson("/tables/$first->id/seats/robots", ['seat' => 'E'])->assertCreated();

    $other = User::factory()->create();
    $second = $this->tableOf($other);
    $this->actingAs($other)->postJson("/tables/$second->id/seats/robots", ['seat' => 'E'])->assertCreated();

    // the first robot was busy, so a second one was made
    $this->assertSame(['robot-1', 'robot-2'], User::robots()->orderBy('id')->pluck('username')->all());

    // a robot sent away goes back to the pool and is the next one seated
    $robot = $first->seats()->where('seat', 'E')->firstOrFail()->user;
    $this->actingAs($this->owner)->deleteJson("/tables/$first->id/seats/$robot->id")->assertOk();
    $this->actingAs($this->owner)->postJson("/tables/$first->id/seats/robots", ['seat' => 'W'])->assertCreated();

    $this->assertSame($robot->id, $first->seats()->where('seat', 'W')->value('user_id'));
    $this->assertSame(2, User::robots()->count());
  }

  public function test_a_new_robot_skips_a_name_already_taken(): void
  {
    // two busy robots, robot-1 and robot-3: the count says robot-3 is next,
    // which is taken (as if a concurrent request had just made it)
    foreach ([1, 3] as $number) {
      $robot = User::factory()->robot()->create(['username' => "robot-$number", 'email' => "robot-$number@robots.invalid"]);
      TableSeat::factory()->create(['user_id' => $robot->id]);
    }

    $table = $this->tableOf($this->owner);
    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])->assertCreated();

    $this->assertSame('robot-4', $table->seats()->where('seat', 'E')->firstOrFail()->user->username);
  }

  public function test_with_a_human_at_the_table_only_a_manager_kicks_a_robot_and_not_mid_set(): void
  {
    $table = $this->robotTable(start: false);
    $robot = $table->seats()->where('seat', 'E')->firstOrFail()->user;

    $this->actingAs(User::factory()->create())
      ->deleteJson("/tables/$table->id/seats/$robot->id")
      ->assertForbidden();

    $this->actingAs($this->owner)
      ->deleteJson("/tables/$table->id/seats/$robot->id")
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table.')
      ->assertJsonPath('data.free_seats', ['E']);

    // once the set is under way, the robot plays it to the end
    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])->assertCreated();
    $this->actingAs($this->owner)->postJson("/tables/$table->id/start")->assertOk();
    $robot = $table->seats()->where('seat', 'E')->firstOrFail()->user;

    $this->actingAs($this->owner)
      ->deleteJson("/tables/$table->id/seats/$robot->id")
      ->assertStatus(409)
      ->assertJsonPath('message', "You can't remove a player in the middle of a set: wait until it is over.");
    $this->assertSame($robot->id, $table->seats()->where('seat', 'E')->value('user_id'));
  }

  public function test_the_last_human_leaving_leaves_the_table_unattended(): void
  {
    $table = $this->robotTable();

    // mid-set, Leave holds the seat for two minutes; with no other human
    // for a robot to play with, the set is then abandoned
    $this->actingAs($this->owner)->deleteJson("/tables/$table->id/seats")
      ->assertStatus(202)
      ->assertJsonPath('data.moderated_by', $this->owner->id);

    $this->travel(config('bridge.away_replace_seconds'))->seconds();
    $this->artisan('tables:check-away')->assertSuccessful();

    $table->refresh();
    $this->assertNull($table->moderated_by);
    $this->assertSame(['N'], $table->freeSeats());
    $this->assertNotNull($table->unattended_since);
    $this->assertSame(3, $table->seats()->count());
    $this->assertSame('abandoned', $table->sets()->sole()->ended);
    // the board in progress was abandoned, as when anyone leaves mid-board
    $this->assertNull($table->board_id);
  }

  public function test_a_human_leaving_a_table_with_no_set_leaves_it_unattended_at_once(): void
  {
    $id = $this->actingAs($this->owner)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');

    $this->actingAs($this->owner)->deleteJson("/tables/$id/seats")
      ->assertOk()
      ->assertJsonPath('data.moderated_by', null)
      ->assertJsonPath('data.free_seats', ['N']);

    $this->assertNotNull(Table::findOrFail($id)->unattended_since);
  }

  public function test_anyone_may_kick_a_robot_from_an_unattended_table_and_the_last_one_deletes_it(): void
  {
    $table = $this->robotTable();
    $this->leaveForGood($table);

    $passerby = User::factory()->create();
    $robots = $table->seats()->with('user')->get()->pluck('user');

    foreach ($robots->slice(0, 2) as $robot) {
      $this->actingAs($passerby)->deleteJson("/tables/$table->id/seats/$robot->id")->assertOk();
    }

    $this->assertNotNull($table->fresh()->unattended_since);

    $this->actingAs($passerby)->deleteJson("/tables/{$table->id}/seats/{$robots->last()->id}")
      ->assertOk()
      ->assertJsonPath('data.table_deleted', true);

    $this->assertModelMissing($table);

    // a human can't be kicked by a passer-by, unattended or not
    $human = User::factory()->create();
    $other = $this->tableOf($human);
    $this->actingAs($passerby)->deleteJson("/tables/$other->id/seats/$human->id")->assertForbidden();
  }

  public function test_moderation_passes_only_to_humans(): void
  {
    $table = $this->tableOf($this->owner);

    // the robot joins before the other human
    $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])->assertCreated();
    $human = User::factory()->create();
    app(TableSeatService::class)->seat($table, $human, 'S');

    $this->actingAs($this->owner)->deleteJson("/tables/$table->id/seats")->assertOk();

    $this->assertSame($human->id, $table->fresh()->moderated_by);
    $this->assertNull($table->fresh()->unattended_since);
  }

  public function test_the_first_human_to_sit_at_an_unattended_table_runs_it(): void
  {
    $table = $this->robotTable();
    $this->leaveForGood($table);

    $newcomer = User::factory()->create();

    $this->actingAs($newcomer)->postJson("/tables/$table->id/seats", ['seat' => 'N'])
      ->assertCreated()
      ->assertJsonPath('data.moderated_by', $newcomer->id)
      ->assertJsonPath('data.unattended_since', null)
      ->assertJsonPath('data.can_manage', true);

    // and robots there are theirs to kick
    $robot = $table->seats()->where('seat', 'E')->firstOrFail()->user;
    $this->actingAs(User::factory()->create())->deleteJson("/tables/$table->id/seats/$robot->id")->assertForbidden();
    $this->actingAs($newcomer)->deleteJson("/tables/$table->id/seats/$robot->id")->assertOk();
  }

  public function test_an_unattended_table_is_deleted_after_ten_minutes(): void
  {
    $table = $this->robotTable();
    $this->leaveForGood($table);

    $this->travel(9)->minutes();
    $this->artisan('tables:delete-unattended')->expectsOutput('Deleted 0 unattended tables.')->assertSuccessful();
    $this->assertModelExists($table);

    $this->travel(2)->minutes();
    $this->artisan('tables:delete-unattended')->expectsOutput('Deleted 1 unattended table.')->assertSuccessful();

    $this->assertModelMissing($table);
    // its robots are free for the next table
    $this->assertSame(0, TableSeat::count());
  }

  public function test_a_human_sitting_down_saves_an_unattended_table(): void
  {
    $table = $this->robotTable();
    $this->leaveForGood($table);

    $this->travel(9)->minutes();
    app(TableSeatService::class)->seat($table, User::factory()->create(), 'N');

    $this->travel(5)->minutes();
    $this->assertSame(0, app(TableSeatService::class)->deleteUnattendedTables());
    $this->assertModelExists($table);
  }

  public function test_the_unattended_sweep_is_scheduled(): void
  {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    $this->assertStringContainsString('tables:delete-unattended', $commands);
  }

  public function test_the_idle_sweep_frees_humans_but_never_robots(): void
  {
    // full but not started: no set, so the idle rule applies
    $table = Table::findOrFail($this->actingAs($this->owner)->postJson('/tables', ['robots' => true])->json('data.id'));

    $this->travel(20)->minutes();

    $this->assertSame(1, app(TableSeatService::class)->releaseIdleSeats());

    $table->refresh();
    $this->assertFalse($table->seats()->where('user_id', $this->owner->id)->exists());
    $this->assertSame(3, $table->seats()->count());
    $this->assertNotNull($table->unattended_since);
  }

  public function test_search_leaves_robots_out(): void
  {
    $this->robotTable();
    User::factory()->create(['username' => 'robotics-fan']);

    $this->actingAs($this->owner)->getJson('/users?search=robot')
      ->assertOk()
      ->assertJsonCount(1, 'data')
      ->assertJsonPath('data.0.username', 'robotics-fan')
      ->assertJsonPath('data.0.is_robot', false);
  }

  public function test_a_robot_cannot_log_in(): void
  {
    // even one whose password somebody knows
    $robot = User::factory()->robot()->create(['password' => Hash::make('guessed')]);

    $this->postJson('/login', ['email' => $robot->email, 'password' => 'guessed'])
      ->assertStatus(422)
      ->assertJsonValidationErrors('email');

    $this->assertGuest();
  }

  public function test_nobody_can_register_a_robot_username(): void
  {
    $this->post('/register', [
      'name' => 'Sneaky',
      'username' => 'Robot-7',
      'email' => 'sneaky@example.com',
      'password' => 'password',
      'password_confirmation' => 'password',
    ], ['Accept' => 'application/json'])
      ->assertStatus(422)
      ->assertJsonValidationErrors('username');
  }

  public function test_unattended_tables_do_not_count_towards_the_creator_limit(): void
  {
    // each round: a table with robots, left to them
    for ($i = 0; $i < Table::MAX_ACTIVE_PER_CREATOR + 1; $i++) {
      $id = $this->actingAs($this->owner)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');
      $this->actingAs($this->owner)->deleteJson("/tables/$id/seats")->assertOk();
    }

    $this->assertSame(Table::MAX_ACTIVE_PER_CREATOR + 1, $this->owner->createdTables()->active()->count());
    $this->assertSame(0, $this->owner->createdTables()->attended()->count());
  }

  public function test_a_robot_does_not_count_as_somebody_else_at_the_table(): void
  {
    // a table the creator left to another human counts; one left to robots doesn't
    $human = User::factory()->create();

    for ($i = 0; $i < Table::MAX_ACTIVE_PER_CREATOR; $i++) {
      $table = $this->tableOf($this->owner);
      $this->actingAs($this->owner)->postJson("/tables/$table->id/seats/robots", ['seat' => 'E'])->assertCreated();

      if ($i === 0) {
        app(TableSeatService::class)->seat($table, $human, 'S');
      }

      $this->actingAs($this->owner)->deleteJson("/tables/$table->id/seats")->assertOk();
    }

    $this->assertSame(1, $this->owner->createdTables()->attended()->count());
    $this->actingAs($this->owner)->postJson('/tables')->assertCreated();
  }

  /**
   * A table `$creator` made and sits at (N), with no board yet.
   */
  private function tableOf(User $creator): Table
  {
    $id = $this->actingAs($creator)->postJson('/tables')->assertCreated()->json('data.id');

    return Table::findOrFail($id);
  }

  /**
   * The owner leaves `$table` for good. Mid-set a Leave only holds the seat,
   * so they go as a kick or running out of time would take them out.
   */
  private function leaveForGood(Table $table): void
  {
    app(TableSeatService::class)->remove($table, $this->owner);
  }

  /**
   * The owner at N and robots in the other three seats, board dealt by the
   * owner's Start.
   */
  private function robotTable(bool $start = true): Table
  {
    $id = $this->actingAs($this->owner)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');

    if ($start) {
      $this->actingAs($this->owner)->postJson("/tables/$id/start")->assertOk();
    }

    return Table::findOrFail($id);
  }
}
