<?php

namespace Tests\Feature\Game;

use App\auxiliary\Seats;
use App\Broadcasting\PusherBody;
use App\Events\BoardMessageSent;
use App\Events\CallAlerted;
use App\Events\CallQuestioned;
use App\Events\DeclarerHandShown;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Events\UserBanned;
use App\Http\Resources\PlayingResource;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\BoardMessage;
use App\Models\BoardTable;
use App\Models\Card;
use App\Models\Table;
use App\Models\User;
use App\Models\UserBan;
use App\Services\AuctionService;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Every broadcast must fit in hosted Pusher's 10 KB per event
 * (`PusherBody::BUDGET`, leaving room for the HTTP request around it), or it
 * fails in the queue and the table never sees that state. These build the
 * largest payload each event can carry: the longest legal auction (319
 * calls), every trick played or a 13-card claim pending, and every name at
 * its limit in emoji, which JSON escapes twice to 14 bytes a character.
 */
class BroadcastSizeTest extends TestCase
{
  use RefreshDatabase;

  /**
   * Two UTF-16 code units, `😀` in JSON: the most bytes one
   * character can cost.
   */
  private const EMOJI = ['N' => '😀', 'E' => '😁', 'S' => '😂', 'W' => '😃'];

  private Table $table;

  private BoardTable $playing;

  /**
   * @var array<string, User>
   */
  private array $players = [];

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed([CardSeeder::class, BidSeeder::class]);

    Event::fake([PlayingUpdated::class, HandDealt::class, TableUpdated::class]);

    $this->table = Table::factory()->create(['board_id' => null, 'name' => str_repeat(self::EMOJI['N'], Table::NAME_MAX)]);

    foreach (Seats::SEATS as $seat) {
      $this->players[$seat] = User::factory()->create([
        'name' => str_repeat(self::EMOJI[$seat], User::NAME_MAX),
        'username' => str_repeat(self::EMOJI[$seat], User::USERNAME_MAX),
        'description' => str_repeat(self::EMOJI[$seat], 1000),
      ]);
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->startBoard($this->table);
    $this->playing = app(PlayingStateService::class)->currentPlaying($this->table->refresh());
  }

  public function test_a_finished_board_after_the_longest_auction_fits(): void
  {
    $this->longestAuction();
    $this->play(52);
    $this->finish();

    $playing = (new PlayingUpdated($this->table))->playing;
    $this->assertSame('finished', $playing['phase']);
    $this->assertCount(319, $playing['auction']);
    $this->assertCount(13, $playing['tricks']);
    $this->assertCount(52, array_merge(...array_values($playing['deal'])));
    $this->assertSame(['N', 'E', 'S', 'W'], $playing['ready']);
    $this->assertNotNull($playing['next_board_at']);

    $this->assertFits(new PlayingUpdated($this->table));
  }

  public function test_a_pending_claim_of_thirteen_cards_fits(): void
  {
    $this->longestAuction();
    // the opening lead, and declarer claims all thirteen at once
    $this->play(1);
    $this->playing->refresh()->update([
      'claim_seat' => $this->playing->declarer_seat,
      'claim_tricks' => 13,
      'claim_accepted' => [Seats::next($this->playing->declarer_seat)],
      'claim_expires_at' => now()->addSeconds(10),
    ]);

    $playing = (new PlayingUpdated($this->table))->playing;
    $this->assertCount(13, $playing['claim']['hand']);
    $this->assertNotNull($playing['claim']['expires_at']);
    $this->assertCount(13, $playing['dummy_hand']);

    $this->assertFits(new PlayingUpdated($this->table));
  }

  public function test_a_claim_accepted_at_trick_twelve_fits_every_step(): void
  {
    // the board from the issue: a claim of the last trick made after 12,
    // then accepted, then everyone asking for the next board
    $this->longestAuction();
    $this->play(48);
    $declarer = $this->playing->refresh()->declarer_seat;
    $this->playing->update(['claim_seat' => $declarer, 'claim_tricks' => 1, 'claim_accepted' => [], 'claim_expires_at' => now()->addSeconds(10)]);

    $this->assertFits(new PlayingUpdated($this->table));

    $this->playing->update(['claim_accepted' => [Seats::next($declarer), Seats::partner(Seats::next($declarer))]]);
    $this->finish(claimed: 1);

    $playing = (new PlayingUpdated($this->table))->playing;
    $this->assertSame('finished', $playing['phase']);
    $this->assertTrue($playing['result']['claimed']);

    $this->assertFits(new PlayingUpdated($this->table));
  }

