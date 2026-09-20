<?php

namespace Tests\Feature\Table;

use App\auxiliary\Seats;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Services\BoardSelectionService;
use App\Services\TableSeatService;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignBoardTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  private BoardSelectionService $boards;

  protected function setUp(): void
  {
    parent::setUp();

    // dealing a board needs the 52 reference cards
    $this->seed(CardSeeder::class);

    $this->seats = app(TableSeatService::class);
    $this->boards = app(BoardSelectionService::class);
  }

  public function test_a_table_short_of_four_players_has_no_board(): void
  {
    $table = Table::factory()->create(['board_id' => null]);

    foreach (['N', 'E', 'S'] as $seat) {
      $this->seats->seat($table, User::factory()->create(), $seat);
    }

    $this->assertNull($table->fresh()->board_id);
    $this->assertDatabaseCount('board_table', 0);
  }

  public function test_the_fourth_player_deals_a_board_and_opens_the_playing(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    $table->refresh();

    $this->assertNotNull($table->board_id);

    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();

    $this->assertSame($table->board_id, $playing->board_id);
    $this->assertNotNull($playing->started_at);
    $this->assertNull($playing->finished_at);

    // the seats are snapshotted as they stand
    $this->assertSame(4, $playing->seats()->count());

    foreach ($players as $seat => $player) {
      $this->assertDatabaseHas('board_table_seats', [
        'board_table_id' => $playing->id,
        'user_id' => $player->id,
        'seat' => $seat,
      ]);
    }
  }

  public function test_the_dealt_board_holds_a_full_deal(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $this->fill($table);

    $board = Board::findOrFail($table->fresh()->board_id);

    $this->assertSame(52, $board->cards()->count());
    $this->assertSame(Seats::dealerForBoard($board->number), $board->dealer);

    foreach (Seats::SEATS as $seat) {
      $this->assertSame(13, $board->cards()->wherePivot('seat', $seat)->count());
    }
  }

  public function test_a_board_a_player_saw_from_the_same_seat_is_not_dealt_again(): void
  {
    $stale = $this->boards->dealBoard();
    $north = User::factory()->create();

    // north has already played this deal sitting north
    $earlier = BoardTable::factory()->create(['board_id' => $stale->id]);
    $earlier->seats()->create(['user_id' => $north->id, 'seat' => 'N']);

    $table = Table::factory()->create(['board_id' => null]);
    $this->seats->seat($table, $north, 'N');
    $this->seats->seat($table, User::factory()->create(), 'E');
    $this->seats->seat($table, User::factory()->create(), 'S');
    $this->seats->seat($table, User::factory()->create(), 'W');

    $this->assertNotSame($stale->id, $table->fresh()->board_id);
  }

  public function test_a_table_never_replays_a_board(): void
  {
    $only = $this->boards->dealBoard();
    $table = Table::factory()->create(['board_id' => null]);

    // the table has already played the one board that exists
    BoardTable::factory()->create([
      'board_id' => $only->id,
      'table_id' => $table->id,
      'finished_at' => now(),
    ]);

    $this->fill($table);

    $this->assertNotSame($only->id, $table->fresh()->board_id);
  }

  public function test_a_new_board_is_dealt_when_every_stored_board_is_spoken_for(): void
  {
    $seen = $this->boards->dealBoard();
    $north = User::factory()->create();

    $earlier = BoardTable::factory()->create(['board_id' => $seen->id]);
    $earlier->seats()->create(['user_id' => $north->id, 'seat' => 'N']);

    $before = Board::count();

    $table = Table::factory()->create(['board_id' => null]);
    $this->seats->seat($table, $north, 'N');
    $this->seats->seat($table, User::factory()->create(), 'E');
    $this->seats->seat($table, User::factory()->create(), 'S');
    $this->seats->seat($table, User::factory()->create(), 'W');

    $this->assertSame($before + 1, Board::count());
    $this->assertSame(52, Board::findOrFail($table->fresh()->board_id)->cards()->count());
  }

  public function test_a_board_with_no_hands_is_never_dealt(): void
  {
    // BoardFactory makes the row but no board_card rows
    $empty = Board::factory()->create();

    $table = Table::factory()->create(['board_id' => null]);
    $this->fill($table);

    $this->assertNotSame($empty->id, $table->fresh()->board_id);
  }

  public function test_a_manager_seating_the_fourth_player_starts_the_playing(): void
  {
    $manager = User::factory()->create();
    $table = Table::factory()->create([
      'board_id' => null,
      'created_by' => $manager->id,
      'moderated_by' => $manager->id,
    ]);

    $this->seats->seat($table, $manager, 'N');
    $this->seats->seat($table, User::factory()->create(), 'E');
    $this->seats->seat($table, User::factory()->create(), 'S');

    $fourth = User::factory()->create();

    $this->actingAs($manager)
      ->postJson("/tables/$table->id/seats/users", ['user_id' => $fourth->id, 'seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.free_seats', []);

    $this->assertNotNull($table->fresh()->board_id);
    $this->assertDatabaseCount('board_table', 1);
  }

  public function test_the_join_response_carries_the_board_it_just_dealt(): void
  {
    $table = Table::factory()->create(['board_id' => null]);

    foreach (['N', 'E', 'S'] as $seat) {
      $this->seats->seat($table, User::factory()->create(), $seat);
    }

    $fourth = User::factory()->create();

    $response = $this->actingAs($fourth)
      ->postJson("/tables/$table->id/seats", ['seat' => 'W'])
      ->assertCreated();

    $this->assertSame($table->fresh()->board_id, $response->json('data.board_id'));
  }

  public function test_a_player_leaving_an_unfinished_playing_abandons_it(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();

    $this->seats->remove($table, $players['E']);

    $this->assertNull($table->fresh()->board_id);

    // kept, but cut loose: those four have seen this deal
    $this->assertDatabaseHas('board_table', ['id' => $playing->id, 'table_id' => null]);
    $this->assertSame(4, $playing->seats()->count());
  }

  public function test_a_finished_playing_survives_a_player_leaving(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();
    $playing->update(['tricks_won' => 9, 'score' => 140, 'finished_at' => now()]);

    $this->seats->remove($table, $players['E']);

    $this->assertDatabaseHas('board_table', ['id' => $playing->id, 'score' => 140]);
    $this->assertSame(4, $playing->seats()->count());
  }

  public function test_the_unfinished_playing_outlives_the_deleted_table(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);
    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();

    foreach ($players as $player) {
      $this->seats->remove($table, $player);
    }

    $this->assertDatabaseMissing('tables', ['id' => $table->id]);
    $this->assertDatabaseHas('board_table', ['id' => $playing->id, 'table_id' => null]);
  }

  public function test_a_refilled_table_gets_a_different_board(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    $first = $table->fresh()->board_id;

    $this->seats->remove($table, $players['W']);
    $this->seats->seat($table, User::factory()->create(), 'W');

    $second = $table->fresh()->board_id;

    $this->assertNotNull($second);
    // the three who stayed have seen the first deal, so it is not dealt again
    $this->assertNotSame($first, $second);
    $this->assertDatabaseCount('board_table', 2);
  }

  /**
   * Seat four fresh players, N/E/S/W.
   *
   * @return array<string, User>
   */
  private function fill(Table $table): array
  {
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $players[$seat] = User::factory()->create();
      $this->seats->seat($table, $players[$seat], $seat);
    }

    return $players;
  }
}
