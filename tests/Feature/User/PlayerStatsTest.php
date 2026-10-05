<?php

namespace Tests\Feature\User;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSet;
use App\Models\TableSetSeat;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A player's stats (`GET /users/{user}/stats`, `GET /api/user/stats`): the
 * boards they finished and how they scored against the other tables, the
 * sets they saw through and won, and the sets they walked out on — a robot
 * taking their seat, or the set ending `abandoned` as they went.
 */
class PlayerStatsTest extends TestCase
{
  use RefreshDatabase;

  private User $me;

  protected function setUp(): void
  {
    parent::setUp();

    $this->me = User::factory()->create();
  }

  public function test_a_user_with_no_boards_gets_zeros_and_null_percentages(): void
  {
    $this->actingAs($this->me)->getJson("/users/{$this->me->id}/stats")
      ->assertOk()
      ->assertJsonPath('message', 'Stats retrieved successfully.')
      ->assertExactJson([
        'status' => 200,
        'message' => 'Stats retrieved successfully.',
        'data' => [
          'user_id' => $this->me->id,
          'boards' => ['played' => 0, 'compared' => 0, 'won' => 0, 'win_rate' => null, 'average_percent' => null],
          'sets' => ['played' => 0, 'won' => 0, 'win_rate' => null, 'average_percent' => null],
          'leaving' => [
            'abandoned' => 0,
            'abandoned_by_reason' => ['turn_timeout' => 0, 'set_time' => 0, 'away' => 0, 'moved' => 0, 'kicked' => 0, 'left' => 0],
            'left_rate' => null,
          ],
        ],
      ]);
  }

  public function test_every_finished_board_is_played_but_only_compared_ones_count_towards_won_and_the_average(): void
  {
    // N-S, against a worse N-S score: every matchpoint
    $this->playing($first = Board::factory()->create(), 420, ['N' => $this->me]);
    $this->playing($first, -50);

    // E-W, level with one table and worse than another for N-S: 3 of 4
    $this->playing($second = Board::factory()->create(), 100, ['E' => $this->me]);
    $this->playing($second, 100);
    $this->playing($second, 200);

    // passed out, and nobody else has played it
    $this->playing(Board::factory()->create(), 0, ['S' => $this->me]);

    // level: half, which is not a win
    $this->playing($fourth = Board::factory()->create(), 300, ['W' => $this->me]);
    $this->playing($fourth, 300);

    // nothing
    $this->playing($fifth = Board::factory()->create(), -100, ['N' => $this->me]);
    $this->playing($fifth, 0);

    // unfinished, or finished by the robot that took their hand over: not theirs
    $this->playing($sixth = Board::factory()->create(), null, ['N' => $this->me]);
    $this->playing($sixth, 50);
    $robot = User::factory()->robot()->create();
    $this->playing($seventh = Board::factory()->create(), 50, ['N' => $robot])
      ->seats()->where('seat', 'N')->update(['replaced_user_id' => $this->me->id]);
    $this->playing($seventh, 0);

    $this->stats($this->me)->assertJsonPath('data.boards', [
      'played' => 5,
      'compared' => 4,
      'won' => 2,
      'win_rate' => 0.5,
      // (100 + 75 + 50 + 0) / 4
      'average_percent' => 56.25,
    ]);

    // the robot finished it, and its stats say so
    $this->stats($robot)->assertJsonPath('data.boards.played', 1)->assertJsonPath('data.boards.won', 1);
  }

