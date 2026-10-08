<?php

namespace Tests\Feature\Table;

use App\auxiliary\Seats;
use App\Models\Table;
use App\Models\TableSet;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * No kick in the middle of a set (`TableSeatService::kick()`): while a set
 * is running, during a board or between two of its boards, the moderator
 * may remove nobody, robots included (409). A quit, an admin's kick and a
 * kick of a robot from an unattended table stay as they were; between sets
 * the moderator kicks as before.
 */
class KickMidSetTest extends TestCase
{
  use RefreshDatabase;

  private const REFUSED = "You can't remove a player in the middle of a set: wait until it is over.";

  private Table $table;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $seats = app(TableSeatService::class);
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      $seats->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->update(['moderated_by' => $this->players['N']->id]);
  }

  public function test_before_any_set_the_moderator_kicks(): void
  {
    $this->kick($this->players['N'], 'E')
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table.')
      ->assertJsonPath('data.free_seats', ['E']);
  }

  public function test_during_a_board_the_moderator_kicks_nobody(): void
  {
    $this->startBoard($this->table);

    $this->kick($this->players['N'], 'E')
      ->assertStatus(409)
      ->assertJsonPath('message', self::REFUSED);

    $this->assertSame($this->players['E']->id, $this->table->seats()->where('seat', 'E')->value('user_id'));
    $this->assertNull(TableSet::sole()->finished_at);
    $this->assertNotNull(app(PlayingStateService::class)->currentPlaying($this->table->refresh()));
  }

  public function test_during_a_board_the_moderator_kicks_no_robot(): void
  {
    $this->kick($this->players['N'], 'E')->assertOk();
    app(RobotService::class)->seatRobot($this->table, 'E', $this->players['N']);
    $robot = $this->table->seats()->where('seat', 'E')->sole()->user;
    $this->startBoard($this->table);

    $this->actingAs($this->players['N'])->deleteJson("/tables/{$this->table->id}/seats/{$robot->id}")
      ->assertStatus(409)
      ->assertJsonPath('message', self::REFUSED);

    $this->assertSame($robot->id, $this->table->seats()->where('seat', 'E')->value('user_id'));
  }

  public function test_between_two_boards_of_a_set_the_moderator_kicks_nobody(): void
  {
    $this->startBoard($this->table);
    $this->finishBoard();

    $this->kick($this->players['N'], 'E')
      ->assertStatus(409)
      ->assertJsonPath('message', self::REFUSED);

    $this->assertNull(TableSet::sole()->finished_at);
  }

  public function test_after_the_sets_last_board_the_moderator_kicks(): void
  {
    config(['bridge.set_size' => 1]);
    $this->startBoard($this->table);
    $this->finishBoard();
    $this->assertSame(TableSet::ENDED_COMPLETED, TableSet::sole()->ended);

    $this->kick($this->players['N'], 'E')
      ->assertOk()
      ->assertJsonPath('data.free_seats', ['E']);
  }

  public function test_an_admins_kick_mid_set_abandons_it_as_before(): void
  {
    $this->startBoard($this->table);

    $this->kick(User::factory()->create(['is_admin' => true]), 'E')
      ->assertOk()
      ->assertJsonPath('message', 'Player removed from the table.')
      ->assertJsonPath('data.set.ended', TableSet::ENDED_ABANDONED)
      ->assertJsonPath('data.free_seats', ['E']);
  }

  public function test_a_quit_mid_set_holds_the_seat_as_before(): void
  {
    $this->startBoard($this->table);

    $this->kick($this->players['E'], 'E')->assertStatus(202);

    $this->assertNotNull($this->table->seats()->where('seat', 'E')->sole()->away_since);
  }

  public function test_anyone_kicks_a_robot_from_an_unattended_table_mid_set(): void
  {
    $robots = app(RobotService::class);

    foreach (['E', 'S', 'W'] as $seat) {
      $this->kick($this->players['N'], $seat)->assertOk();
      $robots->seatRobot($this->table, $seat, $this->players['N']);
    }

    $this->startBoard($this->table);

    // the last human gone without ending the set: only robots keep it
    $this->table->seats()->where('seat', 'N')->delete();
    $this->table->update(['moderated_by' => null, 'unattended_since' => now()]);
    $this->assertNull(TableSet::sole()->finished_at);
    $robot = $this->table->seats()->where('seat', 'E')->sole()->user;

    $this->actingAs(User::factory()->create())->deleteJson("/tables/{$this->table->id}/seats/{$robot->id}")
      ->assertOk()
      ->assertJsonPath('data.free_seats', ['N', 'E']);
  }

  private function kick(User $by, string $seat): TestResponse
  {
    return $this->actingAs($by)->deleteJson("/tables/{$this->table->id}/seats/{$this->players[$seat]->id}");
  }

  /**
   * Pass the board on the table out.
   */
  private function finishBoard(): void
  {
    $playing = app(PlayingStateService::class)->currentPlaying($this->table->refresh());
    $playing->update(['auction_ended_at' => now()]);
    $playing->refresh()->finish(null);
  }
}
