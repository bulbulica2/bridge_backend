<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\TableSet;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BoardSetTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  private Table $table;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->seats = app(TableSeatService::class);
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      $this->seats->seat($this->table, $this->players[$seat], $seat);
    }
  }

  public function test_a_set_is_four_boards_dealt_by_start_then_next_and_stops_after_the_fourth(): void
  {
    $this->assertSame(4, config('bridge.set_size'));
    $this->actingAs($this->players['N'])->getJson("/tables/{$this->table->id}")->assertJsonPath('data.set', null);

    $this->startAll()->assertJsonPath('data.playing.set.board', 1);

    $set = TableSet::sole();
    $boards = [];

    for ($board = 1; $board <= 4; $board++) {
      $expected = ['id' => $set->id, 'number' => 1, 'board' => $board, 'of' => 4, 'finished' => false, 'ended' => null, 'forfeited_by' => null];

      $this->state('N')->assertJsonPath('data.phase', 'auction')->assertJsonPath('data.set', $expected);
      $this->actingAs($this->players['E'])->getJson("/tables/{$this->table->id}")->assertJsonPath('data.set', $expected);

      $boards[] = $this->table->fresh()->board_id;
      $this->finish();

      if ($board < 4) {
        $this->nextAll()->assertJsonPath('message', 'Next board dealt.');
      }
    }

    // four different boards, each in its place in the set
    $this->assertCount(4, array_unique($boards));
    $this->assertSame([1, 2, 3, 4], $set->playings()->pluck('set_position')->all());
    $this->assertSame($boards, $set->playings()->pluck('board_id')->all());

    $set->refresh();
    $this->assertSame(TableSet::ENDED_COMPLETED, $set->ended);
    $this->assertNotNull($set->finished_at);

    // no fifth board: the fourth stays on show, and nobody's Next counts
    foreach (Seats::SEATS as $seat) {
      $this->next($seat)
        ->assertStatus(409)
        ->assertJsonPath('message', 'The set is over: press Start for a new one.');
    }

    $this->assertSame($boards[3], $this->table->fresh()->board_id);
    $this->state('S')
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.ready', [])
      ->assertJsonPath('data.set.board', 4)
      ->assertJsonPath('data.set.finished', true)
      ->assertJsonPath('data.set.ended', 'completed');

    // everyone's Start opens set 2 with its first board
    foreach (['N', 'E', 'S'] as $seat) {
      $this->start($seat)->assertOk()->assertJsonPath('message', 'Ready: waiting for the other players.');
    }

    $this->assertSame($boards[3], $this->table->fresh()->board_id);

    $second = $this->start('W')
      ->assertOk()
      ->assertJsonPath('message', 'Board dealt.')
      ->assertJsonPath('data.playing.phase', 'auction')
      ->assertJsonPath('data.playing.set.number', 2)
      ->assertJsonPath('data.playing.set.board', 1)
      ->assertJsonPath('data.playing.set.of', 4)
      ->assertJsonPath('data.playing.set.finished', false)
      ->assertJsonPath('data.set.number', 2)
      ->json('data.playing.set.id');

    $this->assertNotSame($set->id, $second);
    $this->assertNotContains($this->table->fresh()->board_id, $boards);

    // every seat's Start was used up by the deal
    $this->assertSame(0, $this->table->seats()->whereNotNull('ready_at')->count());
  }

  public function test_start_is_refused_mid_set_and_next_after_it(): void
  {
    $this->startAll();
    $this->finish();

    $this->start('N')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The board is finished: the next board of the set is dealt by itself shortly (POST /tables/{table}/playing/next deals it at once).');
  }

  public function test_the_set_records_its_four_players_and_size(): void
  {
    config(['bridge.set_size' => 2]);

    $this->startAll();
    $set = TableSet::sole();

    $this->assertSame(2, $set->size);
    $this->assertSame($this->table->id, $set->table_id);
    foreach ($this->players as $seat => $player) {
      $this->assertSame($seat, $set->seats()->where('user_id', $player->id)->value('seat'));
    }

    // a set keeps the size it opened with
    config(['bridge.set_size' => 4]);

    $this->finish();
    $this->nextAll();
    $this->finish();

    $this->assertSame(TableSet::ENDED_COMPLETED, $set->fresh()->ended);
    $this->next('N')->assertStatus(409)->assertJsonPath('message', 'The set is over: press Start for a new one.');
  }

  public function test_a_player_taken_out_mid_set_abandons_it(): void
  {
    $this->startAll();
    $this->finish();
    $this->nextAll();

    $set = TableSet::sole();
    $abandoned = $this->table->fresh()->board_id;

    // kicked while there (a Leave would hold the seat: SetForfeitTest)
    $this->seats->remove($this->table, $this->players['E']);

    $set->refresh();
    $this->assertSame(TableSet::ENDED_ABANDONED, $set->ended);
    $this->assertNotNull($set->finished_at);

    // the board in play went with it, still linked to its place in the set
    $this->assertDatabaseHas('board_table', ['board_id' => $abandoned, 'table_id' => null, 'table_set_id' => $set->id, 'set_position' => 2]);

    $this->actingAs($this->players['N'])->getJson("/tables/{$this->table->id}")
      ->assertJsonPath('data.set.board', 2)
      ->assertJsonPath('data.set.finished', true)
      ->assertJsonPath('data.set.ended', 'abandoned');

    // a newcomer and everyone's Start: set 2, board 1
    $this->players['E'] = User::factory()->create();
    $this->seats->seat($this->table, $this->players['E'], 'E');

    $this->startAll()
      ->assertJsonPath('data.playing.set.number', 2)
      ->assertJsonPath('data.playing.set.board', 1);
  }

  public function test_leaving_between_sets_changes_nothing(): void
  {
    config(['bridge.set_size' => 1]);

    $this->startAll();
    $this->finish();

    $set = TableSet::sole();
    $finishedAt = $set->fresh()->finished_at;

    $this->actingAs($this->players['E'])->deleteJson("/tables/{$this->table->id}/seats")->assertOk();

    $this->assertSame(TableSet::ENDED_COMPLETED, $set->fresh()->ended);
    $this->assertEquals($finishedAt, $set->fresh()->finished_at);
  }

  public function test_the_set_outlives_its_table(): void
  {
    $this->startAll();
    $set = TableSet::sole();

    foreach (Seats::SEATS as $seat) {
      $this->seats->remove($this->table, $this->players[$seat]);
    }

    $this->assertModelMissing($this->table);
    $this->assertDatabaseHas('table_sets', ['id' => $set->id, 'table_id' => null, 'ended' => TableSet::ENDED_ABANDONED]);
    $this->assertSame(4, $set->seats()->count());
  }

  public function test_set_results_sum_the_four_boards(): void
  {
    $this->startAll();
    $set = TableSet::sole();

    // N-S make 3NT twice, go one down in 4S doubled once, and E-W make 2H
    $results = [['3NT', 'N', 0, 9], ['3NT', 'S', 0, 10], ['4S', 'N', 1, 9], ['2H', 'E', 0, 8]];

    foreach ($results as $i => [$contract, $declarer, $doubled, $tricks]) {
      $this->finish($contract, $declarer, $doubled, $tricks);

      if ($i < 3) {
        $this->nextAll();
      }
    }

    $playings = $set->playings()->get();
    $scoreNs = (int) $playings->sum('score');

    // another table played board 1 too, and did worse for N-S
    $other = BoardTable::factory()->create([
      'board_id' => $playings[0]->board_id,
      'score' => $playings[0]->score - 100,
      'finished_at' => now(),
    ]);

    $response = $this->actingAs($this->players['N'])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.id', $set->id)
      ->assertJsonPath('data.number', 1)
      ->assertJsonPath('data.table_id', $this->table->id)
      ->assertJsonPath('data.of', 4)
      ->assertJsonPath('data.boards_dealt', 4)
      ->assertJsonPath('data.finished', true)
      ->assertJsonPath('data.ended', 'completed')
      ->assertJsonPath('data.forfeited_by', null)
      ->assertJsonPath('data.players.N.id', $this->players['N']->id)
      ->assertJsonPath('data.players.N.email', null)
      ->assertJsonCount(4, 'data.boards')
      ->assertJsonPath('data.boards.*.position', [1, 2, 3, 4])
      ->assertJsonPath('data.boards.*.playing_id', $playings->pluck('id')->all())
      ->assertJsonPath('data.boards.*.score_ns', $playings->pluck('score')->all())
      ->assertJsonPath('data.boards.*.contract.call', ['3NT', '3NT', '4S', '2H'])
      ->assertJsonPath('data.boards.*.tricks_won', [9, 10, 9, 8])
      ->assertJsonPath('data.boards.2.doubled', 1)
      ->assertJsonPath('data.boards.2.made_by', -1)
      // board 1 against the other table: a top for N-S; the rest have nothing to compare with
      ->assertJsonPath('data.boards.*.top', [2, 0, 0, 0])
      ->assertJsonPath('data.boards.0.matchpoints', ['ns' => 2, 'ew' => 0])
      ->assertJsonPath('data.totals.score', ['ns' => $scoreNs, 'ew' => -$scoreNs])
      ->assertJsonPath('data.totals.matchpoints', ['ns' => 2, 'ew' => 0])
      ->assertJsonPath('data.totals.top', 2);

    // whatever the vulnerability, N-S's two games outscore the rest
    $this->assertGreaterThan(0, $scoreNs);
    $this->assertSame('NS', $response->json('data.winner'));

    // the other table's playing isn't part of the set
    $this->assertNotContains($other->id, $response->json('data.boards.*.playing_id'));
  }

  public function test_an_unfinished_or_abandoned_set_has_no_winner(): void
  {
    $this->startAll();
    $this->finish('3NT', 'N', 0, 9);
    $set = TableSet::sole();

    $this->actingAs($this->players['N'])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.finished', false)
      ->assertJsonCount(1, 'data.boards')
      ->assertJsonPath('data.winner', null);

    $this->nextAll();
    $this->seats->remove($this->table, $this->players['W']);

    // the board abandoned mid-play isn't listed; it was dealt, though
    $this->actingAs($this->players['W'])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.ended', 'abandoned')
      ->assertJsonPath('data.boards_dealt', 2)
      ->assertJsonCount(1, 'data.boards')
      ->assertJsonPath('data.winner', null);
  }

  public function test_a_forfeit_hands_the_set_to_the_other_side(): void
  {
    $this->startAll();
    $this->finish('3NT', 'N', 0, 9);
    $set = TableSet::sole();

    // as tables:check-away would (SetForfeitTest)
    $set->update(['finished_at' => now(), 'ended' => TableSet::ENDED_FORFEIT, 'forfeited_by' => 'NS']);

    $this->actingAs($this->players['N'])->getJson("/sets/$set->id")
      ->assertOk()
      ->assertJsonPath('data.ended', 'forfeit')
      ->assertJsonPath('data.forfeited_by', 'NS')
      ->assertJsonPath('data.winner', 'EW');
  }

  public function test_a_set_that_is_over_keeps_how_it_ended(): void
  {
    $this->startAll();
    $set = TableSet::sole();

    $set->forfeit('EW');
    $finishedAt = $set->fresh()->finished_at;

    $this->travel(1)->minutes();

    // a later abandon or forfeit changes nothing
    $set->end(TableSet::ENDED_ABANDONED);
    $set->forfeit('NS');

    $set->refresh();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame('EW', $set->forfeited_by);
    $this->assertEquals($finishedAt, $set->finished_at);
  }

  public function test_only_the_sets_players_and_those_who_finished_its_boards_see_its_results(): void
  {
    config(['bridge.set_size' => 2]);

    $this->startAll();
    $this->finish();
    $this->nextAll();
    $this->finish();

    $set = TableSet::sole();
    [$first, $second] = $set->playings()->pluck('board_id')->all();

    $this->actingAs(User::factory()->create())->getJson("/sets/$set->id")->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson('/sets/999')->assertNotFound();

    // somebody who has finished one of the boards elsewhere, then both
    $elsewhere = User::factory()->create();
    $this->finishedElsewhere($elsewhere, $first);
    $this->actingAs($elsewhere)->getJson("/sets/$set->id")->assertForbidden();

    $this->finishedElsewhere($elsewhere, $second);
    $this->actingAs($elsewhere)->getJson("/sets/$set->id")->assertOk();

    // a player of the set, even once they have left the table
    $this->actingAs($this->players['S'])->deleteJson("/tables/{$this->table->id}/seats")->assertOk();
    $this->actingAs($this->players['S'])->getJson("/sets/$set->id")->assertOk();
  }

  public function test_a_guest_gets_401(): void
  {
    $this->startBoard($this->table);

    $this->getJson('/sets/'.TableSet::sole()->id)->assertUnauthorized();
  }

  public function test_the_history_says_which_set_each_board_was_in(): void
  {
    $this->startAll();
    $this->finish();
    $set = TableSet::sole();

    $this->actingAs($this->players['E'])->getJson('/api/user/playings')
      ->assertOk()
      ->assertJsonPath('data.data.0.set', ['id' => $set->id, 'number' => 1, 'board' => 1, 'of' => 4]);
  }

  public function test_the_table_list_carries_each_tables_set(): void
  {
    $this->startAll();
    $idle = Table::factory()->create();
    $this->seats->seat($idle, User::factory()->create(), 'N');

    $tables = collect($this->actingAs($this->players['N'])->getJson('/tables')->assertOk()->json('data'))->keyBy('id');

    $this->assertSame(1, $tables[$this->table->id]['set']['board']);
    $this->assertArrayNotHasKey('latest_set', $tables[$this->table->id]);
    $this->assertNull($tables[$idle->id]['set']);
  }

  /**
   * Finish the board on the table: passed out by default, otherwise with
   * `$contract` by `$declarer` and declarer's side's `$tricks`.
   */
  private function finish(?string $contract = null, string $declarer = 'N', int $doubled = 0, int $tricks = 0): void
  {
    $playing = app(PlayingStateService::class)->currentPlaying($this->table->refresh());

    $playing->update($contract === null ? ['auction_ended_at' => now()] : [
      'contract_bid_id' => Bid::where('suit', $contract)->value('id'),
      'doubled' => $doubled,
      'declarer_seat' => $declarer,
      'declarer_id' => $this->players[$declarer]->id,
      'auction_ended_at' => now(),
    ]);

    $playing->refresh()->finish($contract === null ? null : $tricks);
  }

  /**
   * `$user` has finished board `$boardId` at some other table.
   */
  private function finishedElsewhere(User $user, int $boardId): void
  {
    $playing = BoardTable::factory()->create(['board_id' => $boardId, 'score' => 0, 'finished_at' => now()]);
    $playing->seats()->create(['user_id' => $user->id, 'seat' => 'N']);
  }

  /**
   * Everyone presses Start; returns the last press.
   */
  private function startAll(): TestResponse
  {
    foreach (Seats::SEATS as $seat) {
      $response = $this->start($seat)->assertOk();
    }

    return $response;
  }

  /**
   * Everyone asks for the next board; returns the last ask.
   */
  private function nextAll(): TestResponse
  {
    foreach (Seats::SEATS as $seat) {
      $response = $this->next($seat)->assertOk();
    }

    return $response;
  }

  private function start(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->postJson("/tables/{$this->table->id}/start");
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
