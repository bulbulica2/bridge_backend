<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Models\Board;
use App\Models\BoardTable;
use App\Models\Table;
use App\Models\User;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PlayingStateTest extends TestCase
{
  use RefreshDatabase;

  private TableSeatService $seats;

  protected function setUp(): void
  {
    parent::setUp();

    // dealing a board needs the 52 reference cards
    $this->seed(CardSeeder::class);

    $this->seats = app(TableSeatService::class);
  }

  public function test_a_table_short_of_four_players_is_waiting(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $user = User::factory()->create();
    $this->seats->seat($table, $user, 'N');
    $this->seats->seat($table, User::factory()->create(), 'E');

    $this->actingAs($user)->getJson("/tables/$table->id/playing")
      ->assertOk()
      ->assertExactJson([
        'status' => 200,
        'message' => 'Playing retrieved successfully.',
        'data' => [
          'phase' => 'waiting',
          'playing_id' => null,
          'board' => null,
          'players' => null,
          'my_seat' => null,
          'hand' => null,
          'turn' => null,
          'acting_user_id' => null,
          'auction' => null,
          'contract' => null,
          'tricks' => null,
          'current_trick' => null,
          'tricks_won' => null,
          'dummy_hand' => null,
          'result' => null,
        ],
      ]);
  }

  public function test_a_full_table_is_in_the_auction_with_the_dealer_to_call(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    $board = Board::findOrFail($table->fresh()->board_id);
    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();

    $response = $this->actingAs($players['E'])->getJson("/tables/$table->id/playing")
      ->assertOk()
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.playing_id', $playing->id)
      ->assertJsonPath('data.board', [
        'id' => $board->id,
        'number' => $board->number,
        'dealer' => $board->dealer,
        'vulnerable' => $board->vulnerable,
      ])
      ->assertJsonPath('data.turn', $board->dealer)
      ->assertJsonPath('data.my_seat', 'E');

    foreach ($players as $seat => $player) {
      $this->assertSame([
        'id' => $player->id,
        'name' => $player->name,
        'username' => $player->username,
        'description' => $player->description,
      ], $response->json("data.players.$seat"));
    }
  }

  public function test_the_hand_is_the_callers_own_cards_sorted(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);
    $boardId = $table->fresh()->board_id;

    $hand = $this->actingAs($players['S'])->getJson("/tables/$table->id/playing")
      ->assertOk()
      ->json('data.hand');

    $dealt = DB::table('board_card')->where('board_id', $boardId)->where('seat', 'S')->pluck('card_id')->sort()->values()->all();

    $this->assertCount(13, $hand);
    $this->assertSame($dealt, collect($hand)->pluck('id')->sort()->values()->all());

    // spades, hearts, diamonds, clubs; high to low within a suit
    $keys = array_map(fn ($card) => [array_search($card['suit'], ['S', 'H', 'D', 'C'], true), -$card['rank']], $hand);
    $sorted = $keys;
    sort($sorted);
    $this->assertSame($sorted, $keys);
  }

  public function test_no_other_players_cards_appear_in_the_response(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    $response = $this->actingAs($players['W'])->getJson("/tables/$table->id/playing")->assertOk();

    $others = DB::table('board_card')
      ->where('board_id', $table->fresh()->board_id)
      ->where('seat', '!=', 'W')
      ->pluck('card_id')
      ->all();

    $seen = collect($response->json('data.hand'))->pluck('id')->all();
    $this->assertEmpty(array_intersect($others, $seen));

    // the hand is the only place cards appear
    $data = $response->json('data');
    unset($data['hand']);
    $this->assertStringNotContainsString('rank', json_encode($data));
  }

  public function test_a_played_card_leaves_the_hand(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);
    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();

    $card = DB::table('board_card')->where('board_id', $playing->board_id)->where('seat', 'N')->value('card_id');
    $playing->cardPlays()->create([
      'user_id' => $players['N']->id,
      'card_id' => $card,
      'seat' => 'N',
      'round' => 1,
      'order' => 1,
    ]);

    $hand = $this->actingAs($players['N'])->getJson("/tables/$table->id/playing")->json('data.hand');

    $this->assertCount(12, $hand);
    $this->assertNotContains($card, array_column($hand, 'id'));
  }

  public function test_the_phase_follows_the_playing(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $this->fill($table);
    $playing = BoardTable::where('table_id', $table->id)->firstOrFail();
    $state = app(PlayingStateService::class);

    $this->assertSame('auction', $state->phase($playing));

    // the auction ends in a contract with North declaring: East leads
    $playing->update(['auction_ended_at' => now(), 'declarer_seat' => 'N']);
    $this->assertSame('play', $state->phase($playing));
    $this->assertSame('E', $state->turn($playing));

    $playing->update(['finished_at' => now()]);
    $this->assertSame('finished', $state->phase($playing));
  }

  public function test_only_seated_players_may_read_the_playing(): void
  {
    $table = Table::factory()->create(['board_id' => null]);
    $this->fill($table);

    // unseated, then seated at another table
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->getJson("/tables/$table->id/playing")->assertForbidden();

    $this->seats->seat(Table::factory()->create(['board_id' => null]), $outsider, 'N');
    $this->actingAs($outsider)->getJson("/tables/$table->id/playing")->assertForbidden();
  }

  public function test_a_guest_gets_401(): void
  {
    $table = Table::factory()->create(['board_id' => null]);

    $this->getJson("/tables/$table->id/playing")->assertUnauthorized();
  }

  public function test_an_unknown_table_is_404(): void
  {
    $this->actingAs(User::factory()->create())->getJson('/tables/999999/playing')->assertNotFound();
  }

  public function test_the_fourth_seat_broadcasts_the_public_state_without_cards(): void
  {
    Event::fake([PlayingUpdated::class, HandDealt::class]);

    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);

    Event::assertDispatchedTimes(PlayingUpdated::class, 1);
    Event::assertDispatched(PlayingUpdated::class, function (PlayingUpdated $event) use ($table, $players) {
      $payload = $event->broadcastWith()['playing'];
      $public = $this->actingAs($players['N'])->getJson("/tables/$table->id/playing")->json('data');
      unset($public['my_seat'], $public['hand']);

      return $event->broadcastOn() == [new PrivateChannel("table.$table->id")]
        && $payload === $public
        && $payload['phase'] === 'auction'
        && ! array_key_exists('hand', $payload)
        && ! array_key_exists('my_seat', $payload)
        && ! str_contains(json_encode($payload), 'rank');
    });
  }

  public function test_three_players_broadcast_no_playing(): void
  {
    Event::fake([PlayingUpdated::class, HandDealt::class]);

    $table = Table::factory()->create(['board_id' => null]);

    foreach (['N', 'E', 'S'] as $seat) {
      $this->seats->seat($table, User::factory()->create(), $seat);
    }

    Event::assertNotDispatched(PlayingUpdated::class);
    Event::assertNotDispatched(HandDealt::class);
  }

  public function test_each_player_is_dealt_their_own_hand_on_their_own_channel(): void
  {
    Event::fake([PlayingUpdated::class, HandDealt::class]);

    $table = Table::factory()->create(['board_id' => null]);
    $players = $this->fill($table);
    $boardId = $table->fresh()->board_id;

    Event::assertDispatchedTimes(HandDealt::class, 4);

    foreach ($players as $seat => $player) {
      $dealt = DB::table('board_card')->where('board_id', $boardId)->where('seat', $seat)->pluck('card_id')->sort()->values()->all();

      Event::assertDispatched(HandDealt::class, function (HandDealt $event) use ($player, $seat, $dealt, $table) {
        $payload = $event->broadcastWith();

        return $event->broadcastOn() == [new PrivateChannel("App.Models.User.$player->id")]
          && $payload['table_id'] === $table->id
          && $payload['my_seat'] === $seat
          && collect($payload['hand'])->pluck('id')->sort()->values()->all() === $dealt;
      });
    }
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
