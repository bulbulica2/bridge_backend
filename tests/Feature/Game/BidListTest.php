<?php

namespace Tests\Feature\Game;

use App\Models\Bid;
use Database\Seeders\game\BidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /bids` is how a client learns the `bid_id` of a call it wants to make,
 * so the list must be complete, in the game state's shape, and ordered by
 * rank rather than by the ids it hands out.
 */
class BidListTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed(BidSeeder::class);
  }

  public function test_a_guest_gets_the_38_calls_in_the_auction_shape(): void
  {
    $pass = Bid::where('suit', 'P')->firstOrFail();

    $this->getJson('/bids')
      ->assertOk()
      ->assertJsonPath('status', 200)
      ->assertJsonPath('message', 'Bids retrieved successfully.')
      ->assertJsonCount(38, 'data')
      ->assertJsonPath('data.0', ['id' => $pass->id, 'call' => 'P', 'level' => null, 'strain' => null, 'special' => true])
      ->assertJsonPath('data.3', ['id' => $this->bidId('1C'), 'call' => '1C', 'level' => 1, 'strain' => 'C', 'special' => false]);
  }

  public function test_specials_come_first_then_bids_by_rank(): void
  {
    $this->assertSame($this->expectedOrder(), $this->listedCalls());
  }

  /**
   * Hand the ids out in reverse rank order: the list must not follow them.
   */
  public function test_the_order_does_not_follow_the_ids(): void
  {
    DB::table('bids')->update(['id' => DB::raw('1000 - id')]);

    $this->assertSame($this->expectedOrder(), $this->listedCalls());
  }

  public function test_each_id_is_the_one_its_call_has(): void
  {
    DB::table('bids')->update(['id' => DB::raw('1000 - id')]);

    foreach ($this->getJson('/bids')->json('data') as $bid) {
      $this->assertSame($this->bidId($bid['call']), $bid['id'], "{$bid['call']} carries the wrong id");
    }
  }

  /**
   * @return list<string>
   */
  private function expectedOrder(): array
  {
    $calls = ['P', 'X', 'XX'];

    foreach (range(1, 7) as $level) {
      foreach (['C', 'D', 'H', 'S', 'NT'] as $strain) {
        $calls[] = $level.$strain;
      }
    }

    return $calls;
  }

  /**
   * @return list<string>
   */
  private function listedCalls(): array
  {
    return array_column($this->getJson('/bids')->json('data'), 'call');
  }

  private function bidId(string $call): int
  {
    return Bid::where('suit', $call)->value('id');
  }
}
