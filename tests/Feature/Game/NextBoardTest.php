<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Services\BoardSelectionService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NextBoardTest extends TestCase
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
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      $this->seats->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->refresh();
    $this->playing = BoardTable::where('table_id', $this->table->id)->firstOrFail();
  }

  public function test_every_player_asking_deals_a_different_board_to_the_same_table(): void
  {
    $this->finish();
    $first = $this->table->board_id;

    // scored and closed: nothing is open until the players move on
    $this->assertFalse(BoardTable::where('table_id', $this->table->id)->whereNull('finished_at')->exists());

    foreach (['N', 'E', 'S'] as $seat) {
      $this->next($seat)
        ->assertOk()
        ->assertJsonPath('message', 'Waiting for the other players.')
        ->assertJsonPath('data.phase', 'finished')
        ->assertJsonPath('data.board.id', $first);
    }

    $this->next('W')
      ->assertOk()
      ->assertJsonPath('message', 'Next board dealt.')
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.ready', null)
      ->assertJsonPath('data.deal', null)
      ->assertJsonCount(13, 'data.hand');

    $second = $this->table->fresh()->board_id;
    $this->assertNotSame($first, $second);

    // the same four, in the same seats
    $next = BoardTable::where('table_id', $this->table->id)->whereNull('finished_at')->sole();
    $this->assertSame($second, $next->board_id);

    foreach ($this->players as $seat => $player) {
      $this->assertSame($seat, $next->seats()->where('user_id', $player->id)->value('seat'));
    }
  }

  public function test_the_next_board_is_not_one_a_player_saw_from_the_same_seat(): void
  {
    // another board north has already played sitting north
    $stale = app(BoardSelectionService::class)->dealBoard();
    $earlier = BoardTable::factory()->create(['board_id' => $stale->id]);
    $earlier->seats()->create(['user_id' => $this->players['N']->id, 'seat' => 'N']);

    $this->finish();
    foreach (Seats::SEATS as $seat) {
      $this->next($seat)->assertOk();
    }

    $this->assertNotContains($this->table->fresh()->board_id, [$stale->id, $this->playing->board_id]);
  }

  public function test_a_partial_confirmation_deals_nothing_and_asking_twice_changes_nothing(): void
  {
    $this->finish();

    Event::fake([PlayingUpdated::class, HandDealt::class, TableUpdated::class]);

    $this->next('N')->assertOk()->assertJsonPath('data.ready', ['N']);
    $readyAt = $this->playing->seats()->where('seat', 'N')->value('ready_at');

    $this->next('N')->assertOk()->assertJsonPath('data.ready', ['N']);
    $this->next('E')->assertOk()->assertJsonPath('data.ready', ['N', 'E']);

    $this->assertEquals($readyAt, $this->playing->seats()->where('seat', 'N')->value('ready_at'));
    $this->assertSame($this->playing->board_id, $this->table->fresh()->board_id);
    $this->assertDatabaseCount('board_table', 1);

    // one broadcast per newly ready player, none for asking twice
    Event::assertDispatchedTimes(PlayingUpdated::class, 2);
    Event::assertNotDispatched(HandDealt::class);
    Event::assertNotDispatched(TableUpdated::class);
  }

  public function test_the_next_board_broadcasts_like_the_first(): void
  {
    $this->finish();

    foreach (['N', 'E', 'S'] as $seat) {
      $this->next($seat);
    }

    Event::fake([PlayingUpdated::class, HandDealt::class, TableUpdated::class]);

    $this->next('W')->assertOk();

    Event::assertDispatchedTimes(TableUpdated::class, 1);
    Event::assertDispatchedTimes(PlayingUpdated::class, 1);
    Event::assertDispatchedTimes(HandDealt::class, 4);
    Event::assertDispatched(PlayingUpdated::class, fn (PlayingUpdated $e) => $e->playing['phase'] === 'auction');
  }

  public function test_a_manager_moves_everyone_on(): void
  {
    $this->finish();

    // the creator (north, from the factory) is not the manager here
    $this->table->update(['moderated_by' => $this->players['N']->id, 'created_by' => $this->players['N']->id]);

    $this->next('E', everyone: true)->assertForbidden();

    $this->next('N', everyone: true)
      ->assertOk()
      ->assertJsonPath('message', 'Next board dealt.')
      ->assertJsonPath('data.phase', 'auction');
  }

  public function test_an_unseated_admin_may_move_everyone_on_but_not_ask_for_themselves(): void
  {
    $this->finish();
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->postJson("/tables/{$this->table->id}/playing/next")->assertForbidden();

    $this->actingAs($admin)
      ->postJson("/tables/{$this->table->id}/playing/next", ['everyone' => true])
      ->assertOk()
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.my_seat', null);
  }

  public function test_only_seated_players_may_ask(): void
  {
    $this->finish();

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/{$this->table->id}/playing/next")
      ->assertForbidden();

    $this->assertNull($this->playing->seats()->whereNotNull('ready_at')->first());
  }

  public function test_a_guest_gets_401(): void
  {
    $this->postJson("/tables/{$this->table->id}/playing/next")->assertUnauthorized();
  }

  public function test_an_unfinished_board_cannot_be_moved_on_from(): void
  {
    $this->next('N')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The board is not finished yet.');

    $this->assertDatabaseCount('board_table', 1);
    $this->assertNull($this->playing->seats()->whereNotNull('ready_at')->first());
  }

  public function test_a_table_with_no_board_cannot_be_moved_on(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $player = User::factory()->create();
    $this->seats->seat($table, $player, 'N');

    $this->actingAs($player)
      ->postJson("/tables/$table->id/playing/next")
      ->assertStatus(409)
      ->assertJsonPath('message', 'The table has no board yet: the first one is dealt once four players are seated.');
  }

  public function test_the_finished_state_shows_the_whole_deal_and_an_unfinished_one_never_does(): void
  {
    foreach (Seats::SEATS as $seat) {
      $this->state($seat)->assertJsonPath('data.deal', null)->assertJsonPath('data.ready', null);
    }

    // a card already played is still part of the deal
    $north = DB::table('board_card')->where('board_id', $this->playing->board_id)->where('seat', 'N')->value('card_id');
    $this->playing->cardPlays()->create([
      'user_id' => $this->players['E']->id,
      'card_id' => $north,
      'seat' => 'N',
      'round' => 1,
      'order' => 1,
    ]);

    $this->finish();

    $deal = $this->state('E')->assertJsonPath('data.ready', [])->json('data.deal');

    $this->assertSame(Seats::SEATS, array_keys($deal));

    foreach (Seats::SEATS as $seat) {
      $dealt = DB::table('board_card')->where('board_id', $this->playing->board_id)->where('seat', $seat)->pluck('card_id')->sort()->values()->all();
      $this->assertSame($dealt, collect($deal[$seat])->pluck('id')->sort()->values()->all());
    }
  }

  public function test_leaving_between_boards_detaches_nothing(): void
  {
    $this->finish();
    $this->next('N');

    $this->actingAs($this->players['E'])
      ->deleteJson("/tables/{$this->table->id}/seats")
      ->assertSuccessful();

    $this->assertDatabaseHas('board_table', ['id' => $this->playing->id, 'table_id' => $this->table->id, 'score' => 0]);
    $this->assertSame($this->playing->board_id, $this->table->fresh()->board_id);
    $this->state('N')->assertJsonPath('data.phase', 'finished');

    // three players can't move on; a fourth sitting down deals at once
    $this->next('N')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The table is short of a player: the next board is dealt as soon as a fourth one sits down.');

    $this->seats->seat($this->table, User::factory()->create(), 'E');

    $this->assertNotSame($this->playing->board_id, $this->table->fresh()->board_id);
    $this->state('N')->assertJsonPath('data.phase', 'auction');
  }

  /**
   * Pass the board out: the quickest way to a finished, scored playing.
   */
  private function finish(): void
  {
    $this->playing->refresh()->update(['auction_ended_at' => now()]);
    $this->playing->finish(null);
  }

  private function next(string $seat, bool $everyone = false): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/playing/next", $everyone ? ['everyone' => true] : []);
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing");
  }
}