  public function test_the_table_fits_with_every_seat_ready_and_away(): void
  {
    $this->longestAuction();
    $this->play(52);
    $this->finish();
    $this->table->seats()->update(['ready_at' => now(), 'away_since' => now(), 'forfeit_at' => now(), 'last_seen_at' => now()]);

    $table = (new TableUpdated($this->table))->table;
    $this->assertNotNull($table['set']);
    $this->assertNotNull($table['seats'][0]['forfeit_at']);

    $this->assertFits(new TableUpdated($this->table));
  }

  public function test_a_hand_fits(): void
  {
    $this->longestAuction();
    $this->playing->refresh();

    $this->assertFits(new HandDealt($this->playing, $this->players['N']->id, 'N'));
    $this->assertFits(new DeclarerHandShown($this->playing, $this->players['N']->id, Seats::partner($this->playing->declarer_seat)));
  }

  public function test_an_alert_and_a_question_fit(): void
  {
    $explanation = str_repeat(self::EMOJI['N'], Auction::EXPLANATION_MAX);

    $this->assertFits(new CallAlerted($this->players['E']->id, $this->table->id, $this->playing->id, 318, $explanation));
    $this->assertFits(new CallQuestioned($this->players['N']->id, $this->table->id, $this->playing->id, 318, 'E'));
  }

  public function test_a_chat_message_fits(): void
  {
    $message = BoardMessage::create([
      'board_table_id' => $this->playing->id,
      'user_id' => $this->players['N']->id,
      'seat' => 'N',
      'to' => BoardMessage::TO_TABLE,
      'call_index' => 318,
      'card_index' => 51,
      'body' => str_repeat(self::EMOJI['N'], BoardMessage::BODY_MAX),
    ]);

    $this->assertFits(new BoardMessageSent($this->players['E']->id, $this->table->id, $this->playing->id, $message));
  }

  public function test_a_ban_fits(): void
  {
    $ban = UserBan::create([
      'user_id' => $this->players['N']->id,
      'banned_by' => User::factory()->create(['is_admin' => true])->id,
      'reason' => str_repeat(self::EMOJI['N'], UserBan::REASON_MAX),
      'banned_at' => now(),
      'until' => now()->addDays(UserBan::MAX_DAYS),
    ]);

    $this->assertFits(new UserBanned($ban));
  }

  public function test_the_broadcast_decodes_back_to_the_public_state(): void
  {
    $this->longestAuction();
    $this->play(23);
    $this->playing->refresh()->update(['claim_seat' => $this->playing->declarer_seat, 'claim_tricks' => 5, 'claim_accepted' => []]);

    $this->assertDecodes();

    $this->playing->clearClaim();
    $this->play(29);
    $this->finish();

    $this->assertDecodes();
  }

  public function test_the_broadcast_of_a_board_without_play_decodes_too(): void
  {
    // the auction under way: no contract, no tricks
    $this->assertDecodes();

    $pass = Bid::where('suit', Bid::PASS)->value('id');

    foreach (Seats::SEATS as $seat) {
      DB::table('auctions')->insert(['board_table_id' => $this->playing->id, 'user_id' => $this->players[$seat]->id, 'seat' => $seat, 'bid_id' => $pass]);
    }

    // passed out: finished, with no contract
    $this->playing->refresh()->update(['auction_ended_at' => now()]);
    $this->finish();

    $this->assertDecodes();
  }

