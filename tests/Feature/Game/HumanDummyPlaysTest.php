<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\DeclarerHandShown;
use App\Events\PlayingUpdated;
use App\Exceptions\IllegalClaimException;
use App\Exceptions\IllegalPlayException;
use App\Models\Bid;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Services\AuctionService;
use App\Services\CardPlayService;
use App\Services\ClaimService;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A robot declarer whose partner, dummy, is a human: the human plays both
 * hands. One human and three robots, each seat holding one whole suit, so
 * every card is known in advance. Tests run the queue on `sync`, so every
 * robot move happens inside the request that made it the robots' turn.
 */
class HumanDummyPlaysTest extends TestCase
{
  use RefreshDatabase;

  /**
   * N declares 1♠ holding every spade; the human is S, with the diamonds.
   */
  private const SUITS = ['N' => 'S', 'E' => 'H', 'S' => 'D', 'W' => 'C'];

  private PlayingStateService $state;

  private User $human;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    $this->state = app(PlayingStateService::class);
    $this->human = User::factory()->create();
  }

  public function test_the_human_dummy_is_sent_declarers_hand_when_the_auction_ends(): void
  {
    Event::fake([PlayingUpdated::class, DeclarerHandShown::class]);

    $table = $this->setUpBoard(human: 'S', declarer: 'N');
    $spades = $this->ids('S');

    Event::assertDispatchedTimes(DeclarerHandShown::class, 1);
    Event::assertDispatched(DeclarerHandShown::class, function (DeclarerHandShown $event) use ($table, $spades) {
      $payload = $event->broadcastWith();

      return $event->broadcastOn() == [new PrivateChannel('App.Models.User.'.$this->human->id)]
        && $payload['table_id'] === $table->id
        && $payload['playing_id'] === $this->playing($table)->id
        && $payload['my_seat'] === 'S'
        && $payload['declarer'] === 'N'
        && array_column($payload['declarer_hand'], 'id') === $spades;
    });
  }

  public function test_no_declarer_hand_is_sent_to_a_human_defender_or_declarer(): void
  {
    Event::fake([PlayingUpdated::class, DeclarerHandShown::class]);

    $this->setUpBoard(human: 'E', declarer: 'N');

    $this->human = User::factory()->create();
    $this->setUpBoard(human: 'N', declarer: 'N');

    Event::assertNotDispatched(DeclarerHandShown::class);
  }

  public function test_the_human_dummy_plays_both_hands_and_no_robot_plays_them(): void
  {
    $table = $this->rigged(human: 'S', declarer: 'N');

    // nothing played yet: declarer's cards are the human's alone to see, and
    // E, declarer's left-hand opponent, is still the one to lead
    $state = $this->actingAs($this->human)->getJson("/tables/$table->id/playing")->assertOk()->json('data');

    $this->assertSame('E', $state['turn']);
    $this->assertSame('N', $state['contract']['declarer']);
    $this->assertSame('S', $state['contract']['dummy']);
    $this->assertSame($this->ids('S'), array_column($state['declarer_hand'], 'id'));
    $this->assertNull($state['dummy_hand']);

    // and the table channel carries no card at all yet
    $broadcast = (new PlayingUpdated($table))->broadcastWith()['playing'];
    $this->assertArrayNotHasKey('declarer_hand', $broadcast);
    $this->assertStringNotContainsString('"rank"', json_encode($broadcast));

    $this->drive($table);

    // E led a heart; it is dummy's turn, which the human plays
    $playing = $this->playing($table);
    $this->assertSame('E', $playing->cardPlays->sole()->seat);
    $this->assertSame('S', $this->state->turn($playing));
    $this->assertSame($this->human->id, $this->state->actingUserId($playing));

    // the defenders see dummy, and never declarer's hand
    foreach (['E', 'W'] as $defender) {
      $theirs = $this->state->stateFor($table, $this->robot($playing, $defender));

      $this->assertNull($theirs['declarer_hand']);
      $this->assertSame($this->ids('D'), array_column($theirs['dummy_hand'], 'id'));
    }

    $broadcast = (new PlayingUpdated($table))->broadcastWith()['playing'];
    $this->assertArrayNotHasKey('declarer_hand', $broadcast);
    $this->assertSame([], array_intersect($this->ids('S'), $this->cardIds($broadcast)));

    // the human discards a diamond from their own hand; W follows, and it is
    // declarer's turn, which the human plays too
    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('D')[0]])
      ->assertCreated()
      ->assertJsonPath('data.turn', 'N')
      ->assertJsonPath('data.acting_user_id', $this->human->id);

    // a sign of life like any other card: the seat counts as seen
    DB::table('table_seats')->where('user_id', $this->human->id)->update(['last_seen_at' => now()->subMinutes(2)]);

    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('S')[12]])
      ->assertCreated()
      ->assertJsonPath('data.turn', 'N')
      ->assertJsonPath('data.acting_user_id', $this->human->id)
      ->assertJsonPath('data.tricks_won', ['ns' => 1, 'ew' => 0])
      ->assertJsonCount(12, 'data.declarer_hand')
      ->assertJsonPath('data.declarer_hand.0.id', $this->ids('S')[0]);

    $this->assertTrue(now()->subMinute()->lt(DB::table('table_seats')->where('user_id', $this->human->id)->value('last_seen_at')));

    // the human leads from declarer's hand; E follows, and dummy's turn is
    // the human's again
    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('S')[0]])
      ->assertCreated()
      ->assertJsonPath('data.turn', 'S')
      ->assertJsonPath('data.acting_user_id', $this->human->id)
      ->assertJsonCount(11, 'data.declarer_hand');

    // every card from N's or S's hand was the human's, none a robot's
    $rows = $this->playing($table)->cardPlays;
    $this->assertCount(6, $rows);

    foreach ($rows as $row) {
      $this->assertSame(in_array($row->seat, ['N', 'S'], true) ? $this->human->id : $this->robot($playing, $row->seat)->id, $row->user_id);
    }

    // the robot declarer may play neither hand itself
    $this->expectException(IllegalPlayException::class);
    $this->expectExceptionMessage("Your partner, dummy, plays declarer's cards.");

    app(CardPlayService::class)->play($table, $this->robot($playing, 'N'), Card::find($this->ids('D')[1]));
  }

  public function test_the_human_dummy_plays_only_on_their_sides_turns(): void
  {
    $table = $this->rigged(human: 'S', declarer: 'N');

    // E is on lead: neither hand of declarer's side may play
    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('S')[0]])
      ->assertStatus(409)
      ->assertJsonPath('message', 'It is not your turn: E plays next.');

    $this->drive($table);

    // dummy's turn: a card from declarer's hand isn't in the hand being played
    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('S')[0]])
      ->assertStatus(409)
      ->assertJsonPath('message', 'That card is not in the hand being played.');
  }

  public function test_the_human_dummy_claims_for_declarer_and_the_robot_defenders_answer(): void
  {
    $table = $this->rigged(human: 'S', declarer: 'N');
    $this->playFirstTrick($table);

    // N is on lead with twelve top trumps; the robot declarer doesn't claim
    $playing = $this->playing($table);
    $this->assertNull($playing->claim_seat);

    $this->assertRobotDeclarerRefused(fn () => app(ClaimService::class)->claim($table, $this->robot($playing, 'N'), 12));

    // the human claims them for N; the robot defenders accept, which ends it
    $this->actingAs($this->human)->postJson("/tables/$table->id/claim", ['tricks' => 12])
      ->assertCreated()
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.declarer_hand', null)
      ->assertJsonPath('data.result.claimed', true)
      ->assertJsonPath('data.result.tricks_won', 13);

    $playing->refresh();
    $this->assertSame('N', $playing->claim_seat);
    $this->assertSame(['E', 'W'], $playing->claim_accepted);
  }

  public function test_the_claim_shows_declarers_hand_and_the_human_withdraws_it(): void
  {
    $table = $this->rigged(human: 'S', declarer: 'N');
    $this->playFirstTrick($table);

    // robots held back, so the claim stays pending
    Event::fake([PlayingUpdated::class]);

    $this->actingAs($this->human)->postJson("/tables/$table->id/claim", ['tricks' => 12])
      ->assertCreated()
      ->assertJsonPath('data.claim.seat', 'N')
      ->assertJsonPath('data.claim.hand', $this->state->hand($this->playing($table), 'N'));

    $this->actingAs($this->human)->deleteJson("/tables/$table->id/claim")
      ->assertOk()
      ->assertJsonPath('data.claim', null);
  }

  public function test_the_human_dummy_answers_a_defenders_claim_for_declarer(): void
  {
    $table = $this->rigged(human: 'S', declarer: 'N');
    $this->playFirstTrick($table);

    $playing = $this->playing($table);

    // E concedes the rest; W, the other robot, accepts, and the robot
    // declarer leaves its answer to the human
    app(ClaimService::class)->claim($table, $this->robot($playing, 'E'), 0);

    $playing->refresh();
    $this->assertSame('E', $playing->claim_seat);
    $this->assertSame(['W'], $playing->claim_accepted);
    $this->assertNull($playing->finished_at);

    $this->assertRobotDeclarerRefused(fn () => app(ClaimService::class)->respond($table, $this->robot($playing, 'N'), true));

    $this->actingAs($this->human)->postJson("/tables/$table->id/claim/response", ['accept' => true])
      ->assertOk()
      ->assertJsonPath('message', 'Claim accepted: the board is finished.')
      ->assertJsonPath('data.result.tricks_won', 13);

    $this->assertSame(['N', 'W'], $playing->refresh()->claim_accepted);
  }

  public function test_a_human_declarer_with_a_robot_dummy_plays_as_before(): void
  {
    $table = $this->rigged(human: 'N', declarer: 'N');
    $this->drive($table);

    // E led; dummy's turn is the human declarer's, and no hand but their own
    // is private to them
    $state = $this->actingAs($this->human)->getJson("/tables/$table->id/playing")->json('data');

    $this->assertSame('S', $state['turn']);
    $this->assertSame($this->human->id, $state['acting_user_id']);
    $this->assertNull($state['declarer_hand']);
    $this->assertSame($this->ids('D'), array_column($state['dummy_hand'], 'id'));

    // the robot dummy may not play its own cards
    $playing = $this->playing($table);
    $this->assertFalse($this->state->dummyPlaysForDeclarer($playing));

    $this->expectException(IllegalPlayException::class);
    $this->expectExceptionMessage("Dummy doesn't play: declarer plays dummy's cards.");

    app(CardPlayService::class)->play($table, $this->robot($playing, 'S'), Card::find($this->ids('D')[0]));
  }

  public function test_a_human_defender_sees_no_declarer_hand_and_the_robot_declarer_plays_both(): void
  {
    // the human is E, on lead; robots N (declarer) and S (dummy)
    $table = $this->rigged(human: 'E', declarer: 'N');

    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('H')[0]])
      ->assertCreated()
      ->assertJsonPath('data.declarer_hand', null);

    // dummy, W and declarer all played: the robot declarer played dummy's card
    $playing = $this->playing($table);
    $this->assertCount(4, $playing->cardPlays);
    $this->assertSame($this->robot($playing, 'N')->id, $playing->cardPlays->firstWhere('seat', 'S')->user_id);
  }

  /**
   * The robots' moves `PlayingUpdated` asks for, now that the board is set
   * up with them held back.
   */
  private function drive(Table $table): void
  {
    PlayingUpdated::dispatch($table);
  }

  /**
   * E leads a heart, the human discards a diamond, W a club and the human
   * ruffs from N's hand: N wins the first trick and is on lead.
   */
  private function playFirstTrick(Table $table): void
  {
    $this->drive($table);

    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('D')[0]])->assertCreated();
    $this->actingAs($this->human)->postJson("/tables/$table->id/cards", ['card_id' => $this->ids('S')[12]])->assertCreated();

    $this->assertSame('N', $this->state->turn($this->playing($table)));
  }

  private function assertRobotDeclarerRefused(callable $action): void
  {
    try {
      $action();
      $this->fail('The robot declarer acted in a claim.');
    } catch (IllegalClaimException $e) {
      $this->assertSame("Your partner, dummy, plays declarer's cards and claims for declarer's side.", $e->getMessage());
    }
  }

  /**
   * The ids of every card anywhere in a payload.
   *
   * @param  array<string, mixed>  $payload
   * @return list<int>
   */
  private function cardIds(array $payload): array
  {
    $ids = [];

    array_walk_recursive($payload, function ($value, $key) use (&$ids) {
      if ($key === 'id') {
        $ids[] = (int) $value;
      }
    });

    return $ids;
  }

  private function playing(Table $table): BoardTable
  {
    return $this->state->currentPlaying($table);
  }

  private function robot(BoardTable $playing, string $seat): User
  {
    return $playing->seats->firstWhere('seat', $seat)->user;
  }

  /**
   * The ids of a suit's cards, high to low, as a hand lists them.
   *
   * @return list<int>
   */
  private function ids(string $suit): array
  {
    return Card::where('suit', $suit)->orderByDesc('rank')->pluck('id')->map(fn ($id) => (int) $id)->all();
  }

  /**
   * `$declarer` declares 1 of the suit it holds over three passes, with
   * each seat holding one whole suit (`SUITS`), and nobody has played a
   * card yet. The human sits in `$human`, robots in the other seats, held
   * back while it is set up.
   */
  private function rigged(string $human, string $declarer): Table
  {
    return Event::fakeFor(fn () => $this->setUpBoard($human, $declarer), [PlayingUpdated::class]);
  }

  /**
   * `rigged()` with no events faked: the caller fakes `PlayingUpdated`.
   */
  private function setUpBoard(string $human, string $declarer): Table
  {
    $table = Table::create(['created_by' => $this->human->id, 'moderated_by' => $this->human->id]);
    app(TableSeatService::class)->seat($table, $this->human, $human);

    foreach (array_diff(Seats::SEATS, [$human]) as $seat) {
      app(RobotService::class)->seatRobot($table, $seat, $this->human);
    }

    $this->startBoard($table);

    $playing = $this->state->currentPlaying($table->refresh());

    foreach (self::SUITS as $seat => $suit) {
      DB::table('board_card')
        ->where('board_id', $playing->board_id)
        ->whereIn('card_id', DB::table('cards')->where('suit', $suit)->select('id'))
        ->update(['seat' => $seat]);
    }

    $auction = app(AuctionService::class);
    $pass = Bid::where('suit', Bid::PASS)->firstOrFail();
    $opening = Bid::where('suit', '1'.self::SUITS[$declarer])->firstOrFail();
    $opened = false;

    while (! AuctionService::isOver($this->state->calls($playing->refresh()))) {
      $seat = AuctionService::nextToCall($this->state->calls($playing), $playing->board->dealer);
      $bid = $seat === $declarer && ! $opened ? $opening : $pass;
      $opened = $opened || $seat === $declarer;

      $auction->call($table, $playing->seats->firstWhere('seat', $seat)->user, $bid);
    }

    return $table;
  }
}
