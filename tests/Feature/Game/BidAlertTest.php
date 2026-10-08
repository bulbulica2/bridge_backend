<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\AuctionAlertsShown;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Self-alerts: the bidder marks their own call and says what it means. The
 * opponents see it, partner doesn't, until the auction is over — but a
 * robot's partner does, at once; the opponents may ask about any call, and
 * its bidder (a robot at once) answers.
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

    Event::fake([PlayingUpdated::class, CallAlerted::class, CallQuestioned::class, AuctionAlertsShown::class]);
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

    // passed out, the auction is over all the same: partner is told
    Event::assertDispatched(AuctionAlertsShown::class, fn (AuctionAlertsShown $event) => $event->userId === $this->players['S']->id
      && $event->alerts === [['index' => 0, 'explanation' => 'Weak with clubs']]);

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

  public function test_partners_alerts_are_open_to_all_four_once_the_auction_ends(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C', ['explanation' => self::PRECISION])->assertCreated();
    $this->makeCall('E', 'P')->assertCreated();
    $this->makeCall('S', '1D', ['explanation' => 'Negative: 0–7 HCP'])->assertCreated();
    $this->makeCalls('W P, N P');

    // the auction isn't over yet: partner's alerts are still hidden
    $this->state('S')->assertJsonPath('data.auction.0.alert', null)->assertDontSee('Precision');
    $this->state('N')->assertJsonPath('data.auction.2.alert', null);
    Event::assertNotDispatched(AuctionAlertsShown::class);

    // the last pass: declarer and dummy see each other's alerts
    $this->makeCall('E', 'P')
      ->assertCreated()
      ->assertJsonPath('data.phase', 'play')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION])
      ->assertJsonPath('data.auction.2.alert', ['explanation' => 'Negative: 0–7 HCP']);

    $this->state('S')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION])
      ->assertJsonPath('data.auction.2.alert', ['explanation' => 'Negative: 0–7 HCP']);
    $this->state('N')->assertJsonPath('data.auction.2.alert', ['explanation' => 'Negative: 0–7 HCP']);

    // each partner learns the other's, once; the opponents had them already
    $playing = app(PlayingStateService::class)->currentPlaying($this->table);
    $shown = Event::dispatched(AuctionAlertsShown::class)
      ->mapWithKeys(fn ($event) => [$event[0]->broadcastOn()[0]->name => $event[0]->broadcastWith()])
      ->all();

    $this->assertEqualsCanonicalizing([
      'private-App.Models.User.'.$this->players['S']->id => [
        'table_id' => $this->table->id,
        'playing_id' => $playing->id,
        'alerts' => [['index' => 0, 'explanation' => self::PRECISION]],
      ],
      'private-App.Models.User.'.$this->players['N']->id => [
        'table_id' => $this->table->id,
        'playing_id' => $playing->id,
        'alerts' => [['index' => 2, 'explanation' => 'Negative: 0–7 HCP']],
      ],
    ], $shown);
    Event::assertDispatchedTimes(AuctionAlertsShown::class, 2);
  }

  public function test_nothing_is_shown_when_partner_alerted_nothing(): void
  {
    $this->humanTable();

    $this->makeCall('N', '1C')->assertCreated();
    $this->makeCall('E', '1H', ['explanation' => 'Natural, 5+ hearts'])->assertCreated();
    $this->makeCalls('S P, W P, N P');

    $this->assertSame('play', $this->state('N')->json('data.phase'));

    // only W, whose partner E alerted, is told
    Event::assertDispatchedTimes(AuctionAlertsShown::class, 1);
    Event::assertDispatched(AuctionAlertsShown::class, fn (AuctionAlertsShown $event) => $event->userId === $this->players['W']->id
      && $event->alerts === [['index' => 1, 'explanation' => 'Natural, 5+ hearts']]);
  }

  public function test_in_the_play_an_opponent_still_asks_and_the_answer_reaches_all_four(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C, E P, S 1NT, W P, N P, E P');

    // E asks; partner's question stays theirs, as during the auction
    $this->ask('E', 0)->assertOk()->assertJsonPath('data.auction.0.question', ['asked_by' => 'E']);
    $this->state('W')->assertJsonPath('data.auction.0.question', ['asked_by' => 'E']);
    $this->state('S')->assertJsonPath('data.auction.0.question', null);

    // partner may not ask about their own side's call, in the play either
    $this->ask('S', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', "Ask the opponents about their own calls: that call is your side's.");

    $this->explain('N', 0, self::PRECISION)->assertOk();

    $this->assertAlertedTo(['N', 'E', 'S', 'W'], 0, self::PRECISION);
    $this->state('S')->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION]);
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

    // the natural 1NT isn't alerted, Stayman and its artificial 2♦ answer are
    $this->assertNull($state[0]['alert']);
    $this->assertSame(['explanation' => 'Stayman: 8–17 HCP, asks for a four-card major'], $state[2]['alert']);
    $this->assertSame(['explanation' => 'Stayman answer: no four-card major'], $state[4]['alert']);
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
   * Robot N's alerted calls, with a human partner (S) and a human opponent
   * (E): the dealer, N's hand (and W's, else W gets the smallest cards and
   * passes throughout), the auction up to N's alerted call, and its
   * explanation.
   */
  public static function robotConventions(): array
  {
    return [
      'the strong 2♣' => ['N', ['N' => 'AKQ2.AKQ2.AK2.A2'], ['2C'], 'Strong 2♣: 22+ HCP, artificial, forcing'],
      'the 2♦ waiting answer' => ['N', ['N' => '432.432.5432.432'], ['P', 'P', '2C', 'P', '2D'], 'Waiting: artificial, any strength'],
      'Stayman' => ['N', ['N' => 'KJ32.Q32.K32.432'], ['P', 'P', '1NT', 'P', '2C'], 'Stayman: 8–17 HCP, asks for a four-card major'],
      'a transfer' => ['N', ['N' => '32.KJ432.432.432'], ['P', 'P', '1NT', 'P', '2D'], 'Transfer: 0–17 HCP, 5+ ♥, asks partner to bid ♥'],
      'Gerber' => ['S', ['N' => 'AQ2.AQ3.KQ32.Q32'], ['1NT', 'P', '4C'], 'Gerber: 18+ HCP, asks for aces'],
      'the answer to Gerber' => ['N', ['N' => 'AK2.KQ2.A432.432'], ['1NT', 'P', '4C', 'P', '4S'], 'Aces: 2 aces'],
      'Blackwood' => ['N', ['N' => 'A2.AKQ32.KQ32.K2'], ['1H', 'P', '4H', 'P', '4NT'], 'Blackwood: 20+ HCP, asks for aces'],
      'the answer to Blackwood' => ['S', ['N' => 'A32.KJ32.A32.432'], ['1H', 'P', '3H', 'P', '4NT', 'P', '5H'], 'Aces: 2 aces'],
      'fourth suit forcing' => ['S', ['N' => 'A32.AQ432.KQ2.32'], ['1D', 'P', '1H', 'P', '1S', 'P', '2C'], 'Fourth suit forcing: 15+ HCP, artificial, forcing to game'],
      'a negative double' => ['S', ['N' => 'A4.QJ65.J654.765', 'W' => 'KQJ32.K32.32.432'], ['1C', '1S', 'X'], 'Negative double: 6+ HCP, 4+ ♥'],
      'a penalty double of their 1NT' => ['E', ['N' => 'AK2.KQ32.KJ2.Q32'], ['1NT', 'P', 'P', 'X'], 'Penalty double: 15+ HCP'],
      'a penalty double of their 1NT overcall' => ['S', ['N' => 'QJ2.KQ43.K32.J32', 'W' => 'AK3.AJ2.AQ5.T987'], ['1D', '1NT', 'X'], 'Penalty double: 10+ HCP'],
      'a penalty double of their suit over our 1NT' => ['S', ['N' => 'Q43.KT98.AJ5.765', 'W' => 'K2.AQJ32.432.K32'], ['1NT', '2H', 'X'], 'Penalty double: 8+ HCP, 4+ ♥'],
      'a penalty double of their low suit later' => ['N', ['N' => 'K2.AQJ43.2.KQJ32', 'W' => 'Q3.K2.A876.AT987'], ['1H', 'P', '1S', '2C', 'X'], 'Penalty double: 14+ HCP, 4+ ♣'],
      'a cue bid' => ['E', ['N' => 'K2.AQ32.KJ32.432'], ['1C', '1S', 'P', '2C'], 'Cue bid: 12+ HCP, forcing, says nothing about ♣'],
      'extra values over a cue bid' => ['W', ['N' => 'AKJ32.AQ32.32.32', 'W' => 'Q4.K54.54.AKJ654'], ['1C', '1S', 'P', '2C', 'P', '2H'], 'Extra values: 14+ HCP, 4+ ♥'],
      'the 2♦ answer to Stayman' => ['N', ['N' => 'AK2.KQ2.A432.432'], ['1NT', 'P', '2C', 'P', '2D'], 'Stayman answer: no four-card major'],
      'completing a transfer' => ['N', ['N' => 'AK2.KQ2.A432.432'], ['1NT', 'P', '2D', 'P', '2H'], 'Completes the transfer'],
      'a choice of games' => ['S', ['N' => 'K32.KQ432.Q32.32'], ['1NT', 'P', '2D', 'P', '2H', 'P', '3NT'], 'Choice of games: 10–15 HCP, 5+ ♥'],
      'a quantitative 4NT' => ['S', ['N' => 'KQ2.KQ2.AJ32.J32'], ['1NT', 'P', '4NT'], 'Quantitative: 16–17 HCP, invites 6NT'],
      'a weak two' => ['N', ['N' => '32.KQJ432.432.32'], ['2H'], 'Weak two: 5–11 HCP, 6+ ♥'],
      'a preempt' => ['N', ['N' => '2.32.KQJ5432.432'], ['3D'], 'Preempt: 5–10 HCP, 7+ ♦'],
      'a jump shift' => ['S', ['N' => 'A2.AKQ32.KQ2.K32'], ['1C', 'P', '2H'], 'Jump shift: 19+ HCP, 4+ ♥, forcing to game'],
      'a limit raise' => ['S', ['N' => 'K32.Q432.A32.K32'], ['1H', 'P', '3H'], 'Limit raise: 11–12 HCP, 3+ ♥, invites game'],
      'a game raise' => ['S', ['N' => 'A32.Q432.A32.K32'], ['1H', 'P', '4H'], 'Game raise: 13+ HCP, 3+ ♥'],
      'the 2NT answer' => ['S', ['N' => 'KQ2.32.AQ32.K432'], ['1H', 'P', '2NT'], 'Response: 13–15 HCP, balanced'],
      'a reverse' => ['N', ['N' => 'A2.AKQ2.KQ432.32'], ['1D', 'P', '1S', 'P', '2H'], 'Reverse: 17–18 HCP, 4+ ♥, forcing'],
      'the 2NT negative after 2♣' => ['S', ['N' => '5432.32.5432.432'], ['2C', 'P', '2D', 'P', '2H', 'P', '2NT'], 'Negative: 0–7 HCP'],
      'a weak jump overcall' => ['W', ['N' => '32.KQJ432.432.32', 'W' => 'AK54.A65.65.K654'], ['1C', '2H'], 'Weak jump overcall: 5–10 HCP, 6+ ♥'],
      'a balancing 1NT' => ['E', ['N' => 'K32.KQ2.Q432.K32'], ['1H', 'P', 'P', '1NT'], 'Balancing 1NT: 11–14 HCP, balanced, ♥ stopped'],
      'a jump raise of an overcall' => ['E', ['N' => 'K32.Q32.A432.Q32'], ['1C', '1H', 'P', '3H'], 'Jump raise: 11 HCP, 3+ ♥, invites game'],
    ];
  }

  /**
   * @param  array<string, string>  $hands
   * @param  list<string>  $auction
   */
  #[DataProvider('robotConventions')]
  public function test_a_robots_alert_reaches_its_human_opponent_and_partner(string $dealer, array $hands, array $auction, string $explanation): void
  {
    $this->partnersTable($dealer, $hands);
    $this->bid($dealer, $auction);

    $index = count($auction) - 1;
    $state = $this->state('E')
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.turn', 'E')
      ->json('data.auction');

    $this->assertSame($auction, array_map(fn ($call) => $call['bid']['call'], $state));
    $this->assertSame('N', $state[$index]['seat']);
    $this->assertSame(['explanation' => $explanation], $state[$index]['alert']);

    // its human partner learns of it at once, as the opponent does
    $this->assertAlertedTo(['E', 'S'], $index, $explanation);
    $this->state('S')->assertJsonPath("data.auction.$index.alert", ['explanation' => $explanation]);
  }

  /**
   * Robot N's natural calls, as `robotConventions()`: the call isn't
   * alerted, to anyone.
   */
  public static function robotNaturalCalls(): array
  {
    return [
      'a one-level opening' => ['N', ['N' => 'AK32.K32.Q32.432'], ['1C']],
      'a 1NT opening' => ['N', ['N' => 'AK2.KQ2.A432.432'], ['1NT']],
      'a simple raise' => ['S', ['N' => 'K32.Q432.K32.432'], ['1H', 'P', '2H']],
      'a new suit answer' => ['S', ['N' => 'KQ32.32.A432.432'], ['1C', 'P', '1S']],
      'an overcall' => ['W', ['N' => '32.AKJ32.432.432', 'W' => 'AK54.Q65.65.K765'], ['1C', '1H']],
      'a takeout double' => ['W', ['N' => 'QJ32.AK32.KQ32.2', 'W' => 'AK54.Q65.65.K765'], ['1C', 'X']],
      'a pass' => ['N', ['N' => '432.432.5432.432'], ['P']],
    ];
  }

  /**
   * @param  array<string, string>  $hands
   * @param  list<string>  $auction
   */
  #[DataProvider('robotNaturalCalls')]
  public function test_a_robots_natural_call_is_not_alerted(string $dealer, array $hands, array $auction): void
  {
    $this->partnersTable($dealer, $hands);
    $this->bid($dealer, $auction);

    $index = count($auction) - 1;
    $state = $this->state('E')->assertJsonPath('data.turn', 'E')->json('data.auction');

    $this->assertSame($auction, array_map(fn ($call) => $call['bid']['call'], $state));
    $this->assertSame('N', $state[$index]['seat']);
    $this->assertNull($state[$index]['alert']);
    $this->state('S')->assertJsonPath("data.auction.$index.alert", null);
    $this->assertFalse(Auction::orderBy('id')->get()[$index]->alerted);
    Event::assertNotDispatched(CallAlerted::class);
  }

  public function test_a_robots_alert_reaches_all_three_humans(): void
  {
    $this->partnersTable('N', ['N' => 'AKQ2.AKQ2.AK2.A2'], humans: ['E', 'S', 'W']);
    $this->driveRobots();

    $explanation = 'Strong 2♣: 22+ HCP, artificial, forcing';
    $this->assertAlertedTo(['E', 'S', 'W'], 0, $explanation);

    foreach (['E', 'S', 'W'] as $seat) {
      $this->state($seat)->assertJsonPath('data.auction.0.alert', ['explanation' => $explanation]);
    }
  }

  public function test_a_humans_alert_never_reaches_a_robot_partner(): void
  {
    // S, a human, alerts their 1C opposite robot N: E (human) is told, the
    // robot's own state doesn't show it
    $this->partnersTable('S', ['N' => '432.432.5432.432']);

    $this->makeCall('S', '1C', ['explanation' => self::PRECISION])->assertCreated();
    $this->driveRobots();

    $this->assertAlertedTo(['E'], 0, self::PRECISION);
    $this->state('E')->assertJsonPath('data.auction.0.alert', ['explanation' => self::PRECISION]);

    $robot = User::whereKey(Auction::orderBy('id')->skip(2)->first()->user_id)->sole();
    $this->assertTrue($robot->is_robot);
    $this->assertNull(app(PlayingStateService::class)->stateFor($this->table, $robot)['auction'][0]['alert']);
  }

  public function test_a_robots_alerts_are_not_shown_again_when_the_auction_ends(): void
  {
    // N 1NT, S Stayman, N's alerted 2♦, S 3NT: S had N's alert at once
    $this->partnersTable('N', ['N' => 'AK2.KQ2.A432.432']);
    $this->bid('N', ['1NT', 'P', '2C', 'P', '2D', 'P', '3NT', 'P', 'P', 'P']);

    $this->state('S')
      ->assertJsonPath('data.phase', 'play')
      ->assertJsonPath('data.auction.4.alert', ['explanation' => 'Stayman answer: no four-card major']);

    // N is a robot, and W's partner E alerted nothing: nobody is told
    $this->assertAlertedTo(['E', 'S'], 4, 'Stayman answer: no four-card major');
    Event::assertNotDispatched(AuctionAlertsShown::class);
  }

  public function test_a_robots_answer_to_a_question_reaches_its_partner_too(): void
  {
    // N's 1NT isn't alerted; E asks, and N's answer reaches S as well
    $this->partnersTable('N', ['N' => 'AK2.KQ2.A432.432']);
    $this->driveRobots();

    $this->ask('E', 0)->assertOk()->assertJsonPath('message', 'Question answered.');

    $this->assertAlertedTo(['E', 'S'], 0, 'Opening: 15–17 HCP, balanced');
    $this->state('S')->assertJsonPath('data.auction.0.alert', ['explanation' => 'Opening: 15–17 HCP, balanced']);

    // a question is still the opponents' alone
    $this->ask('S', 0)
      ->assertStatus(409)
      ->assertJsonPath('message', "Ask the opponents about their own calls: that call is your side's.");
  }

  public function test_a_kibitzer_still_sees_no_robots_explanation_during_the_auction(): void
  {
    $this->partnersTable('N', ['N' => 'AKQ2.AKQ2.AK2.A2']);
    $this->driveRobots();

    $kibitzer = User::factory()->create();
    $this->actingAs($kibitzer)->postJson("/tables/{$this->table->id}/kibitzers")->assertSuccessful();

    $this->actingAs($kibitzer)->getJson("/tables/{$this->table->id}/playing")
      ->assertOk()
      ->assertJsonPath('data.auction.0.alert', ['explanation' => null]);
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
   * Humans E and S (or `$humans`), robots in the other seats, `$dealer`
   * dealing: N holds its hand (spades.hearts.diamonds.clubs), W its own or
   * else the 13 smallest cards left, and E and S the rest.
   *
   * @param  array<string, string>  $hands
   * @param  list<string>  $humans
   */
  private function partnersTable(string $dealer, array $hands, array $humans = ['E', 'S']): void
  {
    foreach ($humans as $seat) {
      $this->players[$seat] = User::factory()->create();
    }

    $this->table = Table::create(['created_by' => $this->players['S']->id, 'moderated_by' => $this->players['S']->id]);

    foreach (Seats::SEATS as $seat) {
      isset($this->players[$seat])
        ? app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat)
        : app(RobotService::class)->seatRobot($this->table, $seat, $this->players['S']);
    }

    $this->startBoard($this->table);
    $this->table->refresh();

    Board::whereKey($this->table->board_id)->update(['dealer' => $dealer]);

    $ranks = ['A' => 15, 'K' => 14, 'Q' => 13, 'J' => 12, 'T' => 10];
    $cards = Card::orderBy('rank')->orderBy('id')->get()->keyBy(fn (Card $card) => $card->suit.$card->rank);
    $seats = [];

    foreach ($hands as $seat => $hand) {
      foreach (array_combine(['S', 'H', 'D', 'C'], explode('.', $hand)) as $suit => $ranksHeld) {
        foreach (str_split($ranksHeld) as $rank) {
          $seats[$cards[$suit.($ranks[$rank] ?? $rank)]->id] = $seat;
        }
      }
    }

    $left = $cards->reject(fn (Card $card) => isset($seats[$card->id]))->values();
    $rest = isset($hands['W']) ? ['E', 'S'] : ['W', 'E', 'S'];

    foreach ($left as $position => $card) {
      $seats[$card->id] = $rest[intdiv($position, 13)];
    }

    $this->assertCount(52, $seats);

    foreach ($seats as $card => $seat) {
      DB::table('board_card')->where('board_id', $this->table->board_id)->where('card_id', $card)->update(['seat' => $seat]);
    }
  }

  /**
   * The calls of `$auction` in turn from `$dealer`: the humans' through the
   * endpoint, the robots' made by themselves (the caller checks them).
   *
   * @param  list<string>  $auction
   */
  private function bid(string $dealer, array $auction): void
  {
    $seat = $dealer;

    foreach ($auction as $call) {
      if (isset($this->players[$seat])) {
        $this->makeCall($seat, $call)->assertCreated();
      }

      $this->driveRobots();
      $seat = Seats::next($seat);
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