  public function test_a_broadcast_that_fails_is_logged(): void
  {
    Broadcast::extend('failing', fn () => new class extends Broadcaster
    {
      public function auth($request) {}

      public function validAuthenticationResponse($request, $result) {}

      public function broadcast(array $channels, $event, array $payload = []): void
      {
        throw new BroadcastException('Pusher error: Payload too large.');
      }
    });
    config(['broadcasting.connections.failing' => ['driver' => 'failing'], 'broadcasting.default' => 'failing']);
    Log::spy();

    $event = new PlayingUpdated($this->table);

    try {
      // what dispatching it does (the events are faked here); the test queue
      // is sync, so the broadcast runs, fails and throws at once
      Broadcast::queue($event);
      $this->fail('The broadcast did not fail.');
    } catch (BroadcastException) {
    }

    Log::shouldHaveReceived('error')->once()->with(
      'Broadcast failed: PlayingUpdated never reached its channel.',
      Mockery::on(fn ($context) => $context === [
        'event' => PlayingUpdated::class,
        'channels' => ["private-table.{$this->table->id}"],
        'table_id' => $this->table->id,
        'user_id' => null,
        'bytes' => strlen(PusherBody::of($event)),
        'error' => 'Pusher error: Payload too large.',
      ]),
    );
  }

  public function test_another_failed_job_is_not_logged_as_a_broadcast(): void
  {
    Log::spy();

    try {
      dispatch(fn () => throw new RuntimeException('Not a broadcast.'));
      $this->fail('The job did not fail.');
    } catch (RuntimeException) {
    }

    Log::shouldNotHaveReceived('error');
  }

  private function assertFits(object $event): void
  {
    $size = strlen(PusherBody::of($event));

    $this->assertLessThanOrEqual(PusherBody::BUDGET, $size, class_basename($event)." is $size bytes.");
  }

  /**
   * The broadcast, expanded the way a client does it — every id looked up
   * in `GET /cards` and `GET /bids`, seats filled in clockwise from the
   * dealer or the trick's leader — is exactly the public state of
   * `GET /tables/{table}/playing`, less what is the caller's own: their
   * seat and hands, and the alerts on each call.
   */
  private function assertDecodes(): void
  {
    $cards = collect($this->getJson('/cards')->json('data'))->keyBy('id')->all();
    $bids = collect($this->getJson('/bids')->json('data'))->keyBy('id')->all();

    $public = $this->actingAs($this->players['N'])->getJson("/tables/{$this->table->id}/playing")->json('data');
    unset($public['my_seat'], $public['hand'], $public['declarer_hand']);
    $public['auction'] = array_map(fn ($call) => ['seat' => $call['seat'], 'bid' => $call['bid']], $public['auction']);

    $wire = json_decode(json_encode((new PlayingUpdated($this->table))->broadcastWith()['playing']), true);

    $this->assertSame($public, $this->expand($wire, $cards, $bids));
  }

  /**
   * `PlayingResource::compact()` undone, as documented in `docs/API.md`.
   *
   * @param  array<string, mixed>  $wire
   * @param  array<int, array<string, mixed>>  $cards
   * @param  array<int, array<string, mixed>>  $bids
   * @return array<string, mixed>
   */
  private function expand(array $wire, array $cards, array $bids): array
  {
    $hand = fn (?array $ids) => $ids === null ? null : array_map(fn ($id) => $cards[$id], $ids);
    $clockwise = function (?string $seat, array $ids) use ($cards) {
      $plays = [];

      foreach ($ids as $id) {
        $plays[] = ['seat' => $seat, 'card' => $cards[$id]];
        $seat = Seats::next($seat);
      }

      return $plays;
    };
    $seat = $wire['board']['dealer'] ?? null;
    $auction = [];

    foreach ($wire['auction'] ?? [] as $id) {
      $auction[] = ['seat' => $seat, 'bid' => $bids[$id]];
      $seat = Seats::next($seat);
    }

    return [
      ...$wire,
      'auction' => $wire['auction'] === null ? null : $auction,
      'contract' => $wire['contract'] === null ? null : [...$wire['contract'], 'bid' => $bids[$wire['contract']['bid']]],
      'tricks' => $wire['tricks'] === null ? null : array_map(fn ($trick, $index) => [
        'round' => $index + 1,
        'leader' => $trick['leader'],
        'cards' => $clockwise($trick['leader'], $trick['cards']),
        'winner' => $trick['winner'],
      ], $wire['tricks'], array_keys($wire['tricks'])),
      'current_trick' => $wire['current_trick'] === null ? null : $clockwise($wire['current_trick']['leader'], $wire['current_trick']['cards']),
      'dummy_hand' => $hand($wire['dummy_hand']),
      'claim' => $wire['claim'] === null ? null : [...$wire['claim'], 'hand' => $hand($wire['claim']['hand'])],
      'result' => $wire['result'] === null ? null : [
        ...$wire['result'],
        'contract' => $wire['result']['contract'] === null ? null : $bids[$wire['result']['contract']],
      ],
      'deal' => $wire['deal'] === null ? null : array_map($hand, $wire['deal']),
    ];
  }

