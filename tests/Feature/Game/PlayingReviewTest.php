<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlayingReviewTest extends TestCase
{
  use RefreshDatabase;

  private Table $table;

  private BoardTable $playing;

  /**
   * @var array<string, User>
   */
  private array $players;

  /**
   * The calls the auction is made of, from the dealer round: the dealer
   * opens 1C, then three passes, so the dealer declares 1C.
   */
  private const CALLS = ['1C', 'P', 'P', 'P'];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    // four players fill a table and are dealt a board
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->refresh();
    $this->playing = BoardTable::where('table_id', $this->table->id)->sole();

    $seat = $this->playing->board->dealer;

    foreach (self::CALLS as $call) {
      $this->actingAs($this->players[$seat])
        ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', $call)->value('id')])
        ->assertCreated();
      $seat = Seats::next($seat);
    }
  }

  public function test_a_player_who_finished_the_board_reviews_its_auction_and_tricks(): void
  {
    $this->playCards(52);
    $dealer = $this->playing->board->dealer;
    $plays = app(PlayingStateService::class)->plays($this->playing->fresh(PlayingStateService::RELATIONS));

    $response = $this->review('N')
      ->assertOk()
      ->assertJsonPath('message', 'Playing retrieved successfully.')
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.playing_id', $this->playing->id)
      ->assertJsonPath('data.board.id', $this->playing->board_id)
      ->assertJsonPath('data.players.E.id', $this->players['E']->id)
      ->assertJsonPath('data.turn', null)
      ->assertJsonPath('data.acting_user_id', null)
      ->assertJsonPath('data.contract.bid.call', '1C')
      ->assertJsonPath('data.contract.declarer', $dealer)
      ->assertJsonPath('data.claim', null)
      ->assertJsonPath('data.current_trick', [])
      ->assertJsonPath('data.result.claimed', false)
      ->assertJsonCount(13, 'data.tricks')
      ->assertJsonMissingPath('data.ready')
      ->assertJsonMissingPath('data.hand')
      ->assertJsonMissingPath('data.my_seat');

    // the calls in the order they were made, from the dealer round
    $this->assertSame(self::CALLS, $response->json('data.auction.*.bid.call'));
    $this->assertSame(
      [$dealer, Seats::next($dealer), Seats::next(Seats::next($dealer)), Seats::partner(Seats::next($dealer))],
      $response->json('data.auction.*.seat')
    );

    // the tricks in order, each card where it was played
    $this->assertSame(range(1, 13), $response->json('data.tricks.*.round'));
    $this->assertSame(
      array_map(fn ($play) => $play['card']->id, $plays),
      $response->json('data.tricks.*.cards.*.card.id')
    );
    $this->assertSame(Seats::next($dealer), $response->json('data.tricks.0.leader'));

    $this->assertSame(Seats::SEATS, array_keys($response->json('data.deal')));
    $this->assertSame(13, array_sum($response->json('data.tricks_won')));
  }

  public function test_the_playing_can_be_reviewed_after_its_table_is_deleted(): void
  {
    $this->playCards(52);
    $before = $this->review('S')->assertOk()->json('data');

    // everyone leaves: the last one deletes the table
    foreach (Seats::SEATS as $seat) {
      $this->actingAs($this->players[$seat])->deleteJson("/tables/{$this->table->id}/seats")->assertOk();
    }

    $this->assertModelMissing($this->table);
    $this->assertDatabaseHas('board_table', ['id' => $this->playing->id, 'table_id' => null]);
    $this->assertSame(count(self::CALLS), $this->playing->auctions()->count());
    $this->assertSame(52, $this->playing->cardPlays()->count());

    $this->assertSame($before, $this->review('S')->assertOk()->json('data'));
  }

  public function test_a_board_ended_by_a_claim_shows_the_tricks_up_to_the_claim(): void
  {
    $this->playCards(4);

    $declarer = $this->playing->board->dealer;
    $this->actingAs($this->players[$declarer])
      ->postJson("/tables/{$this->table->id}/claim", ['tricks' => 12])
      ->assertCreated();

    foreach ([Seats::next($declarer), Seats::partner(Seats::next($declarer))] as $defender) {
      $this->actingAs($this->players[$defender])
        ->postJson("/tables/{$this->table->id}/claim/response", ['accept' => true])
        ->assertOk();
    }

    $this->review('W')
      ->assertOk()
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonCount(1, 'data.tricks')
      ->assertJsonPath('data.result.claimed', true);
  }

  public function test_a_user_who_has_not_finished_the_board_gets_403(): void
  {
    $this->playCards(52);

    $this->actingAs(User::factory()->create())
      ->getJson("/playings/{$this->playing->id}")
      ->assertForbidden();

    // having finished another board doesn't help
    $other = BoardTable::factory()->finished()->create();
    $user = User::factory()->create();
    $other->seats()->create(['user_id' => $user->id, 'seat' => 'N']);

    $this->actingAs($user)->getJson("/playings/{$this->playing->id}")->assertForbidden();
    $this->actingAs($user)->getJson("/playings/$other->id")->assertOk();
  }

  public function test_an_unfinished_playing_is_404(): void
  {
    // someone who has finished this board at another table, so it isn't a 403
    $finished = BoardTable::factory()->finished()->create(['board_id' => $this->playing->board_id]);
    $user = User::factory()->create();
    $finished->seats()->create(['user_id' => $user->id, 'seat' => 'N']);

    // mid-play at its table
    $this->playCards(3);
    $this->actingAs($user)->getJson("/playings/{$this->playing->id}")->assertNotFound();
    $this->review('N')->assertNotFound();

    // abandoned: a player left, so it was detached
    app(TableSeatService::class)->remove($this->table, $this->players['E']);
    $this->assertNull($this->playing->fresh()->table_id);
    $this->actingAs($user)->getJson("/playings/{$this->playing->id}")->assertNotFound();

    $this->actingAs($user)->getJson('/playings/999999')->assertNotFound();
  }

  public function test_a_playing_finished_before_logs_were_kept_has_none(): void
  {
    $playing = BoardTable::factory()->finished()->create(['table_id' => null]);
    $user = User::factory()->create();
    $playing->seats()->create(['user_id' => $user->id, 'seat' => 'N']);

    $this->actingAs($user)
      ->getJson("/playings/$playing->id")
      ->assertOk()
      ->assertJsonPath('data.auction', [])
      ->assertJsonPath('data.tricks', []);
  }

  public function test_guests_get_401(): void
  {
    $this->playCards(52);

    // log out whoever played the last card
    $this->app['auth']->forgetGuards();

    $this->getJson("/playings/{$this->playing->id}")->assertUnauthorized();
  }

  private function review(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/playings/{$this->playing->id}");
  }

  /**
   * Play the next `$count` cards, each hand playing its first legal card.
   */
  private function playCards(int $count): void
  {
    $state = app(PlayingStateService::class);

    for ($i = 0; $i < $count; $i++) {
      $playing = $state->currentPlaying($this->table);
      $plays = $state->plays($playing);
      $turn = $state->turn($playing);

      $card = collect($state->hand($playing, $turn))
        ->map(fn ($held) => Card::find($held['id']))
        ->first(fn ($card) => CardPlayService::illegalReason($plays, $state->hand($playing, $turn), $card) === null);

      $this->actingAs(User::find($state->actingUserId($playing)))
        ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $card->id])
        ->assertCreated();
    }
  }
}
