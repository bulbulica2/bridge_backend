<?php

namespace Tests\Feature\Table;

use App\Events\BoardMessageSent;
use App\Events\CallAlerted;
use App\Events\CallQuestioned;
use App\Events\HandDealt;
use App\Events\PlayingUpdated;
use App\Events\TableUpdated;
use App\Events\UnseatedFromTable;
use App\Models\Bid;
use App\Models\Board;
use App\Models\Card;
use App\Models\Table;
use App\Models\TableKibitzer;
use App\Models\User;
use App\Services\CardPlayService;
use App\Services\PlayingStateService;
use App\Services\TableSeatService;
use App\Services\UserBanService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class KibitzerTest extends TestCase
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

    config(['bridge.idle_seat_minutes' => 5]);
  }

  public function test_a_new_table_allows_kibitzers_unless_its_creator_says_otherwise(): void
  {
    $this->actingAs(User::factory()->create())->postJson('/tables')
      ->assertCreated()
      ->assertJsonPath('data.allow_kibitzers', true)
      ->assertJsonPath('data.kibitzers', 0);

    $this->actingAs(User::factory()->create())->postJson('/tables', ['allow_kibitzers' => false])
      ->assertCreated()
      ->assertJsonPath('data.allow_kibitzers', false);

    $this->assertSame([true, false], Table::orderBy('id')->pluck('allow_kibitzers')->all());

    $this->actingAs(User::factory()->create())->postJson('/tables', ['allow_kibitzers' => 'maybe'])
      ->assertJsonValidationErrors('allow_kibitzers');
  }

  public function test_watching_a_table_counts_the_kibitzer_and_tells_the_table(): void
  {
    Event::fake([TableUpdated::class]);
    $this->lobbyTable();
    $kibitzer = User::factory()->create();

    $this->watch($kibitzer)
      ->assertCreated()
      ->assertJsonPath('message', 'Watching the table.')
      ->assertJsonPath('data.id', $this->table->id)
      ->assertJsonPath('data.kibitzers', 1);

    $this->assertTrue(TableKibitzer::where('table_id', $this->table->id)->where('user_id', $kibitzer->id)->exists());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->tableId === $this->table->id
      && $event->table['kibitzers'] === 1 && $event->table['allow_kibitzers'] === true);

    // the lobby shows it too, so it can offer Watch
    $this->actingAs(User::factory()->create())->getJson('/tables')
      ->assertOk()
      ->assertJsonPath('data.0.allow_kibitzers', true)
      ->assertJsonPath('data.0.kibitzers', 1);

    // watching it again is no second kibitzer
    $this->watch($kibitzer)->assertCreated()->assertJsonPath('data.kibitzers', 1);
  }

  public function test_a_table_that_doesnt_allow_kibitzers_refuses_them(): void
  {
    $this->lobbyTable();
    $this->table->update(['allow_kibitzers' => false]);

    $this->watch(User::factory()->create())
      ->assertForbidden()
      ->assertJsonPath('message', "This table doesn't allow kibitzers.");

    $this->assertSame(0, TableKibitzer::count());
  }

  public function test_a_seated_player_may_not_watch(): void
  {
    $this->lobbyTable();
    $elsewhere = Table::factory()->create(['board_id' => null]);
    $seated = User::factory()->create();
    app(TableSeatService::class)->seat($elsewhere, $seated, 'N');

    $this->watch($seated)
      ->assertConflict()
      ->assertJsonPath('message', 'You are seated at a table: leave your seat before watching one.');

    // nor at their own table
    $this->watch($this->players['N'])->assertConflict();

    $this->assertSame(0, TableKibitzer::count());
  }

  public function test_a_banned_user_may_not_watch(): void
  {
    $this->lobbyTable();
    $banned = User::factory()->create();
    app(UserBanService::class)->ban($banned, User::factory()->create(['is_admin' => true]), 3, 'Rude');

    $this->watch($banned)->assertForbidden();

    $this->assertSame(0, TableKibitzer::count());
  }

  public function test_watching_another_table_stops_watching_the_first(): void
  {
    $this->lobbyTable();
    $first = $this->table;
    $second = Table::factory()->create(['board_id' => null]);
    app(TableSeatService::class)->seat($second, User::factory()->create(), 'N');
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();

    Event::fake([TableUpdated::class]);

    $this->actingAs($kibitzer)->postJson("/tables/$second->id/kibitzers")
      ->assertCreated()
      ->assertJsonPath('data.kibitzers', 1);

    $this->assertSame($second->id, $kibitzer->kibitzing()->value('table_id'));
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->tableId === $first->id && $event->table['kibitzers'] === 0);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->tableId === $second->id && $event->table['kibitzers'] === 1);
  }

  public function test_stopping_watching(): void
  {
    $this->lobbyTable();
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();

    Event::fake([TableUpdated::class]);

    $this->actingAs($kibitzer)->deleteJson("/tables/{$this->table->id}/kibitzers")
      ->assertOk()
      ->assertJsonPath('message', 'Stopped watching the table.')
      ->assertJsonPath('data.kibitzers', 0);

    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['kibitzers'] === 0);

    $this->actingAs($kibitzer)->deleteJson("/tables/{$this->table->id}/kibitzers")
      ->assertConflict()
      ->assertJsonPath('message', 'You are not watching this table.');
  }

  public function test_sitting_down_at_the_table_watched_ends_watching(): void
  {
    $this->lobbyTable();
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();

    Event::fake([TableUpdated::class]);

    $this->actingAs($kibitzer)->postJson("/tables/{$this->table->id}/seats", ['seat' => 'W'])
      ->assertCreated()
      ->assertJsonPath('data.kibitzers', 0);

    $this->assertSame(0, TableKibitzer::count());
    // one TableUpdated tells both: a seat taken, a kibitzer less
    Event::assertDispatchedTimes(TableUpdated::class, 1);
  }

  public function test_sitting_down_at_another_table_ends_watching(): void
  {
    $this->lobbyTable();
    $other = Table::factory()->create(['board_id' => null]);
    app(TableSeatService::class)->seat($other, User::factory()->create(), 'N');
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();

    Event::fake([TableUpdated::class]);

    $this->actingAs($kibitzer)->postJson("/tables/$other->id/seats", ['seat' => 'S'])->assertCreated();

    $this->assertSame(0, TableKibitzer::count());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->tableId === $this->table->id && $event->table['kibitzers'] === 0);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->tableId === $other->id);
  }

  public function test_a_ban_ends_watching(): void
  {
    $this->lobbyTable();
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();

    Event::fake([TableUpdated::class]);

    app(UserBanService::class)->ban($kibitzer, User::factory()->create(['is_admin' => true]), 3, 'Rude');

    $this->assertSame(0, TableKibitzer::count());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['kibitzers'] === 0);
  }

  public function test_a_heartbeat_keeps_a_kibitzer_and_an_idle_one_is_dropped(): void
  {
    $this->lobbyTable();
    $active = User::factory()->create();
    $idle = User::factory()->create();
    $this->watch($active)->assertCreated();
    $this->watch($idle)->assertCreated();

    $this->travel(4)->minutes();

    $this->actingAs($active)->postJson("/tables/{$this->table->id}/heartbeat")
      ->assertOk()
      ->assertJsonPath('data.last_seen_at', now()->startOfSecond()->toJSON());

    $this->travel(2)->minutes();
    // the players keep their seats
    $this->table->seats()->update(['last_seen_at' => now()]);

    Event::fake([TableUpdated::class]);

    $this->artisan('tables:release-idle-seats')
      ->expectsOutput('Freed 0 idle seats.')
      ->expectsOutput('Dropped 1 idle kibitzer.')
      ->assertSuccessful();

    $this->assertSame([$active->id], TableKibitzer::pluck('user_id')->all());
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['kibitzers'] === 1);

    $this->artisan('tables:release-idle-seats')->expectsOutput('Dropped 0 idle kibitzers.');
  }

  public function test_a_manager_turns_kibitzers_off_and_the_ones_watching_are_sent_away(): void
  {
    $this->lobbyTable();
    $kibitzers = User::factory()->count(2)->create();
    $kibitzers->each(fn ($kibitzer) => $this->watch($kibitzer)->assertCreated());

    Event::fake([TableUpdated::class, UnseatedFromTable::class]);

    $this->actingAs($this->players['N'])->patchJson("/tables/{$this->table->id}", ['allow_kibitzers' => false])
      ->assertOk()
      ->assertJsonPath('data.allow_kibitzers', false)
      ->assertJsonPath('data.kibitzers', 0)
      // the other setting stays as it was
      ->assertJsonPath('data.set_minutes', 16);

    $this->assertSame(0, TableKibitzer::count());

    foreach ($kibitzers as $kibitzer) {
      Event::assertDispatched(UnseatedFromTable::class, fn ($event) => $event->userId === $kibitzer->id
        && $event->broadcastOn()[0]->name === "private-App.Models.User.$kibitzer->id"
        && $event->broadcastWith() === ['table_id' => $this->table->id, 'reason' => 'kibitzers_off', 'kibitzing' => false]);
    }

    Event::assertDispatchedTimes(UnseatedFromTable::class, 2);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['allow_kibitzers'] === false
      && $event->table['kibitzers'] === 0);

    // and back on: nobody is sent anywhere, and anyone may watch again
    $this->actingAs($this->players['N'])->patchJson("/tables/{$this->table->id}", ['allow_kibitzers' => true, 'set_minutes' => 8])
      ->assertOk()
      ->assertJsonPath('data.allow_kibitzers', true)
      ->assertJsonPath('data.set_minutes', 8);

    $this->watch($kibitzers[0])->assertCreated();
  }

  public function test_only_a_manager_changes_whether_kibitzers_are_allowed_and_not_mid_set(): void
  {
    $this->lobbyTable();

    $this->actingAs($this->players['E'])->patchJson("/tables/{$this->table->id}", ['allow_kibitzers' => false])
      ->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->patchJson("/tables/{$this->table->id}", ['allow_kibitzers' => false])
      ->assertOk();
    $this->actingAs($this->players['N'])->patchJson("/tables/{$this->table->id}", [])
      ->assertJsonValidationErrors(['allow_kibitzers', 'set_minutes']);

    $this->table->refresh()->update(['allow_kibitzers' => true]);
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();
    $this->dealt();

    $this->actingAs($this->players['N'])->patchJson("/tables/{$this->table->id}", ['allow_kibitzers' => false])
      ->assertConflict();

    $this->assertTrue($this->table->fresh()->allow_kibitzers);
    $this->assertSame(1, TableKibitzer::count());
  }

  public function test_who_may_subscribe_to_the_table_channel(): void
  {
    // no Reverb server to send the table's changes to
    Event::fake([TableUpdated::class]);
    $this->useReverbBroadcaster();
    $this->lobbyTable();
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();
    $body = ['socket_id' => '1234.5678', 'channel_name' => "private-table.{$this->table->id}"];

    $this->actingAs($this->players['N'])->postJson('/broadcasting/auth', $body)->assertOk();
    $this->actingAs($kibitzer)->postJson('/broadcasting/auth', $body)->assertOk();
    $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', $body)->assertForbidden();

    // somebody watching another table is no kibitzer of this one
    $other = Table::factory()->create(['board_id' => null]);
    app(TableSeatService::class)->seat($other, User::factory()->create(), 'N');
    $this->actingAs($kibitzer)->postJson("/tables/$other->id/kibitzers")->assertCreated();
    $this->actingAs($kibitzer)->postJson('/broadcasting/auth', $body)->assertForbidden();
  }

  public function test_a_kibitzer_may_do_nothing_at_the_table_but_watch(): void
  {
    $this->dealt();
    $this->makeCall('N', '1C', ['alert' => true, 'explanation' => 'Precision'])->assertCreated();
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();
    $nobody = User::factory()->create();

    $id = $this->table->id;
    $actions = [
      ['post', "/tables/$id/start"],
      ['delete', "/tables/$id/start"],
      ['post', "/tables/$id/playing/next"],
      ['post', "/tables/$id/calls", ['bid_id' => Bid::where('suit', 'P')->value('id')]],
      ['post', "/tables/$id/calls/0/question"],
      ['put', "/tables/$id/calls/0/explanation", ['explanation' => 'Natural']],
      ['get', "/tables/$id/messages"],
      ['post', "/tables/$id/messages", ['to' => 'table', 'body' => 'Hi']],
      ['post', "/tables/$id/cards", ['card_id' => Card::value('id')]],
      ['post', "/tables/$id/claim", ['tricks' => 13]],
      ['post', "/tables/$id/claim/response", ['accept' => true]],
      ['delete', "/tables/$id/claim"],
    ];

    foreach ([$kibitzer, $nobody] as $user) {
      foreach ($actions as $action) {
        $this->actingAs($user)->json($action[0], $action[1], $action[2] ?? [])->assertForbidden();
      }
    }

    // following the table is for its players and kibitzers only
    $this->actingAs($kibitzer)->getJson("/tables/$id/playing")->assertOk();
    $this->actingAs($kibitzer)->postJson("/tables/$id/heartbeat")->assertOk();
    $this->actingAs($nobody)->getJson("/tables/$id/playing")->assertForbidden();
    $this->actingAs($nobody)->postJson("/tables/$id/heartbeat")->assertForbidden();

    $this->state($this->players['E'])->assertJsonPath('data.my_seat', 'E')->assertJsonCount(13, 'data.hand');
    $this->assertSame(1, $this->table->auctions()->count());
  }

  public function test_a_kibitzer_sees_the_public_state_and_no_hand(): void
  {
    Event::fake([PlayingUpdated::class, HandDealt::class, CallAlerted::class, CallQuestioned::class, BoardMessageSent::class]);
    $this->lobbyTable();
    $kibitzer = User::factory()->create();
    $this->watch($kibitzer)->assertCreated();
    $this->dealt();

    // nothing private goes to a kibitzer: their hands go to the four
    Event::assertDispatchedTimes(HandDealt::class, 4);
    Event::assertNotDispatched(HandDealt::class, fn ($event) => $event->userId === $kibitzer->id);

    $this->makeCall('N', '1C', ['alert' => true, 'explanation' => 'Precision'])->assertCreated();
    $this->actingAs($this->players['E'])->postJson("/tables/{$this->table->id}/calls/0/question")->assertOk();

    Event::assertNotDispatched(CallAlerted::class, fn ($event) => $event->userId === $kibitzer->id);
    Event::assertNotDispatched(BoardMessageSent::class, fn ($event) => $event->userId === $kibitzer->id);

    $state = $this->state($kibitzer)
      ->assertJsonPath('data.phase', 'auction')
      ->assertJsonPath('data.my_seat', null)
      ->assertJsonPath('data.hand', null)
      ->assertJsonPath('data.declarer_hand', null)
      // alerted, but not what it means; and no question
      ->assertJsonPath('data.auction.0.alert', ['explanation' => null])
      ->assertJsonPath('data.auction.0.question', null)
      ->assertDontSee('Precision')
      ->json('data');

    // the public state, which the table channel carries, and nothing more
    $public = app(PlayingStateService::class)->publicState($this->table);
    $public['auction'][0] += ['alert' => ['explanation' => null], 'question' => null];
    $this->assertEquals([...$public, 'my_seat' => null, 'hand' => null, 'declarer_hand' => null], $state);

    // a call nobody alerted is not marked
    $this->makeCall('E', 'P')->assertCreated();
    $this->state($kibitzer)->assertJsonPath('data.auction.1.alert', null);

    $this->makeCall('S', 'P')->assertCreated();
    $this->makeCall('W', 'P')->assertCreated();

    // N declares 1C: dummy is face down until the opening lead
    $this->state($kibitzer)
      ->assertJsonPath('data.phase', 'play')
      ->assertJsonPath('data.dummy_hand', null)
      ->assertJsonPath('data.auction.0.alert', ['explanation' => null]);

    $this->playCards(1);

    $this->state($kibitzer)
      ->assertJsonPath('data.hand', null)
      ->assertJsonCount(13, 'data.dummy_hand')
      ->assertJsonCount(1, 'data.current_trick');

    $this->playCards(51);

    // once the board is finished every alert is public
    $this->state($kibitzer)
      ->assertJsonPath('data.phase', 'finished')
      ->assertJsonPath('data.auction.0.alert', ['explanation' => 'Precision'])
      ->assertJsonPath('data.hand', null);
  }

  /**
   * A table with N (its moderator) and E seated, and nothing dealt.
   */
  private function lobbyTable(): void
  {
    $this->table = Table::factory()->create(['board_id' => null]);

    foreach (['N', 'E'] as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->table->update(['moderated_by' => $this->players['N']->id, 'created_by' => $this->players['N']->id]);
  }

  /**
   * Fill the table with humans and deal: a set is going on, with N dealing.
   */
  private function dealt(): void
  {
    if (! isset($this->table)) {
      $this->lobbyTable();
    }

    foreach (['S', 'W'] as $seat) {
      $this->players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($this->table, $this->players[$seat], $seat);
    }

    $this->startBoard($this->table);
    $this->table->refresh();

    Board::whereKey($this->table->board_id)->update(['dealer' => 'N']);
  }

  private function watch(User $user): TestResponse
  {
    return $this->actingAs($user)->postJson("/tables/{$this->table->id}/kibitzers");
  }

  private function state(User $user): TestResponse
  {
    return $this->actingAs($user)->getJson("/tables/{$this->table->id}/playing")->assertOk();
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
   * Play `$count` cards, each the first legal card of the hand to play.
   */
  private function playCards(int $count): void
  {
    $state = app(PlayingStateService::class);

    for ($i = 0; $i < $count; $i++) {
      $playing = $state->currentPlaying($this->table);
      $plays = $state->plays($playing);
      $hand = $state->hand($playing, $state->turn($playing));

      $card = collect($hand)
        ->map(fn ($held) => Card::find($held['id']))
        ->first(fn ($card) => CardPlayService::illegalReason($plays, $hand, $card) === null);

      $this->actingAs(User::find($state->actingUserId($playing)))
        ->postJson("/tables/{$this->table->id}/cards", ['card_id' => $card->id])
        ->assertCreated();
    }
  }

  /**
   * As in TableBroadcastTest: channel auth is only real on a Pusher-style
   * driver.
   */
  private function useReverbBroadcaster(): void
  {
    config([
      'broadcasting.default' => 'reverb',
      'broadcasting.connections.reverb.key' => 'test-key',
      'broadcasting.connections.reverb.secret' => 'test-secret',
      'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    $this->app->make(BroadcastManager::class)->forgetDrivers();

    require base_path('routes/channels.php');
  }
}