  public function test_completed_sets_count_won_by_total_score_and_a_robots_replacement_counts_for_the_partner_only(): void
  {
    $partner = User::factory()->create();

    // N-S win it on total score; one board compared (every matchpoint), the other not
    $won = $this->set(['N' => $this->me, 'S' => $partner]);
    $this->playing($compared = Board::factory()->create(), 420, set: $won);
    $this->playing($compared, -50);
    $this->playing(Board::factory()->create(), -100, set: $won);

    // N-S win it, and they were E-W; nothing to compare
    $this->playing(Board::factory()->create(), 200, set: $this->set(['E' => $this->me]));

    // level on total score: no winner; E-W got 1 of 4 matchpoints
    $this->playing($level = Board::factory()->create(), 0, set: $this->set(['W' => $this->me]));
    $this->playing($level, -50);
    $this->playing($level, 0);

    // abandoned: not played
    $this->playing(Board::factory()->create(), 500, set: $this->set(['N' => $this->me], TableSet::ENDED_ABANDONED));

    // a robot took over from them and their partner won it with the robot
    $robot = User::factory()->robot()->create();
    $replaced = $this->set(['N' => $partner, 'S' => $robot]);
    $replaced->seats()->where('seat', 'S')->update([
      'replaced_user_id' => $this->me->id,
      'replaced_reason' => TableSetSeat::REASON_TURN_TIMEOUT,
      'replaced_at' => now(),
    ]);
    $this->playing(Board::factory()->create(), 100, set: $replaced);

    $this->stats($this->me)
      ->assertJsonPath('data.sets', [
        'played' => 3,
        'won' => 1,
        'win_rate' => 0.3333,
        // (100 + 25) / 2: the set with nothing to compare has no percentage
        'average_percent' => 62.5,
      ])
      ->assertJsonPath('data.leaving.abandoned', 1)
      ->assertJsonPath('data.leaving.abandoned_by_reason.turn_timeout', 1)
      // 1 of 3 played + 1 abandoned
      ->assertJsonPath('data.leaving.left_rate', 0.25);

    $this->stats($partner)
      ->assertJsonPath('data.sets', ['played' => 2, 'won' => 2, 'win_rate' => 1, 'average_percent' => 100])
      ->assertJsonPath('data.leaving.abandoned', 0)
      ->assertJsonPath('data.leaving.left_rate', 0);
  }

  public function test_running_out_of_time_moving_and_being_kicked_while_away_each_count_as_an_abandon(): void
  {
    [$table, $players] = $this->tableMidSet();
    $seats = app(TableSeatService::class);
    $turn = $this->turn($table);
    $late = $players[$turn];
    $mover = $players[Seats::next($turn)];
    $kicked = $players[Seats::partner(Seats::next($turn))];

    // on turn and out of time, while there
    $this->travel(60)->seconds();
    foreach ($players as $player) {
      $seats->touch($table, $player);
    }
    $seats->checkAway();

    // off to another table
    $this->actingAs($mover)->postJson('/tables/'.Table::factory()->create(['board_id' => null])->id.'/seats', ['seat' => 'N'])->assertCreated();

    // away, then kicked by the one human left
    $moderator = $players[Seats::partner($turn)];
    $table->update(['moderated_by' => $moderator->id]);
    $this->actingAs($kicked)->deleteJson("/tables/{$table->id}/seats")->assertStatus(202);
    $this->actingAs($moderator)->deleteJson("/tables/{$table->id}/seats/{$kicked->id}")->assertOk();

    $this->assertNull(TableSet::sole()->finished_at);
    $this->stats($late)->assertJsonPath('data.leaving.abandoned_by_reason.turn_timeout', 1);
    $this->stats($mover)->assertJsonPath('data.leaving.abandoned_by_reason.moved', 1);
    $this->stats($kicked)
      ->assertJsonPath('data.leaving.abandoned', 1)
      ->assertJsonPath('data.leaving.abandoned_by_reason.kicked', 1)
      ->assertJsonPath('data.leaving.left_rate', 1);

    // never the partner they left behind
    $this->stats($moderator)->assertJsonPath('data.leaving.abandoned', 0);
  }

  public function test_leaving_a_set_that_then_ends_abandoned_counts_against_whoever_left(): void
  {
    // nobody to play on with: the human out of time abandons the set
    $this->seed([CardSeeder::class, BidSeeder::class]);
    $id = $this->actingAs($this->me)->postJson('/tables', ['robots' => true])->assertCreated()->json('data.id');
    $table = Table::findOrFail($id);
    $this->startBoard($table);
    $this->travel(60)->seconds();
    app(TableSeatService::class)->checkAway();

    $this->assertSame(['ended' => TableSet::ENDED_ABANDONED, 'ended_by' => $this->me->id], $table->sets()->sole()->only('ended', 'ended_by'));
    $this->stats($this->me)
      ->assertJsonPath('data.leaving.abandoned', 1)
      ->assertJsonPath('data.leaving.abandoned_by_reason', ['turn_timeout' => 0, 'set_time' => 0, 'away' => 0, 'moved' => 0, 'kicked' => 0, 'left' => 1])
      ->assertJsonPath('data.leaving.left_rate', 1);
  }