  /**
   * The longest auction there is: three passes, then every bid from 1C to
   * 7NT doubled and redoubled, then the three closing passes — 319 calls,
   * each checked against the auction rules.
   */
  private function longestAuction(): void
  {
    $pass = Bid::where('suit', Bid::PASS)->sole();
    $double = Bid::where('suit', Bid::DOUBLE)->sole();
    $redouble = Bid::where('suit', Bid::REDOUBLE)->sole();

    $sequence = [$pass, $pass, $pass];

    foreach (Bid::contracts()->get()->sort(fn (Bid $a, Bid $b) => $a->rank() <=> $b->rank()) as $bid) {
      array_push($sequence, $bid, $pass, $pass, $double, $pass, $pass, $redouble, $pass, $pass);
    }

    $sequence[] = $pass;

    $calls = [];

    foreach ($sequence as $bid) {
      $seat = AuctionService::nextToCall($calls, $this->playing->board->dealer);
      $this->assertNull(AuctionService::illegalReason($calls, $seat, $bid));
      $calls[] = ['seat' => $seat, 'bid' => $bid];

      DB::table('auctions')->insert([
        'board_table_id' => $this->playing->id,
        'user_id' => $this->players[$seat]->id,
        'seat' => $seat,
        'bid_id' => $bid->id,
      ]);
    }

    $this->assertTrue(AuctionService::isOver($calls));
    $this->assertCount(319, $calls);

    $result = AuctionService::result($calls);

    $this->playing->update([
      'contract_bid_id' => $result['bid']->id,
      'doubled' => $result['doubled'],
      'declarer_seat' => $result['declarer'],
      'declarer_id' => $this->players[$result['declarer']]->id,
      'auction_ended_at' => now(),
    ]);
  }

  /**
   * Plays `$count` more cards by the rules: each seat follows suit with its
   * first card of the suit led, else plays its first card.
   */
  private function play(int $count): void
  {
    $state = app(PlayingStateService::class);
    $playing = $this->playing->refresh();
    $trump = $state->trump($playing);

    for ($i = 0; $i < $count; $i++) {
      $plays = $state->plays($playing);
      $seat = CardPlayService::nextToPlay($plays, $playing->declarer_seat, $trump);
      $hand = collect($state->hand($playing, $seat));
      $trick = CardPlayService::currentTrick($plays);
      $card = $trick === [] ? $hand->first() : ($hand->firstWhere('suit', $trick[0]['card']->suit) ?? $hand->first());
      $card = Card::findOrFail($card['id']);

      $this->assertNull(CardPlayService::illegalReason($plays, $state->hand($playing, $seat), $card));

      $round = intdiv(count($plays), 4) + 1;
      $trick[] = ['seat' => $seat, 'card' => $card];
      $won = count($trick) === 4 ? CardPlayService::trickWinner($trick, $trump) : null;

      DB::table('cardplays')->insert([
        'board_table_id' => $playing->id,
        'user_id' => $this->players[$seat]->id,
        'card_id' => $card->id,
        'round' => $round,
        'order' => count($trick),
        'seat' => $seat,
        'won_trick' => false,
      ]);

      if ($won !== null) {
        $winner = collect($trick)->firstWhere('seat', $won)['card'];
        DB::table('cardplays')->where('board_table_id', $playing->id)->where('card_id', $winner->id)->update(['won_trick' => true]);
      }

      $playing->refresh();
    }
  }

  /**
   * Ends the board as the services do, declarer having won what the tricks
   * say plus `$claimed`, and has all four ask for the next one.
   */
  private function finish(int $claimed = 0): void
  {
    $state = app(PlayingStateService::class);
    $playing = $this->playing->refresh();
    $tricks = null;

    if ($playing->declarer_seat !== null) {
      $won = CardPlayService::tricksWon($state->plays($playing), $state->trump($playing));
      $tricks = $won[CardPlayService::side($playing->declarer_seat)] + $claimed;
    }

    $playing->finish($tricks);
    $playing->seats()->update(['ready_at' => now()]);
  }
}
