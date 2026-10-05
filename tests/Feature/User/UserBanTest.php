<?php

namespace Tests\Feature\User;

use App\auxiliary\Seats;
use App\Events\TableUpdated;
use App\Events\UserBanned;
use App\Models\Table;
use App\Models\TableSeat;
use App\Models\TableSet;
use App\Models\User;
use App\Models\UserBan;
use App\Services\TableSeatService;
use Database\Seeders\game\BidSeeder;
use Database\Seeders\game\CardSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST/DELETE /users/{user}/ban — an admin keeping a user away from the
 * game for some days: their seat is freed (mid-set their side forfeits),
 * they are logged out, and every game action is refused until the ban runs
 * out or is lifted. They may still log in and read why.
 */
class UserBanTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();

    // timestamps come back from the database to the second
    $this->freezeSecond();

    $this->admin = User::factory()->isAdmin()->create();
  }

  public function test_only_an_admin_bans_and_never_an_admin_themselves_or_a_robot(): void
  {
    $user = User::factory()->create();
    $body = ['days' => 7, 'reason' => 'Cheating.'];

    $this->postJson("/users/$user->id/ban", $body)->assertUnauthorized();

    $this->actingAs(User::factory()->create())->postJson("/users/$user->id/ban", $body)
      ->assertForbidden()
      ->assertJsonPath('message', 'Only an admin can ban a user.');

    $this->actingAs($this->admin)->postJson("/users/{$this->admin->id}/ban", $body)
      ->assertForbidden()
      ->assertJsonPath('message', 'You cannot ban yourself.');

    $other = User::factory()->isAdmin()->create();
    $this->actingAs($this->admin)->postJson("/users/$other->id/ban", $body)
      ->assertForbidden()
      ->assertJsonPath('message', 'An admin cannot be banned.');

    $robot = User::factory()->robot()->create();
    $this->actingAs($this->admin)->postJson("/users/$robot->id/ban", $body)
      ->assertForbidden()
      ->assertJsonPath('message', 'A robot cannot be banned.');

    $this->assertDatabaseCount('user_bans', 0);
  }

  public function test_a_ban_needs_a_reason_and_one_to_365_days(): void
  {
    $user = User::factory()->create();

    $this->actingAs($this->admin)->postJson("/users/$user->id/ban", ['days' => 7])
      ->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson("/users/$user->id/ban", ['days' => 7, 'reason' => str_repeat('x', UserBan::REASON_MAX + 1)])
      ->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson("/users/$user->id/ban", ['days' => 0, 'reason' => 'x'])
      ->assertJsonValidationErrors('days');
    $this->actingAs($this->admin)->postJson("/users/$user->id/ban", ['days' => 366, 'reason' => 'x'])
      ->assertJsonValidationErrors('days');

    $this->assertDatabaseCount('user_bans', 0);
  }

  public function test_banning_a_player_mid_set_forfeits_it_at_once_logs_them_out_and_tells_them(): void
  {
    $this->seed([CardSeeder::class, BidSeeder::class]);
    [$table, $players] = $this->fullTable();
    $this->startBoard($table);

    config(['session.driver' => 'database']);
    $this->sessionFor($players['E'], 'e-phone');
    $this->sessionFor($players['E'], 'e-laptop');
    $this->sessionFor($players['N'], 'n-laptop');
    $rememberToken = $players['E']->remember_token;

    Event::fake([UserBanned::class, TableUpdated::class]);

    $this->ban($players['E'], 7, 'Playing two accounts at once.')
      ->assertCreated()
      ->assertJsonPath('message', 'User banned until '.now()->addDays(7)->format('j M Y').'. They were in the middle of a set, so their side forfeited it.')
      ->assertJsonPath('data.user_id', $players['E']->id)
      ->assertJsonPath('data.reason', 'Playing two accounts at once.')
      ->assertJsonPath('data.until', now()->addDays(7)->toJSON())
      ->assertJsonPath('data.banned_by.id', $this->admin->id)
      ->assertJsonPath('data.lifted_at', null)
      ->assertJsonPath('data.active', true);

    // no grace period: the set is lost and the seat free right away
    $set = TableSet::sole();
    $this->assertSame(TableSet::ENDED_FORFEIT, $set->ended);
    $this->assertSame('EW', $set->forfeited_by);
    $this->assertSame(TableSet::FORFEIT_KICKED, $set->forfeit_reason);
    $this->assertDatabaseMissing('table_seats', ['user_id' => $players['E']->id]);
    Event::assertDispatched(TableUpdated::class, fn ($event) => $event->table['set']['ended'] === 'forfeit');

    // logged out everywhere, remember-me included; nobody else is
    $this->assertDatabaseMissing('sessions', ['user_id' => $players['E']->id]);
    $this->assertDatabaseHas('sessions', ['id' => 'n-laptop', 'user_id' => $players['N']->id]);
    $this->assertNotSame($rememberToken, $players['E']->fresh()->remember_token);

    Event::assertDispatched(UserBanned::class, fn ($event) => $event->broadcastOn()[0]->name === "private-App.Models.User.{$players['E']->id}"
      && $event->broadcastWith() === [
        'reason' => 'Playing two accounts at once.',
        'until' => now()->addDays(7)->toJSON(),
        'banned_at' => now()->toJSON(),
      ]);
  }

  public function test_banning_a_moderator_between_sets_frees_the_seat_and_hands_the_table_on(): void
  {
    [$table, $players] = $this->fullTable();

    $this->ban($players['N'])
      ->assertCreated()
      ->assertJsonPath('message', 'User banned until '.now()->addDays(7)->format('j M Y').'.');

    $this->assertDatabaseMissing('table_seats', ['user_id' => $players['N']->id]);
    $this->assertSame($players['E']->id, $table->fresh()->moderated_by);
    $this->assertDatabaseCount('table_sets', 0);
  }

  public function test_the_next_request_after_a_ban_is_a_401(): void
  {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertNoContent();
    $sessionId = DB::table('sessions')->where('user_id', $user->id)->value('id');
    $this->assertNotNull($sessionId);

    $this->forgetSessionAndGuards();
    $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)->getJson('/tables')->assertOk();

    // the admin's request must not carry, and so save back, the user's session
    $this->defaultCookies = [];
    $this->withCredentials = false;
    $this->ban($user)->assertCreated();
    $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);

    $this->forgetSessionAndGuards();
    $this->withCredentials()->withCookie(config('session.cookie'), $sessionId)->getJson('/tables')->assertUnauthorized();
  }

  public function test_a_banned_user_logs_in_sees_why_and_every_game_action_is_refused(): void
  {
    $user = User::factory()->create();
    $this->ban($user, 10, 'Colluding with partner.')->assertCreated();
    $message = 'You are banned until '.now()->addDays(10)->format('j M Y').': Colluding with partner.';
    $ban = ['reason' => 'Colluding with partner.', 'until' => now()->addDays(10)->toJSON(), 'banned_at' => now()->toJSON()];

    $this->app['auth']->forgetGuards();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertNoContent();
    $this->assertAuthenticatedAs($user);

    $this->getJson('/api/user')->assertOk()->assertJsonPath('ban', $ban);

    // somebody else's table, with a free seat
    $table = Table::factory()->create(['board_id' => null]);
    $other = User::factory()->create();
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $other->id, 'seat' => 'N']);

    foreach ($this->gameActions($table, $other) as [$method, $uri]) {
      $this->json($method, $uri, ['seat' => 'S', 'user_id' => $other->id, 'bid_id' => 1, 'card_id' => 1, 'tricks' => 1, 'accept' => true])
        ->assertForbidden()
        ->assertExactJson(['status' => 403, 'message' => $message, 'data' => ['ban' => $ban]]);
    }

    $this->assertDatabaseCount('tables', 1);
    $this->assertDatabaseMissing('table_seats', ['user_id' => $user->id]);

    // reading stays open: their profile, their history, other players, tables
    $this->getJson("/users/$user->id")->assertOk()->assertJsonMissingPath('data.ban')->assertJsonMissingPath('data.bans');
    $this->getJson('/api/user/playings')->assertOk();
    $this->getJson("/users/$other->id/playings")->assertOk();
    $this->getJson('/tables')->assertOk();
    $this->getJson("/tables/$table->id")->assertOk();
  }

  public function test_a_banned_user_may_not_subscribe_to_a_table_channel(): void
  {
    $user = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null]);
    $body = ['socket_id' => '1234.5678', 'channel_name' => "private-table.$table->id"];
    $this->ban($user)->assertCreated();
    $this->useReverbBroadcaster();

    // even somehow seated there
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $user->id, 'seat' => 'N']);

    $this->app['auth']->forgetGuards();
    $this->actingAs($user)->postJson('/broadcasting/auth', $body)->assertForbidden();

    // their own channel stays open
    $this->actingAs($user)->postJson('/broadcasting/auth', [
      'socket_id' => '1234.5678',
      'channel_name' => "private-App.Models.User.$user->id",
    ])->assertOk();

    UserBan::query()->update(['until' => now()->subMinute()]);
    $this->actingAs($user)->postJson('/broadcasting/auth', $body)->assertOk();
  }

  public function test_a_manager_cannot_seat_a_banned_user(): void
  {
    $user = User::factory()->create();
    $this->ban($user)->assertCreated();

    $manager = User::factory()->create();
    $table = Table::factory()->create(['board_id' => null, 'moderated_by' => $manager->id]);
    TableSeat::factory()->create(['table_id' => $table->id, 'user_id' => $manager->id, 'seat' => 'N']);

    $this->actingAs($manager)->postJson("/tables/$table->id/seats/users", ['user_id' => $user->id, 'seat' => 'S'])
      ->assertConflict()
      ->assertJsonPath('message', 'That user is banned.');

    $this->assertDatabaseMissing('table_seats', ['user_id' => $user->id]);
  }

  public function test_the_ban_ends_by_itself(): void
  {
    $user = User::factory()->create();
    $this->ban($user, 3)->assertCreated();

    $this->actingAs($user)->postJson('/tables', ['seat' => 'N'])->assertForbidden();

    $this->travel(3)->days();
    $this->travel(1)->second();

    $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('ban', null);
    $this->actingAs($user)->postJson('/tables', ['seat' => 'N'])->assertCreated();
  }

  public function test_lifting_a_ban_works_at_once(): void
  {
    $user = User::factory()->create();
    $this->ban($user)->assertCreated();

    $this->actingAs($user)->deleteJson("/users/$user->id/ban")->assertForbidden();
    $this->actingAs($user)->postJson('/tables', ['seat' => 'N'])->assertForbidden();

    $this->actingAs($this->admin)->deleteJson("/users/$user->id/ban")
      ->assertOk()
      ->assertJsonPath('message', 'Ban lifted.')
      ->assertJsonPath('data.lifted_at', now()->toJSON())
      ->assertJsonPath('data.lifted_by.id', $this->admin->id)
      ->assertJsonPath('data.active', false);

    $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('ban', null);
    $this->actingAs($user)->postJson('/tables', ['seat' => 'N'])->assertCreated();

    $this->actingAs($this->admin)->deleteJson("/users/$user->id/ban")
      ->assertNotFound()
      ->assertJsonPath('message', 'That user is not banned.');
  }

  public function test_a_new_ban_replaces_the_current_one(): void
  {
    $user = User::factory()->create();
    $this->ban($user, 30, 'First.')->assertCreated();
    $this->travel(1)->day();

    $this->ban($user, 2, 'Second.')->assertCreated();

    $this->assertSame(['Second.', 'First.'], $user->bans()->pluck('reason')->all());
    $this->assertNotNull($user->bans()->where('reason', 'First.')->value('lifted_at'));
    $this->assertSame('Second.', $user->activeBan()->reason);
  }

  public function test_only_an_admin_sees_a_users_bans_on_their_profile(): void
  {
    $user = User::factory()->create();

    $this->actingAs($this->admin)->getJson("/users/$user->id")
      ->assertOk()
      ->assertJsonPath('data.ban', null)
      ->assertJsonPath('data.bans', []);

    $this->ban($user, 5, 'Old.')->assertCreated();
    $this->actingAs($this->admin)->deleteJson("/users/$user->id/ban")->assertOk();
    $this->ban($user, 5, 'Current.')->assertCreated();

    $this->actingAs($this->admin)->getJson("/users/$user->id")
      ->assertOk()
      ->assertJsonPath('data.username', $user->username)
      ->assertJsonPath('data.ban.reason', 'Current.')
      ->assertJsonPath('data.ban.banned_by.id', $this->admin->id)
      ->assertJsonPath('data.bans.0.reason', 'Current.')
      ->assertJsonPath('data.bans.1.reason', 'Old.')
      ->assertJsonPath('data.bans.1.active', false);

    $this->actingAs(User::factory()->create())->getJson("/users/$user->id")
      ->assertOk()
      ->assertJsonMissingPath('data.ban')
      ->assertJsonMissingPath('data.bans');
  }

  private function ban(User $user, int $days = 7, string $reason = 'Cheating.'): TestResponse
  {
    return $this->actingAs($this->admin)->postJson("/users/$user->id/ban", ['days' => $days, 'reason' => $reason]);
  }

  /**
   * A table with four humans seated N, E, S, W, run by N.
   *
   * @return array{0: Table, 1: array<string, User>}
   */
  private function fullTable(): array
  {
    $table = Table::factory()->create(['board_id' => null]);
    $players = [];

    foreach (Seats::SEATS as $seat) {
      $players[$seat] = User::factory()->create();
      app(TableSeatService::class)->seat($table, $players[$seat], $seat);
    }

    $table->update(['moderated_by' => $players['N']->id]);

    return [$table, $players];
  }

  /**
   * Start the next request as a new one would: the test app otherwise keeps
   * the last request's session and logged-in user in memory.
   */
  private function forgetSessionAndGuards(): void
  {
    $this->app->forgetInstance('session.store');
    $this->app['session']->forgetDrivers();
    $this->app['auth']->forgetGuards();
  }

  private function sessionFor(User $user, string $id): void
  {
    DB::table('sessions')->insert([
      'id' => $id,
      'user_id' => $user->id,
      'payload' => '',
      'last_activity' => now()->getTimestamp(),
    ]);
  }

  /**
   * Every route that changes anything at a table.
   *
   * @return list<array{0: string, 1: string}>
   */
  private function gameActions(Table $table, User $other): array
  {
    return [
      ['POST', '/tables'],
      ['POST', "/tables/$table->id/seats"],
      ['DELETE', "/tables/$table->id/seats"],
      ['POST', "/tables/$table->id/seats/users"],
      ['POST', "/tables/$table->id/seats/robots"],
      ['DELETE', "/tables/$table->id/seats/$other->id"],
      ['POST', "/tables/$table->id/heartbeat"],
      ['POST', "/tables/$table->id/start"],
      ['DELETE', "/tables/$table->id/start"],
      ['GET', "/tables/$table->id/playing"],
      ['POST', "/tables/$table->id/playing/next"],
      ['POST', "/tables/$table->id/calls"],
      ['POST', "/tables/$table->id/cards"],
      ['POST', "/tables/$table->id/claim"],
      ['POST', "/tables/$table->id/claim/response"],
      ['DELETE', "/tables/$table->id/claim"],
    ];
  }

  /**
   * As `TableBroadcastTest`: the `null` driver lets everyone subscribe.
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