  public function test_an_admins_leave_mid_set_is_theirs_and_a_kick_of_a_player_who_was_there_is_nobodys(): void
  {
    [$table, $players] = $this->tableMidSet();
    $players['S']->forceFill(['is_admin' => true])->save();

    $this->actingAs($players['S'])->deleteJson("/tables/{$table->id}/seats")->assertOk();

    $this->assertSame($players['S']->id, TableSet::sole()->ended_by);
    $this->stats($players['S'])->assertJsonPath('data.leaving.abandoned_by_reason.left', 1);

    // a new set, and the moderator kicks somebody who is there
    app(TableSeatService::class)->seat($table, $players['S'], 'S');
    $this->startBoard($table->refresh());
    $table->update(['moderated_by' => $players['N']->id]);
    $this->actingAs($players['N'])->deleteJson("/tables/{$table->id}/seats/{$players['E']->id}")->assertOk();

    $set = TableSet::latest('id')->first();
    $this->assertSame([TableSet::ENDED_ABANDONED, null], [$set->ended, $set->ended_by]);
    $this->stats($players['E'])->assertJsonPath('data.leaving.abandoned', 0);
    $this->stats($players['N'])->assertJsonPath('data.leaving.abandoned', 0);
  }

  public function test_anyone_logged_in_reads_anyones_stats_and_their_own_under_api_user(): void
  {
    $this->playing(Board::factory()->create(), 100, ['N' => $this->me]);

    $this->actingAs(User::factory()->create())->getJson("/users/{$this->me->id}/stats")
      ->assertOk()
      ->assertJsonPath('data.user_id', $this->me->id)
      ->assertJsonPath('data.boards.played', 1);

    $this->actingAs($this->me)->getJson('/api/user/stats')
      ->assertOk()
      ->assertJsonPath('data.user_id', $this->me->id)
      ->assertJsonPath('data.boards.played', 1);
  }

  public function test_a_guest_gets_401_and_an_unknown_user_404(): void
  {
    $this->getJson("/users/{$this->me->id}/stats")->assertUnauthorized();
    $this->getJson('/api/user/stats')->assertUnauthorized();

    $this->actingAs($this->me)->getJson('/users/999999/stats')->assertNotFound();
  }

  /**
   * A finished (or, with a null score, unfinished) detached playing of the
   * board, the seats given and new users in the rest, or a set's four.
   *
   * @param  array<string, User>  $seats
   */
  private function playing(Board $board, ?int $scoreNs, array $seats = [], ?TableSet $set = null): BoardTable
  {
    $playing = BoardTable::factory()->create([
      'board_id' => $board->id,
      'table_id' => null,
      'table_set_id' => $set?->id,
      'set_position' => $set === null ? null : $set->playings()->count() + 1,
      'score' => $scoreNs,
      'finished_at' => $scoreNs === null ? null : now(),
    ]);

    foreach (Seats::SEATS as $seat) {
      $user = $set?->seats()->where('seat', $seat)->value('user_id') ?? ($seats[$seat] ?? User::factory()->create())->id;
      $playing->seats()->create(['user_id' => $user, 'seat' => $seat]);
    }

    return $playing;
  }

  /**
   * A set that is over, of the players given and new users in the rest.
   *
   * @param  array<string, User>  $seats
   */
  private function set(array $seats, string $ended = TableSet::ENDED_COMPLETED): TableSet
  {
    $set = TableSet::create([
      'table_id' => null,
      'number' => 1,
      'size' => 4,
      'started_at' => now(),
      'finished_at' => now(),
      'ended' => $ended,
    ]);

    foreach (Seats::SEATS as $seat) {
      $set->seats()->create(['user_id' => ($seats[$seat] ?? User::factory()->create())->id, 'seat' => $seat]);
    }

    return $set;
  }

  /**
   * Four humans at a table, a board of a set dealt, robots held back.
   *
   * @return array{0: Table, 1: array<string, User>}
   */
  private function tableMidSet(): array
  {
    $this->seed([CardSeeder::class, BidSeeder::class]);
    config(['bridge.away_seconds' => 60, 'bridge.turn_seconds' => 60]);
    $this->freezeSecond();
    Event::fake([PlayingUpdated::class]);

    $table = Table::factory()->create(['board_id' => null]);
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($table, $players[$seat], $seat);
    }

    $this->startBoard($table);

    return [$table, $players];
  }

  private function turn(Table $table): string
  {
    $state = app(PlayingStateService::class);

    return $state->turn($state->currentPlaying($table->refresh()));
  }

  private function stats(User $user): TestResponse
  {
    return $this->actingAs($user)->getJson("/users/{$user->id}/stats")->assertOk();
  }
}
