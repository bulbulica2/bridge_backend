<?php

namespace Tests\Feature\Database;

use App\Models\BoardTable;
use App\Models\Cardplay;
use App\Models\Table;
use App\Models\User;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardPlayConstraintsTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);
  }

  public function test_a_table_cannot_play_the_same_board_twice(): void
  {
    $play = BoardTable::factory()->create();

    $this->expectException(QueryException::class);

    BoardTable::factory()->create(['board_id' => $play->board_id, 'table_id' => $play->table_id]);
  }

  public function test_another_table_can_play_the_same_board(): void
  {
    $play = BoardTable::factory()->create();

    BoardTable::factory()->create(['board_id' => $play->board_id, 'table_id' => Table::factory()]);

    $this->assertSame(2, $play->board->plays()->count());
  }

  public function test_finished_play_stores_contract_and_declarer(): void
  {
    $play = BoardTable::factory()->finished()->create();

    $this->assertNotNull($play->contractBid);
    $this->assertFalse((bool) $play->contractBid->special);
    $this->assertNotNull($play->declarer);
    $this->assertNotNull($play->finished_at);
  }

  public function test_a_seat_is_taken_once_per_play(): void
  {
    $play = BoardTable::factory()->create();
    $play->seats()->create(['user_id' => User::factory()->create()->id, 'seat' => 'N']);

    $this->expectException(QueryException::class);

    $play->seats()->create(['user_id' => User::factory()->create()->id, 'seat' => 'N']);
  }

  public function test_a_user_sits_once_per_play(): void
  {
    $play = BoardTable::factory()->create();
    $user = User::factory()->create();
    $play->seats()->create(['user_id' => $user->id, 'seat' => 'N']);

    $this->expectException(QueryException::class);

    $play->seats()->create(['user_id' => $user->id, 'seat' => 'E']);
  }

  public function test_user_seat_history_is_queryable(): void
  {
    $play = BoardTable::factory()->create();
    $user = User::factory()->create();
    $play->seats()->create(['user_id' => $user->id, 'seat' => 'W']);

    $this->assertTrue($user->playedSeats()->where('seat', 'W')->whereHas('boardTable', fn($q) => $q->where('board_id', $play->board_id))->exists());
    $this->assertFalse($user->playedSeats()->where('seat', 'N')->exists());
  }

  public function test_a_card_is_played_once_per_play(): void
  {
    $play = BoardTable::factory()->create();
    $first = $this->playCard($play, round: 1, order: 1);

    $this->expectException(QueryException::class);

    $this->playCard($play, round: 1, order: 2, cardId: $first->card_id);
  }

  public function test_a_trick_position_is_filled_once_per_play(): void
  {
    $play = BoardTable::factory()->create();
    $first = $this->playCard($play, round: 2, order: 3);

    $this->expectException(QueryException::class);

    $this->playCard($play, round: 2, order: 3, cardId: $first->card_id === 1 ? 2 : 1);
  }

  public function test_the_same_card_can_be_played_at_another_table(): void
  {
    $play = BoardTable::factory()->create();
    $other = BoardTable::factory()->create(['board_id' => $play->board_id]);
    $card = $this->playCard($play, round: 1, order: 1);

    $this->playCard($other, round: 1, order: 1, cardId: $card->card_id, wonTrick: true);

    $this->assertSame(1, $play->cardPlays()->count());
    $this->assertTrue($other->cardPlays()->first()->won_trick);
  }

  private function playCard(BoardTable $play, int $round, int $order, ?int $cardId = null, bool $wonTrick = false): Cardplay
  {
    return Cardplay::factory()->create(array_filter([
      'board_id' => $play->board_id,
      'table_id' => $play->table_id,
      'round' => $round,
      'order' => $order,
      'card_id' => $cardId,
      'won_trick' => $wonTrick,
    ], fn($value) => $value !== null));
  }
}
