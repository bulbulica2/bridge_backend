<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\PlayingUpdated;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Cardplay;
use App\Models\Table;
use App\Models\User;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CardPlayTest extends TestCase
{
  use RefreshDatabase;

  /**
   * The deal every test plays. North declares, so South is dummy and East
   * leads. Everyone holds three or four cards of each suit, so after three
   * rounds of spades East, South and West are out of them.
   */
  private const DEAL = [
    'N' => 'SA SK SQ SJ HA HK HQ DA DK DQ CA CK CQ',
    'E' => 'S10 S9 S8 HJ H10 H9 H8 DJ D10 D9 CJ C10 C9',
    'S' => 'S7 S6 S5 H7 H6 H5 D8 D7 D6 D5 C8 C7 C6',
    'W' => 'S4 S3 S2 H4 H3 H2 D4 D3 D2 C5 C4 C3 C2',
  ];

  private const RANKS = ['J' => 12, 'Q' => 13, 'K' => 14, 'A' => 15];

  private Table $table;

  private BoardTable $playing;

  /**
   * @var array<string, User>
   */
  private array $players;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->refresh();
    $this->playing = BoardTable::where('table_id', $this->table->id)->firstOrFail();

    // replace the random deal with a known one
    DB::table('board_card')->where('board_id', $this->table->board_id)->delete();

    foreach (self::DEAL as $seat => $cards) {
      foreach (explode(' ', $cards) as $code) {
        DB::table('board_card')->insert([
          'board_id' => $this->table->board_id,
          'card_id' => $this->card($code)->id,
          'seat' => $seat,
        ]);
      }
    }

    $this->contract('4H');
  }

  public function test_only_the_player_on_declarers_left_may_lead(): void
  {
    $this->play('N', 'SA')
      ->assertStatus(409)
      ->assertJsonPath('message', 'It is not your turn: E plays next.');

    $this->play('W', 'S4')
      ->assertStatus(409)
      ->assertJsonPath('message', 'It is not your turn: E plays next.');

    $this->play('E', 'S10')
      ->assertCreated()
      ->assertJsonPath('message', 'Card played successfully.')
      ->assertJsonPath('data.turn', 'S')
      ->assertJsonPath('data.current_trick', [['seat' => 'E', 'card' => $this->cardJson('S10')]])
      ->assertJsonCount(12, 'data.hand');

    $row = Cardplay::sole();
    $this->assertSame([$this->players['E']->id, 'E', 1, 1], [$row->user_id, $row->seat, $row->round, $row->order]);
  }

  public function test_dummys_hand_is_face_up_only_after_the_opening_lead(): void
  {
    foreach (Seats::SEATS as $seat) {
      $this->state($seat)->assertJsonPath('data.dummy_hand', null);
    }

    $this->play('E', 'S10')->assertCreated();

    $dummy = collect(explode(' ', self::DEAL['S']))->map(fn ($code) => $this->card($code)->id)->sort()->values()->all();

    foreach (Seats::SEATS as $seat) {
      $shown = $this->state($seat)->json('data.dummy_hand');
      $this->assertSame($dummy, collect($shown)->pluck('id')->sort()->values()->all());
    }

    // it shrinks as declarer plays from it
    $this->play('N', 'S7')->assertCreated()->assertJsonCount(12, 'data.dummy_hand');
  }

  public function test_declarer_plays_dummys_cards(): void
  {
    $this->play('E', 'S10')
      ->assertJsonPath('data.turn', 'S')
      ->assertJsonPath('data.acting_user_id', $this->players['N']->id);

    $this->play('N', 'S7')->assertCreated()->assertJsonPath('data.turn', 'W');

    $row = Cardplay::where('card_id', $this->card('S7')->id)->sole();
    $this->assertSame($this->players['N']->id, $row->user_id);
    $this->assertSame('S', $row->seat);
    $this->assertSame([1, 2], [$row->round, $row->order]);
  }

  public function test_dummys_own_player_may_never_play(): void
  {
    $this->play('S', 'S7')
      ->assertStatus(409)
      ->assertJsonPath('message', "Dummy doesn't play: declarer plays dummy's cards.");

    // not even on dummy's turn
    $this->play('E', 'S10')->assertCreated();

    $this->play('S', 'S7')
      ->assertStatus(409)
      ->assertJsonPath('message', "Dummy doesn't play: declarer plays dummy's cards.");

    $this->assertSame(1, Cardplay::count());
  }

  public function test_the_card_must_be_in_the_hand_played_from_and_not_played_yet(): void
  {
    $this->play('E', 'SA')
      ->assertStatus(409)
      ->assertJsonPath('message', 'That card is not in the hand being played.');

    // declarer can't play their own card on dummy's turn
    $this->play('E', 'S10')->assertCreated();
    $this->play('N', 'SK')
      ->assertStatus(409)
      ->assertJsonPath('message', 'That card is not in the hand being played.');

    $this->plays('N S7, W S4, N SA');

    $this->play('N', 'SA')
      ->assertStatus(409)
      ->assertJsonPath('message', 'That card has already been played.');
  }

  public function test_a_hand_must_follow_suit_but_may_discard_when_out_of_it(): void
  {
    $this->play('E', 'S10')->assertCreated();

    $this->play('N', 'H5')
      ->assertStatus(409)
      ->assertJsonPath('message', 'You must follow suit: Spades were led.');

    // three rounds of spades leave East, South and West without any
    $this->plays('N S7, W S4, N SA,  N SK, E S9, N S6, W S3,  N SQ, E S8, N S5, W S2');

    $this->play('N', 'SJ')->assertCreated();
    $this->play('E', 'D9')->assertCreated();
  }

  public function test_the_highest_card_of_the_suit_led_wins_and_leads_next(): void
  {
    $this->plays('E S10, N S7, W S4');

    $this->play('N', 'SA')
      ->assertCreated()
      ->assertJsonPath('data.turn', 'N')
      ->assertJsonPath('data.current_trick', [])
      ->assertJsonPath('data.tricks_won', ['ns' => 1, 'ew' => 0])
      ->assertJsonPath('data.tricks', [[
        'round' => 1,
        'leader' => 'E',
        'cards' => [
          ['seat' => 'E', 'card' => $this->cardJson('S10')],
          ['seat' => 'S', 'card' => $this->cardJson('S7')],
          ['seat' => 'W', 'card' => $this->cardJson('S4')],
          ['seat' => 'N', 'card' => $this->cardJson('SA')],
        ],
        'winner' => 'N',
      ]]);

    $this->assertSame([$this->card('SA')->id], Cardplay::where('won_trick', true)->pluck('card_id')->all());

    // the winner leads the second trick
    $this->play('E', 'S9')
      ->assertStatus(409)
      ->assertJsonPath('message', 'It is not your turn: N plays next.');

    $this->play('N', 'SK')->assertCreated();

    $row = Cardplay::where('card_id', $this->card('SK')->id)->sole();
    $this->assertSame(['N', 2, 1], [$row->seat, $row->round, $row->order]);
  }

  public function test_a_trump_beats_the_suit_led_and_the_highest_trump_wins(): void
  {
    $this->plays('E S10, N S7, W S4, N SA,  N SK, E S9, N S6, W S3,  N SQ, E S8, N S5, W S2');

    // nobody has a spade left: all three ruff, East highest
    $this->plays('N SJ, E H8, N H7, W H4');

    $this->assertSame(
      $this->card('H8')->id,
      Cardplay::where('round', 4)->where('won_trick', true)->sole()->card_id
    );

    $this->state('N')->assertJsonPath('data.turn', 'E')->assertJsonPath('data.tricks.3.winner', 'E');
  }

  public function test_nothing_is_trump_in_no_trump(): void
  {
    $this->contract('3NT');

    $this->plays('E S10, N S7, W S4, N SA,  N SK, E S9, N S6, W S3,  N SQ, E S8, N S5, W S2');

    // hearts discarded on a spade lead don't win
    $this->plays('N SJ, E H8, N H7, W H2');

    $this->state('N')->assertJsonPath('data.tricks.3.winner', 'N');
  }

  public function test_thirteen_tricks_finish_the_board(): void
  {
    $this->playOut();

    $playing = $this->playing->fresh();
    $declarerTricks = Cardplay::where('won_trick', true)->whereIn('seat', ['N', 'S'])->count();

    $this->assertSame(52, Cardplay::count());
    $this->assertSame(13, Cardplay::where('won_trick', true)->count());
    $this->assertSame($declarerTricks, $playing->tricks_won);
    $this->assertNotNull($playing->finished_at);

    $this->state('N')
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.turn', null)
      ->assertJsonPath('data.acting_user_id', null)
      ->assertJsonCount(13, 'data.tricks')
      ->assertJsonPath('data.tricks_won', ['ns' => $declarerTricks, 'ew' => 13 - $declarerTricks])
      ->assertJsonPath('data.hand', [])
      ->assertJsonPath('data.dummy_hand', []);

    $this->play('E', 'S10')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The board is finished.');
  }

  public function test_every_card_broadcasts_and_only_face_up_cards(): void
  {
    Event::fake([PlayingUpdated::class]);

    $this->plays('E S10, N S7, W S4');

    // a refused card says nothing
    $this->play('N', 'H5')->assertStatus(409);

    $this->play('N', 'SA')->assertCreated();

    Event::assertDispatchedTimes(PlayingUpdated::class, 4);

    $dummy = collect(explode(' ', self::DEAL['S']))->map(fn ($code) => $this->card($code)->id)->all();
    $played = [];

    foreach (Event::dispatched(PlayingUpdated::class) as [$event]) {
      $payload = $event->broadcastWith()['playing'];
      $trick = $payload['current_trick'] === [] ? end($payload['tricks'])['cards'] : $payload['current_trick'];
      $played[] = end($trick)['card']['id'];

      $this->assertArrayNotHasKey('hand', $payload);
      $this->assertArrayNotHasKey('my_seat', $payload);
      $this->assertEmpty(array_diff($this->cardIds($payload), [...$played, ...$dummy]));
    }
  }

  public function test_no_card_during_the_auction(): void
  {
    $this->playing->update(['contract_bid_id' => null, 'declarer_seat' => null, 'declarer_id' => null, 'auction_ended_at' => null]);

    $this->play('E', 'S10')
      ->assertStatus(409)
      ->assertJsonPath('message', 'The auction is not over yet.');
  }

  public function test_only_seated_players_may_play(): void
  {
    $this->actingAs(User::factory()->create())
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $this->card('S10')->id])
      ->assertForbidden();
  }

  public function test_a_guest_gets_401(): void
  {
    $this->postJson("/tables/{$this->table->id}/cards", ['card_id' => $this->card('S10')->id])
      ->assertUnauthorized();
  }

  public function test_an_unknown_card_is_422(): void
  {
    $this->actingAs($this->players['E'])
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => 999999])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('card_id');
  }

  /**
   * End the auction in `$contract`, declared by North.
   */
  private function contract(string $contract): void
  {
    $this->playing->update([
      'contract_bid_id' => Bid::where('suit', $contract)->value('id'),
      'declarer_seat' => 'N',
      'declarer_id' => $this->players['N']->id,
      'auction_ended_at' => now(),
    ]);
  }

  /**
   * Play the rest of the board, each hand playing its first legal card.
   */
  private function playOut(): void
  {
    $state = app(PlayingStateService::class);

    for ($i = 0; $i < 52; $i++) {
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

  private function play(string $seat, string $code): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $this->card($code)->id]);
  }

  /**
   * Play several legal cards in turn, written `'E S10, N S7'` — the seat is
   * the player who acts, so declarer's seat for dummy's cards.
   */
  private function plays(string $plays): void
  {
    foreach (preg_split('/,\s+/', $plays) as $made) {
      [$seat, $code] = explode(' ', $made);
      $this->play($seat, $code)->assertCreated();
    }
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing")->assertOk();
  }

  /**
   * A card from its code: suit, then rank (`SA`, `H10`).
   */
  private function card(string $code): Card
  {
    $rank = self::RANKS[substr($code, 1)] ?? (int) substr($code, 1);

    return Card::where('suit', $code[0])->where('rank', $rank)->firstOrFail();
  }

  /**
   * @return array{id: int, suit: string, rank: int, rank_name: string}
   */
  private function cardJson(string $code): array
  {
    return PlayingStateService::card($this->card($code));
  }

  /**
   * Every card id anywhere in a payload.
   *
   * @return list<int>
   */
  private function cardIds(array $payload): array
  {
    $ids = [];

    $walk = function ($node) use (&$walk, &$ids) {
      if (! is_array($node)) {
        return;
      }

      if (isset($node['id'], $node['suit'], $node['rank'])) {
        $ids[] = $node['id'];
      }

      array_map($walk, $node);
    };

    $walk($payload);

    return $ids;
  }
}
