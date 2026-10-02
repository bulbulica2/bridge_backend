<?php

namespace Tests\Feature\Table;

use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Models\Table;
use App\Models\User;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A board is dealt once the table is full and every human seated there has
 * pressed Start (`POST /tables/{table}/start`); robots are ready from the
 * moment they sit down. Filling the table deals nothing by itself.
 */
class StartBoardTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->seats = app(TableSeatService::class);
  }

  public function test_creating_a_table_with_robots_deals_nothing(): void
  {
    $creator = User::factory()->create();

    $id = $this->actingAs($creator)->postJson('/tables', ['robots' => true])
      ->assertCreated()
      ->assertJsonPath('data.free_seats', [])
      ->assertJsonPath('data.board_id', null)
      ->assertJsonPath('data.playing', null)
      ->json('data.id');

    $this->assertDatabaseCount('board_table', 0);

    // the creator's one Start deals: the robots are ready already
    $this->actingAs($creator)->postJson("/tables/$id/start")
      ->assertOk()
      ->assertJsonPath('message', 'Board dealt.')
      ->assertJsonPath('data.playing.my_seat', 'N');

    $this->assertNotNull(Table::findOrFail($id)->board_id);
  }

  public function test_joining_the_fourth_seat_deals_nothing(): void
  {
    $table = $this->tableWith(['N', 'E', 'S']);
    $this->startBoard($table);

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/$table->id/seats", ['seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.free_seats', [])
      ->assertJsonPath('data.board_id', null)
      ->assertJsonPath('data.playing', null);

    $this->assertDatabaseCount('board_table', 0);
  }

  public function test_a_manager_seating_the_fourth_player_deals_nothing(): void
  {
    $table = $this->tableWith(['N', 'E', 'S']);
    $this->startBoard($table);
    $manager = User::findOrFail($table->moderated_by);

    $this->actingAs($manager)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => User::factory()->create()->id, 'seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.board_id', null);

    $this->assertDatabaseCount('board_table', 0);
  }

  public function test_a_robot_in_the_fourth_seat_deals_nothing_until_every_human_has_pressed_start(): void
  {
    $table = $this->tableWith(['N', 'E', 'S']);
    $manager = User::findOrFail($table->moderated_by);

    $this->actingAs($manager)
      ->postJson("/tables/$table->id/seats/robots", ['seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.board_id', null)
      ->assertJsonPath('data.playing', null);

    $this->assertDatabaseCount('board_table', 0);
  }

  public function test_every_human_pressing_start_deals_the_board(): void
  {
    Event::fake([PlayingUpdated::class, HandDealt::class]);

    $table = $this->tableWith(['N', 'E', 'S', 'W']);
    $players = $this->players($table);

    foreach (['N', 'E', 'S'] as $seat) {
      $this->start($table, $players[$seat])
        ->assertOk()
        ->assertJsonPath('message', 'Ready: waiting for the other players.')
        ->assertJsonPath('data.board_id', null)
        ->assertJsonPath('data.playing', null);
    }

    Event::assertNotDispatched(PlayingUpdated::class);

    $response = $this->start($table, $players['W'])
      ->assertOk()
      ->assertJsonPath('message', 'Board dealt.')
      ->assertJsonPath('data.playing.phase', 'auction')
      ->assertJsonPath('data.playing.my_seat', 'W')
      ->assertJsonCount(13, 'data.playing.hand');

    $this->assertNotNull($response->json('data.board_id'));
    $this->assertSame(
      $this->actingAs($players['W'])->getJson("/tables/$table->id/playing")->json('data'),
      $response->json('data.playing')
    );

    Event::assertDispatchedTimes(PlayingUpdated::class, 1);
    Event::assertDispatchedTimes(HandDealt::class, 4);

    // a Start deals one board: nobody human is ready any more
    $this->assertSame(0, $table->seats()->whereNotNull('ready_at')->count());
  }

  public function test_start_pressed_before_the_table_is_full_is_kept(): void
  {
    $table = $this->tableWith(['N', 'E']);
    $this->startBoard($table);

    $this->actingAs(User::findOrFail($table->moderated_by))
      ->postJson("/tables/$table->id/seats/robots", ['seat' => 'S'])
      ->assertCreated();

    $this->assertNull($table->fresh()->board_id);

    // every human has asked by the time the fourth seat goes to a robot
    $this->actingAs(User::findOrFail($table->moderated_by))
      ->postJson("/tables/$table->id/seats/robots", ['seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.playing.phase', 'auction');

    $this->assertNotNull($table->fresh()->board_id);
  }

  public function test_a_newcomer_still_has_to_press_start(): void
  {
    $table = $this->tableWith(['N', 'E', 'S']);
    $this->startBoard($table);

    $newcomer = User::factory()->create();
    $this->seats->seat($table, $newcomer, 'W');

    $this->assertNull($table->fresh()->board_id);

    $this->start($table, $newcomer)->assertJsonPath('message', 'Board dealt.');
  }

  public function test_the_table_payload_shows_who_has_pressed_start(): void
  {
    $table = $this->tableWith(['N', 'E']);
    $players = $this->players($table);

    $this->start($table, $players['E'])->assertOk();

    foreach (["/tables/$table->id", '/tables'] as $url) {
      $seats = collect($this->actingAs($players['N'])->getJson($url)->assertOk()->json(
        $url === '/tables' ? 'data.0.seats' : 'data.seats'
      ))->keyBy('seat');

      $this->assertFalse($seats['N']['ready']);
      $this->assertNull($seats['N']['ready_at']);
      $this->assertTrue($seats['E']['ready']);
      $this->assertNotNull($seats['E']['ready_at']);
    }
  }

  public function test_pressing_twice_changes_nothing_and_start_can_be_taken_back(): void
  {
    $table = $this->tableWith(['N', 'E']);
    $player = $this->players($table)['N'];

    $this->start($table, $player)->assertOk();
    $readyAt = $table->seats()->where('user_id', $player->id)->value('ready_at');

    $this->travel(1)->minutes();
    $this->start($table, $player)->assertOk();
    $this->assertEquals($readyAt, $table->seats()->where('user_id', $player->id)->value('ready_at'));

    $this->actingAs($player)->deleteJson("/tables/$table->id/start")
      ->assertOk()
      ->assertJsonPath('message', 'Start withdrawn.')
      ->assertJsonPath('data.seats.0.ready', false);

    // taking back a Start not pressed is fine too
    $this->actingAs($player)->deleteJson("/tables/$table->id/start")->assertOk();
  }

  public function test_leaving_kicking_and_idling_out_clear_start(): void
  {
    $table = $this->tableWith(['N', 'E', 'S']);
    $players = $this->players($table);
    $this->startBoard($table);

    // the leaver's Start goes with them: whoever sits there next must press
    $this->actingAs($players['E'])->deleteJson("/tables/$table->id/seats")->assertOk();
    $this->seats->seat($table, $players['E'], 'E');
    $this->assertNull($table->seats()->where('user_id', $players['E']->id)->value('ready_at'));

    // kicked
    $this->actingAs($players['N'])->deleteJson("/tables/$table->id/seats/{$players['S']->id}")->assertOk();
    $this->seats->seat($table, $players['S'], 'S');
    $this->assertNull($table->seats()->where('user_id', $players['S']->id)->value('ready_at'));

    // released as idle
    $this->start($table, $players['E'])->assertOk();
    $this->travel(config('bridge.idle_seat_minutes') + 1)->minutes();
    $this->seats->touch($table, $players['N']);
    $this->seats->touch($table, $players['S']);
    $this->artisan('tables:release-idle-seats')->assertSuccessful();

    $this->assertFalse($table->seats()->where('user_id', $players['E']->id)->exists());

    // the one who never left keeps theirs
    $this->assertNotNull($table->seats()->where('user_id', $players['N']->id)->value('ready_at'));
  }

  public function test_a_board_in_progress_is_a_409(): void
  {
    $table = $this->tableWith(['N', 'E', 'S', 'W']);
    $this->startBoard($table);

    $player = $this->players($table)['N'];

    $this->start($table, $player)
      ->assertStatus(409)
      ->assertJsonPath('message', 'A board is already in progress at this table.');

    $this->actingAs($player)->deleteJson("/tables/$table->id/start")
      ->assertStatus(409)
      ->assertJsonPath('message', 'A board is already in progress at this table.');
  }

  public function test_an_abandoned_board_needs_start_again(): void
  {
    $table = $this->tableWith(['N', 'E', 'S', 'W']);
    $players = $this->players($table);
    $first = $this->startBoard($table);

    $this->seats->remove($table, $players['W']);
    $this->seats->seat($table, $players['W'], 'W');

    $this->assertNull($table->fresh()->board_id);
    $this->assertSame(0, $table->seats()->whereNotNull('ready_at')->count());

    $second = $this->startBoard($table);

    $this->assertNotNull($second);
    $this->assertNotSame($first->board_id, $second->board_id);
  }

  public function test_only_seated_players_may_press_start(): void
  {
    $table = $this->tableWith(['N']);

    $this->postJson("/tables/$table->id/start")->assertUnauthorized();
    $this->deleteJson("/tables/$table->id/start")->assertUnauthorized();

    $outsider = User::factory()->create();
    $this->start($table, $outsider)->assertForbidden();
    $this->actingAs($outsider)->deleteJson("/tables/$table->id/start")->assertForbidden();

    // an admin manages the table but plays no seat there
    $this->start($table, User::factory()->isAdmin()->create())->assertForbidden();

    $this->actingAs($outsider)->postJson('/tables/999999/start')->assertNotFound();
  }

  /**
   * A table its first player created, with fresh players in `$seats`.
   *
   * @param  list<string>  $seats
   */
  private function tableWith(array $seats): Table
  {
    $first = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null, 'created_by' => $first->id, 'moderated_by' => $first->id]);

    foreach ($seats as $index => $seat) {
      $this->seats->seat($table, $index === 0 ? $first : User::factory()->create(), $seat);
    }

    return $table;
  }

  /**
   * @return array<string, User>
   */
  private function players(Table $table): array
  {
    return $table->seats()->with('user')->get()->mapWithKeys(fn ($seat) => [$seat->seat => $seat->user])->all();
  }

  private function start(Table $table, User $user): TestResponse
  {
    return $this->actingAs($user)->postJson("/tables/$table->id/start");
  }
}
