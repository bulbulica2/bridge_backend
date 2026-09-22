<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Models\Bid;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuctionTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  private Table $table;

  /**
   * @var array<string, User>
   */
  private array $players;

  protected function setUp(): void
  {
    parent::setUp();

    // dealing needs the 52 cards, calling needs the 38 bids
    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->seats = app(TableSeatService::class);

    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      $this->seats->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->refresh();

    // North deals every test board, so the calls below read N, E, S, W
    Board::whereKey($this->table->board_id)->update(['dealer' => 'N']);
  }

  public function test_the_dealer_calls_first(): void
  {
    $this->makeCall('E', 'P')
      ->assertStatus(409)
      ->assertJsonPath('message', 'It is not your turn: N calls next.');

    $this->makeCall('N', '1H')->assertCreated();

    $this->makeCall('N', '2H')
      ->assertStatus(409)
      ->assertJsonPath('message', 'It is not your turn: E calls next.');

    $this->makeCall('E', 'P')->assertCreated();
  }

  public function test_a_call_answers_with_the_playing_state(): void
  {
    $bid = Bid::where('suit', '1H')->firstOrFail();

    $this->makeCall('N', '1H')
      ->assertCreated()
      ->assertJsonPath('status', 201)
      ->assertJsonPath('message', 'Call made successfully.')
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.turn', 'E')
      ->assertJsonPath('data.my_seat', 'N')
      ->assertJsonPath('data.contract', null)
      ->assertJsonPath('data.auction', [
        ['seat' => 'N', 'bid' => ['id' => $bid->id, 'call' => '1H', 'level' => 1, 'strain' => 'H', 'special' => false]],
      ])
      ->assertJsonCount(13, 'data.hand');

    $this->actingAs($this->players['S'])->getJson("/tables/{$this->table->id}/playing")
      ->assertJsonPath('data.turn', 'E')
      ->assertJsonPath('data.auction.0.bid.call', '1H');
  }

  public function test_a_bid_must_be_higher_than_the_last_one(): void
  {
    $this->makeCall('N', '1H')->assertCreated();

    $this->makeCall('E', '1H')
      ->assertStatus(409)
      ->assertJsonPath('message', '1H is not higher than the last bid, 1H.');

    $this->makeCall('E', '1D')
      ->assertStatus(409)
      ->assertJsonPath('message', '1D is not higher than the last bid, 1H.');

    $this->makeCall('E', '1S')->assertCreated();
    $this->makeCall('S', '2C')->assertCreated();

    $this->assertSame(3, BoardTable::firstOrFail()->auctions()->count());
  }

  public function test_an_opponents_bid_may_be_doubled_even_after_passes(): void
  {
    $this->makeCalls('N 1H, E P, S P');

    $this->makeCall('W', 'X')->assertCreated();
  }

  public function test_illegal_doubles_are_refused(): void
  {
    $this->makeCall('N', 'X')
      ->assertStatus(409)
      ->assertJsonPath('message', 'There is no bid to double.');

    $this->makeCalls('N 1H, E P');

    $this->makeCall('S', 'X')
      ->assertStatus(409)
      ->assertJsonPath('message', "You can't double your own side's bid.");

    $this->makeCalls('S P, W X, N P');

    $this->makeCall('E', 'X')
      ->assertStatus(409)
      ->assertJsonPath('message', 'That bid is already doubled.');
  }

  public function test_an_opponents_double_may_be_redoubled_even_after_passes(): void
  {
    $this->makeCalls('N 1H, E X, S P, W P');

    $this->makeCall('N', 'XX')->assertCreated();
  }

  public function test_illegal_redoubles_are_refused(): void
  {
    $this->makeCalls('N 1H, E X, S P');

    // West's own partner doubled
    $this->makeCall('W', 'XX')
      ->assertStatus(409)
      ->assertJsonPath('message', "You can't redouble your own side's double.");

    $this->makeCalls('W P, N XX, E P');

    $this->makeCall('S', 'XX')
      ->assertStatus(409)
      ->assertJsonPath('message', 'That bid is already redoubled.');
  }

  public function test_three_passes_after_a_bid_save_the_contract(): void
  {
    $this->makeCalls('N 1H, E P, S 4H, W X, N P, E P');

    $response = $this->makeCall('S', 'P')
      ->assertCreated()
      ->assertJsonPath('data.phase', 'play')
      // declarer's left-hand opponent leads
      ->assertJsonPath('data.turn', 'E')
      ->assertJsonPath('data.acting_user_id', $this->players['E']->id)
      ->assertJsonPath('data.tricks', [])
      ->assertJsonPath('data.current_trick', [])
      ->assertJsonPath('data.tricks_won', ['ns' => 0, 'ew' => 0])
      ->assertJsonPath('data.dummy_hand', null);

    $fourHearts = Bid::where('suit', '4H')->firstOrFail();

    // the first of North-South to name hearts declares
    $this->assertSame([
      'bid' => ['id' => $fourHearts->id, 'call' => '4H', 'level' => 4, 'strain' => 'H', 'special' => false],
      'doubled' => 1,
      'declarer' => 'N',
      'dummy' => 'S',
    ], $response->json('data.contract'));

    $playing = BoardTable::firstOrFail();
    $this->assertSame($fourHearts->id, $playing->contract_bid_id);
    $this->assertSame(1, $playing->doubled);
    $this->assertSame('N', $playing->declarer_seat);
    $this->assertSame($this->players['N']->id, $playing->declarer_id);
    $this->assertNotNull($playing->auction_ended_at);
    $this->assertNull($playing->finished_at);
  }

  public function test_a_new_bid_clears_a_double_and_redouble(): void
  {
    $this->makeCalls('N 1H, E X, S XX, W 2C, N P, E P, S P');

    $playing = BoardTable::firstOrFail();
    $this->assertSame(0, $playing->doubled);
    $this->assertSame('W', $playing->declarer_seat);
    $this->assertSame($this->players['W']->id, $playing->declarer_id);
  }

  public function test_four_passes_pass_the_board_out(): void
  {
    $this->makeCalls('N P, E P, S P');

    $this->makeCall('W', 'P')
      ->assertCreated()
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.turn', null)
      ->assertJsonPath('data.contract', null)
      ->assertJsonCount(4, 'data.auction');

    $playing = BoardTable::firstOrFail();
    $this->assertNull($playing->contract_bid_id);
    $this->assertNull($playing->declarer_seat);
    $this->assertNull($playing->declarer_id);
    $this->assertNotNull($playing->auction_ended_at);
    $this->assertNotNull($playing->finished_at);
  }

  public function test_no_call_after_the_auction_ends(): void
  {
    $this->makeCalls('N 1NT, E P, S P, W P');

    $this->makeCall('N', 'P')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The auction has ended.');

    $this->assertSame(4, BoardTable::firstOrFail()->auctions()->count());
  }

  public function test_every_call_broadcasts_the_auction(): void
  {
    Event::fake([PlayingUpdated::class]);

    $this->makeCalls('N 1H, E P');

    // a refused call changes nothing and says nothing
    $this->makeCall('S', '1C')->assertStatus(409);

    Event::assertDispatchedTimes(PlayingUpdated::class, 2);
    Event::assertDispatched(PlayingUpdated::class, function (PlayingUpdated $event) {
      $payload = $event->broadcastWith()['playing'];

      return $event->tableId === $this->table->id
        && array_column(array_column($payload['auction'], 'bid'), 'call') === ['1H', 'P']
        && $payload['turn'] === 'S'
        && ! array_key_exists('hand', $payload);
    });
  }

  public function test_a_player_leaving_mid_auction_abandons_the_playing(): void
  {
    $this->makeCalls('N 1H, E P');
    $playing = BoardTable::firstOrFail();

    $this->seats->remove($this->table, $this->players['E']);

    $playing->refresh();
    $this->assertNull($playing->table_id);
    $this->assertSame(0, $playing->auctions()->count());
    $this->assertNull($this->table->fresh()->board_id);

    // nothing left to bid on
    $this->makeCall('S', 'P')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The table has no board yet: the auction starts once four players are seated.');
  }

  public function test_an_unknown_bid_is_422(): void
  {
    $this->actingAs($this->players['N'])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => 999999])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('bid_id');

    $this->actingAs($this->players['N'])
      ->postJson("/tables/{$this->table->id}/calls", [])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('bid_id');
  }

  public function test_only_seated_players_may_call(): void
  {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => $this->bidId('P')])
      ->assertForbidden();
  }

  public function test_a_guest_gets_401(): void
  {
    $this->postJson("/tables/{$this->table->id}/calls", ['bid_id' => $this->bidId('P')])
      ->assertUnauthorized();
  }

  public function test_a_table_without_a_board_is_not_in_an_auction(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $user = User::factory()->create();
    $this->seats->seat($table, $user, 'N');

    $this->actingAs($user)
      ->postJson("/tables/$table->id/calls", ['bid_id' => $this->bidId('P')])
      ->assertStatus(409);
  }

  private function makeCall(string $seat, string $call): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => $this->bidId($call)]);
  }

  /**
   * Make several legal calls in turn, written `'N 1H, E P, S X'`.
   */
  private function makeCalls(string $calls): void
  {
    foreach (explode(', ', $calls) as $made) {
      [$seat, $call] = explode(' ', $made);
      $this->makeCall($seat, $call)->assertCreated();
    }
  }

  private function bidId(string $call): int
  {
    return Bid::where('suit', $call)->value('id');
  }
}
