<?php

namespace Tests\Feature\Database;

use App\auxiliary\Seats;
use App\Models\Auction;
use App\Models\BoardTable;
use App\Models\Cardplay;
use App\Models\Table;
use App\Models\User;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardTableRelationsTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);
  }

  public function test_eager_loaded_auctions_are_the_playings_calls(): void
  {
    $play = BoardTable::factory()->create();
    Auction::factory(3)->create(['board_table_id' => $play->id]);

    $loaded = BoardTable::with('auctions')->findOrFail($play->id);

    $this->assertCount(3, $loaded->auctions);
  }

  public function test_eager_loaded_card_plays_are_the_playings_cards(): void
  {
    $play = BoardTable::factory()->create();
    $this->playCards($play, 2);

    $loaded = BoardTable::with('cardPlays')->findOrFail($play->id);

    $this->assertCount(2, $loaded->cardPlays);
  }

  public function test_eager_and_lazy_access_return_the_same_rows(): void
  {
    $play = BoardTable::factory()->create();
    Auction::factory(2)->create(['board_table_id' => $play->id]);
    $this->playCards($play, 3);

    $eager = BoardTable::with(['auctions', 'cardPlays'])->findOrFail($play->id);
    $lazy = BoardTable::findOrFail($play->id);

    $this->assertEquals($lazy->auctions->modelKeys(), $eager->auctions->modelKeys());
    $this->assertEquals($lazy->cardPlays->modelKeys(), $eager->cardPlays->modelKeys());
  }

  public function test_another_table_playing_the_same_board_does_not_bleed_in(): void
  {
    $play = BoardTable::factory()->create();
    $other = BoardTable::factory()->create(['board_id' => $play->board_id]);
    $mine = Auction::factory()->create(['board_table_id' => $play->id]);
    Auction::factory(2)->create(['board_table_id' => $other->id]);
    $this->playCards($play, 1);
    $this->playCards($other, 4);

    $plays = BoardTable::with(['auctions', 'cardPlays'])->orderBy('id')->get();

    $this->assertSame([$mine->id], $plays[0]->auctions->modelKeys());
    $this->assertCount(1, $plays[0]->cardPlays);
    $this->assertCount(2, $plays[1]->auctions);
    $this->assertCount(4, $plays[1]->cardPlays);
    // the board sees both tables' calls
    $this->assertSame(3, $play->board->auctions()->count());
  }

  public function test_the_logs_die_with_the_table_but_the_playing_does_not(): void
  {
    $play = BoardTable::factory()->finished()->create();
    Auction::factory(2)->create(['board_table_id' => $play->id]);
    $this->playCards($play, 2);

    $this->assertSame(2, $play->table->auctions()->count());

    $play->table->delete();

    $this->assertDatabaseHas('board_table', ['id' => $play->id, 'table_id' => null]);
    $this->assertDatabaseCount('auctions', 0);
    $this->assertDatabaseCount('cardplays', 0);
  }

  public function test_abandoning_a_playing_discards_its_logs(): void
  {
    $seats = app(TableSeatService::class);
    $table = Table::factory()->create(['board_id' => null]);
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $players[$seat] = User::factory()->create();
      $seats->seat($table, $players[$seat], $seat);
    }

    $play = BoardTable::where('table_id', $table->id)->firstOrFail();
    Auction::factory(2)->create(['board_table_id' => $play->id]);
    $this->playCards($play, 1);

    $seats->remove($table, $players['E']);

    $this->assertDatabaseHas('board_table', ['id' => $play->id, 'table_id' => null]);
    $this->assertSame(0, $play->auctions()->count());
    $this->assertSame(0, $play->cardPlays()->count());
  }

  public function test_a_finished_playings_logs_survive_a_player_leaving(): void
  {
    $seats = app(TableSeatService::class);
    $play = BoardTable::factory()->finished()->create();
    $user = User::factory()->create();
    $seats->seat($play->table, $user, 'N');
    $seats->seat($play->table, User::factory()->create(), 'S');
    Auction::factory()->create(['board_table_id' => $play->id]);

    $seats->remove($play->table, $user);

    $this->assertSame(1, $play->auctions()->count());
  }

  /**
   * Play the first `$count` cards of trick 1, each a different card.
   */
  private function playCards(BoardTable $play, int $count): void
  {
    for ($order = 1; $order <= $count; $order++) {
      Cardplay::factory()->create([
        'board_table_id' => $play->id,
        'card_id' => $order,
        'round' => 1 + intdiv($order - 1, 4),
        'order' => 1 + ($order - 1) % 4,
      ]);
    }
  }
}
