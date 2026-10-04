<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\CallAlerted;
use App\Events\CallQuestioned;
use App\Events\PlayingUpdated;
use App\Exceptions\IllegalCallException;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Board;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Services\AuctionService;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Self-alerts: the bidder marks their own call and says what it means. The
 * opponents see it, partner doesn't, until the board is finished; the
 * opponents may ask about any call, and its bidder (a robot at once)
 * answers.
 */
class BidAlertTest extends TestCase
{
  use RefreshDatabase;

  private const PRECISION = 'Precision: 16+ HCP, any shape';

  private Table $table;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    Event::fake([PlayingUpdated::class, CallAlerted::class, CallQuestioned::class]);
  }

  public function test_an_alert_goes_to_the_opponents_and_not_to_partner(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C', ['alert' => true, 'explanation' => self::PRECISION])
      ->assertCreated()
      ->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION])
      ->assertJsonPath('data.auction.0.question', null);

    $call = Auction::sole();
    $this->assertTrue($call->alerted);
    $this->assertSame(self::PRECISION, $call->explanation);

    foreach (['E', 'W'] as $opponent) {
      $this->state($opponent)->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION]);
    }

    $this->state('S')
      ->assertJsonPath('data.auction.0.alert', null)
      ->assertDontSee('Precision');

    $this->assertAlertedTo(['E', 'W'], 0, self::PRECISION);

    // the table channel carries no alert at all, not even the flag
    Event::assertDispatched(PlayingUpdated::class, function (PlayingUpdated $event) {
      $wire = json_encode($event->broadcastWith());

      return ! str_contains($wire, 'alert') && ! str_contains($wire, 'Precision');
    });
  }

  public function test_an_alert_may_have_no_explanation(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C', ['alert' => true])->assertCreated();

    $this->state('E')->assertJsonPath('data.auction.0.alert', ['explanation' => null]);
    $this->assertAlertedTo(['E', 'W'], 0, null);
  }

  public function test_an_explanation_alerts_the_call_by_itself(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C', ['explanation' => self::PRECISION])->assertCreated();

    $this->assertTrue(Auction::sole()->alerted);
    $this->state('W')->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION]);
  }

  public function test_a_call_with_no_alert_is_not_alerted(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C', ['alert' => false, 'explanation' => ''])->assertCreated();

    $this->assertFalse(Auction::sole()->alerted);
    $this->state('E')->assertJsonPath('data.auction.0.alert', null);
    Event::assertNotDispatched(CallAlerted::class);
  }

  public function test_an_explanation_is_at_most_200_characters(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C', ['explanation' => str_repeat('a', Auction::EXPLANATION_MAX + 1)])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('explanation');

    $this->makeCall('N', '1C', ['alert' => 'maybe'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('alert');

    $this->makeCall('N', '1C', ['explanation' => str_repeat('a', Auction::EXPLANATION_MAX)])->assertCreated();
  }

  public function test_every_alert_is_public_once_the_board_is_finished(): void
  {
    $this->humanTable();

    $this->makeCall('N', 'P', ['explanation' => 'Weak with clubs'])->assertCreated();
    $this->makeCalls('E P, S P, W P');

    $playing = app(PlayingStateService::class)->currentPlaying($this->table);
    $this->assertNotNull($playing->finished_at);

    // partner sees it now, at the table and in the review
    $this->state('S')
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => 'Weak with clubs']);

    $this->actingAs($this->players['S'])->getJson("/playings/{$playing->id}")
      ->assertOk()
      ->assertJsonPath('data.auction.0.alert', ['explanation' => 'Weak with clubs'])
      ->assertJsonPath('data.auction.1.alert', null)
      ->assertJsonMissingPath('data.auction.0.question');

    // and nobody can ask any more
    $this->ask('E', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', 'The board is over: every alert is public now.');
  }

  public function test_an_opponent_asks_and_the_bidder_answers(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C, E P');

    $this->ask('W', 0)
      ->assertOk()
      ->assertJsonPath('message', 'Question asked: waiting for the answer.')
      ->assertJsonPath('data.auction.0.question', ['asked_by' => 'W'])
      ->assertJsonPath('data.auction.0.alert', null);

    $this->assertSame('W', Auction::orderBy('id')->first()->question_seat);

    Event::assertDispatchedTimes(CallQuestioned::class, 1);
    Event::assertDispatched(CallQuestioned::class, fn (CallQuestioned $event) => $event->broadcastOn() == [new PrivateChannel('App.Models.User.'.$this->players['N']->id)]
      && $event->broadcastWith() === [
        'table_id' => $this->table->id,
        'playing_id' => Auction::orderBy('id')->first()->board_table_id,
        'index' => 0,
        'asked_by' => 'W',
      ]);

    // the bidder and the other opponent see the question, partner doesn't
    $this->state('N')->assertJsonPath('data.auction.0.question', ['asked_by' => 'W']);
    $this->state('E')->assertJsonPath('data.auction.0.question', ['asked_by' => 'W']);
    $this->state('S')->assertJsonPath('data.auction.0.question', null);

    // one open question per call
    $this->ask('E', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', 'W has asked about that call already: wait for the answer.');

    $this->explain('N', 0, ' Precision ')
      ->assertOk()
      ->assertJsonPath('message', 'Call explained.')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => 'Precision'])
      ->assertJsonPath('data.auction.0.question', null);

    $this->assertAlertedTo(['E', 'W'], 0, 'Precision');

    $this->state('W')->assertJsonPath('data.auction.0.alert', ['explanation' => 'Precision']);
    $this->state('S')->assertJsonPath('data.auction.0.alert', null)->assertDontSee('Precision');

    // answered: the question may be asked again
    $this->ask('E', 0)->assertOk();
  }

  public function test_the_bidder_may_fix_an_explanation_or_alert_late(): void
  {
    $this->humanTable();
    $this->makeCall('N', '1C', ['alert' => true])->assertCreated();
    $this->makeCalls('E P, S 1D');

    $this->explain('N', 0, self::PRECISION)->assertOk();
    $this->explain('S', 2, 'Negative: 0–7 HCP')->assertOk();

    $this->state('E')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION])
      ->assertJsonPath('data.auction.2.alert', ['explanation' => 'Negative: 0–7 HCP']);
    // partners see neither of the other's
    $this->state('N')->assertJsonPath('data.auction.2.alert', null);
    $this->state('S')->assertJsonPath('data.auction.0.alert', null);
  }

  public function test_questions_and_answers_that_are_refused(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C, E 1H');

    $this->ask('S', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', "Ask the opponents about their own calls: that call is your side's.");
    $this->ask('N', 0)->assertStatus(409);

    $this->ask('N', 5)
      ->assertStatus(409)
      ->assertJsonPath('message', 'There is no call 5 in the auction.');

    $this->explain('S', 0, 'Strong')
      ->assertStatus(409)
      ->assertJsonPath('message', 'Only its bidder can explain a call.');

    $this->explain('N', 0, '')->assertUnprocessable()->assertJsonValidationErrors('explanation');
    $this->explain('N', 0, str_repeat('a', Auction::EXPLANATION_MAX + 1))->assertUnprocessable();

    $this->actingAs(User::factory()->create())
      ->postJson("/tables/{$this->table->id}/calls/0/question")
      ->assertForbidden();
    $this->actingAs(User::factory()->create())
      ->putJson("/tables/{$this->table->id}/calls/0/explanation", ['explanation' => 'Strong'])
      ->assertForbidden();

    $this->expectException(IllegalCallException::class);
    $this->expectExceptionMessage('You are not playing this board.');

    app(AuctionService::class)->ask($this->table, User::factory()->create(), 0);
  }

  public function test_nothing_can_be_asked_before_a_board_is_dealt(): void
  {
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->ask('E', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', 'The table has no board yet.');
  }

  public function test_a_robot_alerts_its_conventions_and_answers_questions_at_once(): void
  {
    // N opens 1NT, the human passes, S asks Stayman, W passes and N denies
    // a major: then it is the human's turn
    $this->robotTable(human: 'E', hands: [
      'N' => 'AK2.KQ2.A432.432',
      'E' => 'JT98.T.QJT8.AKQJ',
      'S' => 'Q43.AJ43.K5.8765',
      'W' => '765.98765.976.T9',
    ]);

    $this->driveRobots();
    $this->makeCall('E', 'P')->assertCreated();
    $this->driveRobots();

    $state = $this->state('E')
      ->assertJsonPath('data.turn', 'E')
      ->json('data.auction');

    $this->assertSame(['1NT', 'P', '2C', 'P', '2D'], array_map(fn ($call) => $call['bid']['call'], $state));

    // the natural 1NT and the answer to Stayman aren't alerted
    $this->assertNull($state[0]['alert']);
    $this->assertSame(['explanation' => 'Stayman: 8–17 HCP, asks for a four-card major'], $state[2]['alert']);
    $this->assertNull($state[4]['alert']);
    $this->assertAlertedTo(['E'], 2, 'Stayman: 8–17 HCP, asks for a four-card major');

    // asked, a robot answers with what its system reads into the call
    $this->ask('E', 0)
      ->assertOk()
      ->assertJsonPath('message', 'Question answered.')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => 'Opening: 15–17 HCP, balanced'])
      ->assertJsonPath('data.auction.0.question', null);

    $this->assertAlertedTo(['E'], 0, 'Opening: 15–17 HCP, balanced');
    Event::assertNotDispatched(CallQuestioned::class);
  }

  /**
   * Four humans, board dealt by N.
   */
  private function humanTable(): void
  {
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->startBoard($this->table);
    $this->table->refresh();

    Board::whereKey($this->table->board_id)->update(['dealer' => 'N']);
  }

  /**
   * One human, three robots, N dealing `$hands` (spades.hearts.diamonds.clubs).
   *
   * @param  array<string, string>  $hands
   */
  private function robotTable(string $human, array $hands): void
  {
    $this->players[$human] = User::factory()->create();
    $this->table = Table::create(['created_by' => $this->players[$human]->id, 'moderated_by' => $this->players[$human]->id]);
    app(TableSeatService::class)->seat($this->table, $this->players[$human], $human);

    foreach (array_diff(Seats::SEATS, [$human]) as $seat) {
      app(RobotService::class)->seatRobot($this->table, $seat, $this->players[$human]);
    }

    $this->startBoard($this->table);
    $this->table->refresh();

    Board::whereKey($this->table->board_id)->update(['dealer' => 'N']);

    $ranks = ['A' => 15, 'K' => 14, 'Q' => 13, 'J' => 12, 'T' => 10];

    foreach ($hands as $seat => $hand) {
      foreach (array_combine(['S', 'H', 'D', 'C'], explode('.', $hand)) as $suit => $cards) {
        foreach (str_split($cards) as $rank) {
          $card = Card::where('suit', $suit)->where('rank', $ranks[$rank] ?? (int) $rank)->value('id');

          DB::table('board_card')
            ->where('board_id', $this->table->board_id)
            ->where('card_id', $card)
            ->update(['seat' => $seat]);
        }
      }
    }
  }

  /**
   * Let the robots make their moves until a human is to act.
   */
  private function driveRobots(): void
  {
    while (app(RobotService::class)->act($this->table)) {
      // one move at a time
    }
  }

  /**
   * @param  list<string>  $seats
   */
  private function assertAlertedTo(array $seats, int $index, ?string $explanation): void
  {
    $events = Event::dispatched(CallAlerted::class, fn (CallAlerted $event) => $event->index === $index
      && $event->explanation === $explanation);

    $channels = $events->map(fn ($event) => $event[0]->broadcastOn()[0]->name)->sort()->values()->all();
    $expected = collect($seats)->map(fn ($seat) => 'private-App.Models.User.'.$this->players[$seat]->id)->sort()->values()->all();

    $this->assertSame($expected, $channels);

    $event = $events->first()[0];
    $this->assertSame([
      'table_id' => $this->table->id,
      'playing_id' => app(PlayingStateService::class)->currentPlaying($this->table)->id,
      'index' => $index,
      'explanation' => $explanation,
    ], $event->broadcastWith());
  }

  /**
   * @param  array<string, mixed>  $alert
   */
  private function makeCall(string $seat, string $call, array $alert = []): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => Bid::where('suit', $call)->value('id'), ...$alert]);
  }

  /**
   * Several calls in turn, written `'N 1H, E P, S X'`.
   */
  private function makeCalls(string $calls): void
  {
    foreach (explode(', ', $calls) as $made) {
      [$seat, $call] = explode(' ', $made);
      $this->makeCall($seat, $call)->assertCreated();
    }
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing")->assertOk();
  }

  private function ask(string $seat, int $index): TestResponse
  {
    return $this->actingAs($this->players[$seat])->postJson("/tables/{$this->table->id}/calls/$index/question");
  }

  private function explain(string $seat, int $index, string $explanation): TestResponse
  {
    return $this->actingAs($this->players[$seat])
      ->putJson("/tables/{$this->table->id}/calls/$index/explanation", ['explanation' => $explanation]);
  }
}
