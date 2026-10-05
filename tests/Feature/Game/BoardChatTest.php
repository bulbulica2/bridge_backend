<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Events\BoardMessageSent;
use App\Events\CallAlerted;
use App\Events\CallQuestioned;
use App\Events\PlayingUpdated;
use App\Exceptions\IllegalMessageException;
use App\Models\Bid;
use App\Models\Board;
use App\Models\BoardMessage;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Models\UserBan;
use App\Robots\RobotCarding;
use App\Services\AuctionService;
use App\Services\BoardChatService;
use App\Services\PlayingStateService;
use App\Services\RobotService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The board's chat: a message to the table reaches all four in every
 * phase, one to the opponents never reaches partner until the board is
 * finished; once it is, everything said is public, and in the review. A
 * question about a robot's call, or a robot defender's card, gets the
 * robot's answer at once.
 */
class BoardChatTest extends TestCase
{
  use RefreshDatabase;

  private Table $table;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    Event::fake([PlayingUpdated::class, BoardMessageSent::class, CallAlerted::class, CallQuestioned::class]);
  }

  public function test_a_message_to_the_opponents_reaches_them_and_not_partner(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1NT');

    $this->send('E', ['to' => 'opponents', 'body' => '  Is that 15–17?  ', 'call_index' => 0])
      ->assertCreated()
      ->assertJsonPath('message', 'Message sent.')
      ->assertJsonPath('data.seat', 'E')
      ->assertJsonPath('data.user_id', $this->players['E']->id)
      ->assertJsonPath('data.to', 'opponents')
      ->assertJsonPath('data.call_index', 0)
      ->assertJsonPath('data.body', 'Is that 15–17?');

    $message = BoardMessage::sole();
    $this->assertSame('Is that 15–17?', $message->body);

    foreach (['E', 'N', 'S'] as $reader) {
      $this->messages($reader)
        ->assertJsonPath('data.playing_id', $this->playing()->id)
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.id', $message->id)
        ->assertJsonPath('data.messages.0.body', 'Is that 15–17?');
    }

    // partner, W, hears nothing of it
    $this->messages('W')
      ->assertJsonCount(0, 'data.messages')
      ->assertDontSee('15–17');

    $this->assertSentTo(['E', 'N', 'S'], $message);

    // and nothing goes on the table channel
    Event::assertDispatched(PlayingUpdated::class);
    Event::assertNotDispatched(PlayingUpdated::class, fn (PlayingUpdated $event) => str_contains(json_encode($event->broadcastWith()), '15–17'));

    // a human bidder isn't answered for: N answers in their own words
    $this->send('N', ['to' => 'opponents', 'body' => 'Yes, 15–17 balanced', 'call_index' => 0])->assertCreated();

    // which E's partner, W, hears, and N's partner, S, doesn't
    $this->assertSame(2, BoardMessage::count());
    $this->messages('S')->assertJsonCount(1, 'data.messages')->assertDontSee('balanced');
    $this->messages('W')
      ->assertJsonCount(1, 'data.messages')
      ->assertJsonPath('data.messages.0.body', 'Yes, 15–17 balanced');
  }

  public function test_a_message_to_the_table_reaches_all_four_while_the_board_is_bid_and_played(): void
  {
    $this->humanTable();

    $this->send('N', ['to' => 'table', 'body' => 'Good luck, partner'])
      ->assertCreated()
      ->assertJsonPath('data.to', 'table')
      ->assertJsonPath('data.card_index', null);
    $this->assertSentTo(Seats::SEATS, BoardMessage::sole());

    $this->makeCalls('N 1C, E P, S P, W P');
    $this->assertSame('play', app(PlayingStateService::class)->phase($this->playing()));

    $this->send('S', ['to' => 'table', 'body' => 'Sorry, I should have bid'])->assertCreated();
    $this->assertSentTo(Seats::SEATS, BoardMessage::orderByDesc('id')->first());

    // an opponents message still never reaches partner
    $this->send('E', ['to' => 'opponents', 'body' => 'We lead fourth best'])->assertCreated();
    $this->assertSentTo(['E', 'N', 'S'], BoardMessage::orderByDesc('id')->first());

    foreach (['N', 'E', 'S'] as $reader) {
      $this->messages($reader)->assertJsonCount(3, 'data.messages');
    }

    // W reads both table messages, from partner and from an opponent
    $this->messages('W')
      ->assertJsonCount(2, 'data.messages')
      ->assertJsonPath('data.messages.0.body', 'Good luck, partner')
      ->assertJsonPath('data.messages.1.body', 'Sorry, I should have bid')
      ->assertDontSee('fourth best');
  }

  public function test_between_boards_a_message_to_the_table_reaches_all_four(): void
  {
    $this->humanTable();
    $this->makeCalls('N P');

    $this->send('N', ['to' => 'opponents', 'body' => 'Weak with clubs'])->assertCreated();
    $this->send('W', ['to' => 'table', 'body' => 'Hello all'])->assertCreated();

    $this->makeCalls('E P, S P, W P');
    $this->assertNotNull($this->playing()->finished_at);

    $this->send('S', ['to' => 'table', 'body' => 'Well passed'])->assertCreated();

    $message = BoardMessage::orderByDesc('id')->first();
    $this->assertSentTo(Seats::SEATS, $message);

    // and everyone, partner included, reads everything said at the board now
    foreach (Seats::SEATS as $reader) {
      $this->messages($reader)
        ->assertJsonCount(3, 'data.messages')
        ->assertJsonPath('data.messages.0.body', 'Weak with clubs')
        ->assertJsonPath('data.messages.1.body', 'Hello all')
        ->assertJsonPath('data.messages.2.body', 'Well passed');
    }

    // opponents is the same as table now
    $this->send('E', ['to' => 'opponents', 'body' => 'Next'])->assertCreated();
    $this->assertSentTo(Seats::SEATS, BoardMessage::orderByDesc('id')->first());

    $this->actingAs($this->players['S'])->getJson("/playings/{$this->playing()->id}")
      ->assertOk()
      ->assertJsonCount(4, 'data.messages')
      ->assertJsonPath('data.messages.0.body', 'Weak with clubs')
      ->assertJsonPath('data.messages.0.seat', 'N')
      ->assertJsonPath('data.messages.0.to', 'opponents')
      ->assertJsonPath('data.messages.1.to', 'table')
      ->assertJsonPath('data.messages.3.body', 'Next');

    // the live state carries no chat
    $this->actingAs($this->players['S'])->getJson("/tables/{$this->table->id}/playing")
      ->assertOk()
      ->assertJsonMissingPath('data.messages');
  }

  public function test_a_robot_answers_a_question_about_its_transfer_at_once(): void
  {
    // N opens 1NT, the human passes, S transfers to spades with 2H, W
    // passes and N completes the transfer: then it is the human's turn
    $this->robotTable(human: 'E', hands: [
      'N' => 'AK2.KQ2.A432.432',
      'E' => 'JT9.T98.QJT8.AKQ',
      'S' => 'Q8765.J43.K5.876',
      'W' => '43.A765.976.JT95',
    ]);

    $this->driveRobots();
    $this->makeCalls('E P');
    $this->driveRobots();

    $auction = $this->state('E')->assertJsonPath('data.turn', 'E')->json('data.auction');
    $this->assertSame(['1NT', 'P', '2H', 'P', '2S'], array_map(fn ($call) => $call['bid']['call'], $auction));

    $this->send('E', ['to' => 'opponents', 'body' => 'What does 2♥ show?', 'call_index' => 2])->assertCreated();

    $messages = $this->messages('E')->assertJsonCount(2, 'data.messages')->json('data.messages');

    $this->assertSame(['E', 'S'], array_column($messages, 'seat'));
    $this->assertSame([2, 2], array_column($messages, 'call_index'));
    $this->assertSame(['opponents', 'opponents'], array_column($messages, 'to'));
    $this->assertSame($this->playing()->seats->firstWhere('seat', 'S')->user_id, $messages[1]['user_id']);
    $this->assertStringStartsWith('Transfer: 0–', $messages[1]['body']);
    $this->assertStringContainsString('5+ ♠', $messages[1]['body']);

    // only the human hears it: robots get no events
    Event::assertDispatchedTimes(BoardMessageSent::class, 2);
    $this->assertSentTo(['E'], BoardMessage::orderByDesc('id')->first());

    // a robot doesn't otherwise chat: no answer to a plain message, nor to
    // one about the human's own call
    $this->send('E', ['to' => 'opponents', 'body' => 'Thanks'])->assertCreated();
    $this->send('E', ['to' => 'opponents', 'body' => 'I passed', 'call_index' => 1])->assertCreated();

    $this->assertSame(4, BoardMessage::count());
  }

  public function test_a_robot_does_not_answer_about_its_partners_call_to_its_partner(): void
  {
    // the human is S, whose robot partner N opened: no answer comes
    $this->robotTable(human: 'S', hands: [
      'N' => 'AK2.KQ2.A432.432',
      'E' => 'JT9.T98.QJT8.AKQ',
      'S' => 'Q8765.J43.K5.876',
      'W' => '43.A765.976.JT95',
    ]);

    $this->driveRobots();

    $this->send('S', ['to' => 'opponents', 'body' => 'Partner?', 'call_index' => 0])->assertCreated();

    $this->assertSame(1, BoardMessage::count());
  }

  public function test_a_robot_defender_answers_a_question_about_its_card(): void
  {
    // robot N declares 3NT and the human S, dummy, plays both hands; robot
    // E leads the top of its diamond sequence
    $this->robotTable(human: 'S', hands: [
      'N' => 'AK2.KQ2.A432.432',
      'E' => 'JT9.T98.QJT8.AKQ',
      'S' => 'Q8765.J43.K5.876',
      'W' => '43.A765.976.JT95',
    ]);

    $this->makeCalls('N 1NT, E P, S 3NT, W P, N P, E P');
    $this->driveRobots();

    $this->send('S', ['to' => 'table', 'body' => 'What do you lead?', 'card_index' => 0])
      ->assertCreated()
      ->assertJsonPath('data.card_index', 0)
      ->assertJsonPath('data.call_index', null);

    $answer = BoardMessage::orderByDesc('id')->first();
    $this->assertSame('E', $answer->seat);
    $this->assertSame('table', $answer->to);
    $this->assertSame(0, $answer->card_index);
    $this->assertNull($answer->call_index);
    $this->assertSame(RobotCarding::OPENING_LEAD, $answer->body);
    $this->assertSentTo(['S'], $answer);

    // dummy's card, then W follows to partner's lead: attitude
    $diamonds = array_filter($this->state('S')->json('data.dummy_hand'), fn ($card) => $card['suit'] === 'D');
    $this->playCard('S', reset($diamonds)['id']);
    $this->driveRobots();

    $this->send('S', ['to' => 'opponents', 'body' => 'Was that a signal?', 'card_index' => 2])->assertCreated();
    $this->assertSame(RobotCarding::ATTITUDE, BoardMessage::orderByDesc('id')->value('body'));
    $this->assertSame('W', BoardMessage::orderByDesc('id')->value('seat'));
    $this->assertSame('opponents', BoardMessage::orderByDesc('id')->value('to'));

    // no answer about a card of the asker's own side
    $this->send('S', ['to' => 'table', 'body' => 'Oops', 'card_index' => 1])->assertCreated();

    $this->assertSame(5, BoardMessage::count());
    $this->messages('S')->assertJsonCount(5, 'data.messages');
  }

  public function test_a_card_question_names_a_card_already_played(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C, E P, S P, W P');

    $this->send('N', ['to' => 'table', 'body' => 'Hi', 'card_index' => 0])
      ->assertUnprocessable()
      ->assertJsonValidationErrors(['card_index' => 'There is no card 0 in the play.']);
    $this->send('N', ['to' => 'table', 'body' => 'Hi', 'card_index' => -1])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('card_index');

    $this->playCard('E', $this->state('E')->json('data.hand.0.id'));

    $this->send('N', ['to' => 'table', 'body' => 'Hi', 'card_index' => 0, 'call_index' => 0])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('card_index');

    // a human's card gets no robot's answer: E answers in their own words
    $this->send('N', ['to' => 'opponents', 'body' => 'Fourth best?', 'card_index' => 0])->assertCreated();
    $this->assertSame(1, BoardMessage::count());

    $this->expectException(IllegalMessageException::class);
    $this->expectExceptionMessage('There is no card 1 in the play.');

    app(BoardChatService::class)->send($this->table, $this->players['N'], 'Hi', BoardMessage::TO_TABLE, null, 1);
  }

  public function test_asking_and_explaining_a_call_go_into_the_chat(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C, E P');

    $this->actingAs($this->players['W'])->postJson("/tables/{$this->table->id}/calls/0/question")->assertOk();
    $this->actingAs($this->players['N'])
      ->putJson("/tables/{$this->table->id}/calls/0/explanation", ['explanation' => 'Precision: 16+ HCP'])
      ->assertOk();
    $this->actingAs($this->players['S'])->postJson("/tables/{$this->table->id}/calls/1/question")->assertOk();

    // W hears all three: their own question, N's answer and S's question
    $messages = $this->messages('W')->assertJsonCount(3, 'data.messages')->json('data.messages');

    $this->assertSame(['W', 'N', 'S'], array_column($messages, 'seat'));
    $this->assertSame(['What does 1C mean?', 'Precision: 16+ HCP', 'What does Pass mean?'], array_column($messages, 'body'));
    $this->assertSame([0, 0, 1], array_column($messages, 'call_index'));
    $this->assertSame(['opponents', 'opponents', 'opponents'], array_column($messages, 'to'));

    // each partner misses what the other side said to them: S, N's answer;
    // N, S's question; E, W's question
    $this->messages('S')->assertJsonCount(2, 'data.messages')->assertDontSee('Precision');
    $this->messages('N')->assertJsonCount(2, 'data.messages')->assertDontSee('Pass mean');
    $this->messages('E')->assertJsonCount(2, 'data.messages')->assertDontSee('1C mean');
  }

  public function test_a_robot_asked_through_the_question_endpoint_answers_in_the_chat(): void
  {
    $this->robotTable(human: 'E', hands: [
      'N' => 'AK2.KQ2.A432.432',
      'E' => 'JT9.T98.QJT8.AKQ',
      'S' => 'Q8765.J43.K5.876',
      'W' => '43.A765.976.JT95',
    ]);

    $this->driveRobots();

    $this->actingAs($this->players['E'])->postJson("/tables/{$this->table->id}/calls/0/question")->assertOk();

    $this->messages('E')
      ->assertJsonCount(2, 'data.messages')
      ->assertJsonPath('data.messages.0.body', 'What does 1NT mean?')
      ->assertJsonPath('data.messages.1.seat', 'N')
      ->assertJsonPath('data.messages.1.body', 'Opening: 15–17 HCP, balanced');
  }

  public function test_the_question_names_a_double_and_a_redouble(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C, E X, S XX');

    $this->actingAs($this->players['N'])->postJson("/tables/{$this->table->id}/calls/1/question")->assertOk();
    $this->actingAs($this->players['E'])->postJson("/tables/{$this->table->id}/calls/2/question")->assertOk();

    $this->assertSame(['What does Double mean?', 'What does Redouble mean?'], BoardMessage::orderBy('id')->pluck('body')->all());
  }

  public function test_a_message_is_validated(): void
  {
    $this->humanTable();
    $this->makeCalls('N 1C');

    $this->send('E', ['to' => 'opponents'])->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->send('E', ['to' => 'opponents', 'body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
    $this->send('E', ['to' => 'opponents', 'body' => str_repeat('a', BoardMessage::BODY_MAX + 1)])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('body');
    $this->send('E', ['body' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->send('E', ['to' => 'partner', 'body' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->send('E', ['to' => 'opponents', 'body' => 'Hi', 'call_index' => -1])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('call_index');
    $this->send('E', ['to' => 'opponents', 'body' => 'Hi', 'call_index' => 'first'])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('call_index');
    $this->send('E', ['to' => 'opponents', 'body' => 'Hi', 'call_index' => 1])
      ->assertUnprocessable()
      ->assertJsonValidationErrors(['call_index' => 'There is no call 1 in the auction.']);

    $this->assertSame(0, BoardMessage::count());

    $this->send('E', ['to' => 'opponents', 'body' => str_repeat('a', BoardMessage::BODY_MAX), 'call_index' => 0])->assertCreated();
  }

  public function test_sending_is_throttled(): void
  {
    $this->humanTable();

    for ($i = 1; $i <= 10; $i++) {
      $this->send('N', ['to' => 'opponents', 'body' => "Message $i"])->assertCreated();
    }

    $this->send('N', ['to' => 'opponents', 'body' => 'One too many'])->assertTooManyRequests();

    // per player
    $this->send('E', ['to' => 'opponents', 'body' => 'Mine'])->assertCreated();

    $this->travel(31)->seconds();

    $this->send('N', ['to' => 'opponents', 'body' => 'Again'])->assertCreated();
  }

  public function test_only_seated_players_may_chat(): void
  {
    $this->humanTable();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->getJson("/tables/{$this->table->id}/messages")->assertForbidden();
    $this->actingAs($stranger)
      ->postJson("/tables/{$this->table->id}/messages", ['to' => 'opponents', 'body' => 'Hi'])
      ->assertForbidden()
      ->assertJsonPath('message', 'Only the players seated at this table can chat there.');

    $this->expectException(IllegalMessageException::class);
    $this->expectExceptionMessage('You are not seated at this table.');

    app(BoardChatService::class)->send($this->table, $stranger, 'Hi', BoardMessage::TO_OPPONENTS);
  }

  public function test_a_banned_player_cannot_send_and_their_messages_stay(): void
  {
    $this->humanTable();

    $this->send('N', ['to' => 'opponents', 'body' => 'Before the ban'])->assertCreated();

    UserBan::create([
      'user_id' => $this->players['N']->id,
      'banned_by' => User::factory()->create(['is_admin' => true])->id,
      'reason' => 'Rude',
      'banned_at' => now(),
      'until' => now()->addDay(),
    ]);

    $this->send('N', ['to' => 'opponents', 'body' => 'After the ban'])->assertForbidden();

    $this->messages('E')
      ->assertJsonCount(1, 'data.messages')
      ->assertJsonPath('data.messages.0.body', 'Before the ban');
  }

  public function test_the_chat_opens_with_the_first_deal(): void
  {
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->messages('N')
      ->assertJsonPath('data.playing_id', null)
      ->assertJsonPath('data.messages', []);

    $this->send('N', ['to' => 'table', 'body' => 'Hello'])
      ->assertStatus(409)
      ->assertJsonPath('message', 'The table has no board yet: the chat opens with the first deal.');

    $this->send('N', ['to' => 'opponents', 'body' => 'Hello', 'call_index' => 0])
      ->assertUnprocessable()
      ->assertJsonValidationErrors('call_index');
  }

  public function test_a_call_that_is_gone_by_the_time_the_message_is_sent_is_refused(): void
  {
    $this->humanTable();

    $this->expectException(IllegalMessageException::class);
    $this->expectExceptionMessage('There is no call 0 in the auction.');

    app(BoardChatService::class)->send($this->table, $this->players['N'], 'Hi', BoardMessage::TO_OPPONENTS, 0);
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
   * `$message` went to exactly the players in `$seats`, each on their own
   * channel.
   *
   * @param  list<string>  $seats
   */
  private function assertSentTo(array $seats, BoardMessage $message): void
  {
    $events = Event::dispatched(BoardMessageSent::class, fn (BoardMessageSent $event) => $event->message['id'] === $message->id);

    $channels = $events->map(fn ($event) => $event[0]->broadcastOn()[0]->name)->sort()->values()->all();
    $expected = collect($seats)->map(fn ($seat) => 'private-App.Models.User.'.$this->players[$seat]->id)->sort()->values()->all();

    $this->assertSame($expected, $channels);

    $wire = $events->first()[0]->broadcastWith();
    $this->assertSame($this->table->id, $wire['table_id']);
    $this->assertSame($message->board_table_id, $wire['playing_id']);
    $this->assertSame($message->body, $wire['message']['body']);
    $this->assertSame($message->seat, $wire['message']['seat']);
  }

  private function playing(): BoardTable
  {
    return app(PlayingStateService::class)->currentPlaying($this->table);
  }

  /**
   * Several calls in turn, written `'N 1H, E P, S X'`; a robot's are made
   * for it.
   */
  private function makeCalls(string $calls): void
  {
    foreach (explode(', ', $calls) as $made) {
      [$seat, $call] = explode(' ', $made);
      $bid = Bid::where('suit', $call)->firstOrFail();

      if (! isset($this->players[$seat])) {
        app(AuctionService::class)->call($this->table, $this->playing()->seats->firstWhere('seat', $seat)->user, $bid);

        continue;
      }

      $this->actingAs($this->players[$seat])
        ->postJson("/tables/{$this->table->id}/calls", ['bid_id' => $bid->id])
        ->assertCreated();
    }
  }

  private function playCard(string $seat, int $cardId): void
  {
    $this->actingAs($this->players[$seat])
      ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $cardId])
      ->assertCreated();
  }

  /**
   * @param  array<string, mixed>  $message
   */
  private function send(string $seat, array $message): TestResponse
  {
    return $this->actingAs($this->players[$seat])->postJson("/tables/{$this->table->id}/messages", $message);
  }

  private function messages(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/messages")->assertOk();
  }

  private function state(string $seat): TestResponse
  {
    return $this->actingAs($this->players[$seat])->getJson("/tables/{$this->table->id}/playing")->assertOk();
  }
}
