<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Jobs\DealNextBoard;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSetSeat;
use App\Models\User;
use App\Services\BoardSelectionService;
use App\Services\ClaimService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Inside a set, a finished board stays on show for
 * `bridge.next_board_seconds` and then the next one is dealt by itself
 * (`DealNextBoard`), unless every human asked for it first.
 */
class AutoNextBoardTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  private Table $table;

  private BoardTable $playing;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->seats = app(TableSeatService::class);

    // the clock stands still unless a test moves it, so a second's
    // difference is the one the test makes
    $this->freezeSecond();
  }

  public function test_a_passed_out_board_is_followed_by_the_next_one_after_the_pause(): void
  {
    $this->seatTable();
    Bus::fake([DealNextBoard::class]);

    $this->passOut();

    $finishedAt = $this->playing->fresh()->finished_at;
    $nextAt = $finishedAt->copy()->addSeconds(15)->toJSON();

    $this->state('N')
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.next_board_at', $nextAt);
    Bus::assertDispatched(DealNextBoard::class, fn (DealNextBoard $job) => $job->playingId === $this->playing->id
      && $job->delay->toJSON() === $nextAt);

    // not due yet: the result stays on show
    $this->travel(14)->seconds();
    $this->assertNull($this->runJob());
    $this->state('N')->assertJsonPath('data.phase', 'finished');

    $this->travel(1)->seconds();
    Event::fake([TableUpdated::class, PlayingUpdated::class, HandDealt::class]);

    $next = $this->runJob();

    $this->assertNotNull($next);
    $this->assertNotSame($this->playing->board_id, $next->board_id);
    $this->assertSame($next->board_id, $this->table->fresh()->board_id);
    $this->assertSame(2, $next->set_position);
    $this->assertSame($this->playing->table_set_id, $next->table_set_id);

    foreach ($this->players as $seat => $player) {
      $this->assertSame($seat, $next->seats()->where('user_id', $player->id)->value('seat'));
    }

    // what the last Next sends
    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatchedTimes(PlayingUpdated::class, 1);
    Event::assertDispatchedTimes(HandDealt::class, 4);
    Event::assertDispatched(PlayingUpdated::class, fn (PlayingUpdated $e) => $e->playing['phase'] === 'auction'
      && $e->playing['next_board_at'] === null);

    $this->state('E')
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.set.board', 2)
      ->assertJsonPath('data.next_board_at', null)
      ->assertJsonCount(13, 'data.hand');

    // running twice deals once
    $this->assertNull($this->runJob());
    $this->assertDatabaseCount('board_table', 2);
  }

  public function test_a_claimed_board_moves_on_the_same_way(): void
  {
    $this->seatTable();
    Bus::fake([DealNextBoard::class]);

    $this->playing->update([
      'contract_bid_id' => Bid::where('suit', '3NT')->value('id'),
      'declarer_seat' => 'N',
      'declarer_id' => $this->players['N']->id,
      'auction_ended_at' => now(),
    ]);

    $claims = app(ClaimService::class);
    $claims->claim($this->table, $this->players['N'], 13);
    $claims->respond($this->table, $this->players['E'], true);
    $claims->respond($this->table, $this->players['W'], true);

    $nextAt = $this->playing->fresh()->finished_at->addSeconds(15)->toJSON();
    $this->state('S')
      ->assertJsonPath('data.result.claimed', true)
      ->assertJsonPath('data.next_board_at', $nextAt);
    Bus::assertDispatchedTimes(DealNextBoard::class, 1);

    $this->travel(15)->seconds();

    $this->assertNotNull($this->runJob());
    $this->state('S')->assertJsonPath('data.phase', 'auction')->assertJsonPath('data.set.board', 2);
  }

  public function test_the_pause_follows_the_config(): void
  {
    config(['bridge.next_board_seconds' => 30]);
    $this->seatTable();

    $this->passOut();

    $this->state('N')->assertJsonPath('data.next_board_at', $this->playing->fresh()->finished_at->addSeconds(30)->toJSON());

    $this->travel(29)->seconds();
    $this->assertNull($this->runJob());

    $this->travel(1)->seconds();
    $this->assertNotNull($this->runJob());
  }

  public function test_nothing_is_dealt_after_a_player_left_or_their_seat_changed_hands(): void
  {
    $this->seatTable();
    $this->passOut();

    // gone for good (kicked; a Leave mid-set only holds the seat)
    $this->seats->remove($this->table, $this->players['E']);
    $this->state('N')->assertJsonPath('data.next_board_at', null);

    $this->travel(15)->seconds();
    $this->assertNull($this->runJob());

    $this->seats->seat($this->table, User::factory()->create(), 'E');
    $this->state('N')->assertJsonPath('data.next_board_at', null);

    $this->assertNull($this->runJob());
    $this->assertSame($this->playing->board_id, $this->table->fresh()->board_id);
    $this->assertDatabaseCount('board_table', 1);
  }

  public function test_the_sets_last_board_is_not_followed_by_itself(): void
  {
    config(['bridge.set_size' => 1]);
    $this->seatTable();
    Bus::fake([DealNextBoard::class]);

    $this->passOut();

    $this->state('N')
      ->assertJsonPath('data.set.finished', true)
      ->assertJsonPath('data.next_board_at', null);
    Bus::assertNotDispatched(DealNextBoard::class);

    $this->travel(15)->seconds();
    $this->assertNull($this->runJob());
    $this->assertDatabaseCount('board_table', 1);
  }

  public function test_a_robot_that_took_a_seat_between_boards_plays_the_next_one(): void
  {
    $this->seatTable();
    $this->passOut();

    // E walks out between boards: a robot takes the seat, the set goes on
    $this->seats->remove($this->table, $this->players['E'], walkOut: TableSetSeat::REASON_MOVED);
    $robot = $this->table->seats()->where('seat', 'E')->sole()->user;
    $this->assertTrue($robot->is_robot);

    // the finished board keeps its four; E doesn't hold up the others' Next
    $this->assertSame($this->players['E']->id, (int) $this->playing->seats()->where('seat', 'E')->value('user_id'));
    $this->state('N')->assertJsonPath('data.next_board_at', $this->playing->fresh()->finished_at->addSeconds(15)->toJSON());

    $this->next('N');
    $this->next('S');
    $next = $this->next('W')->assertOk()->json('data.playing_id');

    $this->assertSame($robot->id, (int) BoardTable::findOrFail($next)->seats()->where('seat', 'E')->value('user_id'));
    $this->assertSame(2, BoardTable::findOrFail($next)->set_position);
  }

  public function test_a_board_dealt_by_everyone_asking_is_not_dealt_again(): void
  {
    $this->seatTable();
    $this->passOut();

    foreach (Seats::SEATS as $seat) {
      $this->next($seat)->assertOk();
    }

    $this->assertDatabaseCount('board_table', 2);

    $this->travel(15)->seconds();
    $this->assertNull($this->runJob());
    $this->assertDatabaseCount('board_table', 2);
  }

  public function test_a_lone_human_with_three_robots_skips_the_pause(): void
  {
    // hold the robots, so none of them has asked
    Event::fake([PlayingUpdated::class]);
    $this->seatTable(robots: ['E', 'S', 'W']);
    $this->passOut();

    $this->next('N')
      ->assertOk()
      ->assertJsonPath('message', 'Next board dealt.')
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.set.board', 2);
  }

  public function test_every_human_asking_deals_at_once_and_robots_count_as_asked(): void
  {
    Event::fake([PlayingUpdated::class]);
    $this->seatTable(robots: ['E', 'W']);
    $this->passOut();

    $this->next('N')
      ->assertOk()
      ->assertJsonPath('message', 'Waiting for the other players.')
      ->assertJsonPath('data.ready', ['N']);

    $this->next('S')
      ->assertOk()
      ->assertJsonPath('message', 'Next board dealt.')
      ->assertJsonPath('data.phase', 'auction');
  }

  public function test_an_away_seat_does_not_stop_the_deal(): void
  {
    $this->seatTable();
    $this->passOut();

    // East presses Leave mid-set: the seat is held, marked away
    $this->actingAs($this->players['E'])->deleteJson("/tables/{$this->table->id}/seats")->assertStatus(202);
    $away = $this->table->seats()->where('seat', 'E')->sole();
    $this->assertNotNull($away->away_since);

    $this->state('N')->assertJsonPath('data.next_board_at', $this->playing->fresh()->finished_at->addSeconds(15)->toJSON());

    $this->travel(15)->seconds();
    $this->assertNotNull($this->runJob());

    // being dealt to is no sign of life: still away, the clock running on
    $seat = $away->fresh();
    $this->assertEquals($away->away_since, $seat->away_since);
    $this->assertEquals($away->last_seen_at, $seat->last_seen_at);
  }

  public function test_a_playing_that_left_its_table_is_never_dealt_on_from(): void
  {
    $this->seatTable();
    $this->passOut();

    $this->table->delete();

    $this->travel(15)->seconds();
    $this->assertNull($this->runJob());
    $this->assertNull(app(PlayingStateService::class)->nextBoardAt($this->playing->fresh()));
  }

  /**
   * Four players, the ones in `$robots` robots, all pressing Start: the
   * set's first board is dealt.
   *
   * @param  list<string>  $robots
   */
  private function seatTable(array $robots = []): void
  {
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = in_array($seat, $robots, true)
        ? User::factory()->robot()->create()
        : User::factory()->create();
      $this->seats->seat($this->table, $this->players[$seat], $seat);
    }

    $this->startBoard($this->table);

    $this->table->refresh();
    $this->playing = BoardTable::where('table_id', $this->table->id)->sole();
  }

  /**
   * Everyone passes, from the dealer round: a finished board, through the
   * auction as played.
   */
  private function passOut(): void
  {
    $pass = Bid::where('suit', Bid::PASS)->value('id');
    $seat = $this->playing->board->dealer;

    foreach (Seats::SEATS as $_) {
      $this->actingAs($this->players[$seat])
        ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => $pass])
        ->assertSuccessful();
      $seat = Seats::next($seat);
    }

    $this->assertNotNull($this->playing->fresh()->finished_at);
  }

  /**
   * Run the job `BoardTable::finish()` queued for the board, as the queue
   * worker would once its delay is up.
   */
  private function runJob(): ?BoardTable
  {
    return app(BoardSelectionService::class)->dealNext($this->playing->id);
  }

  private function next(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->postJson("/tables/{$this->table->id}/playing/next");
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing");
  }
}
